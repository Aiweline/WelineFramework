<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServiceOrchestrator;

final class StartupTraceLockSafetyTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupFiles = [];

    /** @var list<string> */
    private array $cleanupDirectories = [];

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->cleanupFiles) as $file) {
            if (\is_link($file) || \is_file($file)) {
                @\unlink($file);
            }
        }
        foreach (\array_reverse($this->cleanupDirectories) as $directory) {
            @\rmdir($directory);
        }
    }

    public function testBothStartupTraceEntrypointsUseAVerifiedNonBlockingAppender(): void
    {
        foreach ([Start::class, MasterProcess::class] as $class) {
            $reflection = new \ReflectionClass($class);
            self::assertTrue(
                $reflection->hasMethod('appendStartupTraceLine'),
                $class . ' must keep diagnostic trace I/O behind the verified appender.',
            );
            $traceSource = $this->methodSource($reflection->getMethod('traceStartupPhase'));
            self::assertStringContainsString('self::appendStartupTraceLine($path, $line);', $traceSource);
            self::assertStringNotContainsString('FILE_APPEND | LOCK_EX', $traceSource);
            self::assertStringNotContainsString('\\flock($traceHandle, LOCK_EX)', $traceSource);

            $appendSource = $this->methodSource($reflection->getMethod('appendStartupTraceLine'));
            self::assertStringContainsString('LOCK_EX | LOCK_NB', $appendSource);
            self::assertStringContainsString('\\lstat($path)', $appendSource);
            self::assertStringContainsString('\\fstat($handle)', $appendSource);
            self::assertStringContainsString("['nlink']", $appendSource);
        }
    }

    public function testBothStartupTraceWritersSkipAContendedFileImmediately(): void
    {
        [$directory, $path] = $this->createTracePath('contention');
        $handle = \fopen($path, 'x+b');
        self::assertIsResource($handle);
        self::assertSame(7, \fwrite($handle, 'foreign'));
        self::assertTrue(\fflush($handle));
        self::assertTrue(\flock($handle, LOCK_EX));
        $this->cleanupFiles[] = $path;

        try {
            foreach ([Start::class, MasterProcess::class] as $class) {
                $startedAt = \hrtime(true);
                $this->invokeAppender($class, $path, "trace\n");
                $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;
                self::assertLessThan(0.1, $elapsed, $class . ' blocked on a diagnostic trace lock.');
            }
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }

        self::assertSame('foreign', (string)\file_get_contents($path));
        self::assertDirectoryExists($directory);
    }

    public function testBothStartupTraceWritersAppendToTheVerifiedInode(): void
    {
        [, $path] = $this->createTracePath('success');
        $this->cleanupFiles[] = $path;

        $this->invokeAppender(Start::class, $path, "start\n");
        $this->invokeAppender(MasterProcess::class, $path, "master\n");

        self::assertSame("start\nmaster\n", (string)\file_get_contents($path));
        $status = @\lstat($path);
        self::assertIsArray($status);
        self::assertSame(1, (int)($status['nlink'] ?? 0));
    }

    public function testBothStartupTraceWritersRefuseToFollowASymbolicLink(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link creation is not available on this runtime.');
        }
        [$directory, $path] = $this->createTracePath('symlink');
        $foreign = $directory . DIRECTORY_SEPARATOR . 'foreign.log';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\symlink($foreign, $path));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $path;

        foreach ([Start::class, MasterProcess::class] as $class) {
            $this->invokeAppender($class, $path, "trace\n");
        }

        self::assertSame('foreign', (string)\file_get_contents($foreign));
    }

    public function testBothStartupTraceWritersRejectAHardLinkedInode(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        [$directory, $path] = $this->createTracePath('hardlink');
        $foreign = $directory . DIRECTORY_SEPARATOR . 'foreign.log';
        self::assertNotFalse(\file_put_contents($foreign, 'foreign'));
        self::assertTrue(\link($foreign, $path));
        $this->cleanupFiles[] = $foreign;
        $this->cleanupFiles[] = $path;

        foreach ([Start::class, MasterProcess::class] as $class) {
            $this->invokeAppender($class, $path, "trace\n");
        }

        self::assertSame('foreign', (string)\file_get_contents($foreign));
    }

    public function testStopTraceUsesAVerifiedNonBlockingAppender(): void
    {
        $reflection = new \ReflectionClass(ServiceOrchestrator::class);
        self::assertTrue($reflection->hasMethod('appendStopTraceFileLine'));
        $traceSource = $this->methodSource(
            $reflection->getMethod('appendStopTraceLine'),
        );
        self::assertStringContainsString(
            'self::appendStopTraceFileLine($file, $line);',
            $traceSource,
        );
        self::assertStringNotContainsString('FILE_APPEND | LOCK_EX', $traceSource);

        $appendSource = $this->methodSource(
            $reflection->getMethod('appendStopTraceFileLine'),
        );
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $appendSource);
        self::assertStringContainsString('\\lstat($path)', $appendSource);
        self::assertStringContainsString('\\fstat($handle)', $appendSource);
        self::assertStringContainsString("['nlink']", $appendSource);
    }

    public function testStopTraceWriterDropsAContendedDiagnosticImmediately(): void
    {
        [, $path] = $this->createTracePath('stop-contention');
        $handle = \fopen($path, 'x+b');
        self::assertIsResource($handle);
        self::assertSame(7, \fwrite($handle, 'foreign'));
        self::assertTrue(\fflush($handle));
        self::assertTrue(\flock($handle, LOCK_EX));
        $this->cleanupFiles[] = $path;

        try {
            $startedAt = \hrtime(true);
            $this->invokeStopAppender($path, "stop\n");
            $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;
            self::assertLessThan(0.1, $elapsed);
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
        self::assertSame('foreign', (string)\file_get_contents($path));
    }

    public function testStopTraceWriterAppendsOnlyToASingleLinkRegularInode(): void
    {
        [$directory, $path] = $this->createTracePath('stop-success');
        $this->cleanupFiles[] = $path;
        $this->invokeStopAppender($path, "stop\n");
        self::assertSame("stop\n", (string)\file_get_contents($path));

        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            return;
        }
        @\unlink($path);
        $peer = $directory . DIRECTORY_SEPARATOR . 'stop-peer.log';
        self::assertNotFalse(\file_put_contents($peer, 'peer'));
        self::assertTrue(\link($peer, $path));
        $this->cleanupFiles[] = $peer;
        $this->invokeStopAppender($path, "blocked\n");
        self::assertSame('peer', (string)\file_get_contents($peer));
    }

    /** @return array{string,string} */
    private function createTracePath(string $suffix): array
    {
        $directory = (string)\realpath(\sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'wls-startup-trace-' . $suffix . '-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700));
        $this->cleanupDirectories[] = $directory;

        return [$directory, $directory . DIRECTORY_SEPARATOR . 'wls-startup-trace.log'];
    }

    /** @param class-string $class */
    private function invokeAppender(string $class, string $path, string $line): void
    {
        $method = new \ReflectionMethod($class, 'appendStartupTraceLine');
        $method->setAccessible(true);
        $method->invoke(null, $path, $line);
    }

    private function invokeStopAppender(string $path, string $line): void
    {
        $method = new \ReflectionMethod(
            ServiceOrchestrator::class,
            'appendStopTraceFileLine',
        );
        $method->invoke(null, $path, $line);
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
