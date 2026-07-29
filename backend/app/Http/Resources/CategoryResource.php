<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'accent_color' => $this->accent_color,
            'sort_order' => $this->sort_order,
            'is_featured' => $this->is_featured,
            'parent_id' => $this->parent_id,
            'image' => new MediaResource($this->whenLoaded('image')),
            'products_count' => $this->whenCounted('products'),
            'children' => CategoryResource::collection($this->whenLoaded('children')),

            // Both locales, for the admin editor only.
            $this->mergeWhen($request->routeIs('api.v1.admin.*'), fn () => [
                'translations' => [
                    'name' => $this->translations('name'),
                    'description' => $this->translations('description'),
                ],
                'is_active' => $this->is_active,
                'image_id' => $this->image_id,
            ]),
        ];
    }
}
