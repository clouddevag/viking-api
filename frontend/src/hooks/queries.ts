"use client";

import {
  useMutation,
  useQuery,
  useQueryClient,
  keepPreviousData,
  type UseQueryOptions,
} from "@tanstack/react-query";

import {
  adminApi,
  authApi,
  cashierApi,
  favoriteApi,
  kitchenApi,
  orderApi,
  publicApi,
  type CartPayload,
} from "@/lib/api/endpoints";
import { useCartStore } from "@/stores/cart";
import type { PricedCart, Product } from "@/types/api";

/**
 * Query keys.
 *
 * Centralised so an invalidation can target a whole subtree — invalidating
 * `keys.orders()` refreshes every order list and detail view without any screen
 * needing to know the others exist.
 */
export const keys = {
  bootstrap: () => ["bootstrap"] as const,
  categories: () => ["categories"] as const,
  products: (params?: Record<string, unknown>) => ["products", params ?? {}] as const,
  product: (slug: string) => ["product", slug] as const,
  highlights: () => ["highlights"] as const,
  offers: () => ["offers"] as const,
  search: (q: string) => ["search", q] as const,
  reviews: (productId: number, page: number) => ["reviews", productId, page] as const,

  cartPrice: (payload: CartPayload | null) => ["cart-price", payload] as const,

  orders: () => ["orders"] as const,
  order: (orderNumber: string) => ["orders", orderNumber] as const,
  favorites: () => ["favorites"] as const,

  kitchenBoard: (branchId?: number) => ["kitchen", "board", branchId ?? null] as const,
  cashierOrders: (params?: Record<string, unknown>) => ["cashier", "orders", params ?? {}] as const,
  cashierTables: (branchId?: number) => ["cashier", "tables", branchId ?? null] as const,
  cashierOrder: (orderNumber: string) => ["cashier", "order", orderNumber] as const,

  admin: {
    dashboard: (branchId?: number) => ["admin", "dashboard", branchId ?? null] as const,
    orders: (params?: Record<string, unknown>) => ["admin", "orders", params ?? {}] as const,
    products: (params?: Record<string, unknown>) => ["admin", "products", params ?? {}] as const,
    categories: () => ["admin", "categories"] as const,
    optionGroups: (params?: Record<string, unknown>) => ["admin", "option-groups", params ?? {}] as const,
    tables: (params?: Record<string, unknown>) => ["admin", "tables", params ?? {}] as const,
    users: (params?: Record<string, unknown>) => ["admin", "users", params ?? {}] as const,
    roles: () => ["admin", "roles"] as const,
    coupons: (params?: Record<string, unknown>) => ["admin", "coupons", params ?? {}] as const,
    offers: (params?: Record<string, unknown>) => ["admin", "offers", params ?? {}] as const,
    media: (params?: Record<string, unknown>) => ["admin", "media", params ?? {}] as const,
    settings: () => ["admin", "settings"] as const,
    branches: () => ["admin", "branches"] as const,
    report: (report: string, params?: Record<string, unknown>) =>
      ["admin", "report", report, params ?? {}] as const,
    activity: (params?: Record<string, unknown>) => ["admin", "activity", params ?? {}] as const,
    reviews: (params?: Record<string, unknown>) => ["admin", "reviews", params ?? {}] as const,
  },
} as const;

/* Public catalog ----------------------------------------------------------- */

export function useBootstrap() {
  return useQuery({
    queryKey: keys.bootstrap(),
    queryFn: publicApi.bootstrap,
    // Branding and branch list effectively never change during a visit.
    staleTime: 10 * 60_000,
  });
}

export function useCategories() {
  return useQuery({
    queryKey: keys.categories(),
    queryFn: publicApi.categories,
    staleTime: 5 * 60_000,
  });
}

export function useProducts(params?: Parameters<typeof publicApi.products>[0]) {
  return useQuery({
    queryKey: keys.products(params),
    queryFn: () => publicApi.products(params),
    // Keeps the previous page visible while the next loads, so the list does
    // not collapse to a spinner on every filter change.
    placeholderData: keepPreviousData,
  });
}

export function useProduct(slug: string, options?: Partial<UseQueryOptions<Product>>) {
  return useQuery({
    queryKey: keys.product(slug),
    queryFn: () => publicApi.product(slug),
    enabled: Boolean(slug),
    ...options,
  });
}

