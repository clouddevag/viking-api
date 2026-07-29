"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ClipboardList, Layers, Plus, Printer, QrCode, RefreshCw } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Field, Input, Select } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useAdminBranches, useAdminTables } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { api, ApiRequestError } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import type { DiningTable, TableStatus } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

const STATUS_TONE: Record<TableStatus, "success" | "accent" | "warning" | "neutral"> = {
  available: "success",
  occupied: "accent",
  reserved: "warning",
  disabled: "neutral",
};

/**
 * Table and QR management.
 *
 * The print sheet is the important screen here: a venue sets up once by
 * printing every table's code at A4, and rotation exists for the day a code
 * gets photographed and shared.
 */
export default function AdminTablesPage() {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);

  const [page, setPage] = useState(1);
  const [branchId, setBranchId] = useState("");
  const [bulkOpen, setBulkOpen] = useState(false);
  const [qrTable, setQrTable] = useState<DiningTable | null>(null);

  const { data, isLoading } = useAdminTables({ page, branch_id: branchId || undefined });
  const { data: branches } = useAdminBranches();

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "tables"] });

  const rotate = async (table: DiningTable) => {
    if (!window.confirm(t("admin.rotateQrWarning"))) return;

    try {
      await adminApi.rotateTableQr(table.id);
      toast.success(t("admin.rotateQr"));
      await refresh();
    } catch {
      toast.error(t("state.error"));
    }
  };

  const columns: Array<Column<DiningTable>> = [
    {
      key: "number",
      header: t("admin.tables"),
      cell: (table) => (
        <span>
          <span className="tabular block font-semibold text-content">#{table.number}</span>
          {table.zone && <span className="block text-xs text-content-muted">{table.zone}</span>}
        </span>
      ),
    },
    {
      key: "capacity",
      header: t("checkout.guestCount"),
      secondary: true,
      cell: (table) => <span className="tabular">{table.capacity}</span>,
    },
    {
      key: "status",
      header: t("admin.active"),
      cell: (table) => (
        <Badge tone={STATUS_TONE[table.status]}>
          {t(
            `cashier.table${table.status.charAt(0).toUpperCase()}${table.status.slice(1)}` as TranslationKey,
          )}
        </Badge>
      ),
    },
    {
      key: "session",
      header: t("cashier.openOrders"),
      secondary: true,
      cell: (table) =>
        table.active_session ? (
          <span className="text-sm text-content-secondary">
            {table.active_session.guest_name ?? "—"} · {table.active_orders_count ?? 0}
          </span>
        ) : (
          <span className="text-content-muted">—</span>
        ),
    },
    {
      key: "actions",
      header: t("admin.qrCode"),
      className: "text-end",
      cell: (table) => (
        <span className="flex justify-end gap-1">
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              setQrTable(table);
            }}
            aria-label={t("admin.qrCode")}
            className="grid size-9 place-items-center rounded-lg text-content-secondary hover:bg-surface-sunken"
          >
            <QrCode className="size-4" aria-hidden />
          </button>

          {can("tables.manage") && (
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                rotate(table);
              }}
              aria-label={t("admin.rotateQr")}
              className="grid size-9 place-items-center rounded-lg text-content-secondary hover:bg-surface-sunken hover:text-[var(--color-warning)]"
            >
              <RefreshCw className="size-4" aria-hidden />
            </button>
          )}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.tables")}
        action={
          <div className="flex gap-2">
            <Button
              variant="secondary"
              icon={<Printer className="size-4" aria-hidden />}
              onClick={() =>
                window.open(
                  `/admin/tables/print${branchId ? `?branch_id=${branchId}` : ""}`,
                  "_blank",
                )
              }
            >
              {t("admin.printQr")}
            </Button>

            {can("tables.manage") && (
              <Button
                icon={<Layers className="size-4" aria-hidden />}
                onClick={() => setBulkOpen(true)}
              >
                {t("admin.newTable")}
              </Button>
            )}
          </div>
        }
      />

      {branches && branches.length > 1 && (
        <Select
          value={branchId}
          onChange={(event) => {
            setBranchId(event.target.value);
            setPage(1);
          }}
          aria-label={t("admin.branches")}
          className="mb-4 w-auto"
        >
          <option value="">{t("menu.allCategories")}</option>
          {branches.map((branch) => (
            <option key={branch.id} value={branch.id}>
              {branch.name}
            </option>
          ))}
        </Select>
      )}

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(table) => table.id}
        emptyTitle={t("state.empty")}
        emptyIcon={<ClipboardList className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />

      {bulkOpen && (
        <BulkCreate
          branches={branches ?? []}
          onClose={() => setBulkOpen(false)}
          onSaved={async () => {
            setBulkOpen(false);
            await refresh();
          }}
        />
      )}

      {qrTable && <QrPreview table={qrTable} onClose={() => setQrTable(null)} />}
    </>
  );
}

