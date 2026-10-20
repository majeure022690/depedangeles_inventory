<?php

namespace App\Http\Middleware;

use App\Services\LoginSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a deactivated account on its next request. User's
 * `saved` hook already deletes database-driver sessions on deactivation;
 * this covers every other session driver.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            LoginSession::end($request);

            Log::channel('security')->warning('Session ended for inactive account', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
