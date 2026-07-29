<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Exceptions\CouponException;
use App\Http\Controllers\Api\V1\Concerns\IdentifiesGuests;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceCartRequest;
use App\Models\Branch;
use App\Services\Orders\CartPricingService;
use Illuminate\Http\JsonResponse;

/**
 * Live cart totals.
 *
 * The client calls this whenever the cart changes so the customer always sees
 * server-computed prices — the same code path checkout uses, which is what
 * guarantees the quoted total is the charged total.
 */
class CartController extends Controller
{
    use IdentifiesGuests;

    public function __construct(
        private readonly CartPricingService $pricing,
    ) {}

    public function price(PriceCartRequest $request): JsonResponse
    {
        $branch = Branch::query()->active()->findOrFail($request->validated('branch_id'));

        $cart = $this->pricing->price(
            inputs: $request->lines(),
            branch: $branch,
            type: $request->orderType(),
            couponCode: $request->validated('coupon_code'),
            userId: $request->user()?->id,
            guestToken: $this->guestToken($request),
            // An expired coupon should not block the customer from seeing
            // their totals; the error is reported alongside them instead.
            throwOnInvalidCoupon: false,
        );

        $couponError = null;
        $requestedCode = $request->validated('coupon_code');

        if ($requestedCode && ! $cart->coupon) {
            $couponError = $this->couponError($request, $branch);
        }

        return response()->json([
            'data' => $cart->toArray(),
            'coupon_error' => $couponError,
        ]);
    }

    /**
     * Re-runs pricing with strict coupon handling purely to capture the
     * human-readable reason the code was rejected.
     */
    private function couponError(PriceCartRequest $request, Branch $branch): ?array
    {
        try {
            $this->pricing->price(
                inputs: $request->lines(),
                branch: $branch,
                type: $request->orderType(),
                couponCode: $request->validated('coupon_code'),
                userId: $request->user()?->id,
                guestToken: $this->guestToken($request),
                throwOnInvalidCoupon: true,
            );

            return null;
        } catch (CouponException $e) {
            return ['message' => $e->getMessage(), 'context' => $e->context()];
        }
    }
}
