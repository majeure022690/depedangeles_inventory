<?php

namespace Tests\Feature\Management;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
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
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);

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

    public function test_each_effective_change_is_audited_with_old_and_new_values(): void
    {
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);

        $this->actingAs($admin)->putJson('/api/settings/security', [
            'setting_key' => 'trusted_device_days',
            'value' => 7,
        ])->assertOk();

        $activity = Activity::query()->where('event', 'security_setting.updated')->sole();
        $this->assertSame('security_setting.updated', $activity->description);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(SystemSetting::class, $activity->subject_type);
        $this->assertSame(['trusted_device_days' => 30], $activity->attribute_changes['old']);
        $this->assertSame(['trusted_device_days' => 7], $activity->attribute_changes['attributes']);

        $this->actingAs($admin)->putJson('/api/settings/security', [
            'setting_key' => 'trusted_device_days',
            'value' => 0,
        ])->assertOk();

        $latest = Activity::query()->where('event', 'security_setting.updated')->latest('id')->firstOrFail();
        $this->assertSame(['trusted_device_days' => 7], $latest->attribute_changes['old']);
        $this->assertSame(['trusted_device_days' => 0], $latest->attribute_changes['attributes']);

        // Re-saving the same value is not a change.
        $this->actingAs($admin)->putJson('/api/settings/security', [
            'setting_key' => 'trusted_device_days',
            'value' => 0,
        ])->assertOk();
        $this->assertSame(2, Activity::query()->where('event', 'security_setting.updated')->count());
    }

    public function test_rejected_updates_are_not_audited_as_changes(): void
    {
        $this->actingAs($this->userWithRole('viewer'))->putJson('/api/settings/security', [
            'setting_key' => 'trusted_device_days',
            'value' => 7,
        ])->assertForbidden();

        $this->actingAs($this->userWithOnly(Permission::SecuritySettingsManage))->putJson('/api/settings/security', [
            'setting_key' => 'trusted_device_days',
            'value' => 99,
        ])->assertStatus(422);

        $this->assertSame(0, Activity::query()->where('event', 'security_setting.updated')->count());
    }

    public function test_security_settings_require_the_permission(): void
    {
        foreach (['viewer', 'encoder'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->getJson('/api/settings/security')->assertForbidden();
            $this->actingAs($user)->putJson('/api/settings/security', [
                'setting_key' => 'login_rate_limit_per_minute',
                'value' => 8,
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('system_settings', ['key' => 'login_rate_limit_per_minute']);
    }

    public function test_user_and_role_management_permissions_alone_cannot_reach_security_settings(): void
    {
        foreach ([Permission::UsersView, Permission::UsersEdit, Permission::UsersResetTwoFactor, Permission::RolesView, Permission::RolesCreate, Permission::RolesEdit, Permission::RolesDelete] as $permission) {
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
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);

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
        $viewer = $this->userWithRole('viewer');

        $this->getJson('/api/session-settings')->assertUnauthorized();
        $this->actingAs($viewer)->getJson('/api/session-settings')
            ->assertOk()
            ->assertExactJson(['data' => ['idle_timeout_seconds' => 900], 'message' => 'Success']);
    }

    public function test_idle_timeout_can_be_set_down_to_ten_seconds_but_no_lower(): void
    {
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);

        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 9])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 7201])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'idle_timeout_seconds', 'value' => 10])
            ->assertOk()->assertJsonPath('data.value', 10);

        $this->actingAs($admin)->getJson('/api/session-settings')->assertJsonPath('data.idle_timeout_seconds', 10);
    }
}
