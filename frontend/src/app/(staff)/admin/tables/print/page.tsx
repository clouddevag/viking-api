"use client";

import { Loader2, Printer } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";

import { adminApi } from "@/lib/api/endpoints";
import { useI18n } from "@/lib/i18n/provider";

/**
 * Printable QR sheet.
 *
 * Four table tents per A4 page with cut guides, sized so a folded card sits
 * upright on a table. This is how a venue actually deploys QR ordering: print
 * once, cut, fold, done.
 */
export default function QrPrintPage() {
  return (
    <Suspense fallback={<div className="grid min-h-dvh place-items-center"><Loader2 className="size-6 animate-spin" /></div>}>
      <QrSheet />
    </Suspense>
  );
}

interface QrEntry {
  id: number;
  number: string;
  name: string;
  zone: string | null;
  branch: string;
  url: string;
  svg: string;
}

function QrSheet() {
  const { t } = useI18n();
  const searchParams = useSearchParams();
  const branchId = searchParams.get("branch_id");

  const [entries, setEntries] = useState<QrEntry[] | null>(null);

  useEffect(() => {
    adminApi
      .qrSheet(branchId ? Number(branchId) : undefined)
      .then((data) => setEntries(data as QrEntry[]))
      .catch(() => setEntries([]));
  }, [branchId]);

  if (!entries) {
    return (
      <div className="grid min-h-dvh place-items-center">
        <Loader2 className="size-6 animate-spin text-accent" aria-hidden />
      </div>
    );
  }

  return (
    <>
      <style>{`
        @media print {
          @page { size: A4; margin: 10mm; }
          .no-print { display: none !important; }
          .tent { break-inside: avoid; }
          body { background: #fff !important; }
        }
      `}</style>

      <main className="mx-auto max-w-4xl bg-white p-6 text-black">
        <div className="no-print mb-6 flex items-center justify-between">
          <p className="text-sm text-gray-600">{entries.length} × QR</p>
          <button
            type="button"
            onClick={() => window.print()}
            className="flex items-center gap-2 rounded-lg bg-black px-4 py-2 text-sm text-white"
          >
            <Printer className="size-4" aria-hidden />
            {t("action.print")}
          </button>
        </div>

        <div className="grid grid-cols-2 gap-4">
          {entries.map((entry) => (
            <article
              key={entry.id}
              className="tent flex flex-col items-center rounded-xl border-2 border-dashed border-gray-300 p-5 text-center"
            >
              <p className="text-xs tracking-widest text-gray-500 uppercase">{entry.branch}</p>
              <h2 className="mt-1 text-3xl font-bold">#{entry.number}</h2>
              {entry.zone && <p className="text-sm text-gray-600">{entry.zone}</p>}

              <div
                className="my-3 w-40"
                role="img"
                aria-label={`QR ${entry.number}`}
                // Server-rendered SVG from BaconQrCode; no user markup.
                dangerouslySetInnerHTML={{ __html: entry.svg }}
              />

              <p className="text-sm font-semibold">امسح للطلب · Scan to order</p>
              <p className="mt-1 max-w-full text-[10px] break-all text-gray-400">{entry.url}</p>
            </article>
          ))}
        </div>
      </main>
    </>
  );
}
