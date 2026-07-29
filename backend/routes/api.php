<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Cashier;
use App\Http\Controllers\Api\V1\Customer;
use App\Http\Controllers\Api\V1\Kitchen\KitchenController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Public as PublicApi;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API v1
|------------------------------------------------------------------------------
| Versioned under /api/v1 so a future v2 can ship alongside rather than break
| installed PWAs and tablet apps in the field.
|
| Route names are prefixed `api.v1.` and the admin group additionally with
| `admin.`, which is what the API resources check to decide whether to expose
| back-office fields such as cost price and QR tokens.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public — no authentication
    |--------------------------------------------------------------------------
    | A guest scanning a table QR must reach the menu with zero round trips
    | spent on auth.
    */
    Route::middleware('throttle:api')->group(function () {
        Route::get('bootstrap', PublicApi\BootstrapController::class)->name('bootstrap');

        Route::get('categories', [PublicApi\MenuController::class, 'categories'])->name('categories');
        Route::get('products', [PublicApi\MenuController::class, 'products'])->name('products.index');
        Route::get('products/search', [PublicApi\MenuController::class, 'search'])->name('products.search');
        Route::get('products/highlights', [PublicApi\MenuController::class, 'highlights'])->name('products.highlights');
        Route::get('products/{slug}', [PublicApi\MenuController::class, 'product'])->name('products.show');

        Route::get('offers', [PublicApi\OfferController::class, 'index'])->name('offers.index');
        Route::get('offers/{slug}', [PublicApi\OfferController::class, 'show'])->name('offers.show');

        Route::get('products/{product}/reviews', [Customer\ReviewController::class, 'index'])
            ->name('reviews.index');

        // QR table ordering.
        Route::post('tables/scan/{token}', [PublicApi\TableController::class, 'scan'])
            ->middleware('throttle:order')
            ->name('tables.scan');
        Route::get('tables/session/{sessionToken}', [PublicApi\TableController::class, 'session'])
            ->name('tables.session');

        Route::post('cart/price', [PublicApi\CartController::class, 'price'])->name('cart.price');
    });

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | Tightly throttled — these are the endpoints worth brute forcing.
    */
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    Route::prefix('auth')->name('auth.')->middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('profile', [AuthController::class, 'updateProfile'])->name('profile');
        Route::post('password', [AuthController::class, 'changePassword'])->name('password');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    });

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    | Guests may place and track orders without an account; `auth:sanctum` is
    | optional here and ownership falls back to the device token.
    */
    Route::middleware('guest.or.auth')->group(function () {
        Route::post('orders', [Customer\OrderController::class, 'store'])
            ->middleware('throttle:order')
            ->name('orders.store');
        Route::get('orders', [Customer\OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{orderNumber}', [Customer\OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{orderNumber}/cancel', [Customer\OrderController::class, 'cancel'])->name('orders.cancel');
        Route::get('orders/{orderNumber}/reorder', [Customer\OrderController::class, 'reorder'])->name('orders.reorder');
    });

    /*
    |--------------------------------------------------------------------------
    | Customer account
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('favorites', [Customer\FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('favorites/{product}', [Customer\FavoriteController::class, 'toggle'])->name('favorites.toggle');
        Route::post('favorites/sync', [Customer\FavoriteController::class, 'sync'])->name('favorites.sync');

        Route::apiResource('addresses', Customer\AddressController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::post('products/{product}/reviews', [Customer\ReviewController::class, 'store'])
            ->name('reviews.store');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    });

    /*
    |--------------------------------------------------------------------------
    | Kitchen display
    |--------------------------------------------------------------------------
    */
    Route::prefix('kitchen')->name('kitchen.')
        ->middleware(['auth:sanctum', 'active', 'permission:'.Permissions::ORDERS_KITCHEN])
        ->group(function () {
            Route::get('board', [KitchenController::class, 'board'])->name('board');
            Route::post('orders/{order}/advance', [KitchenController::class, 'advance'])->name('advance');
            Route::post('orders/{order}/status', [KitchenController::class, 'transition'])->name('status');
            Route::patch('orders/{order}/items/{item}', [KitchenController::class, 'updateItem'])->name('items.update');
        });

    /*
    |--------------------------------------------------------------------------
    | Cashier / POS
    |--------------------------------------------------------------------------
    */
    Route::prefix('cashier')->name('cashier.')
        ->middleware(['auth:sanctum', 'active', 'permission:'.Permissions::ORDERS_CASHIER])
        ->group(function () {
            Route::get('orders', [Cashier\CashierController::class, 'index'])->name('orders.index');
            Route::get('orders/{orderNumber}', [Cashier\CashierController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/pay', [Cashier\CashierController::class, 'pay'])->name('orders.pay');
            Route::post('orders/{order}/refund', [Cashier\CashierController::class, 'refund'])->name('orders.refund');
            Route::post('orders/{order}/discount', [Cashier\CashierController::class, 'discount'])->name('orders.discount');

            Route::get('tables', [Cashier\CashierController::class, 'tables'])->name('tables');
            Route::post('tables/{table}/close', [Cashier\CashierController::class, 'closeTable'])->name('tables.close');

            Route::get('receipts/{orderNumber}', [Cashier\ReceiptController::class, 'show'])->name('receipt');
        });

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    | Named `api.v1.admin.*`, which is the signal API resources use to include
    | back-office-only fields.
    */
    Route::prefix('admin')->name('admin.')
        ->middleware(['auth:sanctum', 'active'])
        ->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)
                ->middleware('permission:'.Permissions::DASHBOARD_VIEW)
                ->name('dashboard');

            // Orders
            Route::get('orders', [Admin\OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [Admin\OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/status', [Admin\OrderController::class, 'transition'])->name('orders.status');
            Route::post('orders/{order}/cancel', [Admin\OrderController::class, 'cancel'])->name('orders.cancel');

            // Catalog
            Route::post('products/reorder', [Admin\ProductController::class, 'reorder'])->name('products.reorder');
            Route::post('products/{product}/availability', [Admin\ProductController::class, 'toggleAvailability'])
                ->name('products.availability');
            Route::post('products/{id}/restore', [Admin\ProductController::class, 'restore'])->name('products.restore');
            Route::apiResource('products', Admin\ProductController::class);

            Route::post('categories/reorder', [Admin\CategoryController::class, 'reorder'])->name('categories.reorder');
            Route::apiResource('categories', Admin\CategoryController::class);

            Route::apiResource('option-groups', Admin\OptionGroupController::class);

            // Tables and QR codes
            Route::post('tables/bulk', [Admin\TableController::class, 'bulkCreate'])->name('tables.bulk');
            Route::get('tables/qr-sheet', [Admin\TableController::class, 'qrSheet'])->name('tables.qr-sheet');
            Route::get('tables/{table}/qr', [Admin\TableController::class, 'qrCode'])->name('tables.qr');
            Route::post('tables/{table}/rotate-qr', [Admin\TableController::class, 'rotateQr'])->name('tables.rotate-qr');
            Route::apiResource('tables', Admin\TableController::class);

            // People
            Route::get('roles', [Admin\UserController::class, 'roles'])->name('roles.index');
            Route::post('roles', [Admin\UserController::class, 'createRole'])->name('roles.store');
            Route::patch('roles/{role}', [Admin\UserController::class, 'updateRole'])->name('roles.update');
            Route::apiResource('users', Admin\UserController::class);

            // Marketing
            Route::get('coupons/{coupon}/redemptions', [Admin\CouponController::class, 'redemptions'])
                ->name('coupons.redemptions');
            Route::apiResource('coupons', Admin\CouponController::class);
            Route::apiResource('offers', Admin\OfferController::class);

            // Media
            Route::post('media/bulk-delete', [Admin\MediaController::class, 'bulkDestroy'])->name('media.bulk-delete');
            Route::apiResource('media', Admin\MediaController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            // Settings and branches
            Route::get('settings', [Admin\SettingController::class, 'index'])->name('settings.index');
            Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');
            Route::get('branches', [Admin\SettingController::class, 'branches'])->name('branches.index');
            Route::post('branches', [Admin\SettingController::class, 'storeBranch'])->name('branches.store');
            Route::patch('branches/{branch}', [Admin\SettingController::class, 'updateBranch'])->name('branches.update');
            Route::delete('branches/{branch}', [Admin\SettingController::class, 'destroyBranch'])->name('branches.destroy');

            // Reporting and audit
            Route::get('reports/{report}', [Admin\ReportController::class, 'show'])->name('reports.show');
            Route::get('reports/{report}/export', [Admin\ReportController::class, 'export'])->name('reports.export');
            Route::get('activity', [Admin\ActivityController::class, 'index'])->name('activity.index');

            // Reviews
            Route::get('reviews', [Admin\ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews/{review}/approve', [Admin\ReviewController::class, 'approve'])->name('reviews.approve');
            Route::post('reviews/{review}/reject', [Admin\ReviewController::class, 'reject'])->name('reviews.reject');
            Route::delete('reviews/{review}', [Admin\ReviewController::class, 'destroy'])->name('reviews.destroy');
        });
});
