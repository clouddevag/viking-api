/**
 * Response shapes returned by the Laravel API.
 *
 * Hand-written rather than generated so the client can express exactly what it
 * relies on — several fields are conditional server-side (admin-only blocks,
 * `whenLoaded` relations) and are typed optional here to match.
 */

export type Locale = "ar" | "en";

export type OrderStatus =
  | "pending"
  | "confirmed"
  | "preparing"
  | "ready"
  | "served"
  | "completed"
  | "cancelled";

export type OrderType = "dine_in" | "takeaway" | "delivery";

export type PaymentStatus = "unpaid" | "paid" | "refunded" | "partially_refunded";

export type PaymentMethod = "cash" | "card" | "online" | "wallet";

export type OptionGroupKind = "variant" | "addon";

export type TableStatus = "available" | "occupied" | "reserved" | "disabled";

export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export interface Envelope<T> {
  data: T;
  message?: string;
}

export interface MediaImage {
  id: number;
  url: string;
  thumb: string;
  small: string;
  medium: string;
  large: string;
  alt: string | null;
  width: number | null;
  height: number | null;
}

export interface Category {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  icon: string | null;
  accent_color: string | null;
  sort_order: number;
  is_featured: boolean;
  parent_id: number | null;
  image?: MediaImage | null;
  products_count?: number;
  children?: Category[];
  is_active?: boolean;
  translations?: { name: Translations; description: Translations };
}

export type Translations = { en: string | null; ar: string | null };

export interface ProductOption {
  id: number;
  name: string;
  price_delta: number;
  is_default: boolean;
  is_available: boolean;
  max_quantity: number;
  sort_order: number;
  image?: MediaImage | null;
  translations?: { name: Translations };
}

export interface OptionGroup {
  id: number;
  name: string;
  description: string | null;
  kind: OptionGroupKind;
  selection: "single" | "multiple";
  is_required: boolean;
  min_selections: number;
  max_selections: number;
  sort_order: number;
  options: ProductOption[];
  product_id?: number | null;
  is_active?: boolean;
}

export interface Product {
  id: number;
  slug: string;
  sku: string;
  name: string;
  short_description: string | null;
  description: string | null;
  base_price: number;
  compare_at_price: number | null;
  currency: string;
  calories: number | null;
  prep_time_minutes: number;
  spice_level: number;
  allergens: string[];
  tags: string[];
  is_available: boolean;
  is_featured: boolean;
  is_new: boolean;
  rating_average: number;
  rating_count: number;
  image?: MediaImage | null;
  category?: Category;
  category_id: number;
  gallery?: MediaImage[];
  option_groups?: OptionGroup[];
  is_favorite?: boolean;
  is_active?: boolean;
  cost_price?: number | null;
  sort_order?: number;
  order_count?: number;
  image_id?: number | null;
  translations?: {
    name: Translations;
    short_description: Translations;
    description: Translations;
  };
}

export interface Branch {
  id: number;
  slug: string;
  name: string;
  address: string | null;
  phone: string | null;
  latitude: number | null;
  longitude: number | null;
  opens_at: string;
  closes_at: string;
  timezone: string;
  is_open_now: boolean;
  accepts_dine_in: boolean;
  accepts_takeaway: boolean;
  accepts_delivery: boolean;
  delivery_fee: number;
  minimum_order: number;
  is_active?: boolean;
  tables_count?: number;
}

export interface DiningTable {
  id: number;
  number: string;
  name: string;
  zone: string | null;
  capacity: number;
  status: TableStatus;
  branch_id: number;
  branch?: Branch;
  active_session?: {
    id: number;
    party_size: number;
    guest_name: string | null;
    opened_at: string | null;
    running_total: number;
  } | null;
  active_orders_count?: number;
  is_active?: boolean;
  qr_token?: string;
  qr_url?: string;
  qr_rotated_at?: string | null;
}

export interface OrderItemOption {
  id: number;
  /** Null once the underlying menu option has been deleted. */
  option_id: number | null;
  group_name: string;
  group_kind: OptionGroupKind;
  name: string;
  price_delta: number;
  quantity: number;
}

export interface OrderItem {
  id: number;
  product_id: number | null;
  name: string;
  sku: string | null;
  image_url: string | null;
  quantity: number;
  unit_price: number;
  options_total: number;
  line_subtotal: number;
  discount_total: number;
  line_total: number;
  special_instructions: string | null;
  status: "pending" | "preparing" | "ready" | "served" | "cancelled";
  prep_time_minutes: number;
  options?: OrderItemOption[];
}

export interface OrderTotals {
  subtotal: number;
  discount_total: number;
  manual_discount_total: number;
  tax_total: number;
  service_charge: number;
  delivery_fee: number;
  grand_total: number;
  refunded_total: number;
  currency: string;
}

