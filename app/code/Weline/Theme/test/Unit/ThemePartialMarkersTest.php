<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 断言默认 Partials 输出包含稳定标记，便于路由/冒烟测试断言 Theme header/footer。
 */
final class ThemePartialMarkersTest extends TestCase
{
    public function testHeaderDefaultPartialContainsMarker(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $this->assertFileExists($path);
        $html = (string)file_get_contents($path);
        $this->assertStringContainsString('theme-partial:header option=default', $html);
    }

    public function testFooterDefaultPartialContainsMarker(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $this->assertFileExists($path);
        $html = (string)file_get_contents($path);
        $this->assertStringContainsString('theme-partial:footer option=default', $html);
        $this->assertStringNotContainsString('getFooter()->getHtml()', $html);
    }
}
