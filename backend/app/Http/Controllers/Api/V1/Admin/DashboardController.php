<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The admin landing screen: today's numbers, a fortnight of trend and the
 * live queue, in a single request.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        return response()->json([
            'data' => [
                'today' => $this->periodStats($branchId, $today, $today->copy()->endOfDay()),
                'yesterday' => $this->periodStats($branchId, $yesterday, $yesterday->copy()->endOfDay()),
                'month' => $this->periodStats($branchId, $today->copy()->startOfMonth(), $today->copy()->endOfDay()),
                'trend' => $this->dailyTrend($branchId, 14),
                'status_breakdown' => $this->statusBreakdown($branchId),
                'top_products' => $this->topProducts($branchId, 8),
                'hourly' => $this->hourlyToday($branchId),
                'live_orders' => OrderResource::collection(
                    Order::query()
                        ->forBranch($branchId)
                        ->active()
                        ->with(['table', 'branch'])
                        ->withCount('items')
                        ->orderBy('placed_at')
                        ->limit(12)
                        ->get()
                )->resolve(),
            ],
            'meta' => ['branch_id' => $branchId, 'generated_at' => now()->toIso8601String()],
        ]);
    }

    /** @return array<string, mixed> */
    private function periodStats(?int $branchId, Carbon $from, Carbon $to): array
    {
        $base = Order::query()
            ->forBranch($branchId)
            ->whereBetween('placed_at', [$from, $to]);

        $completed = (clone $base)->whereNot('status', OrderStatus::Cancelled);

        $revenue = (float) (clone $completed)->sum('grand_total');
        $orders = (clone $completed)->count();

        return [
            'revenue' => round($revenue, 2),
            'orders' => $orders,
            'average_order_value' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'cancelled' => (clone $base)->where('status', OrderStatus::Cancelled)->count(),
            'refunded' => round((float) (clone $base)->sum('refunded_total'), 2),
            'guests' => (int) (clone $completed)->sum('guest_count'),
        ];
    }

    /**
     * Revenue and order count per day. Grouped in SQL with a portable date
     * expression so it works on both MySQL and the SQLite test database.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailyTrend(?int $branchId, int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $rows = Order::query()
            ->forBranch($branchId)
            ->whereNot('status', OrderStatus::Cancelled)
            ->where('placed_at', '>=', $from)
            ->selectRaw($this->dateExpression('placed_at').' as day')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Fill the gaps so the chart has a point for every day, not just the
        // busy ones.
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $row = $rows->get($date);

            $series[] = [
                'date' => $date,
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
            ];
        }

        return $series;
    }

    /** @return array<string, int> */
    private function statusBreakdown(?int $branchId): array
    {
        $counts = Order::query()
            ->forBranch($branchId)
            ->whereDate('placed_at', Carbon::today())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $breakdown = [];

        foreach (OrderStatus::cases() as $status) {
            $breakdown[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $breakdown;
    }

    /** @return array<int, array<string, mixed>> */
    private function topProducts(?int $branchId, int $limit): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->where('orders.placed_at', '>=', Carbon::today()->subDays(30))
            ->groupBy('order_items.product_id', 'order_items.product_name_en', 'order_items.product_name_ar')
            ->orderByDesc(DB::raw('SUM(order_items.quantity)'))
            ->limit($limit)
            ->get([
                'order_items.product_id',
                'order_items.product_name_en',
                'order_items.product_name_ar',
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            ])
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'name' => app()->getLocale() === 'ar' ? $row->product_name_ar : $row->product_name_en,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Orders per hour today, which is what staffing decisions are made from.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hourlyToday(?int $branchId): array
    {
        $rows = Order::query()
            ->forBranch($branchId)
            ->whereDate('placed_at', Carbon::today())
            ->whereNot('status', OrderStatus::Cancelled)
            ->selectRaw($this->hourExpression('placed_at').' as hour')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as revenue')
            ->groupBy('hour')
            ->pluck('orders', 'hour');

        $series = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $series[] = ['hour' => $hour, 'orders' => (int) ($rows[$key] ?? $rows[$hour] ?? 0)];
        }

        return $series;
    }

    /** Portable `DATE(column)` across MySQL and SQLite. */
    private function dateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column})"
            : "DATE({$column})";
    }

    private function hourExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%H', {$column})"
            : "LPAD(HOUR({$column}), 2, '0')";
    }

    private function resolveBranchId(Request $request): ?int
    {
        $user = $request->user();
        $requested = $request->integer('branch_id') ?: null;

        if ($requested && $user->canAccessBranch($requested)) {
            return $requested;
        }

        // Admins see the whole company when they do not pick a branch.
        return $user->hasAnyRole(['super-admin', 'admin']) ? null : $user->branch_id;
    }
}
