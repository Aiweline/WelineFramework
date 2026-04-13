<?php

declare(strict_types=1);

namespace Weline\Framework\System\Process;

use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;
use Weline\Framework\UnitTest\TestCore;

/**
 * Processer 与进程名规范化的单元测试
 *
 * 不启动真实进程、不执行 kill，仅测试纯逻辑与驱动解析。
 */
class ProcesserTest extends TestCore
{
    protected function tearDown(): void
    {
        ProcessDriverFactory::reset();
        parent::tearDown();
    }

    /* ---------- normalizeName ---------- */

    public function testNormalizeNameEmptyReturnsEmpty(): void
    {
        self::assertSame('', Processer::normalizeName(''));
    }

    public function testNormalizeNameReplacesPunctuationWithDash(): void
    {
        self::assertSame('a-b-c', Processer::normalizeName('a.b.c'));
        self::assertSame('worker-port-9980', Processer::normalizeName('worker.port.9980'));
    }

    public function testNormalizeNameStripsQuotes(): void
    {
        self::assertSame('name', Processer::normalizeName('"name"'));
        self::assertSame('name', Processer::normalizeName("'name'"));
    }

    public function testNormalizeNameCollapsesMultipleDashes(): void
    {
        self::assertSame('a-b', Processer::normalizeName('a---b'));
        self::assertSame('a-b', Processer::normalizeName('a--b'));
    }

    public function testNormalizeNameTrimsLeadingTrailingDashes(): void
    {
        self::assertSame('name', Processer::normalizeName('--name--'));
    }

    public function testNormalizeNameLowercase(): void
    {
        self::assertSame('weline-worker', Processer::normalizeName('Weline-Worker'));
    }

    public function testNormalizeNameTruncatesToMaxLength(): void
    {
        $long = \str_repeat('a', Processer::PROCESS_NAME_MAX_LENGTH + 10);
        $result = Processer::normalizeName($long);
        self::assertLessThanOrEqual(Processer::PROCESS_NAME_MAX_LENGTH, \strlen($result));
    }

    public function testNormalizeNamePortStyle(): void
    {
        self::assertSame('weline-worker-port-9980', Processer::normalizeName('weline-worker-port-9980'));
        self::assertSame('worker-port-9980', Processer::normalizeName('worker.port.9980'));
    }

    /* ---------- generateProcessName ---------- */

    public function testGenerateProcessNameFromCommandWithNameParam(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        self::assertSame('weline-worker-port-9980', Processer::generateProcessName($cmd));
    }

    public function testGenerateProcessNameAddsWelinePrefixWhenMissing(): void
    {
        $cmd = 'php worker.php --name=my-worker';
        self::assertSame('weline-my-worker', Processer::generateProcessName($cmd));
    }

