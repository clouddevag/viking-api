"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useRouter } from "next/navigation";
import { use, useState } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Card, Field, Skeleton, Textarea } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { formatDateTime, formatMoney, formatTime } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import type { OrderStatus } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * Order detail with the full audit trail.
 *
 * The action buttons come from the API's `allowed_transitions`, so the UI can
 * never offer a move the state machine would reject.
 */
export default function AdminOrderDetailPage({
  params,
}: {
  params: Promise<{ number: string }>;
}) {
  const { number } = use(params);
  const { t, locale, isRtl } = useI18n();
  const router = useRouter();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);

  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ["admin", "orders", number],
    queryFn: () => adminApi.order(number),
  });

  const order = data?.data;
  const Back = isRtl ? ArrowRight : ArrowLeft;

  const transition = async (status: OrderStatus) => {
    setBusy(true);

    try {
      await adminApi.setOrderStatus(number, status);
      await queryClient.invalidateQueries({ queryKey: ["admin", "orders"] });
      toast.success(t("admin.saved"));
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setBusy(false);
    }
  };

  if (isLoading || !order) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-9 w-48" />
        <Skeleton className="h-64 rounded-2xl" />
      </div>
    );
  }

  const currency = order.totals.currency;

  return (
    <>
      <button
        type="button"
        onClick={() => router.push("/admin/orders")}
        className="mb-4 flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
      >
        <Back className="size-4" aria-hidden />
        {t("admin.orders")}
      </button>

      <PageHeader
        title={order.order_number}
        description={formatDateTime(order.placed_at, locale)}
        action={
          <div className="flex flex-wrap gap-2">
            {order.allowed_transitions
              .filter((status) => status !== "cancelled")
              .map((status) => (
                <Button key={status} size="sm" loading={busy} onClick={() => transition(status)}>
                  {t(`status.${status}` as TranslationKey)}
                </Button>
              ))}

            {order.allowed_transitions.includes("cancelled") && can("orders.cancel") && (
              <Button size="sm" variant="danger" onClick={() => setCancelOpen(true)}>
                {t("orders.cancel")}
              </Button>
            )}
          </div>
        }
      />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {/* Items ------------------------------------------------------- */}
          <Card>
            <h2 className="mb-4 text-base font-semibold text-content">{t("orders.items")}</h2>

            <ul className="space-y-3">
              {order.items?.map((item) => (
                <li key={item.id} className="flex gap-3">
                  <span className="tabular grid size-7 shrink-0 place-items-center rounded-md bg-surface-sunken text-sm font-semibold">
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

                  <span className="tabular shrink-0 font-medium">
                    {formatMoney(item.line_total, currency, locale)}
                  </span>
                </li>
              ))}
            </ul>

            <dl className="mt-4 space-y-1.5 border-t border-border-subtle pt-4 text-sm">
              <Row label={t("cart.subtotal")} value={formatMoney(order.totals.subtotal, currency, locale)} />
              {order.totals.discount_total > 0 && (
                <Row
                  label={t("cart.discount")}
                  value={`−${formatMoney(order.totals.discount_total, currency, locale)}`}
                />
              )}
              {order.totals.manual_discount_total > 0 && (
                <Row
                  label={t("cashier.discount")}
                  value={`−${formatMoney(order.totals.manual_discount_total, currency, locale)}`}
                />
              )}
              {order.totals.refunded_total > 0 && (
                <Row
                  label={t("payment.refunded")}
                  value={`−${formatMoney(order.totals.refunded_total, currency, locale)}`}
                />
              )}
              <div className="flex justify-between border-t border-border-subtle pt-2 font-semibold">
                <dt>{t("cart.total")}</dt>
                <dd className="tabular text-accent">
                  {formatMoney(order.totals.grand_total, currency, locale)}
                </dd>
              </div>
            </dl>
          </Card>

          {/* Timeline ---------------------------------------------------- */}
          <Card>
            <h2 className="mb-4 text-base font-semibold text-content">{t("orders.track")}</h2>

            <ol className="space-y-3">
              {order.timeline?.map((event, index) => (
                <li key={index} className="flex items-baseline justify-between gap-4 text-sm">
                  <span>
                    <span className="font-medium text-content">
                      {t(`status.${event.to}` as TranslationKey)}
                    </span>
                    {event.actor && <span className="text-content-muted"> · {event.actor}</span>}
                    {event.note && (
                      <span className="block text-xs text-content-muted">{event.note}</span>
                    )}
                  </span>
                  <span className="tabular shrink-0 text-content-muted">
                    {formatTime(event.at, locale)}
                  </span>
                </li>
              ))}
            </ol>
          </Card>
        </div>

        {/* Sidebar --------------------------------------------------------- */}
        <div className="space-y-4">
          <Card>
            <h2 className="mb-3 text-base font-semibold text-content">{t("checkout.yourDetails")}</h2>

            <dl className="space-y-2 text-sm">
              <Row label={t("checkout.name")} value={order.customer_name ?? "—"} />
              <Row label={t("checkout.phone")} value={order.customer_phone ?? "—"} />
              <Row label={t("checkout.orderType")} value={t(`checkout.${order.type === "dine_in" ? "dineIn" : order.type === "takeaway" ? "takeaway" : "delivery"}`)} />
              {order.table && (
                <Row label={t("admin.tables")} value={t("table.title", { number: order.table.number })} />
              )}
              {order.coupon_code && <Row label={t("admin.coupons")} value={order.coupon_code} />}
            </dl>

            {order.notes && (
              <p className="mt-3 rounded-lg bg-surface-sunken p-3 text-sm text-content-secondary">
                {order.notes}
              </p>
            )}
          </Card>

          <Card>
            <h2 className="mb-3 text-base font-semibold text-content">{t("payment.paid")}</h2>

            <Badge tone={order.payment_status === "paid" ? "success" : "warning"}>
              {t(`payment.${order.payment_status}` as TranslationKey)}
            </Badge>

            {order.payments && order.payments.length > 0 && (
              <ul className="mt-3 space-y-2 text-sm">
                {order.payments.map((payment) => (
                  <li key={payment.id} className="flex justify-between gap-3">
                    <span className="text-content-secondary">
                      {t(`payment.${payment.method}` as TranslationKey)}
                    </span>
                    <span className="tabular">{formatMoney(payment.amount, currency, locale)}</span>
                  </li>
                ))}
              </ul>
            )}

            {order.refunds && order.refunds.length > 0 && (
              <ul className="mt-3 space-y-2 border-t border-border-subtle pt-3 text-sm">
                {order.refunds.map((refund) => (
                  <li key={refund.id} className="flex justify-between gap-3">
                    <span className="text-content-secondary">{refund.reason}</span>
                    <span className="tabular text-[var(--color-danger)]">
                      −{formatMoney(refund.amount, currency, locale)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>

      <Sheet
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={t("orders.cancelConfirm")}
        footer={
          <div className="flex gap-3">
            <Button variant="secondary" fullWidth onClick={() => setCancelOpen(false)}>
              {t("action.cancel")}
            </Button>
            <Button
              variant="danger"
              fullWidth
              loading={busy}
              disabled={!reason.trim()}
              onClick={async () => {
                setBusy(true);
                try {
                  await adminApi.cancelOrder(number, reason);
                  await queryClient.invalidateQueries({ queryKey: ["admin", "orders"] });
                  setCancelOpen(false);
                  toast.success(t("status.cancelled"));
                } catch (error) {
                  toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
                } finally {
                  setBusy(false);
                }
              }}
            >
              {t("action.confirm")}
            </Button>
          </div>
        }
      >
        <Field label={t("orders.cancelReason")} htmlFor="cancel-reason" required>
          <Textarea
            id="cancel-reason"
            rows={3}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
        </Field>
      </Sheet>
    </>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-content-secondary">{label}</dt>
      <dd className="text-content">{value}</dd>
    </div>
  );
}
