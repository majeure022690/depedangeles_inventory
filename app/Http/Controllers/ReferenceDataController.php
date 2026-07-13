<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ReferenceDataUpdateRequest;
use App\Models\AuditLog;
use App\Services\ReferenceDataResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * Three actions, mirroring LookupController's shape scaled from 1 table to
 * 13:
 *  - index()   Overview of all 13 tables (label, tier, row count) — the
 *              landing page an admin picks a table from.
 *  - show()    Paginated/searchable (and, for Tier 2, type-filterable) row
 *              listing for ONE table.
 *  - update()  Edits description/sort_order/is_active (+ name for Tier 1
 *              — see below) for one row.
 *
 * No create/destroy, same reasoning as LookupController: the reference
 * data is already fully seeded/migrated from the old `lookups` table
 * (ADR Step 1), so there's no known need to add brand-new rows or
 * hard-delete existing ones through this UI today — deactivating a bad
 * row (is_active = false) already covers "stop offering this without
 * touching historical records that reference it."
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
}
