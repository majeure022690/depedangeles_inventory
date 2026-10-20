<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionEnum;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * The users.manage screen. UserRoleService owns every invariant: no
 * self-lockout, permission-tier separation (an actor can only hand out roles
 * whose permissions they already hold), and the last users.manage holder
 * can never be demoted or deleted.
 */
class UserControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return $this->userWithRole('admin');
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<string, mixed>
     */
    private function updatePayload(User $target, array $roleIds): array
    {
        return ['name' => $target->name, 'email' => $target->email, 'role_ids' => $roleIds];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'new-person@example.com',
            'password' => 'Password123!xyz',
            'password_confirmation' => 'Password123!xyz',
            'role_ids' => [],
        ], $overrides);
    }

    private function actorHoldingAllPermissionsExceptRolesManage(): User
    {
        $role = Role::create(['name' => 'ops-admin', 'label' => 'Ops Admin']);
        $role->syncPermissions(Permission::whereIn('name', collect(PermissionEnum::cases())
            ->reject(fn (PermissionEnum $case) => $case === PermissionEnum::RolesManage)
            ->map(fn (PermissionEnum $case) => $case->value)
            ->all())->pluck('id'));

        return $this->userWithRole('ops-admin');
    }

    // Authorization

    public function test_every_endpoint_is_forbidden_without_users_manage(): void
    {
        $actor = $this->userWithRole('encoder');
        $target = $this->userWithRole('pending');

        $this->actingAs($actor)->getJson('/api/users')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/users/options')->assertForbidden();
        $this->actingAs($actor)->postJson('/api/users', $this->storePayload())->assertForbidden();
        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('viewer')]))->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/users/{$target->id}")->assertForbidden();

        $this->assertNull(User::where('email', 'new-person@example.com')->first());
        $this->assertSame(['pending'], $target->fresh()->roles()->pluck('name')->all());

        // Refused writes are audited even though the route middleware stops them.
        $this->assertSame(3, Activity::query()->where('event', 'authorization.denied')->where('causer_id', $actor->id)->count());
    }

    // Index and options

    public function test_index_lists_users_with_roles_and_supports_search_and_filters(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Angeles ES', 'school_id' => 107022]);
        $this->userWithRole('encoder', ['name' => 'Encoder Person', 'email' => 'encoder@example.com', 'office_id' => $office->id]);
        $this->userWithRole('viewer', ['name' => 'Viewer Person', 'email' => 'viewer@example.com', 'is_active' => false]);

        $this->actingAs($admin)->getJson('/api/users')->assertOk()->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/users?search=Encoder')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.roles', ['encoder'])
            ->assertJsonPath('data.0.role_ids', [$this->roleId('encoder')])
            ->assertJsonPath('data.0.role_details.0.label', Role::where('name', 'encoder')->value('label'))
            ->assertJsonPath('data.0.office', ['id' => $office->id, 'office_name' => 'Angeles ES', 'school_id' => 107022])
            ->assertJsonPath('data.0.is_self', false)
            ->assertJsonMissingPath('data.0.permissions')
            ->assertJsonMissingPath('data.0.password');

        $this->actingAs($admin)->getJson('/api/users?role=viewer')->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/users?is_active=0')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
        $this->actingAs($admin)->getJson("/api/users?office_id={$office->id}")->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/users?search='.urlencode($admin->email))->assertJsonPath('data.0.is_self', true);
    }

    public function test_options_roles_exclude_roles_the_actor_cannot_assign(): void
    {
        Office::create(['office_name' => 'Active ES', 'is_active' => true]);
        Office::create(['office_name' => 'Closed ES', 'is_active' => false]);

        $response = $this->actingAs($this->actorHoldingAllPermissionsExceptRolesManage())->getJson('/api/users/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['roles' => [['id', 'name', 'label']], 'offices' => [['value', 'label']]]])
            ->assertJsonCount(1, 'data.offices');

        $roleNames = collect($response->json('data.roles'))->pluck('name')->all();
        $this->assertContains('viewer', $roleNames);
        $this->assertContains('encoder', $roleNames);
        $this->assertContains('ops-admin', $roleNames);
        $this->assertNotContains('admin', $roleNames);
    }

    public function test_options_roles_include_every_role_for_an_admin(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/users/options')->assertOk();

        $this->assertSame(Role::query()->orderBy('name')->pluck('name')->all(), collect($response->json('data.roles'))->pluck('name')->sort()->values()->all());
    }

    // Update and role assignment

    public function test_admin_can_change_another_users_role_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $pendingUser = $this->userWithRole('pending', ['name' => 'New Registrant', 'email' => 'pending@example.com']);

        $this->actingAs($admin)->putJson("/api/users/{$pendingUser->id}", $this->updatePayload($pendingUser, [$this->roleId('encoder')]))
            ->assertOk()
            ->assertJsonPath('data.roles', ['encoder']);

        $pendingUser->refresh();
        $this->assertSame(['encoder'], $pendingUser->roles()->pluck('name')->all());
        $this->assertSame([
            'personnel.view', 'personnel.create', 'personnel.edit',
            'equipment.view', 'equipment.create', 'equipment.edit',
            'equipment.transactions.create',
            'isp_accounts.view', 'isp_accounts.create', 'isp_accounts.edit',
            'stakeholder_profile.view', 'stakeholder_profile.edit',
            'internet_connectivity.view', 'internet_connectivity.edit',
        ], $pendingUser->getAllPermissions()->pluck('name')->all());

        $log = Activity::where('event', 'user.roles_changed')->where('subject_id', $pendingUser->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame(['pending'], $log->properties['previous_roles']);
        $this->assertSame(['encoder'], $log->properties['new_roles']);
    }

    public function test_admin_can_assign_multiple_roles_to_a_user_and_permissions_union(): void
    {
        $target = $this->userWithRole('viewer');

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('viewer'), $this->roleId('encoder')]))->assertOk();

        $target->refresh();
        $this->assertSame(['encoder', 'viewer'], $target->roles()->pluck('name')->sort()->values()->all());

        $permissions = $target->getAllPermissions()->pluck('name')->all();
        $this->assertContains('equipment.create', $permissions);
        $this->assertContains('personnel.view', $permissions);
        $this->assertNotContains('equipment.delete', $permissions);
        $this->assertSame(count($permissions), count(array_unique($permissions)));
    }

    public function test_admin_can_strip_all_roles_from_a_user(): void
    {
        $target = $this->userWithRole('viewer');

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", $this->updatePayload($target, []))->assertOk()->assertJsonPath('data.roles', []);

        $this->assertSame([], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_role_ids_must_reference_existing_roles(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", $this->updatePayload($target, [999999]))
            ->assertJsonValidationErrors(['role_ids.0']);
    }

    // Self-edit

    public function test_admin_can_rename_themselves_and_change_office_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Angeles ES']);

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [
            ...$this->updatePayload($admin, [$this->roleId('admin')]),
            'name' => 'Renamed Admin',
            'office_id' => $office->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed Admin')
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_manage', false);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $admin->id,
            'causer_id' => $admin->id,
        ]);
    }

    public function test_self_email_change_requires_the_correct_current_password(): void
    {
        $admin = $this->admin();
        $payload = [...$this->updatePayload($admin, [$this->roleId('admin')]), 'email' => 'changed@example.com'];

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $payload)
            ->assertJsonValidationErrors(['current_password']);
        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [...$payload, 'current_password' => 'wrong-password'])
            ->assertJsonValidationErrors(['current_password']);
        $this->assertNotSame('changed@example.com', $admin->fresh()->email);

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [...$payload, 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'changed@example.com');
    }

    public function test_editing_another_users_email_needs_no_password(): void
    {
        $target = $this->userWithRole('viewer');

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", [
            ...$this->updatePayload($target, [$this->roleId('viewer')]),
            'email' => 'other-new@example.com',
        ])->assertOk();
    }

    public function test_self_password_guesses_on_user_update_are_throttled(): void
    {
        $admin = $this->admin();
        $payload = [...$this->updatePayload($admin, [$this->roleId('admin')]), 'email' => 'changed@example.com', 'current_password' => 'wrong-password'];

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $payload)->assertUnprocessable();
        }

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $payload)->assertTooManyRequests();
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [...$this->updatePayload($admin, [$this->roleId('admin')]), 'is_active' => false])
            ->assertJsonValidationErrors(['is_active' => "You can't deactivate your own account."]);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_sole_users_manage_holder_cannot_remove_it_from_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, [$this->roleId('viewer')]))
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertSame(['admin'], $admin->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_user_cannot_drop_their_own_roles_manage_even_when_others_hold_it(): void
    {
        $admin = $this->admin();
        $this->admin();
        $usersManageOnly = Role::create(['name' => 'user-admin', 'label' => 'User Admin']);
        $usersManageOnly->givePermissionTo(PermissionEnum::UsersManage->value);

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, [$usersManageOnly->id]))
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertSame(['admin'], $admin->fresh()->roles()->pluck('name')->all());
    }

    public function test_user_can_add_a_role_within_their_own_permissions_to_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, [$this->roleId('admin'), $this->roleId('viewer')]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(['admin', 'viewer'], $admin->fresh()->roles()->pluck('name')->all());
        $this->assertDatabaseHas('activity_log', ['event' => 'user.roles_changed', 'subject_id' => $admin->id, 'causer_id' => $admin->id]);
    }

    public function test_user_cannot_give_themselves_a_role_exceeding_their_permissions(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::UsersManage]);
        $ownRoleIds = $actor->roles()->pluck('roles.id')->all();

        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", $this->updatePayload($actor, [...$ownRoleIds, $this->roleId('admin')]))
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertFalse($actor->fresh()->hasPermissionTo(PermissionEnum::RolesManage));
    }

    public function test_user_without_users_manage_cannot_edit_their_own_row(): void
    {
        $actor = $this->userWithRole('encoder');

        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", [...$this->updatePayload($actor, []), 'name' => 'Nope'])
            ->assertForbidden();

        $this->assertNotSame('Nope', $actor->fresh()->name);
    }

    public function test_own_row_is_editable_but_not_manageable_in_the_list(): void
    {
        $admin = $this->admin();
        $other = $this->userWithRole('viewer');

        $rows = collect($this->actingAs($admin)->getJson('/api/users?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$admin->id]['can_edit']);
        $this->assertFalse($rows[$admin->id]['can_manage']);
        $this->assertTrue($rows[$other->id]['can_edit']);
        $this->assertTrue($rows[$other->id]['can_manage']);
    }

    public function test_admin_can_update_profile_office_and_active_flag_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Angeles ES']);
        $target = $this->userWithRole('viewer', ['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'office_id' => $office->id,
            'is_active' => false,
            'role_ids' => [$this->roleId('viewer')],
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.is_active', false);

        $log = Activity::where('event', 'updated')->where('subject_type', $target->getMorphClass())->where('subject_id', $target->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('Old Name', $log->attribute_changes['old']['name']);
        $this->assertSame('New Name', $log->attribute_changes['attributes']['name']);
        $this->assertFalse($log->attribute_changes['attributes']['is_active']);
    }

    public function test_omitting_is_active_on_update_keeps_the_current_value(): void
    {
        $target = $this->userWithRole('viewer', ['is_active' => false]);

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('viewer')]))->assertOk();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_update_email_must_be_unique_excluding_the_target_themselves(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('viewer', ['email' => 'target@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('viewer')]))->assertOk();

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['name' => $target->name, 'email' => 'taken@example.com', 'role_ids' => [$this->roleId('viewer')]])
            ->assertJsonValidationErrors(['email']);

        $this->assertSame('target@example.com', $target->fresh()->email);
    }

    public function test_demoting_an_admin_succeeds_when_another_admin_remains(): void
    {
        $otherAdmin = $this->admin();

        $this->actingAs($this->admin())->putJson("/api/users/{$otherAdmin->id}", $this->updatePayload($otherAdmin, [$this->roleId('viewer')]))->assertOk();

        $this->assertSame(['viewer'], $otherAdmin->fresh()->roles()->pluck('name')->all());
    }

    /**
     * Through roles alone an actor who passes the target-tier guard holds
     * users.manage themselves, so the target is never the sole holder. A
     * direct grant (which the holder count, role-based, doesn't see) still
     * reaches the lockout guard.
     */
    private function actorWithEveryPermissionGrantedDirectly(): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(array_map(fn (PermissionEnum $case) => $case->value, PermissionEnum::cases()));

        return $actor;
    }

    public function test_service_blocks_demoting_the_sole_remaining_users_manage_holder(): void
    {
        $soleAdmin = $this->admin();
        $actor = $this->actorWithEveryPermissionGrantedDirectly();

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleService::class)->syncRoles($actor, $soleAdmin, [$this->roleId('viewer')]);
        } finally {
            $this->assertSame(['admin'], $soleAdmin->fresh()->roles()->pluck('name')->all());
            $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
        }
    }

    public function test_service_no_op_sync_does_not_write_an_audit_log(): void
    {
        $target = $this->userWithRole('viewer');

        app(UserRoleService::class)->syncRoles($this->admin(), $target, [$this->roleId('viewer')]);

        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_demoting_sole_admin_succeeds_when_new_role_set_still_grants_users_manage(): void
    {
        $soleAdmin = $this->admin();

        app(UserRoleService::class)->syncRoles($this->admin(), $soleAdmin, [$this->roleId('admin'), $this->roleId('viewer')]);

        $this->assertSame(['admin', 'viewer'], $soleAdmin->fresh()->roles()->pluck('name')->sort()->values()->all());
        $this->assertTrue($soleAdmin->fresh()->hasPermissionTo('users.manage'));
    }

    // Permission-tier separation

    public function test_users_manage_only_actor_cannot_grant_a_role_containing_roles_manage(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = $this->userWithRole('viewer');

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('admin')]))
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_service_blocks_permission_tier_violation_naming_the_role_and_permission(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = User::factory()->create();

        try {
            app(UserRoleService::class)->syncRoles($actor, $target, [$this->roleId('admin')]);
            $this->fail('Expected a ValidationException naming the offending role/permission.');
        } catch (ValidationException $e) {
            $message = $e->errors()['role_ids'][0];
            $this->assertStringContainsString('Administrator', $message);
            $this->assertStringContainsString(PermissionEnum::RolesManage->value, $message);
        }
    }

    public function test_users_manage_only_actor_can_still_assign_roles_within_their_own_permission_ceiling(): void
    {
        $actor = $this->actorHoldingAllPermissionsExceptRolesManage();
        $target = $this->userWithRole('pending');

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('encoder')]))->assertOk();
        $this->assertSame(['encoder'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('viewer')]))->assertOk();
        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_admin_can_assign_any_role_including_admin_to_others(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())->putJson("/api/users/{$target->id}", $this->updatePayload($target, [$this->roleId('admin')]))->assertOk();

        $this->assertTrue($target->fresh()->hasPermissionTo(PermissionEnum::RolesManage));
    }

    // Store

    public function test_admin_can_create_a_user_with_roles_and_office_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $office = Office::create(['office_name' => 'Angeles ES']);

        $response = $this->actingAs($admin)->postJson('/api/users', $this->storePayload([
            'role_ids' => [$this->roleId('encoder')],
            'office_id' => $office->id,
        ]))->assertCreated()
            ->assertJsonPath('data.email', 'new-person@example.com')
            ->assertJsonPath('data.roles', ['encoder'])
            ->assertJsonPath('data.office.id', $office->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonMissingPath('data.password');

        $created = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('Password123!xyz', $created->password));

        $log = Activity::where('event', 'created')->where('subject_type', $created->getMorphClass())->where('subject_id', $created->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('new-person@example.com', $log->attribute_changes['attributes']['email']);
        $this->assertArrayNotHasKey('password', $log->attribute_changes['attributes']);

        $rolesLog = Activity::where('event', 'user.roles_changed')->where('subject_id', $created->id)->sole();
        $this->assertSame([], $rolesLog->properties['previous_roles']);
        $this->assertSame(['encoder'], $rolesLog->properties['new_roles']);
    }

    public function test_store_can_create_an_inactive_account(): void
    {
        $this->actingAs($this->admin())->postJson('/api/users', $this->storePayload(['is_active' => false]))
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_store_validates_email_password_and_roles(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create();

        $this->actingAs($admin)->postJson('/api/users', $this->storePayload(['email' => $existing->email]))->assertJsonValidationErrors(['email']);
        $this->actingAs($admin)->postJson('/api/users', $this->storePayload(['password_confirmation' => 'SomethingElse123!']))->assertJsonValidationErrors(['password']);
        $this->actingAs($admin)->postJson('/api/users', $this->storePayload(['password' => 'short', 'password_confirmation' => 'short']))->assertJsonValidationErrors(['password']);
        $this->actingAs($admin)->postJson('/api/users', $this->storePayload(['role_ids' => [999999]]))->assertJsonValidationErrors(['role_ids.0']);

        $this->assertNull(User::where('email', 'new-person@example.com')->first());
    }

    public function test_users_manage_only_actor_cannot_create_a_user_with_a_role_containing_roles_manage(): void
    {
        $this->actingAs($this->actorHoldingAllPermissionsExceptRolesManage())
            ->postJson('/api/users', $this->storePayload(['role_ids' => [$this->roleId('admin')]]))
            ->assertJsonValidationErrors(['role_ids']);

        $this->assertNull(User::where('email', 'new-person@example.com')->first());
    }

    // Destroy

    public function test_admin_can_delete_a_user_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('viewer', ['email' => 'doomed@example.com']);
        $targetId = $target->id;

        $this->actingAs($admin)->deleteJson("/api/users/{$targetId}")->assertNoContent();

        $this->assertNull(User::find($targetId));

        $log = Activity::where('event', 'deleted')->where('subject_type', (new User)->getMorphClass())->where('subject_id', $targetId)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('doomed@example.com', $log->attribute_changes['old']['email']);

        $rolesLog = Activity::where('event', 'user.roles_changed')->where('subject_id', $targetId)->sole();
        $this->assertSame(['viewer'], $rolesLog->properties['previous_roles']);
        $this->assertSame([], $rolesLog->properties['new_roles']);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }

    public function test_service_blocks_deleting_the_sole_remaining_users_manage_holder(): void
    {
        $soleAdmin = $this->admin();

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleService::class)->deleteUser($this->actorWithEveryPermissionGrantedDirectly(), $soleAdmin);
        } finally {
            $this->assertNotNull($soleAdmin->fresh());
            $this->assertNull(Activity::where('event', 'deleted')->where('subject_id', $soleAdmin->id)->first());
        }
    }

    public function test_deleting_an_admin_succeeds_when_another_admin_remains(): void
    {
        $otherAdmin = $this->admin();

        $this->actingAs($this->admin())->deleteJson("/api/users/{$otherAdmin->id}")->assertNoContent();

        $this->assertNull(User::find($otherAdmin->id));
    }

    public function test_deleting_a_user_does_not_cascade_delete_their_prior_audit_history(): void
    {
        $admin = $this->admin();
        $target = $this->userWithRole('viewer');

        app(UserRoleService::class)->syncRoles($admin, $target, [$this->roleId('encoder')]);
        $priorLog = Activity::where('event', 'user.roles_changed')->where('subject_id', $target->id)->firstOrFail();

        $this->actingAs($admin)->deleteJson("/api/users/{$target->id}")->assertNoContent();

        $this->assertNotNull(Activity::find($priorLog->id));
    }

    // 2FA reset

    public function test_admin_can_reset_another_users_two_factor_but_not_their_own(): void
    {
        $admin = $this->admin();
        $target = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertOk();

        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('activity_log', ['event' => 'user.two_factor_reset', 'subject_id' => $target->id, 'causer_id' => $admin->id]);

        $this->actingAs($admin)->postJson("/api/users/{$admin->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();
    }
}
