"use client";

import { motion } from "framer-motion";
import { Check, ChefHat, CircleDot, Clock, PackageCheck, Receipt, XCircle } from "lucide-react";
import Link from "next/link";
import { use, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Badge, Card, Skeleton } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useCancelOrder, useOrder } from "@/hooks/queries";
import { useOrderTrackingRealtime } from "@/hooks/use-realtime";
import { credentials } from "@/lib/api/client";
import { formatMoney, formatRelative, formatTime } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { OrderStatus } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * Live order tracking.
 *
 * Websockets push the status the instant the kitchen changes it; the 15-second
 * poll is a fallback for a phone whose socket dropped in a basement. Both feed
 * the same query cache, so the UI cannot show two different truths.
 */

const STEPS: Array<{ status: OrderStatus; icon: typeof Check; labelKey: TranslationKey }> = [
  { status: "pending", icon: CircleDot, labelKey: "status.pending" },
  { status: "confirmed", icon: Check, labelKey: "status.confirmed" },
  { status: "preparing", icon: ChefHat, labelKey: "status.preparing" },
  { status: "ready", icon: PackageCheck, labelKey: "status.ready" },
  { status: "served", icon: Receipt, labelKey: "status.served" },
];

export default function OrderTrackingPage({ params }: { params: Promise<{ number: string }> }) {
  const { number } = use(params);
  const { t, locale } = useI18n();

  const user = useAuthStore((state) => state.user);
  const { data: order, isLoading, isError } = useOrder(number, { refetchInterval: 15_000 });
  const cancelOrder = useCancelOrder();

  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState("");

  useOrderTrackingRealtime(number, {
    userId: user?.id ?? null,
    guestToken: credentials.getGuestToken(),
  });

  if (isLoading) {
    return (
      <div className="mx-auto max-w-2xl space-y-4 px-4 py-8">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full rounded-2xl" />
        <Skeleton className="h-48 w-full rounded-2xl" />
      </div>
    );
  }

  if (isError || !order) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-20 text-center">
        <h1 className="text-xl font-semibold text-content">{t("state.notFound")}</h1>
        <Link
          href="/orders"
          className="mt-6 inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-accent-contrast"
        >
          {t("orders.title")}
        </Link>
      </div>
    );
  }

  const cancelled = order.status === "cancelled";
  const currentStep = STEPS.findIndex((step) => step.status === order.status);
  const canCancel = ["pending", "confirmed"].includes(order.status);
  const currency = order.totals.currency;

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <header className="mb-6">
        <p className="text-sm text-content-secondary">
          {t("orders.placedAt", { time: formatRelative(order.placed_at, locale) })}
        </p>
        <h1 className="tabular mt-1 text-2xl font-semibold text-content">{order.order_number}</h1>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Badge tone={cancelled ? "danger" : order.status === "completed" ? "success" : "accent"}>
            {t(`status.${order.status}` as TranslationKey)}
          </Badge>
          <Badge tone={order.payment_status === "paid" ? "success" : "neutral"}>
            {t(`payment.${order.payment_status}` as TranslationKey)}
          </Badge>
          {order.table && <Badge tone="neutral">{t("table.title", { number: order.table.number })}</Badge>}
        </div>
      </header>

      {/* Progress ---------------------------------------------------------- */}
      {cancelled ? (
        <Card className="flex items-center gap-3 border-[var(--color-danger)]/40 bg-[var(--color-danger-soft)]">
          <XCircle className="size-6 shrink-0 text-[var(--color-danger)]" aria-hidden />
          <div>
            <p className="font-semibold text-content">{t("status.cancelled")}</p>
            <p className="text-sm text-content-secondary">
              {order.cancel_reason ?? t("status.cancelled.help")}
            </p>
          </div>
        </Card>
      ) : (
        <Card>
          <ol className="relative space-y-6" aria-label={t("orders.track")}>
            {STEPS.map((step, index) => {
              const done = index < currentStep;
              const active = index === currentStep;
              const Icon = step.icon;

              return (
                <li key={step.status} className="relative flex gap-4">
                  {/* Connector, drawn behind the icons */}
                  {index < STEPS.length - 1 && (
                    <span
                      className={cn(
                        "absolute start-5 top-10 h-6 w-0.5 -translate-x-1/2 rtl:translate-x-1/2",
                        done ? "bg-accent" : "bg-border-subtle",
                      )}
                      aria-hidden
                    />
                  )}

                  <span
                    className={cn(
                      "relative z-10 grid size-10 shrink-0 place-items-center rounded-full transition-colors duration-300",
                      done || active
                        ? "bg-accent text-accent-contrast"
                        : "bg-surface-sunken text-content-muted",
                    )}
                  >
                    <Icon className="size-4.5" aria-hidden />

                    {active && (
                      <motion.span
                        className="absolute inset-0 rounded-full border-2 border-accent"
                        animate={{ scale: [1, 1.35], opacity: [0.7, 0] }}
                        transition={{ duration: 1.8, repeat: Infinity, ease: "easeOut" }}
                        aria-hidden
                      />
                    )}
                  </span>

                  <div className="min-w-0 pt-1.5">
                    <p
                      className={cn(
                        "font-medium",
                        done || active ? "text-content" : "text-content-muted",
                      )}
                    >
                      {t(step.labelKey)}
                    </p>
                    {active && (
                      <p className="mt-0.5 text-sm text-content-secondary">
                        {t(`status.${step.status}.help` as TranslationKey)}
                      </p>
                    )}
                  </div>
                </li>
              );
            })}
          </ol>

          {order.estimated_minutes && order.status !== "completed" && (
            <p className="mt-6 flex items-center justify-center gap-2 rounded-xl bg-surface-sunken py-3 text-sm text-content-secondary">
              <Clock className="size-4" aria-hidden />
              {t("cart.estimatedTime", { count: order.estimated_minutes })}
            </p>
          )}
        </Card>
      )}

      {/* Items -------------------------------------------------------------- */}
      <section className="mt-6" aria-label={t("orders.items")}>
        <h2 className="mb-3 text-lg font-semibold text-content">{t("orders.items")}</h2>

        <Card className="space-y-4">
          {order.items?.map((item) => (
            <div key={item.id} className="flex gap-3">
              <span className="tabular grid size-8 shrink-0 place-items-center rounded-lg bg-surface-sunken text-sm font-semibold text-content">
                {item.quantity}
              </span>

              <div className="min-w-0 flex-1">
                <p className="font-medium text-content">{item.name}</p>

                {item.options && item.options.length > 0 && (
                  <p className="mt-0.5 text-xs text-content-secondary">
                    {item.options.map((option) => option.name).join(" · ")}
                  </p>
                )}

                {item.special_instructions && (
                  <p className="mt-1 text-xs text-content-muted italic">
                    “{item.special_instructions}”
                  </p>
                )}
              </div>

              <span className="tabular shrink-0 font-medium text-content">
                {formatMoney(item.line_total, currency, locale)}
              </span>
            </div>
          ))}

          <div className="space-y-2 border-t border-border-subtle pt-4">
            <Row label={t("cart.subtotal")} value={formatMoney(order.totals.subtotal, currency, locale)} />

            {order.totals.discount_total > 0 && (
              <Row
                label={t("cart.discount")}
                value={`−${formatMoney(order.totals.discount_total, currency, locale)}`}
                accent
              />
            )}
            {order.totals.tax_total > 0 && (
              <Row label={t("cart.tax")} value={formatMoney(order.totals.tax_total, currency, locale)} />
            )}
            {order.totals.service_charge > 0 && (
              <Row
                label={t("cart.serviceCharge")}
                value={formatMoney(order.totals.service_charge, currency, locale)}
              />
            )}
            {order.totals.delivery_fee > 0 && (
              <Row
                label={t("cart.deliveryFee")}
                value={formatMoney(order.totals.delivery_fee, currency, locale)}
              />
            )}
            {order.totals.refunded_total > 0 && (
              <Row
                label={t("payment.refunded")}
                value={`−${formatMoney(order.totals.refunded_total, currency, locale)}`}
                accent
              />
            )}

            <div className="flex items-baseline justify-between border-t border-border-subtle pt-2.5">
              <span className="font-semibold text-content">{t("cart.total")}</span>
              <span className="tabular text-lg font-bold text-accent">
                {formatMoney(order.totals.grand_total, currency, locale)}
              </span>
            </div>
          </div>
        </Card>
      </section>

      {/* Timeline ------------------------------------------------------------ */}
      {order.timeline && order.timeline.length > 1 && (
        <details className="surface-card mt-6 p-5">
          <summary className="cursor-pointer text-sm font-medium text-content">
            {t("orders.track")}
          </summary>

          <ol className="mt-4 space-y-3">
            {order.timeline.map((event, index) => (
              <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
                <span className="text-content">
                  {t(`status.${event.to}` as TranslationKey)}
                  {event.actor && (
                    <span className="text-content-muted"> · {event.actor}</span>
                  )}
                </span>
                <span className="tabular shrink-0 text-content-muted">
                  {formatTime(event.at, locale)}
                </span>
              </li>
            ))}
          </ol>
        </details>
      )}

      {/* Actions -------------------------------------------------------------- */}
      <div className="mt-6 flex gap-3">
        <Link
          href="/menu"
          className="inline-flex h-12 flex-1 items-center justify-center rounded-xl bg-surface-sunken font-medium text-content"
        >
          {t("menu.title")}
        </Link>

        {canCancel && (
          <Button variant="danger" size="lg" className="flex-1" onClick={() => setCancelOpen(true)}>
            {t("orders.cancel")}
          </Button>
        )}
      </div>

      <Sheet
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={t("orders.cancelConfirm")}
        description={t("orders.cancelReason")}
        footer={
          <div className="flex gap-3">
            <Button variant="secondary" fullWidth onClick={() => setCancelOpen(false)}>
              {t("action.cancel")}
            </Button>
            <Button
              variant="danger"
              fullWidth
              loading={cancelOrder.isPending}
              onClick={async () => {
                try {
                  await cancelOrder.mutateAsync({ orderNumber: number, reason });
                  setCancelOpen(false);
                  toast.success(t("status.cancelled"));
                } catch {
                  toast.error(t("state.error"));
                }
              }}
            >
              {t("action.confirm")}
            </Button>
          </div>
        }
      >
        <textarea
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          rows={3}
          maxLength={255}
          aria-label={t("orders.cancelReason")}
          className="w-full rounded-xl border border-border-subtle bg-surface-raised p-3 text-sm"
        />
      </Sheet>
    </div>
  );
}

function Row({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="flex items-baseline justify-between gap-4 text-sm">
      <span className="text-content-secondary">{label}</span>
      <span className={cn("tabular font-medium", accent ? "text-accent" : "text-content")}>
        {value}
      </span>
    </div>
  );
}
