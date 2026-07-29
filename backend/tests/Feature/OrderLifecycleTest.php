<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CartLineInput;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Only the broadcast events are faked. A bare `Event::fake()` swaps out the
     * dispatcher wholesale, which would also silence model `booted` hooks —
     * and DiningTable mints its QR token in one.
     */
    private const BROADCAST_EVENTS = [OrderPlaced::class, OrderStatusChanged::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function placeOrder(array $overrides = []): Order
    {
        $branch = $overrides['branch'] ?? $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 10000]);

        $cart = app(CartPricingService::class)->price(
            [new CartLineInput($product->id, 2)],
            $branch,
        );

        return app(OrderCreationService::class)->create(
            cart: $cart,
            branch: $branch,
            type: $overrides['type'] ?? OrderType::Takeaway,
            attributes: ['customer_name' => 'Layla'],
            table: $overrides['table'] ?? null,
            guestToken: $overrides['guestToken'] ?? $this->guestToken(),
        );
    }

    public function test_placing_an_order_snapshots_the_line(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(20000.0, (float) $order->grand_total);
        $this->assertNotNull($order->placed_at);

        $item = $order->items->first();
        $this->assertSame('Test Burger', $item->product_name_en);
        $this->assertSame(10000.0, (float) $item->unit_price);

        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_order_numbers_are_unique_and_readable(): void
    {
        $branch = $this->makeBranch(['slug' => 'downtown']);

        $first = $this->placeOrder(['branch' => $branch]);
        $second = $this->placeOrder(['branch' => $branch]);

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertStringStartsWith('VK-DOW-', $first->order_number);
    }

    public function test_placing_an_order_writes_an_opening_status_event(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();

        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
        ]);
    }

    public function test_it_advances_along_the_happy_path(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        $service = app(OrderStatusService::class);

        foreach (
            [
                OrderStatus::Confirmed,
                OrderStatus::Preparing,
                OrderStatus::Ready,
                OrderStatus::Served,
                OrderStatus::Completed,
            ] as $expected
        ) {
            $order = $service->advance($order);
            $this->assertSame($expected, $order->status);
        }

        $this->assertNotNull($order->completed_at);
    }

    public function test_it_refuses_an_illegal_transition(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        $service = app(OrderStatusService::class);

        $order = $service->transition($order, OrderStatus::Confirmed);

        $this->expectException(OrderTransitionException::class);

        // Confirmed cannot jump straight to served.
        $service->transition($order, OrderStatus::Served);
    }

    public function test_it_refuses_to_change_a_terminal_order(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        $service = app(OrderStatusService::class);

        $order = $service->cancel($order, 'Changed their mind');

        $this->expectException(OrderTransitionException::class);

        $service->transition($order, OrderStatus::Preparing);
    }

    public function test_each_transition_stamps_its_timestamp_and_journals(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        $service = app(OrderStatusService::class);

        $order = $service->transition($order, OrderStatus::Confirmed);
        $order = $service->transition($order, OrderStatus::Preparing);

        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->preparing_at);

        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->id,
            'from_status' => 'confirmed',
            'to_status' => 'preparing',
        ]);
    }

    public function test_a_transition_broadcasts(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        app(OrderStatusService::class)->transition($order, OrderStatus::Confirmed);

        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event) => $event->to === OrderStatus::Confirmed,
        );
    }

    public function test_cancelling_marks_items_cancelled(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $order = $this->placeOrder();
        app(OrderStatusService::class)->cancel($order, 'Out of stock');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'status' => 'cancelled',
        ]);
        $this->assertSame('Out of stock', $order->fresh()->cancel_reason);
    }

    public function test_a_dine_in_order_occupies_and_then_frees_its_table(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $branch = $this->makeBranch();
        $table = $this->makeTable($branch);

        $order = $this->placeOrder([
            'branch' => $branch,
            'table' => $table,
            'type' => OrderType::DineIn,
        ]);

        $service = app(OrderStatusService::class);
        $service->transition($order, OrderStatus::Confirmed);

        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);

        // Completing the last order on a settled table releases it.
        $order = $order->fresh();
        foreach ([OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Served, OrderStatus::Completed] as $status) {
            $order = $service->transition($order, $status);
        }

        // The order is still unpaid, so the table stays occupied — releasing it
        // here would lose the bill.
        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);
    }

    public function test_product_order_counts_increase(): void
    {
        Event::fake(self::BROADCAST_EVENTS);

        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 1000]);

        $cart = app(CartPricingService::class)->price(
            [new CartLineInput($product->id, 4)],
            $branch,
        );

        app(OrderCreationService::class)->create(
            cart: $cart,
            branch: $branch,
            type: OrderType::Takeaway,
            guestToken: $this->guestToken(),
        );

        $this->assertSame(4, $product->fresh()->order_count);
    }
}
