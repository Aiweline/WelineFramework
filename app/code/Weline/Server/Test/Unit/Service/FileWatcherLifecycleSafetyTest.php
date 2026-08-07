<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\FileWatcher;

final class FileWatcherLifecycleSafetyTest extends TestCase
{
    public function testRunGuardCanStopWatcherWithoutReceivingAnOsSignal(): void
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-file-watcher-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($directory, 0700));

        try {
            $guardCalls = 0;
            $watcher = (new FileWatcher([$directory]))
                ->setCheckInterval(0.1)
                ->setRunGuard(static function () use (&$guardCalls): bool {
                    ++$guardCalls;
                    return false;
                });

            $watcher->watch();

            self::assertSame(1, $guardCalls);
        } finally {
            @\rmdir($directory);
        }
    }

    public function testInotifySelectUsesRealByReferenceVariables(): void
    {
        $source = $this->source('Service/FileWatcher.php');

        self::assertStringContainsString('$write = null;', $source);
        self::assertStringContainsString('$except = null;', $source);
        self::assertStringNotContainsString(
            'stream_select($read, $write = null, $except = null',
            $source,
        );
    }

    public function testWatcherEntrypointRequiresTheExactParentBirthFence(): void
    {
        $source = $this->source('bin/file_watcher.php');
        $autoloadOffset = \strpos($source, 'require_once BP');

        self::assertIsInt($autoloadOffset);
        self::assertStringContainsString('fwrite(STDERR', \substr($source, 0, $autoloadOffset));
        self::assertStringNotContainsString('w_log_error(', \substr($source, 0, $autoloadOffset));

        self::assertStringContainsString("['parent_pid']", $source);
        self::assertStringContainsString("['parent_process_birth']", $source);
        self::assertStringContainsString("['parent_pid_namespace_id']", $source);
        self::assertStringContainsString("['parent_host_boot_id']", $source);
        self::assertStringContainsString('observeProcessIdentity(', $source);
        self::assertStringContainsString('setRunGuard(', $source);
        self::assertStringContainsString('$configExpectedDevice', $source);
        self::assertStringContainsString('$configExpectedInode', $source);
        self::assertStringContainsString('clearstatcache(true, $configPath)', $source);
        self::assertStringContainsString('$currentConfigStat = @\\lstat($configPath);', $source);
        self::assertStringContainsString("(int)(\$currentConfigStat['ino'] ?? -1) !== \$configExpectedInode", $source);
    }

    public function testStartUsesCooperativeShutdownAndExactBirthFallbackOnly(): void
    {
        $source = $this->source('Console/Server/Start.php');
        $start = \strpos($source, 'protected function runFileWatcher(');
        $end = \strpos($source, 'protected function traceStartupPhase(', (int)$start);

        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThan($start, $end);
        $watcherMethods = \substr($source, $start, $end - $start);

        self::assertStringContainsString('captureProcessIdentity(', $watcherMethods);
        self::assertStringContainsString('terminateExactProcessIdentity(', $watcherMethods);
        self::assertStringContainsString('removeManagedProcessLeaseRecord(', $watcherMethods);
        self::assertSame(
            2,
            \substr_count($watcherMethods, 'Processer::setPid($processName, $pid, false);'),
            'Watcher leases must not enter the generic PID trust fast path.',
        );
        self::assertStringNotContainsString('Processer::killByPid(', $watcherMethods);
        self::assertStringNotContainsString('Processer::destroy(', $watcherMethods);
    }

    private function source(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $source = @\file_get_contents($path);

        self::assertIsString($source, 'Source should be readable: ' . $relativePath);
        self::assertNotSame('', $source, 'Source should not be empty: ' . $relativePath);

        return $source;
    }
}
