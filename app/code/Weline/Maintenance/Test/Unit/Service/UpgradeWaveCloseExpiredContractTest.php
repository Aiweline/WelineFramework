<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\UpgradeWaveService;

final class UpgradeWaveCloseExpiredContractTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = \sys_get_temp_dir() . '/weline-mw-wave-' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmpRoot . '/var/maintenance', 0775, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpRoot . '/' . UpgradeWaveService::WAVE_FILE;
        if (\is_file($file)) {
            @\unlink($file);
        }
        @\rmdir($this->tmpRoot . '/var/maintenance');
        @\rmdir($this->tmpRoot . '/var');
        @\rmdir($this->tmpRoot);
    }

    public function testCloseExpiredWaveMarksStaleRedeemWindowClosed(): void
    {
        $service = new UpgradeWaveService($this->tmpRoot);
        $now = 1_790_000_000;
        $service->writeWave([
            'wave_id' => 'test',
            'status' => 'redeem_window',
            'recovered_at' => $now - 900,
            'redeem_deadline_at' => $now - 100,
            'wait_gift_enabled' => true,
        ]);

        $closed = $service->closeExpiredWave($now);
        self::assertNotNull($closed);
        self::assertSame('closed', $closed['status'] ?? null);
        self::assertFalse($service->isRedeemWindowOpen($closed, $now));
    }

    public function testCloseExpiredWaveLeavesOpenWindowUntouched(): void
    {
        $service = new UpgradeWaveService($this->tmpRoot);
        $now = 1_790_000_000;
        $service->writeWave([
            'wave_id' => 'test-open',
            'status' => 'redeem_window',
            'recovered_at' => $now - 60,
            'redeem_deadline_at' => $now + 500,
            'wait_gift_enabled' => true,
        ]);

        $wave = $service->closeExpiredWave($now);
        self::assertNotNull($wave);
        self::assertSame('redeem_window', $wave['status'] ?? null);
    }
}
