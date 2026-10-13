<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guard: storefront/backend chrome must not hardcode DEV module static URLs.
 * Use {@see \Weline\Framework\View\PublicStaticUrl} (or @static / fetchTagSource).
 */
final class ForbidHardcodedModuleStaticUrlContractTest extends TestCase
{
    /** @return list<string> */
    private function criticalRuntimeFiles(): array
    {
        $bp = dirname(__DIR__, 4);

        return [
            $bp . '/Frontend/view/blocks/header/base.phtml',
            $bp . '/Backend/view/blocks/header/base.phtml',
            $bp . '/Theme/Helper/StorefrontImagePlaceholder.php',
            $bp . '/Theme/Service/TextileHeritageCatalog.php',
            $bp . '/Theme/Observer/ControllerFetchFileAfter.php',
            $bp . '/I18n/Helper/CountryFlagMarkup.php',
            $bp . '/CustomerService/view/templates/frontend/widgets/customer-service-float.phtml',
        ];
    }

    public function testCriticalChromeUsesPublicStaticUrlNotBareDevModulePaths(): void
    {
        $pattern = "/['\"]\\/Weline\\/[A-Za-z0-9_]+\\/view\\/statics/";
        foreach ($this->criticalRuntimeFiles() as $file) {
            self::assertFileExists($file, $file);
            $src = (string)file_get_contents($file);
            // Identity constants / DEV path seeds are allowed only as PublicStaticUrl inputs.
            if (str_contains($src, 'PublicStaticUrl::fromDevWebPath')
                || str_contains($src, 'fetchTagSource')
            ) {
                // Still forbid naked ternary DEV/PROD duplicates.
                self::assertDoesNotMatchRegularExpression(
                    "/DEV\\s*\\?\\s*['\"]\\/Weline\\//",
                    $src,
                    'Use PublicStaticUrl instead of DEV ternary: ' . $file,
                );
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                $pattern,
                $src,
                'Hardcoded module static URL in ' . $file,
            );
        }
    }

    public function testPublicStaticUrlHelperExists(): void
    {
        $path = dirname(__DIR__, 3) . '/View/PublicStaticUrl.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('DeployPublicUrlMode::useProduction', $src);
        self::assertStringContainsString('/static/', $src);
    }

    public function testTraitTemplateModuleStaticsUsePublicStaticUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/View/TraitTemplate.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('PublicStaticUrl::fromModuleSource', $src);
        self::assertStringContainsString('buildModuleStaticPublicUrl', $src);
    }
}
