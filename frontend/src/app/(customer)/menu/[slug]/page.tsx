"use client";

import { AnimatePresence, motion } from "framer-motion";
import { ArrowLeft, ArrowRight, Check, Flame, Heart, Minus, Plus, Star } from "lucide-react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { use, useMemo, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Badge, Skeleton, Textarea } from "@/components/ui/primitives";
import { useProduct, useToggleFavorite } from "@/hooks/queries";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";
import type { OptionGroup, ProductOption } from "@/types/api";

/**
 * Product detail and configurator.
 *
 * The running price updates as options are chosen, but it is only ever a
 * preview — the authoritative total comes from the server when the cart is
 * priced. Required groups block the add button until satisfied, mirroring the
 * validation the API would apply anyway, so the customer finds out here rather
 * than at checkout.
 */
export default function ProductPage({ params }: { params: Promise<{ slug: string }> }) {
  // `params` is a promise in Next 16; `use()` unwraps it in a client component.
  const { slug } = use(params);

  const { t, locale, isRtl } = useI18n();
  const router = useRouter();
  const { data: product, isLoading, isError } = useProduct(slug);

  const addLine = useCartStore((state) => state.addLine);
  const isAuthenticated = useAuthStore((state) => state.status === "authenticated");
  const toggleFavorite = useToggleFavorite();

  const [quantity, setQuantity] = useState(1);
  const [instructions, setInstructions] = useState("");
  const [selection, setSelection] = useState<Record<number, number[]>>({});
  const [activeImage, setActiveImage] = useState(0);

  // Memoised so the identity is stable — a fresh `[]` on every render would
  // re-run every downstream memo that depends on it.
  const groups = useMemo(() => product?.option_groups ?? [], [product]);

  // Preselect every group's default, so a required "Single patty" is already
  // chosen and the customer can add to cart in one tap.
  const defaultsApplied = useMemo(() => {
    if (groups.length === 0) return {};

    const defaults: Record<number, number[]> = {};

    for (const group of groups) {
      const preset = group.options.filter((option) => option.is_default && option.is_available);

      if (preset.length > 0) {
        defaults[group.id] = preset.slice(0, group.max_selections).map((o) => o.id);
      } else if (group.is_required) {
        // A required group with no flagged default still needs one, or the add
        // button would start disabled for no visible reason.
        const first = group.options.find((option) => option.is_available);
        if (first) defaults[group.id] = [first.id];
      }
    }

    return defaults;
  }, [groups]);

  // Merge defaults in once the product arrives, without clobbering user edits.
  const effectiveSelection = useMemo(
    () => ({ ...defaultsApplied, ...selection }),
    [defaultsApplied, selection],
  );

  const toggleOption = (group: OptionGroup, option: ProductOption) => {
    if (!option.is_available) return;

    setSelection((current) => {
      const merged = { ...defaultsApplied, ...current };
      const chosen = merged[group.id] ?? [];

      if (group.selection === "single") {
        // Re-tapping the chosen option in a required group is a no-op rather
        // than leaving it unsatisfied.
        if (chosen.includes(option.id) && group.is_required) return merged;
        return { ...merged, [group.id]: chosen.includes(option.id) ? [] : [option.id] };
      }

      if (chosen.includes(option.id)) {
        return { ...merged, [group.id]: chosen.filter((id) => id !== option.id) };
      }

      if (chosen.length >= group.max_selections) {
        toast.error(t("product.chooseUpTo", { count: group.max_selections }));
        return merged;
      }

      return { ...merged, [group.id]: [...chosen, option.id] };
    });
  };

  const unmetGroups = groups.filter(
    (group) => (effectiveSelection[group.id]?.length ?? 0) < group.min_selections,
  );

  const optionsTotal = groups.reduce((total, group) => {
    const chosen = effectiveSelection[group.id] ?? [];
    return (
      total +
      group.options
        .filter((option) => chosen.includes(option.id))
        .reduce((sum, option) => sum + option.price_delta, 0)
    );
  }, 0);

  const unitPrice = (product?.base_price ?? 0) + optionsTotal;

  const handleAdd = () => {
    if (!product || unmetGroups.length > 0) return;

    const flattened = groups.flatMap((group) =>
      group.options
        .filter((option) => (effectiveSelection[group.id] ?? []).includes(option.id))
        .map((option) => ({ group, option })),
    );

    addLine({ product, quantity, options: flattened, specialInstructions: instructions });

    toast.success(t("product.addedToCart"), {
      action: { label: t("nav.cart"), onClick: () => router.push("/cart") },
    });

    setQuantity(1);
    setInstructions("");
  };

  const Back = isRtl ? ArrowRight : ArrowLeft;

  if (isLoading) return <ProductSkeleton />;

  if (isError || !product) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-20 text-center">
        <h1 className="text-xl font-semibold text-content">{t("state.notFound")}</h1>
        <Button className="mt-6" onClick={() => router.push("/menu")}>
          {t("menu.title")}
        </Button>
      </div>
    );
  }

  const gallery = [product.image, ...(product.gallery ?? [])].filter(Boolean);

  return (
    <div className="mx-auto max-w-5xl pb-32">
      {/* Gallery ----------------------------------------------------------- */}
      <div className="relative aspect-4/3 w-full overflow-hidden bg-surface-sunken md:aspect-21/9 md:rounded-b-3xl">
        {gallery.length > 0 ? (
          <>
            <AnimatePresence mode="wait">
              <motion.div
                key={activeImage}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.25 }}
                className="absolute inset-0"
              >
                <Image
                  src={gallery[activeImage]!.large}
                  alt={gallery[activeImage]!.alt ?? product.name}
                  fill
                  priority
                  sizes="(max-width: 1024px) 100vw, 1024px"
                  className="object-cover"
                />
              </motion.div>
            </AnimatePresence>

            {gallery.length > 1 && (
              <div className="absolute inset-x-0 bottom-3 flex justify-center gap-2">
                {gallery.map((_, index) => (
                  <button
                    key={index}
                    type="button"
                    onClick={() => setActiveImage(index)}
                    aria-label={`${index + 1}`}
                    aria-current={index === activeImage}
                    className={cn(
                      "h-1.5 rounded-full transition-all duration-300",
                      index === activeImage ? "w-6 bg-white" : "w-1.5 bg-white/50",
                    )}
                  />
                ))}
              </div>
            )}
          </>
        ) : (
          <div
            className="flex size-full items-center justify-center bg-gradient-to-br from-[var(--color-ember-800)] to-[var(--color-bone-900)]"
            aria-hidden
          >
            <span className="font-display text-7xl text-[var(--color-ember-300)]/60">
              {product.name.charAt(0)}
            </span>
          </div>
        )}

        <button
          type="button"
          onClick={() => router.back()}
          aria-label={t("action.back")}
          className="absolute start-4 top-4 grid size-10 place-items-center rounded-full bg-surface-raised/85 text-content backdrop-blur-sm transition-transform hover:scale-105"
        >
          <Back className="size-5" aria-hidden />
        </button>

        {isAuthenticated && (
          <button
            type="button"
            onClick={() => toggleFavorite.mutate(product.id)}
            aria-pressed={Boolean(product.is_favorite)}
            aria-label={product.is_favorite ? t("product.unfavorited") : t("product.favorited")}
            className={cn(
              "absolute end-4 top-4 grid size-10 place-items-center rounded-full bg-surface-raised/85 backdrop-blur-sm transition-transform hover:scale-105",
              product.is_favorite ? "text-[var(--color-danger)]" : "text-content-secondary",
            )}
          >
            <Heart className={cn("size-5", product.is_favorite && "fill-current")} aria-hidden />
          </button>
        )}
      </div>

      <div className="px-4 pt-6">
        {/* Header ---------------------------------------------------------- */}
        <header>
          <div className="flex flex-wrap items-center gap-2">
            {product.category && <Badge tone="neutral">{product.category.name}</Badge>}
            {product.is_new && <Badge tone="accent">{t("menu.new")}</Badge>}
            {product.spice_level > 0 && (
              <Badge tone="danger" icon={<Flame className="size-3" aria-hidden />}>
                {product.spice_level}
              </Badge>
            )}
            {product.tags.map((tag) => (
              <Badge key={tag} tone="gold">
                {tag}
              </Badge>
            ))}
          </div>

          <h1 className="mt-3 text-2xl font-semibold text-content md:text-4xl">{product.name}</h1>

          {product.short_description && (
            <p className="mt-2 text-base text-content-secondary">{product.short_description}</p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-content-secondary">
            <span className="tabular text-xl font-bold text-accent">
              {formatMoney(product.base_price, product.currency, locale)}
            </span>

            {product.rating_count > 0 && (
              <span className="flex items-center gap-1">
                <Star
                  className="size-4 fill-[var(--color-gold-500)] text-[var(--color-gold-500)]"
                  aria-hidden
                />
                <span className="tabular">{product.rating_average.toFixed(1)}</span>
                <span className="text-content-muted">({product.rating_count})</span>
              </span>
            )}

            <span className="tabular">{t("menu.prepTime", { count: product.prep_time_minutes })}</span>

            {product.calories !== null && (
              <span className="tabular">{t("menu.calories", { count: product.calories })}</span>
            )}
          </div>
        </header>

        {product.description && (
          <p className="mt-5 leading-relaxed text-content-secondary">{product.description}</p>
        )}

        {product.allergens.length > 0 && (
          <section className="mt-5">
            <h2 className="text-sm font-semibold text-content">{t("product.allergens")}</h2>
            <div className="mt-2 flex flex-wrap gap-2">
              {product.allergens.map((allergen) => (
                <Badge key={allergen} tone="warning">
                  {allergen}
                </Badge>
              ))}
            </div>
          </section>
        )}

        {/* Option groups --------------------------------------------------- */}
        {groups.length > 0 && (
          <section className="mt-8 space-y-6" aria-label={t("product.chooseOptions")}>
            {groups.map((group) => {
              const chosen = effectiveSelection[group.id] ?? [];
              const unmet = chosen.length < group.min_selections;

              return (
                <fieldset key={group.id}>
                  <legend className="mb-3 flex w-full items-center justify-between gap-3">
                    <span className="text-base font-semibold text-content">{group.name}</span>

                    <Badge tone={unmet ? "danger" : group.is_required ? "accent" : "neutral"}>
                      {group.is_required
                        ? group.selection === "single"
                          ? t("product.chooseOne")
                          : t("product.chooseAtLeast", { count: group.min_selections })
                        : group.selection === "multiple"
                          ? t("product.chooseUpTo", { count: group.max_selections })
                          : t("product.optional")}
                    </Badge>
                  </legend>

                  {group.description && (
                    <p className="mb-3 -mt-1 text-sm text-content-muted">{group.description}</p>
                  )}

                  <div className="grid gap-2 sm:grid-cols-2">
                    {group.options.map((option) => {
                      const selected = chosen.includes(option.id);

                      return (
                        <button
                          key={option.id}
                          type="button"
                          role={group.selection === "single" ? "radio" : "checkbox"}
                          aria-checked={selected}
                          disabled={!option.is_available}
                          onClick={() => toggleOption(group, option)}
                          className={cn(
                            "flex items-center justify-between gap-3 rounded-xl border p-3.5 text-start transition-all duration-150",
                            selected
                              ? "border-accent bg-accent-soft"
                              : "border-border-subtle bg-surface-raised hover:border-border-strong",
                            !option.is_available && "cursor-not-allowed opacity-45",
                          )}
                        >
                          <span className="flex min-w-0 items-center gap-2.5">
                            <span
                              className={cn(
                                "grid size-5 shrink-0 place-items-center border-2 transition-colors",
                                group.selection === "single" ? "rounded-full" : "rounded-md",
                                selected
                                  ? "border-accent bg-accent text-accent-contrast"
                                  : "border-border-strong",
                              )}
                              aria-hidden
                            >
                              {selected && <Check className="size-3" strokeWidth={3} />}
                            </span>

                            <span className="min-w-0">
                              <span className="clamp-1 block text-sm font-medium text-content">
                                {option.name}
                              </span>
                              {!option.is_available && (
                                <span className="text-xs text-content-muted">
                                  {t("menu.soldOut")}
                                </span>
                              )}
                            </span>
                          </span>

                          {option.price_delta !== 0 && (
                            <span className="tabular shrink-0 text-sm font-medium text-content-secondary">
                              {option.price_delta > 0 ? "+" : ""}
                              {formatMoney(option.price_delta, product.currency, locale)}
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                </fieldset>
              );
            })}
          </section>
        )}

        {/* Instructions ---------------------------------------------------- */}
        <section className="mt-8">
          <label htmlFor="instructions" className="mb-2 block text-base font-semibold text-content">
            {t("product.specialInstructions")}
          </label>
          <Textarea
            id="instructions"
            value={instructions}
            onChange={(event) => setInstructions(event.target.value)}
            placeholder={t("product.instructionsPlaceholder")}
            maxLength={500}
            rows={3}
          />
        </section>
      </div>

      {/* Sticky add bar ---------------------------------------------------- */}
      <div className="fixed inset-x-0 bottom-16 z-20 border-t border-border-subtle bg-surface-raised/95 px-4 py-3 pb-safe backdrop-blur-lg md:bottom-0">
        <div className="mx-auto flex max-w-5xl items-center gap-3">
          <div className="flex items-center gap-1 rounded-xl border border-border-subtle p-1">
            <button
              type="button"
              onClick={() => setQuantity((q) => Math.max(1, q - 1))}
              disabled={quantity <= 1}
              aria-label={t("action.remove")}
              className="grid size-9 place-items-center rounded-lg text-content transition-colors hover:bg-surface-sunken disabled:opacity-40"
            >
              <Minus className="size-4" aria-hidden />
            </button>

            <span className="tabular w-8 text-center text-base font-semibold" aria-live="polite">
              {quantity}
            </span>

            <button
              type="button"
              onClick={() => setQuantity((q) => Math.min(50, q + 1))}
              disabled={quantity >= 50}
              aria-label={t("action.add")}
              className="grid size-9 place-items-center rounded-lg text-content transition-colors hover:bg-surface-sunken disabled:opacity-40"
            >
              <Plus className="size-4" aria-hidden />
            </button>
          </div>

          <Button
            size="lg"
            fullWidth
            disabled={unmetGroups.length > 0 || !product.is_available}
            onClick={handleAdd}
            className="justify-between"
          >
            <span>
              {product.is_available
                ? unmetGroups.length > 0
                  ? unmetGroups[0].name
                  : t("action.addToCart")
                : t("menu.soldOut")}
            </span>
            <span className="tabular font-bold">
              {formatMoney(unitPrice * quantity, product.currency, locale)}
            </span>
          </Button>
        </div>
      </div>
    </div>
  );
}

function ProductSkeleton() {
  return (
    <div className="mx-auto max-w-5xl pb-32">
      <Skeleton className="aspect-4/3 w-full rounded-none md:aspect-21/9 md:rounded-b-3xl" />
      <div className="space-y-4 px-4 pt-6">
        <Skeleton className="h-6 w-24" />
        <Skeleton className="h-9 w-3/4" />
        <Skeleton className="h-5 w-full" />
        <Skeleton className="h-5 w-2/3" />
        <div className="space-y-2 pt-6">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      </div>
    </div>
  );
}
