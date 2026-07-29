"use client";

import { AnimatePresence, motion } from "framer-motion";
import { Minus, Plus, ShoppingBag, Tag, Trash2, X } from "lucide-react";
import Image from "next/image";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Badge, EmptyState, Input } from "@/components/ui/primitives";
import { useCartPricing } from "@/hooks/queries";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";

/**
 * The cart.
 *
 * Line prices shown here come from the server pricing call, not from the
 * locally stored hints — so a promotion that started since the item was added
 * is already reflected before checkout.
 */
export default function CartPage() {
  const { t, locale } = useI18n();
  const router = useRouter();

  const lines = useCartStore((state) => state.lines);
  const setQuantity = useCartStore((state) => state.setQuantity);
  const removeLine = useCartStore((state) => state.removeLine);
  const clear = useCartStore((state) => state.clear);
  const couponCode = useCartStore((state) => state.couponCode);
  const setCoupon = useCartStore((state) => state.setCoupon);
  const tableNumber = useCartStore((state) => state.tableNumber);

  const [couponInput, setCouponInput] = useState(couponCode ?? "");
  const { data, isFetching } = useCartPricing();

  const cart = data?.cart;
  const couponError = data?.couponError;
  const currency = cart?.currency ?? "IQD";

  if (lines.length === 0) {
    return (
      <div className="mx-auto max-w-2xl px-4">
        <EmptyState
          icon={<ShoppingBag className="size-7" aria-hidden />}
          title={t("cart.empty")}
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
    <div className="mx-auto max-w-3xl px-4 py-6">
      <header className="mb-5 flex items-center justify-between gap-4">
        <h1 className="text-2xl font-semibold text-content">{t("cart.title")}</h1>

        <button
          type="button"
          onClick={() => {
            if (window.confirm(t("cart.clearConfirm"))) clear();
          }}
          className="flex items-center gap-1.5 text-sm text-content-muted transition-colors hover:text-[var(--color-danger)]"
        >
          <Trash2 className="size-4" aria-hidden />
          {t("action.clear")}
        </button>
      </header>

      {tableNumber && (
        <Badge tone="accent" className="mb-4">
          {t("table.seatedAt", { number: tableNumber })}
        </Badge>
      )}

      {/* Lines ------------------------------------------------------------- */}
      <ul className="space-y-3">
        <AnimatePresence initial={false}>
          {lines.map((line) => {
            // Match by product so the server's per-line total (which includes
            // any automatic offer discount) is what gets displayed.
            const priced = cart?.lines.find((l) => l.product_id === line.productId);

            return (
              <motion.li
                key={line.key}
                layout
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: "auto" }}
                exit={{ opacity: 0, height: 0, marginBottom: 0 }}
                transition={{ duration: 0.2 }}
              >
                <article className="surface-card flex gap-3 p-3">
                  <Link
                    href={`/menu/${line.slug}`}
                    className="relative size-20 shrink-0 overflow-hidden rounded-xl bg-surface-sunken"
                  >
                    {line.imageUrl ? (
                      <Image
                        src={line.imageUrl}
                        alt=""
                        fill
                        sizes="80px"
                        className="object-cover"
                      />
                    ) : (
                      <span className="grid size-full place-items-center font-display text-2xl text-content-muted">
                        {line.name.charAt(0)}
                      </span>
                    )}
                  </Link>

                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                      <Link href={`/menu/${line.slug}`} className="min-w-0">
                        <h2 className="clamp-1 font-semibold text-content">{line.name}</h2>
                      </Link>

                      <button
                        type="button"
                        onClick={() => removeLine(line.key)}
                        aria-label={t("action.remove")}
                        className="-me-1 -mt-1 shrink-0 rounded-lg p-1.5 text-content-muted transition-colors hover:bg-surface-sunken hover:text-[var(--color-danger)]"
                      >
                        <X className="size-4" aria-hidden />
                      </button>
                    </div>

                    {line.options.length > 0 && (
                      <p className="clamp-2 mt-0.5 text-xs text-content-secondary">
                        {line.options.map((option) => option.name).join(" · ")}
                      </p>
                    )}

                    {line.specialInstructions && (
                      <p className="clamp-1 mt-1 text-xs text-content-muted italic">
                        “{line.specialInstructions}”
                      </p>
                    )}

                    <div className="mt-2.5 flex items-center justify-between gap-2">
                      <div className="flex items-center gap-0.5 rounded-lg border border-border-subtle">
                        <button
                          type="button"
                          onClick={() => setQuantity(line.key, line.quantity - 1)}
                          aria-label={t("action.remove")}
                          className="grid size-8 place-items-center rounded-md text-content transition-colors hover:bg-surface-sunken"
                        >
                          <Minus className="size-3.5" aria-hidden />
                        </button>

                        <span className="tabular w-7 text-center text-sm font-semibold">
                          {line.quantity}
                        </span>

                        <button
                          type="button"
                          onClick={() => setQuantity(line.key, line.quantity + 1)}
                          disabled={line.quantity >= 50}
                          aria-label={t("action.add")}
                          className="grid size-8 place-items-center rounded-md text-content transition-colors hover:bg-surface-sunken disabled:opacity-40"
                        >
                          <Plus className="size-3.5" aria-hidden />
                        </button>
                      </div>

                      <div className="text-end">
                        <p className="tabular font-semibold text-content">
                          {formatMoney(priced?.line_total ?? 0, currency, locale)}
                        </p>
                        {priced && priced.discount_total > 0 && (
                          <p className="tabular text-xs text-content-muted line-through">
                            {formatMoney(priced.line_subtotal, currency, locale)}
                          </p>
                        )}
                      </div>
                    </div>
                  </div>
                </article>
              </motion.li>
            );
          })}
        </AnimatePresence>
      </ul>

      {/* Coupon ------------------------------------------------------------ */}
      <section className="mt-6" aria-label={t("cart.couponPlaceholder")}>
        {couponCode ? (
          <div className="flex items-center justify-between gap-3 rounded-xl border border-accent bg-accent-soft p-3.5">
            <span className="flex min-w-0 items-center gap-2">
              <Tag className="size-4 shrink-0 text-accent" aria-hidden />
              <span className="truncate font-medium text-accent">{couponCode}</span>
              {cart?.coupon && (
                <span className="tabular shrink-0 text-sm text-accent">
                  −{formatMoney(cart.coupon_discount, currency, locale)}
                </span>
              )}
            </span>

            <button
              type="button"
              onClick={() => {
                setCoupon(null);
                setCouponInput("");
              }}
              className="shrink-0 text-sm font-medium text-accent hover:underline"
            >
              {t("cart.couponRemove")}
            </button>
          </div>
        ) : (
          <form
            onSubmit={(event) => {
              event.preventDefault();
              if (couponInput.trim()) setCoupon(couponInput.trim().toUpperCase());
            }}
            className="flex gap-2"
          >
            <Input
              value={couponInput}
              onChange={(event) => setCouponInput(event.target.value)}
              placeholder={t("cart.couponPlaceholder")}
              aria-label={t("cart.couponPlaceholder")}
              autoComplete="off"
              className="uppercase"
            />
            <Button type="submit" variant="secondary" disabled={!couponInput.trim()}>
              {t("action.apply")}
            </Button>
          </form>
        )}

        {couponError && (
          <p role="alert" className="mt-2 text-sm text-[var(--color-danger)]">
            {couponError}
          </p>
        )}
      </section>

      {/* Totals ------------------------------------------------------------ */}
      <section
        className={cn("surface-card mt-6 space-y-2.5 p-5 transition-opacity", isFetching && "opacity-60")}
        aria-label={t("cart.total")}
      >
        <Row label={t("cart.subtotal")} value={formatMoney(cart?.subtotal ?? 0, currency, locale)} />

        {(cart?.discount_total ?? 0) > 0 && (
          <Row
            label={t("cart.discount")}
            value={`−${formatMoney(cart!.discount_total, currency, locale)}`}
            tone="accent"
          />
        )}

        {(cart?.tax_total ?? 0) > 0 && (
          <Row label={t("cart.tax")} value={formatMoney(cart!.tax_total, currency, locale)} />
        )}

        {(cart?.service_charge ?? 0) > 0 && (
          <Row
            label={t("cart.serviceCharge")}
            value={formatMoney(cart!.service_charge, currency, locale)}
          />
        )}

        {(cart?.delivery_fee ?? 0) > 0 && (
          <Row
            label={t("cart.deliveryFee")}
            value={formatMoney(cart!.delivery_fee, currency, locale)}
          />
        )}

        <div className="flex items-baseline justify-between border-t border-border-subtle pt-3">
          <span className="text-base font-semibold text-content">{t("cart.total")}</span>
          <span className="tabular text-xl font-bold text-accent">
            {formatMoney(cart?.grand_total ?? 0, currency, locale)}
          </span>
        </div>

        {cart && cart.estimated_minutes > 0 && (
          <p className="pt-1 text-center text-sm text-content-secondary">
            {t("cart.estimatedTime", { count: cart.estimated_minutes })}
          </p>
        )}
      </section>

      <Button
        size="lg"
        fullWidth
        className="mt-5"
        loading={isFetching && !cart}
        onClick={() => router.push("/checkout")}
      >
        {t("cart.checkout")}
      </Button>
    </div>
  );
}

function Row({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone?: "accent";
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 text-sm">
      <span className="text-content-secondary">{label}</span>
      <span className={cn("tabular font-medium", tone === "accent" ? "text-accent" : "text-content")}>
        {value}
      </span>
    </div>
  );
}
