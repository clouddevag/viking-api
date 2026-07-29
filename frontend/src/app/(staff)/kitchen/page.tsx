"use client";

import { AnimatePresence, motion } from "framer-motion";
import { ChefHat, Clock, Volume2, VolumeX } from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";

import { StaffGuard, StaffTopBar } from "@/components/staff/shell";
import { Button } from "@/components/ui/button";
import { Badge, EmptyState } from "@/components/ui/primitives";
import { useKitchenBoard } from "@/hooks/queries";
import { useKitchenRealtime } from "@/hooks/use-realtime";
import { kitchenApi } from "@/lib/api/endpoints";
import { formatElapsed } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { playNewOrderChime, primeAudio } from "@/lib/sound";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { Order } from "@/types/api";

/**
 * Kitchen display system.
 *
 * Three lanes, one action per ticket. Everything is sized for a wall-mounted
 * tablet read from two metres away and tapped with the side of a hand, so type
 * is large, targets are huge, and colour — not text — carries urgency.
 */
export default function KitchenPage() {
  return (
    <StaffGuard permission="orders.kitchen">
      <KitchenBoard />
    </StaffGuard>
  );
}

function KitchenBoard() {
  const { t } = useI18n();
  const user = useAuthStore((state) => state.user);
  const branchId = user?.branch_id ?? undefined;

  const [soundOn, setSoundOn] = useState(true);
  const [now, setNow] = useState(() => Date.now());
  const [busyOrder, setBusyOrder] = useState<string | null>(null);

  const { data, isLoading, refetch } = useKitchenBoard(branchId);

  // One shared clock rather than a timer per ticket — forty tickets each with
  // their own interval is a lot of wasted wakeups on a tablet.
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  // The chime callback is memoised so it never re-subscribes the channel, so
  // it reads the current toggle through a ref rather than closing over it.
  const soundRef = useRef(soundOn);

  useEffect(() => {
    soundRef.current = soundOn;
  }, [soundOn]);

  const onNewOrder = useCallback(() => {
    if (soundRef.current) playNewOrderChime();
  }, []);

  useKitchenRealtime(branchId, onNewOrder);

  const thresholds = data?.meta.thresholds ?? { warning_minutes: 10, critical_minutes: 20 };

  const lanes = useMemo(
    () =>
      [
        { key: "incoming" as const, label: t("kitchen.incoming"), orders: data?.data.incoming ?? [] },
        { key: "preparing" as const, label: t("kitchen.preparing"), orders: data?.data.preparing ?? [] },
        { key: "ready" as const, label: t("kitchen.ready"), orders: data?.data.ready ?? [] },
      ],
    [data, t],
  );

  const advance = async (order: Order) => {
    setBusyOrder(order.order_number);

    try {
      await kitchenApi.advance(order.order_number);
      await refetch();
    } catch {
      toast.error(t("state.error"));
    } finally {
      setBusyOrder(null);
    }
  };

  const total = lanes.reduce((sum, lane) => sum + lane.orders.length, 0);

  return (
    <div className="flex min-h-dvh flex-col bg-surface-sunken">
      <StaffTopBar title={t("kitchen.title")} branchName={user?.branch?.name}>
        <button
          type="button"
          onClick={() => {
            // Unlocking audio requires a user gesture; this is it.
            primeAudio();
            setSoundOn((on) => !on);
          }}
          aria-pressed={soundOn}
          aria-label={soundOn ? t("kitchen.soundOn") : t("kitchen.soundOff")}
          className={cn(
            "grid size-9 place-items-center rounded-lg transition-colors",
            soundOn ? "text-accent" : "text-content-muted hover:bg-surface-sunken",
          )}
        >
          {soundOn ? <Volume2 className="size-4.5" aria-hidden /> : <VolumeX className="size-4.5" aria-hidden />}
        </button>
      </StaffTopBar>

      <main id="main" className="flex-1 p-3">
        {isLoading ? (
          <div className="grid gap-3 lg:grid-cols-3">
            {Array.from({ length: 3 }, (_, i) => (
              <div key={i} className="skeleton h-64 rounded-2xl" />
            ))}
          </div>
        ) : total === 0 ? (
          <EmptyState
            icon={<ChefHat className="size-7" aria-hidden />}
            title={t("kitchen.noTickets")}
            description={t("kitchen.noTicketsHelp")}
          />
        ) : (
          <div className="grid gap-3 lg:grid-cols-3">
            {lanes.map((lane) => (
              <section key={lane.key} aria-label={lane.label} className="min-w-0">
                <header className="mb-2 flex items-center justify-between px-1">
                  <h2 className="text-base font-semibold text-content">{lane.label}</h2>
                  <span className="tabular rounded-full bg-surface-raised px-2.5 py-0.5 text-sm font-bold text-content-secondary">
                    {lane.orders.length}
                  </span>
                </header>

                <ul className="space-y-3">
                  <AnimatePresence initial={false}>
                    {lane.orders.map((order) => (
                      <motion.li
                        key={order.id}
                        layout
                        initial={{ opacity: 0, scale: 0.96 }}
                        animate={{ opacity: 1, scale: 1 }}
                        exit={{ opacity: 0, scale: 0.96 }}
                        transition={{ duration: 0.2 }}
                      >
                        <Ticket
                          order={order}
                          now={now}
                          thresholds={thresholds}
                          busy={busyOrder === order.order_number}
                          onAdvance={() => advance(order)}
                        />
                      </motion.li>
                    ))}
                  </AnimatePresence>
                </ul>
              </section>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}

function Ticket({
  order,
  now,
  thresholds,
  busy,
  onAdvance,
}: {
  order: Order;
  now: number;
  thresholds: { warning_minutes: number; critical_minutes: number };
  busy: boolean;
  onAdvance: () => void;
}) {
  const { t } = useI18n();

  const ageMinutes = order.placed_at
    ? Math.floor((now - new Date(order.placed_at).getTime()) / 60_000)
    : 0;

  const urgency =
    ageMinutes >= thresholds.critical_minutes
      ? "critical"
      : ageMinutes >= thresholds.warning_minutes
        ? "warning"
        : "normal";

  const actionLabel =
    order.status === "pending" || order.status === "confirmed"
      ? t("kitchen.start")
      : order.status === "preparing"
        ? t("kitchen.markReady")
        : t("kitchen.handOver");

  return (
    <article
      className={cn(
        "overflow-hidden rounded-2xl border-2 bg-surface-raised shadow-[var(--shadow-card)]",
        urgency === "critical"
          ? "border-[var(--color-danger)] ticket-urgent"
          : urgency === "warning"
            ? "border-[var(--color-warning)]"
            : "border-transparent",
      )}
    >
      <header
        className={cn(
          "flex items-center justify-between gap-3 px-4 py-2.5",
          urgency === "critical"
            ? "bg-[var(--color-danger)] text-white"
            : urgency === "warning"
              ? "bg-[var(--color-warning)] text-black"
              : "bg-surface-sunken text-content",
        )}
      >
        <span className="tabular text-lg font-bold">
          {/* The trailing sequence is what staff actually call out. */}
          #{order.order_number.split("-").pop()}
        </span>

        <span className="tabular flex items-center gap-1.5 text-lg font-bold">
          <Clock className="size-4" aria-hidden />
          {formatElapsed(order.placed_at, now)}
        </span>
      </header>

      <div className="px-4 py-3">
        <div className="mb-2.5 flex flex-wrap items-center gap-1.5">
          {order.table ? (
            <Badge tone="accent">{t("table.title", { number: order.table.number })}</Badge>
          ) : (
            <Badge tone="neutral">{t(`checkout.${order.type === "takeaway" ? "takeaway" : "delivery"}`)}</Badge>
          )}
          {order.customer_name && (
            <span className="text-sm text-content-secondary">{order.customer_name}</span>
          )}
        </div>

        <ul className="space-y-2.5">
          {order.items?.map((item) => (
            <li key={item.id} className="flex gap-2.5">
              <span className="tabular grid size-7 shrink-0 place-items-center rounded-md bg-accent text-sm font-bold text-accent-contrast">
                {item.quantity}
              </span>

              <div className="min-w-0">
                <p className="text-base leading-tight font-semibold text-content">{item.name}</p>

                {item.options && item.options.length > 0 && (
                  <p className="mt-0.5 text-sm text-content-secondary">
                    {item.options.map((option) => option.name).join(" · ")}
                  </p>
                )}

                {/* Special instructions are the single most missable thing on
                    a ticket, so they get colour and a border rather than
                    sitting in the same grey as the options. */}
                {item.special_instructions && (
                  <p className="mt-1 rounded-md border-s-4 border-[var(--color-warning)] bg-[var(--color-warning-soft)] px-2 py-1 text-sm font-medium text-content">
                    {item.special_instructions}
                  </p>
                )}
              </div>
            </li>
          ))}
        </ul>

        {order.notes && (
          <p className="mt-3 rounded-lg bg-surface-sunken px-3 py-2 text-sm text-content-secondary">
            {order.notes}
          </p>
        )}
      </div>

      <div className="px-3 pb-3">
        <Button size="xl" fullWidth loading={busy} onClick={onAdvance}>
          {actionLabel}
        </Button>
      </div>
    </article>
  );
}
