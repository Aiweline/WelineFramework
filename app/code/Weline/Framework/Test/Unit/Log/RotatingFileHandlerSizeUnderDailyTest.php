<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Log\Handler\RotatingFileHandler;
use Weline\Framework\Log\LogLevel;

final class RotatingFileHandlerSizeUnderDailyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-rfh-daily-size-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testDailyStrategyAlsoRotatesWhenOverMaxSize(): void
    {
        $live = $this->dir . DIRECTORY_SEPARATOR . 'probe.log';
        file_put_contents($live, str_repeat('x', 1200));

        $handler = new RotatingFileHandler($this->dir . DIRECTORY_SEPARATOR, [
            'strategy' => 'daily',
            'max_size' => 1000,
            'max_files' => 7,
            'use_locking' => false,
        ]);

        $ok = $handler->write(LogLevel::ERROR, "line\n", 'probe');
        $handler->close();

        self::assertTrue($ok);
        $archives = glob($this->dir . DIRECTORY_SEPARATOR . 'probe-*.log') ?: [];
        self::assertNotEmpty($archives, 'daily strategy must size-rotate oversized live file');
        self::assertFileExists($live);
        self::assertLessThan(1200, (int)filesize($live), 'live file should be replaced after rotate+rewrite');
    }

    public function testMaxSizeZeroDisablesSizeRotationUnderDaily(): void
    {
        $live = $this->dir . DIRECTORY_SEPARATOR . 'probe.log';
        file_put_contents($live, str_repeat('y', 1200));

        $handler = new RotatingFileHandler($this->dir . DIRECTORY_SEPARATOR, [
            'strategy' => 'daily',
            'max_size' => 0,
            'max_files' => 7,
            'use_locking' => false,
        ]);

        $handler->write(LogLevel::ERROR, "line\n", 'probe');
        $handler->close();

        $archives = glob($this->dir . DIRECTORY_SEPARATOR . 'probe-*.log') ?: [];
        self::assertSame([], $archives);
        self::assertGreaterThanOrEqual(1200, (int)filesize($live));
    }
}
