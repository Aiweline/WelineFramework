<?php

declare(strict_types=1);

namespace Weline\Filters\Service;

use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;

/**
 * Translate storefront facet chrome/values using Weline_Filters i18n CSV.
 *
 * Product listing requests only load the Product Phrase module dictionary, so
 * Filters CSV keys never reach __() for dynamic attribute names/options.
 */
final class StorefrontFacetTranslator
{
    /** @var array<string, array<string, string>> */
    private static array $dictionaries = [];

    /** @var array<string, int> */
    private static array $dictionaryMtimes = [];

    public static function clearDictionaries(): void
    {
        self::$dictionaries = [];
        self::$dictionaryMtimes = [];
    }

    public function translate(string $text, ?string $locale = null): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        foreach ($this->localeCandidates($locale) as $candidate) {
            $dict = $this->dictionary($candidate);
            $translated = trim((string)($dict[$text] ?? ''));
            if ($translated !== '' && $translated !== $text) {
                return $translated;
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function localeCandidates(?string $locale = null): array
    {
        $primary = trim(str_replace('-', '_', (string)($locale ?? '')));
        if ($primary === '') {
            try {
                $primary = trim(str_replace('-', '_', (string)RequestContext::getWelineUserLang()));
            } catch (\Throwable) {
                $primary = '';
            }
        }
        if ($primary === '') {
            try {
                $primary = trim(str_replace('-', '_', (string)State::getLang()));
            } catch (\Throwable) {
                $primary = '';
            }
        }
        if ($primary === '') {
            $primary = 'zh_Hans_CN';
        }

        $candidates = [$primary];
        $lower = strtolower($primary);
        if (str_starts_with($lower, 'en')) {
            $candidates[] = 'en_US';
        } elseif (str_starts_with($lower, 'zh')) {
            $candidates[] = 'zh_Hans_CN';
        } elseif (str_starts_with($lower, 'ar')) {
            $candidates[] = 'ar_SA';
            $candidates[] = 'en_US';
        } else {
            $candidates[] = 'en_US';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . $locale . '.csv';
        $mtime = is_file($path) ? (int)@filemtime($path) : 0;
        if (
            isset(self::$dictionaries[$locale])
            && (self::$dictionaryMtimes[$locale] ?? -1) === $mtime
        ) {
            return self::$dictionaries[$locale];
        }

        $dict = [];
        if (!is_file($path)) {
            self::$dictionaryMtimes[$locale] = $mtime;

            return self::$dictionaries[$locale] = $dict;
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            self::$dictionaryMtimes[$locale] = $mtime;

            return self::$dictionaries[$locale] = $dict;
        }

        try {
            $prefix = fread($handle, 3);
            if ($prefix !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $key = trim((string)($row[0] ?? ''));
                $value = trim((string)($row[1] ?? ''));
                if ($key === '' || $value === '') {
                    continue;
                }
                $dict[$key] = $value;
            }
        } finally {
            fclose($handle);
        }

        self::$dictionaryMtimes[$locale] = $mtime;

        return self::$dictionaries[$locale] = $dict;
    }
}
