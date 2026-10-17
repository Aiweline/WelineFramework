<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\Storefront;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductStorefrontContractsPass().
 */
final class ProductStorefrontContractsTest extends TestCase
{
    public function testAllProductStorefrontContractsPass(): void
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
            'StorefrontOfferPriceAssembler::testSpiAndAssemblerSurfaceExist' => function () {
            $this->assert_StorefrontOfferPriceAssembler_testSpiAndAssemblerSurfaceExist();
        },
        ];
    }

private function assert_StorefrontOfferPriceAssembler_testSpiAndAssemblerSurfaceExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    self::assertFileExists($root . '/Api/StorefrontOfferPriceAssemblerInterface.php');
    self::assertFileExists($root . '/Api/Storefront/StorefrontPriceAdjustmentProviderInterface.php');
    self::assertFileExists($root . '/Api/Data/StorefrontOfferPriceView.php');
    self::assertFileExists($root . '/Service/Storefront/StorefrontOfferPriceAssembler.php');
    self::assertFileExists($root . '/Service/Storefront/StorefrontPriceAdjustmentProviderRegistry.php');
    $extends = (string) file_get_contents($root . '/extends.php');
    self::assertStringContainsString('StorefrontPriceAdjustmentProvider', $extends);
    $module = (string) file_get_contents($root . '/etc/module.php');
    self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $module);
    self::assertStringContainsString('1.0.157', $module);
    $catalog = (string) file_get_contents($root . '/Service/StorefrontCatalogViewService.php');
    self::assertStringContainsString('applyUnifiedStorefrontPricing', $catalog);
    self::assertStringContainsString('catalog_price_minor', $catalog);
    $variant = (string) file_get_contents($root . '/Service/StorefrontVariantSelectionService.php');
    self::assertStringContainsString("'catalog_price_minor'", $variant);
    self::assertStringContainsString("'compare_at_minor'", $variant);
    self::assertStringContainsString("'has_deal'", $variant);
    $widget = (string) file_get_contents($root . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('catalog_price_minor', $widget);
    self::assertStringContainsString('compare_at_minor', $widget);
    self::assertStringContainsString('campaign_label', $widget);
    self::assertStringContainsString('has_deal', $widget);
    self::assertStringNotContainsString('1.08 + (($productId % 4) * 0.04)', $widget);
    $recommended = (string) file_get_contents($root . '/view/templates/frontend/widgets/recommended-products.phtml');
    self::assertStringContainsString('<w:product:card ids="products"', $recommended);
    self::assertStringNotContainsString('ProductCardRenderer::projectFromProducts', $recommended);
    $card = (string) file_get_contents($root . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('wpc-campaign', $card);
    self::assertStringContainsString('wpc-price-was', $card);
    $category = (string) file_get_contents($root . '/view/templates/frontend/category/index.phtml');
    self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $category);
    self::assertStringNotContainsString('<w:product:card', $category);
    $pdp = (string) file_get_contents($root . '/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $pdp);
    self::assertStringContainsString('catalog_price_minor', $pdp);
    self::assertStringContainsString('product-price-campaign', $pdp);
    self::assertStringContainsString('function priceFromOffer(', $pdp);
    self::assertStringContainsString('renderPrice(offer)', $pdp);
    self::assertStringNotContainsString('renderPrice(offer.unit_price_minor', $pdp);
    self::assertStringNotContainsString('PromotionStorefrontActiveDealResolver', $pdp);
}
}
