"use client";

import { ArrowDownRight, ArrowUpRight, Receipt, TrendingUp, Users, Wallet } from "lucide-react";
import Link from "next/link";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { PageHeader } from "@/components/admin/data-table";
import { Badge, Card, Skeleton } from "@/components/ui/primitives";
import { useDashboard } from "@/hooks/queries";
import { formatCompact, formatMoney, formatNumber, percentChange } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * Admin dashboard.
 *
 * Deliberately one API call: the endpoint returns today, yesterday, the month,
 * a 14-day trend, an hourly histogram, top sellers and the live queue together,
 * so the screen paints once rather than in seven staggered pieces.
 */
export default function AdminDashboardPage() {
  const { t, locale, isRtl } = useI18n();
  const user = useAuthStore((state) => state.user);
  const { data, isLoading } = useDashboard(user?.branch_id ?? undefined);

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title={t("admin.dashboard")} />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-28 rounded-2xl" />
          ))}
        </div>
        <Skeleton className="mt-4 h-72 rounded-2xl" />
      </>
    );
  }

  const revenueChange = percentChange(data.today.revenue, data.yesterday.revenue);
  const ordersChange = percentChange(data.today.orders, data.yesterday.orders);

  return (
    <>
      <PageHeader title={t("admin.dashboard")} description={t("admin.today")} />

      {/* Stat tiles ------------------------------------------------------- */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile
          icon={<Wallet className="size-5" aria-hidden />}
          label={t("admin.revenue")}
          value={formatMoney(data.today.revenue, "IQD", locale)}
          change={revenueChange}
        />
        <StatTile
          icon={<Receipt className="size-5" aria-hidden />}
          label={t("admin.ordersCount")}
          value={formatNumber(data.today.orders, locale)}
          change={ordersChange}
        />
        <StatTile
          icon={<TrendingUp className="size-5" aria-hidden />}
          label={t("admin.averageOrder")}
          value={formatMoney(data.today.average_order_value, "IQD", locale)}
        />
        <StatTile
          icon={<Users className="size-5" aria-hidden />}
          label={t("admin.guests")}
          value={formatNumber(data.today.guests, locale)}
        />
      </div>

      {/* Trend ------------------------------------------------------------- */}
      <section className="mt-4 grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <h2 className="mb-4 text-base font-semibold text-content">{t("admin.last14Days")}</h2>

          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={data.trend} margin={{ top: 4, right: 4, bottom: 0, left: 4 }}>
                <defs>
                  <linearGradient id="revenue" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--accent)" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="var(--accent)" stopOpacity={0} />
                  </linearGradient>
                </defs>

                <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" vertical={false} />

                <XAxis
                  dataKey="date"
                  tick={{ fontSize: 11, fill: "var(--text-muted)" }}
                  tickLine={false}
                  axisLine={false}
                  // Only the day number: full dates crowd out at 14 points.
                  tickFormatter={(value: string) => value.slice(-2)}
                  reversed={isRtl}
                />

                <YAxis
                  tick={{ fontSize: 11, fill: "var(--text-muted)" }}
                  tickLine={false}
                  axisLine={false}
                  width={44}
                  orientation={isRtl ? "right" : "left"}
                  tickFormatter={(value: number) => formatCompact(value, locale)}
                />

                <Tooltip
                  contentStyle={{
                    background: "var(--surface-raised)",
                    border: "1px solid var(--border-subtle)",
                    borderRadius: 12,
                    fontSize: 12,
                  }}
                  formatter={(value) => formatMoney(Number(value ?? 0), "IQD", locale)}
                />

                <Area
                  type="monotone"
                  dataKey="revenue"
                  stroke="var(--accent)"
                  strokeWidth={2}
                  fill="url(#revenue)"
                />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        {/* Top sellers ---------------------------------------------------- */}
        <Card>
          <h2 className="mb-4 text-base font-semibold text-content">{t("admin.topProducts")}</h2>

          <ol className="space-y-3">
            {data.top_products.slice(0, 6).map((product, index) => (
              <li key={product.product_id} className="flex items-center gap-3">
                <span className="tabular grid size-6 shrink-0 place-items-center rounded-md bg-surface-sunken text-xs font-bold text-content-secondary">
                  {index + 1}
                </span>

                <span className="min-w-0 flex-1">
                  <span className="clamp-1 block text-sm font-medium text-content">
                    {product.name}
                  </span>
                  <span className="tabular text-xs text-content-muted">
                    {formatNumber(product.units, locale)} × ·{" "}
                    {formatMoney(product.revenue, "IQD", locale)}
                  </span>
                </span>
              </li>
            ))}
          </ol>
        </Card>
      </section>

      {/* Hourly + live queue ---------------------------------------------- */}
      <section className="mt-4 grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <h2 className="mb-4 text-base font-semibold text-content">{t("admin.byHour")}</h2>

          <div className="h-48">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.hourly} margin={{ top: 4, right: 4, bottom: 0, left: 4 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" vertical={false} />
                <XAxis
                  dataKey="hour"
                  tick={{ fontSize: 10, fill: "var(--text-muted)" }}
                  tickLine={false}
                  axisLine={false}
                  interval={2}
                  reversed={isRtl}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: "var(--text-muted)" }}
                  tickLine={false}
                  axisLine={false}
                  width={30}
                  allowDecimals={false}
                  orientation={isRtl ? "right" : "left"}
                />
                <Tooltip
                  cursor={{ fill: "var(--surface-sunken)" }}
                  contentStyle={{
                    background: "var(--surface-raised)",
                    border: "1px solid var(--border-subtle)",
                    borderRadius: 12,
                    fontSize: 12,
                  }}
                />
                <Bar dataKey="orders" fill="var(--accent)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card>
          <h2 className="mb-4 text-base font-semibold text-content">{t("admin.liveOrders")}</h2>

          {data.live_orders.length === 0 ? (
            <p className="py-6 text-center text-sm text-content-muted">{t("state.empty")}</p>
          ) : (
            <ul className="space-y-2">
              {data.live_orders.slice(0, 8).map((order) => (
                <li key={order.id}>
                  <Link
                    href={`/admin/orders/${order.order_number}`}
                    className="flex items-center justify-between gap-2 rounded-lg px-2 py-2 transition-colors hover:bg-surface-sunken"
                  >
                    <span className="min-w-0">
                      <span className="tabular block truncate text-sm font-medium text-content">
                        {order.order_number}
                      </span>
                      {order.table && (
                        <span className="text-xs text-content-muted">
                          {t("table.title", { number: order.table.number })}
                        </span>
                      )}
                    </span>

                    <Badge tone={order.age_minutes > 20 ? "danger" : "accent"}>
                      {t(`status.${order.status}` as TranslationKey)}
                    </Badge>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </section>
    </>
  );
}

function StatTile({
  icon,
  label,
  value,
  change,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  change?: number | null;
}) {
  const { t, locale } = useI18n();

  return (
    <Card className="flex items-start gap-4">
      <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-accent-soft text-accent">
        {icon}
      </span>

      <div className="min-w-0">
        <p className="text-sm text-content-secondary">{label}</p>
        <p className="tabular mt-0.5 text-xl font-bold text-content">{value}</p>

        {/* A null change means yesterday was zero — showing "+∞%" would be
            noise, so the comparison is simply omitted. */}
        {change !== null && change !== undefined && (
          <p
            className={cn(
              "mt-1 flex items-center gap-1 text-xs font-medium",
              change >= 0 ? "text-[var(--color-success)]" : "text-[var(--color-danger)]",
            )}
          >
            {change >= 0 ? (
              <ArrowUpRight className="size-3.5" aria-hidden />
            ) : (
              <ArrowDownRight className="size-3.5" aria-hidden />
            )}
            <span className="tabular">
              {new Intl.NumberFormat(locale === "ar" ? "ar-IQ-u-nu-latn" : "en", {
                maximumFractionDigits: 1,
              }).format(Math.abs(change))}
              %
            </span>
            <span className="text-content-muted">{t("admin.vsYesterday")}</span>
          </p>
        )}
      </div>
    </Card>
  );
}
