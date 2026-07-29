<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Branch
 */
class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'opens_at' => (string) $this->opens_at,
            'closes_at' => (string) $this->closes_at,
            'timezone' => $this->timezone,
            'is_open_now' => $this->isOpenNow(),
            'accepts_dine_in' => $this->accepts_dine_in,
            'accepts_takeaway' => $this->accepts_takeaway,
            'accepts_delivery' => $this->accepts_delivery,
            'delivery_fee' => (float) $this->delivery_fee,
            'minimum_order' => (float) $this->minimum_order,

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'is_active' => $this->is_active,
                'email' => $this->email,
                'sort_order' => $this->sort_order,
                'tables_count' => $this->whenCounted('tables'),
                'translations' => [
                    'name' => $this->translations('name'),
                    'address' => $this->translations('address'),
                ],
            ]),
        ];
    }
}
