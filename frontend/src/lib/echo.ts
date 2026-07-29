"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

import { API_BASE_URL, credentials } from "@/lib/api/client";

/**
 * Laravel Reverb client.
 *
 * Created lazily and shared, because every screen that needs realtime wants the
 * *same* socket — opening one per component would blow through the connection
 * limit on a busy kitchen tablet.
 */

type EchoInstance = InstanceType<typeof Echo>;

let echo: EchoInstance | null = null;

declare global {
  // eslint-disable-next-line no-var
  var Pusher: typeof import("pusher-js").default | undefined;
}

function authEndpoint(): string {
  // Broadcasting auth lives at the app root, not under the versioned API path.
  return API_BASE_URL.replace(/\/api\/v1\/?$/, "") + "/broadcasting/auth";
}

export function getEcho(): EchoInstance | null {
  if (typeof window === "undefined") return null;
  if (echo) return echo;

  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  // Realtime is optional: without a key the app still works, it just polls.
  if (!key) return null;

  window.Pusher = Pusher;

  echo = new Echo({
    broadcaster: "reverb",
    key,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
    forceTLS: (process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http") === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint: authEndpoint(),
    auth: {
      headers: {
        // Private staff channels authorise with the same bearer token as REST.
        Authorization: credentials.getToken() ? `Bearer ${credentials.getToken()}` : "",
        Accept: "application/json",
      },
    },
  });

  return echo;
}

/**
 * Tears the socket down — called on sign-out so a subsequent user does not
 * inherit the previous one's authorised channels.
 */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
}

/** Rebuilds the connection with the current token after signing in or out. */
export function reconnectEcho(): EchoInstance | null {
  disconnectEcho();
  return getEcho();
}
