<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Every order event fans out to the same set of channels, so the routing lives
 * here instead of being repeated in each event class.
 *
 * Staff channels are private and gated by role in `routes/channels.php`.
 * A guest customer has no account to authenticate, so their channel is public
 * but named with the 32-character device token only their browser holds —
 * the same security model as an unguessable tracking link.
 */
final class OrderChannels
{
    /** @return array<int, Channel> */
    public static function forOrder(Order $order): array
    {
        return array_merge(
            self::staff($order->branch_id),
            self::customer($order),
        );
    }

    /** @return array<int, Channel> */
    public static function staff(int $branchId): array
    {
        return [
            new PrivateChannel("branches.{$branchId}.kitchen"),
            new PrivateChannel("branches.{$branchId}.cashier"),
            new PrivateChannel("branches.{$branchId}.admin"),
        ];
    }

    /** @return array<int, Channel> */
    public static function customer(Order $order): array
    {
        if ($order->user_id) {
            return [new PrivateChannel("users.{$order->user_id}.orders")];
        }

        if ($order->guest_token) {
            return [new Channel("guests.{$order->guest_token}.orders")];
        }

        return [];
    }
}
