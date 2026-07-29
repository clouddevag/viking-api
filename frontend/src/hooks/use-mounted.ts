"use client";

import { useSyncExternalStore } from "react";

/** Hydration never changes this, so there is nothing to subscribe to. */
const subscribe = () => () => {};

/**
 * True once the component has hydrated, false during server rendering.
 *
 * Anything that reads a browser-only value — the resolved theme, an install
 * prompt, localStorage — has to render a neutral placeholder on the server or
 * the markup will not match. `useSyncExternalStore` expresses that directly by
 * returning a different server snapshot, which is cheaper and less
 * error-prone than setting state from an effect on mount.
 */
export function useMounted(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );
}
