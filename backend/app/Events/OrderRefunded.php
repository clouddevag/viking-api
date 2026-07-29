<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\OrderChannels;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderRefunded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Refund $refund,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return OrderChannels::forOrder($this->order);
    }

    public function broadcastAs(): string
    {
        return 'order.refunded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_number' => $this->order->order_number,
            'payment_status' => $this->order->payment_status->value,
            'refunded_total' => (float) $this->order->refunded_total,
            'refund' => [
                'id' => $this->refund->id,
                'amount' => (float) $this->refund->amount,
                'reason' => $this->refund->reason,
                'processed_at' => $this->refund->processed_at?->toIso8601String(),
            ],
        ];
    }
}
