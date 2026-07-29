<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review moderation. Approving a review is what makes it count towards the
 * product's public rating.
 */
class ReviewController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::REVIEWS_MODERATE);

        $query = Review::query()->with(['product:id,name_en,name_ar,slug', 'user:id,name']);

        $this->applyFilters($query, $request, ['is_approved', 'rating', 'product_id']);
        $this->applySorting($query, $request, ['created_at', 'rating'], '-created_at');

        $reviews = $query->paginate($this->perPage($request, 25, 100));

        return response()->json([
            'data' => $reviews->getCollection()->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'is_approved' => $review->is_approved,
                'product' => [
                    'id' => $review->product?->id,
                    'name' => $review->product?->name,
                    'slug' => $review->product?->slug,
                ],
                'author' => $review->user?->name,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'total' => $reviews->total(),
                'pending' => Review::where('is_approved', false)->count(),
            ],
        ]);
    }

    public function approve(Request $request, Review $review): JsonResponse
    {
        $this->authorizePermission(Permissions::REVIEWS_MODERATE);

        // The model's saved hook recomputes the product's rating aggregate.
        $review->forceFill([
            'is_approved' => true,
            'approved_by' => $request->user()->id,
        ])->save();

        return response()->json(['message' => 'Review approved.']);
    }

    public function reject(Review $review): JsonResponse
    {
        $this->authorizePermission(Permissions::REVIEWS_MODERATE);

        $review->forceFill(['is_approved' => false])->save();

        return response()->json(['message' => 'Review hidden.']);
    }

    public function destroy(Review $review): JsonResponse
    {
        $this->authorizePermission(Permissions::REVIEWS_MODERATE);

        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }
}
