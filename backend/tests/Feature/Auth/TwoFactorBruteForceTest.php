<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Models\User;
use App\Services\SecuritySettings;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Guessing limits on POST /api/2fa/verify beyond the per-minute limiter:
 * a per-account hourly cap, and invalidation of every pending token after
 * repeated consecutive failures.
 */
class TwoFactorBruteForceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'CorrectHorse123!';

    /** @var list<string> */
    private array $recoveryCodes;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the limits under test from the per-minute limiter.
        SecuritySettings::update('two_factor_verify_rate_limit_per_minute', 20);
        SecuritySettings::update('login_rate_limit_per_minute', 20);
    }

    private function enrolledUser(): User
    {
        $tfa = new TwoFactorAuthService;
        $this->secret = $tfa->generateSecret();
        $this->recoveryCodes = $tfa->generateRecoveryCodes();

        return User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'two_factor_secret' => $this->secret,
            'two_factor_recovery_codes' => $tfa->hashRecoveryCodes($this->recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function verifyToken(User $user): string
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->json('two_fa_token');
    }

    private function wrongCode(string $token): TestResponse
    {
        $wrong = (new Google2FA)->getCurrentOtp($this->secret) === '000000' ? '111111' : '000000';

        return $this->postJson('/api/2fa/verify', ['two_fa_token' => $token, 'code' => $wrong]);
    }

    public function test_repeated_failures_invalidate_every_pending_token_for_the_user(): void
    {
        $user = $this->enrolledUser();
        $token = $this->verifyToken($user);
        $otherToken = $this->verifyToken($user);

        foreach (range(1, TwoFactorController::MAX_CONSECUTIVE_FAILURES - 1) as $ignored) {
            $this->wrongCode($token)->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        $this->wrongCode($token)->assertUnprocessable()->assertJsonValidationErrors('two_fa_token');

        foreach ([$token, $otherToken] as $stale) {
            $this->postJson('/api/2fa/verify', [
                'two_fa_token' => $stale,
                'code' => (new Google2FA)->getCurrentOtp($this->secret),
            ])->assertUnprocessable()->assertJsonValidationErrors('two_fa_token');
        }
        $this->assertGuest();

        // A fresh login gets a working token again.
        $this->postJson('/api/2fa/verify', [
            'two_fa_token' => $this->verifyToken($user),
            'recovery_code' => $this->recoveryCodes[0],
        ])->assertOk();
    }

    public function test_a_success_resets_the_consecutive_failure_count(): void
    {
        $user = $this->enrolledUser();

        $token = $this->verifyToken($user);
        foreach (range(1, TwoFactorController::MAX_CONSECUTIVE_FAILURES - 1) as $ignored) {
            $this->wrongCode($token)->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/2fa/verify', ['two_fa_token' => $token, 'recovery_code' => $this->recoveryCodes[0]])->assertOk();

        $this->wrongCode($this->verifyToken($user))->assertJsonValidationErrors('code');
    }

    public function test_verify_attempts_are_capped_per_account_per_hour(): void
    {
        $user = $this->enrolledUser();

        // 2 × (9 failures + 1 recovery-code success) = 20 attempts, never
        // tripping the consecutive-failure invalidation.
        foreach ([0, 1] as $batch) {
            $token = $this->verifyToken($user);
            foreach (range(1, TwoFactorController::MAX_CONSECUTIVE_FAILURES - 1) as $ignored) {
                $this->wrongCode($token)->assertUnprocessable();
            }
            $this->postJson('/api/2fa/verify', ['two_fa_token' => $token, 'recovery_code' => $this->recoveryCodes[$batch]])->assertOk();
            $this->travel(2)->minutes();
        }

        $this->wrongCode($this->verifyToken($user))->assertTooManyRequests();
    }

    public function test_a_recovery_code_cannot_be_spent_twice(): void
    {
        $user = $this->enrolledUser();
        $first = $this->verifyToken($user);
        $second = $this->verifyToken($user);

        $this->postJson('/api/2fa/verify', ['two_fa_token' => $first, 'recovery_code' => $this->recoveryCodes[0]])->assertOk();
        $this->postJson('/api/2fa/verify', ['two_fa_token' => $second, 'recovery_code' => $this->recoveryCodes[0]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recovery_code');

        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
    }
}
