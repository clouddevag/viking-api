"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useEffect, useRef } from "react";

import { getEcho } from "@/lib/echo";
import { keys } from "./queries";
import type { Order } from "@/types/api";

/**
 * Realtime subscriptions.
 *
 * Every hook here follows the same contract: it keeps the React Query cache in
 * step with what arrives over the socket, and degrades to nothing at all if
 * Reverb is not configured — the polling intervals on the same queries mean the
 * screens still work, just less immediately.
 */

type OrderEvent = { order: Order };
type StatusEvent = { from: string; to: string; order: Order };

/**
 * Kitchen display feed for one branch.
 *
 * `onNewOrder` is separate from the cache update because the display also has
 * to make a sound, and that must fire once per order rather than on every
 * re-render the cache change causes.
 */
export function useKitchenRealtime(
  branchId: number | null | undefined,
  onNewOrder?: (order: Order) => void,
) {
  const queryClient = useQueryClient();
  const onNewOrderRef = useRef(onNewOrder);
  onNewOrderRef.current = onNewOrder;

  useEffect(() => {
    if (!branchId) return;

    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`branches.${branchId}.kitchen`);

    const invalidate = () => {
      void queryClient.invalidateQueries({ queryKey: keys.kitchenBoard(branchId) });
      void queryClient.invalidateQueries({ queryKey: keys.kitchenBoard(undefined) });
    };

    channel.listen(".order.placed", (event: OrderEvent) => {
      invalidate();
      onNewOrderRef.current?.(event.order);
    });

    channel.listen(".order.status", invalidate);

    return () => {
      channel.stopListening(".order.placed");
      channel.stopListening(".order.status");
      getEcho()?.leave(`branches.${branchId}.kitchen`);
    };
  }, [branchId, queryClient]);
}

/** Cashier feed: new orders to settle, plus payment and refund confirmations. */
export function useCashierRealtime(branchId: number | null | undefined) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!branchId) return;

    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`branches.${branchId}.cashier`);
    const invalidate = () => {
      void queryClient.invalidateQueries({ queryKey: ["cashier"] });
    };

    channel.listen(".order.placed", invalidate);
    channel.listen(".order.status", invalidate);
    channel.listen(".order.payment", invalidate);
    channel.listen(".order.refunded", invalidate);

    return () => {
      [".order.placed", ".order.status", ".order.payment", ".order.refunded"].forEach((event) =>
        channel.stopListening(event),
      );
      getEcho()?.leave(`branches.${branchId}.cashier`);
    };
  }, [branchId, queryClient]);
}

/** Admin dashboard feed. */
export function useAdminRealtime(branchId: number | null | undefined) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!branchId) return;

    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`branches.${branchId}.admin`);
    const invalidate = () => {
      void queryClient.invalidateQueries({ queryKey: ["admin"] });
    };

    channel.listen(".order.placed", invalidate);
    channel.listen(".order.status", invalidate);
    channel.listen(".order.payment", invalidate);

    return () => {
      [".order.placed", ".order.status", ".order.payment"].forEach((event) =>
        channel.stopListening(event),
      );
      getEcho()?.leave(`branches.${branchId}.admin`);
    };
  }, [branchId, queryClient]);
}

/**
 * Customer order tracking.
 *
 * A signed-in customer listens on a private channel keyed by their user id; a
 * guest listens on a public channel named with the random device token only
 * their browser holds, since they have no account to authenticate with.
 */
export function useOrderTrackingRealtime(
  orderNumber: string,
  identity: { userId?: number | null; guestToken?: string | null },
) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const echo = getEcho();
    if (!echo || !orderNumber) return;

    const channelName = identity.userId
      ? `users.${identity.userId}.orders`
      : identity.guestToken
        ? `guests.${identity.guestToken}.orders`
        : null;

    if (!channelName) return;

    const channel = identity.userId
      ? echo.private(channelName)
      : echo.channel(channelName);

    const apply = (event: StatusEvent | OrderEvent) => {
      const order = "order" in event ? event.order : null;

      // The channel carries all of this customer's orders, so ignore anything
      // that is not the one being watched.
      if (!order || order.order_number !== orderNumber) return;

      queryClient.setQueryData(keys.order(orderNumber), order);
      void queryClient.invalidateQueries({ queryKey: keys.orders() });
    };

    channel.listen(".order.status", apply);
    channel.listen(".order.payment", () => {
      void queryClient.invalidateQueries({ queryKey: keys.order(orderNumber) });
    });

    return () => {
      channel.stopListening(".order.status");
      channel.stopListening(".order.payment");
      getEcho()?.leave(channelName);
    };
  }, [orderNumber, identity.userId, identity.guestToken, queryClient]);
}
