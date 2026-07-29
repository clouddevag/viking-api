<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The public, read-only menu. Everything here is cacheable and needs no
 * authentication, so a guest scanning a QR code gets the fastest possible
 * first paint.
 */
class MenuController extends Controller
{
    use HandlesApiQueries;

    /** Category tree with product counts, for the menu navigation. */
    public function categories(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->active()
            ->roots()
            ->ordered()
            ->with(['image', 'children' => fn ($query) => $query->active()->ordered()])
            ->withCount(['products' => fn (Builder $query) => $query->available()])
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * Paginated product list.
     *
     * Supports `?category=slug`, `?search=`, `?tag=`, `?featured=1`,
     * `?sort=` (price, rating, popularity, newest) and `?per_page=`.
     */
    public function products(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->available()
            ->with(['image', 'category'])
            ->when($request->query('category'), function (Builder $query, $slug) {
                $query->whereHas('category', fn (Builder $c) => $c->where('slug', $slug));
            })
            ->when($request->query('search'), fn (Builder $query, $term) => $query->search($term))
            ->when($request->boolean('featured'), fn (Builder $query) => $query->where('is_featured', true))
            ->when($request->boolean('new'), fn (Builder $query) => $query->where('is_new', true))
            ->when($request->query('tag'), function (Builder $query, $tag) {
                // `tags` is a JSON array; whereJsonContains works on both
                // MySQL and SQLite.
                $query->whereJsonContains('tags', $tag);
            })
            ->when($request->query('max_price'), fn (Builder $q, $max) => $q->where('base_price', '<=', $max))
            ->when($request->query('min_price'), fn (Builder $q, $min) => $q->where('base_price', '>=', $min));

        $this->applySort($query, (string) $request->query('sort', 'menu'));

        $products = $query->paginate($this->perPage($request, 24, 60))->withQueryString();

        $this->markFavorites($products->getCollection(), $request);

        return ProductResource::collection($products);
    }

    /** Full product detail including every option group. */
    public function product(Request $request, string $slug): ProductResource
    {
        $product = Product::query()
            ->available()
            ->with([
                'image',
                'category',
                'images.media',
                'ownOptionGroups' => fn ($query) => $query->active()->with(['options' => fn ($o) => $o->orderBy('sort_order')]),
                'sharedOptionGroups' => fn ($query) => $query->active()->with(['options' => fn ($o) => $o->orderBy('sort_order')]),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        $this->markFavorites(collect([$product]), $request);

        return new ProductResource($product);
    }

    /**
     * Lightweight search-as-you-type endpoint. Returns fewer fields and a
     * smaller page than the full listing.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $products = Product::query()
            ->available()
            ->with('image')
            ->search($term)
            ->orderByDesc('order_count')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'base_price' => (float) $product->base_price,
                'image' => $product->image?->conversionUrl('thumb'),
            ])->all(),
        ]);
    }

    /** Curated home-screen rails, in one call. */
    public function highlights(Request $request): JsonResponse
    {
        $featured = Product::query()->available()->with(['image', 'category'])
            ->where('is_featured', true)->ordered()->limit(8)->get();

        $popular = Product::query()->available()->with(['image', 'category'])
            ->orderByDesc('order_count')->limit(8)->get();

        $fresh = Product::query()->available()->with(['image', 'category'])
            ->where('is_new', true)->latest('id')->limit(8)->get();

        foreach ([$featured, $popular, $fresh] as $collection) {
            $this->markFavorites($collection, $request);
        }

        return response()->json([
            'data' => [
                'featured' => ProductResource::collection($featured)->resolve(),
                'popular' => ProductResource::collection($popular)->resolve(),
                'new' => ProductResource::collection($fresh)->resolve(),
            ],
        ]);
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('base_price'),
            'price_desc' => $query->orderByDesc('base_price'),
            'rating' => $query->orderByDesc('rating_average')->orderByDesc('rating_count'),
            'popular' => $query->orderByDesc('order_count'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderBy('sort_order')->orderBy('id'),
        };
    }

    /**
     * Flags which products the signed-in customer has favourited, in one extra
     * query rather than one per product.
     *
     * @param  Collection<int, Product>  $products
     */
    private function markFavorites($products, Request $request): void
    {
        $user = $request->user();

        if (! $user || $products->isEmpty()) {
            return;
        }

        $favorited = Favorite::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('product_id')
            ->flip();

        $products->each(function (Product $product) use ($favorited) {
            $product->is_favorite = $favorited->has($product->id);
        });
    }
}
