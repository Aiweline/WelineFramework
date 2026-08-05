<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class GatewayProjectStateFilesystemLockTest extends TestCase
{
    private string $directory = '';
    private string $lockFile = '';
    private string $readyFile = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-state-lock-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
        $this->lockFile = $this->directory . DIRECTORY_SEPARATOR . 'state.lock';
        $this->readyFile = $this->directory . DIRECTORY_SEPARATOR . 'holder.ready';
    }

    protected function tearDown(): void
    {
        @\unlink($this->readyFile);
        @\unlink($this->lockFile);
        @\rmdir($this->directory);
    }

    public function testCallbackExceptionAlwaysReleasesTheExactLock(): void
    {
        try {
            GatewayProjectStateFilesystem::withExclusiveLock(
                $this->lockFile,
                static function (): void {
                    throw new \LogicException('intentional callback failure');
                },
                waitTimeoutSeconds: 0.1,
            );
            self::fail('The callback exception must escape the lock helper.');
        } catch (\LogicException $exception) {
            self::assertSame('intentional callback failure', $exception->getMessage());
        }

        self::assertSame('released', GatewayProjectStateFilesystem::withExclusiveLock(
            $this->lockFile,
            static fn (): string => 'released',
            waitTimeoutSeconds: 0.1,
        ));
    }

    public function testContendedLockUsesBoundedMonotonicTimeout(): void
    {
        if (PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('pcntl_waitpid')
        ) {
            self::markTestSkipped('POSIX pcntl is required for deterministic lock contention.');
        }
        $pid = \pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            try {
                GatewayProjectStateFilesystem::withExclusiveLock(
                    $this->lockFile,
                    function (): void {
                        \file_put_contents($this->readyFile, 'ready');
                        \usleep(400_000);
                    },
                    waitTimeoutSeconds: 0.5,
                );
                exit(0);
            } catch (\Throwable) {
                exit(2);
            }
        }

        $readyDeadline = (\hrtime(true) / 1_000_000_000) + 1.0;
        while (!\is_file($this->readyFile)
            && (\hrtime(true) / 1_000_000_000) < $readyDeadline
        ) {
            \usleep(1_000);
        }
        self::assertFileExists($this->readyFile);
        $started = \hrtime(true) / 1_000_000_000;
        try {
            GatewayProjectStateFilesystem::withExclusiveLock(
                $this->lockFile,
                static fn (): string => 'must-not-run',
                waitTimeoutSeconds: 0.05,
            );
            self::fail('A contended lock must not wait without a bound.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Timed out acquiring', $exception->getMessage());
        }
        $elapsed = (\hrtime(true) / 1_000_000_000) - $started;
        self::assertGreaterThanOrEqual(0.04, $elapsed);
        self::assertLessThan(0.5, $elapsed);

        $status = 0;
        self::assertSame($pid, \pcntl_waitpid($pid, $status));
        self::assertTrue(\pcntl_wifexited($status));
        self::assertSame(0, \pcntl_wexitstatus($status));
    }

    public function testTimeoutBoundaryRejectsUnboundedValuesBeforeOpening(): void
    {
        foreach ([0.0, -1.0, 301.0, INF] as $timeout) {
            try {
                GatewayProjectStateFilesystem::withExclusiveLock(
                    $this->lockFile,
                    static fn (): null => null,
                    waitTimeoutSeconds: $timeout,
                );
                self::fail('An invalid lock timeout must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertFileDoesNotExist($this->lockFile);
    }
}
