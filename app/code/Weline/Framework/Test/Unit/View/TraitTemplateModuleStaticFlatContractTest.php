<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Module STATICS primary URL/disk must be Upgrade flat via PublicStaticUrl — not theme-ns overlay.
 */
final class TraitTemplateModuleStaticFlatContractTest extends TestCase
{
    public function testTraitTemplateBuildsModuleStaticsViaPublicStaticUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/View/TraitTemplate.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('buildModuleStaticPublicUrl', $src);
        self::assertStringContainsString('PublicStaticUrl::fromModuleSource', $src);
        self::assertStringContainsString('resolveFlatStaticModuleDiskRoot', $src);
        self::assertStringContainsString('prod|flat', $src);
        self::assertStringContainsString('context-env-v10-module-static-flat', $src);

        // Theme-ns must not be used as module STATICS URL fallback under prod.
        self::assertDoesNotMatchRegularExpression(
            "/useProductionStaticPublicUrls\(\)[\s\S]{0,200}resolvePublicThemeNamespace\(\)[\s\S]{0,120}view\/statics/",
            $src,
            'Module STATICS must not fall back to /static/{themeNs}/…/view/statics/',
        );
    }

    public function testPublicStaticUrlRemainsFlatAuthority(): void
    {
        $path = dirname(__DIR__, 3) . '/View/PublicStaticUrl.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('/static/', $src);
        self::assertStringContainsString('view/statics', $src);
        self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
    }
}
