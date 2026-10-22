<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegenerateRecoveryCodesRequest;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Http\Requests\Auth\TwoFactorSetupRequest;
use App\Http\Requests\Auth\TwoFactorVerifyRequest;
use App\Models\User;
use App\Services\LoginSession;
use App\Services\TrustedDeviceService;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public const SETUP_PREFIX = '2fa_setup:';

    public const VERIFY_PREFIX = '2fa_verify:';

    /**
     * Consecutive failed verify attempts after which every pending
     * two_fa_token for the user is invalidated (on top of the
     * two-factor-verify rate limiter's per-minute and per-hour caps).
     */
    public const MAX_CONSECUTIVE_FAILURES = 10;

    public function setup(TwoFactorSetupRequest $request): JsonResponse
    {
        $userId = $this->resolvePendingUserId($request->string('setup_token')->value(), self::SETUP_PREFIX);

        if (! $userId || ! ($user = $this->pendingUser($userId)) || $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'setup_token' => __('Your setup session has expired. Please log in again.'),
            ]);
        }

        if (! $user->hasAppAccess()) {
            return $this->refuseWithoutAccess($user, $request, 'two_factor_setup', $request->string('setup_token')->value(), "2fa_setup_secret:{$userId}");
        }

        $tfa = new TwoFactorAuthService;
        $secret = $tfa->generateSecret();

        Cache::put("2fa_setup_secret:{$userId}", $secret, now()->addMinutes(10));

        return response()->json([
            'data' => [
                'qr_code' => $tfa->getQrCodeDataUri("{$user->name} ({$user->email})", $secret),
                'secret' => $secret,
            ],
        ]);
    }

    public function confirmSetup(TwoFactorConfirmRequest $request): JsonResponse
    {
        $setupToken = $request->string('setup_token')->value();
        $userId = $this->resolvePendingUserId($setupToken, self::SETUP_PREFIX);

        if (! $userId || ! ($user = $this->pendingUser($userId)) || $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'setup_token' => __('Your setup session has expired. Please log in again.'),
            ]);
        }

        if (! $user->hasAppAccess()) {
            return $this->refuseWithoutAccess($user, $request, 'two_factor_setup_confirm', $setupToken, "2fa_setup_secret:{$userId}");
        }

        $secret = Cache::get("2fa_setup_secret:{$userId}");
        if (! $secret) {
            throw ValidationException::withMessages([
                'setup_token' => __('Your setup session has expired. Please start again.'),
            ]);
        }

        $tfa = new TwoFactorAuthService;
        if (! $tfa->verifyCode($secret, $request->string('code')->value())) {
            throw ValidationException::withMessages([
                'code' => __('Invalid verification code.'),
            ]);
        }

        $recoveryCodes = $tfa->generateRecoveryCodes();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = $tfa->hashRecoveryCodes($recoveryCodes);
        $user->two_factor_confirmed_at = now();
        $user->save();

        Cache::forget("2fa_setup_secret:{$userId}");
        Cache::forget($setupToken);

        Log::channel('security')->info('Two-factor authentication enabled', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        $response = LoginSession::complete($user, $request, 'two_factor_setup');
        $response['data']['recovery_codes'] = $recoveryCodes;

        return response()->json($response);
    }

    public function verify(TwoFactorVerifyRequest $request): JsonResponse
    {
        $twoFaToken = $request->string('two_fa_token')->value();
        $userId = $this->resolvePendingUserId($twoFaToken, self::VERIFY_PREFIX);

        if (! $userId || ! ($user = $this->pendingUser($userId)) || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_fa_token' => __('Your session has expired. Please log in again.'),
            ]);
        }

        if (! $user->hasAppAccess()) {
            return $this->refuseWithoutAccess($user, $request, 'two_factor_verify', $twoFaToken);
        }

        $tfa = new TwoFactorAuthService;
        $warning = null;

        if ($request->filled('recovery_code')) {
            $remaining = $this->consumeRecoveryCode($user, $tfa, $request->string('recovery_code')->value());

            if ($remaining === null) {
                $this->recordFailedVerification($user, $request, 'invalid_recovery_code');

                throw ValidationException::withMessages([
                    'recovery_code' => __('Invalid recovery code.'),
                ]);
            }

            if ($remaining <= 2) {
                $warning = 'You have only '.$remaining.' recovery code(s) left. Generate new ones from your profile.';
            }
        } elseif (! $tfa->verifyCodeOnce($user->id, $user->two_factor_secret, $request->string('code')->value())) {
            $this->recordFailedVerification($user, $request, 'invalid_totp_code');

            throw ValidationException::withMessages([
                'code' => __('Invalid verification code.'),
            ]);
        }

        Cache::forget($twoFaToken);
        Cache::forget(self::failuresKey($user->id));

        $response = LoginSession::complete($user, $request, $request->filled('recovery_code') ? 'recovery_code' : 'totp');
        if ($warning) {
            $response['warning'] = $warning;
        }

        $devices = new TrustedDeviceService;
        if ($request->boolean('remember_device') && $devices->days($user) > 0) {
            Log::channel('security')->info('Device trusted for two-factor', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'days' => $devices->days($user),
            ]);

            return response()->json($response)->withCookie($devices->trust($user, $request));
        }

        return response()->json($response);
    }

    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'current_password' => __('Two-factor authentication is not enabled.'),
            ]);
        }

        $tfa = new TwoFactorAuthService;
        $codes = $tfa->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $tfa->hashRecoveryCodes($codes);
        $user->save();

        Log::channel('security')->info('Two-factor recovery codes regenerated', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /**
     * Cache payload for a new setup/verify token. The epoch ties it to the
     * user's current token generation, so bumping the epoch invalidates
     * every outstanding token without having to enumerate them.
     *
     * @return array{user_id: int, epoch: int}
     */
    public static function pendingPayload(User $user): array
    {
        return ['user_id' => $user->id, 'epoch' => (int) Cache::get(self::epochKey($user->id), 0)];
    }

    public static function pendingUserIdForToken(?string $token): ?int
    {
        if (! $token || ! (str_starts_with($token, self::SETUP_PREFIX) || str_starts_with($token, self::VERIFY_PREFIX))) {
            return null;
        }

        $pending = Cache::get($token);
        $userId = is_array($pending) ? ($pending['user_id'] ?? null) : null;

        if (! is_int($userId) || ($pending['epoch'] ?? null) !== (int) Cache::get(self::epochKey($userId), 0)) {
            return null;
        }

        return $userId;
    }

    /**
     * Re-reads the codes under a row lock so one recovery code can't be
     * spent by two concurrent requests. Returns the remaining count, or
     * null when the code doesn't match.
     */
    private function consumeRecoveryCode(User $user, TwoFactorAuthService $tfa, string $code): ?int
    {
        return DB::transaction(function () use ($user, $tfa, $code): ?int {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hashedCodes = $locked->two_factor_recovery_codes ?? [];
            $index = $tfa->verifyRecoveryCode($code, $hashedCodes);

            if ($index === null) {
                return null;
            }

            unset($hashedCodes[$index]);
            $locked->two_factor_recovery_codes = array_values($hashedCodes);
            $locked->save();

            return count($hashedCodes);
        });
    }

    /**
     * After MAX_CONSECUTIVE_FAILURES the user's pending tokens are
     * invalidated (epoch bump), so a stolen password alone can't keep
     * guessing on one token; they must log in again.
     */
    private function recordFailedVerification(User $user, Request $request, string $reason): void
    {
        Log::channel('security')->warning('2FA verification failed', [
            'user_id' => $user->id, 'ip' => $request->ip(), 'reason' => $reason,
        ]);

        $failuresKey = self::failuresKey($user->id);
        $failures = (int) Cache::get($failuresKey, 0) + 1;

        if ($failures < self::MAX_CONSECUTIVE_FAILURES) {
            Cache::put($failuresKey, $failures, now()->addHour());

            return;
        }

        Cache::forget($failuresKey);
        Cache::forever(self::epochKey($user->id), (int) Cache::get(self::epochKey($user->id), 0) + 1);

        Log::channel('security')->warning('Pending 2FA sign-ins invalidated after repeated failures', [
            'user_id' => $user->id, 'ip' => $request->ip(), 'failures' => $failures,
        ]);

        throw ValidationException::withMessages([
            'two_fa_token' => __('Too many failed attempts. Please log in again.'),
        ]);
    }

    private function refuseWithoutAccess(User $user, Request $request, string $stage, string ...$cacheKeys): JsonResponse
    {
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        return LoginSession::refuseWithoutAccess($user, $request, $stage);
    }

    private static function failuresKey(int $userId): string
    {
        return "2fa_failures:{$userId}";
    }

    private static function epochKey(int $userId): string
    {
        return "2fa_token_epoch:{$userId}";
    }

    private function resolvePendingUserId(string $token, string $prefix): ?int
    {
        return str_starts_with($token, $prefix) ? self::pendingUserIdForToken($token) : null;
    }

    private function pendingUser(int $userId): ?User
    {
        return User::query()->whereKey($userId)->where('is_active', true)->first();
    }
}
