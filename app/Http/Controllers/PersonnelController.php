<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PersonnelLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\PersonnelStoreRequest;
use App\Http\Requests\PersonnelUpdateRequest;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\Position;
use App\Models\RoOffice;
use App\Models\SdoOffice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PersonnelController extends Controller
{
    use HasLookupOptions;

    /**
     * Tier 1 reference tables used across Personnel's create/edit forms —
     * lookup-normalization ADR, Step 2. Keyed to match the field's
     * conceptual name (matching the `_id` FK column minus its suffix), so
     * Frontend can destructure `options.position`, `options.ro_office`,
     * `options.sdo_office` — mirroring Equipment's TIER1_FORM_MODELS
     * keying convention.
     *
     * @var array<string, class-string>
     */
    private const TIER1_FORM_MODELS = [
        'position' => Position::class,
        'ro_office' => RoOffice::class,
        'sdo_office' => SdoOffice::class,
    ];

    /**
     * Tier 2 library types used across Personnel's create/edit forms that
     * stay single string-valued selects — repoints the old LookupType-keyed
     * set at PersonnelLibrary/PersonnelLibraryType. `teachers_funding_source`
     * (the JSON-array `fund_source` field) is deliberately NOT here — it
     * needs id-valued options (built separately in formOptions() via
     * libraryOptionsById()), not the string-valued shape this set produces.
     *
     * @var array<string, PersonnelLibraryType>
     */
    private const TIER2_FORM_TYPES = [
        'name_suffix' => PersonnelLibraryType::NameSuffix,
        'cause_of_separation' => PersonnelLibraryType::CauseOfSeparation,
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Personnel::class);

        $status = $request->string('status')->toString();
        $status = in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active';

        $personnel = Personnel::query()
            ->search($request->string('search')->toString() ?: null)
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'inactive', fn ($query) => $query->where('inactive', true))
            ->with([
                'personnelPosition:id,name',
                'roOffice:id,name',
                'sdoOffice:id,name',
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Personnel $person) => [
                'id' => $person->id,
                'employee_id' => $person->employee_id,
                'full_name' => $person->full_name,
                'last_name' => $person->last_name,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'suffix' => $person->suffix,
                'position' => $person->personnelPosition?->name,
                'division_unit' => $person->sdoOffice?->name,
                'ro_division' => $person->roOffice?->name,
                'mobile_1' => $person->mobile_1,
                'deped_email' => $person->deped_email,
                'oic' => $person->oic,
                'oic_office' => $person->oic_office,
                'inactive' => $person->inactive,
            ]);

        return Inertia::render('personnel/Index', [
            'personnel' => $personnel,
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'status' => $status,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Personnel::class);

        return Inertia::render('personnel/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(PersonnelStoreRequest $request): RedirectResponse
    {
        $personnel = Personnel::create($request->validated());

        AuditLog::record('personnel.created', $personnel, ['attributes' => $request->validated()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personnel record created.')]);

        return to_route('personnel.index');
    }

    public function edit(Personnel $personnel): Response
    {
        $this->authorize('update', $personnel);

        $personnel->load(['personnelPosition:id,name', 'roOffice:id,name', 'sdoOffice:id,name']);

        return Inertia::render('personnel/Edit', [
            'personnel' => $this->personnelProps($personnel),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(PersonnelUpdateRequest $request, Personnel $personnel): RedirectResponse
    {
        $before = $personnel->getOriginal();

        $personnel->update($request->validated());

        $changes = collect($personnel->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('personnel.updated', $personnel, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personnel record updated.')]);

        return to_route('personnel.index');
    }

    public function destroy(Personnel $personnel): RedirectResponse
    {
        $this->authorize('delete', $personnel);

        AuditLog::record('personnel.deleted', $personnel, [
            'employee_id' => $personnel->employee_id,
            'full_name' => $personnel->full_name,
        ]);

        $personnel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personnel record deleted.')]);

        return to_route('personnel.index');
    }

    /**
     * Combined Tier 1 (id-valued) + Tier 2 (string-valued) options for the
     * Create/Edit forms, plus the one id-valued Tier 2 exception
     * (`teachers_funding_source`, backing the JSON-array `fund_source`
     * field — see libraryOptionsById()'s doc-comment).
     *
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            ...$this->referenceOptions(self::TIER1_FORM_MODELS),
            ...$this->libraryOptions(PersonnelLibrary::class, self::TIER2_FORM_TYPES),
            'teachers_funding_source' => $this->libraryOptionsById(PersonnelLibrary::class, PersonnelLibraryType::TeachersFundingSource),
        ];
    }

    /**
     * Tier 1 fields ship both the raw FK id (`position_id`, etc. — what
     * the Edit form's Select components bind to and what
     * PersonnelUpdateRequest expects back) and the related row's display
     * name under the pre-cutover key (`position`, etc. — read-only display
     * value, sourced via the relationship, never the now-stale legacy
     * string column). Tier 2 single-value fields are unchanged: a single
     * string value under their existing key. `fund_source` now carries an
     * array of `personnel_libraries` row ids (ADR Question 4) — the Edit
     * form's multi-select binds directly to those ids against the
     * `teachers_funding_source` option list from formOptions().
     *
     * @return array<string, mixed>
     */
    private function personnelProps(Personnel $personnel): array
    {
        return [
            'id' => $personnel->id,
            'employee_id' => $personnel->employee_id,
            'last_name' => $personnel->last_name,
            'first_name' => $personnel->first_name,
            'middle_name' => $personnel->middle_name,
            'suffix' => $personnel->suffix,
            'full_name' => $personnel->full_name,
            'ro_division' => $personnel->roOffice?->name,
            'ro_office_id' => $personnel->ro_office_id,
            'division_unit' => $personnel->sdoOffice?->name,
            'sdo_office_id' => $personnel->sdo_office_id,
            'position' => $personnel->personnelPosition?->name,
            'position_id' => $personnel->position_id,
            'oic' => $personnel->oic,
            'oic_office' => $personnel->oic_office,
            'mobile_1' => $personnel->mobile_1,
            'mobile_2' => $personnel->mobile_2,
            'deped_email' => $personnel->deped_email,
            'personal_email' => $personnel->personal_email,
            'date_hired' => $personnel->date_hired?->toDateString(),
            'non_deped_funded' => $personnel->non_deped_funded,
            'fund_source' => $personnel->fund_source,
            'inactive' => $personnel->inactive,
            'separation_date' => $personnel->separation_date?->toDateString(),
            'separation_cause' => $personnel->separation_cause,
            'transferred_from' => $personnel->transferred_from,
            'transferred_to' => $personnel->transferred_to,
        ];
    }
}
