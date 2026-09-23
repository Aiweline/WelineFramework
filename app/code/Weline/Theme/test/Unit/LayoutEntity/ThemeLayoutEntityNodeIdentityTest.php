<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer;
require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php';
final class ThemeLayoutEntityNodeIdentityTest extends TestCase
{
    public function testSameNodeIsIsolatedByThemeScopeVersionAndSource(): void
    {
        $base = ['uid', 'page', 1, 'scope', 'identity/r1', 'en_US'];
        $key = ThemeLayoutEntityWidgetRenderer::requestNodeKey(...$base);
        foreach ([1 => 'chrome', 2 => 2, 3 => 'other', 4 => 'identity/r2', 5 => 'zh_Hans_CN'] as $index => $value) {
            $other = $base;
            $other[$index] = $value;
            self::assertNotSame($key, ThemeLayoutEntityWidgetRenderer::requestNodeKey(...$other));
        }
        self::assertSame($key, ThemeLayoutEntityWidgetRenderer::requestNodeKey(...$base));
    }
}
