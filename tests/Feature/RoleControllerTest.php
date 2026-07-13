<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coverage for the roles.manage admin screen — genuine RBAC where roles
 * (not just role assignment) are admin-configurable. See RoleController/
 * RoleService doc-comments for the Policy/Service authorization split and
 * Role::PENDING's doc-comment for why 'pending' specifically is protected
 * from deletion/renaming/permission-grants while 'viewer'/'encoder'/
 * 'division-ict-admin' are ordinary editable/deletable seeded data.
 */
class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function rolesManageUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('division-ict-admin');

        return $user;
    }

    private function usersManageOnlyUser(): User
    {
        // encoder/viewer never hold users.manage or roles.manage — use a
        // custom role granting ONLY users.manage, to prove roles.manage
        // is checked as a genuinely distinct permission (the whole point
        // of this feature per Permission::RolesManage's doc-comment).
        $role = Role::create(['name' => 'user-admin-only', 'label' => 'User Admin Only']);
        $role->permissions()->sync(
            Permission::where('name', PermissionEnum::UsersManage->value)->pluck('id'),
        );

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

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

    // --- Authorization: roles.manage is required, users.manage is not sufficient ---

    public function test_index_is_forbidden_without_roles_manage(): void
    {
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);
        $this->inertiaGet(route('roles.index'))->assertForbidden();

        $log = AuditLog::where('action', 'authorization.denied')->where('subject_type', null)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame(PermissionEnum::RolesManage->value, $log->properties['permission']);
    }

    public function test_create_page_is_forbidden_without_roles_manage_and_is_audited(): void
    {
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);
        $this->inertiaGet(route('roles.create'))->assertForbidden();

        $log = AuditLog::where('action', 'authorization.denied')->where('subject_type', null)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame(PermissionEnum::RolesManage->value, $log->properties['permission']);
    }

    public function test_store_is_forbidden_without_roles_manage(): void
    {
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);

        $this->post(route('roles.store'), [
            'name' => 'new-role', 'label' => 'New Role', 'permissions' => [],
        ])->assertForbidden();

        $this->assertNull(Role::where('name', 'new-role')->first());

        $log = AuditLog::where('action', 'authorization.denied')->where('subject_type', null)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
    }

    public function test_update_is_forbidden_without_roles_manage(): void
    {
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);

        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $this->patch(route('roles.update', $viewer), [
            'name' => 'viewer', 'label' => 'Hacked', 'permissions' => [],
        ])->assertForbidden();

        $this->assertSame('Viewer', $viewer->fresh()->label);

        $log = AuditLog::where('action', 'authorization.denied')
            ->where('subject_type', $viewer->getMorphClass())
            ->where('subject_id', $viewer->id)
            ->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
    }

    public function test_destroy_is_forbidden_without_roles_manage(): void
    {
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);

        $custom = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->delete(route('roles.destroy', $custom))->assertForbidden();

        $this->assertNotNull($custom->fresh());

        $log = AuditLog::where('action', 'authorization.denied')
            ->where('subject_type', $custom->getMorphClass())
            ->where('subject_id', $custom->id)
            ->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
    }

    public function test_view_ability_denial_is_audited(): void
    {
        // Not wired to any route (Route::resource excludes 'show'), but
        // the Policy method is still a reachable authorization surface
        // (e.g. `$user->can('view', $role)`) and must log denials like
        // its siblings.
        $actor = $this->usersManageOnlyUser();
        $this->actingAs($actor);
        $viewer = Role::where('name', 'viewer')->firstOrFail();

        $this->assertFalse($actor->can('view', $viewer));

        $log = AuditLog::where('action', 'authorization.denied')
            ->where('subject_type', $viewer->getMorphClass())
            ->where('subject_id', $viewer->id)
            ->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame(PermissionEnum::RolesManage->value, $log->properties['permission']);
    }

    // --- index/create/edit props ---

    public function test_index_lists_roles_with_counts_and_protected_flag(): void
    {
        $admin = $this->rolesManageUser();

        $viewerUser = User::factory()->create();
        $viewerUser->assignRole('viewer');

        $this->actingAs($admin);
        $page = $this->assertInertiaJson($this->inertiaGet(route('roles.index')));

        $roles = collect($page['props']['roles']);
        $pending = $roles->firstWhere('name', 'pending');
        $viewer = $roles->firstWhere('name', 'viewer');

        $this->assertTrue($pending['is_protected']);
        $this->assertSame(0, $pending['permission_count']);
        $this->assertFalse($viewer['is_protected']);
        $this->assertGreaterThanOrEqual(1, $viewer['user_count']);
    }

    public function test_create_form_exposes_the_full_permission_catalog(): void
    {
        $admin = $this->rolesManageUser();

        $this->actingAs($admin);
        $page = $this->assertInertiaJson($this->inertiaGet(route('roles.create')));

        $this->assertCount(count(PermissionEnum::cases()), $page['props']['permissions']);
        $this->assertContains(
            ['value' => PermissionEnum::RolesManage->value, 'label' => PermissionEnum::RolesManage->label()],
            $page['props']['permissions'],
        );
    }

    // --- store ---

    public function test_admin_can_create_a_role_with_a_permission_subset_and_it_is_audited(): void
    {
        $admin = $this->rolesManageUser();

        $response = $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'auditor',
            'label' => 'Auditor',
            'description' => 'Read-only cross-cutting auditor role.',
            'permissions' => [
                PermissionEnum::EquipmentView->value,
                PermissionEnum::PersonnelView->value,
            ],
        ]);

        $role = Role::where('name', 'auditor')->firstOrFail();
        $response->assertRedirect(route('roles.edit', $role));

        $this->assertSame(
            [PermissionEnum::EquipmentView->value, PermissionEnum::PersonnelView->value],
            $role->permissions()->pluck('name')->sort()->values()->all(),
        );

        $log = AuditLog::where('action', 'role.created')->where('subject_id', $role->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_store_rejects_duplicate_role_name(): void
    {
        $admin = $this->rolesManageUser();

        $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'viewer', 'label' => 'Duplicate', 'permissions' => [],
        ])->assertSessionHasErrors(['name']);
    }

    public function test_store_rejects_an_invalid_permission_value(): void
    {
        $admin = $this->rolesManageUser();

        $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'bad-role', 'label' => 'Bad Role', 'permissions' => ['not.a.real.permission'],
        ])->assertSessionHasErrors(['permissions.0']);
    }

    public function test_store_rejects_an_uppercase_role_name(): void
    {
        // 'alpha_dash' alone permits uppercase; the form's help text
        // promises lowercase-only, so an explicit regex rule enforces
        // it — consistent with the seeded pending/viewer/encoder/
        // division-ict-admin names, which are all lowercase.
        $admin = $this->rolesManageUser();

        $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'Bad-Role', 'label' => 'Bad Role', 'permissions' => [],
        ])->assertSessionHasErrors(['name']);

        $this->assertNull(Role::where('name', 'Bad-Role')->first());
    }

    // --- update ---

    public function test_admin_can_update_a_roles_label_description_and_permissions_and_it_is_audited(): void
    {
        $admin = $this->rolesManageUser();
        $role = Role::create(['name' => 'custom', 'label' => 'Custom', 'description' => 'Old']);
        $role->permissions()->sync(Permission::where('name', PermissionEnum::EquipmentView->value)->pluck('id'));

        $response = $this->actingAs($admin)->patch(route('roles.update', $role), [
            'name' => 'custom',
            'label' => 'Custom Renamed',
            'description' => 'New',
            'permissions' => [PermissionEnum::EquipmentView->value, PermissionEnum::EquipmentEdit->value],
        ]);

        $response->assertRedirect(route('roles.edit', $role));

        $role->refresh();
        $this->assertSame('Custom Renamed', $role->label);
        $this->assertSame(
            [PermissionEnum::EquipmentEdit->value, PermissionEnum::EquipmentView->value],
            $role->permissions()->pluck('name')->sort()->values()->all(),
        );

        $this->assertNotNull(AuditLog::where('action', 'role.updated')->where('subject_id', $role->id)->first());
    }

    public function test_update_rejects_an_uppercase_role_name(): void
    {
        $admin = $this->rolesManageUser();
        $role = Role::create(['name' => 'custom', 'label' => 'Custom']);

        $this->actingAs($admin)->patch(route('roles.update', $role), [
            'name' => 'Custom', 'label' => 'Custom', 'permissions' => [],
        ])->assertSessionHasErrors(['name']);

        $this->assertSame('custom', $role->fresh()->name);
    }

    public function test_pending_role_name_cannot_be_changed(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();

        $this->actingAs($admin)->patch(route('roles.update', $pending), [
            'name' => 'renamed-pending', 'label' => 'Pending Approval', 'permissions' => [],
        ])->assertSessionHasErrors(['name']);

        $this->assertSame(Role::PENDING, $pending->fresh()->name);
    }

    public function test_pending_role_permissions_must_stay_empty(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();

        $this->actingAs($admin)->patch(route('roles.update', $pending), [
            'name' => Role::PENDING,
            'label' => 'Pending Approval',
            'permissions' => [PermissionEnum::EquipmentView->value],
        ])->assertSessionHasErrors(['permissions']);

        $this->assertSame([], $pending->fresh()->permissions()->pluck('name')->all());
    }

    public function test_pending_role_label_and_description_remain_editable(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();

        $this->actingAs($admin)->patch(route('roles.update', $pending), [
            'name' => Role::PENDING,
            'label' => 'Awaiting Review',
            'description' => 'Updated copy.',
            'permissions' => [],
        ])->assertRedirect(route('roles.edit', $pending));

        $this->assertSame('Awaiting Review', $pending->fresh()->label);
    }

    // --- destroy ---

    public function test_destroy_blocks_deleting_the_pending_role(): void
    {
        $admin = $this->rolesManageUser();
        $pending = Role::where('name', Role::PENDING)->firstOrFail();

        $this->actingAs($admin)->delete(route('roles.destroy', $pending))
            ->assertSessionHasErrors(['role']);

        $this->assertNotNull($pending->fresh());
    }

    public function test_destroy_blocks_deleting_a_role_assigned_to_users(): void
    {
        $admin = $this->rolesManageUser();
        $viewer = Role::where('name', 'viewer')->firstOrFail();
        $viewerUser = User::factory()->create();
        $viewerUser->assignRole($viewer);

        $this->actingAs($admin)->delete(route('roles.destroy', $viewer))
            ->assertSessionHasErrors(['role']);

        $this->assertNotNull($viewer->fresh());
    }

    public function test_destroy_succeeds_for_an_unused_role_and_is_audited(): void
    {
        $admin = $this->rolesManageUser();
        $unused = Role::create(['name' => 'unused-role', 'label' => 'Unused Role']);

        $this->actingAs($admin)->delete(route('roles.destroy', $unused))
            ->assertRedirect(route('roles.index'));

        $this->assertNull(Role::find($unused->id));
        $this->assertNotNull(AuditLog::where('action', 'role.deleted')->where('subject_id', $unused->id)->first());
    }

    // --- sensitive-permission lockout guards ---

    public function test_update_blocks_removing_roles_manage_when_no_other_role_grants_it(): void
    {
        $actor = $this->rolesManageUser();
        $adminRole = Role::where('name', 'division-ict-admin')->firstOrFail();

        // No custom role exists granting roles.manage — division-ict-admin
        // is the only one (seeded default). Stripping it here would leave
        // nobody in the system able to manage roles at all.
        $remainingPermissions = $adminRole->permissions()->pluck('name')
            ->reject(fn ($name) => $name === PermissionEnum::RolesManage->value)
            ->values()->all();

        $this->actingAs($actor)->patch(route('roles.update', $adminRole), [
            'name' => 'division-ict-admin',
            'label' => $adminRole->label,
            'permissions' => $remainingPermissions,
        ])->assertSessionHasErrors(['permissions']);

        $this->assertTrue($adminRole->fresh()->permissions()->where('name', PermissionEnum::RolesManage->value)->exists());
    }

    public function test_update_blocks_self_lockout_when_actor_holds_the_only_granting_role(): void
    {
        // Another user holds roles.manage+users.manage via a different
        // role, so the SYSTEM-WIDE check passes — but the actor, who
        // holds ONLY 'role-editor', would personally lose roles.manage
        // with no other role of their own granting it back.
        $superAdminRole = Role::create(['name' => 'super-admin', 'label' => 'Super Admin']);
        $superAdminRole->permissions()->sync(
            Permission::whereIn('name', [PermissionEnum::RolesManage->value, PermissionEnum::UsersManage->value])->pluck('id'),
        );
        $otherHolder = User::factory()->create();
        $otherHolder->assignRole($superAdminRole);

        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->permissions()->sync(Permission::where('name', PermissionEnum::RolesManage->value)->pluck('id'));
        $actor = User::factory()->create();
        $actor->assignRole($roleEditor);

        $this->actingAs($actor)->patch(route('roles.update', $roleEditor), [
            'name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => [],
        ])->assertSessionHasErrors(['permissions']);

        $this->assertTrue($roleEditor->fresh()->permissions()->where('name', PermissionEnum::RolesManage->value)->exists());
    }

    public function test_update_allows_removing_roles_manage_when_actor_retains_it_via_another_role(): void
    {
        $roleEditor = Role::create(['name' => 'role-editor', 'label' => 'Role Editor']);
        $roleEditor->permissions()->sync(Permission::where('name', PermissionEnum::RolesManage->value)->pluck('id'));

        $superAdminRole = Role::create(['name' => 'super-admin', 'label' => 'Super Admin']);
        $superAdminRole->permissions()->sync(
            Permission::whereIn('name', [PermissionEnum::RolesManage->value, PermissionEnum::UsersManage->value])->pluck('id'),
        );

        // Actor holds BOTH roles — losing roles.manage from role-editor
        // is fine because super-admin still grants it to them personally.
        $actor = User::factory()->create();
        $actor->assignRole($roleEditor);
        $actor->assignRole($superAdminRole);

        $this->actingAs($actor)->patch(route('roles.update', $roleEditor), [
            'name' => 'role-editor', 'label' => 'Role Editor', 'permissions' => [],
        ])->assertRedirect(route('roles.edit', $roleEditor));

        $this->assertSame([], $roleEditor->fresh()->permissions()->pluck('name')->all());
        $this->assertTrue($actor->fresh()->hasPermissionTo(PermissionEnum::RolesManage));
    }

    public function test_admin_can_edit_permissions_of_a_role_they_hold_when_not_touching_sensitive_permissions(): void
    {
        // Sanity check: unlike UserRoleService's absolute "never touch
        // your own role assignment" rule, editing a role definition you
        // happen to hold is fine as long as roles.manage/users.manage
        // isn't the thing being stripped away.
        $admin = $this->rolesManageUser();
        $adminRole = Role::where('name', 'division-ict-admin')->firstOrFail();

        $this->actingAs($admin)->patch(route('roles.update', $adminRole), [
            'name' => 'division-ict-admin',
            'label' => 'Division ICT Admin',
            'description' => 'Updated description.',
            'permissions' => collect(PermissionEnum::cases())->map->value->all(),
        ])->assertRedirect(route('roles.edit', $adminRole));

        $this->assertSame('Updated description.', $adminRole->fresh()->description);
    }
}
