<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class PixelCronBatchContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!\defined('BP')) {
            $dir = __DIR__;
            while ($dir !== \dirname($dir)) {
                if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                    \define('BP', $dir . DIRECTORY_SEPARATOR);
                    break;
                }
                $dir = \dirname($dir);
            }
        }
        self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
    }

    public function testGetUnDeaPixelsForcesLimitAndOrder(): void
    {
        $path = \BP . 'app/code/Weline/Visitor/Model/Pixel.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('UNDEALT_BATCH_DEFAULT = 500', $src);
        self::assertStringContainsString('UNDEALT_BATCH_MAX = 10000', $src);
        self::assertStringContainsString('function getUnDeaPixels(?int $websiteId = null, ?int $limit = null)', $src);
        self::assertStringContainsString('->limit($batch)', $src);
        self::assertStringContainsString("order(self::schema_fields_ID, 'ASC')", $src);
        self::assertStringContainsString('function countUnDeaPixels', $src);
        self::assertStringContainsString('countUnDeaPixels($websiteId)', $src);
        self::assertStringNotContainsString(
            'count(self::getUnDeaPixels($websiteId))',
            $src,
        );
    }

    public function testPixelCronBatchesInsteadOfUnboundedFetch(): void
    {
        $path = \BP . 'app/code/Weline/Visitor/Cron/Pixel.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('UNDEALT_BATCH_DEFAULT', $src);
        self::assertStringContainsString('getUnDeaPixels(null, PixelModel::UNDEALT_BATCH_DEFAULT)', $src);
        self::assertStringContainsString('RUN_BUDGET_SECONDS', $src);
        self::assertStringContainsString('while (', $src);
        self::assertStringNotContainsString('getUnDeaPixels()', $src);
    }
}
