<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserUnixBatchLauncherHandshakeTest extends TestCase
{
    public function testParentLauncherDeadlinesAndDurationsUseOnlyTheMonotonicClock(): void
    {
        foreach ([
            'batchCreateUnix',
            'batchCreateUnixMasterOwned',
            'finishUnixBatchLauncher',
        ] as $methodName) {
            $source = $this->readProcesserMethodSource($methodName);
            self::assertStringNotContainsString(
                'microtime(',
                $source,
                $methodName . ' must not use wall time for deadlines or duration metrics.',
            );
            self::assertStringContainsString(
                'hrtime(true)',
                $source,
                $methodName . ' must stay in the monotonic time domain.',
            );
        }
    }

    public function testSetSidFailureDoesNotPublishATrustedPid(): void
    {
        $this->requirePosixRuntime();
        $directory = $this->createTemporaryDirectory();
        $marker = $directory . DIRECTORY_SEPARATOR . 'setsid-executed';
        $id = \base64_encode('setsid-failure');

        try {
            $result = $this->invokeLauncher([
                $this->item(
                    $id,
                    $directory,
                    [PHP_BINARY, '-r', 'file_put_contents($argv[1], "executed");', $marker],
                ),
            ], ['-d', 'disable_functions=posix_setsid']);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame($id . "\t0", \trim($result['stdout']));
            self::assertStringContainsString($id . ': setsid failed', $result['stderr']);
            self::assertStringNotContainsString($directory, $result['stderr']);
            self::assertFileDoesNotExist($marker);
        } finally {
            $this->cleanup([$marker, $directory]);
        }
    }

    public function testWorkingDirectoryFailureDoesNotPublishATrustedPid(): void
    {
        $this->requirePosixRuntime();
        $directory = $this->createTemporaryDirectory();
        $missingDirectory = $directory . DIRECTORY_SEPARATOR . 'missing-cwd';
        $marker = $directory . DIRECTORY_SEPARATOR . 'chdir-executed';
        $id = \base64_encode('chdir-failure');

        try {
            $result = $this->invokeLauncher([
                $this->item(
                    $id,
                    $missingDirectory,
                    [PHP_BINARY, '-r', 'file_put_contents($argv[1], "executed");', $marker],
                ),
            ]);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame($id . "\t0", \trim($result['stdout']));
            self::assertStringContainsString($id . ': chdir failed', $result['stderr']);
            self::assertStringNotContainsString($missingDirectory, $result['stderr']);
            self::assertFileDoesNotExist($marker);
        } finally {
            $this->cleanup([$marker, $directory]);
        }
    }

    public function testExecFailureDoesNotPublishATrustedPid(): void
    {
        $this->requirePosixRuntime();
        $directory = $this->createTemporaryDirectory();
        $missingExecutable = $directory . DIRECTORY_SEPARATOR . 'missing-php';
        $id = \base64_encode('exec-failure');

        try {
            $result = $this->invokeLauncher([
                $this->item($id, $directory, [$missingExecutable, '-v']),
            ]);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame($id . "\t0", \trim($result['stdout']));
            self::assertMatchesRegularExpression(
                '/\A' . \preg_quote($id, '/')
                . ': exec failed errno=[1-9][0-9]* message=[^\r\n]+\R?\z/',
                $result['stderr'],
            );
            self::assertStringNotContainsString($missingExecutable, $result['stderr']);
        } finally {
            $this->cleanup([$directory]);
        }
    }

    public function testTerminateAndReapStopsAtTheMonotonicDeadlineWhenTheChildCannotBeCollected(): void
    {
        $this->requirePosixRuntime();
        $terminateAndReap = $this->createTerminateAndReapClosure();
        $pid = 4242;
        $signalCalls = [];
        $waitCalls = [];
        $clock = 1_000_000_000;
        $clockCalls = 0;
        $pauseCalls = [];

        $reaped = $terminateAndReap(
            $pid,
            false,
            1_003_000_000,
            static function (int $actualPid, int $signal) use (&$signalCalls): bool {
                $signalCalls[] = [$actualPid, $signal];

                return true;
            },
            static function (int $actualPid, int &$status, int $options) use (&$waitCalls): int {
                $waitCalls[] = [$actualPid, $options];
                if (\count($waitCalls) > 20) {
                    throw new \RuntimeException('The child reaper did not observe its deadline.');
                }
                $status = 0;

                return 0;
            },
            static fn (): int => 0,
            static function () use (&$clock, &$clockCalls): int {
                $clockCalls++;
                $now = $clock;
                $clock += 1_000_000;

                return $now;
            },
            static function (int $microseconds) use (&$pauseCalls): void {
                $pauseCalls[] = $microseconds;
            },
        );

        self::assertFalse($reaped, 'An unconfirmed child must never be treated as reaped.');
        self::assertSame([[$pid, \defined('SIGKILL') ? \SIGKILL : 9]], $signalCalls);
        self::assertNotEmpty($waitCalls);
        self::assertLessThanOrEqual(5, \count($waitCalls));
        self::assertSame(
            [[$pid, \defined('WNOHANG') ? \WNOHANG : 1]],
            \array_values(\array_unique($waitCalls, \SORT_REGULAR)),
        );
        self::assertGreaterThanOrEqual(4, $clockCalls);
        self::assertNotEmpty($pauseCalls);
    }

    public function testTerminateAndReapRetriesEintrUntilTheExactChildIsCollected(): void
    {
        $this->requirePosixRuntime();
        $terminateAndReap = $this->createTerminateAndReapClosure();
        $pid = 4343;
        $waitResults = [-1, -1, $pid];
        $waitCalls = [];
        $signalCalls = [];
        $clock = 2_000_000_000;
        $lastErrorCalls = 0;

        $reaped = $terminateAndReap(
            $pid,
            false,
            2_100_000_000,
            static function (int $actualPid, int $signal) use (&$signalCalls): bool {
                $signalCalls[] = [$actualPid, $signal];

                return true;
            },
            static function (int $actualPid, int &$status, int $options) use (&$waitResults, &$waitCalls): int {
                $waitCalls[] = [$actualPid, $options];
                $status = 0;

                return (int)\array_shift($waitResults);
            },
            static function () use (&$lastErrorCalls): int {
                $lastErrorCalls++;

                return \defined('PCNTL_EINTR') ? \PCNTL_EINTR : 4;
            },
            static function () use (&$clock): int {
                $clock += 1_000_000;

                return $clock;
            },
            static function (int $microseconds): void {
                unset($microseconds);
            },
        );

        self::assertTrue($reaped);
        self::assertSame([[$pid, \defined('SIGKILL') ? \SIGKILL : 9]], $signalCalls);
        self::assertSame(3, \count($waitCalls));
        self::assertSame(
            [[$pid, \defined('WNOHANG') ? \WNOHANG : 1]],
            \array_values(\array_unique($waitCalls, \SORT_REGULAR)),
        );
        self::assertSame(2, $lastErrorCalls);
        self::assertSame([], $waitResults);
    }

    public function testTimedOutPreExecChildIsReapedWithoutPublishingItsPid(): void
    {
        $this->requirePosixRuntime();
        if (!\function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is required to hold the child before exec.');
        }
        $directory = $this->createTemporaryDirectory();
        $fifo = $directory . DIRECTORY_SEPARATOR . 'blocked-stdout';
        $marker = $directory . DIRECTORY_SEPARATOR . 'executed';
        $id = \base64_encode('pre-exec-timeout');
        self::assertTrue(\posix_mkfifo($fifo, 0600));
        $item = $this->item(
            $id,
            $directory,
            [PHP_BINARY, '-r', 'file_put_contents($argv[1], "executed");', $marker],
        );
        $item['stdout'] = $fifo;
        $process = null;
        $pipes = [];
        $launcherPid = 0;
        $childPid = 0;

        try {
            [$process, $pipes] = $this->openLauncher([$item], [], 0.5);
            $status = \proc_get_status($process);
            $launcherPid = (int)($status['pid'] ?? 0);
            self::assertGreaterThan(0, $launcherPid);
            $childPid = $this->waitForDirectChildPid($launcherPid, 0.35);
            self::assertGreaterThan(0, $childPid, 'The pre-exec child was not observed before the timeout.');

            $result = $this->finishLauncher($process, $pipes);
            $process = null;
            $pipes = [];

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame($id . "\t0", \trim($result['stdout']));
            self::assertStringContainsString($id . ': exec readiness timed out', $result['stderr']);
            self::assertFileDoesNotExist($marker);
            self::assertTrue(
                $this->waitForProcessExit($childPid, 0.5),
                'The failed pre-exec child remained present after launcher completion.',
            );
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            if (\is_resource($process)) {
                @\proc_terminate($process);
                @\proc_close($process);
            }
            $child = $childPid > 0 ? $this->readProcessIdentity($childPid) : null;
            if ($child !== null && $child['ppid'] === $launcherPid) {
                @\posix_kill($childPid, \defined('SIGKILL') ? \SIGKILL : 9);
            }
            $this->cleanup([$marker, $fifo, $directory]);
        }
    }

    public function testSuccessfulExecPublishesTheRealSessionLeaderPid(): void
    {
        $this->requirePosixRuntime();
        if (!\function_exists('posix_getsid')) {
            self::markTestSkipped('posix_getsid is required to verify the detached session identity.');
        }
        $directory = $this->createTemporaryDirectory();
        $script = $directory . DIRECTORY_SEPARATOR . 'child.php';
        $marker = $directory . DIRECTORY_SEPARATOR . 'identity';
        $id = \base64_encode('exec-success');
        $scriptContents = "<?php\n"
            . "file_put_contents(\$argv[1], getmypid() . \"\\t\" . posix_getsid(0));\n"
            . "usleep(250000);\n";
        self::assertSame(\strlen($scriptContents), \file_put_contents($script, $scriptContents));

        try {
            $result = $this->invokeLauncher([
                $this->item($id, $directory, [PHP_BINARY, $script, $marker]),
            ]);

            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertMatchesRegularExpression(
                '/\A' . \preg_quote($id, '/') . "\t([1-9][0-9]*)\z/",
                \trim($result['stdout']),
                $result['stderr'],
            );
            $publishedPid = (int)\explode("\t", \trim($result['stdout']), 2)[1];
            self::assertTrue($this->waitForPath($marker, 2.0), 'The exec child did not publish its identity.');
            [$actualPid, $sessionId] = \array_map('intval', \explode("\t", (string)\file_get_contents($marker), 2));
            self::assertSame($publishedPid, $actualPid);
            self::assertSame($actualPid, $sessionId);
        } finally {
            $this->cleanup([$marker, $script, $directory]);
        }
    }

    /**
     * @param list<string> $argv
     * @return array{id:string,argv:list<string>,cwd:string,stdout:string,stderr:string,preserve_fds:list<int>}
     */
    private function item(string $id, string $cwd, array $argv): array
    {
        return [
            'id' => $id,
            'argv' => $argv,
            'cwd' => $cwd,
            'stdout' => '/dev/null',
            'stderr' => '/dev/null',
            'preserve_fds' => [],
        ];
    }

    /**
     * @param list<array{id:string,argv:list<string>,cwd:string,stdout:string,stderr:string,preserve_fds:list<int>}> $items
     * @param list<string> $phpOptions
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function invokeLauncher(array $items, array $phpOptions = [], float $handshakeTimeout = 4.0): array
    {
        [$process, $pipes] = $this->openLauncher($items, $phpOptions, $handshakeTimeout);

        return $this->finishLauncher($process, $pipes);
    }

    /**
     * @param list<array{id:string,argv:list<string>,cwd:string,stdout:string,stderr:string,preserve_fds:list<int>}> $items
     * @param list<string> $phpOptions
     * @return array{0:resource,1:array<int,resource>}
     */
    private function openLauncher(array $items, array $phpOptions, float $handshakeTimeout): array
    {
        $method = new \ReflectionMethod(Processer::class, 'unixBatchLauncherCode');
        $method->setAccessible(true);
        $code = $method->invoke(null);
        self::assertIsString($code);
        $payload = \json_encode($items, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $closeFds = \json_encode([], JSON_THROW_ON_ERROR);
        self::assertIsString($payload);
        self::assertIsString($closeFds);
        $command = [
            PHP_BINARY,
            ...$phpOptions,
            '-r',
            $code,
            \base64_encode($payload),
            \base64_encode($closeFds),
            (string)$handshakeTimeout,
        ];
        $process = \proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            \dirname(__DIR__, 8),
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function finishLauncher($process, array $pipes): array
    {
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);

        return [
            'exit' => \proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function createTerminateAndReapClosure(): \Closure
    {
        $methodName = 'unixBatchLauncherTerminateAndReapCode';
        self::assertTrue(
            \method_exists(Processer::class, $methodName),
            'The launcher must expose the exact bounded reaper code that it executes.',
        );
        $method = new \ReflectionMethod(Processer::class, $methodName);
        $method->setAccessible(true);
        $code = $method->invoke(null);
        self::assertIsString($code);
        $closure = eval('return ' . $code . ';');
        self::assertInstanceOf(\Closure::class, $closure);

        return $closure;
    }

    private function readProcesserMethodSource(string $methodName): string
    {
        $method = new \ReflectionMethod(Processer::class, $methodName);
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);
        $source = \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );

        return \implode('', $source);
    }

    private function waitForDirectChildPid(int $parentPid, float $seconds): int
    {
        $deadline = \hrtime(true) + (int)\round($seconds * 1_000_000_000);
        do {
            foreach ($this->readProcessTable() as $pid => $identity) {
                if ($identity['ppid'] === $parentPid) {
                    return $pid;
                }
            }
            \usleep(5_000);
        } while (\hrtime(true) < $deadline);

        return 0;
    }

    private function waitForProcessExit(int $pid, float $seconds): bool
    {
        $deadline = \hrtime(true) + (int)\round($seconds * 1_000_000_000);
        do {
            if ($this->readProcessIdentity($pid) === null) {
                return true;
            }
            \usleep(5_000);
        } while (\hrtime(true) < $deadline);

        return $this->readProcessIdentity($pid) === null;
    }

    /** @return array{ppid:int,state:string}|null */
    private function readProcessIdentity(int $pid): ?array
    {
        return $this->readProcessTable()[$pid] ?? null;
    }

    /** @return array<int, array{ppid:int,state:string}> */
    private function readProcessTable(): array
    {
        $process = \proc_open(
            ['/bin/ps', '-axo', 'pid=,ppid=,state='],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return [];
        }
        $output = (string)\stream_get_contents($pipes[1]);
        @\fclose($pipes[1]);
        @\proc_close($process);
        $table = [];
        foreach (\preg_split('/\r\n|\r|\n/', \trim($output)) ?: [] as $line) {
            if (\preg_match('/\A\s*([0-9]+)\s+([0-9]+)\s+(\S+)/', $line, $match) !== 1) {
                continue;
            }
            $table[(int)$match[1]] = [
                'ppid' => (int)$match[2],
                'state' => (string)$match[3],
            ];
        }

        return $table;
    }

    private function requirePosixRuntime(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The Unix batch launcher is not used on Windows.');
        }
        foreach (['proc_open', 'pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'posix_setsid'] as $function) {
            if (!\function_exists($function)) {
                self::markTestSkipped('Required POSIX function is unavailable: ' . $function);
            }
        }
        if (!\class_exists(\FFI::class)) {
            self::markTestSkipped('FFI is required for the explicit FD_CLOEXEC readiness pipe.');
        }
        try {
            \FFI::cdef('int pipe(int descriptors[2]);');
        } catch (\Throwable) {
            self::markTestSkipped('FFI C declarations are disabled for this PHP runtime.');
        }
    }

    private function createTemporaryDirectory(): string
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-unix-batch-handshake-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700));
        self::assertTrue(\chmod($directory, 0700));

        return $directory;
    }

    private function waitForPath(string $path, float $seconds): bool
    {
        $deadline = \hrtime(true) + (int)\round($seconds * 1_000_000_000);
        do {
            if (\is_file($path)) {
                return true;
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        return \is_file($path);
    }

    /** @param list<string> $paths */
    private function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (\file_exists($path) || \is_link($path)) {
                @\unlink($path);
            } elseif (\is_dir($path)) {
                @\rmdir($path);
            }
        }
    }
}
