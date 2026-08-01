<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

/**
 * Same-user host coordination for memory-pressure capacity mutations.
 *
 * Every WLS project samples the same host memory. Without a shared fence, one
 * Critical sample can make every project drain a Worker at once and every
 * project later restore one at once. This short-lived derived lease serialises
 * those host-wide mutations while leaving reclaim broadcasts project-local.
 */
final class HostMemoryPressureCoordinator
{
    private const SCHEMA_VERSION = 1;
    private const MAX_STATE_BYTES = 65536;
    private const MIN_HOLD_SECONDS = 1.0;
    private const MAX_HOLD_SECONDS = 300.0;
    private const CLOCK_ROLLBACK_TOLERANCE_SECONDS = 1.0;
    private const LOCK_ACQUIRE_TIMEOUT_SECONDS = 0.25;
    private const LOCK_RETRY_MICROSECONDS = 10000;
    private const PROCESS_TERMINATION_GRACE_SECONDS = 0.5;
    private const PROCESS_TERMINATION_POLL_MICROSECONDS = 10000;

    private readonly string $stateDirectory;
    private readonly string $stateFile;
    private readonly string $lockFile;
    private readonly string $bootIdentity;

    public function __construct(
        string $stateDirectory,
        ?string $bootIdentity = null,
    ) {
        $this->stateDirectory = $this->normaliseAbsolutePath($stateDirectory);
        $this->stateFile = $this->stateDirectory . DIRECTORY_SEPARATOR
            . 'capacity-mutation.json';
        $this->lockFile = $this->stateDirectory . DIRECTORY_SEPARATOR
            . 'capacity-mutation.lock';
        $this->bootIdentity = $this->normaliseBootIdentity(
            $bootIdentity ?? $this->detectHostBootId(),
        );
    }

    /**
     * @return non-empty-string|null Opaque claim token, or null while another
     *   project owns the host mutation window.
     */
    public function claim(
        string $owner,
        string $action,
        float $now,
        float $holdSeconds,
    ): ?string {
        $owner = $this->normaliseOwner($owner);
        $action = $this->normaliseAction($action);
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \InvalidArgumentException(
                'Host memory-pressure claim time must be a positive finite monotonic timestamp.'
            );
        }
        $holdSeconds = \max(
            self::MIN_HOLD_SECONDS,
            \min(self::MAX_HOLD_SECONDS, $holdSeconds),
        );

