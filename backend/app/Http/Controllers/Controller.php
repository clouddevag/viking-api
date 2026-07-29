<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Aborts unless the caller holds a permission.
     *
     * Used where authorization depends only on a capability and not on a
     * specific record, which is most of the admin CRUD — a full policy per
     * lookup table would be ceremony without benefit.
     */
    protected function authorizePermission(string ...$permissions): void
    {
        $user = request()->user();

        foreach ($permissions as $permission) {
            if ($user?->can($permission)) {
                return;
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
