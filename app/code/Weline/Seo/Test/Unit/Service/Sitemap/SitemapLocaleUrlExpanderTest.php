<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Sitemap;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\I18n\Service\Seo\LocalizedUrlBuilder;
use Weline\Seo\Api\Sitemap\Data\Website;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Seo\Service\Sitemap\SitemapLocaleUrlExpander;
use Weline\Websites\Model\WebsiteLanguage;

final class SitemapLocaleUrlExpanderTest extends TestCase
{
    public function testNonExpandableProviderIsPassthroughWithoutSyntheticAlternates(): void
    {
        $expander = $this->makeExpander();
        $provider = $this->provider(false);
        $website = new Website(0, 'Default', 'default', 'https://www.example.com');
        $raw = [[
            'url_key' => 'blog-1',
            'loc' => 'https://www.example.com/blog/hello',
            'priority' => '0.5',
        ]];

        $result = $expander->expand($provider, $website, $raw);

        self::assertSame(1, $result['expanded_url_count']);
        self::assertCount(1, $result['urls']);
        self::assertSame('https://www.example.com/blog/hello', $result['urls'][0]['loc']);
        self::assertArrayNotHasKey('alternates', $result['urls'][0]['metadata'] ?? []);
    }

    public function testExpandableProviderEmitsOneRowPerLocaleWithDefaultBarePath(): void
    {
        $expander = $this->makeExpander(
            siteCodes: ['en_US', 'zh_Hans_CN', 'fr_FR'],
            catalogCodes: ['en_US', 'zh_Hans_CN', 'fr_FR'],
        );
        $provider = $this->provider(true);
        $website = new Website(0, 'Default', 'default', 'https://www.example.com');
        $raw = [[
            'url_key' => 'theme-static:policy/accessibility',
            'loc' => 'https://www.example.com/policy/accessibility',
            'changefreq' => 'yearly',
            'priority' => '0.5',
            'metadata' => ['page_type' => 'policy'],
        ]];

        $result = $expander->expand($provider, $website, $raw);

        self::assertSame(3, $result['expanded_url_count']);
        $byLocale = [];
        foreach ($result['urls'] as $row) {
            $byLocale[(string)$row['locale']] = $row;
        }
        self::assertArrayHasKey('en_US', $byLocale);
        self::assertArrayHasKey('zh_Hans_CN', $byLocale);
        self::assertArrayHasKey('fr_FR', $byLocale);
        self::assertSame('https://www.example.com/policy/accessibility', $byLocale['en_US']['loc']);
        self::assertSame('https://www.example.com/zh_Hans_CN/policy/accessibility', $byLocale['zh_Hans_CN']['loc']);
        self::assertSame('https://www.example.com/fr_FR/policy/accessibility', $byLocale['fr_FR']['loc']);
        self::assertSame(
            'https://www.example.com/policy/accessibility',
            $byLocale['en_US']['metadata']['alternates']['x-default'],
        );
        self::assertSame(
            'https://www.example.com/fr_FR/policy/accessibility',
            $byLocale['en_US']['metadata']['alternates']['fr_FR'],
        );
        self::assertSame('policy', $byLocale['fr_FR']['metadata']['page_type']);
    }

    public function testDeclaredLocaleLocIsPreservedAndMissingLocalesAreFilled(): void
    {
        $expander = $this->makeExpander(
            siteCodes: ['en_US', 'fr_FR'],
            catalogCodes: ['en_US', 'fr_FR'],
        );
        $provider = $this->provider(true);
        $website = new Website(0, 'Default', 'default', 'https://www.example.com');
        $raw = [[
            'url_key' => 'product:1:store:1',
            'locale' => 'en_US',
            'loc' => 'https://www.example.com/product/custom-en-slug',
        ]];

        $result = $expander->expand($provider, $website, $raw);
        $byLocale = [];
        foreach ($result['urls'] as $row) {
            $byLocale[(string)$row['locale']] = $row;
        }

        self::assertSame('https://www.example.com/product/custom-en-slug', $byLocale['en_US']['loc']);
        self::assertSame('https://www.example.com/fr_FR/product/custom-en-slug', $byLocale['fr_FR']['loc']);
    }

    public function testInactiveSiteLocaleIsSkippedWithWarning(): void
    {
        $expander = $this->makeExpander(
            siteCodes: ['en_US', 'xx_XX'],
            catalogCodes: ['en_US'],
        );
        $provider = $this->provider(true);
        $website = new Website(0, 'Default', 'default', 'https://www.example.com');
        $raw = [[
            'url_key' => 'theme-static:about',
            'loc' => 'https://www.example.com/about',
        ]];

        $result = $expander->expand($provider, $website, $raw);

        self::assertSame(1, $result['expanded_url_count']);
        self::assertSame('en_US', $result['urls'][0]['locale']);
        self::assertNotEmpty($result['warnings']);
    }

    /**
     * @param list<string> $siteCodes
     * @param list<string> $catalogCodes
     */
    private function makeExpander(
        array $siteCodes = ['en_US'],
        array $catalogCodes = ['en_US'],
    ): SitemapLocaleUrlExpander {
        $catalog = $this->createMock(LocaleCatalogInterface::class);
        $catalog->method('installed')->willReturn(array_map(
            static fn (string $code): array => ['code' => $code],
            $catalogCodes,
        ));

        $repository = $this->createMock(LocaleRepositoryInterface::class);
        $repository->method('resolveCode')->willReturnCallback(
            static fn (string $code, string $fallback): string => $code !== '' ? $code : $fallback,
        );

        $websiteLanguage = $this->createMock(WebsiteLanguage::class);
        $websiteLanguage->method('getWebsiteLanguageCodes')->willReturn($siteCodes);

        return new SitemapLocaleUrlExpander(
            new LocalizedUrlBuilder(),
            $catalog,
            $repository,
            $websiteLanguage,
        );
    }

    private function provider(bool $expandable): SitemapUrlProviderInterface
    {
        $provider = $this->createMock(SitemapUrlProviderInterface::class);
        $provider->method('supportsSiteLanguagePathExpansion')->willReturn($expandable);
        $provider->method('getModule')->willReturn($expandable ? 'Weline_Theme' : 'Weline_Blog');
        $provider->method('getScope')->willReturn($expandable ? 'storefront_static' : 'blog_article');

        return $provider;
    }
}
