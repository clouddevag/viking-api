"use client";

import { ChevronLeft, ChevronRight, Receipt, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { Badge, Card, EmptyState, Skeleton } from "@/components/ui/primitives";
import { useOrders } from "@/hooks/queries";
import { orderApi } from "@/lib/api/endpoints";
import { formatMoney, formatRelative } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";
import type { OrderStatus } from "@/types/api";
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

export default function OrderHistoryPage() {
  const { t, locale, isRtl } = useI18n();
  const router = useRouter();
  const { data, isLoading } = useOrders();

  const addRawLine = useCartStore((state) => state.addRawLine);
  const [reordering, setReordering] = useState<string | null>(null);
  const Chevron = isRtl ? ChevronLeft : ChevronRight;

  /**
   * Rebuilds the cart from a past order.
   *
   * The order's line snapshots already carry everything the cart needs —
   * product, option ids, names and price hints — so this needs one request
   * rather than one per product. The server re-prices at checkout regardless,
   * so a stale hint here is cosmetic.
   */
  const reorder = async (orderNumber: string) => {
    setReordering(orderNumber);

    try {
      const order = await orderApi.get(orderNumber);
      const dropped: string[] = [];
      let added = 0;

      for (const item of order.items ?? []) {
        if (!item.product_id) {
          dropped.push(item.name);
          continue;
        }

        addRawLine({
          productId: item.product_id,
          // The list response has no slug, so link through the product id
          // until the customer opens the item.
          slug: item.sku ?? String(item.product_id),
          name: item.name,
          imageUrl: item.image_url,
          quantity: item.quantity,
          basePriceHint: item.unit_price,
          specialInstructions: item.special_instructions,
          options: (item.options ?? [])
            .filter((option) => option.option_id !== null)
            .map((option) => ({
              optionId: option.option_id!,
              groupId: 0,
              groupName: option.group_name,
              groupKind: option.group_kind,
              name: option.name,
              priceDelta: option.price_delta,
              quantity: option.quantity,
            })),
        });

        added++;
      }

      if (added === 0) {
        toast.error(t("menu.soldOut"));
        return;
      }

      if (dropped.length > 0) {
        toast.warning(`${t("menu.soldOut")}: ${dropped.join("، ")}`);
      }

      router.push("/cart");
    } catch {
      toast.error(t("state.error"));
    } finally {
      setReordering(null);
    }
  };

  if (isLoading) {
    return (
      <div className="mx-auto max-w-2xl space-y-3 px-4 py-6">
        <Skeleton className="mb-5 h-8 w-40" />
        {Array.from({ length: 4 }, (_, i) => (
          <Skeleton key={i} className="h-28 w-full rounded-2xl" />
        ))}
      </div>
    );
  }

  const orders = data?.data ?? [];

  if (orders.length === 0) {
    return (
      <div className="mx-auto max-w-2xl px-4">
        <EmptyState
          icon={<Receipt className="size-7" aria-hidden />}
          title={t("orders.empty")}
          description={t("orders.emptyHelp")}
          action={
            <Link
              href="/menu"
              className="inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-accent-contrast"
            >
              {t("menu.title")}
            </Link>
          }
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <h1 className="mb-5 text-2xl font-semibold text-content">{t("orders.title")}</h1>

      <ul className="space-y-3">
        {orders.map((order) => (
          <li key={order.id} className="relative">
            <Link href={`/orders/${order.order_number}`} className="block">
              <Card className="transition-shadow hover:shadow-[var(--shadow-lifted)]">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="tabular font-semibold text-content">{order.order_number}</p>
                    <p className="mt-0.5 text-sm text-content-secondary">
                      {formatRelative(order.placed_at, locale)}
                      {order.table && ` · ${t("table.title", { number: order.table.number })}`}
                    </p>
                  </div>

                  <Chevron className="size-5 shrink-0 text-content-muted" aria-hidden />
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <Badge tone={STATUS_TONE[order.status]}>
                    {t(`status.${order.status}` as TranslationKey)}
                  </Badge>
                  <Badge tone={order.payment_status === "paid" ? "success" : "neutral"}>
                    {t(`payment.${order.payment_status}` as TranslationKey)}
                  </Badge>
                  {order.items_count !== undefined && (
                    <span className="text-sm text-content-muted">
                      {t("menu.itemCount", { count: order.items_count })}
                    </span>
                  )}
                </div>

                <div className="mt-3 flex items-center justify-between border-t border-border-subtle pt-3">
                  <span className="text-sm text-content-secondary">{t("cart.total")}</span>
                  <span className="tabular text-lg font-bold text-accent">
                    {formatMoney(order.totals.grand_total, order.totals.currency, locale)}
                  </span>
                </div>
              </Card>
            </Link>

            {/* Layered over the card rather than nested inside its link, so
                the button stays keyboard-activatable and the markup valid. */}
            <button
              type="button"
              onClick={() => reorder(order.order_number)}
              disabled={reordering === order.order_number}
              className="absolute end-5 bottom-5 inline-flex items-center gap-1.5 rounded-lg bg-surface-sunken px-3 py-1.5 text-xs font-medium text-content transition-colors hover:bg-accent hover:text-accent-contrast disabled:opacity-50"
            >
              <RotateCcw
                className={cn("size-3.5", reordering === order.order_number && "animate-spin")}
                aria-hidden
              />
              {t("orders.reorder")}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
