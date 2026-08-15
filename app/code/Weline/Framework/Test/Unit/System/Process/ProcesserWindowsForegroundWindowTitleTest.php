<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserWindowsForegroundWindowTitleTest extends TestCase
{
    public function testWindowsIsolatedBatchUsesNativeCscriptAsTheWmiIsolationSubmitter(): void
    {
        $source = $this->methodSource('buildWindowsWmiBatchSubmitterScript');

        self::assertStringContainsString('winmgmts:', $source);
        self::assertStringContainsString('Win32_Process', $source);
        self::assertStringContainsString('.Create(', $source);
        self::assertStringContainsString('WELINE_ISOLATED_SUBMIT', $source);
        self::assertStringContainsString('WScript.Quit', $source);
        self::assertStringNotContainsString('CreateProcessW', $source);
        self::assertStringNotContainsString('FFI', $source);
        self::assertStringNotContainsString('Start-Process', $source);
    }

    public function testChildOwnedRegistrationCanResolveOnlyTheExactBrokerLauncherPid(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'windowsBatchRegisteredPidCanResolveLaunchItem');
        $record = [
            'pid' => 4242,
            'launch_id' => '0123456789abcdef0123456789abcdef',
            'process_name' => 'weline-wls-worker-contract-1',
            'pname_key' => '--name=weline-wls-worker-contract-1',
        ];
        $item = [
            'command' => 'php worker.php'
                . ' --launch-id=0123456789abcdef0123456789abcdef'
                . ' --name=weline-wls-worker-contract-1',
            'process_name' => 'weline-wls-worker-contract-1',
            'launch_id' => '0123456789abcdef0123456789abcdef',
            'child_owns_pid' => true,
            'launcher_pid' => 4242,
        ];

        self::assertTrue($method->invoke(null, $record, $item));
        $item['launcher_pid'] = 4243;
        self::assertFalse($method->invoke(null, $record, $item));
    }

    public function testExactMasterArgvIdentityUsesTheManagedCommandLaunchId(): void
    {
        $launchId = '0123456789abcdef0123456789abcdef';
        $processName = 'weline-wls-master-ai-test-win';
        $method = new \ReflectionMethod(Processer::class, 'windowsBatchExactMasterArgvIdentity');
        $identity = $method->invoke(null, [
            'command' => 'windows-isolated-exact-argv'
                . ' --name=' . $processName
                . ' --launch-id=' . $launchId,
            'process_name' => $processName,
            'argument_list' => [
                'C:\\wls\\bin\\w',
                'server:start',
                'ai-test-win',
                '--master-only',
                '--name=' . $processName,
                '--launch-id=' . $launchId,
            ],
            'exact_argv' => true,
            'child_owns_pid' => true,
        ]);

        self::assertSame([
            'instance' => 'ai-test-win',
            'name' => $processName,
            'launch_id' => $launchId,
            'script' => 'C:\\wls\\bin\\w',
        ], $identity);
    }

    public function testExplicitWindowTitleOverridesManagedProcessName(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsForegroundWindowTitle');
        $method->setAccessible(true);

        $title = $method->invoke(
            null,
            'php bin/w server:start default --master-only --frontend --name=weline-wls-master-default --window-title=weline-wls-master-default-frontend'
        );

        self::assertSame('weline-wls-master-default-frontend', $title);
    }

    public function testWindowTitleFallsBackToManagedProcessName(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsForegroundWindowTitle');
        $method->setAccessible(true);

        $title = $method->invoke(
            null,
            'php worker.php --name=weline-wls-worker-default-1 --frontend'
        );

        self::assertSame('weline-wls-worker-default-1', $title);
    }

    public function testExplicitWindowTitleDoesNotOverrideManagedTaskName(): void
    {
        $command = 'php bin/w server:start default --master-only --frontend --name=weline-wls-master-default --window-title=weline-wls-master-default-frontend';

        self::assertSame('weline-wls-master-default', Processer::getTaskName($command));
    }

    public function testForegroundPhpStartScriptDoesNotUseCmdWrapper(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'writeWindowsForegroundPhpStartScript');
        $method->setAccessible(true);

        $scriptPath = $method->invoke(
            null,
            'php.exe',
            ['bin/w', 'server:start', 'default', '--master-only', '--frontend'],
            'E:\\WelineFramework\\DEV-workspace',
            'weline-wls-master-default-frontend'
        );

        self::assertIsString($scriptPath);
        try {
            $script = (string) \file_get_contents($scriptPath);
            self::assertStringNotContainsString('cmd.exe', $script);
            self::assertStringNotContainsString('cmd /d /c', $script);
            self::assertStringContainsString('Start-Process @startArgs', $script);
            self::assertStringContainsString("FilePath = \$phpExe", $script);
            self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        } finally {
            if (\is_file((string) $scriptPath)) {
                @\unlink((string) $scriptPath);
            }
        }
    }

    public function testWindowsBatchCreateScriptUsesPowerShellStartProcessDirectly(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsBatchCreateScript');
        $method->setAccessible(true);

        $script = $method->invoke(null, [
            [
                'key' => 'worker-1',
                'php' => 'php.exe',
                'arguments' => 'bin/worker.php 127.0.0.1 16895 default --name=weline-wls-worker-default-1',
                'argument_list' => ['bin/worker.php', '127.0.0.1', '16895', 'default', '--name=weline-wls-worker-default-1'],
                'process_name' => 'weline-wls-worker-default-1',
                'cwd' => 'E:\\WelineFramework\\DEV-workspace',
                'enable_log' => true,
                'stdout_log' => 'E:\\WelineFramework\\DEV-workspace\\var\\process\\worker.stdout.log',
                'stderr_log' => 'E:\\WelineFramework\\DEV-workspace\\var\\process\\worker.stderr.log',
                'block' => false,
                'foreground' => true,
            ],
            [
                'key' => 'session',
                'php' => 'php.exe',
                'arguments' => 'app/code/Weline/Server/bin/session_server.php 127.0.0.1 26422 default',
                'argument_list' => ['app/code/Weline/Server/bin/session_server.php', '127.0.0.1', '26422', 'default'],
                'process_name' => 'weline-wls-session-default',
                'cwd' => 'E:\\WelineFramework\\DEV-workspace',
                'enable_log' => true,
                'stdout_log' => 'E:\\WelineFramework\\DEV-workspace\\var\\process\\session.stdout.log',
                'stderr_log' => 'E:\\WelineFramework\\DEV-workspace\\var\\process\\session.stderr.log',
                'block' => false,
                'foreground' => false,
            ],
        ], 'C:\\Temp\\weline-result.txt', 'C:\\Temp\\weline-error.txt');

        self::assertIsString($script);
        self::assertStringNotContainsString('cmd.exe', $script);
        self::assertStringNotContainsString('cmd /d /c', $script);
        self::assertStringContainsString('Start-Process @startArgs', $script);
        self::assertStringNotContainsString('[int]$pid', $script);
        self::assertStringContainsString('[int]$welineProcessId', $script);
        self::assertStringContainsString('RedirectStandardOutput', $script);
        self::assertStringContainsString('RedirectStandardError', $script);
        self::assertStringContainsString("WindowStyle = 'Normal'", $script);
        self::assertStringContainsString("WindowStyle = 'Hidden'", $script);
        self::assertStringContainsString('[batch-start-error] ', $script);
    }

    public function testWindowsBatchCreateDoesNotScanProcessTableByDefault(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsBatchCreateNonBlockingPidResolutionTimeout');
        $method->setAccessible(true);

        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString(
            'system.processer.windows_batch_create_nonblocking_pid_resolution_timeout_sec',
            $source
        );
        self::assertStringContainsString('return 0.0;', $source);
    }

    public function testWindowsBatchCreateWaitsBrieflyForHelperRowsByDefault(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveWindowsBatchCreateNonBlockingResultRowTimeout');
        $method->setAccessible(true);

        $timeout = $method->invoke(null, 7);

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.6, $timeout);
    }

    public function testWindowsBatchCreateAlwaysUsesBoundedParallelHelpers(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'batchCreateWindows');
        $method->setAccessible(true);

        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString('windows_batch_create_split_helpers', $source);
        self::assertStringContainsString('if (!$waitForResults)', $source);
        self::assertStringContainsString('return self::batchCreateWindowsDetachedHelpers', $source);
        self::assertStringContainsString('self::resolveWindowsBatchCreateHelperParallelism', $source);
    }

    public function testWindowsIsolatedWlsChildrenRetainTheLockedPhpConfigurationRoot(): void
    {
        $method = new \ReflectionMethod(
            Processer::class,
            'collectWindowsIsolatedBatchEnvironment',
        );
        $previous = \getenv('PHPRC');
        $expected = 'C:\\wls-runtime\\php-locked';
        \putenv('PHPRC=' . $expected);

        try {
            $environment = $method->invoke(null);
            self::assertIsArray($environment);
            self::assertArrayHasKey('PHPRC', $environment);
            self::assertSame($expected, $environment['PHPRC']);
        } finally {
            if ($previous === false) {
                \putenv('PHPRC');
            } else {
                \putenv('PHPRC=' . $previous);
            }
        }
    }

    public function testExplicitWindowsProcessLogsRequireDistinctStreamsForIsolation(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'resolveExplicitProcessOutputLogs');
        $directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'weline-processer-explicit-logs-' . \bin2hex(\random_bytes(6));
        self::assertTrue(@\mkdir($directory, 0777, true));
        $stdout = $directory . \DIRECTORY_SEPARATOR . 'stdout.log';
        $stderr = $directory . \DIRECTORY_SEPARATOR . 'stderr.log';

        try {
            try {
                $method->invoke(null, ['outputLogFile' => $stdout], true, true);
                self::fail('Windows isolated launch must reject one shared output file.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Windows isolated process output logs must use distinct stdout and stderr files.',
                    $exception->getMessage()
                );
            }

            try {
                $method->invoke(null, ['stdoutLogFile' => $stdout], true, true);
                self::fail('An incomplete explicit stream pair must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Explicit process stdout and stderr logs must both be configured.',
                    $exception->getMessage()
                );
            }

            $resolved = $method->invoke(
                null,
                ['stdoutLogFile' => $stdout, 'stderrLogFile' => $stderr],
                true,
                true
            );
            self::assertSame(['stdout' => $stdout, 'stderr' => $stderr], $resolved);
            self::assertFileExists($stdout);
            self::assertFileExists($stderr);
            self::assertIsWritable($stdout);
            self::assertIsWritable($stderr);
            self::assertNull($method->invoke(null, ['outputLogFile' => $stdout], false, true));
        } finally {
            @\unlink($stdout);
            @\unlink($stderr);
            @\rmdir($directory);
        }
    }

    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(Processer::class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
