"use client";

import { useEffect } from "react";

/**
 * Registers the service worker.
 *
 * Development is deliberately excluded: a worker caching the dev server's
 * output makes hot reload behave unpredictably and is a common source of
 * "my change isn't showing" confusion.
 */
export function ServiceWorkerRegistration() {
  useEffect(() => {
    if (process.env.NODE_ENV !== "production") return;
    if (!("serviceWorker" in navigator)) return;

    const register = () => {
      navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(() => {
        // Registration failing is not fatal — the app works online regardless.
      });
    };

    // Wait for load so the worker install does not compete with the first paint.
    if (document.readyState === "complete") {
      register();
    } else {
      window.addEventListener("load", register);
      return () => window.removeEventListener("load", register);
    }
  }, []);

  return null;
}
