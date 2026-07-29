"use client";

/**
 * Kitchen alert tone.
 *
 * Synthesised with the Web Audio API rather than shipping an audio file: it is
 * a few lines, has no network cost, and — more importantly — a generated tone
 * can be re-triggered instantly without waiting on decode, which matters when
 * three tickets land at once.
 *
 * Browsers block audio until the user has interacted with the page, so the
 * context is created lazily on the first unlock and `primeAudio()` is wired to
 * the sound toggle.
 */

let context: AudioContext | null = null;

function ensureContext(): AudioContext | null {
  if (typeof window === "undefined") return null;

  if (!context) {
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Ctor) return null;
    context = new Ctor();
  }

  // Autoplay policy suspends a context created before interaction.
  if (context.state === "suspended") void context.resume();

  return context;
}

/** Call from a click handler so later programmatic plays are allowed. */
export function primeAudio(): void {
  ensureContext();
}

/**
 * Two rising notes — distinct enough to cut through kitchen noise without
 * being an alarm.
 */
export function playNewOrderChime(): void {
  const ctx = ensureContext();
  if (!ctx) return;

  const now = ctx.currentTime;

  [
    { frequency: 660, start: 0, duration: 0.16 },
    { frequency: 880, start: 0.14, duration: 0.26 },
  ].forEach(({ frequency, start, duration }) => {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();

    oscillator.type = "sine";
    oscillator.frequency.value = frequency;

    // Shaped envelope; a raw square start would click.
    gain.gain.setValueAtTime(0, now + start);
    gain.gain.linearRampToValueAtTime(0.28, now + start + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.001, now + start + duration);

    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(now + start);
    oscillator.stop(now + start + duration + 0.02);
  });
}

/** Lower, shorter blip for a ticket that has passed its critical age. */
export function playUrgentBlip(): void {
  const ctx = ensureContext();
  if (!ctx) return;

  const now = ctx.currentTime;
  const oscillator = ctx.createOscillator();
  const gain = ctx.createGain();

  oscillator.type = "triangle";
  oscillator.frequency.value = 320;

  gain.gain.setValueAtTime(0, now);
  gain.gain.linearRampToValueAtTime(0.22, now + 0.02);
  gain.gain.exponentialRampToValueAtTime(0.001, now + 0.3);

  oscillator.connect(gain).connect(ctx.destination);
  oscillator.start(now);
  oscillator.stop(now + 0.32);
}
