<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * DaoCharms Website Scope brand assets — media files under pub/media/websites/daocharms/.../brand.
 */
final class ThemeWebsiteDaocharmsBrandAssetsContractTest extends TestCase
{
    public function testDaocharmsWebsiteBrandPngsExistUnderMediaBrandDir(): void
    {
        $root = \dirname(__DIR__, 7);
        $brandDir = $root . '/pub/media/websites/daocharms/default/brand';
        $files = [
            'daocharms-logo-v1.png',
            'daocharms-icon-v1.png',
            'daocharms-apple-touch-icon-v1.png',
        ];
        foreach ($files as $name) {
            $path = $brandDir . '/' . $name;
            self::assertFileExists($path, $name . ' must exist for daocharms appearance.brand');
            $bytes = (string)\file_get_contents($path);
            self::assertNotSame('', $bytes);
            self::assertSame("\x89PNG", \substr($bytes, 0, 4), $name . ' must be PNG');
        }
    }
}
