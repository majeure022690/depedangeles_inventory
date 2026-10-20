<?php

namespace App\Http\Middleware;

use App\Services\LoginSession;
use App\Services\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the idle_timeout_seconds security setting:
 * the SPA's IdleLogout is the UX, this is the backstop for an abandoned
 * or copied session cookie. Any authenticated API request counts as
 * activity.
 */
class EnforceIdleTimeout
{
    public const SESSION_KEY = 'last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $lastActivity = $session->get(self::SESSION_KEY);
        $now = now()->getTimestamp();

        if (is_int($lastActivity) && $now - $lastActivity > SecuritySettings::get('idle_timeout_seconds')) {
            $userId = $request->user()->id;

            LoginSession::end($request);

            Log::channel('security')->info('Session ended after idle timeout', [
                'user_id' => $userId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Your session has expired due to inactivity.'], 401);
        }

        $session->put(self::SESSION_KEY, $now);

        return $next($request);
    }
}
