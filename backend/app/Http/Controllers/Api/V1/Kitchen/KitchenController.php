<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Kitchen;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kitchen display.
 *
 * Tickets arrive over websockets; these endpoints exist for the initial load
 * and for the state changes a cook makes by tapping a ticket. The board is
 * always scoped to one branch — a cook must never see another kitchen's work.
 */
class KitchenController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $status,
    ) {}

    /**
     * The full board, grouped into the lanes the display renders.
     */
    public function board(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request);

        $orders = Order::query()
            ->forKitchen()
            ->forBranch($branchId)
            ->with(['items.options', 'table', 'branch'])
            ->limit(200)
            ->get();

        $lanes = [
            'incoming' => [],
            'preparing' => [],
            'ready' => [],
        ];

        foreach ($orders as $order) {
            $lane = match ($order->status) {
                OrderStatus::Pending, OrderStatus::Confirmed => 'incoming',
                OrderStatus::Preparing => 'preparing',
                OrderStatus::Ready => 'ready',
                default => null,
            };

            if ($lane) {
                $lanes[$lane][] = (new OrderResource($order))->resolve();
            }
        }

        return response()->json([
            'data' => $lanes,
            'meta' => [
                'branch_id' => $branchId,
                'counts' => array_map('count', $lanes),
                'thresholds' => [
                    'warning_minutes' => (int) config('viking.kitchen.warning_after_minutes'),
                    'critical_minutes' => (int) config('viking.kitchen.critical_after_minutes'),
                ],
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Moves a ticket one lane forward. */
    public function advance(Request $request, Order $order): JsonResponse
    {
        $this->authorize('advance', $order);

        $order = $this->status->advance($order, $request->user());

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
        ]);
    }

    /** Sets an explicit status, for the rare out-of-order correction. */
    public function transition(Request $request, Order $order): JsonResponse
    {
        $this->authorize('advance', $order);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::values())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->status->transition(
            $order,
            OrderStatus::from($validated['status']),
            $request->user(),
            $validated['note'] ?? null,
        );

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }

    /**
     * Marks a single line ready, so a cook can clear the parts of a large
     * ticket that are done without waiting for the whole thing.
     */
    public function updateItem(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        $this->authorize('advance', $order);

        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', OrderItemStatus::values())],
        ]);

        $item->forceFill(['status' => $validated['status']])->save();

        return response()->json([
            'data' => (new OrderResource($order->fresh(['items.options', 'table', 'branch'])))->resolve(),
        ]);
    }

    /**
     * Which branch this display is showing. Staff are pinned to their own;
     * only an admin may pass `?branch_id=` to look at another.
     */
    private function resolveBranchId(Request $request): ?int
    {
        $user = $request->user();
        $requested = $request->integer('branch_id') ?: null;

        if ($requested && $user->canAccessBranch($requested)) {
            return $requested;
        }

        return $user->branch_id;
    }
}
