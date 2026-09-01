<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SlotRendererPageContextContractTest extends TestCase
{
    public function testSlotRendererCapturesAndReinjectsPageContextAfterUnsetData(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('pageRenderContext', $source);
        self::assertStringContainsString('capturePageRenderContext', $source);
        self::assertStringContainsString("'storefront_offer'", $source);
        self::assertStringContainsString("'storefront_offers'", $source);
        self::assertStringContainsString("\$config['preview_mode']", $source);
    }
}
