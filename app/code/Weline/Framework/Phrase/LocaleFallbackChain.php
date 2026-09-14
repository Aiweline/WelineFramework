<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * Deterministic locale candidates for maintained storefront dictionaries.
 *
 * Chinese targets keep their Chinese source text as the final neutral copy.
 * Other targets prefer maintained en_US copy before the configured website
 * locale, then naturally fall back to the source phrase.
 */
final class LocaleFallbackChain
{
    public const NEUTRAL_LOCALE = 'en_US';

    /**
     * @return list<string>
     */
    public static function candidates(string $targetLocale, string $websiteDefaultLocale = ''): array
    {
        $targetLocale = self::normalize($targetLocale);
        $websiteDefaultLocale = self::normalize($websiteDefaultLocale);
        $candidates = [];

        self::append($candidates, $targetLocale);
        if ($targetLocale === '' || !self::isChinese($targetLocale)) {
            self::append($candidates, self::NEUTRAL_LOCALE);
            self::append($candidates, $websiteDefaultLocale);
        }

        return $candidates;
    }

    public static function normalize(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        if ($locale === '') {
            return '';
        }

        $parts = array_values(array_filter(explode('_', $locale), static fn(string $part): bool => $part !== ''));
        foreach ($parts as $index => $part) {
            if ($index === 0) {
                $parts[$index] = strtolower($part);
                continue;
            }
            $parts[$index] = strlen($part) === 4
                ? ucfirst(strtolower($part))
                : strtoupper($part);
        }

        return implode('_', $parts);
    }

    /** 网站默认语言的统一读取入口；仅读取已加载环境，不写配置。 */
    public static function websiteDefaultLocale(): string
    {
        foreach (['website.language', 'locale', 'lang'] as $configKey) {
            try {
                $candidate = \Weline\Framework\App\Env::get($configKey, '');
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $candidate = self::normalize((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            } catch (\Throwable) {
            }
        }

        return self::normalize(\Weline\Framework\App\Env::default_LANGUAGE_CODE);
    }

    private static function isChinese(string $locale): bool
    {
        return strtolower((string)strtok($locale, '_')) === 'zh';
    }

    /** @param list<string> $candidates */
    private static function append(array &$candidates, string $locale): void
    {
        if ($locale !== '' && !in_array($locale, $candidates, true)) {
            $candidates[] = $locale;
        }
    }
}
