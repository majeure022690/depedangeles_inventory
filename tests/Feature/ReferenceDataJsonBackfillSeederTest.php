<?php

namespace Tests\Feature;

use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\Personnel;
use App\Models\PersonnelLibrary;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use Database\Seeders\ReferenceDataJsonBackfillSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves ReferenceDataJsonBackfillSeeder's array-content migration logic
 * (value-strings -> reference-table ids) against realistic data, per the
 * lookup-normalization ADR's Question 4 / Step 1.5. Production
 * StakeholderProfile/InternetConnectivitySurvey singletons are currently
 * blank and Personnel has 0 real rows, so there is genuinely nothing to
 * migrate in the real `inventory` database today — this test is what
 * proves the logic is correct before any future real data depends on it.
 */
class ReferenceDataJsonBackfillSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_migrates_personnel_fund_source_values_to_personnel_library_ids(): void
    {
        $fund = PersonnelLibrary::query()->active()->where('type', 'teachers_funding_source')->first();

        $personnel = Personnel::factory()->create([
            'fund_source' => [$fund->value, 'A Typo Not In The Seeded List'],
        ]);

        $result = (new ReferenceDataJsonBackfillSeeder)->backfillJsonColumn(
            'personnel', 'fund_source', 'personnel_libraries', 'teachers_funding_source'
        );

        $this->assertSame(1, $result['matched']);
        $this->assertArrayHasKey('A Typo Not In The Seeded List', $result['unmatched']);

        $personnel->refresh();

        $this->assertSame([$fund->id, 'A Typo Not In The Seeded List'], $personnel->fund_source);
    }

    public function test_migrates_all_four_stakeholder_profile_json_columns(): void
    {
        $nearby = StakeholderLibrary::query()->active()->where('type', 'nearby_institution')->first();
        $accessPath = StakeholderLibrary::query()->active()->where('type', 'type_of_access_road')->first();
        $transport = StakeholderLibrary::query()->active()->where('type', 'by_transportation')->first();
        $engagement = StakeholderLibrary::query()->active()->where('type', 'community_engagement')->first();

        $profile = StakeholderProfile::create([
            'nearby_institutions' => [$nearby->value],
            'access_paths' => [$accessPath->value],
            'transportation_options' => [$transport->value],
            'community_engagement' => [$engagement->value],
        ]);

        (new ReferenceDataJsonBackfillSeeder)->run();

        $profile->refresh();

        $this->assertSame([$nearby->id], $profile->nearby_institutions);
        $this->assertSame([$accessPath->id], $profile->access_paths);
        $this->assertSame([$transport->id], $profile->transportation_options);
        $this->assertSame([$engagement->id], $profile->community_engagement);
    }

    public function test_migrates_internet_connectivity_survey_json_columns_across_two_reference_tables(): void
    {
        $provider = IspProvider::query()->active()->first();
        $mobileSignal = ConnectivityLibrary::query()->active()->where('type', 'mobile_network_signal')->first();
        $coverageArea = ConnectivityLibrary::query()->active()->where('type', 'coverage_area')->first();
        $electricity = StakeholderLibrary::query()->active()->where('type', 'source_of_electricity')->first();

        $survey = InternetConnectivitySurvey::create([
            'available_isps' => [$provider->name],
            'subscribed_isps' => [$provider->name],
            'mobile_signal_types' => [$mobileSignal->value],
            'coverage_areas' => [$coverageArea->value],
            'electricity_sources' => [$electricity->value],
        ]);

        (new ReferenceDataJsonBackfillSeeder)->run();

        $survey->refresh();

        $this->assertSame([$provider->id], $survey->available_isps);
        $this->assertSame([$provider->id], $survey->subscribed_isps);
        $this->assertSame([$mobileSignal->id], $survey->mobile_signal_types);
        $this->assertSame([$coverageArea->id], $survey->coverage_areas);
        $this->assertSame([$electricity->id], $survey->electricity_sources);
    }

    public function test_is_idempotent_and_leaves_already_migrated_ids_untouched(): void
    {
        $fundQuery = PersonnelLibrary::query()->active()->where('type', 'teachers_funding_source');
        $fund = (clone $fundQuery)->first();
        $otherFund = (clone $fundQuery)->skip(1)->first();

        $personnel = Personnel::factory()->create(['fund_source' => [$fund->value]]);

        (new ReferenceDataJsonBackfillSeeder)->run();
        $this->assertSame([$fund->id], $personnel->refresh()->fund_source);

        // Simulate an admin manually editing the array to point at a
        // different reference row after migration.
        $personnel->update(['fund_source' => [$otherFund->id]]);

        $result = (new ReferenceDataJsonBackfillSeeder)->backfillJsonColumn(
            'personnel', 'fund_source', 'personnel_libraries', 'teachers_funding_source'
        );

        // No elements were strings, so nothing was looked up/changed.
        $this->assertSame(0, $result['matched']);
        $this->assertSame([], $result['unmatched']);
        $this->assertSame([$otherFund->id], $personnel->refresh()->fund_source);
    }

    public function test_no_op_on_null_or_empty_json_columns(): void
    {
        Personnel::factory()->create(['fund_source' => null]);

        $result = (new ReferenceDataJsonBackfillSeeder)->backfillJsonColumn(
            'personnel', 'fund_source', 'personnel_libraries', 'teachers_funding_source'
        );

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['rows_updated']);
    }
}
