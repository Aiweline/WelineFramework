<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\DarwinWinModeLogWindow;
use Weline\Server\Service\DarwinWinModeWindowRegistry;
use Weline\Server\Service\WlsLogService;

final class DarwinWinModeWindowRegistryTest extends TestCase
{
    public function testRememberForgetAndClearRoundTrip(): void
    {
        $instance = 'unit-darwin-win-' . \substr(\hash('sha256', (string)\microtime(true)), 0, 8);
        $registry = new DarwinWinModeWindowRegistry();
        $path = $registry->pathFor($instance);
        if (\is_file($path)) {
            @\unlink($path);
        }

        $registry->remember($instance, [
            'process_name' => 'weline-wls-worker-unit-1',
            'title' => 'worker#1',
            'log_file' => '/tmp/unit-worker.log',
            'window_id' => 1001,
            'shell_pid' => 2002,
        ]);

        $loaded = $registry->load($instance);
        self::assertSame('weline-wls-worker-unit-1', $loaded['windows']['weline-wls-worker-unit-1']['process_name']);
        self::assertSame(1001, $loaded['windows']['weline-wls-worker-unit-1']['window_id']);
        self::assertSame(2002, $loaded['windows']['weline-wls-worker-unit-1']['shell_pid']);

        $registry->forget($instance, 'weline-wls-worker-unit-1');
        self::assertSame([], $registry->load($instance)['windows']);

        $registry->remember($instance, [
            'process_name' => 'weline-wls-master-unit',
            'title' => 'master',
            'log_file' => '/tmp/unit-master.log',
            'window_id' => 9,
            'shell_pid' => 8,
        ]);
        $registry->clear($instance);
        self::assertFileDoesNotExist($path);
        self::assertSame([], $registry->load($instance)['windows']);
    }

    public function testIsSupportedMatchesDarwinAndOsascript(): void
    {
        $expected = \PHP_OS_FAMILY === 'Darwin'
            && \is_file('/usr/bin/osascript')
            && \is_executable('/usr/bin/osascript');

        self::assertSame($expected, DarwinWinModeLogWindow::isSupported());
    }

    public function testOpenForProcessRejectsEmptyNames(): void
    {
        self::assertFalse(DarwinWinModeLogWindow::openForProcess('', 'proc', 'default'));
        self::assertFalse(DarwinWinModeLogWindow::openForProcess('title', '', 'default'));
        self::assertFalse(DarwinWinModeLogWindow::openForProcess('title', 'proc', ''));
    }

    public function testSharedSessionLogPathUsesServiceInstanceNotRequesterDefault(): void
    {
        $processName = 'weline-wls-session-p05113ef3-shared-26277';
        $wrong = WlsLogService::getProcessLogFile($processName, 'default');
        $right = WlsLogService::getProcessLogFile($processName, 'shared-session-p05113ef3-26277');
        self::assertStringContainsString('/default/', \str_replace('\\', '/', $wrong));
        self::assertStringContainsString('/shared-session-p05113ef3-26277/', \str_replace('\\', '/', $right));
        self::assertNotSame($wrong, $right);
    }
}
