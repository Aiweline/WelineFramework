<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

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
    private const MAX_RECOVERY_DIRECTORY_ENTRIES = 4096;
    private const MAX_STAGING_RECOVERY_ARTIFACTS = 8;
    private const MAX_BACKUP_RECOVERY_ARTIFACTS = 8;
    private const MAX_LEGACY_CORRUPT_DIAGNOSTICS = 8;
    private const CORRUPT_DIAGNOSTIC_SUFFIX = '.corrupt-latest';

    private readonly string $stateDirectory;
    private readonly string $stateFile;
    private readonly string $lockFile;
    private readonly string $bootIdentity;
    /** @var resource|null */
    private mixed $activeLock = null;
    /** @var array<string|int,mixed>|null */
    private ?array $activeLockIdentity = null;
    /** @var array<string|int,mixed>|null */
    private ?array $activeDirectoryIdentity = null;

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
        $directoryIdentity = @\lstat($this->stateDirectory);
        if (!\is_array($directoryIdentity)
            || \is_link($this->stateDirectory)
            || !$this->isSafeDirectoryStatus($directoryIdentity)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure coordination directory is unsafe.'
            );
        }
        $lock = VerifiedPersistentFileLock::acquire(
            $this->lockFile,
            self::LOCK_ACQUIRE_TIMEOUT_SECONDS,
            fn(): array => [
                'schema' => 'wls-host-memory-pressure-lock/1',
                'pid' => \getmypid(),
                'boot_id' => $this->bootIdentity,
                'acquired_at' => \gmdate(DATE_ATOM),
            ],
        );
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Timed out or failed to safely acquire the host memory-pressure coordination lock.'
            );
        }
        $lockIdentity = @\fstat($lock);
        $pathIdentity = @\lstat($this->lockFile);
        $directoryAfterAcquire = @\lstat($this->stateDirectory);
        if (!\is_array($lockIdentity)
            || !\is_array($pathIdentity)
            || !\is_array($directoryAfterAcquire)
            || !$this->isSafeRegularStatus($lockIdentity)
            || !$this->isSafeRegularStatus($pathIdentity)
            || !$this->sameObjectIdentity($lockIdentity, $pathIdentity)
            || !$this->sameDirectoryIdentity($directoryIdentity, $directoryAfterAcquire)
            || \is_link($this->lockFile)
            || \is_link($this->stateDirectory)
        ) {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
            throw new \RuntimeException(
                'Host memory-pressure coordination lock identity is unsafe.'
            );
        }
        $this->activeLock = $lock;
        $this->activeLockIdentity = $lockIdentity;
        $this->activeDirectoryIdentity = $directoryAfterAcquire;
        try {
            $this->assertActiveLockFence();
            try {
                return $callback();
            } finally {
                $this->assertActiveLockFence();
            }
        } finally {
            $this->activeLock = null;
            $this->activeLockIdentity = null;
            $this->activeDirectoryIdentity = null;
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
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
        return $this->recoverStateNamespace();
    }

    /**
     * Reconcile hard-crash artifacts while the stable host lock is held.
     *
     * This state is derived. A missing or corrupt committed generation may be
     * reset to empty after preserving one fixed diagnostic slot; a valid
     * generation remains authoritative and only its uncommitted artifacts are
     * collected. Every reserved leaf is validated twice before the first
     * removal, so an unsafe namespace is preserved in full for diagnosis.
     *
     * @return array<string,mixed>
     */
    private function recoverStateNamespace(): array
    {
        $this->assertActiveLockFence();
        $firstInventory = $this->scanRecoveryNamespace();
        $firstTarget = $this->inspectCommittedState();
        $secondInventory = $this->scanRecoveryNamespace();
        $secondTarget = $this->inspectCommittedState();
        if (!$this->sameRecoveryInventory($firstInventory, $secondInventory)
            || !$this->sameTargetInspection($firstTarget, $secondTarget)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure recovery namespace changed during preflight.'
            );
        }

        $targetKind = (string)$secondTarget['kind'];
        $artifacts = $secondInventory['artifacts'];
        if ($targetKind === 'corrupt') {
            $diagnosticIdentity = $this->quarantineInvalidState(
                $secondTarget['identity'],
                $secondInventory['diagnostic'],
            );
            $this->removeRecoveryArtifacts(
                $artifacts,
                null,
                $diagnosticIdentity,
            );
            return [];
        }
        if ($artifacts !== []) {
            $this->removeRecoveryArtifacts(
                $artifacts,
                $targetKind === 'valid' ? $secondTarget['identity'] : null,
                $secondInventory['diagnostic'],
            );
        }
        if ($targetKind === 'missing') {
            return [];
        }

        return \is_array($secondTarget['state'] ?? null)
            ? $secondTarget['state']
            : [];
    }

    /**
     * @return array{
     *   artifacts:array<string,array{path:string,kind:string,identity:array<string|int,mixed>}>,
     *   diagnostic:array{path:string,identity:array<string|int,mixed>}|null
     * }
     */
    private function scanRecoveryNamespace(): array
    {
        $handle = @\opendir($this->stateDirectory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the host memory-pressure recovery namespace.'
            );
        }
        $targetLeaf = \basename($this->stateFile);
        $lockLeaf = \basename($this->lockFile);
        $temporaryPrefix = $targetLeaf . '.tmp-';
        $backupPrefix = $targetLeaf . '.wls-backup-';
        $diagnosticLeaf = $targetLeaf . self::CORRUPT_DIAGNOSTIC_SUFFIX;
        $legacyDiagnosticPrefix = $targetLeaf . '.corrupt-';
        $foldedTargetLeaf = \strtolower($targetLeaf);
        $foldedLockLeaf = \strtolower($lockLeaf);
        $foldedTemporaryPrefix = \strtolower($temporaryPrefix);
        $foldedBackupPrefix = \strtolower($backupPrefix);
        $foldedDiagnosticLeaf = \strtolower($diagnosticLeaf);
        $foldedLegacyDiagnosticPrefix = \strtolower($legacyDiagnosticPrefix);
        $temporaryPattern = '/\A' . \preg_quote($temporaryPrefix, '/')
            . '(?:[a-f0-9]{16}|[a-f0-9]{24})\z/Du';
        $backupPattern = '/\A' . \preg_quote($backupPrefix, '/')
            . '[a-f0-9]{16}\z/Du';
        $legacyDiagnosticPattern = '/\A'
            . \preg_quote($legacyDiagnosticPrefix, '/')
            . '[0-9]{14}-[a-f0-9]{8}\z/Du';
        $artifacts = [];
        $diagnostic = null;
        $stagingCount = 0;
        $backupCount = 0;
        $legacyDiagnosticCount = 0;
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$visited > self::MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Host memory-pressure recovery directory exceeds its fixed raw entry quota.'
                    );
                }
                $foldedLeaf = \strtolower($leaf);
                if (($foldedLeaf === $foldedTargetLeaf && $leaf !== $targetLeaf)
                    || ($foldedLeaf === $foldedLockLeaf && $leaf !== $lockLeaf)
                ) {
                    throw new \RuntimeException(
                        'Host memory-pressure recovery namespace contains a non-canonical case alias.'
                    );
                }

                $temporaryNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedTemporaryPrefix,
                );
                $backupNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedBackupPrefix,
                );
                $diagnosticNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedDiagnosticLeaf,
                );
                $legacyDiagnosticNamespace = \str_starts_with(
                    $foldedLeaf,
                    $foldedLegacyDiagnosticPrefix,
                );
                if (!$temporaryNamespace
                    && !$backupNamespace
                    && !$diagnosticNamespace
                    && !$legacyDiagnosticNamespace
                ) {
                    continue;
                }
                if (($temporaryNamespace
                        && !\str_starts_with($leaf, $temporaryPrefix))
                    || ($backupNamespace
                        && !\str_starts_with($leaf, $backupPrefix))
                    || ($diagnosticNamespace
                        && !\str_starts_with($leaf, $diagnosticLeaf))
                    || ($legacyDiagnosticNamespace
                        && !\str_starts_with($leaf, $legacyDiagnosticPrefix))
                ) {
                    throw new \RuntimeException(
                        'Host memory-pressure recovery namespace contains a non-canonical case alias.'
                    );
                }

                $path = $this->stateDirectory . DIRECTORY_SEPARATOR . $leaf;
                if ($diagnosticNamespace) {
                    if ($leaf !== $diagnosticLeaf || $diagnostic !== null) {
                        throw new \RuntimeException(
                            'Host memory-pressure recovery namespace contains a malformed diagnostic leaf.'
                        );
                    }
                    $diagnostic = [
                        'path' => $path,
                        'identity' => $this->inspectRecoveryFile(
                            $path,
                            'host memory-pressure corrupt diagnostic',
                        ),
                    ];
                    continue;
                }

                $kind = '';
                if ($temporaryNamespace
                    && \preg_match($temporaryPattern, $leaf) === 1
                ) {
                    $kind = \strlen($leaf) === \strlen($temporaryPrefix) + 16
                        ? 'legacy staging file'
                        : 'staging file';
                    if (++$stagingCount > self::MAX_STAGING_RECOVERY_ARTIFACTS) {
                        throw new \RuntimeException(
                            'Host memory-pressure staging recovery quota is exhausted.'
                        );
                    }
                } elseif ($backupNamespace
                    && \preg_match($backupPattern, $leaf) === 1
                ) {
                    $kind = 'backup file';
                    if (++$backupCount > self::MAX_BACKUP_RECOVERY_ARTIFACTS) {
                        throw new \RuntimeException(
                            'Host memory-pressure backup recovery quota is exhausted.'
                        );
                    }
                } elseif ($legacyDiagnosticNamespace
                    && \preg_match($legacyDiagnosticPattern, $leaf) === 1
                ) {
                    $kind = 'legacy corrupt diagnostic';
                    if (++$legacyDiagnosticCount
                        > self::MAX_LEGACY_CORRUPT_DIAGNOSTICS
                    ) {
                        throw new \RuntimeException(
                            'Host memory-pressure legacy corrupt diagnostic quota is exhausted.'
                        );
                    }
                } else {
                    throw new \RuntimeException(
                        'Host memory-pressure recovery namespace contains a malformed reserved leaf.'
                    );
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $this->inspectRecoveryFile(
                        $path,
                        'host memory-pressure ' . $kind,
                        $kind === 'legacy corrupt diagnostic'
                            ? PHP_INT_MAX
                            : self::MAX_STATE_BYTES,
                    ),
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);

        return [
            'artifacts' => $artifacts,
            'diagnostic' => $diagnostic,
        ];
    }

    /** @return array<string|int,mixed> */
    private function inspectRecoveryFile(
        string $path,
        string $label,
        int $maximumBytes = self::MAX_STATE_BYTES,
    ): array
    {
        $before = @\lstat($path);
        if (!\is_array($before) || !$this->isSafeRegularStatus($before)) {
            throw new \RuntimeException(
                \ucfirst($label) . ' must be one regular non-linked file.'
            );
        }
        GatewayProjectStateFilesystem::size($path, $maximumBytes, $label);
        $after = @\lstat($path);
        if (!\is_array($after) || !$this->sameStateIdentity($before, $after)) {
            throw new \RuntimeException(\ucfirst($label) . ' changed during inspection.');
        }

        return $after;
    }

    /**
     * @return array{kind:string,identity:array<string|int,mixed>|null,state:array<string,mixed>|null}
     */
    private function inspectCommittedState(): array
    {
        \clearstatcache(true, $this->stateFile);
        $before = @\lstat($this->stateFile);
        if (!\is_array($before)) {
            if (\file_exists($this->stateFile) || \is_link($this->stateFile)) {
                throw new \RuntimeException(
                    'Host memory-pressure committed state path is indeterminate or unsafe.'
                );
            }
            return ['kind' => 'missing', 'identity' => null, 'state' => null];
        }
        if (!$this->isSafeRegularStatus($before) || \is_link($this->stateFile)) {
            throw new \RuntimeException(
                'Host memory-pressure committed state must be one regular non-linked file.'
            );
        }
        $size = (int)($before['size'] ?? -1);
        if ($size < 1 || $size > self::MAX_STATE_BYTES) {
            return ['kind' => 'corrupt', 'identity' => $before, 'state' => null];
        }
        $encoded = GatewayProjectStateFilesystem::read(
            $this->stateFile,
            self::MAX_STATE_BYTES,
            'host memory-pressure committed state',
        );
        $after = @\lstat($this->stateFile);
        if (!\is_array($after) || !$this->sameStateIdentity($before, $after)) {
            throw new \RuntimeException(
                'Host memory-pressure committed state changed during inspection.'
            );
        }
        $state = \json_decode($encoded, true);
        if (!\is_array($state)
            || (int)($state['schema_version'] ?? 0) !== self::SCHEMA_VERSION
        ) {
            return ['kind' => 'corrupt', 'identity' => $after, 'state' => null];
        }

        return ['kind' => 'valid', 'identity' => $after, 'state' => $state];
    }

    /**
     * @param array<string|int,mixed> $targetIdentity
     * @param array{path:string,identity:array<string|int,mixed>}|null $existingDiagnostic
     * @return array{path:string,identity:array<string|int,mixed>}
     */
    private function quarantineInvalidState(
        array $targetIdentity,
        ?array $existingDiagnostic,
    ): array {
        $this->assertActiveLockFence();
        $currentTarget = @\lstat($this->stateFile);
        if (!\is_array($currentTarget)
            || !$this->sameStateIdentity($targetIdentity, $currentTarget)
            || !$this->isSafeRegularStatus($currentTarget)
        ) {
            throw new \RuntimeException(
                'Invalid host memory-pressure state changed before isolation.'
            );
        }
        $diagnostic = $this->stateFile . self::CORRUPT_DIAGNOSTIC_SUFFIX;
        if ($existingDiagnostic !== null) {
            if (!GatewayProjectStateFilesystem::removeRegular(
                $diagnostic,
                'previous host memory-pressure corrupt diagnostic',
                $existingDiagnostic['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect the previous host memory-pressure corrupt diagnostic.'
                );
            }
        }
        if ((int)($targetIdentity['size'] ?? -1) > self::MAX_STATE_BYTES) {
            $diagnosticIdentity = $this->createCorruptDiagnosticMarker(
                $diagnostic,
                (int)$targetIdentity['size'],
            );
            $currentTarget = @\lstat($this->stateFile);
            if (!\is_array($currentTarget)
                || !$this->sameStateIdentity($targetIdentity, $currentTarget)
                || !GatewayProjectStateFilesystem::removeRegular(
                    $this->stateFile,
                    'oversized host memory-pressure corrupt state',
                    $targetIdentity,
                )
            ) {
                throw new \RuntimeException(
                    'Unable to discard oversized invalid host memory-pressure state.'
                );
            }
            $this->assertActiveLockFence();
            return ['path' => $diagnostic, 'identity' => $diagnosticIdentity];
        }
        $currentTarget = @\lstat($this->stateFile);
        if (!\is_array($currentTarget)
            || !$this->sameStateIdentity($targetIdentity, $currentTarget)
            || !@\rename($this->stateFile, $diagnostic)
        ) {
            throw new \RuntimeException(
                'Unable to isolate invalid host memory-pressure coordination state.'
            );
        }
        $renamedIdentity = @\lstat($diagnostic);
        if (!\is_array($renamedIdentity)
            || !$this->isSafeRegularStatus($renamedIdentity)
            || !$this->sameObjectIdentity($targetIdentity, $renamedIdentity)
            || \is_array(@\lstat($this->stateFile))
            || \file_exists($this->stateFile)
            || \is_link($this->stateFile)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure corrupt diagnostic failed identity validation.'
            );
        }
        @\chmod($diagnostic, 0600);
        $diagnosticIdentity = @\lstat($diagnostic);
        if (!\is_array($diagnosticIdentity)
            || !$this->isSafeRegularStatus($diagnosticIdentity)
            || !$this->samePhysicalIdentity($renamedIdentity, $diagnosticIdentity)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure corrupt diagnostic failed permission sealing.'
            );
        }
        GatewayProjectStateFilesystem::syncDirectory($this->stateDirectory);
        $this->assertActiveLockFence();

        return ['path' => $diagnostic, 'identity' => $diagnosticIdentity];
    }

    /** @return array<string|int,mixed> */
    private function createCorruptDiagnosticMarker(
        string $diagnostic,
        int $originalSize,
    ): array {
        $payload = (string)\json_encode([
            'schema' => 'wls-host-memory-pressure-corrupt-diagnostic/1',
            'reason' => 'committed_state_exceeds_size_limit',
            'original_size' => $originalSize,
            'maximum_size' => self::MAX_STATE_BYTES,
            'recorded_at' => \gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $handle = @\fopen($diagnostic, 'x+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to create the bounded host memory-pressure corrupt diagnostic.'
            );
        }
        $createdIdentity = null;
        $failure = null;
        try {
            $createdIdentity = @\fstat($handle);
            $createdPath = @\lstat($diagnostic);
            if (!\is_array($createdIdentity)
                || !\is_array($createdPath)
                || !$this->isSafeRegularStatus($createdIdentity)
                || !$this->sameStateIdentity($createdIdentity, $createdPath)
            ) {
                throw new \RuntimeException(
                    'Bounded host memory-pressure diagnostic identity is unsafe.'
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                $sealed = \function_exists('fchmod')
                    ? @\fchmod($handle, 0600)
                    : @\chmod($diagnostic, 0600);
                if (!$sealed) {
                    throw new \RuntimeException(
                        'Unable to seal the bounded host memory-pressure diagnostic.'
                    );
                }
            }
            $offset = 0;
            while ($offset < \strlen($payload)) {
                $written = @\fwrite($handle, \substr($payload, $offset));
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException(
                        'Unable to persist the bounded host memory-pressure diagnostic.'
                    );
                }
                $offset += $written;
            }
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException(
                    'Unable to synchronize the bounded host memory-pressure diagnostic.'
                );
            }
            $opened = @\fstat($handle);
            $path = @\lstat($diagnostic);
            if (!\is_array($opened)
                || !\is_array($path)
                || !$this->isSafeRegularStatus($opened)
                || !$this->sameStateIdentity($opened, $path)
                || (int)($opened['size'] ?? -1) !== \strlen($payload)
            ) {
                throw new \RuntimeException(
                    'Bounded host memory-pressure diagnostic failed publication validation.'
                );
            }
            $createdIdentity = $opened;
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($handle);
        }
        if ($failure instanceof \Throwable) {
            if (\is_array($createdIdentity)) {
                try {
                    GatewayProjectStateFilesystem::removeRegular(
                        $diagnostic,
                        'failed host memory-pressure corrupt diagnostic',
                    );
                } catch (\Throwable) {
                    // Preserve an indeterminate fixed diagnostic for doctor.
                }
            }
            throw $failure;
        }
        GatewayProjectStateFilesystem::syncDirectory($this->stateDirectory);

        return $createdIdentity;
    }

    /**
     * @param array<string,array{path:string,kind:string,identity:array<string|int,mixed>}> $artifacts
     * @param array<string|int,mixed>|null $targetIdentity
     * @param array{path:string,identity:array<string|int,mixed>}|null $diagnostic
     */
    private function removeRecoveryArtifacts(
        array $artifacts,
        ?array $targetIdentity,
        ?array $diagnostic,
    ): void {
        foreach ($artifacts as $artifact) {
            $this->assertActiveLockFence();
            $currentTarget = @\lstat($this->stateFile);
            if ($targetIdentity === null) {
                if (\is_array($currentTarget)
                    || \file_exists($this->stateFile)
                    || \is_link($this->stateFile)
                ) {
                    throw new \RuntimeException(
                        'Host memory-pressure committed state appeared during recovery cleanup.'
                    );
                }
            } elseif (!\is_array($currentTarget)
                || !$this->sameStateIdentity($targetIdentity, $currentTarget)
            ) {
                throw new \RuntimeException(
                    'Host memory-pressure committed state changed during recovery cleanup.'
                );
            }
            if ($diagnostic !== null) {
                $currentDiagnostic = @\lstat($diagnostic['path']);
                if (!\is_array($currentDiagnostic)
                    || !$this->sameStateIdentity(
                        $diagnostic['identity'],
                        $currentDiagnostic,
                    )
                ) {
                    throw new \RuntimeException(
                        'Host memory-pressure corrupt diagnostic changed during recovery cleanup.'
                    );
                }
            }
            $currentArtifact = @\lstat($artifact['path']);
            if (!\is_array($currentArtifact)
                || !$this->sameStateIdentity(
                    $artifact['identity'],
                    $currentArtifact,
                )
                || !GatewayProjectStateFilesystem::removeRegular(
                    $artifact['path'],
                    'host memory-pressure ' . $artifact['kind'],
                    $artifact['identity'],
                )
            ) {
                throw new \RuntimeException(
                    'Unable to safely collect a host memory-pressure recovery artifact.'
                );
            }
        }
        $this->assertActiveLockFence();
        if ($this->scanRecoveryNamespace()['artifacts'] !== []) {
            throw new \RuntimeException(
                'Host memory-pressure recovery artifacts remain after cleanup.'
            );
        }
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second */
    private function sameRecoveryInventory(array $first, array $second): bool
    {
        $firstArtifacts = $first['artifacts'] ?? null;
        $secondArtifacts = $second['artifacts'] ?? null;
        if (!\is_array($firstArtifacts)
            || !\is_array($secondArtifacts)
            || \array_keys($firstArtifacts) !== \array_keys($secondArtifacts)
        ) {
            return false;
        }
        foreach ($firstArtifacts as $path => $artifact) {
            $candidate = $secondArtifacts[$path] ?? null;
            if (!\is_array($artifact)
                || !\is_array($candidate)
                || !\hash_equals(
                    (string)($artifact['kind'] ?? ''),
                    (string)($candidate['kind'] ?? ''),
                )
                || !\is_array($artifact['identity'] ?? null)
                || !\is_array($candidate['identity'] ?? null)
                || !$this->sameStateIdentity(
                    $artifact['identity'],
                    $candidate['identity'],
                )
            ) {
                return false;
            }
        }
        $firstDiagnostic = $first['diagnostic'] ?? null;
        $secondDiagnostic = $second['diagnostic'] ?? null;
        if ($firstDiagnostic === null || $secondDiagnostic === null) {
            return $firstDiagnostic === $secondDiagnostic;
        }

        return \is_array($firstDiagnostic)
            && \is_array($secondDiagnostic)
            && \is_array($firstDiagnostic['identity'] ?? null)
            && \is_array($secondDiagnostic['identity'] ?? null)
            && \hash_equals(
                (string)($firstDiagnostic['path'] ?? ''),
                (string)($secondDiagnostic['path'] ?? ''),
            )
            && $this->sameStateIdentity(
                $firstDiagnostic['identity'],
                $secondDiagnostic['identity'],
            );
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second */
    private function sameTargetInspection(array $first, array $second): bool
    {
        if (!\hash_equals(
            (string)($first['kind'] ?? ''),
            (string)($second['kind'] ?? ''),
        )) {
            return false;
        }
        $firstIdentity = $first['identity'] ?? null;
        $secondIdentity = $second['identity'] ?? null;
        if ($firstIdentity === null || $secondIdentity === null) {
            return $firstIdentity === $secondIdentity;
        }

        return \is_array($firstIdentity)
            && \is_array($secondIdentity)
            && $this->sameStateIdentity($firstIdentity, $secondIdentity)
            && ($first['state'] ?? null) === ($second['state'] ?? null);
    }

    private function assertActiveLockFence(): void
    {
        if (!\is_resource($this->activeLock)
            || !\is_array($this->activeLockIdentity)
            || !\is_array($this->activeDirectoryIdentity)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure coordination lock is not active.'
            );
        }
        $opened = @\fstat($this->activeLock);
        $path = @\lstat($this->lockFile);
        $directory = @\lstat($this->stateDirectory);
        if (!\is_array($opened)
            || !\is_array($path)
            || !\is_array($directory)
            || !$this->isSafeRegularStatus($opened)
            || !$this->isSafeRegularStatus($path)
            || !$this->sameStateIdentity($this->activeLockIdentity, $opened)
            || !$this->sameStateIdentity($opened, $path)
            || !$this->sameDirectoryIdentity(
                $this->activeDirectoryIdentity,
                $directory,
            )
            || \is_link($this->lockFile)
            || \is_link($this->stateDirectory)
        ) {
            throw new \RuntimeException(
                'Host memory-pressure coordination lock fence changed while active.'
            );
        }
    }

    /** @param array<string|int,mixed> $status */
    private function isSafeRegularStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
            && (int)($status['nlink'] ?? 0) === 1;
    }

    /** @param array<string|int,mixed> $status */
    private function isSafeDirectoryStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0040000);
    }

    /** @param array<string|int,mixed> $first @param array<string|int,mixed> $second */
    private function sameObjectIdentity(array $first, array $second): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
            if ((int)($first[$field] ?? -1) !== (int)($second[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $first @param array<string|int,mixed> $second */
    private function samePhysicalIdentity(array $first, array $second): bool
    {
        foreach (['dev', 'ino', 'nlink'] as $field) {
            if ((int)($first[$field] ?? -1) !== (int)($second[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $first @param array<string|int,mixed> $second */
    private function sameDirectoryIdentity(array $first, array $second): bool
    {
        foreach (['dev', 'ino', 'mode'] as $field) {
            if ((int)($first[$field] ?? -1) !== (int)($second[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string|int,mixed> $first @param array<string|int,mixed> $second */
    private function sameStateIdentity(array $first, array $second): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($first[$field] ?? -1) !== (int)($second[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function publishState(array $state): void
    {
        $this->assertActiveLockFence();
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
        if (\strlen($payload) > self::MAX_STATE_BYTES) {
            throw new \RuntimeException(
                'Host memory-pressure coordination state exceeds its fixed size limit.'
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $this->stateFile,
            $payload,
            0600,
        );
        $this->assertActiveLockFence();
        $published = $this->recoverStateNamespace();
        $publishedPayload = GatewayProjectStateFilesystem::read(
            $this->stateFile,
            self::MAX_STATE_BYTES,
            'published host memory-pressure coordination state',
        );
        if ((int)($published['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals(
                \hash('sha256', $payload),
                \hash('sha256', $publishedPayload),
            )
        ) {
            throw new \RuntimeException(
                'Published host memory-pressure coordination state failed validation.'
            );
        }
        $this->assertActiveLockFence();
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
            $bootTime = '';
            try {
                $result = GatewayBoundedCommandRunner::run([
                    '/usr/sbin/sysctl',
                    '-n',
                    'kern.boottime',
                ], 3.0);
                $bootTime = \trim((string)($result['stdout'] ?? $result['output'] ?? ''));
            } catch (\Throwable) {
                $bootTime = '';
            }
            if ($bootTime === ''
                || \preg_match(
                    '/\A\{\s*sec\s*=\s*(\d+),\s*usec\s*=\s*(\d+)\s*\}/',
                    $bootTime,
                    $matches,
                ) !== 1
            ) {
                $bootTime = GatewayHostBootIdentity::platformToken();
                if (\preg_match('/\Adarwin-(\d+)-(\d+)\z/D', $bootTime, $matches) !== 1) {
                    throw new \RuntimeException('macOS boot identity is unavailable.');
                }
            }
            return \hash(
                'sha256',
                'darwin:' . $matches[1] . ':' . $matches[2],
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $systemRoot = \rtrim((string)\getenv('SystemRoot'), '\\/');
            if ($systemRoot === '') {
                throw new \RuntimeException(
                    'Windows system root is unavailable for the boot identity probe.'
                );
            }
            $powershell = $systemRoot
                . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
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
        $result = GatewayBoundedCommandRunner::run(
            $command,
            \max(0.1, $timeoutSeconds),
        );
        if ((int)$result['code'] !== 0) {
            $output = \trim((string)$result['output']);
            if (\str_contains(\strtolower($output), 'timed out')) {
                throw new \RuntimeException(
                    'Host memory-pressure boot identity probe timed out.'
                );
            }
            throw new \RuntimeException(
                'Host memory-pressure boot identity probe failed: '
                    . $output,
            );
        }

        return \trim((string)$result['stdout']);
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
