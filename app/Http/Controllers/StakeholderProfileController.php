<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Http\Controllers\Concerns\HasLookupOptions;
use App\Http\Requests\StakeholderProfileUpdateRequest;
use App\Models\AuditLog;
use App\Models\EquipmentLibrary;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SINGLETON controller (see App\Models\StakeholderProfile) — deliberately
 * only edit()/update(), never a resource controller. There is exactly one
 * row for the whole application, fetched/created via
 * `StakeholderProfile::firstOrCreate([])`; no index/paginated listing, no
 * create()/store() that would produce a second row, no destroy().
 */
class StakeholderProfileController extends Controller
{
    use HasLookupOptions;

    public function edit(): Response
    {
        // Authorize against a transient (unsaved) instance BEFORE touching
        // the DB — StakeholderProfilePolicy never inspects the model
        // instance (only hasPermissionTo()), so this is authorization-
        // equivalent to the persisted row, but a `pending`-role user's GET
        // no longer creates the singleton row before being 403'd.
        $this->authorize('view', new StakeholderProfile);

        $stakeholderProfile = StakeholderProfile::firstOrCreate([]);

        return Inertia::render('stakeholder-profile/Edit', [
            'stakeholderProfile' => $this->stakeholderProfileProps($stakeholderProfile),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(StakeholderProfileUpdateRequest $request): RedirectResponse
    {
        $this->authorize('update', new StakeholderProfile);

        $stakeholderProfile = StakeholderProfile::firstOrCreate([]);

        $before = $stakeholderProfile->getOriginal();

        $stakeholderProfile->update($request->validated());

        $changes = collect($stakeholderProfile->getChanges())
            ->except(['updated_at'])
            ->mapWithKeys(fn ($new, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $new]])
            ->all();

        AuditLog::record('stakeholder_profile.updated', $stakeholderProfile, ['changes' => $changes]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stakeholder profile updated.')]);

        return to_route('stakeholder-profile.edit');
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

            'province' => $stakeholderProfile->province,
            'city_municipality' => $stakeholderProfile->city_municipality,
            'legislative_district' => $stakeholderProfile->legislative_district,
            'barangay' => $stakeholderProfile->barangay,
            'street' => $stakeholderProfile->street,
            'psgc' => $stakeholderProfile->psgc,
            // Read-only: DB-generated (STORED) column, never part of the
            // writable Form Request payload.
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
        ];
    }
}
