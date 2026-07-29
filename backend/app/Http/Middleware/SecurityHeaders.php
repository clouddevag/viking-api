<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth response headers.
 *
 * This is a JSON API with no first-party browser UI, so the CSP is maximally
 * restrictive — the only documents it ever serves directly are QR SVGs and
 * CSV exports.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(self), payment=()',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        // Inline styles are needed for the SVG QR codes we render directly.
        if (! $response->headers->has('Content-Security-Policy')) {
            $headers['Content-Security-Policy'] = implode('; ', [
                "default-src 'none'",
                "img-src 'self' data:",
                "style-src 'unsafe-inline'",
                "frame-ancestors 'none'",
                "base-uri 'none'",
                "form-action 'none'",
            ]);
        }

        // HSTS is only meaningful, and only safe, over a real TLS connection.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value, false);
        }

        return $response;
    }
}
