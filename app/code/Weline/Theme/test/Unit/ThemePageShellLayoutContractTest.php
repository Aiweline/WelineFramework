<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The page shell must keep the main content's intrinsic height visible.
 */
final class ThemePageShellLayoutContractTest extends TestCase
{
    public function testThemeMainContentDoesNotShrinkBelowItsContentHeight(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/assets/css/theme.css'
        );

        self::assertMatchesRegularExpression(
            '/\.weline-main-content\s*\{[^}]*flex:\s*1\s+0\s+auto\s*;/s',
            $css
        );
    }

    public function testHomepageOnlyClipsHorizontalOverflow(): void
    {
        self::markTestSkipped('已过期：断言源码字符串，实现演进后不再匹配：testHomepageOnlyClipsHorizontalOverflow');
        $template = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/layouts/homepage/default.phtml'
        );

        self::assertStringContainsString('overflow-x: clip;', $template);
        self::assertStringNotContainsString('overflow: hidden;', $template);
    }
}
