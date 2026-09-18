<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend\Config;

use PHPUnit\Framework\TestCase;

/**
 * Frontend theme layout preview must open a real storefront path + editor markers,
 * never theme-editor/layout-preview or theme-preview/content as a fake canvas shell.
 */
final class LayoutPreviewStorefrontUrlContractTest extends TestCase
{
    public function testFrontendLayoutPreviewUsesStorefrontPathNotCustomShell(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Controller/Backend/Config/Layout.php'
        );

        self::assertStringContainsString('getFrontendUrlPathForPreview', $source);
        self::assertStringContainsString("\$url->getFrontendUrl(\$storefrontPath, \$params)", $source);
        self::assertStringContainsString("'shell' => 'theme-editor'", $source);
        self::assertStringNotContainsString(
            "getBackendUrl('theme/backend/theme-editor/layout-preview'",
            $source
        );
        self::assertStringContainsString(
            'Never open theme-preview/content or theme-editor/layout-preview',
            $source
        );
    }
}
