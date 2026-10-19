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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public const SETUP_PREFIX = '2fa_setup:';

    public const VERIFY_PREFIX = '2fa_verify:';

    public function setup(TwoFactorSetupRequest $request): JsonResponse
    {
        $userId = $this->resolvePendingUserId($request->string('setup_token')->value(), self::SETUP_PREFIX);

        if (! $userId || ! ($user = $this->pendingUser($userId)) || $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'setup_token' => __('Your setup session has expired. Please log in again.'),
            ]);
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

        $tfa = new TwoFactorAuthService;
        $warning = null;

        if ($request->filled('recovery_code')) {
            $hashedCodes = $user->two_factor_recovery_codes ?? [];
            $index = $tfa->verifyRecoveryCode($request->string('recovery_code')->value(), $hashedCodes);

            if ($index === null) {
                Log::channel('security')->warning('2FA verification failed', [
                    'user_id' => $user->id, 'ip' => $request->ip(), 'reason' => 'invalid_recovery_code',
                ]);

                throw ValidationException::withMessages([
                    'recovery_code' => __('Invalid recovery code.'),
                ]);
            }

            unset($hashedCodes[$index]);
            $hashedCodes = array_values($hashedCodes);
            $user->two_factor_recovery_codes = $hashedCodes;
            $user->save();

            if (count($hashedCodes) <= 2) {
                $warning = 'You have only '.count($hashedCodes).' recovery code(s) left. Generate new ones from your profile.';
            }
        } elseif (! $tfa->verifyCodeOnce($user->id, $user->two_factor_secret, $request->string('code')->value())) {
            Log::channel('security')->warning('2FA verification failed', [
                'user_id' => $user->id, 'ip' => $request->ip(), 'reason' => 'invalid_totp_code',
            ]);

            throw ValidationException::withMessages([
                'code' => __('Invalid verification code.'),
            ]);
        }

        Cache::forget($twoFaToken);

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

    public static function pendingUserIdForToken(?string $token): ?int
    {
        if (! $token || ! (str_starts_with($token, self::SETUP_PREFIX) || str_starts_with($token, self::VERIFY_PREFIX))) {
            return null;
        }

        $pending = Cache::get($token);

        return is_array($pending) ? ($pending['user_id'] ?? null) : null;
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
