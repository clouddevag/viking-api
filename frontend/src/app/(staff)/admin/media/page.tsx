"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Image as ImageIcon, Trash2, Upload } from "lucide-react";
import Image from "next/image";
import { useRef, useState } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/admin/data-table";
import { Button } from "@/components/ui/button";
import { EmptyState, Skeleton } from "@/components/ui/primitives";
import { useAdminMedia } from "@/hooks/queries";
import { adminApi } from "@/lib/api/endpoints";
import { ApiRequestError } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";

/**
 * Media library.
 *
 * Uploads go through the API, which generates the WebP conversions; this grid
 * shows the `thumb` variant so a page of 40 images costs a fraction of what the
 * originals would.
 */
export default function AdminMediaPage() {
  const { t } = useI18n();
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);

  const [page, setPage] = useState(1);
  const [uploading, setUploading] = useState(false);

  const { data, isLoading } = useAdminMedia({ page });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin", "media"] });

  const upload = async (files: FileList | null) => {
    if (!files || files.length === 0) return;

    setUploading(true);

    try {
      await adminApi.uploadMedia(Array.from(files));
      toast.success(t("admin.saved"));
      await refresh();
    } catch (error) {
      toast.error(error instanceof ApiRequestError ? error.message : t("state.error"));
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm(t("admin.deleteConfirm"))) return;

    try {
      await adminApi.deleteMedia(id);
      toast.success(t("admin.deleted"));
      await refresh();
    } catch {
      toast.error(t("state.error"));
    }
  };

  return (
    <>
      <PageHeader
        title={t("admin.media")}
        action={
          <>
            <input
              ref={inputRef}
              type="file"
              multiple
              accept="image/jpeg,image/png,image/webp,image/avif"
              className="sr-only"
              onChange={(event) => upload(event.target.files)}
            />
            <Button
              icon={<Upload className="size-4" aria-hidden />}
              loading={uploading}
              onClick={() => inputRef.current?.click()}
            >
              {t("action.add")}
            </Button>
          </>
        }
      />

      {isLoading ? (
        <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
          {Array.from({ length: 12 }, (_, i) => (
            <Skeleton key={i} className="aspect-square rounded-xl" />
          ))}
        </div>
      ) : (data?.data ?? []).length === 0 ? (
        <div className="surface-card">
          <EmptyState
            icon={<ImageIcon className="size-7" aria-hidden />}
            title={t("state.empty")}
            description={t("account.installHelp")}
          />
        </div>
      ) : (
        <>
          <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
            {data?.data.map((media) => (
              <li key={media.id} className="group relative">
                <div className="relative aspect-square overflow-hidden rounded-xl bg-surface-sunken">
                  <Image
                    src={media.thumb}
                    alt={media.alt ?? ""}
                    fill
                    sizes="(max-width: 640px) 33vw, 160px"
                    className="object-cover"
                  />
                </div>

                <button
                  type="button"
                  onClick={() => remove(media.id)}
                  aria-label={t("action.delete")}
                  className="absolute end-1.5 top-1.5 grid size-8 place-items-center rounded-lg bg-surface-raised/90 text-content-muted opacity-0 backdrop-blur-sm transition-opacity group-hover:opacity-100 focus-visible:opacity-100 hover:text-[var(--color-danger)]"
                >
                  <Trash2 className="size-4" aria-hidden />
                </button>
              </li>
            ))}
          </ul>

          {data && data.meta.last_page > 1 && (
            <div className="mt-5 flex justify-center gap-2">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => setPage((current) => current - 1)}
              >
                {t("action.back")}
              </Button>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= data.meta.last_page}
                onClick={() => setPage((current) => current + 1)}
              >
                {t("action.next")}
              </Button>
            </div>
          )}
        </>
      )}
    </>
  );
}
