<?php
declare(strict_types=1);

namespace Weline\Frontend\test\Unit\View;

use Weline\Framework\Test\TestCore;

class FrontendRuntimeThemeVersionContractTest extends TestCore
{
    public function testHeaderRuntimeInjectsThemePublishedVersionFields(): void
    {
        $path = dirname(__DIR__, 3) . '/view/blocks/header/base.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('themePublishedVersionId', $src);
        self::assertStringContainsString('themePublishedVersion', $src);
        self::assertStringContainsString('deployVersion', $src);
        self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
        self::assertStringContainsString('weline-api.js', $src);
        self::assertStringContainsString('frontendAssetVersion', $src);
        self::assertStringContainsString("'assetVersion' => \$frontendAssetVersion", $src);
    }
}
