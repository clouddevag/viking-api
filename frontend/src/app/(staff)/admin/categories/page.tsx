"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Plus, Shapes } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { DataTable, PageHeader, type Column } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { Badge, Field, Input, Switch, Textarea } from "@/components/ui/primitives";
import { Sheet } from "@/components/ui/sheet";
import { useAdminCategories } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import type { Category } from "@/types/api";

export default function AdminCategoriesPage() {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const can = useAuthStore((state) => state.can);
  const [editing, setEditing] = useState<Category | "new" | null>(null);

  const { data, isLoading } = useAdminCategories();
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "categories"] });

  const columns: Array<Column<Category>> = [
    {
      key: "name",
      header: t("admin.categories"),
      cell: (category) => (
        <span className="flex items-center gap-3">
          <span
            className="grid size-9 shrink-0 place-items-center rounded-lg text-sm font-bold"
            style={{
              backgroundColor: `${category.accent_color ?? "#C8642A"}1F`,
              color: category.accent_color ?? undefined,
            }}
            aria-hidden
          >
            {category.name.charAt(0)}
          </span>
          <span className="font-medium text-content">{category.name}</span>
        </span>
      ),
    },
    {
      key: "products",
      header: t("admin.products"),
      className: "text-end",
      cell: (category) => <span className="tabular">{category.products_count ?? 0}</span>,
    },
    {
      key: "order",
      header: "#",
      secondary: true,
      className: "text-end",
      cell: (category) => <span className="tabular">{category.sort_order}</span>,
    },
    {
      key: "status",
      header: t("admin.active"),
      cell: (category) => (
        <Badge tone={category.is_active === false ? "neutral" : "success"}>
          {category.is_active === false ? t("admin.inactive") : t("admin.active")}
        </Badge>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("admin.categories")}
        action={
          can("categories.manage") && (
            <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing("new")}>
              {t("admin.newCategory")}
            </Button>
          )
        }
      />

      <DataTable
        columns={columns}
        rows={data?.data ?? []}
        loading={isLoading}
        rowKey={(category) => category.id}
        onRowClick={can("categories.manage") ? (category) => setEditing(category) : undefined}
        emptyTitle={t("state.empty")}
        emptyIcon={<Shapes className="size-7" aria-hidden />}
      />

      {editing && (
        <CategoryEditor
          category={editing === "new" ? null : editing}
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

function CategoryEditor({
  category,
  onClose,
  onSaved,
}: {
  category: Category | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [form, setForm] = useState({
    name_en: category?.translations?.name.en ?? "",
    name_ar: category?.translations?.name.ar ?? "",
    description_en: category?.translations?.description.en ?? "",
    description_ar: category?.translations?.description.ar ?? "",
    accent_color: category?.accent_color ?? "#C8642A",
    sort_order: String(category?.sort_order ?? 0),
    is_active: category?.is_active ?? true,
    is_featured: category?.is_featured ?? false,
  });

  const save = async () => {
    setBusy(true);
    setErrors({});

    const payload = {
      name_en: form.name_en,
      name_ar: form.name_ar,
      description_en: form.description_en || null,
      description_ar: form.description_ar || null,
      accent_color: form.accent_color,
      sort_order: Number(form.sort_order),
      is_active: form.is_active,
      is_featured: form.is_featured,
    };

    try {
      if (category) {
        await adminApi.updateCategory(category.slug, payload);
      } else {
        await adminApi.createCategory(payload);
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
      title={category ? category.name : t("admin.newCategory")}
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
        <Field label={t("admin.nameEn")} htmlFor="name_en" error={errors.name_en} required>
          <Input
            id="name_en"
            dir="ltr"
            value={form.name_en}
            onChange={(event) => setForm({ ...form, name_en: event.target.value })}
            invalid={Boolean(errors.name_en)}
          />
        </Field>

        <Field label={t("admin.nameAr")} htmlFor="name_ar" error={errors.name_ar} required>
          <Input
            id="name_ar"
            dir="rtl"
            value={form.name_ar}
            onChange={(event) => setForm({ ...form, name_ar: event.target.value })}
            invalid={Boolean(errors.name_ar)}
          />
        </Field>

        <Field label={t("admin.descriptionEn")} htmlFor="desc_en">
          <Textarea
            id="desc_en"
            dir="ltr"
            rows={2}
            value={form.description_en}
            onChange={(event) => setForm({ ...form, description_en: event.target.value })}
          />
        </Field>

        <Field label={t("admin.descriptionAr")} htmlFor="desc_ar">
          <Textarea
            id="desc_ar"
            dir="rtl"
            rows={2}
            value={form.description_ar}
            onChange={(event) => setForm({ ...form, description_ar: event.target.value })}
          />
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Colour" htmlFor="colour">
            <Input
              id="colour"
              type="color"
              value={form.accent_color}
              onChange={(event) => setForm({ ...form, accent_color: event.target.value })}
              className="h-11 p-1"
            />
          </Field>

          <Field label="#" htmlFor="sort">
            <Input
              id="sort"
              type="number"
              min={0}
              dir="ltr"
              value={form.sort_order}
              onChange={(event) => setForm({ ...form, sort_order: event.target.value })}
            />
          </Field>
        </div>

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
            <span className="text-sm font-medium text-content">{t("menu.featured")}</span>
            <Switch
              checked={form.is_featured}
              onChange={(checked) => setForm({ ...form, is_featured: checked })}
              label={t("menu.featured")}
            />
          </div>
        </div>
      </div>
    </Sheet>
  );
}
