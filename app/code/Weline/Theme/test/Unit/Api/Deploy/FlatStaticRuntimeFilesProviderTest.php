<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Deploy\FlatStaticRuntimeFilesProvider;

final class FlatStaticRuntimeFilesProviderTest extends TestCase
{
    public function testProvidesStorefrontImageFallbackForProdFlatStatic(): void
    {
        $provider = new FlatStaticRuntimeFilesProvider();

        self::assertSame('Weline_Theme', $provider->moduleName());
        self::assertContains('js/storefront-image-fallback.js', $provider->relativeFiles());
        self::assertContains('js/storefront-shopper-toast.js', $provider->relativeFiles());
        self::assertContains('frontend/weline.modules.js', $provider->relativeFiles());
    }

    public function testModuleRegistersFlatStaticProvider(): void
    {
        $module = include dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertIsArray($provides);
        self::assertSame(
            FlatStaticRuntimeFilesProvider::class,
            $provides['deploy.flat_static.Weline_Theme'] ?? null,
        );
    }
}
