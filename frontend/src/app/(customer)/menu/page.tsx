"use client";

import { Search, SlidersHorizontal, UtensilsCrossed, X } from "lucide-react";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { ProductCard, ProductCardSkeleton } from "@/components/customer/product-card";
import { Button } from "@/components/ui/button";
import { EmptyState, Input, Select } from "@/components/ui/primitives";
import { useCategories, useProducts, useToggleFavorite } from "@/hooks/queries";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { cn, debounce } from "@/lib/utils";
import type { Product } from "@/types/api";

/**
 * The menu.
 *
 * Filter state lives in the URL rather than component state so a customer can
 * share or bookmark "the burgers under 15,000", and so the back button walks
 * their filter history the way they expect.
 */
/**
 * `useSearchParams` opts the subtree into client-side rendering, so it has to
 * sit inside a Suspense boundary or the whole route fails to prerender.
 */
export default function MenuPage() {
  return (
    <Suspense fallback={<MenuFallback />}>
      <MenuContent />
    </Suspense>
  );
}

function MenuFallback() {
  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <div className="skeleton mb-5 h-9 w-40 rounded" />
      <div className="skeleton mb-4 h-12 w-full rounded-xl" />
      <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        {Array.from({ length: 8 }, (_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </div>
  );
}

function MenuContent() {
  const { t } = useI18n();
  const router = useRouter();
  const searchParams = useSearchParams();

  const category = searchParams.get("category") ?? "";
  const sort = searchParams.get("sort") ?? "menu";
  const urlSearch = searchParams.get("search") ?? "";

  const [searchInput, setSearchInput] = useState(urlSearch);
  const searchRef = useRef<HTMLInputElement>(null);

  const { data: categories } = useCategories();
  const isAuthenticated = useAuthStore((state) => state.status === "authenticated");
  const toggleFavorite = useToggleFavorite();

  const { data, isLoading, isFetching } = useProducts({
    category: category || undefined,
    search: urlSearch || undefined,
    sort,
    per_page: 24,
  });

  // Autofocus when arriving from the header's search affordance.
  useEffect(() => {
    if (searchParams.get("focus") === "search") {
      searchRef.current?.focus();
    }
  }, [searchParams]);

  const setParam = useCallback(
    (key: string, value: string) => {
      const params = new URLSearchParams(searchParams.toString());

      if (value) {
        params.set(key, value);
      } else {
        params.delete(key);
      }
      params.delete("focus");

      router.replace(`/menu${params.toString() ? `?${params}` : ""}`, { scroll: false });
    },
    [router, searchParams],
  );

  // One request per pause in typing rather than one per keystroke.
  const pushSearch = useMemo(
    () => debounce((value: string) => setParam("search", value), 350),
    [setParam],
  );

  useEffect(() => () => pushSearch.cancel(), [pushSearch]);

  const onSearchChange = (value: string) => {
    setSearchInput(value);
    pushSearch(value);
  };

  const products = data?.data ?? [];

  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <h1 className="mb-5 text-2xl font-semibold text-content md:text-3xl">{t("menu.title")}</h1>

      {/* Search ------------------------------------------------------------ */}
      <div className="relative mb-4">
        <Search
          className="pointer-events-none absolute start-3.5 top-1/2 size-4.5 -translate-y-1/2 text-content-muted"
          aria-hidden
        />
        <Input
          ref={searchRef}
          type="search"
          value={searchInput}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder={t("menu.searchPlaceholder")}
          aria-label={t("action.search")}
          className="h-12 ps-11 pe-11"
        />
        {searchInput && (
          <button
            type="button"
            onClick={() => onSearchChange("")}
            aria-label={t("action.clear")}
            className="absolute end-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-content-muted hover:bg-surface-sunken hover:text-content"
          >
            <X className="size-4" aria-hidden />
          </button>
        )}
      </div>

      {/* Category chips ---------------------------------------------------- */}
      <div className="rail -mx-4 mb-4 flex gap-2 px-4 pb-1" role="tablist" aria-label={t("menu.title")}>
        <CategoryChip
          label={t("menu.allCategories")}
          active={!category}
          onClick={() => setParam("category", "")}
        />
        {categories?.map((item) => (
          <CategoryChip
            key={item.id}
            label={item.name}
            count={item.products_count}
            active={category === item.slug}
            onClick={() => setParam("category", item.slug)}
          />
        ))}
      </div>

      {/* Sort -------------------------------------------------------------- */}
      <div className="mb-6 flex items-center justify-between gap-3">
        <p className="text-sm text-content-secondary" aria-live="polite">
          {data ? t("menu.itemCount", { count: data.meta.total }) : " "}
        </p>

        <label className="flex items-center gap-2 text-sm">
          <SlidersHorizontal className="size-4 text-content-muted" aria-hidden />
          <span className="sr-only">{t("action.apply")}</span>
          <Select
            value={sort}
            onChange={(event) => setParam("sort", event.target.value)}
            className="h-10 w-auto py-0 text-sm"
          >
            <option value="menu">{t("menu.title")}</option>
            <option value="popular">{t("menu.popular")}</option>
            <option value="rating">{t("product.reviews")}</option>
            <option value="price_asc">{t("admin.price")} ↑</option>
            <option value="price_desc">{t("admin.price")} ↓</option>
            <option value="newest">{t("menu.new")}</option>
          </Select>
        </label>
      </div>

      {/* Grid -------------------------------------------------------------- */}
      {isLoading ? (
        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <ProductCardSkeleton key={i} />
          ))}
        </div>
      ) : products.length === 0 ? (
        <EmptyState
          icon={<UtensilsCrossed className="size-7" aria-hidden />}
          title={t("menu.noResults")}
          description={t("menu.noResultsHelp")}
          action={
            (category || urlSearch) && (
              <Button
                variant="secondary"
                onClick={() => {
                  setSearchInput("");
                  router.replace("/menu", { scroll: false });
                }}
              >
                {t("action.clear")}
              </Button>
            )
          }
        />
      ) : (
        <div
          className={cn(
            "grid grid-cols-2 gap-4 transition-opacity md:grid-cols-3 lg:grid-cols-4",
            // Dim rather than unmount while refetching, so the grid does not
            // jump on every filter change.
            isFetching && "opacity-60",
          )}
        >
          {products.map((product: Product, index) => (
            <ProductCard
              key={product.id}
              product={product}
              priority={index < 4}
              onToggleFavorite={
                isAuthenticated ? (p) => toggleFavorite.mutate(p.id) : undefined
              }
            />
          ))}
        </div>
      )}

      {/* Pagination -------------------------------------------------------- */}
      {data && data.meta.last_page > 1 && (
        <nav className="mt-8 flex items-center justify-center gap-2" aria-label="Pagination">
          {Array.from({ length: data.meta.last_page }, (_, i) => i + 1).map((page) => (
            <button
              key={page}
              type="button"
              onClick={() => setParam("page", String(page))}
              aria-current={page === data.meta.current_page ? "page" : undefined}
              className={cn(
                "tabular size-10 rounded-xl text-sm font-medium transition-colors",
                page === data.meta.current_page
                  ? "bg-accent text-accent-contrast"
                  : "bg-surface-sunken text-content-secondary hover:bg-border-subtle",
              )}
            >
              {page}
            </button>
          ))}
        </nav>
      )}
    </div>
  );
}

function CategoryChip({
  label,
  count,
  active,
  onClick,
}: {
  label: string;
  count?: number;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={cn(
        "shrink-0 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap transition-all duration-200",
        active
          ? "bg-accent text-accent-contrast shadow-[var(--shadow-ember)]"
          : "bg-surface-sunken text-content-secondary hover:bg-border-subtle hover:text-content",
      )}
    >
      {label}
      {count !== undefined && count > 0 && (
        <span className="tabular ms-1.5 opacity-70">{count}</span>
      )}
    </button>
  );
}
