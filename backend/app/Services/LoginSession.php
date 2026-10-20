<?php

namespace App\Services;

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Resources\Management\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginSession
{
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

    /**
     * Server-initiated sign-out (deactivated account, idle timeout).
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
