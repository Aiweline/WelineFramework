<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

final class NativeFileLockDeadlineTest extends TestCase
{
    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX flock deadline contracts do not apply to Windows.');
        }
        $temporaryRoot = (string)\realpath(
            \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir(),
        );
        $this->temporaryDirectory = $temporaryRoot . DIRECTORY_SEPARATOR
            . 'wls-native-lock-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory === '' || !\is_dir($this->temporaryDirectory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->temporaryDirectory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @\rmdir($item->getPathname())
                : @\unlink($item->getPathname());
        }
        @\rmdir($this->temporaryDirectory);
    }

    public function testBrokerFileLockAcquisitionExpiresOnAContendedDescriptor(): void
    {
        $server = \dirname(__DIR__, 3);
        $source = $server . '/Service/Edge/Gateway/Native/posix/wls_gateway_broker.c';
        self::assertFileExists($source);

        $harness = $this->temporaryDirectory . '/broker-lock-deadline.c';
        $binary = $this->temporaryDirectory . '/broker-lock-deadline';
        $sourceLiteral = \json_encode(
            $source,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(\file_put_contents($harness, <<<C
#define main wls_gateway_broker_program_main
#include {$sourceLiteral}
#undef main

int main(void) {
    char path[] = "/tmp/wls-broker-lock-deadline-XXXXXX";
    int holder = mkstemp(path);
    int contender = -1;
    pthread_mutex_t mutex = PTHREAD_MUTEX_INITIALIZER;
    unsigned long long started = 0ULL;
    unsigned long long finished = 0ULL;
    int result;
    int result_errno;
    if (holder < 0 || fchmod(holder, 0600) != 0
        || flock(holder, LOCK_EX | LOCK_NB) != 0) return 10;
    contender = open(path, O_RDWR | O_CLOEXEC | O_NOFOLLOW);
    if (contender < 0) return 11;
    if (wls_monotonic_milliseconds(&started) != 0) return 12;
    result = wls_flock_bounded(contender, LOCK_EX, 60ULL);
    result_errno = errno;
    if (wls_monotonic_milliseconds(&finished) != 0) return 13;
    (void)flock(holder, LOCK_UN);
    close(contender);
    close(holder);
    unlink(path);
    if (result != -1 || result_errno != ETIMEDOUT) return 14;
    if (finished < started || finished - started < 40ULL) return 15;
    if (finished - started > 1000ULL) return 16;
    if (pthread_mutex_lock(&mutex) != 0) return 17;
    if (wls_monotonic_milliseconds(&started) != 0) return 18;
    result = wls_mutex_lock_bounded(&mutex, 60ULL);
    result_errno = errno;
    if (wls_monotonic_milliseconds(&finished) != 0) return 19;
    if (pthread_mutex_unlock(&mutex) != 0
        || pthread_mutex_destroy(&mutex) != 0) return 20;
    if (result != -1 || result_errno != ETIMEDOUT) return 21;
    if (finished < started || finished - started < 40ULL) return 22;
    if (finished - started > 1000ULL) return 23;
    return 0;
}
C));

        $sodiumCflags = $this->pkgConfigFlags('--cflags');
        $sodiumLdflags = $this->pkgConfigFlags('--libs');
        $compile = $this->runProcess([
            'cc',
            '-std=c11',
            \PHP_OS_FAMILY === 'Darwin' ? '-D_DARWIN_C_SOURCE' : '-D_GNU_SOURCE',
            '-Wall',
            '-Wextra',
            '-Werror',
            '-pthread',
            ...$sodiumCflags,
            $harness,
            ...$sodiumLdflags,
            ...(\PHP_OS_FAMILY === 'Darwin' ? ['-lproc'] : []),
            '-o',
            $binary,
        ], 20.0);
        self::assertSame(0, $compile['code'], $compile['output']);

        $run = $this->runProcess([$binary], 2.0);
        self::assertSame(0, $run['code'], $run['output']);
        self::assertLessThan(1.5, $run['elapsed']);
    }

    public function testLinuxHttp3RouteLockExpiresWithoutEnteringAnUnboundedWait(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The Linux reuseport runtime is compiled and exercised on Linux.');
        }
        $server = \dirname(__DIR__, 3);
        $source = $server . '/Protocol/Http3/Native/wls_linux_reuseport_runtime.c';
        self::assertFileExists($source);

        $harness = $this->temporaryDirectory . '/http3-lock-deadline.c';
        $binary = $this->temporaryDirectory . '/http3-lock-deadline';
        $sourceLiteral = \json_encode(
            $source,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(\file_put_contents($harness, <<<C
#include {$sourceLiteral}

int main(void) {
    char path[] = "/tmp/wls-http3-lock-deadline-XXXXXX";
    int holder = mkstemp(path);
    int contender = -1;
    struct timespec started;
    struct timespec finished;
    wls_linux_h3_route route;
    char error[256] = {0};
    int result;
    int result_errno;
    if (holder < 0 || fchmod(holder, 0600) != 0
        || flock(holder, LOCK_EX | LOCK_NB) != 0) return 20;
    contender = open(path, O_RDWR | O_CLOEXEC | O_NOFOLLOW);
    if (contender < 0) return 21;
    wls_linux_h3_route_init(&route);
    route.lock_fd = contender;
    if (clock_gettime(CLOCK_MONOTONIC, &started) != 0) return 22;
    result = wls_linux_lock(&route, error, sizeof(error));
    result_errno = errno;
    if (clock_gettime(CLOCK_MONOTONIC, &finished) != 0) return 23;
    route.lock_fd = -1;
    (void)flock(holder, LOCK_UN);
    close(contender);
    close(holder);
    unlink(path);
    if (result != WLS_TRANSPORT_SOCKET_ERROR || result_errno != ETIMEDOUT) return 24;
    long long elapsed_ms = (finished.tv_sec - started.tv_sec) * 1000LL
        + (finished.tv_nsec - started.tv_nsec) / 1000000LL;
    if (elapsed_ms < 40LL || elapsed_ms > 1000LL) return 25;
    return 0;
}
C));

        $compile = $this->runProcess([
            'cc',
            '-std=c11',
            '-Wall',
            '-Wextra',
            '-Werror',
            '-pthread',
            $harness,
            '-o',
            $binary,
        ], 20.0);
        self::assertSame(0, $compile['code'], $compile['output']);

        $run = $this->runProcess([$binary], 2.0);
        self::assertSame(0, $run['code'], $run['output']);
        self::assertLessThan(1.5, $run['elapsed']);
    }

    public function testReleasePathAcquisitionsUseTheBoundedHelpers(): void
    {
        $server = \dirname(__DIR__, 3);
        $broker = (string)\file_get_contents(
            $server . '/Service/Edge/Gateway/Native/posix/wls_gateway_broker.c',
        );
        $http3 = (string)\file_get_contents(
            $server . '/Protocol/Http3/Native/wls_linux_reuseport_runtime.c',
        );

        foreach ([
            ['static int wls_registry_append(', 'static int wls_registry_lookup('],
            ['static int wls_registry_lookup(', 'static int wls_authorize_root('],
            ['static int wls_security_open_locked(', 'static int wls_security_read_locked('],
            ['static int wls_emergency_file_open_locked(', 'static int wls_emergency_parse_binding('],
        ] as [$start, $end]) {
            $body = $this->between($broker, $start, $end);
            self::assertStringContainsString('wls_flock_bounded(', $body);
            self::assertDoesNotMatchRegularExpression(
                '/flock\([^;]*(?:LOCK_EX|LOCK_SH)\s*\)/',
                $body,
            );
        }

        $http3Lock = $this->between(
            $http3,
            'static int wls_linux_monotonic_milliseconds(',
            'static void wls_linux_unlock(',
        );
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $http3Lock);
        self::assertStringContainsString('CLOCK_MONOTONIC', $http3Lock);
        self::assertStringContainsString('ETIMEDOUT', $http3Lock);
    }

    public function testPosixMaintenanceCancellationCannotReenterAHeldCompletionMutex(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3)
                . '/Service/Edge/Gateway/Native/posix/wls_gateway_broker.c',
        );
        $record = $this->between(
            $source,
            'static int wls_bootstrap_same_health_generation(',
            'static void wls_bootstrap_health_arm(',
        );
        $completed = $this->between(
            $source,
            'static void wls_bootstrap_maintenance_completed(',
            'static void *wls_bootstrap_maintenance_thread(',
        );
        $stop = $this->between(
            $source,
            'static int wls_stop_bootstrap_maintenance_bounded(',
            'static void wls_handle(',
        );

        self::assertStringContainsString('PTHREAD_CANCEL_DISABLE', $record);
        self::assertStringContainsString('wls_mutex_lock_bounded(', $record);
        self::assertLessThan(
            \strpos($record, 'wls_mutex_lock_bounded('),
            \strpos($record, 'wls_bootstrap_receipt_matches('),
        );
        self::assertStringContainsString('wls_mutex_lock_bounded(', $completed);
        self::assertStringContainsString('pthread_mutex_trylock(', $stop);
        self::assertStringNotContainsString('pthread_mutex_lock(', $stop);
    }

    public function testWindowsRegistryAndSecurityLocksUseTheBoundedNativePrimitive(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3)
                . '/Service/Edge/Gateway/Native/windows/wls_gateway_broker.c',
        );
        $bounded = $this->between(
            $source,
            'static int wls_win_lock_file_bounded(',
            'static int wls_registry_append(',
        );
        self::assertStringContainsString('GetTickCount64()', $bounded);
        self::assertStringContainsString('LOCKFILE_FAIL_IMMEDIATELY', $bounded);
        self::assertStringContainsString('ERROR_TIMEOUT', $bounded);

        foreach ([
            ['static int wls_registry_append(', 'static int wls_parse_generation('],
            ['static int wls_registry_lookup(', 'static int wls_snapshot_enrolled('],
            ['static int wls_win_security_open_locked(', 'static int wls_win_security_read_locked('],
        ] as [$start, $end]) {
            $body = $this->between($source, $start, $end);
            self::assertStringContainsString('wls_win_lock_file_bounded(', $body);
            self::assertDoesNotMatchRegularExpression(
                '/LockFileEx\([^;]*MAXDWORD\s*,\s*MAXDWORD/s',
                $body,
            );
        }

        $selfTest = $this->between(
            $source,
            'static int wls_self_test(void)',
            'static int wls_snapshot_command(',
        );
        self::assertStringContainsString(
            'wls_win_self_test_bounded_file_lock()',
            $selfTest,
        );
    }

    /** @return list<string> */
    private function pkgConfigFlags(string $operation): array
    {
        $result = $this->runProcess(['pkg-config', $operation, 'libsodium'], 5.0);
        self::assertSame(0, $result['code'], $result['output']);
        return \array_values(\array_filter(
            \preg_split('/\s+/', \trim($result['output'])) ?: [],
            static fn (string $flag): bool => $flag !== '',
        ));
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string,elapsed:float}
     */
    private function runProcess(array $command, float $timeout): array
    {
        $pipes = [];
        $process = \proc_open([
            ...$command,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        @\stream_set_blocking($pipes[1], false);
        @\stream_set_blocking($pipes[2], false);
        $started = \hrtime(true);
        $deadline = $started + (int)\round($timeout * 1_000_000_000);
        $output = '';
        $code = -1;
        do {
            $output .= (string)@\stream_get_contents($pipes[1]);
            $output .= (string)@\stream_get_contents($pipes[2]);
            $status = \proc_get_status($process);
            if (!$status['running']) {
                $code = $status['exitcode'];
                break;
            }
            \usleep(1_000);
        } while (\hrtime(true) < $deadline);
        if ($code < 0) {
            @\proc_terminate($process, 9);
        }
        $output .= (string)@\stream_get_contents($pipes[1]);
        $output .= (string)@\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $closed = @\proc_close($process);
        if ($code < 0) {
            $code = $closed;
        }
        return [
            'code' => $code,
            'output' => $output,
            'elapsed' => (\hrtime(true) - $started) / 1_000_000_000,
        ];
    }

    private function between(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start, 'Missing source start: ' . $startNeedle);
        $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
        self::assertIsInt($end, 'Missing source end: ' . $endNeedle);
        return \substr($source, $start, $end - $start);
    }
}
