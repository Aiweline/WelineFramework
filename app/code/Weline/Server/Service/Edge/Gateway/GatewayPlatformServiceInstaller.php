<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Installs the host-level service definition that owns the stable launcher.
 *
 * Rendering and activation are deliberately separate so a package can be
 * verified before the active-slot pointer changes.
 */
final class GatewayPlatformServiceInstaller
{
    public const SERVICE_NAME = 'weline-wls-gateway-v2';
    private const WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS = 75.0;
    private const WINDOWS_SERVICE_POLL_MICROSECONDS = 100_000;
    private const WINDOWS_SERVICE_QUERY_TIMEOUT_SECONDS = 5.0;
    private const MIN_COMMAND_TIMEOUT_SECONDS = 0.1;
    private const PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES = 2_000_000;
    private const PLATFORM_ATOMIC_RECOVERY_ENTRY_QUOTA = 16_384;
    private const PLATFORM_ATOMIC_RECOVERY_KIND_QUOTA = 8;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly ?string $templateDirectory = null,
    ) {
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    public function installDefinition(string $profile): array
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException('Gateway service profile must be default or ipv4-only.');
        }
        $this->paths->ensureDirectories();
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        return $this->withPackageInstallLock(
            fn (?array $recovered): array => $this->installDefinitionLocked(
                $profile,
                $recovered,
            ),
        );
    }

    /**
     * @template TResult
     * @param \Closure(array{operation:string,to_profile:string}|null):TResult $callback
     * @return TResult
     */
    private function withPackageInstallLock(\Closure $callback): mixed
    {
        $this->paths->ensureDirectories();
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'package-install.lock',
            function () use ($callback): mixed {
                $recovered = $this->recoverPlatformDefinitionTransaction();
                $this->cleanupPlatformAtomicRecoveryBackups();
                return $callback($recovered);
            },
        );
    }

    private function cleanupPlatformAtomicRecoveryBackups(): void
    {
        $specifications = [
            $this->paths->platformServiceMetadataFile() => [
                'maximum' => 16_384,
                'label' => 'WLS Gateway platform service metadata',
            ],
            $this->paths->serviceDefinitionFile() => [
                'maximum' => 1_048_576,
                'label' => 'WLS Gateway platform service definition',
            ],
            $this->platformRemovalPendingFile() => [
                'maximum' => 1024,
                'label' => 'WLS Gateway platform removal fence',
            ],
            $this->platformDefinitionTransactionFile() => [
                'maximum' => self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'label' => 'WLS Gateway platform definition transaction',
            ],
        ];
        $recoveries = [];
        foreach ($specifications as $path => $specification) {
            if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $path,
                (int)$specification['maximum'],
                (string)$specification['label'],
            )) {
                $recoveries[$path] = $specification;
            }
        }

        // Pre-validate the complete transaction recovery set. No prior
        // generation is removed when one paired platform target is missing or
        // damaged.
        foreach ($recoveries as $path => $specification) {
            $raw = GatewayProjectStateFilesystem::read(
                $path,
                (int)$specification['maximum'],
                (string)$specification['label'] . ' recovery backup paired target',
            );
            $this->validatePlatformRecoveryTarget($path, $raw);
        }
        foreach ($recoveries as $path => $specification) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $path,
                (int)$specification['maximum'],
                (string)$specification['label'],
                function (string $raw) use ($path): void {
                    $this->validatePlatformRecoveryTarget($path, $raw);
                },
            );
        }
    }

    private function validatePlatformRecoveryTarget(string $path, string $raw): void
    {
        if (\hash_equals($this->paths->platformServiceMetadataFile(), $path)) {
            $this->decodePlatformServiceMetadata($raw);
            return;
        }
        if (\hash_equals($this->platformDefinitionTransactionFile(), $path)) {
            $this->decodePlatformDefinitionTransaction($raw);
            return;
        }
        if (\hash_equals($this->paths->serviceDefinitionFile(), $path)) {
            $metadataRaw = GatewayProjectStateFilesystem::read(
                $this->paths->platformServiceMetadataFile(),
                16_384,
                'WLS Gateway platform metadata for definition recovery',
            );
            $metadata = $this->decodePlatformServiceMetadata($metadataRaw);
            $expected = $this->renderDefinition((string)$metadata['profile']);
            if (!\hash_equals($expected, $raw)) {
                throw new \RuntimeException(
                    'WLS Gateway platform definition recovery target is not bound to installed metadata.'
                );
            }
            return;
        }
        $expectedKind = $this->paths->isTestMode()
            ? 'test-session'
            : match (\PHP_OS_FAMILY) {
                'Darwin' => 'launchd-system',
                'Linux' => 'systemd-system',
                'Windows' => 'windows-service',
                default => '',
            };
        $matches = [];
        if (\hash_equals($this->platformRemovalPendingFile(), $path)
            && \preg_match(
                '/\AWLS-PLATFORM-REMOVAL\/1\n'
                    . 'kind=(test-session|launchd-system|systemd-system|windows-service)\n'
                    . 'at=[1-9][0-9]{0,18}\n'
                    . 'nonce=[a-f0-9]{32}\n\z/D',
                $raw,
                $matches,
            ) === 1
            && \hash_equals($expectedKind, (string)($matches[1] ?? ''))
        ) {
            return;
        }
        throw new \RuntimeException(
            'WLS Gateway platform recovery target is malformed or unsupported.'
        );
    }

    /** @return array<string,mixed> */
    private function decodePlatformServiceMetadata(string $raw): array
    {
        $decoded = \json_decode($raw, true);
        $expected = [
            'schema_version',
            'kind',
            'definition',
            'profile',
            'test_mode',
            'installed_at',
        ];
        $actual = \is_array($decoded) ? \array_keys($decoded) : [];
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        $expectedKind = $this->paths->isTestMode()
            ? 'test-session'
            : match (\PHP_OS_FAMILY) {
                'Darwin' => 'launchd-system',
                'Linux' => 'systemd-system',
                'Windows' => 'windows-service',
                default => '',
            };
        if (!\is_array($decoded)
            || $actual !== $expected
            || ($decoded['schema_version'] ?? null) !== 1
            || !\hash_equals($expectedKind, (string)($decoded['kind'] ?? ''))
            || !\hash_equals(
                $this->paths->serviceDefinitionFile(),
                (string)($decoded['definition'] ?? ''),
            )
            || !\in_array((string)($decoded['profile'] ?? ''), [
                'default',
                'ipv4-only',
            ], true)
            || ($decoded['test_mode'] ?? null) !== $this->paths->isTestMode()
            || !\is_string($decoded['installed_at'] ?? null)
            || \strlen((string)$decoded['installed_at']) > 128
            || \strtotime((string)$decoded['installed_at']) === false
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform service metadata recovery target is invalid.'
            );
        }
        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function newPlatformDefinitionTransaction(
        string $operation,
        ?string $fromProfile,
        string $toProfile,
        ?string $oldDefinition,
        ?string $oldMetadata,
        string $newDefinition,
        string $newMetadata,
    ): array {
        if (!\hash_equals($this->renderDefinition($toProfile), $newDefinition)) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction cannot stage an unrendered definition.'
            );
        }
        $journal = [
            'schema_version' => 1,
            'operation' => $operation,
            'phase' => 'prepared',
            'nonce' => \bin2hex(\random_bytes(16)),
            'from_profile' => $fromProfile,
            'to_profile' => $toProfile,
            'old_definition_sha256' => $oldDefinition === null
                ? null
                : \hash('sha256', $oldDefinition),
            'old_metadata_sha256' => $oldMetadata === null
                ? null
                : \hash('sha256', $oldMetadata),
            'new_definition_sha256' => \hash('sha256', $newDefinition),
            'new_metadata_sha256' => \hash('sha256', $newMetadata),
            'new_definition_base64' => \base64_encode($newDefinition),
            'new_metadata_base64' => \base64_encode($newMetadata),
        ];
        return $this->decodePlatformDefinitionTransaction(
            $this->encodePlatformDefinitionTransaction($journal),
        );
    }

    /** @param array<string,mixed> $journal */
    private function encodePlatformDefinitionTransaction(array $journal): string
    {
        return \json_encode(
            [
                'schema_version' => $journal['schema_version'] ?? null,
                'operation' => $journal['operation'] ?? null,
                'phase' => $journal['phase'] ?? null,
                'nonce' => $journal['nonce'] ?? null,
                'from_profile' => $journal['from_profile'] ?? null,
                'to_profile' => $journal['to_profile'] ?? null,
                'old_definition_sha256' => $journal['old_definition_sha256'] ?? null,
                'old_metadata_sha256' => $journal['old_metadata_sha256'] ?? null,
                'new_definition_sha256' => $journal['new_definition_sha256'] ?? null,
                'new_metadata_sha256' => $journal['new_metadata_sha256'] ?? null,
                'new_definition_base64' => $journal['new_definition_base64'] ?? null,
                'new_metadata_base64' => $journal['new_metadata_base64'] ?? null,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /** @return array<string,mixed> */
    private function decodePlatformDefinitionTransaction(string $raw): array
    {
        $decoded = \json_decode($raw, true);
        $expectedKeys = [
            'schema_version',
            'operation',
            'phase',
            'nonce',
            'from_profile',
            'to_profile',
            'old_definition_sha256',
            'old_metadata_sha256',
            'new_definition_sha256',
            'new_metadata_sha256',
            'new_definition_base64',
            'new_metadata_base64',
        ];
        $actualKeys = \is_array($decoded) ? \array_keys($decoded) : [];
        \sort($expectedKeys, SORT_STRING);
        \sort($actualKeys, SORT_STRING);
        if (!\is_array($decoded)
            || $actualKeys !== $expectedKeys
            || ($decoded['schema_version'] ?? null) !== 1
            || !\in_array((string)($decoded['operation'] ?? ''), [
                'install',
                'refresh',
            ], true)
            || !\in_array((string)($decoded['phase'] ?? ''), [
                'prepared',
                'definition_published',
                'metadata_published',
            ], true)
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($decoded['nonce'] ?? ''),
            ) !== 1
            || !\in_array((string)($decoded['to_profile'] ?? ''), [
                'default',
                'ipv4-only',
            ], true)
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction journal is malformed.'
            );
        }
        $operation = (string)$decoded['operation'];
        $fromProfile = $decoded['from_profile'] ?? null;
        $oldDefinitionDigest = $decoded['old_definition_sha256'] ?? null;
        $oldMetadataDigest = $decoded['old_metadata_sha256'] ?? null;
        if (($operation === 'install'
                && ($fromProfile !== null
                    || $oldDefinitionDigest !== null
                    || $oldMetadataDigest !== null))
            || ($operation === 'refresh'
                && (!\in_array((string)$fromProfile, [
                    'default',
                    'ipv4-only',
                ], true)
                    || \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)$oldDefinitionDigest,
                    ) !== 1
                    || \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)$oldMetadataDigest,
                    ) !== 1))
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction origin is invalid.'
            );
        }
        foreach ([
            'new_definition_sha256',
            'new_metadata_sha256',
        ] as $digestKey) {
            if (\preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($decoded[$digestKey] ?? ''),
            ) !== 1) {
                throw new \RuntimeException(
                    'WLS Gateway platform definition transaction digest is invalid.'
                );
            }
        }
        $newDefinition = \base64_decode(
            (string)($decoded['new_definition_base64'] ?? ''),
            true,
        );
        $newMetadata = \base64_decode(
            (string)($decoded['new_metadata_base64'] ?? ''),
            true,
        );
        if (!\is_string($newDefinition)
            || $newDefinition === ''
            || \strlen($newDefinition) > 1_048_576
            || !\is_string($newMetadata)
            || $newMetadata === ''
            || \strlen($newMetadata) > 16_384
            || !\hash_equals(
                (string)$decoded['new_definition_sha256'],
                \hash('sha256', $newDefinition),
            )
            || !\hash_equals(
                (string)$decoded['new_metadata_sha256'],
                \hash('sha256', $newMetadata),
            )
            || !\hash_equals(
                $this->renderDefinition((string)$decoded['to_profile']),
                $newDefinition,
            )
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction after-image is invalid.'
            );
        }
        $metadata = $this->decodePlatformServiceMetadata($newMetadata);
        if (!\hash_equals(
            (string)$decoded['to_profile'],
            (string)$metadata['profile'],
        )) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction metadata profile is invalid.'
            );
        }
        $decoded['new_definition'] = $newDefinition;
        $decoded['new_metadata'] = $newMetadata;
        return $decoded;
    }

    /**
     * @return array{operation:string,to_profile:string}|null
     */
    private function recoverPlatformDefinitionTransaction(): ?array
    {
        $journalPath = $this->platformDefinitionTransactionFile();
        $inventories = $this->preflightPlatformAtomicRecoveryInventories();
        $journalStatus = @\lstat($journalPath);
        if (!\is_array($journalStatus)) {
            if (\file_exists($journalPath) || \is_link($journalPath)) {
                throw new \RuntimeException(
                    'WLS Gateway platform definition transaction path is indeterminate.'
                );
            }
            $journalArtifacts = $inventories[$journalPath] ?? [];
            if ($journalArtifacts === []) {
                return null;
            }
            $this->restoreMissingPlatformDefinitionTransaction(
                $inventories,
                $journalArtifacts,
            );
            $inventories = $this->preflightPlatformAtomicRecoveryInventories();
        }

        $journalRaw = $this->readStableRegularFile(
            $journalPath,
            self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
            'WLS Gateway platform definition transaction',
        );
        $journal = $this->decodePlatformDefinitionTransaction($journalRaw);
        $this->assertPlatformDefinitionTransactionTargetsInClosure($journal);
        if (($inventories[$journalPath] ?? []) !== []) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $journalPath,
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
                fn (string $raw): array => $this->decodePlatformDefinitionTransaction($raw),
            );
            $journalRaw = $this->readStableRegularFile(
                $journalPath,
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
            );
            $journal = $this->decodePlatformDefinitionTransaction($journalRaw);
        }

        $this->cleanupPlatformDefinitionTransactionTargetArtifacts($journal);
        $definitionState = $this->platformDefinitionTransactionTargetState(
            $this->paths->serviceDefinitionFile(),
            $journal['old_definition_sha256'],
            (string)$journal['new_definition_sha256'],
            (string)$journal['new_definition'],
            1_048_576,
            'definition',
            $journal,
        );
        $metadataState = $this->platformDefinitionTransactionTargetState(
            $this->paths->platformServiceMetadataFile(),
            $journal['old_metadata_sha256'],
            (string)$journal['new_metadata_sha256'],
            (string)$journal['new_metadata'],
            16_384,
            'metadata',
            $journal,
        );

        if ($definitionState === 'old') {
            $this->atomicWrite(
                $this->paths->serviceDefinitionFile(),
                (string)$journal['new_definition'],
                $this->paths->isTestMode() ? 0600 : 0644,
            );
        }
        if (!\hash_equals('definition_published', (string)$journal['phase'])
            && !\hash_equals('metadata_published', (string)$journal['phase'])
        ) {
            $journal = $this->advancePlatformDefinitionTransaction(
                $journal,
                'definition_published',
            );
        }
        if ($metadataState === 'old') {
            $this->atomicWrite(
                $this->paths->platformServiceMetadataFile(),
                (string)$journal['new_metadata'],
                0600,
            );
        }
        if (!\hash_equals('metadata_published', (string)$journal['phase'])) {
            $journal = $this->advancePlatformDefinitionTransaction(
                $journal,
                'metadata_published',
            );
        }

        foreach ([
            [
                $this->paths->serviceDefinitionFile(),
                $journal['old_definition_sha256'],
                (string)$journal['new_definition_sha256'],
                (string)$journal['new_definition'],
                1_048_576,
                'definition',
            ],
            [
                $this->paths->platformServiceMetadataFile(),
                $journal['old_metadata_sha256'],
                (string)$journal['new_metadata_sha256'],
                (string)$journal['new_metadata'],
                16_384,
                'metadata',
            ],
        ] as [$path, $oldDigest, $newDigest, $newRaw, $maximum, $label]) {
            if ($this->platformDefinitionTransactionTargetState(
                (string)$path,
                $oldDigest,
                (string)$newDigest,
                (string)$newRaw,
                (int)$maximum,
                (string)$label,
                $journal,
            ) !== 'new') {
                throw new \RuntimeException(
                    'WLS Gateway platform definition transaction did not reach its after-image.'
                );
            }
        }
        if (\hash_equals('install', (string)$journal['operation'])
            && !$this->paths->isTestMode()
            && \PHP_OS_FAMILY !== 'Windows'
        ) {
            // The journal remains authoritative until the privilege boundary
            // is sealed. A crash during this pass therefore retries instead
            // of exposing an apparently complete but untrusted installation.
            $this->ensureServiceIdentityAndPermissions();
        }
        $this->cleanupPlatformDefinitionTransactionTargetArtifacts($journal);
        $this->removePlatformDefinitionTransaction($journal);
        return [
            'operation' => (string)$journal['operation'],
            'to_profile' => (string)$journal['to_profile'],
        ];
    }

    /** @param array<string,mixed> $journal */
    private function publishPlatformDefinitionTransaction(array $journal): void
    {
        $path = $this->platformDefinitionTransactionFile();
        $inventories = $this->preflightPlatformAtomicRecoveryInventories();
        foreach ($inventories as $artifacts) {
            if ($artifacts !== []) {
                throw new \RuntimeException(
                    'WLS Gateway platform definition transaction cannot start over unresolved atomic recovery evidence.'
                );
            }
        }
        if (@\lstat($path) !== false || \file_exists($path) || \is_link($path)) {
            throw new \RuntimeException(
                'A WLS Gateway platform definition transaction is already active.'
            );
        }
        $this->atomicWrite(
            $path,
            $this->encodePlatformDefinitionTransaction($journal),
            0600,
        );
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function advancePlatformDefinitionTransaction(
        array $journal,
        string $phase,
    ): array {
        $path = $this->platformDefinitionTransactionFile();
        $this->preflightPlatformAtomicRecoveryInventories();
        $this->assertPlatformDefinitionTransactionTargetsInClosure($journal);
        if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $path,
            self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
            'WLS Gateway platform definition transaction',
        )) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $path,
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
                fn (string $raw): array => $this->decodePlatformDefinitionTransaction($raw),
            );
        }
        $current = $this->decodePlatformDefinitionTransaction(
            $this->readStableRegularFile(
                $path,
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
            ),
        );
        if (!\hash_equals((string)$journal['nonce'], (string)$current['nonce'])) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction identity changed.'
            );
        }
        $current['phase'] = $phase;
        $this->atomicWrite(
            $path,
            $this->encodePlatformDefinitionTransaction($current),
            0600,
        );
        return $current;
    }

    /** @param array<string,mixed> $journal */
    private function removePlatformDefinitionTransaction(array $journal): void
    {
        $path = $this->platformDefinitionTransactionFile();
        $this->preflightPlatformAtomicRecoveryInventories();
        $this->assertPlatformDefinitionTransactionTargetsInClosure($journal);
        if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $path,
            self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
            'WLS Gateway platform definition transaction',
        )) {
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $path,
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
                fn (string $raw): array => $this->decodePlatformDefinitionTransaction($raw),
            );
        }
        $currentRaw = $this->readStableRegularFile(
            $path,
            self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
            'WLS Gateway platform definition transaction',
        );
        $current = $this->decodePlatformDefinitionTransaction($currentRaw);
        if (!\hash_equals((string)$journal['nonce'], (string)$current['nonce'])
            || !\hash_equals('metadata_published', (string)$current['phase'])
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform definition transaction is not removable.'
            );
        }
        $status = @\lstat($path);
        if (!\is_array($status)
            || !GatewayProjectStateFilesystem::removeRegular(
                $path,
                'completed WLS Gateway platform definition transaction',
                $status,
            )
        ) {
            throw new \RuntimeException(
                'Unable to remove the completed WLS Gateway platform definition transaction.'
            );
        }
    }

    /**
     * @return array<string,array<string,array{path:string,kind:string,status:array<string|int,mixed>,contents:string}>>
     */
    private function preflightPlatformAtomicRecoveryInventories(): array
    {
        $specifications = [
            $this->platformDefinitionTransactionFile() => [
                self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                'WLS Gateway platform definition transaction',
            ],
            $this->paths->serviceDefinitionFile() => [
                1_048_576,
                'WLS Gateway platform service definition',
            ],
            $this->paths->platformServiceMetadataFile() => [
                16_384,
                'WLS Gateway platform service metadata',
            ],
            $this->platformRemovalPendingFile() => [
                1024,
                'WLS Gateway platform removal fence',
            ],
        ];
        $inventories = [];
        foreach ($specifications as $target => [$maximumBytes, $label]) {
            $inventories[$target] = $this->inspectPlatformAtomicRecoveryArtifacts(
                $target,
                $maximumBytes,
                $label,
            );
        }
        return $inventories;
    }

    /**
     * @return array<string,array{path:string,kind:string,status:array<string|int,mixed>,contents:string}>
     */
    private function inspectPlatformAtomicRecoveryArtifacts(
        string $target,
        int $maximumBytes,
        string $label,
    ): array {
        $directory = \dirname($target);
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || ((((int)($directoryBefore['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                $label . ' atomic recovery directory is unsafe.'
            );
        }
        $targetLeaf = \basename(\str_replace('\\', '/', $target));
        if ($targetLeaf === '' || $targetLeaf === '.' || $targetLeaf === '..') {
            throw new \RuntimeException($label . ' atomic recovery target is invalid.');
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
                if (++$visited > self::PLATFORM_ATOMIC_RECOVERY_ENTRY_QUOTA) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory exceeds its raw entry quota.'
                    );
                }
                $foldedLeaf = \strtolower($leaf);
                $isBackup = \str_starts_with($foldedLeaf, $foldedBackupPrefix);
                $isStaging = \str_starts_with($foldedLeaf, $foldedStagingPrefix);
                if (!$isBackup && !$isStaging) {
                    continue;
                }
                if (($isBackup && !\str_starts_with($leaf, $backupPrefix))
                    || ($isStaging && !\str_starts_with($leaf, $stagingPrefix))
                ) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory contains a case alias.'
                    );
                }
                if ($isBackup && \preg_match($backupPattern, $leaf) === 1) {
                    $kind = 'backup';
                    ++$backups;
                } elseif ($isStaging
                    && \preg_match($stagingPattern, $leaf) === 1
                ) {
                    $kind = 'staging';
                    ++$staging;
                } else {
                    throw new \RuntimeException(
                        $label . ' atomic recovery directory contains a malformed reserved leaf.'
                    );
                }
                if ($backups > self::PLATFORM_ATOMIC_RECOVERY_KIND_QUOTA
                    || $staging > self::PLATFORM_ATOMIC_RECOVERY_KIND_QUOTA
                ) {
                    throw new \RuntimeException(
                        $label . ' atomic recovery artifact quota is exhausted.'
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                [$contents, $status] = $this->readStablePlatformRecoveryArtifact(
                    $path,
                    $maximumBytes,
                    $label . ' atomic recovery artifact',
                );
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'status' => $status,
                    'contents' => $contents,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameFileState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                $label . ' atomic recovery directory changed during inspection.'
            );
        }
        \ksort($artifacts, SORT_STRING);
        return $artifacts;
    }

    /**
     * @return array{0:string,1:array<string|int,mixed>}
     */
    private function readStablePlatformRecoveryArtifact(
        string $path,
        int $maximumBytes,
        string $label,
    ): array {
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 0
            || (int)($before['size'] ?? -1) > $maximumBytes
        ) {
            throw new \RuntimeException(
                $label . ' is missing, linked, oversized, or special.'
            );
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($opened)
                || !\is_string($contents)
                || \strlen($contents) > $maximumBytes
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileState($before, $opened)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
                || (int)($after['size'] ?? -1) !== \strlen($contents)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return [$contents, $after];
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string,array<string,array{path:string,kind:string,status:array<string|int,mixed>,contents:string}>> $expected
     */
    private function assertPlatformRecoveryInventoriesUnchanged(array $expected): void
    {
        $actual = $this->preflightPlatformAtomicRecoveryInventories();
        if (\array_keys($expected) !== \array_keys($actual)) {
            throw new \RuntimeException(
                'WLS Gateway platform atomic recovery namespace changed.'
            );
        }
        foreach ($expected as $target => $artifacts) {
            $currentArtifacts = $actual[$target] ?? null;
            if (!\is_array($currentArtifacts)
                || \array_keys($artifacts) !== \array_keys($currentArtifacts)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway platform atomic recovery artifact set changed.'
                );
            }
            foreach ($artifacts as $path => $artifact) {
                $current = $currentArtifacts[$path] ?? null;
                if (!\is_array($current)
                    || !\hash_equals($artifact['kind'], (string)$current['kind'])
                    || !\hash_equals(
                        \hash('sha256', $artifact['contents']),
                        \hash('sha256', (string)$current['contents']),
                    )
                    || !$this->sameFileState(
                        $artifact['status'],
                        (array)$current['status'],
                    )
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway platform atomic recovery artifact changed.'
                    );
                }
            }
        }
    }

    /**
     * @param array<string,array<string,array{path:string,kind:string,status:array<string|int,mixed>,contents:string}>> $inventories
     * @param array<string,array{path:string,kind:string,status:array<string|int,mixed>,contents:string}> $journalArtifacts
     */
    private function restoreMissingPlatformDefinitionTransaction(
        array $inventories,
        array $journalArtifacts,
    ): void {
        if (\count($journalArtifacts) !== 1) {
            throw new \RuntimeException(
                'A missing WLS Gateway platform transaction has ambiguous recovery evidence.'
            );
        }
        $artifact = \reset($journalArtifacts);
        if (!\is_array($artifact)
            || !\hash_equals('staging', (string)$artifact['kind'])
        ) {
            throw new \RuntimeException(
                'A missing WLS Gateway platform transaction has no validated staging after-image.'
            );
        }
        $journal = $this->decodePlatformDefinitionTransaction(
            (string)$artifact['contents'],
        );
        $this->assertPlatformDefinitionTransactionTargetsInClosure($journal);
        $this->assertPlatformRecoveryInventoriesUnchanged($inventories);
        $this->promotePlatformDefinitionTransactionStaging(
            (string)$artifact['path'],
            $this->platformDefinitionTransactionFile(),
            (string)$artifact['contents'],
            (array)$artifact['status'],
        );
    }

    /** @param array<string|int,mixed> $expectedStatus */
    private function promotePlatformDefinitionTransactionStaging(
        string $staging,
        string $target,
        string $expectedContents,
        array $expectedStatus,
    ): void {
        $current = @\lstat($staging);
        if (!\is_array($current)
            || !$this->sameRecoveryObjectIdentity($expectedStatus, $current)
            || @\lstat($target) !== false
            || \file_exists($target)
            || \is_link($target)
        ) {
            throw new \RuntimeException(
                'Interrupted platform transaction staging identity changed before promotion.'
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            if (!\extension_loaded('FFI')
                || !\class_exists(\FFI::class)
                || !\function_exists('iconv')
            ) {
                throw new \RuntimeException(
                    'Windows platform transaction staging promotion requires the locked FFI runtime.'
                );
            }
            try {
                $ffi = \FFI::cdef(
                    'typedef int BOOL; typedef unsigned long DWORD;'
                        . ' typedef unsigned short WCHAR;'
                        . ' BOOL MoveFileExW(const WCHAR*, const WCHAR*, DWORD);'
                        . ' DWORD GetLastError(void);',
                    'kernel32.dll',
                );
                $moved = (int)$ffi->MoveFileExW(
                    $this->windowsTransactionWidePath($ffi, $staging),
                    $this->windowsTransactionWidePath($ffi, $target),
                    0x00000008,
                );
                if ($moved === 0) {
                    throw new \RuntimeException(
                        'Windows platform transaction staging promotion failed with error '
                            . (int)$ffi->GetLastError() . '.',
                    );
                }
            } catch (\RuntimeException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Windows platform transaction staging promotion is unavailable.',
                    0,
                    $throwable,
                );
            }
        } elseif (!@\rename($staging, $target)) {
            throw new \RuntimeException(
                'Unable to atomically promote the platform transaction staging file.'
            );
        }
        $published = @\lstat($target);
        if (!\is_array($published)
            || !$this->sameRecoveryObjectIdentity($expectedStatus, $published)
            || @\lstat($staging) !== false
            || \file_exists($staging)
            || \is_link($staging)
            || !\hash_equals(
                $expectedContents,
                $this->readStableRegularFile(
                    $target,
                    self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                    'promoted WLS Gateway platform definition transaction',
                ),
            )
        ) {
            throw new \RuntimeException(
                'Promoted WLS Gateway platform transaction failed identity verification.'
            );
        }
        GatewayProjectStateFilesystem::syncDirectory(\dirname($target));
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameRecoveryObjectIdentity(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    private function windowsTransactionWidePath(\FFI $ffi, string $path): \FFI\CData
    {
        if ($path === '' || \str_contains($path, "\0")) {
            throw new \RuntimeException('Windows platform transaction path is invalid.');
        }
        $encoded = @\iconv('UTF-8', 'UTF-16LE', $path . "\0");
        if (!\is_string($encoded) || $encoded === '' || (\strlen($encoded) % 2) !== 0) {
            throw new \RuntimeException(
                'Windows platform transaction path cannot be encoded safely.'
            );
        }
        $buffer = $ffi->new('WCHAR[' . (int)(\strlen($encoded) / 2) . ']');
        \FFI::memcpy($buffer, $encoded, \strlen($encoded));
        return $buffer;
    }

    /** @param array<string,mixed> $journal */
    private function cleanupPlatformDefinitionTransactionTargetArtifacts(
        array $journal,
    ): void {
        $inventories = $this->preflightPlatformAtomicRecoveryInventories();
        $targets = [
            $this->paths->serviceDefinitionFile() => [
                $journal['old_definition_sha256'],
                (string)$journal['new_definition_sha256'],
                (string)$journal['new_definition'],
                1_048_576,
                'definition',
            ],
            $this->paths->platformServiceMetadataFile() => [
                $journal['old_metadata_sha256'],
                (string)$journal['new_metadata_sha256'],
                (string)$journal['new_metadata'],
                16_384,
                'metadata',
            ],
        ];
        foreach ($targets as $path => [$oldDigest, $newDigest, $newRaw, $maximum, $label]) {
            $this->platformDefinitionTransactionTargetState(
                $path,
                $oldDigest,
                $newDigest,
                $newRaw,
                $maximum,
                $label,
                $journal,
            );
        }
        $this->assertPlatformRecoveryInventoriesUnchanged($inventories);
        foreach ($targets as $path => [$oldDigest, $newDigest, $newRaw, $maximum, $label]) {
            $artifacts = $inventories[$path] ?? [];
            if ($artifacts === []) {
                continue;
            }
            $status = @\lstat($path);
            if (\is_array($status)) {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $path,
                    $maximum,
                    'WLS Gateway platform transaction ' . $label,
                    function (string $raw) use (
                        $oldDigest,
                        $newDigest,
                        $newRaw,
                        $label,
                        $journal,
                    ): void {
                        $this->platformDefinitionTransactionRawState(
                            $raw,
                            $oldDigest,
                            $newDigest,
                            $newRaw,
                            $label,
                            $journal,
                        );
                    },
                );
                $inventories = $this->preflightPlatformAtomicRecoveryInventories();
                continue;
            }
            if ($oldDigest !== null
                || \file_exists($path)
                || \is_link($path)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway platform transaction target is missing or unsafe.'
                );
            }
            foreach ($artifacts as $artifact) {
                if (!\hash_equals('staging', (string)$artifact['kind'])) {
                    throw new \RuntimeException(
                        'A missing WLS Gateway platform transaction target has ambiguous recovery evidence.'
                    );
                }
            }
            $this->assertPlatformRecoveryInventoriesUnchanged($inventories);
            foreach ($artifacts as $artifact) {
                if (@\lstat($path) !== false
                    || \file_exists($path)
                    || \is_link($path)
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway platform transaction target appeared during staging cleanup.'
                    );
                }
                if (!GatewayProjectStateFilesystem::removeRegular(
                    (string)$artifact['path'],
                    'interrupted WLS Gateway platform target staging file',
                    (array)$artifact['status'],
                )) {
                    throw new \RuntimeException(
                        'Unable to collect an interrupted platform target staging file.'
                    );
                }
            }
            $inventories = $this->preflightPlatformAtomicRecoveryInventories();
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertPlatformDefinitionTransactionTargetsInClosure(
        array $journal,
    ): void {
        $this->platformDefinitionTransactionTargetState(
            $this->paths->serviceDefinitionFile(),
            $journal['old_definition_sha256'],
            (string)$journal['new_definition_sha256'],
            (string)$journal['new_definition'],
            1_048_576,
            'definition',
            $journal,
        );
        $this->platformDefinitionTransactionTargetState(
            $this->paths->platformServiceMetadataFile(),
            $journal['old_metadata_sha256'],
            (string)$journal['new_metadata_sha256'],
            (string)$journal['new_metadata'],
            16_384,
            'metadata',
            $journal,
        );
    }

    /**
     * @param string|null $oldDigest
     * @param array<string,mixed> $journal
     */
    private function platformDefinitionTransactionTargetState(
        string $path,
        mixed $oldDigest,
        string $newDigest,
        string $newRaw,
        int $maximumBytes,
        string $label,
        array $journal,
    ): string {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path) || $oldDigest !== null) {
                throw new \RuntimeException(
                    'WLS Gateway platform transaction target ' . $label
                        . ' is missing or unsafe.'
                );
            }
            return 'old';
        }
        try {
            $raw = $this->readStableRegularFile(
                $path,
                $maximumBytes,
                'WLS Gateway platform transaction target ' . $label,
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'WLS Gateway platform transaction target ' . $label
                    . ' is unsafe.',
                0,
                $throwable,
            );
        }
        return $this->platformDefinitionTransactionRawState(
            $raw,
            $oldDigest,
            $newDigest,
            $newRaw,
            $label,
            $journal,
        );
    }

    /** @param array<string,mixed> $journal */
    private function platformDefinitionTransactionRawState(
        string $raw,
        mixed $oldDigest,
        string $newDigest,
        string $newRaw,
        string $label,
        array $journal,
    ): string {
        $digest = \hash('sha256', $raw);
        if (\hash_equals($newDigest, $digest)
            && \hash_equals($newRaw, $raw)
        ) {
            return 'new';
        }
        if (\is_string($oldDigest) && \hash_equals($oldDigest, $digest)) {
            if (\hash_equals('metadata', $label)) {
                $metadata = $this->decodePlatformServiceMetadata($raw);
                if (!\hash_equals(
                    (string)$journal['from_profile'],
                    (string)$metadata['profile'],
                )) {
                    throw new \RuntimeException(
                        'WLS Gateway platform transaction old metadata profile is invalid.'
                    );
                }
            }
            return 'old';
        }
        throw new \RuntimeException(
            'WLS Gateway platform transaction target ' . $label
                . ' matches neither the old nor new generation.'
        );
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    private function installDefinitionLocked(
        string $profile,
        ?array $recoveredTransaction = null,
    ): array
    {
        if (\is_array($recoveredTransaction)
            && \hash_equals('install', (string)$recoveredTransaction['operation'])
        ) {
            if (!\hash_equals(
                $profile,
                (string)$recoveredTransaction['to_profile'],
            )) {
                throw new \RuntimeException(
                    'Recovered WLS Gateway platform installation belongs to another profile.'
                );
            }
            return $this->installedDefinition();
        }
        $path = $this->paths->serviceDefinitionFile();
        $metadataPath = $this->paths->platformServiceMetadataFile();
        $this->assertInitialInstallTargetsAbsent($path, $metadataPath);
        if (!$this->paths->isTestMode()) {
            // A Windows virtual service SID is resolvable only after the SCM
            // service exists. start() creates the disabled service, applies
            // the service SID ACLs, and only then allows it to execute. The
            // Broker later creates a restricted token only for the Controller.
            if (\PHP_OS_FAMILY !== 'Windows') {
                $this->ensureServiceIdentityAndPermissions();
            }
        }

        $kind = match (\PHP_OS_FAMILY) {
            'Darwin' => 'launchd-system',
            'Linux' => 'systemd-system',
            'Windows' => 'windows-service',
            default => throw new \RuntimeException('Unsupported WLS Gateway service platform.'),
        };
        $definition = $this->renderDefinition($profile);
        $metadata = \json_encode([
            'schema_version' => 1,
            'kind' => $this->paths->isTestMode() ? 'test-session' : $kind,
            'definition' => $path,
            'profile' => $profile,
            'test_mode' => $this->paths->isTestMode(),
            'installed_at' => \gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;
        $journal = $this->newPlatformDefinitionTransaction(
            'install',
            null,
            $profile,
            null,
            null,
            $definition,
            $metadata,
        );
        $this->publishPlatformDefinitionTransaction($journal);
        $completed = $this->recoverPlatformDefinitionTransaction();
        if (!\is_array($completed)
            || !\hash_equals('install', (string)$completed['operation'])
            || !\hash_equals($profile, (string)$completed['to_profile'])
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform installation transaction did not complete.'
            );
        }
        return [
            'kind' => $this->paths->isTestMode() ? 'test-session' : $kind,
            'path' => $path,
            'test_mode' => $this->paths->isTestMode(),
        ];
    }

    /**
     * Refresh release-owned platform policy without traversing the live,
     * controller-writable state tree. Running controllers continuously create
     * and rename atomic state files, so an installation-time ownership sweep
     * is neither race-free nor appropriate during an A/B upgrade.
     *
     * @return array{kind:string,path:string,test_mode:bool}
     */
    public function refreshDefinition(string $profile): array
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException(
                'Gateway service profile must be default or ipv4-only.'
            );
        }
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        return $this->withPackageInstallLock(function (?array $_recovered) use ($profile): array {
            // Refresh is the one administrator repair path allowed to replace
            // a drifted release-owned definition. Authenticate the metadata
            // first, then publish and re-verify the canonical definition.
            $metadataPath = $this->paths->platformServiceMetadataFile();
            $metadataRaw = GatewayProjectStateFilesystem::read(
                $metadataPath,
                16_384,
                'Installed WLS Gateway platform service metadata',
            );
            $metadata = $this->decodePlatformServiceMetadata($metadataRaw);
            $definitionPath = $this->paths->serviceDefinitionFile();
            $definitionRaw = $this->readStableRegularFile(
                $definitionPath,
                1_048_576,
                'Installed WLS Gateway platform service definition for refresh',
            );
            $newDefinition = $this->renderDefinition($profile);
            $newMetadata = $metadata;
            $newMetadata['profile'] = $profile;
            $newMetadataRaw = \json_encode(
                $newMetadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            if (\hash_equals($definitionRaw, $newDefinition)
                && \hash_equals($metadataRaw, $newMetadataRaw)
            ) {
                return $this->installedDefinition();
            }
            $journal = $this->newPlatformDefinitionTransaction(
                'refresh',
                (string)$metadata['profile'],
                $profile,
                $definitionRaw,
                $metadataRaw,
                $newDefinition,
                $newMetadataRaw,
            );
            $this->publishPlatformDefinitionTransaction($journal);
            $this->recoverPlatformDefinitionTransaction();
            return $this->installedDefinition();
        });
    }

    public function start(string $kind): void
    {
        if ($this->paths->isTestMode()) {
            if (!\hash_equals('test-session', $kind)) {
                throw new \RuntimeException('Test gateway cannot start a production platform service.');
            }
            return;
        }
        $this->assertAdministrator();
        if ($kind === 'launchd-system') {
            $label = 'system/com.weline.wls-gateway-v2';
            $this->runCommand(['/bin/launchctl', 'enable', $label], true);
            $this->runCommand(['/bin/launchctl', 'bootout', $label], true);
            $this->mustRun([
                '/bin/launchctl',
                'bootstrap',
                'system',
                $this->paths->serviceDefinitionFile(),
            ], 'launchd bootstrap');
            $this->mustRun(['/bin/launchctl', 'kickstart', '-k', $label], 'launchd kickstart');
            return;
        }
        if ($kind === 'systemd-system') {
            $this->mustRun(['/bin/systemctl', 'daemon-reload'], 'systemd daemon-reload');
            // A fresh unit has no loaded failed state and reset-failed returns
            // non-zero; an existing crash-loop does have one and is cleared.
            // In both cases enable --now below remains the decisive action.
            $this->runCommand([
                '/bin/systemctl',
                'reset-failed',
                self::SERVICE_NAME . '.service',
            ], true);
            $this->mustRun(
                ['/bin/systemctl', 'enable', '--now', self::SERVICE_NAME . '.service'],
                'systemd service activation',
            );
            return;
        }
        if ($kind === 'windows-service') {
            $this->ensureWindowsServiceStopped(false);
            $this->configureWindowsServiceDefinition(true);
            $this->ensureServiceIdentityAndPermissions();
            $this->enableWindowsServiceDefinition();
            $this->mustRun([$this->windowsSystemExecutable('sc.exe'), 'start', self::SERVICE_NAME], 'Windows service start');
            $this->waitForWindowsServiceState(4);
            return;
        }
        throw new \RuntimeException('Unsupported gateway platform service kind: ' . $kind);
    }

    /**
     * Seal a newly installed immutable A/B slot for the privilege-separated
     * Controller before the active-slot pointer can reference it.
     *
     * Windows slot access is inherited from the protected slots directory and
     * refreshed after the service SID exists. POSIX does not use
     * inherited ACLs here, so every new slot must receive the dedicated
     * controller group explicitly.
     */
    public function secureInstalledRuntimeSlot(string $slotDirectory): void
    {
        if ($this->paths->isTestMode()) {
            return;
        }
        $this->assertAdministrator();
        $resolved = \realpath($slotDirectory);
        $allowed = \array_map(
            static fn (string $slot): string|false => \realpath($slot),
            [
                $this->paths->slotDir('A'),
                $this->paths->slotDir('B'),
            ],
        );
        if (!\is_string($resolved)
            || \is_link($slotDirectory)
            || !\in_array($resolved, $allowed, true)
        ) {
            throw new \RuntimeException(
                'Gateway runtime slot permission target is not an installed A/B slot.'
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $service = $this->queryWindowsService();
            if ($service !== null) {
                try {
                    $installed = $this->installedDefinition();
                } catch (\Throwable $throwable) {
                    throw new \RuntimeException(
                        'An orphan WLS Gateway Windows service exists without trusted metadata.',
                        0,
                        $throwable,
                    );
                }
                if (!\hash_equals('windows-service', (string)$installed['kind'])
                    || !\hash_equals(
                        $this->paths->serviceDefinitionFile(),
                        (string)$installed['path'],
                    )
                ) {
                    throw new \RuntimeException(
                        'The existing WLS Gateway Windows service metadata is invalid.'
                    );
                }
                // Seal only the new immutable slot. Traversing the live state
                // tree during an upgrade would race the Controller, while
                // leaving this slot on inherited ProgramData ACLs would let a
                // local user replace code before the privileged self-test.
                $this->assertWindowsTreeHasNoLinks($resolved);
                $this->applyWindowsAcl(
                    $resolved,
                    'NT SERVICE\\' . self::SERVICE_NAME,
                    'RX',
                );
                return;
            }
            // Before the SCM service exists its virtual SID cannot be
            // resolved. Remove inherited ProgramData access now so the
            // administrator credential created immediately after staging
            // is never exposed; start() later adds the service SID.
            $this->assertWindowsTreeHasNoLinks($this->paths->home());
            $this->applyWindowsAcl(
                $this->paths->home(),
                'NT SERVICE\\' . self::SERVICE_NAME,
                'NONE',
            );
            return;
        }
        $account = \PHP_OS_FAMILY === 'Darwin'
            ? '_welinegateway'
            : 'weline-gateway';
        $identity = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($account)
            : false;
        $groupIdentity = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($account)
            : false;
        if (\is_array($identity)
            && (!\is_array($groupIdentity)
                || !self::posixServiceIdentityIsValid(
                    $identity,
                    $groupIdentity,
                    \PHP_OS_FAMILY,
                ))
        ) {
            throw new \RuntimeException(
                'The existing WLS Gateway controller identity is unsafe.'
            );
        }
        $group = \is_array($identity) ? (int)$identity['gid'] : 0;
        if ($group < 1) {
            // On a fresh host package verification/staging deliberately runs
            // before the platform definition is published. Provision the
            // dedicated identity at this first immutable-slot boundary so
            // initial installation cannot deadlock on its own ordering.
            $this->ensureServiceIdentityAndPermissions();
            $identity = \function_exists('posix_getpwnam')
                ? @\posix_getpwnam($account)
                : false;
            $groupIdentity = \function_exists('posix_getgrnam')
                ? @\posix_getgrnam($account)
                : false;
            if (!\is_array($identity)
                || !\is_array($groupIdentity)
                || !self::posixServiceIdentityIsValid(
                    $identity,
                    $groupIdentity,
                    \PHP_OS_FAMILY,
                )
            ) {
                throw new \RuntimeException(
                    'Dedicated WLS Gateway controller identity is unavailable.'
                );
            }
            $group = (int)$identity['gid'];
        }
        $this->secureRuntimeTree($resolved, $group);
    }

    /**
     * Establish the host trust boundary before opening the installation lock.
     * The Controller never owns this tree; the native Broker/launcher use the
     * LocalSystem/root identity for all mutations below it.
     */
    public function securePackageTransactionTrust(): void
    {
        if ($this->paths->isTestMode()) {
            return;
        }
        $this->assertAdministrator();
        $trust = $this->paths->trustDir();
        $status = @\lstat($trust);
        if (!\is_array($status)
            || \is_link($trust)
            || !\is_dir($trust)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Gateway package trust root is unsafe.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertWindowsTreeHasNoLinks($trust);
            $service = $this->queryWindowsService();
            $serviceRights = 'NONE';
            if ($service !== null) {
                $installed = $this->installedDefinition();
                if (!\hash_equals('windows-service', (string)$installed['kind'])
                    || !\hash_equals(
                        $this->paths->serviceDefinitionFile(),
                        (string)$installed['path'],
                    )
                ) {
                    throw new \RuntimeException(
                        'The existing WLS Gateway Windows service metadata is invalid.'
                    );
                }
                // A running restricted Controller must retain read access to
                // the current fencing token and immutable trust facts, but it
                // never receives directory write access or lock ownership.
                $serviceRights = 'RX';
            }
            $rootOnlyPaths = [];
            foreach ($this->rootOnlyTrustFiles() as $rootOnlyFile) {
                $rootOnlyPath = $trust . DIRECTORY_SEPARATOR . $rootOnlyFile;
                if ($rootOnlyFile === 'package-install.lock'
                    && !\file_exists($rootOnlyPath)
                    && !\is_link($rootOnlyPath)
                ) {
                    $created = @\fopen($rootOnlyPath, 'x+b');
                    if (!\is_resource($created)) {
                        throw new \RuntimeException(
                            'Gateway package lock could not be created securely.'
                        );
                    }
                    @\fclose($created);
                }
                if (\file_exists($rootOnlyPath) || \is_link($rootOnlyPath)) {
                    $rootOnlyStatus = @\lstat($rootOnlyPath);
                    if (!\is_array($rootOnlyStatus)
                        || \is_link($rootOnlyPath)
                        || ((((int)($rootOnlyStatus['mode'] ?? 0)) & 0170000)
                            !== 0100000)
                        || (int)($rootOnlyStatus['nlink'] ?? 0) !== 1
                    ) {
                        throw new \RuntimeException(
                            'Gateway root-only trust file is unsafe: ' . $rootOnlyPath
                        );
                    }
                    $this->applyWindowsAcl(
                        $rootOnlyPath,
                        'NT SERVICE\\' . self::SERVICE_NAME,
                        'NONE',
                    );
                    $rootOnlyPaths[] = $rootOnlyPath;
                }
            }
            // Seal Broker-only files before refreshing the readable trust
            // tree and exclude them from the recursive replacement. This
            // prevents a live restricted Controller from receiving even a
            // transient inherited read ACE during package transactions.
            $this->applyWindowsAcl(
                $trust,
                'NT SERVICE\\' . self::SERVICE_NAME,
                $serviceRights,
                $rootOnlyPaths,
            );
            return;
        }
        if ((int)($status['uid'] ?? -1) !== 0
            || ((((int)($status['mode'] ?? 0)) & 0022) !== 0)
        ) {
            throw new \RuntimeException(
                'Gateway package trust root must be root-owned and non-writable by tenants.'
            );
        }
        $this->assertPosixServiceTreeSafe($trust);
        if (\PHP_OS_FAMILY === 'Darwin') {
            $this->mustRun(
                ['/bin/chmod', '-RN', $trust],
                'macOS gateway package trust ACL reset',
            );
            $status = @\lstat($trust);
        }
        $this->assertPosixTrustTreeOwnership($trust);
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    public function installedDefinition(): array
    {
        return $this->installedDefinitionFromMetadata(true);
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    private function installedDefinitionFromMetadata(bool $requireBoundDefinition): array
    {
        $file = $this->paths->platformServiceMetadataFile();
        \clearstatcache(true, $file);
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'WLS Gateway platform service metadata path is indeterminate.'
                );
            }
            throw new \RuntimeException(
                'WLS Gateway platform service metadata is unavailable.'
            );
        }
        try {
            $decoded = $this->decodePlatformServiceMetadata(
                $this->readStableRegularFile(
                    $file,
                    16_384,
                    'WLS Gateway platform service metadata',
                ),
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'WLS Gateway platform service metadata is invalid.',
                0,
                $throwable,
            );
        }
        if ($requireBoundDefinition) {
            $definition = $this->readStableRegularFile(
                $this->paths->serviceDefinitionFile(),
                1_048_576,
                'WLS Gateway platform service definition',
            );
            if (!\hash_equals(
                $this->renderDefinition((string)$decoded['profile']),
                $definition,
            )) {
                throw new \RuntimeException(
                    'WLS Gateway platform service definition is not bound to installed metadata.'
                );
            }
        }
        return [
            'kind' => (string)$decoded['kind'],
            'path' => (string)$decoded['definition'],
            'test_mode' => ($decoded['test_mode'] ?? false) === true,
        ];
    }

    public function stop(string $kind): void
    {
        if ($this->paths->isTestMode()) {
            if (!\hash_equals('test-session', $kind)) {
                throw new \RuntimeException(
                    'Test gateway cannot stop a production platform service.'
                );
            }
            return;
        }
        $this->assertAdministrator();
        if ($kind === 'launchd-system') {
            $label = 'system/com.weline.wls-gateway-v2';
            $this->runCommand(['/bin/launchctl', 'bootout', $label], true);
            $this->mustRun(
                ['/bin/launchctl', 'disable', $label],
                'launchd persistent disable',
            );
            $this->assertPlatformServiceStopped($kind);
            return;
        }
        if ($kind === 'systemd-system') {
            $this->mustRun([
                '/bin/systemctl',
                'disable',
                '--now',
                self::SERVICE_NAME . '.service',
            ], 'systemd persistent stop');
            $this->assertPlatformServiceStopped($kind);
            return;
        }
        if ($kind === 'windows-service') {
            $service = $this->queryWindowsService();
            if ($service === null) {
                // ERROR_SERVICE_DOES_NOT_EXIST is already the requested
                // persistent stopped state. In particular, do not follow it
                // with `sc config`, which would turn an idempotent stop into
                // a localized 1060 failure.
                return;
            }
            if (self::windowsServiceStateFromQuery($service['output']) !== 1) {
                $this->runCommand([$this->windowsSystemExecutable('sc.exe'), 'stop', self::SERVICE_NAME], true);
                $this->waitForWindowsServiceState(1);
            }
            if ($this->queryWindowsService() === null) {
                // The definition can disappear between query and stop. 1060
                // is already the durable form of a persistent stop.
                return;
            }
            $this->mustRun([
                $this->windowsSystemExecutable('sc.exe'),
                'config',
                self::SERVICE_NAME,
                'start=',
                'disabled',
            ], 'Windows service persistent stop');
            $this->assertPlatformServiceStopped($kind);
            return;
        }
        throw new \RuntimeException(
            'Unsupported gateway platform service kind: ' . $kind
        );
    }

    public function restart(string $kind): void
    {
        if ($this->paths->isTestMode()) {
            if (!\hash_equals('test-session', $kind)) {
                throw new \RuntimeException(
                    'Test gateway cannot restart a production platform service.'
                );
            }
            return;
        }
        $this->assertAdministrator();
        if ($kind === 'launchd-system') {
            $label = 'system/com.weline.wls-gateway-v2';
            $this->runCommand(['/bin/launchctl', 'bootout', $label], true);
            $this->mustRun([
                '/bin/launchctl',
                'bootstrap',
                'system',
                $this->paths->serviceDefinitionFile(),
            ], 'launchd gateway definition reload');
            $this->mustRun(
                ['/bin/launchctl', 'kickstart', '-k', $label],
                'launchd gateway restart',
            );
            return;
        }
        if ($kind === 'systemd-system') {
            $this->mustRun(
                ['/bin/systemctl', 'daemon-reload'],
                'systemd gateway definition reload',
            );
            $this->mustRun([
                '/bin/systemctl',
                'restart',
                self::SERVICE_NAME . '.service',
            ], 'systemd gateway restart');
            return;
        }
        if ($kind === 'windows-service') {
            $this->ensureWindowsServiceStopped(true);
            $this->configureWindowsServiceDefinition(false);
            $this->ensureServiceIdentityAndPermissions();
            $this->enableWindowsServiceDefinition();
            $this->mustRun(
                [$this->windowsSystemExecutable('sc.exe'), 'start', self::SERVICE_NAME],
                'Windows gateway service restart',
            );
            $this->waitForWindowsServiceState(4);
            return;
        }
        throw new \RuntimeException(
            'Unsupported gateway platform service kind: ' . $kind
        );
    }

    public function restartControlPlane(string $kind): void
    {
        if ($this->paths->isTestMode()) {
            if (!\hash_equals('test-session', $kind)) {
                throw new \RuntimeException(
                    'Test gateway cannot reload a production platform service.'
                );
            }
            return;
        }

        // An installed stable launcher can predate the current project
        // runtime. Treating a newer HUP/SCM control code as a mandatory
        // cross-version contract can make that launcher exit cleanly; with a
        // systemd Restart=on-failure policy no MainPID remains, and even the
        // rollback handoff then fails. A full platform restart is the
        // backwards-compatible transaction boundary: it loads the newly
        // sealed launcher and can also start a verified rollback slot when
        // the previous process has already exited.
        $this->restart($kind);
    }

    public function secureInstalledRuntime(): void
    {
        if ($this->paths->isTestMode()) {
            return;
        }
        $this->assertAdministrator();
        if (\PHP_OS_FAMILY === 'Windows') {
            // Initial installation applies ACLs in start(), and upgrades do
            // so inside restart() after the service is stopped. Never sweep a
            // live Controller tree from this compatibility hook.
            return;
        }
        $this->ensureServiceIdentityAndPermissions();
    }

    /**
     * Prepare test fixtures for project identity access. Production enrollment
     * fails closed until the native handle-relative ACL helper can grant only
     * the sanitized backend identity records without a path validation/mutation
     * race. Full instance records and certificate material remain excluded.
     *
     * @return array{applied:bool,test_mode:bool,identities_dir:string,instances_dir:string,service_identity:string}
     */
    public function authorizeProjectRuntimeRead(
        string $projectRoot,
        ?int $ownerUid = null,
        ?int $ownerGid = null,
    ): array {
        $root = \realpath($projectRoot);
        if (!\is_string($root)
            || !\is_dir($root)
            || \is_link($projectRoot)
            || \rtrim($root, '/\\') === ''
        ) {
            throw new \RuntimeException(
                'Unable to authorize an invalid project root for the WLS Gateway.'
            );
        }
        $root = \rtrim($root, '/\\');
        if ($this->paths->isTestMode()) {
            $identities = $this->prepareProjectEndpointDirectory(
                $root,
                $ownerUid,
                $ownerGid,
            );
            return [
                'applied' => false,
                'test_mode' => true,
                'identities_dir' => $identities,
                'instances_dir' => $identities,
                'service_identity' => 'test-session',
            ];
        }

        $this->assertAdministrator();
        throw new \RuntimeException(
            'Project endpoint ACL authorization requires the native handle-relative ACL helper; path-string root ACL mutation is disabled.'
        );
    }

    /**
     * Resolve test fixtures for endpoint revocation. Production revocation
     * fails closed until the native handle-relative ACL helper can mutate the
     * exact object that was validated rather than reopening a path string.
     *
     * @return array{applied:bool,test_mode:bool,identities_dir:string,service_identity:string}
     */
    public function revokeProjectRuntimeRead(string $projectRoot): array
    {
        $root = \realpath($projectRoot);
        if (!\is_string($root)
            || !\is_dir($root)
            || \is_link($projectRoot)
            || \rtrim($root, '/\\') === ''
        ) {
            throw new \RuntimeException(
                'Unable to revoke project endpoint access for an invalid project root.'
            );
        }
        $root = \rtrim($root, '/\\');
        $identities = $this->existingProjectEndpointDirectory($root);
        if ($identities === null) {
            return [
                'applied' => false,
                'test_mode' => $this->paths->isTestMode(),
                'identities_dir' => $root . DIRECTORY_SEPARATOR . 'var'
                    . DIRECTORY_SEPARATOR . 'server'
                    . DIRECTORY_SEPARATOR . 'gateway-identities',
                'service_identity' => '',
            ];
        }
        if ($this->paths->isTestMode()) {
            return [
                'applied' => false,
                'test_mode' => true,
                'identities_dir' => $identities,
                'service_identity' => 'test-session',
            ];
        }

        $this->assertAdministrator();
        throw new \RuntimeException(
            'Project endpoint ACL revocation requires the native handle-relative ACL helper; path-string root ACL mutation is disabled.'
        );
    }

    public function removeDefinition(string $kind): void
    {
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        $this->withPackageInstallLock(function (?array $_recovered) use ($kind): void {
        $pending = $this->platformRemovalPendingFile();
        $existingPending = GatewayProjectStateFilesystem::readOptional(
            $pending,
            1024,
            'WLS Gateway platform removal fence',
        );
        if ($existingPending === null) {
            $this->atomicWrite(
                $pending,
                "WLS-PLATFORM-REMOVAL/1\n"
                    . 'kind=' . $kind . "\n"
                    . 'at=' . \time() . "\n"
                    . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n",
                0600,
            );
        } elseif (\preg_match(
            '/\AWLS-PLATFORM-REMOVAL\/1\nkind=([^\n]+)\n/',
            $existingPending,
            $pendingMatch,
        ) !== 1
            || !\hash_equals($kind, (string)$pendingMatch[1])
        ) {
            throw new \RuntimeException(
                'Existing WLS Gateway platform removal fence belongs to another operation.'
            );
        }
        if (!$this->paths->isTestMode()) {
            if ($kind === 'launchd-system') {
                $label = 'system/com.weline.wls-gateway-v2';
                $this->runCommand(['/bin/launchctl', 'bootout', $label], true);
                $this->runCommand(['/bin/launchctl', 'disable', $label], true);
                $this->assertPlatformServiceStopped($kind);
            } elseif ($kind === 'systemd-system') {
                $this->runCommand([
                    '/bin/systemctl',
                    'disable',
                    '--now',
                    self::SERVICE_NAME . '.service',
                ], true);
                $this->assertPlatformServiceStopped($kind);
            } elseif ($kind === 'windows-service') {
                $service = $this->queryWindowsService();
                if ($service !== null) {
                    if (self::windowsServiceStateFromQuery($service['output']) !== 1) {
                        $this->runCommand([$this->windowsSystemExecutable('sc.exe'), 'stop', self::SERVICE_NAME], true);
                        $this->waitForWindowsServiceState(1);
                    }
                    $deleted = $this->runCommand(
                        [$this->windowsSystemExecutable('sc.exe'), 'delete', self::SERVICE_NAME],
                        true,
                    );
                    $alreadyAbsent = $deleted['code'] !== 0
                        && \preg_match(
                            '/(?:^|\D)1060(?:\D|$)/D',
                            $deleted['output'],
                        ) === 1;
                    $deletionPending = $deleted['code'] !== 0
                        && \preg_match(
                            '/(?:^|\D)1072(?:\D|$)/D',
                            $deleted['output'],
                        ) === 1;
                    if ($deleted['code'] !== 0
                        && !$alreadyAbsent
                        && !$deletionPending
                    ) {
                        throw new \RuntimeException(
                            'Windows gateway service deletion failed: '
                                . $deleted['output'],
                        );
                    }
                    if (!$alreadyAbsent) {
                        $this->waitForWindowsServiceDeletion();
                    }
                }
            } else {
                throw new \RuntimeException(
                    'Unsupported gateway platform service kind: ' . $kind
                );
            }
        }
        $path = $this->paths->serviceDefinitionFile();
        if ((\file_exists($path) || \is_link($path))
            && !$this->removeVerifiedRegularFile($path)
        ) {
            throw new \RuntimeException('Unable to remove the failed gateway service definition.');
        }
        if (!$this->paths->isTestMode() && $kind === 'systemd-system') {
            // Reload only after unlinking the unit. Reloading before unlink
            // leaves systemd with a loaded definition that may respawn while
            // the package rollback deletes its executable.
            $this->mustRun(
                ['/bin/systemctl', 'daemon-reload'],
                'systemd definition removal reload',
            );
            $this->assertPlatformDefinitionAbsent($kind);
        } elseif (!$this->paths->isTestMode()) {
            $this->assertPlatformDefinitionAbsent($kind);
        }
        $metadata = $this->paths->platformServiceMetadataFile();
        if ((\file_exists($metadata) || \is_link($metadata))
            && !$this->removeVerifiedRegularFile($metadata)
        ) {
            throw new \RuntimeException('Unable to remove failed gateway service metadata.');
        }
        GatewayProjectStateFilesystem::removeRegular(
            $pending,
            'completed gateway platform removal fence',
        );
        });
    }

    public function renderDefinition(string $profile): string
    {
        $template = $this->templateFile();
        try {
            $contents = $this->readStableRegularFile(
                $template,
                1_048_576,
                'WLS Gateway platform service template',
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'WLS Gateway platform service template is missing: '
                    . $throwable->getMessage()
            );
        }
        if (\trim($contents) === '') {
            throw new \RuntimeException('WLS Gateway platform service template is missing.');
        }
        $values = [
            '{{LAUNCHER}}' => $this->paths->launcherFile(),
            '{{HOME}}' => $this->paths->home(),
            '{{RUN_DIR}}' => $this->paths->runDir(),
            '{{PROFILE}}' => $profile,
            '{{HTTP_PORT}}' => (string)$this->paths->publicHttpPort(),
            '{{HTTPS_PORT}}' => (string)$this->paths->publicHttpsPort(),
        ];
        foreach ($values as $token => $value) {
            if (\str_contains($value, "\0") || \str_contains($value, "\n") || \str_contains($value, "\r")) {
                throw new \RuntimeException('Unsafe value in WLS Gateway service definition.');
            }
            $contents = \str_replace($token, $this->escapeForTemplate($value), $contents);
        }
        if (\preg_match('/\{\{[A-Z0-9_]+\}\}/', $contents) === 1) {
            throw new \RuntimeException('Unresolved token in WLS Gateway service definition.');
        }
        return $contents;
    }

    private function templateFile(): string
    {
        $directory = $this->templateDirectory
            ?? \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'gateway';
        $name = match (\PHP_OS_FAMILY) {
            'Darwin' => 'launchd.plist.template',
            'Linux' => 'systemd.service.template',
            'Windows' => 'windows-service.json.template',
            default => throw new \RuntimeException('Unsupported WLS Gateway platform.'),
        };
        return $directory . DIRECTORY_SEPARATOR . $name;
    }

    private function escapeForTemplate(string $value): string
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            return \htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return \str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        }
        return \str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $value);
    }

    private function prepareProjectEndpointDirectory(
        string $projectRoot,
        ?int $ownerUid,
        ?int $ownerGid,
    ): string {
        $rootStatus = @\lstat($projectRoot);
        if (!\is_array($rootStatus)) {
            throw new \RuntimeException('Unable to inspect the project root owner.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $ownerUid ??= (int)$rootStatus['uid'];
            $ownerGid ??= (int)$rootStatus['gid'];
            if ((int)$rootStatus['uid'] !== $ownerUid
                || (int)$rootStatus['gid'] !== $ownerGid
            ) {
                throw new \RuntimeException(
                    'Project endpoint ACL owner proof does not match the project root.'
                );
            }
        }

        $directory = $projectRoot;
        foreach (['var', 'server', 'gateway-identities'] as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            $created = false;
            if (!\is_dir($directory)) {
                if (!@\mkdir($directory, 0700) || !\is_dir($directory)) {
                    throw new \RuntimeException(
                        'Unable to create the project endpoint directory.'
                    );
                }
                $created = true;
            }
            $real = \realpath($directory);
            if (!\is_string($real)
                || \is_link($directory)
                || !$this->pathInside($real, $projectRoot)
            ) {
                throw new \RuntimeException(
                    'Project endpoint ACL path is outside the project root.'
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows' && $created
                && (!@\chown($directory, (int)$ownerUid)
                    || !@\chgrp($directory, (int)$ownerGid))
            ) {
                throw new \RuntimeException(
                    'Unable to preserve the project endpoint directory owner.'
                );
            }
        }
        return $directory;
    }

    private function existingProjectEndpointDirectory(string $projectRoot): ?string
    {
        $directory = $projectRoot;
        foreach (['var', 'server', 'gateway-identities'] as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            $status = @\lstat($directory);
            if (!\is_array($status)) {
                if (\file_exists($directory) || \is_link($directory)) {
                    throw new \RuntimeException(
                        'Project endpoint revocation path is indeterminate or unsafe.'
                    );
                }
                return null;
            }
            $real = \realpath($directory);
            if (\is_link($directory)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || !\is_string($real)
                || !$this->pathInside($real, $projectRoot)
            ) {
                throw new \RuntimeException(
                    'Project endpoint revocation path escapes the project root.'
                );
            }
            $directory = \rtrim($real, '/\\');
        }
        return $directory;
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = $this->normalizeBoundaryPath($path);
        $root = $this->normalizeBoundaryPath($root);
        return $path !== null && $root !== null
            && ($path === $root || \str_starts_with($path, $root . '/'));
    }

    private function normalizeBoundaryPath(string $path): ?string
    {
        if ($path === '' || \str_contains($path, "\0")) {
            return null;
        }
        $path = \str_replace('\\', '/', $path);
        if (\preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#D', $path) === 1) {
            return null;
        }
        $path = \rtrim($path, '/');
        if ($path === '') {
            return null;
        }
        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    private function removeVerifiedRegularFile(string $path): bool
    {
        try {
            return GatewayProjectStateFilesystem::removeRegular(
                $path,
                'WLS Gateway platform service file',
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertInitialInstallTargetsAbsent(
        string $definitionPath,
        string $metadataPath,
    ): void {
        foreach ([$definitionPath, $metadataPath] as $path) {
            \clearstatcache(true, $path);
            if (@\lstat($path) !== false) {
                throw new \RuntimeException(
                    'A WLS Gateway platform installation artifact already exists: ' . $path,
                );
            }
        }
    }

    private function assertAdministrator(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $result = $this->runCommand([
                $this->windowsSystemExecutable('fltmc.exe'),
            ], true);
            if ($result['code'] !== 0) {
                throw new \RuntimeException('WLS Gateway production installation requires an elevated administrator.');
            }
            return;
        }
        if (!\function_exists('posix_geteuid') || \posix_geteuid() !== 0) {
            throw new \RuntimeException('WLS Gateway production installation requires root.');
        }
    }

    private function ensureWindowsServiceStopped(bool $required): void
    {
        $query = $this->queryWindowsService();
        if ($query === null) {
            if ($required) {
                throw new \RuntimeException(
                    'The installed WLS Gateway Windows service is unavailable.'
                );
            }
            return;
        }
        if (self::windowsServiceStateFromQuery($query['output']) === 1) {
            return;
        }
        $this->runCommand([$this->windowsSystemExecutable('sc.exe'), 'stop', self::SERVICE_NAME], true);
        $this->waitForWindowsServiceState(1);
    }

    private function configureWindowsServiceDefinition(bool $createIfMissing): void
    {
        $launcher = '"' . $this->paths->launcherFile() . '" --service'
            . ' --home="' . $this->paths->home() . '"'
            . ' --run="' . $this->paths->runDir() . '"';
        $existing = $this->queryWindowsService();
        if ($existing !== null) {
            $this->mustRun([
                $this->windowsSystemExecutable('sc.exe'),
                'config',
                self::SERVICE_NAME,
                'binPath=',
                $launcher,
                'start=',
                'disabled',
                'obj=',
                'LocalSystem',
            ], 'Windows service definition refresh');
        } elseif ($createIfMissing) {
            $this->mustRun([
                $this->windowsSystemExecutable('sc.exe'),
                'create',
                self::SERVICE_NAME,
                'binPath=',
                $launcher,
                'start=',
                'disabled',
                'obj=',
                'LocalSystem',
            ], 'Windows service creation');
        } else {
            throw new \RuntimeException(
                'The installed WLS Gateway Windows service is unavailable.'
            );
        }
        $this->mustRun([
            $this->windowsSystemExecutable('sc.exe'),
            'sidtype',
            self::SERVICE_NAME,
            'unrestricted',
        ], 'Windows service SID migration');
        $serviceRegistryPath = 'HKLM:\\SYSTEM\\CurrentControlSet\\Services\\'
            . self::SERVICE_NAME;
        $sidTypeScript = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_SERVICE_PATH__'))
$value = [int](Get-ItemPropertyValue -LiteralPath $path -Name 'ServiceSidType')
if ($value -ne 1) { exit 3 }
[Console]::Out.Write('1')
POWERSHELL;
        $sidTypeScript = \str_replace(
            '__WLS_SERVICE_PATH__',
            \base64_encode($serviceRegistryPath),
            $sidTypeScript,
        );
        $sidType = $this->runCommand([
            $this->windowsPowerShell(),
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            $this->encodeWindowsPowerShell($sidTypeScript),
        ], true);
        if ($sidType['code'] !== 0 || \trim($sidType['output']) !== '1') {
            throw new \RuntimeException(
                'Windows gateway service SID did not become unrestricted.'
            );
        }
        $this->mustRun([
            $this->windowsSystemExecutable('sc.exe'),
            'failure',
            self::SERVICE_NAME,
            'reset=',
            '900',
            'actions=',
            'restart/5000/restart/30000/restart/300000',
        ], 'Windows service recovery policy');
        $this->mustRun([
            $this->windowsSystemExecutable('sc.exe'),
            'failureflag',
            self::SERVICE_NAME,
            '1',
        ], 'Windows non-crash recovery policy');
    }

    private function enableWindowsServiceDefinition(): void
    {
        $this->mustRun([
            $this->windowsSystemExecutable('sc.exe'),
            'config',
            self::SERVICE_NAME,
            'start=',
            'auto',
        ], 'Windows service automatic-start enable');
    }

    /** @return array{code:int,output:string}|null */
    private function queryWindowsService(): ?array
    {
        $result = $this->runCommand(
            [$this->windowsSystemExecutable('sc.exe'), 'query', self::SERVICE_NAME],
            true,
        );
        if ($result['code'] === 0) {
            return $result;
        }
        // sc.exe localizes its prose but preserves the Win32 service error
        // number. Only ERROR_SERVICE_DOES_NOT_EXIST is absence; access,
        // transport, and SCM failures must never trigger first-install ACL
        // mutation or an attempted replacement service.
        if (\preg_match('/(?:^|\D)1060(?:\D|$)/D', $result['output']) === 1) {
            return null;
        }
        if (\preg_match('/(?:^|\D)1072(?:\D|$)/D', $result['output']) === 1) {
            // ERROR_SERVICE_MARKED_FOR_DELETE is a recoverable intermediate
            // state, not an unknown service identity. A previous removal may
            // have been interrupted after `sc delete`; resume the same
            // transaction until SCM reports the authoritative 1060 absence.
            $this->waitForWindowsServiceDeletion();
            return null;
        }
        throw new \RuntimeException(
            'Windows gateway service identity is indeterminate: ' . $result['output']
        );
    }

    private function ensureServiceIdentityAndPermissions(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $serviceIdentity = 'NT SERVICE\\' . self::SERVICE_NAME;
            $readOnly = [
                $this->paths->home(),
                $this->paths->slotsDir(),
                $this->paths->trustDir(),
                \dirname($this->paths->launcherFile()),
            ];
            $mutable = [
                $this->paths->runtimeDir(),
                $this->paths->runDir(),
                $this->paths->logDir(),
                $this->paths->stateDir(),
                $this->paths->home() . DIRECTORY_SEPARATOR . 'snapshots',
            ];
            foreach (\array_unique([...$readOnly, ...$mutable]) as $directory) {
                if (!\is_dir($directory) || \is_link($directory)) {
                    throw new \RuntimeException(
                        'Windows gateway ACL target is missing or is a reparse point: '
                        . $directory
                    );
                }
                $this->assertWindowsTreeHasNoLinks($directory);
            }
            $rootOnlyPaths = [];
            foreach ($this->rootOnlyTrustFiles() as $rootOnlyFile) {
                $rootOnlyPath = $this->paths->trustDir()
                    . DIRECTORY_SEPARATOR . $rootOnlyFile;
                if (!\file_exists($rootOnlyPath) && !\is_link($rootOnlyPath)) {
                    continue;
                }
                $status = @\lstat($rootOnlyPath);
                if (!\is_array($status)
                    || \is_link($rootOnlyPath)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Windows gateway root-only trust file is unsafe: '
                            . $rootOnlyPath
                    );
                }
                $this->applyWindowsAcl(
                    $rootOnlyPath,
                    $serviceIdentity,
                    'NONE',
                );
                $rootOnlyPaths[] = $rootOnlyPath;
            }
            foreach ($readOnly as $directory) {
                $this->applyWindowsAcl(
                    $directory,
                    $serviceIdentity,
                    'RX',
                    $rootOnlyPaths,
                );
            }
            foreach ($mutable as $directory) {
                $this->applyWindowsAcl($directory, $serviceIdentity, 'M');
            }
            return;
        }
        $account = \PHP_OS_FAMILY === 'Darwin' ? '_welinegateway' : 'weline-gateway';
        $group = $account;
        $identity = \function_exists('posix_getpwnam') ? @\posix_getpwnam($account) : false;
        $groupIdentity = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($group)
            : false;
        if (!\is_array($identity)) {
            if (\is_array($groupIdentity)) {
                throw new \RuntimeException(
                    'An orphan WLS Gateway controller group already exists.'
                );
            }
            if (\PHP_OS_FAMILY === 'Linux') {
                $this->mustRun([
                    '/usr/sbin/useradd',
                    '--system',
                    '--home-dir',
                    '/nonexistent',
                    '--shell',
                    '/usr/sbin/nologin',
                    '--user-group',
                    $account,
                ], 'gateway service account creation');
            } elseif (\PHP_OS_FAMILY === 'Darwin') {
                $this->createDarwinServiceIdentity($account, $group);
            }
            $identity = \function_exists('posix_getpwnam') ? @\posix_getpwnam($account) : false;
            $groupIdentity = \function_exists('posix_getgrnam')
                ? @\posix_getgrnam($group)
                : false;
        }
        if (!\is_array($identity)
            || !\is_array($groupIdentity)
            || !self::posixServiceIdentityIsValid(
                $identity,
                $groupIdentity,
                \PHP_OS_FAMILY,
            )
        ) {
            throw new \RuntimeException('Dedicated WLS Gateway controller identity is unavailable.');
        }
        $uid = (int)$identity['uid'];
        $gid = (int)$identity['gid'];
        $this->assertPosixServiceTreeSafe($this->paths->home());
        if (\PHP_OS_FAMILY === 'Darwin') {
            // chmod(2) mode bits do not remove macOS extended ACL entries.
            // Strip inherited or pre-existing NFSv4 ACLs from the dedicated
            // host tree before rebuilding the root/controller split.
            $this->mustRun(
                ['/bin/chmod', '-RN', $this->paths->home()],
                'macOS gateway ACL reset',
            );
        }
        foreach ([
            $this->paths->home() => [0, $gid, 0750],
            $this->paths->slotsDir() => [0, $gid, 0750],
            $this->paths->trustDir() => [0, $gid, 0750],
            \dirname($this->paths->launcherFile()) => [0, $gid, 0750],
            $this->paths->runtimeDir() => [$uid, $gid, 0700],
            $this->paths->logDir() => [$uid, $gid, 0700],
            $this->paths->stateDir() => [$uid, $gid, 0700],
            $this->paths->home() . DIRECTORY_SEPARATOR . 'snapshots' => [$uid, $gid, 0700],
        ] as $directory => [$owner, $directoryGroup, $mode]) {
            if (!\is_dir($directory)
                || \is_link($directory)
                || !@\chown($directory, $owner)
                || !@\chgrp($directory, $directoryGroup)
                || !@\chmod($directory, $mode)
            ) {
                throw new \RuntimeException('Unable to apply gateway privilege separation: ' . $directory);
            }
        }
        foreach ([
            $this->paths->runtimeDir(),
            $this->paths->stateDir(),
            $this->paths->home() . DIRECTORY_SEPARATOR . 'snapshots',
        ] as $controllerTree) {
            $this->secureControllerTree($controllerTree, $uid, $gid);
        }
        $rootOnlyPaths = [];
        foreach ($this->rootOnlyTrustFiles() as $rootOnlyFile) {
            $rootOnlyPath = $this->paths->trustDir()
                . DIRECTORY_SEPARATOR . $rootOnlyFile;
            if (\file_exists($rootOnlyPath) || \is_link($rootOnlyPath)) {
                $rootOnlyStatus = @\lstat($rootOnlyPath);
                if (!\is_array($rootOnlyStatus)
                    || \is_link($rootOnlyPath)
                    || ((((int)($rootOnlyStatus['mode'] ?? 0)) & 0170000)
                        !== 0100000)
                    || (int)($rootOnlyStatus['nlink'] ?? 0) !== 1
                    || !@\chown($rootOnlyPath, 0)
                    || !@\chgrp($rootOnlyPath, $gid)
                    || !@\chmod($rootOnlyPath, 0600)
                ) {
                    throw new \RuntimeException(
                        'Gateway root-only trust file permission verification failed: '
                        . $rootOnlyPath
                    );
                }
                $rootOnlyPaths[] = $rootOnlyPath;
            }
        }
        $this->secureRuntimeTree(
            $this->paths->trustDir(),
            $gid,
            $rootOnlyPaths,
        );
        foreach (['A', 'B'] as $slot) {
            $slotDirectory = $this->paths->slotDir($slot);
            if (\is_dir($slotDirectory)) {
                $this->secureRuntimeTree($slotDirectory, $gid);
            }
        }
    }

    private function assertPosixServiceTreeSafe(string $root): void
    {
        GatewayBoundedTreeWalker::collect($root);
    }

    private function assertPosixTrustTreeOwnership(string $root): void
    {
        $entries = GatewayBoundedTreeWalker::collect($root, true);
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $status = GatewayBoundedTreeWalker::revalidate($entry);
            $type = \is_array($status)
                ? (((int)($status['mode'] ?? 0)) & 0170000)
                : 0;
            if (!\is_array($status)
                || \is_link($path)
                || !\in_array($type, [0040000, 0100000], true)
                || ($type === 0100000 && (int)($status['nlink'] ?? 0) !== 1)
                || (int)($status['uid'] ?? -1) !== 0
                || (((int)($status['mode'] ?? 0)) & 0022) !== 0
            ) {
                throw new \RuntimeException(
                    'Gateway package trust tree contains a tenant-owned or writable entry: '
                        . $path
                );
            }
        }
    }

    private function assertWindowsTreeHasNoLinks(string $root): void
    {
        GatewayBoundedTreeWalker::collect($root, true);
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
__WLS_BOUNDED_WALKER__
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_PATH__'))
$null = @(Get-WlsBoundedTree -RootPath $path)
POWERSHELL;
        $script = \str_replace(
            ['__WLS_BOUNDED_WALKER__', '__WLS_PATH__'],
            [$this->windowsBoundedTreeWalkerScript(), \base64_encode($root)],
            $script,
        );
        $this->mustRun([
            $this->windowsPowerShell(),
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            $this->encodeWindowsPowerShell($script),
        ], 'Windows gateway reparse-point verification');
    }

    /** @return list<string> */
    private function rootOnlyTrustFiles(): array
    {
        return [
            'broker-enrollments.tsv',
            'broker-security-v2.tsv',
            'package-install.lock',
            'platform-definition.transaction',
        ];
    }

    private function applyWindowsAcl(
        string $directory,
        string $serviceIdentity,
        string $serviceRights,
        array $excludedPaths = [],
    ): void {
        if (!\in_array($serviceRights, ['RX', 'M', 'NONE'], true)) {
            throw new \InvalidArgumentException(
                'Windows gateway service rights must be RX, M or NONE.'
            );
        }
        $targetStatus = @\lstat($directory);
        $targetType = \is_array($targetStatus)
            ? (((int)($targetStatus['mode'] ?? 0)) & 0170000)
            : 0;
        if (!\is_array($targetStatus)
            || \is_link($directory)
            || !\in_array($targetType, [0040000, 0100000], true)
            || ($targetType === 0100000
                && (int)($targetStatus['nlink'] ?? 0) !== 1)
        ) {
            throw new \RuntimeException(
                'Windows gateway ACL target is linked, special, or hard-linked: '
                    . $directory
            );
        }
        if ($targetType === 0040000) {
            GatewayBoundedTreeWalker::collect($directory, true);
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
__WLS_BOUNDED_WALKER__
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_PATH__'))
$serviceName = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_SERVICE__'))
$excludedJson = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_EXCLUDED__'))
$rightsName = '__WLS_RIGHTS__'
$systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
$administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
$serviceSid = $null
$allow = [Security.AccessControl.AccessControlType]::Allow
$none = [Security.AccessControl.PropagationFlags]::None
$fullControl = [Security.AccessControl.FileSystemRights]::FullControl
$serviceRights = if ($rightsName -eq 'RX') {
    [Security.AccessControl.FileSystemRights]::ReadAndExecute
} elseif ($rightsName -eq 'M') {
    [Security.AccessControl.FileSystemRights]::Modify
} elseif ($rightsName -eq 'NONE') {
    $null
} else {
    throw 'Unsupported WLS service ACL rights.'
}
if ($null -ne $serviceRights) {
    $serviceSid = [Security.Principal.NTAccount]::new($serviceName).Translate(
        [Security.Principal.SecurityIdentifier]
    )
}
$excluded = [Collections.Generic.HashSet[string]]::new(
    [StringComparer]::OrdinalIgnoreCase
)
foreach ($excludedPath in @($excludedJson | ConvertFrom-Json)) {
    if ($excludedPath -isnot [string] -or [string]::IsNullOrWhiteSpace($excludedPath)) {
        throw 'WLS ACL exclusion contains an invalid path.'
    }
    [void]$excluded.Add([IO.Path]::GetFullPath($excludedPath))
}

function Set-WlsExactAcl([System.IO.FileSystemInfo]$item) {
    $isDirectory = $item.PSIsContainer
    $inheritance = if ($isDirectory) {
        ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor
            [Security.AccessControl.InheritanceFlags]::ObjectInherit)
    } else {
        [Security.AccessControl.InheritanceFlags]::None
    }
    $acl = if ($isDirectory) {
        [Security.AccessControl.DirectorySecurity]::new()
    } else {
        [Security.AccessControl.FileSecurity]::new()
    }
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($administratorsSid)
    $expectedRules = @(
        [Security.AccessControl.FileSystemAccessRule]::new(
            $systemSid, $fullControl, $inheritance, $none, $allow
        ),
        [Security.AccessControl.FileSystemAccessRule]::new(
            $administratorsSid, $fullControl, $inheritance, $none, $allow
        )
    )
    if ($null -ne $serviceRights) {
        $expectedRules += [Security.AccessControl.FileSystemAccessRule]::new(
            $serviceSid, $serviceRights, $inheritance, $none, $allow
        )
    }
    foreach ($rule in $expectedRules) {
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $item.FullName -AclObject $acl

    $verified = Get-Acl -LiteralPath $item.FullName
    $owner = $verified.GetOwner(
        [Security.Principal.SecurityIdentifier]
    ).Value
    $actualRules = @($verified.GetAccessRules(
        $true,
        $true,
        [Security.Principal.SecurityIdentifier]
    ))
    if (-not $verified.AreAccessRulesProtected -or
        $owner -ne $administratorsSid.Value -or
        $actualRules.Count -ne $expectedRules.Count) {
        throw "WLS ACL identity verification failed: $($item.FullName)"
    }
    $expected = @{}
    foreach ($rule in $expectedRules) {
        $expected[$rule.IdentityReference.Value] = $rule
    }
    foreach ($rule in $actualRules) {
        $identity = $rule.IdentityReference.Value
        if (-not $expected.ContainsKey($identity)) {
            throw "Unexpected WLS ACL identity: $identity"
        }
        $wanted = $expected[$identity]
        if ($rule.IsInherited -or
            $rule.AccessControlType -ne $wanted.AccessControlType -or
            [int]$rule.FileSystemRights -ne [int]$wanted.FileSystemRights -or
            $rule.InheritanceFlags -ne $wanted.InheritanceFlags -or
            $rule.PropagationFlags -ne $wanted.PropagationFlags) {
            throw "WLS ACL rights verification failed: $($item.FullName)"
        }
    }
}

$descendants = @(Get-WlsBoundedTree -RootPath $path)
$icacls = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_ICACLS__'))
& $icacls $path '/setowner' '*S-1-5-32-544' '/L' '/Q'
if ($LASTEXITCODE -ne 0) {
    throw "WLS ACL root ownership reset failed: $path"
}
$reparse = [IO.FileAttributes]::ReparsePoint
$rootItem = Get-Item -LiteralPath $path -Force
if (($rootItem.Attributes -band $reparse) -ne 0) {
    throw "WLS ACL root changed to a reparse point: $($rootItem.FullName)"
}
Set-WlsExactAcl $rootItem
foreach ($item in $descendants) {
    $current = Get-Item -LiteralPath $item.FullName -Force
    if (($current.Attributes -band $reparse) -ne 0 -or
        $current.PSIsContainer -ne $item.PSIsContainer) {
        throw "WLS ACL tree identity changed after preflight: $($item.FullName)"
    }
    if ($excluded.Contains($current.FullName)) {
        continue
    }
    Set-WlsExactAcl $current
}
POWERSHELL;
        $script = \str_replace(
            [
                '__WLS_BOUNDED_WALKER__',
                '__WLS_PATH__',
                '__WLS_SERVICE__',
                '__WLS_EXCLUDED__',
                '__WLS_ICACLS__',
                '__WLS_RIGHTS__',
            ],
            [
                $this->windowsBoundedTreeWalkerScript(),
                \base64_encode($directory),
                \base64_encode($serviceIdentity),
                \base64_encode((string)\json_encode(
                    \array_values($excludedPaths),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )),
                \base64_encode($this->windowsSystemExecutable('icacls.exe')),
                $serviceRights,
            ],
            $script,
        );
        $encodedScript = $this->encodeWindowsPowerShell($script);
        $this->mustRun([
            $this->windowsPowerShell(),
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            $encodedScript,
        ], 'Windows gateway exact ACL replacement');

        $verified = $this->runCommand([
            $this->windowsSystemExecutable('icacls.exe'),
            $directory,
        ], true);
        if ($verified['code'] !== 0) {
            throw new \RuntimeException(
                'Windows gateway ACL verification failed: ' . $directory
            );
        }
    }

    private function windowsPowerShell(): string
    {
        $candidate = $this->windowsSystemExecutable(
            'WindowsPowerShell\\v1.0\\powershell.exe',
        );
        if (!\is_file($candidate) || \is_link($candidate)) {
            throw new \RuntimeException(
                'The canonical Windows PowerShell executable is unsafe.',
            );
        }
        return $candidate;
    }

    public function windowsPowerShellExecutable(): string
    {
        return $this->windowsPowerShell();
    }

    private function windowsSystemExecutable(string $relative): string
    {
        if (\PHP_OS_FAMILY !== 'Windows'
            || \preg_match(
                '/\A(?:sc\.exe|icacls\.exe|fltmc\.exe|WindowsPowerShell\\\\v1\.0\\\\powershell\.exe)\z/Di',
                $relative,
            ) !== 1
        ) {
            throw new \RuntimeException('Windows system executable identity is invalid.');
        }
        $systemRoot = $this->windowsSystemRoot();
        if ($systemRoot === ''
            || \str_contains($systemRoot, "\0")
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $systemRoot) !== 1
        ) {
            throw new \RuntimeException('The canonical Windows SystemRoot is unavailable.');
        }
        $candidate = $systemRoot . '\\System32\\' . $relative;
        if (!\is_file($candidate) || \is_link($candidate)) {
            throw new \RuntimeException(
                'The canonical Windows system executable is unavailable: ' . $relative,
            );
        }
        return $candidate;
    }

    private function windowsSystemRoot(): string
    {
        static $systemRoot = null;
        if (\is_string($systemRoot) && $systemRoot !== '') {
            return $systemRoot;
        }
        if (!\class_exists(\FFI::class) || !\function_exists('iconv')) {
            throw new \RuntimeException(
                'Canonical Windows system-directory resolution requires FFI and iconv.',
            );
        }
        try {
            $ffi = \FFI::cdef(
                'typedef unsigned int UINT; typedef unsigned short WCHAR;'
                    . ' UINT GetSystemWindowsDirectoryW(WCHAR*, UINT);',
                'kernel32.dll',
            );
            $buffer = $ffi->new('WCHAR[32768]');
            $length = (int)$ffi->GetSystemWindowsDirectoryW($buffer, 32768);
            if ($length < 3 || $length >= 32768) {
                throw new \RuntimeException(
                    'Windows returned an invalid canonical system-directory length.',
                );
            }
            $bytes = \FFI::string(
                $ffi->cast('char*', $buffer),
                $length * 2,
            );
            $decoded = @\iconv('UTF-16LE', 'UTF-8', $bytes);
            if (!\is_string($decoded) || $decoded === '') {
                throw new \RuntimeException(
                    'Windows canonical system directory could not be decoded.',
                );
            }
            $systemRoot = \rtrim($decoded, '/\\');
            return $systemRoot;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'The canonical Windows system directory is unavailable.',
                0,
                $throwable,
            );
        }
    }

    private function windowsBoundedTreeWalkerScript(): string
    {
        return <<<'POWERSHELL'
function Get-WlsBoundedTree([string]$RootPath) {
    $maximumEntries = 8192
    $maximumDepth = 64
    $maximumPathLength = 32768
    $reparse = [IO.FileAttributes]::ReparsePoint
    $rootItem = Get-Item -LiteralPath $RootPath -Force
    if ($rootItem.FullName.Length -gt $maximumPathLength -or
        ($rootItem.Attributes -band $reparse) -ne 0) {
        throw "WLS bounded ACL root is invalid or a reparse point: $RootPath"
    }
    if (-not $rootItem.PSIsContainer) {
        return @()
    }

    $stack = [Collections.Stack]::new()
    $items = [Collections.ArrayList]::new()
    $stack.Push([pscustomobject]@{ Path = $rootItem.FullName; Depth = 0 })
    $entryCount = 0
    while ($stack.Count -gt 0) {
        $node = $stack.Pop()
        $current = Get-Item -LiteralPath $node.Path -Force
        if (-not $current.PSIsContainer -or
            ($current.Attributes -band $reparse) -ne 0) {
            throw "WLS bounded ACL directory identity changed: $($node.Path)"
        }
        foreach ($childPath in [IO.Directory]::EnumerateFileSystemEntries($current.FullName)) {
            $entryCount++
            if ($entryCount -gt $maximumEntries) {
                throw 'WLS bounded ACL tree exceeds the 8192-entry safety limit.'
            }
            $depth = [int]$node.Depth + 1
            if ($depth -gt $maximumDepth) {
                throw 'WLS bounded ACL tree exceeds the depth-64 safety limit.'
            }
            $item = Get-Item -LiteralPath $childPath -Force
            if ($item.FullName.Length -gt $maximumPathLength -or
                ($item.Attributes -band $reparse) -ne 0) {
                throw "WLS bounded ACL tree contains an invalid reparse entry: $($item.FullName)"
            }
            [void]$items.Add($item)
            if ($item.PSIsContainer) {
                $stack.Push([pscustomobject]@{
                    Path = $item.FullName
                    Depth = $depth
                })
            }
        }
        $after = Get-Item -LiteralPath $node.Path -Force
        if (-not $after.PSIsContainer -or
            ($after.Attributes -band $reparse) -ne 0) {
            throw "WLS bounded ACL directory changed during enumeration: $($node.Path)"
        }
    }
    return @($items.ToArray())
}
POWERSHELL;
    }

    private function encodeWindowsPowerShell(string $script): string
    {
        $utf16 = \function_exists('iconv')
            ? @\iconv('UTF-8', 'UTF-16LE', $script)
            : false;
        if (!\is_string($utf16) && \function_exists('mb_convert_encoding')) {
            $utf16 = @\mb_convert_encoding($script, 'UTF-16LE', 'UTF-8');
        }
        if (!\is_string($utf16)) {
            throw new \RuntimeException(
                'Windows gateway ACL setup requires UTF-16LE conversion support.'
            );
        }
        return \base64_encode($utf16);
    }

    private function secureControllerTree(string $root, int $owner, int $group): void
    {
        $entries = GatewayBoundedTreeWalker::collect($root);
        foreach ($entries as $entry) {
            $path = $entry['path'];
            GatewayBoundedTreeWalker::revalidate($entry);
            if (!@\chown($path, $owner)
                || !@\chgrp($path, $group)
                || !@\chmod($path, $entry['directory'] ? 0700 : 0600)
            ) {
                throw new \RuntimeException('Gateway controller state permission verification failed: ' . $path);
            }
        }
    }

    private function createDarwinServiceIdentity(string $account, string $group): void
    {
        $id = $this->availableDarwinSystemId();
        $groupCreated = false;
        $userCreated = false;
        try {
            $this->mustRun(
                ['/usr/bin/dscl', '.', '-create', '/Groups/' . $group],
                'gateway group creation',
            );
            $groupCreated = true;
            $this->mustRun([
                '/usr/bin/dscl',
                '.',
                '-create',
                '/Groups/' . $group,
                'PrimaryGroupID',
                (string)$id,
            ], 'gateway group id assignment');
            $this->mustRun(
                ['/usr/bin/dscl', '.', '-create', '/Users/' . $account],
                'gateway user creation',
            );
            $userCreated = true;
            foreach ([
                ['UniqueID', (string)$id],
                ['PrimaryGroupID', (string)$id],
                ['UserShell', '/usr/bin/false'],
                ['NFSHomeDirectory', '/var/empty'],
                ['RealName', 'Weline Gateway Controller'],
            ] as [$property, $value]) {
                $this->mustRun([
                    '/usr/bin/dscl',
                    '.',
                    '-create',
                    '/Users/' . $account,
                    $property,
                    $value,
                ], 'gateway account property ' . $property);
            }
        } catch (\Throwable $throwable) {
            $cleanupFailures = $this->rollbackDarwinServiceIdentityCreation(
                $account,
                $group,
                $userCreated,
                $groupCreated,
            );
            if ($cleanupFailures !== []) {
                throw new \RuntimeException(
                    $throwable->getMessage()
                        . ' Darwin service identity rollback also failed: '
                        . \implode('; ', $cleanupFailures),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /** @return list<string> */
    private function rollbackDarwinServiceIdentityCreation(
        string $account,
        string $group,
        bool $userCreated,
        bool $groupCreated,
    ): array {
        $failures = [];
        if ($userCreated) {
            $deleted = $this->runCommand([
                '/usr/bin/dscl',
                '.',
                '-delete',
                '/Users/' . $account,
            ], true);
            $remaining = \function_exists('posix_getpwnam')
                ? @\posix_getpwnam($account)
                : null;
            if ($deleted['code'] !== 0 || $remaining !== false) {
                $failures[] = 'user ' . $account . ': '
                    . GatewayBoundedText::singleLine(
                        $deleted['output'],
                        512,
                        'deletion was not verified',
                    );
            }
        }
        if ($groupCreated) {
            $deleted = $this->runCommand([
                '/usr/bin/dscl',
                '.',
                '-delete',
                '/Groups/' . $group,
            ], true);
            $remaining = \function_exists('posix_getgrnam')
                ? @\posix_getgrnam($group)
                : null;
            if ($deleted['code'] !== 0 || $remaining !== false) {
                $failures[] = 'group ' . $group . ': '
                    . GatewayBoundedText::singleLine(
                        $deleted['output'],
                        512,
                        'deletion was not verified',
                    );
            }
        }
        return $failures;
    }

    private function availableDarwinSystemId(): int
    {
        $used = [];
        foreach ([
            ['/Users', 'UniqueID'],
            ['/Groups', 'PrimaryGroupID'],
        ] as [$namespace, $property]) {
            $result = $this->runCommand([
                '/usr/bin/dscl',
                '.',
                '-list',
                $namespace,
                $property,
            ], true);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Unable to enumerate macOS system identity namespace: '
                        . $namespace,
                );
            }
            foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
                if (\preg_match('/\s([0-9]+)\s*$/', $line, $matches) === 1) {
                    $used[(int)$matches[1]] = true;
                }
            }
        }
        for ($candidate = 399; $candidate >= 200; $candidate--) {
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
        throw new \RuntimeException('No free macOS system UID/GID is available for WLS Gateway.');
    }

    /**
     * @param array<string|int,mixed> $identity
     * @param array<string|int,mixed> $group
     */
    private static function posixServiceIdentityIsValid(
        array $identity,
        array $group,
        string $platform,
    ): bool {
        [$expectedName, $expectedHome, $expectedShell] = match ($platform) {
            'Darwin' => ['_welinegateway', '/var/empty', '/usr/bin/false'],
            'Linux' => ['weline-gateway', '/nonexistent', '/usr/sbin/nologin'],
            default => ['', '', ''],
        };
        $uid = (int)($identity['uid'] ?? 0);
        $gid = (int)($identity['gid'] ?? 0);
        return $expectedName !== ''
            && $uid > 0
            && $gid > 0
            && (int)($group['gid'] ?? 0) === $gid
            && \hash_equals($expectedName, (string)($identity['name'] ?? ''))
            && \hash_equals($expectedName, (string)($group['name'] ?? ''))
            && \hash_equals($expectedHome, (string)($identity['dir'] ?? ''))
            && \hash_equals($expectedShell, (string)($identity['shell'] ?? ''));
    }

    private function secureRuntimeTree(
        string $root,
        int $group,
        array $excludedPaths = [],
    ): void
    {
        $normalizedRoot = \rtrim($root, '/\\');
        $entries = GatewayBoundedTreeWalker::collect($normalizedRoot, true);
        $rootEntry = \array_shift($entries);
        if (!\is_array($rootEntry)
            || !\hash_equals($normalizedRoot, (string)$rootEntry['path'])
        ) {
            throw new \RuntimeException('Gateway runtime slot root is unsafe.');
        }
        GatewayBoundedTreeWalker::revalidate($rootEntry);
        if (!@\chown($normalizedRoot, 0)
            || !@\chgrp($normalizedRoot, $group)
            || !@\chmod($normalizedRoot, 0750)
        ) {
            throw new \RuntimeException('Gateway runtime slot root is unsafe.');
        }
        foreach ($entries as $entry) {
            $path = $entry['path'];
            GatewayBoundedTreeWalker::revalidate($entry);
            if (\in_array($path, $excludedPaths, true)) {
                continue;
            }
            if (!@\chown($path, 0)
                || !@\chgrp($path, $group)
                || !@\chmod(
                    $path,
                    $entry['directory'] ? 0750 : ($entry['executable'] ? 0550 : 0440),
                )
            ) {
                throw new \RuntimeException('Gateway runtime slot permission verification failed: ' . $path);
            }
        }
    }

    private function readStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
    ): string {
        $pathStatus = @\lstat($path);
        if ($maximumBytes < 1
            || !\is_array($pathStatus)
            || \is_link($path)
            || ((((int)($pathStatus['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($pathStatus['nlink'] ?? 0) !== 1
            || (int)($pathStatus['size'] ?? -1) < 1
            || (int)($pathStatus['size'] ?? -1) > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or special.');
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $openedStatus = @\fstat($handle);
            if (!\is_array($openedStatus)
                || !$this->sameFileState($pathStatus, $openedStatus)
            ) {
                throw new \RuntimeException($label . ' changed before reading.');
            }
            $contents = @\stream_get_contents($handle, $maximumBytes + 1);
            $afterStatus = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_string($contents)
                || \strlen($contents) > $maximumBytes
                || !\is_array($afterStatus)
                || !\is_array($pathAfter)
                || !$this->sameFileState($openedStatus, $afterStatus)
                || !$this->sameFileState($afterStatus, $pathAfter)
                || (int)($afterStatus['size'] ?? -1) !== \strlen($contents)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $contents;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = \dirname($path);
        $parent = @\lstat($directory);
        if (!\is_array($parent)
            || \is_link($directory)
            || !\is_dir($directory)
            || ((((int)($parent['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Gateway service definition path is unsafe.');
        }
        $seal = null;
        if (\PHP_OS_FAMILY !== 'Windows') {
            if (!isset($parent['uid'], $parent['gid'])) {
                throw new \RuntimeException(
                    'Gateway service definition parent ownership is unavailable.'
                );
            }
            $owner = (int)$parent['uid'];
            $group = (int)$parent['gid'];
            $seal = static function ($handle, string $path) use ($owner, $group): void {
                $ownerOk = \function_exists('fchown')
                    ? @\fchown($handle, $owner)
                    : @\chown($path, $owner);
                $groupOk = \function_exists('fchgrp')
                    ? @\fchgrp($handle, $group)
                    : @\chgrp($path, $group);
                if (!$ownerOk || !$groupOk) {
                    throw new \RuntimeException(
                        'Unable to seal the gateway service definition ownership.'
                    );
                }
            };
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $contents,
            $mode,
            $seal,
        );
        $published = @\lstat($path);
        if (!\is_array($published)
            || ((((int)($published['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($published['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($published['mode'] ?? 0)) & 0777) !== $mode)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((int)($published['uid'] ?? -1) !== (int)$parent['uid']
                    || (int)($published['gid'] ?? -1) !== (int)$parent['gid']))
        ) {
            throw new \RuntimeException('Published gateway service definition is unsafe.');
        }
    }

    private function waitForWindowsServiceState(int $expectedState): void
    {
        if (!\in_array($expectedState, [1, 4], true)) {
            throw new \InvalidArgumentException(
                'Windows gateway service wait state must be STOPPED or RUNNING.'
            );
        }
        $deadline = \hrtime(true) / 1_000_000_000
            + self::WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS;
        $lastState = null;
        $lastOutput = '';
        do {
            $timeout = self::windowsPollCommandTimeoutSeconds(
                $deadline,
                \hrtime(true) / 1_000_000_000,
            );
            if ($timeout === null) {
                break;
            }
            $result = $this->runCommand(
                [$this->windowsSystemExecutable('sc.exe'), 'query', self::SERVICE_NAME],
                true,
                $timeout,
            );
            $lastOutput = $result['output'];
            $lastState = $result['code'] === 0
                ? self::windowsServiceStateFromQuery($lastOutput)
                : null;
            if ($lastState === $expectedState) {
                return;
            }
            if ($result['code'] !== 0
                && \preg_match('/(?:^|\D)1060(?:\D|$)/D', $lastOutput) === 1
                && $expectedState === 1
            ) {
                // ERROR_SERVICE_DOES_NOT_EXIST is an idempotent STOPPED
                // result even if deletion raced the preceding stop request.
                return;
            }
            if ($result['code'] !== 0
                && \preg_match('/(?:^|\D)1072(?:\D|$)/D', $lastOutput) === 1
            ) {
                $this->waitForWindowsServiceDeletion($deadline);
                if ($expectedState === 1) {
                    return;
                }
                throw new \RuntimeException(
                    'Windows gateway service was deleted while waiting for RUNNING.',
                );
            }
            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                break;
            }
            \usleep(self::WINDOWS_SERVICE_POLL_MICROSECONDS);
        } while (true);

        throw new \RuntimeException(
            'Windows gateway service did not reach '
                . ($expectedState === 1 ? 'STOPPED' : 'RUNNING')
                . ' within '
                . (int)self::WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS
                . ' seconds (last_state='
                . ($lastState === null ? 'unknown' : (string)$lastState)
                . '): ' . $lastOutput
        );
    }

    private function waitForWindowsServiceDeletion(?float $deadline = null): void
    {
        $deadline ??= \hrtime(true) / 1_000_000_000
            + self::WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS;
        do {
            $timeout = self::windowsPollCommandTimeoutSeconds(
                $deadline,
                \hrtime(true) / 1_000_000_000,
            );
            if ($timeout === null) {
                break;
            }
            $query = $this->runCommand(
                [$this->windowsSystemExecutable('sc.exe'), 'query', self::SERVICE_NAME],
                true,
                $timeout,
            );
            if ($query['code'] !== 0
                && \preg_match('/(?:^|\D)1060(?:\D|$)/D', $query['output']) === 1
            ) {
                return;
            }
            if ($query['code'] !== 0
                && \preg_match('/(?:^|\D)1072(?:\D|$)/D', $query['output']) !== 1
            ) {
                throw new \RuntimeException(
                    'Windows gateway service deletion state is indeterminate: '
                        . $query['output']
                );
            }
            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                break;
            }
            \usleep(self::WINDOWS_SERVICE_POLL_MICROSECONDS);
        } while (true);

        throw new \RuntimeException(
            'Windows gateway service definition remained registered after deletion.'
        );
    }

    private function assertPlatformServiceStopped(string $kind): void
    {
        if ($kind === 'launchd-system') {
            $query = $this->runCommand([
                '/bin/launchctl',
                'print',
                'system/com.weline.wls-gateway-v2',
            ], true);
            if ($query['code'] === 0) {
                throw new \RuntimeException(
                    'launchd still owns the WLS Gateway supervisor after stop.'
                );
            }
            if (\preg_match(
                '/(?:could not find service|service[^\r\n]*not found)/i',
                $query['output'],
            ) === 1) {
                return;
            }
            throw new \RuntimeException(
                'launchd gateway service state is indeterminate: ' . $query['output']
            );
        }
        if ($kind === 'systemd-system') {
            $query = $this->runCommand([
                '/bin/systemctl',
                'show',
                self::SERVICE_NAME . '.service',
                '--property=ActiveState',
                '--property=SubState',
                '--property=MainPID',
            ], true);
            if ($query['code'] !== 0) {
                if (\preg_match(
                    '/(?:unit[^\r\n]*(?:could not be found|not found)|not-found)/i',
                    $query['output'],
                ) === 1) {
                    return;
                }
                throw new \RuntimeException(
                    'systemd gateway service state is indeterminate: ' . $query['output']
                );
            }
            $state = self::systemdServiceStateFromShow($query['output']);
            if ($state === null) {
                throw new \RuntimeException(
                    'systemd gateway service returned an incomplete or ambiguous state.'
                );
            }
            $stopped = ($state['active'] === 'inactive' && $state['sub'] === 'dead')
                || ($state['active'] === 'failed' && $state['sub'] === 'failed');
            if ($state['main_pid'] !== 0 || !$stopped) {
                throw new \RuntimeException(
                    'systemd still owns a live WLS Gateway supervisor after stop.'
                );
            }
            return;
        }
        if ($kind === 'windows-service') {
            $query = $this->queryWindowsService();
            if ($query !== null
                && self::windowsServiceStateFromQuery($query['output']) !== 1
            ) {
                throw new \RuntimeException(
                    'Windows SCM still owns a live WLS Gateway supervisor after stop.'
                );
            }
            return;
        }
        throw new \RuntimeException(
            'Unsupported gateway platform service kind: ' . $kind
        );
    }

    private function assertPlatformDefinitionAbsent(string $kind): void
    {
        $this->assertPlatformServiceStopped($kind);
        if ($kind === 'systemd-system') {
            $query = $this->runCommand([
                '/bin/systemctl',
                'show',
                self::SERVICE_NAME . '.service',
                '--property=LoadState',
                '--value',
            ], true);
            if ($query['code'] === 0 && \trim($query['output']) === 'not-found') {
                return;
            }
            if ($query['code'] !== 0
                && \preg_match(
                    '/(?:unit[^\r\n]*(?:could not be found|not found)|not-found)/i',
                    $query['output'],
                ) === 1
            ) {
                return;
            }
            if ($query['code'] === 0) {
                throw new \RuntimeException(
                    'systemd still has the removed WLS Gateway unit loaded.'
                );
            }
            throw new \RuntimeException(
                'systemd gateway definition state is indeterminate: ' . $query['output']
            );
        }
    }

    private function platformRemovalPendingFile(): string
    {
        return $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-removal.pending';
    }

    private function platformDefinitionTransactionFile(): string
    {
        return $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-definition.transaction';
    }

    private static function windowsServiceStateFromQuery(string $output): ?int
    {
        if (\preg_match(
            '/^\s*STATE\s*:\s*([1-7])(?:\s|$)/mi',
            $output,
            $match,
        ) !== 1) {
            return null;
        }
        return (int)$match[1];
    }

    private static function windowsPollCommandTimeoutSeconds(
        float $deadline,
        float $now,
    ): ?float {
        $remaining = $deadline - $now;
        if (!\is_finite($remaining) || $remaining < self::MIN_COMMAND_TIMEOUT_SECONDS) {
            return null;
        }
        return \min(self::WINDOWS_SERVICE_QUERY_TIMEOUT_SECONDS, $remaining);
    }

    /** @return array{active:string,sub:string,main_pid:int}|null */
    private static function systemdServiceStateFromShow(string $output): ?array
    {
        $values = [];
        foreach ([
            'ActiveState' => 'active',
            'SubState' => 'sub',
            'MainPID' => 'main_pid',
        ] as $property => $key) {
            $count = \preg_match_all(
                '/^' . $property . '=([^\r\n]*)\r?$/mD',
                $output,
                $matches,
            );
            if ($count !== 1 || !isset($matches[1][0])) {
                return null;
            }
            $values[$key] = (string)$matches[1][0];
        }
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $values['main_pid']) !== 1
            || \preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $values['active']) !== 1
            || \preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $values['sub']) !== 1
        ) {
            return null;
        }
        return [
            'active' => $values['active'],
            'sub' => $values['sub'],
            'main_pid' => (int)$values['main_pid'],
        ];
    }

    /** @param list<string> $command */
    private function mustRun(array $command, string $action): void
    {
        $result = $this->runCommand($command);
        if ($result['code'] !== 0) {
            throw new \RuntimeException($action . ' failed: ' . $result['output']);
        }
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(
        array $command,
        bool $allowFailure = false,
        ?float $timeoutSeconds = null,
    ): array
    {
        $result = $timeoutSeconds === null
            ? GatewayBoundedCommandRunner::run($command)
            : GatewayBoundedCommandRunner::run($command, $timeoutSeconds);
        if (!$allowFailure && $result['code'] !== 0) {
            throw new \RuntimeException(
                'Gateway platform command failed: ' . $result['output']
            );
        }
        return $result;
    }
}
