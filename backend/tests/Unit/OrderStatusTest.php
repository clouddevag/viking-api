<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum behaviour — no database, so these run in milliseconds and pin down
 * the state machine that everything else defers to.
 */
class OrderStatusTest extends TestCase
{
    public function test_the_happy_path_is_permitted(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Preparing));
        $this->assertTrue(OrderStatus::Preparing->canTransitionTo(OrderStatus::Ready));
        $this->assertTrue(OrderStatus::Ready->canTransitionTo(OrderStatus::Served));
        $this->assertTrue(OrderStatus::Served->canTransitionTo(OrderStatus::Completed));
    }

    public function test_it_cannot_move_backwards(): void
    {
        $this->assertFalse(OrderStatus::Preparing->canTransitionTo(OrderStatus::Pending));
        $this->assertFalse(OrderStatus::Ready->canTransitionTo(OrderStatus::Preparing));
        $this->assertFalse(OrderStatus::Completed->canTransitionTo(OrderStatus::Ready));
    }

    public function test_it_cannot_skip_preparation(): void
    {
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Ready));
        $this->assertFalse(OrderStatus::Confirmed->canTransitionTo(OrderStatus::Served));
    }

    public function test_terminal_states_accept_nothing(): void
    {
        $this->assertTrue(OrderStatus::Completed->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertSame([], OrderStatus::Completed->allowedTransitions());
        $this->assertSame([], OrderStatus::Cancelled->allowedTransitions());
    }

    public function test_cancellation_is_allowed_until_the_food_is_handed_over(): void
    {
        foreach (
            [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready] as $status
        ) {
            $this->assertTrue(
                $status->canTransitionTo(OrderStatus::Cancelled),
                "{$status->value} should be cancellable",
            );
        }

        // Once served, the food exists and has been given away.
        $this->assertFalse(OrderStatus::Served->canTransitionTo(OrderStatus::Cancelled));
    }

    public function test_the_kitchen_owns_exactly_four_statuses(): void
    {
        $active = array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $status) => $status->isActiveInKitchen(),
        );

        $this->assertCount(4, $active);
    }

    public function test_every_status_maps_to_a_timestamp_column(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertNotNull($status->timestampColumn(), "{$status->value} needs a column");
        }
    }

    public function test_labels_exist_in_both_locales(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertNotSame('', $status->label('en'));
            $this->assertNotSame('', $status->label('ar'));
            $this->assertNotSame($status->label('en'), $status->label('ar'));
        }
    }
}

/**
 * Discount arithmetic, including the edges that produce a negative bill if the
 * clamping is missing.
 */
class DiscountTypeTest extends TestCase
{
    public function test_a_percentage_is_computed_on_the_amount(): void
    {
        $this->assertSame(1000.0, DiscountType::Percentage->computeOn(10000, 10));
    }

    public function test_a_fixed_discount_is_the_value(): void
    {
        $this->assertSame(2500.0, DiscountType::Fixed->computeOn(10000, 2500));
    }

    public function test_a_cap_limits_a_percentage(): void
    {
        $this->assertSame(500.0, DiscountType::Percentage->computeOn(10000, 50, maximum: 500));
    }

    public function test_a_discount_never_exceeds_the_amount(): void
    {
        // A 5,000 coupon on a 2,000 cart discounts 2,000, not 5,000.
        $this->assertSame(2000.0, DiscountType::Fixed->computeOn(2000, 5000));
    }

    public function test_it_never_returns_a_negative_discount(): void
    {
        $this->assertSame(0.0, DiscountType::Fixed->computeOn(1000, -500));
    }

    public function test_it_rounds_to_two_decimals(): void
    {
        $this->assertSame(333.33, DiscountType::Percentage->computeOn(1000, 33.333));
    }
}

class OrderTypeTest extends TestCase
{
    public function test_only_dine_in_needs_a_table(): void
    {
        $this->assertTrue(OrderType::DineIn->requiresTable());
        $this->assertFalse(OrderType::Takeaway->requiresTable());
        $this->assertFalse(OrderType::Delivery->requiresTable());
    }

    public function test_only_delivery_needs_an_address(): void
    {
        $this->assertTrue(OrderType::Delivery->requiresAddress());
        $this->assertFalse(OrderType::DineIn->requiresAddress());
    }
}
