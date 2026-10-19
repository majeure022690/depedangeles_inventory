<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        // The backend has no login page (the SPA owns /portal/login), so
        // guests get a 401 instead of a redirect to a missing route.
        $middleware->redirectGuestsTo(null);
        // Only the permission alias: inventory never routes on a role name.
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
        ]);

        // Interim, until Phase 3 replaces the Inertia pages: their web
        // routes still need these. Delete with HandleInertiaRequests.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // rwatch renders JSON for everything. The Inertia web routes that
        // Phase 3 removes still rely on redirect-back validation errors, so
        // until then: JSON for the API, and a 401 (never a redirect to the
        // removed Fortify login page) for any unauthenticated request.
        // Phase 3: replace with `fn () => true`.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || $request->expectsJson()
                || $e instanceof AuthenticationException,
        );

        $exceptions->render(function (Throwable $e) {
            if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof TokenMismatchException) {
                return null;
            }

            if (app()->environment('production')) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                if ($status === 404) {
                    return response()->json(['message' => 'Not Found.'], 404);
                }

                if (! in_array($status, [401, 403, 404, 405, 409, 422, 429], true)) {
                    return response()->json([
                        'message' => 'An unexpected error occurred.',
                    ], $status);
                }
            }

            return null;
        });
    })->create();
