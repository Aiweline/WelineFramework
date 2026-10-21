<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 默认前台主题（theme_id=1）补齐旧组件仍引用的通用颜色 token。
 * 自定义主题（hanfu/daocharms/playinginchina 等）保留自己的色盘覆盖，不在本测试范围。
 */
final class DefaultFrontendLegacyTokenBridgeTest extends TestCase
{
    public function testDefaultFrontendThemeBridgesMissingLegacyColors(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/variables/_colors.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);

        self::assertStringContainsString('默认前台主题兼容别名', $css);
        self::assertStringContainsString('--color-bg-surface: var(--color-surface, var(--color-bg-primary));', $css);
        self::assertStringContainsString('--color-bg-inverse: var(--color-bg-dark, #1f2937);', $css);
        self::assertStringContainsString('--color-border-default: var(--color-border-soft, var(--color-border-light, #d2d2d7));', $css);
        self::assertStringContainsString('--color-border: var(--color-border-default);', $css);
    }
}
