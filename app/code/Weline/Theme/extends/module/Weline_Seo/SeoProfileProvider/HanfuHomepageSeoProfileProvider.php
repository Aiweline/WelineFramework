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
    private const DESCRIPTION_ZH = '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服、马面裙与传统配饰，服务日常、节庆与礼仪场景。';
    private const DESCRIPTION_EN = 'Discover Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories for everyday wear, festivals, and ceremonies.';

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

            return $profile;
        }

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

        return $profile;
    }

    /** @param array<string, mixed> $context */
    private function routeLocaleSegment(array $context): string
    {
        $url = trim((string)($context['canonical_url'] ?? ''));
        if ($url === '') {
            $url = trim((string)($context['url'] ?? ''));
        }
        $path = $url !== '' ? parse_url($url, PHP_URL_PATH) : null;
        if (!is_string($path)) {
            return '';
        }
        $first = explode('/', trim(rawurldecode($path), '/'))[0] ?? '';

        return preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $first) === 1
            ? $first
            : '';
    }

    private function isSystemDefaultSiteName(string $siteName): bool
    {
        return in_array(trim($siteName), [
            '',
            'Weline',
            'Weline Framework',
            '韦林',
            '微线框架',
            '默认网站',
            'Default Website',
        ], true);
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

    /**
     * Returns an empty string for the unprefixed homepage, its locale segment
     * for a locale homepage, and null for every non-homepage URL.
     *
     * @param array<string, mixed> $context
     */
    private function homepageLocaleSegment(array $context): ?string
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
        $segment = trim(rawurldecode($path), '/');
        if ($segment === '') {
            return '';
        }

        return preg_match('/^[a-z]{2}(?:[-_][a-z]{2,4}){1,2}$/i', $segment) === 1
            ? $segment
            : null;
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
            self::TITLE_ZH . ' - Weline Framework',
            self::TITLE_EN . ' - Weline Framework',
            'Home - Weline Framework',
            'Homepage - Weline Framework',
            'Homepage Default - Weline Framework',
        ], true)) {
            return true;
        }

        $title = trim((string)($context['title'] ?? ''));
        $siteName = trim((string)($context['site_name'] ?? ''));

        return $title !== ''
            && $siteName !== ''
            && $description === $title . ' - ' . $siteName;
    }
}
