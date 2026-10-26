<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'BrandNewHorse123!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.local_login_emails' => ['admin@example.test']]);
    }

    private function localUser(): User
    {
        return User::factory()->create(['email' => 'admin@example.test', 'password' => Hash::make('OldPassword123!')]);
    }

    public function test_sets_the_password_of_a_local_only_account_and_revokes_sessions_and_devices(): void
    {
        $user = $this->localUser();
        DB::table('sessions')->insert([
            'id' => 'stale-session', 'user_id' => $user->id, 'ip_address' => null,
            'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        $user->trustedDevices()->create(['token_hash' => hash('sha256', 'device'), 'expires_at' => now()->addDays(30)]);

        $this->artisan('users:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('New password', self::NEW_PASSWORD)
            ->expectsQuestion('Confirm new password', self::NEW_PASSWORD)
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-session']);
        $this->assertSame(0, $user->trustedDevices()->count());

        $activity = Activity::query()->where('event', 'user.password_set')->sole();
        $this->assertTrue($activity->subject->is($user));
        $this->assertNull($activity->causer_id);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($activity->properties));
    }

    public function test_refuses_an_account_that_is_not_on_the_local_login_allowlist(): void
    {
        $user = $this->localUser();
        config(['auth.local_login_emails' => ['someone-else@example.test']]);

        $this->artisan('users:set-password', ['email' => 'admin@example.test'])
            ->expectsOutputToContain('Only developer break-glass accounts')
            ->assertFailed();

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
        $this->assertSame(0, Activity::query()->where('event', 'user.password_set')->count());
    }

    public function test_allowlist_match_ignores_case(): void
    {
        $this->localUser();
        config(['auth.local_login_emails' => ['ADMIN@Example.test']]);

        $this->artisan('users:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('New password', self::NEW_PASSWORD)
            ->expectsQuestion('Confirm new password', self::NEW_PASSWORD)
            ->assertSuccessful();
    }

    public function test_rejects_a_weak_or_mismatched_password(): void
    {
        $user = $this->localUser();

        $this->artisan('users:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('New password', 'short')
            ->expectsQuestion('Confirm new password', 'short')
            ->assertFailed();

        $this->artisan('users:set-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('New password', self::NEW_PASSWORD)
            ->expectsQuestion('Confirm new password', 'SomethingElse123!')
            ->assertFailed();

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }

    public function test_fails_for_an_unknown_email(): void
    {
        $this->artisan('users:set-password', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('No account with that email address.')
            ->assertFailed();
    }
}
