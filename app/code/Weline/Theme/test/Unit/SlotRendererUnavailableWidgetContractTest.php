<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 失效部件检测与可操作占位必须经 wrapper，并收集 node_uid。
 */
final class SlotRendererUnavailableWidgetContractTest extends TestCase
{
    public function testUnavailableDetectionAndWrappedTipContract(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/SlotRendererService.php'
        );

        self::assertStringContainsString('private array $unavailableWidgets = [];', $source);
        self::assertStringContainsString('function getUnavailableWidgets()', $source);
        self::assertStringContainsString('function hasUnavailableWidgets()', $source);
        self::assertStringContainsString('function recordUnavailableWidget(', $source);
        self::assertStringContainsString('function renderUnavailableWidgetTip(', $source);
        self::assertStringContainsString('function classifyUnavailableReason(', $source);
        self::assertStringContainsString("'missing_definition'", $source);
        self::assertStringContainsString("'missing_template'", $source);
        self::assertStringContainsString("'missing_module'", $source);
        self::assertStringContainsString('data-action="remove-unavailable-widget"', $source);
        self::assertStringContainsString('widget-unavailable-tip', $source);
        self::assertStringContainsString('maybeWrapWidgetHtml(', $source);
        self::assertStringContainsString('data-unavailable="1"', $source);
        self::assertStringContainsString('data-editor-interactive', $source);
        self::assertStringContainsString('recoverUnavailableWidgetsFromHtml(', $source);
        self::assertStringContainsString('syncUnavailableWidgetsFromHtml(', $source);
        self::assertStringContainsString('$this->unavailableWidgets = [];', $source);
    }
}
