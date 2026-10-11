<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache\Adapter;

use PHPUnit\Framework\TestCase;

/** Guard: FileAdapter refuses whole-file get when headroom cannot cover payload. */
final class FileAdapterNearLimitContractTest extends TestCase
{
    public function testGetChecksHeadroomForAnySizedBlobNotOnlyHugeFiles(): void
    {
        $fw = \dirname(__DIR__, 4);
        $src = (string) \file_get_contents($fw . '/Cache/Adapter/FileAdapter.php');
        self::assertStringContainsString('NEAR_LIMIT_PAYLOAD_FLOOR_BYTES', $src);
        self::assertStringContainsString('NEAR_LIMIT_HEADROOM_BYTES', $src);
        self::assertStringContainsString('memoryHeadroomBytes() < $needBytes', $src);
        self::assertStringContainsString('never process-fatal OOM', $src);
        self::assertSame('2.5.340', (string) (require $fw . '/etc/module.php')['version']);
    }
}
