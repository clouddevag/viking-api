<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The complete permission catalogue and the roles built from it.
 *
 * Declared in code rather than only in a seeder so the values can be
 * referenced from policies, middleware and tests, and so `db:seed` stays
 * idempotent as new permissions are added over time.
 */
final class Permissions
{
    // Dashboard & reporting
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    // Catalog
    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const CATEGORIES_MANAGE = 'categories.manage';

    // Orders
    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const ORDERS_CANCEL = 'orders.cancel';

    public const ORDERS_KITCHEN = 'orders.kitchen';

    public const ORDERS_CASHIER = 'orders.cashier';

    // Money
    public const PAYMENTS_CAPTURE = 'payments.capture';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const PAYMENTS_DISCOUNT = 'payments.discount';

    // Floor
    public const TABLES_VIEW = 'tables.view';

    public const TABLES_MANAGE = 'tables.manage';

    // Marketing
    public const COUPONS_MANAGE = 'coupons.manage';

    public const OFFERS_MANAGE = 'offers.manage';

    // Administration
    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const MEDIA_MANAGE = 'media.manage';

    public const ACTIVITY_VIEW = 'activity.view';

    public const REVIEWS_MODERATE = 'reviews.moderate';

    /**
     * Every permission, grouped for the role editor UI.
     *
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        return [
            'dashboard' => [self::DASHBOARD_VIEW],
            'reports' => [self::REPORTS_VIEW, self::REPORTS_EXPORT],
            'catalog' => [self::PRODUCTS_VIEW, self::PRODUCTS_MANAGE, self::CATEGORIES_MANAGE],
            'orders' => [
                self::ORDERS_VIEW, self::ORDERS_MANAGE, self::ORDERS_CANCEL,
                self::ORDERS_KITCHEN, self::ORDERS_CASHIER,
            ],
            'payments' => [self::PAYMENTS_CAPTURE, self::PAYMENTS_REFUND, self::PAYMENTS_DISCOUNT],
            'tables' => [self::TABLES_VIEW, self::TABLES_MANAGE],
            'marketing' => [self::COUPONS_MANAGE, self::OFFERS_MANAGE],
            'administration' => [
                self::USERS_VIEW, self::USERS_MANAGE, self::ROLES_MANAGE,
                self::BRANCHES_MANAGE, self::SETTINGS_MANAGE, self::MEDIA_MANAGE,
                self::ACTIVITY_VIEW, self::REVIEWS_MODERATE,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_values(array_merge(...array_values(self::grouped())));
    }

    /**
     * Role definitions. `super-admin` is deliberately absent: it is granted
     * everything through a Gate::before hook rather than by enumeration, so a
     * newly added permission is never accidentally withheld from it.
     *
     * @return array<string, array<int, string>>
     */
    public static function roles(): array
    {
        return [
            'admin' => self::all(),

            'manager' => [
                self::DASHBOARD_VIEW, self::REPORTS_VIEW, self::REPORTS_EXPORT,
                self::PRODUCTS_VIEW, self::PRODUCTS_MANAGE, self::CATEGORIES_MANAGE,
                self::ORDERS_VIEW, self::ORDERS_MANAGE, self::ORDERS_CANCEL,
                self::ORDERS_KITCHEN, self::ORDERS_CASHIER,
                self::PAYMENTS_CAPTURE, self::PAYMENTS_REFUND, self::PAYMENTS_DISCOUNT,
                self::TABLES_VIEW, self::TABLES_MANAGE,
                self::COUPONS_MANAGE, self::OFFERS_MANAGE,
                self::USERS_VIEW, self::MEDIA_MANAGE, self::REVIEWS_MODERATE,
                self::ACTIVITY_VIEW,
            ],

            'cashier' => [
                self::DASHBOARD_VIEW,
                self::ORDERS_VIEW, self::ORDERS_MANAGE, self::ORDERS_CASHIER,
                self::PAYMENTS_CAPTURE, self::PAYMENTS_DISCOUNT,
                self::TABLES_VIEW,
                self::PRODUCTS_VIEW,
            ],

            'kitchen' => [
                self::ORDERS_VIEW, self::ORDERS_KITCHEN,
                self::PRODUCTS_VIEW,
            ],

            'waiter' => [
                self::ORDERS_VIEW, self::ORDERS_MANAGE,
                self::TABLES_VIEW,
                self::PRODUCTS_VIEW,
            ],

            // Customers hold no back-office permissions; their access is
            // governed by ownership checks in the policies instead.
            'customer' => [],
        ];
    }

    /** @return array<int, string> */
    public static function roleNames(): array
    {
        return array_merge(['super-admin'], array_keys(self::roles()));
    }
}
