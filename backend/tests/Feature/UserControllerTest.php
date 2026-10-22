<?php

namespace Tests\Feature;

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
    }

    private function superAdmin(): User
    {
        return $this->userWithRole(Role::SUPER_ADMINISTRATOR);
    }

    private function systemAdmin(): User
    {
        return $this->userWithRole('system_administrator');
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
        $response->assertUnprocessable();
        $this->assertStringContainsString($fragment, collect($response->json('errors'))->flatten()->implode(' '));
    }

    // Authorization

    public function test_every_endpoint_is_forbidden_without_a_users_permission_and_writes_are_audited(): void
    {
        $actor = $this->userWithRole('encoder');
        $target = $this->userWithRole('viewer');

        $this->actingAs($actor)->getJson('/api/users')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/users/options')->assertForbidden();
        $this->actingAs($actor)->postJson('/api/users', [])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('encoder'))->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/users/{$target->id}")->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertSame(4, Activity::query()->where('event', 'authorization.denied')->where('causer_id', $actor->id)->count());
    }

    public function test_users_view_alone_lists_accounts_but_cannot_change_them(): void
    {
        $actor = $this->userWithPermissions([PermissionEnum::UsersView]);
        $target = $this->userWithRole('viewer');

        $rows = collect($this->actingAs($actor)->getJson('/api/users?per_page=100')->assertOk()->json('data'));
        $this->assertSame([false], $rows->pluck('can_edit')->unique()->values()->all());
        $this->assertSame([false], $rows->pluck('can_reset_two_factor')->unique()->values()->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('encoder'))->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertForbidden();

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_accounts_cannot_be_created_or_deleted_even_by_a_super_administrator(): void
    {
        $admin = $this->superAdmin();
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'New Person',
            'email' => 'new-person@example.com',
            'password' => 'Password123!xyz',
            'password_confirmation' => 'Password123!xyz',
            'role_id' => $this->roleId('viewer'),
        ])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/users/{$target->id}")->assertForbidden();

        $this->assertNull(User::where('email', 'new-person@example.com')->first());
        $this->assertNotNull($target->fresh());
    }

    // Index and options

    public function test_index_lists_users_with_their_role_and_supports_search_and_filters(): void
    {
        $admin = $this->superAdmin();
        $office = Office::create(['office_name' => 'Angeles ES', 'school_id' => 107022]);
        $this->userWithRole('encoder', ['name' => 'Encoder Person', 'email' => 'encoder@example.com', 'office_id' => $office->id]);
        $this->userWithRole('viewer', ['name' => 'Viewer Person', 'email' => 'viewer@example.com', 'is_active' => false]);
        $encoderRole = Role::where('name', 'encoder')->firstOrFail();

        $this->actingAs($admin)->getJson('/api/users')->assertOk()->assertJsonPath('meta.total', 3);

        $this->actingAs($admin)->getJson('/api/users?search=Encoder')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.roles', ['encoder'])
            ->assertJsonPath('data.0.role_ids', [$encoderRole->id])
            ->assertJsonPath('data.0.role', ['id' => $encoderRole->id, 'name' => 'encoder', 'label' => $encoderRole->label])
            ->assertJsonPath('data.0.role_details.0.label', $encoderRole->label)
            ->assertJsonPath('data.0.office', ['id' => $office->id, 'office_name' => 'Angeles ES', 'school_id' => 107022])
            ->assertJsonPath('data.0.is_self', false)
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.can_delete', false)
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

        $response = $this->actingAs($this->systemAdmin())->getJson('/api/users/options')
            ->assertOk()
            ->assertJsonStructure(['data' => ['roles' => [['id', 'name', 'label']], 'offices' => [['value', 'label']]]])
            ->assertJsonCount(1, 'data.offices');

        $this->assertEqualsCanonicalizing(
            ['system_administrator', 'encoder', 'viewer'],
            collect($response->json('data.roles'))->pluck('name')->all(),
        );
    }

    public function test_options_roles_include_every_role_for_a_super_administrator(): void
    {
        $response = $this->actingAs($this->superAdmin())->getJson('/api/users/options')->assertOk();

        $this->assertEqualsCanonicalizing(Role::pluck('name')->all(), collect($response->json('data.roles'))->pluck('name')->all());
    }

    // Update and role assignment

    public function test_admin_can_change_another_users_role_and_it_is_audited(): void
    {
        $admin = $this->systemAdmin();
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", $this->updatePayload('encoder'))
            ->assertOk()
            ->assertJsonPath('data.roles', ['encoder']);

        $target->refresh();
        $this->assertSame(['encoder'], $target->roles()->pluck('name')->all());
        $this->assertSame([
            'personnel.view', 'personnel.create', 'personnel.edit',
            'equipment.view', 'equipment.create', 'equipment.edit',
            'equipment.transactions.create',
            'isp_accounts.view', 'isp_accounts.create', 'isp_accounts.edit',
            'stakeholder_profile.view', 'stakeholder_profile.edit',
            'internet_connectivity.view', 'internet_connectivity.edit',
        ], $target->getAllPermissions()->pluck('name')->all());

        $log = Activity::where('event', 'user.roles_changed')->where('subject_id', $target->id)->sole();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame(['viewer'], $log->properties['previous_roles']);
        $this->assertSame(['encoder'], $log->properties['new_roles']);
    }

    public function test_a_role_can_be_given_to_an_account_that_has_none(): void
    {
        $target = User::factory()->create(['email' => 'first-login@deped.gov.ph']);

        $this->actingAs($this->systemAdmin())->putJson("/api/users/{$target->id}", $this->updatePayload('viewer'))->assertOk();

        $this->assertTrue($target->fresh()->hasPermissionTo(PermissionEnum::EquipmentView));
    }

    public function test_role_id_is_required_and_must_reference_an_existing_role(): void
    {
        $admin = $this->systemAdmin();
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [])->assertJsonValidationErrors(['role_id']);
        $this->actingAs($admin)->putJson("/api/users/{$target->id}", ['role_id' => 999999])->assertJsonValidationErrors(['role_id']);

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
    }

    public function test_name_and_email_are_managed_by_prime_and_ignored_on_update(): void
    {
        $target = $this->userWithRole('viewer', ['name' => 'Prime Name', 'email' => 'prime@deped.gov.ph']);

        $this->actingAs($this->systemAdmin())->putJson("/api/users/{$target->id}", [
            ...$this->updatePayload('viewer'),
            'name' => 'Local Rename',
            'email' => 'hijacked@example.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Prime Name')
            ->assertJsonPath('data.email', 'prime@deped.gov.ph');
    }

    public function test_admin_can_update_office_and_active_flag_and_it_is_audited(): void
    {
        $admin = $this->systemAdmin();
        $office = Office::create(['office_name' => 'Angeles ES']);
        $target = $this->userWithRole('viewer');

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            ...$this->updatePayload('viewer'),
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
        $target = $this->userWithRole('viewer', ['is_active' => false]);

        $this->actingAs($this->systemAdmin())->putJson("/api/users/{$target->id}", $this->updatePayload('viewer'))->assertOk();

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_demoting_an_admin_succeeds_when_another_users_edit_holder_remains(): void
    {
        $otherAdmin = $this->systemAdmin();

        $this->actingAs($this->systemAdmin())->putJson("/api/users/{$otherAdmin->id}", $this->updatePayload('viewer'))->assertOk();

        $this->assertSame(['viewer'], $otherAdmin->fresh()->roles()->pluck('name')->all());
    }

    // Self-edit

    public function test_admin_can_change_their_own_office(): void
    {
        $admin = $this->systemAdmin();
        $office = Office::create(['office_name' => 'Angeles ES']);

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [
            ...$this->updatePayload('system_administrator'),
            'office_id' => $office->id,
        ])->assertOk()
            ->assertJsonPath('data.office_id', $office->id)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_reset_two_factor', false);

        $this->assertDatabaseHas('activity_log', ['event' => 'updated', 'subject_type' => User::class, 'subject_id' => $admin->id, 'causer_id' => $admin->id]);
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->systemAdmin();

        $this->actingAs($admin)->putJson("/api/users/{$admin->id}", [...$this->updatePayload('system_administrator'), 'is_active' => false])
            ->assertJsonValidationErrors(['is_active' => "You can't deactivate your own account."]);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_cannot_drop_their_own_users_edit_even_when_others_hold_it(): void
    {
        $admin = $this->systemAdmin();
        $this->systemAdmin();

        $this->assertRefusedWith(
            $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload('viewer')),
            'lock you out',
        );

        $this->assertSame(['system_administrator'], $admin->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_user_cannot_drop_their_own_roles_edit(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        $this->assertRefusedWith(
            $this->actingAs($admin)->putJson("/api/users/{$admin->id}", $this->updatePayload('system_administrator')),
            PermissionEnum::RolesEdit->value,
        );

        $this->assertSame([Role::SUPER_ADMINISTRATOR], $admin->fresh()->roles()->pluck('name')->all());
    }

    public function test_user_cannot_give_themselves_a_role_exceeding_their_permissions(): void
    {
        $actor = $this->systemAdmin();

        $this->assertRefusedWith(
            $this->actingAs($actor)->putJson("/api/users/{$actor->id}", $this->updatePayload(Role::SUPER_ADMINISTRATOR)),
            PermissionEnum::RolesCreate->value,
        );

        $this->assertFalse($actor->fresh()->hasPermissionTo(PermissionEnum::RolesEdit));
    }

    public function test_user_without_users_edit_cannot_edit_their_own_row(): void
    {
        $actor = $this->userWithRole('encoder');

        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", [...$this->updatePayload('system_administrator'), 'office_id' => null])
            ->assertForbidden();

        $this->assertSame(['encoder'], $actor->fresh()->roles()->pluck('name')->all());
    }

    // Last holder and permission tier

    public function test_service_blocks_demoting_the_sole_remaining_users_edit_holder(): void
    {
        $soleAdmin = $this->systemAdmin();
        $actor = User::factory()->create();
        $actor->givePermissionTo(array_map(fn (PermissionEnum $case) => $case->value, PermissionEnum::cases()));

        $this->expectException(ValidationException::class);

        try {
            app(UserRoleService::class)->syncRoles($actor, $soleAdmin, [$this->roleId('viewer')]);
        } finally {
            $this->assertSame(['system_administrator'], $soleAdmin->fresh()->roles()->pluck('name')->all());
            $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
        }
    }

    public function test_service_no_op_sync_does_not_write_an_audit_log(): void
    {
        $target = $this->userWithRole('viewer');

        app(UserRoleService::class)->syncRoles($this->systemAdmin(), $target, [$this->roleId('viewer')]);

        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_system_administrator_cannot_grant_the_super_administrator_role(): void
    {
        $target = $this->userWithRole('viewer');

        $this->assertRefusedWith(
            $this->actingAs($this->systemAdmin())->putJson("/api/users/{$target->id}", $this->updatePayload(Role::SUPER_ADMINISTRATOR)),
            'Super Administrator',
        );

        $this->assertSame(['viewer'], $target->fresh()->roles()->pluck('name')->all());
        $this->assertNull(Activity::where('event', 'user.roles_changed')->first());
    }

    public function test_service_blocks_permission_tier_violation_naming_the_role_and_permission(): void
    {
        $target = User::factory()->create();

        try {
            app(UserRoleService::class)->syncRoles($this->systemAdmin(), $target, [$this->roleId(Role::SUPER_ADMINISTRATOR)]);
            $this->fail('Expected a ValidationException naming the offending role/permission.');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('Super Administrator', $message);
            $this->assertStringContainsString(PermissionEnum::RolesCreate->value, $message);
        }
    }

    public function test_system_administrator_can_still_assign_roles_within_their_own_permission_ceiling(): void
    {
        $actor = $this->systemAdmin();
        $target = User::factory()->create();

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('encoder'))->assertOk();
        $this->assertSame(['encoder'], $target->fresh()->roles()->pluck('name')->all());

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('system_administrator'))->assertOk();
        $this->assertSame(['system_administrator'], $target->fresh()->roles()->pluck('name')->all());
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
        $admin = $this->systemAdmin();
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

        $this->actingAs($actor)->putJson("/api/users/{$target->id}", $this->updatePayload('viewer'))->assertForbidden();
        $this->actingAs($actor)->postJson("/api/users/{$target->id}/2fa/reset", ['current_password' => 'password'])->assertOk();

        $this->assertSame([], $target->fresh()->roles()->pluck('name')->all());
        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
    }
}
