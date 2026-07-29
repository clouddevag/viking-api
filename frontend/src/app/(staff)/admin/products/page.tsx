"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Plus, UtensilsCrossed } from "lucide-react";
import Image from "next/image";
import { useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Field, Input, Select, Switch, Textarea } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useAdminCategories, useAdminProducts } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import type { Product } from "@/types/api";

/**
 * Product management.
 *
 * The editor writes both locales side by side rather than behind a language
 * switch — a menu item that exists in English but not Arabic is a bug the
 * kitchen finds out about at dinner service, so both fields are required and
 * visible together.
 */
export default function AdminProductsPage() {
  const { t, locale } = useI18n();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [editing, setEditing] = useState<Product | "new" | null>(null);

  const { data, isLoading } = useAdminProducts({
    page,
    search: search || undefined,
    category_id: categoryId || undefined,
  });
  const { data: categories } = useAdminCategories();

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "products"] });

  const toggleAvailability = async (product: Product) => {
    try {
      const available = await adminApi.toggleProductAvailability(product.slug);
      toast.success(available ? t("admin.available") : t("admin.soldOut"));
      await refresh();
    } catch {
      toast.error(t("state.error"));
    }
  };

  const columns: Array<Column<Product>> = [
    {
      key: "product",
      header: t("admin.products"),
      cell: (product) => (
        <span className="flex items-center gap-3">
          <span className="relative size-10 shrink-0 overflow-hidden rounded-lg bg-surface-sunken">
            {product.image ? (
              <Image src={product.image.thumb} alt="" fill sizes="40px" className="object-cover" />
            ) : (
              <span className="grid size-full place-items-center text-sm text-content-muted">
                {product.name.charAt(0)}
              </span>
            )}
          </span>

          <span className="min-w-0">
            <span className="block truncate font-medium text-content">{product.name}</span>
            <span className="tabular block text-xs text-content-muted">{product.sku}</span>
          </span>
        </span>
      ),
    },
    {
      key: "category",
      header: t("admin.category"),
      secondary: true,
      cell: (product) => (
        <span className="text-content-secondary">{product.category?.name ?? "—"}</span>
      ),
    },
    {
      key: "price",
      header: t("admin.price"),
      className: "text-end",
      cell: (product) => (
        <span className="tabular font-semibold">
          {formatMoney(product.base_price, product.currency, locale)}
        </span>
      ),
    },
    {
      key: "orders",
      header: t("menu.popular"),
      secondary: true,
      className: "text-end",
      cell: (product) => <span className="tabular">{product.order_count ?? 0}</span>,
    },
    {
      key: "status",
      header: t("admin.available"),
      cell: (product) => (
        <span className="flex items-center gap-2">
          <Switch
            checked={product.is_available}
            onChange={() => toggleAvailability(product)}
            label={t("admin.available")}
            disabled={!can("products.manage")}
          />
          {!product.is_active && <Badge tone="neutral">{t("admin.inactive")}</Badge>}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.products")}
        action={
          can("products.manage") && (
            <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing("new")}>
              {t("admin.newProduct")}
            </Button>
          )
        }
      />

      <div className="mb-4 flex flex-wrap gap-3">
        <Input
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
          placeholder={t("menu.searchPlaceholder")}
          aria-label={t("action.search")}
          className="max-w-xs"
        />

        <Select
          value={categoryId}
          onChange={(event) => {
            setCategoryId(event.target.value);
            setPage(1);
          }}
          aria-label={t("admin.category")}
          className="w-auto"
        >
          <option value="">{t("menu.allCategories")}</option>
          {categories?.data.map((category) => (
            <option key={category.id} value={category.id}>
              {category.name}
            </option>
          ))}
        </Select>
      </div>

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(product) => product.id}
        onRowClick={can("products.manage") ? (product) => setEditing(product) : undefined}
        emptyTitle={t("state.empty")}
        emptyIcon={<UtensilsCrossed className="size-7" aria-hidden />}
        meta={data?.meta}
        onPageChange={setPage}
      />

      {editing && (
        <ProductEditor
          product={editing === "new" ? null : editing}
          categories={categories?.data ?? []}
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

function ProductEditor({
  product,
  categories,
  onClose,
  onSaved,
}: {
  product: Product | null;
  categories: Array<{ id: number; name: string }>;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // The list response omits translations, so the editor starts from what it
  // has and the detail fetch fills in the rest.
  const [form, setForm] = useState({
    sku: product?.sku ?? "",
    category_id: String(product?.category_id ?? categories[0]?.id ?? ""),
    name_en: product?.translations?.name.en ?? "",
    name_ar: product?.translations?.name.ar ?? "",
    short_description_en: product?.translations?.short_description.en ?? "",
    short_description_ar: product?.translations?.short_description.ar ?? "",
    base_price: String(product?.base_price ?? ""),
    compare_at_price: product?.compare_at_price ? String(product.compare_at_price) : "",
    prep_time_minutes: String(product?.prep_time_minutes ?? 10),
    calories: product?.calories ? String(product.calories) : "",
    is_active: product?.is_active ?? true,
    is_available: product?.is_available ?? true,
    is_featured: product?.is_featured ?? false,
    is_new: product?.is_new ?? false,
  });

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const save = async () => {
    setBusy(true);
    setErrors({});

    const payload = {
      sku: form.sku,
      category_id: Number(form.category_id),
      name_en: form.name_en,
      name_ar: form.name_ar,
      short_description_en: form.short_description_en || null,
      short_description_ar: form.short_description_ar || null,
      base_price: Number(form.base_price),
      compare_at_price: form.compare_at_price ? Number(form.compare_at_price) : null,
      prep_time_minutes: Number(form.prep_time_minutes),
      calories: form.calories ? Number(form.calories) : null,
      is_active: form.is_active,
      is_available: form.is_available,
      is_featured: form.is_featured,
      is_new: form.is_new,
    };

    try {
      if (product) {
        await adminApi.updateProduct(product.slug, payload);
      } else {
        await adminApi.createProduct(payload);
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
      title={product ? product.name : t("admin.newProduct")}
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
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="SKU" htmlFor="sku" error={errors.sku} required>
            <Input
              id="sku"
              dir="ltr"
              value={form.sku}
              onChange={(event) => set("sku", event.target.value)}
              invalid={Boolean(errors.sku)}
            />
          </Field>

          <Field label={t("admin.category")} htmlFor="category" error={errors.category_id} required>
            <Select
              id="category"
              value={form.category_id}
              onChange={(event) => set("category_id", event.target.value)}
            >
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Field label={t("admin.nameEn")} htmlFor="name_en" error={errors.name_en} required>
          <Input
            id="name_en"
            dir="ltr"
            value={form.name_en}
            onChange={(event) => set("name_en", event.target.value)}
            invalid={Boolean(errors.name_en)}
          />
        </Field>

        <Field label={t("admin.nameAr")} htmlFor="name_ar" error={errors.name_ar} required>
          <Input
            id="name_ar"
            dir="rtl"
            value={form.name_ar}
            onChange={(event) => set("name_ar", event.target.value)}
            invalid={Boolean(errors.name_ar)}
          />
        </Field>

        <Field label={t("admin.descriptionEn")} htmlFor="desc_en">
          <Textarea
            id="desc_en"
            dir="ltr"
            rows={2}
            value={form.short_description_en}
            onChange={(event) => set("short_description_en", event.target.value)}
          />
        </Field>

        <Field label={t("admin.descriptionAr")} htmlFor="desc_ar">
          <Textarea
            id="desc_ar"
            dir="rtl"
            rows={2}
            value={form.short_description_ar}
            onChange={(event) => set("short_description_ar", event.target.value)}
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t("admin.price")} htmlFor="price" error={errors.base_price} required>
            <Input
              id="price"
              type="number"
              min={0}
              dir="ltr"
              value={form.base_price}
              onChange={(event) => set("base_price", event.target.value)}
              invalid={Boolean(errors.base_price)}
            />
          </Field>

          <Field label={t("admin.compareAtPrice")} htmlFor="compare">
            <Input
              id="compare"
              type="number"
              min={0}
              dir="ltr"
              value={form.compare_at_price}
              onChange={(event) => set("compare_at_price", event.target.value)}
            />
          </Field>

          <Field label={t("menu.prepTime", { count: "" }).trim()} htmlFor="prep">
            <Input
              id="prep"
              type="number"
              min={0}
              dir="ltr"
              value={form.prep_time_minutes}
              onChange={(event) => set("prep_time_minutes", event.target.value)}
            />
          </Field>

          <Field label={t("menu.calories", { count: "" }).trim()} htmlFor="calories">
            <Input
              id="calories"
              type="number"
              min={0}
              dir="ltr"
              value={form.calories}
              onChange={(event) => set("calories", event.target.value)}
            />
          </Field>
        </div>

        <div className="space-y-3 border-t border-border-subtle pt-4">
          {(
            [
              ["is_active", t("admin.active")],
              ["is_available", t("admin.available")],
              ["is_featured", t("menu.featured")],
              ["is_new", t("menu.new")],
            ] as const
          ).map(([key, label]) => (
            <div key={key} className="flex items-center justify-between">
              <span className="text-sm font-medium text-content">{label}</span>
              <Switch
                checked={form[key]}
                onChange={(checked) => set(key, checked)}
                label={label}
              />
            </div>
          ))}
        </div>
      </div>
    </Sheet>
  );
}
