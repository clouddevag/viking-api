<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Api\V1\Concerns\IdentifiesGuests;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableSession;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use App\Services\Orders\OrderStatusService;
use App\Services\Tables\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer-facing ordering: checkout, history and tracking.
 *
 * Every read is scoped to the caller's own orders — by account when signed in,
 * by device token when not — so no ownership check is ever skipped.
 */
class OrderController extends Controller
{
    use HandlesApiQueries;
    use IdentifiesGuests;

    public function __construct(
        private readonly CartPricingService $pricing,
        private readonly OrderCreationService $creator,
        private readonly OrderStatusService $status,
        private readonly TableSessionService $sessions,
    ) {}

    /**
     * Places an order.
     *
     * Prices are recomputed here from scratch rather than taken from the
     * request, so a tampered client cannot dictate what it pays.
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user && ! config('viking.allow_guest_orders')) {
            return response()->json([
                'message' => 'Please sign in to place an order.',
            ], 401);
        }

        $branch = Branch::query()->active()->findOrFail($request->validated('branch_id'));
        $type = $request->orderType();
        $guestToken = $user ? null : $this->resolveGuestToken($request);

        [$table, $session] = $this->resolveTableContext($request, $type, $user);

        if ($type === OrderType::DineIn && ! $table) {
            return response()->json([
                'message' => 'Scan the QR code on your table to order.',
                'error' => 'table_required',
            ], 422);
        }

        if ($type === OrderType::Delivery && ! $branch->accepts_delivery) {
            return response()->json([
                'message' => 'This branch does not deliver.',
                'error' => 'delivery_unavailable',
            ], 422);
        }

        $cart = $this->pricing->price(
            inputs: $request->lines(),
            branch: $branch,
            type: $type,
            couponCode: $request->validated('coupon_code'),
            userId: $user?->id,
            guestToken: $guestToken,
        );

        if ($cart->grandTotal < (float) $branch->minimum_order && $type === OrderType::Delivery) {
            return response()->json([
                'message' => 'Your order is below this branch\'s delivery minimum.',
                'error' => 'below_minimum',
                'context' => ['minimum' => (float) $branch->minimum_order],
            ], 422);
        }

        $order = $this->creator->create(
            cart: $cart,
            branch: $branch,
            type: $type,
            attributes: $request->orderAttributes(),
            user: $user,
            table: $table,
            session: $session,
            guestToken: $guestToken,
        );

        $order->load(['items.options', 'branch', 'table', 'statusEvents']);

        return response()->json([
            'data' => (new OrderResource($order))->resolve(),
            'guest_token' => $guestToken,
            'message' => 'Order placed.',
        ], 201);
    }

    /** The caller's own order history, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::query()
            ->ownedBy($request->user()?->id, $this->guestToken($request))
            ->with(['branch', 'table'])
            ->withCount('items');

        $this->applyFilters($query, $request, ['status', 'type', 'branch_id']);
        $this->applySorting($query, $request, ['created_at', 'placed_at', 'grand_total'], '-placed_at');

        return OrderResource::collection(
            $query->paginate($this->perPage($request, 15, 50))->withQueryString()
        );
    }

    /** A single order with its full tracking timeline. */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->ownedBy($request->user()?->id, $this->guestToken($request))
            ->with(['items.options', 'branch', 'table', 'statusEvents'])
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => (new OrderResource($order))->resolve()]);
    }

    /**
     * Cancels an order the customer placed.
     *
     * Only allowed while the kitchen has not started cooking — after that the
     * food exists and a member of staff has to make the call.
     */
    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::query()
            ->ownedBy($request->user()?->id, $this->guestToken($request))
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
            return response()->json([
                'message' => 'This order is already being prepared. Please ask a member of staff.',
                'error' => 'too_late_to_cancel',
            ], 409);
        }

        $order = $this->status->cancel(
            $order,
            $validated['reason'] ?? 'Cancelled by customer',
            $request->user(),
        );

        return response()->json([
            'data' => (new OrderResource($order->load(['items.options', 'branch', 'table'])))->resolve(),
            'message' => 'Order cancelled.',
        ]);
    }

    /**
     * Reorders a past order by returning its lines as a fresh cart payload.
     * Items that have since been removed or sold out are reported back rather
     * than silently dropped.
     */
    public function reorder(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->ownedBy($request->user()?->id, $this->guestToken($request))
            ->with(['items.options'])
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $items = [];
        $unavailable = [];

        foreach ($order->items as $item) {
            $product = $item->product()->with('image')->first();

            if (! $product || ! $product->is_active || ! $product->is_available) {
                $unavailable[] = $item->product_name;

                continue;
            }

            $items[] = [
                'product_id' => $product->id,
                'quantity' => $item->quantity,
                'special_instructions' => $item->special_instructions,
                'options' => $item->options
                    ->filter(fn ($option) => $option->option_id !== null)
                    ->map(fn ($option) => [
                        'option_id' => $option->option_id,
                        'quantity' => $option->quantity,
                    ])->values()->all(),
            ];
        }

        return response()->json([
            'data' => [
                'branch_id' => $order->branch_id,
                'type' => $order->type->value,
                'items' => $items,
            ],
            'unavailable' => $unavailable,
        ]);
    }

    /**
     * Works out which table and session an order belongs to.
     *
     * The session token is preferred because it survives a page reload; the
     * raw table token is the fallback for a first scan.
     *
     * @return array{0: DiningTable|null, 1: TableSession|null}
     */
    private function resolveTableContext(PlaceOrderRequest $request, OrderType $type, $user): array
    {
        if ($type !== OrderType::DineIn) {
            return [null, null];
        }

        if ($sessionToken = $request->validated('table_session_token')) {
            $session = $this->sessions->findByToken($sessionToken);

            if ($session?->isOpen()) {
                $session->touchActivity();

                return [$session->table, $session];
            }
        }

        if ($tableToken = $request->validated('table_token')) {
            $session = $this->sessions->openFromToken(
                token: $tableToken,
                user: $user,
                guestName: $request->validated('customer_name'),
                guestPhone: $request->validated('customer_phone'),
                partySize: $request->validated('guest_count'),
            );

            return [$session->table, $session];
        }

        return [null, null];
    }
}