    public function testGenerateProcessNameFromCommandWithoutName(): void
    {
        $cmd = 'php worker.php --port=9980';
        $name = Processer::generateProcessName($cmd);
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX, $name);
        self::assertStringContainsString('9980', $name);
    }

    public function testGenerateProcessNameEmptyCommandReturnsUnknownWithTimestamp(): void
    {
        $name = Processer::generateProcessName('');
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX . 'unknown-', $name);
    }

    /* ---------- ensureProcessName ---------- */

    public function testEnsureProcessNameWhenNamePresentLeavesCommandUnchanged(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        $result = Processer::ensureProcessName($cmd);
        self::assertSame($cmd, $result['command']);
        self::assertSame('weline-worker-port-9980', $result['name']);
    }

    public function testEnsureProcessNameWhenNameMissingAppendsName(): void
    {
        $cmd = 'php worker.php --port=9980';
        $result = Processer::ensureProcessName($cmd);
        self::assertStringContainsString('--name=', $result['command']);
        self::assertNotSame($cmd, $result['command']);
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX, $result['name']);
    }

    public function testEnsureProcessNameShortFormatName(): void
    {
        $cmd = 'php script.php -name=weline-foo';
        $result = Processer::ensureProcessName($cmd);
        self::assertSame($cmd, $result['command']);
        self::assertSame('weline-foo', $result['name']);
    }

    /* ---------- getSearchableIdentifier ---------- */

    public function testGetSearchableIdentifierFromPnameWithNameParam(): void
    {
        $pname = '--name=weline-master-default-worker-1';
        self::assertSame('weline-master-default-worker-1', Processer::getSearchableIdentifier($pname));
    }

    public function testGetSearchableIdentifierFromPureName(): void
    {
        self::assertSame('weline-worker', Processer::getSearchableIdentifier('weline-worker'));
    }

    public function testGetSearchableIdentifierFromCommand(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        self::assertSame('weline-worker-port-9980', Processer::getSearchableIdentifier($cmd));
    }

    public function testIsWelineServerProcessAcceptsQuotedSharedSidecarName(): void
    {
        $pid = 654320;
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::once())
            ->method('getProcessCommandLine')
            ->with($pid)
            ->willReturn('"C:\php\php.exe" "app/code/Weline/Server/bin/session_server.php" "127.0.0.1" "19970" "shared-session-19970" --instance-name="shared-session-19970" --shared-service=1 --name="weline-wls-session-shared-19970"');

        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);

        self::assertTrue(Processer::isWelineServerProcess($pid));
    }

    public function testIsWelineServerProcessAcceptsSharedSessionServerWithoutExplicitName(): void
    {
        $pid = 654321;
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::once())
            ->method('getProcessCommandLine')
            ->with($pid)
            ->willReturn('"C:\php\php.exe" "app/code/Weline/Server/bin/session_server.php" "127.0.0.1" "19970" "shared-session-19970" --instance-name="shared-session-19970" --shared-service=1');

        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);

        self::assertTrue(Processer::isWelineServerProcess($pid));
    }

    /* ---------- Driver (LSP/OCP) ---------- */

    public function testGetDriverReturnsProcessDriverInterface(): void
    {
        $driver = Processer::getDriver();
        self::assertInstanceOf(ProcessDriverInterface::class, $driver);
    }

    public function testGetDriverSupportsCurrentOs(): void
    {
        $driver = Processer::getDriver();
        self::assertTrue($driver->supports(), 'Driver must support current OS');
    }

    public function testGetDriverOsNameNonEmpty(): void
    {
        $driver = Processer::getDriver();
        self::assertNotEmpty($driver->getOsName());
    }

    public function testProcessDriverFactoryIsWindowsOrNot(): void
    {
        $isWin = ProcessDriverFactory::isWindows();
        self::assertIsBool($isWin);
        self::assertSame($isWin, Processer::isWindows());
    }

    public function testProcessDriverFactoryGetRegisteredDrivers(): void
    {
        $drivers = ProcessDriverFactory::getRegisteredDrivers();
        self::assertIsArray($drivers);
        self::assertGreaterThanOrEqual(1, \count($drivers));
        foreach ($drivers as $class) {
            self::assertTrue(\is_subclass_of($class, ProcessDriverInterface::class), "Driver $class must implement interface");
        }
    }

    /* ---------- Constants ---------- */

    public function testWelineProcessPrefixConstant(): void
    {
        self::assertSame('weline-', Processer::WELINE_PROCESS_PREFIX);
    }

    public function testProcessNameMaxLengthConstant(): void
    {
        self::assertGreaterThan(0, Processer::PROCESS_NAME_MAX_LENGTH);
    }

    public function testBuildWindowsBatchCreateScriptKeepsForegroundWindowsVisible(): void
    {
        $script = $this->invokePrivateStatic(Processer::class, 'buildWindowsBatchCreateScript', [
            [
                [
                    'key' => 'worker-foreground',
                    'command' => '"C:\php\php.exe" worker.php --name=weline-worker-visible',
                    'php' => 'C:\php\php.exe',
                    'arguments' => 'worker.php --name=weline-worker-visible',
                    'process_name' => 'weline-worker-visible',
                    'cwd' => 'C:\repo',
                    'enable_log' => true,
                    'foreground' => true,
                    'foreground_script' => 'C:\temp\weline-worker-visible.cmd',
                ],
                [
                    'key' => 'worker-hidden',
                    'command' => '"C:\php\php.exe" worker.php --name=weline-worker-hidden',
                    'php' => 'C:\php\php.exe',
                    'arguments' => 'worker.php --name=weline-worker-hidden',
                    'process_name' => 'weline-worker-hidden',
                    'cwd' => 'C:\repo',
                    'enable_log' => true,
                    'foreground' => false,
                ],
            ],
            'C:\temp\batch-result.txt',
            'C:\temp\batch-error.txt',
        ]);

        self::assertIsString($script);
        self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
        self::assertStringContainsString("FilePath = 'cmd.exe'", $script);
        self::assertStringContainsString('ArgumentList = @(\'/d\',\'/c\',\'"C:\temp\weline-worker-visible.cmd"\')', $script);
        self::assertStringContainsString("FilePath = 'C:\\php\\php.exe'", $script);
        self::assertStringContainsString("ArgumentList = @('worker.php','--name=weline-worker-hidden')", $script);
        self::assertSame(0, \substr_count($script, 'RedirectStandardOutput'));
        self::assertSame(0, \substr_count($script, 'RedirectStandardError'));
        self::assertSame(0, \substr_count($script, 'PassThru = $true'));
        self::assertStringContainsString('$results.Add("worker-hidden`t0")', $script);
    }

    public function testBuildWindowsBatchCreateScriptMarksForegroundPidForLaterResolution(): void
    {
        $script = $this->invokePrivateStatic(Processer::class, 'buildWindowsBatchCreateScript', [
            [[
                'key' => 'worker-foreground',
                'command' => '"C:\php\php.exe" worker.php --name=weline-worker-visible --launch-id=launch-visible',
                'php' => 'C:\php\php.exe',
                'arguments' => 'worker.php --name=weline-worker-visible --launch-id=launch-visible',
                'process_name' => 'weline-worker-visible',
                'cwd' => 'C:\repo',
                'enable_log' => true,
                'foreground' => true,
                'foreground_script' => 'C:\temp\weline-worker-visible.cmd',
            ]],
            'C:\temp\batch-result.txt',
            'C:\temp\batch-error.txt',
        ]);

        self::assertIsString($script);
        self::assertStringContainsString('$results.Add("worker-foreground`t0")', $script);
        self::assertStringContainsString('Start-Process @startArgs | Out-Null', $script);
    }

    public function testBuildWindowsBatchCreateScriptUsesExplicitArgumentArrayForBackgroundProcess(): void
    {
        $script = $this->invokePrivateStatic(Processer::class, 'buildWindowsBatchCreateScript', [
            [[
                'key' => 'worker-hidden',
                'command' => '"C:\php\php.exe" worker.php --name=weline-worker-hidden --launch-id=launch-visible',
                'php' => 'C:\php\php.exe',
                'arguments' => 'worker.php --name=weline-worker-hidden --launch-id=launch-visible',
                'argument_list' => ['worker.php', '--name=weline-worker-hidden', '--launch-id=launch-visible'],
                'process_name' => 'weline-worker-hidden',
                'cwd' => 'C:\repo',
                'enable_log' => true,
                'foreground' => false,
            ]],
            'C:\temp\batch-result.txt',
            'C:\temp\batch-error.txt',
        ]);

        self::assertIsString($script);
        self::assertStringContainsString(
            "ArgumentList = @('worker.php','--name=weline-worker-hidden','--launch-id=launch-visible')",
            $script
        );
    }

    public function testBuildWindowsBatchCreateScriptKeepsPassThruOnlyForBlockingBackgroundProcess(): void
    {
        $script = $this->invokePrivateStatic(Processer::class, 'buildWindowsBatchCreateScript', [
            [[
                'key' => 'worker-blocking',
                'command' => '"C:\php\php.exe" worker.php --name=weline-worker-blocking --launch-id=launch-blocking',
                'php' => 'C:\php\php.exe',
                'arguments' => 'worker.php --name=weline-worker-blocking --launch-id=launch-blocking',
                'argument_list' => ['worker.php', '--name=weline-worker-blocking', '--launch-id=launch-blocking'],
                'process_name' => 'weline-worker-blocking',
                'cwd' => 'C:\repo',
                'enable_log' => true,
                'block' => true,
                'foreground' => false,
            ]],
            'C:\temp\batch-result.txt',
            'C:\temp\batch-error.txt',
        ]);

        self::assertIsString($script);
        self::assertStringContainsString('PassThru = $true', $script);
        self::assertStringContainsString('RedirectStandardOutput', $script);
        self::assertStringContainsString('RedirectStandardError', $script);
        self::assertStringContainsString('$p = Start-Process @startArgs', $script);
        self::assertStringContainsString('$results.Add("worker-blocking`t" + [string]$p.Id)', $script);
    }

    public function testTokenizeCommandLineArgumentsPreservesQuotedWindowsScriptPathBackslashes(): void
    {
        $tokens = $this->invokePrivateStatic(Processer::class, 'tokenizeCommandLineArguments', [
            '"E:\WelineFramework\DEV-workspace\var\tmp\codex-processer-child.php" --label=tokenize --name=codex-repro',
        ]);

        self::assertSame([
            'E:\WelineFramework\DEV-workspace\var\tmp\codex-processer-child.php',
            '--label=tokenize',
            '--name=codex-repro',
        ], $tokens);
    }

    public function testBuildWindowsDetachedPhpArgvFromCommandPreservesQuotedWindowsBackslashes(): void
    {
        $argv = $this->invokePrivateStatic(Processer::class, 'buildWindowsDetachedPhpArgvFromCommand', [
            '"' . PHP_BINARY . '" "E:\WelineFramework\DEV-workspace\app\code\Weline\Server\bin\dispatcher.php" 127.0.0.1 443 default --name=weline-test-dispatcher',
        ]);

        self::assertSame(PHP_BINARY, $argv[0]);
        self::assertSame('E:\WelineFramework\DEV-workspace\app\code\Weline\Server\bin\dispatcher.php', $argv[1]);
        self::assertSame('127.0.0.1', $argv[2]);
        self::assertSame('443', $argv[3]);
        self::assertSame('default', $argv[4]);
        self::assertSame('--name=weline-test-dispatcher', $argv[5]);
    }

    public function testShouldTryManagedProcessReuseIgnoresForegroundFlag(): void
    {
        self::assertTrue($this->invokePrivateStatic(Processer::class, 'shouldTryManagedProcessReuse', [true, false]));
        self::assertTrue($this->invokePrivateStatic(Processer::class, 'shouldTryManagedProcessReuse', [true, true]));
        self::assertFalse($this->invokePrivateStatic(Processer::class, 'shouldTryManagedProcessReuse', [false, false]));
        self::assertFalse($this->invokePrivateStatic(Processer::class, 'shouldTryManagedProcessReuse', [false, true]));
    }

    public function testWindowsFastDetachedBatchCreateFallbackStaysDisabled(): void
    {
        $enabled = $this->invokePrivateStatic(Processer::class, 'shouldUseWindowsFastDetachedBatchCreate', [[
            'worker-1' => [
                'command' => '"C:\php\php.exe" worker.php --name=weline-worker-1',
                'block' => false,
                'foreground' => false,
            ],
            'worker-2' => [
                'command' => '"C:\php\php.exe" worker.php --name=weline-worker-2',
                'block' => false,
                'foreground' => false,
            ],
        ]]);

        self::assertFalse($enabled);
    }

    public function testBuildWindowsForegroundStartCommandUsesCmdStartLauncher(): void
    {
        $command = $this->invokePrivateStatic(Processer::class, 'buildWindowsForegroundStartCommand', [
            'C:\temp\weline-worker-visible.cmd',
            'C:\repo',
            'weline-worker-visible',
        ]);

        self::assertIsString($command);
        self::assertStringContainsString('start "weline-worker-visible"', $command);
        self::assertStringContainsString('cmd.exe /d /c', $command);
        self::assertStringContainsString('weline-worker-visible.cmd', $command);
    }

    public function testWriteWindowsForegroundLauncherScriptBuildsSelfDeletingCmdFile(): void
    {
        $scriptPath = $this->invokePrivateStatic(Processer::class, 'writeWindowsForegroundLauncherScript', [
            'C:\php\php.exe',
            'worker.php --name=weline-worker-visible --launch-id=launch-visible',
            'C:\repo',
        ]);

        self::assertIsString($scriptPath);
        self::assertFileExists($scriptPath);

        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringContainsString('cd /d "C:\repo"', $script);
            self::assertStringContainsString('"C:\php\php.exe" worker.php --name=weline-worker-visible --launch-id=launch-visible', $script);
            self::assertStringContainsString('del "%~f0"', $script);
        } finally {
            @\unlink($scriptPath);
        }
    }

    public function testNormalizeWindowsForegroundArgumentsUnquotesStableIdentityFlags(): void
    {
        $arguments = '"C:\repo\worker.php" "127.0.0.1" "9982" --launch-id="worker-1-abc123" --name="weline-worker-1" --epoch="1" --label="needs space"';
        $normalized = $this->invokePrivateStatic(Processer::class, 'normalizeWindowsForegroundArguments', [$arguments]);

        self::assertSame(
            '"C:\repo\worker.php" "127.0.0.1" "9982" --launch-id=worker-1-abc123 --name=weline-worker-1 --epoch=1 --label="needs space"',
            $normalized
        );
    }

    public function testCollectBlockingLaunchItemsNeedingPidResolutionSkipsNonBlockingEntries(): void
    {
        $items = [
            ['key' => 'phase-one-visible', 'block' => false],
            ['key' => 'worker-hidden', 'block' => true],
            ['key' => 'worker-already-has-pid', 'block' => true],
        ];
        $resolved = $this->invokePrivateStatic(Processer::class, 'collectBlockingLaunchItemsNeedingPidResolution', [
            $items,
            [
                'worker-already-has-pid' => 4321,
            ],
        ]);

        self::assertSame([
            ['key' => 'worker-hidden', 'block' => true],
        ], $resolved);
    }

    public function testBuildWindowsBatchSignalCommandUsesSingleTaskkillInvocation(): void
    {
        $command = $this->invokePrivateStatic(Processer::class, 'buildWindowsBatchSignalCommand', [[101, 202, 303]]);

        self::assertSame('taskkill /F /PID 101 /PID 202 /PID 303 2>NUL', $command);
    }

    public function testBuildWindowsAsyncBatchSignalCommandUsesDetachedStartWrapper(): void
    {
        $command = $this->invokePrivateStatic(Processer::class, 'buildWindowsAsyncBatchSignalCommand', [[101, 202, 303]]);

        self::assertSame(
            'cmd /d /c start "" /B cmd /d /c "taskkill /F /PID 101 /PID 202 /PID 303 1>NUL 2>NUL"',
            $command
        );
    }

    public function testPartitionRunningPidsSeparatesExitedProcessesBeforeFallbackKill(): void
    {
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::exactly(2))
            ->method('isRunningByPid')
            ->willReturnMap([
                [101, false],
                [202, true],
            ]);

        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);

        $state = $this->invokePrivateStatic(Processer::class, 'partitionRunningPids', [[101, 202]]);

        self::assertSame([
            'running' => [202],
            'exited' => [101],
        ], $state);
    }

    public function testBatchCheckRunningUsesBatchProcessInfoQueryForLargePidSets(): void
    {
        $pids = \range(101, 117);
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::once())
            ->method('batchGetProcessInfo')
            ->with($pids)
            ->willReturn(\array_fill_keys($pids, ['exists' => false]));
        $driver->expects(self::never())
            ->method('isRunningByPid');

        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);

        self::assertSame(
            \array_fill_keys($pids, false),
            Processer::batchCheckRunning($pids)
        );
    }

    public function testDoesPidMatchRecordedIdentityAcceptsForegroundMasterByCommandLineHash(): void
    {
        $pid = 654321;
        $commandLine = '"C:\php\php.exe" bin/w s:start -r -f -frontend -p 9982';

        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::once())
            ->method('isRunningByPid')
            ->with($pid)
            ->willReturn(true);
        $driver->expects(self::once())
            ->method('getProcessCommandLine')
            ->with($pid)
            ->willReturn($commandLine);

        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);

        self::assertTrue($this->invokePrivateStatic(Processer::class, 'doesPidMatchRecordedIdentity', [
            $pid,
            [
                'pname' => '--name=weline-wls-master-default',
                'process_name' => 'weline-wls-master-default',
                'command_line_hash' => \sha1($commandLine),
            ],
        ]));
    }

    public function testFilterPidIndexExistingJsonPathsDropsMissingJsonRecords(): void
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'wls-pid-');
        self::assertNotFalse($tempFile);

        try {
            $filtered = $this->invokePrivateStatic(Processer::class, 'filterPidIndexExistingJsonPaths', [[
                101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => $tempFile],
                202 => ['pname' => '--name=weline-wls-worker-default-1', 'jsonPath' => $tempFile . '.missing'],
                303 => ['pname' => '', 'jsonPath' => $tempFile],
            ]]);

            self::assertSame([
                101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => $tempFile],
            ], $filtered);
        } finally {
            @\unlink($tempFile);
        }
    }

    public function testFilterNameIndexByPidIndexRemovesHistoricalOrphanEntries(): void
    {
        $filtered = $this->invokePrivateStatic(Processer::class, 'filterNameIndexByPidIndex', [[
            '--name=weline-wls-master-default' => [
                ['pid' => 101, 'jsonPath' => 'var/process/pid/live-master.json'],
                ['pid' => 999, 'jsonPath' => 'var/process/pid/stale-master.json'],
            ],
            '--name=weline-wls-worker-default-1' => [
                ['pid' => 202, 'jsonPath' => 'var/process/pid/live-worker.json'],
                ['pid' => 303, 'jsonPath' => 'var/process/pid/stale-worker.json'],
            ],
        ], [
            101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => 'var/process/pid/live-master.json'],
            202 => ['pname' => '--name=weline-wls-worker-default-1', 'jsonPath' => 'var/process/pid/live-worker.json'],
        ]]);

        self::assertSame([
            '--name=weline-wls-master-default' => [
                ['pid' => 101, 'jsonPath' => 'var/process/pid/live-master.json'],
            ],
            '--name=weline-wls-worker-default-1' => [
                ['pid' => 202, 'jsonPath' => 'var/process/pid/live-worker.json'],
            ],
        ], $filtered);
    }

    private function invokePrivateStatic(string $class, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(null, $args);
    }
}
