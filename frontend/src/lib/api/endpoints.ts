import { api, getData } from "./client";

import type {
  BootstrapPayload,
  Branch,
  Category,
  Coupon,
  DashboardPayload,
  DiningTable,
  KitchenBoard,
  Offer,
  OptionGroup,
  Order,
  OrderStatus,
  Paginated,
  PaymentMethod,
  PricedCart,
  Product,
  ReportPayload,
  TableSessionInfo,
  User,
} from "@/types/api";

/**
 * One typed function per API endpoint.
 *
 * Query hooks and mutations call through here rather than touching axios, so
 * the wire format is described in exactly one place and a route change is a
 * single-file edit.
 */

// The payload shape the client sends for a cart, shared by pricing and checkout.
export interface CartPayload {
  branch_id: number;
  type?: "dine_in" | "takeaway" | "delivery";
  coupon_code?: string | null;
  items: Array<{
    product_id: number;
    quantity: number;
    special_instructions?: string | null;
    options?: Array<{ option_id: number; quantity?: number }>;
  }>;
}

export interface PlaceOrderPayload extends CartPayload {
  type: "dine_in" | "takeaway" | "delivery";
  customer_name?: string | null;
  customer_phone?: string | null;
  notes?: string | null;
  guest_count?: number | null;
  table_token?: string | null;
  table_session_token?: string | null;
  delivery_address?: string | null;
}

// Public -----------------------------------------------------------------------

export const publicApi = {
  bootstrap: () => getData<BootstrapPayload>("/bootstrap"),

  categories: () =>
    api.get<{ data: Category[] }>("/categories").then((r) => r.data.data),

  products: (params?: {
    category?: string;
    search?: string;
    tag?: string;
    featured?: boolean;
    new?: boolean;
    sort?: string;
    page?: number;
    per_page?: number;
    min_price?: number;
    max_price?: number;
  }) => api.get<Paginated<Product>>("/products", { params }).then((r) => r.data),

  product: (slug: string) => getData<Product>(`/products/${slug}`),

  search: (q: string) =>
    api
      .get<{ data: Array<{ id: number; slug: string; name: string; base_price: number; image: string | null }> }>(
        "/products/search",
        { params: { q } },
      )
      .then((r) => r.data.data),

  highlights: () =>
    getData<{ featured: Product[]; popular: Product[]; new: Product[] }>("/products/highlights"),

  offers: () => api.get<{ data: Offer[] }>("/offers").then((r) => r.data.data),

  offer: (slug: string) => getData<Offer>(`/offers/${slug}`),

  reviews: (productId: number, page = 1) =>
    api
      .get<{
        data: Array<{ id: number; rating: number; comment: string | null; author: string | null; created_at: string | null }>;
        meta: { current_page: number; last_page: number; total: number; average: number; count: number };
      }>(`/products/${productId}/reviews`, { params: { page } })
      .then((r) => r.data),

  /**
   * Opens or rejoins a dining session from a scanned QR token. Idempotent —
   * re-scanning returns the session already in progress.
   */
  scanTable: (
    token: string,
    body?: { guest_name?: string; guest_phone?: string; party_size?: number },
  ) =>
    api
      .post<{ data: TableSessionInfo }>(`/tables/scan/${token}`, body ?? {})
      .then((r) => r.data.data),

  priceCart: (payload: CartPayload) =>
    api
      .post<{ data: PricedCart; coupon_error: { message: string } | null }>("/cart/price", payload)
      .then((r) => r.data),
};

export const tableApi = {
  session: (sessionToken: string) =>
    getData<TableSessionInfo>(`/tables/session/${sessionToken}`),
};

// Auth -------------------------------------------------------------------------

export interface AuthResult {
  token: string;
  token_type: string;
  user: User;
}

export const authApi = {
  login: (payload: { login: string; password: string; guest_token?: string | null }) =>
    api.post<{ data: AuthResult }>("/auth/login", payload).then((r) => r.data.data),

  register: (payload: {
    name: string;
    email?: string | null;
    phone: string;
    password: string;
    password_confirmation: string;
    locale?: string;
    guest_token?: string | null;
  }) => api.post<{ data: AuthResult }>("/auth/register", payload).then((r) => r.data.data),

  me: () => getData<User>("/auth/me"),

  updateProfile: (payload: Partial<Pick<User, "name" | "email" | "phone" | "locale">>) =>
    api.patch<{ data: User }>("/auth/profile", payload).then((r) => r.data.data),

  changePassword: (payload: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }) => api.post<{ message: string }>("/auth/password", payload).then((r) => r.data),

  logout: () => api.post("/auth/logout").then(() => undefined),
};

// Customer ordering ------------------------------------------------------------

