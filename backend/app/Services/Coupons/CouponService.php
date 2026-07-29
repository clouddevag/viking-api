<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Data\PricedLine;
use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Validates and redeems discount codes.
 *
 * Validation and redemption are deliberately separate: `evaluate()` is called
 * repeatedly while the customer edits their cart and must have no side
 * effects, while `redeem()` runs once inside the checkout transaction and is
 * what actually consumes a use.
 */
class CouponService
{
    /**
     * Resolves a code and computes the discount it would produce, without
     * consuming it.
     *
     * @param  array<int, PricedLine>  $lines
     * @return array{0: Coupon, 1: float}
     *
     * @throws CouponException
     */
    public function evaluate(
        string $code,
        array $lines,
        float $eligibleSubtotal,
        ?int $userId = null,
        ?string $guestToken = null,
    ): array {
        $coupon = $this->findOrFail($code);

        $this->assertUsable($coupon);
        $this->assertUserEligible($coupon, $userId, $guestToken);

        $applicable = $this->applicableSubtotal($coupon, $lines);

        if ($applicable <= 0) {
            throw CouponException::noEligibleItems($coupon->code);
        }

        // The minimum is judged against the whole order, not just the items the
        // coupon happens to cover.
        if ($eligibleSubtotal < (float) $coupon->minimum_order_amount) {
            throw CouponException::minimumNotMet(
                $coupon->code,
                (float) $coupon->minimum_order_amount
            );
        }

        $discount = $coupon->type->computeOn(
            amount: $applicable,
            value: (float) $coupon->value,
            maximum: $coupon->maximum_discount_amount !== null
                ? (float) $coupon->maximum_discount_amount
                : null,
        );

        return [$coupon, $discount];
    }

    /**
     * Consumes one use of the coupon for an order.
     *
     * Must be called inside the checkout transaction. The atomic increment and
     * the unique index on `coupon_redemptions` together make it impossible for
     * two concurrent checkouts to exceed `usage_limit`.
     *
     * @throws CouponException
     */
    public function redeem(
        Coupon $coupon,
        Order $order,
        float $discountAmount,
        ?int $userId = null,
        ?string $guestToken = null,
    ): CouponRedemption {
        // Conditional UPDATE: only succeeds while the limit still has room, so
        // the check and the increment cannot interleave.
        $claimed = Coupon::query()
            ->whereKey($coupon->getKey())
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->update(['used_count' => DB::raw('used_count + 1')]);

        if ($claimed === 0) {
            throw CouponException::usageLimitReached($coupon->code);
        }

        try {
            return CouponRedemption::create([
                'coupon_id' => $coupon->id,
                'order_id' => $order->id,
                'user_id' => $userId,
                'guest_token' => $guestToken,
                'discount_amount' => $discountAmount,
            ]);
        } catch (QueryException $e) {
            // Unique violation: this order already redeemed the coupon. Give
            // the claimed use back rather than leaking it.
            Coupon::query()
                ->whereKey($coupon->getKey())
                ->update(['used_count' => DB::raw('CASE WHEN used_count > 0 THEN used_count - 1 ELSE 0 END')]);

            throw $e;
        }
    }

    /** Releases a use when an order carrying the coupon is cancelled. */
    public function release(Order $order): void
    {
        if (! $order->coupon_id) {
            return;
        }

        DB::transaction(function () use ($order) {
            $deleted = CouponRedemption::query()
                ->where('coupon_id', $order->coupon_id)
                ->where('order_id', $order->id)
                ->delete();

            if ($deleted > 0) {
                Coupon::query()
                    ->whereKey($order->coupon_id)
                    ->update(['used_count' => DB::raw('CASE WHEN used_count > 0 THEN used_count - 1 ELSE 0 END')]);
            }
        });
    }

    /** @throws CouponException */
    public function findOrFail(string $code): Coupon
    {
        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $coupon) {
            throw CouponException::notFound($code);
        }

        return $coupon;
    }

    /** @throws CouponException */
    private function assertUsable(Coupon $coupon): void
    {
        if (! $coupon->is_active) {
            throw CouponException::inactive($coupon->code);
        }

        if (! $coupon->hasStarted()) {
            throw CouponException::notStarted($coupon->code);
        }

        if ($coupon->hasExpired()) {
            throw CouponException::expired($coupon->code);
        }

        if ($coupon->hasReachedGlobalLimit()) {
            throw CouponException::usageLimitReached($coupon->code);
        }
    }

    /**
     * Per-customer limits. A guest is identified by their device token, which
     * is weaker than an account but still stops casual re-use.
     *
     * @throws CouponException
     */
    private function assertUserEligible(Coupon $coupon, ?int $userId, ?string $guestToken): void
    {
        if ($coupon->first_order_only && $userId !== null) {
            $hasPrevious = Order::query()
                ->where('user_id', $userId)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($hasPrevious) {
                throw CouponException::firstOrderOnly($coupon->code);
            }
        }

        if ($coupon->usage_limit_per_user === null) {
            return;
        }

        if ($userId === null && $guestToken === null) {
            return;
        }

        $used = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->when($userId !== null,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->where('guest_token', $guestToken)
            )
            ->count();

        if ($used >= $coupon->usage_limit_per_user) {
            throw CouponException::userLimitReached($coupon->code);
        }
    }

    /**
     * The portion of the cart the coupon actually covers. A coupon restricted
     * to a category or product discounts only those lines.
     *
     * @param  array<int, PricedLine>  $lines
     */
    private function applicableSubtotal(Coupon $coupon, array $lines): float
    {
        if ($coupon->applies_to === 'all') {
            return round(array_sum(array_map(
                static fn (PricedLine $line) => $line->lineTotal,
                $lines
            )), 2);
        }

        $allowedProducts = $coupon->applies_to === 'products'
            ? $coupon->products()->pluck('products.id')->all()
            : [];

        $allowedCategories = $coupon->applies_to === 'categories'
            ? $coupon->categories()->pluck('categories.id')->all()
            : [];

        $total = 0.0;

        foreach ($lines as $line) {
            $matches = $coupon->applies_to === 'products'
                ? in_array($line->product->id, $allowedProducts, true)
                : in_array($line->product->category_id, $allowedCategories, true);

            if ($matches) {
                $total += $line->lineTotal;
            }
        }

        return round($total, 2);
    }
}
