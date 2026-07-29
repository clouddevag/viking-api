<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'name' => $this->product_name,
            'sku' => $this->product_sku,
            'image_url' => $this->image_url,
            'quantity' => $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'options_total' => (float) $this->options_total,
            'line_subtotal' => (float) $this->line_subtotal,
            'discount_total' => (float) $this->discount_total,
            'line_total' => (float) $this->line_total,
            'special_instructions' => $this->special_instructions,
            'status' => $this->status->value,
            'prep_time_minutes' => $this->prep_time_minutes,
            'options' => $this->whenLoaded(
                'options',
                fn () => $this->options->map(fn ($option) => [
                    'id' => $option->id,
                    'group_name' => $option->group_name,
                    'group_kind' => $option->group_kind->value,
                    'name' => $option->option_name,
                    'price_delta' => (float) $option->price_delta,
                    'quantity' => $option->quantity,
                ])->all()
            ),
        ];
    }
}