export const orderApi = {
  place: (payload: PlaceOrderPayload) =>
    api
      .post<{ data: Order; guest_token: string | null }>("/orders", payload)
      .then((r) => r.data),

  list: (params?: { page?: number; per_page?: number; filter?: Record<string, string> }) =>
    api.get<Paginated<Order>>("/orders", { params }).then((r) => r.data),

  get: (orderNumber: string) => getData<Order>(`/orders/${orderNumber}`),

  cancel: (orderNumber: string, reason?: string) =>
    api
      .post<{ data: Order }>(`/orders/${orderNumber}/cancel`, { reason })
      .then((r) => r.data.data),

  reorder: (orderNumber: string) =>
    api
      .get<{ data: CartPayload; unavailable: string[] }>(`/orders/${orderNumber}/reorder`)
      .then((r) => r.data),
};

export const favoriteApi = {
  list: (page = 1) =>
    api.get<Paginated<Product>>("/favorites", { params: { page } }).then((r) => r.data),

  toggle: (productId: number) =>
    api
      .post<{ data: { is_favorite: boolean } }>(`/favorites/${productId}`)
      .then((r) => r.data.data.is_favorite),

  sync: (productIds: number[]) =>
    api.post("/favorites/sync", { product_ids: productIds }).then(() => undefined),
};

export const addressApi = {
  list: () => getData<Array<Record<string, unknown>>>("/addresses"),
  create: (payload: Record<string, unknown>) =>
    api.post("/addresses", payload).then((r) => r.data.data),
  update: (id: number, payload: Record<string, unknown>) =>
    api.put(`/addresses/${id}`, payload).then((r) => r.data.data),
  remove: (id: number) => api.delete(`/addresses/${id}`).then(() => undefined),
};

export const reviewApi = {
  create: (productId: number, payload: { order_number: string; rating: number; comment?: string }) =>
    api.post(`/products/${productId}/reviews`, payload).then((r) => r.data),
};

// Kitchen ----------------------------------------------------------------------

export const kitchenApi = {
  board: (branchId?: number) =>
    api
      .get<{
        data: KitchenBoard;
        meta: {
          branch_id: number | null;
          counts: Record<string, number>;
          thresholds: { warning_minutes: number; critical_minutes: number };
          server_time: string;
        };
      }>("/kitchen/board", { params: { branch_id: branchId } })
      .then((r) => r.data),

  advance: (orderNumber: string) =>
    api.post<{ data: Order }>(`/kitchen/orders/${orderNumber}/advance`).then((r) => r.data.data),

  setStatus: (orderNumber: string, status: OrderStatus, note?: string) =>
    api
      .post<{ data: Order }>(`/kitchen/orders/${orderNumber}/status`, { status, note })
      .then((r) => r.data.data),

  setItemStatus: (orderNumber: string, itemId: number, status: string) =>
    api
      .patch<{ data: Order }>(`/kitchen/orders/${orderNumber}/items/${itemId}`, { status })
      .then((r) => r.data.data),
};

// Cashier ----------------------------------------------------------------------

export const cashierApi = {
  orders: (params?: { unpaid_only?: boolean; branch_id?: number; page?: number }) =>
    api.get<Paginated<Order>>("/cashier/orders", { params }).then((r) => r.data),

  get: (orderNumber: string) =>
    api
      .get<{ data: Order; meta: { paid_amount: number; outstanding: number } }>(
        `/cashier/orders/${orderNumber}`,
      )
      .then((r) => r.data),

  pay: (
    orderNumber: string,
    payload: { method: PaymentMethod; amount: number; tendered_amount?: number; reference?: string },
  ) =>
    api
      .post<{ data: Order; meta: { change_due: number; outstanding: number } }>(
        `/cashier/orders/${orderNumber}/pay`,
        payload,
      )
      .then((r) => r.data),

  refund: (orderNumber: string, payload: { amount: number; reason: string }) =>
    api
      .post<{ data: Order }>(`/cashier/orders/${orderNumber}/refund`, payload)
      .then((r) => r.data.data),

  discount: (orderNumber: string, payload: { amount: number; reason: string }) =>
    api
      .post<{ data: Order }>(`/cashier/orders/${orderNumber}/discount`, payload)
      .then((r) => r.data.data),

  tables: (branchId?: number) =>
    api
      .get<{ data: DiningTable[] }>("/cashier/tables", { params: { branch_id: branchId } })
      .then((r) => r.data.data),

  closeTable: (tableId: number, force = false) =>
    api.post(`/cashier/tables/${tableId}/close`, { force }).then((r) => r.data),

  receipt: (orderNumber: string, locale: string) =>
    getData<Record<string, unknown>>(`/cashier/receipts/${orderNumber}?locale=${locale}`),
};

// Admin ------------------------------------------------------------------------

