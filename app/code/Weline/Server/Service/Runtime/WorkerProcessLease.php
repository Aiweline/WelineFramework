<?php

namespace Weline\Server\Service\Runtime;

use Weline\Framework\System\Process\Processer;

/**
 * Owns the exact Processer lease for one Worker generation.
 *
 * Worker launch commands carry private control-plane credentials, so only the
 * redacted stable identity is persisted. Registration happens in the child;
 * getmypid() is therefore the real PHP Worker PID on every platform, never a
 * POSIX launcher, PowerShell helper, cmd.exe, or other intermediate process.
 */
final class WorkerProcessLease
{
    private static int $pid = 0;
    private static string $processName = '';
    private static string $launchId = '';
    private static bool $shutdownRegistered = false;

    public static function register(string $processName, string $launchId, int $epoch): void
    {
        $processName = \trim($processName);
        $launchId = \trim($launchId);
        if ($processName === ''
            || \preg_match('/^[a-zA-Z0-9_.:@-]+$/D', $processName) !== 1
            || $launchId === ''
            || $epoch <= 0) {
            throw new \RuntimeException('Worker managed-process identity is incomplete or invalid.');
        }

        $pid = \getmypid();
        if (!\is_int($pid) || $pid <= 0) {
            throw new \RuntimeException('Worker managed-process PID is unavailable.');
        }

        $identity = '--name=' . $processName
            . ' --launch-id=' . \rawurlencode($launchId)
            . ' --epoch=' . $epoch;
        Processer::setPid($identity, $pid);
        self::$pid = $pid;
        self::$processName = $processName;
        self::$launchId = $launchId;

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            \register_shutdown_function(static function (): void {
                self::release();
            });
        }
    }

    public static function release(): void
    {
        $pid = self::$pid;
        $processName = self::$processName;
        $launchId = self::$launchId;
        self::$pid = 0;
        self::$processName = '';
        self::$launchId = '';
        if ($pid <= 0 || $processName === '' || $launchId === '') {
            return;
        }

        try {
            Processer::removeManagedProcessLeaseRecord($pid, $processName, $launchId);
        } catch (\Throwable $throwable) {
            \error_log('[WorkerProcessLease] release failed: ' . $throwable->getMessage());
        }
    }
}
