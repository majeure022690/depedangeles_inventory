<?php

namespace Tests\Feature;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\AuditLog;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspAccount;
use App\Models\IspProvider;
use App\Models\IspSubscriptionCost;
use App\Models\StakeholderLibrary;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InternetConnectivitySurvey's fields (lookup-normalization ADR, Step 2+3)
 * are validated against the real seeded reference tables (via
 * ReferenceDataSeeder) rather than the old Lookup/LookupType system, which
 * this resource no longer consults. `available_isps`/`subscribed_isps` are
 * now arrays of `isp_providers` row ids (Tier 1). `mobile_signal_types`/
 * `coverage_areas` are arrays of `connectivity_libraries` row ids —
 * activating the MobileNetworkSignal/CoverageArea types for the first
 * time. `electricity_sources` is an array of `stakeholder_libraries` row
 * ids (a deliberate cross-domain read).
 */
class InternetConnectivitySurveyControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * internet-connectivity-survey/Edit.vue doesn't exist yet (Frontend
     * builds it against the prop contract this controller establishes) —
     * same situation as IspAccountControllerTest/StakeholderProfileControllerTest.
     */
    private function inertiaGet(string $url): TestResponse
    {
        $manifest = public_path('build/manifest.json');
        $version = file_exists($manifest) ? hash_file('xxh128', $manifest) : null;

        return $this->get($url, array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ]));
    }

    private function assertInertiaJson(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode($response->getContent(), true);
    }

    /**
     * IspAccountFactory's literal string values already match the real
     * seeded ConnectivityLibrary/EquipmentLibrary rows without needing to
     * query them (see IspAccountFactory's own doc-comment) and the Tier 1
     * `isp_provider_id` FK defaults to null — none of that matters here
     * anyway, since this helper only feeds InternetConnectivitySurvey's
     * live-computed aggregate fields (cost/rooms/access-points), which
     * don't depend on any lookup-backed column at all. No reference-data
     * seeding is required to use this helper.
     */
    private function makeIspAccount(array $overrides = []): IspAccount
    {
        return IspAccount::factory()->create($overrides);
    }

    public function test_viewer_can_view_but_not_update(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('viewer');

        $page = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('internet-connectivity-survey.edit'))
        );
        $this->assertSame('internet-connectivity-survey/Edit', $page['component']);

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [])
            ->assertForbidden();
    }

    public function test_encoder_can_view_and_update(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-survey.edit'))->assertOk();

        $pldtId = IspProvider::where('name', 'PLDT')->value('id');

        $response = $this->actingAs($user)->put(route('internet-connectivity-survey.update'), [
            'has_isp_in_area' => true,
            'available_isps' => [$pldtId],
            'rooms_other_use' => 3,
        ]);

        $response->assertRedirect(route('internet-connectivity-survey.edit'));
        $response->assertSessionHasNoErrors();

        $survey = InternetConnectivitySurvey::sole();
        $this->assertTrue($survey->has_isp_in_area);
        $this->assertSame([$pldtId], $survey->available_isps);
        $this->assertSame(3, $survey->rooms_other_use);
    }

    public function test_admin_can_view_and_update(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-survey.edit'))->assertOk();

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), ['frequent_brownouts' => true])
            ->assertRedirect(route('internet-connectivity-survey.edit'));

        $this->assertTrue(InternetConnectivitySurvey::sole()->frequent_brownouts);
    }

    public function test_pending_role_gets_403(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('pending');

        $this->actingAs($user)->get(route('internet-connectivity-survey.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('internet-connectivity-survey.update'), [])->assertForbidden();

        // Authorization must be checked BEFORE the singleton row is
        // created — a zero-permission user's forbidden requests must not
        // have any write side effect.
        $this->assertSame(0, InternetConnectivitySurvey::count());
    }

    public function test_update_writes_an_audit_log_entry_with_diffed_changes(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)->put(route('internet-connectivity-survey.update'), [
            'rooms_other_use' => 3,
        ])->assertSessionHasNoErrors();

        // assignRole() itself writes a 'role.assigned' AuditLog entry
        // (see User::assignRole), so filter to the action under test
        // rather than assuming this is the only row.
        $log = AuditLog::where('action', 'internet_connectivity_survey.updated')->sole();
        $this->assertSame('internet_connectivity_survey.updated', $log->action);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame(InternetConnectivitySurvey::sole()->id, $log->subject_id);
        $this->assertSame(InternetConnectivitySurvey::class, $log->subject_type);
        $this->assertSame(null, $log->properties['changes']['rooms_other_use']['old']);
        $this->assertSame(3, $log->properties['changes']['rooms_other_use']['new']);
        $this->assertArrayNotHasKey('updated_at', $log->properties['changes']);
    }

    public function test_update_rejects_oversized_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        // Deactivate every IspProvider row but one, so the field's max
        // drops to 1 — two distinct, otherwise-valid ids must then be
        // rejected by the array size cap, not the per-element existence
        // check.
        $ids = IspProvider::query()->active()->pluck('id');
        IspProvider::whereIn('id', $ids->slice(1))->update(['is_active' => false]);

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [
                'available_isps' => $ids->take(2)->all(),
            ])
            ->assertSessionHasErrors(['available_isps']);
    }

    public function test_validation_rejects_a_nonexistent_isp_provider_id_in_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [
                'available_isps' => [999999],
            ])
            ->assertSessionHasErrors(['available_isps.0']);
    }

    /**
     * mobile_signal_types/coverage_areas are both connectivity_libraries
     * ids, but scoped to different types (MobileNetworkSignal vs
     * CoverageArea respectively) — a real, active row of the wrong type
     * must be rejected, proving the Rule::exists() is scoped by `type`.
     */
    public function test_validation_rejects_a_connectivity_library_id_of_the_wrong_type_in_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $wrongTypeId = ConnectivityLibrary::where('type', ConnectivityLibraryType::CoverageArea)
            ->active()
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [
                'mobile_signal_types' => [$wrongTypeId],
            ])
            ->assertSessionHasErrors(['mobile_signal_types.0']);
    }

    /**
     * `electricity_sources` is a deliberate cross-domain read of
     * `stakeholder_libraries` (StakeholderLibraryType::SourceOfElectricity)
     * — proves a real, active row of that type is accepted, and a real row
     * of a different StakeholderLibraryType is rejected.
     */
    public function test_electricity_sources_validates_against_the_shared_stakeholder_library_table(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('encoder');

        $sourceOfElectricityId = StakeholderLibrary::where('type', StakeholderLibraryType::SourceOfElectricity)
            ->where('value', 'Grid')
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [
                'electricity_sources' => [$sourceOfElectricityId],
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(
            [$sourceOfElectricityId],
            InternetConnectivitySurvey::sole()->electricity_sources
        );

        $wrongTypeId = StakeholderLibrary::where('type', StakeholderLibraryType::GovernanceLevel)
            ->active()
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-survey.update'), [
                'electricity_sources' => [$wrongTypeId],
            ])
            ->assertSessionHasErrors(['electricity_sources.0']);
    }

    public function test_aggregate_fields_are_never_writable_and_are_computed_live_on_edit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $accountOne = $this->makeIspAccount([
            'cost_per_month' => 5000,
            'number_admin_area_covered' => 2,
            'number_classrooms_covered' => 10,
            'number_access_points_linked' => 4,
        ]);
        $accountTwo = $this->makeIspAccount([
            'isp_billing_account_no' => 'BA-0002',
            'cost_per_month' => 3000,
            'number_admin_area_covered' => 1,
            'number_classrooms_covered' => 5,
            'number_access_points_linked' => 2,
        ]);

        IspSubscriptionCost::factory()->create([
            'isp_account_id' => $accountOne->id,
            'total_amount_spent' => 60000,
            'total_projected_expenditure' => 65000,
        ]);
        IspSubscriptionCost::factory()->create([
            'isp_account_id' => $accountTwo->id,
            'total_amount_spent' => 36000,
            'total_projected_expenditure' => 40000,
        ]);

        // Attempting to feed a "protected" aggregate field through the
        // update payload must be silently ignored — it is not a column on
        // internet_connectivity_surveys and not in the Form Request's
        // validated() data at all, so it never reaches the database.
        $this->actingAs($user)->put(route('internet-connectivity-survey.update'), [
            'total_isps' => 999,
        ])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('total_isps', InternetConnectivitySurvey::sole()->getAttributes());

        $page = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('internet-connectivity-survey.edit'))
        );

        // Numeric aggregates are asserted with assertEquals rather than
        // assertSame for the monetary totals: they round-trip through the
        // Inertia JSON response, and PHP's json_encode renders a
        // whole-number float (e.g. 8000.0) without a decimal point, so
        // json_decode hands the test an int even though the controller
        // cast the value to float — a JSON-transport artifact, not a
        // controller bug.
        $aggregates = $page['props']['aggregates'];
        $this->assertSame(2, $aggregates['total_isps']);
        $this->assertEquals(8000.0, $aggregates['total_cost_per_month']);
        $this->assertEquals(96000.0, $aggregates['total_amount_spent']);
        $this->assertEquals(105000.0, $aggregates['total_projected_expenditure']);
        $this->assertSame(3, $aggregates['rooms_covered_admin']);
        $this->assertSame(15, $aggregates['rooms_covered_classroom']);
        $this->assertSame(6, $aggregates['total_access_points']);
    }
}
