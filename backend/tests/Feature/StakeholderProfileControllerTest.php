<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\PsgcBarangay;
use App\Models\PsgcMunicipality;
use App\Models\PsgcProvince;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * One profile PER OFFICE. `.view`/`.edit` reach only the user's own
 * office; `.view_all` reaches every office and the cross-office list.
 * Authorization runs against a transient model before firstOrCreate(),
 * so a forbidden request never creates a row. The checkbox-group fields
 * store stakeholder_libraries ids.
 */
class StakeholderProfileControllerTest extends TestCase
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

    private function createPsgcBarangay(): PsgcBarangay
    {
        $province = PsgcProvince::create(['code' => '030800000', 'name' => 'BATAAN']);
        $municipality = PsgcMunicipality::create(['code' => '030801000', 'province_id' => $province->id, 'name' => 'ABUCAY']);

        return PsgcBarangay::create(['code' => '030801001', 'municipality_id' => $municipality->id, 'name' => 'BANGKAL']);
    }

    public function test_viewer_can_view_own_office_but_not_update(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('viewer', $office);

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")
            ->assertOk()
            ->assertJsonPath('data.office.id', $office->id)
            ->assertJsonPath('data.profile.office_id', $office->id);

        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", [])->assertForbidden();
    }

    public function test_encoder_can_view_and_update_own_office(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")->assertOk();

        $institutionId = StakeholderLibrary::where('type', StakeholderLibraryType::NearbyInstitution)
            ->where('value', 'Barangay Hall')
            ->value('id');

        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", [
            'sdo' => 'SDO Angeles City',
            'chief_name' => 'DOMINGO, EDGARD C',
            'gidca' => true,
            'nearby_institutions' => [$institutionId],
        ])->assertOk()
            ->assertJsonPath('data.profile.sdo', 'SDO Angeles City')
            ->assertJsonPath('data.profile.gidca', true)
            ->assertJsonPath('data.profile.nearby_institutions', [$institutionId]);

        $profile = StakeholderProfile::where('office_id', $office->id)->sole();
        $this->assertSame('SDO Angeles City', $profile->sdo);
        $this->assertSame('DOMINGO, EDGARD C', $profile->chief_name);
        $this->assertTrue($profile->gidca);
        $this->assertSame([$institutionId], $profile->nearby_institutions);
    }

    public function test_show_exposes_the_owning_offices_school_id(): void
    {
        $office = $this->createOffice(['office_name' => 'Angeles ES', 'school_id' => 107022]);

        $this->actingAs($this->userWithRole('admin'))->getJson("/api/stakeholder-profiles/{$office->id}")
            ->assertOk()
            ->assertJsonPath('data.office', ['id' => $office->id, 'office_name' => 'Angeles ES', 'school_id' => 107022]);
    }

    public function test_encoder_cannot_view_or_update_a_different_office(): void
    {
        $myOffice = $this->createOffice(['office_name' => 'My School']);
        $otherOffice = $this->createOffice(['office_name' => 'Other School']);
        $user = $this->userAt('encoder', $myOffice);

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$otherOffice->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$otherOffice->id}", ['sdo' => 'Nope'])->assertForbidden();

        $this->assertSame(0, StakeholderProfile::where('office_id', $otherOffice->id)->count());
    }

    public function test_encoder_with_no_office_gets_403_on_any_office(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userAt('encoder', null))->getJson("/api/stakeholder-profiles/{$office->id}")->assertForbidden();
    }

    public function test_admin_can_view_and_update_any_office_via_view_all(): void
    {
        $office = $this->createOffice();
        // No office of their own: view_all must not depend on one.
        $user = $this->userAt('admin', null);

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")->assertOk();
        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", ['ro' => 'III'])->assertOk();

        $this->assertSame('III', StakeholderProfile::where('office_id', $office->id)->sole()->ro);
    }

    public function test_only_view_all_holder_can_access_the_index_list(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userWithRole('admin'))->getJson('/api/stakeholder-profiles')->assertOk();
        $this->actingAs($this->userAt('encoder', $office))->getJson('/api/stakeholder-profiles')->assertForbidden();
    }

    public function test_index_lists_offices_with_and_without_a_profile(): void
    {
        $withProfile = $this->createOffice(['office_name' => 'Has Profile ES']);
        $withoutProfile = $this->createOffice(['office_name' => 'No Profile ES']);
        StakeholderProfile::create(['office_id' => $withProfile->id, 'sdo' => 'SDO Angeles City']);

        $response = $this->actingAs($this->userWithRole('admin'))->getJson('/api/stakeholder-profiles')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'office_name', 'office_type', 'school_id', 'has_profile', 'updated_at']], 'meta', 'links']);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('id', $withProfile->id)['has_profile']);
        $this->assertNotNull($rows->firstWhere('id', $withProfile->id)['updated_at']);
        $this->assertFalse($rows->firstWhere('id', $withoutProfile->id)['has_profile']);

        $this->actingAs($this->userWithRole('admin'))->getJson('/api/stakeholder-profiles?search=No%20Profile')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $withoutProfile->id);
    }

    /**
     * show() firstOrCreate()s an empty row; merely opening it must not
     * flip the list to "submitted", nor write an audit row.
     */
    public function test_index_still_shows_not_started_after_show_but_nothing_saved(): void
    {
        $office = $this->createOffice(['office_name' => 'Just Viewed ES']);
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->getJson("/api/stakeholder-profiles/{$office->id}")->assertOk();
        $this->assertDatabaseHas('stakeholder_profiles', ['office_id' => $office->id]);
        $this->assertSame(0, Activity::query()->where('subject_type', StakeholderProfile::class)->count());

        $row = collect($this->actingAs($admin)->getJson('/api/stakeholder-profiles')->json('data'))->firstWhere('id', $office->id);

        $this->assertFalse($row['has_profile']);
        $this->assertNull($row['updated_at']);
    }

    public function test_pending_role_gets_403_without_creating_a_row(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('pending', $office);

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", [])->assertForbidden();
        $this->actingAs($user)->getJson('/api/stakeholder-profiles/options')->assertForbidden();

        $this->assertSame(0, StakeholderProfile::count());
    }

    public function test_update_writes_an_audit_entry_with_diffed_changes(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", ['sdo' => 'SDO Angeles City'])->assertOk();

        $profile = StakeholderProfile::where('office_id', $office->id)->sole();
        $activity = Activity::query()->where('subject_type', StakeholderProfile::class)->sole();

        $this->assertSame('updated', $activity->event);
        $this->assertSame('stakeholder_profile.updated', $activity->description);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($profile->id, $activity->subject_id);
        $this->assertNull($activity->attribute_changes['old']['sdo']);
        $this->assertSame('SDO Angeles City', $activity->attribute_changes['attributes']['sdo']);
        $this->assertArrayNotHasKey('updated_at', $activity->attribute_changes['attributes']);
    }

    public function test_options_ship_library_lists_and_the_psgc_tree_with_parent_ids(): void
    {
        $barangay = $this->createPsgcBarangay();
        $office = $this->createOffice();

        $response = $this->actingAs($this->userAt('viewer', $office))->getJson('/api/stakeholder-profiles/options')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'governance_level', 'transaction_type', 'nearby_institution', 'type_of_access_road',
                'by_transportation', 'community_engagement', 'province', 'municipality', 'barangay',
            ]])
            ->assertJsonPath('data.barangay.0', ['value' => $barangay->id, 'label' => 'BANGKAL', 'parent_id' => $barangay->municipality_id])
            ->assertJsonPath('data.municipality.0.parent_id', $barangay->municipality->province_id);

        $this->assertIsInt($response->json('data.nearby_institution.0.value'));
        $this->assertIsString($response->json('data.governance_level.0.value'));
    }

    public function test_update_rejects_oversized_array_field(): void
    {
        $office = $this->createOffice();

        // Leave one active NearbyInstitution row so the max drops to 1.
        $ids = StakeholderLibrary::where('type', StakeholderLibraryType::NearbyInstitution)->active()->pluck('id');
        StakeholderLibrary::whereIn('id', $ids->slice(1))->update(['is_active' => false]);

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson("/api/stakeholder-profiles/{$office->id}", ['nearby_institutions' => $ids->take(2)->all()])
            ->assertJsonValidationErrors(['nearby_institutions']);
    }

    public function test_validation_rejects_a_nonexistent_id_in_array_field(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson("/api/stakeholder-profiles/{$office->id}", ['nearby_institutions' => [999999]])
            ->assertJsonValidationErrors(['nearby_institutions.0']);
    }

    public function test_validation_rejects_a_stakeholder_library_id_of_the_wrong_type_in_array_field(): void
    {
        $office = $this->createOffice();
        $wrongTypeId = StakeholderLibrary::where('type', StakeholderLibraryType::GovernanceLevel)->active()->value('id');

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson("/api/stakeholder-profiles/{$office->id}", ['nearby_institutions' => [$wrongTypeId]])
            ->assertJsonValidationErrors(['nearby_institutions.0']);
    }

    /**
     * `transaction_type` is validated against Equipment's library on
     * purpose (it is an EquipmentLibraryType, not a StakeholderLibraryType).
     */
    public function test_transaction_type_validates_against_the_shared_equipment_library_table(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->assertContains('Beginning Inventory', EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType));

        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", ['transaction_type' => 'Beginning Inventory'])->assertOk();
        $this->assertSame('Beginning Inventory', StakeholderProfile::where('office_id', $office->id)->sole()->transaction_type);

        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", ['transaction_type' => 'Central Office'])
            ->assertJsonValidationErrors(['transaction_type']);
    }

    public function test_encoder_can_save_psgc_address_fields_and_complete_address_reflects_them(): void
    {
        $office = $this->createOffice();
        $barangay = $this->createPsgcBarangay();

        $this->actingAs($this->userAt('encoder', $office))->putJson("/api/stakeholder-profiles/{$office->id}", [
            'province_id' => $barangay->municipality->province_id,
            'municipality_id' => $barangay->municipality_id,
            'barangay_id' => $barangay->id,
            'street' => '123 Rizal St.',
        ])->assertOk()
            ->assertJsonPath('data.profile.barangay_id', $barangay->id)
            ->assertJsonPath('data.profile.complete_address', '123 Rizal St., BANGKAL, ABUCAY, BATAAN');

        $profile = StakeholderProfile::where('office_id', $office->id)->sole();
        $this->assertSame($barangay->municipality_id, $profile->municipality_id);
        $this->assertSame('123 Rizal St., BANGKAL, ABUCAY, BATAAN', $profile->complete_address);
    }

    public function test_validation_rejects_a_nonexistent_psgc_barangay_id(): void
    {
        $office = $this->createOffice();

        $this->actingAs($this->userAt('encoder', $office))
            ->putJson("/api/stakeholder-profiles/{$office->id}", ['barangay_id' => 999999])
            ->assertJsonValidationErrors(['barangay_id']);
    }

    public function test_first_or_create_creates_once_per_office_and_reuses_the_same_row(): void
    {
        $office = $this->createOffice();
        $user = $this->userAt('encoder', $office);

        $this->assertSame(0, StakeholderProfile::count());

        $firstId = $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")->assertOk()->json('data.profile.id');
        $this->assertSame(1, StakeholderProfile::count());

        $this->actingAs($user)->getJson("/api/stakeholder-profiles/{$office->id}")->assertOk()->assertJsonPath('data.profile.id', $firstId);
        $this->actingAs($user)->putJson("/api/stakeholder-profiles/{$office->id}", ['ro' => 'III'])->assertOk()->assertJsonPath('data.profile.id', $firstId);

        $this->assertSame(1, StakeholderProfile::count());
    }

    public function test_there_is_no_create_or_delete_route(): void
    {
        $office = $this->createOffice();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->postJson('/api/stakeholder-profiles', ['office_id' => $office->id])->assertStatus(405);
        $this->actingAs($admin)->deleteJson("/api/stakeholder-profiles/{$office->id}")->assertStatus(405);
    }
}
