<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::USERS_VIEW);
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->can(Permissions::USERS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::USERS_MANAGE);
    }

    public function update(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        // Nobody may edit an account more privileged than their own.
        if ($target->hasRole('super-admin') && ! $user->hasRole('super-admin')) {
            return false;
        }

        return $user->can(Permissions::USERS_MANAGE);
    }

    public function delete(User $user, User $target): bool
    {
        // Deleting yourself would lock you out mid-session.
        if ($user->id === $target->id) {
            return false;
        }

        if ($target->hasRole('super-admin')) {
            return false;
        }

        return $user->can(Permissions::USERS_MANAGE);
    }
}
