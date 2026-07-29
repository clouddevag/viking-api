import type { NextConfig } from "next";

/**
 * Next.js 16 runs Turbopack for both `dev` and `build` by default, so there is
 * deliberately no webpack configuration here — defining one would fail the
 * build rather than silently fall back.
 */
const nextConfig: NextConfig = {
  reactStrictMode: true,

  // Emits a self-contained server bundle so the production image ships without
  // node_modules or source.
  output: "standalone",

  // The API returns absolute media URLs, so every host that can serve product
  // photography has to be allow-listed. `images.domains` is deprecated in 16.
  images: {
    remotePatterns: [
      // Local development: Laravel's public disk.
      { protocol: "http", hostname: "localhost", port: "8000", pathname: "/storage/**" },
      { protocol: "http", hostname: "127.0.0.1", port: "8000", pathname: "/storage/**" },
      // Production: the API host or an S3-compatible bucket / CDN.
      ...(process.env.NEXT_PUBLIC_MEDIA_HOSTNAME
        ? [
            {
              protocol: "https" as const,
              hostname: process.env.NEXT_PUBLIC_MEDIA_HOSTNAME,
              pathname: "/**",
            },
          ]
        : []),
    ],

    // Local IP optimisation is blocked by default in 16, but development serves
    // media from 127.0.0.1, so it is opted back in outside production only.
    dangerouslyAllowLocalIP: process.env.NODE_ENV !== "production",

    // Menu photography is the heaviest thing on the page. AVIF first, then
    // WebP, so modern phones get the smallest payload.
    formats: ["image/avif", "image/webp"],

    // A single quality level keeps the srcset small; 75 is indistinguishable
    // from 100 for food photography at these sizes.
    qualities: [75],

    // Product images are re-uploaded rarely, so a long cache is safe and cuts
    // optimisation cost substantially.
    minimumCacheTTL: 60 * 60 * 24,
  },

  // Trims the client bundle by importing only the icons actually used rather
  // than the whole lucide barrel file.
  experimental: {
    optimizePackageImports: ["lucide-react", "recharts", "date-fns"],
  },

  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "SAMEORIGIN" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            // Camera stays available: the customer app scans table QR codes.
            value: "camera=(self), microphone=(), geolocation=(self), payment=()",
          },
        ],
      },
      {
        // The service worker must never be cached, or clients pin themselves
        // to an old build forever.
        source: "/sw.js",
        headers: [{ key: "Cache-Control", value: "no-cache, no-store, must-revalidate" }],
      },
    ];
  },
};

export default nextConfig;
