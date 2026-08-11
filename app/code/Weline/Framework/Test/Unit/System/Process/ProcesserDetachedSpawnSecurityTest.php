<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserDetachedSpawnSecurityTest extends TestCase
{
    public function testParentPublishesSpawnConfigThroughPrivateExclusiveFile(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'createPosixDetachedPhpArgvOutOfProcess');
        $source = (array)\file($method->getFileName());
        $body = \implode('', \array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString("\\mkdir(\$configDir, 0700", $body);
        self::assertStringContainsString("\\fopen(\$configPath, 'xb')", $body);
        self::assertStringContainsString('\\chmod($configPath, 0600)', $body);
        self::assertStringContainsString('\\fflush($configHandle)', $body);
        self::assertStringContainsString('\\fsync($configHandle)', $body);
        self::assertStringNotContainsString("\\mkdir(\$configDir, 0777", $body);
        self::assertStringNotContainsString('\\file_put_contents($configPath', $body);
    }

    public function testPosixDetachedChildrenFailClosedWhenWorkingDirectoryCannotBeEntered(): void
    {
        $method = new \ReflectionMethod(Processer::class, 'createPosixDetachedPhpArgv');
        $source = (array)\file($method->getFileName());
        $body = \implode('', \array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $helper = \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'System' . DIRECTORY_SEPARATOR . 'Process' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'posix_detached_spawn.php';
        $helperSource = (string)\file_get_contents($helper);

        self::assertStringContainsString("if (!@\\chdir(\$cwd))", $body);
        self::assertStringContainsString("if (!@\\chdir(\$cwd))", $helperSource);
        self::assertStringNotContainsString("@\\chdir(\$cwd);", $body);
        self::assertStringNotContainsString("@\\chdir(\$cwd);", $helperSource);
    }

    public function testHelperRejectsGroupReadableConfigBeforeFork(): void
    {
        $this->requirePosixHelperRuntime();
        $directory = $this->createPrivateTemporaryDirectory();
        $sentinel = $directory . DIRECTORY_SEPARATOR . 'executed';
        $configPath = $this->writeConfig($directory, $sentinel, 0644);

        try {
            $result = $this->invokeHelper($configPath, $directory);
            if ($result['exit'] === 0) {
                $this->waitForPath($sentinel, 0.5);
            }

            self::assertNotSame(0, $result['exit'], $result['stderr']);
            self::assertFileDoesNotExist($sentinel);
        } finally {
            $this->cleanupPaths([$sentinel, $configPath, $directory]);
        }
    }

    public function testHelperRejectsConfigFromGroupWritableDirectoryBeforeFork(): void
    {
        $this->requirePosixHelperRuntime();
        $directory = $this->createPrivateTemporaryDirectory();
        self::assertTrue(\chmod($directory, 0770));
        $sentinel = $directory . DIRECTORY_SEPARATOR . 'executed';
        $configPath = $this->writeConfig($directory, $sentinel, 0600);

        try {
            $result = $this->invokeHelper($configPath, $directory);
            if ($result['exit'] === 0) {
                $this->waitForPath($sentinel, 0.5);
            }

            self::assertNotSame(0, $result['exit'], $result['stderr']);
            self::assertFileDoesNotExist($sentinel);
        } finally {
            @\chmod($directory, 0700);
            $this->cleanupPaths([$sentinel, $configPath, $directory]);
        }
    }

    public function testHelperRejectsSymlinkConfigBeforeFork(): void
    {
        $this->requirePosixHelperRuntime();
        $directory = $this->createPrivateTemporaryDirectory();
        $sentinel = $directory . DIRECTORY_SEPARATOR . 'executed';
        $targetPath = $this->writeConfig($directory, $sentinel, 0600, 'target.json');
        $configPath = $directory . DIRECTORY_SEPARATOR
            . 'spawn-' . \bin2hex(\random_bytes(16)) . '.json';
        if (!@\symlink($targetPath, $configPath)) {
            $this->cleanupPaths([$targetPath, $directory]);
            self::markTestSkipped('Symbolic links are unavailable.');
        }

        try {
            $result = $this->invokeHelper($configPath, $directory);
            if ($result['exit'] === 0) {
                $this->waitForPath($sentinel, 0.5);
            }

            self::assertNotSame(0, $result['exit'], $result['stderr']);
            self::assertFileDoesNotExist($sentinel);
        } finally {
            $this->cleanupPaths([$sentinel, $configPath, $targetPath, $directory]);
        }
    }

    public function testHelperConsumesValidPrivateConfigBeforeDetachedExec(): void
    {
        $this->requirePosixHelperRuntime();
        $directory = $this->createPrivateTemporaryDirectory();
        $sentinel = $directory . DIRECTORY_SEPARATOR . 'executed';
        $configPath = $this->writeConfig($directory, $sentinel, 0600);

        try {
            $result = $this->invokeHelper($configPath, $directory);
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertTrue($this->waitForPath($sentinel, 2.0), 'Detached command did not execute.');
            self::assertFileDoesNotExist(
                $configPath,
                'The helper must consume the verified capability before it reports the child PID.'
            );
        } finally {
            $this->cleanupPaths([$sentinel, $configPath, $directory]);
        }
    }

    public function testParentLaunchesThroughTheRestrictedProductionSpool(): void
    {
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);
        }
        $this->requirePosixHelperRuntime();
        $directory = $this->createPrivateTemporaryDirectory();
        $sentinel = $directory . DIRECTORY_SEPARATOR . 'parent-executed';
        $method = new \ReflectionMethod(Processer::class, 'createPosixDetachedPhpArgvOutOfProcess');

        try {
            $pid = $method->invoke(
                null,
                [
                    PHP_BINARY,
                    '-r',
                    'file_put_contents(' . \var_export($sentinel, true) . ', "executed");',
                ],
                $directory,
                '--name=weline-test-secure-detached-spool',
                false,
                null,
                null,
            );
            self::assertIsInt($pid);
            self::assertGreaterThan(0, $pid);
            self::assertTrue($this->waitForPath($sentinel, 2.0), 'Secure spool child did not execute.');

            $spool = BP . 'var' . DIRECTORY_SEPARATOR . 'process' . DIRECTORY_SEPARATOR . 'spawn';
            $status = \lstat($spool);
            self::assertIsArray($status);
            self::assertSame(0700, ((int)$status['mode']) & 0777);
            self::assertFalse(\is_link($spool));
        } finally {
            $this->cleanupPaths([$sentinel, $directory]);
        }
    }

    private function requirePosixHelperRuntime(): void
    {
        if (\defined('IS_WIN') && IS_WIN) {
            self::markTestSkipped('The POSIX detached helper is not used on Windows.');
        }
        foreach (['proc_open', 'pcntl_fork', 'pcntl_exec', 'posix_setsid', 'posix_geteuid'] as $function) {
            if (!\function_exists($function)) {
                self::markTestSkipped('Required POSIX function is unavailable: ' . $function);
            }
        }
    }

    private function createPrivateTemporaryDirectory(): string
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline-detached-spawn-security-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700));
        self::assertTrue(\chmod($directory, 0700));

        return $directory;
    }

    private function writeConfig(
        string $directory,
        string $sentinel,
        int $mode,
        ?string $fileName = null,
    ): string {
        $configPath = $directory . DIRECTORY_SEPARATOR
            . ($fileName ?? ('spawn-' . \bin2hex(\random_bytes(16)) . '.json'));
        $payload = \json_encode([
            'cwd' => $directory,
            'argv' => [
                PHP_BINARY,
                '-r',
                'file_put_contents(' . \var_export($sentinel, true) . ', "executed");',
            ],
            'stdout' => '/dev/null',
            'stderr' => '/dev/null',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertIsString($payload);
        self::assertSame(\strlen($payload), \file_put_contents($configPath, $payload));
        self::assertTrue(\chmod($configPath, $mode));

        return $configPath;
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function invokeHelper(string $configPath, string $cwd): array
    {
        $helper = \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'System' . DIRECTORY_SEPARATOR . 'Process' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'posix_detached_spawn.php';
        self::assertFileExists($helper);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [PHP_BINARY, $helper, $configPath],
            $descriptors,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        @\fclose($pipes[0]);
        $stdout = \trim((string)\stream_get_contents($pipes[1]));
        $stderr = \trim((string)\stream_get_contents($pipes[2]));
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);

        return [
            'exit' => \proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function waitForPath(string $path, float $timeoutSeconds): bool
    {
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        do {
            if (\is_file($path)) {
                return true;
            }
            \usleep(10_000);
        } while (\hrtime(true) < $deadline);

        return \is_file($path);
    }

    /** @param list<string> $paths */
    private function cleanupPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (\is_link($path) || \is_file($path)) {
                @\unlink($path);
                continue;
            }
            if (\is_dir($path)) {
                @\rmdir($path);
            }
        }
    }
}