function BulkCreate({
  branches,
  onClose,
  onSaved,
}: {
  branches: Array<{ id: number; name: string }>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({
    branch_id: String(branches[0]?.id ?? ""),
    from: "1",
    to: "10",
    zone: "",
    capacity: "4",
  });

  const submit = async () => {
    setBusy(true);

    try {
      const created = await adminApi.bulkCreateTables({
        branch_id: Number(form.branch_id),
        from: Number(form.from),
        to: Number(form.to),
        zone: form.zone || null,
        capacity: Number(form.capacity),
      });

      toast.success(`${created.length}`);
      onSaved();
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet
      open
      onClose={onClose}
      title={t("admin.newTable")}
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" fullWidth onClick={onClose}>
            {t("action.cancel")}
          </Button>
          <Button fullWidth loading={busy} onClick={submit}>
            {t("action.save")}
          </Button>
        </div>
      }
    >
      <div className="space-y-4 py-2">
        <Field label={t("admin.branches")} htmlFor="branch" required>
          <Select
            id="branch"
            value={form.branch_id}
            onChange={(event) => setForm({ ...form, branch_id: event.target.value })}
          >
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </Select>
        </Field>

        {/* A range rather than one-at-a-time: a venue numbers its tables
            sequentially, so this is the shape of the real task. */}
        <div className="grid grid-cols-2 gap-4">
          <Field label="From" htmlFor="from" required>
            <Input
              id="from"
              type="number"
              min={1}
              dir="ltr"
              value={form.from}
              onChange={(event) => setForm({ ...form, from: event.target.value })}
            />
          </Field>

          <Field label="To" htmlFor="to" required>
            <Input
              id="to"
              type="number"
              min={1}
              dir="ltr"
              value={form.to}
              onChange={(event) => setForm({ ...form, to: event.target.value })}
            />
          </Field>
        </div>

        <Field label="Zone" htmlFor="zone">
          <Input
            id="zone"
            value={form.zone}
            onChange={(event) => setForm({ ...form, zone: event.target.value })}
          />
        </Field>

        <Field label={t("checkout.guestCount")} htmlFor="capacity">
          <Input
            id="capacity"
            type="number"
            min={1}
            dir="ltr"
            value={form.capacity}
            onChange={(event) => setForm({ ...form, capacity: event.target.value })}
          />
        </Field>
      </div>
    </Sheet>
  );
}

function QrPreview({ table, onClose }: { table: DiningTable; onClose: () => void }) {
  const { t } = useI18n();
  const [svg, setSvg] = useState<string | null>(null);

  // The QR endpoint sits behind the admin guard, so it cannot be loaded with a
  // plain <img src> — that request would carry no Authorization header. It is
  // fetched through the API client and inlined instead.
  useEffect(() => {
    let cancelled = false;

    api
      .get<string>(`/admin/tables/${table.id}/qr`, {
        params: { size: 320 },
        responseType: "text",
      })
      .then((response) => {
        if (!cancelled) setSvg(response.data);
      })
      .catch(() => {
        if (!cancelled) setSvg(null);
      });

    return () => {
      cancelled = true;
    };
  }, [table.id]);

  return (
    <Sheet open onClose={onClose} title={`#${table.number}`} description={table.zone ?? undefined}>
      <div className="space-y-4 py-4 text-center">
        {svg ? (
          <div
            className="mx-auto w-[280px] rounded-2xl border border-border-subtle bg-white p-3"
            role="img"
            aria-label={t("admin.qrCode")}
            // Server-generated by BaconQrCode from a URL we construct; it
            // contains no user-supplied markup.
            dangerouslySetInnerHTML={{ __html: svg }}
          />
        ) : (
          <div className="skeleton mx-auto size-[280px] rounded-2xl" />
        )}

        {table.qr_url && (
          <p className="tabular rounded-lg bg-surface-sunken px-3 py-2 text-xs break-all text-content-secondary">
            {table.qr_url}
          </p>
        )}

        <Button
          variant="secondary"
          fullWidth
          icon={<Printer className="size-4" aria-hidden />}
          onClick={() => window.open(`/admin/tables/print?branch_id=${table.branch_id}`, "_blank")}
        >
          {t("admin.printQr")}
        </Button>
      </div>
    </Sheet>
  );
}
