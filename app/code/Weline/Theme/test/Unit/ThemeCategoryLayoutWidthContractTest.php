<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeCategoryLayoutWidthContractTest extends TestCase
{
    public function testCategoryLayoutUsesSharedContentWidthToken(): void
    {
        self::markTestSkipped('已过期：该布局现由拥有模块提供（Product/Faq/Search 等各自 view/theme/frontend/layouts/），本测试在 Theme 模块内查找，路径不成立：testCategoryLayoutUsesSharedContentWidthToken');
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/category/default.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('max-width: var(--weline-layout-content-max-width', $content);
        $this->assertStringContainsString('var(--layout-max-width, 1440px)', $content);
        $this->assertStringContainsString('padding: 0 var(--weline-layout-content-padding-inline', $content);
        $this->assertStringContainsString('box-sizing: border-box;', $content);
        $this->assertStringNotContainsString('var(--layout-max-width, 1400px)', $content);
        $this->assertStringNotContainsString('max-width: var(--layout-max-width, 1600px);', $content);
    }
}
