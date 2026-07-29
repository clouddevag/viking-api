"use client";

import { create } from "zustand";
import { persist, createJSONStorage } from "zustand/middleware";

import type { OptionGroup, Product, ProductOption } from "@/types/api";

/**
 * The cart.
 *
 * Deliberately stores only the *identity* of what was chosen — product id,
 * option ids, quantity, note — and never prices. Totals always come from the
 * server, so a stale cart in localStorage can never quote yesterday's price.
 * The `unitPriceHint` fields exist purely to render an optimistic figure while
 * the pricing request is in flight.
 */

export interface CartLineOption {
  optionId: number;
  groupId: number;
  groupName: string;
  groupKind: "variant" | "addon";
  name: string;
  priceDelta: number;
  quantity: number;
}

export interface CartLine {
  /** Stable key derived from the selection, so identical lines merge. */
  key: string;
  productId: number;
  slug: string;
  name: string;
  imageUrl: string | null;
  quantity: number;
  basePriceHint: number;
  options: CartLineOption[];
  specialInstructions: string | null;
}

interface CartState {
  lines: CartLine[];
  branchId: number | null;
  couponCode: string | null;
  orderType: "dine_in" | "takeaway" | "delivery";
  tableToken: string | null;
  tableSessionToken: string | null;
  tableNumber: string | null;

  addLine: (input: {
    product: Product;
    quantity: number;
    options: Array<{ group: OptionGroup; option: ProductOption; quantity?: number }>;
    specialInstructions?: string | null;
  }) => void;
  setQuantity: (key: string, quantity: number) => void;
  removeLine: (key: string) => void;
  clear: () => void;

  setBranch: (branchId: number) => void;
  setCoupon: (code: string | null) => void;
  setOrderType: (type: "dine_in" | "takeaway" | "delivery") => void;
  setTableContext: (context: {
    tableToken?: string | null;
    sessionToken?: string | null;
    tableNumber?: string | null;
    branchId?: number | null;
  }) => void;
  clearTableContext: () => void;

  itemCount: () => number;
  estimatedTotal: () => number;
}

/**
 * Two lines are the same line when the product, the exact set of options and
 * the note all match — which is what lets "add another" increment instead of
 * stacking duplicate rows on the kitchen ticket.
 */
function lineKey(
  productId: number,
  options: CartLineOption[],
  instructions: string | null,
): string {
  const optionPart = options
    .map((option) => `${option.optionId}x${option.quantity}`)
    .sort()
    .join(",");

  return `${productId}|${optionPart}|${instructions ?? ""}`;
}

export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      lines: [],
      branchId: null,
      couponCode: null,
      orderType: "dine_in",
      tableToken: null,
      tableSessionToken: null,
      tableNumber: null,

      addLine: ({ product, quantity, options, specialInstructions = null }) => {
        const normalized: CartLineOption[] = options.map(({ group, option, quantity: q }) => ({
          optionId: option.id,
          groupId: group.id,
          groupName: group.name,
          groupKind: group.kind,
          name: option.name,
          priceDelta: option.price_delta,
          quantity: q ?? 1,
        }));

        const instructions = specialInstructions?.trim() || null;
        const key = lineKey(product.id, normalized, instructions);

        set((state) => {
          const existing = state.lines.find((line) => line.key === key);

          if (existing) {
            return {
              lines: state.lines.map((line) =>
                line.key === key
                  ? { ...line, quantity: Math.min(50, line.quantity + quantity) }
                  : line,
              ),
            };
          }

          return {
            lines: [
              ...state.lines,
              {
                key,
                productId: product.id,
                slug: product.slug,
                name: product.name,
                imageUrl: product.image?.small ?? null,
                quantity: Math.min(50, quantity),
                basePriceHint: product.base_price,
                options: normalized,
                specialInstructions: instructions,
              },
            ],
          };
        });
      },

      setQuantity: (key, quantity) =>
        set((state) => ({
          lines:
            quantity <= 0
              ? state.lines.filter((line) => line.key !== key)
              : state.lines.map((line) =>
                  line.key === key ? { ...line, quantity: Math.min(50, quantity) } : line,
                ),
        })),

      removeLine: (key) =>
        set((state) => ({ lines: state.lines.filter((line) => line.key !== key) })),

      clear: () => set({ lines: [], couponCode: null }),

      setBranch: (branchId) =>
        set((state) =>
          // Switching branch invalidates the cart: prices and availability are
          // per-branch, and silently carrying items over would surprise people.
          state.branchId !== null && state.branchId !== branchId
            ? { branchId, lines: [], couponCode: null }
            : { branchId },
        ),

      setCoupon: (couponCode) => set({ couponCode }),

      setOrderType: (orderType) => set({ orderType }),

      setTableContext: ({ tableToken, sessionToken, tableNumber, branchId }) =>
        set((state) => ({
          tableToken: tableToken ?? state.tableToken,
          tableSessionToken: sessionToken ?? state.tableSessionToken,
          tableNumber: tableNumber ?? state.tableNumber,
          branchId: branchId ?? state.branchId,
          orderType: "dine_in",
        })),

      clearTableContext: () =>
        set({ tableToken: null, tableSessionToken: null, tableNumber: null }),

      itemCount: () => get().lines.reduce((total, line) => total + line.quantity, 0),

      /** Optimistic figure for the cart badge; the server total is authoritative. */
      estimatedTotal: () =>
        get().lines.reduce((total, line) => {
          const optionsTotal = line.options.reduce(
            (sum, option) => sum + option.priceDelta * option.quantity,
            0,
          );
          return total + (line.basePriceHint + optionsTotal) * line.quantity;
        }, 0),
    }),
    {
      name: "viking.cart",
      storage: createJSONStorage(() => localStorage),
      version: 1,
      // The table context is a property of *this visit*, not of the cart, so
      // it is deliberately excluded from what gets persisted across sessions.
      partialize: (state) => ({
        lines: state.lines,
        branchId: state.branchId,
        couponCode: state.couponCode,
        orderType: state.orderType,
      }),
    },
  ),
);
