<?php

declare(strict_types=1);

namespace Weline\Cron\Test\Unit\Schedule\Linux;

use PHPUnit\Framework\TestCase;
use Weline\Cron\Console\Cron\Task\Run;
use Weline\Cron\Schedule\Linux\Crontab;

final class CrontabScriptTest extends TestCase
{
    public function testGeneratedWrapperUsesPerProjectSingleFlightLock(): void
    {
        $method = new \ReflectionMethod(Crontab::class, 'buildShellScript');
        $script = $method->invoke(
            new Crontab(),
            '/tmp/Weline Project',
            '/opt/php 8.4/bin/php',
            '/tmp/Weline Project/var/cron.log',
        );

        self::assertStringStartsWith('#!/bin/sh' . PHP_EOL, $script);
        self::assertStringContainsString(
            "CRON_LOCK_FILE='/tmp/Weline Project/var/cron-main.lock'",
            $script,
        );
        self::assertStringContainsString('command -v lockf', $script);
        self::assertStringContainsString('exec lockf -k -t 0 "$CRON_LOCK_FILE"', $script);
        self::assertStringContainsString('command -v flock', $script);
        self::assertStringContainsString('exec flock -n "$CRON_LOCK_FILE"', $script);
        self::assertSame(
            2,
            \substr_count($script, 'bin/w cron:task:run'),
            'Only the lockf and flock branches may start a dispatcher.',
        );
        self::assertStringContainsString('exit 75', $script);
    }

    public function testGeneratedWrapperHasValidPosixShellSyntax(): void
    {
        if (IS_WIN || !\function_exists('proc_open')) {
            self::markTestSkipped('POSIX shell syntax validation is unavailable.');
        }

        $method = new \ReflectionMethod(Crontab::class, 'buildShellScript');
        $script = $method->invoke(
            new Crontab(),
            '/tmp/Weline Project',
            PHP_BINARY,
            '/tmp/Weline Project/var/cron.log',
        );
        $path = \tempnam(\sys_get_temp_dir(), 'weline-cron-wrapper-');
        self::assertIsString($path);

        try {
            self::assertNotFalse(\file_put_contents($path, $script));
            $command = ['/bin/sh', '-n', $path];
            $pipes = [];
            $process = \proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            self::assertSame(0, \proc_close($process));
        } finally {
            @\unlink($path);
        }
    }

    public function testGeneratedWrapperAllowsOnlyOneConcurrentDispatcher(): void
    {
        if (IS_WIN || !\function_exists('proc_open')) {
            self::markTestSkipped('POSIX process concurrency validation is unavailable.');
        }
        $lockTool = \trim((string)\shell_exec('command -v lockf 2>/dev/null || command -v flock 2>/dev/null'));
        if ($lockTool === '') {
            self::markTestSkipped('Neither lockf nor flock is available.');
        }

        $base = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-cron-lock-' . \bin2hex(\random_bytes(4));
        $binDir = $base . DIRECTORY_SEPARATOR . 'bin';
        $varDir = $base . DIRECTORY_SEPARATOR . 'var';
        $runner = $base . DIRECTORY_SEPARATOR . 'fake-php.sh';
        $wrapper = $base . DIRECTORY_SEPARATOR . 'cron.sh';
        $record = $base . DIRECTORY_SEPARATOR . 'started.log';
        $release = $base . DIRECTORY_SEPARATOR . 'release';
        self::assertTrue(\mkdir($binDir, 0777, true));
        self::assertTrue(\mkdir($varDir, 0777, true));

        $method = new \ReflectionMethod(Crontab::class, 'buildShellScript');
        $script = $method->invoke(
            new Crontab(),
            $base,
            $runner,
            $varDir . DIRECTORY_SEPARATOR . 'cron.log',
        );
        $runnerScript = '#!/bin/sh' . PHP_EOL
            . 'printf \'%s\\n\' "$$" >> "$CRON_TEST_RECORD"' . PHP_EOL
            . 'while [ ! -f "$CRON_TEST_RELEASE" ]; do sleep 0.02; done' . PHP_EOL;

        $first = null;
        $second = null;
        try {
            self::assertNotFalse(\file_put_contents($runner, $runnerScript));
            self::assertTrue(\chmod($runner, 0700));
            self::assertNotFalse(\file_put_contents($wrapper, $script));
            $environment = [
                'PATH' => (string)\getenv('PATH'),
                'CRON_TEST_RECORD' => $record,
                'CRON_TEST_RELEASE' => $release,
            ];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $firstPipes = [];
            $first = \proc_open(['/bin/sh', $wrapper], $descriptors, $firstPipes, $base, $environment);
            self::assertIsResource($first);
            $this->closePipes($firstPipes);

            $deadline = \microtime(true) + 2.0;
            while (!\is_file($record) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($record, 'The first dispatcher did not acquire the project lock.');

            $secondPipes = [];
            $second = \proc_open(['/bin/sh', $wrapper], $descriptors, $secondPipes, $base, $environment);
            self::assertIsResource($second);
            $this->closePipes($secondPipes);
            \proc_close($second);
            $second = null;

            $starts = \file($record, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($starts);
            self::assertCount(1, $starts);
        } finally {
            @\touch($release);
            if (\is_resource($second)) {
                @\proc_terminate($second);
                @\proc_close($second);
            }
            if (\is_resource($first)) {
                @\proc_close($first);
            }
            foreach ([
                $record,
                $release,
                $runner,
                $wrapper,
                $varDir . DIRECTORY_SEPARATOR . 'cron.log',
                $varDir . DIRECTORY_SEPARATOR . 'cron-main.lock',
            ] as $path) {
                @\unlink($path);
            }
            @\rmdir($binDir);
            @\rmdir($varDir);
            @\rmdir($base);
        }
    }

    public function testCronChildColdStartHandoffBudgetIsThirtySeconds(): void
    {
        $constant = new \ReflectionClassConstant(Run::class, 'CHILD_GATE_HANDOFF_TIMEOUT_MS');

        self::assertSame(30_000, $constant->getValue());
    }

    public function testOnlySqliteSerializesManagedCronChildren(): void
    {
        $method = new \ReflectionMethod(Run::class, 'requiresManagedChildSerialization');

        self::assertTrue($method->invoke(null, 'sqlite'));
        self::assertTrue($method->invoke(null, ' SQLITE '));
        self::assertFalse($method->invoke(null, 'mysql'));
        self::assertFalse($method->invoke(null, 'pgsql'));

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Console/Cron/Task/Run.php'
        );
        self::assertStringContainsString('elseif ($serializeManagedChildren)', $source);
        self::assertStringContainsString('waitForManagedChildCompletion(', $source);
        self::assertStringContainsString('Process::reapManagedCronChildIfExited(', $source);
        self::assertStringContainsString('受管计划任务子进程已退出但未提交终态', $source);
    }

    /** @param array<int,resource> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }
    }
}
