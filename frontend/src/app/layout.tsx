import type { Metadata, Viewport } from "next";
import { IBM_Plex_Sans_Arabic, Instrument_Serif } from "next/font/google";

import { Providers } from "@/components/providers";

import "./globals.css";

/**
 * IBM Plex Sans Arabic carries both scripts, so Arabic and English share one
 * face and one vertical rhythm instead of visibly switching fonts mid-sentence
 * in mixed content like "برغر Classic".
 */
const body = IBM_Plex_Sans_Arabic({
  subsets: ["arabic", "latin"],
  weight: ["300", "400", "500", "600", "700"],
  variable: "--font-body",
  display: "swap",
});

/** Latin-only display face; Arabic headings fall back to the body face. */
const display = Instrument_Serif({
  subsets: ["latin"],
  weight: ["400"],
  style: ["normal", "italic"],
  variable: "--font-display",
  display: "swap",
});

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000"),
  title: {
    default: "Viking — برغر مشوي على النار",
    template: "%s · Viking",
  },
  description:
    "Order fire-grilled burgers, chicken and sides from Viking. Scan your table, browse the menu and track your order live.",
  applicationName: "Viking",
  keywords: ["Viking", "burger", "restaurant", "Baghdad", "مطعم", "برغر", "طلب اونلاين"],
  manifest: "/manifest.webmanifest",
  appleWebApp: {
    capable: true,
    title: "Viking",
    statusBarStyle: "black-translucent",
  },
  formatDetection: { telephone: false },
  openGraph: {
    type: "website",
    siteName: "Viking",
    title: "Viking — Fire-grilled burgers",
    description: "Scan, order and track your table's food in real time.",
  },
  twitter: { card: "summary_large_image" },
  robots: { index: true, follow: true },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  // Zoom is left enabled: pinch-to-zoom is an accessibility requirement, and
  // locking it out to feel "app-like" is not worth excluding people over.
  maximumScale: 5,
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#faf7f2" },
    { media: "(prefers-color-scheme: dark)", color: "#0b0b0d" },
  ],
  viewportFit: "cover",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    // `lang`/`dir` are the Arabic default; the I18n provider updates them on
    // the client once the stored preference is known.
    //
    // `data-scroll-behavior` opts back into Next's scroll override, which 16
    // dropped by default — without it the global `scroll-behavior: smooth`
    // makes every route change animate its scroll to top.
    <html lang="ar" dir="rtl" data-scroll-behavior="smooth" suppressHydrationWarning>
      <body className={`${body.variable} ${display.variable} antialiased`}>
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-accent-contrast"
        >
          Skip to content
        </a>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
