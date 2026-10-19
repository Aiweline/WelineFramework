<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Compilation;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;

final class AtomicCompiledFilePublisherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'weline-compile-lock-'
            . \bin2hex(\random_bytes(6));
        if (!@\mkdir($this->tempDir, 0777, true) && !\is_dir($this->tempDir)) {
            self::fail('Failed to create temp directory for compile-lock tests.');
        }
    }

    protected function tearDown(): void
    {
        AtomicCompiledFilePublisher::releaseDirectoryLock($this->tempDir);
        foreach (\glob($this->tempDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testDefaultAndCliTimeoutBudgets(): void
    {
        $default = new AtomicCompiledFilePublisher();
        $cli = AtomicCompiledFilePublisher::forCli();

        self::assertSame(
            AtomicCompiledFilePublisher::DEFAULT_LOCK_TIMEOUT_MILLISECONDS,
            $default->getLockTimeoutMilliseconds(),
        );
        self::assertSame(10_000, $default->getLockTimeoutMilliseconds());
        self::assertSame(
            AtomicCompiledFilePublisher::CLI_LOCK_TIMEOUT_MILLISECONDS,
            $cli->getLockTimeoutMilliseconds(),
        );
        self::assertSame(180_000, $cli->getLockTimeoutMilliseconds());
    }

    public function testDescribeAndFormatLockHolderAfterExclusiveAcquire(): void
    {
        $publisher = new AtomicCompiledFilePublisher(1_000);
        self::assertTrue($publisher->acquireDirectoryLock($this->tempDir, \LOCK_EX));

        $holder = AtomicCompiledFilePublisher::describeLockHolder($this->tempDir);
        self::assertNotNull($holder);
        self::assertSame((int)\getmypid(), $holder['pid']);
        self::assertNotSame('', $holder['acquired_at']);
        self::assertTrue($holder['alive']);

        $formatted = AtomicCompiledFilePublisher::formatLockHolder($this->tempDir);
        self::assertStringContainsString('holder_pid=' . (int)\getmypid(), $formatted);
        self::assertStringContainsString('holder_status=alive', $formatted);

        AtomicCompiledFilePublisher::releaseDirectoryLock($this->tempDir);
    }

    public function testDefaultPublisherTimesOutWithHolderMetadataUnderContention(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Contention UC uses POSIX flock child process.');
        }

        $lockFile = $this->tempDir . \DIRECTORY_SEPARATOR . '.framework-compile.lock';
        $holdScript = $this->tempDir . \DIRECTORY_SEPARATOR . 'hold-lock.php';
        \file_put_contents($holdScript, <<<'PHP'
<?php
$lockFile = $argv[1] ?? '';
$holdMs = (int)($argv[2] ?? 1500);
$handle = fopen($lockFile, 'c+b');
if (!$handle || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "hold-failed\n");
    exit(2);
}
ftruncate($handle, 0);
rewind($handle);
fwrite($handle, "pid=" . getmypid() . "\nacquired_at=" . date(DATE_ATOM) . "\n");
fflush($handle);
fwrite(STDOUT, "held:" . getmypid() . "\n");
usleep($holdMs * 1000);
flock($handle, LOCK_UN);
fclose($handle);
PHP);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [\PHP_BINARY, $holdScript, $lockFile, '2000'],
            $descriptors,
            $pipes,
            $this->tempDir,
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = '';
        $deadline = \hrtime(true) + 2_000_000_000;
        while (\hrtime(true) < $deadline) {
            $chunk = \fread($pipes[1], 1024);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            if (\str_contains($stdout, 'held:')) {
                break;
            }
            \usleep(10_000);
        }
        self::assertMatchesRegularExpression('/held:(\d+)/', $stdout, 'Child did not report lock hold.');
        \preg_match('/held:(\d+)/', $stdout, $match);
        $holderPid = (int)$match[1];

        $publisher = new AtomicCompiledFilePublisher(250);
        $started = \hrtime(true);
        try {
            $publisher->publish(
                $this->tempDir . \DIRECTORY_SEPARATOR . 'artifact.php',
                "<?php\nreturn ['ok' => true];\n",
            );
            self::fail('Expected lock timeout under contention.');
        } catch (\RuntimeException $e) {
            $elapsedMs = (int)((\hrtime(true) - $started) / 1_000_000);
            self::assertGreaterThanOrEqual(200, $elapsedMs);
            self::assertLessThan(2_000, $elapsedMs);
            self::assertStringContainsString('Timed out after 250ms', $e->getMessage());
            self::assertStringContainsString('holder_pid=' . $holderPid, $e->getMessage());
            self::assertStringContainsString('holder_status=alive', $e->getMessage());
        } finally {
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $status = \proc_close($process);
            self::assertSame(0, $status);
        }
    }

    public function testCliPublisherWaitsOutShortContentionAndPublishes(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Contention UC uses POSIX flock child process.');
        }

        $lockFile = $this->tempDir . \DIRECTORY_SEPARATOR . '.framework-compile.lock';
        $holdScript = $this->tempDir . \DIRECTORY_SEPARATOR . 'hold-lock-short.php';
        \file_put_contents($holdScript, <<<'PHP'
<?php
$lockFile = $argv[1] ?? '';
$handle = fopen($lockFile, 'c+b');
if (!$handle || !flock($handle, LOCK_EX)) {
    exit(2);
}
ftruncate($handle, 0);
rewind($handle);
fwrite($handle, "pid=" . getmypid() . "\nacquired_at=" . date(DATE_ATOM) . "\n");
fflush($handle);
fwrite(STDOUT, "held\n");
usleep(400_000);
flock($handle, LOCK_UN);
fclose($handle);
PHP);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [\PHP_BINARY, $holdScript, $lockFile],
            $descriptors,
            $pipes,
            $this->tempDir,
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = '';
        $deadline = \hrtime(true) + 2_000_000_000;
        while (\hrtime(true) < $deadline) {
            $chunk = \fread($pipes[1], 1024);
            if ($chunk !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            if (\str_contains($stdout, 'held')) {
                break;
            }
            \usleep(10_000);
        }
        self::assertStringContainsString('held', $stdout);

        // CLI budget is 180s; use a shortened CLI-like publisher (2s) to prove wait-and-succeed.
        $publisher = new AtomicCompiledFilePublisher(2_000);
        $target = $this->tempDir . \DIRECTORY_SEPARATOR . 'artifact-cli.php';
        $publisher->publish($target, "<?php\nreturn ['ok' => true];\n");
        self::assertFileExists($target);
        self::assertSame(['ok' => true], include $target);

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        self::assertSame(0, \proc_close($process));
    }

    public function testControlPlaneSourcesUseForCliFactory(): void
    {
        $frameworkRoot = \dirname(__DIR__, 3);
        $files = [
            $frameworkRoot . '/Compilation/FrameworkCompiler.php',
            $frameworkRoot . '/Setup/Console/Setup/Upgrade.php',
            $frameworkRoot . '/Setup/Stage/FileUpdateStage.php',
            \dirname($frameworkRoot) . '/Server/Console/Server/Start.php',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file, $file);
            $src = (string)\file_get_contents($file);
            self::assertStringContainsString(
                'AtomicCompiledFilePublisher::forCli()',
                $src,
                $file . ' must use forCli()',
            );
            self::assertStringNotContainsString(
                'new AtomicCompiledFilePublisher(60_000)',
                $src,
                $file . ' must not hardcode legacy 60s timeout',
            );
        }
    }
}
