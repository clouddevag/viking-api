"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Save } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Card, Field, Input, Skeleton, Switch } from "@/components/ui/primitives";
import { useAdminBranches, useAdminSettings } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";

type SettingRow = { key: string; value: unknown; type: string; is_public: boolean };

/**
 * Settings.
 *
 * The API only accepts keys that already exist, so this renders whatever the
 * backend defines rather than hard-coding a form — adding a setting is a
 * seeder change and it appears here automatically, typed by its stored value.
 */
export default function AdminSettingsPage() {
  const { t } = useI18n();
  const queryClient = useQueryClient();

  const { data, isLoading } = useAdminSettings();
  const { data: branches } = useAdminBranches();

  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [busy, setBusy] = useState(false);

  // Seed the draft once the server values arrive; edits then live locally
  // until saved, so a slow connection cannot clobber typing.
  useEffect(() => {
    if (!data) return;

    const initial: Record<string, unknown> = {};
    Object.values(data).forEach((rows) =>
      (rows as SettingRow[]).forEach((row) => {
        initial[row.key] = row.value;
      }),
    );
    setDraft(initial);
  }, [data]);

  const save = async () => {
    setBusy(true);

    try {
      await adminApi.updateSettings(
        Object.entries(draft).map(([key, value]) => ({ key, value })),
      );
      await queryClient.invalidateQueries({ queryKey: ["admin", "settings"] });
      toast.success(t("admin.saved"));
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setBusy(false);
    }
  };

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title={t("admin.settings")} />
        <div className="space-y-4">
          {Array.from({ length: 3 }, (_, i) => (
            <Skeleton key={i} className="h-48 rounded-2xl" />
          ))}
        </div>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={t("admin.settings")}
        action={
          <Button icon={<Save className="size-4" aria-hidden />} loading={busy} onClick={save}>
            {t("action.save")}
          </Button>
        }
      />

      <div className="space-y-4">
        {Object.entries(data).map(([group, rows]) => (
          <Card key={group}>
            <h2 className="mb-4 text-base font-semibold text-content capitalize">{group}</h2>

            <div className="grid gap-4 sm:grid-cols-2">
              {(rows as SettingRow[]).map((row) => {
                const value = draft[row.key];

                if (typeof row.value === "boolean") {
                  return (
                    <div
                      key={row.key}
                      className="flex items-center justify-between gap-3 rounded-xl bg-surface-sunken px-4 py-3"
                    >
                      <span className="text-sm font-medium text-content">
                        {row.key.replaceAll("_", " ")}
                      </span>
                      <Switch
                        checked={Boolean(value)}
                        onChange={(checked) =>
                          setDraft((current) => ({ ...current, [row.key]: checked }))
                        }
                        label={row.key}
                      />
                    </div>
                  );
                }

                const numeric = typeof row.value === "number";

                return (
                  <Field key={row.key} label={row.key.replaceAll("_", " ")} htmlFor={row.key}>
                    <Input
                      id={row.key}
                      type={numeric ? "number" : "text"}
                      dir={numeric || row.key.includes("email") || row.key.includes("phone") ? "ltr" : undefined}
                      value={value === null || value === undefined ? "" : String(value)}
                      onChange={(event) =>
                        setDraft((current) => ({
                          ...current,
                          [row.key]: numeric ? Number(event.target.value) : event.target.value,
                        }))
                      }
                    />
                  </Field>
                );
              })}
            </div>
          </Card>
        ))}

        {/* Branches are settings-adjacent, so they live on the same screen. */}
        {branches && branches.length > 0 && (
          <Card>
            <h2 className="mb-4 text-base font-semibold text-content">{t("admin.branches")}</h2>

            <ul className="divide-y divide-border-subtle">
              {branches.map((branch) => (
                <li key={branch.id} className="flex items-center justify-between gap-4 py-3">
                  <div className="min-w-0">
                    <p className="font-medium text-content">{branch.name}</p>
                    <p className="text-sm text-content-muted">{branch.address ?? "—"}</p>
                  </div>

                  <span className="tabular shrink-0 text-sm text-content-secondary">
                    {branch.opens_at.slice(0, 5)}–{branch.closes_at.slice(0, 5)} ·{" "}
                    {branch.tables_count ?? 0}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>
    </>
  );
}
