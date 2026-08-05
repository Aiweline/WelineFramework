<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Lock;

/**
 * Cross-process database-access barrier for setup:upgrade and Cron.
 *
 * Setup owns an exclusive lease for the full non-hot command. Cron owns a
 * shared lease before it reads task models and keeps it until all dispatch
 * bookkeeping is complete. The coordination file is intentionally stable;
 * activity is determined by flock(), never by file existence.
 */
final class SetupDatabaseAccessLock
{
    public const LOCK_FILENAME = 'setup_database_access.lock';
    private const HANDOFF_FILE_PREFIX = 'setup_database_access_handoff_';
    private const HANDOFF_READY_SUFFIX = '.ready';
    private const HANDOFF_DECISION_SUFFIX = '.decision';
    private const HANDOFF_TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    private static ?self $cliBootstrapLease = null;

    /** @var resource|null */
    private $handle = null;
    private int $ownerPid = 0;
    private ?int $mode = null;
    private bool $shutdownReleaseRegistered = false;
    private bool $retainedForCliBootstrap = false;

    public function acquireShared(): bool
    {
        return $this->acquire(LOCK_SH);
    }

    public function acquireExclusive(): bool
    {
        return $this->acquire(LOCK_EX);
    }

    public static function newSharedHandoffToken(): string
    {
        self::cleanupExpiredSharedHandoffs();
        return bin2hex(random_bytes(16));
    }

    /**
     * Retain an already-acquired lease across the complete top-level CLI
     * process, including application bootstrap and optional follow-up commands.
     */
    public static function retainCliBootstrapLease(self $lease): void
    {
        if (!is_resource($lease->handle)
            || $lease->ownerPid !== (int)(getmypid() ?: 0)
            || !\in_array($lease->mode, [LOCK_SH, LOCK_EX], true)
        ) {
            throw new \LogicException('An acquired setup database access lock is required for CLI bootstrap retention.');
        }
        if (self::$cliBootstrapLease !== null && self::$cliBootstrapLease !== $lease) {
            throw new \LogicException('A CLI bootstrap database access lease is already retained.');
        }

        $lease->retainedForCliBootstrap = true;
        self::$cliBootstrapLease = $lease;
        // Do not register an early unlock callback here. Application bootstrap
        // registers additional shutdown handlers after this point, and any of
        // them may access the database. The entrypoint lease intentionally
        // remains retained through all PHP shutdown callbacks; the engine/OS
        // closes the descriptor after shutdown. Explicit probes may call
        // releaseCliBootstrapLease() before process termination.
    }

    public static function borrowCliBootstrapSharedLease(): ?self
    {
        return self::borrowCliBootstrapLease(LOCK_SH);
    }

    public static function borrowCliBootstrapExclusiveLease(): ?self
    {
        return self::borrowCliBootstrapLease(LOCK_EX);
    }

    public static function releaseCliBootstrapLease(): void
    {
        $lease = self::$cliBootstrapLease;
        self::$cliBootstrapLease = null;
        if ($lease === null) {
            return;
        }

        $lease->retainedForCliBootstrap = false;
        $lease->release();
    }

    /**
     * Publish proof that a spawned Cron child owns its own shared lease.
     */
    public function publishSharedHandoff(string $token): bool
    {
        $token = self::normalizeHandoffToken($token);
        if (!is_resource($this->handle)
            || $this->ownerPid !== (int)(getmypid() ?: 0)
            || $this->mode !== LOCK_SH
        ) {
            throw new \LogicException('Shared setup database access lock is required before publishing handoff readiness.');
        }

        $path = self::handoffPath($token);
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            return false;
        }
        $payload = 'pid=' . $this->ownerPid . PHP_EOL;
        if (!self::writeCompletePayload($handle, $payload)) {
            @fclose($handle);
            @unlink($path);
            return false;
        }
        @fclose($handle);

