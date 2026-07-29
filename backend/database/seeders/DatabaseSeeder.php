<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Order matters: roles must exist before staff can be assigned them, and the
 * menu must exist before offers and coupons can reference products.
 *
 * Every seeder here is idempotent, so `db:seed` can be re-run on an existing
 * database to pick up new permissions or menu items without duplication.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            BranchSeeder::class,
            StaffSeeder::class,
            MenuSeeder::class,
            MarketingSeeder::class,
        ]);

        // Sample traffic for the dashboards. Never in production, where real
        // orders are the only orders.
        if (app()->environment(['local', 'testing', 'development'])) {
            $this->call(DemoOrderSeeder::class);
        }
    }
}
