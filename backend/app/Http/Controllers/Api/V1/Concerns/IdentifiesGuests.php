<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Anonymous customers must still be able to place an order, watch it and see
 * their history. They are identified by a random token their device generates
 * once and sends on every request as `X-Guest-Token`.
 *
 * This is a claim of identity, not proof of it — anyone holding the token is
 * treated as that guest — so it only ever grants access to that guest's own
 * orders, never to anything staff-facing.
 */
trait IdentifiesGuests
{
    protected function guestToken(Request $request): ?string
    {
        $token = $request->header('X-Guest-Token')
            ?? $request->query('guest_token')
            ?? $request->input('guest_token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        // Reject anything that isn't the shape we issue, so the column can
        // never be probed with arbitrary input.
        return preg_match('/^[a-z0-9]{16,64}$/', $token) === 1 ? $token : null;
    }

    protected function issueGuestToken(): string
    {
        return Str::lower(Str::random(32));
    }

    /**
     * The guest token for this request, minting one if the client has not
     * presented a valid one yet.
     */
    protected function resolveGuestToken(Request $request): string
    {
        return $this->guestToken($request) ?? $this->issueGuestToken();
    }
}
