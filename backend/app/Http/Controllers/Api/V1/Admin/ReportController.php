<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporting.
 *
 * Every report takes the same `from`/`to`/`branch_id` window and returns rows
 * plus a totals block, so the frontend renders them all through one table
 * component and the CSV export works for any of them.
 */
class ReportController extends Controller
{
    private const REPORTS = ['sales', 'products', 'categories', 'payments', 'staff', 'hours'];

    public function show(Request $request, string $report): JsonResponse
    {
        $this->authorizePermission(Permissions::REPORTS_VIEW);

        abort_unless(in_array($report, self::REPORTS, true), 404, 'Unknown report.');

        [$from, $to, $branchId] = $this->window($request);

        return response()->json([
            'data' => $this->build($report, $from, $to, $branchId),
            'meta' => [
                'report' => $report,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch_id' => $branchId,
                'currency' => config('viking.currency'),
            ],
        ]);
    }

    /** Streams the same rows as CSV, so a large window never buffers in memory. */
    public function export(Request $request, string $report): StreamedResponse
    {
        $this->authorizePermission(Permissions::REPORTS_EXPORT);

        abort_unless(in_array($report, self::REPORTS, true), 404, 'Unknown report.');

        [$from, $to, $branchId] = $this->window($request);
        $payload = $this->build($report, $from, $to, $branchId);
        $rows = $payload['rows'];

        $filename = sprintf('viking-%s-%s-to-%s.csv', $report, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens Arabic column values correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            if ($rows !== []) {
                fputcsv($handle, array_keys($rows[0]));

                foreach ($rows as $row) {
                    fputcsv($handle, array_map(
                        fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value),
                        $row
                    ));
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function build(string $report, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return match ($report) {
            'sales' => $this->sales($from, $to, $branchId),
            'products' => $this->products($from, $to, $branchId),
            'categories' => $this->categories($from, $to, $branchId),
            'payments' => $this->payments($from, $to, $branchId),
            'staff' => $this->staff($from, $to, $branchId),
            'hours' => $this->hours($from, $to, $branchId),
        };
    }

    /** @return array<string, mixed> */
    private function sales(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = Order::query()
            ->forBranch($branchId)
            ->whereBetween('placed_at', [$from, $to])
            ->whereNot('status', OrderStatus::Cancelled)
            ->selectRaw($this->dateExpr('placed_at').' as date')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount_total + manual_discount_total), 0) as discounts')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(service_charge), 0) as service_charge')
            ->selectRaw('COALESCE(SUM(delivery_fee), 0) as delivery')
            ->selectRaw('COALESCE(SUM(refunded_total), 0) as refunds')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'orders' => (int) $row->orders,
                'subtotal' => round((float) $row->subtotal, 2),
                'discounts' => round((float) $row->discounts, 2),
                'tax' => round((float) $row->tax, 2),
                'service_charge' => round((float) $row->service_charge, 2),
                'delivery' => round((float) $row->delivery, 2),
                'refunds' => round((float) $row->refunds, 2),
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['date'])];
    }

    /** @return array<string, mixed> */
    private function products(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->whereBetween('orders.placed_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.product_sku', 'order_items.product_name_en', 'order_items.product_name_ar')
            ->orderByDesc(DB::raw('SUM(order_items.line_total)'))
            ->get([
                'order_items.product_sku as sku',
                'order_items.product_name_en as name_en',
                'order_items.product_name_ar as name_ar',
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('COUNT(DISTINCT order_items.order_id) as orders'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            ])
            ->map(fn ($row) => [
                'sku' => $row->sku,
                'name_en' => $row->name_en,
                'name_ar' => $row->name_ar,
                'units' => (int) $row->units,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['sku', 'name_en', 'name_ar'])];
    }

    /** @return array<string, mixed> */
    private function categories(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->whereBetween('orders.placed_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('categories.id', 'categories.name_en', 'categories.name_ar')
            ->orderByDesc(DB::raw('SUM(order_items.line_total)'))
            ->get([
                'categories.name_en',
                'categories.name_ar',
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            ])
            ->map(fn ($row) => [
                'name_en' => $row->name_en,
                'name_ar' => $row->name_ar,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['name_en', 'name_ar'])];
    }

    /** @return array<string, mixed> */
    private function payments(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->whereBetween('payments.processed_at', [$from, $to])
            ->where('payments.status', 'captured')
            ->groupBy('payments.method')
            ->get([
                'payments.method',
                DB::raw('COUNT(*) as transactions'),
                DB::raw('SUM(payments.amount) as amount'),
            ])
            ->map(fn ($row) => [
                'method' => $row->method,
                'transactions' => (int) $row->transactions,
                'amount' => round((float) $row->amount, 2),
            ])
            ->all();

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['method'])];
    }

    /**
     * Per-cashier takings and per-cook throughput, which is what shift reviews
     * are built on.
     *
     * @return array<string, mixed>
     */
    private function staff(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.cashier_id')
            ->when($branchId, fn ($q) => $q->where('orders.branch_id', $branchId))
            ->whereBetween('orders.placed_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('orders.cashier_id')
            ->groupBy('orders.cashier_id', 'users.name')
            ->orderByDesc(DB::raw('SUM(orders.grand_total)'))
            ->get([
                'users.name as staff',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(orders.grand_total) as revenue'),
                DB::raw('SUM(orders.refunded_total) as refunds'),
            ])
            ->map(fn ($row) => [
                'staff' => $row->staff ?? 'Unassigned',
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'refunds' => round((float) $row->refunds, 2),
            ])
            ->all();

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['staff'])];
    }

    /** @return array<string, mixed> */
    private function hours(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $raw = Order::query()
            ->forBranch($branchId)
            ->whereBetween('placed_at', [$from, $to])
            ->whereNot('status', OrderStatus::Cancelled)
            ->selectRaw($this->hourExpr('placed_at').' as hour')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as revenue')
            ->groupBy('hour')
            ->get()
            ->keyBy(fn ($row) => (int) $row->hour);

        $rows = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $row = $raw->get($hour);

            $rows[] = [
                'hour' => sprintf('%02d:00', $hour),
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
            ];
        }

        return ['rows' => $rows, 'totals' => $this->sumColumns($rows, ['hour'])];
    }

    /**
     * Sums every numeric column so each report gets a footer row for free.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $skip
     * @return array<string, float|int>
     */
    private function sumColumns(array $rows, array $skip = []): array
    {
        if ($rows === []) {
            return [];
        }

        $totals = [];

        foreach (array_keys($rows[0]) as $column) {
            if (in_array($column, $skip, true)) {
                continue;
            }

            $sum = array_sum(array_column($rows, $column));
            $totals[$column] = is_float($sum) ? round($sum, 2) : $sum;
        }

        return $totals;
    }

    /** @return array{0: Carbon, 1: Carbon, 2: int|null} */
    private function window(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::today()->endOfDay();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        $user = $request->user();
        $requested = $validated['branch_id'] ?? null;

        $branchId = $requested && $user->canAccessBranch($requested)
            ? $requested
            : ($user->hasAnyRole(['super-admin', 'admin']) ? null : $user->branch_id);

        return [$from, $to, $branchId];
    }

    private function dateExpr(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date({$column})" : "DATE({$column})";
    }

    private function hourExpr(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }
}
