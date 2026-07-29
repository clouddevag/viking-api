"use client";

import { ShoppingBag } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Badge, Input, Select } from "@/components/ui/primitives";
import { useAdminOrders } from "@/hooks/queries";
import { formatMoney, formatRelative } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import type { Order, OrderStatus } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const STATUS_TONE: Record<OrderStatus, "accent" | "success" | "danger" | "neutral" | "warning"> = {
  pending: "warning",
  confirmed: "accent",
  preparing: "accent",
  ready: "success",
  served: "success",
  completed: "neutral",
  cancelled: "danger",
};

export default function AdminOrdersPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const user = useAuthStore((state) => state.user);

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");

  const { data, isLoading } = useAdminOrders({
    page,
    search: search || undefined,
    // The API's filter syntax is `filter[column]`, applied server-side against
    // an allow-list.
    ...(status ? { "filter[status]": status } : {}),
    branch_id: user?.branch_id ?? undefined,
  });

  const columns: Array<Column<Order>> = [
    {
      key: "number",
      header: t("orders.orderNumber", { number: "" }).trim(),
      cell: (order) => <span className="tabular font-semibold">{order.order_number}</span>,
    },
    {
      key: "customer",
      header: t("checkout.name"),
      secondary: true,
      cell: (order) => (
        <span className="text-content-secondary">
          {order.customer_name ?? "—"}
          {order.table && ` · ${t("table.title", { number: order.table.number })}`}
        </span>
      ),
    },
    {
      key: "status",
      header: t("admin.orders"),
      cell: (order) => (
        <Badge tone={STATUS_TONE[order.status]}>
          {t(`status.${order.status}` as TranslationKey)}
        </Badge>
      ),
    },
    {
      key: "payment",
      header: t("payment.paid"),
      secondary: true,
      cell: (order) => (
        <Badge tone={order.payment_status === "paid" ? "success" : "neutral"}>
          {t(`payment.${order.payment_status}` as TranslationKey)}
        </Badge>
      ),
    },
    {
      key: "total",
      header: t("cart.total"),
      className: "text-end",
      cell: (order) => (
        <span className="tabular font-semibold text-accent">
          {formatMoney(order.totals.grand_total, order.totals.currency, locale)}
        </span>
      ),
    },
    {
      key: "placed",
      header: t("orders.placedAt", { time: "" }).trim(),
      secondary: true,
      cell: (order) => (
        <span className="text-content-muted">{formatRelative(order.placed_at, locale)}</span>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={t("admin.orders")} />

      <div className="mb-4 flex flex-wrap gap-3">
        <Input
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
          placeholder={t("cashier.lookupPlaceholder")}
          aria-label={t("action.search")}
          className="max-w-xs"
        />

        <Select
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
          }}
          aria-label={t("admin.orders")}
          className="w-auto"
        >
          <option value="">{t("menu.allCategories")}</option>
          {(
            ["pending", "confirmed", "preparing", "ready", "served", "completed", "cancelled"] as const
          ).map((value) => (
            <option key={value} value={value}>
              {t(`status.${value}` as TranslationKey)}
            </option>
          ))}
        </Select>
      </div>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(order) => order.id}
        onRowClick={(order) => router.push(`/admin/orders/${order.order_number}`)}
        emptyTitle={t("orders.empty")}
        emptyIcon={<ShoppingBag className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />
    </>
  );
}
