<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ExternalUserAuthService $externalAuth): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        $user = null;
        $primeRefused = false;
        try {
            $user = $externalAuth->authenticate($credentials['email'], $credentials['password']);
        } catch (InvalidPrimeCredentials) {
            $primeRefused = true;
        } catch (\Exception $e) {
            Log::warning('External authentication unavailable, falling back to local', [
                'error' => $e->getMessage(),
            ]);
        }

        if (! $user) {
            if ($primeRefused || ! Auth::attempt($credentials, false)) {
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

        return (new UserResource($user->load('roles.permissions', 'permissions', 'office')))
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

        unset($data['avatar'], $data['remove_avatar'], $data['current_password']);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        if ($replacedAvatar) {
            Storage::disk('public')->delete($replacedAvatar);
        }

        return (new UserResource($user->fresh()->load('roles.permissions', 'permissions', 'office')))
            ->additional(['message' => 'Profile updated successfully.'])
            ->response();
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        Log::channel('security')->info('Password reset requested', [
            'email' => $request->input('email'),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'If that account exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill(['password' => $password])->save();

                DB::table('sessions')->where('user_id', $user->id)->delete();

                Log::channel('security')->info('Password reset completed', [
                    'user_id' => $user->id,
                    'ip' => $request->ip(),
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('This password reset link is invalid or has expired.'),
            ]);
        }

        return response()->json(['message' => 'Success']);
    }
}
