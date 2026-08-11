<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class GatewayProjectStateFilesystemLockTest extends TestCase
{
    private string $directory = '';
    private string $lockFile = '';
    private string $linkedFile = '';
    private string $replacementFile = '';
    private string $readyFile = '';
    private string $startFile = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-state-lock-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
        $this->lockFile = $this->directory . DIRECTORY_SEPARATOR . 'state.lock';
        $this->linkedFile = $this->directory . DIRECTORY_SEPARATOR . 'state.lock.link';
        $this->replacementFile = $this->directory . DIRECTORY_SEPARATOR . 'state.lock.original';
        $this->readyFile = $this->directory . DIRECTORY_SEPARATOR . 'holder.ready';
        $this->startFile = $this->directory . DIRECTORY_SEPARATOR . 'contenders.start';
    }

    protected function tearDown(): void
    {
        @\unlink($this->readyFile);
        @\unlink($this->startFile);
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . 'contender-*') as $contenderFile) {
            @\unlink($contenderFile);
        }
        @\unlink($this->linkedFile);
        @\unlink($this->replacementFile);
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
                self::exitForkedChildWithoutRuntimeShutdown(0);
            } catch (\Throwable) {
                self::exitForkedChildWithoutRuntimeShutdown(2);
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

    public function testExpiredAbsoluteDeadlineRejectsBeforeOpeningOrCallback(): void
    {
        $called = false;
        try {
            GatewayProjectStateFilesystem::withExclusiveLock(
                $this->lockFile,
                static function () use (&$called): void {
                    $called = true;
                },
                waitTimeoutSeconds: 1.0,
                deadlineMonotonic: (\hrtime(true) / 1_000_000_000) - 1.0,
            );
            self::fail('An expired absolute deadline must reject the lock operation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Timed out acquiring',
                $exception->getMessage(),
            );
        }
        self::assertFalse($called);
        self::assertFileDoesNotExist($this->lockFile);
    }

    public function testConcurrentTrustedOpenersKeepOneStableLockIdentity(): void
    {
        if (PHP_OS_FAMILY === 'Windows'
            || !\function_exists('pcntl_fork')
            || !\function_exists('pcntl_waitpid')
        ) {
            self::markTestSkipped('POSIX pcntl is required for lock concurrency.');
        }

        $unsealed = @\fopen($this->lockFile, 'x+b');
        self::assertIsResource($unsealed);
        @\fclose($unsealed);
        self::assertTrue(@\chmod($this->lockFile, 0640));
        $identity = @\lstat($this->lockFile);
        self::assertIsArray($identity);

        $children = [];
        $readyFiles = [];
        $forkFailure = false;
        for ($index = 0; $index < 48; ++$index) {
            $readyFile = $this->directory . DIRECTORY_SEPARATOR . 'contender-' . $index . '.ready';
            $pid = \pcntl_fork();
            if ($pid < 0) {
                $forkFailure = true;
                break;
            }
            if ($pid === 0) {
                if (@\file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
                    self::exitForkedChildWithoutRuntimeShutdown(3);
                }
                $releaseDeadline = (\hrtime(true) / 1_000_000_000) + 3.0;
                do {
                    \clearstatcache(true, $this->startFile);
                    if (\is_file($this->startFile)) {
                        break;
                    }
                    \usleep(1_000);
                } while ((\hrtime(true) / 1_000_000_000) < $releaseDeadline);
                if (!\is_file($this->startFile)) {
                    self::exitForkedChildWithoutRuntimeShutdown(5);
                }
                try {
                    for ($attempt = 0; $attempt < 6; ++$attempt) {
                        GatewayProjectStateFilesystem::withExclusiveLock(
                            $this->lockFile,
                            static function (): void {
                                \usleep(250);
                            },
                            waitTimeoutSeconds: 5.0,
                        );
                    }
                    self::exitForkedChildWithoutRuntimeShutdown(0);
                } catch (\Throwable $exception) {
                    @\file_put_contents(
                        $readyFile . '.error',
                        $exception::class . ': ' . $exception->getMessage(),
                        LOCK_EX,
                    );
                    self::exitForkedChildWithoutRuntimeShutdown(4);
                }
            }
            $children[] = $pid;
            $readyFiles[] = $readyFile;
        }

        $readyDeadline = (\hrtime(true) / 1_000_000_000) + 3.0;
        do {
            $readyCount = 0;
            foreach ($readyFiles as $readyFile) {
                \clearstatcache(true, $readyFile);
                if (\is_file($readyFile)) {
                    ++$readyCount;
                }
            }
            if ($readyCount === \count($readyFiles)) {
                break;
            }
            \usleep(1_000);
        } while ((\hrtime(true) / 1_000_000_000) < $readyDeadline);
        $released = @\file_put_contents($this->startFile, 'start', LOCK_EX);
        $failures = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            $waited = \pcntl_waitpid($pid, $status);
            if ($waited !== $pid
                || !\pcntl_wifexited($status)
                || \pcntl_wexitstatus($status) !== 0
            ) {
                $failures[] = [
                    'pid' => $pid,
                    'waited' => $waited,
                    'status' => $status,
                    'error' => @\file_get_contents($readyFiles[$index] . '.error'),
                ];
            }
        }

        self::assertSame(\count($readyFiles), $readyCount, 'Every contender must reach the start barrier.');
        self::assertSame(5, $released, 'Every contender must leave the start barrier.');
        self::assertFalse($forkFailure, 'Unable to fork every lock contender.');
        self::assertSame([], $failures, 'Trusted contenders must not reject their shared lock inode.');
        $after = @\lstat($this->lockFile);
        self::assertIsArray($after);
        self::assertSame((int)$identity['dev'], (int)$after['dev']);
        self::assertSame((int)$identity['ino'], (int)$after['ino']);
        self::assertSame(1, (int)$after['nlink']);
    }

    public function testReplacementDuringSealIsRejectedBeforeTheCallbackRuns(): void
    {
        GatewayProjectStateFilesystem::withExclusiveLock(
            $this->lockFile,
            static fn (): null => null,
            waitTimeoutSeconds: 1.0,
        );
        $callbackRan = false;

        try {
            GatewayProjectStateFilesystem::withExclusiveLock(
                $this->lockFile,
                static function () use (&$callbackRan): void {
                    $callbackRan = true;
                },
                function ($handle, string $path): void {
                    unset($handle);
                    if (!@\rename($path, $this->replacementFile)) {
                        throw new \RuntimeException('Unable to stage the lock replacement.');
                    }
                    $replacement = @\fopen($path, 'x+b');
                    if (!\is_resource($replacement)) {
                        throw new \RuntimeException('Unable to create the replacement lock.');
                    }
                    @\chmod($path, 0600);
                    @\fclose($replacement);
                    \clearstatcache(true, $path);
                },
                waitTimeoutSeconds: 1.0,
            );
            self::fail('Replacing the path after opening must invalidate the exact lock handle.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('changed while being sealed', $exception->getMessage());
        }

        self::assertFalse($callbackRan);
        $original = @\lstat($this->replacementFile);
        $replacement = @\lstat($this->lockFile);
        self::assertIsArray($original);
        self::assertIsArray($replacement);
        self::assertNotSame((int)$original['ino'], (int)$replacement['ino']);
    }

    public function testHardLinkIntroducedDuringSealIsRejectedBeforeTheCallbackRuns(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('POSIX hard-link semantics are required.');
        }
        $callbackRan = false;

        try {
            GatewayProjectStateFilesystem::withExclusiveLock(
                $this->lockFile,
                static function () use (&$callbackRan): void {
                    $callbackRan = true;
                },
                function ($handle, string $path): void {
                    unset($handle);
                    if (!@\link($path, $this->linkedFile)) {
                        throw new \RuntimeException('Unable to create the lock hard-link fixture.');
                    }
                    \clearstatcache(true, $path);
                },
                waitTimeoutSeconds: 1.0,
            );
            self::fail('A multiply linked lock inode must never guard the state callback.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'must be one regular non-linked file',
                $exception->getMessage(),
            );
        }

        self::assertFalse($callbackRan);
        $status = @\lstat($this->lockFile);
        self::assertIsArray($status);
        self::assertSame(2, (int)$status['nlink']);
    }

    public function testNormallyReopeningASealedLockPreservesItsIdentity(): void
    {
        $sealCalls = 0;
        $seal = static function ($handle, string $path) use (&$sealCalls): void {
            self::assertIsResource($handle);
            self::assertNotSame('', $path);
            ++$sealCalls;
        };

        self::assertSame('first', GatewayProjectStateFilesystem::withExclusiveLock(
            $this->lockFile,
            static fn (): string => 'first',
            $seal,
            waitTimeoutSeconds: 1.0,
        ));
        $first = @\lstat($this->lockFile);
        self::assertIsArray($first);
        $nextSecondDeadline = (\hrtime(true) / 1_000_000_000) + 2.0;
        while (\time() <= (int)$first['ctime']
            && (\hrtime(true) / 1_000_000_000) < $nextSecondDeadline
        ) {
            \usleep(1_000);
        }
        self::assertGreaterThan((int)$first['ctime'], \time());

        self::assertSame('second', GatewayProjectStateFilesystem::withExclusiveLock(
            $this->lockFile,
            static fn (): string => 'second',
            $seal,
            waitTimeoutSeconds: 1.0,
        ));
        $second = @\lstat($this->lockFile);
        self::assertIsArray($second);

        self::assertSame(2, $sealCalls);
        self::assertSame((int)$first['dev'], (int)$second['dev']);
        self::assertSame((int)$first['ino'], (int)$second['ino']);
        self::assertSame((int)$first['ctime'], (int)$second['ctime']);
        self::assertSame(0100600, (int)$second['mode']);
        self::assertSame(1, (int)$second['nlink']);
    }

    /**
     * A forked PHPUnit child inherits every already-open runtime descriptor.
     * Replacing the child image before reporting its status prevents PHP/PDO
     * destructors from sending a PostgreSQL terminate frame on the parent's
     * shared socket while preserving the exact process exit code.
     */
    private static function exitForkedChildWithoutRuntimeShutdown(int $status): never
    {
        $status = \max(0, \min(255, $status));
        if (\function_exists('pcntl_exec')) {
            @\pcntl_exec(PHP_BINARY, [
                '-n',
                '-r',
                'exit(' . $status . ');',
            ]);
        }
        exit($status);
    }
}
