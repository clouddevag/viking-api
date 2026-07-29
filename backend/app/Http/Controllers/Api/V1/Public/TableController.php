<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\Concerns\IdentifiesGuests;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Services\Tables\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR table ordering.
 *
 * Scanning a code hits `scan`, which identifies the table and opens (or
 * rejoins) a dining session. The guest never types a table number — that path
 * exists only as a staff-assisted fallback if a code is damaged.
 */
class TableController extends Controller
{
    use IdentifiesGuests;

    public function __construct(
        private readonly TableSessionService $sessions,
    ) {}

    /**
     * Resolves a scanned QR token. Idempotent: re-scanning returns the session
     * already in progress rather than starting a second one.
     */
    public function scan(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $session = $this->sessions->openFromToken(
            token: $token,
            user: $request->user(),
            guestName: $validated['guest_name'] ?? null,
            guestPhone: $validated['guest_phone'] ?? null,
            partySize: $validated['party_size'] ?? null,
        );

        $session->load(['table.branch']);

        return response()->json([
            'data' => [
                'session_token' => $session->session_token,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'party_size' => $session->party_size,
                'running_total' => $session->runningTotal(),
                'table' => [
                    'id' => $session->table->id,
                    'number' => $session->table->number,
                    'name' => $session->table->displayName(),
                    'zone' => $session->table->zone,
                    'capacity' => $session->table->capacity,
                ],
                'branch' => (new BranchResource($session->table->branch))->resolve(),
                'guest_token' => $this->resolveGuestToken($request),
            ],
        ]);
    }

    /**
     * Reads a session back from the token the device stored, so a page refresh
     * or a returning tab keeps its table context.
     */
    public function session(Request $request, string $sessionToken): JsonResponse
    {
        $session = $this->sessions->findByToken($sessionToken);

        if (! $session) {
            return response()->json(['message' => 'This table session was not found.'], 404);
        }

        $session->loadMissing('table.branch');

        return response()->json([
            'data' => [
                'session_token' => $session->session_token,
                'status' => $session->status->value,
                'is_open' => $session->isOpen(),
                'opened_at' => $session->opened_at?->toIso8601String(),
                'party_size' => $session->party_size,
                'running_total' => $session->runningTotal(),
                'table' => [
                    'id' => $session->table->id,
                    'number' => $session->table->number,
                    'name' => $session->table->displayName(),
                    'zone' => $session->table->zone,
                ],
                'branch' => (new BranchResource($session->table->branch))->resolve(),
            ],
        ]);
    }
}
