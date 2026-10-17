<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingI18nContractsPass().
 */
final class ShippingI18nContractsTest extends TestCase
{
    public function testAllShippingI18nContractsPass(): void
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
            'HindiShippingHintCsv::testHindiCsvTranslatesCheckoutFreightHint' => function () {
            $this->assert_HindiShippingHintCsv_testHindiCsvTranslatesCheckoutFreightHint();
        },
            'HindiShippingHintCsv::testPreviewHintReturnsSourceKeysForStorefrontWidgetI18n' => function () {
            $this->assert_HindiShippingHintCsv_testPreviewHintReturnsSourceKeysForStorefrontWidgetI18n();
        },
        ];
    }

private function assert_HindiShippingHintCsv_testHindiCsvTranslatesCheckoutFreightHint(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = (string) file_get_contents($path);
    self::assertStringContainsString('运费以结算页为准,"शिपिंग शुल्क चेकआउट पर तय होगा"', $hi);
    self::assertStringContainsString('重货,"भारी माल"', $hi);
    self::assertDoesNotMatchRegularExpression('/^本商品使用重货配送方案，运费以结算页为准,本商品使用重货配送方案/m', $hi);
    self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
}

private function assert_HindiShippingHintCsv_testPreviewHintReturnsSourceKeysForStorefrontWidgetI18n(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Product/StorefrontShippingProfileCatalogProvider/ShippingServiceProfileCatalogProvider.php');
    self::assertStringContainsString("'note' => '运费以结算页为准'", $src);
    self::assertStringNotContainsString("__('运费以结算页为准')", $src);
}
}
