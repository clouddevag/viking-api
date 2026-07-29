<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OptionGroup
 */
class OptionGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'selection' => $this->selection->value,
            'is_required' => $this->is_required,
            // Effective bounds, so the client never has to re-derive the rules.
            'min_selections' => $this->effectiveMinSelections(),
            'max_selections' => $this->effectiveMaxSelections(),
            'sort_order' => $this->sort_order,
            'options' => OptionResource::collection($this->whenLoaded('options')),

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'product_id' => $this->product_id,
                'is_active' => $this->is_active,
                'translations' => [
                    'name' => $this->translations('name'),
                    'description' => $this->translations('description'),
                ],
            ]),
        ];
    }
}
