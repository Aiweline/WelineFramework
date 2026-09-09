<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Supplies localized defaults for the Hanfu storefront homepage only.
 *
 * The head template is rendered independently from the Theme layout, so the
 * shared SEO provider pipeline is the authoritative integration point. Unknown
 * values are treated as merchant-authored and are deliberately left untouched.
 */
final class HanfuHomepageSeoProfileProvider implements SeoProfileProviderInterface
{
    private const SITE_NAME_ZH = '云裳汉服 · Hanfu Atelier';
    private const SITE_NAME_EN = 'Yunshang Hanfu · Hanfu Atelier';
    private const TITLE_ZH = '云裳汉服 · Hanfu Atelier | 水墨汉服商城首页';
    private const TITLE_EN = 'Yunshang Hanfu · Hanfu Atelier | Ink-Wash Hanfu Boutique';
    private const DESCRIPTION_ZH = '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的汉服款式。';
    private const DESCRIPTION_EN = 'Yunshang Hanfu offers Ming, Song, and Tang Hanfu, mamian skirts, and accessories for everyday wear, festivals, and ceremonies.';
    private const SHARE_IMAGE = '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp';
    private const SHARE_IMAGE_ALT_ZH = '桃园清梦米白粉色明制上衣与马面裙套装';
    private const SHARE_IMAGE_ALT_EN = 'Peach Garden Dream ivory-and-pink Ming-style top and mamian set';
    /** @var list<string> Demo storefront social profiles used when merchant has not authored sameAs / footer links. */
    private const SAME_AS = [
        'https://www.instagram.com/yunshang.hanfu',
        'https://www.pinterest.com/yunshanghanfu',
        'https://www.tiktok.com/@yunshanghanfu',
        'https://www.youtube.com/@yunshanghanfu',
    ];

    public function __construct(
        private readonly TranslationResolverInterface $translations,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        $homepageLocale = $this->homepageLocaleSegment($context);
        $routeLocale = $this->routeLocaleSegment($context);
        $locale = $routeLocale !== ''
            ? $routeLocale
            : trim((string)($context['locale'] ?? ''));
        $normalizedLocale = strtolower(str_replace('_', '-', $locale));
        $isChinese = $normalizedLocale === '' || str_starts_with($normalizedLocale, 'zh');
        $localizedSiteName = $this->localizedDefault(
            self::SITE_NAME_ZH,
            self::SITE_NAME_EN,
            $locale,
            $isChinese,
        );

        $profile = [];
        $siteName = trim((string)($context['site_name'] ?? ''));
        $usesFrameworkBrand = $this->isSystemDefaultSiteName($siteName);
        if ($usesFrameworkBrand) {
            $profile['site_name'] = $localizedSiteName;
            $profile['organization'] = [
                'name' => $localizedSiteName,
            ];
        }

        if ($homepageLocale === null) {
            $title = trim((string)($context['title'] ?? ''));
            $description = trim((string)($context['description'] ?? ''));
            if ($usesFrameworkBrand
                && $title !== ''
                && in_array($description, [
                    $title . ' - ' . $siteName,
                    $title . ' - Weline Framework',
                ], true)
            ) {
                $profile['description'] = $title . ' - ' . $localizedSiteName;
            }

            return $this->withSameAsDefaults($profile, $context, $usesFrameworkBrand, $localizedSiteName);
        }

        $profile['page_type'] = 'home';
        $localizedTitle = $this->localizedDefault(self::TITLE_ZH, self::TITLE_EN, $locale, $isChinese);
        $localizedDescription = $this->localizedDefault(
            self::DESCRIPTION_ZH,
            self::DESCRIPTION_EN,
            $locale,
            $isChinese,
        );

        if ($this->isSystemDefaultTitle((string)($context['title'] ?? ''), $localizedTitle)) {
            $profile['title'] = $localizedTitle;
        }
        if ($this->isSystemDefaultDescription(
            (string)($context['description'] ?? ''),
            $context,
            $localizedDescription,
        )) {
            $profile['description'] = $localizedDescription;
        }

        if (trim((string)($context['image'] ?? '')) === '') {
            $profile['image'] = self::SHARE_IMAGE;
            $profile['image_alt'] = $this->localizedDefault(
                self::SHARE_IMAGE_ALT_ZH,
                self::SHARE_IMAGE_ALT_EN,
                $locale,
                $isChinese,
            );
        }

        return $this->withSameAsDefaults($profile, $context, $usesFrameworkBrand, $localizedSiteName);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withSameAsDefaults(
        array $profile,
        array $context,
        bool $usesFrameworkBrand,
        string $localizedSiteName,
    ): array {
        $organization = is_array($context['organization'] ?? null) ? $context['organization'] : [];
        $existingSameAs = $organization['sameAs'] ?? null;
        if (is_array($existingSameAs) && $this->hasActionableSameAs($existingSameAs)) {
            return $profile;
        }
        $profileOrg = is_array($profile['organization'] ?? null) ? $profile['organization'] : [];
        $profileSameAs = $profileOrg['sameAs'] ?? null;
        if (is_array($profileSameAs) && $this->hasActionableSameAs($profileSameAs)) {
            return $profile;
        }

        $brandName = trim((string) ($profile['site_name'] ?? $context['site_name'] ?? $localizedSiteName));
        if (!$usesFrameworkBrand && !$this->isYunshangHanfuBrand($brandName)) {
            return $profile;
        }

        $profile['organization'] = array_replace($profileOrg, [
            'sameAs' => self::SAME_AS,
        ]);

        return $profile;
    }

    /** @param mixed $sameAs */
    private function hasActionableSameAs(mixed $sameAs): bool
    {
        if (!is_array($sameAs)) {
            return false;
        }
        foreach ($sameAs as $url) {
            if (!is_string($url) && !is_numeric($url)) {
                continue;
            }
            $url = trim((string) $url);
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }
            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
            if (in_array($scheme, ['http', 'https'], true) || str_starts_with($url, '//')) {
                return true;
            }
        }

        return false;
    }

