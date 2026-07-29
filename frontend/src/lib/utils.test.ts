import { describe, expect, it, vi } from "vitest";

import { clamp, cn, debounce, initials } from "./utils";

describe("cn", () => {
  it("lets a later utility win over an earlier one in the same group", () => {
    expect(cn("px-4", "px-6")).toBe("px-6");
  });

  it("keeps utilities from different groups", () => {
    expect(cn("px-4", "py-2")).toBe("px-4 py-2");
  });

  it("drops falsy values", () => {
    expect(cn("px-4", false && "hidden", undefined, null)).toBe("px-4");
  });
});

describe("debounce", () => {
  it("issues one call for a burst", async () => {
    vi.useFakeTimers();
    const spy = vi.fn();
    const debounced = debounce(spy, 100);

    debounced("a");
    debounced("b");
    debounced("c");

    vi.advanceTimersByTime(150);

    // Search-as-you-type must not fire once per keystroke.
    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith("c");
    vi.useRealTimers();
  });

  it("can be cancelled before it fires", () => {
    vi.useFakeTimers();
    const spy = vi.fn();
    const debounced = debounce(spy, 100);

    debounced("a");
    debounced.cancel();
    vi.advanceTimersByTime(150);

    expect(spy).not.toHaveBeenCalled();
    vi.useRealTimers();
  });
});

describe("clamp", () => {
  it("constrains to the range", () => {
    expect(clamp(5, 1, 10)).toBe(5);
    expect(clamp(-3, 1, 10)).toBe(1);
    expect(clamp(99, 1, 10)).toBe(10);
  });
});

describe("initials", () => {
  it("takes the first letter of the first two words", () => {
    expect(initials("Downtown Manager")).toBe("DM");
  });

  it("works for a single name", () => {
    expect(initials("Owner")).toBe("O");
  });

  it("ignores extra whitespace", () => {
    expect(initials("  Site   Admin  ")).toBe("SA");
  });

  it("works for Arabic names", () => {
    expect(initials("علي حسن")).toBe("عح");
  });
});
