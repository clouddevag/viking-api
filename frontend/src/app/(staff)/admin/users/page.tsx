"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Plus, Shield, UsersRound } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Card, Field, Input, Select, Switch } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useAdminBranches, useAdminRoles, useAdminUsers } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { formatRelative } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn } from "@/lib/utils";
import type { User } from "@/types/api";

/**
 * Users and roles.
 *
 * The permission matrix edits roles rather than individuals, which is what
 * keeps access reviewable — "what can a cashier do" has one answer instead of
 * one per cashier.
 */
export default function AdminUsersPage() {
  const { t, locale } = useI18n();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [tab, setTab] = useState<"users" | "roles">("users");
  const [editing, setEditing] = useState<User | "new" | null>(null);

  const { data, isLoading } = useAdminUsers({ page, search: search || undefined, staff_only: true });
  const { data: roles } = useAdminRoles();
  const { data: branches } = useAdminBranches();

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "users"] });

  const columns: Array<Column<User>> = [
    {
      key: "name",
      header: t("checkout.name"),
      cell: (user) => (
        <span>
          <span className="block font-medium text-content">{user.name}</span>
          <span className="block text-xs text-content-muted" dir="ltr">
            {user.email ?? user.phone}
          </span>
        </span>
      ),
    },
    {
      key: "roles",
      header: t("admin.roles"),
      cell: (user) => (
        <span className="flex flex-wrap gap-1">
          {user.roles?.map((role) => (
            <Badge key={role} tone={role === "super-admin" ? "gold" : "accent"}>
              {role}
            </Badge>
          ))}
        </span>
      ),
    },
    {
      key: "branch",
      header: t("admin.branches"),
      secondary: true,
      cell: (user) => (
        <span className="text-content-secondary">{user.branch?.name ?? "—"}</span>
      ),
    },
    {
      key: "last",
      header: t("auth.signIn"),
      secondary: true,
      cell: (user) => (
        <span className="text-content-muted">{formatRelative(user.last_login_at, locale)}</span>
      ),
    },
    {
      key: "active",
      header: t("admin.active"),
      cell: (user) => (
        <Badge tone={user.is_active ? "success" : "neutral"}>
          {user.is_active ? t("admin.active") : t("admin.inactive")}
        </Badge>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.users")}
        action={
          can("users.manage") &&
          tab === "users" && (
            <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing("new")}>
              {t("admin.newUser")}
            </Button>
          )
        }
      />

      <div className="mb-4 flex gap-1 rounded-xl bg-surface-raised p-1" role="tablist">
        {(
          [
            { key: "users" as const, label: t("admin.users"), icon: UsersRound },
            { key: "roles" as const, label: t("admin.roles"), icon: Shield },
          ]
        ).map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={cn(
              "flex flex-1 items-center justify-center gap-2 rounded-lg py-2 text-sm font-medium transition-colors",
              tab === item.key ? "bg-accent-soft text-accent" : "text-content-muted",
            )}
          >
            <item.icon className="size-4" aria-hidden />
            {item.label}
          </button>
        ))}
      </div>

      {tab === "users" ? (
        <>
          <Input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
            placeholder={t("menu.searchPlaceholder")}
            aria-label={t("action.search")}
            className="mb-4 max-w-xs"
          />

          <DataTable
            columns={columns}
            rows={data?.data ?? []}
            loading={isLoading}
            rowKey={(user) => user.id}
            onRowClick={can("users.manage") ? (user) => setEditing(user) : undefined}
            emptyTitle={t("state.empty")}
            emptyIcon={<UsersRound className="size-7" aria-hidden />}
            meta={data?.meta}
            onPageChange={setPage}
          />
        </>
      ) : (
        <RoleMatrix />
      )}

      {editing && (
        <UserEditor
          user={editing === "new" ? null : editing}
          roles={roles?.roles.map((role) => role.name) ?? []}
          branches={branches ?? []}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await refresh();
          }}
        />
      )}
    </>
  );
}

