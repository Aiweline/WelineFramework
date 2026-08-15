<?php

declare(strict_types=1);

namespace Weline\Framework\System\Process;

use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;
use Weline\Framework\Test\TestCore;

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
        self::assertStringContainsString("FilePath = 'C:\php\php.exe'", $script);
        self::assertStringContainsString("WorkingDirectory = 'C:\\repo'", $script);
        self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
        self::assertSame(2, \substr_count($script, 'Start-Process @startArgs'));
        self::assertStringContainsString("Add-WelineResult 'worker-foreground' ([int]\$p.Id)", $script);
        self::assertStringContainsString("Add-WelineResult 'worker-hidden' ([int]\$p.Id)", $script);
        self::assertSame(0, \substr_count($script, 'RedirectStandardOutput'));
        self::assertSame(0, \substr_count($script, 'RedirectStandardError'));
        self::assertSame(0, \substr_count($script, 'Start-Job'));
        self::assertStringContainsString('Remove-Item -LiteralPath $PSCommandPath -Force', $script);
    }

    public function testBuildWindowsBatchCreateScriptRecordsForegroundLauncherPid(): void
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
        self::assertStringContainsString('Start-Process @startArgs', $script);
        self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        self::assertStringContainsString("Add-WelineResult 'worker-foreground' ([int]\$p.Id)", $script);
        self::assertStringContainsString('--launch-id=launch-visible', $script);
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
        self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
        self::assertStringContainsString('$argList = @(', $script);
        self::assertStringContainsString("'worker.php'", $script);
        self::assertStringContainsString("'--name=weline-worker-hidden'", $script);
        self::assertStringContainsString("'--launch-id=launch-visible'", $script);
        self::assertStringContainsString('Start-Process @startArgs', $script);
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
        self::assertStringContainsString('Start-Process @startArgs', $script);
        self::assertStringContainsString("Add-WelineResult 'worker-blocking' ([int]\$p.Id)", $script);
    }

    public function testWriteWindowsStartScriptArgvUsesWmiDetachedProcessCreation(): void
    {
        $scriptPath = $this->invokePrivateStatic(Processer::class, 'writeWindowsStartScriptArgv', [
            ['C:\php\php.exe', 'bin\w', 'server:start', 'default', '--master-only'],
            'C:\repo',
        ]);

        self::assertIsString($scriptPath);
        self::assertFileExists($scriptPath);

        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringContainsString('$phpExe = \'C:\php\php.exe\'', $script);
            self::assertStringContainsString('$argList = @(', $script);
            self::assertStringContainsString("'bin\w'", $script);
            self::assertStringContainsString("'server:start'", $script);
            self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
            self::assertStringContainsString('Start-Process @startArgs', $script);
            self::assertStringContainsString('[Console]::Out.WriteLine([string]$process.Id)', $script);
            self::assertStringNotContainsString('RedirectStandardOutput', $script);
        } finally {
            @\unlink($scriptPath);
        }
    }

    public function testWriteWindowsStartScriptUsesWmiDetachedProcessCreation(): void
    {
        $scriptPath = $this->invokePrivateStatic(Processer::class, 'writeWindowsStartScript', [
            'C:\php\php.exe',
            'bin\w server:start default --master-only',
            'C:\repo',
        ]);

        self::assertIsString($scriptPath);
        self::assertFileExists($scriptPath);

        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringContainsString('$phpExe = \'C:\php\php.exe\'', $script);
            self::assertStringContainsString('$arguments = \'bin\w server:start default --master-only\'', $script);
            self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
            self::assertStringContainsString('Start-Process @startArgs', $script);
            self::assertStringContainsString('[Console]::Out.WriteLine([string]$process.Id)', $script);
            self::assertStringNotContainsString('RedirectStandardError', $script);
        } finally {
            @\unlink($scriptPath);
        }
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

    public function testWindowsFastDetachedBatchCreateFallbackHelperRemoved(): void
    {
        self::assertFalse((new \ReflectionClass(Processer::class))->hasMethod('shouldUseWindowsFastDetachedBatchCreate'));
    }

    public function testWriteWindowsForegroundPhpStartScriptReturnsLauncherPid(): void
    {
        $scriptPath = $this->invokePrivateStatic(Processer::class, 'writeWindowsForegroundPhpStartScript', [
            'C:\php\php.exe',
            ['worker.php', '--name=weline-worker-visible'],
            'C:\repo',
            'weline-worker-visible',
        ]);

        self::assertIsString($scriptPath);
        self::assertFileExists($scriptPath);

        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringContainsString('$windowTitle = \'weline-worker-visible\'', $script);
            self::assertStringContainsString('WindowStyle = \'Normal\'', $script);
            self::assertStringContainsString('PassThru = $true', $script);
            self::assertStringContainsString('Start-Process @startArgs', $script);
            self::assertStringContainsString("'worker.php'", $script);
            self::assertStringContainsString("'--name=weline-worker-visible'", $script);
            self::assertStringContainsString('[Console]::Out.WriteLine([string]$p.Id)', $script);
        } finally {
            @\unlink($scriptPath);
        }
    }

    public function testParseWindowsBatchCreatePidMapIgnoresZeroRows(): void
    {
        $pidMap = $this->invokePrivateStatic(Processer::class, 'parseWindowsBatchCreatePidMap', [
            "dispatcher\t1234\nworker\t0\nbad-row\n",
        ]);

        self::assertSame(['dispatcher' => 1234], $pidMap);
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

    public function testCollectLaunchItemsNeedingPidResolutionIncludesNonBlockingForFastPidAdoption(): void
    {
        $items = [
            ['key' => 'phase-one-visible', 'block' => false],
            ['key' => 'worker-hidden', 'block' => true],
            ['key' => 'worker-already-has-pid', 'block' => false],
        ];
        $resolved = $this->invokePrivateStatic(Processer::class, 'collectLaunchItemsNeedingPidResolution', [
            $items,
            [
                'worker-already-has-pid' => 4321,
            ],
            false,
        ]);

        self::assertSame([
            ['key' => 'phase-one-visible', 'block' => false],
            ['key' => 'worker-hidden', 'block' => true],
        ], $resolved);
    }

    public function testCollectLaunchItemsRechecksChildOwnedPidAfterWindowsEmulationTransition(): void
    {
        $items = [[
            'key' => 'worker-emulated',
            'block' => false,
            'child_owns_pid' => true,
            'command' => 'worker.php --name=weline-worker-emulated'
                . ' --launch-id=0123456789abcdef0123456789abcdef'
                . ' --slot-id=worker#1'
                . ' --lease-id=fedcba9876543210fedcba9876543210',
        ]];

        $resolved = $this->invokePrivateStatic(Processer::class, 'collectLaunchItemsNeedingPidResolution', [
            $items,
            ['worker-emulated' => 4321],
            false,
        ]);

        self::assertSame($items, $resolved);
    }

    public function testChildOwnedPidSelectionPrefersRegistrationAndRejectsAnExitedLauncher(): void
    {
        $currentPid = \getmypid();
        self::assertGreaterThan(0, $currentPid);

        self::assertSame($currentPid, $this->invokePrivateStatic(Processer::class, 'selectWindowsBatchCreatePid', [
            ['child_owns_pid' => true],
            $currentPid,
            0,
        ]));
        self::assertSame(0, $this->invokePrivateStatic(Processer::class, 'selectWindowsBatchCreatePid', [
            ['child_owns_pid' => true],
            2147483647,
            0,
        ]));
        self::assertSame($currentPid, $this->invokePrivateStatic(Processer::class, 'selectWindowsBatchCreatePid', [
            ['child_owns_pid' => true],
            4321,
            $currentPid,
        ]));
    }

    public function testRegisteredPidResolutionBindsNameLaunchIdAndIdentityKey(): void
    {
        $command = 'worker.php --name=weline-worker-emulated --launch-id=0123456789abcdef0123456789abcdef';
        $item = [
            'command' => $command,
            'process_name' => 'weline-worker-emulated',
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ];
        $record = [
            'pname_key' => '--name=weline-worker-emulated',
            'process_name' => 'weline-worker-emulated',
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ];

        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchRegisteredPidMatchesLaunchItem',
            [$record, $item],
        ));

        $record['launch_id'] = 'fedcba9876543210fedcba9876543210';
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchRegisteredPidMatchesLaunchItem',
            [$record, $item],
        ));
    }

    public function testChildOwnedWindowsLaunchDoesNotCommitTheFirstRegisteredTransitionPid(): void
    {
        $command = 'worker.php --name=weline-worker-emulated'
            . ' --launch-id=0123456789abcdef0123456789abcdef';
        $item = [
            'command' => $command,
            'process_name' => 'weline-worker-emulated',
            'launch_id' => '0123456789abcdef0123456789abcdef',
            'child_owns_pid' => true,
        ];
        $record = [
            'pname_key' => '--name=weline-worker-emulated',
            'process_name' => 'weline-worker-emulated',
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ];

        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchRegisteredPidCanResolveLaunchItem',
            [$record, $item],
        ));
        $item['child_owns_pid'] = false;
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchRegisteredPidCanResolveLaunchItem',
            [$record, $item],
        ));
    }

    public function testWindowsTransitionPidMatcherRequiresEveryOpaqueChildIdentity(): void
    {
        $command = '"C:\\Tools\\PHP84\\php.exe" worker.php'
            . ' --instance-name=isolated-win'
            . ' --master-pid=9556'
            . ' --epoch=9'
            . ' --launch-id=0123456789abcdef0123456789abcdef'
            . ' --slot-id=worker#1'
            . ' --lease-id=fedcba9876543210fedcba9876543210'
            . ' --name=weline-worker-emulated';
        $item = [
            'command' => $command,
            'process_name' => 'weline-worker-emulated',
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ];

        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [$command, $item],
        ));
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [\str_replace('--master-pid=9556', '--master-pid=9557', $command), $item],
        ));
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [\str_replace('fedcba9876543210fedcba9876543210', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $command), $item],
        ));
    }

    public function testWindowsEmulatedBatchUsesExactBoundedTransitionAuthority(): void
    {
        $nativeHelperParallelism = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateHelperParallelism',
            [6, true, false],
        );
        $emulatedHelperParallelism = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateHelperParallelism',
            [6, true, true],
        );
        $nativeResultBudget = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateNonBlockingResultRowTimeout',
            [6, true, false, 0.0],
        );
        $emulatedResultBudget = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateNonBlockingResultRowTimeout',
            [6, true, true, 0.0],
        );
        $nativePidBudget = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateNonBlockingPidResolutionTimeout',
            [6, true, false, 0.0],
        );
        $emulatedPidBudget = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateNonBlockingPidResolutionTimeout',
            [6, true, true, 0.0],
        );
        $emulatedTransitionScanDelay = $this->invokePrivateStatic(
            Processer::class,
            'resolveWindowsBatchCreateTransitionScanDelay',
            [$emulatedPidBudget, true],
        );

        self::assertSame(4, $nativeHelperParallelism);
        self::assertSame(1, $emulatedHelperParallelism);
        self::assertLessThanOrEqual(0.6, $nativeResultBudget);
        self::assertGreaterThanOrEqual(24.0, $emulatedResultBudget);
        self::assertLessThanOrEqual(30.0, $emulatedResultBudget);
        self::assertLessThanOrEqual(0.8, $nativePidBudget);
        self::assertSame(8.0, $emulatedPidBudget);
        self::assertSame(4.0, $emulatedTransitionScanDelay);
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchEmulatedRuntimeProfileActive',
            [[
                'profile' => 'native',
                'requires_jit_isolation' => false,
            ]],
        ));
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchEmulatedRuntimeProfileActive',
            [[
                'profile' => 'windows-arm64-x64-cli-safe-v2',
                'requires_jit_isolation' => true,
            ]],
        ));
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchEmulatedRuntimeProfileActive',
            [[
                'profile' => 'unexpected-runtime-profile',
                'requires_jit_isolation' => true,
            ]],
        ));

        $command = '"C:\\Tools\\PHP84\\php.exe" dispatcher.php'
            . ' --launch-id=0123456789abcdef0123456789abcdef'
            . ' --slot-id=dispatcher#1'
            . ' --lease-id=fedcba9876543210fedcba9876543210'
            . ' --name=weline-wls-dispatcher-isolated-win';
        $item = [
            'command' => $command,
            'process_name' => 'weline-wls-dispatcher-isolated-win',
            'launch_id' => '0123456789abcdef0123456789abcdef',
            'child_owns_pid' => false,
        ];
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchTransitionPidRequiresResolution',
            [$item, false],
        ));
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchTransitionPidRequiresResolution',
            [$item, true],
        ));
        $item['command'] = \str_replace(
            ' --lease-id=fedcba9876543210fedcba9876543210',
            '',
            $command,
        );
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchTransitionPidRequiresResolution',
            [$item, true],
        ));
    }

    public function testWindowsEmulatedBatchCarriesLaunchParentAndOnlySelectsItsExactLeaf(): void
    {
        $command = 'child.php'
            . ' --name=weline-wls-worker-parent-bound'
            . ' --launch-id=0123456789abcdef0123456789abcdef'
            . ' --slot-id=worker#1'
            . ' --lease-id=fedcba9876543210fedcba9876543210'
            . ' --epoch=1'
            . ' --master-pid=900';
        $item = [
            'key' => 'worker-1',
            'command' => $command,
            'process_name' => 'weline-wls-worker-parent-bound',
            'child_owns_pid' => true,
            'block' => false,
        ];

        $pending = $this->invokePrivateStatic(
            Processer::class,
            'collectLaunchItemsNeedingPidResolution',
            [[$item], ['worker-1' => 4100], false, true, ['worker-1' => 4000]],
        );
        self::assertCount(1, $pending);
        self::assertSame(4100, $pending[0]['launcher_pid'] ?? null);
        self::assertSame(4000, $pending[0]['topology_root_pid'] ?? null);

        $eligible = ['worker-1' => $pending[0] + [
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ]];
        $parentOnly = [
            4000 => ['pid' => 4000, 'parent_pid' => 900, 'command_line' => 'powershell.exe batch.ps1'],
            4100 => ['pid' => 4100, 'parent_pid' => 4000, 'command_line' => $command],
        ];
        self::assertSame(['worker-1' => 4100], $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionPidsFromSnapshot',
            [$eligible, $parentOnly, true],
        ));

        $parentAndLeaf = $parentOnly + [
            4101 => ['pid' => 4101, 'parent_pid' => 4100, 'command_line' => $command],
        ];
        self::assertSame(['worker-1' => 4101], $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionPidsFromSnapshot',
            [$eligible, $parentAndLeaf, true],
        ));

        $brokerAndSibling = [
            4000 => ['pid' => 4000, 'parent_pid' => 900, 'command_line' => 'powershell.exe batch.ps1'],
            4200 => ['pid' => 4200, 'parent_pid' => 4000, 'command_line' => $command],
        ];
        self::assertSame(['worker-1' => 4200], $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionPidsFromSnapshot',
            [$eligible, $brokerAndSibling, true],
        ));
    }

    public function testWindowsExactMasterArgvParticipatesInBrokerBoundTopologyResolution(): void
    {
        $launchId = '0123456789abcdef0123456789abcdef';
        $name = 'weline-wls-master-isolated-win';
        $instance = 'isolated-win';
        $command = 'windows-isolated-exact-argv'
            . ' --name=' . $name
            . ' --launch-id=' . $launchId;
        $item = [
            'command' => $command,
            'process_name' => $name,
            'launch_id' => $launchId,
            'child_owns_pid' => true,
            'exact_argv' => true,
            'argument_list' => [
                '-d',
                'opcache.jit=0',
                'C:\\repo\\bin\\w',
                'server:start',
                $instance,
                '--master-only',
                '--name=' . $name,
                '--launch-id=' . $launchId,
            ],
        ];
        $liveCommand = '"C:\\Tools\\PHP84\\php.exe"'
            . ' -d opcache.jit=0 C:\\repo\\bin\\w server:start ' . $instance
            . ' --master-only'
            . ' --name=' . $name
            . ' --launch-id=' . $launchId;

        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchTransitionPidRequiresResolution',
            [$item, true],
        ));
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [$liveCommand, $item],
        ));
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [\str_replace('server:start isolated-win', 'server:start other-win', $liveCommand), $item],
        ));
    }

    public function testWindowsManagedBatchPendingIdentityPreservesExactArgv(): void
    {
        $arguments = [
            '-d',
            'opcache.jit=0',
            'C:\\repo\\bin\\w',
            'server:start',
            'isolated-win',
            '--master-only',
            '--name=weline-wls-master-isolated-win',
            '--launch-id=0123456789abcdef0123456789abcdef',
        ];
        $pending = $this->invokePrivateStatic(
            Processer::class,
            'normalizeWindowsBatchPendingLaunchItem',
            [[
                'key' => 'master',
                'command' => 'windows-isolated-exact-argv'
                    . ' --name=weline-wls-master-isolated-win'
                    . ' --launch-id=0123456789abcdef0123456789abcdef',
                'process_name' => 'weline-wls-master-isolated-win',
                'child_owns_pid' => true,
                'launcher_pid' => 4100,
                'topology_root_pid' => 4000,
                'exact_argv' => true,
                'argument_list' => $arguments,
            ]],
        );

        self::assertIsArray($pending);
        self::assertTrue($pending['exact_argv'] ?? false);
        self::assertSame($arguments, $pending['argument_list'] ?? null);
        self::assertSame(4100, $pending['launcher_pid'] ?? null);
        self::assertSame(4000, $pending['topology_root_pid'] ?? null);
    }

    public function testCommittedWindowsIsolatedBatchSubmissionSkipsGlobalBrokerLookup(): void
    {
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsIsolatedBatchSubmissionNeedsBrokerLookup',
            [['state' => 'committed', 'broker_pid' => 4100]],
        ));
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsIsolatedBatchSubmissionNeedsBrokerLookup',
            [['state' => 'committed', 'broker_pid' => 0]],
        ));
        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsIsolatedBatchSubmissionNeedsBrokerLookup',
            [['state' => 'ambiguous', 'broker_pid' => 4100]],
        ));

        self::assertSame([
            'dispatcher' => 4100,
            'worker-1' => 4100,
        ], $this->invokePrivateStatic(
            Processer::class,
            'windowsBatchTopologyRootPidMap',
            [[
                [
                    'isolated_submission_state' => 'committed',
                    'isolated_broker_pid' => 4100,
                    'launch_items' => [
                        ['key' => 'dispatcher'],
                        ['key' => 'worker-1'],
                    ],
                ],
                [
                    'isolated_submission_state' => 'ambiguous',
                    'isolated_broker_pid' => 4200,
                    'launch_items' => [['key' => 'worker-2']],
                ],
            ]],
        ));
    }

    public function testWindowsTransitionPidMatcherAcceptsDispatcherWithoutSyntheticInstanceOption(): void
    {
        $command = '"C:\\Tools\\PHP84\\php.exe" dispatcher.php 127.0.0.1 29522 15504 4 isolated-win'
            . ' --control-port=58967'
            . ' --master-pid=5328'
            . ' --epoch=13'
            . ' --launch-id=0123456789abcdef0123456789abcdef'
            . ' --slot-id=dispatcher#1'
            . ' --lease-id=fedcba9876543210fedcba9876543210'
            . ' --windows-listener-wls-instance=isolated-win'
            . ' --name=weline-wls-dispatcher-isolated-win-p974cbfc5';
        $item = [
            'command' => $command,
            'process_name' => 'weline-wls-dispatcher-isolated-win-p974cbfc5',
            'launch_id' => '0123456789abcdef0123456789abcdef',
        ];

        self::assertTrue($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [$command, $item],
        ));
        self::assertFalse($this->invokePrivateStatic(
            Processer::class,
            'windowsBatchLiveCommandMatchesLaunchItem',
            [\str_replace(
                '--windows-listener-wls-instance=isolated-win',
                '--windows-listener-wls-instance=other-win',
                $command,
            ), $item],
        ));
    }

    public function testWindowsTransitionPidSelectionRequiresAUniqueExactCommandLeaf(): void
    {
        self::assertSame(8064, $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionLeafPid',
            [[2988, 8064], [2988 => 2476, 8064 => 2988]],
        ));
        self::assertSame(9128, $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionLeafPid',
            [[2988, 8064, 9128], [2988 => 2476, 8064 => 2988, 9128 => 8064]],
        ));
        self::assertSame(0, $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionLeafPid',
            [[2988, 8064], [2988 => 2476, 8064 => 2476]],
        ));
    }

    public function testWindowsBatchTransitionSnapshotResolvesSixUniqueLeavesInOnePass(): void
    {
        $items = [];
        $snapshot = [];
        $expected = [];
        for ($index = 1; $index <= 6; $index++) {
            $name = 'weline-wls-child-batch-' . $index;
            $launchId = \str_pad((string)$index, 32, (string)$index);
            $parentPid = 1000 + ($index * 10);
            $leafPid = $parentPid + 1;
            $command = 'child.php'
                . ' --name=' . $name
                . ' --launch-id=' . $launchId
                . ' --slot-id=worker#' . $index
                . ' --lease-id=' . $launchId
                . ' --epoch=1'
                . ' --master-pid=900';

            $items['child-' . $index] = [
                'command' => $command,
                'process_name' => $name,
                'launch_id' => $launchId,
            ];
            $snapshot[$parentPid] = [
                'pid' => $parentPid,
                'parent_pid' => 900,
                'command_line' => $command,
            ];
            $snapshot[$leafPid] = [
                'pid' => $leafPid,
                'parent_pid' => $parentPid,
                'command_line' => $command,
            ];
            $expected['child-' . $index] = $leafPid;
        }
        $snapshot[9099] = [
            'pid' => 9099,
            'parent_pid' => 900,
            'command_line' => \str_replace(
                '--lease-id=11111111111111111111111111111111',
                '--lease-id=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                $items['child-1']['command'],
            ),
        ];

        self::assertSame($expected, $this->invokePrivateStatic(
            Processer::class,
            'selectWindowsBatchTransitionPidsFromSnapshot',
            [$items, $snapshot],
        ));
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

    public function testBuildWindowsAsyncBatchTreeKillCommandUsesDetachedStartWrapper(): void
    {
        $command = $this->invokePrivateStatic(Processer::class, 'buildWindowsAsyncBatchTreeKillCommand', [[101, 202, 303]]);

        self::assertSame(
            'cmd /d /c start "" /B cmd /d /c "taskkill /F /T /PID 101 /PID 202 /PID 303 1>NUL 2>NUL"',
            $command
        );
    }

    public function testPartitionRunningPidsSeparatesExitedProcessesBeforeFallbackKill(): void
    {
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->expects(self::once())
            ->method('batchGetProcessInfo')
            ->with([101, 202])
            ->willReturn([
                101 => ['exists' => false],
                202 => ['exists' => true],
            ]);
        $driver->expects(self::never())
            ->method('isRunningByPid');

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