export function useHighlights() {
  return useQuery({ queryKey: keys.highlights(), queryFn: publicApi.highlights });
}

export function useOffers() {
  return useQuery({ queryKey: keys.offers(), queryFn: publicApi.offers, staleTime: 5 * 60_000 });
}

export function useProductSearch(term: string) {
  return useQuery({
    queryKey: keys.search(term),
    queryFn: () => publicApi.search(term),
    // The API ignores anything shorter, so do not spend a request on it.
    enabled: term.trim().length >= 2,
    staleTime: 30_000,
  });
}

export function useProductReviews(productId: number | undefined, page = 1) {
  return useQuery({
    queryKey: keys.reviews(productId ?? 0, page),
    queryFn: () => publicApi.reviews(productId!, page),
    enabled: Boolean(productId),
  });
}

/* Cart pricing -------------------------------------------------------------- */

/**
 * Server-computed cart totals.
 *
 * Re-runs whenever the cart, branch, coupon or order type changes, which keeps
 * what the customer sees identical to what checkout will charge.
 */
export function useCartPricing() {
  const lines = useCartStore((state) => state.lines);
  const branchId = useCartStore((state) => state.branchId);
  const couponCode = useCartStore((state) => state.couponCode);
  const orderType = useCartStore((state) => state.orderType);

  const payload: CartPayload | null =
    branchId && lines.length > 0
      ? {
          branch_id: branchId,
          type: orderType,
          coupon_code: couponCode,
          items: lines.map((line) => ({
            product_id: line.productId,
            quantity: line.quantity,
            special_instructions: line.specialInstructions,
            options: line.options.map((option) => ({
              option_id: option.optionId,
              quantity: option.quantity,
            })),
          })),
        }
      : null;

  return useQuery({
    queryKey: keys.cartPrice(payload),
    queryFn: () => publicApi.priceCart(payload!),
    enabled: payload !== null,
    // Prices must be current at checkout, so never serve a cached total.
    staleTime: 0,
    placeholderData: keepPreviousData,
    select: (data): { cart: PricedCart; couponError: string | null } => ({
      cart: data.data,
      couponError: data.coupon_error?.message ?? null,
    }),
  });
}

/* Orders -------------------------------------------------------------------- */

export function useOrders(params?: Parameters<typeof orderApi.list>[0]) {
  return useQuery({
    queryKey: [...keys.orders(), params ?? {}],
    queryFn: () => orderApi.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useOrder(orderNumber: string, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: keys.order(orderNumber),
    queryFn: () => orderApi.get(orderNumber),
    enabled: Boolean(orderNumber),
    // Tracking screens poll as a fallback for when websockets are unavailable.
    refetchInterval: options?.refetchInterval ?? false,
  });
}

export function usePlaceOrder() {
  const queryClient = useQueryClient();
  const clearCart = useCartStore((state) => state.clear);

  return useMutation({
    mutationFn: orderApi.place,
    onSuccess: () => {
      clearCart();
      void queryClient.invalidateQueries({ queryKey: keys.orders() });
    },
  });
}

export function useCancelOrder() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ orderNumber, reason }: { orderNumber: string; reason?: string }) =>
      orderApi.cancel(orderNumber, reason),
    onSuccess: (order) => {
      queryClient.setQueryData(keys.order(order.order_number), order);
      void queryClient.invalidateQueries({ queryKey: keys.orders() });
    },
  });
}

/* Favourites ---------------------------------------------------------------- */

export function useFavorites(page = 1) {
  return useQuery({ queryKey: [...keys.favorites(), page], queryFn: () => favoriteApi.list(page) });
}

/**
 * Optimistic favourite toggle: the heart fills instantly and rolls back only
 * if the request fails.
 */
export function useToggleFavorite() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: favoriteApi.toggle,
    onMutate: async (productId: number) => {
      await queryClient.cancelQueries({ queryKey: ["product"] });

      const snapshots = queryClient.getQueriesData<Product>({ queryKey: ["product"] });

      queryClient.setQueriesData<Product>({ queryKey: ["product"] }, (current) =>
        current && current.id === productId
          ? { ...current, is_favorite: !current.is_favorite }
          : current,
      );

      return { snapshots };
    },
    onError: (_error, _productId, context) => {
      context?.snapshots.forEach(([key, value]) => queryClient.setQueryData(key, value));
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: keys.favorites() });
    },
  });
}

