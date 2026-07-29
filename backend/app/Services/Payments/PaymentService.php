<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentRecorded;
use App\Events\OrderRefunded;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records money in and out at the till.
 *
 * The order's `payment_status` is never assigned directly — it is always
 * recomputed from the sum of captured payments and refunds, so split payments
 * and partial refunds can't leave it inconsistent.
 */
class PaymentService
{
    /**
     * Captures a payment against an order.
     *
     * @throws PaymentException
     */
    public function capture(
        Order $order,
        PaymentMethod $method,
        float $amount,
        ?User $cashier = null,
        ?float $tendered = null,
        ?string $reference = null,
        array $meta = [],
    ): Payment {
        $outstanding = $order->outstandingAmount();

        if ($outstanding <= 0) {
            throw PaymentException::alreadySettled($order->order_number);
        }

        $amount = round($amount, 2);

        // A rounding cent over is fine; anything more is a mistake.
        if ($amount - $outstanding > 0.01) {
            throw PaymentException::amountExceedsOutstanding($amount, $outstanding);
        }

        if ($method->requiresTendering() && $tendered !== null && $tendered + 0.01 < $amount) {
            throw PaymentException::insufficientTender($tendered, $amount);
        }

        $payment = DB::transaction(function () use (
            $order, $method, $amount, $cashier, $tendered, $reference, $meta
        ) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'method' => $method,
                'status' => PaymentRecordStatus::Captured,
                'amount' => $amount,
                'tendered_amount' => $tendered,
                'change_amount' => $tendered !== null ? round(max(0, $tendered - $amount), 2) : 0,
                'currency' => $order->currency,
                'reference' => $reference,
                'meta' => $meta ?: null,
                'processed_by' => $cashier?->id,
                'processed_at' => now(),
            ]);

            $order->forceFill([
                'payment_method' => $method,
                'cashier_id' => $cashier?->id ?? $order->cashier_id,
            ])->save();

            $this->syncPaymentStatus($order);

            return $payment;
        });

        $order->refresh();

        OrderPaymentRecorded::dispatch($order, $payment);

        return $payment;
    }

    /**
     * Issues a refund. Partial refunds are allowed up to what was captured.
     *
     * @throws PaymentException
     */
    public function refund(
        Order $order,
        float $amount,
        string $reason,
        ?User $actor = null,
        ?Payment $against = null,
    ): Refund {
        $paid = $order->paidAmount();

        if ($paid <= 0) {
            throw PaymentException::nothingToRefund($order->order_number);
        }

        $refundable = round($paid - (float) $order->refunded_total, 2);
        $amount = round($amount, 2);

        if ($amount <= 0 || $amount - $refundable > 0.01) {
            throw PaymentException::refundExceedsPaid($amount, $refundable);
        }

        $refund = DB::transaction(function () use ($order, $amount, $reason, $actor, $against) {
            $refund = Refund::create([
                'order_id' => $order->id,
                'payment_id' => $against?->id,
                'amount' => $amount,
                'currency' => $order->currency,
                'reason' => $reason,
                'status' => 'completed',
                'processed_by' => $actor?->id,
                'processed_at' => now(),
            ]);

            $order->forceFill([
                'refunded_total' => round((float) $order->refunded_total + $amount, 2),
            ])->save();

            $this->syncPaymentStatus($order);

            return $refund;
        });

        $order->refresh();

        OrderRefunded::dispatch($order, $refund);

        return $refund;
    }

    /**
     * Applies a manager discount to an unpaid order and re-totals it.
     *
     * Only allowed before settlement — discounting a paid order would leave the
     * till short with no audit trail.
     *
     * @throws PaymentException
     */
    public function applyManualDiscount(
        Order $order,
        float $amount,
        string $reason,
        ?User $actor = null,
    ): Order {
        if ($order->payment_status !== PaymentStatus::Unpaid) {
            throw PaymentException::alreadySettled($order->order_number);
        }

        $ceiling = round(
            (float) $order->subtotal - (float) $order->discount_total,
            2
        );
        $amount = round(max(0, min($amount, $ceiling)), 2);

        $order->forceFill([
            'manual_discount_total' => $amount,
            'manual_discount_reason' => $reason,
            'grand_total' => $this->recomputeGrandTotal($order, $amount),
            'cashier_id' => $actor?->id ?? $order->cashier_id,
        ])->save();

        activity('order')
            ->performedOn($order)
            ->causedBy($actor)
            ->withProperties(['amount' => $amount, 'reason' => $reason])
            ->log('Manual discount applied');

        return $order->refresh();
    }

    /**
     * Recomputes `payment_status` from the ledger. Called after every capture
     * and refund so the flag can never drift from the underlying rows.
     */
    public function syncPaymentStatus(Order $order): void
    {
        $paid = $order->paidAmount();
        $refunded = (float) $order->refunded_total;
        $total = (float) $order->grand_total;

        $status = match (true) {
            $refunded > 0 && $refunded >= $paid - 0.01 => PaymentStatus::Refunded,
            $refunded > 0 => PaymentStatus::PartiallyRefunded,
            $paid >= $total - 0.01 && $total > 0 => PaymentStatus::Paid,
            default => PaymentStatus::Unpaid,
        };

        $order->forceFill(['payment_status' => $status])->save();
    }

    /**
     * Whether an order is fully settled — used by the cashier list and by the
     * table-release logic.
     */
    public function isSettled(Order $order): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            || $order->status === OrderStatus::Cancelled;
    }

    private function recomputeGrandTotal(Order $order, float $manualDiscount): float
    {
        $discounted = max(0, (float) $order->subtotal - (float) $order->discount_total - $manualDiscount);

        $tax = $this->percentOf($discounted, (float) config('viking.tax_percent', 0));
        $service = (float) $order->service_charge > 0
            ? $this->percentOf($discounted, (float) config('viking.service_charge_percent', 0))
            : 0.0;

        return round($discounted + $tax + $service + (float) $order->delivery_fee, 2);
    }

    private function percentOf(float $amount, float $percent): float
    {
        return $percent <= 0 ? 0.0 : round($amount * ($percent / 100), 2);
    }
}
