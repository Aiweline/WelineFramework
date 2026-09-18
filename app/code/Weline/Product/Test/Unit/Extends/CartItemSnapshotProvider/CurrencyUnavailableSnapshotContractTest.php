<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\CartItemSnapshotProvider;

use PHPUnit\Framework\TestCase;

final class CurrencyUnavailableSnapshotContractTest extends TestCase
{
    public function testMissingFxKeepsProductAndBlocksPurchase(): void
    {
        $path = dirname(__DIR__, 4)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("'fx_unavailable' => true", $source);
        self::assertStringContainsString('currencyUnavailableMessage', $source);
        self::assertStringContainsString("'currency_unavailable' => '1'", $source);
        self::assertStringContainsString('当前货币暂不可用', $source);
        self::assertStringNotContainsString(
            "if (\$priced['unresolved']) {\n            return \$this->unavailable(",
            $source,
        );

        $catalog = (string) file_get_contents(dirname(__DIR__, 4) . '/Service/StorefrontProductWidgetCatalog.php');
        self::assertStringContainsString("'currency_unavailable' => !empty(\$offer['currency_unavailable'])", $catalog);
        $variants = (string) file_get_contents(dirname(__DIR__, 4) . '/Service/StorefrontVariantSelectionService.php');
        self::assertStringContainsString("'currency_unavailable' => !empty(\$offer['currency_unavailable'])", $variants);
        $card = (string) file_get_contents(dirname(__DIR__, 4) . '/view/templates/frontend/partials/product-card.phtml');
        self::assertStringContainsString('$currencyUnavailable', $card);
        self::assertStringContainsString('aria-disabled="true">@lang{当前货币暂不可用}', $card);
    }
}
