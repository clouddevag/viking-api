import { describe, expect, it } from "vitest";

import type { OptionGroup, Product, ProductOption } from "@/types/api";

import { useCartStore } from "./cart";

function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    id: 1,
    slug: "smash-burger",
    sku: "BRG-001",
    name: "Smash Burger",
    short_description: null,
    description: null,
    base_price: 10000,
    compare_at_price: null,
    currency: "IQD",
    calories: null,
    prep_time_minutes: 10,
    spice_level: 0,
    allergens: [],
    tags: [],
    is_available: true,
    is_featured: false,
    is_new: false,
    rating_average: 0,
    rating_count: 0,
    category_id: 1,
    ...overrides,
  };
}

function makeGroup(overrides: Partial<OptionGroup> = {}): OptionGroup {
  return {
    id: 1,
    name: "Size",
    description: null,
    kind: "variant",
    selection: "single",
    is_required: true,
    min_selections: 1,
    max_selections: 1,
    sort_order: 0,
    options: [],
    ...overrides,
  };
}

function makeOption(overrides: Partial<ProductOption> = {}): ProductOption {
  return {
    id: 1,
    name: "Large",
    price_delta: 2000,
    is_default: false,
    is_available: true,
    max_quantity: 1,
    sort_order: 0,
    ...overrides,
  };
}

const cart = () => useCartStore.getState();

describe("cart line merging", () => {
  it("merges a second identical add into one line", () => {
    const product = makeProduct();

    cart().addLine({ product, quantity: 1, options: [] });
    cart().addLine({ product, quantity: 2, options: [] });

    expect(cart().lines).toHaveLength(1);
    expect(cart().lines[0].quantity).toBe(3);
  });

  it("keeps lines separate when the options differ", () => {
    const product = makeProduct();
    const group = makeGroup();

    cart().addLine({
      product,
      quantity: 1,
      options: [{ group, option: makeOption({ id: 1, name: "Large" }) }],
    });
    cart().addLine({
      product,
      quantity: 1,
      options: [{ group, option: makeOption({ id: 2, name: "Small", price_delta: 0 }) }],
    });

    // A large and a small burger are two things the kitchen must make
    // separately, even though they share a product id.
    expect(cart().lines).toHaveLength(2);
  });

  it("keeps lines separate when only the note differs", () => {
    const product = makeProduct();

    cart().addLine({ product, quantity: 1, options: [], specialInstructions: "No onion" });
    cart().addLine({ product, quantity: 1, options: [] });

    expect(cart().lines).toHaveLength(2);
  });

  it("merges regardless of the order the options were chosen in", () => {
    const product = makeProduct();
    const size = makeGroup({ id: 1, name: "Size" });
    const extras = makeGroup({ id: 2, name: "Extras", kind: "addon" });
    const large = makeOption({ id: 1, name: "Large" });
    const cheese = makeOption({ id: 2, name: "Cheese", price_delta: 1000 });

    cart().addLine({
      product,
      quantity: 1,
      options: [
        { group: size, option: large },
        { group: extras, option: cheese },
      ],
    });
    cart().addLine({
      product,
      quantity: 1,
      options: [
        { group: extras, option: cheese },
        { group: size, option: large },
      ],
    });

    expect(cart().lines).toHaveLength(1);
    expect(cart().lines[0].quantity).toBe(2);
  });

  it("treats a blank note as no note", () => {
    const product = makeProduct();

    cart().addLine({ product, quantity: 1, options: [], specialInstructions: "   " });
    cart().addLine({ product, quantity: 1, options: [], specialInstructions: null });

    expect(cart().lines).toHaveLength(1);
    expect(cart().lines[0].specialInstructions).toBeNull();
  });

  it("caps a line at 50 so a stuck button cannot order a hundred burgers", () => {
    const product = makeProduct();

    cart().addLine({ product, quantity: 40, options: [] });
    cart().addLine({ product, quantity: 40, options: [] });

    expect(cart().lines[0].quantity).toBe(50);
  });
});

