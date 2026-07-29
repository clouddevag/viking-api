"use client";

import { Heart } from "lucide-react";
import Link from "next/link";

import { ProductCard, ProductCardSkeleton } from "@/components/customer/product-card";
import { EmptyState } from "@/components/ui/primitives";
import { useFavorites, useToggleFavorite } from "@/hooks/queries";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";

export default function FavoritesPage() {
  const { t } = useI18n();
  const status = useAuthStore((state) => state.status);
  const toggleFavorite = useToggleFavorite();

  // Favourites are stored server-side against the account, so this needs one.
  const { data, isLoading } = useFavorites();

  if (status === "anonymous") {
    return (
      <div className="mx-auto max-w-2xl px-4">
        <EmptyState
          icon={<Heart className="size-7" aria-hidden />}
          title={t("nav.favorites")}
          description={t("error.unauthorized")}
          action={
            <Link
              href="/auth/login"
              className="inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-accent-contrast"
            >
              {t("auth.signIn")}
            </Link>
          }
        />
      </div>
    );
  }

  if (isLoading || status === "loading" || status === "idle") {
    return (
      <div className="mx-auto max-w-6xl px-4 py-6">
        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 6 }, (_, i) => (
            <ProductCardSkeleton key={i} />
          ))}
        </div>
      </div>
    );
  }

  const products = data?.data ?? [];

  if (products.length === 0) {
    return (
      <div className="mx-auto max-w-2xl px-4">
        <EmptyState
          icon={<Heart className="size-7" aria-hidden />}
          title={t("state.empty")}
          description={t("cart.emptyHelp")}
          action={
            <Link
              href="/menu"
              className="inline-flex h-11 items-center rounded-xl bg-accent px-5 font-medium text-accent-contrast"
            >
              {t("menu.title")}
            </Link>
          }
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <h1 className="mb-5 text-2xl font-semibold text-content">{t("nav.favorites")}</h1>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        {products.map((product, index) => (
          <ProductCard
            key={product.id}
            product={product}
            priority={index < 4}
            onToggleFavorite={(p) => toggleFavorite.mutate(p.id)}
          />
        ))}
      </div>
    </div>
  );
}
