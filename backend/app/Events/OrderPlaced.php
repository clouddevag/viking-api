<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\OrderChannels;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new order has been accepted and persisted. This is what makes a ticket
 * appear on the kitchen display and plays its alert sound.
 */
class OrderPlaced implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Order $order) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return OrderChannels::forOrder($this->order);
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order' => (new OrderResource($this->order->loadMissing([
                'items.options', 'table', 'branch',
            ])))->resolve(),
        ];
    }
}
