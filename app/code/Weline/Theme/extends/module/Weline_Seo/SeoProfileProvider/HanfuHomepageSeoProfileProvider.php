<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Seo\Interface\SeoProfileProviderInterface;
use Weline\Theme\Helper\SiteBrand;

/**
 * Supplies localized homepage SEO defaults from Website/SiteBrand configuration.
 *
 * Never hardcodes merchant brand social accounts or demo share images.
 * sameAs comes from ThemeSocialSameAsSeoContextService / merchant config only.
 */
final class HanfuHomepageSeoProfileProvider implements SeoProfileProviderInterface
{
    private const PAGE_LABEL_ZH = '首页';
    private const PAGE_LABEL_EN = 'Home';

    public function __construct(
        private readonly TranslationResolverInterface $translations,
        private readonly SiteBrand $siteBrand,
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

        $contextSiteName = trim((string)($context['site_name'] ?? ''));
        $resolvedSiteName = trim($this->siteBrand->resolveFrontendSiteName($contextSiteName));
        $usesFrameworkBrand = $this->isSystemDefaultSiteName($contextSiteName)
            && $this->isSystemDefaultSiteName($resolvedSiteName);
        $merchantSiteName = $usesFrameworkBrand ? '' : ($resolvedSiteName !== '' ? $resolvedSiteName : $contextSiteName);

        $profile = [];
        if ($merchantSiteName !== '' && $this->isSystemDefaultSiteName($contextSiteName)) {
            $profile['site_name'] = $merchantSiteName;
            $organization = [
                'name' => $merchantSiteName,
            ];
            $alternate = trim($this->siteBrand->resolveOrganizationAlternateName($merchantSiteName));
            if ($alternate !== '') {
                $organization['alternateName'] = $alternate;
            }
            $profile['organization'] = $organization;
        }

        $websiteDescription = trim($this->siteBrand->resolveFrontendSiteDescription(
            trim((string)($context['description'] ?? '')),
        ));

        if ($homepageLocale === null) {
            $title = trim((string)($context['title'] ?? ''));
            $description = trim((string)($context['description'] ?? ''));
            if ($merchantSiteName !== ''
                && $title !== ''
                && in_array($description, [
                    $title . ' - ' . $contextSiteName,
                    $title . ' - Weline Framework',
                ], true)
            ) {
                $profile['description'] = $title . ' - ' . $merchantSiteName;
            }

            return $profile;
        }

        $profile['page_type'] = 'home';
        $pageLabel = $this->localizedDefault(
            self::PAGE_LABEL_ZH,
            self::PAGE_LABEL_EN,
            $locale,
            $isChinese,
        );
        $localizedTitle = $merchantSiteName !== ''
            ? ($merchantSiteName . ' | ' . $pageLabel)
            : $pageLabel;
        $localizedDescription = $websiteDescription;

        $layoutFallback = class_exists(\Weline\Seo\Service\Head\SeoPageProfileBag::class)
            ? \Weline\Seo\Service\Head\SeoPageProfileBag::pullLayoutFallback()
            : [];
        $hasLayoutTitle = trim((string) ($layoutFallback['meta_title'] ?? '')) !== '';
        $hasLayoutDescription = trim((string) ($layoutFallback['meta_description'] ?? '')) !== '';

        if (!$hasLayoutTitle && $this->isSystemDefaultTitle((string)($context['title'] ?? ''), $localizedTitle, $merchantSiteName)) {
            $profile['title'] = $localizedTitle;
        }
        if ($localizedDescription !== ''
            && !$hasLayoutDescription
            && $this->isSystemDefaultDescription(
                (string)($context['description'] ?? ''),
                $context,
                $localizedDescription,
            )
        ) {
            $profile['description'] = $localizedDescription;
        }

        return $profile;
    }

    /** @param array<string, mixed> $context */
    private function routeLocaleSegment(array $context): string
    {
        $segments = $this->storefrontPathSegments($context);
        foreach ($segments ?? [] as $segment) {
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

            return null;
        }

        return $locale;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>|null
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

        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $trimmed), static fn(string $s): bool => $s !== ''));
    }

    private function isCurrencySegment(string $segment): bool
    {
        return preg_match('/^[A-Z]{3}$/', $segment) === 1;
    }

    private function isSystemDefaultSiteName(string $siteName): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $siteName) ?? $siteName);
        if ($normalized === '') {
            return true;
        }

        if (in_array($normalized, [
            'Weline',
            'weline',
            'Weline Framework',
            '韦林',
            '微线框架',
            '默认网站',
            '默认店铺',
            '默认网站 默认店铺',
            'Default Website',
            'Default Store',
            'Default Website Default Store',
            '系统默认站点',
        ], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(默认网站|默认店铺|Default Website|Default Store|系统默认站点)(?:\s+(默认网站|默认店铺|Default Website|Default Store|系统默认站点))*$/iu',
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

    private function isSystemDefaultTitle(string $title, string $localizedDefault, string $merchantSiteName): bool
    {
        $title = trim($title);
        $candidates = [
            '',
            $localizedDefault,
            self::PAGE_LABEL_ZH,
            self::PAGE_LABEL_EN,
            'Home',
            'Homepage',
            'Homepage Default',
            '水墨汉服商城首页',
            'Ink-Wash Hanfu Boutique',
        ];
        if ($merchantSiteName !== '') {
            $candidates[] = $merchantSiteName;
            $candidates[] = $merchantSiteName . ' | ' . self::PAGE_LABEL_ZH;
            $candidates[] = $merchantSiteName . ' | ' . self::PAGE_LABEL_EN;
            $candidates[] = $merchantSiteName . ' | 水墨汉服商城首页';
            $candidates[] = $merchantSiteName . ' | Ink-Wash Hanfu Boutique';
        }

        if (in_array($title, $candidates, true)) {
            return true;
        }

        return (bool) preg_match(
            '/^.+\s\|\s(?:首页|Home|水墨汉服商城首页|Ink-Wash Hanfu Boutique|متجر هانفو بأسلوب الحبر الصيني)$/u',
            $title
        );
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
            $localizedDefault,
            'Weline Framework',
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

        if (preg_match('/ - Weline Framework$/u', $description) === 1) {
            return true;
        }

        return false;
    }
}
