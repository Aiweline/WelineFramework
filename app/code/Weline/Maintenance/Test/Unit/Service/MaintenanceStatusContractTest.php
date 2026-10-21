<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Console\Maintenance\Status;
use Weline\Maintenance\Helper\MaintenanceSessionMarker;

final class MaintenanceStatusContractTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        $this->baseDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-maint-' . \getmypid() . '-' . \bin2hex(\random_bytes(4));
        \mkdir($this->baseDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $path = (new MaintenanceSessionMarker($this->baseDir))->path();
        @\unlink($path);
        @\rmdir(\dirname($path));
        @\rmdir($this->baseDir);
    }

    public function testMarkerRoundTrip(): void
    {
        $marker = new MaintenanceSessionMarker($this->baseDir);
        self::assertNull($marker->read());

        $marker->start('maintenance:enable');
        $read = $marker->read();
        self::assertIsArray($read);
        self::assertSame('maintenance:enable', $read['action']);
        self::assertGreaterThan(0, $read['pid']);
        self::assertTrue(MaintenanceSessionMarker::pidAlive($read['pid']));
        self::assertSame(0, $marker->ageSeconds());

        $marker->clear();
        self::assertNull($marker->read());
    }

    public function testAssessFlagsStaleMaintenanceAfterOwnerExit(): void
    {
        $result = Status::assess(true, false, false, 30);
        self::assertTrue($result['stale']);
        self::assertNotSame([], $result['warnings']);
    }

    public function testAssessFlagsStaleMaintenanceAfterTimeout(): void
    {
        $result = Status::assess(true, false, true, Status::STALE_AFTER_SECONDS + 1);
        self::assertTrue($result['stale']);
    }

    public function testAssessKeepsActiveUpgradeWindowAsNormal(): void
    {
        $result = Status::assess(true, true, true, 5);
        self::assertFalse($result['stale']);
    }

    public function testAssessKeepsFreshMaintenanceAsNormal(): void
    {
        $result = Status::assess(true, false, true, 30);
        self::assertFalse($result['stale']);
    }

    public function testAssessWithoutMarkerIsStaleWhenMaintenanceOn(): void
    {
        $result = Status::assess(true, false, false, null);
        self::assertTrue($result['stale']);
    }

    public function testAssessClosedMaintenanceIsNormal(): void
    {
        $result = Status::assess(false, false, false, null);
        self::assertFalse($result['stale']);
        self::assertSame([], $result['warnings']);
    }
}
