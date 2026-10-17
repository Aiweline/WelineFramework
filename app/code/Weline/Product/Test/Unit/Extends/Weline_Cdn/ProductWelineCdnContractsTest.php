<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Weline_Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Cdn\ProductHeatUrls;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductWeline_CdnContractsPass().
 */
final class ProductWelineCdnContractsTest extends TestCase
{
    public function testAllProductWeline_CdnContractsPass(): void
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
            'ProductHeatUrls::testClassImplementsWarmupProvider' => function () {
            $this->assert_ProductHeatUrls_testClassImplementsWarmupProvider();
        },
            'ProductHeatUrls::testExecuteUsesBestSellerCardsAndAbsoluteUrls' => function () {
            $this->assert_ProductHeatUrls_testExecuteUsesBestSellerCardsAndAbsoluteUrls();
        },
        ];
    }

private function assert_ProductHeatUrls_testClassImplementsWarmupProvider(): void
{
    $this->assertTrue(is_subclass_of(ProductHeatUrls::class, \Weline\Cdn\Api\WarmupProviderInterface::class));
    $this->assertSame(48, ProductHeatUrls::TOP_N);
    $this->assertSame('Weline_Product', ProductHeatUrls::SOURCE_MODULE);
    $this->assertSame('商品热度 PDP', ProductHeatUrls::UI_LABEL);
}

private function assert_ProductHeatUrls_testExecuteUsesBestSellerCardsAndAbsoluteUrls(): void
{
    $this->assertTrue(method_exists(ProductHeatUrls::class, 'execute'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cdn/ProductHeatUrls.php');
    $this->assertStringContainsString('bestSellerCards', $src);
    $this->assertStringContainsString('WarmupLocaleUrlExpander', $src);
    $this->assertStringContainsString('RequestContext::setWelineWebsiteId', $src);
    $this->assertStringContainsString("'/product/'", $src);
    $this->assertStringContainsString('站点已启用语种', $src);
    $this->assertStringNotContainsString('Visitor', $src);
}
}
