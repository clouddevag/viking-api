"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useEffect, useState, type ReactNode } from "react";
import { Toaster } from "sonner";

import { ApiRequestError, credentials, setUnauthorizedHandler } from "@/lib/api/client";
import { authApi } from "@/lib/api/endpoints";
import { I18nProvider } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { disconnectEcho } from "@/lib/echo";

/**
 * Builds the query client.
 *
 * Created per browser session rather than as a module singleton so a server
 * render never shares a cache between two users' requests.
 */
function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Menu content changes rarely; a minute of staleness saves a lot of
        // requests on a phone that is switching between tabs.
        staleTime: 60_000,
        gcTime: 10 * 60_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          // Never retry a request the server has definitively rejected —
          // retrying a 403 or a 422 just burns the rate limit.
          if (error instanceof ApiRequestError) {
            if (error.status >= 400 && error.status < 500 && error.status !== 429) {
              return false;
            }
          }

          return failureCount < 2;
        },
        retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 8000),
      },
      mutations: { retry: false },
    },
  });
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(makeQueryClient);
  const setUser = useAuthStore((state) => state.setUser);
  const setStatus = useAuthStore((state) => state.setStatus);

  // Restore the session on boot: if a token survives in storage, exchange it
  // for the current user so role-gated UI renders correctly on first paint.
  useEffect(() => {
    let cancelled = false;

    setUnauthorizedHandler(() => {
      useAuthStore.getState().signOut();
      disconnectEcho();
      queryClient.clear();
    });

    if (!credentials.getToken()) {
      setStatus("anonymous");
      return;
    }

    setStatus("loading");

    authApi
      .me()
      .then((user) => {
        if (!cancelled) setUser(user);
      })
      .catch(() => {
        // The interceptor has already cleared a dead token.
        if (!cancelled) setStatus("anonymous");
      });

    return () => {
      cancelled = true;
      setUnauthorizedHandler(null);
    };
  }, [queryClient, setStatus, setUser]);

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
        <I18nProvider>
          {children}
          <Toaster
            position="top-center"
            richColors
            closeButton
            toastOptions={{
              classNames: {
                toast: "surface-card !rounded-xl",
              },
            }}
          />
        </I18nProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
}
