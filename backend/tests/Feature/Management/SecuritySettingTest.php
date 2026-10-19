<?php

namespace Tests\Feature\Management;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecuritySettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function userWithOnly(Permission $permission): User
    {
        $role = Role::create(['name' => 'only-'.$permission->value, 'label' => 'Only '.$permission->label()]);
        $role->givePermissionTo($permission->value);

        return $this->userWithRole($role->name);
    }

    public function test_admin_can_read_and_update_security_settings(): void
    {
        $admin = $this->userWithRole('admin');

        $response = $this->actingAs($admin)->getJson('/api/settings/security')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'login_rate_limit_per_minute');

        $this->assertSame([
            'login_rate_limit_per_minute',
            'password_reset_rate_limit_per_hour',
            'two_factor_verify_rate_limit_per_minute',
            'trusted_device_days',
            'idle_timeout_seconds',
        ], collect($response->json('data'))->pluck('key')->all());

        $this->actingAs($admin)->putJson('/api/settings/security', [
            'setting_key' => 'login_rate_limit_per_minute',
            'value' => 8,
        ])->assertOk()->assertJsonPath('data.value', 8);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'login_rate_limit_per_minute',
            'value' => '8',
            'type' => 'integer',
        ]);
    }

    public function test_security_settings_require_the_permission(): void
    {
        foreach (['viewer', 'encoder', 'pending'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->getJson('/api/settings/security')->assertForbidden();
            $this->actingAs($user)->putJson('/api/settings/security', [
                'setting_key' => 'login_rate_limit_per_minute',
                'value' => 8,
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('system_settings', ['key' => 'login_rate_limit_per_minute']);
    }

    public function test_users_manage_or_roles_manage_alone_cannot_reach_security_settings(): void
    {
        foreach ([Permission::UsersManage, Permission::RolesManage] as $permission) {
            $this->actingAs($this->userWithOnly($permission))->getJson('/api/settings/security')->assertForbidden();
        }
    }

    public function test_security_settings_manage_alone_grants_access(): void
    {
        $this->actingAs($this->userWithOnly(Permission::SecuritySettingsManage))
            ->getJson('/api/settings/security')
            ->assertOk();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/settings/security')->assertUnauthorized();
    }

    public function test_values_outside_the_allowed_range_or_unknown_keys_are_rejected(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->putJson('/api/settings/security', [
            'setting_key' => 'login_rate_limit_per_minute',
            'value' => 1000,
        ])->assertStatus(422);

        // public_api_rate_limit_per_minute is an rwatch public-portal key
        // inventory deliberately does not carry.
        foreach (['unknown_setting', 'public_api_rate_limit_per_minute'] as $key) {
            $this->actingAs($admin)->putJson('/api/settings/security', [
                'setting_key' => $key,
                'value' => 5,
            ])->assertStatus(422);

            $this->assertDatabaseMissing('system_settings', ['key' => $key]);
        }
    }

    public function test_idle_timeout_defaults_to_fifteen_minutes_and_is_readable_by_any_signed_in_user(): void
    {
        $pending = $this->userWithRole('pending');

        $this->getJson('/api/session-settings')->assertUnauthorized();
        $this->actingAs($pending)->getJson('/api/session-settings')
            ->assertOk()
            ->assertExactJson(['data' => ['idle_timeout_seconds' => 900], 'message' => 'Success']);
    }

    public function test_idle_timeout_can_be_set_down_to_ten_seconds_but_no_lower(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 9])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 7201])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 10])
            ->assertOk()->assertJsonPath('data.value', 10);

        $this->actingAs($admin)->getJson('/api/session-settings')->assertJsonPath('data.idle_timeout_seconds', 10);
    }
}
