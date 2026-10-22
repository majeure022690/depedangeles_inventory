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
 * Roles are readable by roles.view or users.edit holders (the latter
 * assign them); creating, editing and deleting each need their own
 * roles.* permission. RoleService enforces the protected Super
 * Administrator role, the assigned-role delete block and the
 * roles.edit/users.edit lockout guards.
 */
class RoleControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMINISTRATOR);
    }

    private function role(string $name): Role
    {
        return Role::where('name', $name)->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function allPermissions(): array
    {
        return array_map(fn (PermissionEnum $p) => $p->value, PermissionEnum::cases());
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

    public function test_roles_view_or_users_edit_holders_can_read_roles_and_the_permission_catalog(): void
    {
        $viewer = $this->role('viewer');

        foreach ([PermissionEnum::RolesView, PermissionEnum::UsersEdit] as $permission) {
            $actor = $this->userWithPermissions([$permission]);

            $this->actingAs($actor)->getJson('/api/roles')->assertOk();
            $this->actingAs($actor)->getJson("/api/roles/{$viewer->id}")->assertOk();
            $this->actingAs($actor)->getJson('/api/permissions')->assertOk();
        }
    }

    public function test_reading_roles_is_forbidden_without_roles_view_or_users_edit_and_the_policy_audits_it(): void
    {
        $actor = $this->userWithRole('encoder');
        $viewer = $this->role('viewer');

        $this->actingAs($actor)->getJson('/api/roles')->assertForbidden();
        $this->actingAs($actor)->getJson("/api/roles/{$viewer->id}")->assertForbidden();
        $this->actingAs($actor)->getJson('/api/permissions')->assertForbidden();

        $this->assertFalse($actor->can('view', $viewer));
        $log = $this->deniedFor($actor, $viewer);
        $this->assertNotNull($log);
        $this->assertSame(PermissionEnum::RolesView->value, $log->properties['permission']);
    }

    public function test_index_lists_roles_with_counts_scope_and_protected_flag(): void
    {
        $this->userWithRole('viewer');

        $response = $this->actingAs($this->superAdmin())->getJson('/api/roles')->assertOk();

        $roles = collect($response->json('data'));
        $super = $roles->firstWhere('name', Role::SUPER_ADMINISTRATOR);
        $viewer = $roles->firstWhere('name', 'viewer');

        $this->assertTrue($super['is_protected']);
        $this->assertSame(count(PermissionEnum::cases()), $super['permission_count']);
        $this->assertSame('all', $super['data_scope']);
        $this->assertFalse($viewer['is_protected']);
        $this->assertSame('own', $viewer['data_scope']);
        $this->assertSame(1, $viewer['user_count']);
        $this->assertArrayHasKey('label', $viewer);
        $this->assertArrayHasKey('description', $viewer);
        $this->assertArrayNotHasKey('permissions', $viewer);
        $this->assertFalse($roles->contains('name', 'pending'));
    }

    public function test_show_includes_permission_names_and_counts(): void
    {
        $viewer = $this->role('viewer');

        $response = $this->actingAs($this->superAdmin())->getJson("/api/roles/{$viewer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'viewer')
            ->assertJsonPath('data.user_count', 0);

        $this->assertContains(PermissionEnum::EquipmentView->value, $response->json('data.permissions'));
        $this->assertSame(count($response->json('data.permissions')), $response->json('data.permission_count'));
    }

    public function test_permission_catalog_is_labelled_and_grouped_by_the_enum_in_declaration_order(): void
    {
        $response = $this->actingAs($this->superAdmin())->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonCount(count(PermissionEnum::cases()), 'data');

        $this->assertSame($this->allPermissions(), collect($response->json('data'))->pluck('name')->all());

        $rolesEdit = collect($response->json('data'))->firstWhere('name', PermissionEnum::RolesEdit->value);
        $this->assertSame(PermissionEnum::RolesEdit->label(), $rolesEdit['label']);
        $this->assertSame('Administration', $rolesEdit['group']);
    }

    // Writing: each action needs its own permission, and the denial is audited

    public function test_store_is_forbidden_without_roles_create_and_audited(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::RolesView, PermissionEnum::RolesEdit, PermissionEnum::RolesDelete]);

        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'new-role', 'label' => 'New Role', 'permissions' => []])->assertForbidden();

        $this->assertNull(Role::where('name', 'new-role')->first());
        $this->assertSame(PermissionEnum::RolesCreate->value, $this->deniedFor($actor)?->properties['permission']);

        $this->assertFalse($actor->can('create', Role::class));
        $this->assertSame(PermissionEnum::RolesCreate->value, $this->deniedFor($actor)?->properties['permission']);
    }

    public function test_update_is_forbidden_without_roles_edit_and_audited(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::RolesView, PermissionEnum::RolesCreate, PermissionEnum::RolesDelete]);
        $viewer = $this->role('viewer');

        $this->actingAs($actor)->putJson("/api/roles/{$viewer->id}", ['name' => 'viewer', 'label' => 'Hacked', 'permissions' => []])->assertForbidden();

        $this->assertSame('Viewer', $viewer->fresh()->label);
        $this->assertSame(PermissionEnum::RolesEdit->value, $this->deniedFor($actor, $viewer)?->properties['permission']);
    }

    public function test_destroy_is_forbidden_without_roles_delete_and_audited(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::RolesView, PermissionEnum::RolesCreate, PermissionEnum::RolesEdit]);
        $custom = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->actingAs($actor)->deleteJson("/api/roles/{$custom->id}")->assertForbidden();

        $this->assertNotNull($custom->fresh());
        $this->assertSame(PermissionEnum::RolesDelete->value, $this->deniedFor($actor, $custom)?->properties['permission']);
    }

    public function test_system_administrator_can_read_roles_but_not_change_them(): void
    {
        $actor = $this->userWithRole('system_administrator');
        $viewer = $this->role('viewer');

        $this->actingAs($actor)->getJson('/api/roles')->assertOk();
        $this->actingAs($actor)->postJson('/api/roles', ['name' => 'new-role', 'label' => 'New Role', 'permissions' => []])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/roles/{$viewer->id}", ['name' => 'viewer', 'label' => 'Hacked', 'permissions' => []])->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/roles/{$viewer->id}")->assertForbidden();

        $this->assertNull(Role::where('name', 'new-role')->first());
        $this->assertSame('Viewer', $viewer->fresh()->label);
    }

    // Create

    public function test_super_admin_can_create_a_role_with_a_permission_subset_and_it_is_audited(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/roles', [
            'name' => 'auditor',
            'label' => 'Auditor',
            'description' => 'Read-only cross-cutting auditor role.',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::PersonnelView->value],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'auditor')
            ->assertJsonPath('data.label', 'Auditor')
            ->assertJsonPath('data.data_scope', 'own')
            ->assertJsonPath('data.user_count', 0)
            ->assertJsonPath('data.permission_count', 2);

        $role = $this->role('auditor');
        $this->assertSame(
            [PermissionEnum::EquipmentView->value, PermissionEnum::PersonnelView->value],
            $role->permissions()->pluck('name')->sort()->values()->all(),
        );

        $log = Activity::where('event', 'role.created')->where('subject_id', $role->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->causer_id);
    }

    public function test_store_validates_name_permissions_and_data_scope(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'viewer', 'label' => 'Duplicate', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'bad-role', 'label' => 'Bad Role', 'permissions' => ['not.a.real.permission']])
            ->assertJsonValidationErrors(['permissions.0']);
        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'Bad-Role', 'label' => 'Bad Role', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->postJson('/api/roles', ['name' => 'bad-role', 'label' => 'Bad Role', 'data_scope' => 'everyone', 'permissions' => []])
            ->assertJsonValidationErrors(['data_scope']);

        $this->assertNull(Role::where('name', 'Bad-Role')->first());
        $this->assertNull(Role::where('name', 'bad-role')->first());
    }

    // Update

    public function test_super_admin_can_update_a_roles_label_description_scope_and_permissions_and_it_is_audited(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom', 'description' => 'Old']);
        $role->syncPermissions(Permission::where('name', PermissionEnum::EquipmentView->value)->pluck('id'));

        $response = $this->actingAs($this->superAdmin())->putJson("/api/roles/{$role->id}", [
            'name' => 'custom',
            'label' => 'Custom Renamed',
            'description' => 'New',
            'data_scope' => 'school',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value],
        ])->assertOk()
            ->assertJsonPath('data.label', 'Custom Renamed')
            ->assertJsonPath('data.data_scope', 'school');

        $this->assertEqualsCanonicalizing([PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value], $response->json('data.permissions'));
        $this->assertNotNull(Activity::where('event', 'role.updated')->where('subject_id', $role->id)->first());
    }

    public function test_update_fails_loudly_when_a_submitted_permission_is_not_yet_seeded(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom', 'description' => null]);
        $role->syncPermissions(Permission::where('name', PermissionEnum::EquipmentView->value)->pluck('id'));
        $actor = $this->superAdmin();
        Permission::where('name', PermissionEnum::EquipmentEdit->value)->delete();

        $this->actingAs($actor)->putJson("/api/roles/{$role->id}", [
            'name' => 'custom',
            'label' => 'Custom',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value],
        ])->assertJsonValidationErrors(['permissions']);

        $this->assertSame([PermissionEnum::EquipmentView->value], $role->fresh()->permissions()->pluck('name')->all());
    }

    public function test_update_rejects_an_uppercase_role_name(): void
    {
        $role = Role::create(['name' => 'custom', 'label' => 'Custom']);

        $this->actingAs($this->superAdmin())->putJson("/api/roles/{$role->id}", ['name' => 'Custom', 'label' => 'Custom', 'permissions' => []])
            ->assertJsonValidationErrors(['name']);

        $this->assertSame('custom', $role->fresh()->name);
    }

    public function test_super_administrator_name_permissions_and_scope_are_fixed_but_label_is_editable(): void
    {
        $admin = $this->superAdmin();
        $super = $this->role(Role::SUPER_ADMINISTRATOR);
        $reduced = array_values(array_diff($this->allPermissions(), [PermissionEnum::EquipmentDelete->value]));

        $this->actingAs($admin)->putJson("/api/roles/{$super->id}", ['name' => 'renamed-super', 'label' => 'Super', 'permissions' => $this->allPermissions()])
            ->assertJsonValidationErrors(['name']);
        $this->actingAs($admin)->putJson("/api/roles/{$super->id}", ['name' => Role::SUPER_ADMINISTRATOR, 'label' => 'Super', 'permissions' => $reduced])
            ->assertJsonValidationErrors(['permissions']);

        $this->assertSame(Role::SUPER_ADMINISTRATOR, $super->fresh()->name);
        $this->assertEqualsCanonicalizing($this->allPermissions(), $super->fresh()->permissions()->pluck('name')->all());

        $this->actingAs($admin)->putJson("/api/roles/{$super->id}", [
            'name' => Role::SUPER_ADMINISTRATOR, 'label' => 'Head of ICT', 'description' => 'Updated copy.',
            'data_scope' => 'own', 'permissions' => $this->allPermissions(),
        ])->assertOk()->assertJsonPath('data.data_scope', 'all');

        $this->assertSame('Head of ICT', $super->fresh()->label);
    }

    // Destroy

    public function test_destroy_is_a_409_for_the_super_administrator_role_and_for_an_assigned_role(): void
    {
        $admin = $this->superAdmin();
        $super = $this->role(Role::SUPER_ADMINISTRATOR);
        $viewer = $this->role('viewer');
        $this->userWithRole('viewer');

        $this->actingAs($admin)->deleteJson("/api/roles/{$super->id}")->assertConflict()->assertJsonStructure(['message']);
        $this->actingAs($admin)->deleteJson("/api/roles/{$viewer->id}")
            ->assertConflict()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'assigned to 1 user'));

        $this->assertNotNull($super->fresh());
        $this->assertNotNull($viewer->fresh());
    }

    public function test_destroy_succeeds_for_an_unused_role_and_is_audited(): void
    {
        $unused = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->actingAs($this->superAdmin())->deleteJson("/api/roles/{$unused->id}")->assertNoContent();

        $this->assertNull(Role::find($unused->id));
        $this->assertNotNull(Activity::where('event', 'role.deleted')->where('subject_id', $unused->id)->first());
    }

    // Lockout guards

    public function test_update_blocks_removing_users_edit_when_no_other_role_grants_it_to_anyone(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo($this->allPermissions());
        $systemAdmin = $this->role('system_administrator');
        $this->userWithRole('system_administrator');
        $remaining = $systemAdmin->permissions()->pluck('name')->reject(fn ($name) => $name === PermissionEnum::UsersEdit->value)->values()->all();

        $this->actingAs($actor)->putJson("/api/roles/{$systemAdmin->id}", [
            'name' => 'system_administrator', 'label' => $systemAdmin->label, 'permissions' => $remaining,
        ])->assertJsonValidationErrors(['permissions']);

        $this->assertTrue($systemAdmin->fresh()->permissions()->where('name', PermissionEnum::UsersEdit->value)->exists());
    }

    public function test_update_blocks_self_lockout_when_actor_holds_the_only_granting_role(): void
    {
        $this->superAdmin();

        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->syncPermissions(Permission::whereIn('name', [PermissionEnum::RolesView->value, PermissionEnum::RolesEdit->value])->pluck('id'));
        $actor = $this->userWithRole('role-editor');

        $this->actingAs($actor)->putJson("/api/roles/{$roleEditor->id}", ['name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => [PermissionEnum::RolesView->value]])
            ->assertJsonValidationErrors(['permissions']);

        $this->assertTrue($roleEditor->fresh()->permissions()->where('name', PermissionEnum::RolesEdit->value)->exists());
    }

    public function test_update_allows_removing_roles_edit_when_actor_retains_it_via_another_role(): void
    {
        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->syncPermissions(Permission::where('name', PermissionEnum::RolesEdit->value)->pluck('id'));

        $actor = User::factory()->create();
        $actor->assignRole($roleEditor);
        $actor->assignRole(Role::SUPER_ADMINISTRATOR);

        $this->actingAs($actor)->putJson("/api/roles/{$roleEditor->id}", ['name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.permissions', []);

        $this->assertTrue($actor->fresh()->hasPermissionTo(PermissionEnum::RolesEdit));
    }

    public function test_super_admin_can_edit_the_role_they_hold_when_not_reducing_it(): void
    {
        $super = $this->role(Role::SUPER_ADMINISTRATOR);

        $this->actingAs($this->superAdmin())->putJson("/api/roles/{$super->id}", [
            'name' => Role::SUPER_ADMINISTRATOR,
            'label' => $super->label,
            'description' => 'Updated description.',
            'permissions' => $this->allPermissions(),
        ])->assertOk();

        $this->assertSame('Updated description.', $super->fresh()->description);
    }
}
