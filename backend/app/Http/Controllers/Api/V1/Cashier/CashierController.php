<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cashier;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiningTableResource;
use App\Http\Resources\OrderResource;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use App\Services\Tables\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The till.
 *
 * Handles settlement, refunds, manager discounts and order lookup. Money
 * movement itself is delegated to {@see PaymentService} so the ledger rules
 * stay in one place.
 */
class CashierController extends Controller
{
    use HandlesApiQueries;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly TableSessionService $sessions,
    ) {}

    /** Orders awaiting payment or still open, for the live till list. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $query = Order::query()
            ->forBranch($branchId)
            ->with(['items.options', 'table', 'branch', 'payments'])
            ->withCount('items')
            ->when(
                $request->boolean('unpaid_only', true),
                fn ($q) => $q->where('payment_status', PaymentStatus::Unpaid)
                    ->whereNot('status', OrderStatus::Cancelled)
            );

        $this->applyFilters($query, $request, ['status', 'payment_status', 'type', 'dining_table_id']);
        $this->applySorting($query, $request, ['placed_at', 'grand_total', 'order_number'], '-placed_at');

        return OrderResource::collection(
            $query->paginate($this->perPage($request, 25, 100))->withQueryString()
        );
    }

    /** Order lookup by number, for "the guest at table 4 wants to pay". */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['items.options', 'table', 'branch', 'payments', 'refunds', 'statusEvents'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        $this->authorize('view', $order);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'meta' => [
                'paid_amount' => $order->paidAmount(),
                'outstanding' => $order->outstandingAmount(),
            ],
        ]);
    }

    /** Captures a payment. */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->authorize('pay', $order);

        $validated = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);

        $payment = $this->payments->capture(
            order: $order,
            method: PaymentMethod::from($validated['method']),
            amount: (float) $validated['amount'],
            cashier: $request->user(),
            tendered: isset($validated['tendered_amount']) ? (float) $validated['tendered_amount'] : null,
            reference: $validated['reference'] ?? null,
        );

        $order->refresh()->load(['items.options', 'table', 'branch', 'payments']);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'meta' => [
                'change_due' => (float) $payment->change_amount,
                'outstanding' => $order->outstandingAmount(),
            ],
            'message' => 'Payment recorded.',
        ], 201);
    }

    public function refund(Request $request, Order $order): JsonResponse
    {
        $this->authorize('refund', $order);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->payments->refund(
            order: $order,
            amount: (float) $validated['amount'],
            reason: $validated['reason'],
            actor: $request->user(),
        );

        $order->refresh()->load(['items.options', 'table', 'branch', 'payments', 'refunds']);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'message' => 'Refund issued.',
        ]);
    }

    public function discount(Request $request, Order $order): JsonResponse
    {
        $this->authorize('discount', $order);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->payments->applyManualDiscount(
            order: $order,
            amount: (float) $validated['amount'],
            reason: $validated['reason'],
            actor: $request->user(),
        );

        $order->load(['items.options', 'table', 'branch']);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'message' => 'Discount applied.',
        ]);
    }

    /** The floor plan, with each table's live session and running total. */
    public function tables(Request $request): AnonymousResourceCollection
    {
        $branchId = $this->resolveBranchId($request);

        $tables = DiningTable::query()
            ->active()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->with(['activeSession', 'branch'])
            ->withCount(['orders' => fn ($query) => $query->active()])
            ->orderBy('sort_order')
            ->get();

        return DiningTableResource::collection($tables);
    }

    /**
     * Closes a table's dining session once the bill is settled, which frees
     * the table on the floor plan.
     */
    public function closeTable(Request $request, DiningTable $table): JsonResponse
    {
        abort_unless($request->user()->canAccessBranch($table->branch_id), 403);

        $session = $table->activeSession;

        if (! $session) {
            return response()->json(['message' => 'This table has no open session.'], 422);
        }

        $unpaid = Order::query()
            ->where('table_session_id', $session->id)
            ->where('payment_status', PaymentStatus::Unpaid)
            ->whereNot('status', OrderStatus::Cancelled)
            ->count();

        if ($unpaid > 0 && ! $request->boolean('force')) {
            return response()->json([
                'message' => "This table still has {$unpaid} unpaid order(s).",
                'error' => 'unpaid_orders',
                'context' => ['unpaid_orders' => $unpaid],
            ], 422);
        }

        $this->sessions->close($session, $request->user());

        return response()->json(['message' => 'Table closed.']);
    }

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
