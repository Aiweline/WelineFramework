<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Extends\Product;

use PHPUnit\Framework\TestCase;

final class PromotionThemeDealPriceAdjustmentProviderContractTest extends TestCase
{
    public function testProviderRegistersAndPageUsesAssemblerCampaignFields(): void
    {
        $root = dirname(__DIR__, 4);
        $provider = $root . '/extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/PromotionThemeDealPriceAdjustmentProvider.php';
        self::assertFileExists($provider);
        $providerSrc = (string) file_get_contents($provider);
        self::assertStringContainsString('StorefrontPriceAdjustmentProviderInterface', $providerSrc);
        self::assertStringContainsString('resolveStorefrontCampaignMeta', $providerSrc);

        $page = (string) file_get_contents($root . '/Service/PromotionStorefrontPageService.php');
        self::assertStringContainsString('applyStorefrontPricing', $page);
        self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $page);
        self::assertStringNotContainsString('applyDealPricing', $page);

        $tpl = (string) file_get_contents($root . '/view/templates/frontend/promotion/index.phtml');
        self::assertStringContainsString('<w:product:card', $tpl);
        self::assertStringContainsString('campaign_label', $tpl);
        self::assertStringContainsString('ProductCardRenderer', $tpl);
        $card = dirname($root) . '/Product/view/templates/frontend/partials/product-card.phtml';
        self::assertFileExists($card);
        self::assertStringContainsString('wpc-campaign', (string) file_get_contents($card));

        $module = (string) file_get_contents($root . '/etc/module.php');
        self::assertStringContainsString('1.1.13', $module);
    }
}
