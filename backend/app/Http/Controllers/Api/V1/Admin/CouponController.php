<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DiscountType;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        $query = Coupon::query()
            ->withCount('redemptions')
            ->when($request->query('search'), fn ($q, $term) => $q->where('code', 'like', '%'.$term.'%'));

        $this->applyFilters($query, $request, ['is_active', 'type', 'applies_to']);
        $this->applySorting($query, $request, ['code', 'used_count', 'expires_at', 'created_at'], '-created_at');

        return CouponResource::collection(
            $query->paginate($this->perPage($request, 25, 100))->withQueryString()
        );
    }

    public function show(Coupon $coupon): CouponResource
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        return new CouponResource($coupon->load(['categories:id', 'products:id']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        $validated = $this->validateCoupon($request);
        $validated['created_by'] = $request->user()->id;

        $coupon = Coupon::create($this->attributes($validated));
        $this->syncScope($coupon, $validated);

        return response()->json([
            'data' => (new CouponResource($coupon))->resolve(),
            'message' => 'Coupon created.',
        ], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        $validated = $this->validateCoupon($request, $coupon);

        $coupon->fill($this->attributes($validated))->save();
        $this->syncScope($coupon, $validated);

        return response()->json([
            'data' => (new CouponResource($coupon->fresh(['categories:id', 'products:id'])))->resolve(),
            'message' => 'Coupon updated.',
        ]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        $coupon->delete();

        return response()->json(['message' => 'Coupon archived.']);
    }

    /** Who redeemed a coupon and when, for campaign reporting. */
    public function redemptions(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorizePermission(Permissions::COUPONS_MANAGE);

        $redemptions = $coupon->redemptions()
            ->with(['order:id,order_number,grand_total,placed_at', 'user:id,name'])
            ->latest()
            ->paginate($this->perPage($request, 25, 100));

        return response()->json([
            'data' => $redemptions->getCollection()->map(fn ($redemption) => [
                'id' => $redemption->id,
                'order_number' => $redemption->order?->order_number,
                'order_total' => (float) ($redemption->order?->grand_total ?? 0),
                'discount_amount' => (float) $redemption->discount_amount,
                'customer' => $redemption->user?->name ?? 'Guest',
                'redeemed_at' => $redemption->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $redemptions->total(),
                'current_page' => $redemptions->currentPage(),
                'last_page' => $redemptions->lastPage(),
                'total_discount' => round((float) $coupon->redemptions()->sum('discount_amount'), 2),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateCoupon(Request $request, ?Coupon $coupon = null): array
    {
        $required = $coupon ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [
                $required, 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon?->id)->whereNull('deleted_at'),
            ],
            'name_en' => [$required, 'string', 'max:255'],
            'name_ar' => [$required, 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'type' => [$required, 'string', 'in:'.implode(',', DiscountType::values())],
            'value' => [$required, 'numeric', 'min:0'],
            'minimum_order_amount' => ['sometimes', 'numeric', 'min:0'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'string', 'in:all,categories,products'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['sometimes', 'array', 'max:300'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $validated): array
    {
        unset($validated['category_ids'], $validated['product_ids']);

        // A percentage above 100 would produce a negative total.
        if (($validated['type'] ?? null) === DiscountType::Percentage->value && isset($validated['value'])) {
            $validated['value'] = min(100, (float) $validated['value']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function syncScope(Coupon $coupon, array $validated): void
    {
        if (array_key_exists('category_ids', $validated)) {
            $coupon->categories()->sync($validated['category_ids']);
        }

        if (array_key_exists('product_ids', $validated)) {
            $coupon->products()->sync($validated['product_ids']);
        }
    }
}
