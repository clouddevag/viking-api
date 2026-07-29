<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'value' => (float) $this->value,
            'minimum_order_amount' => (float) $this->minimum_order_amount,
            'maximum_discount_amount' => $this->maximum_discount_amount !== null
                ? (float) $this->maximum_discount_amount
                : null,
            'applies_to' => $this->applies_to,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'is_active' => $this->is_active,
                'first_order_only' => $this->first_order_only,
                'usage_limit' => $this->usage_limit,
                'usage_limit_per_user' => $this->usage_limit_per_user,
                'used_count' => $this->used_count,
                'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),
                'product_ids' => $this->whenLoaded('products', fn () => $this->products->pluck('id')),
                'translations' => [
                    'name' => $this->translations('name'),
                    'description' => $this->translations('description'),
                ],
                'created_at' => $this->created_at?->toIso8601String(),
            ]),
        ];
    }
}