export const adminApi = {
  dashboard: (branchId?: number) =>
    getData<DashboardPayload>("/admin/dashboard", { branch_id: branchId }),

  orders: (params?: Record<string, unknown>) =>
    api.get<Paginated<Order>>("/admin/orders", { params }).then((r) => r.data),

  order: (orderNumber: string) =>
    api
      .get<{ data: Order; meta: { paid_amount: number; outstanding: number } }>(
        `/admin/orders/${orderNumber}`,
      )
      .then((r) => r.data),

  setOrderStatus: (orderNumber: string, status: OrderStatus, note?: string) =>
    api
      .post<{ data: Order }>(`/admin/orders/${orderNumber}/status`, { status, note })
      .then((r) => r.data.data),

  cancelOrder: (orderNumber: string, reason: string) =>
    api
      .post<{ data: Order }>(`/admin/orders/${orderNumber}/cancel`, { reason })
      .then((r) => r.data.data),

  products: (params?: Record<string, unknown>) =>
    api.get<Paginated<Product>>("/admin/products", { params }).then((r) => r.data),
  product: (slug: string) => getData<Product>(`/admin/products/${slug}`),
  createProduct: (payload: Record<string, unknown>) =>
    api.post<{ data: Product }>("/admin/products", payload).then((r) => r.data.data),
  updateProduct: (slug: string, payload: Record<string, unknown>) =>
    api.put<{ data: Product }>(`/admin/products/${slug}`, payload).then((r) => r.data.data),
  deleteProduct: (slug: string) => api.delete(`/admin/products/${slug}`).then(() => undefined),
  toggleProductAvailability: (slug: string) =>
    api
      .post<{ data: { is_available: boolean } }>(`/admin/products/${slug}/availability`)
      .then((r) => r.data.data.is_available),
  reorderProducts: (order: Array<{ id: number; sort_order: number }>) =>
    api.post("/admin/products/reorder", { order }).then(() => undefined),

  categories: (params?: Record<string, unknown>) =>
    api.get<Paginated<Category>>("/admin/categories", { params }).then((r) => r.data),
  createCategory: (payload: Record<string, unknown>) =>
    api.post<{ data: Category }>("/admin/categories", payload).then((r) => r.data.data),
  updateCategory: (slug: string, payload: Record<string, unknown>) =>
    api.put<{ data: Category }>(`/admin/categories/${slug}`, payload).then((r) => r.data.data),
  deleteCategory: (slug: string) => api.delete(`/admin/categories/${slug}`).then(() => undefined),

  optionGroups: (params?: Record<string, unknown>) =>
    api.get<Paginated<OptionGroup>>("/admin/option-groups", { params }).then((r) => r.data),
  createOptionGroup: (payload: Record<string, unknown>) =>
    api.post<{ data: OptionGroup }>("/admin/option-groups", payload).then((r) => r.data.data),
  updateOptionGroup: (id: number, payload: Record<string, unknown>) =>
    api.put<{ data: OptionGroup }>(`/admin/option-groups/${id}`, payload).then((r) => r.data.data),
  deleteOptionGroup: (id: number) =>
    api.delete(`/admin/option-groups/${id}`).then(() => undefined),

  tables: (params?: Record<string, unknown>) =>
    api.get<Paginated<DiningTable>>("/admin/tables", { params }).then((r) => r.data),
  createTable: (payload: Record<string, unknown>) =>
    api.post<{ data: DiningTable }>("/admin/tables", payload).then((r) => r.data.data),
  updateTable: (id: number, payload: Record<string, unknown>) =>
    api.put<{ data: DiningTable }>(`/admin/tables/${id}`, payload).then((r) => r.data.data),
  deleteTable: (id: number) => api.delete(`/admin/tables/${id}`).then(() => undefined),
  bulkCreateTables: (payload: Record<string, unknown>) =>
    api.post<{ data: DiningTable[] }>("/admin/tables/bulk", payload).then((r) => r.data.data),
  rotateTableQr: (id: number) =>
    api.post<{ data: DiningTable }>(`/admin/tables/${id}/rotate-qr`).then((r) => r.data.data),
  qrSheet: (branchId?: number) =>
    getData<Array<{ id: number; number: string; name: string; zone: string | null; branch: string; url: string; svg: string }>>(
      "/admin/tables/qr-sheet",
      { branch_id: branchId },
    ),

  users: (params?: Record<string, unknown>) =>
    api.get<Paginated<User>>("/admin/users", { params }).then((r) => r.data),
  createUser: (payload: Record<string, unknown>) =>
    api.post<{ data: User }>("/admin/users", payload).then((r) => r.data.data),
  updateUser: (id: number, payload: Record<string, unknown>) =>
    api.put<{ data: User }>(`/admin/users/${id}`, payload).then((r) => r.data.data),
  deleteUser: (id: number) => api.delete(`/admin/users/${id}`).then(() => undefined),

  roles: () =>
    getData<{
      roles: Array<{ id: number; name: string; permissions: string[]; users_count: number }>;
      permissions: Record<string, string[]>;
    }>("/admin/roles"),
  updateRole: (id: number, permissions: string[]) =>
    api.patch(`/admin/roles/${id}`, { permissions }).then((r) => r.data),
  createRole: (name: string, permissions: string[]) =>
    api.post("/admin/roles", { name, permissions }).then((r) => r.data),

  coupons: (params?: Record<string, unknown>) =>
    api.get<Paginated<Coupon>>("/admin/coupons", { params }).then((r) => r.data),
  createCoupon: (payload: Record<string, unknown>) =>
    api.post<{ data: Coupon }>("/admin/coupons", payload).then((r) => r.data.data),
  updateCoupon: (code: string, payload: Record<string, unknown>) =>
    api.put<{ data: Coupon }>(`/admin/coupons/${code}`, payload).then((r) => r.data.data),
  deleteCoupon: (code: string) => api.delete(`/admin/coupons/${code}`).then(() => undefined),

  offers: (params?: Record<string, unknown>) =>
    api.get<Paginated<Offer>>("/admin/offers", { params }).then((r) => r.data),
  createOffer: (payload: Record<string, unknown>) =>
    api.post<{ data: Offer }>("/admin/offers", payload).then((r) => r.data.data),
  updateOffer: (slug: string, payload: Record<string, unknown>) =>
    api.put<{ data: Offer }>(`/admin/offers/${slug}`, payload).then((r) => r.data.data),
  deleteOffer: (slug: string) => api.delete(`/admin/offers/${slug}`).then(() => undefined),

  media: (params?: Record<string, unknown>) =>
    api.get<Paginated<import("@/types/api").MediaImage>>("/admin/media", { params }).then((r) => r.data),
  uploadMedia: (files: File[], collection = "default") => {
    const form = new FormData();
    files.forEach((file) => form.append("files[]", file));
    form.append("collection", collection);

    return api
      .post<{ data: import("@/types/api").MediaImage[] }>("/admin/media", form)
      .then((r) => r.data.data);
  },
  deleteMedia: (id: number) => api.delete(`/admin/media/${id}`).then(() => undefined),

  settings: () => getData<Record<string, Array<{ key: string; value: unknown; type: string; is_public: boolean }>>>("/admin/settings"),
  updateSettings: (settings: Array<{ key: string; value: unknown }>) =>
    api.put("/admin/settings", { settings }).then((r) => r.data),

  branches: () => getData<Branch[]>("/admin/branches"),
  createBranch: (payload: Record<string, unknown>) =>
    api.post<{ data: Branch }>("/admin/branches", payload).then((r) => r.data.data),
  updateBranch: (id: number, payload: Record<string, unknown>) =>
    api.patch<{ data: Branch }>(`/admin/branches/${id}`, payload).then((r) => r.data.data),

  report: (report: string, params?: { from?: string; to?: string; branch_id?: number }) =>
    api
      .get<{ data: ReportPayload; meta: Record<string, unknown> }>(`/admin/reports/${report}`, { params })
      .then((r) => r.data),

  exportReportUrl: (report: string, params: Record<string, string>) => {
    const query = new URLSearchParams(params).toString();
    return `${api.defaults.baseURL}/admin/reports/${report}/export?${query}`;
  },

  activity: (params?: Record<string, unknown>) =>
    api
      .get<{
        data: Array<Record<string, unknown>>;
        meta: { current_page: number; last_page: number; total: number; log_names: string[] };
      }>("/admin/activity", { params })
      .then((r) => r.data),

  reviews: (params?: Record<string, unknown>) =>
    api
      .get<{
        data: Array<Record<string, unknown>>;
        meta: { current_page: number; last_page: number; total: number; pending: number };
      }>("/admin/reviews", { params })
      .then((r) => r.data),
  approveReview: (id: number) => api.post(`/admin/reviews/${id}/approve`).then(() => undefined),
  rejectReview: (id: number) => api.post(`/admin/reviews/${id}/reject`).then(() => undefined),
  deleteReview: (id: number) => api.delete(`/admin/reviews/${id}`).then(() => undefined),
};

export const notificationApi = {
  list: (unreadOnly = false) =>
    api
      .get<{
        data: Array<{ id: string; type: string; data: Record<string, unknown>; read_at: string | null; created_at: string | null }>;
        meta: { unread_count: number; total: number };
      }>("/notifications", { params: { unread_only: unreadOnly } })
      .then((r) => r.data),

  markRead: (id: string) => api.post(`/notifications/${id}/read`).then(() => undefined),
  markAllRead: () => api.post("/notifications/read-all").then(() => undefined),
};
