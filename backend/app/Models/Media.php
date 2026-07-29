<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Public URL for the original upload. */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * URL for a generated size. Conversions are written next to the original
     * under `conversions_path`; if one was never generated we serve the
     * original rather than 404.
     */
    public function conversionUrl(string $conversion = 'medium'): string
    {
        if (! $this->conversions_path) {
            return $this->url();
        }

        return Storage::disk($this->disk)->url(
            rtrim($this->conversions_path, '/')."/{$conversion}.webp"
        );
    }

    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /** Removes the stored file(s) alongside the row. */
    protected static function booted(): void
    {
        static::deleting(function (self $media) {
            $disk = Storage::disk($media->disk);
            $disk->delete($media->path);

            if ($media->conversions_path) {
                $disk->deleteDirectory($media->conversions_path);
            }
        });
    }
}
