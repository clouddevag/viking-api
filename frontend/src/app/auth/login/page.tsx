"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { UtensilsCrossed } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/primitives";
import { useLogin } from "@/hooks/queries";
import { ApiRequestError, credentials } from "@/lib/api/client";
import { reconnectEcho } from "@/lib/echo";
import { useI18n } from "@/lib/i18n/provider";
import { useAuthStore } from "@/stores/auth";

const schema = z.object({
  login: z.string().trim().min(3),
  password: z.string().min(1),
});

type FormValues = z.infer<typeof schema>;

/**
 * Sign-in for customers and staff alike — the API decides which by role, and
 * the redirect afterwards follows from that.
 */
export default function LoginPage() {
  // `useSearchParams` (for the post-login `next` redirect) requires a Suspense
  // boundary before this route can prerender.
  return (
    <Suspense fallback={<div className="grid min-h-dvh place-items-center bg-surface" />}>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const { t } = useI18n();
  const router = useRouter();
  const searchParams = useSearchParams();
  const login = useLogin();
  const signIn = useAuthStore((state) => state.signIn);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = handleSubmit(async (values) => {
    try {
      const result = await login.mutateAsync({
        ...values,
        // Hands this device's anonymous order history to the account.
        guest_token: credentials.getGuestToken(),
      });

      signIn(result.token, result.user);
      // The socket has to be rebuilt with the new token before private staff
      // channels will authorise.
      reconnectEcho();

      toast.success(t("auth.welcomeBack"));

      const next = searchParams.get("next");
      if (next) {
        router.replace(next);
        return;
      }

      // Staff land on the surface their role is actually for.
      const roles = result.user.roles ?? [];
      if (roles.includes("kitchen")) router.replace("/kitchen");
      else if (roles.includes("cashier")) router.replace("/cashier");
      else if (roles.some((role) => ["super-admin", "admin", "manager"].includes(role)))
        router.replace("/admin");
      else router.replace("/");
    } catch (error) {
      if (error instanceof ApiRequestError) {
        if (error.fieldErrors?.login) {
          setError("login", { message: error.fieldErrors.login[0] });
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
          <h1 className="mt-5 font-display text-3xl text-content">{t("auth.signIn")}</h1>
        </div>

        <form onSubmit={onSubmit} className="mt-8 space-y-4">
          <Field
            label={t("auth.emailOrPhone")}
            htmlFor="login"
            error={errors.login?.message}
            required
          >
            <Input
              id="login"
              autoComplete="username"
              dir="ltr"
              invalid={Boolean(errors.login)}
              {...register("login")}
            />
          </Field>

          <Field
            label={t("auth.password")}
            htmlFor="password"
            error={errors.password?.message}
            required
          >
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              invalid={Boolean(errors.password)}
              {...register("password")}
            />
          </Field>

          <Button type="submit" size="lg" fullWidth loading={login.isPending}>
            {t("auth.signIn")}
          </Button>
        </form>

        <div className="mt-6 space-y-3 text-center text-sm">
          <p className="text-content-secondary">
            {t("auth.noAccount")}{" "}
            <Link href="/auth/register" className="font-medium text-accent hover:underline">
              {t("action.signUp")}
            </Link>
          </p>

          <Link href="/menu" className="inline-block text-content-muted hover:text-content">
            {t("auth.continueAsGuest")}
          </Link>
        </div>
      </div>
    </main>
  );
}
