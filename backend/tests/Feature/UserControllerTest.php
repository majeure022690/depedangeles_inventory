<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesUsersWithRoles;
use Tests\TestCase;

/**
 * Fixture roles (reader/writer/manager/owner) are defined by permission set
 * and data scope; the seeded roles appear only where the protected
 * super_administrator role is itself the subject.
 *
 * Accounts come from PRIME: name and email are not editable here, and
 * accounts cannot be created or deleted. Editing sets office, active flag
 * and the single role. UserRoleService owns every invariant: no
 * self-lockout, permission-tier separation (an actor can only hand out
 * roles whose permissions they already hold), and the last users.edit
 * holder can never be demoted.
 */
class UserControllerTest extends TestCase
{
    use CreatesUsersWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $all = PermissionEnum::cases();
        $roleWrites = [PermissionEnum::RolesCreate, PermissionEnum::RolesEdit, PermissionEnum::RolesDelete];

        $this->fixtureRole('reader', 'Reader', DataScope::Own, [PermissionEnum::EquipmentView]);
        $this->fixtureRole('writer', 'Writer', DataScope::All, [PermissionEnum::PersonnelView, PermissionEnum::EquipmentView, PermissionEnum::EquipmentEdit]);
        $this->fixtureRole('manager', 'Manager', DataScope::All, array_values(array_filter($all, fn (PermissionEnum $p) => ! in_array($p, $roleWrites, true))));
        $this->fixtureRole('owner', 'Owner', DataScope::All, $all);
    }

    /**
     * @param  array<int, PermissionEnum>  $permissions
     */
    private function fixtureRole(string $name, string $label, DataScope $scope, array $permissions): void
    {
        Role::create(['name' => $name, 'label' => $label, 'data_scope' => $scope])
            ->givePermissionTo(array_map(fn (PermissionEnum $p) => $p->value, $permissions));
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMINISTRATOR);
    }

    private function owner(): User
    {
        return $this->userWithRole('owner');
    }

    private function manager(): User
    {
        return $this->userWithRole('manager');
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(string $role): array
    {
        return ['role_id' => $this->roleId($role)];
    }

    private function assertRefusedWith(TestResponse $response, string $fragment): void
    {
        $response->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertStringContainsString($fragment, implode(' ', $response->json('errors.role_id')));
    }

    // Authorization

    public function test_every_endpoint_is_forbidden_without_a_users_permission_and_writes_are_audited(): void
    {
        $actor = $this->userWithRole('writer');
        $target = $this->userWithRole('reader');

        $this->actingAs($actor)->getJson('/api/users')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/users/options')->assertForbidden();
        $this->actingAs($actor)->postJson('/api/users', [])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('writer'))->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/users/{$target->id}")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();

        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertSame(4, Activity::query()->where('event', 'authorization.denied')->where('causer_id', $actor->id)->count());
    }

    public function test_users_view_alone_lists_accounts_but_cannot_change_them(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::UsersView]);
        $target = $this->userWithRole('reader');

        $rows = collect($this->actingAs($actor)->getJson('/api/users?per_page=100')->assertOk()->json('data'));
        $this->assertSame([false], $rows->pluck('can_edit')->unique()->values()->all());
        $this->assertSame([false], $rows->pluck('can_reset_two_factor')->unique()->values()->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('writer'))->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();

        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_accounts_cannot_be_created_or_deleted_even_with_every_permission(): void
    {
        $admin = $this->owner();
        $target = $this->userWithRole('reader');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'New Person',
            'email' => 'new-person@example.com',
            'password' => 'Password123!xyz',
            'password_confirmation' => 'Password123!xyz',
            'role_id' => $this->roleId('reader'),
        ])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/users/{$target->id}")->assertForbidden();

        $this->assertNull(User::where('email', 'new-person@example.com')->first());
        $this->assertNotNull($target->fresh());
    }

    // Index and options

    public function test_index_lists_users_with_their_role_and_supports_search_and_filters(): void
    {
        $admin = $this->owner();
        $office = Office::create(['office_name' => 'Angeles ES', 'school_id' => 107022]);
        $this->userWithRole('writer', ['name' => 'Writer Person', 'email' => 'writer@example.com', 'office_id' => $office->id]);
        $this->userWithRole('reader', ['name' => 'Reader Person', 'email' => 'reader@example.com', 'is_active' => false]);
        $encoderRole = Role::where('name', 'writer')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/users')->assertOk()->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/users?search=Writer')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.roles', ['writer'])
            ->assertJsonPath('data.0.role_ids', [$encoderRole->id])
            ->assertJsonPath('data.0.role', ['id' => $encoderRole->id, 'name' => 'writer', 'label' => $encoderRole->label])
            ->assertJsonPath('data.0.role_details.0.label', $encoderRole->label)
            ->assertJsonPath('data.0.office', ['id' => $office->id, 'office_name' => 'Angeles ES', 'school_id' => 107022])
            ->assertJsonPath('data.0.is_self', false)
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonMissingPath('data.0.permissions')
            ->assertJsonMissingPath('data.0.password');

        $this->actingAs($admin)->getJson('/api/users?role=reader')->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/users?is_active=0')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
        $this->actingAs($admin)->getJson("/api/users?office_id={$office->id}")->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/users?search='.urlencode($admin->email))->assertJsonPath('data.0.is_self', true);
    }

    public function test_options_roles_exclude_roles_the_actor_cannot_assign(): void
    {
        Office::create(['office_name' => 'Active ES', 'is_active' => true]);
        Office::create(['office_name' => 'Closed ES', 'is_active' => false]);

        $response = $this->actingAs($this->manager())->getJson('/api/users/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['roles' => [['id', 'name', 'label']], 'offices' => [['value', 'label']]]])
            ->assertJsonCount(1, 'data.offices');

        $names = collect($response->json('data.roles'))->pluck('name')->all();
        foreach (['manager', 'writer', 'reader'] as $assignable) {
            $this->assertContains($assignable, $names);
        }
        $this->assertNotContains('owner', $names);
        $this->assertNotContains(Role::SUPER_ADMINISTRATOR, $names);
    }

    public function test_options_roles_include_every_role_for_a_holder_of_every_permission_at_all_scope(): void
    {
        $response = $this->actingAs($this->owner())->getJson('/api/users/options')->assertOk();

        $this->assertEqualsCanonicalizing(Role::pluck('name')->all(), collect($response->json('data.roles'))->pluck('name')->all());
    }

    // Update and role assignment

    public function test_admin_can_change_another_users_role_and_it_is_audited(): void
    {
        $admin = $this->manager();
        $target = $this->userWithRole('reader');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->updatePayload('writer'))
            ->assertOk()
            ->assertJsonPath('data.roles', ['writer']);

        $target->refresh();
        $this->assertSame(['writer'], $target->roles()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['personnel.view', 'equipment.view', 'equipment.edit'],
            $target->getAllPermissions()->pluck('name')->all(),
        );

        $log = Activity::where('event', 'user.roles_changed')->where('subject_id', $target->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame(['reader'], $log->properties['previous_roles']);
        $this->assertSame(['writer'], $log->properties['new_roles']);
    }

    public function test_a_role_can_be_given_to_an_account_that_has_none(): void
    {
        $target = User::factory()->create(['email' => 'first-login@deped.gov.ph']);

        $this->actingAs($this->manager())->putJson("/api/users/{$target->id}", $this->updatePayload('reader'))->assertOk();

        $this->assertTrue($target->fresh()->hasPermissionTo(PermissionEnum::EquipmentView));
    }

    public function test_role_id_is_required_and_must_reference_an_existing_role(): void
    {
        $admin = $this->manager();
        $target = $this->userWithRole('reader');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [])->assertJsonValidationErrors(['role_id']);
        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => 999999])->assertJsonValidationErrors(['role_id']);

        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_name_and_email_are_managed_by_prime_and_ignored_on_update(): void
    {
        $target = $this->userWithRole('reader', ['name' => 'Prime Name', 'email' => 'prime@deped.gov.ph']);

        $this->actingAs($this->manager())->putJson("/api/users/{$target->id}", [
            ...$this->updatePayload('reader'),
            'name' => 'Local Rename',
            'email' => 'hijacked@example.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Prime Name')
            ->assertJsonPath('data.email', 'prime@deped.gov.ph');
    }

    public function test_admin_can_update_office_and_active_flag_and_it_is_audited(): void
    {
        $admin = $this->manager();
        $office = Office::create(['office_name' => 'Angeles ES']);
        $target = $this->userWithRole('reader');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            ...$this->updatePayload('reader'),
            'office_id' => $office->id,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.is_active', false);

        $log = Activity::where('event', 'updated')->where('subject_type', $target->getMorphClass())->where('subject_id', $target->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertTrue($log->attribute_changes['old']['is_active']);
        $this->assertFalse($log->attribute_changes['attributes']['is_active']);
    }

    public function test_omitting_is_active_on_update_keeps_the_current_value(): void
    {
        $target = $this->userWithRole('reader', ['is_active' => false]);

        $this->actingAs($this->manager())->putJson("/api/users/{$target->id}", $this->updatePayload('reader'))->assertOk();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_demoting_an_admin_succeeds_when_another_users_edit_holder_remains(): void
    {
        $otherAdmin = $this->manager();

        $this->actingAs($this->manager())->putJson("/api/users/{$otherAdmin->id}", $this->updatePayload('reader'))->assertOk();

        $this->assertSame(['reader'], $otherAdmin->fresh()->roles()->pluck('name')->all());
    }

    // Self-edit

    public function test_admin_can_change_their_own_office(): void
    {
        $admin = $this->manager();
        $office = Office::create(['office_name' => 'Angeles ES']);

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [
            ...$this->updatePayload('manager'),
            'office_id' => $office->id,
        ])->assertOk()
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_reset_two_factor', false);

        $this->assertDatabaseHas('activity_log', ['event' => 'updated', 'subject_type' => User::class, 'subject_id' => $admin->id, 'causer_id' => $admin->id]);
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->manager();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [...$this->updatePayload('manager'), 'is_active' => false])
            ->assertJsonValidationErrors(['is_active' => "You can't deactivate your own account."]);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_cannot_drop_their_own_users_edit_even_when_others_hold_it(): void
    {
        $admin = $this->manager();
        $this->manager();

        $this->assertRefusedWith(
            $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload('reader')),
            'lock you out',
        );

        $this->assertSame(['manager'], $admin->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_user_cannot_drop_their_own_roles_edit(): void
    {
        $admin = $this->owner();
        $this->owner();

        $this->assertRefusedWith(
            $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload('manager')),
            PermissionEnum::RolesEdit->value,
        );

        $this->assertSame(['owner'], $admin->fresh()->roles()->pluck('name')->all());
    }

    public function test_user_cannot_give_themselves_a_role_exceeding_their_permissions(): void
    {
        $actor = $this->manager();

        $this->assertRefusedWith(
            $this->actingAs($actor)->putJson("/api/users/{$actor->id}", $this->updatePayload('owner')),
            PermissionEnum::RolesCreate->value,
        );

        $this->assertFalse($actor->fresh()->hasPermissionTo(PermissionEnum::RolesEdit));
    }

    public function test_user_without_users_edit_cannot_edit_their_own_row(): void
    {
        $actor = $this->userWithRole('writer');

        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", [...$this->updatePayload('manager'), 'office_id' => null])
            ->assertForbidden();

        $this->assertSame(['writer'], $actor->fresh()->roles()->pluck('name')->all());
    }

    // Last holder and permission tier

    public function test_service_blocks_demoting_the_sole_remaining_users_edit_holder(): void
    {
        $soleAdmin = $this->manager();
        $actor = User::factory()->create();
        $actor->givePermissionTo(array_map(fn (PermissionEnum $case) => $case->value, PermissionEnum::cases()));
        $this->giveDataScope($actor, DataScope::All);

        try {
            app(UserRoleService::class)->syncRoles($actor, $soleAdmin, [$this->roleId('reader')]);
            $this->fail('Expected a ValidationException for the sole users.edit holder.');
        } catch (ValidationException $e) {
            $this->assertSame(['role_id'], array_keys($e->errors()));
            $this->assertStringContainsString('only remaining user', implode(' ', $e->errors()['role_id']));
        } finally {
            $this->assertSame(['manager'], $soleAdmin->fresh()->roles()->pluck('name')->all());
            $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
        }
    }

    public function test_service_no_op_sync_does_not_write_an_audit_log(): void
    {
        $target = $this->userWithRole('reader');

        app(UserRoleService::class)->syncRoles($this->manager(), $target, [$this->roleId('reader')]);

        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_actor_cannot_grant_a_role_holding_permissions_they_lack(): void
    {
        $target = $this->userWithRole('reader');

        $this->assertRefusedWith(
            $this->actingAs($this->manager())->putJson("/api/users/{$target->id}", $this->updatePayload('owner')),
            'Owner',
        );

        $this->assertSame(['reader'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_service_blocks_permission_tier_violation_naming_the_role_and_permission(): void
    {
        $target = User::factory()->create();

        try {
            app(UserRoleService::class)->syncRoles($this->manager(), $target, [$this->roleId('owner')]);
            $this->fail('Expected a ValidationException naming the offending role/permission.');
        } catch (ValidationException $e) {
            $this->assertSame(['role_id'], array_keys($e->errors()));
            $message = implode(' ', $e->errors()['role_id']);
            $this->assertStringContainsString('Owner', $message);
            $this->assertStringContainsString(PermissionEnum::RolesCreate->value, $message);
        }
    }

    public function test_actor_can_still_assign_roles_within_their_own_permission_ceiling(): void
    {
        $actor = $this->manager();
        $target = User::factory()->create();

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('writer'))->assertOk();
        $this->assertSame(['writer'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('manager'))->assertOk();
        $this->assertSame(['manager'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_super_administrator_can_assign_the_super_administrator_role_to_others(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin())->putJson("/api/users/{$target->id}", $this->updatePayload(Role::SUPER_ADMINISTRATOR))->assertOk();

        $this->assertTrue($target->fresh()->hasPermissionTo(PermissionEnum::RolesEdit));
    }

    // 2FA reset

    public function test_admin_can_reset_another_users_two_factor_but_not_their_own(): void
    {
        $admin = $this->manager();
        $target = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($admin)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertOk();

        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('activity_log', ['event' => 'user.two_factor_reset', 'subject_id' => $target->id, 'causer_id' => $admin->id]);

        $this->actingAs($admin)->postJson("/api/users/{$admin->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();
    }

    public function test_reset_two_factor_permission_alone_cannot_edit_accounts(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::UsersResetTwoFactor]);
        $target = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('reader'))->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertOk();

        $this->assertSame([], $target->fresh()->roles()->pluck('name')->all());
        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
    }
}
