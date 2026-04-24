<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process\Driver;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\WindowsProcessDriver;

final class WindowsProcessDriverCommandRoutingTest extends TestCase
{
    public function testPrepareDirectBypassShellCommandSupportsPowerShellCommandArgument(): void
    {
        $driver = new WindowsProcessDriver();
        $method = new \ReflectionMethod(WindowsProcessDriver::class, 'prepareDirectBypassShellCommand');
        $method->setAccessible(true);

        $prepared = $method->invoke(
            $driver,
            'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Select-Object -First 1 ProcessId" 2>NUL'
        );

        self::assertIsArray($prepared);
        self::assertSame(
            [
                'powershell',
                '-NoProfile',
                '-Command',
                'Get-CimInstance Win32_Process | Select-Object -First 1 ProcessId',
            ],
            $prepared['argv']
        );
        self::assertFalse($prepared['merge_stderr']);
    }

    public function testPrepareDirectBypassShellCommandRejectsShellPipelineOutsideQuotes(): void
    {
        $driver = new WindowsProcessDriver();
        $method = new \ReflectionMethod(WindowsProcessDriver::class, 'prepareDirectBypassShellCommand');
        $method->setAccessible(true);

        $prepared = $method->invoke(
            $driver,
            'tasklist /V /FO CSV 2>NUL | findstr /I "weline-"'
        );

        self::assertNull($prepared);
    }

    public function testExecuteCommandRunsPowerShellProbeWithoutCmdShellPath(): void
    {
        if (\strtoupper(\substr(PHP_OS, 0, 3)) !== 'WIN') {
            self::markTestSkipped('Windows only.');
        }

        $driver = new class extends WindowsProcessDriver {
            public function run(string $command, array &$output = [], int &$exitCode = 0): bool
            {
                return $this->executeCommand($command, $output, $exitCode);
            }
        };

        $output = [];
        $exitCode = 1;
        $result = $driver->run('powershell -NoProfile -Command "Write-Output ok" 2>NUL', $output, $exitCode);

        self::assertTrue($result);
        self::assertSame(0, $exitCode);
        self::assertSame(['ok'], $output);
    }
}
