<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    use HandlesApiQueries;

    public function __construct(
        private readonly OrderStatusService $status,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::query()
            ->forBranch($this->resolveBranchId($request))
            ->with(['branch', 'table', 'user:id,name'])
            ->withCount('items')
            ->when($request->query('search'), function (Builder $query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn (Builder $q) => $q
                    ->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like));
            });

        $this->applyFilters($query, $request, [
            'status', 'payment_status', 'type', 'branch_id', 'dining_table_id', 'user_id',
        ]);
        $this->applyDateRange($query, $request, 'placed_at');
        $this->applySorting(
            $query,
            $request,
            ['placed_at', 'grand_total', 'order_number', 'created_at'],
            '-placed_at'
        );

        return OrderResource::collection(
            $query->paginate($this->perPage($request, 25, 100))->withQueryString()
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'items.options', 'branch', 'table', 'user:id,name,phone',
            'payments.processor:id,name', 'refunds.processor:id,name',
            'statusEvents.user:id,name', 'coupon',
        ]);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'meta' => [
                'paid_amount' => $order->paidAmount(),
                'outstanding' => $order->outstandingAmount(),
            ],
        ]);
    }

    public function transition(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::values())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $target = OrderStatus::from($validated['status']);

        if ($target === OrderStatus::Cancelled) {
            $this->authorize('cancel', $order);
        }

        $order = $this->status->transition($order, $target, $request->user(), $validated['note'] ?? null);

        return response()->json([
            'data' => (new OrderResource($order->load(['items.options', 'branch', 'table'])))->resolve(),
            'message' => 'Order updated.',
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->status->cancel($order, $validated['reason'], $request->user());

        return response()->json([
            'data' => (new OrderResource($order->load(['items.options', 'branch', 'table'])))->resolve(),
            'message' => 'Order cancelled.',
        ]);
    }

    private function resolveBranchId(Request $request): ?int
    {
        $user = $request->user();
        $requested = $request->integer('branch_id') ?: null;

        if ($requested && $user->canAccessBranch($requested)) {
            return $requested;
        }

        return $user->hasAnyRole(['super-admin', 'admin']) ? null : $user->branch_id;
    }
}
