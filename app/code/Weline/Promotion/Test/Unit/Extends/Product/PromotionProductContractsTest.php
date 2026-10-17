<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Extends\Product;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionProductContractsPass().
 */
final class PromotionProductContractsTest extends TestCase
{
    public function testAllPromotionProductContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PromotionThemeDealPriceAdjustmentProvider::testProviderRegistersAndPageUsesAssemblerCampaignFields' => function () {
            $this->assert_PromotionThemeDealPriceAdjustmentProvider_testProviderRegistersAndPageUsesAssemblerCampaignFields();
        },
        ];
    }

private function assert_PromotionThemeDealPriceAdjustmentProvider_testProviderRegistersAndPageUsesAssemblerCampaignFields(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion';
    $provider = $root . '/extends/module/Weline_Product/StorefrontPriceAdjustmentProvider/PromotionThemeDealPriceAdjustmentProvider.php';
    self::assertFileExists($provider);
    $providerSrc = (string) file_get_contents($provider);
    self::assertStringContainsString('StorefrontPriceAdjustmentProviderInterface', $providerSrc);
    self::assertStringContainsString('StorefrontPriceAdjustmentProviderInterface', $providerSrc);
    self::assertStringContainsString('listEligibleCampaignChoices', $providerSrc);
    self::assertStringContainsString('promotion_theme_id', $providerSrc);
    $page = (string) file_get_contents($root . '/Service/PromotionStorefrontPageService.php');
    self::assertStringContainsString('applyStorefrontPricing', $page);
    self::assertStringContainsString('stampCampaignEntryLinks', $page);
    self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $page);
    self::assertStringNotContainsString('applyDealPricing', $page);
    $tpl = (string) file_get_contents($root . '/view/templates/frontend/promotion/index.phtml');
    self::assertStringContainsString('<w:product:card ids="batchProducts"', $tpl);
    self::assertStringNotContainsString('ProductCardRenderer::projectFromProducts', $tpl);
    self::assertStringContainsString('campaign_label', $tpl);
    self::assertStringContainsString('ProductCardRenderer', $tpl);
    $card = dirname($root) . '/Product/view/templates/frontend/partials/product-card.phtml';
    self::assertFileExists($card);
    self::assertStringContainsString('wpc-campaign', (string) file_get_contents($card));
    $module = (string) file_get_contents($root . '/etc/module.php');
    self::assertMatchesRegularExpression("/'version'\\s*=>\\s*'1\\.1\\.\\d+'/", $module);
}
}
