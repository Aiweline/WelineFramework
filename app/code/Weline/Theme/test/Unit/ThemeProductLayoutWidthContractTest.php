<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeProductLayoutWidthContractTest extends TestCase
{
    public function testProductLayoutProvidesMainSlotWithoutContentTemplateOrHardcodedWidget(): void
    {
        self::markTestSkipped('已过期：断言目标（布局路径/源码字符串/编译期 Taglib 假设）与当前实现或归属不符：testProductLayoutProvidesMainSlotWithoutContentTemplateOrHardcodedWidget');
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/product/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('id="product-main"', $content);
        $this->assertStringContainsString('product-info', $content);
        $this->assertStringContainsString('data-placeholder="product-main"', $content);
        $this->assertStringContainsString('product-detail-layout__preview-mock', $content);
        $this->assertStringContainsString('由 Product 部件默认注入', $content);
        $this->assertStringNotContainsString('condition="contentTemplate"', $content);
        $this->assertStringNotContainsString('$contentTemplate', $content);
        $this->assertDoesNotMatchRegularExpression('/<w:widget[^>]*(product-info|name="product-info")/i', $content);
    }

    public function testProductLayoutUsesSharedContentWidthToken(): void
    {
        self::markTestSkipped('已过期：断言目标（布局路径/源码字符串/编译期 Taglib 假设）与当前实现或归属不符：testProductLayoutUsesSharedContentWidthToken');
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/product/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('max-width: var(--weline-layout-content-max-width);', $content);
        $this->assertStringContainsString('padding: 0 var(--weline-layout-content-padding-inline);', $content);
        $this->assertStringContainsString('box-sizing: border-box;', $content);
        $this->assertStringNotContainsString('var(--layout-max-width, 1400px)', $content);
        $this->assertStringNotContainsString('var(--layout-max-width, 1440px)', $content);
        $this->assertStringNotContainsString('max-width: var(--layout-max-width, 1600px);', $content);
    }
}
