<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical order shape, used by the customer tracker, the kitchen
 * display, the cashier and the admin list alike — one payload keeps the
 * websocket message and the REST response identical.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(app()->getLocale()),
            'payment_status' => $this->payment_status->value,
            'payment_method' => $this->payment_method?->value,

            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'guest_count' => $this->guest_count,
            'notes' => $this->notes,
            'delivery_address' => $this->delivery_address,

            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'phone' => $this->branch->phone,
            ]),
            'branch_id' => $this->branch_id,

            'table' => $this->whenLoaded('table', fn () => $this->table ? [
                'id' => $this->table->id,
                'number' => $this->table->number,
                'name' => $this->table->displayName(),
                'zone' => $this->table->zone,
            ] : null),

            'totals' => [
                'subtotal' => (float) $this->subtotal,
                'discount_total' => (float) $this->discount_total,
                'manual_discount_total' => (float) $this->manual_discount_total,
                'tax_total' => (float) $this->tax_total,
                'service_charge' => (float) $this->service_charge,
                'delivery_fee' => (float) $this->delivery_fee,
                'grand_total' => (float) $this->grand_total,
                'refunded_total' => (float) $this->refunded_total,
                'currency' => $this->currency,
            ],

            'coupon_code' => $this->coupon_code,
            'estimated_minutes' => $this->estimated_minutes,
            'age_minutes' => $this->ageInMinutes(),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),

            'timeline' => $this->whenLoaded(
                'statusEvents',
                fn () => $this->statusEvents->map(fn ($event) => [
                    'from' => $event->from_status,
                    'to' => $event->to_status,
                    'actor' => $event->actor_label,
                    'note' => $event->note,
                    'at' => $event->created_at?->toIso8601String(),
                ])->all()
            ),

            'payments' => $this->whenLoaded(
                'payments',
                fn () => $this->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'method' => $payment->method->value,
                    'status' => $payment->status->value,
                    'amount' => (float) $payment->amount,
                    'tendered_amount' => $payment->tendered_amount !== null
                        ? (float) $payment->tendered_amount
                        : null,
                    'change_amount' => (float) $payment->change_amount,
                    'processed_at' => $payment->processed_at?->toIso8601String(),
                ])->all()
            ),

            'refunds' => $this->whenLoaded(
                'refunds',
                fn () => $this->refunds->map(fn ($refund) => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'reason' => $refund->reason,
                    'status' => $refund->status,
                    'processed_at' => $refund->processed_at?->toIso8601String(),
                ])->all()
            ),

            'placed_at' => $this->placed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'preparing_at' => $this->preparing_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'served_at' => $this->served_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,

            'allowed_transitions' => array_map(
                fn ($status) => $status->value,
                $this->status->allowedTransitions()
            ),
        ];
    }
}
