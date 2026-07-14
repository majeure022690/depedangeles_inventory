<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\StakeholderProfileUpdateRequest;
use App\Models\AuditLog;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\PsgcBarangay;
use App\Models\PsgcMunicipality;
use App\Models\PsgcProvince;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ONE ROW PER OFFICE (see App\Models\StakeholderProfile) — index() is the
 * cross-office admin list (stakeholder_profile.view_all only); edit()/
 * update() are always scoped to one specific {office}, never a bare
 * singleton. No create()/store() (a profile is always
 * firstOrCreate(['office_id' => ...])'d lazily, never explicitly created)
 * and no destroy() (removing a profile isn't a supported action).
 */
class StakeholderProfileController extends Controller
{
    use HasLookupOptions;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StakeholderProfile::class);

        $search = $request->string('search')->toString() ?: null;

        $offices = Office::query()
            ->with('stakeholderProfile:id,office_id,governance_level,updated_at')
            ->when($search, fn ($query) => $query->where('office_name', 'like', "%{$search}%"))
            ->orderBy('office_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Office $office) => [
                'id' => $office->id,
                'office_name' => $office->office_name,
                'office_type' => $office->office_type,
                'school_id' => $office->school_id,
                'has_profile' => $office->stakeholderProfile !== null,
                'updated_at' => $office->stakeholderProfile?->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('stakeholder-profile/Index', [
            'offices' => $offices,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function edit(Office $office): Response
    {
        // Authorize against a transient (unsaved) instance BEFORE touching
        // the DB — with office_id already set, so StakeholderProfilePolicy
        // can correctly compare it against the acting user's own office_id
        // — a `pending`-role (or wrong-office) user's GET no longer
        // creates the row before being 403'd.
        $this->authorize('view', new StakeholderProfile(['office_id' => $office->id]));

        $stakeholderProfile = StakeholderProfile::firstOrCreate(['office_id' => $office->id]);
        $stakeholderProfile->load('province', 'municipality', 'barangay');

        return Inertia::render('stakeholder-profile/Edit', [
            'office' => [
                'id' => $office->id,
                'office_name' => $office->office_name,
            ],
            'stakeholderProfile' => $this->stakeholderProfileProps($stakeholderProfile),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(StakeholderProfileUpdateRequest $request, Office $office): RedirectResponse
    {
        $this->authorize('update', new StakeholderProfile(['office_id' => $office->id]));

        $stakeholderProfile = StakeholderProfile::firstOrCreate(['office_id' => $office->id]);

        $before = $stakeholderProfile->getOriginal();

        $stakeholderProfile->update($request->validated());

        $changes = collect($stakeholderProfile->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('stakeholder_profile.updated', $stakeholderProfile, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stakeholder profile updated.')]);

        return to_route('stakeholder-profiles.edit', $office);
    }

    /**
     * @return array<string, mixed>
     */
    private function stakeholderProfileProps(StakeholderProfile $stakeholderProfile): array
    {
        return [
            'id' => $stakeholderProfile->id,

            'governance_level' => $stakeholderProfile->governance_level,
            'ro' => $stakeholderProfile->ro,
            'sdo' => $stakeholderProfile->sdo,

            'school_district' => $stakeholderProfile->school_district,
            'school_name' => $stakeholderProfile->school_name,
            'school_id' => $stakeholderProfile->school_id,

            'province_id' => $stakeholderProfile->province_id,
            'municipality_id' => $stakeholderProfile->municipality_id,
            'legislative_district' => $stakeholderProfile->legislative_district,
            'barangay_id' => $stakeholderProfile->barangay_id,
            'street' => $stakeholderProfile->street,
            // Read-only: PHP accessor built from the province/municipality/
            // barangay relations, never part of the writable Form Request
            // payload.
            'complete_address' => $stakeholderProfile->complete_address,

            'notes_corrections' => $stakeholderProfile->notes_corrections,
            'notes_recent_development' => $stakeholderProfile->notes_recent_development,

            'mobile_1' => $stakeholderProfile->mobile_1,
            'mobile_2' => $stakeholderProfile->mobile_2,
            'landline' => $stakeholderProfile->landline,

            'chief_name' => $stakeholderProfile->chief_name,
            'chief_position' => $stakeholderProfile->chief_position,
            'chief_email' => $stakeholderProfile->chief_email,
            'chief_mobile' => $stakeholderProfile->chief_mobile,

            'admin_staff_name' => $stakeholderProfile->admin_staff_name,
            'admin_staff_position' => $stakeholderProfile->admin_staff_position,
            'admin_staff_email' => $stakeholderProfile->admin_staff_email,
            'admin_staff_mobile' => $stakeholderProfile->admin_staff_mobile,

            'network_administrator_name' => $stakeholderProfile->network_administrator_name,

            'longitude' => $stakeholderProfile->longitude !== null ? (float) $stakeholderProfile->longitude : null,
            'latitude' => $stakeholderProfile->latitude !== null ? (float) $stakeholderProfile->latitude : null,

            'nearby_institutions' => $stakeholderProfile->nearby_institutions,
            'nearby_institutions_other' => $stakeholderProfile->nearby_institutions_other,

            'travel_time_to_center_minutes' => $stakeholderProfile->travel_time_to_center_minutes,

            'access_paths' => $stakeholderProfile->access_paths,

            'transportation_options' => $stakeholderProfile->transportation_options,
            'transportation_other' => $stakeholderProfile->transportation_other,

            'transportation_difficult' => $stakeholderProfile->transportation_difficult,
            'considered_very_remote' => $stakeholderProfile->considered_very_remote,
            'remote_context_notes' => $stakeholderProfile->remote_context_notes,

            'gidca' => $stakeholderProfile->gidca,
            'lms' => $stakeholderProfile->lms,

            'community_engagement' => $stakeholderProfile->community_engagement,
            'community_context_remarks' => $stakeholderProfile->community_context_remarks,

            'submitted_at' => $stakeholderProfile->submitted_at?->toIso8601String(),
            'transaction_type' => $stakeholderProfile->transaction_type,
        ];
    }

    /**
     * Options for the edit form's selects/checkbox-groups
     * (lookup-normalization ADR, Step 2+3 — StakeholderProfile has no
     * Tier 1 fields, so this is pure Tier 2). `governance_level` stays a
     * single string-valued select, sourced from StakeholderLibrary.
     * `transaction_type` also stays single string-valued, but is
     * DELIBERATELY sourced from EquipmentLibrary — see
     * StakeholderProfileUpdateRequest's doc-comment for why. The four
     * JSON-array checkbox-group fields (nearby_institution,
     * type_of_access_road, by_transportation, community_engagement) ship
     * id-valued options via libraryOptionsById() — the stored array
     * elements are now `stakeholder_libraries` row ids, not value strings
     * (ADR Question 4). Option keys are unchanged from the pre-cutover
     * shape so the existing Vue form doesn't need re-keying, only its
     * bound value types change.
     *
     * `province`/`municipality`/`barangay` are the full Region III PSGC
     * hierarchy (~3,100 barangays total), shipped once per edit-page load
     * so the frontend can filter municipality-by-province and
     * barangay-by-municipality client-side (cascading selects) without a
     * round trip per keystroke/selection — there is no cross-office reuse
     * concern here (this is government reference data, not a per-office
     * resource), and the payload is small enough (~200KB) for a single
     * admin-tool page load. `municipality`/`barangay` options carry their
     * parent id alongside {value, label} so the Vue form can filter them.
     *
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function formOptions(): array
    {
        return [
            ...$this->libraryOptions(StakeholderLibrary::class, ['governance_level' => StakeholderLibraryType::GovernanceLevel]),
            ...$this->libraryOptions(EquipmentLibrary::class, ['transaction_type' => EquipmentLibraryType::TransactionType]),
            'nearby_institution' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::NearbyInstitution),
            'type_of_access_road' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::TypeOfAccessRoad),
            'by_transportation' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::ByTransportation),
            'community_engagement' => $this->libraryOptionsById(StakeholderLibrary::class, StakeholderLibraryType::CommunityEngagement),
            'province' => PsgcProvince::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (PsgcProvince $province) => ['value' => $province->id, 'label' => $province->name])
                ->all(),
            'municipality' => PsgcMunicipality::query()
                ->orderBy('name')
                ->get(['id', 'name', 'province_id'])
                ->map(fn (PsgcMunicipality $municipality) => [
                    'value' => $municipality->id,
                    'label' => $municipality->name,
                    'parent_id' => $municipality->province_id,
                ])
                ->all(),
            'barangay' => PsgcBarangay::query()
                ->orderBy('name')
                ->get(['id', 'name', 'municipality_id'])
                ->map(fn (PsgcBarangay $barangay) => [
                    'value' => $barangay->id,
                    'label' => $barangay->name,
                    'parent_id' => $barangay->municipality_id,
                ])
                ->all(),
        ];
    }
}
