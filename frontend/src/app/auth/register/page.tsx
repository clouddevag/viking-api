"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { UtensilsCrossed } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/primitives";
import { useRegister } from "@/hooks/queries";
import { ApiRequestError, credentials } from "@/lib/api/client";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";

/**
 * Customer registration.
 *
 * The phone number is the required identifier and email is optional, because
 * most customers here order from a phone and many have no email at all.
 */
const schema = z
  .object({
    name: z.string().trim().min(2).max(120),
    phone: z
      .string()
      .trim()
      .regex(/^[0-9+\-\s()]{6,32}$/),
    email: z.string().trim().email().optional().or(z.literal("")),
    password: z.string().min(8).max(255),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ["password_confirmation"],
    message: "passwordMismatch",
  });

type FormValues = z.infer<typeof schema>;

export default function RegisterPage() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const registerUser = useRegister();
  const signIn = useAuthStore((state) => state.signIn);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = handleSubmit(async (values) => {
    try {
      const result = await registerUser.mutateAsync({
        name: values.name,
        phone: values.phone,
        email: values.email || null,
        password: values.password,
        password_confirmation: values.password_confirmation,
        locale,
        // Carries this device's guest orders over to the new account.
        guest_token: credentials.getGuestToken(),
      });

      signIn(result.token, result.user);
      toast.success(t("auth.welcomeBack"));
      router.replace("/");
    } catch (error) {
      if (error instanceof ApiRequestError) {
        if (error.isValidation && error.fieldErrors) {
          Object.entries(error.fieldErrors).forEach(([field, messages]) => {
            setError(field as keyof FormValues, { message: messages[0] });
          });
        }
        toast.error(error.message);
        return;
      }

      toast.error(t("state.error"));
    }
  });

  return (
    <main className="grid min-h-dvh place-items-center bg-surface px-6 py-10">
      <div className="w-full max-w-sm">
        <div className="text-center">
          <Link href="/" className="mx-auto grid size-14 place-items-center rounded-2xl bg-accent text-accent-contrast">
            <UtensilsCrossed className="size-6" aria-hidden />
          </Link>
          <h1 className="mt-5 font-display text-3xl text-content">{t("auth.signUp")}</h1>
        </div>

        <form onSubmit={onSubmit} className="mt-8 space-y-4">
          <Field label={t("checkout.name")} htmlFor="name" error={errors.name?.message} required>
            <Input id="name" autoComplete="name" invalid={Boolean(errors.name)} {...register("name")} />
          </Field>

          <Field
            label={t("checkout.phone")}
            htmlFor="phone"
            error={errors.phone && t("error.invalidPhone")}
            required
          >
            <Input
              id="phone"
              type="tel"
              inputMode="tel"
              dir="ltr"
              autoComplete="tel"
              placeholder="+964 770 000 0000"
              invalid={Boolean(errors.phone)}
              {...register("phone")}
            />
          </Field>

          <Field
            label="Email"
            htmlFor="email"
            hint={t("product.optional")}
            error={errors.email && t("error.invalidEmail")}
          >
            <Input
              id="email"
              type="email"
              dir="ltr"
              autoComplete="email"
              invalid={Boolean(errors.email)}
              {...register("email")}
            />
          </Field>

          <Field
            label={t("auth.password")}
            htmlFor="password"
            error={errors.password && t("error.passwordTooShort")}
            required
          >
            <Input
              id="password"
              type="password"
              autoComplete="new-password"
              invalid={Boolean(errors.password)}
              {...register("password")}
            />
          </Field>

          <Field
            label={t("account.confirmPassword")}
            htmlFor="password_confirmation"
            error={errors.password_confirmation && t("error.passwordMismatch")}
            required
          >
            <Input
              id="password_confirmation"
              type="password"
              autoComplete="new-password"
              invalid={Boolean(errors.password_confirmation)}
              {...register("password_confirmation")}
            />
          </Field>

          <Button type="submit" size="lg" fullWidth loading={registerUser.isPending}>
            {t("action.signUp")}
          </Button>
        </form>

        <p className="mt-6 text-center text-sm text-content-secondary">
          {t("auth.hasAccount")}{" "}
          <Link href="/auth/login" className="font-medium text-accent hover:underline">
            {t("auth.signIn")}
          </Link>
        </p>
      </div>
    </main>
  );
}
