"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Bike, ShoppingBag, Store, UtensilsCrossed } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Field, Input, Textarea } from "@/components/ui/primitives";
import { useBootstrap, useCartPricing, usePlaceOrder } from "@/hooks/queries";
import { ApiRequestError, credentials } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";
import { useCartStore } from "@/stores/cart";
import { cn } from "@/lib/utils";
import type { OrderType } from "@/types/api";

/**
 * Checkout.
 *
 * Validation mirrors the API's rules so the customer is corrected before a
 * round trip, but the server remains the authority — a 422 is surfaced back
 * onto the matching field rather than as an opaque toast.
 */

const schema = z.object({
  customer_name: z.string().trim().max(120).optional(),
  customer_phone: z
    .string()
    .trim()
    .regex(/^[0-9+\-\s()]{6,32}$/)
    .optional()
    .or(z.literal("")),
  notes: z.string().trim().max(1000).optional(),
  guest_count: z.coerce.number().int().min(1).max(50).optional(),
  delivery_address: z.string().trim().max(500).optional(),
});

// `z.coerce.number()` makes the input and output types differ, so react-hook-form
// needs both: the raw field values it holds, and the parsed values it hands to
// the submit handler.
type FormInput = z.input<typeof schema>;
type FormValues = z.output<typeof schema>;

const ORDER_TYPES: Array<{ value: OrderType; icon: typeof Store; labelKey: "checkout.dineIn" | "checkout.takeaway" | "checkout.delivery" }> = [
  { value: "dine_in", icon: UtensilsCrossed, labelKey: "checkout.dineIn" },
  { value: "takeaway", icon: ShoppingBag, labelKey: "checkout.takeaway" },
  { value: "delivery", icon: Bike, labelKey: "checkout.delivery" },
];

