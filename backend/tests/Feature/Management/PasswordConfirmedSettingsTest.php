<?php

namespace Tests\Feature\Management;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PasswordConfirmedSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = [
        ['PUT', '/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7]],
        ['DELETE', '/api/settings/system-logs/errors', []],
        ['DELETE', '/api/settings/system-logs/slow-queries', []],
    ];

    private const AUDITED_EVENTS = ['security_setting.updated', 'system_log.errors_cleared', 'system_log.slow_queries_cleared'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_user_without_the_permission_gets_403_whatever_the_password_and_no_validation_errors(): void
    {
        $this->userWithOnly(Permission::EquipmentView);
        $auditedChanges = Activity::query()->whereIn('event', self::AUDITED_EVENTS)->count();

        foreach (self::ENDPOINTS as [$method, $uri, $payload]) {
            $user = User::factory()->create();
            $user->assignRole('only-'.Permission::EquipmentView->value);

            foreach ([[], ['current_password' => 'wrong-password'], ['current_password' => 'password']] as $password) {
                $this->actingAs($user)->json($method, $uri, $payload + $password)
                    ->assertForbidden()
                    ->assertJsonMissingPath('errors');
            }
        }

        $this->assertDatabaseMissing('system_settings', ['key' => 'trusted_device_days']);
        $this->assertSame($auditedChanges, Activity::query()->whereIn('event', self::AUDITED_EVENTS)->count());
    }

    public function test_the_password_confirm_limit_is_six_per_minute_and_shared_with_the_profile_page(): void
    {
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);
        $payload = ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => 'wrong-password'];

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($admin)->putJson('/api/settings/security', $payload)->assertUnprocessable();
        }

        $this->actingAs($admin)->putJson('/api/settings/security', ['current_password' => 'password'] + $payload)->assertTooManyRequests();
        $this->actingAs($admin)->postJson('/api/profile', ['name' => 'Blocked', 'email' => $admin->email])->assertTooManyRequests();

        $this->travel(61)->seconds();
        $this->actingAs($admin)->putJson('/api/settings/security', ['current_password' => 'password'] + $payload)->assertOk();
        $this->actingAs($admin)->postJson('/api/profile', ['name' => 'Allowed', 'email' => $admin->email])->assertOk();
    }

    public function test_successful_confirmations_count_toward_the_limit_too(): void
    {
        $admin = $this->userWithOnly(Permission::SecuritySettingsManage);

        foreach (range(1, 6) as $value) {
            $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => $value, 'current_password' => 'password'])->assertOk();
        }

        $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => 'password'])->assertTooManyRequests();
    }

    public function test_the_limit_is_per_user(): void
    {
        $first = $this->userWithOnly(Permission::SecuritySettingsManage);
        $second = User::factory()->create();
        $second->assignRole('only-'.Permission::SecuritySettingsManage->value);
        $payload = ['setting_key' => 'trusted_device_days', 'value' => 7];

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($first)->putJson('/api/settings/security', $payload + ['current_password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->actingAs($first)->putJson('/api/settings/security', $payload + ['current_password' => 'password'])->assertTooManyRequests();

        $this->actingAs($second)->putJson('/api/settings/security', $payload + ['current_password' => 'password'])->assertOk();
    }

    public function test_denied_requests_do_not_spend_the_password_confirm_budget(): void
    {
        $user = $this->userWithOnly(Permission::EquipmentView);

        foreach (range(1, 7) as $ignored) {
            $this->actingAs($user)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => 'password'])
                ->assertForbidden();
        }

        $this->actingAs($user)->postJson('/api/profile', ['name' => 'Still allowed', 'email' => $user->email])->assertOk();
    }

    public function test_failed_password_confirmations_are_logged_without_the_input(): void
    {
        $path = storage_path('logs/test-password-confirm-security.log');
        @unlink($path);
        config(['logging.channels.security' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
        Log::forgetChannel('security');

        try {
            $admin = $this->userWithOnly(Permission::SecuritySettingsManage);
            $outsider = $this->userWithOnly(Permission::EquipmentView);

            $this->actingAs($admin)->putJson('/api/settings/security', ['setting_key' => 'trusted_device_days', 'value' => 7, 'current_password' => 'Secret-Guess-123'])->assertUnprocessable();
            $this->actingAs($outsider)->putJson('/api/settings/security', ['setting_key' => 'x', 'value' => 1, 'current_password' => 'password'])->assertForbidden();

            $log = (string) file_get_contents($path);
            $this->assertSame(1, substr_count($log, 'Password re-confirmation failed'));
            $this->assertStringContainsString('"user_id":'.$admin->id, $log);
            $this->assertStringNotContainsString('Secret-Guess-123', $log);
        } finally {
            Log::forgetChannel('security');
            @unlink($path);
        }
    }

    private function userWithOnly(Permission $permission): User
    {
        $role = Role::create(['name' => 'only-'.$permission->value, 'label' => 'Only '.$permission->label()]);
        $role->givePermissionTo($permission->value);

        $user = User::factory()->create();
        $user->assignRole($role->name);

        return $user;
    }
}
