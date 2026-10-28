<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\Management\UserResource;
use App\Models\User;
use App\Services\ExternalUserAuthService;
use App\Services\FileUploadService;
use App\Services\InvalidPrimeCredentials;
use App\Services\LoginSession;
use App\Services\PrimeUserSync;
use App\Services\SecuritySettings;
use App\Services\TrustedDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    /**
     * Every failed login takes at least this long, so unknown, inactive,
     * not-allowlisted and wrong-password attempts can't be told apart by
     * timing. Covers a PRIME lookup plus a bcrypt check.
     */
    private const LOGIN_TIMEBOX_MICROSECONDS = 400_000;

    public function login(LoginRequest $request, ExternalUserAuthService $externalAuth, Timebox $timebox): JsonResponse
    {
        $credentials = [
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
        ];

        /** @var User $user */
        $user = $timebox->call(function (Timebox $timebox) use ($request, $externalAuth, $credentials): User {
            $user = $this->verifyCredentials($request, $externalAuth, $credentials);
            $timebox->returnEarly();

            return $user;
        }, self::LOGIN_TIMEBOX_MICROSECONDS);

        if (! $user->hasAppAccess()) {
            return LoginSession::refuseWithoutAccess($user, $request, 'login');
        }

        Auth::logout();

        if ($user->hasTwoFactorEnabled()) {
            $devices = new TrustedDeviceService;

            if ($device = $devices->findValid($user, $request)) {
                $devices->touch($device, $request);

                return response()->json(LoginSession::complete($user, $request, 'trusted_device'));
            }

            $twoFaToken = TwoFactorController::VERIFY_PREFIX.Str::random(40);
            Cache::put($twoFaToken, TwoFactorController::pendingPayload($user), now()->addMinutes(5));

            return response()->json([
                'requires_2fa' => true,
                'two_fa_token' => $twoFaToken,
                'trust_device_days' => $devices->days($user),
            ]);
        }

        $setupToken = TwoFactorController::SETUP_PREFIX.Str::random(40);
        Cache::put($setupToken, TwoFactorController::pendingPayload($user), now()->addMinutes(30));

        return response()->json(['requires_2fa_setup' => true, 'setup_token' => $setupToken]);
    }

    /**
     * PRIME is the password authority. Only allowlisted developer accounts
     * may use a local password, and only when PRIME is unreachable or
     * doesn't know them. Returns the active, authenticated user; throws the
     * generic "Invalid credentials." otherwise.
     *
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    private function verifyCredentials(Request $request, ExternalUserAuthService $externalAuth, array $credentials): User
    {
        $user = null;
        $localReason = null;
        try {
            $user = $externalAuth->authenticate($credentials['email'], $credentials['password']);
            $localReason = $user ? null : 'not_in_prime';
        } catch (InvalidPrimeCredentials) {
            // PRIME refused: never fall back.
        } catch (Throwable $e) {
            if (ExternalUserAuthService::isUnreachable($e)) {
                $localReason = ExternalUserAuthService::outageReason();
                Log::warning('External authentication unavailable', [
                    'error' => $e->getMessage(),
                    'reason' => $localReason,
                ]);
            } else {
                // Anything else (a PRIME query/data error, an unreadable PRIME hash) is a refusal, not an outage.
                $event = ExternalUserAuthService::isQueryError($e) ? 'PRIME query failed; refusing' : 'PRIME authentication failed unexpectedly; refusing';
                Log::channel('security')->error($event, [
                    'email' => $credentials['email'],
                    'ip' => $request->ip(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $user) {
            if ($localReason === null
                || ! ExternalUserAuthService::allowsLocalPassword($credentials['email'])
                || ! Auth::attempt($credentials, false)) {
                Log::channel('security')->warning('Failed login attempt', [
                    'email' => $credentials['email'],
                    'ip' => $request->ip(),
                ]);

                throw ValidationException::withMessages([
                    'email' => __('Invalid credentials.'),
                ]);
            }

            /** @var User $user */
            $user = Auth::user();

            // The 2FA steps below apply unchanged.
            Log::channel('security')->warning('Local break-glass login', [
                'email' => $user->email,
                'ip' => $request->ip(),
                'reason' => $localReason,
            ]);
        } else {
            Auth::login($user, false);
        }

        if (! $user->is_active) {
            Auth::logout();

            Log::channel('security')->warning('Login attempt on inactive account', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => __('Invalid credentials.'),
            ]);
        }

        return $user;
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = Auth::id();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::channel('security')->info('Logout', [
            'user_id' => $userId,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Success']);
    }

    public function user(Request $request, PrimeUserSync $primeSync): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $primeSync->syncUser($user);

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return (new UserResource($user->load('roles.permissions', 'permissions', 'office', 'personnel:id,employee_id,full_name')))
            ->additional(['message' => 'Success'])
            ->response();
    }

    public function sessionSettings(): JsonResponse
    {
        return response()->json([
            'data' => ['idle_timeout_seconds' => SecuritySettings::get('idle_timeout_seconds')],
            'message' => 'Success',
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request, FileUploadService $fileUploads): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $replacedAvatar = null;

        if ($request->hasFile('avatar')) {
            // New file first, so a rejected upload keeps the old avatar.
            $data['avatar_path'] = $fileUploads->storeImage($request->file('avatar'), 'public', 'avatars', 'avatar')['path'];
            $replacedAvatar = $user->avatar_path;
        } elseif ($request->boolean('remove_avatar') && $user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $data['avatar_path'] = null;
        }

        // 'password' can only be empty here (prohibited); never write it.
        unset($data['avatar'], $data['remove_avatar'], $data['password']);

        $user->update($data);

        if ($replacedAvatar) {
            Storage::disk('public')->delete($replacedAvatar);
        }

        return (new UserResource($user->fresh()->load('roles.permissions', 'permissions', 'office', 'personnel:id,employee_id,full_name')))
            ->additional(['message' => 'Profile updated successfully.'])
            ->response();
    }
}
