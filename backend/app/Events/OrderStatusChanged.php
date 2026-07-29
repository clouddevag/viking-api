<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\OrderChannels;
use App\Enums\OrderStatus;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order moved along the lifecycle. Drives the kitchen lanes, the cashier's
 * live list and the customer's tracking timeline from a single message.
 */
class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return OrderChannels::forOrder($this->order);
    }

    public function broadcastAs(): string
    {
        return 'order.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'order' => (new OrderResource($this->order->loadMissing([
                'items.options', 'table', 'branch',
            ])))->resolve(),
        ];
    }
}
