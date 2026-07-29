<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a token belonging to a deactivated account.
 *
 * Deactivation revokes tokens at the point it happens, but this closes the
 * window where a request is already in flight and covers tokens issued by
 * another process.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'This account has been deactivated.',
                'error' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
