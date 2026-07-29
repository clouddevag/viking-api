<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->available()
            ->with(['image', 'category'])
            ->whereHas('favorites', fn ($query) => $query->where('user_id', $request->user()->id))
            ->paginate($this->perPage($request, 24, 60));

        $products->getCollection()->each(fn (Product $product) => $product->is_favorite = true);

        return ProductResource::collection($products);
    }

    /**
     * Idempotent toggle — the client does not have to track current state, it
     * just says "flip this" and reads the result back.
     */
    public function toggle(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();

        $existing = Favorite::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return response()->json(['data' => ['is_favorite' => false]]);
        }

        Favorite::create(['user_id' => $user->id, 'product_id' => $product->id]);

        return response()->json(['data' => ['is_favorite' => true]], 201);
    }

    /**
     * Bulk sync for the moment a guest signs in — the PWA has been keeping
     * favourites locally and hands the whole list over at once.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $user = $request->user();
        $existing = Favorite::where('user_id', $user->id)->pluck('product_id')->all();
        $incoming = array_values(array_unique($validated['product_ids']));
        $toCreate = array_diff($incoming, $existing);

        foreach ($toCreate as $productId) {
            Favorite::create(['user_id' => $user->id, 'product_id' => $productId]);
        }

        return response()->json([
            'data' => ['product_ids' => array_values(array_unique(array_merge($existing, $incoming)))],
            'message' => 'Favourites synced.',
        ]);
    }
}
