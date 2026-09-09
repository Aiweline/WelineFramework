<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontScopeContractTest extends TestCase
{
    public function testStorefrontPageServiceUsesScopeResolverAndNavTabsForUrls(): void
    {
        $storefront = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        $scopeResolver = dirname(__DIR__, 3) . '/Service/PromotionScopeResolver.php';
        $frontendTemplate = dirname(__DIR__, 3) . '/view/templates/frontend/promotion/index.phtml';

        self::assertFileExists($storefront);
        self::assertFileExists($scopeResolver);
        self::assertFileExists($frontendTemplate);

        $storefrontContent = (string)file_get_contents($storefront);
        $scopeContent = (string)file_get_contents($scopeResolver);
        $templateContent = (string)file_get_contents($frontendTemplate);

        self::assertStringContainsString('PromotionScopeResolver', $storefrontContent);
        self::assertStringContainsString('$this->scopeResolver->resolve()', $storefrontContent);
        self::assertStringContainsString('slugUrlsFromNavTabs', $storefrontContent);
        self::assertStringContainsString("'store_code' =>", $storefrontContent);
        self::assertStringContainsString('CartScopeResolverInterface', $scopeContent);
        self::assertStringContainsString('resolveTrustedScope', $scopeContent);
        self::assertStringNotContainsString('今日精选', $templateContent);
        self::assertStringNotContainsString('主题陈列', $templateContent);
    }

    public function testThemeServiceFiltersByScopeMatcherOnStorefront(): void
    {
        $themeService = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        $content = (string)file_get_contents($themeService);

        self::assertStringContainsString('PromotionActivityThemeScopeMatcher::matches', $content);
        self::assertStringContainsString('PromotionActivityThemeScopeMatcher::dedupeByPageSlug', $content);
        self::assertStringContainsString('$this->scopeResolver->resolve()', $content);
        self::assertStringContainsString("'nav_label' => '今日特价'", $content);
        self::assertStringNotContainsString("'nav_label' => '今日精选'", $content);
        self::assertStringContainsString('migrateLegacyDealsFeaturedBranding', $content);
        self::assertStringContainsString("__('今日特价')", $content);
        self::assertStringContainsString('findActiveDealOverlaps', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PromotionThemeProductService.php',
        ));
        self::assertStringContainsString('listEligibleDealsForProduct', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PromotionStorefrontActiveDealResolver.php',
        ));
        self::assertStringContainsString('force_overlap', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php',
        ));
        self::assertStringContainsString('needs_overlap_confirm', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/promotion/theme/form.phtml',
        ));
    }
}
