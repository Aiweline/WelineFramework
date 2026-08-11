<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

final class GatewayBoundedCommandRunnerDeadlineTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-command-deadline-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . '*') as $file) {
            @\unlink($file);
        }
        @\rmdir($this->directory);
    }

    public function testExpiredAbsoluteDeadlineNeverLaunchesTheCommand(): void
    {
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'expired.marker';
        $started = \hrtime(true);
        $result = GatewayBoundedCommandRunner::run(
            [
                \PHP_BINARY,
                '-r',
                'file_put_contents($argv[1], "launched");',
                $marker,
            ],
            1.0,
            deadlineMonotonic: (\hrtime(true) / 1_000_000_000) - 1.0,
        );

        self::assertSame(124, $result['code']);
        self::assertStringContainsString('deadline was exhausted', $result['output']);
        self::assertFileDoesNotExist($marker);
        self::assertLessThan(250_000_000, \hrtime(true) - $started);
    }

    public function testPosixChildAndContainmentConsumeOneCallerDeadline(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_setsid')
            || !\function_exists('pcntl_exec')
        ) {
            self::markTestSkipped('The POSIX process-group runner is required.');
        }
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'late.marker';
        $started = \hrtime(true) / 1_000_000_000;
        $result = GatewayBoundedCommandRunner::run(
            [
                \PHP_BINARY,
                '-r',
                'usleep(2000000); file_put_contents($argv[1], "late");',
                $marker,
            ],
            5.0,
            deadlineMonotonic: $started + 0.35,
        );
        $elapsed = (\hrtime(true) / 1_000_000_000) - $started;

        self::assertSame(124, $result['code']);
        self::assertLessThan(1.0, $elapsed);
        \usleep(250_000);
        self::assertFileDoesNotExist($marker);
    }

    public function testPosixNaturallyExitingDescendantIsReapedBeforeSuccess(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_setsid')
            || !\function_exists('pcntl_exec')
            || !\function_exists('pcntl_fork')
        ) {
            self::markTestSkipped('The POSIX process-group runner and pcntl_fork are required.');
        }

        $result = GatewayBoundedCommandRunner::run([
            \PHP_BINARY,
            '-r',
            <<<'PHP'
$pid = pcntl_fork();
if ($pid < 0) {
    exit(71);
}
if ($pid === 0) {
    usleep(180000);
    exit(0);
}
exit(0);
PHP,
        ], 3.0);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringNotContainsString(
            'left descendant processes after exit',
            $result['output'],
        );
    }

    public function testPosixPersistentDescendantFailsClosedAndIsTerminated(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_setsid')
            || !\function_exists('pcntl_exec')
            || !\function_exists('pcntl_fork')
        ) {
            self::markTestSkipped('The POSIX process-group runner and pcntl_fork are required.');
        }
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'descendant.marker';

        $result = GatewayBoundedCommandRunner::run([
            \PHP_BINARY,
            '-r',
            <<<'PHP'
$pid = pcntl_fork();
if ($pid < 0) {
    exit(71);
}
if ($pid === 0) {
    usleep(1000000);
    file_put_contents($argv[1], 'survived');
    exit(0);
}
exit(0);
PHP,
            $marker,
        ], 3.0);

        self::assertSame(125, $result['code'], $result['output']);
        self::assertStringContainsString(
            'left descendant processes after exit',
            $result['output'],
        );
        \usleep(1_100_000);
        self::assertFileDoesNotExist($marker);
    }

    public function testWindowsWatchdogAndHostCallersShareTheAbsoluteDeadline(): void
    {
        $timeoutMethod = new \ReflectionMethod(
            GatewayBoundedCommandRunner::class,
            'windowsCommandTimeoutWithinDeadline',
        );
        $bounded = $timeoutMethod->invoke(
            null,
            5.0,
            (\hrtime(true) / 1_000_000_000) + 13.0,
        );
        self::assertIsFloat($bounded);
        self::assertGreaterThan(0.8, $bounded);
        self::assertLessThanOrEqual(1.0, $bounded);
        self::assertNull($timeoutMethod->invoke(
            null,
            5.0,
            (\hrtime(true) / 1_000_000_000) + 12.05,
        ));

        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/';
        $runner = (string)\file_get_contents(
            $gateway . 'GatewayBoundedCommandRunner.php',
        );
        self::assertStringContainsString(
            'private static function windowsCommandTimeoutWithinDeadline(',
            $runner,
        );
        self::assertStringContainsString(
            '- self::WINDOWS_OUTER_GRACE_SECONDS;',
            $runner,
        );
        self::assertStringContainsString(
            'self::reapDeferredProcesses($deadlineMonotonic)',
            $runner,
        );
        self::assertStringContainsString(
            "self::waitForExit(\n                \$process,\n                \$outerDeadline,",
            $runner,
        );
        self::assertStringNotContainsString(
            'WINDOWS_TERMINATE_GRACE_SECONDS',
            $runner,
        );

        foreach ([
            'GatewayPlatformServiceInstaller.php',
            'HostGatewayPackageManager.php',
            'GatewayHostManager.php',
        ] as $file) {
            $source = (string)\file_get_contents($gateway . $file);
            self::assertStringContainsString(
                'deadlineMonotonic:',
                $source,
                $file,
            );
        }
    }

    public function testDeferredReaperConvergesWatchdogKilledPipeStaging(): void
    {
        $transaction = $this->directory . DIRECTORY_SEPARATOR . 'pipe-deferred';
        self::assertTrue(@\mkdir($transaction, 0700));
        foreach ([
            'request.bin',
            'response.bin.tmp',
            'response.bin',
            'result.json.tmp',
            'result.json',
        ] as $leaf) {
            self::assertSame(
                1,
                @\file_put_contents(
                    $transaction . DIRECTORY_SEPARATOR . $leaf,
                    'x',
                ),
            );
        }

        $deferred = new \ReflectionProperty(
            GatewayBoundedCommandRunner::class,
            'deferredProcesses',
        );
        $reap = new \ReflectionMethod(
            GatewayBoundedCommandRunner::class,
            'reapDeferredProcesses',
        );
        $previous = $deferred->getValue();
        try {
            $deferred->setValue(null, [[
                'process' => null,
                'group_id' => 0,
                'pipe_result_dir' => $transaction,
            ]]);
            self::assertTrue($reap->invoke(
                null,
                (\hrtime(true) / 1_000_000_000) + 2.0,
            ));
            self::assertDirectoryDoesNotExist($transaction);
            self::assertSame([], $deferred->getValue());

            // An empty second reap is the admission condition for the next
            // named-pipe request; stale watchdog staging must not poison it.
            self::assertTrue($reap->invoke(
                null,
                (\hrtime(true) / 1_000_000_000) + 2.0,
            ));
        } finally {
            $deferred->setValue(null, $previous);
            foreach ((array)@\glob($transaction . DIRECTORY_SEPARATOR . '*') as $file) {
                @\unlink($file);
            }
            @\rmdir($transaction);
        }
    }

    public function testExpiredPipeReadFenceRejectsAnOtherwiseValidReceipt(): void
    {
        $transaction = $this->directory . DIRECTORY_SEPARATOR . 'pipe-result';
        self::assertTrue(@\mkdir($transaction, 0700));
        $response = "WLS-EDGE/2\tOK\n";
        self::assertSame(
            \strlen($response),
            @\file_put_contents(
                $transaction . DIRECTORY_SEPARATOR . 'response.bin',
                $response,
            ),
        );
        $manifest = \json_encode([
            'schema' => 'wls-named-pipe-exchange-result/1',
            'response_bytes' => \strlen($response),
            'response_sha256' => \hash('sha256', $response),
        ], JSON_THROW_ON_ERROR) . "\n";
        self::assertSame(
            \strlen($manifest),
            @\file_put_contents(
                $transaction . DIRECTORY_SEPARATOR . 'result.json',
                $manifest,
            ),
        );

        $read = new \ReflectionMethod(
            GatewayBoundedCommandRunner::class,
            'readWindowsPipeResult',
        );
        try {
            $read->invoke(
                null,
                $transaction,
                4_194_304,
                (\hrtime(true) / 1_000_000_000) - 1.0,
            );
            self::fail('An authoritative named-pipe receipt crossed its caller deadline.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertInstanceOf(
                \Weline\Server\Service\Edge\Gateway\GatewayWindowsNamedPipeTransportException::class,
                $exception,
            );
            self::assertStringContainsString('deadline', $exception->getMessage());
        }
    }

    public function testPipeHelperAndReceiptHaveAbsoluteFencesAtEveryAcceptanceBoundary(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayBoundedCommandRunner.php',
        );
        $execute = $this->sourceBetween(
            $source,
            'private static function executeWindowsPipeHelperUntil(',
            'private static function writeWindowsPipeRequest(',
        );
        $spawn = \strpos($execute, '$process = @\proc_open(');
        $spawnFence = \strpos(
            $execute,
            'self::absoluteDeadlineExhausted($absoluteDeadline)',
            (int)$spawn,
        );
        self::assertIsInt($spawn);
        self::assertIsInt($spawnFence);
        self::assertGreaterThan($spawn, $spawnFence);
        self::assertGreaterThanOrEqual(
            3,
            \substr_count(
                $execute,
                'self::absoluteDeadlineExhausted($absoluteDeadline)',
            ),
        );

        $read = $this->sourceBetween(
            $source,
            'private static function readWindowsPipeResult(',
            '/** @param list<string> $expectedLeaves */',
        );
        self::assertStringContainsString('float $absoluteDeadline', $read);
        self::assertSame(
            5,
            \substr_count($read, 'self::assertWindowsPipeDeadlineAvailable('),
        );
        self::assertLessThan(
            \strpos($read, 'GatewayProjectStateFilesystem::read('),
            \strpos($read, 'self::assertWindowsPipeDeadlineAvailable('),
        );
        self::assertLessThan(
            \strpos($read, 'return $response;'),
            \strrpos($read, 'self::assertWindowsPipeDeadlineAvailable('),
        );
    }

    public function testWindowsPipeOrphanReaperIsCrossProcessBoundedAndFailClosed(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/';
        $runner = (string)\file_get_contents(
            $gateway . 'GatewayBoundedCommandRunner.php',
        );
        $native = (string)\file_get_contents(
            $gateway . 'Native/windows/wls_bounded_command.c',
        );

        $reaper = $this->sourceBetween(
            $runner,
            'private static function reapWindowsPipeOrphans(',
            'private static function executeWindowsOrphanReaperUntil(',
        );
        foreach ([
            "preg_match('/\\Apipe-[a-f0-9]{32}\\z/D'",
            'WINDOWS_PIPE_ORPHAN_SCAN_LIMIT',
            '--pipe-reap-orphan',
            '--minimum-age-ms=',
            'unsafe orphan',
            'absoluteDeadlineExhausted($absoluteDeadline)',
        ] as $contract) {
            self::assertStringContainsString($contract, $reaper);
        }
        self::assertStringContainsString(
            'self::reapWindowsPipeOrphans(',
            $runner,
        );

        foreach ([
            'wls_pipe_reap_orphan(',
            'wls_private_path_acl_exact(',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE',
            'FILE_DISPOSITION_INFO',
            'WLS_PIPE_ORPHAN_MINIMUM_AGE_MS',
            'response.bin.tmp',
            'result.json.tmp',
        ] as $contract) {
            self::assertStringContainsString($contract, $native);
        }

        $integration = (string)\file_get_contents(
            \dirname(__DIR__, 4)
                . '/Integration/Service/Edge/Gateway/windows_service_recovery.php',
        );
        foreach ([
            'function wlsCrossProcessPipeOrphanReaper(',
            '@\\proc_terminate($producer, 9)',
            "'reapWindowsPipeOrphans'",
            "'--pipe-prepare'",
            'Cross-process pipe orphan was retained.',
            'The request after orphan convergence could not be prepared.',
        ] as $contract) {
            self::assertStringContainsString($contract, $integration);
        }
    }

    private function sourceBetween(
        string $source,
        string $startNeedle,
        string $endNeedle,
    ): string {
        $start = \strpos($source, $startNeedle);
        $end = \strpos($source, $endNeedle, (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        return \substr($source, $start, $end - $start);
    }
}
