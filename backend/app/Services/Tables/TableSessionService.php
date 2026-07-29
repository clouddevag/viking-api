<?php

declare(strict_types=1);

namespace App\Services\Tables;

use App\Enums\TableSessionStatus;
use App\Enums\TableStatus;
use App\Exceptions\TableSessionException;
use App\Models\DiningTable;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a scanned QR token into an active dining session.
 *
 * Scanning must be idempotent: a guest who re-opens the link, or a second
 * person at the same table, joins the session that is already open rather than
 * starting a competing one — otherwise a table would end up with two bills.
 */
class TableSessionService
{
    /**
     * Resolves a QR token to its table, rejecting unknown or disabled ones.
     *
     * @throws TableSessionException
     */
    public function resolveTable(string $token): DiningTable
    {
        $table = DiningTable::query()
            ->with('branch')
            ->where('qr_token', $token)
            ->first();

        if (! $table) {
            throw TableSessionException::unknownToken();
        }

        if (! $table->is_active || ! $table->status->acceptsNewSession()) {
            throw TableSessionException::tableDisabled($table->number);
        }

        if (! $table->branch || ! $table->branch->is_active) {
            throw TableSessionException::branchClosed($table->branch?->name ?? 'This branch');
        }

        return $table;
    }

    /**
     * Opens a session for a scanned table, or returns the one already running.
     *
     * @throws TableSessionException
     */
    public function openFromToken(
        string $token,
        ?User $user = null,
        ?string $guestName = null,
        ?string $guestPhone = null,
        ?int $partySize = null,
    ): TableSession {
        $table = $this->resolveTable($token);

        return DB::transaction(function () use ($table, $user, $guestName, $guestPhone, $partySize) {
            // Lock the table row so two simultaneous scans cannot both decide
            // no session exists and each create one.
            DiningTable::query()->whereKey($table->getKey())->lockForUpdate()->first();

            $existing = $this->expireStaleSessions($table);

            if ($existing) {
                $existing->fill(array_filter([
                    'user_id' => $user?->id ?? $existing->user_id,
                    'guest_name' => $guestName ?? $existing->guest_name,
                    'guest_phone' => $guestPhone ?? $existing->guest_phone,
                    'party_size' => $partySize ?? $existing->party_size,
                ], static fn ($value) => $value !== null));

                $existing->last_activity_at = now();
                $existing->save();

                return $existing;
            }

            $session = TableSession::create([
                'dining_table_id' => $table->id,
                'branch_id' => $table->branch_id,
                'user_id' => $user?->id,
                'guest_name' => $guestName ?? $user?->name,
                'guest_phone' => $guestPhone ?? $user?->phone,
                'party_size' => $partySize ?? 1,
                'status' => TableSessionStatus::Open,
            ]);

            $table->forceFill(['status' => TableStatus::Occupied])->save();

            return $session;
        });
    }

    /**
     * Closes a session and frees the table.
     *
     * @throws TableSessionException
     */
    public function close(TableSession $session, ?User $actor = null): TableSession
    {
        if (! $session->isOpen()) {
            throw TableSessionException::sessionClosed();
        }

        return DB::transaction(function () use ($session, $actor) {
            $session->forceFill([
                'status' => TableSessionStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor?->id,
            ])->save();

            $stillBusy = TableSession::query()
                ->where('dining_table_id', $session->dining_table_id)
                ->whereKeyNot($session->getKey())
                ->open()
                ->exists();

            if (! $stillBusy) {
                $session->table?->forceFill(['status' => TableStatus::Available])->save();
            }

            return $session->refresh();
        });
    }

    /**
     * Marks sessions abandoned once they have been idle past the configured
     * TTL, and returns the still-live one if there is one.
     *
     * Without this a guest who walks out without paying would leave the table
     * marked occupied forever.
     */
    public function expireStaleSessions(DiningTable $table): ?TableSession
    {
        $ttl = (int) config('viking.table_session_ttl_minutes', 240);
        $cutoff = now()->subMinutes($ttl);

        TableSession::query()
            ->where('dining_table_id', $table->id)
            ->open()
            ->where('last_activity_at', '<', $cutoff)
            ->update([
                'status' => TableSessionStatus::Abandoned->value,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);

        return TableSession::query()
            ->where('dining_table_id', $table->id)
            ->open()
            ->latest('opened_at')
            ->first();
    }

    /**
     * Looks a session up by the token stored on the guest's device.
     */
    public function findByToken(string $sessionToken): ?TableSession
    {
        return TableSession::query()
            ->with(['table.branch'])
            ->where('session_token', $sessionToken)
            ->first();
    }
}