describe("cart mutation", () => {
  it("removes a line when its quantity reaches zero", () => {
    const product = makeProduct();
    cart().addLine({ product, quantity: 2, options: [] });

    cart().setQuantity(cart().lines[0].key, 0);

    expect(cart().lines).toHaveLength(0);
  });

  it("drops the coupon when the cart is cleared", () => {
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });
    cart().setCoupon("WELCOME10");

    cart().clear();

    expect(cart().lines).toHaveLength(0);
    expect(cart().couponCode).toBeNull();
  });

  it("empties the cart when the branch changes", () => {
    cart().setBranch(1);
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });
    cart().setCoupon("WELCOME10");

    cart().setBranch(2);

    // Prices and availability are per-branch, so carrying items over would
    // quote the wrong restaurant's menu.
    expect(cart().lines).toHaveLength(0);
    expect(cart().couponCode).toBeNull();
    expect(cart().branchId).toBe(2);
  });

  it("keeps the cart when the branch is set for the first time", () => {
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });

    cart().setBranch(1);

    expect(cart().lines).toHaveLength(1);
  });

  it("keeps the cart when the same branch is set again", () => {
    cart().setBranch(1);
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });

    cart().setBranch(1);

    expect(cart().lines).toHaveLength(1);
  });
});

describe("cart totals", () => {
  it("counts items rather than lines", () => {
    cart().addLine({ product: makeProduct({ id: 1 }), quantity: 2, options: [] });
    cart().addLine({ product: makeProduct({ id: 2, slug: "fries" }), quantity: 3, options: [] });

    expect(cart().itemCount()).toBe(5);
  });

  it("includes option deltas in the estimate", () => {
    cart().addLine({
      product: makeProduct({ base_price: 10000 }),
      quantity: 2,
      options: [{ group: makeGroup(), option: makeOption({ price_delta: 2000 }) }],
    });

    // (10000 + 2000) * 2
    expect(cart().estimatedTotal()).toBe(24000);
  });

  it("multiplies an option taken more than once", () => {
    cart().addLine({
      product: makeProduct({ base_price: 10000 }),
      quantity: 1,
      options: [
        {
          group: makeGroup({ kind: "addon" }),
          option: makeOption({ price_delta: 1000, max_quantity: 3 }),
          quantity: 3,
        },
      ],
    });

    expect(cart().estimatedTotal()).toBe(13000);
  });
});

describe("reorder", () => {
  it("rebuilds a line from a snapshot and merges it with an identical one", () => {
    const snapshot = {
      productId: 1,
      slug: "smash-burger",
      name: "Smash Burger",
      imageUrl: null,
      quantity: 1,
      basePriceHint: 10000,
      options: [],
      specialInstructions: null,
    };

    cart().addRawLine(snapshot);
    cart().addRawLine(snapshot);

    expect(cart().lines).toHaveLength(1);
    expect(cart().lines[0].quantity).toBe(2);
  });

  it("produces the same key as a live add, so the two merge", () => {
    const product = makeProduct();

    cart().addLine({ product, quantity: 1, options: [] });
    cart().addRawLine({
      productId: product.id,
      slug: product.slug,
      name: product.name,
      imageUrl: null,
      quantity: 1,
      basePriceHint: product.base_price,
      options: [],
      specialInstructions: null,
    });

    expect(cart().lines).toHaveLength(1);
  });
});

describe("table context", () => {
  it("switches to dine-in when a table is scanned", () => {
    cart().setOrderType("takeaway");

    cart().setTableContext({ tableToken: "abc", sessionToken: "sess", tableNumber: "7", branchId: 1 });

    expect(cart().orderType).toBe("dine_in");
    expect(cart().tableNumber).toBe("7");
    expect(cart().branchId).toBe(1);
  });

  it("is excluded from what gets persisted", () => {
    cart().setTableContext({ tableToken: "abc", sessionToken: "sess", tableNumber: "7" });
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });

    const persisted = JSON.parse(localStorage.getItem("viking.cart") ?? "{}");

    // A table session belongs to this visit. Restoring it tomorrow would put
    // the customer at a table they are not sitting at.
    expect(persisted.state.lines).toHaveLength(1);
    expect(persisted.state.tableSessionToken).toBeUndefined();
    expect(persisted.state.tableNumber).toBeUndefined();
  });

  it("clears the table without clearing the cart", () => {
    cart().setTableContext({ tableToken: "abc", sessionToken: "sess", tableNumber: "7" });
    cart().addLine({ product: makeProduct(), quantity: 1, options: [] });

    cart().clearTableContext();

    expect(cart().tableSessionToken).toBeNull();
    expect(cart().lines).toHaveLength(1);
  });
});
