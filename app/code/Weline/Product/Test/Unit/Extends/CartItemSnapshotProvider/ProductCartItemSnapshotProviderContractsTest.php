<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\CartItemSnapshotProvider;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductCartItemSnapshotProviderContractsPass().
 */
final class ProductCartItemSnapshotProviderContractsTest extends TestCase
{
    public function testAllProductCartItemSnapshotProviderContractsPass(): void
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
            'CurrencyUnavailableSnapshot::testMissingFxKeepsProductAndBlocksPurchase' => function () {
            $this->assert_CurrencyUnavailableSnapshot_testMissingFxKeepsProductAndBlocksPurchase();
        },
        ];
    }

private function assert_CurrencyUnavailableSnapshot_testMissingFxKeepsProductAndBlocksPurchase(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'fx_unavailable' => true", $source);
    self::assertStringContainsString('currencyUnavailableMessage', $source);
    self::assertStringContainsString("'currency_unavailable' => '1'", $source);
    self::assertStringContainsString('当前货币暂不可用', $source);
    self::assertStringNotContainsString("if (\$priced['unresolved']) {\n            return \$this->unavailable(", $source);
    $catalog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString("'currency_unavailable' => !empty(\$offer['currency_unavailable'])", $catalog);
    $variants = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontVariantSelectionService.php');
    self::assertStringContainsString("'currency_unavailable' => !empty(\$offer['currency_unavailable'])", $variants);
    $card = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/view/templates/frontend/partials/product-card.phtml');
    self::assertStringContainsString('$currencyUnavailable', $card);
    self::assertStringContainsString('aria-disabled="true">@lang{当前货币暂不可用}', $card);
}
}
