<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;

/**
 * Design theme injectprobe deliberately omits float destinations; Theme must restore on bake.
 */
final class InjectprobeFloatlessThemeContractTest extends TestCase
{
    public function testDesignFooterOmitsFloatAndMaterializerRestoresSlots(): void
    {
        // __DIR__ = .../Theme/Test/Unit/LayoutEntity → 6 levels up = app/
        $design = dirname(__DIR__, 6) . '/design/Weline/injectprobe/frontend/partials/footer/default.phtml';
        self::assertFileExists($design);
        $src = (string)file_get_contents($design);
        self::assertStringContainsString('injectprobe:floatless', $src);
        self::assertFalse(
            StorefrontFloatLayerHost::htmlHasFloatDestinations($src),
            'injectprobe design footer must omit storefront-float destinations'
        );

        $restored = StorefrontFloatLayerHost::ensureInSourceTemplate($src);
        self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($restored));
        self::assertStringContainsString('<w:slot id="storefront-float-start"', $restored);
        self::assertStringContainsString('<w:slot id="storefront-float-end"', $restored);

        $materializer = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
        self::assertStringContainsString(
            'StorefrontFloatLayerHost::ensureInSourceTemplate',
            (string)file_get_contents($materializer)
        );
    }

    public function testHomepageOverrideDisabledSoParentShellIsUsed(): void
    {
        $active = dirname(__DIR__, 6) . '/design/Weline/injectprobe/frontend/layouts/homepage/default.phtml';
        $disabled = dirname(__DIR__, 6)
            . '/design/Weline/injectprobe/frontend/layouts/_disabled/homepage/default.phtml';
        // Active homepage override must stay absent: a fragment <main> replaces
        // Weline_Theme shell and causes worker_scope_response_incomplete_html 503.
        self::assertFileDoesNotExist($active);
        self::assertFileExists($disabled);
        $src = (string)file_get_contents($disabled);
        self::assertStringContainsString('data-injectprobe-theme', $src);
    }
}
