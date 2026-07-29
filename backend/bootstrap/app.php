<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\OptionalSanctumAuth;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * Channel authorization must accept Sanctum bearer tokens.
     *
     * Registering channels through withRouting() puts /broadcasting/auth behind
     * the `web` middleware alone, which authenticates from a session cookie.
     * This frontend is a separate origin holding a bearer token and sends no
     * session cookie, so every private-channel subscribe was answered 403 and
     * the kitchen, cashier and admin screens fell back to polling — working,
     * but never actually live.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['api', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetLocale::class,
            SecurityHeaders::class,
        ]);

        // This deployment has no server-rendered login page, so an
        // unauthenticated request must surface as a 401 rather than Laravel
        // trying to redirect to a `login` route that does not exist.
        $middleware->redirectGuestsTo(fn () => null);

        /*
         * The API is only ever reached through a proxy — nginx under Compose,
         * the platform edge on Railway — so X-Forwarded-* must be honoured.
         *
         * Without this every request appears to come from the proxy's address.
         * The rate limiters key on $request->ip(), so the whole restaurant
         * would share one 10/minute login bucket and a single member of staff
         * mistyping their password would lock out the tills. $request->secure()
         * would also stay false behind TLS termination, silently suppressing
         * the HSTS header.
         *
         * Trusting every proxy is the right setting here rather than a lax one:
         * the container is not routable except through that proxy (Compose
         * publishes nginx only; Railway publishes its edge only), and the
         * platform's address is not fixed enough to pin.
         */
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'guest.or.auth' => OptionalSanctumAuth::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Consistent error envelopes: every failure carries a `message` and a
        // machine-readable `error` code, matching the domain exceptions.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Please sign in to continue.',
                    'error' => 'unauthenticated',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'You are not allowed to do that.',
                    'error' => 'forbidden',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The requested resource was not found.',
                    'error' => 'not_found',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The requested resource was not found.',
                    'error' => 'not_found',
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Too many requests. Please slow down and try again shortly.',
                    'error' => 'rate_limited',
                ], 429, ['Retry-After' => $e->getHeaders()['Retry-After'] ?? 60]);
            }
        });
    })
    ->booted(function (): void {
        /*
        | Rate limiters. Authenticated callers are keyed by user id so a busy
        | restaurant behind a single NAT address does not throttle itself,
        | while anonymous traffic falls back to the guest token then the IP.
        */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('viking.rate_limits.api', 120)
        )->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(
            (int) config('viking.rate_limits.auth', 10)
        )->by($request->ip()));

        RateLimiter::for('order', fn (Request $request) => Limit::perMinute(
            (int) config('viking.rate_limits.order', 30)
        )->by($request->user()?->id ?: $request->header('X-Guest-Token') ?: $request->ip()));
    })
    ->create();
