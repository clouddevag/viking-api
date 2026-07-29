<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\App;

/**
 * Menu content is stored in parallel `*_en` / `*_ar` columns rather than a JSON
 * blob so both locales stay indexable and full-text searchable.
 *
 * This trait exposes the pair as one virtual attribute: `$product->name`
 * resolves to `name_ar` under an Arabic locale and falls back to `name_en` when
 * the translation is empty, which is what the API resources serialise.
 *
 * Models opt in by listing their base names in `$localized`.
 */
trait HasLocalizedAttributes
{
    /**
     * Base attribute names that have `_en` / `_ar` column pairs.
     *
     * @return array<int, string>
     */
    public function localizedAttributes(): array
    {
        return $this->localized ?? [];
    }

    public function getAttribute($key)
    {
        if (in_array($key, $this->localizedAttributes(), true) && ! $this->hasAttributeInDatabase($key)) {
            return $this->translate($key);
        }

        return parent::getAttribute($key);
    }

    /**
     * Resolves one localized attribute, falling back to the application's
     * fallback locale when the requested translation is missing or blank.
     */
    public function translate(string $key, ?string $locale = null): ?string
    {
        $locale = $locale ?? App::getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $value = $this->getAttributeFromArray("{$key}_{$locale}");

        if ($value === null || $value === '') {
            $value = $this->getAttributeFromArray("{$key}_{$fallback}");
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Both translations for one attribute, used by the admin panel where the
     * editor always sees every locale at once.
     *
     * @return array<string, string|null>
     */
    public function translations(string $key): array
    {
        return [
            'en' => $this->getAttributeFromArray("{$key}_en"),
            'ar' => $this->getAttributeFromArray("{$key}_ar"),
        ];
    }

    /**
     * Guards against shadowing a real column: if a model genuinely has a `name`
     * column we must not hijack it.
     */
    protected function hasAttributeInDatabase(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /** @return array<int, string> */
    public function appendLocalized(): array
    {
        return $this->localizedAttributes();
    }
}
