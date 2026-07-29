"use client";

import { Loader2, Printer } from "lucide-react";
import { use, useEffect, useState } from "react";

import { cashierApi } from "@/lib/api/endpoints";
import { useI18n } from "@/lib/i18n/provider";

/**
 * Receipt print view.
 *
 * Sized for an 80mm thermal roll and styled entirely in monospace with hard
 * borders, because thermal printers render greys as either black or nothing.
 * The print stylesheet strips the page chrome so the paper carries only the
 * receipt.
 *
 * The API returns structured data rather than HTML, so this component owns the
 * layout and the backend stays printer-agnostic.
 */

interface Receipt {
  header: string;
  footer: string | null;
  direction: "rtl" | "ltr";
  branch: { name: string; address: string | null; phone: string | null };
  order: {
    number: string;
    type: string;
    table: string | null;
    customer: string | null;
    placed_at: string | null;
    cashier: string | null;
    notes: string | null;
  };
  lines: Array<{
    name: string;
    quantity: number;
    unit_price: number;
    line_total: number;
    options: Array<{ name: string; price_delta: number; quantity: number }>;
    instructions: string | null;
  }>;
  totals: Record<string, number | string>;
  payments: Array<{ method: string; amount: number; tendered: number | null; change: number; at: string | null }>;
  qr_svg: string | null;
}

export default function ReceiptPage({ params }: { params: Promise<{ number: string }> }) {
  const { number } = use(params);
  const { locale, t } = useI18n();

  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    cashierApi
      .receipt(number, locale)
      .then((data) => setReceipt(data as unknown as Receipt))
      .catch(() => setError(true));
  }, [number, locale]);

  // Fire the print dialog once the content is on screen. A frame is enough —
  // printing before layout settles produces a blank first page on some drivers.
  useEffect(() => {
    if (!receipt) return;

    const timer = window.setTimeout(() => window.print(), 300);
    return () => window.clearTimeout(timer);
  }, [receipt]);

  if (error) {
    return (
      <main className="grid min-h-dvh place-items-center p-6 text-center">
        <p className="text-content">{t("state.notFound")}</p>
      </main>
    );
  }

  if (!receipt) {
    return (
      <main className="grid min-h-dvh place-items-center">
        <Loader2 className="size-6 animate-spin text-accent" aria-hidden />
      </main>
    );
  }

  const money = (value: number) =>
    new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 }).format(value);

  return (
    <>
      <style>{`
        @media print {
          @page { size: 80mm auto; margin: 3mm; }
          .no-print { display: none !important; }
          body { background: #fff !important; }
        }
      `}</style>

      <main
        dir={receipt.direction}
        className="mx-auto min-h-dvh max-w-[80mm] bg-white p-3 font-mono text-[11px] leading-tight text-black"
      >
        <div className="no-print mb-4 flex justify-center">
          <button
            type="button"
            onClick={() => window.print()}
            className="flex items-center gap-2 rounded-lg bg-black px-4 py-2 text-sm text-white"
          >
            <Printer className="size-4" aria-hidden />
            {t("action.print")}
          </button>
        </div>

        <header className="text-center">
          <h1 className="text-base font-bold tracking-wide">{receipt.header}</h1>
          <p className="mt-1">{receipt.branch.name}</p>
          {receipt.branch.address && <p>{receipt.branch.address}</p>}
          {receipt.branch.phone && <p dir="ltr">{receipt.branch.phone}</p>}
        </header>

        <hr className="my-2 border-t border-dashed border-black" />

        <dl className="space-y-0.5">
          <Line label="#" value={receipt.order.number} mono />
          {receipt.order.table && <Line label="Table" value={receipt.order.table} />}
          <Line label="Type" value={receipt.order.type} />
          {receipt.order.customer && <Line label="Customer" value={receipt.order.customer} />}
          {receipt.order.cashier && <Line label="Cashier" value={receipt.order.cashier} />}
          {receipt.order.placed_at && (
            <Line
              label="Date"
              value={new Date(receipt.order.placed_at).toLocaleString("en-GB", {
                dateStyle: "short",
                timeStyle: "short",
              })}
              mono
            />
          )}
        </dl>

        <hr className="my-2 border-t border-dashed border-black" />

        <ul className="space-y-1.5">
          {receipt.lines.map((line, index) => (
            <li key={index}>
              <div className="flex justify-between gap-2">
                <span className="font-bold">
                  {line.quantity}× {line.name}
                </span>
                <span className="shrink-0 tabular-nums">{money(line.line_total)}</span>
              </div>

              {line.options.length > 0 && (
                <p className="ps-4 opacity-80">
                  {line.options.map((option) => option.name).join(", ")}
                </p>
              )}

              {line.instructions && <p className="ps-4 italic">* {line.instructions}</p>}
            </li>
          ))}
        </ul>

        <hr className="my-2 border-t border-dashed border-black" />

        <dl className="space-y-0.5">
          <Line label="Subtotal" value={money(Number(receipt.totals.subtotal))} mono />
          {Number(receipt.totals.discount) > 0 && (
            <Line label="Discount" value={`-${money(Number(receipt.totals.discount))}`} mono />
          )}
          {Number(receipt.totals.manual_discount) > 0 && (
            <Line label="Discount" value={`-${money(Number(receipt.totals.manual_discount))}`} mono />
          )}
          {Number(receipt.totals.tax) > 0 && (
            <Line label="Tax" value={money(Number(receipt.totals.tax))} mono />
          )}
          {Number(receipt.totals.service_charge) > 0 && (
            <Line label="Service" value={money(Number(receipt.totals.service_charge))} mono />
          )}
          {Number(receipt.totals.delivery_fee) > 0 && (
            <Line label="Delivery" value={money(Number(receipt.totals.delivery_fee))} mono />
          )}
          {Number(receipt.totals.refunded) > 0 && (
            <Line label="Refunded" value={`-${money(Number(receipt.totals.refunded))}`} mono />
          )}
        </dl>

        <div className="mt-2 flex justify-between border-t-2 border-black pt-2 text-sm font-bold">
          <span>TOTAL</span>
          <span className="tabular-nums">
            {money(Number(receipt.totals.grand_total))} {String(receipt.totals.currency)}
          </span>
        </div>

        {receipt.payments.length > 0 && (
          <>
            <hr className="my-2 border-t border-dashed border-black" />
            <dl className="space-y-0.5">
              {receipt.payments.map((payment, index) => (
                <div key={index}>
                  <Line label={payment.method} value={money(payment.amount)} mono />
                  {payment.tendered !== null && (
                    <Line label="Tendered" value={money(payment.tendered)} mono />
                  )}
                  {payment.change > 0 && <Line label="Change" value={money(payment.change)} mono />}
                </div>
              ))}
            </dl>
          </>
        )}

        {receipt.qr_svg && (
          <div
            className="mx-auto mt-3 w-28"
            // The SVG is generated server-side by BaconQrCode from a URL we
            // construct ourselves — it contains no user input.
            dangerouslySetInnerHTML={{ __html: receipt.qr_svg }}
          />
        )}

        {receipt.footer && <p className="mt-3 text-center">{receipt.footer}</p>}
      </main>
    </>
  );
}

function Line({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex justify-between gap-2">
      <dt className="opacity-80">{label}</dt>
      <dd className={mono ? "tabular-nums" : undefined} dir={mono ? "ltr" : undefined}>
        {value}
      </dd>
    </div>
  );
}
