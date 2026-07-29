"use client";

import { motion } from "framer-motion";
import { Flame, Heart, Star } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import { Badge } from "@/components/ui/primitives";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { cn } from "@/lib/utils";
import type { Product } from "@/types/api";

/**
 * A menu item card.
 *
 * The whole card is one link with the favourite button layered on top rather
 * than nested inside it — a button inside an anchor is invalid HTML and breaks
 * keyboard activation.
 */
export function ProductCard({
  product,
  onToggleFavorite,
  layout = "grid",
  priority = false,
}: {
  product: Product;
  onToggleFavorite?: (product: Product) => void;
  layout?: "grid" | "rail" | "row";
  priority?: boolean;
}) {
  const { t, locale } = useI18n();

  const unavailable = !product.is_available;
  const discounted =
    product.compare_at_price !== null && product.compare_at_price > product.base_price;

  if (layout === "row") {
    return (
      <div className="relative">
        <Link
          href={`/menu/${product.slug}`}
          className={cn(
            "surface-card flex items-center gap-4 p-3 transition-shadow hover:shadow-[var(--shadow-lifted)]",
            unavailable && "opacity-60",
          )}
        >
          <div className="relative size-20 shrink-0 overflow-hidden rounded-xl bg-surface-sunken">
            <ProductImage product={product} sizes="80px" priority={priority} />
          </div>

          <div className="min-w-0 flex-1">
            <h3 className="clamp-1 text-base font-semibold text-content">{product.name}</h3>
            {product.short_description && (
              <p className="clamp-1 mt-0.5 text-sm text-content-secondary">
                {product.short_description}
              </p>
            )}
            <p className="tabular mt-1.5 text-sm font-semibold text-accent">
              {formatMoney(product.base_price, product.currency, locale)}
            </p>
          </div>
        </Link>

        {onToggleFavorite && <FavoriteButton product={product} onToggle={onToggleFavorite} />}
      </div>
    );
  }

  return (
    <motion.div
      whileHover={{ y: -4 }}
      transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
      className={cn("relative", layout === "rail" && "w-[15rem] shrink-0")}
    >
      <Link
        href={`/menu/${product.slug}`}
        className={cn(
          "surface-card group flex h-full flex-col overflow-hidden p-0 transition-shadow hover:shadow-[var(--shadow-lifted)]",
          unavailable && "opacity-60",
        )}
      >
        <div className="relative aspect-4/3 overflow-hidden bg-surface-sunken">
          <ProductImage
            product={product}
            sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 240px"
            priority={priority}
            className="transition-transform duration-500 ease-[var(--ease-out-quint)] group-hover:scale-105"
          />

          <div className="absolute start-3 top-3 flex flex-col gap-1.5">
            {product.is_new && <Badge tone="accent">{t("menu.new")}</Badge>}
            {discounted && (
              <Badge tone="danger">
                -
                {Math.round(
                  ((product.compare_at_price! - product.base_price) / product.compare_at_price!) *
                    100,
                )}
                %
              </Badge>
            )}
            {product.spice_level > 0 && (
              <Badge tone="danger" icon={<Flame className="size-3" aria-hidden />}>
                {product.spice_level}
              </Badge>
            )}
          </div>

          {unavailable && (
            <div className="absolute inset-0 flex items-center justify-center bg-[var(--surface-overlay)]">
              <Badge tone="neutral" className="text-sm">
                {t("menu.soldOut")}
              </Badge>
            </div>
          )}
        </div>

        <div className="flex flex-1 flex-col p-4">
          <h3 className="clamp-2 text-base leading-snug font-semibold text-content">
            {product.name}
          </h3>

          {product.short_description && (
            <p className="clamp-2 mt-1 text-sm text-content-secondary">
              {product.short_description}
            </p>
          )}

          <div className="mt-auto flex items-end justify-between gap-2 pt-3">
            <div>
              <p className="tabular text-base font-bold text-accent">
                {formatMoney(product.base_price, product.currency, locale)}
              </p>
              {discounted && (
                <p className="tabular text-xs text-content-muted line-through">
                  {formatMoney(product.compare_at_price!, product.currency, locale)}
                </p>
              )}
            </div>

            {product.rating_count > 0 && (
              <span className="flex items-center gap-1 text-xs text-content-secondary">
                <Star
                  className="size-3.5 fill-[var(--color-gold-500)] text-[var(--color-gold-500)]"
                  aria-hidden
                />
                <span className="tabular">{product.rating_average.toFixed(1)}</span>
              </span>
            )}
          </div>
        </div>
      </Link>

      {onToggleFavorite && <FavoriteButton product={product} onToggle={onToggleFavorite} />}
    </motion.div>
  );
}

function ProductImage({
  product,
  sizes,
  priority,
  className,
}: {
  product: Product;
  sizes: string;
  priority?: boolean;
  className?: string;
}) {
  if (!product.image) {
    // A tinted monogram beats a broken-image icon while a venue is still
    // photographing its menu.
    return (
      <div
        className={cn(
          "flex size-full items-center justify-center bg-gradient-to-br from-[var(--color-ember-800)] to-[var(--color-bone-900)]",
          className,
        )}
        aria-hidden
      >
        <span className="font-display text-3xl text-[var(--color-ember-300)]/70">
          {product.name.charAt(0)}
        </span>
      </div>
    );
  }

  return (
    <Image
      src={product.image.medium}
      alt={product.image.alt ?? product.name}
      fill
      sizes={sizes}
      priority={priority}
      className={cn("object-cover", className)}
    />
  );
}

function FavoriteButton({
  product,
  onToggle,
}: {
  product: Product;
  onToggle: (product: Product) => void;
}) {
  const { t } = useI18n();
  const favorited = Boolean(product.is_favorite);

  return (
    <button
      type="button"
      onClick={() => onToggle(product)}
      aria-pressed={favorited}
      aria-label={favorited ? t("product.unfavorited") : t("product.favorited")}
      className={cn(
        "absolute end-3 top-3 z-10 grid size-9 place-items-center rounded-full",
        "bg-surface-raised/85 backdrop-blur-sm transition-all duration-200",
        "hover:scale-110 active:scale-95",
        favorited ? "text-[var(--color-danger)]" : "text-content-muted hover:text-content",
      )}
    >
      <Heart className={cn("size-4.5", favorited && "fill-current")} aria-hidden />
    </button>
  );
}

export function ProductCardSkeleton({ layout = "grid" }: { layout?: "grid" | "rail" }) {
  return (
    <div className={cn("surface-card overflow-hidden p-0", layout === "rail" && "w-[15rem] shrink-0")}>
      <div className="skeleton aspect-4/3" />
      <div className="space-y-2 p-4">
        <div className="skeleton h-4 w-4/5 rounded" />
        <div className="skeleton h-3 w-full rounded" />
        <div className="skeleton h-5 w-1/3 rounded" />
      </div>
    </div>
  );
}
