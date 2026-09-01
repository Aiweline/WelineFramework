<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Greenfield debt: former PreviewFlow integration mocked getWidgetByLayoutId.
 * Now asserts update-config is scoped node_uid only (source contract).
 */
final class ThemeEditorPreviewFlowTest extends TestCase
{
    public function testUpdateConfigIsScopedNodeUidOnly(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        $start = strpos($source, 'function postUpdateConfig(');
        self::assertNotFalse($start);
        $next = strpos($source, "\n    public function ", $start + 10);
        $body = $next === false
            ? substr($source, (int)$start)
            : substr($source, (int)$start, $next - (int)$start);

        self::assertStringContainsString('ThemeScopedLayoutWriteService', $body);
        self::assertStringContainsString('resolveNodeUidFromEditorRequest', $body);
        self::assertStringContainsString('缺少布局节点 UID', $body);
        self::assertStringContainsString('buildPreviewHtmlForWidget', $body);
        self::assertStringNotContainsString('getWidgetByLayoutId', $body);
        self::assertStringNotContainsString('layoutService->updateWidgetConfig', $body);
    }
}
