<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Shared candidate/active/rollback transaction for managed Nginx configs.
 *
 * Candidates and rollback files always remain beside the active file, keeping
 * every rename on one filesystem. Callers must hold their lifecycle lock.
 */
final class NginxConfigPublication
{
    private const MAX_CONFIG_BYTES = 16 * 1024 * 1024;

    private const MAX_RECOVERY_DIRECTORY_ENTRIES = 8192;

    private const MAX_ORPHAN_STAGING_ARTIFACTS = 64;

    private const MAX_ATOMIC_TEMPORARIES_PER_TARGET = 8;

    private const MAX_ATOMIC_TEMPORARIES_PER_DIRECTORY = 64;

    public function __construct(
        private readonly string $activeConfig,
        private readonly string $scope = 'managed nginx',
    ) {
        if (!$this->isAbsolutePath($activeConfig)
            || \basename($activeConfig) === ''
            || \str_contains($activeConfig, "\0")
            || \preg_match('#(?:^|[\\/])\.\.?([\\/]|$)#D', $activeConfig) === 1
        ) {
            throw new \InvalidArgumentException('Nginx active config path must be absolute.');
        }
        $directory = \dirname($activeConfig);
        $resolved = \realpath($directory);
        if (!\is_string($resolved)
            || !\is_dir($resolved)
            || \is_link($directory)
            || !$this->samePath($resolved, $directory)
        ) {
            throw new \InvalidArgumentException('Nginx active config directory is unsafe.');
        }
    }

    public function stageCandidate(string $contents): string
    {
        if ($contents === '') {
            throw new \InvalidArgumentException('Nginx candidate config must not be empty.');
        }
        $candidate = $this->candidatePath();
        $this->writeNewFile($candidate, $contents, 0600);
        return $candidate;
    }

