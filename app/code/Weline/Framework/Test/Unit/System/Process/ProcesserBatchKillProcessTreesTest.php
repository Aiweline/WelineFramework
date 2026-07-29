<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;
use Weline\Framework\System\Process\Processer;

final class ProcesserBatchKillProcessTreesTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, null);

        parent::tearDown();
    }

    public function testBuildWindowsBatchTreeKillCommandAggregatesPidList(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildWindowsBatchTreeKillCommand');
        $method->setAccessible(true);

        $command = $method->invoke(null, [101, 202, 303]);

        self::assertSame('taskkill /F /T /PID 101 /PID 202 /PID 303 2>NUL', $command);
    }

    public function testBuildPosixKillCommandCanUseNonInteractiveSudo(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'buildPosixKillCommand');
        $method->setAccessible(true);

        self::assertSame(
            'sudo -n kill -9 101 202 2>/dev/null',
            $method->invoke(null, [101, 202], 9, true)
        );
    }

    public function testBatchKillProcessTreesPosixReportsStillRunningPidAsRemaining(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX kill behavior is verified on Linux-like systems.');
        }

        $pid = 987654321;
        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->method('isRunningByPid')
            ->with($pid)
            ->willReturn(true);

        $this->replaceProcessDriver($driver);

        $method = new \ReflectionMethod(Processer::class, 'batchKillProcessTreesPosix');
        $method->setAccessible(true);

        self::assertSame([
            'killed' => 0,
            'failed' => 1,
            'remaining' => [$pid],
        ], $method->invoke(null, [$pid]));
    }

    public function testKillByProcessNamePrefixesSkipsReusedPidWhenCommandLineDoesNotMatch(): void
    {
        $pid = 987654321;
        $pname = '--name=weline-wls-worker-default-punit-1';
        $pidFile = Processer::getPidFile($pname, $pid);
        $nameIndex = Processer::readNameIndex();
        $pidIndex = Processer::readPidIndex();

        $driver = $this->createMock(ProcessDriverInterface::class);
        $driver->method('batchGetProcessInfo')
            ->with([$pid])
            ->willReturn([
                $pid => [
                    'pid' => $pid,
                    'exists' => true,
                    'is_zombie' => false,
                    'name' => 'svchost.exe',
                    'command' => '',
                    'memory' => '',
                    'cpu' => '',
                    'start_time' => '',
                ],
            ]);
        $driver->method('getProcessCommandLine')
            ->with($pid)
            ->willReturn('C:\\Windows\\System32\\svchost.exe -k netsvcs');

        try {
            Processer::writeNameIndex([
                $pname => [
                    [
                        'pid' => $pid,
                        'jsonPath' => $pidFile,
                    ],
                ],
            ]);
            Processer::writePidIndex([
                $pid => [
                    'pname' => $pname,
                    'jsonPath' => $pidFile,
                ],
            ]);
            $this->replaceProcessDriver($driver);

            self::assertSame(0, Processer::killByProcessNamePrefixes(['weline-wls-worker-default-']));
        } finally {
            Processer::writeNameIndex($nameIndex);
            Processer::writePidIndex($pidIndex);
        }
    }

    public function testPrepareProcessLogFileForWriteRecoversReadOnlyLog(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file mode behavior is verified on Linux-like systems.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-processer-log-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));

        $path = $dir . DIRECTORY_SEPARATOR . 'process.log';
        file_put_contents($path, 'old');
        chmod($path, 0444);

        try {
            $method = new \ReflectionMethod(Processer::class, 'prepareProcessLogFileForWrite');
            $method->setAccessible(true);

            self::assertTrue($method->invoke(null, $path));
            self::assertFileExists($path);
            self::assertTrue(is_writable($path));
            self::assertNotFalse(file_put_contents($path, 'new', FILE_APPEND));
        } finally {
            @chmod($path, 0666);
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testBatchCreateWritesChildStdoutAndStderrToExplicitOutputLog(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The real child output contract is exercised on POSIX; Windows uses the same file fields.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-processer-output-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));
        $script = $dir . DIRECTORY_SEPARATOR . 'child.php';
        $log = $dir . DIRECTORY_SEPARATOR . 'advertised.log';
        $stdoutMarker = 'explicit-stdout-' . bin2hex(random_bytes(4));
        $stderrMarker = 'explicit-stderr-' . bin2hex(random_bytes(4));
        $processName = 'weline-processer-output-' . bin2hex(random_bytes(4));
        file_put_contents(
            $script,
            "<?php\nfwrite(STDOUT, " . var_export($stdoutMarker . PHP_EOL, true) . ");\n"
            . "fwrite(STDERR, " . var_export($stderrMarker . PHP_EOL, true) . ");\n"
            . "usleep(150000);\n",
        );
        $argv = [PHP_BINARY, $script, '--name=' . $processName];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --name=' . escapeshellarg($processName);

        try {
            $pids = Processer::batchCreate([
                'child' => [
                    'command' => $command,
                    'argv' => $argv,
                    'cwd' => $dir,
                    'block' => false,
                    'foreground' => false,
                    'enableLog' => true,
                    'outputLogFile' => $log,
                    'childOwnsPid' => true,
                    'masterOwned' => true,
                ],
            ]);
            self::assertGreaterThan(0, (int)($pids['child'] ?? 0));

            $contents = '';
            $deadline = microtime(true) + 3.0;
            do {
                clearstatcache(true, $log);
                $contents = is_file($log) ? (string)file_get_contents($log) : '';
                if (str_contains($contents, $stdoutMarker) && str_contains($contents, $stderrMarker)) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);

            self::assertStringContainsString($stdoutMarker, $contents);
            self::assertStringContainsString($stderrMarker, $contents);
        } finally {
            @unlink($script);
            @unlink($log);
            @rmdir($dir);
        }
    }

    public function testBatchCreateRejectsUnavailableExplicitOutputLog(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The invalid path preflight is exercised on POSIX.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-processer-output-invalid-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));
        $script = $dir . DIRECTORY_SEPARATOR . 'child.php';
        $blocker = $dir . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($script, "<?php\nusleep(100000);\n");
        file_put_contents($blocker, 'block');
        $processName = 'weline-processer-output-invalid-' . bin2hex(random_bytes(4));
        $argv = [PHP_BINARY, $script, '--name=' . $processName];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --name=' . escapeshellarg($processName);
        $exception = null;

        try {
            Processer::batchCreate([
                'child' => [
                    'command' => $command,
                    'argv' => $argv,
                    'cwd' => $dir,
                    'block' => false,
                    'foreground' => false,
                    'enableLog' => true,
                    'outputLogFile' => $blocker . DIRECTORY_SEPARATOR . 'child.log',
                    'childOwnsPid' => true,
                    'masterOwned' => true,
                ],
            ]);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        } finally {
            @unlink($script);
            @unlink($blocker);
            @rmdir($dir);
        }

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('Explicit process output log is unavailable.', $exception->getMessage());
    }


    private function replaceProcessDriver(ProcessDriverInterface $driver): void
    {
        $reflection = new \ReflectionProperty(ProcessDriverFactory::class, 'driver');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $driver);
    }

}
