<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * SlotRenderer must not mirror request page facts; use RequestContext bags + SlotRenderPass.
 */
final class SlotRendererPageContextContractTest extends TestCase
{
    public function testSlotRendererHasNoPageRenderContextMirror(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringNotContainsString('pageRenderContext', $source);
        self::assertStringNotContainsString('capturePageRenderContext', $source);
        self::assertStringContainsString('SlotRenderPass', $source);
        self::assertStringContainsString('withRenderPass', $source);
        self::assertStringContainsString("\$config['preview_mode']", $source);
        self::assertStringContainsString('isEditorPreviewRequest', $source);
    }

    public function testSlotRenderPassIsAlgorithmScratchOnly(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SlotRenderPass.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('not request facts', $source);
        self::assertStringContainsString('filledSlotIds', $source);
        self::assertStringContainsString('unavailableWidgets', $source);
        self::assertStringContainsString('orphanWidgets', $source);
        self::assertStringNotContainsString('storefront_offer', $source);
        self::assertStringNotContainsString('pageRenderContext', $source);
    }
}
