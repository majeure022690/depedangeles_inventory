<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ReferenceDataStoreRequest;
use App\Http\Requests\ReferenceDataUpdateRequest;
use App\Models\AuditLog;
use App\Services\ReferenceDataResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reference-data.manage admin screen — successor to LookupController /
 * lookups/Index.vue (see docs/architecture-decisions/
 * lookup-normalization.md, Question 3 and Step 2 item 8 / Step 3 item 5).
 * ONE generic controller drives all 13 reference-data tables, reading
 * config/reference-data.php rather than being duplicated 13 times — that
 * config array is the single source of truth for "what reference tables
 * exist" (ADR Question 3); this controller is the single place that reads
 * it for admin CRUD.
 *
 * Five actions, mirroring LookupController's shape scaled from 1 table to
 * 13, extended beyond LookupController's read/update-only shape to add
 * create/hard-delete once a real need surfaced (deactivating a bad row
 * doesn't help when the row simply shouldn't exist, e.g. a typo'd value
 * created in error, or a genuinely new option the seeded set never
 * anticipated):
 *  - index()   Overview of all 13 tables (label, tier, row count) — the
 *              landing page an admin picks a table from.
 *  - show()    Paginated/searchable (and, for Tier 2, type-filterable) row
 *              listing for ONE table.
 *  - store()   Creates a new row — name (Tier 1) or type+value (Tier 2).
 *  - update()  Edits description/sort_order/is_active (+ name for Tier 1
 *              — see below) for one row.
 *  - destroy() Hard-deletes a row, guarded by ReferenceDataResolver::
 *              guardDeletable() (Tier 2 + the isp-providers JSON-column
 *              exception) and a QueryException catch for Tier 1's
 *              restrictOnDelete() FKs — see destroy()'s own doc-comment.
 *
 * Tier-1-`name`-editability decision (ReferenceDataUpdateRequest enforces
 * this; see its own doc-comment for the mirrored short version): UNLIKE
 * the old single `lookups` table — where LookupUpdateRequest permanently
 * excludes `value` because every consumer COPIES that string into its own
 * column, so a rename would silently orphan historical records — Tier 1
 * tables are real foreign-key targets (Question 2 of the ADR).
 * Equipment/Personnel/IspAccount store `item_type_id`/`position_id`/etc.,
 * not a copied name string, and every consumer reads the display name
 * through the `belongsTo` relationship at render time (e.g.
 * `$equipment->itemType->name`), never a cached copy. Renaming `name`
 * here therefore propagates correctly and immediately everywhere, by
 * construction — there is no stale-copy problem for a real FK to create.
 * `name` is accordingly editable for Tier 1, with a per-table uniqueness
 * rule (two rows in the same reference table sharing a name would make
 * the dropdown genuinely ambiguous, unlike a duplicate `description`).
 * Tier 2 keeps the old design exactly (`type`/`value` immutable) because
 * Tier 2 consumer columns deliberately stayed validated strings, not FKs
 * — the ADR's Question 2 rejected an FK there precisely because these are
 * small, rarely-renamed closed vocabularies, so the stale-copy risk that
 * justified `value`'s immutability on the old `lookups` table applies to
 * Tier 2's `value` column completely unchanged.
 *
 * Authorization: index() has no single model instance to check against
 * (it's an aggregate over all 13 tables), so it authorizes the bare
 * `reference-data.manage` permission string directly — identical in
 * effect to going through ReferenceDataPolicy::viewAny() for any one of
 * the 13 models (the Policy has no per-model branching, see its own
 * doc-comment), and the same pattern UserController already uses for its
 * own single-capability admin index. show() and update() DO have a
 * concrete (if only compile-time-unknown) model class/instance, so they
 * authorize through ReferenceDataPolicy via `$this->authorize(...)` —
 * this is the dynamic-model Gate::policy() resolution the ADR asked
 * Backend to confirm works: AppServiceProvider::registerReferenceDataPolicies()
 * registers ReferenceDataPolicy against each of the 13 concrete class
 * strings up front, so `Gate::policy($modelClass, ...)`'s lookup table
 * already has an entry for whatever class config/reference-data.php names
 * — PHP resolves `SomeClass::class` the same way whether `$modelClass`
 * was a literal or a runtime variable, and Laravel's Gate keys its policy
 * map by that resolved string either way. Covered by
 * ReferenceDataControllerTest.
 */
class ReferenceDataController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::ReferenceDataManage->value);

        $tables = collect(config('reference-data'))
            ->map(function (array $entry, string $key): array {
                /** @var class-string<Model> $modelClass */
                $modelClass = $entry['model'];

                return [
                    'key' => $key,
                    'label' => $entry['label'],
                    'tier' => $entry['tier'],
                    'row_count' => $modelClass::query()->count(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('reference-data/Index', [
            'tables' => $tables,
        ]);
    }

    public function show(Request $request, string $table): Response
    {
        $entry = ReferenceDataResolver::entry($table);

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['model'];

        $this->authorize('viewAny', $modelClass);

        $isTier1 = $entry['tier'] === 1;
        $search = $request->string('search')->toString() ?: null;
        $typeFilter = ! $isTier1 ? ($request->string('type')->toString() ?: null) : null;

        $rows = $modelClass::query()
            ->when($search, fn ($query) => $query->where(
                fn ($q) => $q
                    ->where($isTier1 ? 'name' : 'value', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"),
            ))
            ->when($typeFilter, fn ($query) => $query->where('type', $typeFilter))
            ->when(! $isTier1, fn ($query) => $query->orderBy('type'))
            ->orderBy('sort_order')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Model $row): array => $isTier1 ? [
                'id' => $row->id,
                'name' => $row->name,
                'description' => $row->description,
                'sort_order' => $row->sort_order,
                'is_active' => $row->is_active,
            ] : [
                'id' => $row->id,
                'type' => $row->type->value,
                'value' => $row->value,
                'description' => $row->description,
                'sort_order' => $row->sort_order,
                'is_active' => $row->is_active,
            ]);

        return Inertia::render('reference-data/Show', [
            'table' => $table,
            'label' => $entry['label'],
            'tier' => $entry['tier'],
            'rows' => $rows,
            'types' => $isTier1 ? null : collect($entry['type_enum']::cases())
                ->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])
                ->all(),
            'filters' => [
                'search' => $search,
                'type' => $typeFilter,
            ],
        ]);
    }

    /**
     * Authorization is handled entirely by ReferenceDataStoreRequest::
     * authorize() (mirrors update()'s split with ReferenceDataUpdateRequest
     * — no `$this->authorize(...)` call needed here since the Form Request
     * already gated the request before this method runs).
     */
    public function store(ReferenceDataStoreRequest $request, string $table): RedirectResponse
    {
        $entry = ReferenceDataResolver::entry($table);

        /** @var class-string<Model> $modelClass */
        $modelClass = $entry['model'];

        $data = $request->validated();
        $data['sort_order'] ??= 0;
        $data['is_active'] ??= true;

        try {
            $row = $modelClass::create($data);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    ($entry['tier'] === 1 ? 'name' : 'value') => __('This value already exists.'),
                ]);
            }

            throw $e;
        }

        AuditLog::record('reference_data.created', $row, [
            'table' => $table,
            ...$data,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference data created.')]);

        return to_route('reference-data.show', $table);
    }

    public function update(ReferenceDataUpdateRequest $request, string $table, int $id): RedirectResponse
    {
        $entry = ReferenceDataResolver::entry($table);
        $row = ReferenceDataResolver::resolveRow($table, $id);

        $trackedAttributes = $entry['tier'] === 1
            ? ['name', 'description', 'sort_order', 'is_active']
            : ['description', 'sort_order', 'is_active'];

        $before = $row->only($trackedAttributes);

        $row->update($request->validated());

        AuditLog::record('reference_data.updated', $row, [
            'table' => $table,
            'before' => $before,
            'after' => $row->only($trackedAttributes),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference data updated.')]);

        return to_route('reference-data.show', $table);
    }

    /**
     * Hard-delete. Authorized inline (unlike store()/update()) because
     * there's no Form Request here — a plain `{table}/{id}` DELETE has no
     * body to validate, so there's nothing for a Form Request to add over
     * a direct `$this->authorize()` call, matching how index()/show()
     * authorize inline for the same "no Form Request in play" reason.
     *
     * Two-layer delete-safety, per the `database` agent's analysis (see
     * ReferenceDataResolver::guardDeletable()'s doc-comment for the full
     * split):
     *  1. ReferenceDataResolver::guardDeletable() runs FIRST and throws a
     *     friendly ValidationException for Tier 2 rows still referenced
     *     by their fixed table.column usage map, and for isp_providers
     *     rows still referenced via the two unenforced JSON survey
     *     columns — both need an explicit pre-check because neither has a
     *     database-level FK to rely on.
     *  2. For the remaining Tier 1 tables, the delete is simply attempted
     *     and the resulting QueryException is caught here: their
     *     `restrictOnDelete()` FK is already authoritative and atomic, so
     *     a redundant pre-query would just be a second query without
     *     buying any additional safety (and would still need this catch
     *     anyway, to guard the race between the check and the delete).
     */
    public function destroy(string $table, int $id): RedirectResponse
    {
        $row = ReferenceDataResolver::resolveRow($table, $id);

        $this->authorize('delete', $row);

        ReferenceDataResolver::guardDeletable($table, $row);

        try {
            DB::transaction(function () use ($row, $table): void {
                $row->delete();

                AuditLog::record('reference_data.deleted', $row, ['table' => $table]);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'delete' => __('This value is still in use and cannot be deleted — deactivate it instead.'),
                ]);
            }

            throw $e;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference data deleted.')]);

        return to_route('reference-data.show', $table);
    }
}
