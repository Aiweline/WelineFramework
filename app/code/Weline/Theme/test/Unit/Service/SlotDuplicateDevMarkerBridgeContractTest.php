<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * DEV/preview: same-slot duplicate widget_code gets red outline + source tip.
 * Cross-slot same code stays unmarked (legal multi-plant).
 */
final class SlotDuplicateDevMarkerBridgeContractTest extends TestCase
{
    public function testSlotRendererInjectsDuplicateDevBridgeFromHealthPath(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/SlotRendererService.php'
        );
        self::assertStringContainsString('appendSlotDuplicateDevMarkerBridge', $src);
        self::assertStringContainsString('appendDevStorefrontDiagnostics', $src);
        self::assertStringContainsString('data-w-slot-dup-dev-bridge', $src);
        self::assertStringContainsString('w-slot-dup-dev', $src);
        self::assertStringContainsString('data-widget-source', $src);
        // Must chain after health toast bridge, still gated by shouldInspectWidgetHtml.
        $healthRet = strpos($src, 'return $this->appendSlotDuplicateDevMarkerBridge($html);');
        self::assertNotFalse($healthRet);
        $dupFn = strpos($src, 'function appendSlotDuplicateDevMarkerBridge');
        self::assertNotFalse($dupFn);
        self::assertLessThan($dupFn, $healthRet);
        self::assertStringContainsString('shouldInspectWidgetHtml()', substr($src, $dupFn, 800));
        // Cross-slot same code must not be treated as duplicate in the scan key.
        self::assertStringContainsString("slot + '::' + id", $src);

        $obs = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );
        self::assertStringContainsString('appendDevStorefrontDiagnostics', $obs);
    }

    public function testEntityRendererEmitsWidgetSourceAttr(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php'
        );
        self::assertStringContainsString("'data-widget-source' => \$source", $src);
    }

    public function testTaglibInlineMarksTemplateSource(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Widget/Taglib/Widget.php'
        );
        self::assertStringContainsString('data-widget-source="template_inline"', $src);
    }
}
