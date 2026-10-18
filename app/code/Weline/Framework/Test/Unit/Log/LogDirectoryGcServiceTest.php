<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Log;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Log\LogDirectoryGcService;

final class LogDirectoryGcServiceTest extends TestCase
{
    private string $root;
    private string $logDir;
    private string $cronLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-log-gc-' . bin2hex(random_bytes(4));
        $this->logDir = $this->root . DIRECTORY_SEPARATOR . 'log';
        $this->cronLog = $this->root . DIRECTORY_SEPARATOR . 'cron.log';
        mkdir($this->logDir . DIRECTORY_SEPARATOR . 'other', 0777, true);
        mkdir($this->logDir . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'history' . DIRECTORY_SEPARATOR . 'h1', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
    }

    public function testCopyTruncateOversizedLiveAndCronLog(): void
    {
        $live = $this->logDir . DIRECTORY_SEPARATOR . 'exception.log';
        $nested = $this->logDir . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'noise.log';
        file_put_contents($live, str_repeat('E', 2500));
        file_put_contents($nested, str_repeat('N', 2500));
        file_put_contents($this->cronLog, str_repeat('C', 2500));
        // under-limit archive-looking name must not be truncated as live
        $smallArchive = $this->logDir . DIRECTORY_SEPARATOR . 'exception-2026-01-01.log';
        file_put_contents($smallArchive, 'keep');

        $svc = new LogDirectoryGcService();
        $stats = $svc->run($this->logDir, $this->cronLog, 2000, 7, 7);

        self::assertSame(0, count($stats['errors']), implode('; ', $stats['errors']));
        self::assertSame(3, $stats['rotated']);
        self::assertSame(0, (int)filesize($live));
        self::assertSame(0, (int)filesize($nested));
        self::assertSame(0, (int)filesize($this->cronLog));
        self::assertFileExists($smallArchive);
        self::assertSame('keep', (string)file_get_contents($smallArchive));
        self::assertNotEmpty(glob($this->logDir . DIRECTORY_SEPARATOR . 'exception-*.log') ?: []);
        self::assertNotEmpty(glob($this->root . DIRECTORY_SEPARATOR . 'cron-*.log') ?: []);
    }

    public function testPruneArchivesKeepsMaxFiles(): void
    {
        $live = $this->logDir . DIRECTORY_SEPARATOR . 'app.log';
        file_put_contents($live, 'live');
        for ($i = 1; $i <= 5; $i++) {
            $name = sprintf('app-2026-01-%02d_12-00-00.log', $i);
            $path = $this->logDir . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, 'a' . $i);
            touch($path, time() - (10 - $i) * 86400);
        }

        $svc = new LogDirectoryGcService();
        $stats = $svc->run($this->logDir, $this->cronLog, 50_000_000, 2, 7);

        self::assertSame(0, $stats['rotated']);
        self::assertSame(3, $stats['pruned_archives']);
        $left = glob($this->logDir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [];
        self::assertCount(2, $left);
    }

    public function testPruneCronHistoryByAge(): void
    {
        $old = $this->logDir . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'history' . DIRECTORY_SEPARATOR . 'h1' . DIRECTORY_SEPARATOR . 'old.log';
        $fresh = $this->logDir . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'history' . DIRECTORY_SEPARATOR . 'h1' . DIRECTORY_SEPARATOR . 'fresh.log';
        file_put_contents($old, 'old');
        file_put_contents($fresh, 'fresh');
        touch($old, time() - 10 * 86400);
        touch($fresh, time() - 1 * 86400);

        $svc = new LogDirectoryGcService();
        $stats = $svc->run($this->logDir, $this->cronLog, 50_000_000, 7, 7);

        self::assertSame(1, $stats['pruned_history']);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
    }

    public function testIsRotatedArchiveName(): void
    {
        $svc = new LogDirectoryGcService();
        self::assertTrue($svc->isRotatedArchiveName('exception-2026-10-17.log'));
        self::assertTrue($svc->isRotatedArchiveName('exception-2026-10-17_18-30-01.log'));
        self::assertTrue($svc->isRotatedArchiveName('exception-2026-10-17_18-30-01-2.log'));
        self::assertFalse($svc->isRotatedArchiveName('exception.log'));
        self::assertFalse($svc->isRotatedArchiveName('php_error.log'));
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
