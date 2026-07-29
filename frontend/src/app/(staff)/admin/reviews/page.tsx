"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Check, Star, Trash2, X } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Badge } from "@/components/ui/primitives";
import { useAdminReviews } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { formatRelative } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";

type Review = Record<string, unknown>;

/**
 * Review moderation. Approving is what makes a review count towards the
 * product's public rating, so nothing is visible until a human says so.
 */
export default function AdminReviewsPage() {
  const { t, locale } = useI18n();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);

  const { data, isLoading } = useAdminReviews({ page });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "reviews"] });

  const act = async (action: "approve" | "reject" | "delete", id: number) => {
    try {
      if (action === "approve") await adminApi.approveReview(id);
      else if (action === "reject") await adminApi.rejectReview(id);
      else await adminApi.deleteReview(id);

      toast.success(t("admin.saved"));
      await refresh();
    } catch {
      toast.error(t("state.error"));
    }
  };

  const columns: Array<Column<Review>> = [
    {
      key: "rating",
      header: t("product.reviews"),
      cell: (review) => (
        <span className="flex items-center gap-0.5" aria-label={`${review.rating}/5`}>
          {Array.from({ length: 5 }, (_, i) => (
            <Star
              key={i}
              className={
                i < Number(review.rating)
                  ? "size-4 fill-[var(--color-gold-500)] text-[var(--color-gold-500)]"
                  : "size-4 text-border-strong"
              }
              aria-hidden
            />
          ))}
        </span>
      ),
    },
    {
      key: "product",
      header: t("admin.products"),
      cell: (review) => (
        <span className="text-content">
          {String((review.product as { name?: string })?.name ?? "—")}
        </span>
      ),
    },
    {
      key: "comment",
      header: "—",
      secondary: true,
      cell: (review) => (
        <span className="clamp-2 max-w-xs text-sm text-content-secondary">
          {String(review.comment ?? "—")}
        </span>
      ),
    },
    {
      key: "author",
      header: t("admin.users"),
      secondary: true,
      cell: (review) => (
        <span>
          <span className="block text-sm text-content">{String(review.author ?? "—")}</span>
          <span className="block text-xs text-content-muted">
            {formatRelative(review.created_at as string | null, locale)}
          </span>
        </span>
      ),
    },
    {
      key: "status",
      header: t("admin.active"),
      cell: (review) => (
        <Badge tone={review.is_approved ? "success" : "warning"}>
          {review.is_approved ? t("admin.active") : t("state.loading")}
        </Badge>
      ),
    },
    {
      key: "actions",
      header: "—",
      className: "text-end",
      cell: (review) => (
        <span className="flex justify-end gap-1">
          {!review.is_approved && (
            <button
              type="button"
              onClick={() => act("approve", Number(review.id))}
              aria-label={t("action.confirm")}
              className="grid size-9 place-items-center rounded-lg text-[var(--color-success)] hover:bg-surface-sunken"
            >
              <Check className="size-4" aria-hidden />
            </button>
          )}

          {Boolean(review.is_approved) && (
            <button
              type="button"
              onClick={() => act("reject", Number(review.id))}
              aria-label={t("action.cancel")}
              className="grid size-9 place-items-center rounded-lg text-content-muted hover:bg-surface-sunken"
            >
              <X className="size-4" aria-hidden />
            </button>
          )}

          <button
            type="button"
            onClick={() => {
              if (window.confirm(t("admin.deleteConfirm"))) act("delete", Number(review.id));
            }}
            aria-label={t("action.delete")}
            className="grid size-9 place-items-center rounded-lg text-content-muted hover:bg-surface-sunken hover:text-[var(--color-danger)]"
          >
            <Trash2 className="size-4" aria-hidden />
          </button>
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.reviews")}
        description={data ? `${data.meta.pending}` : undefined}
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(review) => String(review.id)}
        emptyTitle={t("product.noReviews")}
        emptyIcon={<Star className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />
    </>
  );
}
