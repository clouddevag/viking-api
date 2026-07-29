<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Produces human-readable, per-branch-per-day sequential order numbers such as
 * `VK-DTN-260729-0042`. Staff read these aloud, so they must be short and
 * unambiguous rather than a UUID.
 */
class OrderNumberGenerator
{
    public function generate(int $branchId, string $branchSlug): string
    {
        $prefix = sprintf(
            'VK-%s-%s',
            strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $branchSlug) ?: 'BR', 0, 3)),
            now()->format('ymd')
        );

        // A short retry loop is cheaper than a dedicated counter table and the
        // unique index on `order_number` is the real guarantee.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sequence = $this->nextSequence($branchId, $prefix);
            $candidate = sprintf('%s-%04d', $prefix, $sequence);

            if (! Order::withTrashed()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Fall back to a time-based suffix rather than failing the checkout.
        return sprintf('%s-%s', $prefix, strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));
    }

    private function nextSequence(int $branchId, string $prefix): int
    {
        $count = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_number', 'like', $prefix.'%')
            ->count();

        return $count + 1;
    }
}
