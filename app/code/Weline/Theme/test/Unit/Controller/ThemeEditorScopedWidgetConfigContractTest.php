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
