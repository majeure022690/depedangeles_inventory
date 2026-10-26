<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
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
        // The backend has no login page (the SPA owns /login), so
        // guests get a 401 instead of a redirect to a missing route.
        $middleware->redirectGuestsTo(null);
        // Only the permission alias: inventory never routes on a role name.
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
        ]);
        // A denied request must not spend the user's throttle budget (e.g. password-confirm),
        // and an inactive or idle-expired session is ended before any permission check.
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, EnforceIdleTimeout::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, PermissionMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // A write refused by the route-level `permission:` fast fail never
        // reaches the Policy that would otherwise audit the denial.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            $user = $request->user();

            // Throttled per user so a scripted loop can't flood the audit trail.
            if ($user !== null && ! $request->isMethodSafe()
                && RateLimiter::attempt('authorization-denied-audit:'.$user->getAuthIdentifier(), 10, fn () => true, 60)) {
                $route = $request->route();

                // The permission check runs ahead of SubstituteBindings, so bind here for the audit subject.
                if ($route instanceof Route) {
                    try {
                        app('router')->substituteBindings($route);
                        app('router')->substituteImplicitBindings($route);
                    } catch (ModelNotFoundException|BackedEnumCaseNotFoundException) {
                    }
                }

                $subject = collect($route?->parameters() ?? [])->first(fn ($value) => $value instanceof Model);

                activity()
                    ->causedBy($user)
                    ->when($subject instanceof Model, fn ($logger) => $logger->performedOn($subject))
                    ->event('authorization.denied')
                    ->withProperties([
                        'permission' => implode('|', $e->getRequiredPermissions()),
                        'route' => $request->method().' '.$request->path(),
                        'parameters' => $route?->originalParameters() ?? [],
                    ])
                    ->log('authorization.denied');
            }

            return null;
        });

        $exceptions->render(function (Throwable $e) {
            if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof TokenMismatchException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status === 404 && ! app()->environment('local', 'testing')) {
                return response()->json(['message' => 'Not Found.'], 404);
            }

            if (app()->environment('production')) {
                if (! in_array($status, [401, 403, 404, 405, 409, 422, 429], true)) {
                    return response()->json([
                        'message' => 'An unexpected error occurred.',
                    ], $status);
                }
            }

            return null;
        });
    })->create();
