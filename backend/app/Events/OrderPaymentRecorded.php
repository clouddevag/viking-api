<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\OrderChannels;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money changed hands. Lets a second cashier terminal drop a settled order
 * from its list without polling.
 */
class OrderPaymentRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Payment $payment,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return OrderChannels::forOrder($this->order);
    }

    public function broadcastAs(): string
    {
        return 'order.payment';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_number' => $this->order->order_number,
            'payment_status' => $this->order->payment_status->value,
            'grand_total' => (float) $this->order->grand_total,
            'paid_amount' => $this->order->paidAmount(),
            'outstanding' => $this->order->outstandingAmount(),
            'payment' => [
                'id' => $this->payment->id,
                'method' => $this->payment->method->value,
                'amount' => (float) $this->payment->amount,
                'change_amount' => (float) $this->payment->change_amount,
                'processed_at' => $this->payment->processed_at?->toIso8601String(),
            ],
        ];
    }
}
