<?php

declare(strict_types=1);

namespace Weline\Frontend\Helper;

/**
 * Filters Phrase/JsWords bags before they are embedded into the storefront
 * runtime JSON. Event catalogs and other backend docs often call __() on
 * multi-line descriptions; dumping those into every page freezes the browser.
 */
final class FrontendRuntimeI18nDictionary
{
    /** Soft UI phrases stay; docs and event schemas usually exceed this. */
    public const MAX_KEY_CHARS = 160;

    /**
     * @param array<array-key, mixed> $words
     * @return array<string, string>
     */
    public static function filterForClient(array $words): array
    {
        $filtered = [];
        foreach ($words as $key => $value) {
            $phrase = is_string($key) && !is_numeric($key) ? $key : (is_string($value) ? $value : '');
            if ($phrase === '' || !self::isClientSafePhrase($phrase)) {
                continue;
            }
            $translation = is_string($value) && $value !== '' ? $value : $phrase;
            if (!self::isClientSafePhrase($translation)) {
                $translation = $phrase;
            }
            $filtered[$phrase] = $translation;
        }

        return $filtered;
    }

    public static function isClientSafePhrase(string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }
        if (str_contains($phrase, "\n") || str_contains($phrase, "\r")) {
            return false;
        }
        if (strlen($phrase) > self::MAX_KEY_CHARS) {
            return false;
        }

        return true;
    }
}
