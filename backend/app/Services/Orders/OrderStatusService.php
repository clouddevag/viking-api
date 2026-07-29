<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TableStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\OrderTransitionException;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\User;
use App\Services\Coupons\CouponService;
use Illuminate\Support\Facades\DB;

/**
 * The one way an order's status ever changes.
 *
 * Centralising it means a transition is always validated against the state
 * machine, stamped with its timestamp, journalled to `order_status_events` and
 * broadcast — no caller can do three of those four and forget the last.
 */
class OrderStatusService
{
    public function __construct(
        private readonly CouponService $coupons,
    ) {}

    /**
     * @throws OrderTransitionException
     */
    public function transition(
        Order $order,
        OrderStatus $target,
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        $from = $order->status;

        if ($from === $target) {
            return $order;
        }

        if ($from->isTerminal()) {
            throw OrderTransitionException::terminal($from);
        }

        if (! $from->canTransitionTo($target)) {
            throw OrderTransitionException::illegal($from, $target);
        }

        DB::transaction(function () use ($order, $from, $target, $actor, $note) {
            $updates = ['status' => $target];

            if ($column = $target->timestampColumn()) {
                $updates[$column] = now();
            }

            // Record who did what, for the kitchen performance report.
            match ($target) {
                OrderStatus::Confirmed, OrderStatus::Preparing => $updates['accepted_by'] = $actor?->id ?? $order->accepted_by,
                OrderStatus::Served => $updates['served_by'] = $actor?->id,
                OrderStatus::Cancelled => $updates['cancelled_by'] = $actor?->id,
                default => null,
            };

            if ($target === OrderStatus::Cancelled && $note) {
                $updates['cancel_reason'] = $note;
            }

            $order->forceFill($updates)->save();

            $this->syncItemStatuses($order, $target);

            OrderStatusEvent::create([
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'user_id' => $actor?->id,
                'actor_label' => $actor?->name ?? 'System',
                'note' => $note,
                'created_at' => now(),
            ]);

            if ($target === OrderStatus::Cancelled) {
                // Give the coupon use back so the customer can retry.
                $this->coupons->release($order);
            }

            $this->syncTableStatus($order, $target);
        });

        $order->refresh()->load(['items.options', 'table', 'branch']);

        OrderStatusChanged::dispatch($order, $from, $target);

        return $order;
    }

    /**
     * Cancels an order, refusing once it is terminal.
     *
     * @throws OrderTransitionException
     */
    public function cancel(Order $order, string $reason, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::Cancelled, $actor, $reason);
    }

    /**
     * Moves the order forward one step along the happy path, which is what the
     * kitchen's single big button does.
     *
     * @throws OrderTransitionException
     */
    public function advance(Order $order, ?User $actor = null): Order
    {
        $next = match ($order->status) {
            OrderStatus::Pending => OrderStatus::Confirmed,
            OrderStatus::Confirmed => OrderStatus::Preparing,
            OrderStatus::Preparing => OrderStatus::Ready,
            OrderStatus::Ready => OrderStatus::Served,
            OrderStatus::Served => OrderStatus::Completed,
            default => throw OrderTransitionException::terminal($order->status),
        };

        return $this->transition($order, $next, $actor);
    }

    /**
     * Line statuses follow the order's, except that a cancelled order leaves
     * already-served items alone — they were genuinely delivered.
     */
    private function syncItemStatuses(Order $order, OrderStatus $target): void
    {
        $itemStatus = match ($target) {
            OrderStatus::Preparing => OrderItemStatus::Preparing,
            OrderStatus::Ready => OrderItemStatus::Ready,
            OrderStatus::Served, OrderStatus::Completed => OrderItemStatus::Served,
            OrderStatus::Cancelled => OrderItemStatus::Cancelled,
            default => null,
        };

        if ($itemStatus === null) {
            return;
        }

        $query = $order->items()->getQuery();

        if ($target === OrderStatus::Cancelled) {
            $query->where('status', '!=', OrderItemStatus::Served->value);
        }

        $query->update(['status' => $itemStatus->value, 'updated_at' => now()]);
    }

    /**
     * Frees a table once its last order is done and the bill is settled, so the
     * floor plan reflects reality without staff having to tap anything.
     */
    private function syncTableStatus(Order $order, OrderStatus $target): void
    {
        if (! $order->dining_table_id) {
            return;
        }

        if (in_array($target, [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing], true)) {
            $order->table?->forceFill(['status' => TableStatus::Occupied])->save();

            return;
        }

        if (! in_array($target, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            return;
        }

        $stillBusy = Order::query()
            ->where('dining_table_id', $order->dining_table_id)
            ->whereKeyNot($order->getKey())
            ->active()
            ->exists();

        $unsettled = Order::query()
            ->where('dining_table_id', $order->dining_table_id)
            ->where('payment_status', PaymentStatus::Unpaid)
            ->whereNot('status', OrderStatus::Cancelled)
            ->exists();

        if (! $stillBusy && ! $unsettled) {
            $order->table?->forceFill(['status' => TableStatus::Available])->save();
        }
    }
}
