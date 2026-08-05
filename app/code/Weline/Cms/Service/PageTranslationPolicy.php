<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use InvalidArgumentException;

final class PageTranslationPolicy
{
    /**
     * @param list<string> $supportedLocales
     * @param array<string,string> $titles
     * @return list<string>
     */
    public function missingTargets(array $supportedLocales, array $titles, string $sourceLocale): array
    {
        $supportedLocales = $this->normalizeLocales($supportedLocales);
        $sourceLocale = trim($sourceLocale);
        if ($sourceLocale === '' || !in_array($sourceLocale, $supportedLocales, true)) {
            throw new InvalidArgumentException((string)__('翻译源语言不属于当前网站。'));
        }
        if (trim((string)($titles[$sourceLocale] ?? '')) === '') {
            throw new InvalidArgumentException((string)__('请先填写源语言标题。'));
        }

        $targets = [];
        foreach ($supportedLocales as $locale) {
            if ($locale === $sourceLocale || trim((string)($titles[$locale] ?? '')) !== '') {
                continue;
            }
            $targets[] = $locale;
        }

        return $targets;
    }

    /**
     * @param array<string,string> $currentTitles
     * @param array<string,string> $translatedTitles
     * @param list<string> $supportedLocales
     * @return array<string,string>
     */
    public function mergeMissing(
        array $currentTitles,
        array $translatedTitles,
        array $supportedLocales,
        string $sourceLocale,
    ): array {
        $allowed = array_fill_keys($this->normalizeLocales($supportedLocales), true);
        $sourceLocale = trim($sourceLocale);

        foreach ($translatedTitles as $locale => $title) {
            $locale = trim((string)$locale);
            $title = trim((string)$title);
            if ($locale === '' || $locale === $sourceLocale || !isset($allowed[$locale]) || $title === '') {
                continue;
            }
            if (trim((string)($currentTitles[$locale] ?? '')) !== '') {
                continue;
            }
            $currentTitles[$locale] = $title;
        }

        return $currentTitles;
    }

    /**
     * @param list<string> $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale !== '' && !isset($normalized[$locale])) {
                $normalized[$locale] = true;
            }
        }

        return array_keys($normalized);
    }
}
