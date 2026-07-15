<?php

namespace Tests\Feature;

use App\Enums\ConnectivityLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\AuditLog;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InternetConnectivitySurvey is one row PER OFFICE (not a global singleton)
 * — every test here creates its own Office row(s) and scopes users to one
 * via `office_id`, mirroring StakeholderProfileControllerTest. Its fields
 * (lookup-normalization ADR, Step 2+3) are validated against the real
 * seeded reference tables (via ReferenceDataSeeder) rather than the old
 * Lookup/LookupType system, which this resource no longer consults.
 * `available_isps`/`subscribed_isps` are arrays of `isp_providers` row ids
 * (Tier 1). `mobile_signal_types`/`coverage_areas` are arrays of
 * `connectivity_libraries` row ids. `electricity_sources` is an array of
 * `stakeholder_libraries` row ids (a deliberate cross-domain read).
 */
class InternetConnectivitySurveyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createOffice(array $overrides = []): Office
    {
        return Office::create(array_merge(['office_name' => 'Test Elementary School'], $overrides));
    }

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

    public function test_viewer_can_view_own_office_but_not_update(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('viewer');

        $page = $this->assertInertiaJson(
            $this->actingAs($user)->inertiaGet(route('internet-connectivity-surveys.edit', $office))
        );
        $this->assertSame('internet-connectivity-survey/Edit', $page['component']);

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [])
            ->assertForbidden();
    }

    public function test_encoder_can_view_and_update_own_office(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-surveys.edit', $office))->assertOk();

        $pldtId = IspProvider::where('name', 'PLDT')->value('id');

        $response = $this->actingAs($user)->put(route('internet-connectivity-surveys.update', $office), [
            'has_isp_in_area' => true,
            'available_isps' => [$pldtId],
            'rooms_other_use' => 3,
        ]);

        $response->assertRedirect(route('internet-connectivity-surveys.edit', $office));
        $response->assertSessionHasNoErrors();

        $survey = InternetConnectivitySurvey::where('office_id', $office->id)->sole();
        $this->assertTrue($survey->has_isp_in_area);
        $this->assertSame([$pldtId], $survey->available_isps);
        $this->assertSame(3, $survey->rooms_other_use);
    }

    public function test_encoder_cannot_view_or_update_a_different_office(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $myOffice = $this->createOffice(['office_name' => 'My School']);
        $otherOffice = $this->createOffice(['office_name' => 'Other School']);
        $user = User::factory()->create(['office_id' => $myOffice->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->get(route('internet-connectivity-surveys.edit', $otherOffice))->assertForbidden();
        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $otherOffice), ['rooms_other_use' => 3])
            ->assertForbidden();

        // No side effect: the other office's survey is never created by a
        // forbidden request.
        $this->assertSame(0, InternetConnectivitySurvey::where('office_id', $otherOffice->id)->count());
    }

    public function test_encoder_with_no_office_gets_403_on_any_office(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => null]);
        $user->assignRole('encoder');

        $this->actingAs($user)->get(route('internet-connectivity-surveys.edit', $office))->assertForbidden();
    }

    public function test_admin_can_view_and_update_any_office_via_view_all(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        // Deliberately no office_id on the admin -- view_all must not
        // depend on the actor having one of their own.
        $user = User::factory()->create(['office_id' => null]);
        $user->assignRole('admin');

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-surveys.edit', $office))->assertOk();

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), ['frequent_brownouts' => true])
            ->assertRedirect(route('internet-connectivity-surveys.edit', $office));

        $this->assertTrue(InternetConnectivitySurvey::where('office_id', $office->id)->sole()->frequent_brownouts);
    }

    public function test_only_view_all_holder_can_access_the_index_list(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->inertiaGet(route('internet-connectivity-surveys.index'))->assertOk();

        $encoder = User::factory()->create(['office_id' => $office->id]);
        $encoder->assignRole('encoder');
        $this->actingAs($encoder)->get(route('internet-connectivity-surveys.index'))->assertForbidden();
    }

    public function test_index_lists_offices_with_and_without_a_survey(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $withSurvey = $this->createOffice(['office_name' => 'Has Survey ES']);
        $withoutSurvey = $this->createOffice(['office_name' => 'No Survey ES']);
        InternetConnectivitySurvey::create(['office_id' => $withSurvey->id, 'rooms_other_use' => 2]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $page = $this->assertInertiaJson($this->actingAs($admin)->inertiaGet(route('internet-connectivity-surveys.index')));

        $rows = collect($page['props']['offices']['data']);
        $this->assertTrue($rows->firstWhere('id', $withSurvey->id)['has_survey']);
        $this->assertFalse($rows->firstWhere('id', $withoutSurvey->id)['has_survey']);
    }

    public function test_pending_role_gets_403(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('pending');

        $this->actingAs($user)->get(route('internet-connectivity-surveys.edit', $office))->assertForbidden();
        $this->actingAs($user)->put(route('internet-connectivity-surveys.update', $office), [])->assertForbidden();

        // Authorization must be checked BEFORE the row is created — a
        // zero-permission user's forbidden requests must not have any
        // write side effect.
        $this->assertSame(0, InternetConnectivitySurvey::count());
    }

    public function test_update_writes_an_audit_log_entry_with_diffed_changes(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->put(route('internet-connectivity-surveys.update', $office), [
            'rooms_other_use' => 3,
        ])->assertSessionHasNoErrors();

        // assignRole() itself writes a 'role.assigned' AuditLog entry
        // (see User::assignRole), so filter to the action under test
        // rather than assuming this is the only row.
        $log = AuditLog::where('action', 'internet_connectivity_survey.updated')->sole();
        $survey = InternetConnectivitySurvey::where('office_id', $office->id)->sole();
        $this->assertSame('internet_connectivity_survey.updated', $log->action);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($survey->id, $log->subject_id);
        $this->assertSame(InternetConnectivitySurvey::class, $log->subject_type);
        $this->assertSame(null, $log->properties['changes']['rooms_other_use']['old']);
        $this->assertSame(3, $log->properties['changes']['rooms_other_use']['new']);
        $this->assertArrayNotHasKey('updated_at', $log->properties['changes']);
    }

    public function test_update_rejects_oversized_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        // Deactivate every IspProvider row but one, so the field's max
        // drops to 1 — two distinct, otherwise-valid ids must then be
        // rejected by the array size cap, not the per-element existence
        // check.
        $ids = IspProvider::query()->active()->pluck('id');
        IspProvider::whereIn('id', $ids->slice(1))->update(['is_active' => false]);

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [
                'available_isps' => $ids->take(2)->all(),
            ])
            ->assertSessionHasErrors(['available_isps']);
    }

    public function test_validation_rejects_a_nonexistent_isp_provider_id_in_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [
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
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $wrongTypeId = ConnectivityLibrary::where('type', ConnectivityLibraryType::CoverageArea)
            ->active()
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [
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
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $sourceOfElectricityId = StakeholderLibrary::where('type', StakeholderLibraryType::SourceOfElectricity)
            ->where('value', 'Grid')
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [
                'electricity_sources' => [$sourceOfElectricityId],
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(
            [$sourceOfElectricityId],
            InternetConnectivitySurvey::where('office_id', $office->id)->sole()->electricity_sources
        );

        $wrongTypeId = StakeholderLibrary::where('type', StakeholderLibraryType::GovernanceLevel)
            ->active()
            ->value('id');

        $this->actingAs($user)
            ->put(route('internet-connectivity-surveys.update', $office), [
                'electricity_sources' => [$wrongTypeId],
            ])
            ->assertSessionHasErrors(['electricity_sources.0']);
    }

    public function test_first_or_create_creates_once_per_office_and_reuses_the_same_row(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->assertSame(0, InternetConnectivitySurvey::count());

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-surveys.edit', $office))->assertOk();
        $this->assertSame(1, InternetConnectivitySurvey::count());
        $firstId = InternetConnectivitySurvey::where('office_id', $office->id)->sole()->id;

        $this->actingAs($user)->inertiaGet(route('internet-connectivity-surveys.edit', $office))->assertOk();
        $this->assertSame(1, InternetConnectivitySurvey::count());

        $this->actingAs($user)->put(route('internet-connectivity-surveys.update', $office), ['rooms_other_use' => 4]);
        $this->assertSame(1, InternetConnectivitySurvey::count());
        $this->assertSame($firstId, InternetConnectivitySurvey::where('office_id', $office->id)->sole()->id);
    }
}
