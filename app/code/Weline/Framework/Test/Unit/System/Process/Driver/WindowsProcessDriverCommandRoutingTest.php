<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process\Driver;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\WindowsProcessDriver;

final class WindowsProcessDriverCommandRoutingTest extends TestCase
{
    public function testTopologyProbeBudgetCoversMeasuredColdArm64EmulationLatency(): void
    {
        $constant = (new \ReflectionClass(WindowsProcessDriver::class))
            ->getReflectionConstant('PROCESS_TOPOLOGY_COMMAND_TIMEOUT_SECONDS');

        self::assertNotFalse($constant);
        self::assertSame(12.0, $constant->getValue());
    }

    public function testBoundedWindowsCommandTerminatesAHungTopologyProbe(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The portable fake PowerShell fixture uses a POSIX executable.');
        }

        $directory = \sys_get_temp_dir() . '/weline-powershell-timeout-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($directory, 0700));
        $fakePowerShell = $directory . '/powershell';
        self::assertNotFalse(\file_put_contents(
            $fakePowerShell,
            "#!/bin/sh\nexec sleep 5\n",
        ));
        self::assertTrue(@\chmod($fakePowerShell, 0700));
        $previousPath = (string)\getenv('PATH');
        \putenv('PATH=' . $directory . PATH_SEPARATOR . $previousPath);

        try {
            $driver = new class extends WindowsProcessDriver {
                /** @return array{executed:bool,output:list<string>,exit_code:int} */
                public function runBounded(string $command, float $timeout): array
                {
                    $output = [];
                    $exitCode = 0;
                    $executed = $this->executeCommandWithinDeadline(
                        $command,
                        $timeout,
                        $output,
                        $exitCode,
                    );

                    return [
                        'executed' => $executed,
                        'output' => $output,
                        'exit_code' => $exitCode,
                    ];
                }
            };
            $startedAt = \hrtime(true);
            $result = $driver->runBounded('powershell -NoProfile', 0.1);
            $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

            self::assertFalse($result['executed']);
            self::assertSame([], $result['output']);
            self::assertSame(124, $result['exit_code']);
            self::assertLessThan(2.0, $elapsed);
        } finally {
            \putenv('PATH=' . $previousPath);
            @\unlink($fakePowerShell);
            @\rmdir($directory);
        }
    }

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

    public function testBuildProcessTopologyPowerShellConstrainsCimQueryToLaunchParents(): void
    {
        $driver = new WindowsProcessDriver();
        $method = new \ReflectionMethod(WindowsProcessDriver::class, 'buildProcessTopologyPowerShell');
        $method->setAccessible(true);

        $script = $method->invoke(
            $driver,
            ['weline-worker-a', 'weline-worker-b'],
            [4100, 4200],
        );

        self::assertIsString($script);
        self::assertStringContainsString(
            "Get-CimInstance Win32_Process -Filter 'ProcessId=4100 OR ParentProcessId=4100 OR ProcessId=4200 OR ParentProcessId=4200'",
            $script,
        );
        self::assertStringNotContainsString(
            'Get-CimInstance Win32_Process -ErrorAction SilentlyContinue',
            $script,
        );
        self::assertSame(1, \substr_count($script, 'Get-CimInstance Win32_Process'));
        self::assertStringNotContainsString('$descendantFilter', $script);
    }
}
