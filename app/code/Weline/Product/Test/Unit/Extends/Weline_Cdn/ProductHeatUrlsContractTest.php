<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Weline_Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Product\Extends\Module\Weline_Cdn\ProductHeatUrls;

final class ProductHeatUrlsContractTest extends TestCase
{
    public function testClassImplementsWarmupProvider(): void
    {
        $this->assertTrue(is_subclass_of(ProductHeatUrls::class, \Weline\Cdn\Api\WarmupProviderInterface::class));
        $this->assertSame(48, ProductHeatUrls::TOP_N);
        $this->assertSame('Weline_Product', ProductHeatUrls::SOURCE_MODULE);
        $this->assertSame('商品热度 PDP', ProductHeatUrls::UI_LABEL);
    }

    public function testExecuteUsesBestSellerCardsAndAbsoluteUrls(): void
    {
        $this->assertTrue(method_exists(ProductHeatUrls::class, 'execute'));
        $src = (string)file_get_contents(dirname(__DIR__, 4) . '/extends/module/Weline_Cdn/ProductHeatUrls.php');
        $this->assertStringContainsString('bestSellerCards', $src);
        $this->assertStringContainsString('WarmupLocaleUrlExpander', $src);
        $this->assertStringContainsString('RequestContext::setWelineWebsiteId', $src);
        $this->assertStringContainsString("'/product/'", $src);
        $this->assertStringContainsString('站点已启用语种', $src);
        $this->assertStringNotContainsString('Visitor', $src);
    }
}
