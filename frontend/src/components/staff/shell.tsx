"use client";

import { Loader2, LogOut, Moon, Sun } from "lucide-react";
import { useTheme } from "next-themes";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";

import { authApi } from "@/lib/api/endpoints";
import { useMounted } from "@/hooks/use-mounted";
import { disconnectEcho } from "@/lib/echo";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn, initials } from "@/lib/utils";

/**
 * Shell for every staff surface (kitchen, cashier, admin).
 *
 * Also the access gate: it waits for the session restore to finish before
 * deciding, because redirecting on a still-loading auth state would bounce a
 * legitimately signed-in cook back to the login screen on every refresh.
 */
export function StaffGuard({
  permission,
  children,
}: {
  permission: string;
  children: ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const status = useAuthStore((state) => state.status);
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);

  useEffect(() => {
    if (status === "idle" || status === "loading") return;

    if (status === "anonymous") {
      router.replace(`/auth/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [status, pathname, router]);

  if (status === "idle" || status === "loading") {
    return (
      <div className="grid min-h-dvh place-items-center bg-surface">
        <Loader2 className="size-8 animate-spin text-accent" aria-hidden />
      </div>
    );
  }

  if (status === "anonymous") return null;

  if (!can(permission)) {
    return (
      <div className="grid min-h-dvh place-items-center bg-surface px-6 text-center">
        <div>
          <h1 className="text-xl font-semibold text-content">403</h1>
          <p className="mt-2 text-content-secondary">
            {user?.name} — you do not have access to this screen.
          </p>
          <Link
            href="/"
            className="mt-6 inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-accent-contrast"
          >
            Home
          </Link>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}

/**
 * Top bar shared by the staff screens.
 *
 * Deliberately dense and dark: these run all shift on a wall-mounted tablet, so
 * chrome gets as little vertical space as possible and the surface behind it
 * never glows white in a dim kitchen.
 */
export function StaffTopBar({
  title,
  branchName,
  children,
}: {
  title: string;
  branchName?: string | null;
  children?: ReactNode;
}) {
  const { t, locale, setLocale } = useI18n();
  const { resolvedTheme, setTheme } = useTheme();
  const mounted = useMounted();
  const user = useAuthStore((state) => state.user);
  const signOut = useAuthStore((state) => state.signOut);
  const router = useRouter();

  const handleSignOut = async () => {
    try {
      await authApi.logout();
    } catch {
      // The local session must clear even if the revoke call fails.
    } finally {
      signOut();
      disconnectEcho();
      router.replace("/auth/login");
    }
  };

  return (
    <header className="sticky top-0 z-30 border-b border-border-subtle bg-surface-raised/90 backdrop-blur-lg">
      <div className="flex h-14 items-center gap-3 px-4">
        <h1 className="text-lg font-semibold text-content">{title}</h1>

        {branchName && (
          <span className="rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-medium text-content-secondary">
            {branchName}
          </span>
        )}

        <div className="ms-auto flex items-center gap-1.5">
          {children}

          <button
            type="button"
            onClick={() => setLocale(locale === "ar" ? "en" : "ar")}
            className="h-9 rounded-lg px-2.5 text-sm font-medium text-content-secondary hover:bg-surface-sunken"
            aria-label={t("account.language")}
          >
            {locale === "ar" ? "EN" : "ع"}
          </button>

          {mounted && (
            <button
              type="button"
              onClick={() => setTheme(resolvedTheme === "dark" ? "light" : "dark")}
              aria-label={t("account.theme")}
              className="grid size-9 place-items-center rounded-lg text-content-secondary hover:bg-surface-sunken"
            >
              {resolvedTheme === "dark" ? (
                <Sun className="size-4.5" aria-hidden />
              ) : (
                <Moon className="size-4.5" aria-hidden />
              )}
            </button>
          )}

          {user && (
            <span
              className="grid size-9 place-items-center rounded-lg bg-accent text-xs font-bold text-accent-contrast"
              title={user.name}
            >
              {initials(user.name)}
            </span>
          )}

          <button
            type="button"
            onClick={handleSignOut}
            aria-label={t("action.signOut")}
            className="grid size-9 place-items-center rounded-lg text-content-secondary hover:bg-surface-sunken hover:text-[var(--color-danger)]"
          >
            <LogOut className="size-4.5" aria-hidden />
          </button>
        </div>
      </div>
    </header>
  );
}

/** Sidebar navigation used by the admin panel. */
export function StaffSidebar({
  items,
  className,
}: {
  items: Array<{ href: string; label: string; icon: ReactNode; badge?: number }>;
  className?: string;
}) {
  const pathname = usePathname();

  return (
    <nav className={cn("space-y-0.5", className)} aria-label="Admin">
      {items.map((item) => {
        // Exact match for the index route, prefix match for the rest, so
        // /admin does not light up while on /admin/orders.
        const active =
          item.href === "/admin" ? pathname === "/admin" : pathname.startsWith(item.href);

        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors",
              active
                ? "bg-accent-soft text-accent"
                : "text-content-secondary hover:bg-surface-sunken hover:text-content",
            )}
          >
            <span className="shrink-0">{item.icon}</span>
            <span className="truncate">{item.label}</span>

            {item.badge !== undefined && item.badge > 0 && (
              <span className="tabular ms-auto rounded-full bg-accent px-1.5 py-0.5 text-[0.65rem] font-bold text-accent-contrast">
                {item.badge}
              </span>
            )}
          </Link>
        );
      })}
    </nav>
  );
}
