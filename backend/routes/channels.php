<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|------------------------------------------------------------------------------
| Broadcast channel authorization
|------------------------------------------------------------------------------
| Staff channels are gated by permission and by branch: a cook at one branch
| must not be able to subscribe to another branch's tickets. Admins are the
| only role allowed across branches.
|
| Guest customers subscribe to a public channel keyed by their device token, so
| they need no authorization callback here.
*/

Broadcast::channel('branches.{branchId}.kitchen', function (User $user, int $branchId) {
    return $user->is_active
        && $user->can('orders.kitchen')
        && $user->canAccessBranch($branchId);
});

Broadcast::channel('branches.{branchId}.cashier', function (User $user, int $branchId) {
    return $user->is_active
        && $user->can('orders.cashier')
        && $user->canAccessBranch($branchId);
});

Broadcast::channel('branches.{branchId}.admin', function (User $user, int $branchId) {
    return $user->is_active
        && $user->can('dashboard.view')
        && $user->canAccessBranch($branchId);
});

/**
 * A registered customer's own order feed.
 */
Broadcast::channel('users.{userId}.orders', function (User $user, int $userId) {
    return $user->id === $userId;
});

/**
 * Presence channel listing the staff currently watching a branch's kitchen
 * display, used to warn when nobody is.
 */
Broadcast::channel('presence-branches.{branchId}.staff', function (User $user, int $branchId) {
    if (! $user->is_active || ! $user->canAccessBranch($branchId)) {
        return null;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'roles' => $user->getRoleNames()->all(),
    ];
});
