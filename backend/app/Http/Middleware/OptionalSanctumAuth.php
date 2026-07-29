<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the request when a valid token is present, and lets it through
 * as a guest when it is not.
 *
 * Ordering endpoints need exactly this: a signed-in customer's order should be
 * attached to their account, but a walk-in who scanned a QR code must not be
 * forced to create one first.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Point the default guard at Sanctum so `$request->user()` resolves
        // the bearer token — and simply returns null when there isn't one.
        Auth::shouldUse('sanctum');

        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json([
                'message' => 'This account has been deactivated.',
                'error' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
