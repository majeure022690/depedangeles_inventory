<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Rules\CurrentPassword;
use App\Services\PrimeUserSync;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\Concerns\FakesPrime;
use Tests\TestCase;

class PrimeDisabledTest extends TestCase
{
    use FakesPrime, RefreshDatabase;

    private const SYNC_NOTICE = 'PRIME sync disabled by PRIME_ENABLED=false';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->fakePrime();
    }

    private function breakGlassAccount(): User
    {
        config(['auth.local_login_emails' => ['dev@example.test']]);

        return User::factory()->twoFactorConfirmed()
            ->create(['email' => 'dev@example.test', 'password' => Hash::make('LocalPass123!')])
            ->givePermissionTo(Permission::EquipmentView->value);
    }

    private function securityLog(Closure $run): string
    {
        $path = storage_path('logs/test-prime-disabled-security.log');
        @unlink($path);
        config(['logging.channels.security' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
        Log::forgetChannel('security');

        try {
            $run();

            return (string) @file_get_contents($path);
        } finally {
            Log::forgetChannel('security');
            @unlink($path);
        }
    }

    private function bootWarning(): void
    {
        $provider = new AppServiceProvider($this->app);
        Closure::bind(fn () => $this->warnIfPrimeDisabled(), $provider, AppServiceProvider::class)();
    }

    private function login(): void
    {
        $this->postJson('/api/login', ['email' => 'dev@example.test', 'password' => 'LocalPass123!'])
            ->assertOk()
            ->assertJsonPath('requires_2fa', true);
    }

    public function test_break_glass_login_reason_is_prime_disabled_when_prime_is_turned_off(): void
    {
        $this->breakGlassAccount();
        config(['auth.prime_enabled' => false]);

        $log = $this->securityLog(fn () => $this->login());

        $this->assertStringContainsString('Local break-glass login', $log);
        $this->assertStringContainsString('"reason":"prime_disabled"', $log);
        $this->assertStringNotContainsString('prime_unreachable', $log);
    }

    public function test_break_glass_login_reason_is_prime_unreachable_when_prime_is_down(): void
    {
        $this->breakGlassAccount();
        $this->breakPrime();

        $log = $this->securityLog(fn () => $this->login());

        $this->assertStringContainsString('"reason":"prime_unreachable"', $log);
        $this->assertStringNotContainsString('prime_disabled', $log);
    }

    public function test_current_password_break_glass_reason_distinguishes_disabled_from_unreachable(): void
    {
        $user = $this->breakGlassAccount();
        $confirm = fn () => $this->assertTrue(
            Validator::make(['p' => 'LocalPass123!'], ['p' => [new CurrentPassword($user)]])->passes(),
        );

        config(['auth.prime_enabled' => false]);
        $disabled = $this->securityLog($confirm);

        config(['auth.prime_enabled' => true]);
        $this->breakPrime();
        $down = $this->securityLog($confirm);

        $this->assertStringContainsString('Local break-glass password confirmation', $disabled);
        $this->assertStringContainsString('"reason":"prime_disabled"', $disabled);
        $this->assertStringContainsString('"reason":"prime_unreachable"', $down);
    }

    public function test_current_password_still_refuses_a_non_allowlisted_account_when_prime_is_disabled(): void
    {
        $user = User::factory()->create(['email' => 'juan@deped.gov.ph', 'password' => Hash::make('LocalPass123!')]);
        config(['auth.prime_enabled' => false]);

        $log = $this->securityLog(fn () => $this->assertTrue(
            Validator::make(['p' => 'LocalPass123!'], ['p' => [new CurrentPassword($user)]])->fails(),
        ));

        $this->assertStringNotContainsString('break-glass', $log);
    }

    public function test_disabled_sync_writes_one_throttled_security_warning_instead_of_outage_warnings(): void
    {
        $admin = User::factory()->create()->givePermissionTo(Permission::UsersView->value);
        User::factory()->create(['email' => 'juan@deped.gov.ph']);
        config(['auth.prime_enabled' => false]);

        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$messages) {
            $messages[] = $e->message;
        });

        $log = $this->securityLog(function () use ($admin) {
            $this->actingAs($admin)->getJson('/api/users')->assertOk();
            $this->actingAs($admin)->getJson('/api/users?search=juan')->assertOk();
            app(PrimeUserSync::class)->syncUser(User::where('email', 'juan@deped.gov.ph')->sole());
        });

        $this->assertNotContains('PRIME user sync failed; showing the local copy', $messages);
        $this->assertSame(1, substr_count($log, self::SYNC_NOTICE));
        $this->assertStringContainsString('.WARNING: ', $log);
        $this->assertTrue(Cache::has(PrimeUserSync::DISABLED_NOTICE_KEY));
    }

    public function test_disabled_sync_warning_repeats_after_an_hour(): void
    {
        config(['auth.prime_enabled' => false]);
        $sync = app(PrimeUserSync::class);

        $log = $this->securityLog(function () use ($sync) {
            $sync->importNew();
            $sync->importNew();
            $this->travel(61)->minutes();
            $sync->importNew();
        });

        $this->assertSame(2, substr_count($log, self::SYNC_NOTICE));
    }

    public function test_production_boot_logs_a_disabled_prime_error_once_per_hour(): void
    {
        $this->app['env'] = 'production';
        config(['auth.prime_enabled' => false]);

        $log = $this->securityLog(function () {
            $this->bootWarning();
            $this->bootWarning();
        });

        $this->assertSame(1, substr_count($log, 'PRIME is disabled in production'));
        $this->assertStringContainsString('.ERROR: ', $log);
        $this->assertTrue(Cache::has(AppServiceProvider::PRIME_DISABLED_NOTICE_KEY));
    }

    public function test_boot_logs_nothing_outside_production_or_with_prime_enabled(): void
    {
        $log = $this->securityLog(function () {
            config(['auth.prime_enabled' => false]);
            $this->bootWarning();

            $this->app['env'] = 'production';
            config(['auth.prime_enabled' => true]);
            $this->bootWarning();
        });

        $this->assertSame('', $log);
    }

    public function test_production_boot_warning_survives_an_unavailable_cache(): void
    {
        $this->app['env'] = 'production';
        config(['auth.prime_enabled' => false]);
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache store unavailable'));

        $this->bootWarning();

        $this->addToAssertionCount(1);
    }
}
