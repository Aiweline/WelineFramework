<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Api\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\StoreMusic\Api\Deploy\FlatStaticRuntimeFilesProvider;

final class FlatStaticRuntimeFilesProviderTest extends TestCase
{
    public function testProvidesStoreMusicRuntimeStaticsForProdFlatStatic(): void
    {
        $provider = new FlatStaticRuntimeFilesProvider();

        self::assertSame('Weline_StoreMusic', $provider->moduleName());
        self::assertContains('js/store-music.js', $provider->relativeFiles());
        self::assertContains('css/store-music.css', $provider->relativeFiles());
        self::assertContains('frontend/weline.modules.js', $provider->relativeFiles());
        self::assertContains('images/guofeng-cameo.png', $provider->relativeFiles());
    }

    public function testModuleRegistersFlatStaticProvider(): void
    {
        $module = include dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertIsArray($provides);
        self::assertSame(
            FlatStaticRuntimeFilesProvider::class,
            $provides['deploy.flat_static.Weline_StoreMusic'] ?? null,
        );
    }
}
