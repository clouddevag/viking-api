import { describe, expect, it } from "vitest";

import {
  formatCompact,
  formatDate,
  formatElapsed,
  formatMoney,
  formatNumber,
  formatPercent,
  percentChange,
} from "./format";

/** Arabic-Indic digits. Their absence is the point of most of these tests. */
const ARABIC_INDIC = /[٠-٩]/;

describe("formatMoney", () => {
  it("renders IQD without decimals", () => {
    expect(formatMoney(24300, "IQD", "en")).toBe("IQD 24,300");
  });

  it("renders a decimal currency with two places", () => {
    expect(formatMoney(24.5, "USD", "en")).toBe("USD 24.50");
  });

  it("puts the currency after the amount in Arabic", () => {
    expect(formatMoney(24300, "IQD", "ar")).toBe("24,300 IQD");
  });

  it("keeps numerals Latin in Arabic", () => {
    // Arabic-Indic digits misalign in a tabular price column and are slower to
    // scan for most Iraqi customers, so `latn` is forced.
    expect(formatMoney(24300, "IQD", "ar")).not.toMatch(ARABIC_INDIC);
  });

  it("handles zero and negatives", () => {
    expect(formatMoney(0, "IQD", "en")).toBe("IQD 0");
    expect(formatMoney(-5000, "IQD", "en")).toBe("IQD -5,000");
  });

  it("falls back to two decimals for an unknown currency", () => {
    expect(formatMoney(10, "GBP", "en")).toBe("GBP 10.00");
  });
});

describe("number formatting", () => {
  it("compacts large figures for dashboard tiles", () => {
    expect(formatCompact(1_240_000, "en")).toBe("1.2M");
  });

  it("keeps Latin numerals in Arabic across every helper", () => {
    expect(formatNumber(1234, "ar")).not.toMatch(ARABIC_INDIC);
    expect(formatCompact(1_240_000, "ar")).not.toMatch(ARABIC_INDIC);
    expect(formatPercent(12.5, "ar")).not.toMatch(ARABIC_INDIC);
  });

  it("treats percent input as whole percentage points", () => {
    expect(formatPercent(12.5, "en")).toBe("12.5%");
  });
});

describe("formatElapsed", () => {
  const now = new Date("2026-07-29T12:10:30Z").getTime();

  it("renders MM:SS", () => {
    expect(formatElapsed("2026-07-29T12:00:00Z", now)).toBe("10:30");
  });

  it("zero-pads both halves", () => {
    expect(formatElapsed("2026-07-29T12:10:25Z", now)).toBe("00:05");
  });

  it("keeps counting past an hour rather than wrapping", () => {
    // The kitchen timer must read 90:00, not 30:00 — a ticket that old is the
    // whole point of the display.
    expect(formatElapsed("2026-07-29T10:40:30Z", now)).toBe("90:00");
  });

  it("clamps a future timestamp to zero instead of going negative", () => {
    expect(formatElapsed("2026-07-29T12:20:00Z", now)).toBe("00:00");
  });

  it("returns a zero clock for a missing timestamp", () => {
    expect(formatElapsed(null, now)).toBe("00:00");
  });
});

describe("null handling", () => {
  it("renders an em dash rather than 'Invalid Date'", () => {
    expect(formatDate(null, "en")).toBe("—");
  });
});

describe("percentChange", () => {
  it("computes an increase", () => {
    expect(percentChange(150, 100)).toBe(50);
  });

  it("computes a decrease", () => {
    expect(percentChange(75, 100)).toBe(-25);
  });

  it("returns null when there is no baseline to compare against", () => {
    // Rendering "+∞%" on a dashboard is worse than rendering nothing.
    expect(percentChange(100, 0)).toBeNull();
  });

  it("returns zero when both periods are zero", () => {
    expect(percentChange(0, 0)).toBe(0);
  });
});
