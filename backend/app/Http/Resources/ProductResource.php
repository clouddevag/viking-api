<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'base_price' => (float) $this->base_price,
            'compare_at_price' => $this->compare_at_price !== null
                ? (float) $this->compare_at_price
                : null,
            'currency' => config('viking.currency'),
            'calories' => $this->calories,
            'prep_time_minutes' => $this->prep_time_minutes,
            'spice_level' => $this->spice_level,
            'allergens' => $this->allergens ?? [],
            'tags' => $this->tags ?? [],
            'is_available' => $this->is_available && $this->is_active,
            'is_featured' => $this->is_featured,
            'is_new' => $this->is_new,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => $this->rating_count,
            'image' => new MediaResource($this->whenLoaded('image')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'category_id' => $this->category_id,

            'gallery' => $this->whenLoaded(
                'images',
                fn () => MediaResource::collection(
                    $this->images->map(fn ($row) => $row->media)->filter()
                )
            ),

            // Own and shared groups arrive as two relations but the client only
            // ever wants one ordered list.
            'option_groups' => $this->when(
                $this->relationLoaded('ownOptionGroups') && $this->relationLoaded('sharedOptionGroups'),
                fn () => OptionGroupResource::collection(
                    $this->allOptionGroups()->filter(fn (OptionGroup $group) => $group->is_active)->values()
                )
            ),

            'is_favorite' => $this->when(isset($this->is_favorite), fn () => (bool) $this->is_favorite),

            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'is_active' => $this->is_active,
                'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
                'sort_order' => $this->sort_order,
                'order_count' => $this->order_count,
                'image_id' => $this->image_id,
                'translations' => [
                    'name' => $this->translations('name'),
                    'short_description' => $this->translations('short_description'),
                    'description' => $this->translations('description'),
                ],
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ]),
        ];
    }
}
