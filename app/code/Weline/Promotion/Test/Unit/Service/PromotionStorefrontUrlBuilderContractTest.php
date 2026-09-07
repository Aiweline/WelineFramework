<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 活动 Tab / 入口 / campaign 链接必须走框架 Url::getFrontendUrl，禁止硬编码 /promotion 拼接。
 */
final class PromotionStorefrontUrlBuilderContractTest extends TestCase
{
    public function testThemeServiceBuildsStorefrontUrlsViaFrameworkFrontendUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('use Weline\\Framework\\Http\\Url;', $src);
        self::assertStringContainsString('private readonly Url $url', $src);
        self::assertStringContainsString('public function storefrontUrl(string $pageSlug = \'\'): string', $src);
        self::assertStringContainsString("getFrontendUrl('promotion')", $src);
        self::assertStringContainsString("getFrontendUrl('promotion/' . rawurlencode(\$pageSlug))", $src);
        self::assertStringContainsString("'url' => \$this->storefrontUrl()", $src);
        self::assertStringContainsString("'url' => \$this->storefrontUrl(\$slug)", $src);
        self::assertStringContainsString("'action_url' => \$this->storefrontUrl(\$slug)", $src);
        self::assertStringContainsString("'campaign_url' => \$slug !== '' ? \$this->storefrontUrl(\$slug) : ''", $src);
        self::assertStringContainsString("'storefront_url' => \$this->storefrontUrl(", $src);
        self::assertStringNotContainsString("'url' => '/promotion'", $src);
        self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$slug)", $src);
    }

    public function testPageServiceDelegatesListAndCampaignUrlsToThemeStorefrontUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('$this->storefrontUrl($pageType)', $src);
        self::assertStringContainsString("'list_url' => \$this->storefrontUrl()", $src);
        self::assertStringContainsString('return $this->themeService->storefrontUrl($pageSlug);', $src);
        self::assertStringNotContainsString("'list_url' => '/promotion'", $src);
        self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$pageType)", $src);
    }

    public function testFrontendTemplateFallbacksUseGetFrontendUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/promotion/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("getFrontendUrl('promotion')", $src);
        self::assertStringContainsString("getFrontendUrl('promotion/deals')", $src);
        self::assertStringContainsString("getFrontendUrl('promotion/sale')", $src);
        self::assertStringNotContainsString("?? '/promotion'", $src);
        self::assertStringNotContainsString("?? '/promotion/deals'", $src);
        self::assertStringNotContainsString("?? '/promotion/sale'", $src);
    }

    public function testPriceAdjustmentProviderUsesThemeStorefrontUrlFallback(): void
    {
        $path = dirname(__DIR__, 3)
            . '/extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/PromotionThemeDealPriceAdjustmentProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('$this->themeService->storefrontUrl($pageSlug)', $src);
        self::assertStringNotContainsString("'/promotion/' . rawurlencode(\$pageSlug)", $src);
    }
}