function RoleMatrix() {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);
  const { data, isLoading } = useAdminRoles();
  const [saving, setSaving] = useState<number | null>(null);

  if (isLoading || !data) {
    return (
      <Card className="h-64">
        <span className="sr-only">{t("state.loading")}</span>
      </Card>
    );
  }

  const allPermissions = Object.entries(data.permissions);

  const toggle = async (
    role: { id: number; name: string; permissions: string[] },
    permission: string,
  ) => {
    setSaving(role.id);

    const next = role.permissions.includes(permission)
      ? role.permissions.filter((item) => item !== permission)
      : [...role.permissions, permission];

    try {
      await adminApi.updateRole(role.id, next);
      await queryClient.invalidateQueries({ queryKey: ["admin", "roles"] });
      toast.success(t("admin.saved"));
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setSaving(null);
    }
  };

  return (
    <div className="space-y-4">
      {data.roles
        // Both are managed by the system and rejected by the API, so they are
        // shown read-only rather than offering an edit that would fail.
        .filter((role) => !["super-admin", "customer"].includes(role.name))
        .map((role) => (
          <Card key={role.id}>
            <header className="mb-4 flex items-center justify-between gap-3">
              <h2 className="text-base font-semibold text-content">{role.name}</h2>
              <span className="text-sm text-content-muted">
                {role.users_count} · {role.permissions.length}
              </span>
            </header>

            <div className="space-y-4">
              {allPermissions.map(([group, permissions]) => (
                <fieldset key={group}>
                  <legend className="mb-2 text-xs font-semibold tracking-wide text-content-secondary uppercase">
                    {group}
                  </legend>

                  <div className="flex flex-wrap gap-2">
                    {permissions.map((permission) => {
                      const granted = role.permissions.includes(permission);

                      return (
                        <button
                          key={permission}
                          type="button"
                          role="switch"
                          aria-checked={granted}
                          disabled={!can("roles.manage") || saving === role.id}
                          onClick={() => toggle(role, permission)}
                          className={cn(
                            "rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors disabled:opacity-50",
                            granted
                              ? "bg-accent text-accent-contrast"
                              : "bg-surface-sunken text-content-muted hover:bg-border-subtle",
                          )}
                        >
                          {permission.split(".")[1]}
                        </button>
                      );
                    })}
                  </div>
                </fieldset>
              ))}
            </div>
          </Card>
        ))}
    </div>
  );
}

function UserEditor({
  user,
  roles,
  branches,
  onClose,
  onSaved,
}: {
  user: User | null;
  roles: string[];
  branches: Array<{ id: number; name: string }>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [form, setForm] = useState({
    name: user?.name ?? "",
    email: user?.email ?? "",
    phone: user?.phone ?? "",
    password: "",
    branch_id: user?.branch_id ? String(user.branch_id) : "",
    role: user?.roles?.[0] ?? "cashier",
    is_active: user?.is_active ?? true,
  });

  const save = async () => {
    setBusy(true);
    setErrors({});

    const payload: Record<string, unknown> = {
      name: form.name,
      email: form.email,
      phone: form.phone || null,
      branch_id: form.branch_id ? Number(form.branch_id) : null,
      roles: [form.role],
      is_active: form.is_active,
    };

    // Only send a password when one was actually typed, so editing a user does
    // not silently reset it.
    if (form.password) payload.password = form.password;

    try {
      if (user) {
        await adminApi.updateUser(user.id, payload);
      } else {
        await adminApi.createUser(payload);
      }

      toast.success(t("admin.saved"));
      onSaved();
    } catch (error) {
      if (error instanceof ApiRequestError && error.fieldErrors) {
        setErrors(
          Object.fromEntries(
            Object.entries(error.fieldErrors).map(([field, messages]) => [field, messages[0]]),
          ),
        );
      }
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet
      open
      onClose={onClose}
      side="end"
      title={user ? user.name : t("admin.newUser")}
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" fullWidth onClick={onClose}>
            {t("action.cancel")}
          </Button>
          <Button fullWidth loading={busy} onClick={save}>
            {t("action.save")}
          </Button>
        </div>
      }
    >
      <div className="space-y-4 py-2">
        <Field label={t("checkout.name")} htmlFor="name" error={errors.name} required>
          <Input
            id="name"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            invalid={Boolean(errors.name)}
          />
        </Field>

        <Field label="Email" htmlFor="email" error={errors.email} required>
          <Input
            id="email"
            type="email"
            dir="ltr"
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            invalid={Boolean(errors.email)}
          />
        </Field>

        <Field label={t("checkout.phone")} htmlFor="phone" error={errors.phone}>
          <Input
            id="phone"
            type="tel"
            dir="ltr"
            value={form.phone}
            onChange={(event) => setForm({ ...form, phone: event.target.value })}
            invalid={Boolean(errors.phone)}
          />
        </Field>

        <Field
          label={t("auth.password")}
          htmlFor="password"
          error={errors.password}
          hint={user ? t("account.changePassword") : undefined}
          required={!user}
        >
          <Input
            id="password"
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={(event) => setForm({ ...form, password: event.target.value })}
            invalid={Boolean(errors.password)}
          />
        </Field>

        <Field label={t("admin.roles")} htmlFor="role" error={errors.roles} required>
          <Select
            id="role"
            value={form.role}
            onChange={(event) => setForm({ ...form, role: event.target.value })}
          >
            {roles
              .filter((role) => role !== "customer")
              .map((role) => (
                <option key={role} value={role}>
                  {role}
                </option>
              ))}
          </Select>
        </Field>

        <Field label={t("admin.branches")} htmlFor="branch" error={errors.branch_id}>
          <Select
            id="branch"
            value={form.branch_id}
            onChange={(event) => setForm({ ...form, branch_id: event.target.value })}
          >
            <option value="">—</option>
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </Select>
        </Field>

        <div className="flex items-center justify-between border-t border-border-subtle pt-4">
          <span className="text-sm font-medium text-content">{t("admin.active")}</span>
          <Switch
            checked={form.is_active}
            onChange={(checked) => setForm({ ...form, is_active: checked })}
            label={t("admin.active")}
          />
        </div>
      </div>
    </Sheet>
  );
}
