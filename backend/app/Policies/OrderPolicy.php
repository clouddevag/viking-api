<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\Permissions;

/**
 * Staff access is permission-plus-branch: holding `orders.view` is not enough
 * if the order belongs to another branch.
 *
 * Customers reach their own orders through a separate guest-aware path in the
 * controller, not through this policy.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ORDERS_VIEW);
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->id === $order->user_id) {
            return true;
        }

        return $user->can(Permissions::ORDERS_VIEW)
            && $user->canAccessBranch($order->branch_id);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->can(Permissions::ORDERS_MANAGE)
            && $user->canAccessBranch($order->branch_id);
    }

    /** Moving a ticket through the kitchen lanes. */
    public function advance(User $user, Order $order): bool
    {
        return ($user->can(Permissions::ORDERS_KITCHEN) || $user->can(Permissions::ORDERS_MANAGE))
            && $user->canAccessBranch($order->branch_id);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->can(Permissions::ORDERS_CANCEL)
            && $user->canAccessBranch($order->branch_id);
    }

    public function pay(User $user, Order $order): bool
    {
        return $user->can(Permissions::PAYMENTS_CAPTURE)
            && $user->canAccessBranch($order->branch_id);
    }

    public function refund(User $user, Order $order): bool
    {
        return $user->can(Permissions::PAYMENTS_REFUND)
            && $user->canAccessBranch($order->branch_id);
    }

    public function discount(User $user, Order $order): bool
    {
        return $user->can(Permissions::PAYMENTS_DISCOUNT)
            && $user->canAccessBranch($order->branch_id);
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }
}
