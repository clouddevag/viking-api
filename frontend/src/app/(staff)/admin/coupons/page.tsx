"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Plus, Tag } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Field, Input, Select, Switch } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useAdminCoupons } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import type { Coupon } from "@/types/api";

export default function AdminCouponsPage() {
  const { t, locale } = useI18n();
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Coupon | "new" | null>(null);

  const { data, isLoading } = useAdminCoupons({ page });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "coupons"] });

  const columns: Array<Column<Coupon>> = [
    {
      key: "code",
      header: t("cart.couponPlaceholder"),
      cell: (coupon) => (
        <span>
          <span className="tabular block font-bold text-content">{coupon.code}</span>
          <span className="block text-xs text-content-muted">{coupon.name}</span>
        </span>
      ),
    },
    {
      key: "value",
      header: t("cart.discount"),
      cell: (coupon) => (
        <span className="tabular font-medium">
          {coupon.type === "percentage"
            ? `${coupon.value}%`
            : formatMoney(coupon.value, "IQD", locale)}
        </span>
      ),
    },
    {
      key: "usage",
      header: t("menu.popular"),
      secondary: true,
      className: "text-end",
      cell: (coupon) => (
        <span className="tabular">
          {coupon.used_count ?? 0}
          {coupon.usage_limit ? ` / ${coupon.usage_limit}` : ""}
        </span>
      ),
    },
    {
      key: "expires",
      header: "—",
      secondary: true,
      cell: (coupon) => (
        <span className="text-content-muted">{formatDate(coupon.expires_at, locale)}</span>
      ),
    },
    {
      key: "status",
      header: t("admin.active"),
      cell: (coupon) => (
        <Badge tone={coupon.is_active ? "success" : "neutral"}>
          {coupon.is_active ? t("admin.active") : t("admin.inactive")}
        </Badge>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.coupons")}
        action={
          <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing("new")}>
            {t("admin.newCoupon")}
          </Button>
        }
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(coupon) => coupon.id}
        onRowClick={(coupon) => setEditing(coupon)}
        emptyTitle={t("state.empty")}
        emptyIcon={<Tag className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />

      {editing && (
        <CouponEditor
          coupon={editing === "new" ? null : editing}
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

function CouponEditor({
  coupon,
  onClose,
  onSaved,
}: {
  coupon: Coupon | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [form, setForm] = useState({
    code: coupon?.code ?? "",
    name_en: "",
    name_ar: "",
    type: coupon?.type ?? "percentage",
    value: String(coupon?.value ?? ""),
    minimum_order_amount: String(coupon?.minimum_order_amount ?? 0),
    maximum_discount_amount: coupon?.maximum_discount_amount
      ? String(coupon.maximum_discount_amount)
      : "",
    usage_limit: coupon?.usage_limit ? String(coupon.usage_limit) : "",
    usage_limit_per_user: coupon?.usage_limit_per_user ? String(coupon.usage_limit_per_user) : "",
    expires_at: coupon?.expires_at ? coupon.expires_at.slice(0, 10) : "",
    first_order_only: coupon?.first_order_only ?? false,
    is_active: coupon?.is_active ?? true,
  });

  const save = async () => {
    setBusy(true);
    setErrors({});

    const payload = {
      code: form.code.toUpperCase(),
      name_en: form.name_en || form.code,
      name_ar: form.name_ar || form.code,
      type: form.type,
      value: Number(form.value),
      minimum_order_amount: Number(form.minimum_order_amount) || 0,
      maximum_discount_amount: form.maximum_discount_amount
        ? Number(form.maximum_discount_amount)
        : null,
      usage_limit: form.usage_limit ? Number(form.usage_limit) : null,
      usage_limit_per_user: form.usage_limit_per_user ? Number(form.usage_limit_per_user) : null,
      expires_at: form.expires_at || null,
      first_order_only: form.first_order_only,
      is_active: form.is_active,
    };

    try {
      if (coupon) {
        await adminApi.updateCoupon(coupon.code, payload);
      } else {
        await adminApi.createCoupon(payload);
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
      title={coupon ? coupon.code : t("admin.newCoupon")}
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
        <Field label={t("cart.couponPlaceholder")} htmlFor="code" error={errors.code} required>
          <Input
            id="code"
            dir="ltr"
            className="uppercase"
            value={form.code}
            onChange={(event) => setForm({ ...form, code: event.target.value })}
            invalid={Boolean(errors.code)}
          />
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field label={t("admin.nameEn")} htmlFor="name_en">
            <Input
              id="name_en"
              dir="ltr"
              value={form.name_en}
              onChange={(event) => setForm({ ...form, name_en: event.target.value })}
            />
          </Field>

          <Field label={t("admin.nameAr")} htmlFor="name_ar">
            <Input
              id="name_ar"
              dir="rtl"
              value={form.name_ar}
              onChange={(event) => setForm({ ...form, name_ar: event.target.value })}
            />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Field label={t("cart.discount")} htmlFor="type" required>
            <Select
              id="type"
              value={form.type}
              onChange={(event) =>
                setForm({ ...form, type: event.target.value as "fixed" | "percentage" })
              }
            >
              <option value="percentage">%</option>
              <option value="fixed">IQD</option>
            </Select>
          </Field>

          <Field label={t("admin.price")} htmlFor="value" error={errors.value} required>
            <Input
              id="value"
              type="number"
              min={0}
              dir="ltr"
              value={form.value}
              onChange={(event) => setForm({ ...form, value: event.target.value })}
              invalid={Boolean(errors.value)}
            />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Field label={t("cart.subtotal")} htmlFor="minimum">
            <Input
              id="minimum"
              type="number"
              min={0}
              dir="ltr"
              value={form.minimum_order_amount}
              onChange={(event) => setForm({ ...form, minimum_order_amount: event.target.value })}
            />
          </Field>

          <Field label={t("cart.discount")} htmlFor="maximum">
            <Input
              id="maximum"
              type="number"
              min={0}
              dir="ltr"
              value={form.maximum_discount_amount}
              onChange={(event) =>
                setForm({ ...form, maximum_discount_amount: event.target.value })
              }
            />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Limit" htmlFor="limit">
            <Input
              id="limit"
              type="number"
              min={1}
              dir="ltr"
              value={form.usage_limit}
              onChange={(event) => setForm({ ...form, usage_limit: event.target.value })}
            />
          </Field>

          <Field label="Per user" htmlFor="per_user">
            <Input
              id="per_user"
              type="number"
              min={1}
              dir="ltr"
              value={form.usage_limit_per_user}
              onChange={(event) => setForm({ ...form, usage_limit_per_user: event.target.value })}
            />
          </Field>
        </div>

        <Field label="Expires" htmlFor="expires">
          <Input
            id="expires"
            type="date"
            dir="ltr"
            value={form.expires_at}
            onChange={(event) => setForm({ ...form, expires_at: event.target.value })}
          />
        </Field>

        <div className="space-y-3 border-t border-border-subtle pt-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-content">{t("admin.active")}</span>
            <Switch
              checked={form.is_active}
              onChange={(checked) => setForm({ ...form, is_active: checked })}
              label={t("admin.active")}
            />
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-content">First order only</span>
            <Switch
              checked={form.first_order_only}
              onChange={(checked) => setForm({ ...form, first_order_only: checked })}
              label="First order only"
            />
          </div>
        </div>
      </div>
    </Sheet>
  );
}