export interface Order {
  id: number;
  order_number: string;
  type: OrderType;
  status: OrderStatus;
  status_label: string;
  payment_status: PaymentStatus;
  payment_method: PaymentMethod | null;
  customer_name: string | null;
  customer_phone: string | null;
  guest_count: number | null;
  notes: string | null;
  delivery_address: string | null;
  branch?: { id: number; name: string; phone: string | null };
  branch_id: number;
  table?: { id: number; number: string; name: string; zone: string | null } | null;
  totals: OrderTotals;
  coupon_code: string | null;
  estimated_minutes: number | null;
  age_minutes: number;
  items?: OrderItem[];
  items_count?: number;
  timeline?: Array<{
    from: string | null;
    to: string;
    actor: string | null;
    note: string | null;
    at: string | null;
  }>;
  payments?: Array<{
    id: number;
    method: PaymentMethod;
    status: string;
    amount: number;
    tendered_amount: number | null;
    change_amount: number;
    processed_at: string | null;
  }>;
  refunds?: Array<{
    id: number;
    amount: number;
    reason: string;
    status: string;
    processed_at: string | null;
  }>;
  placed_at: string | null;
  confirmed_at: string | null;
  preparing_at: string | null;
  ready_at: string | null;
  served_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  allowed_transitions: OrderStatus[];
}

export interface Offer {
  id: number;
  slug: string;
  title: string;
  description: string | null;
  badge: string | null;
  type: "banner" | "combo" | "discount";
  discount_type: "fixed" | "percentage" | null;
  discount_value: number | null;
  combo_price: number | null;
  cta_url: string | null;
  image?: MediaImage | null;
  products?: Product[];
  starts_at: string | null;
  ends_at: string | null;
  is_live: boolean;
  is_active?: boolean;
  sort_order?: number;
}

export interface Coupon {
  id: number;
  code: string;
  name: string;
  description: string | null;
  type: "fixed" | "percentage";
  value: number;
  minimum_order_amount: number;
  maximum_discount_amount: number | null;
  applies_to: "all" | "categories" | "products";
  starts_at: string | null;
  expires_at: string | null;
  is_active?: boolean;
  first_order_only?: boolean;
  usage_limit?: number | null;
  usage_limit_per_user?: number | null;
  used_count?: number;
  category_ids?: number[];
  product_ids?: number[];
}

export interface User {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  locale: Locale;
  avatar_url: string | null;
  branch_id: number | null;
  branch?: Branch;
  is_active: boolean;
  roles?: string[];
  permissions?: string[];
  is_staff?: boolean;
  last_login_at: string | null;
  created_at: string | null;
}

export interface PricedCartLine {
  product_id: number;
  name: string;
  sku: string;
  quantity: number;
  unit_price: number;
  options_total: number;
  line_subtotal: number;
  discount_total: number;
  line_total: number;
  special_instructions: string | null;
  options: Array<{
    option_id: number;
    group_name_en: string;
    group_name_ar: string;
    group_kind: OptionGroupKind;
    option_name_en: string;
    option_name_ar: string;
    price_delta: number;
    quantity: number;
    total: number;
  }>;
}

export interface PricedCart {
  lines: PricedCartLine[];
  item_count: number;
  subtotal: number;
  discount_total: number;
  coupon_discount: number;
  offer_discount: number;
  tax_total: number;
  service_charge: number;
  delivery_fee: number;
  grand_total: number;
  currency: string;
  estimated_minutes: number;
  coupon: { code: string; name: string; type: string; value: number } | null;
}

export interface TableSessionInfo {
  session_token: string;
  status?: string;
  is_open?: boolean;
  opened_at: string | null;
  party_size: number;
  running_total: number;
  table: { id: number; number: string; name: string; zone: string | null; capacity?: number };
  branch: Branch;
  guest_token?: string;
}

export interface BootstrapPayload {
  settings: Record<string, unknown>;
  branches: Branch[];
  currency: { code: string; decimals: number };
  locales: Array<{ code: Locale; name: string; dir: "rtl" | "ltr" }>;
  features: { guest_orders: boolean; realtime: boolean };
}

export interface DashboardStats {
  revenue: number;
  orders: number;
  average_order_value: number;
  cancelled: number;
  refunded: number;
  guests: number;
}

export interface DashboardPayload {
  today: DashboardStats;
  yesterday: DashboardStats;
  month: DashboardStats;
  trend: Array<{ date: string; orders: number; revenue: number }>;
  status_breakdown: Record<OrderStatus, number>;
  top_products: Array<{ product_id: number; name: string; units: number; revenue: number }>;
  hourly: Array<{ hour: number; orders: number }>;
  live_orders: Order[];
}

export interface KitchenBoard {
  incoming: Order[];
  preparing: Order[];
  ready: Order[];
}

export interface ReportPayload {
  rows: Array<Record<string, string | number | null>>;
  totals: Record<string, number>;
}

/** The error envelope every API failure uses. */
export interface ApiError {
  message: string;
  error?: string;
  errors?: Record<string, string[]>;
  context?: Record<string, unknown>;
}
