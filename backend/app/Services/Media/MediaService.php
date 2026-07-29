<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Stores uploads and derives the responsive sizes the frontend requests.
 *
 * Originals are kept untouched; conversions are re-encoded to WebP at the
 * widths in `config('viking.media.conversions')` and never upscaled, so a small
 * source image simply has fewer variants rather than a blurry large one.
 */
class MediaService
{
    private ImageManager $images;

    public function __construct()
    {
        $this->images = new ImageManager(new Driver);
    }

    public function store(
        UploadedFile $file,
        string $collection = 'default',
        ?User $uploader = null,
        ?string $altEn = null,
        ?string $altAr = null,
    ): Media {
        $disk = config('filesystems.default', 'public');
        $directory = trim($collection, '/').'/'.now()->format('Y/m');
        $basename = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $path = $file->storeAs($directory, "{$basename}.{$extension}", ['disk' => $disk]);

        [$width, $height] = $this->dimensions($file);

        $media = Media::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'width' => $width,
            'height' => $height,
            'collection' => $collection,
            'alt_en' => $altEn,
            'alt_ar' => $altAr,
            'uploaded_by' => $uploader?->id,
        ]);

        $conversionsPath = $this->generateConversions($media, $file);

        if ($conversionsPath) {
            $media->forceFill(['conversions_path' => $conversionsPath])->save();
        }

        return $media->refresh();
    }

    /**
     * Writes one WebP per configured width. A failure here must not fail the
     * upload — the original is already stored and `conversionUrl()` falls back
     * to it, so we log and carry on.
     */
    private function generateConversions(Media $media, UploadedFile $file): ?string
    {
        if (! str_starts_with($media->mime_type, 'image/')) {
            return null;
        }

        $directory = dirname($media->path).'/conversions/'.pathinfo($media->path, PATHINFO_FILENAME);
        $disk = Storage::disk($media->disk);

        try {
            $source = $this->images->read($file->getRealPath());

            foreach ((array) config('viking.media.conversions', []) as $name => $targetWidth) {
                $image = clone $source;

                if ($image->width() > $targetWidth) {
                    $image->scaleDown(width: $targetWidth);
                }

                $disk->put(
                    "{$directory}/{$name}.webp",
                    (string) $image->toWebp(quality: 82),
                    ['visibility' => 'public']
                );
            }

            return $directory;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{0: int|null, 1: int|null} */
    private function dimensions(UploadedFile $file): array
    {
        try {
            $info = getimagesize($file->getRealPath());

            return $info ? [(int) $info[0], (int) $info[1]] : [null, null];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * Deletes media rows and their files. The model's `deleting` hook removes
     * the stored objects, so this only needs to iterate.
     *
     * @param  array<int, int>  $ids
     */
    public function deleteMany(array $ids): int
    {
        $deleted = 0;

        foreach (Media::whereIn('id', $ids)->cursor() as $media) {
            $media->delete();
            $deleted++;
        }

        return $deleted;
    }
}
