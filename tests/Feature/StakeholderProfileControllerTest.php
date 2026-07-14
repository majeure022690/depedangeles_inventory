<?php

namespace Tests\Feature;

use App\Enums\EquipmentLibraryType;
use App\Enums\StakeholderLibraryType;
use App\Models\AuditLog;
use App\Models\EquipmentLibrary;
use App\Models\Office;
use App\Models\StakeholderLibrary;
use App\Models\StakeholderProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * StakeholderProfile is one row PER OFFICE (not a global singleton) — every
 * test here creates its own Office row(s) and scopes users to one via
 * `office_id`. StakeholderProfile's Tier 2 fields (lookup-normalization
 * ADR, Step 2+3) are string-validated against the real seeded
 * StakeholderLibrary/EquipmentLibrary rows (via ReferenceDataSeeder) rather
 * than the old Lookup/LookupType system, which this resource no longer
 * consults. The four JSON-array fields (nearby_institutions, access_paths,
 * transportation_options, community_engagement) store `stakeholder_libraries`
 * row ids, not value strings.
 */
class StakeholderProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createOffice(array $overrides = []): Office
    {
        return Office::create(array_merge(['office_name' => 'Test Elementary School'], $overrides));
    }

    /**
     * stakeholder-profile/Edit.vue doesn't exist yet (Frontend builds it
     * against the prop contract this controller establishes) — same
     * situation as IspAccountControllerTest/UserControllerTest. Sending
     * X-Inertia makes the response pure JSON straight from the controller,
     * never touching the root Blade view's `@vite(...)` call.
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
            $this->actingAs($user)->inertiaGet(route('stakeholder-profiles.edit', $office))
        );
        $this->assertSame('stakeholder-profile/Edit', $page['component']);

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), [])
            ->assertForbidden();
    }

    public function test_encoder_can_view_and_update_own_office(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->inertiaGet(route('stakeholder-profiles.edit', $office))->assertOk();

        $institutionId = StakeholderLibrary::where('type', StakeholderLibraryType::NearbyInstitution)
            ->where('value', 'Barangay Hall')
            ->value('id');

        $response = $this->actingAs($user)->put(route('stakeholder-profiles.update', $office), [
            'sdo' => 'SDO Angeles City',
            'chief_name' => 'DOMINGO, EDGARD C',
            'gidca' => true,
            'nearby_institutions' => [$institutionId],
        ]);

        $response->assertRedirect(route('stakeholder-profiles.edit', $office));
        $response->assertSessionHasNoErrors();

        $stakeholderProfile = StakeholderProfile::where('office_id', $office->id)->sole();
        $this->assertSame('SDO Angeles City', $stakeholderProfile->sdo);
        $this->assertSame('DOMINGO, EDGARD C', $stakeholderProfile->chief_name);
        $this->assertTrue($stakeholderProfile->gidca);
        $this->assertSame([$institutionId], $stakeholderProfile->nearby_institutions);
    }

    public function test_encoder_cannot_view_or_update_a_different_office(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $myOffice = $this->createOffice(['office_name' => 'My School']);
        $otherOffice = $this->createOffice(['office_name' => 'Other School']);
        $user = User::factory()->create(['office_id' => $myOffice->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->get(route('stakeholder-profiles.edit', $otherOffice))->assertForbidden();
        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $otherOffice), ['sdo' => 'Nope'])
            ->assertForbidden();

        // No side effect: the other office's profile is never created by a
        // forbidden request.
        $this->assertSame(0, StakeholderProfile::where('office_id', $otherOffice->id)->count());
    }

    public function test_encoder_with_no_office_gets_403_on_any_office(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => null]);
        $user->assignRole('encoder');

        $this->actingAs($user)->get(route('stakeholder-profiles.edit', $office))->assertForbidden();
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

        $this->actingAs($user)->inertiaGet(route('stakeholder-profiles.edit', $office))->assertOk();

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), ['ro' => 'III'])
            ->assertRedirect(route('stakeholder-profiles.edit', $office));

        $this->assertSame('III', StakeholderProfile::where('office_id', $office->id)->sole()->ro);
    }

    public function test_only_view_all_holder_can_access_the_index_list(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->inertiaGet(route('stakeholder-profiles.index'))->assertOk();

        $encoder = User::factory()->create(['office_id' => $office->id]);
        $encoder->assignRole('encoder');
        $this->actingAs($encoder)->get(route('stakeholder-profiles.index'))->assertForbidden();
    }

    public function test_index_lists_offices_with_and_without_a_profile(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $withProfile = $this->createOffice(['office_name' => 'Has Profile ES']);
        $withoutProfile = $this->createOffice(['office_name' => 'No Profile ES']);
        StakeholderProfile::create(['office_id' => $withProfile->id, 'sdo' => 'SDO Angeles City']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $page = $this->assertInertiaJson($this->actingAs($admin)->inertiaGet(route('stakeholder-profiles.index')));

        $rows = collect($page['props']['offices']['data']);
        $this->assertTrue($rows->firstWhere('id', $withProfile->id)['has_profile']);
        $this->assertFalse($rows->firstWhere('id', $withoutProfile->id)['has_profile']);
    }

    public function test_pending_role_gets_403(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('pending');

        $this->actingAs($user)->get(route('stakeholder-profiles.edit', $office))->assertForbidden();
        $this->actingAs($user)->put(route('stakeholder-profiles.update', $office), [])->assertForbidden();

        // Authorization must be checked BEFORE the row is created — a
        // zero-permission user's forbidden requests must not have any
        // write side effect.
        $this->assertSame(0, StakeholderProfile::count());
    }

    public function test_update_writes_an_audit_log_entry_with_diffed_changes(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)->put(route('stakeholder-profiles.update', $office), [
            'sdo' => 'SDO Angeles City',
        ])->assertSessionHasNoErrors();

        // assignRole() itself writes a 'role.assigned' AuditLog entry
        // (see User::assignRole), so filter to the action under test
        // rather than assuming this is the only row.
        $log = AuditLog::where('action', 'stakeholder_profile.updated')->sole();
        $stakeholderProfile = StakeholderProfile::where('office_id', $office->id)->sole();
        $this->assertSame('stakeholder_profile.updated', $log->action);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($stakeholderProfile->id, $log->subject_id);
        $this->assertSame(StakeholderProfile::class, $log->subject_type);
        $this->assertSame(null, $log->properties['changes']['sdo']['old']);
        $this->assertSame('SDO Angeles City', $log->properties['changes']['sdo']['new']);
        $this->assertArrayNotHasKey('updated_at', $log->properties['changes']);
    }

    public function test_update_rejects_oversized_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        // Deactivate every NearbyInstitution row but one, so the field's
        // max drops to 1 — two distinct, otherwise-valid ids must then be
        // rejected by the array size cap, not the per-element existence
        // check.
        $ids = StakeholderLibrary::where('type', StakeholderLibraryType::NearbyInstitution)
            ->active()
            ->pluck('id');
        StakeholderLibrary::whereIn('id', $ids->slice(1))->update(['is_active' => false]);

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), [
                'nearby_institutions' => $ids->take(2)->all(),
            ])
            ->assertSessionHasErrors(['nearby_institutions']);
    }

    public function test_validation_rejects_a_nonexistent_id_in_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), [
                'nearby_institutions' => [999999],
            ])
            ->assertSessionHasErrors(['nearby_institutions.0']);
    }

    /**
     * Mirrors PersonnelControllerTest's equivalent test for fund_source —
     * a real, active StakeholderLibrary row of the WRONG type
     * (governance_level, not nearby_institution) must still be rejected
     * for nearby_institutions, proving the Rule::exists() is scoped by
     * `type`, not just by table membership.
     */
    public function test_validation_rejects_a_stakeholder_library_id_of_the_wrong_type_in_array_field(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $wrongTypeId = StakeholderLibrary::where('type', StakeholderLibraryType::GovernanceLevel)
            ->active()
            ->value('id');

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), [
                'nearby_institutions' => [$wrongTypeId],
            ])
            ->assertSessionHasErrors(['nearby_institutions.0']);
    }

    /**
     * `transaction_type` is DELIBERATELY validated against
     * EquipmentLibrary/EquipmentLibraryType, not StakeholderLibraryType —
     * it is not one of StakeholderLibraryType's 7 cases, it's one of
     * EquipmentLibraryType's 9 (shared with Equipment/EquipmentTransaction)
     * — see StakeholderProfileUpdateRequest's doc-comment. This proves
     * both directions: a real EquipmentLibrary TransactionType value is
     * accepted, and a real StakeholderLibrary value (wrong table entirely)
     * is rejected.
     */
    public function test_transaction_type_validates_against_the_shared_equipment_library_table(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->assertContains(
            'Beginning Inventory',
            EquipmentLibrary::activeValues(EquipmentLibraryType::TransactionType)
        );

        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), ['transaction_type' => 'Beginning Inventory'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Beginning Inventory', StakeholderProfile::where('office_id', $office->id)->sole()->transaction_type);

        // A real StakeholderLibrary value is not a valid TransactionType —
        // it lives in a completely different table.
        $this->actingAs($user)
            ->put(route('stakeholder-profiles.update', $office), ['transaction_type' => 'Central Office'])
            ->assertSessionHasErrors(['transaction_type']);
    }

    public function test_first_or_create_creates_once_per_office_and_reuses_the_same_row(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $office = $this->createOffice();
        $user = User::factory()->create(['office_id' => $office->id]);
        $user->assignRole('encoder');

        $this->assertSame(0, StakeholderProfile::count());

        $this->actingAs($user)->inertiaGet(route('stakeholder-profiles.edit', $office))->assertOk();
        $this->assertSame(1, StakeholderProfile::count());
        $firstId = StakeholderProfile::where('office_id', $office->id)->sole()->id;

        $this->actingAs($user)->inertiaGet(route('stakeholder-profiles.edit', $office))->assertOk();
        $this->assertSame(1, StakeholderProfile::count());

        $this->actingAs($user)->put(route('stakeholder-profiles.update', $office), ['ro' => 'III']);
        $this->assertSame(1, StakeholderProfile::count());
        $this->assertSame($firstId, StakeholderProfile::where('office_id', $office->id)->sole()->id);
    }
}
