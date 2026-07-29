"use client";

import { motion } from "framer-motion";
import { useEffect } from "react";
import { ArrowLeft, ArrowRight, Clock, MapPin } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import { ProductCard, ProductCardSkeleton } from "@/components/customer/product-card";
import { Badge } from "@/components/ui/primitives";
import { useBootstrap, useCategories, useHighlights, useOffers, useToggleFavorite } from "@/hooks/queries";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";
import type { Product } from "@/types/api";
import type { TranslationKey } from "@/lib/i18n/dictionaries";

export default function HomePage() {
  const { t, isRtl } = useI18n();
  const { data: bootstrap } = useBootstrap();
  const { data: categories, isLoading: categoriesLoading } = useCategories();
  const { data: highlights, isLoading: highlightsLoading } = useHighlights();
  const { data: offers } = useOffers();

  const tableNumber = useCartStore((state) => state.tableNumber);
  const setBranch = useCartStore((state) => state.setBranch);
  const branchId = useCartStore((state) => state.branchId);
  const toggleFavorite = useToggleFavorite();
  const isAuthenticated = useAuthStore((state) => state.status === "authenticated");

  // Default to the first active branch so a walk-in who has not scanned a table
  // can still browse and order takeaway.
  const branch = bootstrap?.branches.find((b) => b.id === branchId) ?? bootstrap?.branches[0];

  useEffect(() => {
    if (branch && branchId === null) setBranch(branch.id);
  }, [branch, branchId, setBranch]);

  const onToggleFavorite = (product: Product) => {
    if (!isAuthenticated) return;
    toggleFavorite.mutate(product.id);
  };

  const Arrow = isRtl ? ArrowLeft : ArrowRight;

  return (
    <div className="mx-auto max-w-6xl px-4 py-6 space-y-10">
      {/* Hero ---------------------------------------------------------------- */}
      <motion.section
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[var(--color-bone-950)] via-[var(--color-ember-900)] to-[var(--color-bone-900)] p-7 text-[var(--color-bone-50)] md:p-12"
      >
        {/* Decorative ember glow */}
        <div
          className="pointer-events-none absolute -end-24 -top-24 size-72 rounded-full bg-[var(--color-ember-500)]/25 blur-3xl"
          aria-hidden
        />

        <div className="relative max-w-lg">
          {tableNumber ? (
            <Badge tone="gold" className="mb-4">
              {t("table.seatedAt", { number: tableNumber })}
            </Badge>
          ) : branch ? (
            <Badge tone={branch.is_open_now ? "success" : "neutral"} className="mb-4">
              {branch.name} · {branch.is_open_now ? t("admin.active") : t("state.notFound")}
            </Badge>
          ) : null}

          <h1 className="font-display text-4xl leading-tight md:text-6xl">{t("app.name")}</h1>
          <p className="mt-3 text-base text-[var(--color-bone-300)] md:text-lg">
            {t("app.tagline")}
          </p>

          <div className="mt-7 flex flex-wrap gap-3">
            <Link
              href="/menu"
              className="inline-flex h-12 items-center gap-2 rounded-xl bg-accent px-6 text-base font-semibold text-accent-contrast transition-transform hover:scale-[1.02] active:scale-[0.98]"
            >
              {t("menu.title")}
              <Arrow className="size-4" aria-hidden />
            </Link>

            {!tableNumber && (
              <Link
                href="/orders"
                className="inline-flex h-12 items-center rounded-xl border border-white/25 px-6 text-base font-medium transition-colors hover:bg-white/10"
              >
                {t("orders.title")}
              </Link>
            )}
          </div>

          {branch && (
            <dl className="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-[var(--color-bone-400)]">
              {branch.address && (
                <div className="flex items-center gap-1.5">
                  <MapPin className="size-4" aria-hidden />
                  <dd>{branch.address}</dd>
                </div>
              )}
              <div className="flex items-center gap-1.5">
                <Clock className="size-4" aria-hidden />
                <dd className="tabular">
                  {branch.opens_at.slice(0, 5)} – {branch.closes_at.slice(0, 5)}
                </dd>
              </div>
            </dl>
          )}
        </div>
      </motion.section>

      {/* Categories ---------------------------------------------------------- */}
      <section aria-labelledby="categories-heading">
        <SectionHeader id="categories-heading" titleKey="menu.title" href="/menu" />

        <div className="rail -mx-4 flex gap-3 px-4 pb-2">
          {categoriesLoading
            ? Array.from({ length: 6 }, (_, i) => (
                <div key={i} className="skeleton h-24 w-28 shrink-0 rounded-2xl" />
              ))
            : categories?.map((category) => (
                <Link
                  key={category.id}
                  href={`/menu?category=${category.slug}`}
                  className="group surface-card flex w-28 shrink-0 flex-col items-center justify-center gap-2 p-4 transition-shadow hover:shadow-[var(--shadow-lifted)]"
                >
                  <span
                    className="grid size-11 place-items-center rounded-xl text-lg font-bold"
                    style={{
                      backgroundColor: `${category.accent_color ?? "#C8642A"}1F`,
                      color: category.accent_color ?? undefined,
                    }}
                    aria-hidden
                  >
                    {category.name.charAt(0)}
                  </span>
                  <span className="clamp-1 text-center text-xs font-medium text-content">
                    {category.name}
                  </span>
                </Link>
              ))}
        </div>
      </section>

      {/* Offers -------------------------------------------------------------- */}
      {offers && offers.length > 0 && (
        <section aria-labelledby="offers-heading">
          <SectionHeader id="offers-heading" titleKey="menu.offers" />

          <div className="rail -mx-4 flex gap-4 px-4 pb-2">
            {offers.map((offer) => (
              <article
                key={offer.id}
                className="relative w-[19rem] shrink-0 overflow-hidden rounded-2xl bg-gradient-to-br from-[var(--color-ember-700)] to-[var(--color-bone-900)] p-5 text-[var(--color-bone-50)]"
              >
                {offer.image && (
                  <Image
                    src={offer.image.medium}
                    alt=""
                    fill
                    sizes="304px"
                    className="object-cover opacity-30"
                  />
                )}

                <div className="relative">
                  {offer.badge && (
                    <span className="inline-block rounded-full bg-[var(--color-gold-500)] px-2.5 py-1 text-xs font-bold text-black">
                      {offer.badge}
                    </span>
                  )}
                  <h3 className="mt-3 font-display text-xl">{offer.title}</h3>
                  {offer.description && (
                    <p className="clamp-2 mt-1 text-sm text-[var(--color-bone-300)]">
                      {offer.description}
                    </p>
                  )}
                  {offer.cta_url && (
                    <Link
                      href={offer.cta_url}
                      className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold underline-offset-4 hover:underline"
                    >
                      {t("action.viewAll")}
                      <Arrow className="size-3.5" aria-hidden />
                    </Link>
                  )}
                </div>
              </article>
            ))}
          </div>
        </section>
      )}

      <ProductRail
        titleKey="menu.featured"
        products={highlights?.featured}
        loading={highlightsLoading}
        onToggleFavorite={isAuthenticated ? onToggleFavorite : undefined}
        priority
      />

      <ProductRail
        titleKey="menu.popular"
        products={highlights?.popular}
        loading={highlightsLoading}
        onToggleFavorite={isAuthenticated ? onToggleFavorite : undefined}
      />

      {highlights?.new && highlights.new.length > 0 && (
        <ProductRail
          titleKey="menu.new"
          products={highlights.new}
          loading={false}
          onToggleFavorite={isAuthenticated ? onToggleFavorite : undefined}
        />
      )}
    </div>
  );
}

