<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Small no-follow-style file primitive for project-owned WLS 2.0 state.
 *
 * PHP does not expose openat(2) or the Windows relative-handle APIs. This
 * helper therefore fences every operation with lstat/fstat identity checks,
 * rejects links and hard links, publishes through a same-directory candidate,
 * and fsyncs both the file and its parent on POSIX. Callers still own directory
 * containment and project-owner policy.
 */
final class GatewayProjectStateFilesystem
{
    /** @var array{ready:bool,mode:string,reason:string}|null */
    private static ?array $atomicWriteRuntimeCapability = null;
    private const MAX_WINDOWS_RECOVERY_BACKUPS_PER_TARGET = 8;
    private const MAX_ATOMIC_STAGING_FILES_PER_TARGET = 8;
    private const MAX_WINDOWS_RECOVERY_BACKUPS_PER_DIRECTORY = 256;
    private const MAX_WINDOWS_ATOMIC_DIRECTORY_ENTRIES = 16384;
    private const DEFAULT_LOCK_WAIT_SECONDS = 300.0;
    private const MAX_LOCK_WAIT_SECONDS = 300.0;
    private const LOCK_RETRY_USEC = 10_000;

    /**
     * Windows deliberately has no PHP rename fallback: every project-owned
     * WLS state mutation needs a callable kernel32 FFI path. Expose that hard
     * runtime contract so install/start/doctor can reject an unsupported PHP
     * binary before the first identity or lease write.
     *
     * @return array{ready:bool,mode:string,reason:string}
     */
    public static function atomicWriteRuntimeCapability(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return [
                'ready' => true,
                'mode' => 'posix_same_directory_rename',
                'reason' => '',
            ];
        }
        if (self::$atomicWriteRuntimeCapability !== null) {
            return self::$atomicWriteRuntimeCapability;
        }
        if (!\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || !\function_exists('iconv')
        ) {
            return self::$atomicWriteRuntimeCapability = [
                'ready' => false,
                'mode' => 'windows_kernel32_ffi',
                'reason' => 'The locked WLS Windows PHP runtime must provide FFI and iconv.',
            ];
        }
        $ffiEnable = \strtolower(\trim((string)\ini_get('ffi.enable')));
        if (\in_array($ffiEnable, ['', '0', 'off', 'false', 'no'], true)) {
            return self::$atomicWriteRuntimeCapability = [
                'ready' => false,
                'mode' => 'windows_kernel32_ffi',
                'reason' => 'The locked WLS Windows PHP runtime has ffi.enable disabled.',
            ];
        }
        try {
            $ffi = \FFI::cdef(
                'typedef unsigned long DWORD; typedef unsigned short WCHAR; DWORD GetLastError(void);',
                'kernel32.dll',
            );
            $ffi->GetLastError();
            self::windowsWidePath($ffi, 'C:\\wls-atomic-capability-probe');
        } catch (\Throwable $throwable) {
            return self::$atomicWriteRuntimeCapability = [
                'ready' => false,
                'mode' => 'windows_kernel32_ffi',
                'reason' => 'The locked WLS Windows PHP runtime cannot call kernel32 through FFI: '
                    . \substr($throwable->getMessage(), 0, 512),
            ];
        }
        return self::$atomicWriteRuntimeCapability = [
            'ready' => true,
            'mode' => 'windows_kernel32_ffi',
            'reason' => '',
        ];
    }

    public static function assertAtomicWriteRuntimeCapability(): void
    {
        $capability = self::atomicWriteRuntimeCapability();
        if (($capability['ready'] ?? false) !== true) {
            throw new \RuntimeException((string)(
                $capability['reason']
                    ?? 'The WLS project-state atomic write runtime is unavailable.'
            ));
        }
    }

    /**
     * @template TResult
     * @param \Closure():TResult $callback
     * @param (\Closure(resource,string):void)|null $seal
     * @param float $waitTimeoutSeconds Maximum monotonic time spent waiting
     *        for another process to release this lock. The callback duration
     *        is deliberately not included.
     * @param float|null $deadlineMonotonic Optional caller-owned absolute
     *        monotonic deadline for the complete safe-open and acquisition
     *        path. The callback remains responsible for checking the same
     *        deadline before each blocking operation or mutation.
     * @return TResult
     */
    public static function withExclusiveLock(
        string $path,
        \Closure $callback,
        ?\Closure $seal = null,
        float $waitTimeoutSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
        ?float $deadlineMonotonic = null,
    ): mixed {
        if (!\is_finite($waitTimeoutSeconds)
            || $waitTimeoutSeconds <= 0.0
            || $waitTimeoutSeconds > self::MAX_LOCK_WAIT_SECONDS
        ) {
            throw new \InvalidArgumentException(
                'WLS state lock wait timeout must be within (0, 300] seconds.'
            );
        }
        if ($deadlineMonotonic !== null
            && (!\is_finite($deadlineMonotonic)
                || $deadlineMonotonic <= 0.0)
        ) {
            throw new \InvalidArgumentException(
                'WLS state lock absolute deadline is invalid.'
            );
        }
        self::assertLockDeadlineAvailable($deadlineMonotonic);
        self::assertParentDirectory($path);
        $before = false;
        $created = false;
        $handle = false;
        $openingChanged = false;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            self::assertLockDeadlineAvailable($deadlineMonotonic);
            \clearstatcache(true, $path);
            $before = @\lstat($path);
            $created = false;
            if (\is_array($before)) {
                self::assertRegularStatus($path, $before, 'WLS state lock');
                $handle = @\fopen($path, 'r+b');
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException(
                        'WLS state lock path is indeterminate or unsafe.'
                    );
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }
            if (\is_resource($handle)) {
                $opened = @\fstat($handle);
                \clearstatcache(true, $path);
                $pathStatus = @\lstat($path);
                if (!\is_array($opened) || !\is_array($pathStatus)) {
                    @\fclose($handle);
                    $handle = false;
                    throw new \RuntimeException('Unable to verify the opened WLS state lock.');
                }
                try {
                    self::assertRegularStatus($path, $opened, 'WLS state lock');
                    self::assertRegularStatus($path, $pathStatus, 'WLS state lock');
                } catch (\Throwable $exception) {
                    @\fclose($handle);
                    $handle = false;
                    throw $exception;
                }
                $stable = ($created
                        || (\is_array($before) && self::sameState($before, $opened)))
                    && self::sameState($opened, $pathStatus);
                if ($stable) {
                    break;
                }
                $sameOpenedObject = ($created
                        || (\is_array($before)
                            && self::sameOpenFileIdentity($before, $opened)))
                    && self::sameOpenFileIdentity($opened, $pathStatus);
                @\fclose($handle);
                $handle = false;
                if (!$sameOpenedObject) {
                    throw new \RuntimeException('WLS state lock changed while being opened.');
                }
                // A trusted holder may have tightened mode or ownership on
                // this exact inode between lstat and fstat. Retry until one
                // complete snapshot is stable; never retry a path/inode swap.
                $openingChanged = true;
            }
            // Another local process may have won the first-create race after
            // lstat but before fopen(x). Re-resolve and open that exact inode;
            // never downgrade to fopen(c), which follows links and truncates
            // the distinction between creation and an existing lock.
            if ($attempt < 7) {
                \usleep(self::lockRetryMicroseconds(
                    2_000,
                    $deadlineMonotonic,
                ));
            }
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException($openingChanged
                ? 'WLS state lock did not stabilize while being opened.'
                : 'Unable to open the WLS state lock safely.');
        }
        $locked = false;
        try {
            $startedAt = self::monotonicSeconds();
            $deadline = $startedAt + $waitTimeoutSeconds;
            if ($deadlineMonotonic !== null) {
                $deadline = \min($deadline, $deadlineMonotonic);
            }
            if ($startedAt >= $deadline) {
                throw new \RuntimeException(
                    'Timed out acquiring the WLS state lock.'
                );
            }
            do {
                if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                $now = self::monotonicSeconds();
                if ($now >= $deadline) {
                    break;
                }
                $remainingUsec = self::lockRetryMicroseconds(
                    self::LOCK_RETRY_USEC,
                    $deadline,
                );
                \usleep($remainingUsec);
            } while (true);
            if (!$locked) {
                throw new \RuntimeException('Timed out acquiring the WLS state lock.');
            }
            self::assertLockDeadlineAvailable($deadlineMonotonic);
            $afterLock = @\fstat($handle);
            \clearstatcache(true, $path);
            $pathAfterLock = @\lstat($path);
            if (!\is_array($afterLock)
                || !\is_array($pathAfterLock)
                || !self::sameState($afterLock, $pathAfterLock)
            ) {
                throw new \RuntimeException('WLS state lock changed while being locked.');
            }
            self::assertRegularStatus($path, $afterLock, 'WLS state lock');
            self::assertRegularStatus($path, $pathAfterLock, 'WLS state lock');
            self::sealHandle($handle, $path, 0600, $seal);
            $afterSeal = @\fstat($handle);
            \clearstatcache(true, $path);
            $pathAfterSeal = @\lstat($path);
            if (!\is_array($afterSeal)
                || !\is_array($pathAfterSeal)
                || !self::sameState($afterSeal, $pathAfterSeal)
            ) {
                throw new \RuntimeException('WLS state lock changed while being sealed.');
            }
            self::assertRegularStatus($path, $afterSeal, 'WLS state lock');
            self::assertRegularStatus($path, $pathAfterSeal, 'WLS state lock');
            if ($created) {
                if (!@\fflush($handle)
                    || (\function_exists('fsync') && !@\fsync($handle))
                ) {
                    throw new \RuntimeException('Unable to persist the WLS state lock.');
                }
                self::syncDirectory(\dirname($path));
            }
            self::assertLockDeadlineAvailable($deadlineMonotonic);
            return $callback();
        } finally {
            if ($locked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    private static function monotonicSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS state lock monotonic clock is invalid.');
        }

        return $now;
    }

    private static function assertLockDeadlineAvailable(
        ?float $deadlineMonotonic,
    ): void {
        if ($deadlineMonotonic !== null
            && self::monotonicSeconds() >= $deadlineMonotonic
        ) {
            throw new \RuntimeException(
                'Timed out acquiring the WLS state lock.'
            );
        }
    }

    private static function lockRetryMicroseconds(
        int $maximumMicroseconds,
        ?float $deadlineMonotonic,
    ): int {
        if ($maximumMicroseconds < 1) {
            throw new \InvalidArgumentException(
                'WLS state lock retry interval is invalid.'
            );
        }
        if ($deadlineMonotonic === null) {
            return $maximumMicroseconds;
        }
        $remaining = $deadlineMonotonic - self::monotonicSeconds();
        $remainingMicroseconds = (int)\floor($remaining * 1_000_000);
        if ($remainingMicroseconds < 1) {
            throw new \RuntimeException(
                'Timed out acquiring the WLS state lock.'
            );
        }
        return \min($maximumMicroseconds, $remainingMicroseconds);
    }

    public static function read(
        string $path,
        int $maximumBytes,
        string $label,
        bool $allowEmpty = false,
    ): string {
        $before = @\lstat($path);
        if ($maximumBytes < 1 || !\is_array($before)) {
            throw new \RuntimeException($label . ' is missing or unsafe.');
        }
        self::assertRegularStatus($path, $before, $label);
        $size = (int)($before['size'] ?? -1);
        if ($size < ($allowEmpty ? 0 : 1) || $size > $maximumBytes) {
            throw new \RuntimeException($label . ' has an invalid size.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !self::sameState($before, $opened)) {
                throw new \RuntimeException($label . ' changed before reading.');
            }
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_string($contents)
                || \strlen($contents) > $maximumBytes
                || (!$allowEmpty && $contents === '')
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !self::sameState($opened, $after)
                || !self::sameState($after, $pathAfter)
                || (int)($after['size'] ?? -1) !== \strlen($contents)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $contents;
        } finally {
            @\fclose($handle);
        }
    }

    public static function readOptional(
        string $path,
        int $maximumBytes,
        string $label,
        bool $allowEmpty = false,
    ): ?string {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException($label . ' path is indeterminate or unsafe.');
            }
            return null;
        }
        return self::read($path, $maximumBytes, $label, $allowEmpty);
    }

    public static function size(string $path, int $maximumBytes, string $label): ?int
    {
        $before = @\lstat($path);
        if (!\is_array($before)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException($label . ' path is indeterminate or unsafe.');
            }
            return null;
        }
        self::assertRegularStatus($path, $before, $label);
        $size = (int)($before['size'] ?? -1);
        if ($maximumBytes < 0 || $size < 0 || $size > $maximumBytes) {
            throw new \RuntimeException($label . ' exceeds its fixed size limit.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to inspect ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($pathAfter)
                || !self::sameState($before, $opened)
                || !self::sameState($opened, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being inspected.');
            }
            return $size;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * Report whether an exact atomic-write target has retained recovery
     * evidence. Besides Windows ReplaceFileW backups this includes an exact
     * same-target staging leaf left by a hard crash before rename. This method
     * is read-only; callers that observe evidence must acquire the target
     * namespace lock and use cleanupAtomicWriteRecoveryBackups().
     */
    public static function hasAtomicWriteRecoveryBackups(
        string $target,
        int $maximumBytes,
        string $label,
    ): bool {
        return self::atomicWriteRecoveryArtifacts($target, $maximumBytes, $label) !== [];
    }

    /**
     * Collect retained ReplaceFileW backups and interrupted staging leaves for
     * one exact target.
     *
     * The caller must hold the namespace lock used by every writer of $target.
     * A backup is the previous committed generation and a staging leaf may be
     * the only after-image of a failed first publication. Both are removed only
     * after the caller-provided validator accepts the current paired target and
     * that target retains one stable identity through the whole collection.
     * Missing or invalid targets, case aliases, malformed reserved leaves,
     * special files and quota violations fail before any evidence is deleted.
     *
     * @param \Closure(string):void $validateTarget
     */
    public static function cleanupAtomicWriteRecoveryBackups(
        string $target,
        int $maximumBytes,
        string $label,
        \Closure $validateTarget,
        bool $allowEmpty = false,
    ): void {
        $artifacts = self::atomicWriteRecoveryArtifacts(
            $target,
            $maximumBytes,
            $label,
        );
        if ($artifacts === []) {
            return;
        }

        $targetBefore = @\lstat($target);
        if (!\is_array($targetBefore)) {
            throw new \RuntimeException(
                $label . ' recovery artifact paired target is missing or unsafe.'
            );
        }
        $contents = self::read(
            $target,
            $maximumBytes,
            $label . ' recovery artifact paired target',
            $allowEmpty,
        );
        $validateTarget($contents);
        $targetAfterValidation = @\lstat($target);
        if (!\is_array($targetAfterValidation)
            || !self::sameState($targetBefore, $targetAfterValidation)
        ) {
            throw new \RuntimeException(
                $label . ' recovery artifact paired target changed during validation.'
            );
        }

        // Repeat the complete namespace preflight before the first unlink.
        // A late case alias, special file, quota violation, added artifact, or
        // identity replacement therefore preserves the whole recovery set.
        $rechecked = self::atomicWriteRecoveryArtifacts(
            $target,
            $maximumBytes,
            $label,
        );
        if (\array_keys($artifacts) !== \array_keys($rechecked)) {
            throw new \RuntimeException(
                $label . ' atomic recovery artifact set changed before cleanup.'
            );
        }
        foreach ($artifacts as $path => $artifact) {
            $current = $rechecked[$path] ?? null;
            if (!\is_array($current)
                || !self::sameState($artifact['identity'], $current['identity'])
                || !\hash_equals($artifact['kind'], $current['kind'])
            ) {
                throw new \RuntimeException(
                    $label . ' atomic recovery artifact changed before cleanup.'
                );
            }
        }
        $targetAfterPreflight = @\lstat($target);
        if (!\is_array($targetAfterPreflight)
            || !self::sameState($targetAfterValidation, $targetAfterPreflight)
        ) {
            throw new \RuntimeException(
                $label . ' recovery artifact paired target changed before cleanup.'
            );
        }

        foreach ($artifacts as $artifact) {
            $currentTarget = @\lstat($target);
            if (!\is_array($currentTarget)
                || !self::sameState($targetAfterPreflight, $currentTarget)
            ) {
                throw new \RuntimeException(
                    $label . ' recovery artifact paired target changed during cleanup.'
                );
            }
            if (!self::removeRegular(
                $artifact['path'],
                $label . ' atomic recovery ' . $artifact['kind'],
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect ' . $label . ' atomic recovery artifact.'
                );
            }
        }
    }

    /**
     * Discard only uncommitted staging leaves from a first-publication write.
     *
     * The caller must hold the target writer lock and must independently
     * prove that publication of the missing target is the commit fence for
     * every later mutation. A retained ReplaceFileW backup is never eligible:
     * it proves that a prior target existed and requires semantic recovery.
     */
    public static function discardUnpairedFirstPublicationStaging(
        string $target,
        int $maximumBytes,
        string $label,
    ): void {
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)
            || \file_exists($target)
            || \is_link($target)
        ) {
            throw new \RuntimeException(
                $label . ' first-publication target is not absent.'
            );
        }
        $artifacts = self::atomicWriteRecoveryArtifacts(
            $target,
            $maximumBytes,
            $label,
        );
        if ($artifacts === []) {
            return;
        }
        foreach ($artifacts as $artifact) {
            if (!\hash_equals('staging file', $artifact['kind'])) {
                throw new \RuntimeException(
                    $label . ' has a retained backup and is not an uncommitted first publication.'
                );
            }
        }
        $rechecked = self::atomicWriteRecoveryArtifacts(
            $target,
            $maximumBytes,
            $label,
        );
        if (\array_keys($artifacts) !== \array_keys($rechecked)) {
            throw new \RuntimeException(
                $label . ' first-publication staging set changed before cleanup.'
            );
        }
        foreach ($artifacts as $path => $artifact) {
            $current = $rechecked[$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals('staging file', (string)$current['kind'])
                || !self::sameState(
                    $artifact['identity'],
                    $current['identity'],
                )
            ) {
                throw new \RuntimeException(
                    $label . ' first-publication staging changed before cleanup.'
                );
            }
        }
        foreach ($rechecked as $artifact) {
            if (!self::removeRegular(
                $artifact['path'],
                $label . ' uncommitted first-publication staging',
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to discard ' . $label . ' first-publication staging.'
                );
            }
        }
        self::syncDirectory(\dirname($target));
    }

    /**
     * @return array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>
     */
    private static function atomicWriteRecoveryArtifacts(
        string $target,
        int $maximumBytes,
        string $label,
    ): array {
        if ($maximumBytes < 1 || $label === '') {
            throw new \InvalidArgumentException(
                'Atomic recovery artifact inspection boundary is invalid.'
            );
        }
        self::assertParentDirectory($target);
        $directory = \dirname($target);
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        if ($targetLeaf === '' || $targetLeaf === '.' || $targetLeaf === '..') {
            throw new \RuntimeException('Atomic recovery target leaf is invalid.');
        }
        $backupPrefix = $targetLeaf . '.wls-backup-';
        $stagingPrefix = $targetLeaf . '.tmp-';
        $backupPattern = '/\A' . \preg_quote($backupPrefix, '/')
            . '[a-f0-9]{16}\z/Du';
        $stagingPattern = '/\A' . \preg_quote($stagingPrefix, '/')
            . '[a-f0-9]{24}\z/Du';
        $foldedBackupPrefix = \strtolower($backupPrefix);
        $foldedStagingPrefix = \strtolower($stagingPrefix);
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate ' . $label . ' atomic recovery directory.'
            );
        }
        $artifacts = [];
        $backups = 0;
        $staging = 0;
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::MAX_WINDOWS_ATOMIC_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory exceeds its fixed raw entry quota.'
                    );
                }
                $foldedLeaf = \strtolower($leaf);
                $isBackupNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedBackupPrefix,
                );
                $isStagingNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedStagingPrefix,
                );
                if (!$isBackupNamespace && !$isStagingNamespace) {
                    continue;
                }
                if (($isBackupNamespace && !\str_starts_with($leaf, $backupPrefix))
                    || ($isStagingNamespace && !\str_starts_with($leaf, $stagingPrefix))
                ) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory contains a non-canonical case alias.'
                    );
                }
                $kind = '';
                if ($isBackupNamespace && \preg_match($backupPattern, $leaf) === 1) {
                    $kind = 'backup';
                    ++$backups;
                } elseif ($isStagingNamespace
                    && \preg_match($stagingPattern, $leaf) === 1
                ) {
                    $kind = 'staging file';
                    ++$staging;
                } else {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory contains a malformed reserved leaf.'
                    );
                }
                if ($backups > self::MAX_WINDOWS_RECOVERY_BACKUPS_PER_TARGET
                    || $staging > self::MAX_ATOMIC_STAGING_FILES_PER_TARGET
                ) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery artifact quota is exhausted.'
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery artifact is indeterminate.'
                    );
                }
                self::size($path, $maximumBytes, $label . ' atomic recovery artifact');
                $after = @\lstat($path);
                if (!\is_array($after) || !self::sameState($before, $after)) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery artifact changed during inspection.'
                    );
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $after,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);
        return $artifacts;
    }

    /** @param (\Closure(resource,string):void)|null $seal */
    public static function atomicWrite(
        string $path,
        string $contents,
        int $mode = 0600,
        ?\Closure $seal = null,
    ): void {
        self::assertAtomicWriteRuntimeCapability();
        self::assertParentDirectory($path);
        $existing = @\lstat($path);
        if (\is_array($existing)) {
            self::assertRegularStatus($path, $existing, 'WLS state target');
        } elseif (\file_exists($path) || \is_link($path)) {
            throw new \RuntimeException('WLS state target is indeterminate or unsafe.');
        }

        // Never layer a new transaction over unresolved evidence from a hard
        // crash. Callers own the semantic validator and namespace lock needed
        // to reconcile that evidence through cleanupAtomicWriteRecoveryBackups().
        // Refusing here keeps repeated retries from exhausting the directory
        // or obscuring a first-publication after-image with newer staging.
        // Recovery artifacts may be a previous committed generation larger than
        // the incoming write; size them against max(new, current), not only
        // strlen($contents).
        $artifactCeiling = \max(
            1,
            \strlen($contents),
            \is_array($existing) ? (int)($existing['size'] ?? 0) : 0,
        );
        if (self::atomicWriteRecoveryArtifacts(
            $path,
            $artifactCeiling,
            'WLS state target',
        ) !== []) {
            throw new \RuntimeException(
                'WLS state target has unresolved atomic recovery artifacts.'
            );
        }

        $temporary = $path . '.tmp-' . \bin2hex(\random_bytes(12));
        $handle = @\fopen($temporary, 'x+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create the WLS state staging file.');
        }
        $failure = null;
        $stagedStatus = null;
        $temporaryIdentity = null;
        try {
            $createdStatus = @\fstat($handle);
            $createdPathStatus = @\lstat($temporary);
            if (!\is_array($createdStatus)
                || !\is_array($createdPathStatus)
                || !self::sameState($createdStatus, $createdPathStatus)
            ) {
                throw new \RuntimeException(
                    'WLS state staging identity changed immediately after creation.'
                );
            }
            self::assertRegularStatus(
                $temporary,
                $createdStatus,
                'WLS state staging file',
            );
            $temporaryIdentity = $createdStatus;
            self::sealHandle($handle, $temporary, $mode, $seal);
            self::writeAll($handle, $contents, 'WLS state staging file');
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException('Unable to synchronize the WLS state staging file.');
            }
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($temporary);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !self::sameState($opened, $pathStatus)
                || (int)($opened['size'] ?? -1) !== \strlen($contents)
            ) {
                throw new \RuntimeException('WLS state staging identity changed before publication.');
            }
            $stagedStatus = $opened;
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($handle);
        }
        if ($failure instanceof \Throwable) {
            if (\is_array($temporaryIdentity)) {
                self::removeRegular(
                    $temporary,
                    'failed WLS state staging file',
                    $temporaryIdentity,
                );
            }
            throw $failure;
        }

        $publishedByRename = false;
        $nativeReceipt = null;
        // Windows rename semantics vary by PHP/runtime and do not return a
        // native file-identity receipt. Always use the verified kernel path on
        // Windows so an apparently successful rename cannot bypass the
        // reparse, hard-link, digest and target-generation fences below.
        $attempts = \PHP_OS_FAMILY === 'Windows' ? 0 : 1;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            if (@\rename($temporary, $path)) {
                $publishedByRename = true;
                break;
            }
        }
        if (!$publishedByRename && \PHP_OS_FAMILY === 'Windows') {
            try {
                $nativeReceipt = self::windowsNativeAtomicReplace(
                    $temporary,
                    $path,
                    \is_array($stagedStatus) ? $stagedStatus : [],
                    \is_array($existing) ? $existing : null,
                    \hash('sha256', $contents),
                    \strlen($contents),
                );
            } catch (\Throwable $throwable) {
                // The staging leaf is never the committed generation. It is
                // safe to collect after the native operation has either
                // failed before publication or completed its own rollback.
                self::removeRegular(
                    $temporary,
                    'failed Windows WLS state staging file',
                    \is_array($stagedStatus) ? $stagedStatus : [],
                );
                throw $throwable;
            }
        }
        if (!$publishedByRename && !\is_array($nativeReceipt)) {
            // Keep the previous committed file intact on every platform.
            // In-place truncate/write is not an atomic replacement on Windows
            // and can turn a transient sharing violation into state loss.
            self::removeRegular(
                $temporary,
                'unpublished WLS state staging file',
                \is_array($stagedStatus) ? $stagedStatus : [],
            );
            throw new \RuntimeException('Unable to atomically publish the WLS state file.');
        }

        $published = @\lstat($path);
        if (!\is_array($published)
            || (int)($published['size'] ?? -1) !== \strlen($contents)
            || (($publishedByRename || \is_array($nativeReceipt))
                && (!\is_array($stagedStatus)
                    || !self::sameIdentity($stagedStatus, $published)))
        ) {
            throw new \RuntimeException('Published WLS state file has an invalid identity or size.');
        }
        if (\is_array($nativeReceipt)
            && (!\hash_equals(
                'wls-windows-atomic-replace/1',
                (string)($nativeReceipt['schema'] ?? ''),
            )
                || !\hash_equals(
                    self::stableIdentityDigest($stagedStatus ?? []),
                    (string)($nativeReceipt['source_identity'] ?? ''),
                )
                || !\hash_equals(
                    self::stableIdentityDigest($published),
                    (string)($nativeReceipt['target_identity'] ?? ''),
                )
                || !\hash_equals(
                    \hash('sha256', $contents),
                    (string)($nativeReceipt['sha256'] ?? ''),
                )
                || \preg_match(
                    '/\A[a-f0-9]{24}\z/D',
                    (string)($nativeReceipt['native_source_identity'] ?? ''),
                ) !== 1
                || !\hash_equals(
                    (string)($nativeReceipt['native_source_identity'] ?? ''),
                    (string)($nativeReceipt['native_target_identity'] ?? ''),
                )
                || !\in_array(
                    (string)($nativeReceipt['backup_cleanup'] ?? ''),
                    ['not_applicable', 'deleted', 'retained'],
                    true,
                )
                || (int)($nativeReceipt['size'] ?? -1) !== \strlen($contents))
        ) {
            throw new \RuntimeException(
                'Windows native atomic publication returned an invalid identity receipt.'
            );
        }
        self::assertRegularStatus($path, $published, 'Published WLS state file');
        if (\PHP_OS_FAMILY !== 'Windows'
            && ((((int)($published['mode'] ?? 0)) & 0777) !== $mode)
        ) {
            throw new \RuntimeException('Published WLS state file mode is unsafe.');
        }
        if (self::injectedPostRenameDirectorySyncFailure($path)) {
            throw new \RuntimeException(
                'Unable to synchronize the WLS state parent directory after publication.'
            );
        }
        self::syncDirectory(\dirname($path));
    }

    /**
     * Restore one exact, semantically pre-validated ReplaceFileW backup as the
     * committed target without introducing a delete-then-write crash window.
     *
     * The caller must hold the target namespace lock and must bind both path
     * identities, contents and size before calling. A safe-but-semantically
     * damaged target may be replaced only while it still has the exact
     * identity selected by the caller. Links, hard links, cross-directory
     * moves and ambiguous backup leaves are always rejected.
     *
     * @param array<string|int,mixed> $expectedBackupIdentity
     * @param array<string|int,mixed>|null $expectedTargetIdentity
     */
    public static function restoreVerifiedAtomicBackup(
        string $backup,
        string $target,
        array $expectedBackupIdentity,
        ?array $expectedTargetIdentity,
        string $expectedDigest,
        int $expectedSize,
        int $mode = 0600,
    ): void {
        self::assertAtomicWriteRuntimeCapability();
        self::assertParentDirectory($backup);
        self::assertParentDirectory($target);
        if ($expectedBackupIdentity === []
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
            || $expectedSize < 1
            || $mode < 0
            || $mode > 0777
        ) {
            throw new \InvalidArgumentException(
                'WLS atomic backup restoration contract is invalid.'
            );
        }
        $backupDirectory = @\realpath(\dirname($backup));
        $targetDirectory = @\realpath(\dirname($target));
        if (!\is_string($backupDirectory)
            || !\is_string($targetDirectory)
            || !\hash_equals(
                \PHP_OS_FAMILY === 'Windows'
                    ? self::normalizeWindowsPathForComparison($targetDirectory)
                    : $targetDirectory,
                \PHP_OS_FAMILY === 'Windows'
                    ? self::normalizeWindowsPathForComparison($backupDirectory)
                    : $backupDirectory,
            )
        ) {
            throw new \RuntimeException(
                'WLS atomic backup and target must share one canonical directory.'
            );
        }
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        $backupLeaf = \basename(\str_replace('\\', '/', $backup));
        if ($targetLeaf === ''
            || \preg_match(
                '/\A' . \preg_quote($targetLeaf, '/')
                    . '\.wls-backup-[a-f0-9]{16}\z/Du',
                $backupLeaf,
            ) !== 1
        ) {
            throw new \RuntimeException(
                'WLS atomic backup leaf is not bound to the exact target.'
            );
        }

        $backupBefore = @\lstat($backup);
        if (!\is_array($backupBefore)
            || !self::sameState($expectedBackupIdentity, $backupBefore)
        ) {
            throw new \RuntimeException(
                'WLS atomic backup changed before restoration.'
            );
        }
        self::assertRegularStatus($backup, $backupBefore, 'WLS atomic recovery backup');
        if ((int)($backupBefore['size'] ?? -1) !== $expectedSize
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($backupBefore['mode'] ?? 0)) & 0777) !== $mode))
        ) {
            throw new \RuntimeException(
                'WLS atomic recovery backup shape is invalid.'
            );
        }
        $backupDigest = @\hash_file('sha256', $backup);
        $backupAfterDigest = @\lstat($backup);
        if (!\is_string($backupDigest)
            || !\hash_equals($expectedDigest, $backupDigest)
            || !\is_array($backupAfterDigest)
            || !self::sameState($backupBefore, $backupAfterDigest)
        ) {
            throw new \RuntimeException(
                'WLS atomic recovery backup contents or identity changed.'
            );
        }

        $targetBefore = @\lstat($target);
        if ($expectedTargetIdentity === null) {
            if (\is_array($targetBefore)
                || \file_exists($target)
                || \is_link($target)
            ) {
                throw new \RuntimeException(
                    'WLS atomic recovery target appeared before restoration.'
                );
            }
        } elseif (!\is_array($targetBefore)
            || !self::sameState($expectedTargetIdentity, $targetBefore)
        ) {
            throw new \RuntimeException(
                'WLS atomic recovery target changed before restoration.'
            );
        } else {
            self::assertRegularStatus($target, $targetBefore, 'WLS damaged atomic target');
        }

        // Repeat both exact identity checks immediately before the one atomic
        // name operation. No evidence is deleted before this point.
        $backupAtCommit = @\lstat($backup);
        $targetAtCommit = @\lstat($target);
        if (!\is_array($backupAtCommit)
            || !self::sameState($backupAfterDigest, $backupAtCommit)
            || ($expectedTargetIdentity === null
                ? (\is_array($targetAtCommit)
                    || \file_exists($target)
                    || \is_link($target))
                : (!\is_array($targetAtCommit)
                    || !self::sameState($targetBefore, $targetAtCommit)))
        ) {
            throw new \RuntimeException(
                'WLS atomic recovery namespace changed before commit.'
            );
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            self::windowsRestoreVerifiedAtomicBackup(
                $backup,
                $target,
                $backupAtCommit,
                \is_array($targetAtCommit) ? $targetAtCommit : null,
                $expectedDigest,
                $expectedSize,
            );
        } elseif (!@\rename($backup, $target)) {
            throw new \RuntimeException(
                'Unable to atomically restore the WLS recovery backup.'
            );
        }

        \clearstatcache(true, $backup);
        \clearstatcache(true, $target);
        $published = @\lstat($target);
        $publishedDigest = @\hash_file('sha256', $target);
        if (\file_exists($backup)
            || \is_link($backup)
            || !\is_array($published)
            || !self::sameIdentity($backupAtCommit, $published)
            || !\is_string($publishedDigest)
            || !\hash_equals($expectedDigest, $publishedDigest)
            || (int)($published['size'] ?? -1) !== $expectedSize
        ) {
            throw new \RuntimeException(
                'Restored WLS atomic target failed its identity receipt.'
            );
        }
        self::assertRegularStatus($target, $published, 'Restored WLS atomic target');
        if (\PHP_OS_FAMILY !== 'Windows'
            && ((((int)($published['mode'] ?? 0)) & 0777) !== $mode)
        ) {
            throw new \RuntimeException('Restored WLS atomic target mode is unsafe.');
        }
        self::syncDirectory($targetDirectory);
    }

    /**
     * @param array<string|int,mixed> $backupStatus
     * @param array<string|int,mixed>|null $targetStatus
     */
    private static function windowsRestoreVerifiedAtomicBackup(
        string $backup,
        string $target,
        array $backupStatus,
        ?array $targetStatus,
        string $expectedDigest,
        int $expectedSize,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows'
            || !\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || !\function_exists('iconv')
        ) {
            throw new \RuntimeException(
                'Windows native WLS backup restoration is unavailable.'
            );
        }
        try {
            $ffi = \FFI::cdef(
                'typedef int BOOL; typedef unsigned long DWORD;'
                    . ' typedef unsigned short WCHAR; typedef void* HANDLE;'
                    . ' typedef struct { DWORD dwLowDateTime; DWORD dwHighDateTime; } FILETIME;'
                    . ' typedef struct {'
                    . ' DWORD dwFileAttributes; FILETIME ftCreationTime;'
                    . ' FILETIME ftLastAccessTime; FILETIME ftLastWriteTime;'
                    . ' DWORD dwVolumeSerialNumber; DWORD nFileSizeHigh;'
                    . ' DWORD nFileSizeLow; DWORD nNumberOfLinks;'
                    . ' DWORD nFileIndexHigh; DWORD nFileIndexLow;'
                    . ' } BY_HANDLE_FILE_INFORMATION;'
                    . ' HANDLE CreateFileW(const WCHAR*, DWORD, DWORD, void*,'
                    . ' DWORD, DWORD, HANDLE);'
                    . ' BOOL GetFileInformationByHandle('
                    . ' HANDLE, BY_HANDLE_FILE_INFORMATION*);'
                    . ' BOOL CloseHandle(HANDLE);'
                    . ' BOOL ReplaceFileW(const WCHAR*, const WCHAR*, const WCHAR*,'
                    . ' DWORD, void*, void*);'
                    . ' BOOL MoveFileExW(const WCHAR*, const WCHAR*, DWORD);'
                    . ' DWORD GetLastError(void);',
                'kernel32.dll',
            );
            $backupWide = self::windowsWidePath($ffi, $backup);
            $targetWide = self::windowsWidePath($ffi, $target);
            $handles = [];
            try {
                $backupNative = self::windowsNativeFileProof(
                    $ffi,
                    $backup,
                    $backupWide,
                );
                $handles[] = $backupNative['handle'];
                if ((int)$backupNative['size'] !== $expectedSize) {
                    throw new \RuntimeException(
                        'Windows WLS recovery backup size changed before commit.'
                    );
                }
                if ($targetStatus !== null) {
                    $targetNative = self::windowsNativeFileProof(
                        $ffi,
                        $target,
                        $targetWide,
                    );
                    $handles[] = $targetNative['handle'];
                }
                $backupProof = self::windowsNativeFileProof(
                    $ffi,
                    $backup,
                    $backupWide,
                );
                $handles[] = $backupProof['handle'];
                $digestAtCommit = @\hash_file('sha256', $backup);
                if (!\hash_equals(
                    (string)$backupNative['identity'],
                    (string)$backupProof['identity'],
                )
                    || !\is_string($digestAtCommit)
                    || !\hash_equals($expectedDigest, $digestAtCommit)
                ) {
                    throw new \RuntimeException(
                        'Windows WLS recovery backup changed immediately before commit.'
                    );
                }
                $succeeded = $targetStatus === null
                    ? (int)$ffi->MoveFileExW($backupWide, $targetWide, 0x00000008)
                    : (int)$ffi->ReplaceFileW(
                        $targetWide,
                        $backupWide,
                        null,
                        0x00000001,
                        null,
                        null,
                    );
                if ($succeeded === 0) {
                    throw new \RuntimeException(
                        'Windows native WLS backup restoration failed with error '
                        . (int)$ffi->GetLastError() . '.'
                    );
                }
                $publishedNative = self::windowsNativeFileProof(
                    $ffi,
                    $target,
                    $targetWide,
                );
                $handles[] = $publishedNative['handle'];
                if (!\hash_equals(
                    (string)$backupNative['identity'],
                    (string)$publishedNative['identity'],
                ) || (int)$publishedNative['size'] !== $expectedSize) {
                    throw new \RuntimeException(
                        'Windows native WLS backup restoration changed the selected file identity.'
                    );
                }
            } finally {
                foreach ($handles as $handle) {
                    try {
                        $ffi->CloseHandle($handle);
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \RuntimeException) {
                throw $throwable;
            }
            throw new \RuntimeException(
                'Windows native WLS backup restoration failed.',
                0,
                $throwable,
            );
        }
    }

    /**
     * Windows PHP builds do not provide one portable replace-existing rename
     * contract. Always use the kernel primitive and bind it to the exact
     * staging leaf and file identity that this process created. The committed
     * target is never unlinked or opened for truncate/write.
     *
     * @param array<string|int,mixed> $stagedStatus
     * @param array<string|int,mixed>|null $existingStatus
     * @return array{schema:string,source_identity:string,target_identity:string,native_source_identity:string,native_target_identity:string,sha256:string,size:int,operation:string,backup_cleanup:string}
     */
    private static function windowsNativeAtomicReplace(
        string $temporary,
        string $target,
        array $stagedStatus,
        ?array $existingStatus,
        string $expectedDigest,
        int $expectedSize,
    ): array {
        if (\PHP_OS_FAMILY !== 'Windows'
            || !\extension_loaded('FFI')
            || !\class_exists(\FFI::class)
            || !\function_exists('iconv')
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
            || $expectedSize < 0
            || $stagedStatus === []
        ) {
            throw new \RuntimeException(
                'Windows native atomic replacement is unavailable; the committed target was preserved.'
            );
        }

        [$canonicalTarget, $targetLeaf] = self::canonicalWindowsAtomicTarget($target);
        $temporaryLeaf = \basename(\str_replace('\\', '/', $temporary));
        if (\preg_match(
            '/\A' . \preg_quote($targetLeaf, '/') . '\.tmp-[a-f0-9]{24}\z/Du',
            $temporaryLeaf,
        ) !== 1) {
            throw new \RuntimeException(
                'Windows atomic staging leaf does not match its exact target generation.'
            );
        }
        $canonicalTemporary = \dirname($canonicalTarget)
            . \DIRECTORY_SEPARATOR . $temporaryLeaf;
        if (!\hash_equals(
            self::normalizeWindowsPathForComparison($canonicalTemporary),
            self::normalizeWindowsPathForComparison($temporary),
        )) {
            throw new \RuntimeException(
                'Windows atomic staging file escaped the canonical target directory.'
            );
        }

        \clearstatcache(true, $temporary);
        \clearstatcache(true, $target);
        $temporaryStatus = @\lstat($temporary);
        $currentTarget = @\lstat($target);
        if (!\is_array($temporaryStatus)
            || !self::sameState($stagedStatus, $temporaryStatus)
        ) {
            throw new \RuntimeException(
                'Windows atomic staging identity changed before native replacement.'
            );
        }
        self::assertRegularStatus(
            $temporary,
            $temporaryStatus,
            'Windows atomic staging file',
        );
        if ($existingStatus !== null) {
            if (!\is_array($currentTarget)
                || !self::sameState($existingStatus, $currentTarget)
            ) {
                throw new \RuntimeException(
                    'Windows committed target changed before native replacement.'
                );
            }
            self::assertRegularStatus(
                $target,
                $currentTarget,
                'Windows atomic committed target',
            );
        } elseif (\is_array($currentTarget)
            || \file_exists($target)
            || \is_link($target)
        ) {
            throw new \RuntimeException(
                'Windows atomic target appeared after the publication preflight.'
            );
        }
        $temporaryDigest = @\hash_file('sha256', $temporary);
        if (!\is_string($temporaryDigest)
            || !\hash_equals($expectedDigest, $temporaryDigest)
            || (int)($temporaryStatus['size'] ?? -1) !== $expectedSize
        ) {
            throw new \RuntimeException(
                'Windows atomic staging contents changed before native replacement.'
            );
        }
        $previousDigest = null;
        if ($existingStatus !== null) {
            $previousDigest = @\hash_file('sha256', $target);
            if (!\is_string($previousDigest)) {
                throw new \RuntimeException(
                    'Windows committed target could not be sealed before replacement.'
                );
            }
        }

        $backup = $existingStatus !== null
            ? self::allocateWindowsAtomicRecoveryPath(
                $canonicalTarget,
                $targetLeaf,
            )
            : $canonicalTarget . '.wls-backup-' . \bin2hex(\random_bytes(8));
        $ffi = null;
        $nativeHandles = [];
        try {
            $ffi = \FFI::cdef(
                'typedef int BOOL; typedef unsigned long DWORD;'
                    . ' typedef unsigned short WCHAR;'
                    . ' typedef void* HANDLE;'
                    . ' typedef struct { DWORD dwLowDateTime; DWORD dwHighDateTime; } FILETIME;'
                    . ' typedef struct {'
                    . ' DWORD dwFileAttributes; FILETIME ftCreationTime;'
                    . ' FILETIME ftLastAccessTime; FILETIME ftLastWriteTime;'
                    . ' DWORD dwVolumeSerialNumber; DWORD nFileSizeHigh;'
                    . ' DWORD nFileSizeLow; DWORD nNumberOfLinks;'
                    . ' DWORD nFileIndexHigh; DWORD nFileIndexLow;'
                    . ' } BY_HANDLE_FILE_INFORMATION;'
                    . ' HANDLE CreateFileW(const WCHAR*, DWORD, DWORD, void*,'
                    . ' DWORD, DWORD, HANDLE);'
                    . ' BOOL GetFileInformationByHandle('
                    . ' HANDLE, BY_HANDLE_FILE_INFORMATION*);'
                    . ' BOOL CloseHandle(HANDLE);'
                    . ' BOOL ReplaceFileW(const WCHAR*, const WCHAR*, const WCHAR*,'
                    . ' DWORD, void*, void*);'
                    . ' BOOL MoveFileExW(const WCHAR*, const WCHAR*, DWORD);'
                    . ' BOOL DeleteFileW(const WCHAR*);'
                    . ' DWORD GetLastError(void);',
                'kernel32.dll',
            );
            $temporaryWide = self::windowsWidePath($ffi, $canonicalTemporary);
            $targetWide = self::windowsWidePath($ffi, $canonicalTarget);
            $backupWide = self::windowsWidePath($ffi, $backup);
            $sourceNative = self::windowsNativeFileProof(
                $ffi,
                $canonicalTemporary,
                $temporaryWide,
            );
            $nativeHandles[] = $sourceNative['handle'];
            if ((int)$sourceNative['size'] !== $expectedSize) {
                throw new \RuntimeException(
                    'Windows native staging handle size changed before replacement.'
                );
            }
            $targetNative = null;
            if ($existingStatus !== null) {
                $targetNative = self::windowsNativeFileProof(
                    $ffi,
                    $canonicalTarget,
                    $targetWide,
                );
                $nativeHandles[] = $targetNative['handle'];
            }
            $sourceAtCommit = self::windowsNativeFileProof(
                $ffi,
                $canonicalTemporary,
                $temporaryWide,
            );
            $nativeHandles[] = $sourceAtCommit['handle'];
            $sourceDigestAtCommit = @\hash_file('sha256', $canonicalTemporary);
            if (!\hash_equals(
                (string)$sourceNative['identity'],
                (string)$sourceAtCommit['identity'],
            )
                || !\is_string($sourceDigestAtCommit)
                || !\hash_equals($expectedDigest, $sourceDigestAtCommit)
            ) {
                throw new \RuntimeException(
                    'Windows atomic staging path or contents changed immediately before native replacement.'
                );
            }
            if ($existingStatus !== null) {
                $targetAtCommit = self::windowsNativeFileProof(
                    $ffi,
                    $canonicalTarget,
                    $targetWide,
                );
                $nativeHandles[] = $targetAtCommit['handle'];
                $targetDigestAtCommit = @\hash_file('sha256', $canonicalTarget);
                if (!\hash_equals(
                    (string)($targetNative['identity'] ?? ''),
                    (string)$targetAtCommit['identity'],
                )
                    || !\is_string($targetDigestAtCommit)
                    || !\is_string($previousDigest)
                    || !\hash_equals($previousDigest, $targetDigestAtCommit)
                ) {
                    throw new \RuntimeException(
                        'Windows committed target identity or contents changed immediately before native replacement.'
                    );
                }
            }
            $operation = $existingStatus !== null ? 'ReplaceFileW' : 'MoveFileExW';
            $succeeded = $existingStatus !== null
                ? (int)$ffi->ReplaceFileW(
                    $targetWide,
                    $temporaryWide,
                    $backupWide,
                    // WRITE_THROUGH only. Never ignore metadata/security
                    // merge errors: an existing target's owner and protected
                    // DACL are part of the committed state contract.
                    0x00000001,
                    null,
                    null,
                )
                : (int)$ffi->MoveFileExW(
                    $temporaryWide,
                    $targetWide,
                    0x00000008,
                );
            if ($succeeded === 0) {
                $nativeError = (int)$ffi->GetLastError();
                self::restoreWindowsAtomicTarget(
                    $ffi,
                    $canonicalTarget,
                    $canonicalTemporary,
                    $backup,
                    $existingStatus,
                    $previousDigest,
                    $stagedStatus,
                    $expectedDigest,
                    (string)$sourceNative['identity'],
                    (string)($targetNative['identity'] ?? ''),
                );
                throw new \RuntimeException(
                    'Windows native atomic replacement failed with error '
                    . $nativeError . '; the committed target was preserved.'
                );
            }

            \clearstatcache(true, $canonicalTarget);
            $published = @\lstat($canonicalTarget);
            $publishedDigest = @\hash_file('sha256', $canonicalTarget);
            if (!\is_array($published)
                || !self::sameIdentity($stagedStatus, $published)
                || !\is_string($publishedDigest)
                || !\hash_equals($expectedDigest, $publishedDigest)
                || (int)($published['size'] ?? -1) !== $expectedSize
            ) {
                self::restoreWindowsAtomicTarget(
                    $ffi,
                    $canonicalTarget,
                    $canonicalTemporary,
                    $backup,
                    $existingStatus,
                    $previousDigest,
                    $stagedStatus,
                    $expectedDigest,
                    (string)$sourceNative['identity'],
                    (string)($targetNative['identity'] ?? ''),
                );
                throw new \RuntimeException(
                    'Windows native atomic replacement failed its identity receipt; rollback was requested.'
                );
            }
            self::assertRegularStatus(
                $canonicalTarget,
                $published,
                'Windows native published target',
            );
            $publishedNative = self::windowsNativeFileProof(
                $ffi,
                $canonicalTarget,
                $targetWide,
            );
            $nativeHandles[] = $publishedNative['handle'];
            if (!\hash_equals(
                (string)$sourceNative['identity'],
                (string)$publishedNative['identity'],
            ) || (int)$publishedNative['size'] !== $expectedSize) {
                self::restoreWindowsAtomicTarget(
                    $ffi,
                    $canonicalTarget,
                    $canonicalTemporary,
                    $backup,
                    $existingStatus,
                    $previousDigest,
                    $stagedStatus,
                    $expectedDigest,
                    (string)$sourceNative['identity'],
                    (string)($targetNative['identity'] ?? ''),
                );
                throw new \RuntimeException(
                    'Windows native atomic replacement changed the staged file identity.'
                );
            }
            if ($existingStatus !== null) {
                $backupNative = self::windowsNativeFileProof(
                    $ffi,
                    $backup,
                    $backupWide,
                );
                $nativeHandles[] = $backupNative['handle'];
                $backupDigest = @\hash_file('sha256', $backup);
                if (!\hash_equals(
                    (string)($targetNative['identity'] ?? ''),
                    (string)$backupNative['identity'],
                )
                    || !\is_string($backupDigest)
                    || !\is_string($previousDigest)
                    || !\hash_equals($previousDigest, $backupDigest)
                ) {
                    throw new \RuntimeException(
                        'Windows atomic recovery backup does not match the replaced target identity.'
                    );
                }
                // The backup is retained automatically if cleanup is blocked;
                // publication remains unambiguous because the target receipt
                // above is already complete and content-addressed.
                $backupCleanup = (int)$ffi->DeleteFileW($backupWide) !== 0
                    ? 'deleted'
                    : 'retained';
            } else {
                $backupCleanup = 'not_applicable';
            }

            return [
                'schema' => 'wls-windows-atomic-replace/1',
                'source_identity' => self::stableIdentityDigest($stagedStatus),
                'target_identity' => self::stableIdentityDigest($published),
                'native_source_identity' => (string)$sourceNative['identity'],
                'native_target_identity' => (string)$publishedNative['identity'],
                'sha256' => $publishedDigest,
                'size' => $expectedSize,
                'operation' => $operation,
                'backup_cleanup' => $backupCleanup,
            ];
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \RuntimeException) {
                throw $throwable;
            }
            throw new \RuntimeException(
                'Windows native atomic replacement is unavailable; the committed target was preserved.',
                0,
                $throwable,
            );
        } finally {
            if ($ffi instanceof \FFI) {
                foreach ($nativeHandles as $nativeHandle) {
                    try {
                        $ffi->CloseHandle($nativeHandle);
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    private static function allocateWindowsAtomicRecoveryPath(
        string $canonicalTarget,
        string $targetLeaf,
    ): string {
        $directory = \dirname($canonicalTarget);
        $entries = @\opendir($directory);
        if (!\is_resource($entries)) {
            throw new \RuntimeException(
                'Windows atomic recovery directory cannot be enumerated safely.'
            );
        }
        $targetPattern = '/\A' . \preg_quote($targetLeaf, '/')
            . '\.wls-backup-[a-f0-9]{16}\z/D';
        $allPattern = '/\A.+\.wls-backup-[a-f0-9]{16}\z/D';
        $targetBackups = 0;
        $directoryBackups = 0;
        $visited = 0;
        try {
            while (($leaf = @\readdir($entries)) !== false) {
                ++$visited;
                if ($visited > self::MAX_WINDOWS_ATOMIC_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Windows atomic recovery directory exceeds its bounded entry contract.'
                    );
                }
                if (\preg_match($allPattern, $leaf) !== 1) {
                    continue;
                }
                ++$directoryBackups;
                if (\preg_match($targetPattern, $leaf) === 1) {
                    ++$targetBackups;
                }
                $path = $directory . \DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (!\is_array($status)) {
                    if (\file_exists($path) || \is_link($path)) {
                        throw new \RuntimeException(
                            'Windows atomic recovery directory contains an indeterminate reserved leaf.'
                        );
                    }
                    continue;
                }
                self::assertRegularStatus(
                    $path,
                    $status,
                    'Windows atomic recovery backup',
                );
            }
        } finally {
            @\closedir($entries);
        }
        if ($targetBackups >= self::MAX_WINDOWS_RECOVERY_BACKUPS_PER_TARGET
            || $directoryBackups >= self::MAX_WINDOWS_RECOVERY_BACKUPS_PER_DIRECTORY
        ) {
            throw new \RuntimeException(
                'Windows atomic recovery backup quota is exhausted; retained backups require repair.'
            );
        }
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $candidate = $canonicalTarget . '.wls-backup-'
                . \bin2hex(\random_bytes(8));
            if (!\file_exists($candidate) && !\is_link($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException(
            'Windows atomic recovery leaf allocation exhausted its bounded retry budget.'
        );
    }

    /** @return array{0:string,1:string} */
    private static function canonicalWindowsAtomicTarget(string $path): array
    {
        $requestedDirectory = \dirname($path);
        $canonicalDirectory = @\realpath($requestedDirectory);
        $leaf = \basename(\str_replace('\\', '/', $path));
        $unsafeLeaf = $leaf === ''
            || $leaf === '.'
            || $leaf === '..'
            || \str_ends_with($leaf, '.')
            || \str_ends_with($leaf, ' ')
            || \preg_match('/[\x00-\x1f<>:"\/\\\\|?*]/u', $leaf) !== 0
            || \preg_match('/(?:\.tmp-[a-f0-9]{24}|\.wls-backup-[a-f0-9]{16})\z/iu', $leaf) === 1;
        $deviceBase = \strtoupper((string)(\explode('.', $leaf, 2)[0] ?? ''));
        if (!\is_string($canonicalDirectory)
            || $canonicalDirectory === ''
            || $unsafeLeaf
            || \preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/D', $deviceBase) === 1
            || !\hash_equals(
                self::normalizeWindowsPathForComparison($canonicalDirectory),
                self::normalizeWindowsPathForComparison($requestedDirectory),
            )
        ) {
            throw new \RuntimeException('Windows atomic target path is not canonical or safe.');
        }
        return [
            \rtrim($canonicalDirectory, '/\\') . \DIRECTORY_SEPARATOR . $leaf,
            $leaf,
        ];
    }

    private static function normalizeWindowsPathForComparison(string $path): string
    {
        $path = \str_replace('/', '\\', \rtrim($path, '/\\'));
        if (\str_starts_with($path, '\\\\?\\UNC\\')) {
            $path = '\\\\' . \substr($path, 8);
        } elseif (\str_starts_with($path, '\\\\?\\')) {
            $path = \substr($path, 4);
        }
        return \function_exists('mb_strtolower')
            ? \mb_strtolower($path, 'UTF-8')
            : \strtolower($path);
    }

    /** @return \FFI\CData */
    private static function windowsWidePath(\FFI $ffi, string $path): \FFI\CData
    {
        $encoded = @\iconv('UTF-8', 'UTF-16LE', $path . "\0");
        if (!\is_string($encoded)
            || $encoded === ''
            || (\strlen($encoded) % 2) !== 0
        ) {
            throw new \RuntimeException('Windows atomic path cannot be encoded as UTF-16LE.');
        }
        $buffer = $ffi->new('WCHAR[' . (int)(\strlen($encoded) / 2) . ']');
        \FFI::memcpy($buffer, $encoded, \strlen($encoded));
        return $buffer;
    }

    /**
     * Open the named object itself (not its reparse destination) and retain the
     * handle through publication. ReplaceFileW is name based, so the exact
     * path is re-opened immediately before commit and the resulting backup is
     * compared with this native file identity after commit.
     *
     * @return array{handle:\FFI\CData,identity:string,size:int}
     */
    private static function windowsNativeFileProof(
        \FFI $ffi,
        string $path,
        \FFI\CData $widePath,
    ): array {
        $handle = $ffi->CreateFileW(
            $widePath,
            0x00000080,
            0x00000005,
            null,
            3,
            0x00200080,
            null,
        );
        $info = $ffi->new('BY_HANDLE_FILE_INFORMATION');
        if ((int)$ffi->GetFileInformationByHandle(
            $handle,
            \FFI::addr($info),
        ) === 0) {
            try {
                $ffi->CloseHandle($handle);
            } catch (\Throwable) {
            }
            throw new \RuntimeException(
                'Windows native atomic path cannot be opened without following: ' . $path
            );
        }
        $attributes = (int)$info->dwFileAttributes;
        $links = (int)$info->nNumberOfLinks;
        if (($attributes & 0x00000400) !== 0
            || ($attributes & 0x00000010) !== 0
            || $links !== 1
        ) {
            $ffi->CloseHandle($handle);
            throw new \RuntimeException(
                'Windows native atomic path is a reparse point, directory, or hard link.'
            );
        }
        $identity = \sprintf(
            '%08x%08x%08x',
            (int)$info->dwVolumeSerialNumber,
            (int)$info->nFileIndexHigh,
            (int)$info->nFileIndexLow,
        );
        $size = ((int)$info->nFileSizeHigh << 32) | (int)$info->nFileSizeLow;
        return [
            'handle' => $handle,
            'identity' => \strtolower($identity),
            'size' => $size,
        ];
    }

    /**
     * @param array<string|int,mixed>|null $existingStatus
     * @param array<string|int,mixed> $stagedStatus
     */
    private static function restoreWindowsAtomicTarget(
        \FFI $ffi,
        string $target,
        string $temporary,
        string $backup,
        ?array $existingStatus,
        ?string $previousDigest,
        array $stagedStatus,
        string $expectedDigest,
        string $expectedSourceNativeIdentity,
        string $expectedTargetNativeIdentity,
    ): void {
        if ($existingStatus === null) {
            \clearstatcache(true, $target);
            if (!\file_exists($target) && !\is_link($target)) {
                self::assertWindowsAtomicStagingPreserved(
                    $ffi,
                    $temporary,
                    $stagedStatus,
                    $expectedDigest,
                    $expectedSourceNativeIdentity,
                );
                return;
            }
            $targetStatus = @\lstat($target);
            if (!\is_array($targetStatus)) {
                throw new \RuntimeException(
                    'Windows atomic first-publication rollback found an indeterminate target.'
                );
            }
            self::assertRegularStatus(
                $target,
                $targetStatus,
                'Windows atomic first-publication rollback target',
            );
            $targetDigest = @\hash_file('sha256', $target);
            $targetNative = null;
            try {
                $targetNative = self::windowsNativeFileProof(
                    $ffi,
                    $target,
                    self::windowsWidePath($ffi, $target),
                );
                if (!self::sameIdentity($stagedStatus, $targetStatus)
                    || !\is_string($targetDigest)
                    || !\hash_equals($expectedDigest, $targetDigest)
                    || !\hash_equals(
                        $expectedSourceNativeIdentity,
                        (string)$targetNative['identity'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows atomic first-publication rollback refused an unverified target; the target was left untouched.'
                    );
                }
                if (\file_exists($temporary) || \is_link($temporary)) {
                    throw new \RuntimeException(
                        'Windows atomic first-publication rollback refused to replace an existing staging leaf.'
                    );
                }
                $targetWide = self::windowsWidePath($ffi, $target);
                $temporaryWide = self::windowsWidePath($ffi, $temporary);
                $restored = (int)$ffi->MoveFileExW(
                    $targetWide,
                    $temporaryWide,
                    0x00000008,
                );
            } finally {
                if (\is_array($targetNative)) {
                    $ffi->CloseHandle($targetNative['handle']);
                }
            }
            \clearstatcache(true, $target);
            \clearstatcache(true, $temporary);
            $temporaryStatus = @\lstat($temporary);
            $temporaryDigest = @\hash_file('sha256', $temporary);
            $temporaryNative = null;
            try {
                $temporaryNative = self::windowsNativeFileProof(
                    $ffi,
                    $temporary,
                    self::windowsWidePath($ffi, $temporary),
                );
            } finally {
                if (\is_array($temporaryNative)) {
                    $ffi->CloseHandle($temporaryNative['handle']);
                }
            }
            if ($restored === 0
                || \file_exists($target)
                || \is_link($target)
                || !\is_array($temporaryStatus)
                || !self::sameIdentity($stagedStatus, $temporaryStatus)
                || !\is_string($temporaryDigest)
                || !\hash_equals($expectedDigest, $temporaryDigest)
                || !\is_array($temporaryNative)
                || !\hash_equals(
                    $expectedSourceNativeIdentity,
                    (string)$temporaryNative['identity'],
                )
            ) {
                throw new \RuntimeException(
                    'Windows atomic first-publication rollback failed; the staged target was retained.'
                );
            }
            return;
        }

        \clearstatcache(true, $target);
        $currentTargetStatus = @\lstat($target);
        if (\is_array($currentTargetStatus)) {
            self::assertRegularStatus(
                $target,
                $currentTargetStatus,
                'Windows atomic rollback current target',
            );
            $currentTargetNative = null;
            try {
                $currentTargetNative = self::windowsNativeFileProof(
                    $ffi,
                    $target,
                    self::windowsWidePath($ffi, $target),
                );
                $currentTargetDigest = @\hash_file('sha256', $target);
                if (self::sameIdentity($existingStatus, $currentTargetStatus)
                    && \is_string($previousDigest)
                    && \is_string($currentTargetDigest)
                    && \hash_equals($previousDigest, $currentTargetDigest)
                    && \hash_equals(
                        $expectedTargetNativeIdentity,
                        (string)$currentTargetNative['identity'],
                    )
                ) {
                    return;
                }
                if (!self::sameIdentity($stagedStatus, $currentTargetStatus)
                    || !\is_string($currentTargetDigest)
                    || !\hash_equals($expectedDigest, $currentTargetDigest)
                    || !\hash_equals(
                        $expectedSourceNativeIdentity,
                        (string)$currentTargetNative['identity'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows atomic rollback refused an unverified current target; it was left untouched.'
                    );
                }
            } finally {
                if (\is_array($currentTargetNative)) {
                    $ffi->CloseHandle($currentTargetNative['handle']);
                }
            }
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'Windows atomic rollback found an indeterminate current target.'
            );
        }

        \clearstatcache(true, $backup);
        if (!\is_file($backup) || \is_link($backup)) {
            throw new \RuntimeException(
                'Windows atomic rollback cannot prove the previous committed target because its recovery backup is missing.'
            );
        }
        $backupNative = null;
        try {
            $backupNative = self::windowsNativeFileProof(
                $ffi,
                $backup,
                self::windowsWidePath($ffi, $backup),
            );
            if (!\hash_equals(
                $expectedTargetNativeIdentity,
                (string)$backupNative['identity'],
            )) {
                throw new \RuntimeException(
                    'Windows atomic recovery backup identity changed before rollback.'
                );
            }
            $backupDigest = @\hash_file('sha256', $backup);
            if (!\is_string($previousDigest)
                || !\is_string($backupDigest)
                || !\hash_equals($previousDigest, $backupDigest)
            ) {
                throw new \RuntimeException(
                    'Windows atomic recovery backup contents changed before rollback.'
                );
            }
            $targetWide = self::windowsWidePath($ffi, $target);
            $backupWide = self::windowsWidePath($ffi, $backup);
            $restored = \is_file($target) && !\is_link($target)
                ? (int)$ffi->ReplaceFileW(
                    $targetWide,
                    $backupWide,
                    null,
                    // Restore the prior contents only if Windows can also
                    // preserve the target security metadata transactionally.
                    0x00000001,
                    null,
                    null,
                )
                : (int)$ffi->MoveFileExW($backupWide, $targetWide, 0x00000008);
        } finally {
            if (\is_array($backupNative)) {
                $ffi->CloseHandle($backupNative['handle']);
            }
        }
        \clearstatcache(true, $target);
        $restoredStatus = @\lstat($target);
        $restoredDigest = @\hash_file('sha256', $target);
        $restoredNative = null;
        try {
            $restoredNative = self::windowsNativeFileProof(
                $ffi,
                $target,
                self::windowsWidePath($ffi, $target),
            );
        } finally {
            if (\is_array($restoredNative)) {
                $ffi->CloseHandle($restoredNative['handle']);
            }
        }
        if ($restored === 0
            || !\is_array($restoredStatus)
            || !self::sameIdentity($existingStatus, $restoredStatus)
            || !\is_string($previousDigest)
            || !\is_string($restoredDigest)
            || !\hash_equals($previousDigest, $restoredDigest)
            || !\is_array($restoredNative)
            || !\hash_equals(
                $expectedTargetNativeIdentity,
                (string)$restoredNative['identity'],
            )
        ) {
            throw new \RuntimeException(
                'Windows atomic replacement rollback failed; the recovery backup was retained.'
            );
        }
    }

    /** @param array<string|int,mixed> $stagedStatus */
    private static function assertWindowsAtomicStagingPreserved(
        \FFI $ffi,
        string $temporary,
        array $stagedStatus,
        string $expectedDigest,
        string $expectedNativeIdentity,
    ): void {
        \clearstatcache(true, $temporary);
        $status = @\lstat($temporary);
        if (!\is_array($status)) {
            throw new \RuntimeException(
                'Windows atomic staging file disappeared while the target remained unpublished.'
            );
        }
        self::assertRegularStatus(
            $temporary,
            $status,
            'Windows atomic preserved staging file',
        );
        $native = null;
        try {
            $native = self::windowsNativeFileProof(
                $ffi,
                $temporary,
                self::windowsWidePath($ffi, $temporary),
            );
            $digest = @\hash_file('sha256', $temporary);
            if (!self::sameIdentity($stagedStatus, $status)
                || !\is_string($digest)
                || !\hash_equals($expectedDigest, $digest)
                || !\hash_equals(
                    $expectedNativeIdentity,
                    (string)$native['identity'],
                )
            ) {
                throw new \RuntimeException(
                    'Windows atomic staging identity changed while the target remained unpublished.'
                );
            }
        } finally {
            if (\is_array($native)) {
                $ffi->CloseHandle($native['handle']);
            }
        }
    }

    /** @param array<string|int,mixed> $status */
    private static function stableIdentityDigest(array $status): string
    {
        $identity = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'size'] as $field) {
            if (!\array_key_exists($field, $status)) {
                return '';
            }
            $identity[] = (string)(int)$status[$field];
        }
        return \hash('sha256', \implode(':', $identity));
    }

    /** @param array<string|int,mixed>|null $expectedIdentity */
    public static function removeRegular(
        string $path,
        string $label,
        ?array $expectedIdentity = null,
    ): bool
    {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            return !\file_exists($path) && !\is_link($path);
        }
        self::assertRegularStatus($path, $status, $label);
        if ($expectedIdentity !== null
            && !self::sameObjectIdentity($expectedIdentity, $status)
        ) {
            throw new \RuntimeException(
                $label . ' no longer has the identity selected for removal.'
            );
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to verify ' . $label . ' before removal.');
        }
        $removed = false;
        $verified = null;
        try {
            $opened = @\fstat($handle);
            $pathBeforeRemove = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($pathBeforeRemove)
                || !self::sameState($status, $opened)
                || !self::sameState($opened, $pathBeforeRemove)
                || ($expectedIdentity !== null
                    && !self::sameObjectIdentity($expectedIdentity, $opened))
            ) {
                throw new \RuntimeException($label . ' changed before removal.');
            }
            $verified = $opened;
            if (\PHP_OS_FAMILY !== 'Windows') {
                if (!@\unlink($path)) {
                    throw new \RuntimeException('Unable to remove ' . $label . '.');
                }
                $removed = true;
            }
        } finally {
            @\fclose($handle);
        }
        if (!$removed) {
            // Windows normally refuses unlink while the verification handle is
            // open. Recheck the exact file identity immediately after closing
            // it, so a path substitution cannot silently delete an unchecked
            // object during the platform-specific close-to-unlink window.
            $pathAfterClose = @\lstat($path);
            if (!\is_array($verified)
                || !\is_array($pathAfterClose)
                || !self::sameState($verified, $pathAfterClose)
                || ($expectedIdentity !== null
                    && !self::sameObjectIdentity($expectedIdentity, $pathAfterClose))
                || !@\unlink($path)
            ) {
                throw new \RuntimeException('Unable to safely remove ' . $label . '.');
            }
        }
        self::syncDirectory(\dirname($path));
        return true;
    }

    public static function syncDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return;
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS state parent directory is unsafe.');
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the WLS state parent directory.');
        }
        try {
            if (!@\fsync($handle)) {
                throw new \RuntimeException('Unable to synchronize the WLS state parent directory.');
            }
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * Move one already-verified regular file or directory within a filesystem
     * without replacing the destination. The destination parent is made
     * durable before the source parent so a crash cannot durably unlink the
     * only name before its new name is committed.
     */
    public static function moveNoReplace(
        string $source,
        string $destination,
        string $label,
    ): void {
        if ($source === ''
            || $destination === ''
            || $label === ''
            || \hash_equals($source, $destination)
        ) {
            throw new \InvalidArgumentException(
                'Gateway durable move boundary is invalid.',
            );
        }
        if (\file_exists($destination) || \is_link($destination)) {
            throw new \RuntimeException($label . ' destination already exists.');
        }
        $sourceIdentity = GatewayBoundedTreeWalker::identity($source);
        $sourceParent = \dirname($source);
        $destinationParent = \dirname($destination);
        $sourceParentIdentity = GatewayBoundedTreeWalker::identity(
            $sourceParent,
        );
        $destinationParentIdentity = GatewayBoundedTreeWalker::identity(
            $destinationParent,
        );
        if (($sourceParentIdentity['directory'] ?? false) !== true
            || ($destinationParentIdentity['directory'] ?? false) !== true
            || !\hash_equals(
                (string)$sourceParentIdentity['device'],
                (string)$destinationParentIdentity['device'],
            )
        ) {
            throw new \RuntimeException(
                $label . ' must remain on one verified filesystem.',
            );
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            self::windowsMoveNoReplaceWriteThrough($source, $destination, $label);
        } elseif (\PHP_OS_FAMILY === 'Darwin') {
            self::darwinMoveNoReplace($source, $destination, $label);
        } else {
            self::linuxMoveNoReplace($source, $destination, $label);
        }
        if (\file_exists($source) || \is_link($source)) {
            throw new \RuntimeException($label . ' source remained after move.');
        }
        self::assertMovedIdentity($destination, $sourceIdentity, $label);
        self::syncDirectory($destinationParent);
        if (!\hash_equals($sourceParent, $destinationParent)) {
            self::syncDirectory($sourceParent);
        }
        self::assertMovedIdentity($destination, $sourceIdentity, $label);
    }

    /**
     * Finish the only supported cross-parent crash replay that exposes both
     * names for one moved regular-file identity.
     */
    public static function reconcileMovedRegularAlias(
        string $source,
        string $destination,
        string $label,
    ): void {
        $sourceIdentity = GatewayBoundedTreeWalker::identity($source, true);
        $destinationIdentity = GatewayBoundedTreeWalker::identity(
            $destination,
            true,
        );
        if (($sourceIdentity['directory'] ?? true) !== false
            || ($destinationIdentity['directory'] ?? true) !== false
            || !\hash_equals(
                (string)$sourceIdentity['device'],
                (string)$destinationIdentity['device'],
            )
            || !\hash_equals(
                (string)$sourceIdentity['inode'],
                (string)$destinationIdentity['inode'],
            )
        ) {
            throw new \RuntimeException(
                $label . ' exposes two different move identities.',
            );
        }
        self::syncDirectory(\dirname($destination));
        $sourceAgain = GatewayBoundedTreeWalker::identity($source, true);
        $destinationAgain = GatewayBoundedTreeWalker::identity(
            $destination,
            true,
        );
        if (!\hash_equals(
                (string)$sourceIdentity['device'],
                (string)$sourceAgain['device'],
            )
            || !\hash_equals(
                (string)$sourceIdentity['inode'],
                (string)$sourceAgain['inode'],
            )
            || !\hash_equals(
                (string)$destinationIdentity['device'],
                (string)$destinationAgain['device'],
            )
            || !\hash_equals(
                (string)$destinationIdentity['inode'],
                (string)$destinationAgain['inode'],
            )
            || !@\unlink($source)
        ) {
            throw new \RuntimeException(
                'Unable to reconcile ' . $label . ' source alias.',
            );
        }
        self::syncDirectory(\dirname($source));
        self::assertMovedIdentity($destination, $destinationIdentity, $label);
    }

    /**
     * Exercise the exact supported file and directory move primitives on the
     * host rebootstrap filesystem before any maintenance stop is authorized.
     */
    public static function assertMoveNoReplaceRuntimeCapability(
        string $rebootstrapRoot,
    ): void {
        $rootIdentity = GatewayBoundedTreeWalker::identity($rebootstrapRoot);
        if (($rootIdentity['directory'] ?? false) !== true) {
            throw new \RuntimeException(
                'Gateway rebootstrap move-probe root is not a directory.',
            );
        }
        $probe = $rebootstrapRoot . DIRECTORY_SEPARATOR
            . 'move-capability.probe';
        if (\file_exists($probe) || \is_link($probe)) {
            self::removeMoveCapabilityProbe($probe);
        }
        if (!@\mkdir($probe, 0700)
            || !@\mkdir($probe . DIRECTORY_SEPARATOR . 'source', 0700)
            || !@\mkdir($probe . DIRECTORY_SEPARATOR . 'destination', 0700)
        ) {
            throw new \RuntimeException(
                'Unable to create the gateway durable-move capability probe.',
            );
        }
        self::syncDirectory($rebootstrapRoot);
        try {
            $sourceParent = $probe . DIRECTORY_SEPARATOR . 'source';
            $destinationParent = $probe . DIRECTORY_SEPARATOR . 'destination';
            $fileSource = $sourceParent . DIRECTORY_SEPARATOR . 'file.source';
            $fileDestination = $destinationParent . DIRECTORY_SEPARATOR
                . 'file.destination';
            self::atomicWrite($fileSource, "wls-move-probe\n", 0600);
            self::moveNoReplace(
                $fileSource,
                $fileDestination,
                'Gateway regular-file durable-move capability probe',
            );
            $directorySource = $sourceParent . DIRECTORY_SEPARATOR
                . 'directory.source';
            $directoryDestination = $destinationParent . DIRECTORY_SEPARATOR
                . 'directory.destination';
            if (!@\mkdir($directorySource, 0700)) {
                throw new \RuntimeException(
                    'Unable to create the gateway directory-move capability probe.',
                );
            }
            self::atomicWrite(
                $directorySource . DIRECTORY_SEPARATOR . 'proof',
                "wls-directory-move-probe\n",
                0600,
            );
            self::moveNoReplace(
                $directorySource,
                $directoryDestination,
                'Gateway directory durable-move capability probe',
            );
        } finally {
            if (\file_exists($probe) || \is_link($probe)) {
                self::removeMoveCapabilityProbe($probe);
            }
        }
    }

    private static function removeMoveCapabilityProbe(string $probe): void
    {
        $entries = GatewayBoundedTreeWalker::collect(
            $probe,
            true,
            true,
            32,
            8,
        );
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
            $removed = ($entry['directory'] ?? false) === true
                ? @\rmdir((string)$entry['path'])
                : @\unlink((string)$entry['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove the verified gateway move capability probe.',
                );
            }
        }
        self::syncDirectory(\dirname($probe));
    }

    /** @param array<string,mixed> $expected */
    private static function assertMovedIdentity(
        string $path,
        array $expected,
        string $label,
    ): void {
        $actual = GatewayBoundedTreeWalker::identity($path);
        if (($actual['directory'] ?? null) !== ($expected['directory'] ?? null)
            || !\hash_equals(
                (string)($expected['device'] ?? ''),
                (string)($actual['device'] ?? ''),
            )
            || !\hash_equals(
                (string)($expected['inode'] ?? ''),
                (string)($actual['inode'] ?? ''),
            )
        ) {
            throw new \RuntimeException($label . ' identity changed during move.');
        }
    }

    private static function darwinMoveNoReplace(
        string $source,
        string $destination,
        string $label,
    ): void {
        try {
            $ffi = \FFI::cdef(
                'int renamex_np(const char*, const char*, unsigned int);',
                '/usr/lib/libSystem.B.dylib',
            );
            if ((int)$ffi->renamex_np($source, $destination, 0x00000004) !== 0) {
                throw new \RuntimeException($label . ' native renamex_np failed.');
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                $label . ' requires the locked macOS native no-replace move runtime.',
                0,
                $throwable,
            );
        }
    }

    private static function linuxMoveNoReplace(
        string $source,
        string $destination,
        string $label,
    ): void {
        try {
            $ffi = \FFI::cdef(
                'int renameat2(int, const char*, int, const char*, unsigned int);',
                null,
            );
            if ((int)$ffi->renameat2(
                -100,
                $source,
                -100,
                $destination,
                0x00000001,
            ) !== 0) {
                throw new \RuntimeException($label . ' native renameat2 failed.');
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                $label . ' requires the locked Linux native no-replace move runtime.',
                0,
                $throwable,
            );
        }
    }

    private static function windowsMoveNoReplaceWriteThrough(
        string $source,
        string $destination,
        string $label,
    ): void {
        self::assertAtomicWriteRuntimeCapability();
        try {
            $ffi = \FFI::cdef(
                'typedef int BOOL; typedef unsigned long DWORD;'
                    . ' typedef unsigned short WCHAR;'
                    . ' BOOL MoveFileExW(const WCHAR*, const WCHAR*, DWORD);'
                    . ' DWORD GetLastError(void);',
                'kernel32.dll',
            );
            $sourceWide = self::windowsWidePath($ffi, $source);
            $destinationWide = self::windowsWidePath($ffi, $destination);
            if ((int)$ffi->MoveFileExW(
                $sourceWide,
                $destinationWide,
                0x00000008,
            ) === 0) {
                throw new \RuntimeException(
                    $label . ' MoveFileExW failed with Win32 error '
                        . (int)$ffi->GetLastError() . '.',
                );
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                $label . ' requires the locked Windows write-through move runtime.',
                0,
                $throwable,
            );
        }
    }

    /**
     * Test-only fault injection for the ambiguous commit boundary after a
     * successful atomic rename. The exact target is addressed by digest so a
     * test cannot accidentally poison unrelated project state writes.
     */
    private static function injectedPostRenameDirectorySyncFailure(
        string $path,
    ): bool {
        $targetDigest = \strtolower(\trim((string)\getenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256',
        )));
        return (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1'
            && \hash_equals(
                'directory_fsync_after_rename_failed',
                (string)\getenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE'),
            )
            && \preg_match('/\A[a-f0-9]{64}\z/D', $targetDigest) === 1
            && \hash_equals($targetDigest, \hash('sha256', $path));
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $contents, string $label): void
    {
        $offset = 0;
        $length = \strlen($contents);
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Unable to write the ' . $label . '.');
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $handle
     * @param (\Closure(resource,string):void)|null $seal
     */
    private static function sealHandle(
        $handle,
        string $path,
        int $mode,
        ?\Closure $seal,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows' && !self::applyHandleMode($handle, $path, $mode)) {
            throw new \RuntimeException('Unable to seal the WLS state file mode.');
        }
        if ($seal !== null) {
            $seal($handle, $path);
        }
    }

    /**
     * Prefer fchmod(handle) when available; otherwise chmod(path) for the same mode.
     * Some macOS PHP builds ship without fchmod while still supporting chmod.
     *
     * @param resource $handle
     */
    private static function applyHandleMode($handle, string $path, int $mode): bool
    {
        $status = @\fstat($handle);
        if (\is_array($status)
            && (((int)($status['mode'] ?? -1)) & 07777) === $mode
        ) {
            return true;
        }
        if (\function_exists('fchmod')) {
            return @\fchmod($handle, $mode);
        }

        return $path !== '' && @\chmod($path, $mode);
    }

    private static function assertParentDirectory(string $path): void
    {
        if ($path === '' || \str_contains($path, "\0")) {
            throw new \RuntimeException('WLS state path is invalid.');
        }
        $directory = \dirname($path);
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS state parent directory is missing or unsafe.');
        }
    }

    /** @param array<string|int,mixed> $status */
    private static function assertRegularStatus(
        string $path,
        array $status,
        string $label,
    ): void {
        if (\is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
        ) {
            throw new \RuntimeException($label . ' must be one regular non-linked file.');
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)) {
                return false;
            }
            $beforeValue = (int)$before[$field];
            $afterValue = (int)$after[$field];
            if ($field === 'mode' && \PHP_OS_FAMILY === 'Windows') {
                // PHP synthesizes executable permission bits from a Windows
                // pathname extension for lstat(), while fstat() on the same
                // open file handle reports 0666. Windows authority is carried
                // by the existing ACL checks; retain only the file-type bits
                // here so one unchanged .exe remains the same object.
                $beforeValue &= 0170000;
                $afterValue &= 0170000;
            }
            if ($beforeValue !== $afterValue) {
                return false;
            }
        }
        return true;
    }

    /**
     * Mutable metadata may be tightened by another trusted lock holder while
     * an opener is taking its pre-flock snapshots. The open file description
     * keeps its inode alive, so an equal device/inode pair cannot be a reused
     * replacement. Callers still validate regular type and nlink=1 on every
     * snapshot and require a subsequent complete sameState comparison.
     *
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameOpenFileIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rename may update inode timestamps while preserving the staged object.
     * Compare only immutable identity and publication-shape fields here.
     *
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Staging cleanup may follow a partial write, so size is mutable even
     * though the selected file object must remain the one created by us.
     *
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private static function sameObjectIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }
}
