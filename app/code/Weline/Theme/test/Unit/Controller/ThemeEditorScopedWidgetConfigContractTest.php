<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Greenfield: widget-config / save-widget-config must resolve by scoped node_uid.
 */
final class ThemeEditorScopedWidgetConfigContractTest extends TestCase
{
    public function testWidgetConfigEndpointsPreferNodeUidHelpers(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        self::assertStringContainsString('function resolveNodeUidFromEditorRequest', $source);
        self::assertStringContainsString('function resolveScopedDraftNode', $source);
        self::assertStringContainsString('function ensureScopedNodeI18nInstance', $source);

        foreach (['function getWidgetConfig(', 'function postSaveWidgetConfig(', 'function getWidgetFieldI18nPayload('] as $fn) {
            $start = \strpos($source, $fn);
            self::assertNotFalse($start, $fn);
            $next = \strpos($source, "\n    public function ", $start + 10);
            $body = $next === false
                ? \substr($source, (int)$start)
                : \substr($source, (int)$start, $next - (int)$start);
            self::assertStringContainsString('resolveNodeUidFromEditorRequest', $body, $fn);
            self::assertStringContainsString('resolveScopedDraftNode', $body, $fn);
            self::assertStringNotContainsString('$this->themeLayout->reset()->load($layoutId)', $body, $fn);
        }

        $saveStart = \strpos($source, 'function postSaveWidgetConfig(');
        self::assertNotFalse($saveStart);
        $saveNext = \strpos($source, "\n    public function ", $saveStart + 10);
        $saveBody = $saveNext === false
            ? \substr($source, (int)$saveStart)
            : \substr($source, (int)$saveStart, $saveNext - (int)$saveStart);
        self::assertStringContainsString("'scoped_workspace' => \$scopedWorkspace", $saveBody);
        self::assertStringContainsString('updateWidgetConfig(', $saveBody);

        $getStart = \strpos($source, 'function getWidgetConfig(');
        self::assertNotFalse($getStart);
        $getNext = \strpos($source, "\n    public function ", $getStart + 10);
        $getBody = $getNext === false
            ? \substr($source, (int)$getStart)
            : \substr($source, (int)$getStart, $getNext - (int)$getStart);
        // 无 @param 必须 success=true 空态，禁止 success=false 触发 BinQuery ERR /「加载配置失败」
        self::assertStringContainsString("'has_params' => false", $getBody);
        self::assertStringContainsString("'params' => []", $getBody);
        // GET 配置默认不得同步渲 preview（单部件预览可达数十秒，会堵住「点部件」）。
        // 契约演进：允许保留 tryBuildPreviewHtmlForWidget 入口，但必须先经 widgetConfigWantsPreviewHtml() 门控，
        // 仅显式 with_preview=1（画布回填）那一次才渲；禁止无条件调用。
        $previewGatePosition = strpos($getBody, 'widgetConfigWantsPreviewHtml()');
        $previewCallPosition = strpos($getBody, 'tryBuildPreviewHtmlForWidget');
        self::assertNotFalse($previewGatePosition, 'GET 配置必须以 widgetConfigWantsPreviewHtml() 门控预览渲染。');
        self::assertNotFalse($previewCallPosition, '自动保存后的画布回填仍需保留 preview 渲染入口。');
        self::assertLessThan(
            $previewCallPosition,
            $previewGatePosition,
            '预览门控必须先于 preview 渲染，保证打开配置时不渲染预览。',
        );
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*empty\(\s*\$params\s*\)\s*\)\s*\{\s*return\s+\$this->fetchJson\(\s*\[\s*\'success\'\s*=>\s*false/s',
            $getBody,
        );

        $updateStart = \strpos($source, 'function postUpdateConfig(');
        self::assertNotFalse($updateStart);
        $updateNext = \strpos($source, "\n    public function ", $updateStart + 10);
        $updateBody = $updateNext === false
            ? \substr($source, (int)$updateStart)
            : \substr($source, (int)$updateStart, $updateNext - (int)$updateStart);
        self::assertStringContainsString('resolveNodeUidFromEditorRequest', $updateBody);
        self::assertStringNotContainsString('$this->themeLayout->reset()->load($layoutId)', $updateBody);
    }
}
