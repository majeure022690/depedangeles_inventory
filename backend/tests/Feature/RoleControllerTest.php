<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Roles are readable by roles.manage or users.manage holders (the latter
 * assign them), writable only with roles.manage. RoleService enforces the
 * protected `pending` role, the assigned-role delete block and the
 * roles.manage/users.manage lockout guards.
 */
class RoleControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function rolesManageUser(): User
    {
        return $this->userWithRole('admin');
    }

    private function usersManageOnlyUser(): User
    {
        return $this->userWithPermissions([PermissionEnum::UsersManage]);
    }

    private function deniedFor(User $actor, ?Role $subject = null): ?Activity
    {
        return Activity::query()
            ->where('event', 'authorization.denied')
            ->where('causer_id', $actor->id)
            ->where('subject_type', $subject?->getMorphClass())
            ->where('subject_id', $subject?->id)
            ->latest('id')
            ->first();
    }

    // Reading

    public function test_users_manage_holders_can_read_roles_and_the_permission_catalog(): void
    {
        $actor = $this->usersManageOnlyUser();
        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $this->actingAs($actor)->getJson('/api/roles')->assertOk();
        $this->actingAs($actor)->getJson("/api/roles/{$viewer->id}")->assertOk();
        $this->actingAs($actor)->getJson('/api/permissions')->assertOk();
    }

    public function test_reading_roles_is_forbidden_without_roles_or_users_manage_and_the_policy_audits_it(): void
    {
        $actor = $this->userWithRole('encoder');
        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $this->actingAs($actor)->getJson('/api/roles')->assertForbidden();
        $this->actingAs($actor)->getJson("/api/roles/{$viewer->id}")->assertForbidden();
        $this->actingAs($actor)->getJson('/api/permissions')->assertForbidden();

        $this->assertFalse($actor->can('view', $viewer));
        $log = $this->deniedFor($actor, $viewer);
        $this->assertNotNull($log);
        $this->assertSame(PermissionEnum::RolesManage->value, $log->properties['permission']);
    }

    public function test_index_lists_roles_with_counts_and_protected_flag(): void
    {
        $this->userWithRole('viewer');

        $response = $this->actingAs($this->rolesManageUser())->getJson('/api/roles')->assertOk();

        $roles = collect($response->json('data'));
        $pending = $roles->firstWhere('name', 'pending');
        $viewer = $roles->firstWhere('name', 'viewer');

        $this->assertTrue($pending['is_protected']);
        $this->assertSame(0, $pending['permission_count']);
        $this->assertFalse($viewer['is_protected']);
        $this->assertSame(1, $viewer['user_count']);
        $this->assertArrayHasKey('label', $viewer);
        $this->assertArrayHasKey('description', $viewer);
        $this->assertArrayNotHasKey('permissions', $viewer);
    }

    public function test_show_includes_permission_names_and_counts(): void
    {
        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $response = $this->actingAs($this->rolesManageUser())->getJson("/api/roles/{$viewer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'viewer')
            ->assertJsonPath('data.user_count', 0);

        $this->assertContains(PermissionEnum::EquipmentView->value, $response->json('data.permissions'));
        $this->assertSame(count($response->json('data.permissions')), $response->json('data.permission_count'));
    }

    public function test_permission_catalog_is_labelled_and_grouped_by_the_enum_in_declaration_order(): void
    {
        $response = $this->actingAs($this->rolesManageUser())->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonCount(count(PermissionEnum::cases()), 'data');

        $this->assertSame(array_map(fn ($c) => $c->value, PermissionEnum::cases()), collect($response->json('data'))->pluck('name')->all());

        $rolesManage = collect($response->json('data'))->firstWhere('name', PermissionEnum::RolesManage->value);
        $this->assertSame(PermissionEnum::RolesManage->label(), $rolesManage['label']);
        $this->assertSame('Administration', $rolesManage['group']);
    }

    // Writing: forbidden without roles.manage, and the denial is audited

    public function test_store_is_forbidden_without_roles_manage_and_audited(): void
    {
        $actor = $this->usersManageOnlyUser();

        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'new-role', 'label' => 'New Role', 'permissions' => []])->assertForbidden();

        $this->assertNull(Role::where('name', 'new-role')->first());
        $log = $this->deniedFor($actor);
        $this->assertNotNull($log);
        $this->assertSame(PermissionEnum::RolesManage->value, $log->properties['permission']);
    }

    public function test_update_is_forbidden_without_roles_manage_and_audited(): void
    {
        $actor = $this->usersManageOnlyUser();
        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $this->actingAs($actor)->putJson("/api/roles/{$viewer->id}", ['name' => 'viewer', 'label' => 'Hacked', 'permissions' => []])->assertForbidden();

        $this->assertSame('Viewer', $viewer->fresh()->label);
        $this->assertNotNull($this->deniedFor($actor, $viewer));
    }

    public function test_destroy_is_forbidden_without_roles_manage_and_audited(): void
    {
        $actor = $this->usersManageOnlyUser();
        $custom = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->actingAs($actor)->deleteJson("/api/roles/{$custom->id}")->assertForbidden();

        $this->assertNotNull($custom->fresh());
        $this->assertNotNull($this->deniedFor($actor, $custom));
    }

    // Create

    public function test_admin_can_create_a_role_with_a_permission_subset_and_it_is_audited(): void
    {
        $admin = $this->rolesManageUser();

        $this->actingAs($admin)->postJson('/api/roles', [
            'name' => 'auditor',
            'label' => 'Auditor',
            'description' => 'Read-only cross-cutting auditor role.',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::PersonnelView->value],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'auditor')
            ->assertJsonPath('data.label', 'Auditor')
            ->assertJsonPath('data.user_count', 0)
            ->assertJsonPath('data.permission_count', 2);

        $role = Role::where('name', 'auditor')->firstOrFail();
        $this->assertSame(
            [PermissionEnum::EquipmentView->value, PermissionEnum::PersonnelView->value],
            $role->permissions()->pluck('name')->sort()->values()->all(),
        );

        $log = Activity::where('event', 'role.created')->where('subject_id', $role->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_store_validates_name_and_permissions(): void
    {
        $admin = $this->rolesManageUser();

        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'viewer', 'label' => 'Duplicate', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'bad-role', 'label' => 'Bad Role', 'permissions' => ['not.a.real.permission']])
            ->assertJsonValidationErrors(['permissions.0']);
        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'Bad-Role', 'label' => 'Bad Role', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);

        $this->assertNull(Role::where('name', 'Bad-Role')->first());
    }

    // Update

    public function test_admin_can_update_a_roles_label_description_and_permissions_and_it_is_audited(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom', 'description' => 'Old']);
        $role->syncPermissions(Permission::where('name', PermissionEnum::EquipmentView->value)->pluck('id'));

        $response = $this->actingAs($this->rolesManageUser())->putJson("/api/roles/{$role->id}", [
            'name' => 'custom',
            'label' => 'Custom Renamed',
            'description' => 'New',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value],
        ])->assertOk()->assertJsonPath('data.label', 'Custom Renamed');

        $this->assertEqualsCanonicalizing([PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value], $response->json('data.permissions'));
        $this->assertNotNull(Activity::where('event', 'role.updated')->where('subject_id', $role->id)->first());
    }

    public function test_update_fails_loudly_when_a_submitted_permission_is_not_yet_seeded(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom', 'description' => null]);
        $role->syncPermissions(Permission::where('name', PermissionEnum::EquipmentView->value)->pluck('id'));
        Permission::where('name', PermissionEnum::EquipmentEdit->value)->delete();

        $this->actingAs($this->rolesManageUser())->putJson("/api/roles/{$role->id}", [
            'name' => 'custom',
            'label' => 'Custom',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value],
        ])->assertJsonValidationErrors(['permissions']);

        $this->assertSame([PermissionEnum::EquipmentView->value], $role->fresh()->permissions()->pluck('name')->all());
    }

    public function test_update_rejects_an_uppercase_role_name(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom']);

        $this->actingAs($this->rolesManageUser())->putJson("/api/roles/{$role->id}", ['name' => 'Custom', 'label' => 'Custom', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('custom', $role->fresh()->name);
    }

    public function test_pending_role_name_and_permissions_are_fixed_but_label_is_editable(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();

        $this->actingAs($admin)->putJson("/api/roles/{$pending->id}", ['name' => 'renamed-pending', 'label' => 'Pending', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->putJson("/api/roles/{$pending->id}", ['name' => Role::PENDING, 'label' => 'Pending', 'permissions' => [PermissionEnum::EquipmentView->value]])
            ->assertJsonValidationErrors(['permissions']);

        $this->assertSame(Role::PENDING, $pending->fresh()->name);
        $this->assertSame([], $pending->fresh()->permissions()->pluck('name')->all());

        $this->actingAs($admin)->putJson("/api/roles/{$pending->id}", [
            'name' => Role::PENDING, 'label' => 'Awaiting Review', 'description' => 'Updated copy.', 'permissions' => [],
        ])->assertOk();

        $this->assertSame('Awaiting Review', $pending->fresh()->label);
    }

    // Destroy

    public function test_destroy_is_a_409_for_the_pending_role_and_for_an_assigned_role(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();
        $viewer = Role::where('name', 'viewer')->firstOrFail();
        $this->userWithRole('viewer');

        $this->actingAs($admin)->deleteJson("/api/roles/{$pending->id}")->assertConflict()->assertJsonStructure(['message']);
        $this->actingAs($admin)->deleteJson("/api/roles/{$viewer->id}")
            ->assertConflict()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'assigned to 1 user'));

        $this->assertNotNull($pending->fresh());
        $this->assertNotNull($viewer->fresh());
    }

    public function test_destroy_succeeds_for_an_unused_role_and_is_audited(): void
    {
        $unused = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->actingAs($this->rolesManageUser())->deleteJson("/api/roles/{$unused->id}")->assertNoContent();

        $this->assertNull(Role::find($unused->id));
        $this->assertNotNull(Activity::where('event', 'role.deleted')->where('subject_id', $unused->id)->first());
    }

    // Lockout guards

    public function test_update_blocks_removing_roles_manage_when_no_other_role_grants_it(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $remaining = $adminRole->permissions()->pluck('name')->reject(fn ($name) => $name === PermissionEnum::RolesManage->value)->values()->all();

        $this->actingAs($this->rolesManageUser())->putJson("/api/roles/{$adminRole->id}", [
            'name' => 'admin', 'label' => $adminRole->label, 'permissions' => $remaining,
        ])->assertJsonValidationErrors(['permissions']);

        $this->assertTrue($adminRole->fresh()->permissions()->where('name', PermissionEnum::RolesManage->value)->exists());
    }

    public function test_update_blocks_self_lockout_when_actor_holds_the_only_granting_role(): void
    {
        $superAdminRole = Role::create(['name' => 'super-admin', 'label' => 'Super Admin']);
        $superAdminRole->syncPermissions(Permission::whereIn('name', [PermissionEnum::RolesManage->value, PermissionEnum::UsersManage->value])->pluck('id'));
        User::factory()->create()->assignRole($superAdminRole);

        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->syncPermissions(Permission::where('name', PermissionEnum::RolesManage->value)->pluck('id'));
        $actor = $this->userWithRole('role-editor');

        $this->actingAs($actor)->putJson("/api/roles/{$roleEditor->id}", ['name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => []])
            ->assertJsonValidationErrors(['permissions']);

        $this->assertTrue($roleEditor->fresh()->permissions()->where('name', PermissionEnum::RolesManage->value)->exists());
    }

    public function test_update_allows_removing_roles_manage_when_actor_retains_it_via_another_role(): void
    {
        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->syncPermissions(Permission::where('name', PermissionEnum::RolesManage->value)->pluck('id'));
        $superAdminRole = Role::create(['name' => 'super-admin', 'label' => 'Super Admin']);
        $superAdminRole->syncPermissions(Permission::whereIn('name', [PermissionEnum::RolesManage->value, PermissionEnum::UsersManage->value])->pluck('id'));

        $actor = User::factory()->create();
        $actor->assignRole($roleEditor);
        $actor->assignRole($superAdminRole);

        $this->actingAs($actor)->putJson("/api/roles/{$roleEditor->id}", ['name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.permissions', []);

        $this->assertTrue($actor->fresh()->hasPermissionTo(PermissionEnum::RolesManage));
    }

    public function test_admin_can_edit_a_role_they_hold_when_not_touching_sensitive_permissions(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $this->actingAs($this->rolesManageUser())->putJson("/api/roles/{$adminRole->id}", [
            'name' => 'admin',
            'label' => 'Administrator',
            'description' => 'Updated description.',
            'permissions' => collect(PermissionEnum::cases())->map->value->all(),
        ])->assertOk();

        $this->assertSame('Updated description.', $adminRole->fresh()->description);
    }
}
