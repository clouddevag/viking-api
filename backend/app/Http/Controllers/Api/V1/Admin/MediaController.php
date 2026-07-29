<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MediaController extends Controller
{
    use HandlesApiQueries;

    public function __construct(
        private readonly MediaService $media,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::MEDIA_MANAGE);

        $query = Media::query()
            ->when($request->query('collection'), fn ($q, $collection) => $q->collection($collection))
            ->when($request->query('search'), fn ($q, $term) => $q->where('original_name', 'like', '%'.$term.'%'));

        $this->applySorting($query, $request, ['created_at', 'size', 'original_name'], '-created_at');

        return MediaResource::collection(
            $query->paginate($this->perPage($request, 40, 100))->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::MEDIA_MANAGE);

        $maxKb = (int) config('viking.media.max_upload_kb', 8192);
        $mimes = implode(',', config('viking.media.accepted_mimes', ['image/jpeg']));

        $validated = $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['required', 'file', "max:{$maxKb}", "mimetypes:{$mimes}"],
            'collection' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
            'alt_en' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
        ]);

        $uploaded = [];

        foreach ($validated['files'] as $file) {
            $uploaded[] = $this->media->store(
                file: $file,
                collection: $validated['collection'] ?? 'default',
                uploader: $request->user(),
                altEn: $validated['alt_en'] ?? null,
                altAr: $validated['alt_ar'] ?? null,
            );
        }

        return response()->json([
            'data' => MediaResource::collection(collect($uploaded))->resolve(),
            'message' => count($uploaded).' file(s) uploaded.',
        ], 201);
    }

    public function update(Request $request, Media $medium): JsonResponse
    {
        $this->authorizePermission(Permissions::MEDIA_MANAGE);

        $validated = $request->validate([
            'alt_en' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'collection' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/'],
        ]);

        $medium->fill($validated)->save();

        return response()->json([
            'data' => (new MediaResource($medium))->resolve(),
            'message' => 'Media updated.',
        ]);
    }

    public function destroy(Media $medium): JsonResponse
    {
        $this->authorizePermission(Permissions::MEDIA_MANAGE);

        // Referencing rows use nullOnDelete, so products simply lose their
        // image rather than disappearing.
        $medium->delete();

        return response()->json(['message' => 'Media deleted.']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::MEDIA_MANAGE);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:media,id'],
        ]);

        $deleted = $this->media->deleteMany($validated['ids']);

        return response()->json(['message' => "{$deleted} file(s) deleted."]);
    }
}