export default function CheckoutPage() {
  const { t, locale } = useI18n();
  const router = useRouter();

  const lines = useCartStore((state) => state.lines);
  const branchId = useCartStore((state) => state.branchId);
  const couponCode = useCartStore((state) => state.couponCode);
  const orderType = useCartStore((state) => state.orderType);
  const setOrderType = useCartStore((state) => state.setOrderType);
  const tableToken = useCartStore((state) => state.tableToken);
  const tableSessionToken = useCartStore((state) => state.tableSessionToken);
  const tableNumber = useCartStore((state) => state.tableNumber);
  const clearTableContext = useCartStore((state) => state.clearTableContext);

  const user = useAuthStore((state) => state.user);
  const { data: bootstrap } = useBootstrap();
  const { data: pricing } = useCartPricing();
  const placeOrder = usePlaceOrder();

  const branch = bootstrap?.branches.find((b) => b.id === branchId);
  const cart = pricing?.cart;
  const currency = cart?.currency ?? "IQD";

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormInput, unknown, FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      customer_name: user?.name ?? "",
      customer_phone: user?.phone ?? "",
    },
  });

  // An empty cart on this route means the order already went through, or the
  // page was opened directly.
  useEffect(() => {
    if (lines.length === 0 && !placeOrder.isPending && !placeOrder.isSuccess) {
      router.replace("/menu");
    }
  }, [lines.length, placeOrder.isPending, placeOrder.isSuccess, router]);

  const onSubmit = handleSubmit(async (values) => {
    if (!branchId) {
      toast.error(t("state.error"));
      return;
    }

    try {
      const result = await placeOrder.mutateAsync({
        branch_id: branchId,
        type: orderType,
        coupon_code: couponCode,
        customer_name: values.customer_name || null,
        customer_phone: values.customer_phone || null,
        notes: values.notes || null,
        guest_count: values.guest_count ?? null,
        delivery_address: values.delivery_address || null,
        table_token: orderType === "dine_in" ? tableToken : null,
        table_session_token: orderType === "dine_in" ? tableSessionToken : null,
        items: lines.map((line) => ({
          product_id: line.productId,
          quantity: line.quantity,
          special_instructions: line.specialInstructions,
          options: line.options.map((option) => ({
            option_id: option.optionId,
            quantity: option.quantity,
          })),
        })),
      });

      // The API mints a guest token on first order; persist it so this device
      // can track the order and see it in history later.
      if (result.guest_token) {
        credentials.setGuestToken(result.guest_token);
      }

      // The visit is over once the order is in; a fresh scan starts a new one.
      if (orderType !== "dine_in") clearTableContext();

      toast.success(t("checkout.success"));
      router.replace(`/orders/${result.data.order_number}`);
    } catch (error) {
      if (error instanceof ApiRequestError) {
        if (error.isValidation && error.fieldErrors) {
          Object.entries(error.fieldErrors).forEach(([field, messages]) => {
            setError(field as keyof FormInput, { message: messages[0] });
          });
        }

        toast.error(error.message);
        return;
      }

      toast.error(t("state.error"));
    }
  });

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <h1 className="mb-6 text-2xl font-semibold text-content">{t("checkout.title")}</h1>

      <form onSubmit={onSubmit} className="space-y-7">
        {/* Order type ------------------------------------------------------ */}
        <fieldset>
          <legend className="mb-3 text-base font-semibold text-content">
            {t("checkout.orderType")}
          </legend>

          <div className="grid grid-cols-3 gap-2">
            {ORDER_TYPES.map(({ value, icon: Icon, labelKey }) => {
              const supported =
                value === "dine_in"
                  ? (branch?.accepts_dine_in ?? true)
                  : value === "takeaway"
                    ? (branch?.accepts_takeaway ?? true)
                    : (branch?.accepts_delivery ?? false);

              return (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={orderType === value}
                  disabled={!supported}
                  onClick={() => setOrderType(value)}
                  className={cn(
                    "flex flex-col items-center gap-2 rounded-xl border p-4 transition-all duration-150",
                    orderType === value
                      ? "border-accent bg-accent-soft text-accent"
                      : "border-border-subtle bg-surface-raised text-content-secondary hover:border-border-strong",
                    !supported && "cursor-not-allowed opacity-40",
                  )}
                >
                  <Icon className="size-5" aria-hidden />
                  <span className="text-xs font-medium">{t(labelKey)}</span>
                </button>
              );
            })}
          </div>

          {orderType === "dine_in" && !tableSessionToken && !tableToken && (
            <p role="alert" className="mt-3 rounded-xl bg-[var(--color-warning-soft)] p-3 text-sm text-[var(--color-warning)]">
              {t("table.invalidCode")} — {t("table.startOrdering")}
            </p>
          )}

          {orderType === "dine_in" && tableNumber && (
            <p className="mt-3 text-sm text-content-secondary">
              {t("table.seatedAt", { number: tableNumber })}
            </p>
          )}
        </fieldset>

        {/* Customer details ------------------------------------------------ */}
        <fieldset className="space-y-4">
          <legend className="mb-1 text-base font-semibold text-content">
            {t("checkout.yourDetails")}
          </legend>

          <Field label={t("checkout.name")} htmlFor="customer_name" error={errors.customer_name?.message}>
            <Input
              id="customer_name"
              autoComplete="name"
              invalid={Boolean(errors.customer_name)}
              {...register("customer_name")}
            />
          </Field>

          <Field
            label={t("checkout.phone")}
            htmlFor="customer_phone"
            error={errors.customer_phone?.message}
            required={orderType !== "dine_in"}
          >
            <Input
              id="customer_phone"
              type="tel"
              inputMode="tel"
              dir="ltr"
              autoComplete="tel"
              placeholder="+964 770 000 0000"
              invalid={Boolean(errors.customer_phone)}
              {...register("customer_phone")}
            />
          </Field>

          {orderType === "dine_in" && (
            <Field
              label={t("checkout.guestCount")}
              htmlFor="guest_count"
              error={errors.guest_count?.message}
            >
              <Input
                id="guest_count"
                type="number"
                min={1}
                max={50}
                inputMode="numeric"
                invalid={Boolean(errors.guest_count)}
                {...register("guest_count")}
              />
            </Field>
          )}

          {orderType === "delivery" && (
            <Field
              label={t("checkout.address")}
              htmlFor="delivery_address"
              error={errors.delivery_address?.message}
              required
            >
              <Textarea
                id="delivery_address"
                rows={3}
                invalid={Boolean(errors.delivery_address)}
                {...register("delivery_address")}
              />
            </Field>
          )}

          <Field label={t("checkout.notes")} htmlFor="notes" error={errors.notes?.message}>
            <Textarea
              id="notes"
              rows={2}
              placeholder={t("checkout.notesPlaceholder")}
              invalid={Boolean(errors.notes)}
              {...register("notes")}
            />
          </Field>
        </fieldset>

        {/* Summary --------------------------------------------------------- */}
        <section className="surface-card space-y-2.5 p-5" aria-label={t("cart.total")}>
          {cart?.lines.map((line) => (
            <div key={line.product_id} className="flex justify-between gap-3 text-sm">
              <span className="text-content-secondary">
                <span className="tabular font-medium text-content">{line.quantity}×</span>{" "}
                {line.name}
              </span>
              <span className="tabular shrink-0 text-content">
                {formatMoney(line.line_total, currency, locale)}
              </span>
            </div>
          ))}

          <div className="flex items-baseline justify-between border-t border-border-subtle pt-3">
            <span className="font-semibold text-content">{t("cart.total")}</span>
            <span className="tabular text-xl font-bold text-accent">
              {formatMoney(cart?.grand_total ?? 0, currency, locale)}
            </span>
          </div>
        </section>

        <Button
          type="submit"
          size="lg"
          fullWidth
          loading={placeOrder.isPending}
          disabled={lines.length === 0}
        >
          {placeOrder.isPending ? t("checkout.placing") : t("checkout.placeOrder")}
        </Button>
      </form>
    </div>
  );
}
