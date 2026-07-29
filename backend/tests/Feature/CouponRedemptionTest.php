<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CartLineInput;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Services\Coupons\CouponService;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Coupon redemption, with an emphasis on the limit accounting — the part that
 * quietly leaks free money when it is wrong.
 */
class CouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Event::fake([OrderPlaced::class, OrderStatusChanged::class]);
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'TEST10',
            'name_en' => 'Ten',
            'name_ar' => 'عشرة',
            'type' => DiscountType::Fixed,
            'value' => 1000,
            'is_active' => true,
        ], $attributes));
    }

    private function placeOrderWithCoupon(string $code, ?string $guestToken = null)
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 20000]);
        $guestToken ??= $this->guestToken();

        $cart = app(CartPricingService::class)->price(
            [new CartLineInput($product->id, 1)],
            $branch,
            couponCode: $code,
            guestToken: $guestToken,
        );

        return app(OrderCreationService::class)->create(
            cart: $cart,
            branch: $branch,
            type: OrderType::Takeaway,
            guestToken: $guestToken,
        );
    }

    public function test_redeeming_records_the_use_and_increments_the_counter(): void
    {
        $coupon = $this->coupon();

        $order = $this->placeOrderWithCoupon('TEST10');

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertDatabaseHas('coupon_redemptions', [
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
        ]);
        $this->assertSame(19000.0, (float) $order->grand_total);
    }

    public function test_the_global_usage_limit_is_enforced(): void
    {
        $this->coupon(['usage_limit' => 1]);

        $this->placeOrderWithCoupon('TEST10');

        // The second attempt must fail while pricing, before an order exists.
        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('TEST10');
    }

    public function test_the_claim_is_atomic_at_the_redemption_step(): void
    {
        $coupon = $this->coupon(['usage_limit' => 1]);
        $service = app(CouponService::class);

        $first = $this->placeOrderWithCoupon('TEST10');
        $this->assertSame(1, $coupon->fresh()->used_count);

        // Simulate a racing checkout that got past validation before the first
        // one committed: the conditional UPDATE is what stops it.
        $this->expectException(CouponException::class);

        $service->redeem($coupon->fresh(), $first, 1000);
    }

    public function test_the_per_user_limit_counts_a_guest_by_device_token(): void
    {
        $this->coupon(['usage_limit_per_user' => 1]);
        $token = $this->guestToken();

        $this->placeOrderWithCoupon('TEST10', $token);

        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('TEST10', $token);
    }

    public function test_a_different_device_may_still_redeem(): void
    {
        $coupon = $this->coupon(['usage_limit_per_user' => 1]);

        $this->placeOrderWithCoupon('TEST10', $this->guestToken());
        $this->placeOrderWithCoupon('TEST10', $this->guestToken());

        $this->assertSame(2, $coupon->fresh()->used_count);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $this->coupon(['expires_at' => now()->subDay()]);

        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('TEST10');
    }

    public function test_a_future_coupon_is_refused(): void
    {
        $this->coupon(['starts_at' => now()->addWeek()]);

        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('TEST10');
    }

    public function test_an_inactive_coupon_is_refused(): void
    {
        $this->coupon(['is_active' => false]);

        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('TEST10');
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->expectException(CouponException::class);

        $this->placeOrderWithCoupon('NOSUCHCODE');
    }

    public function test_codes_are_matched_case_insensitively(): void
    {
        $coupon = $this->coupon(['code' => 'SUMMER']);

        $this->placeOrderWithCoupon('summer');

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_cancelling_an_order_releases_the_coupon(): void
    {
        $coupon = $this->coupon(['usage_limit' => 1]);

        $order = $this->placeOrderWithCoupon('TEST10');
        $this->assertSame(1, $coupon->fresh()->used_count);

        app(OrderStatusService::class)->cancel($order, 'Changed their mind');

        // The customer must be able to try again with the same code.
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertDatabaseMissing('coupon_redemptions', ['order_id' => $order->id]);
    }

    public function test_a_percentage_coupon_never_exceeds_the_cart(): void
    {
        $this->coupon([
            'code' => 'FREE',
            'type' => DiscountType::Percentage,
            'value' => 100,
        ]);

        $order = $this->placeOrderWithCoupon('FREE');

        $this->assertSame(0.0, (float) $order->grand_total);
    }
}