        return $this->withLock(function () use ($owner, $action, $now, $holdSeconds): ?string {
            $state = $this->readState();
            $claim = \is_array($state['claim'] ?? null) ? $state['claim'] : [];
            $claimedAt = \is_numeric($claim['claimed_at'] ?? null)
                ? (float)$claim['claimed_at']
                : 0.0;
            $holdUntil = \is_numeric($claim['hold_until'] ?? null)
                ? (float)$claim['hold_until']
                : 0.0;
            $sameBoot = \is_string($claim['boot_id'] ?? null)
                && \hash_equals($this->bootIdentity, (string)$claim['boot_id']);
            $clockRolledBack = $claimedAt > 0.0
                && ($now + self::CLOCK_ROLLBACK_TOLERANCE_SECONDS) < $claimedAt;
            $emergencyShrinkPreemptsRecovery = $action === 'scale_down'
                && (string)($claim['action'] ?? '') === 'scale_up';
            if ($sameBoot
                && !$clockRolledBack
                && $claimedAt > 0.0
                && $holdUntil > $now
                && $this->validStoredClaim($claim)
                && !$emergencyShrinkPreemptsRecovery
            ) {
                return null;
            }

            $token = \bin2hex(\random_bytes(16));
            $this->publishState([
                'schema_version' => self::SCHEMA_VERSION,
                'claim' => [
                    'owner' => $owner,
                    'action' => $action,
                    'token' => $token,
                    'pid' => \getmypid(),
                    'boot_id' => $this->bootIdentity,
                    'claimed_at' => $now,
                    'hold_until' => $now + $holdSeconds,
                ],
                'updated_at' => \gmdate(DATE_ATOM),
            ]);

            return $token;
        });
    }

    /**
     * Release only the exact still-current claim. A stale project must never
     * clear a newer project's fence.
     */
    public function release(string $owner, string $token): bool
    {
        $owner = $this->normaliseOwner($owner);
        $token = \strtolower(\trim($token));
        if (\preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return false;
        }

        return $this->withLock(function () use ($owner, $token): bool {
            $state = $this->readState();
            $claim = \is_array($state['claim'] ?? null) ? $state['claim'] : [];
            if (!\hash_equals(
                $this->bootIdentity,
                (string)($claim['boot_id'] ?? ''),
            )
                || !\hash_equals($owner, (string)($claim['owner'] ?? ''))
                || !\hash_equals($token, (string)($claim['token'] ?? ''))
            ) {
                return false;
            }
            $this->publishState([
                'schema_version' => self::SCHEMA_VERSION,
                'claim' => null,
                'released_at' => \gmdate(DATE_ATOM),
                'updated_at' => \gmdate(DATE_ATOM),
            ]);

            return true;
        });
    }

    /**
     * @param array<string,mixed> $claim
     */
    private function validStoredClaim(array $claim): bool
    {
        $claimedAt = \is_numeric($claim['claimed_at'] ?? null)
            ? (float)$claim['claimed_at']
            : 0.0;
        $holdUntil = \is_numeric($claim['hold_until'] ?? null)
            ? (float)$claim['hold_until']
            : 0.0;
        $holdDuration = $holdUntil - $claimedAt;

        return \is_finite($claimedAt)
            && \is_finite($holdUntil)
            && $claimedAt > 0.0
            && $holdDuration >= self::MIN_HOLD_SECONDS
            && $holdDuration <= self::MAX_HOLD_SECONDS
            && \preg_match('/^[a-f0-9]{64}$/D', (string)($claim['owner'] ?? '')) === 1
            && \in_array(
                (string)($claim['action'] ?? ''),
                ['scale_down', 'scale_up'],
                true,
            )
            && \preg_match('/^[a-f0-9]{32}$/D', (string)($claim['token'] ?? '')) === 1
            && \preg_match('/^[a-f0-9]{64}$/D', (string)($claim['boot_id'] ?? '')) === 1;
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withLock(callable $callback): mixed
    {
        $this->ensureStateDirectory();
        $this->assertSafeFileTarget($this->lockFile);
        $lock = @\fopen($this->lockFile, 'c+b');
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Unable to open the host memory-pressure coordination lock.'
            );
        }
        try {
            $this->acquireExclusiveLock($lock);
        } catch (\Throwable $throwable) {
            @\fclose($lock);
            throw $throwable;
        }
        @\chmod($this->lockFile, 0600);
        try {
            return $callback();
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @param resource $stream
     */
    private function acquireExclusiveLock(mixed $stream): void
    {
        $deadline = \hrtime(true)
            + (int)(self::LOCK_ACQUIRE_TIMEOUT_SECONDS * 1_000_000_000);
        do {
            if (@\flock($stream, LOCK_EX | LOCK_NB)) {
                return;
            }
            \usleep(self::LOCK_RETRY_MICROSECONDS);
        } while (\hrtime(true) < $deadline);

        throw new \RuntimeException(
            'Timed out acquiring a host memory-pressure coordination file lock.'
        );
    }

    private function ensureStateDirectory(): void
    {
        if (\is_link($this->stateDirectory)
            || (!\is_dir($this->stateDirectory)
                && !@\mkdir($this->stateDirectory, 0700, true)
                && !\is_dir($this->stateDirectory))
        ) {
            throw new \RuntimeException(
                'Unable to create the host memory-pressure coordination directory.'
            );
        }
        @\chmod($this->stateDirectory, 0700);
    }

    /**
     * @return array<string,mixed>
     */
    private function readState(): array
    {
        $this->assertSafeFileTarget($this->stateFile);
        if (!\is_file($this->stateFile)) {
            return [];
        }
        $size = @\filesize($this->stateFile);
        if (!\is_int($size) || $size < 1 || $size > self::MAX_STATE_BYTES) {
            $this->quarantineInvalidState();
            return [];
        }
        $encoded = @\file_get_contents($this->stateFile);
        $state = \is_string($encoded) ? \json_decode($encoded, true) : null;
        if (!\is_array($state)
            || (int)($state['schema_version'] ?? 0) !== self::SCHEMA_VERSION
        ) {
            $this->quarantineInvalidState();
            return [];
        }

        return $state;
    }

    private function quarantineInvalidState(): void
    {
        $this->assertSafeFileTarget($this->stateFile);
        if (!\is_file($this->stateFile)) {
            return;
        }
        $quarantine = $this->stateFile . '.corrupt-'
            . \gmdate('YmdHis') . '-' . \bin2hex(\random_bytes(4));
        if (!@\rename($this->stateFile, $quarantine)) {
            throw new \RuntimeException(
                'Unable to isolate invalid host memory-pressure coordination state.'
            );
        }
        @\chmod($quarantine, 0600);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function publishState(array $state): void
    {
        $this->assertSafeFileTarget($this->stateFile);
        $encoded = \json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException(
                'Unable to encode host memory-pressure coordination state.'
            );
        }
        $payload = $encoded . PHP_EOL;
        $temporary = $this->stateFile . '.tmp-' . \bin2hex(\random_bytes(8));
        $stream = @\fopen($temporary, 'x+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException(
                'Unable to stage host memory-pressure coordination state.'
            );
        }
        try {
            @\chmod($temporary, 0600);
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($stream, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException(
                        'Unable to persist host memory-pressure coordination state.'
                    );
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($stream)
                || (\function_exists('fsync') && !@\fsync($stream))
            ) {
                throw new \RuntimeException(
                    'Unable to synchronize host memory-pressure coordination state.'
                );
            }
        } catch (\Throwable $throwable) {
            @\fclose($stream);
            @\unlink($temporary);
            throw $throwable;
        }
        @\fclose($stream);
        if (@\rename($temporary, $this->stateFile)) {
            @\chmod($this->stateFile, 0600);
            return;
        }

        // Windows may reject replacing an existing target with rename().
        // The coordination lock is still held, so a complete locked overwrite
        // keeps readers from observing a partial JSON document.
        $target = @\fopen($this->stateFile, 'c+b');
        if (!\is_resource($target)) {
            @\unlink($temporary);
            throw new \RuntimeException(
                'Unable to publish host memory-pressure coordination state.'
            );
        }
        try {
            $this->acquireExclusiveLock($target);
        } catch (\Throwable $throwable) {
            @\fclose($target);
            @\unlink($temporary);
            throw $throwable;
        }
        try {
            if (!@\ftruncate($target, 0) || !@\rewind($target)) {
                throw new \RuntimeException(
                    'Unable to replace host memory-pressure coordination state.'
                );
            }
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($target, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException(
                        'Unable to write host memory-pressure coordination state.'
                    );
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($target)) {
                throw new \RuntimeException(
                    'Unable to flush host memory-pressure coordination state.'
                );
            }
            @\chmod($this->stateFile, 0600);
        } finally {
            @\flock($target, LOCK_UN);
            @\fclose($target);
            @\unlink($temporary);
        }
    }

    private function assertSafeFileTarget(string $file): void
    {
        if (\is_link($file) || (\file_exists($file) && !\is_file($file))) {
            throw new \RuntimeException(
                'Host memory-pressure state target must be a regular non-symlink file.'
            );
        }
    }

    private function normaliseOwner(string $owner): string
    {
        $owner = \strtolower(\trim($owner));
        if (\preg_match('/^[a-f0-9]{64}$/D', $owner) !== 1) {
            throw new \InvalidArgumentException(
                'Host memory-pressure owner must be a SHA-256 identity.'
            );
        }

        return $owner;
    }

    private function normaliseAction(string $action): string
    {
        $action = \strtolower(\trim($action));
        if (!\in_array($action, ['scale_down', 'scale_up'], true)) {
            throw new \InvalidArgumentException(
                'Unsupported host memory-pressure capacity mutation.'
            );
        }

        return $action;
    }

    private function normaliseBootIdentity(string $bootIdentity): string
    {
        $bootIdentity = \strtolower(\trim($bootIdentity));
        if (\preg_match('/^[a-f0-9]{64}$/D', $bootIdentity) !== 1) {
            throw new \InvalidArgumentException(
                'Host memory-pressure boot identity must be a SHA-256 identity.'
            );
        }

        return $bootIdentity;
    }

    private function detectHostBootId(): string
    {
        if (\PHP_OS_FAMILY === 'Linux') {
            $bootId = \strtolower(\trim((string)@\file_get_contents(
                '/proc/sys/kernel/random/boot_id',
            )));
            if (\preg_match('/\A[a-f0-9-]{36}\z/D', $bootId) === 1) {
                return \hash('sha256', 'linux:' . $bootId);
            }
            throw new \RuntimeException('Linux boot identity is unavailable.');
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            $bootTime = $this->boundedCommandOutput([
                '/usr/sbin/sysctl',
                '-n',
                'kern.boottime',
            ]);
            if (\preg_match(
                '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}/',
                $bootTime,
                $matches,
            ) === 1) {
                return \hash(
                    'sha256',
                    'darwin:' . $matches[1] . ':' . $matches[2],
                );
            }
            throw new \RuntimeException('macOS boot identity is unavailable.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $systemRoot = \rtrim((string)\getenv('SystemRoot'), '\\/');
            $powershell = $systemRoot !== ''
                ? $systemRoot
                    . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe'
                : 'powershell.exe';
            $bootTime = $this->boundedCommandOutput([
                $powershell,
                '-NoLogo',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                'Get-CimInstance -ClassName Win32_OperatingSystem '
                    . '| Select-Object -ExpandProperty LastBootUpTime '
                    . '| Get-Date -UFormat %s',
            ]);
            if (\preg_match('/\A\d{9,12}(?:\.\d{1,9})?\z/D', $bootTime) === 1) {
                return \hash('sha256', 'windows:' . $bootTime);
            }
            throw new \RuntimeException('Windows boot identity is unavailable.');
        }
        throw new \RuntimeException(
            'Unsupported platform for WLS memory-pressure boot identity: '
            . \PHP_OS_FAMILY,
        );
    }

    /**
     * @param list<string> $command
     */
    private function boundedCommandOutput(
        array $command,
        float $timeoutSeconds = 3.0,
    ): string {
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @\proc_open($command, [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException(
                'Unable to start the host memory-pressure boot identity probe.'
            );
        }
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = (\hrtime(true) / 1_000_000_000)
            + \max(0.1, $timeoutSeconds);
        $status = \proc_get_status($process);
        while (($status['running'] ?? false)
            && (\hrtime(true) / 1_000_000_000) < $deadline
        ) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @\stream_select($read, $write, $except, 0, 100_000);
            foreach ($read as $stream) {
                $chunk = (string)@\stream_get_contents($stream);
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            $status = \proc_get_status($process);
        }
        $timedOut = (bool)($status['running'] ?? false);
        if ($timedOut) {
            @\proc_terminate($process);
            $terminationDeadline = (\hrtime(true) / 1_000_000_000)
                + self::PROCESS_TERMINATION_GRACE_SECONDS;
            do {
                \usleep(self::PROCESS_TERMINATION_POLL_MICROSECONDS);
                $status = \proc_get_status($process);
            } while (($status['running'] ?? false)
                && (\hrtime(true) / 1_000_000_000) < $terminationDeadline
            );
        }
        if ($status['running'] ?? false) {
            @\proc_terminate($process, 9);
            $killDeadline = (\hrtime(true) / 1_000_000_000)
                + self::PROCESS_TERMINATION_GRACE_SECONDS;
            do {
                \usleep(self::PROCESS_TERMINATION_POLL_MICROSECONDS);
                $status = \proc_get_status($process);
            } while (($status['running'] ?? false)
                && (\hrtime(true) / 1_000_000_000) < $killDeadline
            );
        }
        $stdout .= (string)@\stream_get_contents($pipes[1]);
        $stderr .= (string)@\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        $exitCode = @\proc_close($process);
        if ($timedOut) {
            throw new \RuntimeException(
                'Host memory-pressure boot identity probe timed out.'
            );
        }
        if (($status['running'] ?? false)
            || ($exitCode !== 0 && (int)($status['exitcode'] ?? -1) !== 0)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure boot identity probe failed: '
                . \trim($stderr),
            );
        }

        return \trim($stdout);
    }

    private function normaliseAbsolutePath(string $path): string
    {
        $path = \trim(\str_replace("\0", '', $path));
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if (!$absolute
            || \in_array('..', \preg_split('#[\\\\/]+#', $path) ?: [], true)
        ) {
            throw new \InvalidArgumentException(
                'Host memory-pressure state directory must be absolute without traversal.'
            );
        }

        return \rtrim($path, '/\\');
    }
}
