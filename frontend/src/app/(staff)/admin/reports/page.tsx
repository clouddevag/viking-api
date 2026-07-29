"use client";

import { BarChart3, Download } from "lucide-react";
import { useState } from "react";

import { PageHeader } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Card, EmptyState, Field, Input, Skeleton } from "@/components/ui/primitives";
import { useReport } from "@/hooks/queries";
import { api } from "@/lib/api/client";
import { formatMoney, formatNumber } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

/**
 * Reporting.
 *
 * Every report returns `{ rows, totals }` in the same shape, so one table
 * renders all six and export works identically for each — adding a report is a
 * backend change plus one entry in this array.
 */
const REPORTS: Array<{ key: string; labelKey: TranslationKey }> = [
  { key: "sales", labelKey: "admin.revenue" },
  { key: "products", labelKey: "admin.products" },
  { key: "categories", labelKey: "admin.categories" },
  { key: "payments", labelKey: "payment.paid" },
  { key: "staff", labelKey: "admin.users" },
  { key: "hours", labelKey: "admin.byHour" },
];

/** Columns whose values are money and should be formatted as such. */
const MONEY_COLUMNS = new Set([
  "revenue",
  "subtotal",
  "discounts",
  "tax",
  "service_charge",
  "delivery",
  "refunds",
  "amount",
]);

export default function AdminReportsPage() {
  const { t, locale } = useI18n();
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);

  // The clock is read once, when the screen opens, rather than on every
  // render — reading it during render makes the component impure and lets the
  // date inputs drift under the user across a midnight boundary.
  const [dateDefaults] = useState(() => ({
    today: new Date().toISOString().slice(0, 10),
    monthAgo: new Date(Date.now() - 29 * 86_400_000).toISOString().slice(0, 10),
  }));

  const [report, setReport] = useState("sales");
  const [from, setFrom] = useState(dateDefaults.monthAgo);
  const [to, setTo] = useState(dateDefaults.today);

  const { data, isLoading } = useReport(report, {
    from,
    to,
    branch_id: user?.branch_id ?? undefined,
  });

  const rows = data?.data.rows ?? [];
  const totals = data?.data.totals ?? {};
  const columns = rows.length > 0 ? Object.keys(rows[0]) : [];

  /**
   * The export endpoint is authenticated, so the file is fetched with the
   * bearer token and handed to the browser as a blob — a plain link would 401.
   */
  const download = async () => {
    try {
      const response = await api.get(`/admin/reports/${report}/export`, {
        params: { from, to, branch_id: user?.branch_id ?? undefined },
        responseType: "blob",
      });

      const url = URL.createObjectURL(response.data as Blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `viking-${report}-${from}-to-${to}.csv`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch {
      // The button simply does nothing rather than throwing at the user.
    }
  };

  const formatCell = (column: string, value: unknown): string => {
    if (value === null || value === undefined) return "—";
    if (typeof value === "number") {
      return MONEY_COLUMNS.has(column)
        ? formatMoney(value, "IQD", locale)
        : formatNumber(value, locale);
    }
    return String(value);
  };

  return (
    <>
      <PageHeader
        title={t("admin.reports")}
        action={
          can("reports.export") && (
            <Button
              variant="secondary"
              icon={<Download className="size-4" aria-hidden />}
              onClick={download}
              disabled={rows.length === 0}
            >
              {t("action.export")}
            </Button>
          )
        }
      />

      {/* Report picker ----------------------------------------------------- */}
      <div className="rail mb-4 flex gap-2 pb-1" role="tablist">
        {REPORTS.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={report === item.key}
            onClick={() => setReport(item.key)}
            className={cn(
              "shrink-0 rounded-full px-4 py-2 text-sm font-medium transition-colors",
              report === item.key
                ? "bg-accent text-accent-contrast"
                : "bg-surface-raised text-content-secondary hover:bg-border-subtle",
            )}
          >
            {t(item.labelKey)}
          </button>
        ))}
      </div>

      {/* Range ------------------------------------------------------------- */}
      <div className="mb-4 flex flex-wrap gap-3">
        <Field label="From" htmlFor="from" className="w-40">
          <Input
            id="from"
            type="date"
            dir="ltr"
            value={from}
            max={to}
            onChange={(event) => setFrom(event.target.value)}
          />
        </Field>

        <Field label="To" htmlFor="to" className="w-40">
          <Input
            id="to"
            type="date"
            dir="ltr"
            value={to}
            min={from}
            max={dateDefaults.today}
            onChange={(event) => setTo(event.target.value)}
          />
        </Field>
      </div>

      {/* Totals ------------------------------------------------------------ */}
      {Object.keys(totals).length > 0 && (
        <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {Object.entries(totals).map(([key, value]) => (
            <Card key={key} className="py-4">
              <p className="text-xs tracking-wide text-content-secondary uppercase">
                {key.replaceAll("_", " ")}
              </p>
              <p className="tabular mt-1 text-xl font-bold text-content">
                {formatCell(key, value)}
              </p>
            </Card>
          ))}
        </div>
      )}

      {/* Rows -------------------------------------------------------------- */}
      {isLoading ? (
        <Card className="space-y-2">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} className="h-10 w-full" />
          ))}
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState icon={<BarChart3 className="size-7" aria-hidden />} title={t("state.empty")} />
        </Card>
      ) : (
        <div className="surface-card overflow-hidden p-0">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-border-subtle bg-surface-sunken">
                  {columns.map((column) => (
                    <th
                      key={column}
                      scope="col"
                      className="px-4 py-3 text-start text-xs font-semibold tracking-wide text-content-secondary uppercase whitespace-nowrap"
                    >
                      {column.replaceAll("_", " ")}
                    </th>
                  ))}
                </tr>
              </thead>

              <tbody>
                {rows.map((row, index) => (
                  <tr key={index} className="border-b border-border-subtle last:border-0">
                    {columns.map((column) => (
                      <td
                        key={column}
                        className={cn(
                          "px-4 py-2.5 whitespace-nowrap",
                          typeof row[column] === "number" && "tabular text-end",
                        )}
                      >
                        {formatCell(column, row[column])}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
