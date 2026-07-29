"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
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
  const [locale, setLocaleState] = useState<Locale>(initialLocale);

  // Read the stored preference after mount. Doing it during render would
  // produce different markup on server and client and trip hydration.
  useEffect(() => {
    const stored = window.localStorage.getItem(STORAGE_KEY);

    if (stored === "ar" || stored === "en") {
      setLocaleState(stored);
    }
  }, []);

  useEffect(() => {
    const meta = LOCALE_META[locale];
    document.documentElement.lang = meta.htmlLang;
    document.documentElement.dir = meta.dir;
  }, [locale]);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    window.localStorage.setItem(STORAGE_KEY, next);
    // The API returns localized menu content, so it needs to know too.
    document.cookie = `viking_locale=${next};path=/;max-age=31536000;samesite=lax`;
  }, []);

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
