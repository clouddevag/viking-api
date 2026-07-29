<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale, which decides which side of every `*_en` /
 * `*_ar` column pair the API resources serialise.
 *
 * Precedence: explicit header, then query string, then the signed-in user's
 * saved preference, then Accept-Language, then the app default.
 */
class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);

        $response = $next($request);

        // Tell caches that a response varies by language.
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $candidates = [
            $request->header('X-Locale'),
            $request->query('locale'),
            $request->user()?->locale,
            $request->getPreferredLanguage(self::SUPPORTED),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return (string) config('app.locale', 'ar');
    }
}
