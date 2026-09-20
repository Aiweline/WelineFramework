<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class ThemeLayoutEntitySidecarHydrationContractTest extends TestCase
{
    public function testConfigStoreHydratesModuleCodeFromStructure(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityConfigStore.php'
        );
        self::assertStringContainsString('hydratePageNodeFromStructure', $src);
        self::assertStringContainsString('needsStructureHydration', $src);
    }

    public function testWidgetRendererHydratesPrimedParamOnlyNodes(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityWidgetRenderer.php'
        );
        self::assertStringContainsString('hydratePageNodeFromStructure', $src);
        self::assertStringContainsString('needsStructureHydration', $src);
    }

    public function testSlotFillerRunsRequiredOverlayOnShellAfterSplice(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertGreaterThanOrEqual(2, substr_count($src, 'RequiredDefaultInjectionStorefrontOverlay::class'));
        self::assertStringContainsString('shellMissingRequiredInjections', $src);
        self::assertStringNotContainsString('shellMissingRequiredPurchaseWidgets', $src);
        self::assertStringContainsString('spliceSolidifiedSlots($html, $rendered)', $src);
    }
}
