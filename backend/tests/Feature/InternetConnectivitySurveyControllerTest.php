<?php

namespace Tests\Feature;

use App\Enums\ConnectivityLibraryType;
use App\Enums\Permission;
use App\Enums\StakeholderLibraryType;
use App\Models\ConnectivityLibrary;
use App\Models\InternetConnectivitySurvey;
use App\Models\IspProvider;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * One survey PER OFFICE, same office scoping and pre-create authorization
 * as the Stakeholder Profile. `available_isps`/`subscribed_isps` store
 * isp_providers ids; `mobile_signal_types`/`coverage_areas`
 * connectivity_libraries ids; `electricity_sources` stakeholder_libraries ids.
 */
class InternetConnectivitySurveyControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOffice(array $overrides = []): Office
    {
        return Office::create(array_merge(['office_name' => 'Test Elementary School'], $overrides));
    }

    private function userAt(string $role, ?Office $office): User
    {
        return $this->userWithRole($role, ['office_id' => $office?->id]);
    }

    private function url(Office $office): string
    {
        return "/api/internet-connectivity-surveys/{$office->id}";
    }

    public function test_viewer_can_view_own_office_but_not_update(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('viewer', $office);

        $this->actingAs($user)->getJson($this->url($office))
            ->assertOk()
            ->assertJsonPath('data.office.id', $office->id)
            ->assertJsonPath('data.survey.office_id', $office->id)
            ->assertJsonMissingPath('data.aggregates');

        $this->actingAs($user)->putJson($this->url($office), [])->assertForbidden();
    }

    public function test_encoder_can_view_and_update_own_office(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);
        $pldtId = IspProvider::where('name', 'PLDT')->value('id');

        $this->actingAs($user)->getJson($this->url($office))->assertOk();

        $this->actingAs($user)->putJson($this->url($office), [
            'has_isp_in_area' => true,
            'available_isps' => [$pldtId],
            'rooms_other_use' => 3,
        ])->assertOk()
            ->assertJsonPath('data.survey.has_isp_in_area', true)
            ->assertJsonPath('data.survey.available_isps', [$pldtId])
            ->assertJsonPath('data.survey.rooms_other_use', 3);

        $survey = InternetConnectivitySurvey::where('office_id', $office->id)->sole();
        $this->assertTrue($survey->has_isp_in_area);
        $this->assertSame([$pldtId], $survey->available_isps);
        $this->assertSame(3, $survey->rooms_other_use);
    }

    public function test_encoder_cannot_view_or_update_a_different_office(): void
    {
        $myOffice = $this->createOffice(['office_name' => 'My School']);
        $otherOffice = $this->createOffice(['office_name' => 'Other School']);
        $user = $this->userAt('encoder', $myOffice);

        $this->actingAs($user)->getJson($this->url($otherOffice))->assertForbidden();
        $this->actingAs($user)->putJson($this->url($otherOffice), ['rooms_other_use' => 1])->assertForbidden();

        $this->assertSame(0, InternetConnectivitySurvey::where('office_id', $otherOffice->id)->count());
    }

    public function test_encoder_with_no_office_gets_403_on_any_office(): void
    {
        $this->actingAs($this->userAt('encoder', null))->getJson($this->url($this->createOffice()))->assertForbidden();
    }

    public function test_admin_can_view_and_update_any_office_via_view_all(): void
    {
        $office = $this->createOffice();
        $user = $this->userWithPermissions([Permission::InternetConnectivityViewAll]);

        $this->actingAs($user)->getJson($this->url($office))->assertOk();
        $this->actingAs($user)->putJson($this->url($office), ['rooms_other_use' => 2])->assertOk();

        $this->assertSame(2, InternetConnectivitySurvey::where('office_id', $office->id)->sole()->rooms_other_use);
    }

    public function test_only_view_all_holder_can_access_the_index_list(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userWithPermissions([Permission::InternetConnectivityViewAll]))->getJson('/api/internet-connectivity-surveys')->assertOk();
        $this->actingAs($this->userAt('encoder', $office))->getJson('/api/internet-connectivity-surveys')->assertForbidden();
    }

    public function test_index_lists_offices_with_and_without_a_survey(): void
    {
        $withSurvey = $this->createOffice(['office_name' => 'Has Survey ES']);
        $withoutSurvey = $this->createOffice(['office_name' => 'No Survey ES']);
        InternetConnectivitySurvey::create(['office_id' => $withSurvey->id, 'rooms_other_use' => 1]);

        $response = $this->actingAs($this->userWithPermissions([Permission::InternetConnectivityViewAll]))->getJson('/api/internet-connectivity-surveys')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'office_name', 'office_type', 'school_id', 'has_survey', 'updated_at']], 'meta', 'links']);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('id', $withSurvey->id)['has_survey']);
        $this->assertFalse($rows->firstWhere('id', $withoutSurvey->id)['has_survey']);
    }

    public function test_index_still_shows_not_started_after_show_but_nothing_saved(): void
    {
        $office = $this->createOffice(['office_name' => 'Just Viewed ES']);
        $admin = $this->userWithPermissions([Permission::InternetConnectivityViewAll]);

        $this->actingAs($admin)->getJson($this->url($office))->assertOk();
        $this->assertDatabaseMissing('internet_connectivity_surveys', ['office_id' => $office->id]);
        $this->assertSame(0, Activity::query()->where('subject_type', InternetConnectivitySurvey::class)->count());

        $row = collect($this->actingAs($admin)->getJson('/api/internet-connectivity-surveys')->json('data'))->firstWhere('id', $office->id);
        $this->assertFalse($row['has_survey']);
        $this->assertNull($row['updated_at']);
    }

    public function test_holder_of_an_unrelated_permission_gets_403_without_creating_a_row(): void
    {
        $office = $this->createOffice();
        $user = $this->userWithPermissions([Permission::EquipmentView], ['office_id' => $office->id]);

        $this->actingAs($user)->getJson($this->url($office))->assertForbidden()->assertJsonMissingPath('code');
        $this->actingAs($user)->putJson($this->url($office), [])->assertForbidden()->assertJsonMissingPath('code');
        $this->actingAs($user)->getJson('/api/internet-connectivity-surveys/options')->assertForbidden()->assertJsonMissingPath('code');

        $this->assertSame(0, InternetConnectivitySurvey::count());
    }

    public function test_update_writes_an_audit_entry_with_diffed_changes(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->actingAs($user)->putJson($this->url($office), ['rooms_other_use' => 3])->assertOk();

        $survey = InternetConnectivitySurvey::where('office_id', $office->id)->sole();
        $activity = Activity::query()->where('subject_type', InternetConnectivitySurvey::class)->sole();

        $this->assertSame('internet_connectivity_survey.updated', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($survey->id, $activity->subject_id);
        $this->assertNull($activity->attribute_changes['old']['rooms_other_use']);
        $this->assertSame(3, $activity->attribute_changes['attributes']['rooms_other_use']);
        $this->assertArrayNotHasKey('updated_at', $activity->attribute_changes['attributes']);
    }

    public function test_options_ship_id_valued_checkbox_lists(): void
    {
        $office = $this->createOffice();

        $response = $this->actingAs($this->userAt('viewer', $office))->getJson('/api/internet-connectivity-surveys/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['isp_provider', 'signal_quality', 'mobile_network_signal', 'coverage_area', 'source_of_electricity']]);

        $this->assertIsInt($response->json('data.isp_provider.0.value'));
        $this->assertIsInt($response->json('data.coverage_area.0.value'));
        $this->assertIsString($response->json('data.signal_quality.0.value'));
    }

    public function test_update_rejects_oversized_array_field(): void
    {
        $office = $this->createOffice();
        $ids = IspProvider::query()->active()->pluck('id');
        IspProvider::whereIn('id', $ids->slice(1))->update(['is_active' => false]);

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson($this->url($office), ['available_isps' => $ids->take(2)->all()])
            ->assertJsonValidationErrors(['available_isps']);
    }

    public function test_validation_rejects_a_nonexistent_isp_provider_id_in_array_field(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson($this->url($office), ['available_isps' => [999999]])
            ->assertJsonValidationErrors(['available_isps.0']);
    }

    public function test_validation_rejects_a_connectivity_library_id_of_the_wrong_type_in_array_field(): void
    {
        $office = $this->createOffice();
        $wrongTypeId = ConnectivityLibrary::where('type', ConnectivityLibraryType::CoverageArea)->active()->value('id');

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson($this->url($office), ['mobile_signal_types' => [$wrongTypeId]])
            ->assertJsonValidationErrors(['mobile_signal_types.0']);
    }

    public function test_electricity_sources_validates_against_the_shared_stakeholder_library_table(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $gridId = StakeholderLibrary::where('type', StakeholderLibraryType::SourceOfElectricity)->where('value', 'Grid')->value('id');

        $this->actingAs($user)->putJson($this->url($office), ['electricity_sources' => [$gridId]])->assertOk();
        $this->assertSame([$gridId], InternetConnectivitySurvey::where('office_id', $office->id)->sole()->electricity_sources);

        $wrongTypeId = StakeholderLibrary::where('type', StakeholderLibraryType::GovernanceLevel)->active()->value('id');

        $this->actingAs($user)->putJson($this->url($office), ['electricity_sources' => [$wrongTypeId]])
            ->assertJsonValidationErrors(['electricity_sources.0']);
    }

    public function test_show_does_not_persist_and_the_first_update_creates_the_single_row(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->actingAs($user)->getJson($this->url($office))->assertOk()
            ->assertJsonPath('data.survey.id', null)
            ->assertJsonPath('data.survey.office_id', $office->id);
        $this->assertSame(0, InternetConnectivitySurvey::count());

        $id = $this->actingAs($user)->putJson($this->url($office), ['rooms_other_use' => 4])->assertOk()->json('data.survey.id');
        $this->assertIsInt($id);

        $this->actingAs($user)->getJson($this->url($office))->assertJsonPath('data.survey.id', $id);
        $this->actingAs($user)->putJson($this->url($office), ['rooms_other_use' => 4])->assertJsonPath('data.survey.id', $id);

        $this->assertSame(1, InternetConnectivitySurvey::count());
    }

    public function test_update_reuses_a_row_another_request_created_first(): void
    {
        $office = $this->createOffice();
        $existing = InternetConnectivitySurvey::create(['office_id' => $office->id]);

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson($this->url($office), ['rooms_other_use' => 4])
            ->assertOk()
            ->assertJsonPath('data.survey.id', $existing->id);

        $this->assertSame(1, InternetConnectivitySurvey::count());
    }

    public function test_an_office_that_was_only_viewed_can_still_be_deleted(): void
    {
        $office = $this->createOffice();
        $admin = $this->userWithPermissions([Permission::InternetConnectivityViewAll, Permission::OfficeDelete]);

        $this->actingAs($admin)->getJson($this->url($office))->assertOk();
        $this->actingAs($admin)->deleteJson("/api/offices/{$office->id}")->assertNoContent();
        $this->assertModelMissing($office);
    }

    public function test_an_office_with_a_saved_row_is_still_protected_from_deletion(): void
    {
        $office = $this->createOffice();
        $admin = $this->userWithPermissions([Permission::InternetConnectivityViewAll, Permission::OfficeDelete]);

        $this->actingAs($admin)->putJson($this->url($office), ['rooms_other_use' => 4])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/offices/{$office->id}")->assertStatus(409);
        $this->assertModelExists($office);
    }
}
