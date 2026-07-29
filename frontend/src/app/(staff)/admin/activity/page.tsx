"use client";

import { Activity } from "lucide-react";
import { useState } from "react";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Badge, Select } from "@/components/ui/primitives";
import { useActivityLog } from "@/hooks/queries";
import { formatDateTime } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";

type Entry = Record<string, unknown>;

/**
 * The audit trail — read-only by design. An audit log with an edit button is
 * not an audit log.
 */
export default function AdminActivityPage() {
  const { t, locale } = useI18n();
  const [page, setPage] = useState(1);
  const [logName, setLogName] = useState("");

  const { data, isLoading } = useActivityLog({ page, log_name: logName || undefined });

  const columns: Array<Column<Entry>> = [
    {
      key: "description",
      header: t("admin.activity"),
      cell: (row) => (
        <span>
          <span className="block font-medium text-content">{String(row.description ?? "")}</span>
          <span className="block text-xs text-content-muted">
            {String(row.subject_type ?? "")} #{String(row.subject_id ?? "")}
          </span>
        </span>
      ),
    },
    {
      key: "log",
      header: "—",
      cell: (row) => <Badge tone="neutral">{String(row.log_name ?? "")}</Badge>,
    },
    {
      key: "causer",
      header: t("admin.users"),
      secondary: true,
      cell: (row) => <span className="text-content-secondary">{String(row.causer ?? "System")}</span>,
    },
    {
      key: "at",
      header: "—",
      className: "text-end",
      cell: (row) => (
        <span className="text-content-muted">
          {formatDateTime(row.created_at as string | null, locale)}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={t("admin.activity")} />

      <Select
        value={logName}
        onChange={(event) => {
          setLogName(event.target.value);
          setPage(1);
        }}
        aria-label={t("admin.activity")}
        className="mb-4 w-auto"
      >
        <option value="">{t("menu.allCategories")}</option>
        {data?.meta.log_names?.map((name) => (
          <option key={name} value={name}>
            {name}
          </option>
        ))}
      </Select>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(row) => String(row.id)}
        emptyTitle={t("state.empty")}
        emptyIcon={<Activity className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />
    </>
  );
}
