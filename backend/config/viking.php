<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Money
    |---------------------------------------------------------------------------
    | The Iraqi dinar has no minor unit in practice, so `decimals` is 0 by
    | default and totals are rounded to whole dinars on the receipt.
    */

    'currency' => env('VIKING_CURRENCY', 'IQD'),
    'currency_decimals' => (int) env('VIKING_CURRENCY_DECIMALS', 0),

    /*
    |---------------------------------------------------------------------------
    | Order charges
    |---------------------------------------------------------------------------
    | Percentages applied to the discounted subtotal at checkout. Both can be
    | overridden per-deployment from the admin settings screen.
    */

    'tax_percent' => (float) env('VIKING_TAX_PERCENT', 0),
    'service_charge_percent' => (float) env('VIKING_SERVICE_CHARGE_PERCENT', 0),

    /*
    |---------------------------------------------------------------------------
    | Ordering rules
    |---------------------------------------------------------------------------
    */

    'allow_guest_orders' => filter_var(env('VIKING_ALLOW_GUEST_ORDERS', true), FILTER_VALIDATE_BOOL),

    // Maximum units of a single product allowed on one order line.
    'max_item_quantity' => 50,

    // Maximum distinct lines on a single order.
    'max_order_lines' => 60,

    // A table session with no activity for this long is considered abandoned.
    'table_session_ttl_minutes' => 240,

    /*
    |---------------------------------------------------------------------------
    | Kitchen display
    |---------------------------------------------------------------------------
    | Age thresholds in minutes that drive the amber/red ticket colouring.
    */

    'kitchen' => [
        'warning_after_minutes' => 10,
        'critical_after_minutes' => 20,
        'default_prep_minutes' => 10,
    ],

    /*
    |---------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |---------------------------------------------------------------------------
    */

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 120),
        'auth' => (int) env('RATE_LIMIT_AUTH', 10),
        'order' => (int) env('RATE_LIMIT_ORDER', 30),
    ],

    /*
    |---------------------------------------------------------------------------
    | Media
    |---------------------------------------------------------------------------
    | Conversions generated on upload. Each is a max-width in pixels; images are
    | re-encoded to WebP and never upscaled.
    */

    'media' => [
        'max_upload_kb' => 8192,
        'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        'conversions' => [
            'thumb' => 200,
            'small' => 480,
            'medium' => 800,
            'large' => 1600,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Frontend origins allowed to call the API and open websockets
    |---------------------------------------------------------------------------
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

];
