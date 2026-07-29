<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $query = Product::query()
            ->with(['image', 'category'])
            ->when($request->query('search'), fn ($q, $term) => $q->search($term))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed());

        $this->applyFilters($query, $request, ['category_id', 'is_active', 'is_available', 'is_featured', 'is_new']);
        $this->applySorting(
            $query,
            $request,
            ['name_en', 'base_price', 'sort_order', 'order_count', 'rating_average', 'created_at'],
            'sort_order'
        );

        return ProductResource::collection(
            $query->paginate($this->perPage($request, 20, 100))->withQueryString()
        );
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        return new ProductResource($product->load([
            'image', 'category', 'images.media',
            'ownOptionGroups.options', 'sharedOptionGroups.options',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $validated = $this->validateProduct($request);

        $product = DB::transaction(function () use ($validated, $request) {
            $product = Product::create($this->attributes($validated));
            $this->syncGallery($product, $request);
            $this->syncSharedGroups($product, $request);

            return $product;
        });

        return response()->json([
            'data' => (new ProductResource($product->load(['image', 'category'])))->resolve(),
            'message' => 'Product created.',
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $this->validateProduct($request, $product);

        DB::transaction(function () use ($product, $validated, $request) {
            $product->fill($this->attributes($validated, $product))->save();
            $this->syncGallery($product, $request);
            $this->syncSharedGroups($product, $request);
        });

        return response()->json([
            'data' => (new ProductResource($product->fresh(['image', 'category', 'images.media'])))->resolve(),
            'message' => 'Product updated.',
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        // Soft delete: historic order items reference this row for reporting.
        $product->delete();

        return response()->json(['message' => 'Product archived.']);
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $this->authorize('update', $product);

        $product->restore();

        return response()->json(['message' => 'Product restored.']);
    }

    /** Quick availability toggle, used by the "86 an item" button. */
    public function toggleAvailability(Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $product->forceFill(['is_available' => ! $product->is_available])->save();

        return response()->json([
            'data' => ['is_available' => $product->is_available],
            'message' => $product->is_available ? 'Item is back on the menu.' : 'Item marked as sold out.',
        ]);
    }

    /** Persists a drag-and-drop reorder in one statement per row. */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:500'],
            'order.*.id' => ['required', 'integer', 'exists:products,id'],
            'order.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['order'] as $row) {
                Product::whereKey($row['id'])->update(['sort_order' => $row['sort_order']]);
            }
        });

        return response()->json(['message' => 'Order saved.']);
    }

    /** @return array<string, mixed> */
    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $isUpdate = $product !== null;
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => [$required, 'integer', 'exists:categories,id'],
            'sku' => [$required, 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product?->id)],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product?->id)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_ar' => [$required, 'string', 'max:255'],
            'short_description_en' => ['nullable', 'string', 'max:255'],
            'short_description_ar' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'image_id' => ['nullable', 'integer', 'exists:media,id'],
            'base_price' => [$required, 'numeric', 'min:0', 'max:99999999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'prep_time_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'spice_level' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'allergens' => ['nullable', 'array', 'max:20'],
            'allergens.*' => ['string', 'max:40'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_new' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'gallery_media_ids' => ['sometimes', 'array', 'max:12'],
            'gallery_media_ids.*' => ['integer', 'exists:media,id'],
            'shared_option_group_ids' => ['sometimes', 'array', 'max:20'],
            'shared_option_group_ids.*' => ['integer', 'exists:option_groups,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $validated, ?Product $product = null): array
    {
        unset($validated['gallery_media_ids'], $validated['shared_option_group_ids']);

        // Derive a slug from the English name unless one was supplied. Kept
        // stable on update so existing links do not break.
        if (empty($validated['slug']) && ! $product) {
            $validated['slug'] = $this->uniqueSlug($validated['name_en'] ?? Str::random(8));
        } elseif (array_key_exists('slug', $validated) && empty($validated['slug'])) {
            unset($validated['slug']);
        }

        return $validated;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function syncGallery(Product $product, Request $request): void
    {
        if (! $request->has('gallery_media_ids')) {
            return;
        }

        $ids = array_values(array_unique($request->input('gallery_media_ids', [])));

        $product->images()->delete();

        foreach ($ids as $index => $mediaId) {
            ProductImage::create([
                'product_id' => $product->id,
                'media_id' => $mediaId,
                'sort_order' => $index,
            ]);
        }
    }

    private function syncSharedGroups(Product $product, Request $request): void
    {
        if (! $request->has('shared_option_group_ids')) {
            return;
        }

        $product->sharedOptionGroups()->sync(
            collect($request->input('shared_option_group_ids', []))
                ->mapWithKeys(fn ($id, $index) => [$id => ['sort_order' => 50 + $index]])
                ->all()
        );
    }
}
