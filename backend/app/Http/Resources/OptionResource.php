<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Option
 */
class OptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_delta' => (float) $this->price_delta,
            'is_default' => $this->is_default,
            'is_available' => $this->is_available,
            'max_quantity' => $this->max_quantity,
            'sort_order' => $this->sort_order,
            'image' => new MediaResource($this->whenLoaded('image')),

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'translations' => ['name' => $this->translations('name')],
            ]),
        ];
    }
}
