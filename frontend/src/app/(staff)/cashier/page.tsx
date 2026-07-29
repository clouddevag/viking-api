"use client";

import {
  Banknote,
  CreditCard,
  LayoutGrid,
  Percent,
  Printer,
  Receipt,
  RotateCcw,
  Search,
  Users,
} from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { StaffGuard, StaffTopBar } from "@/components/staff/shell";
import { Button } from "@/components/ui/button";
import { Badge, Card, EmptyState, Field, Input, Skeleton, Textarea } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useCashierOrder, useCashierOrders, useCashierTables } from "@/hooks/queries";
import { useCashierRealtime } from "@/hooks/use-realtime";
import { cashierApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { formatMoney, formatRelative } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { DiningTable, Order, PaymentMethod, TableStatus } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * Cashier / point of sale.
 *
 * Two views on one screen: the queue of unsettled orders, and the floor plan.
 * All money movement goes through the API's PaymentService, so this file only
 * ever collects input and renders the result — it never computes a balance.
 */
export default function CashierPage() {
  return (
    <StaffGuard permission="orders.cashier">
      <CashierWorkspace />
    </StaffGuard>
  );
}

type View = "orders" | "tables";

function CashierWorkspace() {
  const { t, locale } = useI18n();
  const user = useAuthStore((state) => state.user);
  const can = useAuthStore((state) => state.can);
  const branchId = user?.branch_id ?? undefined;

  const [view, setView] = useState<View>("orders");
  const [lookup, setLookup] = useState("");
  const [active, setActive] = useState<Order | null>(null);

  useCashierRealtime(branchId);

  const { data: orders, isLoading, refetch } = useCashierOrders({ unpaid_only: true, branch_id: branchId });
  const { data: tables, refetch: refetchTables } = useCashierTables(branchId);

  const rows = (orders?.data ?? []).filter((order) =>
    lookup.trim()
      ? order.order_number.toLowerCase().includes(lookup.trim().toLowerCase())
      : true,
  );

  const refreshAll = async () => {
    await Promise.all([refetch(), refetchTables()]);
  };

  return (
    <div className="flex min-h-dvh flex-col bg-surface-sunken">
      <StaffTopBar title={t("cashier.title")} branchName={user?.branch?.name}>
        <div className="flex rounded-lg bg-surface-sunken p-0.5" role="tablist">
          {(
            [
              { key: "orders" as const, icon: Receipt, label: t("cashier.openOrders") },
              { key: "tables" as const, icon: LayoutGrid, label: t("cashier.floorPlan") },
            ]
          ).map(({ key, icon: Icon, label }) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={view === key}
              onClick={() => setView(key)}
              className={cn(
                "flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-colors",
                view === key ? "bg-surface-raised text-content shadow-sm" : "text-content-muted",
              )}
            >
              <Icon className="size-4" aria-hidden />
              <span className="hidden sm:inline">{label}</span>
            </button>
          ))}
        </div>
      </StaffTopBar>

      <main id="main" className="flex-1 p-4">
        {view === "orders" ? (
          <>
            <div className="relative mb-4 max-w-md">
              <Search
                className="pointer-events-none absolute start-3.5 top-1/2 size-4.5 -translate-y-1/2 text-content-muted"
                aria-hidden
              />
              <Input
                value={lookup}
                onChange={(event) => setLookup(event.target.value)}
                placeholder={t("cashier.lookupPlaceholder")}
                aria-label={t("cashier.lookup")}
                className="h-11 ps-11"
              />
            </div>

            {isLoading ? (
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {Array.from({ length: 6 }, (_, i) => (
                  <Skeleton key={i} className="h-40 rounded-2xl" />
                ))}
              </div>
            ) : rows.length === 0 ? (
              <EmptyState
                icon={<Receipt className="size-7" aria-hidden />}
                title={t("state.empty")}
                description={t("kitchen.noTicketsHelp")}
              />
            ) : (
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {rows.map((order) => (
                  <li key={order.id}>
                    <button
                      type="button"
                      onClick={() => setActive(order)}
                      className="w-full text-start"
                    >
                      <Card className="transition-shadow hover:shadow-[var(--shadow-lifted)]">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <p className="tabular font-bold text-content">{order.order_number}</p>
                            <p className="mt-0.5 text-sm text-content-secondary">
                              {formatRelative(order.placed_at, locale)}
                            </p>
                          </div>

                          <Badge tone={order.payment_status === "paid" ? "success" : "warning"}>
                            {t(`payment.${order.payment_status}` as TranslationKey)}
                          </Badge>
                        </div>

                        <div className="mt-3 flex flex-wrap items-center gap-1.5">
                          {order.table && (
                            <Badge tone="accent">
                              {t("table.title", { number: order.table.number })}
                            </Badge>
                          )}
                          <Badge tone="neutral">
                            {t(`status.${order.status}` as TranslationKey)}
                          </Badge>
                          {order.items_count !== undefined && (
                            <span className="text-sm text-content-muted">
                              {t("menu.itemCount", { count: order.items_count })}
                            </span>
                          )}
                        </div>

                        <div className="mt-3 flex items-baseline justify-between border-t border-border-subtle pt-3">
                          <span className="text-sm text-content-secondary">
                            {t("cashier.amountDue")}
                          </span>
                          <span className="tabular text-xl font-bold text-accent">
                            {formatMoney(order.totals.grand_total, order.totals.currency, locale)}
                          </span>
                        </div>
                      </Card>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </>
        ) : (
          <FloorPlan tables={tables ?? []} onChanged={refreshAll} canClose={can("orders.cashier")} />
        )}
      </main>

      {active && (
        <OrderDrawer
          orderNumber={active.order_number}
          onClose={() => setActive(null)}
          onSettled={refreshAll}
        />
      )}
    </div>
  );
}

/* -----------------------------------------------------------------------------
 * Floor plan
 * -------------------------------------------------------------------------- */

const TABLE_TONE: Record<TableStatus, string> = {
  available: "border-[var(--color-success)]/40 bg-[var(--color-success-soft)]",
  occupied: "border-accent bg-accent-soft",
  reserved: "border-[var(--color-warning)]/50 bg-[var(--color-warning-soft)]",
  disabled: "border-border-subtle bg-surface-sunken opacity-60",
};

function FloorPlan({
  tables,
  onChanged,
  canClose,
}: {
  tables: DiningTable[];
  onChanged: () => void;
  canClose: boolean;
}) {
  const { t, locale } = useI18n();
  const [closing, setClosing] = useState<number | null>(null);

  // Grouped by zone so the grid mirrors how the room is actually laid out.
  const zones = tables.reduce<Record<string, DiningTable[]>>((groups, table) => {
    const zone = table.zone ?? "—";
    (groups[zone] ??= []).push(table);
    return groups;
  }, {});

  const closeTable = async (table: DiningTable, force = false) => {
    setClosing(table.id);

    try {
      await cashierApi.closeTable(table.id, force);
      toast.success(t("cashier.closeTable"));
      onChanged();
    } catch (error) {
      if (error instanceof ApiRequestError && error.code === "unpaid_orders") {
        // Closing over unpaid orders is a manager decision, so it is confirmed
        // explicitly rather than forced silently.
        if (window.confirm(`${error.message}\n${t("action.confirm")}?`)) {
          await closeTable(table, true);
          return;
        }
      } else {
        toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
      }
    } finally {
      setClosing(null);
    }
  };

  if (tables.length === 0) {
    return <EmptyState icon={<LayoutGrid className="size-7" aria-hidden />} title={t("state.empty")} />;
  }

  return (
    <div className="space-y-7">
      {Object.entries(zones).map(([zone, zoneTables]) => (
        <section key={zone} aria-label={zone}>
          <h2 className="mb-3 text-sm font-semibold tracking-wide text-content-secondary uppercase">
            {zone}
          </h2>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
            {zoneTables.map((table) => (
              <article
                key={table.id}
                className={cn("rounded-2xl border-2 p-4 transition-colors", TABLE_TONE[table.status])}
              >
                <div className="flex items-start justify-between">
                  <span className="tabular text-2xl font-bold text-content">{table.number}</span>
                  <span className="flex items-center gap-1 text-xs text-content-secondary">
                    <Users className="size-3.5" aria-hidden />
                    <span className="tabular">{table.capacity}</span>
                  </span>
                </div>

                <p className="mt-1 text-xs font-medium text-content-secondary">
                  {t(
                    `cashier.table${table.status.charAt(0).toUpperCase()}${table.status.slice(1)}` as TranslationKey,
                  )}
                </p>

                {table.active_session && (
                  <>
                    <p className="tabular mt-2 text-sm font-bold text-accent">
                      {formatMoney(table.active_session.running_total, "IQD", locale)}
                    </p>

                    {canClose && (
                      <Button
                        size="sm"
                        variant="secondary"
                        fullWidth
                        className="mt-2"
                        loading={closing === table.id}
                        onClick={() => closeTable(table)}
                      >
                        {t("cashier.closeTable")}
                      </Button>
                    )}
                  </>
                )}
              </article>
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}

/* -----------------------------------------------------------------------------
 * Order drawer — payment, refund, discount, receipt
 * -------------------------------------------------------------------------- */

const METHODS: Array<{ value: PaymentMethod; icon: typeof Banknote; key: TranslationKey }> = [
  { value: "cash", icon: Banknote, key: "payment.cash" },
  { value: "card", icon: CreditCard, key: "payment.card" },
];

function OrderDrawer({
  orderNumber,
  onClose,
  onSettled,
}: {
  orderNumber: string;
  onClose: () => void;
  onSettled: () => void;
}) {
  const { t, locale } = useI18n();
  const can = useAuthStore((state) => state.can);

  const [mode, setMode] = useState<"pay" | "refund" | "discount">("pay");
  const [method, setMethod] = useState<PaymentMethod>("cash");
  const [tendered, setTendered] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);

  // Loaded fresh rather than reusing the list row, because the drawer needs
  // items, payments and the true outstanding balance.
  const { data, isError, refetch } = useCashierOrder(orderNumber);

  const order = data?.data ?? null;
  const outstanding = data?.meta.outstanding ?? 0;

  useEffect(() => {
    if (!isError) return;

    toast.error(t("state.error"));
    onClose();
  }, [isError, t, onClose]);

  // The amount box defaults to the balance owed and holds an override only
  // once the cashier types one, so a refetched balance flows through instead
  // of being pinned to whatever it was when the drawer opened.
  const [amountOverride, setAmountOverride] = useState<string | null>(null);
  const amount = amountOverride ?? String(outstanding);
  const setAmount = setAmountOverride;

  const currency = order?.totals.currency ?? "IQD";
  const tenderedNumber = Number(tendered) || 0;
  const amountNumber = Number(amount) || 0;
  const change = Math.max(0, tenderedNumber - amountNumber);

  const submit = async () => {
    if (!order) return;
    setBusy(true);

    try {
      if (mode === "pay") {
        const result = await cashierApi.pay(order.order_number, {
          method,
          amount: amountNumber,
          tendered_amount: method === "cash" && tenderedNumber > 0 ? tenderedNumber : undefined,
        });

        toast.success(
          result.meta.change_due > 0
            ? `${t("cashier.change")}: ${formatMoney(result.meta.change_due, currency, locale)}`
            : t("cashier.paymentTaken"),
        );
      } else if (mode === "refund") {
        await cashierApi.refund(order.order_number, { amount: amountNumber, reason });
        toast.success(t("cashier.refund"));
      } else {
        await cashierApi.discount(order.order_number, { amount: amountNumber, reason });
        toast.success(t("cashier.discount"));
      }

      onSettled();
      await refetch();
      setMode("pay");
      setReason("");
      setTendered("");
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setBusy(false);
    }
  };

  const printReceipt = () => {
    // Opened as a standalone route so the browser's own print dialog handles
    // the thermal printer, rather than reimplementing pagination here.
    window.open(`/cashier/receipt/${orderNumber}`, "_blank", "width=380,height=700");
  };

  return (
    <Sheet
      open
      onClose={onClose}
      side="end"
      title={orderNumber}
      description={order ? t(`status.${order.status}` as TranslationKey) : undefined}
      footer={
        order && (
          <div className="space-y-3">
            <div className="flex items-baseline justify-between">
              <span className="font-medium text-content">{t("cashier.amountDue")}</span>
              <span className="tabular text-2xl font-bold text-accent">
                {formatMoney(outstanding, currency, locale)}
              </span>
            </div>

            <div className="flex gap-2">
              <Button
                variant="secondary"
                icon={<Printer className="size-4" aria-hidden />}
                onClick={printReceipt}
              >
                {t("action.print")}
              </Button>

              <Button
                fullWidth
                loading={busy}
                disabled={amountNumber <= 0 || (mode !== "pay" && !reason.trim())}
                onClick={submit}
              >
                {mode === "pay"
                  ? t("cashier.takePayment")
                  : mode === "refund"
                    ? t("cashier.refund")
                    : t("cashier.discount")}
              </Button>
            </div>
          </div>
        )
      }
    >
      {!order ? (
        <div className="space-y-3 py-4">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-24 w-full" />
        </div>
      ) : (
        <div className="space-y-5 py-2">
          {/* Items */}
          <ul className="space-y-2">
            {order.items?.map((item) => (
              <li key={item.id} className="flex justify-between gap-3 text-sm">
                <span className="text-content">
                  <span className="tabular font-semibold">{item.quantity}×</span> {item.name}
                </span>
                <span className="tabular shrink-0 text-content-secondary">
                  {formatMoney(item.line_total, currency, locale)}
                </span>
              </li>
            ))}
          </ul>

          {/* Totals */}
          <dl className="space-y-1.5 border-t border-border-subtle pt-3 text-sm">
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
              <dt className="text-content">{t("cart.total")}</dt>
              <dd className="tabular text-content">
                {formatMoney(order.totals.grand_total, currency, locale)}
              </dd>
            </div>
          </dl>

          {/* Mode switch */}
          <div className="flex gap-1 rounded-xl bg-surface-sunken p-1" role="tablist">
            {(
              [
                { key: "pay" as const, label: t("cashier.takePayment"), icon: Banknote, allowed: can("payments.capture") },
                { key: "discount" as const, label: t("cashier.discount"), icon: Percent, allowed: can("payments.discount") },
                { key: "refund" as const, label: t("cashier.refund"), icon: RotateCcw, allowed: can("payments.refund") },
              ] as const
            )
              .filter((tab) => tab.allowed)
              .map((tab) => (
                <button
                  key={tab.key}
                  type="button"
                  role="tab"
                  aria-selected={mode === tab.key}
                  onClick={() => {
                    setMode(tab.key);
                    setAmount(tab.key === "pay" ? String(outstanding) : "");
                  }}
                  className={cn(
                    "flex flex-1 items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-medium transition-colors",
                    mode === tab.key
                      ? "bg-surface-raised text-content shadow-sm"
                      : "text-content-muted",
                  )}
                >
                  <tab.icon className="size-4" aria-hidden />
                  {tab.label}
                </button>
              ))}
          </div>

          {/* Inputs */}
          {mode === "pay" && (
            <div className="grid grid-cols-2 gap-2">
              {METHODS.map(({ value, icon: Icon, key }) => (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={method === value}
                  onClick={() => setMethod(value)}
                  className={cn(
                    "flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-medium transition-colors",
                    method === value
                      ? "border-accent bg-accent-soft text-accent"
                      : "border-border-subtle text-content-secondary",
                  )}
                >
                  <Icon className="size-4" aria-hidden />
                  {t(key)}
                </button>
              ))}
            </div>
          )}

          <Field
            label={mode === "pay" ? t("cashier.amountDue") : mode === "refund" ? t("cashier.refundAmount") : t("cashier.discountAmount")}
            htmlFor="amount"
          >
            <Input
              id="amount"
              type="number"
              inputMode="decimal"
              min={0}
              dir="ltr"
              value={amount}
              onChange={(event) => setAmount(event.target.value)}
              className="h-12 text-lg font-semibold"
            />
          </Field>

          {mode === "pay" && method === "cash" && (
            <>
              <Field label={t("cashier.tendered")} htmlFor="tendered">
                <Input
                  id="tendered"
                  type="number"
                  inputMode="decimal"
                  min={0}
                  dir="ltr"
                  value={tendered}
                  onChange={(event) => setTendered(event.target.value)}
                  className="h-12 text-lg font-semibold"
                />
              </Field>

              {/* Quick-tender chips: the notes a cashier actually receives. */}
              <div className="flex flex-wrap gap-2">
                {[amountNumber, 25000, 50000, 100000].map((preset, index) => (
                  <button
                    key={`${preset}-${index}`}
                    type="button"
                    onClick={() => setTendered(String(preset))}
                    className="tabular rounded-lg bg-surface-sunken px-3 py-2 text-sm font-medium text-content hover:bg-border-subtle"
                  >
                    {formatMoney(preset, currency, locale)}
                  </button>
                ))}
              </div>

              {change > 0 && (
                <p className="flex items-baseline justify-between rounded-xl bg-[var(--color-success-soft)] px-4 py-3">
                  <span className="font-medium text-content">{t("cashier.change")}</span>
                  <span className="tabular text-xl font-bold text-[var(--color-success)]">
                    {formatMoney(change, currency, locale)}
                  </span>
                </p>
              )}
            </>
          )}

          {mode !== "pay" && (
            <Field
              label={mode === "refund" ? t("cashier.refundReason") : t("cashier.discountReason")}
              htmlFor="reason"
              required
            >
              <Textarea
                id="reason"
                rows={2}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </Field>
          )}
        </div>
      )}
    </Sheet>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-content-secondary">{label}</dt>
      <dd className="tabular text-content">{value}</dd>
    </div>
  );
}