    public function validateCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        $this->readRegularFile($candidate, 'candidate');
    }

    /** @return array{conf:string,rollback:string|null} */
    public function publishCandidate(string $candidate, string $transactionId): array
    {
        $this->assertCandidatePath($candidate);
        $candidateContents = $this->readRegularFile($candidate, 'candidate');
        $this->assertTransactionId($transactionId);
        $active = $this->activeConfig;
        $rollback = null;
        $activeContents = null;
        if (\file_exists($active) || \is_link($active)) {
            $activeContents = $this->readRegularFile($active, 'active config');
            $rollback = $this->rollbackPathForTransaction($transactionId);
            if (\file_exists($rollback) || \is_link($rollback)) {
                throw new \RuntimeException($this->scope . ' transaction rollback already exists.');
            }
            GatewayProjectStateFilesystem::atomicWrite(
                $rollback,
                $activeContents,
                0600,
            );
        }
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $active,
                $candidateContents,
                0600,
            );
            GatewayProjectStateFilesystem::removeRegular(
                $candidate,
                \ucfirst($this->scope) . ' candidate config',
            );
        } catch (\Throwable $throwable) {
            if ($activeContents !== null) {
                try {
                    GatewayProjectStateFilesystem::atomicWrite(
                        $active,
                        $activeContents,
                        0600,
                    );
                    GatewayProjectStateFilesystem::removeRegular(
                        $rollback,
                        \ucfirst($this->scope) . ' rollback config',
                    );
                } catch (\Throwable $restoreFailure) {
                    throw new \RuntimeException(
                        'Unable to publish or restore the ' . $this->scope
                            . ' config: ' . $restoreFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
            } elseif (@\lstat($active) !== false) {
                try {
                    GatewayProjectStateFilesystem::removeRegular(
                        $active,
                        \ucfirst($this->scope) . ' failed first active config',
                    );
                } catch (\Throwable $restoreFailure) {
                    throw new \RuntimeException(
                        'Unable to publish or remove the first ' . $this->scope
                            . ' config: ' . $restoreFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
            }
            throw new \RuntimeException(
                'Unable to publish the ' . $this->scope . ' candidate config.',
                0,
                $throwable,
            );
        }

        return ['conf' => $active, 'rollback' => $rollback];
    }

    public function rollbackPublished(?string $rollback): void
    {
        if ($rollback !== null) {
            if ($rollback !== $this->rollbackPathForTransaction($this->transactionIdFromRollback($rollback))) {
                throw new \InvalidArgumentException($this->scope . ' rollback path is outside its config scope.');
            }
            $rollbackContents = $this->readRegularFile($rollback, 'rollback');
        } else {
            $rollbackContents = null;
        }
        $active = $this->activeConfig;
        $rejected = null;
        if (\file_exists($active) || \is_link($active)) {
            $activeContents = $this->readRegularFile($active, 'active config');
            $rejected = $active . '.rejected.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
            GatewayProjectStateFilesystem::atomicWrite(
                $rejected,
                $activeContents,
                0600,
            );
        }
        $restored = false;
        try {
            if ($rollbackContents !== null) {
                GatewayProjectStateFilesystem::atomicWrite(
                    $active,
                    $rollbackContents,
                    0600,
                );
                GatewayProjectStateFilesystem::removeRegular(
                    (string)$rollback,
                    \ucfirst($this->scope) . ' rollback config',
                );
                $this->cleanupResolvedRollbackTemporaries((string)$rollback);
            } elseif (\file_exists($active) || \is_link($active)) {
                GatewayProjectStateFilesystem::removeRegular(
                    $active,
                    \ucfirst($this->scope) . ' active config',
                );
            }
            $restored = true;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Unable to restore the previous ' . $this->scope . ' config.',
                0,
                $throwable,
            );
        } finally {
            if ($restored && $rejected !== null) {
                GatewayProjectStateFilesystem::removeRegular(
                    $rejected,
                    \ucfirst($this->scope) . ' rejected config',
                );
            }
        }
    }

    /**
     * Replace the already-published candidate inside the same lifecycle
     * transaction while preserving its original before-image rollback. This is
     * used for protocol-only degradation (for example QUIC -> H2/H1) after the
     * first candidate has already reached the live data plane.
     *
     * @return array{conf:string,rollback:string|null}
     */
    public function replacePublishedCandidate(
        string $candidate,
        string $transactionId,
        ?string $rollback,
        string $expectedActiveSha256,
    ): array {
        $this->assertCandidatePath($candidate);
        $this->assertTransactionId($transactionId);
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedActiveSha256) !== 1) {
            throw new \InvalidArgumentException(
                \ucfirst($this->scope) . ' active config digest is invalid.',
            );
        }
        if ($rollback !== null) {
            $expectedRollback = $this->rollbackPathForTransaction($transactionId);
            if (!\hash_equals($expectedRollback, $rollback)) {
                throw new \InvalidArgumentException(
                    \ucfirst($this->scope) . ' rollback does not belong to this transaction.',
                );
            }
            $this->readRegularFile($rollback, 'rollback');
        }
        $activeContents = $this->readRegularFile($this->activeConfig, 'active config');
        if (!\hash_equals($expectedActiveSha256, \hash('sha256', $activeContents))) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' active config changed before in-transaction replacement.',
            );
        }
        $candidateContents = $this->readRegularFile($candidate, 'candidate');
        $candidateSha256 = \hash('sha256', $candidateContents);
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $this->activeConfig,
                $candidateContents,
                0600,
            );
        } catch (\Throwable $throwable) {
            $this->reconcileWriteAfterImageDurability(
                $this->activeConfig,
                $candidateSha256,
                'active config replacement after-image',
                $throwable,
            );
        }
        try {
            GatewayProjectStateFilesystem::removeRegular(
                $candidate,
                \ucfirst($this->scope) . ' replacement candidate config',
            );
        } catch (\Throwable $throwable) {
            if ($this->pathExistsNoFollow($candidate)
                || !$this->fileHasExactDigest(
                    $this->activeConfig,
                    $candidateSha256,
                    'active config replacement after-image',
                )
            ) {
                throw $throwable;
            }
            $this->reconcileRemovalAfterImageDurability(
                $candidate,
                $candidateSha256,
                $throwable,
            );
        }

        return ['conf' => $this->activeConfig, 'rollback' => $rollback];
    }

    public function recoverInterruptedPublication(): void
    {
        $this->recoverOrphanRollbacks();
        $this->cleanupOrphanStagingArtifacts();
        if (\file_exists($this->activeConfig)) {
            $this->assertRegularFile($this->activeConfig, 'active config');
            return;
        }
        $lastGood = $this->lastGoodPath();
        if (!\file_exists($lastGood) && !\is_link($lastGood)) {
            return;
        }
        $contents = $this->readRegularFile($lastGood, 'last-known-good config');
        GatewayProjectStateFilesystem::atomicWrite(
            $this->activeConfig,
            $contents,
            0600,
        );
    }

    /**
     * Resolve the single untracked rollback left by the legacy writer, or
     * collect rollback staging files once active/LKG authority is proven.
     * Multiple committed rollback generations are intentionally ambiguous.
     */
    private function recoverOrphanRollbacks(): void
    {
        $directory = \dirname($this->activeConfig);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' rollback recovery directory is unsafe.',
            );
        }
        $stream = @\opendir($directory);
        if (!\is_resource($stream)) {
            throw new \RuntimeException(
                'Unable to enumerate ' . $this->scope . ' rollback recovery directory.',
            );
        }
        $prefix = \basename($this->activeConfig) . '.rollback.';
        $rollbacks = [];
        $temporaries = [];
        $rawEntries = 0;
        try {
            while (($leaf = \readdir($stream)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' rollback recovery directory quota is exhausted.',
                    );
                }
                $reserved = \str_starts_with($leaf, $prefix)
                    || (\PHP_OS_FAMILY === 'Windows'
                        && \strncasecmp($leaf, $prefix, \strlen($prefix)) === 0);
                if (!$reserved) {
                    continue;
                }
                if (!\str_starts_with($leaf, $prefix)
                    || \preg_match(
                        '/\A' . \preg_quote($prefix, '/')
                            . '([a-f0-9]{32})(?:\.tmp-([a-f0-9]{24}))?\z/D',
                        $leaf,
                        $matches,
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope)
                            . ' rollback recovery found a malformed reserved leaf.',
                    );
                }
                $isTemporary = isset($matches[2]);
                if (\count($rollbacks) + \count($temporaries)
                    >= self::MAX_ORPHAN_STAGING_ARTIFACTS
                ) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' orphan rollback quota is exhausted.',
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                GatewayProjectStateFilesystem::read(
                    $path,
                    self::MAX_CONFIG_BYTES,
                    \ucfirst($this->scope) . ' orphan rollback artifact',
                    $isTemporary,
                );
                $identity = @\lstat($path);
                if (!\is_array($identity)) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' orphan rollback disappeared during discovery.',
                    );
                }
                $artifact = [
                    'path' => $path,
                    'identity' => $identity,
                ];
                if ($isTemporary) {
                    $temporaries[] = $artifact;
                } else {
                    $rollbacks[] = $artifact;
                }
            }
        } finally {
            @\closedir($stream);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameStableState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' rollback recovery directory changed during discovery.',
            );
        }
        if (\count($rollbacks) > 1) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' orphan rollback generations are ambiguous.',
            );
        }
        if ($rollbacks === [] && $temporaries === []) {
            return;
        }

        $activeExists = $this->pathExistsNoFollow($this->activeConfig);
        if ($activeExists) {
            $this->readRegularFile($this->activeConfig, 'active config');
        } elseif ($rollbacks === []) {
            $lastGood = $this->lastGoodPath();
            $contents = $this->readRegularFile($lastGood, 'last-known-good config');
            GatewayProjectStateFilesystem::atomicWrite(
                $this->activeConfig,
                $contents,
                0600,
            );
            $activeExists = true;
        }

        foreach ([...$rollbacks, ...$temporaries] as $artifact) {
            $current = @\lstat($artifact['path']);
            if (!\is_array($current)
                || !$this->sameStableState($artifact['identity'], $current)
            ) {
                throw new \RuntimeException(
                    \ucfirst($this->scope) . ' orphan rollback changed before recovery.',
                );
            }
        }
        if ($rollbacks !== []) {
            $rollback = $rollbacks[0];
            $contents = $this->readRegularFile($rollback['path'], 'rollback');
            if ($activeExists) {
                GatewayProjectStateFilesystem::atomicWrite(
                    $this->lastGoodPath(),
                    $contents,
                    0600,
                );
            } else {
                GatewayProjectStateFilesystem::atomicWrite(
                    $this->activeConfig,
                    $contents,
                    0600,
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $rollback['path'],
                \ucfirst($this->scope) . ' orphan rollback config',
                $rollback['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect ' . $this->scope . ' orphan rollback config.',
                );
            }
        }
        foreach ($temporaries as $temporary) {
            if (!GatewayProjectStateFilesystem::removeRegular(
                $temporary['path'],
                \ucfirst($this->scope) . ' orphan rollback temporary',
                $temporary['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect ' . $this->scope . ' orphan rollback temporary.',
                );
            }
        }
    }

    /**
     * Collect only exact config staging leaves while the caller holds the
     * lifecycle namespace lock. Discovery and validation complete before the
     * first removal so one unsafe or malformed reserved leaf preserves the
     * complete crash evidence set.
     */
    private function cleanupOrphanStagingArtifacts(): void
    {
        $directory = \dirname($this->activeConfig);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' candidate recovery directory is unsafe.',
            );
        }
        $stream = @\opendir($directory);
        if (!\is_resource($stream)) {
            throw new \RuntimeException(
                'Unable to enumerate ' . $this->scope . ' candidate recovery directory.',
            );
        }
        $activeLeaf = \basename($this->activeConfig);
        $reservedPatterns = [
            [
                'prefix' => $activeLeaf . '.candidate.',
                'suffix' => '/\A[1-9][0-9]{0,9}\.[a-f0-9]{8}'
                    . '(?:\.tmp-[a-f0-9]{24})?\z/D',
            ],
            [
                'prefix' => $activeLeaf . '.rejected.',
                'suffix' => '/\A[1-9][0-9]{0,9}\.[a-f0-9]{8}'
                    . '(?:\.tmp-[a-f0-9]{24})?\z/D',
            ],
        ];
        $rawEntries = 0;
        /** @var list<array{path:string,identity:array<string|int,mixed>}> $artifacts */
        $artifacts = [];
        try {
            while (($leaf = \readdir($stream)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' candidate recovery directory quota is exhausted.',
                    );
                }
                $matched = false;
                foreach ($reservedPatterns as $pattern) {
                    $prefix = $pattern['prefix'];
                    $reserved = \str_starts_with($leaf, $prefix)
                        || (\PHP_OS_FAMILY === 'Windows'
                            && \strncasecmp($leaf, $prefix, \strlen($prefix)) === 0);
                    if (!$reserved) {
                        continue;
                    }
                    $suffix = \substr($leaf, \strlen($prefix));
                    if (!\str_starts_with($leaf, $prefix)
                        || \preg_match($pattern['suffix'], $suffix) !== 1
                    ) {
                        throw new \RuntimeException(
                            \ucfirst($this->scope)
                                . ' candidate recovery found a malformed reserved leaf.',
                        );
                    }
                    $matched = true;
                    break;
                }
                if (!$matched) {
                    continue;
                }
                if (\count($artifacts) >= self::MAX_ORPHAN_STAGING_ARTIFACTS) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' orphan candidate quota is exhausted.',
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                GatewayProjectStateFilesystem::read(
                    $path,
                    self::MAX_CONFIG_BYTES,
                    \ucfirst($this->scope) . ' orphan candidate config',
                    true,
                );
                $identity = @\lstat($path);
                if (!\is_array($identity)) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' orphan candidate disappeared during discovery.',
                    );
                }
                $artifacts[] = ['path' => $path, 'identity' => $identity];
            }
        } finally {
            @\closedir($stream);
        }
        $directoryAfterDiscovery = @\lstat($directory);
        if (!\is_array($directoryAfterDiscovery)
            || !$this->sameStableState($directoryBefore, $directoryAfterDiscovery)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' candidate recovery directory changed during discovery.',
            );
        }

        // Revalidate the complete candidate closure immediately before the
        // first deletion. removeRegular receives the selected identity too,
        // so a later per-file swap is rejected instead of deleting an alias.
        foreach ($artifacts as $candidate) {
            $current = @\lstat($candidate['path']);
            if (!\is_array($current)
                || !$this->sameStableState($candidate['identity'], $current)
            ) {
                throw new \RuntimeException(
                    \ucfirst($this->scope) . ' orphan candidate changed before cleanup.',
                );
            }
        }
        foreach ($artifacts as $candidate) {
            if (!GatewayProjectStateFilesystem::removeRegular(
                $candidate['path'],
                \ucfirst($this->scope) . ' orphan candidate config',
                $candidate['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect ' . $this->scope . ' orphan candidate config.',
                );
            }
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameStableState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
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
     * Collect retained Windows replacement backups for the two reusable
     * publication targets. The caller must hold the lifecycle lock shared by
     * every writer and must perform full target-specific config validation.
     * Candidate, rollback and rejected leaves are unique transaction files.
     *
     * @param \Closure(string,string,string):void $validateConfig path, contents, kind
     */
    public function cleanupAtomicWriteRecoveryBackups(\Closure $validateConfig): void
    {
        $targets = [
            [
                'kind' => 'active config',
                'path' => $this->activeConfig,
                'label' => \ucfirst($this->scope) . ' active config',
            ],
            [
                'kind' => 'last-good config',
                'path' => $this->lastGoodPath(),
                'label' => \ucfirst($this->scope) . ' last-good config',
            ],
        ];
        $temporaries = $this->atomicWriteRecoveryTemporaries($targets);
        $retained = [];
        foreach ($targets as $target) {
            $hasBackups = GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $target['path'],
                self::MAX_CONFIG_BYTES,
                $target['label'],
            );
            $targetTemporaries = $temporaries[$target['path']] ?? [];
            if ($hasBackups || $targetTemporaries !== []) {
                $retained[] = [
                    ...$target,
                    'has_backups' => $hasBackups,
                    'temporaries' => $targetTemporaries,
                ];
            }
        }

        $validatedDigests = [];
        foreach ($retained as $target) {
            $contents = GatewayProjectStateFilesystem::read(
                $target['path'],
                self::MAX_CONFIG_BYTES,
                $target['label'] . ' recovery backup paired target',
            );
            $validateConfig($target['path'], $contents, $target['kind']);
            $validatedDigests[$target['path']] = \hash('sha256', $contents);
        }

        // Complete target validation is finished. Revalidate every selected
        // staging identity before deleting any retained artifact.
        foreach ($retained as $target) {
            foreach ($target['temporaries'] as $temporary) {
                $current = @\lstat($temporary['path']);
                if (!\is_array($current)
                    || !$this->sameStableState($temporary['identity'], $current)
                ) {
                    throw new \RuntimeException(
                        $target['label'] . ' atomic temporary changed before cleanup.',
                    );
                }
            }
        }

        foreach ($retained as $target) {
            $expectedDigest = $validatedDigests[$target['path']] ?? '';
            if ($target['has_backups']) {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $target['path'],
                    self::MAX_CONFIG_BYTES,
                    $target['label'],
                    static function (string $contents) use ($expectedDigest): void {
                        if (!\hash_equals(
                            $expectedDigest,
                            \hash('sha256', $contents),
                        )) {
                            throw new \RuntimeException(
                                'Nginx recovery target changed after complete validation.',
                            );
                        }
                    },
                );
            }
            foreach ($target['temporaries'] as $temporary) {
                $targetContents = GatewayProjectStateFilesystem::read(
                    $target['path'],
                    self::MAX_CONFIG_BYTES,
                    $target['label'] . ' atomic temporary paired target',
                );
                if (!\hash_equals(
                    $expectedDigest,
                    \hash('sha256', $targetContents),
                )) {
                    throw new \RuntimeException(
                        'Nginx recovery target changed before temporary cleanup.',
                    );
                }
                if (!GatewayProjectStateFilesystem::removeRegular(
                    $temporary['path'],
                    $target['label'] . ' atomic temporary',
                    $temporary['identity'],
                )) {
                    throw new \RuntimeException(
                        'Unable to collect ' . $target['label'] . ' atomic temporary.',
                    );
                }
            }
        }
    }

    /**
     * Collect atomic-write staging files only after their exact rollback
     * transaction has been resolved. Callers must hold the lifecycle lock.
     */
    public function cleanupResolvedRollbackTemporaries(string $rollback): void
    {
        $transactionId = $this->transactionIdFromRollback($rollback);
        if ($rollback !== $this->rollbackPathForTransaction($transactionId)) {
            throw new \InvalidArgumentException(
                \ucfirst($this->scope) . ' rollback path is outside its config scope.',
            );
        }
        if ($this->pathExistsNoFollow($rollback)) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' rollback transaction has not been resolved.',
            );
        }

        $temporaries = $this->atomicWriteRecoveryTemporaries([
            [
                'kind' => 'rollback config',
                'path' => $rollback,
                'label' => \ucfirst($this->scope) . ' rollback config',
            ],
        ])[$rollback] ?? [];

        // Complete discovery before mutation, then fence both the transaction
        // target and every selected identity against concurrent replacement.
        foreach ($temporaries as $temporary) {
            $current = @\lstat($temporary['path']);
            if (!\is_array($current)
                || !$this->sameStableState($temporary['identity'], $current)
            ) {
                throw new \RuntimeException(
                    \ucfirst($this->scope) . ' rollback atomic temporary changed before cleanup.',
                );
            }
        }
        foreach ($temporaries as $temporary) {
            if ($this->pathExistsNoFollow($rollback)) {
                throw new \RuntimeException(
                    \ucfirst($this->scope) . ' rollback transaction reappeared before cleanup.',
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $temporary['path'],
                \ucfirst($this->scope) . ' rollback atomic temporary',
                $temporary['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to collect ' . $this->scope . ' rollback atomic temporary.',
                );
            }
        }
    }

    /**
     * @param list<array{kind:string,path:string,label:string}> $targets
     * @return array<string,list<array{path:string,identity:array<string|int,mixed>}>>
     */
    private function atomicWriteRecoveryTemporaries(array $targets): array
    {
        $directory = \dirname($this->activeConfig);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)$directoryBefore['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' atomic temporary directory is unsafe.',
            );
        }
        $stream = @\opendir($directory);
        if (!\is_resource($stream)) {
            throw new \RuntimeException(
                'Unable to enumerate ' . $this->scope . ' atomic temporaries.',
            );
        }
        $result = [];
        $rawEntries = 0;
        $total = 0;
        try {
            while (($leaf = \readdir($stream)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        \ucfirst($this->scope) . ' atomic temporary directory quota is exhausted.',
                    );
                }
                foreach ($targets as $target) {
                    $prefix = \basename($target['path']) . '.tmp-';
                    $reserved = \str_starts_with($leaf, $prefix)
                        || (\PHP_OS_FAMILY === 'Windows'
                            && \strncasecmp($leaf, $prefix, \strlen($prefix)) === 0);
                    if (!$reserved) {
                        continue;
                    }
                    $suffix = \substr($leaf, \strlen($prefix));
                    if (!\str_starts_with($leaf, $prefix)
                        || \preg_match('/\A[a-f0-9]{24}\z/D', $suffix) !== 1
                    ) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary reserved leaf is malformed.',
                        );
                    }
                    if (\count($result[$target['path']] ?? [])
                            >= self::MAX_ATOMIC_TEMPORARIES_PER_TARGET
                        || ++$total > self::MAX_ATOMIC_TEMPORARIES_PER_DIRECTORY
                    ) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary quota is exhausted.',
                        );
                    }
                    $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                    GatewayProjectStateFilesystem::read(
                        $path,
                        self::MAX_CONFIG_BYTES,
                        $target['label'] . ' atomic temporary',
                        true,
                    );
                    $identity = @\lstat($path);
                    if (!\is_array($identity)) {
                        throw new \RuntimeException(
                            $target['label'] . ' atomic temporary disappeared during discovery.',
                        );
                    }
                    $result[$target['path']][] = [
                        'path' => $path,
                        'identity' => $identity,
                    ];
                    continue 2;
                }
            }
        } finally {
            @\closedir($stream);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameStableState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' atomic temporary directory changed during discovery.',
            );
        }
        return $result;
    }

    public function rollbackPathForTransaction(string $transactionId): string
    {
        $this->assertTransactionId($transactionId);
        return $this->activeConfig . '.rollback.'
            . \strtolower(\trim($transactionId));
    }

    public function commitPublished(?string $rollback): bool
    {
        if ($rollback === null) {
            return true;
        }
        try {
            $transactionId = $this->transactionIdFromRollback($rollback);
            if ($rollback !== $this->rollbackPathForTransaction($transactionId)) {
                return false;
            }
            $contents = $this->readRegularFile($rollback, 'rollback');
            $this->replaceFile($this->lastGoodPath(), $contents, 0600);
            if (!GatewayProjectStateFilesystem::removeRegular(
                $rollback,
                \ucfirst($this->scope) . ' committed rollback config',
            )) {
                return false;
            }
            $this->cleanupResolvedRollbackTemporaries($rollback);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function discardCandidate(string $candidate): void
    {
        $this->assertCandidatePath($candidate);
        if (\file_exists($candidate) || \is_link($candidate)) {
            GatewayProjectStateFilesystem::removeRegular(
                $candidate,
                \ucfirst($this->scope) . ' discarded candidate config',
            );
        }
    }

    public function candidatePath(): string
    {
        return $this->activeConfig
            . '.candidate.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
    }

    private function assertCandidatePath(string $candidate): void
    {
        $prefix = $this->activeConfig . '.candidate.';
        if (\dirname($candidate) !== \dirname($this->activeConfig)
            || !\str_starts_with($candidate, $prefix)
            || \preg_match(
                '/\A[1-9][0-9]{0,9}\.[a-f0-9]{8}\z/D',
                \substr($candidate, \strlen($prefix)),
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                \ucfirst($this->scope) . ' candidate path is outside the isolated config scope.'
            );
        }
    }

    private function assertTransactionId(string $transactionId): void
    {
        if (\preg_match('/\A[a-f0-9]{32}\z/D', \strtolower(\trim($transactionId))) !== 1) {
            throw new \InvalidArgumentException(\ucfirst($this->scope) . ' transaction id is invalid.');
        }
    }

    private function transactionIdFromRollback(string $rollback): string
    {
        $prefix = $this->activeConfig . '.rollback.';
        if (\dirname($rollback) !== \dirname($this->activeConfig)
            || !\str_starts_with($rollback, $prefix)
        ) {
            throw new \InvalidArgumentException($this->scope . ' rollback path is invalid.');
        }
        $transactionId = \substr($rollback, \strlen($prefix));
        $this->assertTransactionId($transactionId);
        return $transactionId;
    }

    private function assertRegularFile(string $file, string $kind): void
    {
        // Reuse the sealed state-file reader so interrupted recovery only
        // continues against a regular, size-bounded config path.
        $this->readRegularFile($file, $kind);
    }

    private function readRegularFile(string $file, string $kind): string
    {
        return GatewayProjectStateFilesystem::read(
            $file,
            self::MAX_CONFIG_BYTES,
            \ucfirst($this->scope) . ' ' . $kind,
        );
    }

    private function fileHasExactDigest(string $file, string $digest, string $kind): bool
    {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return false;
        }
        try {
            $contents = $this->readRegularFile($file, $kind);
        } catch (\Throwable) {
            return false;
        }

        return \hash_equals($digest, \hash('sha256', $contents));
    }

    private function reconcileWriteAfterImageDurability(
        string $file,
        string $digest,
        string $kind,
        \Throwable $original,
    ): void {
        if (!$this->fileHasExactDigest($file, $digest, $kind)) {
            throw $original;
        }
        try {
            GatewayProjectStateFilesystem::syncDirectory(\dirname($file));
        } catch (\Throwable $syncFailure) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' ' . $kind
                    . ' is exact but its directory durability remains unproven: '
                    . $syncFailure->getMessage(),
                0,
                $original,
            );
        }
        if (!$this->fileHasExactDigest($file, $digest, $kind)) {
            throw new \RuntimeException(
                \ucfirst($this->scope) . ' ' . $kind
                    . ' changed while directory durability was reconciled.',
                0,
                $original,
            );
        }
    }

    private function reconcileRemovalAfterImageDurability(
        string $candidate,
        string $activeDigest,
        \Throwable $original,
    ): void {
        if ($this->pathExistsNoFollow($candidate)
            || !$this->fileHasExactDigest(
                $this->activeConfig,
                $activeDigest,
                'active config replacement after-image',
            )
        ) {
            throw $original;
        }
        try {
            GatewayProjectStateFilesystem::syncDirectory(\dirname($candidate));
        } catch (\Throwable $syncFailure) {
            throw new \RuntimeException(
                \ucfirst($this->scope)
                    . ' replacement candidate is absent but directory durability remains unproven: '
                    . $syncFailure->getMessage(),
                0,
                $original,
            );
        }
        if ($this->pathExistsNoFollow($candidate)
            || !$this->fileHasExactDigest(
                $this->activeConfig,
                $activeDigest,
                'active config replacement after-image',
            )
        ) {
            throw new \RuntimeException(
                \ucfirst($this->scope)
                    . ' replacement after-image changed during directory reconciliation.',
                0,
                $original,
            );
        }
    }

    /** @phpstan-impure */
    private function pathExistsNoFollow(string $path): bool
    {
        \clearstatcache(true, $path);
        return @\lstat($path) !== false;
    }

    private function lastGoodPath(): string
    {
        return $this->activeConfig . '.last-good';
    }

    private function writeNewFile(string $file, string $contents, int $mode): void
    {
        if (\file_exists($file) || \is_link($file)) {
            throw new \RuntimeException('Refusing to overwrite an existing ' . $this->scope . ' staging file.');
        }
        GatewayProjectStateFilesystem::atomicWrite($file, $contents, $mode);
    }

    private function replaceFile(string $target, string $contents, int $mode): void
    {
        GatewayProjectStateFilesystem::atomicWrite($target, $contents, $mode);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1;
        }
        return \str_starts_with($path, '/');
    }

    private function samePath(string $left, string $right): bool
    {
        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $left = \strtolower($left);
            $right = \strtolower($right);
        }
        return \hash_equals($left, $right);
    }
}