function SectionHeader({
  id,
  titleKey,
  href,
}: {
  id: string;
  titleKey: TranslationKey;
  href?: string;
}) {
  const { t, isRtl } = useI18n();
  const Arrow = isRtl ? ArrowLeft : ArrowRight;

  return (
    <div className="mb-4 flex items-baseline justify-between gap-4">
      <h2 id={id} className="text-xl font-semibold text-content md:text-2xl">
        {t(titleKey)}
      </h2>

      {href && (
        <Link
          href={href}
          className="flex shrink-0 items-center gap-1 text-sm font-medium text-accent hover:underline"
        >
          {t("action.viewAll")}
          <Arrow className="size-3.5" aria-hidden />
        </Link>
      )}
    </div>
  );
}

function ProductRail({
  titleKey,
  products,
  loading,
  onToggleFavorite,
  priority,
}: {
  titleKey: TranslationKey;
  products?: Product[];
  loading: boolean;
  onToggleFavorite?: (product: Product) => void;
  priority?: boolean;
}) {
  if (!loading && (!products || products.length === 0)) return null;

  return (
    <section aria-labelledby={`rail-${titleKey}`}>
      <SectionHeader id={`rail-${titleKey}`} titleKey={titleKey} href="/menu" />

      <div className={cn("rail -mx-4 flex gap-4 px-4 pb-2")}>
        {loading
          ? Array.from({ length: 4 }, (_, i) => <ProductCardSkeleton key={i} layout="rail" />)
          : products?.map((product, index) => (
              <ProductCard
                key={product.id}
                product={product}
                layout="rail"
                priority={priority && index < 2}
                onToggleFavorite={onToggleFavorite}
              />
            ))}
      </div>
    </section>
  );
}
