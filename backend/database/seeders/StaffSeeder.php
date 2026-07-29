<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates one account per role so every screen can be demonstrated
 * immediately after a fresh install.
 *
 * Passwords come from the environment when provided and otherwise fall back to
 * a documented development default. `VIKING_SEED_PASSWORD` must be set in any
 * environment that is reachable from the internet.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // Quote the value in .env if it contains a '#'. Dotenv treats an
        // unquoted '#' as the start of a comment, so VIKING_SEED_PASSWORD=Pa#1
        // silently seeds the password "Pa" and every documented login fails.
        $password = (string) env('VIKING_SEED_PASSWORD', 'Viking#2026');

        if ($password === '') {
            throw new \RuntimeException(
                'VIKING_SEED_PASSWORD resolved to an empty string. Check for an unquoted "#" in .env.'
            );
        }
        $downtown = Branch::where('slug', 'downtown')->first();
        $riverside = Branch::where('slug', 'riverside')->first();

        $staff = [
            ['Owner', 'owner@viking.example', '+9647700000001', 'super-admin', null],
            ['Site Admin', 'admin@viking.example', '+9647700000002', 'admin', null],
            ['Downtown Manager', 'manager@viking.example', '+9647700000003', 'manager', $downtown?->id],
            ['Downtown Cashier', 'cashier@viking.example', '+9647700000004', 'cashier', $downtown?->id],
            ['Downtown Kitchen', 'kitchen@viking.example', '+9647700000005', 'kitchen', $downtown?->id],
            ['Downtown Waiter', 'waiter@viking.example', '+9647700000006', 'waiter', $downtown?->id],
            ['Riverside Manager', 'riverside@viking.example', '+9647700000007', 'manager', $riverside?->id],
        ];

        foreach ($staff as [$name, $email, $phone, $role, $branchId]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone' => $phone,
                    'password' => Hash::make($password),
                    'branch_id' => $branchId,
                    'locale' => 'en',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);
        }

        $customer = User::updateOrCreate(
            ['email' => 'customer@viking.example'],
            [
                'name' => 'Demo Customer',
                'phone' => '+9647701234567',
                'password' => Hash::make($password),
                'locale' => 'ar',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $customer->syncRoles(['customer']);
    }
}
