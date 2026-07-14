<?php

namespace Database\Seeders;

use App\Enums\StakeholderLibraryType;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Seeds ONE `stakeholder_profiles` row from
 * database/seeders/data/stakeholder_profile.json — the division's answers
 * to the source Excel workbook's single-record "Stakeholder Profile" sheet.
 *
 * OFFICE ATTRIBUTION — the source sheet describes the Schools Division
 * Office as a whole (RO=III, SDO=Angeles City, chief = the Schools
 * Division Superintendent), but stakeholder_profiles is now office-scoped
 * (one row per specific office/unit, not a global singleton — see
 * make_stakeholder_profiles_office_scoped's migration doc-comment) and the
 * seeded `offices` table (imported from a separate legacy source, not this
 * workbook) has no single row representing the division as one entity,
 * only its ~20 specific sub-units/schools. Confirmed with the user: this
 * profile attaches to office_id 74
 * ("OSDS-OFFICE OF THE SCHOOLS DIVISION SUPERINTENDENT"), the closest real
 * match — that unit literally is the Schools Division Superintendent's
 * office the sheet's "RO/SDO Chief" field describes.
 *
 * `chief_name`/`admin_staff_name`/`network_administrator_name` are stored
 * as the source's literal freeform strings (e.g.
 * "DOMINGO, EDGARD C - 4341529") — NOT resolved against `personnel`, per
 * the create_stakeholder_profiles_table migration's explicit note that
 * these are deliberately plain text, not a validated FK.
 *
 * SAFETY — firstOrCreate keyed on office_id (unique on the table): if a
 * profile already exists for office 74, this is skipped entirely rather
 * than overwritten, to avoid clobbering real admin work — same
 * "don't guess which fields are safe to fill" caution
 * InternetConnectivitySurveySeeder uses for its own singleton-style row.
 */
class StakeholderProfileSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/stakeholder_profile.json');

        if (! File::exists($path)) {
            throw new RuntimeException("Stakeholder profile seed file not found at [{$path}].");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $existing = StakeholderProfile::where('office_id', $data['office_id'])->exists();

        if ($existing) {
            $this->command?->info(sprintf(
                'stakeholder_profiles: a profile for office_id=%d already exists — left untouched.',
                $data['office_id']
            ));

            return;
        }

        StakeholderProfile::create([
            'office_id' => $data['office_id'],
            'governance_level' => $data['governance_level'],
            'ro' => $data['ro'],
            'sdo' => $data['sdo'],

            'school_district' => $data['school_district'],
            'school_name' => $data['school_name'],
            'school_id' => $data['school_id'],

            'province_id' => $data['province_id'],
            'municipality_id' => $data['municipality_id'],
            'legislative_district' => $data['legislative_district'],
            'barangay_id' => $data['barangay_id'],
            'street' => $data['street'],

            'notes_corrections' => $data['notes_corrections'],
            'notes_recent_development' => $data['notes_recent_development'],

            'mobile_1' => $data['mobile_1'],
            'mobile_2' => $data['mobile_2'],
            'landline' => $data['landline'],

            'chief_name' => $data['chief_name'],
            'chief_position' => $data['chief_position'],
            'chief_email' => $data['chief_email'],
            'chief_mobile' => $data['chief_mobile'],

            'admin_staff_name' => $data['admin_staff_name'],
            'admin_staff_position' => $data['admin_staff_position'],
            'admin_staff_email' => $data['admin_staff_email'],
            'admin_staff_mobile' => $data['admin_staff_mobile'],

            'network_administrator_name' => $data['network_administrator_name'],

            'longitude' => $data['longitude'],
            'latitude' => $data['latitude'],

            'nearby_institutions' => $this->resolveLibraryIds(StakeholderLibraryType::NearbyInstitution, $data['nearby_institution_names']),
            'nearby_institutions_other' => $data['nearby_institutions_other'],

            'travel_time_to_center_minutes' => $data['travel_time_to_center_minutes'],

            'access_paths' => $this->resolveLibraryIds(StakeholderLibraryType::TypeOfAccessRoad, $data['access_path_names']),

            'transportation_options' => $this->resolveLibraryIds(StakeholderLibraryType::ByTransportation, $data['transportation_option_names']),
            'transportation_other' => $data['transportation_other'],

            'transportation_difficult' => $data['transportation_difficult'],
            'considered_very_remote' => $data['considered_very_remote'],
            'remote_context_notes' => $data['remote_context_notes'],

            'gidca' => $data['gidca'],
            'lms' => $data['lms'],

            'community_engagement' => $this->resolveLibraryIds(StakeholderLibraryType::CommunityEngagement, $data['community_engagement_names']),
            'community_context_remarks' => $data['community_context_remarks'],

            'submitted_at' => $data['submitted_at'],
            'transaction_type' => $data['transaction_type'],
        ]);

        $this->command?->info('stakeholder_profiles: profile seeded for office_id='.$data['office_id'].' from source workbook.');
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>|null
     */
    private function resolveLibraryIds(StakeholderLibraryType $type, array $names): ?array
    {
        if ($names === []) {
            return null;
        }

        $ids = StakeholderLibrary::where('type', $type->value)->whereIn('value', $names)->pluck('id', 'value');

        foreach ($names as $name) {
            if (! $ids->has($name)) {
                throw new RuntimeException("stakeholder_profile seed: [{$type->value}] value [{$name}] not found.");
            }
        }

        return $ids->only($names)->values()->all();
    }
}