    private function isYunshangHanfuBrand(string $siteName): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $siteName) ?? $siteName);
        if ($normalized === '') {
            return false;
        }
        if (in_array($normalized, [
            self::SITE_NAME_ZH,
            self::SITE_NAME_EN,
            '云裳汉服',
            'Yunshang Hanfu',
        ], true)) {
            return true;
        }

        return str_contains($normalized, '云裳汉服')
            || str_contains(mb_strtolower($normalized), 'yunshang hanfu')
            || str_contains(mb_strtolower($normalized), 'yunshang');
    }

    /** @param array<string, mixed> $context */
    private function routeLocaleSegment(array $context): string
    {
        $segments = $this->storefrontPathSegments($context);
        foreach ($segments as $segment) {
            if ($this->isCurrencySegment($segment)) {
                continue;
            }
            if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1) {
                return $segment;
            }
            break;
        }

        return '';
    }

    /**
     * Returns an empty string for the unprefixed homepage, its locale segment
     * for a locale homepage, and null for every non-homepage URL.
     *
     * Currency prefixes like `/USD/en_US` must still count as homepage.
     *
     * @param array<string, mixed> $context
     */
    private function homepageLocaleSegment(array $context): ?string
    {
        $segments = $this->storefrontPathSegments($context);
        if ($segments === null) {
            return null;
        }

        $locale = '';
        foreach ($segments as $segment) {
            if ($this->isCurrencySegment($segment)) {
                continue;
            }
            if ($locale === '' && preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1) {
                $locale = $segment;
                continue;
            }

            // Any non-currency, non-leading-locale segment means not homepage.
            return null;
        }

        return $locale;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>|null null when URL/path unavailable
     */
    private function storefrontPathSegments(array $context): ?array
    {
        $url = trim((string)($context['canonical_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)($context['url'] ?? ''));
        }
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        return array_values(array_filter(
            explode('/', trim(rawurldecode($path), '/')),
            static fn(string $segment): bool => $segment !== '',
        ));
    }

    private function isCurrencySegment(string $segment): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', trim($segment));
    }

    private function isSystemDefaultSiteName(string $siteName): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $siteName) ?? $siteName);
        if (in_array($normalized, [
            '',
            'Weline',
            'Weline Framework',
            '韦林',
            '微线框架',
            '默认网站',
            '默认店铺',
            '默认网站 默认店铺',
            'Default Website',
            'Default Store',
            'Default Website Default Store',
        ], true)) {
            return true;
        }

        // Framework placeholders are often concatenated as "默认网站 默认店铺".
        return (bool) preg_match(
            '/^(默认网站|默认店铺|Default Website|Default Store)(?:\s+(默认网站|默认店铺|Default Website|Default Store))*$/iu',
            $normalized
        );
    }

    private function localizedDefault(
        string $source,
        string $englishFallback,
        string $locale,
        bool $isChinese,
    ): string {
        if ($isChinese) {
            return $source;
        }

        $translated = trim($this->translations->translate($source, $locale, ['Weline_Theme']));

        return $translated !== '' && $translated !== $source ? $translated : $englishFallback;
    }

    private function isSystemDefaultTitle(string $title, string $localizedDefault): bool
    {
        return in_array(trim($title), [
            '',
            self::TITLE_ZH,
            self::TITLE_EN,
            $localizedDefault,
            '水墨汉服商城首页',
            '云裳汉服 · Hanfu Atelier',
            'Yunshang Hanfu · Hanfu Atelier',
            'Home',
            'Homepage',
            'Homepage Default',
        ], true);
    }

    /** @param array<string, mixed> $context */
    private function isSystemDefaultDescription(
        string $description,
        array $context,
        string $localizedDefault,
    ): bool {
        $description = trim($description);
        if (in_array($description, [
            '',
            self::DESCRIPTION_ZH,
            self::DESCRIPTION_EN,
            $localizedDefault,
            '云裳汉服国际独立站默认首页',
            // 上一版首页默认（89 字，差审计下限 90）
            '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服与马面裙及传统配饰，覆盖日常出行、节日庆典与礼仪场合；提供形制说明、尺码参考、面料要点与搭配灵感，助你更快选到合身又得体的款式。',
            self::TITLE_ZH . ' - Weline Framework',
            self::TITLE_EN . ' - Weline Framework',
            self::SITE_NAME_ZH . ' - Weline Framework',
            self::SITE_NAME_EN . ' - Weline Framework',
            'Home - Weline Framework',
            'Homepage - Weline Framework',
            'Homepage Default - Weline Framework',
        ], true)) {
            return true;
        }

        $title = trim((string)($context['title'] ?? ''));
        $siteName = trim((string)($context['site_name'] ?? ''));
        if ($title !== '' && $siteName !== '' && $description === $title . ' - ' . $siteName) {
            return true;
        }

        // Framework/composer leftovers: "{brand} - Weline Framework"
        if (preg_match('/ - Weline Framework$/u', $description) === 1) {
            return true;
        }

        return false;
    }
}
