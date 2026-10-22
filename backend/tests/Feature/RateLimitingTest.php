<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each auth limiter actually trips on the Nth+1 request; a limiter being
 * attached to a route does not by itself prove its limit or window.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_limiter_trips_after_five_attempts(): void
    {
        $user = User::factory()->create(['email' => 'ratelimit@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_login_limit_follows_the_security_setting(): void
    {
        SystemSetting::query()->updateOrCreate(['key' => 'login_rate_limit_per_minute'], ['value' => '2', 'type' => 'integer']);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/login', ['email' => 'someone@example.com', 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'someone@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_login_limiter_also_caps_one_ip_trying_many_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login', ['email' => "user{$i}@example.com", 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'user99@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_password_reset_limiter_trips_after_three_attempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'someone@example.com'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'someone@example.com'])->assertStatus(429);
    }

    public function test_password_reset_limiter_also_caps_one_ip_mailing_many_accounts(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/forgot-password', ['email' => "someone{$i}@example.com"])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'someone99@example.com'])->assertStatus(429);
    }

    public function test_two_factor_verify_limiter_trips_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/2fa/verify', ['two_fa_token' => 'bogus', 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/2fa/verify', ['two_fa_token' => 'bogus', 'code' => '000000'])->assertStatus(429);
    }

    public function test_password_confirming_actions_are_limited_per_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create()->givePermissionTo(Permission::EquipmentView->value);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->postJson('/api/2fa/recovery-codes/regenerate', ['current_password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->actingAs($user)->postJson('/api/2fa/recovery-codes/regenerate', ['current_password' => 'wrong-password'])
            ->assertStatus(429);
    }
}
