<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Greenfield debt: update-config contract no longer mocks legacy layout_id APIs.
 */
final class ThemeEditorUpdateConfigContractTest extends TestCase
{
    public function testUpdateConfigContractUsesScopedWriterNotLegacyLayoutId(): void
    {
        $editor = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        $start = strpos($editor, 'function postUpdateConfig(');
        self::assertNotFalse($start);
        $next = strpos($editor, "\n    public function ", $start + 10);
        $body = $next === false
            ? substr($editor, (int)$start)
            : substr($editor, (int)$start, $next - (int)$start);

        self::assertStringContainsString('$layoutWriter->updateWidgetConfig(', $body);
        self::assertStringContainsString("'node_uid' => \$saved['node_uid']", $body);
        self::assertStringNotContainsString('getWidgetByLayoutId', $body);
        self::assertStringNotContainsString('$this->layoutService->updateWidgetConfig', $body);
    }
}
