"use client";

import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from "react";
import { Loader2 } from "lucide-react";

import { cn } from "@/lib/utils";

type Variant = "primary" | "secondary" | "ghost" | "outline" | "danger" | "success";
type Size = "sm" | "md" | "lg" | "xl" | "icon";

const VARIANTS: Record<Variant, string> = {
  primary:
    "bg-accent text-accent-contrast hover:bg-accent-hover shadow-[var(--shadow-ember)] hover:shadow-lg",
  secondary:
    "bg-surface-sunken text-content hover:bg-border-subtle border border-border-subtle",
  ghost: "text-content hover:bg-surface-sunken",
  outline: "border border-border-strong text-content hover:bg-surface-sunken",
  danger: "bg-[var(--color-danger)] text-white hover:brightness-110",
  success: "bg-[var(--color-success)] text-white hover:brightness-110",
};

const SIZES: Record<Size, string> = {
  // 44px minimum touch target on every non-icon size — these screens are used
  // on phones and on kitchen tablets with wet hands.
  sm: "h-9 px-3 text-sm gap-1.5 rounded-lg",
  md: "h-11 px-4 text-sm gap-2 rounded-xl",
  lg: "h-12 px-6 text-base gap-2 rounded-xl",
  xl: "h-16 px-8 text-lg gap-3 rounded-2xl font-semibold",
  icon: "h-11 w-11 rounded-xl",
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  fullWidth?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    className,
    variant = "primary",
    size = "md",
    loading = false,
    icon,
    fullWidth = false,
    disabled,
    children,
    ...props
  },
  ref,
) {
  return (
    <button
      ref={ref}
      // A loading button stays focusable but rejects clicks, so a screen reader
      // user is not thrown out of the tab order mid-submit.
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        "inline-flex items-center justify-center font-medium",
        "transition-[background-color,box-shadow,transform] duration-200 ease-[var(--ease-out-quint)]",
        "active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
        VARIANTS[variant],
        SIZES[size],
        fullWidth && "w-full",
        className,
      )}
      {...props}
    >
      {loading ? (
        <Loader2 className="size-4 animate-spin" aria-hidden />
      ) : (
        icon
      )}
      {children}
    </button>
  );
});
