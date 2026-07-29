<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'thumb' => $this->conversionUrl('thumb'),
            'small' => $this->conversionUrl('small'),
            'medium' => $this->conversionUrl('medium'),
            'large' => $this->conversionUrl('large'),
            'alt' => $request->getPreferredLanguage(['en', 'ar']) === 'ar'
                ? ($this->alt_ar ?? $this->alt_en)
                : ($this->alt_en ?? $this->alt_ar),
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'collection' => $this->collection,
            'original_name' => $this->original_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
