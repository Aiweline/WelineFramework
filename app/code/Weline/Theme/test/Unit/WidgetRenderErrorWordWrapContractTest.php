<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DEV 部件渲染错误提示须允许长文案换行，避免撑破布局。
 */
final class WidgetRenderErrorWordWrapContractTest extends TestCase
{
    public function testWidgetRenderErrorInlineStyleContainsWordWrap(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/SlotRendererService.php'
        );
        self::assertNotSame('', $source);

        preg_match_all(
            '/class="widget-render-error"\s+style="([^"]*)"/',
            $source,
            $matches
        );
        self::assertGreaterThanOrEqual(2, count($matches[1]), 'expected ≥2 widget-render-error style attributes');

        foreach ($matches[1] as $style) {
            self::assertStringContainsString(
                'word-wrap: break-word',
                $style,
                'widget-render-error style must include word-wrap: break-word'
            );
        }
    }
}
