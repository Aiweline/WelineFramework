<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Cross-command lifecycle mutex for one WLS instance.
 *
 * Start, Stop, stale cleanup and wait-mode Reload take this lock before any
 * command-specific diagnostic lock. Same-process nesting is reference-counted
 * because Start's full-restart path deliberately delegates to Stop while the
 * outer startup transaction remains fenced.
 */
final class ServerLifecycleOperationLock
{
    /** @var array<string,array{handle:resource,depth:int,pid:int}> */
    private static array $heldByPath = [];

    private string $heldPath = '';

    private bool $acquired = false;

    public function acquire(
        string $instanceName,
        string $purpose,
        float $timeout,
    ): bool {
        if ($this->acquired
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
            || !\is_finite($timeout)
            || $timeout <= 0.0
            || $timeout > 300.0
        ) {
            return false;
        }
        $purpose = \strtolower(\trim($purpose));
        if (\preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $purpose) !== 1) {
            return false;
        }
        $path = self::pathForInstance($instanceName);
        $pid = (int)\getmypid();
        $existing = self::$heldByPath[$path] ?? null;
        if (\is_array($existing)) {
            if ((int)($existing['pid'] ?? 0) !== $pid
                || !\is_resource($existing['handle'] ?? null)
                || (int)($existing['depth'] ?? 0) < 1
            ) {
                // A fork inherits PHP statics and the underlying file
                // description. It must not treat the parent's ownership as a
                // re-entrant child lock or unlock the parent's transaction.
                return false;
            }
            self::$heldByPath[$path]['depth']++;
            $this->heldPath = $path;
            $this->acquired = true;
            return true;
        }

        $handle = VerifiedPersistentFileLock::acquire(
            $path,
            $timeout,
            static function () use ($instanceName, $purpose, $pid): array {
                $identity = [];
                try {
                    $identity = (new MasterLeaseRuntimeIdentity())->captureProcessIdentity($pid);
                } catch (\Throwable) {
                    // Diagnostic identity is optional; flock remains authority.
                }
                return [
                    'pid' => $pid,
                    'process_birth' => (string)($identity['birth'] ?? ''),
                    'pid_namespace_id' => (string)($identity['pid_namespace_id'] ?? ''),
                    'instance' => $instanceName,
                    'purpose' => $purpose,
                    'started_at' => \date('Y-m-d H:i:s'),
                ];
            },
        );
        if (!\is_resource($handle)) {
            return false;
        }
        self::$heldByPath[$path] = [
            'handle' => $handle,
            'depth' => 1,
            'pid' => $pid,
        ];
        $this->heldPath = $path;
        $this->acquired = true;

        return true;
    }

    public function release(): void
    {
        if (!$this->acquired || $this->heldPath === '') {
            return;
        }
        $path = $this->heldPath;
        $this->heldPath = '';
        $this->acquired = false;
        $entry = self::$heldByPath[$path] ?? null;
        if (!\is_array($entry) || (int)($entry['pid'] ?? 0) !== (int)\getmypid()) {
            return;
        }
        $depth = (int)($entry['depth'] ?? 0);
        if ($depth > 1) {
            self::$heldByPath[$path]['depth'] = $depth - 1;
            return;
        }
        $handle = $entry['handle'] ?? null;
        unset(self::$heldByPath[$path]);
        if (\is_resource($handle)) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function pathForInstance(string $instanceName): string
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('WLS lifecycle lock instance name is invalid.');
        }

        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'locks'
            . DIRECTORY_SEPARATOR . 'lifecycle_' . $instanceName . '.lock';
    }
}
