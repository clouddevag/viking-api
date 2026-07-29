<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::PRODUCTS_VIEW);

        $query = Category::query()
            ->with(['image', 'parent'])
            ->withCount('products');

        $this->applyFilters($query, $request, ['parent_id', 'is_active', 'is_featured']);
        $this->applySorting($query, $request, ['name_en', 'sort_order', 'created_at'], 'sort_order');

        return CategoryResource::collection(
            $query->paginate($this->perPage($request, 50, 100))->withQueryString()
        );
    }

    public function show(Category $category): CategoryResource
    {
        $this->authorizePermission(Permissions::PRODUCTS_VIEW);

        return new CategoryResource($category->load(['image', 'children']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::CATEGORIES_MANAGE);

        $validated = $this->validateCategory($request);
        $validated['slug'] ??= $this->uniqueSlug($validated['name_en']);

        $category = Category::create($validated);

        return response()->json([
            'data' => (new CategoryResource($category))->resolve(),
            'message' => 'Category created.',
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizePermission(Permissions::CATEGORIES_MANAGE);

        $validated = $this->validateCategory($request, $category);

        // A category cannot be its own ancestor.
        if (($validated['parent_id'] ?? null) !== null && $this->wouldCycle($category, (int) $validated['parent_id'])) {
            return response()->json([
                'message' => 'A category cannot be nested inside itself.',
                'error' => 'invalid_parent',
            ], 422);
        }

        $category->fill($validated)->save();

        return response()->json([
            'data' => (new CategoryResource($category->fresh('image')))->resolve(),
            'message' => 'Category updated.',
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorizePermission(Permissions::CATEGORIES_MANAGE);

        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Move or archive this category\'s products first.',
                'error' => 'category_not_empty',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category archived.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::CATEGORIES_MANAGE);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*.id' => ['required', 'integer', 'exists:categories,id'],
            'order.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['order'] as $row) {
                Category::whereKey($row['id'])->update(['sort_order' => $row['sort_order']]);
            }
        });

        return response()->json(['message' => 'Order saved.']);
    }

    /** @return array<string, mixed> */
    private function validateCategory(Request $request, ?Category $category = null): array
    {
        $required = $category ? 'sometimes' : 'required';

        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_ar' => [$required, 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'image_id' => ['nullable', 'integer', 'exists:media,id'],
            'icon' => ['nullable', 'string', 'max:64'],
            'accent_color' => ['nullable', 'string', 'max:9'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);
    }

    /** Walks up the proposed parent chain looking for this category. */
    private function wouldCycle(Category $category, int $parentId): bool
    {
        if ($parentId === $category->id) {
            return true;
        }

        $seen = [];
        $current = Category::find($parentId);

        while ($current && $current->parent_id) {
            if ($current->parent_id === $category->id || in_array($current->parent_id, $seen, true)) {
                return true;
            }

            $seen[] = $current->parent_id;
            $current = Category::find($current->parent_id);
        }

        return false;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
