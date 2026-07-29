"use client";

import {
  Activity,
  BarChart3,
  ClipboardList,
  Image as ImageIcon,
  LayoutDashboard,
  Menu as MenuIcon,
  Percent,
  Settings,
  Shapes,
  ShoppingBag,
  Star,
  Tag,
  UsersRound,
  UtensilsCrossed,
  X,
} from "lucide-react";
import { useState } from "react";

import { StaffGuard, StaffSidebar, StaffTopBar } from "@/components/staff/shell";
import { useAdminRealtime } from "@/hooks/use-realtime";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";

/**
 * Admin shell.
 *
 * Sidebar entries are filtered by permission, so a branch manager sees a
 * genuinely smaller panel rather than a full menu with items that 403 — the
 * navigation reflects what the API will actually allow.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <StaffGuard permission="dashboard.view">
      <AdminChrome>{children}</AdminChrome>
    </StaffGuard>
  );
}

function AdminChrome({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const [navOpen, setNavOpen] = useState(false);

  useAdminRealtime(user?.branch_id ?? undefined);

  const items = [
    { href: "/admin", label: t("admin.dashboard"), icon: <LayoutDashboard className="size-4.5" />, show: can("dashboard.view") },
    { href: "/admin/orders", label: t("admin.orders"), icon: <ShoppingBag className="size-4.5" />, show: can("orders.view") },
    { href: "/admin/products", label: t("admin.products"), icon: <UtensilsCrossed className="size-4.5" />, show: can("products.view") },
    { href: "/admin/categories", label: t("admin.categories"), icon: <Shapes className="size-4.5" />, show: can("products.view") },
    { href: "/admin/tables", label: t("admin.tables"), icon: <ClipboardList className="size-4.5" />, show: can("tables.view") },
    { href: "/admin/coupons", label: t("admin.coupons"), icon: <Tag className="size-4.5" />, show: can("coupons.manage") },
    { href: "/admin/offers", label: t("admin.offers"), icon: <Percent className="size-4.5" />, show: can("offers.manage") },
    { href: "/admin/reports", label: t("admin.reports"), icon: <BarChart3 className="size-4.5" />, show: can("reports.view") },
    { href: "/admin/users", label: t("admin.users"), icon: <UsersRound className="size-4.5" />, show: can("users.view") },
    { href: "/admin/media", label: t("admin.media"), icon: <ImageIcon className="size-4.5" />, show: can("media.manage") },
    { href: "/admin/reviews", label: t("admin.reviews"), icon: <Star className="size-4.5" />, show: can("reviews.moderate") },
    { href: "/admin/activity", label: t("admin.activity"), icon: <Activity className="size-4.5" />, show: can("activity.view") },
    { href: "/admin/settings", label: t("admin.settings"), icon: <Settings className="size-4.5" />, show: can("settings.manage") },
  ].filter((item) => item.show);

  return (
    <div className="flex min-h-dvh flex-col bg-surface-sunken">
      <StaffTopBar title={t("admin.title")} branchName={user?.branch?.name}>
        <button
          type="button"
          onClick={() => setNavOpen((open) => !open)}
          aria-expanded={navOpen}
          aria-label={t("nav.menu")}
          className="grid size-9 place-items-center rounded-lg text-content-secondary hover:bg-surface-sunken lg:hidden"
        >
          {navOpen ? <X className="size-4.5" aria-hidden /> : <MenuIcon className="size-4.5" aria-hidden />}
        </button>
      </StaffTopBar>

      <div className="flex flex-1">
        {/* Permanent on desktop, a slide-over on smaller screens. */}
        <aside
          className={cn(
            "w-60 shrink-0 border-e border-border-subtle bg-surface-raised p-3",
            "fixed inset-y-14 z-20 overflow-y-auto transition-transform lg:sticky lg:top-14 lg:h-[calc(100dvh-3.5rem)] lg:translate-x-0",
            navOpen ? "translate-x-0" : "-translate-x-full rtl:translate-x-full lg:rtl:translate-x-0",
          )}
        >
          <StaffSidebar items={items} />
        </aside>

        {navOpen && (
          <button
            type="button"
            aria-hidden
            tabIndex={-1}
            onClick={() => setNavOpen(false)}
            className="fixed inset-0 z-10 bg-[var(--surface-overlay)] lg:hidden"
          />
        )}

        <main id="main" className="min-w-0 flex-1 p-4 lg:p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