        return true;
    }

    /**
     * Wait while the parent still owns SH, then remove the one-shot marker.
     */
    public static function waitForSharedHandoff(string $token, int $timeoutMilliseconds = 10_000): int
    {
        $token = self::normalizeHandoffToken($token);
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 30_000) {
            throw new \InvalidArgumentException('Shared lock handoff timeout must be between 1 and 30000 milliseconds.');
        }

        $path = self::handoffPath($token);
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            clearstatcache(true, $path);
            $contents = @file_get_contents($path);
            if (is_string($contents)
                && preg_match('/^pid=([1-9][0-9]*)\R?$/D', $contents, $matches) === 1
            ) {
                return (int)$matches[1];
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(10_000);
        } while (true);

        return 0;
    }

    /**
     * Publish the parent's one-shot decision after READY and fenced PID CAS.
     */
    public static function publishSharedHandoffDecision(string $token, bool $allowExecution): bool
    {
        $token = self::normalizeHandoffToken($token);
        $path = self::handoffDecisionPath($token);
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            return false;
        }

        $payload = 'decision=' . ($allowExecution ? 'go' : 'abort') . PHP_EOL;
        if (!self::writeCompletePayload($handle, $payload)) {
            @fclose($handle);
            @unlink($path);
            return false;
        }
        @fclose($handle);

        return true;
    }

    /**
     * A spawned child must not enter business code until the parent publishes
     * GO. Missing, malformed, or timed-out decisions fail closed.
     */
    public static function waitForSharedHandoffDecision(
        string $token,
        int $timeoutMilliseconds = 15_000,
    ): ?bool {
        $token = self::normalizeHandoffToken($token);
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > 30_000) {
            throw new \InvalidArgumentException('Shared lock handoff decision timeout must be between 1 and 30000 milliseconds.');
        }

        $path = self::handoffDecisionPath($token);
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            clearstatcache(true, $path);
            $contents = @file_get_contents($path);
            if ($contents === 'decision=go' . PHP_EOL || $contents === 'decision=go') {
                self::cleanupSharedHandoff($token);
                return true;
            }
            if ($contents === 'decision=abort' . PHP_EOL || $contents === 'decision=abort') {
                self::cleanupSharedHandoff($token);
                return false;
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(10_000);
        } while (true);

        return null;
    }

    public static function cleanupSharedHandoff(string $token): void
    {
        $token = self::normalizeHandoffToken($token);
        @unlink(self::handoffPath($token));
        @unlink(self::handoffDecisionPath($token));
    }

    public function release(): void
    {
        if ($this->retainedForCliBootstrap
            && self::$cliBootstrapLease === $this
            && $this->ownerPid === (int)(getmypid() ?: 0)
        ) {
            return;
        }
        if (!is_resource($this->handle)) {
            $this->handle = null;
            $this->ownerPid = 0;
            $this->mode = null;
            return;
        }

        $handle = $this->handle;
        $ownerPid = $this->ownerPid;
        $this->handle = null;
        $this->ownerPid = 0;
        $this->mode = null;

        // A forked child may inherit the descriptor before exec. It may close
        // its own copy, but must never unlock the lease owned by the parent.
        if ($ownerPid === (int)(getmypid() ?: 0)) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
    }

    public static function path(): string
    {
        return BP . 'var' . DIRECTORY_SEPARATOR . 'process' . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
    }

    private static function handoffPath(string $token): string
    {
        return dirname(self::path()) . DIRECTORY_SEPARATOR . self::HANDOFF_FILE_PREFIX . $token
            . self::HANDOFF_READY_SUFFIX;
    }

    private static function handoffDecisionPath(string $token): string
    {
        return dirname(self::path()) . DIRECTORY_SEPARATOR . self::HANDOFF_FILE_PREFIX . $token
            . self::HANDOFF_DECISION_SUFFIX;
    }

    private static function cleanupExpiredSharedHandoffs(int $maxAgeSeconds = 3600): void
    {
        $directory = dirname(self::path());
        $patterns = [
            $directory . DIRECTORY_SEPARATOR . self::HANDOFF_FILE_PREFIX . '*' . self::HANDOFF_READY_SUFFIX,
            $directory . DIRECTORY_SEPARATOR . self::HANDOFF_FILE_PREFIX . '*' . self::HANDOFF_DECISION_SUFFIX,
        ];
        $deadline = time() - max(60, $maxAgeSeconds);
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                clearstatcache(true, $path);
                $modified = @filemtime($path);
                if (is_int($modified) && $modified < $deadline) {
                    @unlink($path);
                }
            }
        }
    }

    private static function normalizeHandoffToken(string $token): string
    {
        $token = strtolower(trim($token));
        if (preg_match(self::HANDOFF_TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('Invalid setup database access handoff token.');
        }

        return $token;
    }

    /** @param resource $handle */
    private static function writeCompletePayload($handle, string $payload): bool
    {
        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $chunk = @fwrite($handle, substr($payload, $written));
            if (!is_int($chunk) || $chunk < 1) {
                return false;
            }
            $written += $chunk;
        }

        return $written === $length && @fflush($handle);
    }

    private static function borrowCliBootstrapLease(int $mode): ?self
    {
        $lease = self::$cliBootstrapLease;
        if ($lease === null) {
            return null;
        }
        if (!is_resource($lease->handle)
            || $lease->ownerPid !== (int)(getmypid() ?: 0)
            || $lease->mode !== $mode
        ) {
            throw new \LogicException('CLI bootstrap database access lease mode does not match the command.');
        }

        return $lease;
    }

    public function __destruct()
    {
        $this->release();
    }

    private function acquire(int $mode): bool
    {
        if ($mode !== LOCK_SH && $mode !== LOCK_EX) {
            throw new \InvalidArgumentException('Setup database access lock mode must be shared or exclusive.');
        }

        if (is_resource($this->handle)) {
            if ($this->ownerPid !== (int)(getmypid() ?: 0)) {
                throw new \RuntimeException('Cannot reuse an inherited setup database access lock.');
            }
            if ($this->mode === $mode || $this->mode === LOCK_EX) {
                return true;
            }
            throw new \RuntimeException('Cannot upgrade a shared setup database access lock in place.');
        }

        $lockFile = self::path();
        $directory = dirname($lockFile);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create setup database access lock directory: ' . $directory);
        }

        // `e` requests close-on-exec on POSIX. Retain a Windows/filesystem
        // fallback for runtimes that do not accept the flag.
        $handle = @fopen($lockFile, 'c+be');
        if (!is_resource($handle)) {
            $handle = @fopen($lockFile, 'c+b');
        }
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open setup database access lock: ' . $lockFile);
        }

        $wouldBlock = 0;
        if (!@flock($handle, $mode | LOCK_NB, $wouldBlock)) {
            @fclose($handle);
            if ($wouldBlock === 1) {
                return false;
            }
            throw new \RuntimeException('Unable to acquire setup database access lock: ' . $lockFile);
        }

        $this->handle = $handle;
        $this->ownerPid = (int)(getmypid() ?: 0);
        $this->mode = $mode;
        if (!$this->shutdownReleaseRegistered) {
            $this->shutdownReleaseRegistered = true;
            register_shutdown_function([$this, 'release']);
        }

        return true;
    }
}
