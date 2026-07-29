"use client";

import { Percent } from "lucide-react";
import { useState } from "react";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Badge } from "@/components/ui/primitives";
import { useAdminOffers } from "@/hooks/queries";
import { formatDate, formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import type { Offer } from "@/types/api";

export default function AdminOffersPage() {
  const { t, locale } = useI18n();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useAdminOffers({ page });

  const columns: Array<Column<Offer>> = [
    {
      key: "title",
      header: t("admin.offers"),
      cell: (offer) => (
        <span>
          <span className="block font-medium text-content">{offer.title}</span>
          {offer.badge && <span className="block text-xs text-content-muted">{offer.badge}</span>}
        </span>
      ),
    },
    {
      key: "type",
      header: "—",
      cell: (offer) => <Badge tone="accent">{offer.type}</Badge>,
    },
    {
      key: "value",
      header: t("cart.discount"),
      cell: (offer) => (
        <span className="tabular">
          {offer.discount_type === "percentage"
            ? `${offer.discount_value}%`
            : offer.discount_value
              ? formatMoney(offer.discount_value, "IQD", locale)
              : offer.combo_price
                ? formatMoney(offer.combo_price, "IQD", locale)
                : "—"}
        </span>
      ),
    },
    {
      key: "window",
      header: "—",
      secondary: true,
      cell: (offer) => (
        <span className="text-content-muted">
          {formatDate(offer.starts_at, locale)} → {formatDate(offer.ends_at, locale)}
        </span>
      ),
    },
    {
      key: "live",
      header: t("admin.active"),
      cell: (offer) => (
        <Badge tone={offer.is_live ? "success" : "neutral"}>
          {offer.is_live ? t("admin.active") : t("admin.inactive")}
        </Badge>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={t("admin.offers")} />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(offer) => offer.id}
        emptyTitle={t("state.empty")}
        emptyIcon={<Percent className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />
    </>
  );
}
