<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

final class VerifiedPersistentFileLockSafetyTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-verified-lock-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $entries = @\scandir($this->root);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @\unlink($this->root . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @\rmdir($this->root);
    }

    public function testHardLinkAddedWhileWaitingCannotBecomeAnAuthorizedLock(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX flock/hard-link race contract.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . 'runtime.lock';
        $alias = $this->root . DIRECTORY_SEPARATOR . 'runtime.lock.alias';
        $ready = $this->root . DIRECTORY_SEPARATOR . 'child.ready';
        self::assertSame(0, \file_put_contents($path, ''));
        $holder = \fopen($path, 'r+b');
        self::assertIsResource($holder);
        self::assertTrue(\flock($holder, LOCK_EX));

        $autoload = \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        self::assertFileExists($autoload);
        $script = 'require ' . \var_export($autoload, true) . ';'
            . 'file_put_contents(' . \var_export($ready, true) . ', "ready");'
            . '$lock=\\Weline\\Server\\Service\\Runtime\\VerifiedPersistentFileLock::acquire('
            . \var_export($path, true) . ',2.0,static fn():array=>["child"=>true]);'
            . 'exit(is_resource($lock)?7:0);';
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \stream_set_blocking($pipe, false);
            }
        }

        try {
            $deadline = \hrtime(true) + 2_000_000_000;
            while (!\is_file($ready) && \hrtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($ready);
            // Give the child enough time to open and enter its non-blocking
            // retry loop, then mutate nlink while it is waiting.
            \usleep(150_000);
            self::assertTrue(\link($path, $alias));
            self::assertTrue(\flock($holder, LOCK_UN));
            self::assertTrue(\fclose($holder));

            $exitCode = \proc_close($process);
            $process = null;
            self::assertSame(
                0,
                $exitCode,
                'A lock inode that became multiply linked must fail closed after contention.',
            );
            self::assertSame('', \file_get_contents($path));
        } finally {
            if (\is_resource($holder)) {
                @\flock($holder, LOCK_UN);
                @\fclose($holder);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            if (\is_resource($process)) {
                @\proc_terminate($process, 9);
                @\proc_close($process);
            }
        }
    }

    public function testOrdinaryAcquireStillPublishesAndReleases(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'ordinary.lock';
        $handle = VerifiedPersistentFileLock::acquire(
            $path,
            0.25,
            static fn (): array => ['purpose' => 'test'],
        );
        self::assertIsResource($handle);
        self::assertTrue(VerifiedPersistentFileLock::isHeld($path));
        self::assertTrue(\flock($handle, LOCK_UN));
        self::assertTrue(\fclose($handle));
        self::assertFalse(VerifiedPersistentFileLock::isHeld($path));
    }

    public function testContendedAcquireHonorsSubRetryIntervalDeadline(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'deadline.lock';
        $holder = VerifiedPersistentFileLock::acquire(
            $path,
            0.25,
            static fn (): array => ['purpose' => 'deadline-holder'],
        );
        self::assertIsResource($holder);

        try {
            $startedAt = \hrtime(true);
            $contender = VerifiedPersistentFileLock::acquire(
                $path,
                0.01,
                static fn (): array => ['purpose' => 'deadline-contender'],
            );
            $elapsedSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

            self::assertFalse($contender);
            self::assertLessThan(
                0.08,
                $elapsedSeconds,
                'A 10ms lock budget must not inherit the fixed 100ms retry sleep.',
            );
        } finally {
            @\flock($holder, LOCK_UN);
            @\fclose($holder);
        }
    }
}
