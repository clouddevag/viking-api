"use client";

import {
  ChevronLeft,
  ChevronRight,
  Download,
  Globe,
  LogOut,
  Moon,
  Shield,
  Smartphone,
  Sun,
  User as UserIcon,
} from "lucide-react";
import { useTheme } from "next-themes";
import Link from "next/link";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/primitives";
import { authApi } from "@/lib/api/endpoints";
import { disconnectEcho } from "@/lib/echo";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn, initials } from "@/lib/utils";

/**
 * Account and app preferences.
 *
 * Also hosts the PWA install prompt: browsers only fire `beforeinstallprompt`
 * once and expect it to be deferred, so it is captured here and replayed when
 * the customer actually asks to install.
 */

interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

export default function AccountPage() {
  const { t, locale, setLocale, isRtl } = useI18n();
  const { theme, setTheme } = useTheme();

  const user = useAuthStore((state) => state.user);
  const status = useAuthStore((state) => state.status);
  const signOut = useAuthStore((state) => state.signOut);

  const [installPrompt, setInstallPrompt] = useState<InstallPromptEvent | null>(null);
  const [mounted, setMounted] = useState(false);

  useEffect(() => setMounted(true), []);

  useEffect(() => {
    const capture = (event: Event) => {
      event.preventDefault();
      setInstallPrompt(event as InstallPromptEvent);
    };

    window.addEventListener("beforeinstallprompt", capture);
    return () => window.removeEventListener("beforeinstallprompt", capture);
  }, []);

  const handleSignOut = async () => {
    try {
      await authApi.logout();
    } catch {
      // A failed logout call still has to clear the local session — the token
      // may already have been revoked server-side.
    } finally {
      signOut();
      disconnectEcho();
      toast.success(t("auth.signedOut"));
    }
  };

  const Chevron = isRtl ? ChevronLeft : ChevronRight;

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <h1 className="mb-5 text-2xl font-semibold text-content">{t("account.title")}</h1>

      {/* Identity ---------------------------------------------------------- */}
      {status === "authenticated" && user ? (
        <Card className="flex items-center gap-4">
          <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-accent text-lg font-bold text-accent-contrast">
            {initials(user.name)}
          </span>

          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold text-content">{user.name}</p>
            <p className="truncate text-sm text-content-secondary" dir="ltr">
              {user.phone ?? user.email}
            </p>
          </div>

          {user.is_staff && (
            <Link
              href="/staff"
              className="shrink-0 rounded-lg bg-surface-sunken px-3 py-2 text-xs font-medium text-content"
            >
              {t("auth.staffSignIn")}
            </Link>
          )}
        </Card>
      ) : (
        <Card className="text-center">
          <div className="mx-auto grid size-14 place-items-center rounded-2xl bg-surface-sunken text-content-muted">
            <UserIcon className="size-6" aria-hidden />
          </div>
          <p className="mt-3 font-medium text-content">{t("auth.noAccount")}</p>
          <p className="mt-1 text-sm text-content-secondary">{t("orders.emptyHelp")}</p>

          <div className="mt-5 flex gap-3">
            <Link
              href="/auth/login"
              className="inline-flex h-11 flex-1 items-center justify-center rounded-xl bg-accent font-medium text-accent-contrast"
            >
              {t("auth.signIn")}
            </Link>
            <Link
              href="/auth/register"
              className="inline-flex h-11 flex-1 items-center justify-center rounded-xl bg-surface-sunken font-medium text-content"
            >
              {t("action.signUp")}
            </Link>
          </div>
        </Card>
      )}

      {/* Preferences -------------------------------------------------------- */}
      <section className="mt-6 space-y-3" aria-label={t("account.title")}>
        <Card className="p-0">
          <div className="flex items-center justify-between gap-4 p-4">
            <span className="flex items-center gap-3">
              <Globe className="size-5 text-content-muted" aria-hidden />
              <span className="font-medium text-content">{t("account.language")}</span>
            </span>

            <div className="flex rounded-lg bg-surface-sunken p-1" role="radiogroup">
              {(["ar", "en"] as const).map((code) => (
                <button
                  key={code}
                  type="button"
                  role="radio"
                  aria-checked={locale === code}
                  onClick={() => setLocale(code)}
                  className={cn(
                    "rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                    locale === code
                      ? "bg-surface-raised text-content shadow-sm"
                      : "text-content-muted",
                  )}
                >
                  {code === "ar" ? "العربية" : "English"}
                </button>
              ))}
            </div>
          </div>

          <div className="flex items-center justify-between gap-4 border-t border-border-subtle p-4">
            <span className="flex items-center gap-3">
              {mounted && theme === "dark" ? (
                <Moon className="size-5 text-content-muted" aria-hidden />
              ) : (
                <Sun className="size-5 text-content-muted" aria-hidden />
              )}
              <span className="font-medium text-content">{t("account.theme")}</span>
            </span>

            <div className="flex rounded-lg bg-surface-sunken p-1" role="radiogroup">
              {(["light", "dark", "system"] as const).map((option) => (
                <button
                  key={option}
                  type="button"
                  role="radio"
                  aria-checked={mounted && theme === option}
                  onClick={() => setTheme(option)}
                  className={cn(
                    "rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors",
                    mounted && theme === option
                      ? "bg-surface-raised text-content shadow-sm"
                      : "text-content-muted",
                  )}
                >
                  {t(`account.theme.${option}` as "account.theme.light")}
                </button>
              ))}
            </div>
          </div>
        </Card>

        {/* Install ---------------------------------------------------------- */}
        {installPrompt && (
          <Card className="flex items-center gap-4">
            <Smartphone className="size-5 shrink-0 text-accent" aria-hidden />
            <div className="min-w-0 flex-1">
              <p className="font-medium text-content">{t("account.installApp")}</p>
              <p className="text-sm text-content-secondary">{t("account.installHelp")}</p>
            </div>
            <Button
              size="sm"
              icon={<Download className="size-4" aria-hidden />}
              onClick={async () => {
                await installPrompt.prompt();
                const { outcome } = await installPrompt.userChoice;
                // The event is single-use; drop it either way.
                setInstallPrompt(null);
                if (outcome === "accepted") toast.success(t("account.installApp"));
              }}
            >
              {t("action.continue")}
            </Button>
          </Card>
        )}

        {status === "authenticated" && (
          <Card className="p-0">
            <Link
              href="/account/security"
              className="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-surface-sunken"
            >
              <span className="flex items-center gap-3">
                <Shield className="size-5 text-content-muted" aria-hidden />
                <span className="font-medium text-content">{t("account.changePassword")}</span>
              </span>
              <Chevron className="size-5 text-content-muted" aria-hidden />
            </Link>
          </Card>
        )}
      </section>

      {status === "authenticated" && (
        <Button
          variant="ghost"
          fullWidth
          className="mt-6 text-[var(--color-danger)]"
          icon={<LogOut className="size-4" aria-hidden />}
          onClick={handleSignOut}
        >
          {t("action.signOut")}
        </Button>
      )}
    </div>
  );
}
