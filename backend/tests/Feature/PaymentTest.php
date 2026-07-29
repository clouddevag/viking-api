<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CartLineInput;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentRecorded;
use App\Events\OrderPlaced;
use App\Events\OrderRefunded;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The payment ledger. `payment_status` is derived rather than assigned, so
 * these tests check that it stays consistent through split payments and
 * partial refunds — the cases where a naive implementation drifts.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        // Faking only the broadcasts keeps model booted hooks alive.
        Event::fake([OrderPlaced::class, OrderPaymentRecorded::class, OrderRefunded::class]);
        $this->payments = app(PaymentService::class);
    }

    private function order(float $total = 20000): Order
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => $total]);

        $cart = app(CartPricingService::class)->price(
            [new CartLineInput($product->id, 1)],
            $branch,
        );

        return app(OrderCreationService::class)->create(
            cart: $cart,
            branch: $branch,
            type: OrderType::Takeaway,
            guestToken: $this->guestToken(),
        );
    }

    public function test_a_full_payment_marks_the_order_paid(): void
    {
        $order = $this->order(20000);

        $this->payments->capture($order, PaymentMethod::Cash, 20000);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0.0, $order->fresh()->outstandingAmount());
    }

    public function test_it_computes_change_from_the_tendered_amount(): void
    {
        $order = $this->order(17500);

        $payment = $this->payments->capture(
            $order,
            PaymentMethod::Cash,
            17500,
            tendered: 20000,
        );

        $this->assertSame(2500.0, (float) $payment->change_amount);
    }

    public function test_split_payments_settle_the_order_together(): void
    {
        $order = $this->order(20000);

        $this->payments->capture($order, PaymentMethod::Cash, 12000);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(8000.0, $order->fresh()->outstandingAmount());

        $this->payments->capture($order->fresh(), PaymentMethod::Card, 8000);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_it_refuses_to_overpay(): void
    {
        $order = $this->order(10000);

        $this->expectException(PaymentException::class);

        $this->payments->capture($order, PaymentMethod::Cash, 15000);
    }

    public function test_it_refuses_a_second_payment_once_settled(): void
    {
        $order = $this->order(10000);
        $this->payments->capture($order, PaymentMethod::Cash, 10000);

        $this->expectException(PaymentException::class);

        $this->payments->capture($order->fresh(), PaymentMethod::Cash, 10000);
    }

    public function test_it_refuses_insufficient_tender(): void
    {
        $order = $this->order(10000);

        $this->expectException(PaymentException::class);

        $this->payments->capture($order, PaymentMethod::Cash, 10000, tendered: 5000);
    }

    public function test_a_partial_refund_is_flagged_as_partial(): void
    {
        $order = $this->order(20000);
        $this->payments->capture($order, PaymentMethod::Cash, 20000);

        $this->payments->refund($order->fresh(), 5000, 'Item was cold');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $fresh->payment_status);
        $this->assertSame(5000.0, (float) $fresh->refunded_total);
    }

    public function test_a_full_refund_is_flagged_as_refunded(): void
    {
        $order = $this->order(20000);
        $this->payments->capture($order, PaymentMethod::Cash, 20000);

        $this->payments->refund($order->fresh(), 20000, 'Order cancelled');

        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
    }

    public function test_it_refuses_to_refund_more_than_was_paid(): void
    {
        $order = $this->order(20000);
        $this->payments->capture($order, PaymentMethod::Cash, 20000);

        $this->expectException(PaymentException::class);

        $this->payments->refund($order->fresh(), 30000, 'Too much');
    }

    public function test_it_refuses_to_refund_an_unpaid_order(): void
    {
        $order = $this->order(20000);

        $this->expectException(PaymentException::class);

        $this->payments->refund($order, 5000, 'Nothing to refund');
    }

    public function test_repeated_partial_refunds_cannot_exceed_the_total(): void
    {
        $order = $this->order(20000);
        $this->payments->capture($order, PaymentMethod::Cash, 20000);

        $this->payments->refund($order->fresh(), 8000, 'First');
        $this->payments->refund($order->fresh(), 8000, 'Second');

        $this->expectException(PaymentException::class);

        // 16,000 already returned — only 4,000 remains refundable.
        $this->payments->refund($order->fresh(), 8000, 'Third');
    }

    public function test_a_manual_discount_re_totals_the_order(): void
    {
        $order = $this->order(20000);

        $discounted = $this->payments->applyManualDiscount($order, 5000, 'Manager comp');

        $this->assertSame(5000.0, (float) $discounted->manual_discount_total);
        $this->assertSame(15000.0, (float) $discounted->grand_total);
    }

    public function test_a_manual_discount_cannot_exceed_the_subtotal(): void
    {
        $order = $this->order(10000);

        $discounted = $this->payments->applyManualDiscount($order, 999999, 'Typo');

        // Clamped rather than producing a negative bill.
        $this->assertSame(10000.0, (float) $discounted->manual_discount_total);
        $this->assertSame(0.0, (float) $discounted->grand_total);
    }

    public function test_a_paid_order_cannot_be_discounted(): void
    {
        $order = $this->order(10000);
        $this->payments->capture($order, PaymentMethod::Cash, 10000);

        $this->expectException(PaymentException::class);

        $this->payments->applyManualDiscount($order->fresh(), 2000, 'Too late');
    }
}
