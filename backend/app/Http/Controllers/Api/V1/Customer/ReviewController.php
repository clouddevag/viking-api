<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product reviews. A customer may only review something they actually ordered
 * and received, which is what keeps ratings meaningful.
 */
class ReviewController extends Controller
{
    use HandlesApiQueries;

    /** Approved reviews for a product, publicly readable. */
    public function index(Request $request, Product $product): JsonResponse
    {
        $reviews = Review::query()
            ->approved()
            ->where('product_id', $product->id)
            ->with('user:id,name')
            ->latest()
            ->paginate($this->perPage($request, 10, 50));

        return response()->json([
            'data' => $reviews->getCollection()->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'author' => $review->user?->name,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'total' => $reviews->total(),
                'average' => (float) $product->rating_average,
                'count' => $product->rating_count,
            ],
        ]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:32'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('order_number', $validated['order_number'])
            ->whereIn('status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'You can only review items from an order you have received.',
                'error' => 'not_eligible',
            ], 422);
        }

        $review = Review::updateOrCreate(
            ['product_id' => $product->id, 'user_id' => $user->id, 'order_id' => $order->id],
            [
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                // Held for moderation; the product aggregate only counts
                // approved reviews.
                'is_approved' => false,
            ]
        );

        return response()->json([
            'data' => ['id' => $review->id, 'rating' => $review->rating],
            'message' => 'Thank you — your review will appear once approved.',
        ], 201);
    }
}