/* Auth ---------------------------------------------------------------------- */

export function useLogin() {
  return useMutation({ mutationFn: authApi.login });
}

export function useRegister() {
  return useMutation({ mutationFn: authApi.register });
}

/* Kitchen ------------------------------------------------------------------- */

export function useKitchenBoard(branchId?: number, pollMs: number | false = 20_000) {
  return useQuery({
    queryKey: keys.kitchenBoard(branchId),
    queryFn: () => kitchenApi.board(branchId),
    // Websockets drive the board; this poll is the safety net for a dropped
    // socket on a tablet that has been asleep.
    refetchInterval: pollMs,
    refetchOnWindowFocus: true,
    staleTime: 0,
  });
}

export function useAdvanceOrder(branchId?: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: kitchenApi.advance,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: keys.kitchenBoard(branchId) });
    },
  });
}

/* Cashier ------------------------------------------------------------------- */

export function useCashierOrders(params?: Parameters<typeof cashierApi.orders>[0]) {
  return useQuery({
    queryKey: keys.cashierOrders(params),
    queryFn: () => cashierApi.orders(params),
    refetchInterval: 30_000,
    placeholderData: keepPreviousData,
  });
}

/**
 * A single order with its payments and true outstanding balance.
 *
 * Deliberately separate from the list query: the till drawer needs line items
 * and the payment ledger, which the list row does not carry.
 */
export function useCashierOrder(orderNumber: string) {
  return useQuery({
    queryKey: keys.cashierOrder(orderNumber),
    queryFn: () => cashierApi.get(orderNumber),
    // The balance must be current when money changes hands.
    staleTime: 0,
  });
}

export function useCashierTables(branchId?: number) {
  return useQuery({
    queryKey: keys.cashierTables(branchId),
    queryFn: () => cashierApi.tables(branchId),
    refetchInterval: 30_000,
  });
}

export function useTakePayment() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      orderNumber,
      ...payload
    }: { orderNumber: string } & Parameters<typeof cashierApi.pay>[1]) =>
      cashierApi.pay(orderNumber, payload),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["cashier"] });
    },
  });
}

/* Admin --------------------------------------------------------------------- */

export function useDashboard(branchId?: number) {
  return useQuery({
    queryKey: keys.admin.dashboard(branchId),
    queryFn: () => adminApi.dashboard(branchId),
    refetchInterval: 60_000,
  });
}

export function useAdminOrders(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.orders(params),
    queryFn: () => adminApi.orders(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminProducts(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.products(params),
    queryFn: () => adminApi.products(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminCategories() {
  return useQuery({
    queryKey: keys.admin.categories(),
    queryFn: () => adminApi.categories({ per_page: 100 }),
  });
}

export function useAdminTables(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.tables(params),
    queryFn: () => adminApi.tables(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminUsers(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.users(params),
    queryFn: () => adminApi.users(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminCoupons(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.coupons(params),
    queryFn: () => adminApi.coupons(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminOffers(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.offers(params),
    queryFn: () => adminApi.offers(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminRoles() {
  return useQuery({ queryKey: keys.admin.roles(), queryFn: adminApi.roles });
}

export function useAdminSettings() {
  return useQuery({ queryKey: keys.admin.settings(), queryFn: adminApi.settings });
}

export function useAdminBranches() {
  return useQuery({ queryKey: keys.admin.branches(), queryFn: adminApi.branches });
}

export function useReport(report: string, params?: { from?: string; to?: string; branch_id?: number }) {
  return useQuery({
    queryKey: keys.admin.report(report, params),
    queryFn: () => adminApi.report(report, params),
    placeholderData: keepPreviousData,
  });
}

export function useActivityLog(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.activity(params),
    queryFn: () => adminApi.activity(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminMedia(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.media(params),
    queryFn: () => adminApi.media(params),
    placeholderData: keepPreviousData,
  });
}

export function useAdminReviews(params?: Record<string, unknown>) {
  return useQuery({
    queryKey: keys.admin.reviews(params),
    queryFn: () => adminApi.reviews(params),
    placeholderData: keepPreviousData,
  });
}
