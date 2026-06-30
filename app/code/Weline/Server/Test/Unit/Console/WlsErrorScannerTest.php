<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\WlsErrorScanner;

final class WlsErrorScannerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $baseDir = (defined('BP') ? BP . '/var/tmp' : sys_get_temp_dir()) . '/wls-error-scanner-' . bin2hex(random_bytes(4));
        mkdir($baseDir, 0777, true);
        $this->tempDir = str_replace('\\', '/', $baseDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testScannerDoesNotReplayHistoricalFatalAfterCursorBootstrap(): void
    {
        $logFile = $this->tempDir . '/php_error.log';
        file_put_contents($logFile, "[18-May-2026 02:10:29 UTC] PHP Fatal error: old framework crash\n");

        $scanner = $this->scanner([$logFile]);
        $this->runScanner($scanner);

        self::assertFileDoesNotExist($this->tempDir . '/wls_monitor.log');

        file_put_contents($logFile, "[18-May-2026 03:10:00 UTC] PHP Fatal error: new framework crash\n", FILE_APPEND);

        $this->runScanner($scanner);

        $monitor = file_get_contents($this->tempDir . '/wls_monitor.log');
        self::assertIsString($monitor);
        self::assertStringContainsString('03:10:00', $monitor);
        self::assertStringContainsString('new framework crash', $monitor);
        self::assertStringNotContainsString('old framework crash', $monitor);

        $before = $monitor;
        $this->runScanner($scanner);

        self::assertSame($before, file_get_contents($this->tempDir . '/wls_monitor.log'));
    }

    public function testFalseVerboseFlagDoesNotEnableCronOutput(): void
    {
        $logFile = $this->tempDir . '/php_error.log';
        file_put_contents($logFile, '');

        ob_start();
        try {
            $this->scanner([$logFile])->execute(['v' => false], []);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertSame('', $output);
    }

    private function scanner(array $logFiles): WlsErrorScanner
    {
        return new class($this->tempDir, $logFiles) extends WlsErrorScanner {
            public function __construct(
                private readonly string $tempDir,
                private readonly array $logFiles
            ) {
            }

            protected function getSignatureCachePath(): string
            {
                return $this->tempDir . '/signatures.json';
            }

            protected function getCursorCachePath(): string
            {
                return $this->tempDir . '/cursors.json';
            }

            protected function getAlertLogPath(): string
            {
                return $this->tempDir . '/wls_monitor.log';
            }

            protected function getTasksFilePath(): string
            {
                return $this->tempDir . '/tasks.json';
            }

            protected function getLogFiles(): array
            {
                return $this->logFiles;
            }
        };
    }

    private function runScanner(WlsErrorScanner $scanner): void
    {
        ob_start();
        try {
            $scanner->execute([], []);
        } finally {
            ob_end_clean();
        }
    }

    private function removeDirectory(string $dir): void
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
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
