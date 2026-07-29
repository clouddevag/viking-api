import { WifiOff } from "lucide-react";

/**
 * The last-resort offline page, served by the service worker when a navigation
 * misses both the network and the cached shell.
 *
 * Static and dependency-free on purpose: it has to render with no API, no
 * fonts and no client JavaScript.
 */
export const metadata = { title: "Offline" };

export default function OfflinePage() {
  return (
    <main className="grid min-h-dvh place-items-center bg-surface px-6 text-center">
      <div className="max-w-sm">
        <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-surface-sunken text-content-muted">
          <WifiOff className="size-7" aria-hidden />
        </div>
        <h1 className="mt-5 text-xl font-semibold text-content">You are offline</h1>
        <p className="mt-2 text-sm text-content-secondary">
          Check your connection and try again. Pages you have already visited stay available.
        </p>
      </div>
    </main>
  );
}
