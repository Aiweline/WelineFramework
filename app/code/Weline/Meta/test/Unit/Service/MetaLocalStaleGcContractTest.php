<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class MetaLocalStaleGcContractTest extends TestCase
{
    public function testCronAndServiceExistWithLimits(): void
    {
        $cron = dirname(__DIR__, 3) . '/Cron/MetaLocalStaleGc.php';
        $service = dirname(__DIR__, 3) . '/Service/MetaLocalStaleGcService.php';
        self::assertFileExists($cron);
        self::assertFileExists($service);

        $cronSrc = (string)file_get_contents($cron);
        self::assertStringContainsString("return 'meta_local_stale_gc'", $cronSrc);
        self::assertStringContainsString('MetaLocalStaleGcService', $cronSrc);

        $svc = (string)file_get_contents($service);
        self::assertStringContainsString('DEFAULT_MAX_DELETE', $svc);
        self::assertStringContainsString('deleted_orphan_version', $svc);
        self::assertStringContainsString('deleted_stale_preview', $svc);
        self::assertStringContainsString('deleted_orphan_meta', $svc);
        self::assertStringNotContainsString('@meta::', $svc);
    }
}
