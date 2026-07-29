"use client";

import { forwardRef, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from "react";
import { AlertCircle } from "lucide-react";

import { cn } from "@/lib/utils";

/* -----------------------------------------------------------------------------
 * Card
 * -------------------------------------------------------------------------- */

export function Card({
  className,
  children,
  as: Component = "div",
  ...props
}: {
  className?: string;
  children: ReactNode;
  as?: "div" | "section" | "article";
} & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <Component className={cn("surface-card p-5", className)} {...props}>
      {children}
    </Component>
  );
}

/* -----------------------------------------------------------------------------
 * Field
 *
 * Label, control, hint and error as one unit. Wiring the error to the input
 * with aria-describedby in a single place is what keeps forms accessible
 * without every screen having to remember it.
 * -------------------------------------------------------------------------- */

interface FieldProps {
  label: string;
  htmlFor: string;
  error?: string;
  hint?: string;
  required?: boolean;
  children: ReactNode;
  className?: string;
}

export function Field({ label, htmlFor, error, hint, required, children, className }: FieldProps) {
  return (
    <div className={cn("space-y-1.5", className)}>
      <label htmlFor={htmlFor} className="block text-sm font-medium text-content">
        {label}
        {required && (
          <span className="text-[var(--color-danger)] ms-1" aria-hidden>
            *
          </span>
        )}
      </label>

      {children}

      {hint && !error && <p className="text-xs text-content-muted">{hint}</p>}

      {error && (
        <p
          id={`${htmlFor}-error`}
          role="alert"
          className="flex items-center gap-1.5 text-xs text-[var(--color-danger)]"
        >
          <AlertCircle className="size-3.5 shrink-0" aria-hidden />
          {error}
        </p>
      )}
    </div>
  );
}

/* -----------------------------------------------------------------------------
 * Input / Textarea / Select
 * -------------------------------------------------------------------------- */

const CONTROL_CLASSES = cn(
  "w-full rounded-xl border bg-surface-raised px-3.5 py-2.5 text-sm text-content",
  "placeholder:text-content-muted",
  "transition-colors duration-150",
  "focus:border-accent focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25",
  "disabled:cursor-not-allowed disabled:opacity-60",
);

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(
  function Input({ className, invalid, id, ...props }, ref) {
    return (
      <input
        ref={ref}
        id={id}
        aria-invalid={invalid || undefined}
        aria-describedby={invalid && id ? `${id}-error` : undefined}
        className={cn(
          CONTROL_CLASSES,
          invalid ? "border-[var(--color-danger)]" : "border-border-subtle",
          className,
        )}
        {...props}
      />
    );
  },
);

export const Textarea = forwardRef<
  HTMLTextAreaElement,
  TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }
>(function Textarea({ className, invalid, id, rows = 3, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      id={id}
      rows={rows}
      aria-invalid={invalid || undefined}
      aria-describedby={invalid && id ? `${id}-error` : undefined}
      className={cn(
        CONTROL_CLASSES,
        "resize-y",
        invalid ? "border-[var(--color-danger)]" : "border-border-subtle",
        className,
      )}
      {...props}
    />
  );
});

export const Select = forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }
>(function Select({ className, invalid, children, ...props }, ref) {
  return (
    <select
      ref={ref}
      className={cn(
        CONTROL_CLASSES,
        "appearance-none bg-no-repeat pe-9",
        invalid ? "border-[var(--color-danger)]" : "border-border-subtle",
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
});

/* -----------------------------------------------------------------------------
 * Badge
 * -------------------------------------------------------------------------- */

type BadgeTone = "neutral" | "accent" | "success" | "warning" | "danger" | "info" | "gold";

const BADGE_TONES: Record<BadgeTone, string> = {
  neutral: "bg-surface-sunken text-content-secondary",
  accent: "bg-accent-soft text-accent",
  success: "bg-[var(--color-success-soft)] text-[var(--color-success)]",
  warning: "bg-[var(--color-warning-soft)] text-[var(--color-warning)]",
  danger: "bg-[var(--color-danger-soft)] text-[var(--color-danger)]",
  info: "bg-[var(--color-info-soft)] text-[var(--color-info)]",
  gold: "bg-[var(--color-gold-500)]/15 text-[var(--color-gold-600)] dark:text-[var(--color-gold-400)]",
};

export function Badge({
  tone = "neutral",
  className,
  children,
  icon,
}: {
  tone?: BadgeTone;
  className?: string;
  children: ReactNode;
  icon?: ReactNode;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap",
        BADGE_TONES[tone],
        className,
      )}
    >
      {icon}
      {children}
    </span>
  );
}

/* -----------------------------------------------------------------------------
 * Skeleton and empty state
 * -------------------------------------------------------------------------- */

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn("skeleton rounded-lg", className)} aria-hidden />;
}

export function EmptyState({
  icon,
  title,
  description,
  action,
  className,
}: {
  icon?: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-16 text-center", className)}>
      {icon && (
        <div className="mb-4 flex size-16 items-center justify-center rounded-2xl bg-surface-sunken text-content-muted">
          {icon}
        </div>
      )}
      <h3 className="text-lg font-semibold text-content">{title}</h3>
      {description && (
        <p className="mt-1.5 max-w-sm text-sm text-content-secondary">{description}</p>
      )}
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}

/* -----------------------------------------------------------------------------
 * Switch
 * -------------------------------------------------------------------------- */

export function Switch({
  checked,
  onChange,
  label,
  disabled,
  id,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  disabled?: boolean;
  id?: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      id={id}
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cn(
        "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200",
        "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
        "disabled:cursor-not-allowed disabled:opacity-50",
        checked ? "bg-accent" : "bg-border-strong",
      )}
    >
      <span
        // Translated with a logical offset so the knob travels the correct way
        // under RTL without a separate stylesheet.
        className={cn(
          "inline-block size-4 rounded-full bg-white shadow transition-transform duration-200",
          checked ? "translate-x-[1.375rem] rtl:-translate-x-[1.375rem]" : "translate-x-1 rtl:-translate-x-1",
        )}
      />
    </button>
  );
}
