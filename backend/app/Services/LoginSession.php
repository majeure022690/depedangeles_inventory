<?php

namespace App\Services;

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Resources\Management\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LoginSession
{
    public const NO_ACCESS = 'no_access';

    /**
     * @return array<string, mixed>
     */
    public static function complete(User $user, Request $request, string $method): array
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(EnforceIdleTimeout::SESSION_KEY, now()->getTimestamp());

        Log::channel('security')->info('Successful login', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'method' => $method,
        ]);

        return (new UserResource($user->load('roles.permissions', 'permissions', 'office')))
            ->additional(['message' => 'Success'])
            ->response()
            ->getData(true);
    }

    public static function refuseWithoutAccess(User $user, Request $request, string $stage): JsonResponse
    {
        self::end($request);

        Log::channel('security')->warning('Sign-in refused: account has no permissions', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
            'stage' => $stage,
        ]);

        if (Cache::add("no_access_audited:{$user->id}:{$stage}", true, now()->addHour())) {
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('auth.no_access')
                ->withProperties(['stage' => $stage, 'ip' => $request->ip()])
                ->log('auth.no_access');
        }

        return response()->json([
            'code' => self::NO_ACCESS,
            'message' => "Your account doesn't have access to DICTIS yet. Please contact the Division ICT Unit.",
        ], 403);
    }

    /**
     * Server-initiated sign-out (deactivated account, no permissions, idle timeout).
     */
    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
