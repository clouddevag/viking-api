"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from "react";

import {
  DEFAULT_LOCALE,
  LOCALE_META,
  dictionaries,
  type Locale,
  type TranslationKey,
} from "./dictionaries";

const STORAGE_KEY = "viking.locale";

/**
 * The stored locale is external state, so it is read through
 * `useSyncExternalStore` rather than copied into React state by an effect.
 * That keeps the server render deterministic (the snapshot is `null`, so the
 * caller's `initialLocale` wins) and, as a bonus, switching language in one
 * tab now updates the others.
 */
const localeListeners = new Set<() => void>();

function subscribeToLocale(listener: () => void): () => void {
  localeListeners.add(listener);
  window.addEventListener("storage", listener);

  return () => {
    localeListeners.delete(listener);
    window.removeEventListener("storage", listener);
  };
}

/** Returns a primitive, so repeated calls are referentially stable. */
function readStoredLocale(): Locale | null {
  const stored = window.localStorage.getItem(STORAGE_KEY);
  return stored === "ar" || stored === "en" ? stored : null;
}

/** Nothing is stored during SSR, so the caller's default applies. */
const serverLocale = (): Locale | null => null;

function writeStoredLocale(next: Locale): void {
  window.localStorage.setItem(STORAGE_KEY, next);
  // The API returns localized menu content, so it needs to know too.
  document.cookie = `viking_locale=${next};path=/;max-age=31536000;samesite=lax`;
  // `storage` does not fire in the tab that wrote it.
  localeListeners.forEach((listener) => listener());
}

type Interpolations = Record<string, string | number>;

interface I18nContextValue {
  locale: Locale;
  dir: "rtl" | "ltr";
  isRtl: boolean;
  setLocale: (locale: Locale) => void;
  t: (key: TranslationKey, values?: Interpolations) => string;
}

const I18nContext = createContext<I18nContextValue | null>(null);

/**
 * Locale state, persisted to localStorage and mirrored onto `<html lang/dir>`.
 *
 * The direction lives on the document element rather than a wrapper div so that
 * portalled UI — dialogs, toasts, dropdowns — inherits it too; a modal rendered
 * into document.body would otherwise pop out of RTL.
 */
export function I18nProvider({
  children,
  initialLocale = DEFAULT_LOCALE,
}: {
  children: ReactNode;
  initialLocale?: Locale;
}) {
  const stored = useSyncExternalStore(subscribeToLocale, readStoredLocale, serverLocale);
  const locale = stored ?? initialLocale;

  useEffect(() => {
    const meta = LOCALE_META[locale];
    document.documentElement.lang = meta.htmlLang;
    document.documentElement.dir = meta.dir;
  }, [locale]);

  const setLocale = useCallback((next: Locale) => writeStoredLocale(next), []);

  const t = useCallback(
    (key: TranslationKey, values?: Interpolations) => {
      const dictionary = dictionaries[locale] as Record<string, string>;
      // Fall back to English rather than rendering the raw key at the user.
      const template = dictionary[key] ?? (dictionaries.en as Record<string, string>)[key] ?? key;

      if (!values) return template;

      return Object.entries(values).reduce(
        (text, [name, value]) => text.replaceAll(`{${name}}`, String(value)),
        template,
      );
    },
    [locale],
  );

  const value = useMemo<I18nContextValue>(
    () => ({
      locale,
      dir: LOCALE_META[locale].dir,
      isRtl: LOCALE_META[locale].dir === "rtl",
      setLocale,
      t,
    }),
    [locale, setLocale, t],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextValue {
  const context = useContext(I18nContext);

  if (!context) {
    throw new Error("useI18n must be used inside <I18nProvider>");
  }

  return context;
}

/** Shorthand for the common case of only needing the translate function. */
export function useT() {
  return useI18n().t;
}
