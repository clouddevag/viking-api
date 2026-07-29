<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Data\PricedCart;
use App\Data\PricedLine;
use App\Data\PricedOption;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderPlaced;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Coupons\CouponService;
use Illuminate\Support\Facades\DB;

/**
 * Writes a priced cart to the database as an order.
 *
 * Everything here happens in one transaction: the order, its line snapshots,
 * the coupon redemption and the opening status event either all land or none
 * do. Broadcasting happens after commit so the kitchen never sees an order
 * that was subsequently rolled back.
 */
class OrderCreationService
{
    public function __construct(
        private readonly OrderNumberGenerator $numbers,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  customer_name, customer_phone,
     *                                            notes, delivery_address, guest_count
     */
    public function create(
        PricedCart $cart,
        Branch $branch,
        OrderType $type,
        array $attributes = [],
        ?User $user = null,
        ?DiningTable $table = null,
        ?TableSession $session = null,
        ?string $guestToken = null,
    ): Order {
        $order = DB::transaction(function () use (
            $cart, $branch, $type, $attributes, $user, $table, $session, $guestToken
        ) {
            $now = now();

            $order = Order::create([
                'order_number' => $this->numbers->generate($branch->id, $branch->slug),
                'branch_id' => $branch->id,
                'dining_table_id' => $table?->id,
                'table_session_id' => $session?->id,
                'user_id' => $user?->id,
                'customer_name' => $attributes['customer_name']
                    ?? $user?->name
                    ?? $session?->guest_name,
                'customer_phone' => $attributes['customer_phone']
                    ?? $user?->phone
                    ?? $session?->guest_phone,
                'guest_token' => $user ? null : $guestToken,
                'type' => $type,
                'status' => OrderStatus::Pending,
                'subtotal' => $cart->subtotal,
                'discount_total' => $cart->discountTotal,
                'tax_total' => $cart->taxTotal,
                'service_charge' => $cart->serviceCharge,
                'delivery_fee' => $cart->deliveryFee,
                'grand_total' => $cart->grandTotal,
                'currency' => $cart->currency,
                'coupon_id' => $cart->coupon?->id,
                'coupon_code' => $cart->coupon?->code,
                'notes' => $attributes['notes'] ?? null,
                'delivery_address' => $attributes['delivery_address'] ?? null,
                'guest_count' => $attributes['guest_count'] ?? $session?->party_size,
                'estimated_minutes' => $cart->estimatedMinutes,
                'placed_at' => $now,
            ]);

            foreach ($cart->lines as $line) {
                $this->writeLine($order, $line);
            }

            if ($cart->coupon) {
                $this->coupons->redeem(
                    coupon: $cart->coupon,
                    order: $order,
                    discountAmount: $cart->couponDiscount,
                    userId: $user?->id,
                    guestToken: $guestToken,
                );
            }

            OrderStatusEvent::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'user_id' => $user?->id,
                'actor_label' => $user?->name ?? $order->customer_name ?? 'Guest',
                'note' => 'Order placed',
                'created_at' => $now,
            ]);

            $session?->touchActivity();

            // Popularity counters feed the "most ordered" menu rail.
            foreach ($cart->lines as $line) {
                $line->product->incrementOrderCount($line->quantity);
            }

            return $order;
        });

        $order->load(['items.options', 'branch', 'table', 'user']);

        // After the transaction commits — the kitchen must only ever be told
        // about orders that really exist.
        OrderPlaced::dispatch($order);

        return $order;
    }

    private function writeLine(Order $order, PricedLine $line): OrderItem
    {
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $line->product->id,
            'product_name_en' => (string) $line->product->name_en,
            'product_name_ar' => (string) $line->product->name_ar,
            'product_sku' => $line->product->sku,
            'image_url' => $line->product->image?->conversionUrl('small'),
            'unit_price' => $line->unitPrice,
            'options_total' => $line->optionsTotal,
            'quantity' => $line->quantity,
            'line_subtotal' => $line->lineSubtotal,
            'discount_total' => $line->discountTotal,
            'line_total' => $line->lineTotal,
            'special_instructions' => $line->specialInstructions,
            'status' => OrderItemStatus::Pending,
            'prep_time_minutes' => $line->prepTimeMinutes,
        ]);

        $rows = array_map(static fn (PricedOption $option) => [
            'order_item_id' => $item->id,
            'option_id' => $option->optionId,
            'group_name_en' => $option->groupNameEn,
            'group_name_ar' => $option->groupNameAr,
            'group_kind' => $option->groupKind->value,
            'option_name_en' => $option->optionNameEn,
            'option_name_ar' => $option->optionNameAr,
            'price_delta' => $option->priceDelta,
            'quantity' => $option->quantity,
        ], $line->options);

        if ($rows !== []) {
            DB::table('order_item_options')->insert($rows);
        }

        return $item;
    }
}
