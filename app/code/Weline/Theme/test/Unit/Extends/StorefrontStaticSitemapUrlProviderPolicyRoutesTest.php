<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Extends\Module\Weline_Seo\SitemapUrlProvider\StorefrontStaticSitemapUrlProvider;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * UT-StorefrontStaticSitemap-policy-routes：Theme 静态 Provider 须收录 policy/*（含 accessibility）。
 */
final class StorefrontStaticSitemapUrlProviderPolicyRoutesTest extends TestCase
{
    public function testGetUrlsIncludesPolicyAccessibilityAndPrivacyRoutes(): void
    {
        $catalog = new class implements WebsiteCatalogInterface {
            public function defaultWebsiteId(): int
            {
                return 1;
            }

            public function all(): array
            {
                return [
                    new WebsiteSummary(1, 'Shop', 'default', 'https://shop.test'),
                ];
            }

            public function count(): int
            {
                return 1;
            }
        };

        $provider = new StorefrontStaticSitemapUrlProvider($catalog);
        $urls = $provider->getUrlsForWebsite(1);

        $byKey = [];
        foreach ($urls as $row) {
            $byKey[(string) ($row['url_key'] ?? '')] = $row;
        }

        self::assertArrayHasKey('theme-static:policy/accessibility', $byKey);
        self::assertArrayHasKey('theme-static:policy/privacy', $byKey);
        self::assertSame('https://shop.test/policy/accessibility', $byKey['theme-static:policy/accessibility']['loc']);
        self::assertSame('0.5', $byKey['theme-static:policy/accessibility']['priority']);
        self::assertSame('yearly', $byKey['theme-static:policy/accessibility']['changefreq']);
        self::assertSame('policy', $byKey['theme-static:policy/accessibility']['metadata']['page_type'] ?? null);

        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Seo/SitemapUrlProvider/StorefrontStaticSitemapUrlProvider.php'
        );
        self::assertStringContainsString("'path' => 'policy/accessibility'", $source);
        self::assertStringContainsString("'path' => 'policy/privacy'", $source);
        self::assertSame('storefront_static', $provider->getScope());
        self::assertSame('Weline_Theme', $provider->getModule());
    }
}
