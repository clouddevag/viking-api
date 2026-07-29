import "@testing-library/jest-dom/vitest";

import { afterEach } from "vitest";

import { useCartStore } from "@/stores/cart";

/**
 * The cart is a module-level singleton persisted to localStorage, so state
 * leaks between test files unless it is reset explicitly.
 */
afterEach(() => {
  localStorage.clear();
  useCartStore.setState({
    lines: [],
    branchId: null,
    couponCode: null,
    orderType: "dine_in",
    tableToken: null,
    tableSessionToken: null,
    tableNumber: null,
  });
});
