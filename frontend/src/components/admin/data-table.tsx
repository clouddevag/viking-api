"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import type { ReactNode } from "react";

import { EmptyState, Skeleton } from "@/components/ui/primitives";
import { useI18n } from "@/lib/i18n/provider";
import { cn } from "@/lib/utils";

/**
 * The admin list primitive.
 *
 * One component behind every admin table so pagination, empty and loading
 * states behave identically across the panel — and so the horizontal overflow
 * is handled once, correctly, instead of each screen discovering on a phone
 * that its table pushes the page sideways.
 */

export interface Column<T> {
  key: string;
  header: string;
  /** Renders the cell. Kept as a function so columns can compose freely. */
  cell: (row: T) => ReactNode;
  className?: string;
  /** Hidden below `md` — for columns that are useful but not essential. */
  secondary?: boolean;
}

export function DataTable<T>({
  columns,
  rows,
  loading,
  emptyTitle,
  emptyDescription,
  emptyIcon,
  rowKey,
  onRowClick,
  meta,
  onPageChange,
}: {
  columns: Array<Column<T>>;
  rows: T[];
  loading?: boolean;
  emptyTitle: string;
  emptyDescription?: string;
  emptyIcon?: ReactNode;
  rowKey: (row: T) => string | number;
  onRowClick?: (row: T) => void;
  meta?: { current_page: number; last_page: number; total: number };
  onPageChange?: (page: number) => void;
}) {
  const { t, isRtl } = useI18n();
  const Prev = isRtl ? ChevronRight : ChevronLeft;
  const Next = isRtl ? ChevronLeft : ChevronRight;

  if (loading) {
    return (
      <div className="surface-card space-y-2 p-4">
        {Array.from({ length: 8 }, (_, i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (rows.length === 0) {
    return (
      <div className="surface-card">
        <EmptyState icon={emptyIcon} title={emptyTitle} description={emptyDescription} />
      </div>
    );
  }

  return (
    <div className="surface-card overflow-hidden p-0">
      {/* The scroll container is here, not on the page, so the body never
          scrolls horizontally on a narrow screen. */}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[36rem] border-collapse text-sm">
          <thead>
            <tr className="border-b border-border-subtle bg-surface-sunken">
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={cn(
                    "px-4 py-3 text-start text-xs font-semibold tracking-wide text-content-secondary uppercase",
                    column.secondary && "hidden md:table-cell",
                    column.className,
                  )}
                >
                  {column.header}
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {rows.map((row) => (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                className={cn(
                  "border-b border-border-subtle last:border-0",
                  onRowClick && "cursor-pointer transition-colors hover:bg-surface-sunken",
                )}
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={cn(
                      "px-4 py-3 text-content",
                      column.secondary && "hidden md:table-cell",
                      column.className,
                    )}
                  >
                    {column.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && onPageChange && (
        <nav
          className="flex items-center justify-between gap-4 border-t border-border-subtle px-4 py-3"
          aria-label="Pagination"
        >
          <p className="tabular text-sm text-content-secondary">
            {meta.current_page} / {meta.last_page} · {meta.total}
          </p>

          <div className="flex gap-1">
            <button
              type="button"
              disabled={meta.current_page <= 1}
              onClick={() => onPageChange(meta.current_page - 1)}
              aria-label={t("action.back")}
              className="grid size-9 place-items-center rounded-lg bg-surface-sunken text-content disabled:opacity-40"
            >
              <Prev className="size-4" aria-hidden />
            </button>

            <button
              type="button"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => onPageChange(meta.current_page + 1)}
              aria-label={t("action.next")}
              className="grid size-9 place-items-center rounded-lg bg-surface-sunken text-content disabled:opacity-40"
            >
              <Next className="size-4" aria-hidden />
            </button>
          </div>
        </nav>
      )}
    </div>
  );
}

/** Page header with an optional primary action. */
export function PageHeader({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <header className="mb-5 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 className="text-2xl font-semibold text-content">{title}</h1>
        {description && <p className="mt-1 text-sm text-content-secondary">{description}</p>}
      </div>

      {action}
    </header>
  );
}
