<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\LegacyMediaUrl;

/**
 * Local raster URL remaps to sibling .webp after batch conversion.
 */
final class LegacyMediaUrlWebpFallbackContractTest extends TestCase
{
    public function testPreferExistingLocalRasterRewritesMissingJpgToWebp(): void
    {
        $webpRel = 'catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.webp';
        $webpAbs = rtrim((string)\PUB, '/\\') . '/media/' . $webpRel;
        self::assertFileExists($webpAbs, 'fixture webp from buyer-show catalog must exist');

        $out = LegacyMediaUrl::preferExistingLocalRaster(
            '/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.jpg'
        );
        self::assertSame(
            '/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.webp',
            $out
        );
    }

    public function testSanitizeLeavesNonMediaPathsUntouched(): void
    {
        $url = '/static/Weline/hanfu/demo.png';
        self::assertSame($url, LegacyMediaUrl::sanitize($url));
    }

    public function testPreferExistingKeepsUrlWhenWebpMissing(): void
    {
        $url = '/media/catalog/hanfu/__no_such_dir__/missing-look.jpg';
        self::assertSame($url, LegacyMediaUrl::preferExistingLocalRaster($url));
    }
}
