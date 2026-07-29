<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Offer
 */
class OfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'badge' => $this->badge,
            'type' => $this->type->value,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'combo_price' => $this->combo_price !== null ? (float) $this->combo_price : null,
            'cta_url' => $this->cta_url,
            'image' => new MediaResource($this->whenLoaded('image')),
            'products' => ProductResource::collection($this->whenLoaded('products')),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_live' => $this->isLive(),

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'is_active' => $this->is_active,
                'sort_order' => $this->sort_order,
                'image_id' => $this->image_id,
                'translations' => [
                    'title' => $this->translations('title'),
                    'description' => $this->translations('description'),
                    'badge' => $this->translations('badge'),
                ],
            ]),
        ];
    }
}
