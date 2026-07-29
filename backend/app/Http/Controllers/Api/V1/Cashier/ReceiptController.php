<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cashier;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Tables\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receipt payloads.
 *
 * The server returns structured data rather than rendered HTML so the till can
 * lay it out for whatever it is printing to — an 80mm thermal roll, an A4
 * invoice or a PDF the guest gets emailed — without the backend knowing
 * anything about paper widths.
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly QrCodeService $qr,
    ) {}

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::query()
            ->with(['items.options', 'branch', 'table', 'payments', 'refunds', 'cashier'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        $this->authorize('view', $order);

        $locale = $request->query('locale', app()->getLocale()) === 'ar' ? 'ar' : 'en';
        $settings = Setting::map();

        return response()->json([
            'data' => [
                'header' => $settings["receipt_header_{$locale}"] ?? config('app.name'),
                'footer' => $settings["receipt_footer_{$locale}"] ?? null,
                'locale' => $locale,
                'direction' => $locale === 'ar' ? 'rtl' : 'ltr',

                'branch' => [
                    'name' => $order->branch->translate('name', $locale),
                    'address' => $order->branch->translate('address', $locale),
                    'phone' => $order->branch->phone,
                ],

                'order' => [
                    'number' => $order->order_number,
                    'type' => $order->type->label($locale),
                    'table' => $order->table?->displayName(),
                    'customer' => $order->customer_name,
                    'placed_at' => $order->placed_at?->toIso8601String(),
                    'cashier' => $order->cashier?->name,
                    'notes' => $order->notes,
                ],

                'lines' => $order->items->map(fn ($item) => [
                    'name' => $item->translate('product_name', $locale),
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                    'options' => $item->options->map(fn ($option) => [
                        'name' => $option->translate('option_name', $locale),
                        'price_delta' => (float) $option->price_delta,
                        'quantity' => $option->quantity,
                    ])->all(),
                    'instructions' => $item->special_instructions,
                ])->all(),

                'totals' => [
                    'subtotal' => (float) $order->subtotal,
                    'discount' => (float) $order->discount_total,
                    'manual_discount' => (float) $order->manual_discount_total,
                    'tax' => (float) $order->tax_total,
                    'service_charge' => (float) $order->service_charge,
                    'delivery_fee' => (float) $order->delivery_fee,
                    'grand_total' => (float) $order->grand_total,
                    'refunded' => (float) $order->refunded_total,
                    'currency' => $order->currency,
                ],

                'payments' => $order->payments->map(fn ($payment) => [
                    'method' => $payment->method->label($locale),
                    'amount' => (float) $payment->amount,
                    'tendered' => $payment->tendered_amount !== null ? (float) $payment->tendered_amount : null,
                    'change' => (float) $payment->change_amount,
                    'at' => $payment->processed_at?->toIso8601String(),
                ])->all(),

                // Lets the guest reopen their order on their phone from the
                // printed receipt.
                'qr_svg' => ($settings['receipt_show_qr'] ?? true)
                    ? $this->qr->svg(
                        rtrim((string) config('viking.frontend_url'), '/').'/orders/'.$order->order_number,
                        220
                    )
                    : null,
            ],
        ]);
    }
}
