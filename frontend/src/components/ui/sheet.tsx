"use client";

import { AnimatePresence, motion } from "framer-motion";
import { X } from "lucide-react";
import { useEffect, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";

import { useI18n } from "@/lib/i18n/provider";
import { cn } from "@/lib/utils";

/**
 * Bottom sheet on phones, side panel on wide screens.
 *
 * Portalled to `document.body` so it escapes any transformed ancestor, and
 * because `dir` is set on `<html>` the panel inherits the right direction from
 * there rather than needing it passed down.
 */
export function Sheet({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  side = "bottom",
  className,
}: {
  open: boolean;
  onClose: () => void;
  title?: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  side?: "bottom" | "end";
  className?: string;
}) {
  const panelRef = useRef<HTMLDivElement>(null);
  const { t, isRtl } = useI18n();

  // Escape to dismiss, and lock the page behind the sheet so a scroll gesture
  // inside it does not chain to the body underneath.
  useEffect(() => {
    if (!open) return;

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") onClose();
    };

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    document.addEventListener("keydown", onKeyDown);

    // Move focus into the panel so keyboard and screen reader users land here.
    const focusTimer = window.setTimeout(() => panelRef.current?.focus(), 50);

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKeyDown);
      window.clearTimeout(focusTimer);
    };
  }, [open, onClose]);

  if (typeof document === "undefined") return null;

  const isBottom = side === "bottom";
  const offscreen = isBottom ? { y: "100%" } : { x: isRtl ? "-100%" : "100%" };

  return createPortal(
    <AnimatePresence>
      {open && (
        <div className="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" aria-label={title}>
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            onClick={onClose}
            className="absolute inset-0 bg-[var(--surface-overlay)] backdrop-blur-sm"
            aria-hidden
          />

          <motion.div
            ref={panelRef}
            tabIndex={-1}
            initial={offscreen}
            animate={{ x: 0, y: 0 }}
            exit={offscreen}
            transition={{ type: "spring", damping: 32, stiffness: 320 }}
            className={cn(
              "relative z-10 flex flex-col bg-surface-raised shadow-[var(--shadow-lifted)] outline-none",
              isBottom
                ? "mt-auto max-h-[92dvh] w-full rounded-t-3xl"
                : "ms-auto h-full w-full max-w-md rounded-s-3xl",
              className,
            )}
          >
            {/* Drag affordance — signals the sheet is dismissible. */}
            {isBottom && (
              <div className="flex justify-center pt-3 pb-1" aria-hidden>
                <div className="h-1.5 w-10 rounded-full bg-border-strong" />
              </div>
            )}

            {(title || description) && (
              <header className="flex items-start justify-between gap-4 px-5 pt-4 pb-3">
                <div className="min-w-0">
                  {title && <h2 className="text-lg font-semibold text-content">{title}</h2>}
                  {description && (
                    <p className="mt-0.5 text-sm text-content-secondary">{description}</p>
                  )}
                </div>

                <button
                  type="button"
                  onClick={onClose}
                  aria-label={t("action.close")}
                  className="-me-1 shrink-0 rounded-lg p-2 text-content-muted transition-colors hover:bg-surface-sunken hover:text-content"
                >
                  <X className="size-5" aria-hidden />
                </button>
              </header>
            )}

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-5">
              {children}
            </div>

            {footer && (
              <footer className="border-t border-border-subtle bg-surface-raised px-5 py-4 pb-safe">
                {footer}
              </footer>
            )}
          </motion.div>
        </div>
      )}
    </AnimatePresence>,
    document.body,
  );
}
