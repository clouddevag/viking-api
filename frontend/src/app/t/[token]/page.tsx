"use client";

import { motion } from "framer-motion";
import { AlertTriangle, Loader2, UtensilsCrossed } from "lucide-react";
import { useRouter } from "next/navigation";
import { use, useEffect, useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/primitives";
import { publicApi } from "@/lib/api/endpoints";
import { ApiRequestError, credentials } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";
import { useCartStore } from "@/stores/cart";
import type { TableSessionInfo } from "@/types/api";

/**
 * The QR landing page.
 *
 * Scanning a table code lands here. The scan itself is what identifies the
 * table — the guest never types a number — so this runs immediately on mount
 * and only asks for a name and party size afterwards, both optional.
 *
 * Deliberately outside the (customer) route group: it has no bottom navigation
 * because the guest has not chosen a destination yet.
 */
export default function TableLandingPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const { t } = useI18n();
  const router = useRouter();

  const setTableContext = useCartStore((state) => state.setTableContext);

  const [session, setSession] = useState<TableSessionInfo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [guestName, setGuestName] = useState("");
  const [partySize, setPartySize] = useState("2");
  const [submitting, setSubmitting] = useState(false);

  // React 18+ mounts effects twice in development; the scan is idempotent
  // server-side, but this keeps it to one request either way.
  const scanned = useRef(false);

  useEffect(() => {
    if (scanned.current) return;
    scanned.current = true;

    publicApi
      .scanTable(token)
      .then((result) => {
        setSession(result);

        // The API issues a device token on first scan so this guest can track
        // orders later without an account.
        if (result.guest_token) credentials.setGuestToken(result.guest_token);

        setTableContext({
          tableToken: token,
          sessionToken: result.session_token,
          tableNumber: result.table.number,
          branchId: result.branch.id,
        });
      })
      .catch((caught) => {
        setError(
          caught instanceof ApiRequestError ? caught.message : t("table.invalidCode"),
        );
      });
  }, [token, setTableContext, t]);

  const start = async () => {
    setSubmitting(true);

    try {
      // Re-scanning with details attaches them to the same open session rather
      // than starting a second one.
      const result = await publicApi.scanTable(token, {
        guest_name: guestName || undefined,
        party_size: Number(partySize) || undefined,
      });

      setTableContext({
        tableToken: token,
        sessionToken: result.session_token,
        tableNumber: result.table.number,
        branchId: result.branch.id,
      });

      router.replace("/menu");
    } catch {
      // Details are a nicety; never block ordering on them.
      router.replace("/menu");
    }
  };

  if (error) {
    return (
      <main className="grid min-h-dvh place-items-center bg-surface px-6 text-center">
        <div className="max-w-sm">
          <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-[var(--color-danger-soft)] text-[var(--color-danger)]">
            <AlertTriangle className="size-7" aria-hidden />
          </div>
          <h1 className="mt-5 text-xl font-semibold text-content">{t("table.invalidCode")}</h1>
          <p className="mt-2 text-sm text-content-secondary">{t("table.invalidCodeHelp")}</p>
          <Button className="mt-6" onClick={() => router.replace("/menu")}>
            {t("menu.title")}
          </Button>
        </div>
      </main>
    );
  }

  if (!session) {
    return (
      <main className="grid min-h-dvh place-items-center bg-surface px-6 text-center">
        <div>
          <Loader2 className="mx-auto size-8 animate-spin text-accent" aria-hidden />
          <p className="mt-4 text-sm text-content-secondary">{t("table.scanning")}</p>
        </div>
      </main>
    );
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-surface px-6 py-10">
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        className="w-full max-w-sm"
      >
        <div className="text-center">
          <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-accent text-accent-contrast">
            <UtensilsCrossed className="size-7" aria-hidden />
          </div>

          <h1 className="mt-5 font-display text-3xl text-content">
            {t("table.welcome", { branch: session.branch.name })}
          </h1>
          <p className="mt-2 text-content-secondary">
            {t("table.seatedAt", { number: session.table.number })}
          </p>

          {session.running_total > 0 && (
            <p className="tabular mt-3 rounded-xl bg-surface-sunken px-4 py-2 text-sm text-content-secondary">
              {t("table.runningTotal")}: {session.running_total.toLocaleString()}{" "}
              {session.branch.slug ? "IQD" : ""}
            </p>
          )}
        </div>

        <form
          onSubmit={(event) => {
            event.preventDefault();
            void start();
          }}
          className="mt-8 space-y-4"
        >
          <Field label={t("table.yourName")} htmlFor="guest_name">
            <Input
              id="guest_name"
              value={guestName}
              onChange={(event) => setGuestName(event.target.value)}
              autoComplete="name"
              maxLength={120}
            />
          </Field>

          <Field label={t("table.partySize")} htmlFor="party_size">
            <Input
              id="party_size"
              type="number"
              inputMode="numeric"
              min={1}
              max={50}
              value={partySize}
              onChange={(event) => setPartySize(event.target.value)}
            />
          </Field>

          <Button type="submit" size="lg" fullWidth loading={submitting}>
            {t("table.startOrdering")}
          </Button>
        </form>
      </motion.div>
    </main>
  );
}
