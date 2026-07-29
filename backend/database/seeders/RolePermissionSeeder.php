<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rebuilds the permission catalogue and role grants from
 * {@see Permissions}. Idempotent: safe to re-run after adding a permission,
 * which is how new capabilities reach existing deployments.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Permissions::roles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        // Granted everything via a Gate::before hook, so it deliberately holds
        // no explicit permissions of its own.
        Role::findOrCreate('super-admin', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
