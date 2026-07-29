import type { Locale } from "@/types/api";

/**
 * Formatting helpers.
 *
 * Money and dates both need locale awareness, but with one deliberate
 * exception: numerals stay Latin even in Arabic. Arabic-Indic digits in a price
 * column are slower to scan for most Iraqi customers and misalign in tabular
 * layouts, so `latn` is forced throughout.
 */

const CURRENCY_DECIMALS: Record<string, number> = {
  IQD: 0,
  USD: 2,
  EUR: 2,
  SAR: 2,
  AED: 2,
};

export function formatMoney(
  amount: number,
  currency = "IQD",
  locale: Locale = "ar",
): string {
  const decimals = CURRENCY_DECIMALS[currency] ?? 2;
  const tag = locale === "ar" ? "ar-IQ-u-nu-latn" : "en-US";

  const formatted = new Intl.NumberFormat(tag, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);

  // The ISO code reads better than a symbol for IQD, and stays unambiguous
  // when a venue switches currency.
  return locale === "ar" ? `${formatted} ${currency}` : `${currency} ${formatted}`;
}

/** Compact form for dashboard tiles: 1.2M rather than 1,240,000. */
export function formatCompact(value: number, locale: Locale = "ar"): string {
  const tag = locale === "ar" ? "ar-IQ-u-nu-latn" : "en-US";

  return new Intl.NumberFormat(tag, {
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(value);
}

export function formatNumber(value: number, locale: Locale = "ar"): string {
  const tag = locale === "ar" ? "ar-IQ-u-nu-latn" : "en-US";
  return new Intl.NumberFormat(tag).format(value);
}

export function formatPercent(value: number, locale: Locale = "ar"): string {
  const tag = locale === "ar" ? "ar-IQ-u-nu-latn" : "en-US";
  return new Intl.NumberFormat(tag, {
    style: "percent",
    maximumFractionDigits: 1,
  }).format(value / 100);
}

export function formatTime(iso: string | null, locale: Locale = "ar"): string {
  if (!iso) return "—";

  return new Intl.DateTimeFormat(locale === "ar" ? "ar-IQ-u-nu-latn" : "en-GB", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

export function formatDate(iso: string | null, locale: Locale = "ar"): string {
  if (!iso) return "—";

  return new Intl.DateTimeFormat(locale === "ar" ? "ar-IQ-u-nu-latn" : "en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
}

export function formatDateTime(iso: string | null, locale: Locale = "ar"): string {
  if (!iso) return "—";

  return `${formatDate(iso, locale)} · ${formatTime(iso, locale)}`;
}

/**
 * "3 minutes ago" style. Uses Intl.RelativeTimeFormat so both locales get
 * grammatical output rather than a hand-rolled string.
 */
export function formatRelative(iso: string | null, locale: Locale = "ar"): string {
  if (!iso) return "—";

  const formatter = new Intl.RelativeTimeFormat(
    locale === "ar" ? "ar-IQ-u-nu-latn" : "en",
    { numeric: "auto" },
  );

  const deltaSeconds = (new Date(iso).getTime() - Date.now()) / 1000;
  const absolute = Math.abs(deltaSeconds);

  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ["year", 31_536_000],
    ["month", 2_592_000],
    ["week", 604_800],
    ["day", 86_400],
    ["hour", 3_600],
    ["minute", 60],
  ];

  for (const [unit, seconds] of units) {
    if (absolute >= seconds) {
      return formatter.format(Math.round(deltaSeconds / seconds), unit);
    }
  }

  return formatter.format(Math.round(deltaSeconds), "second");
}

/** Elapsed minutes rendered as `MM:SS`, for the kitchen ticket timer. */
export function formatElapsed(since: string | null, now = Date.now()): string {
  if (!since) return "00:00";

  const seconds = Math.max(0, Math.floor((now - new Date(since).getTime()) / 1000));
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;

  return `${String(minutes).padStart(2, "0")}:${String(remainder).padStart(2, "0")}`;
}

/** Percentage change between two periods, guarding against a zero baseline. */
export function percentChange(current: number, previous: number): number | null {
  if (previous === 0) return current === 0 ? 0 : null;
  return ((current - previous) / previous) * 100;
}
