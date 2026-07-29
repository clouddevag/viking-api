"use client";

import { AnimatePresence, motion } from "framer-motion";
import {
  Globe,
  Heart,
  Home,
  Moon,
  Receipt,
  Search,
  ShoppingBag,
  Sun,
  User as UserIcon,
  UtensilsCrossed,
} from "lucide-react";
import { useTheme } from "next-themes";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";

import { useMounted } from "@/hooks/use-mounted";
import { useI18n } from "@/lib/i18n/provider";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * The customer app chrome: a slim top bar, a persistent bottom navigation on
 * phones, and an offline banner.
 *
 * Bottom navigation rather than a hamburger because this is used one-handed at
 * a table — the five destinations sit inside thumb reach.
 */

const NAV_ITEMS: Array<{ href: string; labelKey: TranslationKey; icon: typeof Home }> = [
  { href: "/", labelKey: "nav.home", icon: Home },
  { href: "/menu", labelKey: "nav.menu", icon: UtensilsCrossed },
  { href: "/cart", labelKey: "nav.cart", icon: ShoppingBag },
  { href: "/orders", labelKey: "nav.orders", icon: Receipt },
  { href: "/account", labelKey: "nav.account", icon: UserIcon },
];

export function CustomerShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-dvh flex-col bg-surface">
      <OfflineBanner />
      <TopBar />

      <main id="main" className="flex-1 pb-24 md:pb-8">
        {children}
      </main>

      <BottomNav />
    </div>
  );
}

function TopBar() {
  const { t, locale, setLocale } = useI18n();
  const tableNumber = useCartStore((state) => state.tableNumber);

  return (
    <header className="sticky top-0 z-30 border-b border-border-subtle bg-surface/85 backdrop-blur-lg">
      <div className="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
        <Link href="/" className="flex items-center gap-2 font-display text-xl tracking-tight">
          <span className="grid size-8 place-items-center rounded-lg bg-accent text-accent-contrast">
            <UtensilsCrossed className="size-4" aria-hidden />
          </span>
          <span className="text-content">{t("app.name")}</span>
        </Link>

        {/* The table badge is the guest's anchor: it confirms the QR scan
            worked and which table their order will reach. */}
        {tableNumber && (
          <span className="rounded-full bg-accent-soft px-3 py-1 text-xs font-medium text-accent">
            {t("table.title", { number: tableNumber })}
          </span>
        )}

        <div className="ms-auto flex items-center gap-1">
          <Link
            href="/menu?focus=search"
            aria-label={t("action.search")}
            className="grid size-10 place-items-center rounded-xl text-content-secondary transition-colors hover:bg-surface-sunken hover:text-content"
          >
            <Search className="size-5" aria-hidden />
          </Link>

          <Link
            href="/favorites"
            aria-label={t("nav.favorites")}
            className="grid size-10 place-items-center rounded-xl text-content-secondary transition-colors hover:bg-surface-sunken hover:text-content"
          >
            <Heart className="size-5" aria-hidden />
          </Link>

          <button
            type="button"
            onClick={() => setLocale(locale === "ar" ? "en" : "ar")}
            aria-label={t("account.language")}
            className="flex h-10 items-center gap-1.5 rounded-xl px-2.5 text-sm font-medium text-content-secondary transition-colors hover:bg-surface-sunken hover:text-content"
          >
            <Globe className="size-4" aria-hidden />
            {locale === "ar" ? "EN" : "ع"}
          </button>

          <ThemeToggle />
        </div>
      </div>
    </header>
  );
}

export function ThemeToggle() {
  const { resolvedTheme, setTheme } = useTheme();
  const { t } = useI18n();

  // The resolved theme is unknown during SSR, so render a placeholder of the
  // same size to avoid a layout shift once it settles.
  const mounted = useMounted();

  if (!mounted) {
    return <div className="size-10" aria-hidden />;
  }

  const isDark = resolvedTheme === "dark";

  return (
    <button
      type="button"
      onClick={() => setTheme(isDark ? "light" : "dark")}
      aria-label={t("account.theme")}
      className="grid size-10 place-items-center rounded-xl text-content-secondary transition-colors hover:bg-surface-sunken hover:text-content"
    >
      {isDark ? <Sun className="size-5" aria-hidden /> : <Moon className="size-5" aria-hidden />}
    </button>
  );
}

function BottomNav() {
  const pathname = usePathname();
  const { t } = useI18n();
  const itemCount = useCartStore((state) => state.lines.reduce((n, l) => n + l.quantity, 0));

  return (
    <nav
      aria-label={t("nav.menu")}
      className="fixed inset-x-0 bottom-0 z-30 border-t border-border-subtle bg-surface/90 pb-safe backdrop-blur-lg md:hidden"
    >
      <ul className="mx-auto flex max-w-lg">
        {NAV_ITEMS.map(({ href, labelKey, icon: Icon }) => {
          const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
          const isCart = href === "/cart";

          return (
            <li key={href} className="flex-1">
              <Link
                href={href}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "relative flex h-16 flex-col items-center justify-center gap-1 text-[0.65rem] font-medium transition-colors",
                  active ? "text-accent" : "text-content-muted",
                )}
              >
                <span className="relative">
                  <Icon className="size-5.5" aria-hidden />

                  {isCart && itemCount > 0 && (
                    <span
                      className="tabular absolute -end-2 -top-1.5 grid min-w-4.5 place-items-center rounded-full bg-accent px-1 text-[0.6rem] leading-4 font-bold text-accent-contrast"
                      aria-label={t("menu.itemCount", { count: itemCount })}
                    >
                      {itemCount > 99 ? "99+" : itemCount}
                    </span>
                  )}
                </span>

                {t(labelKey)}

                {active && (
                  <motion.span
                    layoutId="nav-indicator"
                    className="absolute inset-x-5 top-0 h-0.5 rounded-full bg-accent"
                  />
                )}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/**
 * Offline banner.
 *
 * The menu is served from the service worker cache, so the app stays usable —
 * this exists to explain why an order button will not submit yet.
 */
function OfflineBanner() {
  const { t } = useI18n();
  const [offline, setOffline] = useState(false);

  useEffect(() => {
    const update = () => setOffline(!navigator.onLine);
    update();

    window.addEventListener("online", update);
    window.addEventListener("offline", update);

    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);

  return (
    <AnimatePresence>
      {offline && (
        <motion.div
          initial={{ height: 0, opacity: 0 }}
          animate={{ height: "auto", opacity: 1 }}
          exit={{ height: 0, opacity: 0 }}
          role="status"
          className="overflow-hidden bg-[var(--color-warning)] text-center text-sm font-medium text-black"
        >
          <p className="px-4 py-2">
            {t("state.offline")} — {t("state.offlineHelp")}
          </p>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
