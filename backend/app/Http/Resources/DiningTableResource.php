<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DiningTable
 */
class DiningTableResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->displayName(),
            'zone' => $this->zone,
            'capacity' => $this->capacity,
            'status' => $this->status->value,
            'branch_id' => $this->branch_id,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'active_session' => $this->whenLoaded('activeSession', fn () => $this->activeSession ? [
                'id' => $this->activeSession->id,
                'party_size' => $this->activeSession->party_size,
                'guest_name' => $this->activeSession->guest_name,
                'opened_at' => $this->activeSession->opened_at?->toIso8601String(),
                'running_total' => $this->activeSession->runningTotal(),
            ] : null),
            'active_orders_count' => $this->whenCounted('orders'),

            // The QR token is a printable secret: only ever exposed to staff
            // who are allowed to manage tables.
            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'is_active' => $this->is_active,
                'sort_order' => $this->sort_order,
                'qr_token' => $this->qr_token,
                'qr_rotated_at' => $this->qr_rotated_at?->toIso8601String(),
                'qr_url' => rtrim((string) config('viking.frontend_url'), '/').'/t/'.$this->qr_token,
                'translations' => ['name' => $this->translations('name')],
            ]),
        ];
    }
}
