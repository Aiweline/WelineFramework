<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Installs the host-level service definition that owns the immutable Guardian.
 *
 * Rendering and activation are deliberately separate so a package can be
 * verified before the active-slot pointer changes.
 */
final class GatewayPlatformServiceInstaller
{
    public const SERVICE_NAME = 'weline-wls-gateway-v2';
    public const DATA_PLANE_SERVICE_NAME = 'weline-wls-gateway-v2-data-plane';
    public const DERIVED_AUTHORITY_HOME = 'host-root-controller-search-v2';
    public const DERIVED_AUTHORITY_STATE = 'controller-private-v2';
    public const DERIVED_AUTHORITY_TRUST = 'root-controller-read-v2';
    public const DERIVED_AUTHORITY_SNAPSHOTS_V1 = 'controller-data-plane-search-v2';
    public const DERIVED_AUTHORITY_SNAPSHOTS_V2 = 'root-data-plane-search-v2';
    public const DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2 = 'controller-snapshot-candidates-private-v2';
    public const DERIVED_AUTHORITY_RUNTIME = 'controller-data-plane-runtime-v2';
    public const DERIVED_AUTHORITY_RUNTIME_CHILD = 'controller-runtime-child-v2';
    private const WINDOWS_CONTROLLER_SERVICE_SID =
        'S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626';
    private const WINDOWS_DATA_PLANE_SERVICE_SID =
        'S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245';
    private const WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS = 75.0;
    private const WINDOWS_SERVICE_POLL_MICROSECONDS = 100_000;
    private const WINDOWS_SERVICE_QUERY_TIMEOUT_SECONDS = 5.0;
    private const MIN_COMMAND_TIMEOUT_SECONDS = 0.1;
    private const PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES = 2_000_000;
    private const PLATFORM_ATOMIC_RECOVERY_ENTRY_QUOTA = 16_384;
    private const PLATFORM_ATOMIC_RECOVERY_KIND_QUOTA = 8;
    private const DEFINITION_OPERATION_TIMEOUT_SECONDS = 600.0;
    private const SERVICE_OPERATION_TIMEOUT_SECONDS = 180.0;
    private const REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA = 16_384;
    private const REBOOTSTRAP_BACKUP_ACL_TOTAL_ENTRY_QUOTA = 65_536;
    private const REBOOTSTRAP_WORKSPACE_NAMESPACE_ENTRY_QUOTA = 16_384;
    private const REBOOTSTRAP_WORKSPACE_TOTAL_ENTRY_QUOTA = 32_768;
    private const REBOOTSTRAP_BACKUP_ACL_ROOTS = [
        'bin',
        'derived',
        'new-derived',
        'new-generation',
        'platform',
        'slots',
        'trust',
        'working-generation',
    ];

    /** @var list<float> */
    private array $operationDeadlineStack = [];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly ?string $templateDirectory = null,
        private readonly ?\Closure $initialServiceRegistrationProbe = null,
    ) {
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    public function installDefinition(
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($profile): array {
                $definition = $this->installDefinitionWithinDeadline($profile);
                // Models the exact commit-before-return-assignment window in
                // GatewayHostManager. The durable definition is intentionally
                // retained so replay must discover and validate it.
                GatewayInitialBootstrapCrashSimulation::hit(
                    'definition-after-commit',
                    $this->paths,
                );
                return $definition;
            },
        );
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    private function installDefinitionWithinDeadline(string $profile): array
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException('Gateway service profile must be default or ipv4-only.');
        }
        $this->assertInitialServiceRegistrationAbsent();
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
        if ($this->managesLinuxSystemdLayout()) {
            // The mutable unit target is intentionally outside /var/lib. It
            // must exist before generic transaction recovery enumerates its
            // same-directory atomic-write evidence.
            $this->paths->ensureSystemdDefinitionDirectory();
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'package-install.lock',
            function () use ($callback): mixed {
                $this->recoverLinuxSystemdLayoutMigrationIfPending();
                $recovered = $this->recoverPlatformDefinitionTransaction();
                $this->cleanupPlatformAtomicRecoveryBackups();
                return $callback($recovered);
            },
            waitTimeoutSeconds: \min(
                300.0,
                $this->remainingOperationDeadline(300.0),
            ),
            deadlineMonotonic: $this->activeOperationDeadline(),
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
        if (\hash_equals($this->platformRemovalPendingFile(), $path)) {
            try {
                $removal = $this->decodePlatformRemovalFence($raw);
                if (\hash_equals($expectedKind, (string)$removal['kind'])) {
                    return;
                }
            } catch (\Throwable) {
                // Fall through to the single malformed-target error below.
            }
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
            || !(
                \hash_equals(
                    $this->paths->serviceDefinitionFile(),
                    (string)($decoded['definition'] ?? ''),
                )
                || ($this->managesLinuxSystemdLayout()
                    && \hash_equals(
                        $this->paths->legacySystemdServiceDefinitionFile(),
                        (string)($decoded['definition'] ?? ''),
                    ))
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

    private function managesLinuxSystemdLayout(): bool
    {
        return !$this->paths->isTestMode() && \PHP_OS_FAMILY === 'Linux';
    }

    /** @param array<string,mixed> $metadata */
    private function metadataUsesLegacyLinuxSystemdLayout(array $metadata): bool
    {
        return $this->managesLinuxSystemdLayout()
            && \hash_equals(
                $this->paths->legacySystemdServiceDefinitionFile(),
                (string)($metadata['definition'] ?? ''),
            );
    }

    private function recoverLinuxSystemdLayoutMigrationIfPending(): void
    {
        if (!$this->managesLinuxSystemdLayout()) {
            return;
        }
        (new GatewayLinuxSystemdLayoutMigration($this->paths))->recoverPending(
            function (): void {
                $this->mustRun(
                ['/bin/systemctl', 'daemon-reload'],
                'systemd layout migration daemon-reload',
                );
            },
        );
    }

    /**
     * Convert the one exact schema-1 systemd layout only from an explicit
     * administrator mutation path.  installedDefinition() deliberately does
     * not call this method: status is read-only and must never replace the
     * canonical unit by observation.
     */
    private function migrateLegacyLinuxSystemdLayoutIfRequired(): void
    {
        if (!$this->managesLinuxSystemdLayout()) {
            return;
        }
        $metadataPath = $this->paths->platformServiceMetadataFile();
        $oldMetadata = GatewayProjectStateFilesystem::read(
            $metadataPath,
            16_384,
            'Installed WLS Gateway platform metadata before systemd layout migration',
        );
        $decoded = $this->decodePlatformServiceMetadata($oldMetadata);
        if (!$this->metadataUsesLegacyLinuxSystemdLayout($decoded)) {
            return;
        }
        $profile = (string)$decoded['profile'];
        $oldDefinition = $this->renderLegacyLinuxSystemdDefinition($profile);
        $this->linuxSystemdLayout()->assertExactLegacyDefinition(
            $oldDefinition,
        );
        $newMetadata = $decoded;
        $newMetadata['definition'] = $this->paths->serviceDefinitionFile();
        $newMetadataRaw = \json_encode(
            $newMetadata,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $newDefinition = $this->renderDefinition($profile);
        (new GatewayLinuxSystemdLayoutMigration($this->paths))->migrate(
            $profile,
            $oldDefinition,
            $oldMetadata,
            $newDefinition,
            $newMetadataRaw,
            function (): void {
                $this->mustRun(
                    ['/bin/systemctl', 'daemon-reload'],
                    'systemd layout migration daemon-reload',
                );
            },
        );
    }

    private function renderLegacyLinuxSystemdDefinition(string $profile): string
    {
        if (!$this->managesLinuxSystemdLayout()) {
            throw new \LogicException(
                'Legacy systemd rendering is unavailable outside production Linux.',
            );
        }
        $current = $this->renderDefinition($profile);
        $dedicatedDirectory = ' "' . $this->escapeForTemplate(
            $this->paths->systemdDefinitionDirectory(),
        ) . '"';
        if (\substr_count($current, $dedicatedDirectory) !== 1) {
            throw new \RuntimeException(
                'WLS Gateway systemd template cannot derive the exact schema-1 unit layout.',
            );
        }
        return \str_replace($dedicatedDirectory, '', $current);
    }

    private function linuxSystemdLayout(): GatewayLinuxSystemdLayout
    {
        if (!$this->managesLinuxSystemdLayout()) {
            throw new \LogicException(
                'The WLS Gateway Linux systemd layout is unavailable on this platform.',
            );
        }
        return new GatewayLinuxSystemdLayout($this->paths);
    }

    /** @param array<string,mixed> $journal */
    private function ensureLinuxSystemdFixedLinkForPlatformTransaction(
        array $journal,
    ): void {
        if (!$this->managesLinuxSystemdLayout()) {
            return;
        }
        $metadata = $this->decodePlatformServiceMetadata(
            (string)$journal['new_metadata'],
        );
        if ($this->metadataUsesLegacyLinuxSystemdLayout($metadata)) {
            throw new \RuntimeException(
                'A schema-1 systemd metadata image cannot publish a new platform definition transaction.',
            );
        }
        $this->linuxSystemdLayout()->publishNewDefinitionAndFixedLink(
            (string)$journal['new_definition'],
        );
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
        ) . "\n";
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

        if ($this->managesLinuxSystemdLayout()) {
            // Validate the immutable canonical-name boundary before the
            // generic transaction mutates its separate mutable target.
            $this->linuxSystemdLayout()
                ->assertCanonicalLinkAvailableForDefinitionPublication();
        }
        if ($definitionState === 'old') {
            $this->atomicWrite(
                $this->paths->serviceDefinitionFile(),
                (string)$journal['new_definition'],
                $this->paths->isTestMode() ? 0600 : 0644,
            );
        }
        $this->ensureLinuxSystemdFixedLinkForPlatformTransaction($journal);
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
        ) {
            if (\PHP_OS_FAMILY === 'Windows') {
                // The virtual service SID is usable only after SCM creates
                // the disabled service in start(). Keep the root-only journal
                // as explicit bootstrap authority until that method seals all
                // fixed DACLs; a crash can then retry without universally
                // accepting a stale bootstrap ACL.
                $this->cleanupPlatformDefinitionTransactionTargetArtifacts(
                    $journal,
                );
                return [
                    'operation' => (string)$journal['operation'],
                    'to_profile' => (string)$journal['to_profile'],
                ];
            }
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

    private function completeWindowsInstallPermissionTransaction(): void
    {
        if ($this->paths->isTestMode() || \PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $this->withPackageInstallLock(
            function (?array $recovered): void {
                if ($recovered === null) {
                    return;
                }
                if (!\hash_equals(
                    'install',
                    (string)($recovered['operation'] ?? ''),
                )) {
                    throw new \RuntimeException(
                        'Windows gateway permission sealing encountered a non-install platform transaction.',
                    );
                }
                $journal = $this->decodePlatformDefinitionTransaction(
                    $this->readStableRegularFile(
                        $this->platformDefinitionTransactionFile(),
                        self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                        'WLS Gateway Windows permission-seal transaction',
                    ),
                );
                $this->assertPlatformDefinitionTransactionTargetsInClosure(
                    $journal,
                );
                $this->cleanupPlatformDefinitionTransactionTargetArtifacts(
                    $journal,
                );
                $this->removePlatformDefinitionTransaction($journal);
            },
        );
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
        // The host bootstrap lock does not prevent an administrator or a
        // package manager from registering the same platform service name.
        // Recheck under the installation lock before rendering, configuring,
        // enabling or stopping anything.
        $this->assertInitialServiceRegistrationAbsent();
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
            . "\n";
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
    public function refreshDefinition(
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            fn (): array => $this->refreshDefinitionWithinDeadline($profile),
        );
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    private function refreshDefinitionWithinDeadline(string $profile): array
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
            $this->migrateLegacyLinuxSystemdLayoutIfRequired();
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
            ) . "\n";
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

    /**
     * Capture the exact installed platform definition into the fixed
     * whole-generation backup namespace before the gateway is stopped.
     *
     * @return array{kind:string,profile:string,definition_sha256:string,metadata_sha256:string}
     */
    public function snapshotRebootstrapDefinition(
        string $nonce,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            fn (): array => $this->snapshotRebootstrapDefinitionWithinDeadline(
                $nonce,
            ),
        );
    }

    /** @return array{kind:string,profile:string,definition_sha256:string,metadata_sha256:string} */
    private function snapshotRebootstrapDefinitionWithinDeadline(
        string $nonce,
    ): array
    {
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $this->paths->ensureDirectories();
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        return $this->withPackageInstallLock(function (?array $_recovered) use (
            $backup,
        ): array {
            $this->migrateLegacyLinuxSystemdLayoutIfRequired();
            $platformBackup = $backup . DIRECTORY_SEPARATOR . 'platform';
            $this->ensurePrivateRebootstrapDirectory($backup);
            $this->ensurePrivateRebootstrapDirectory($platformBackup);
            $metadata = $this->readStableRegularFile(
                $this->paths->platformServiceMetadataFile(),
                16_384,
                'Installed gateway platform metadata before rebootstrap',
            );
            $decoded = $this->decodePlatformServiceMetadata($metadata);
            $definition = $this->readStableRegularFile(
                $this->paths->serviceDefinitionFile(),
                1_048_576,
                'Installed gateway platform definition before rebootstrap',
            );
            if (!\hash_equals(
                $this->renderDefinition((string)$decoded['profile']),
                $definition,
            )) {
                throw new \RuntimeException(
                    'Gateway platform definition is not bound to its installed profile before rebootstrap.'
                );
            }
            $definitionBackup = $platformBackup . DIRECTORY_SEPARATOR
                . 'definition.before';
            $metadataBackup = $platformBackup . DIRECTORY_SEPARATOR
                . 'metadata.before';
            $this->writeExactRebootstrapBackup(
                $definitionBackup,
                $definition,
                'gateway platform definition backup',
            );
            $this->writeExactRebootstrapBackup(
                $metadataBackup,
                $metadata,
                'gateway platform metadata backup',
            );
            return [
                'kind' => (string)$decoded['kind'],
                'profile' => (string)$decoded['profile'],
                'definition_sha256' => \hash('sha256', $definition),
                'metadata_sha256' => \hash('sha256', $metadata),
            ];
        });
    }

    /**
     * Restore the exact captured definition through the existing schema-1
     * platform transaction. The service must already be persistently stopped.
     *
     * @param array{kind:string,profile:string,definition_sha256:string,metadata_sha256:string} $snapshot
     */
    public function restoreRebootstrapDefinition(
        string $nonce,
        array $snapshot,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($nonce, $snapshot): void {
                $this->restoreRebootstrapDefinitionWithinDeadline(
                    $nonce,
                    $snapshot,
                );
            },
        );
    }

    /**
     * @param array{kind:string,profile:string,definition_sha256:string,metadata_sha256:string} $snapshot
     */
    private function restoreRebootstrapDefinitionWithinDeadline(
        string $nonce,
        array $snapshot,
    ): void {
        $backup = $this->paths->rebootstrapBackupDir($nonce)
            . DIRECTORY_SEPARATOR . 'platform';
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        $this->withPackageInstallLock(function (?array $_recovered) use (
            $backup,
            $snapshot,
        ): null {
            $this->assertRebootstrapPlatformSnapshot($snapshot);
            $kind = (string)$snapshot['kind'];
            $this->assertPlatformServiceStopped($kind);
            $oldDefinition = $this->readStableRegularFile(
                $backup . DIRECTORY_SEPARATOR . 'definition.before',
                1_048_576,
                'Gateway rebootstrap platform definition backup',
            );
            $oldMetadata = $this->readStableRegularFile(
                $backup . DIRECTORY_SEPARATOR . 'metadata.before',
                16_384,
                'Gateway rebootstrap platform metadata backup',
            );
            if (!\hash_equals(
                    (string)$snapshot['definition_sha256'],
                    \hash('sha256', $oldDefinition),
                )
                || !\hash_equals(
                    (string)$snapshot['metadata_sha256'],
                    \hash('sha256', $oldMetadata),
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap platform backup digest is invalid.'
                );
            }
            $oldDecoded = $this->decodePlatformServiceMetadata($oldMetadata);
            if (!\hash_equals($kind, (string)$oldDecoded['kind'])
                || !\hash_equals(
                    (string)$snapshot['profile'],
                    (string)$oldDecoded['profile'],
                )
                || !\hash_equals(
                    $this->renderDefinition((string)$oldDecoded['profile']),
                    $oldDefinition,
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap platform backup no longer matches the trusted installer template.'
                );
            }
            $currentMetadata = $this->readStableRegularFile(
                $this->paths->platformServiceMetadataFile(),
                16_384,
                'Current gateway platform metadata during rebootstrap rollback',
            );
            $currentDecoded = $this->decodePlatformServiceMetadata(
                $currentMetadata,
            );
            $currentDefinition = $this->readStableRegularFile(
                $this->paths->serviceDefinitionFile(),
                1_048_576,
                'Current gateway platform definition during rebootstrap rollback',
            );
            if (\hash_equals($oldDefinition, $currentDefinition)
                && \hash_equals($oldMetadata, $currentMetadata)
            ) {
                return null;
            }
            $journal = $this->newPlatformDefinitionTransaction(
                'refresh',
                (string)$currentDecoded['profile'],
                (string)$oldDecoded['profile'],
                $currentDefinition,
                $currentMetadata,
                $oldDefinition,
                $oldMetadata,
            );
            $this->publishPlatformDefinitionTransaction($journal);
            $this->recoverPlatformDefinitionTransaction();
            $installed = $this->installedDefinition();
            if (!\hash_equals($kind, (string)$installed['kind'])) {
                throw new \RuntimeException(
                    'Gateway platform definition rollback identity is invalid.'
                );
            }
            return null;
        });
    }

    /**
     * Seal the stopped whole-generation backup before the replacement
     * service is allowed to start. On Windows every reachable backup object
     * receives an exact, protected LocalSystem + Administrators DACL. The
     * service SID, project identities and inherited ProgramData grants are
     * therefore removed instead of being carried into the rollback copy.
     * This is an ACL-boundary repair, not isolation from the platform TCB:
     * the Windows service currently runs as LocalSystem and deliberately
     * retains access through the SYSTEM ACE.
     *
     * POSIX already isolates this namespace through its 0700 rebootstrap
     * parent. Keep its existing ownership/modes unchanged, but re-prove the
     * bounded no-follow closure and reject group/other-writable descendants.
     */
    public function secureRebootstrapBackup(
        string $nonce,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($nonce): void {
                $this->paths->ensureDirectories();
                if (!$this->paths->isTestMode()) {
                    $this->assertAdministrator();
                }
                $this->withPackageInstallLock(
                    function (?array $_recovered) use ($nonce): void {
                        $this->secureRebootstrapBackupWithinDeadline($nonce);
                    },
                );
            },
        );
    }

    private function secureRebootstrapBackupWithinDeadline(
        string $nonce,
        bool $requireFullGeneration = true,
        ?string $backupOverride = null,
    ): void {
        $this->assertTraversalDeadline();
        $backup = $backupOverride ?? $this->paths->rebootstrapBackupDir($nonce);
        $expectedParent = $this->paths->rebootstrapBackupsDir();
        $resolvedBackup = \realpath($backup);
        $resolvedParent = \realpath($expectedParent);
        $normalize = static fn (string $path): string => \PHP_OS_FAMILY === 'Windows'
            ? \strtolower(\rtrim(\str_replace('\\', '/', $path), '/'))
            : \rtrim($path, '/');
        if (!\is_string($resolvedBackup)
            || !\is_string($resolvedParent)
            || \is_link($backup)
            || !\is_dir($backup)
            || !\hash_equals(
                $normalize($resolvedParent),
                $normalize(\dirname($resolvedBackup)),
            )
            || !\hash_equals($normalize($backup), $normalize($resolvedBackup))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL target is not the fixed backup directory.'
            );
        }

        $topLevel = $this->rebootstrapBackupTopLevelInventory($backup);
        $entries = $topLevel['entries'];
        if ($requireFullGeneration) {
            foreach (['bin', 'platform', 'slots', 'trust'] as $required) {
                if (!isset($entries[$required])
                    || !$entries[$required]['directory']
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap backup lacks required ACL root: '
                            . $required
                    );
                }
            }
        }
        $hasDerived = isset($entries['derived']);
        $hasDerivedManifest = isset($entries['derived-state.manifest.json']);
        if ($hasDerived !== $hasDerivedManifest) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived backup and manifest must be paired.'
            );
        }

        /**
         * @var array<string,array{
         *   root:array<string,mixed>,
         *   segments:array<string,list<array<string,mixed>>>
         * }> $treeClosures
         */
        $treeClosures = [];
        $remainingEntries = self::REBOOTSTRAP_BACKUP_ACL_TOTAL_ENTRY_QUOTA;
        foreach ($entries as $entry) {
            if (!(bool)$entry['directory']) {
                --$remainingEntries;
            }
        }
        foreach (self::REBOOTSTRAP_BACKUP_ACL_ROOTS as $leaf) {
            if (!isset($entries[$leaf])) {
                continue;
            }
            if (!$entries[$leaf]['directory']) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ACL root is not a directory: ' . $leaf
                );
            }
            $this->assertTraversalDeadline();
            $closure = $this->rebootstrapBackupAclTreeClosure(
                $entries[$leaf],
                $remainingEntries,
            );
            $treeClosures[$leaf] = $closure;
        }

        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->assertPrivatePosixRebootstrapBackup(
                $backup,
                $topLevel,
                $treeClosures,
            );
            return;
        }

        $serviceIdentity = 'NT SERVICE\\' . self::SERVICE_NAME;
        $this->withWindowsRebootstrapBackupIdentityHandles(
            [$topLevel['root']],
            function () use (
                $backup,
                $entries,
                $hasDerivedManifest,
                $serviceIdentity,
                $topLevel,
                $treeClosures,
            ): void {
                // The root handle denies FILE_SHARE_DELETE for the complete
                // transition. Seal its DACL first so explicit virtual-service,
                // project and controller ACEs cannot add another top-level
                // name while child handles are acquired and resealed.
                $this->applyWindowsAcl(
                    $backup,
                    $serviceIdentity,
                    'NONE',
                    recursive: false,
                    inheritChildren: false,
                );
                $this->assertRebootstrapBackupObjectUnchanged(
                    $topLevel['root'],
                );

                foreach ($treeClosures as $closure) {
                    foreach ($closure['segments'] as $segment => $records) {
                        $this->assertTraversalDeadline();
                        $this->assertRebootstrapBackupRecordsUnchanged(
                            $records,
                            false,
                        );
                        $this->withWindowsRebootstrapBackupIdentityHandles(
                            $records,
                            function () use (
                                $records,
                                $segment,
                                $serviceIdentity,
                            ): void {
                                $first = $records[0];
                                $this->applyWindowsAcl(
                                    (string)$first['path'],
                                    $serviceIdentity,
                                    'NONE',
                                    maximumEntries: self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                                    recursive: $segment !== '.'
                                        && (bool)$first['directory'],
                                    inheritChildren: false,
                                );
                                $this->assertRebootstrapBackupRecordsUnchanged(
                                    $records,
                                    false,
                                );
                            },
                        );
                    }
                }
                if ($hasDerivedManifest) {
                    $manifest = $entries['derived-state.manifest.json'];
                    $this->withWindowsRebootstrapBackupIdentityHandles(
                        [$manifest],
                        function () use (
                            $manifest,
                            $serviceIdentity,
                        ): void {
                            $this->applyWindowsAcl(
                                $manifest['path'],
                                $serviceIdentity,
                                'NONE',
                                inheritChildren: false,
                            );
                            $this->assertRebootstrapBackupObjectUnchanged(
                                $manifest,
                            );
                        },
                    );
                }

                $this->assertRebootstrapBackupTopLevelUnchanged(
                    $topLevel,
                    $this->rebootstrapBackupTopLevelInventory($backup),
                );
                $verificationRemaining =
                    self::REBOOTSTRAP_BACKUP_ACL_TOTAL_ENTRY_QUOTA
                        - ($hasDerivedManifest ? 1 : 0);
                foreach ($treeClosures as $leaf => $closure) {
                    foreach ($closure['segments'] as $records) {
                        $this->assertRebootstrapBackupRecordsUnchanged(
                            $records,
                            false,
                        );
                    }
                    $this->assertSameRebootstrapBackupAclTreeClosure(
                        $closure,
                        $this->rebootstrapBackupAclTreeClosure(
                            $entries[$leaf],
                            $verificationRemaining,
                        ),
                    );
                }
            },
        );
    }

    /**
     * Re-prove the persistent supervisor stop after any crash/re-entry.
     *
     * @return array{kind:string,stopped:true,test_mode:bool,definition_sha256:string,metadata_sha256:string}
     */
    public function persistentStoppedProof(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): array
    {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            self::SERVICE_OPERATION_TIMEOUT_SECONDS,
            fn (): array => $this->persistentStoppedProofWithinDeadline($kind),
        );
    }

    /** @return array{kind:string,stopped:true,test_mode:bool,definition_sha256:string,metadata_sha256:string} */
    private function persistentStoppedProofWithinDeadline(string $kind): array
    {
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        $installed = $this->installedDefinition();
        if (!\hash_equals((string)$installed['kind'], $kind)) {
            throw new \RuntimeException(
                'Gateway persistent-stop proof kind does not match installed metadata.'
            );
        }
        $this->assertPlatformServiceStopped($kind);
        $metadata = $this->readStableRegularFile(
            $this->paths->platformServiceMetadataFile(),
            16_384,
            'Gateway platform metadata during persistent-stop proof',
        );
        $definition = $this->readStableRegularFile(
            (string)$installed['path'],
            1_048_576,
            'Gateway platform definition during persistent-stop proof',
        );
        return [
            'kind' => $kind,
            'stopped' => true,
            'test_mode' => $this->paths->isTestMode(),
            'definition_sha256' => \hash('sha256', $definition),
            'metadata_sha256' => \hash('sha256', $metadata),
        ];
    }

    public function start(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::SERVICE_OPERATION_TIMEOUT_SECONDS,
            function () use ($kind): void {
                $this->startWithinDeadline($kind);
            },
        );
    }

    private function startWithinDeadline(string $kind): void
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
            $installed = $this->installedDefinition();
            if (!\hash_equals('systemd-system', (string)$installed['kind'])
                || !\hash_equals(
                    $this->paths->serviceDefinitionFile(),
                    (string)$installed['path'],
                )
            ) {
                throw new \RuntimeException(
                    'The installed WLS Gateway systemd unit uses the legacy layout; run platform refresh before activation.',
                );
            }
            $this->linuxSystemdLayout()->assertCurrentDefinitionAndFixedLink(
                $this->readStableRegularFile(
                    $this->paths->serviceDefinitionFile(),
                    1_048_576,
                    'Installed WLS Gateway systemd target before activation',
                ),
            );
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
                ['/bin/systemctl', 'enable', '--now', $this->paths->serviceDefinitionFile()],
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
    public function secureInstalledRuntimeSlot(
        string $slotDirectory,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($slotDirectory): void {
                $this->secureInstalledRuntimeSlotWithinDeadline($slotDirectory);
            },
        );
    }

    private function secureInstalledRuntimeSlotWithinDeadline(
        string $slotDirectory,
    ): void {
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
        $this->secureImmutableRuntimeDirectory($resolved);
    }

    /**
     * Seal the fixed, root-owned rebootstrap candidate before privileged
     * self-tests. The candidate remains outside A/B and inaccessible through
     * the running service namespace until the stopped whole-host transaction
     * atomically renames it into slot A.
     */
    public function secureRebootstrapCandidateRuntime(
        string $candidateDirectory,
        string $nonce,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($candidateDirectory, $nonce): void {
                $this->secureRebootstrapCandidateRuntimeWithinDeadline(
                    $candidateDirectory,
                    $nonce,
                );
            },
        );
    }

    private function secureRebootstrapCandidateRuntimeWithinDeadline(
        string $candidateDirectory,
        string $nonce,
    ): void {
        if ($this->paths->isTestMode()) {
            return;
        }
        $this->assertAdministrator();
        $expected = $this->paths->rebootstrapCandidateDir($nonce);
        $resolved = \realpath($candidateDirectory);
        $expectedResolved = \realpath($expected);
        if (!\is_string($resolved)
            || !\is_string($expectedResolved)
            || \is_link($candidateDirectory)
            || !\hash_equals($expectedResolved, $resolved)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap permission target is not the fixed candidate directory.'
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->secureWindowsRebootstrapWorkspaceAcl();
        }
        $this->secureImmutableRuntimeDirectory($resolved);
    }

    private function secureImmutableRuntimeDirectory(string $resolved): void
    {
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
            // Before SCM creates the service, seal only this staged slot with
            // SYSTEM/Administrators. The host root already has its exact
            // Known-Folder authority profile; rewriting that root here would
            // invalidate subsequent bootstrap checks. start() later replaces
            // the slot DACL with the exact service-SID read profile.
            $this->assertWindowsTreeHasNoLinks($resolved);
            $this->applyWindowsAcl(
                $resolved,
                'NT SERVICE\\' . self::SERVICE_NAME,
                'NONE',
            );
            return;
        }
        [$account, $dataPlaneAccount] = self::posixServiceAccountNames(
            \PHP_OS_FAMILY,
        );
        $identity = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($account)
            : false;
        $dataPlaneIdentity = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($dataPlaneAccount)
            : false;
        $groupIdentity = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($account)
            : false;
        $dataPlaneGroupIdentity = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($dataPlaneAccount)
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
        if (\is_array($dataPlaneIdentity)
            && (!\is_array($dataPlaneGroupIdentity)
                || !self::posixServiceIdentityIsValid(
                    $dataPlaneIdentity,
                    $dataPlaneGroupIdentity,
                    \PHP_OS_FAMILY,
                    'data-plane',
                ))
        ) {
            throw new \RuntimeException(
                'The existing WLS Gateway data-plane identity is unsafe.'
            );
        }
        $group = \is_array($identity) ? (int)$identity['gid'] : 0;
        if ($group < 1 || !\is_array($dataPlaneIdentity)) {
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
            $dataPlaneIdentity = \function_exists('posix_getpwnam')
                ? @\posix_getpwnam($dataPlaneAccount)
                : false;
            $dataPlaneGroupIdentity = \function_exists('posix_getgrnam')
                ? @\posix_getgrnam($dataPlaneAccount)
                : false;
            if (!\is_array($identity)
                || !\is_array($groupIdentity)
                || !self::posixServiceIdentityIsValid(
                    $identity,
                    $groupIdentity,
                    \PHP_OS_FAMILY,
                )
                || !\is_array($dataPlaneIdentity)
                || !\is_array($dataPlaneGroupIdentity)
                || !self::posixServiceIdentityIsValid(
                    $dataPlaneIdentity,
                    $dataPlaneGroupIdentity,
                    \PHP_OS_FAMILY,
                    'data-plane',
                )
            ) {
                throw new \RuntimeException(
                    'Dedicated WLS Gateway service identities are unavailable.'
                );
            }
            $group = (int)$identity['gid'];
        }
        $this->assertPosixIdentitySeparation($identity, $dataPlaneIdentity);
        $this->secureRuntimeTree($resolved, $group, [], true);
    }

    /**
     * Establish the host trust boundary before opening the installation lock.
     * The Controller never owns this tree; the native Broker/launcher use the
     * LocalSystem/root identity for all mutations below it.
     */
    public function securePackageTransactionTrust(
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function (): void {
                $this->securePackageTransactionTrustWithinDeadline();
            },
        );
    }

    /** @return array{sha256:string,sddl_b64:string} */
    public function captureRebootstrapDerivedRootAuthority(
        string $root,
        array $expectedIdentity,
        ?string $expectedProfile = null,
    ): array {
        $this->assertRebootstrapDerivedRootPath($root);
        $profile = $this->rebootstrapDerivedRootAuthorityProfile($root);
        if ($expectedProfile !== null
            && !\hash_equals($expectedProfile, $profile)
        ) {
            throw new \RuntimeException(
                'Windows derived-root authority profile does not match its fixed namespace.',
            );
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows derived-root authority capture is unavailable on this platform.',
            );
        }
        $contract = $this->rebootstrapDerivedRootWindowsContract($root);
        $expected = $this->windowsDerivedCanonicalAuthorityContracts([
            $contract,
        ])[0];
        $sddl = GatewayWindowsHostRootAuthority::captureExactPathSddl(
            $root,
            true,
            $expectedIdentity,
        );
        if (!\hash_equals($expected['sddl'], $sddl)) {
            throw new \RuntimeException(
                'Windows derived-root authority differs from its exact canonical profile.',
            );
        }
        $encoded = \base64_encode($sddl);
        return [
            'sha256' => \hash('sha256', $sddl),
            'sddl_b64' => $encoded,
        ];
    }

    public function restoreRebootstrapDerivedRootAuthority(
        string $root,
        string $sddlBase64,
        string $expectedSha256,
        array $expectedIdentity,
        ?string $expectedProfile = null,
    ): void {
        $this->assertRebootstrapDerivedRootPath($root);
        $profile = $this->rebootstrapDerivedRootAuthorityProfile($root);
        if ($expectedProfile !== null
            && !\hash_equals($expectedProfile, $profile)
        ) {
            throw new \RuntimeException(
                'Windows derived-root restore profile does not match its fixed namespace.',
            );
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows derived-root authority restore is unavailable on this platform.',
            );
        }
        $sddl = \base64_decode($sddlBase64, true);
        if (!\is_string($sddl)
            || $sddl === ''
            || \strlen($sddl) > 8192
            || \str_contains($sddl, "\0")
            || !\hash_equals(\base64_encode($sddl), $sddlBase64)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1
            || !\hash_equals($expectedSha256, \hash('sha256', $sddl))
        ) {
            throw new \RuntimeException(
                'Windows derived-root authority restore proof is invalid.',
            );
        }
        $contract = $this->rebootstrapDerivedRootWindowsContract($root);
        $expected = $this->windowsDerivedCanonicalAuthorityContracts([
            $contract,
        ])[0]['sddl'];
        $canonical = GatewayWindowsHostRootAuthority::canonicalizeSddl($sddl);
        if (!\hash_equals($sddl, $canonical)
            || !\hash_equals($expected, $canonical)
        ) {
            throw new \RuntimeException(
                'Windows derived-root restore proof is not its exact canonical profile.',
            );
        }
        $restored = GatewayWindowsHostRootAuthority::applyExactPathSddl(
            $root,
            true,
            $canonical,
            $expectedIdentity,
        );
        if (!\hash_equals($canonical, $restored)) {
            throw new \RuntimeException(
                'Windows derived-root authority changed while it was restored.',
            );
        }
    }

    /**
     * Capture the exact protected Windows DACL for one derived-state object.
     * The returned SDDL is canonical Access+Owner state and is accepted only
     * when it is one of the fixed profile variants produced by WLS 2.0.
     *
     * @return array{acl_profile:string,owner_sid:string,sddl_b64:string,sha256:string}
     */
    public function captureRebootstrapDerivedDescendantAuthority(
        string $path,
        bool $directory,
        string $profile,
    ): array {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows derived-state descendant authority capture is unavailable on this platform.',
            );
        }
        $this->assertRebootstrapDerivedDescendantProfilePath($path, $profile);
        $record = $this->rebootstrapBackupObjectRecord($path, $directory);
        $contracts = $this->rebootstrapDerivedDescendantWindowsContracts(
            $path,
            $directory,
            $profile,
        );
        $proof = null;
        $this->withWindowsRebootstrapBackupIdentityHandles(
            [$record],
            function () use (
                $path,
                $directory,
                $contracts,
                $record,
                &$proof,
            ): void {
                $proof = $this->windowsDerivedDescendantAuthorityProof(
                    $path,
                    $directory,
                    $contracts,
                    $record,
                );
            },
        );
        if (!\is_array($proof)) {
            throw new \RuntimeException(
                'Windows derived-state descendant authority proof was not produced.',
            );
        }
        return [
            'acl_profile' => $profile,
            'owner_sid' => $proof['owner_sid'],
            'sddl_b64' => $proof['sddl_b64'],
            'sha256' => \hash('sha256', $proof['sddl']),
        ];
    }

    public function restoreRebootstrapDerivedDescendantAuthority(
        string $path,
        bool $directory,
        string $profile,
        string $ownerSid,
        string $sddlBase64,
        string $expectedSha256,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows derived-state descendant authority restore is unavailable on this platform.',
            );
        }
        $this->assertRebootstrapDerivedDescendantProfilePath($path, $profile);
        $sddl = self::validatedWindowsDerivedAuthorityProof(
            $ownerSid,
            $sddlBase64,
            $expectedSha256,
        );
        $record = $this->rebootstrapBackupObjectRecord($path, $directory);
        $contracts = $this->rebootstrapDerivedDescendantWindowsContracts(
            $path,
            $directory,
            $profile,
        );
        $proof = null;
        $this->withWindowsRebootstrapBackupIdentityHandles(
            [$record],
            function () use (
                $path,
                $directory,
                $contracts,
                $record,
                $ownerSid,
                $sddlBase64,
                &$proof,
            ): void {
                $proof = $this->windowsDerivedDescendantAuthorityProof(
                    $path,
                    $directory,
                    $contracts,
                    $record,
                    $ownerSid,
                    $sddlBase64,
                );
            },
        );
        if (!\is_array($proof)
            || !\hash_equals($ownerSid, $proof['owner_sid'])
            || !\hash_equals($sddlBase64, $proof['sddl_b64'])
            || !\hash_equals($sddl, $proof['sddl'])
        ) {
            throw new \RuntimeException(
                'Windows derived-state descendant authority changed while it was restored.',
            );
        }
    }

    public function assertRebootstrapDerivedBackupDescendantAuthority(
        string $path,
        bool $directory,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows derived-state backup authority validation is unavailable on this platform.',
            );
        }
        $this->assertRebootstrapDerivedBackupDescendantPath($path);
        $record = $this->rebootstrapBackupObjectRecord($path, $directory);
        $contracts = [[
            'owner_sid' => 'S-1-5-32-544',
            'service_sid' => '',
            'rights' => '',
            'sddl_rights' => '',
            'inheritance' => '',
        ]];
        $this->withWindowsRebootstrapBackupIdentityHandles(
            [$record],
            function () use ($path, $directory, $contracts, $record): void {
                $this->windowsDerivedDescendantAuthorityProof(
                    $path,
                    $directory,
                    $contracts,
                    $record,
                );
            },
        );
    }

    private static function validatedWindowsDerivedAuthorityProof(
        string $ownerSid,
        string $sddlBase64,
        string $expectedSha256,
    ): string {
        $sddl = \base64_decode($sddlBase64, true);
        if (!\in_array($ownerSid, ['S-1-5-18', 'S-1-5-32-544'], true)
            || !\is_string($sddl)
            || $sddl === ''
            || \strlen($sddl) > 8192
            || \str_contains($sddl, "\0")
            || !\hash_equals(\base64_encode($sddl), $sddlBase64)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1
            || !\hash_equals($expectedSha256, \hash('sha256', $sddl))
        ) {
            throw new \RuntimeException(
                'Windows derived-state descendant authority proof is invalid.',
            );
        }
        return $sddl;
    }

    private function assertRebootstrapDerivedDescendantProfilePath(
        string $path,
        string $profile,
    ): void {
        $roots = match ($profile) {
            self::DERIVED_AUTHORITY_HOME => [$this->paths->home()],
            self::DERIVED_AUTHORITY_STATE => [$this->paths->stateDir()],
            self::DERIVED_AUTHORITY_TRUST => [$this->paths->trustDir()],
            self::DERIVED_AUTHORITY_SNAPSHOTS_V1
                => [$this->paths->legacySnapshotsDir()],
            self::DERIVED_AUTHORITY_SNAPSHOTS_V2
                => [$this->paths->sealedSnapshotsDir()],
            self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2
                => [$this->paths->snapshotCandidatesDir()],
            self::DERIVED_AUTHORITY_RUNTIME => [$this->paths->runtimeDir()],
            self::DERIVED_AUTHORITY_RUNTIME_CHILD => [
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'conf',
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'temp',
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow',
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'run',
            ],
            default => throw new \RuntimeException(
                'Windows derived-state descendant authority profile is unsupported.',
            ),
        };
        $candidate = \rtrim(\str_replace('\\', '/', $path), '/');
        $segments = \explode('/', $candidate);
        if ($candidate === ''
            || \str_contains($candidate, "\0")
            || \in_array('.', $segments, true)
            || \in_array('..', $segments, true)
        ) {
            throw new \RuntimeException(
                'Windows derived-state descendant path is invalid.',
            );
        }
        foreach ($roots as $root) {
            $prefix = \rtrim(\str_replace('\\', '/', $root), '/') . '/';
            if (\strncasecmp($candidate, $prefix, \strlen($prefix)) === 0) {
                return;
            }
        }
        throw new \RuntimeException(
            'Windows derived-state descendant escaped its fixed authority namespace.',
        );
    }

    private function assertRebootstrapDerivedBackupDescendantPath(
        string $path,
    ): void {
        $candidate = \str_replace('\\', '/', $path);
        $root = \rtrim(\str_replace(
            '\\',
            '/',
            $this->paths->rebootstrapBackupsDir(),
        ), '/');
        if (\str_contains($candidate, "\0")
            || \in_array('..', \explode('/', $candidate), true)
            || \in_array('.', \explode('/', $candidate), true)
            || \preg_match(
            '/\A' . \preg_quote($root, '/')
                . '\/[a-f0-9]{32}\/derived\/.+\z/Di',
            $candidate,
        ) !== 1) {
            throw new \RuntimeException(
                'Windows derived-state backup descendant escaped its fixed transaction namespace.',
            );
        }
    }

    /**
     * @return list<array{owner_sid:string,service_sid:string,rights:string,sddl_rights:string,inheritance:string}>
     */
    private function rebootstrapDerivedDescendantWindowsContracts(
        string $path,
        bool $directory,
        string $profile,
    ): array {
        $inheritance = $directory ? 'OICI' : '';
        $controller = self::WINDOWS_CONTROLLER_SERVICE_SID;
        $dataPlane = self::WINDOWS_DATA_PLANE_SERVICE_SID;
        $contract = static function (
            string $ownerSid,
            string $serviceSid,
            string $rights,
            string $inheritance,
        ): array {
            return [
                'owner_sid' => $ownerSid,
                'service_sid' => $serviceSid,
                'rights' => $rights,
                'sddl_rights'
                    => self::windowsDerivedAuthoritySddlRights($rights),
                'inheritance' => $inheritance,
            ];
        };
        if ($profile === self::DERIVED_AUTHORITY_TRUST
            && !$directory
            && $this->windowsDerivedTrustDescendantIsRootOnly($path)
        ) {
            return [[
                'owner_sid' => 'S-1-5-32-544',
                'service_sid' => '',
                'rights' => '',
                'sddl_rights' => '',
                'inheritance' => '',
            ]];
        }
        if ($profile === self::DERIVED_AUTHORITY_RUNTIME_CHILD
            && !$directory
            && \strcasecmp(
                \str_replace('\\', '/', $path),
                \str_replace(
                    '\\',
                    '/',
                    $this->paths->launcherRecoveryStatusFile(),
                ),
            ) === 0
        ) {
            return [
                $contract(
                    'S-1-5-32-544',
                    'S-1-5-32-545',
                    'R',
                    '',
                ),
            ];
        }
        return match ($profile) {
            self::DERIVED_AUTHORITY_HOME,
            self::DERIVED_AUTHORITY_TRUST => [
                $contract(
                    'S-1-5-32-544',
                    $controller,
                    'RX',
                    $inheritance,
                ),
            ],
            self::DERIVED_AUTHORITY_STATE,
            self::DERIVED_AUTHORITY_SNAPSHOTS_V1,
            self::DERIVED_AUTHORITY_RUNTIME,
            self::DERIVED_AUTHORITY_RUNTIME_CHILD => [
                $contract(
                    'S-1-5-32-544',
                    $controller,
                    'M',
                    $inheritance,
                ),
            ],
            self::DERIVED_AUTHORITY_SNAPSHOTS_V2 => [
                $contract(
                    'S-1-5-18',
                    $dataPlane,
                    $directory ? 'GRGX' : 'GR',
                    '',
                ),
            ],
            // A crash may leave a candidate either in its exact controller
            // staging DACL or after the native Broker has applied the exact
            // immutable data-plane seal. No other superset is accepted.
            self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2 => [
                $contract('S-1-5-18', $controller, 'GA', ''),
                $contract(
                    'S-1-5-18',
                    $dataPlane,
                    $directory ? 'GRGX' : 'GR',
                    '',
                ),
            ],
            default => throw new \RuntimeException(
                'Windows derived-state descendant authority profile is unsupported.',
            ),
        };
    }

    private function windowsDerivedTrustDescendantIsRootOnly(
        string $path,
    ): bool {
        $leaf = \basename(\str_replace('\\', '/', $path));
        $base = (string)\preg_replace(
            '/\.(?:wls-backup-[a-f0-9]{16}|tmp-[a-f0-9]{24})\z/Di',
            '',
            $leaf,
        );
        foreach ($this->rootOnlyTrustFiles() as $rootOnly) {
            if (\strcasecmp($base, $rootOnly) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{owner_sid:string,service_sid:string,rights:string,sddl_rights:string,inheritance:string}> $contracts
     * @return array{owner_sid:string,sddl:string,sddl_b64:string}
     */
    private function windowsDerivedDescendantAuthorityProof(
        string $path,
        bool $directory,
        array $contracts,
        array $expectedIdentity,
        ?string $restoreOwnerSid = null,
        ?string $restoreSddlBase64 = null,
    ): array {
        $allowed = $this->windowsDerivedCanonicalAuthorityContracts($contracts);
        $restore = $restoreOwnerSid !== null || $restoreSddlBase64 !== null;
        if ($restore !== ($restoreOwnerSid !== null
                && $restoreSddlBase64 !== null)
        ) {
            throw new \RuntimeException(
                'Windows derived-state descendant restore proof is incomplete.',
            );
        }

        if ($restore) {
            $sddl = \base64_decode((string)$restoreSddlBase64, true);
            if (!\is_string($sddl)
                || $sddl === ''
                || \strlen($sddl) > 8192
                || \str_contains($sddl, "\0")
                || !\hash_equals(
                    \base64_encode($sddl),
                    (string)$restoreSddlBase64,
                )
            ) {
                throw new \RuntimeException(
                    'Windows derived-state descendant restore SDDL is malformed.',
                );
            }
            $canonical = GatewayWindowsHostRootAuthority::canonicalizeSddl(
                $sddl,
            );
            if (!\hash_equals($sddl, $canonical)) {
                throw new \RuntimeException(
                    'Windows derived-state descendant restore SDDL is not canonical.',
                );
            }
            $matched = null;
            foreach ($allowed as $candidate) {
                if (\hash_equals(
                        (string)$restoreOwnerSid,
                        $candidate['owner_sid'],
                    )
                    && \hash_equals($canonical, $candidate['sddl'])
                ) {
                    $matched = $candidate;
                    break;
                }
            }
            if ($matched === null) {
                throw new \RuntimeException(
                    'Windows derived-state descendant restore proof is not an allowed exact canonical DACL.',
                );
            }
            $actual = GatewayWindowsHostRootAuthority::applyExactPathSddl(
                $path,
                $directory,
                $canonical,
                $expectedIdentity,
            );
            if (!\hash_equals($canonical, $actual)) {
                throw new \RuntimeException(
                    'Windows derived-state descendant authority changed while it was restored.',
                );
            }
        } else {
            $actual = GatewayWindowsHostRootAuthority::captureExactPathSddl(
                $path,
                $directory,
                $expectedIdentity,
            );
            if ($actual === ''
                || \strlen($actual) > 8192
                || \str_contains($actual, "\0")
                || !\hash_equals(
                    $actual,
                    GatewayWindowsHostRootAuthority::canonicalizeSddl($actual),
                )
            ) {
                throw new \RuntimeException(
                    'Windows derived-state descendant canonical SDDL is malformed.',
                );
            }
            $matched = null;
            foreach ($allowed as $candidate) {
                if (\hash_equals($actual, $candidate['sddl'])) {
                    $matched = $candidate;
                    break;
                }
            }
            if ($matched === null) {
                throw new \RuntimeException(
                    'Windows derived-state descendant DACL differs from its exact canonical profile.',
                );
            }
        }

        return [
            'owner_sid' => $matched['owner_sid'],
            'sddl' => $actual,
            'sddl_b64' => \base64_encode($actual),
        ];
    }

    /**
     * @param list<array{owner_sid:string,service_sid:string,rights:string,sddl_rights:string,inheritance:string}> $contracts
     * @return list<array{owner_sid:string,sddl:string}>
     */
    private function windowsDerivedCanonicalAuthorityContracts(
        array $contracts,
    ): array {
        if ($contracts === [] || \count($contracts) > 2) {
            throw new \RuntimeException(
                'Windows derived-state authority variants are invalid.',
            );
        }
        $allowed = [];
        foreach ($contracts as $contract) {
            $ownerSid = (string)($contract['owner_sid'] ?? '');
            $serviceSid = (string)($contract['service_sid'] ?? '');
            $rights = (string)($contract['rights'] ?? '');
            $sddlRights = (string)($contract['sddl_rights'] ?? '');
            $inheritance = (string)($contract['inheritance'] ?? '');
            if (!\in_array(
                    $ownerSid,
                    ['S-1-5-18', 'S-1-5-32-544'],
                    true,
                )
                || !\in_array($inheritance, ['', 'OICI'], true)
            ) {
                throw new \RuntimeException(
                    'Windows derived-state authority owner or inheritance contract is invalid.',
                );
            }
            if ($serviceSid === '') {
                if (\count($contracts) !== 1
                    || $rights !== ''
                    || $sddlRights !== ''
                    || $inheritance !== ''
                ) {
                    throw new \RuntimeException(
                        'Windows derived-state sealed-backup authority contract is invalid.',
                    );
                }
                $raw = 'O:' . $ownerSid
                    . 'D:P(A;;FA;;;SY)(A;;FA;;;BA)';
            } else {
                $allowedRights = match ($serviceSid) {
                    self::WINDOWS_CONTROLLER_SERVICE_SID
                        => ['RX', 'M', 'GA'],
                    self::WINDOWS_DATA_PLANE_SERVICE_SID
                        => ['GX', 'GR', 'GRGX'],
                    'S-1-5-32-545' => ['R'],
                    default => throw new \RuntimeException(
                        'Windows derived-state authority service SID is unsupported.',
                    ),
                };
                if (!\in_array($rights, $allowedRights, true)
                    || !\hash_equals(
                        self::windowsDerivedAuthoritySddlRights($rights),
                        $sddlRights,
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows derived-state authority service rights contract is invalid.',
                    );
                }
                $raw = 'O:' . $ownerSid
                    . 'D:P(A;' . $inheritance . ';FA;;;SY)'
                    . '(A;' . $inheritance . ';FA;;;BA)'
                    . '(A;' . $inheritance . ';' . $sddlRights
                    . ';;;' . $serviceSid . ')';
            }
            $canonical = GatewayWindowsHostRootAuthority::canonicalizeSddl(
                $raw,
            );
            foreach ($allowed as $existing) {
                if (\hash_equals($existing['sddl'], $canonical)) {
                    throw new \RuntimeException(
                        'Windows derived-state authority variants are not distinct.',
                    );
                }
            }
            $allowed[] = [
                'owner_sid' => $ownerSid,
                'sddl' => $canonical,
            ];
        }
        return $allowed;
    }

    /**
     * WLS native publication requires ACL-free POSIX derived state. Verify
     * every object through an O_NOFOLLOW descriptor so an extended ACL can
     * neither be hidden by a path replacement nor silently dropped from the
     * signed closure digest.
     */
    public function assertRebootstrapDerivedDescendantPosixAclFree(
        string $path,
        bool $directory,
    ): void {
        $expected = $this->rebootstrapBackupObjectRecord($path, $directory);
        $this->assertRebootstrapDerivedPosixAclFreeRecord($expected);
    }

    /** @param array<string,mixed> $expected */
    private function assertRebootstrapDerivedPosixAclFreeRecord(
        array $expected,
    ): void {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'POSIX derived-state descendant ACL validation is unavailable on Windows.',
            );
        }
        if (!\in_array(\PHP_OS_FAMILY, ['Linux', 'Darwin'], true)
            || !\class_exists(\FFI::class)
        ) {
            throw new \RuntimeException(
                'Gateway derived-state descendant ACL verification requires the supported FFI runtime.',
            );
        }
        $path = (string)($expected['path'] ?? '');
        $directory = (bool)($expected['directory'] ?? false);
        if ($path === ''
            || (string)($expected['device'] ?? '') === ''
            || (string)($expected['inode'] ?? '') === ''
        ) {
            throw new \RuntimeException(
                'Gateway derived-state descendant ACL identity proof is invalid.',
            );
        }
        static $bindings = [];
        $platform = \PHP_OS_FAMILY;
        try {
            if (!isset($bindings[$platform])) {
                $bindings[$platform] = match ($platform) {
                    'Linux' => \FFI::cdef(
                        'int open(const char *path, int flags, ...);'
                            . ' long fgetxattr(int fd, const char *name,'
                            . ' void *value, unsigned long size);'
                            . ' int close(int fd);'
                            . ' int *__errno_location(void);',
                    ),
                    'Darwin' => \FFI::cdef(
                        'typedef void *acl_t;'
                            . ' typedef void *acl_entry_t;'
                            . ' struct wls_darwin_timespec {'
                            . ' long tv_sec; long tv_nsec; };'
                            . ' struct wls_darwin_stat {'
                            . ' int st_dev; unsigned short st_mode;'
                            . ' unsigned short st_nlink;'
                            . ' unsigned long long st_ino;'
                            . ' unsigned int st_uid; unsigned int st_gid;'
                            . ' int st_rdev;'
                            . ' struct wls_darwin_timespec st_atimespec;'
                            . ' struct wls_darwin_timespec st_mtimespec;'
                            . ' struct wls_darwin_timespec st_ctimespec;'
                            . ' struct wls_darwin_timespec st_birthtimespec;'
                            . ' long long st_size; long long st_blocks;'
                            . ' int st_blksize; unsigned int st_flags;'
                            . ' unsigned int st_gen; int st_lspare;'
                            . ' long long st_qspare[2]; };'
                            . ' int open(const char *path, int flags, ...);'
                            . ' int fstat64(int fd,'
                            . ' struct wls_darwin_stat *status);'
                            . ' acl_t acl_get_fd_np(int fd, int type);'
                            . ' int acl_get_entry(acl_t acl, int entry_id,'
                            . ' acl_entry_t *entry);'
                            . ' int acl_free(void *obj);'
                            . ' int close(int fd);'
                            . ' int *__error(void);',
                    ),
                };
            }
            $ffi = $bindings[$platform];
            $flags = $platform === 'Linux'
                ? (0x20000 | 0x80000 | ($directory ? 0x10000 : 0))
                : (0x100 | 0x1000000 | ($directory ? 0x100000 : 0));
            $fd = (int)$ffi->open($path, $flags);
            if ($fd < 0) {
                throw new \RuntimeException(
                    'Gateway derived-state descendant ACL handle cannot be opened no-follow.',
                );
            }
            $fdPath = '/proc/self/fd/' . $fd;
            $assertDescriptorIdentity = static function (
                mixed $status,
            ) use ($expected, $directory): void {
                $expectedType = $directory ? 0040000 : 0100000;
                if (!\is_array($status)
                    || (string)($status['dev'] ?? '')
                        !== (string)$expected['device']
                    || (string)($status['ino'] ?? '')
                        !== (string)$expected['inode']
                    || (((int)($status['mode'] ?? 0)) & 0170000)
                        !== $expectedType
                    || (!$directory
                        && (int)($status['nlink'] ?? 0) !== 1)
                ) {
                    throw new \RuntimeException(
                        'Gateway derived-state descendant ACL descriptor identity is inconsistent.',
                    );
                }
            };
            $verificationFailure = null;
            try {
                $assertDescriptorIdentity(self::posixAclDescriptorStatus(
                    $ffi,
                    $fd,
                    $platform,
                    $fdPath,
                ));
                if ($platform === 'Linux') {
                    foreach ([
                        'system.posix_acl_access',
                        'system.posix_acl_default',
                    ] as $name) {
                        $ffi->__errno_location()[0] = 0;
                        $result = (int)$ffi->fgetxattr($fd, $name, null, 0);
                        $error = (int)$ffi->__errno_location()[0];
                        if ($result >= 0
                            || !\in_array($error, [61, 95], true)
                        ) {
                            throw new \RuntimeException(
                                'Gateway derived-state descendant POSIX ACL is present or indeterminate.',
                            );
                        }
                    }
                } else {
                    $ffi->__error()[0] = 0;
                    $acl = $ffi->acl_get_fd_np($fd, 0x00000100);
                    if ($acl === null || \FFI::isNull($acl)) {
                        if ((int)$ffi->__error()[0] !== 2) {
                            throw new \RuntimeException(
                                'Gateway derived-state descendant macOS ACL is indeterminate.',
                            );
                        }
                    } else {
                        $entry = $ffi->new('acl_entry_t');
                        $ffi->__error()[0] = 0;
                        $entryResult = (int)$ffi->acl_get_entry(
                            $acl,
                            0,
                            \FFI::addr($entry),
                        );
                        $entryError = (int)$ffi->__error()[0];
                        $freeResult = (int)$ffi->acl_free($acl);
                        if ($entryResult >= 0
                            || $entryError !== 22
                            || $freeResult !== 0
                        ) {
                            throw new \RuntimeException(
                                'Gateway derived-state descendant macOS ACL is present or indeterminate.',
                            );
                        }
                    }
                }
                $assertDescriptorIdentity(self::posixAclDescriptorStatus(
                    $ffi,
                    $fd,
                    $platform,
                    $fdPath,
                ));
            } catch (\Throwable $throwable) {
                $verificationFailure = $throwable;
            }
            $closeFailure = null;
            try {
                if ((int)$ffi->close($fd) !== 0) {
                    $closeFailure = new \RuntimeException(
                        'Gateway derived-state descendant ACL handle did not close cleanly.',
                    );
                }
            } catch (\Throwable $throwable) {
                $closeFailure = new \RuntimeException(
                    'Gateway derived-state descendant ACL handle close failed.',
                    0,
                    $throwable,
                );
            }
            if ($closeFailure !== null) {
                if ($verificationFailure !== null) {
                    throw new \RuntimeException(
                        $closeFailure->getMessage(),
                        0,
                        $verificationFailure,
                    );
                }
                throw $closeFailure;
            }
            if ($verificationFailure !== null) {
                throw $verificationFailure;
            }
            $this->assertRebootstrapBackupObjectUnchanged($expected);
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Gateway derived-state descendant ACL verification failed closed.',
                0,
                $exception,
            );
        }
    }

    /**
     * Read descriptor identity without resolving a mutable pathname.
     *
     * macOS exposes /dev/fd entries as character devices to stat(2), so their
     * metadata is not the opened object's metadata. Use the descriptor-native
     * Darwin ABI instead; Linux /proc/self/fd has target stat semantics.
     *
     * @return array<string, int|string>|false
     */
    private static function posixAclDescriptorStatus(
        \FFI $ffi,
        int $fd,
        string $platform,
        string $fdPath,
    ): array|false {
        if ($platform === 'Linux') {
            \clearstatcache(true, $fdPath);
            return @\stat($fdPath);
        }
        if ($platform !== 'Darwin') {
            return false;
        }
        $status = $ffi->new('struct wls_darwin_stat');
        $ffi->__error()[0] = 0;
        if ((int)$ffi->fstat64($fd, \FFI::addr($status)) !== 0) {
            return false;
        }
        return [
            'dev' => (int)$status->st_dev,
            'ino' => (string)$status->st_ino,
            'mode' => (int)$status->st_mode,
            'nlink' => (int)$status->st_nlink,
        ];
    }

    private function assertRebootstrapDerivedRootPath(string $root): void
    {
        $allowed = [
            $this->paths->home(),
            $this->paths->runtimeDir(),
            $this->paths->stateDir(),
            $this->paths->trustDir(),
            $this->paths->legacySnapshotsDir(),
            $this->paths->sealedSnapshotsDir(),
            $this->paths->snapshotCandidatesDir(),
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'conf',
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'temp',
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow',
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'run',
        ];
        $normalize = static fn (string $path): string => \rtrim(
            \str_replace('\\', '/', $path),
            '/',
        );
        $candidate = $normalize($root);
        foreach ($allowed as $path) {
            $expected = $normalize($path);
            if (\PHP_OS_FAMILY === 'Windows'
                ? \strcasecmp($candidate, $expected) === 0
                : \hash_equals($candidate, $expected)
            ) {
                return;
            }
        }
        throw new \RuntimeException(
            'Gateway rebootstrap derived-root authority path is outside the fixed host namespace.',
        );
    }

    public function rebootstrapDerivedRootAuthorityProfile(string $root): string
    {
        $this->assertRebootstrapDerivedRootPath($root);
        $normalize = static fn (string $path): string => \rtrim(
            \str_replace('\\', '/', $path),
            '/',
        );
        $candidate = $normalize($root);
        $profiles = [
            $this->paths->home() => self::DERIVED_AUTHORITY_HOME,
            $this->paths->runtimeDir() => self::DERIVED_AUTHORITY_RUNTIME,
            $this->paths->stateDir() => self::DERIVED_AUTHORITY_STATE,
            $this->paths->trustDir() => self::DERIVED_AUTHORITY_TRUST,
            $this->paths->legacySnapshotsDir()
                => self::DERIVED_AUTHORITY_SNAPSHOTS_V1,
            $this->paths->sealedSnapshotsDir()
                => self::DERIVED_AUTHORITY_SNAPSHOTS_V2,
            $this->paths->snapshotCandidatesDir()
                => self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2,
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'conf'
                => self::DERIVED_AUTHORITY_RUNTIME_CHILD,
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'temp'
                => self::DERIVED_AUTHORITY_RUNTIME_CHILD,
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow'
                => self::DERIVED_AUTHORITY_RUNTIME_CHILD,
            $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'run'
                => self::DERIVED_AUTHORITY_RUNTIME_CHILD,
        ];
        foreach ($profiles as $path => $profile) {
            $expected = $normalize($path);
            if (\PHP_OS_FAMILY === 'Windows'
                ? \strcasecmp($candidate, $expected) === 0
                : \hash_equals($candidate, $expected)
            ) {
                return $profile;
            }
        }
        throw new \RuntimeException(
            'Gateway rebootstrap derived-root authority profile is undefined.',
        );
    }

    public function assertRebootstrapDerivedRootPosixAuthority(
        string $root,
        int $uid,
        int $gid,
        string $expectedDevice,
        string $expectedInode,
        int $expectedType,
        int $expectedNlink,
        ?int $mode = null,
        ?string $expectedProfile = null,
    ): void {
        $this->assertRebootstrapDerivedRootPath($root);
        if (\PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'POSIX derived-root authority validation is unavailable on Windows.',
            );
        }
        $profile = $this->rebootstrapDerivedRootAuthorityProfile($root);
        if (($expectedProfile !== null
                && !\hash_equals($expectedProfile, $profile))
            || $uid < 0
            || $gid < 0
            || $expectedDevice === ''
            || $expectedInode === ''
            || \str_contains($expectedDevice, "\0")
            || \str_contains($expectedInode, "\0")
            || $expectedType !== 0040000
            || $expectedNlink < 1
            || ($mode !== null && ($mode < 0 || $mode > 0777))
        ) {
            throw new \RuntimeException(
                'Gateway derived-root POSIX authority is invalid.',
            );
        }
        if ($this->paths->isTestMode()) {
            $home = @\lstat($this->paths->home());
            if (!\is_array($home)
                || $uid !== (int)($home['uid'] ?? -1)
                || $gid !== (int)($home['gid'] ?? -1)
            ) {
                throw new \RuntimeException(
                    'Test gateway derived-root authority escaped its isolated owner.',
                );
            }
            if ($mode !== null && (($mode & 0700) !== 0700
                    || ($mode & 0022) !== 0)) {
                throw new \RuntimeException(
                    'Test gateway derived-root authority mode is unsafe.',
                );
            }
            $this->assertRebootstrapDerivedRootPosixAclFree(
                $root,
                $expectedDevice,
                $expectedInode,
                $expectedType,
                $expectedNlink,
            );
            return;
        }

        [$controllerAccount, $dataPlaneAccount] = self::posixServiceAccountNames(
            \PHP_OS_FAMILY,
        );
        $controller = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($controllerAccount)
            : false;
        $controllerGroup = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($controllerAccount)
            : false;
        $dataPlane = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($dataPlaneAccount)
            : false;
        $dataPlaneGroup = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($dataPlaneAccount)
            : false;
        if (!\is_array($controller)
            || !\is_array($controllerGroup)
            || !self::posixServiceIdentityIsValid(
                $controller,
                $controllerGroup,
                \PHP_OS_FAMILY,
            )
            || !\is_array($dataPlane)
            || !\is_array($dataPlaneGroup)
            || !self::posixServiceIdentityIsValid(
                $dataPlane,
                $dataPlaneGroup,
                \PHP_OS_FAMILY,
                'data-plane',
            )
        ) {
            throw new \RuntimeException(
                'Dedicated WLS Gateway POSIX identities are unavailable for derived-root validation.',
            );
        }
        $controllerUid = (int)$controller['uid'];
        $controllerGid = (int)$controller['gid'];
        $dataPlaneGid = (int)$dataPlane['gid'];
        [$expectedUid, $expectedGids, $expectedModes] = match ($profile) {
            self::DERIVED_AUTHORITY_HOME => [0, [$controllerGid], [0751]],
            self::DERIVED_AUTHORITY_STATE,
            self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2
                => [$controllerUid, [$controllerGid], [0700]],
            self::DERIVED_AUTHORITY_TRUST => [0, [$controllerGid], [0750]],
            self::DERIVED_AUTHORITY_SNAPSHOTS_V1
                => [$controllerUid, [$dataPlaneGid], [0710]],
            self::DERIVED_AUTHORITY_SNAPSHOTS_V2
                => [0, [$dataPlaneGid], [0710]],
            self::DERIVED_AUTHORITY_RUNTIME
                => [$controllerUid, [$dataPlaneGid], [0750]],
            self::DERIVED_AUTHORITY_RUNTIME_CHILD
                => [$controllerUid, [$controllerGid, $dataPlaneGid], [0700, 0750]],
            default => throw new \RuntimeException(
                'Gateway derived-root POSIX authority profile is unsupported.',
            ),
        };
        if ($uid !== $expectedUid
            || !\in_array($gid, $expectedGids, true)
            || ($mode !== null && !\in_array($mode, $expectedModes, true))
        ) {
            throw new \RuntimeException(
                'Gateway derived-root POSIX authority differs from its fixed namespace profile.',
            );
        }
        $this->assertRebootstrapDerivedRootPosixAclFree(
            $root,
            $expectedDevice,
            $expectedInode,
            $expectedType,
            $expectedNlink,
        );
    }

    private function assertRebootstrapDerivedRootPosixAclFree(
        string $root,
        string $expectedDevice,
        string $expectedInode,
        int $expectedType,
        int $expectedNlink,
    ): void {
        if (!\class_exists(\FFI::class)) {
            throw new \RuntimeException(
                'Gateway derived-root ACL verification requires FFI.',
            );
        }
        \clearstatcache(true, $root);
        $before = @\lstat($root);
        if (!\is_array($before)
            || \is_link($root)
            || !\hash_equals(
                $expectedDevice,
                (string)($before['dev'] ?? ''),
            )
            || !\hash_equals(
                $expectedInode,
                (string)($before['ino'] ?? ''),
            )
            || ((((int)($before['mode'] ?? 0)) & 0170000)
                !== $expectedType)
            || (int)($before['nlink'] ?? 0) !== $expectedNlink
        ) {
            throw new \RuntimeException(
                'Gateway derived-root ACL object changed before its descriptor was opened.',
            );
        }
        static $bindings = [];
        $platform = \PHP_OS_FAMILY;
        try {
            if (!isset($bindings[$platform])) {
                $bindings[$platform] = match ($platform) {
                    'Linux' => \FFI::cdef(
                        'int open(const char *path, int flags, ...);'
                            . ' long fgetxattr(int fd, const char *name,'
                            . ' void *value, unsigned long size);'
                            . ' int close(int fd);'
                            . ' int *__errno_location(void);',
                    ),
                    'Darwin' => \FFI::cdef(
                        'typedef void *acl_t;'
                            . ' typedef void *acl_entry_t;'
                            . ' struct wls_darwin_timespec {'
                            . ' long tv_sec; long tv_nsec; };'
                            . ' struct wls_darwin_stat {'
                            . ' int st_dev; unsigned short st_mode;'
                            . ' unsigned short st_nlink;'
                            . ' unsigned long long st_ino;'
                            . ' unsigned int st_uid; unsigned int st_gid;'
                            . ' int st_rdev;'
                            . ' struct wls_darwin_timespec st_atimespec;'
                            . ' struct wls_darwin_timespec st_mtimespec;'
                            . ' struct wls_darwin_timespec st_ctimespec;'
                            . ' struct wls_darwin_timespec st_birthtimespec;'
                            . ' long long st_size; long long st_blocks;'
                            . ' int st_blksize; unsigned int st_flags;'
                            . ' unsigned int st_gen; int st_lspare;'
                            . ' long long st_qspare[2]; };'
                            . ' int open(const char *path, int flags, ...);'
                            . ' int fstat64(int fd,'
                            . ' struct wls_darwin_stat *status);'
                            . ' acl_t acl_get_fd_np(int fd, int type);'
                            . ' int acl_get_entry(acl_t acl, int entry_id,'
                            . ' acl_entry_t *entry);'
                            . ' int acl_free(void *obj);'
                            . ' int close(int fd);'
                            . ' int *__error(void);',
                    ),
                    default => throw new \RuntimeException(
                        'Unsupported POSIX ACL verification platform.',
                    ),
                };
            }
            $ffi = $bindings[$platform];
            $flags = $platform === 'Linux'
                ? (0x10000 | 0x20000 | 0x80000)
                : (0x100000 | 0x100 | 0x1000000);
            $fd = (int)$ffi->open($root, $flags);
            if ($fd < 0) {
                throw new \RuntimeException(
                    'Gateway derived-root ACL handle cannot be opened.',
                );
            }
            $fdPath = '/proc/self/fd/' . $fd;
            $assertDescriptorIdentity = static function (
                mixed $status,
            ) use (
                $expectedDevice,
                $expectedInode,
                $expectedType,
                $expectedNlink,
            ): void {
                if (!\is_array($status)
                    || (string)($status['dev'] ?? '')
                        !== $expectedDevice
                    || (string)($status['ino'] ?? '')
                        !== $expectedInode
                    || ((((int)($status['mode'] ?? 0)) & 0170000)
                        !== $expectedType)
                    || (int)($status['nlink'] ?? 0) !== $expectedNlink
                ) {
                    throw new \RuntimeException(
                        'Gateway derived-root ACL descriptor identity is inconsistent.',
                    );
                }
            };
            $verificationFailure = null;
            try {
                $assertDescriptorIdentity(self::posixAclDescriptorStatus(
                    $ffi,
                    $fd,
                    $platform,
                    $fdPath,
                ));
                if ($platform === 'Linux') {
                    foreach ([
                        'system.posix_acl_access',
                        'system.posix_acl_default',
                    ] as $name) {
                        $ffi->__errno_location()[0] = 0;
                        $result = (int)$ffi->fgetxattr($fd, $name, null, 0);
                        $error = (int)$ffi->__errno_location()[0];
                        if ($result >= 0 || !\in_array($error, [61, 95], true)) {
                            throw new \RuntimeException(
                                'Gateway derived-root POSIX ACL is present or indeterminate.',
                            );
                        }
                    }
                } else {
                    $ffi->__error()[0] = 0;
                    $acl = $ffi->acl_get_fd_np($fd, 0x00000100);
                    if ($acl === null || \FFI::isNull($acl)) {
                        if ((int)$ffi->__error()[0] !== 2) {
                            throw new \RuntimeException(
                                'Gateway derived-root macOS ACL is indeterminate.',
                            );
                        }
                    } else {
                        $entry = $ffi->new('acl_entry_t');
                        $ffi->__error()[0] = 0;
                        $entryResult = (int)$ffi->acl_get_entry(
                            $acl,
                            0,
                            \FFI::addr($entry),
                        );
                        $entryError = (int)$ffi->__error()[0];
                        $freeResult = (int)$ffi->acl_free($acl);
                        if ($entryResult >= 0
                            || $entryError !== 22
                            || $freeResult !== 0
                        ) {
                            throw new \RuntimeException(
                                'Gateway derived-root macOS ACL is present or indeterminate.',
                            );
                        }
                    }
                }
                $assertDescriptorIdentity(self::posixAclDescriptorStatus(
                    $ffi,
                    $fd,
                    $platform,
                    $fdPath,
                ));
            } catch (\Throwable $throwable) {
                $verificationFailure = $throwable;
            }
            $closeFailure = null;
            try {
                if ((int)$ffi->close($fd) !== 0) {
                    $closeFailure = new \RuntimeException(
                        'Gateway derived-root ACL handle did not close cleanly.',
                    );
                }
            } catch (\Throwable $throwable) {
                $closeFailure = new \RuntimeException(
                    'Gateway derived-root ACL handle close failed.',
                    0,
                    $throwable,
                );
            }
            if ($closeFailure !== null) {
                if ($verificationFailure !== null) {
                    throw new \RuntimeException(
                        $closeFailure->getMessage(),
                        0,
                        $verificationFailure,
                    );
                }
                throw $closeFailure;
            }
            if ($verificationFailure !== null) {
                throw $verificationFailure;
            }
            \clearstatcache(true, $root);
            $after = @\lstat($root);
            if (!\is_array($after)
                || \is_link($root)
                || !\hash_equals(
                    $expectedDevice,
                    (string)($after['dev'] ?? ''),
                )
                || !\hash_equals(
                    $expectedInode,
                    (string)($after['ino'] ?? ''),
                )
                || ((((int)($after['mode'] ?? 0)) & 0170000)
                    !== $expectedType)
                || (int)($after['nlink'] ?? 0) !== $expectedNlink
            ) {
                throw new \RuntimeException(
                    'Gateway derived-root ACL path identity changed after its descriptor closed.',
                );
            }
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Gateway derived-root ACL verification failed closed.',
                0,
                $exception,
            );
        }
    }

    private function rebootstrapDerivedRootWindowsServiceRights(
        string $root,
    ): string {
        return match ($this->rebootstrapDerivedRootAuthorityProfile($root)) {
            self::DERIVED_AUTHORITY_HOME,
            self::DERIVED_AUTHORITY_TRUST => 'RX',
            self::DERIVED_AUTHORITY_SNAPSHOTS_V2 => 'GX',
            self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2 => 'GA',
            default => 'M',
        };
    }

    /** @return array{owner_sid:string,service_sid:string,rights:string,sddl_rights:string,inheritance:string} */
    private function rebootstrapDerivedRootWindowsContract(string $root): array
    {
        $profile = $this->rebootstrapDerivedRootAuthorityProfile($root);
        if ($profile === self::DERIVED_AUTHORITY_HOME) {
            return [
                'owner_sid' => 'S-1-5-18',
                'service_sid' => self::WINDOWS_CONTROLLER_SERVICE_SID,
                'rights' => 'RX',
                'sddl_rights' => self::windowsDerivedAuthoritySddlRights('RX'),
                'inheritance' => '',
            ];
        }
        if ($profile === self::DERIVED_AUTHORITY_SNAPSHOTS_V2) {
            return [
                'owner_sid' => 'S-1-5-18',
                'service_sid' => self::WINDOWS_DATA_PLANE_SERVICE_SID,
                'rights' => 'GX',
                'sddl_rights' => self::windowsDerivedAuthoritySddlRights('GX'),
                'inheritance' => '',
            ];
        }
        if ($profile === self::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2) {
            return [
                'owner_sid' => 'S-1-5-18',
                'service_sid' => self::WINDOWS_CONTROLLER_SERVICE_SID,
                'rights' => 'GA',
                'sddl_rights' => self::windowsDerivedAuthoritySddlRights('GA'),
                'inheritance' => '',
            ];
        }
        $rights = $this->rebootstrapDerivedRootWindowsServiceRights($root);
        return [
            'owner_sid' => 'S-1-5-32-544',
            'service_sid' => self::WINDOWS_CONTROLLER_SERVICE_SID,
            'rights' => $rights,
            'sddl_rights' => self::windowsDerivedAuthoritySddlRights($rights),
            'inheritance' => 'OICI',
        ];
    }

    private static function windowsDerivedAuthoritySddlRights(
        string $rights,
    ): string {
        return match ($rights) {
            // RX and M are logical .NET FileSystemRights names, not SDDL
            // aliases. Their exact access masks must be serialized explicitly.
            'RX' => '0x1200a9',
            'M' => '0x1301bf',
            'R' => '0x120089',
            'GX' => 'GX',
            'GA' => 'GA',
            'GR' => 'GR',
            'GRGX' => 'GRGX',
            default => throw new \RuntimeException(
                'Gateway derived Windows ACL rights profile is unsupported.',
            ),
        };
    }


    private function securePackageTransactionTrustWithinDeadline(): void
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
                if (\in_array($rootOnlyFile, [
                    'package-bootstrap.lock',
                    'package-install.lock',
                ], true)
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

    /**
     * Read-only first-install classification. Only an exact platform-native
     * absence result authorizes a virgin installation; localized errors,
     * access failures and a same-name foreign/legacy service fail closed.
     *
     * @return array{state:string,reason:string}
     */
    public function initialServiceRegistrationStatus(
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            self::SERVICE_OPERATION_TIMEOUT_SECONDS,
            function (): array {
                if ($this->initialServiceRegistrationProbe !== null) {
                    $result = ($this->initialServiceRegistrationProbe)();
                    if (!\is_array($result)) {
                        return [
                            'state' => 'UNKNOWN',
                            'reason' => 'The injected platform service registration probe returned an invalid result.',
                        ];
                    }
                    $state = \strtoupper(\trim((string)(
                        $result['state'] ?? ''
                    )));
                    if (!\in_array($state, ['ABSENT', 'PRESENT', 'UNKNOWN'], true)) {
                        $state = 'UNKNOWN';
                    }
                    return [
                        'state' => $state,
                        'reason' => GatewayBoundedText::singleLine(
                            (string)($result['reason']
                                ?? 'Platform service registration classification completed.'),
                            1024,
                            'Platform service registration classification completed.',
                        ),
                    ];
                }
                if ($this->paths->isTestMode()) {
                    return [
                        'state' => 'ABSENT',
                        'reason' => 'The isolated test host has no platform service registration.',
                    ];
                }
                try {
                    return $this->platformServiceRegistrationStatusReadOnly();
                } catch (\Throwable $throwable) {
                    return [
                        'state' => 'UNKNOWN',
                        'reason' => GatewayBoundedText::singleLine(
                            $throwable->getMessage(),
                            1024,
                            'Platform service registration is indeterminate.',
                        ),
                    ];
                }
            },
        );
    }

    private function assertInitialServiceRegistrationAbsent(): void
    {
        $registration = $this->initialServiceRegistrationStatus(
            $this->activeOperationDeadline(),
        );
        $state = (string)($registration['state'] ?? 'UNKNOWN');
        if (\hash_equals('ABSENT', $state)) {
            return;
        }
        $prefix = \hash_equals('PRESENT', $state)
            ? 'HOST_SERVICE_PRESENT'
            : 'REPAIR_REQUIRED';
        throw new \RuntimeException(
            $prefix . ': ' . (string)($registration['reason']
                ?? 'The platform service registration is not provably absent.'),
        );
    }

    /** @return array{state:string,reason:string} */
    private function platformServiceRegistrationStatusReadOnly(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = $this->runCommand([
                '/bin/launchctl',
                'print',
                'system/com.weline.wls-gateway-v2',
            ], true);
            if ((int)$result['code'] === 0) {
                return [
                    'state' => 'PRESENT',
                    'reason' => 'launchd already has the WLS Gateway service label registered.',
                ];
            }
            if (\preg_match(
                '/(?:could not find service|service[^\r\n]*not found)/i',
                (string)$result['output'],
            ) === 1) {
                return [
                    'state' => 'ABSENT',
                    'reason' => 'launchd reports the WLS Gateway service label absent.',
                ];
            }
            return [
                'state' => 'UNKNOWN',
                'reason' => 'launchd could not prove the WLS Gateway service label absent.',
            ];
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $result = $this->runCommand([
                '/bin/systemctl',
                'show',
                self::SERVICE_NAME . '.service',
                '--property=LoadState',
                '--value',
            ], true);
            $loadState = \strtolower(\trim((string)$result['output']));
            if ((int)$result['code'] === 0 && \hash_equals('not-found', $loadState)) {
                return [
                    'state' => 'ABSENT',
                    'reason' => 'systemd reports the WLS Gateway unit absent.',
                ];
            }
            if ((int)$result['code'] === 0 && $loadState !== '') {
                return [
                    'state' => 'PRESENT',
                    'reason' => 'systemd already has the WLS Gateway unit registered.',
                ];
            }
            if (\preg_match(
                '/(?:unit[^\r\n]*(?:could not be found|not found)|not-found)/i',
                (string)$result['output'],
            ) === 1) {
                return [
                    'state' => 'ABSENT',
                    'reason' => 'systemd reports the WLS Gateway unit absent.',
                ];
            }
            return [
                'state' => 'UNKNOWN',
                'reason' => 'systemd could not prove the WLS Gateway unit absent.',
            ];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $result = $this->runCommand([
                $this->windowsSystemExecutable('sc.exe'),
                'query',
                self::SERVICE_NAME,
            ], true);
            if ((int)$result['code'] === 0) {
                return [
                    'state' => 'PRESENT',
                    'reason' => 'Windows SCM already has the WLS Gateway service registered.',
                ];
            }
            if (\preg_match(
                '/(?:^|\D)1060(?:\D|$)/D',
                (string)$result['output'],
            ) === 1) {
                return [
                    'state' => 'ABSENT',
                    'reason' => 'Windows SCM reports the WLS Gateway service absent.',
                ];
            }
            return [
                'state' => 'UNKNOWN',
                'reason' => 'Windows SCM could not prove the WLS Gateway service absent.',
            ];
        }
        return [
            'state' => 'UNKNOWN',
            'reason' => 'The host platform cannot classify Gateway service registration.',
        ];
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
            $legacySystemdLayout = $this->metadataUsesLegacyLinuxSystemdLayout(
                $decoded,
            );
            $definitionPath = $legacySystemdLayout
                ? $this->paths->legacySystemdServiceDefinitionFile()
                : $this->paths->serviceDefinitionFile();
            $expectedDefinition = $legacySystemdLayout
                ? $this->renderLegacyLinuxSystemdDefinition(
                    (string)$decoded['profile'],
                )
                : $this->renderDefinition((string)$decoded['profile']);
            $definition = $this->readStableRegularFile(
                $definitionPath,
                1_048_576,
                'WLS Gateway platform service definition',
            );
            if (!\hash_equals(
                $expectedDefinition,
                $definition,
            )) {
                throw new \RuntimeException(
                    'WLS Gateway platform service definition is not bound to installed metadata.'
                );
            }
            if ($this->managesLinuxSystemdLayout()) {
                if ($legacySystemdLayout) {
                    $this->linuxSystemdLayout()->assertExactLegacyDefinition(
                        $expectedDefinition,
                    );
                } else {
                    $this->linuxSystemdLayout()
                        ->assertCurrentDefinitionAndFixedLink($expectedDefinition);
                }
            }
        }
        return [
            'kind' => (string)$decoded['kind'],
            'path' => (string)$decoded['definition'],
            'test_mode' => ($decoded['test_mode'] ?? false) === true,
        ];
    }

    public function stop(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::SERVICE_OPERATION_TIMEOUT_SECONDS,
            function () use ($kind): void {
                $this->stopWithinDeadline($kind);
            },
        );
    }

    private function stopWithinDeadline(string $kind): void
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

    public function restart(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::SERVICE_OPERATION_TIMEOUT_SECONDS,
            function () use ($kind): void {
                $this->restartWithinDeadline($kind);
            },
        );
    }

    private function restartWithinDeadline(string $kind): void
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

    public function restartControlPlane(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): void
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
        // backwards-compatible transaction boundary for the already frozen
        // stable-launcher digest and can also start a verified rollback slot
        // when the previous process has already exited. It must never be used
        // to load different global launcher bytes; that requires the explicit,
        // persistently stopped whole-host rebootstrap transaction.
        $this->restart($kind, $deadlineMonotonic);
    }

    public function secureInstalledRuntime(
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function (): void {
                $this->secureInstalledRuntimeWithinDeadline();
            },
        );
    }

    private function secureInstalledRuntimeWithinDeadline(): void
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

    public function removeDefinition(
        string $kind,
        ?float $deadlineMonotonic = null,
    ): void
    {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            self::DEFINITION_OPERATION_TIMEOUT_SECONDS,
            function () use ($kind): void {
                $this->removeDefinitionWithinDeadline($kind);
            },
        );
    }

    private function removeDefinitionWithinDeadline(string $kind): void
    {
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
        }
        $this->withPackageInstallLock(function (?array $recovered) use ($kind): void {
        $pending = $this->platformRemovalPendingFile();
        $existingPending = GatewayProjectStateFilesystem::readOptional(
            $pending,
            1024,
            'WLS Gateway platform removal fence',
        );
        $removalFence = $existingPending === null
            ? null
            : $this->decodePlatformRemovalFence($existingPending);
        $linuxSystemdRemoval = null;
        if ($this->managesLinuxSystemdLayout()
            && \hash_equals('systemd-system', $kind)
        ) {
            $metadataPath = $this->paths->platformServiceMetadataFile();
            $metadataRaw = GatewayProjectStateFilesystem::readOptional(
                $metadataPath,
                16_384,
                'Installed WLS Gateway platform metadata before systemd removal',
            );
            if ($metadataRaw === null) {
                if ($removalFence === null
                    || (int)$removalFence['schema'] !== 2
                    || !\hash_equals($kind, (string)$removalFence['kind'])
                    || !\hash_equals(
                        'definition-removed',
                        (string)$removalFence['phase'],
                    )
                    || !\in_array(
                        (string)$removalFence['layout'],
                        ['current', 'legacy'],
                        true,
                    )
                ) {
                    throw new \RuntimeException(
                        'Installed WLS Gateway systemd metadata is absent before its exact definition-removal phase.',
                    );
                }
                $legacy = \hash_equals(
                    'legacy',
                    (string)$removalFence['layout'],
                );
                if ($legacy) {
                    $this->linuxSystemdLayout()
                        ->assertLegacyDefinitionRemoved();
                } else {
                    $this->linuxSystemdLayout()
                        ->assertCurrentDefinitionRemoved();
                }
                $linuxSystemdRemoval = [
                    'legacy' => $legacy,
                    'definition' => null,
                ];
            } else {
                $metadata = $this->decodePlatformServiceMetadata($metadataRaw);
                if (!\hash_equals('systemd-system', (string)$metadata['kind'])) {
                    throw new \RuntimeException(
                        'WLS Gateway systemd removal kind does not match installed metadata.',
                    );
                }
                $legacy = $this->metadataUsesLegacyLinuxSystemdLayout($metadata);
                $definition = $legacy
                    ? $this->renderLegacyLinuxSystemdDefinition(
                        (string)$metadata['profile'],
                    )
                    : $this->renderDefinition((string)$metadata['profile']);
                $layoutName = $legacy ? 'legacy' : 'current';
                $definitionDigest = \hash('sha256', $definition);
                if ($removalFence === null) {
                    if ($legacy) {
                        $this->linuxSystemdLayout()->assertExactLegacyDefinition(
                            $definition,
                        );
                    } else {
                        $this->linuxSystemdLayout()->assertCurrentDefinitionAndFixedLink(
                            $definition,
                        );
                    }
                    $removalFence = [
                        'kind' => $kind,
                        'layout' => $layoutName,
                        'definition_sha256' => $definitionDigest,
                        'phase' => 'prepared',
                        'at' => \time(),
                        'nonce' => \bin2hex(\random_bytes(16)),
                    ];
                    $this->atomicWrite(
                        $pending,
                        $this->encodePlatformRemovalFence($removalFence),
                        0600,
                    );
                } elseif (!\hash_equals($kind, (string)$removalFence['kind'])) {
                    throw new \RuntimeException(
                        'Existing WLS Gateway platform removal fence belongs to another operation.',
                    );
                } elseif ((int)$removalFence['schema'] === 1) {
                    // Schema 1 may have crashed after either unlink. Bind its
                    // authenticated metadata image before replaying the
                    // idempotent exact-layout phases.
                    $removalFence = [
                        'kind' => $kind,
                        'layout' => $layoutName,
                        'definition_sha256' => $definitionDigest,
                        'phase' => 'prepared',
                        'at' => (int)$removalFence['at'],
                        'nonce' => (string)$removalFence['nonce'],
                    ];
                    $this->atomicWrite(
                        $pending,
                        $this->encodePlatformRemovalFence($removalFence),
                        0600,
                    );
                } elseif (!\hash_equals($layoutName, (string)$removalFence['layout'])
                    || !\hash_equals(
                        $definitionDigest,
                        (string)$removalFence['definition_sha256'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Existing WLS Gateway systemd removal fence is not bound to installed metadata.',
                    );
                }
                $linuxSystemdRemoval = [
                    'legacy' => $legacy,
                    'definition' => $definition,
                ];
            }
        }
        $installJournalStatus = @\lstat(
            $this->platformDefinitionTransactionFile(),
        );
        if (!\is_array($installJournalStatus)
            && (\file_exists($this->platformDefinitionTransactionFile())
                || \is_link($this->platformDefinitionTransactionFile()))
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform install transaction path is indeterminate during removal.',
            );
        }
        if (\is_array($recovered)
            && \hash_equals(
                'install',
                (string)($recovered['operation'] ?? ''),
            )
            && \is_array($installJournalStatus)
        ) {
            if ($this->paths->isTestMode()
                || \PHP_OS_FAMILY !== 'Windows'
                || !\hash_equals('windows-service', $kind)
                || \is_link($this->platformDefinitionTransactionFile())
                || ((((int)($installJournalStatus['mode'] ?? 0)) & 0170000)
                    !== 0100000)
                || (int)($installJournalStatus['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException(
                    'Only an exact pending Windows permission-seal transaction can be cancelled during platform removal.',
                );
            }
            $installJournal = $this->decodePlatformDefinitionTransaction(
                $this->readStableRegularFile(
                    $this->platformDefinitionTransactionFile(),
                    self::PLATFORM_DEFINITION_TRANSACTION_MAX_BYTES,
                    'WLS Gateway cancelled Windows install transaction',
                ),
            );
            $this->assertPlatformDefinitionTransactionTargetsInClosure(
                $installJournal,
            );
            $this->cleanupPlatformDefinitionTransactionTargetArtifacts(
                $installJournal,
            );
            $this->removePlatformDefinitionTransaction($installJournal);
        }
        if ($removalFence === null) {
            $this->atomicWrite(
                $pending,
                "WLS-PLATFORM-REMOVAL/1\n"
                    . 'kind=' . $kind . "\n"
                    . 'at=' . \time() . "\n"
                    . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n",
                0600,
            );
            $removalFence = $this->decodePlatformRemovalFence(
                (string)GatewayProjectStateFilesystem::read(
                    $pending,
                    1024,
                    'WLS Gateway platform removal fence',
                ),
            );
        } elseif (!\hash_equals($kind, (string)$removalFence['kind'])) {
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
        if (\is_array($linuxSystemdRemoval)) {
            $phase = (string)($removalFence['phase'] ?? '');
            $definition = $linuxSystemdRemoval['definition'] ?? null;
            if (($linuxSystemdRemoval['legacy'] ?? false) === true) {
                if (\hash_equals('canonical-removed', $phase)) {
                    throw new \RuntimeException(
                        'A legacy WLS Gateway systemd removal fence cannot have a canonical-link phase.',
                    );
                }
                if (\hash_equals('prepared', $phase)) {
                    if (!\is_string($definition)) {
                        throw new \RuntimeException(
                            'Legacy WLS Gateway systemd removal lost its definition authority.',
                        );
                    }
                    $this->linuxSystemdLayout()->removeExactLegacyDefinition(
                        $definition,
                    );
                    $removalFence['phase'] = 'definition-removed';
                    $this->atomicWrite(
                        $pending,
                        $this->encodePlatformRemovalFence($removalFence),
                        0600,
                    );
                }
                $this->linuxSystemdLayout()->assertLegacyDefinitionRemoved();
            } else {
                if (\hash_equals('prepared', $phase)) {
                    if (!\is_string($definition)) {
                        throw new \RuntimeException(
                            'Current WLS Gateway systemd removal lost its definition authority.',
                        );
                    }
                    $this->linuxSystemdLayout()->removeCurrentCanonicalFixedLink(
                        $definition,
                    );
                    $removalFence['phase'] = 'canonical-removed';
                    $this->atomicWrite(
                        $pending,
                        $this->encodePlatformRemovalFence($removalFence),
                        0600,
                    );
                    $phase = 'canonical-removed';
                }
                if (\hash_equals('canonical-removed', $phase)) {
                    if (!\is_string($definition)) {
                        throw new \RuntimeException(
                            'Current WLS Gateway systemd removal lost its definition authority.',
                        );
                    }
                    $this->linuxSystemdLayout()->removeCurrentTargetAfterFixedLink(
                        $definition,
                    );
                    $removalFence['phase'] = 'definition-removed';
                    $this->atomicWrite(
                        $pending,
                        $this->encodePlatformRemovalFence($removalFence),
                        0600,
                    );
                }
                $this->linuxSystemdLayout()->assertCurrentDefinitionRemoved();
            }
        } else {
            $path = $this->paths->serviceDefinitionFile();
            if ((\file_exists($path) || \is_link($path))
                && !$this->removeVerifiedRegularFile($path)
            ) {
                throw new \RuntimeException('Unable to remove the failed gateway service definition.');
            }
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
            '{{GUARDIAN}}' => $this->paths->guardianFile(),
            '{{HOME}}' => $this->paths->home(),
            '{{RUN_DIR}}' => $this->paths->runDir(),
            '{{SYSTEMD_DEFINITION_DIR}}' => $this->paths->systemdDefinitionDirectory(),
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
        if ($this->managesLinuxSystemdLayout()) {
            $this->paths->assertSystemdUnitLinkDirectoryAuthority();
            $link = $this->paths->systemdServiceLinkFile();
            if (@\lstat($link) !== false
                || \file_exists($link)
                || \is_link($link)
            ) {
                throw new \RuntimeException(
                    'A WLS Gateway canonical systemd link already exists: ' . $link,
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
        $guardian = '"' . $this->paths->guardianFile() . '" --service'
            . ' --home="' . $this->paths->home() . '"'
            . ' --run="' . $this->paths->runDir() . '"';
        $existing = $this->queryWindowsService();
        if ($existing !== null) {
            $this->mustRun([
                $this->windowsSystemExecutable('sc.exe'),
                'config',
                self::SERVICE_NAME,
                'binPath=',
                $guardian,
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
                $guardian,
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
            '0',
            'actions=',
            'restart/5000/restart/5000/restart/5000',
        ], 'Windows service recovery policy');
        $this->mustRun([
            $this->windowsSystemExecutable('sc.exe'),
            'failureflag',
            self::SERVICE_NAME,
            '1',
        ], 'Windows non-crash recovery policy');
        $this->configureWindowsServicePreshutdownTimeout();
        $this->configureWindowsServiceObjectDacl();
    }

    private function configureWindowsServicePreshutdownTimeout(): void
    {
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$serviceName = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_SERVICE_NAME__'))
Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;

public static class WlsGatewayPreshutdownConfig {
    private const UInt32 SC_MANAGER_CONNECT = 0x0001;
    private const UInt32 SERVICE_QUERY_CONFIG = 0x0001;
    private const UInt32 SERVICE_CHANGE_CONFIG = 0x0002;
    private const UInt32 SERVICE_CONFIG_PRESHUTDOWN_INFO = 7;
    private const UInt32 PRESHUTDOWN_TIMEOUT_MILLISECONDS = 330000;

    [StructLayout(LayoutKind.Sequential)]
    private struct SERVICE_PRESHUTDOWN_INFO {
        public UInt32 dwPreshutdownTimeout;
    }

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr OpenSCManagerW(string machine, string database, UInt32 access);

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr OpenServiceW(IntPtr manager, string name, UInt32 access);

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ChangeServiceConfig2W(
        IntPtr service,
        UInt32 level,
        ref SERVICE_PRESHUTDOWN_INFO info
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool QueryServiceConfig2W(
        IntPtr service,
        UInt32 level,
        IntPtr buffer,
        UInt32 bufferSize,
        out UInt32 bytesNeeded
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseServiceHandle(IntPtr handle);

    public static void Apply(string serviceName) {
        IntPtr manager = IntPtr.Zero;
        IntPtr service = IntPtr.Zero;
        IntPtr readback = IntPtr.Zero;
        try {
            manager = OpenSCManagerW(null, null, SC_MANAGER_CONNECT);
            if (manager == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            service = OpenServiceW(
                manager,
                serviceName,
                SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG
            );
            if (service == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            SERVICE_PRESHUTDOWN_INFO info = new SERVICE_PRESHUTDOWN_INFO {
                dwPreshutdownTimeout = PRESHUTDOWN_TIMEOUT_MILLISECONDS
            };
            if (!ChangeServiceConfig2W(service, SERVICE_CONFIG_PRESHUTDOWN_INFO, ref info)) {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            UInt32 bytesNeeded = 0;
            UInt32 readbackSize = (UInt32)Marshal.SizeOf(typeof(SERVICE_PRESHUTDOWN_INFO));
            readback = Marshal.AllocHGlobal((Int32)readbackSize);
            if (!QueryServiceConfig2W(
                    service,
                    SERVICE_CONFIG_PRESHUTDOWN_INFO,
                    readback,
                    readbackSize,
                    out bytesNeeded
                )) {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            SERVICE_PRESHUTDOWN_INFO actual = (SERVICE_PRESHUTDOWN_INFO)
                Marshal.PtrToStructure(readback, typeof(SERVICE_PRESHUTDOWN_INFO));
            if (actual.dwPreshutdownTimeout != PRESHUTDOWN_TIMEOUT_MILLISECONDS) {
                throw new InvalidOperationException("SCM preshutdown timeout read-back mismatch.");
            }
        } finally {
            if (readback != IntPtr.Zero) Marshal.FreeHGlobal(readback);
            if (service != IntPtr.Zero) CloseServiceHandle(service);
            if (manager != IntPtr.Zero) CloseServiceHandle(manager);
        }
    }
}
'@
[WlsGatewayPreshutdownConfig]::Apply($serviceName)
POWERSHELL;
        $script = \str_replace(
            '__WLS_SERVICE_NAME__',
            \base64_encode(self::SERVICE_NAME),
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
        ], 'Windows service preshutdown policy');
    }

    private function configureWindowsServiceObjectDacl(): void
    {
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$serviceName = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_SERVICE_NAME__'))
$sddl = 'D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)'
Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;

public static class WlsGatewayServiceObjectSecurity {
    private const UInt32 SC_MANAGER_CONNECT = 0x0001;
    private const UInt32 READ_CONTROL = 0x00020000;
    private const UInt32 WRITE_DAC = 0x00040000;
    private const UInt32 DACL_SECURITY_INFORMATION = 0x00000004;
    private const UInt32 PROTECTED_DACL_SECURITY_INFORMATION = 0x80000000;
    private const UInt32 SE_SERVICE = 5;
    private const Int32 SERVICE_ALL_ACCESS = 0x000F01FF;

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr OpenSCManagerW(string machine, string database, UInt32 access);

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr OpenServiceW(IntPtr manager, string name, UInt32 access);

    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool ConvertStringSecurityDescriptorToSecurityDescriptorW(
        string sddl,
        UInt32 revision,
        out IntPtr descriptor,
        out UInt32 size
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetSecurityDescriptorDacl(
        IntPtr descriptor,
        out bool present,
        out IntPtr dacl,
        out bool defaulted
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    private static extern UInt32 SetSecurityInfo(
        IntPtr handle,
        UInt32 objectType,
        UInt32 information,
        IntPtr owner,
        IntPtr group,
        IntPtr dacl,
        IntPtr sacl
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool QueryServiceObjectSecurity(
        IntPtr service,
        UInt32 information,
        IntPtr descriptor,
        UInt32 descriptorSize,
        out UInt32 bytesNeeded
    );

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseServiceHandle(IntPtr handle);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr LocalFree(IntPtr memory);

    private static void AssertExactProtectedDacl(IntPtr service) {
        UInt32 bytesNeeded = 0;
        QueryServiceObjectSecurity(
            service,
            DACL_SECURITY_INFORMATION,
            IntPtr.Zero,
            0,
            out bytesNeeded
        );
        if (bytesNeeded == 0) {
            throw new Win32Exception(Marshal.GetLastWin32Error());
        }
        IntPtr readback = Marshal.AllocHGlobal((Int32)bytesNeeded);
        try {
            if (!QueryServiceObjectSecurity(
                    service,
                    DACL_SECURITY_INFORMATION,
                    readback,
                    bytesNeeded,
                    out bytesNeeded
                )) {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            byte[] raw = new byte[bytesNeeded];
            Marshal.Copy(readback, raw, 0, (Int32)bytesNeeded);
            RawSecurityDescriptor actual = new RawSecurityDescriptor(raw, 0);
            if ((actual.ControlFlags & ControlFlags.DiscretionaryAclProtected) == 0
                || actual.DiscretionaryAcl == null
                || actual.DiscretionaryAcl.Count != 2) {
                throw new InvalidOperationException("SCM service DACL protection or ACE count mismatch.");
            }
            SecurityIdentifier system = new SecurityIdentifier(
                WellKnownSidType.LocalSystemSid,
                null
            );
            SecurityIdentifier administrators = new SecurityIdentifier(
                WellKnownSidType.BuiltinAdministratorsSid,
                null
            );
            Int32 systemCount = 0;
            Int32 administratorCount = 0;
            foreach (GenericAce ace in actual.DiscretionaryAcl) {
                CommonAce common = ace as CommonAce;
                if (common == null
                    || common.AceFlags != AceFlags.None
                    || common.AceQualifier != AceQualifier.AccessAllowed
                    || common.AccessMask != SERVICE_ALL_ACCESS) {
                    throw new InvalidOperationException("SCM service DACL contains an unexpected ACE.");
                }
                if (common.SecurityIdentifier.Equals(system)) {
                    systemCount++;
                } else if (common.SecurityIdentifier.Equals(administrators)) {
                    administratorCount++;
                } else {
                    throw new InvalidOperationException("SCM service DACL contains an unauthorized SID.");
                }
            }
            if (systemCount != 1 || administratorCount != 1) {
                throw new InvalidOperationException("SCM service DACL principal set mismatch.");
            }
        } finally {
            Marshal.FreeHGlobal(readback);
        }
    }

    public static void Apply(string serviceName, string sddl) {
        IntPtr manager = IntPtr.Zero;
        IntPtr service = IntPtr.Zero;
        IntPtr descriptor = IntPtr.Zero;
        IntPtr dacl = IntPtr.Zero;
        UInt32 size = 0;
        try {
            manager = OpenSCManagerW(null, null, SC_MANAGER_CONNECT);
            if (manager == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            service = OpenServiceW(manager, serviceName, WRITE_DAC | READ_CONTROL);
            if (service == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            if (!ConvertStringSecurityDescriptorToSecurityDescriptorW(sddl, 1, out descriptor, out size)
                || descriptor == IntPtr.Zero || size == 0) {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            bool present = false;
            bool defaulted = false;
            if (!GetSecurityDescriptorDacl(descriptor, out present, out dacl, out defaulted)
                || !present || defaulted || dacl == IntPtr.Zero) {
                throw new InvalidOperationException("SCM service DACL descriptor is invalid.");
            }
            UInt32 status = SetSecurityInfo(
                service,
                SE_SERVICE,
                DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
                IntPtr.Zero,
                IntPtr.Zero,
                dacl,
                IntPtr.Zero
            );
            if (status != 0) {
                throw new Win32Exception((Int32)status);
            }
            AssertExactProtectedDacl(service);
        } finally {
            if (descriptor != IntPtr.Zero) LocalFree(descriptor);
            if (service != IntPtr.Zero) CloseServiceHandle(service);
            if (manager != IntPtr.Zero) CloseServiceHandle(manager);
        }
    }
}
'@
[WlsGatewayServiceObjectSecurity]::Apply($serviceName, $sddl)
POWERSHELL;
        $script = \str_replace(
            '__WLS_SERVICE_NAME__',
            \base64_encode(self::SERVICE_NAME),
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
        ], 'Windows service object DACL sealing');
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
            GatewayWindowsHostRootAuthority::ensureBootstrapDirectories([
                $this->paths->sealedSnapshotsDir(),
                $this->paths->snapshotCandidatesDir(),
            ], true);
            $serviceIdentity = 'NT SERVICE\\' . self::SERVICE_NAME;
            $readOnlyTrees = [
                $this->paths->trustDir(),
                $this->paths->guardianDir(),
                \dirname($this->paths->launcherFile()),
            ];
            $slotTrees = [];
            foreach (['A', 'B'] as $slot) {
                $slotDirectory = $this->paths->slotDir($slot);
                if (\is_dir($slotDirectory) || \is_link($slotDirectory)) {
                    $slotTrees[] = $slotDirectory;
                }
            }
            $mutable = [
                $this->paths->runtimeDir(),
                $this->paths->runDir(),
                $this->paths->logDir(),
                $this->paths->stateDir(),
                $this->paths->legacySnapshotsDir(),
            ];
            $snapshotNamespaceTrees = [
                $this->paths->sealedSnapshotsDir(),
                $this->paths->snapshotCandidatesDir(),
            ];
            foreach (\array_unique([
                ...$readOnlyTrees,
                ...$mutable,
                ...$snapshotNamespaceTrees,
            ]) as $directory) {
                if (!\is_dir($directory) || \is_link($directory)) {
                    throw new \RuntimeException(
                        'Windows gateway ACL target is missing or is a reparse point: '
                        . $directory
                    );
                }
                $this->assertWindowsTreeHasNoLinks($directory);
            }
            foreach ($slotTrees as $slotDirectory) {
                if (!\is_dir($slotDirectory) || \is_link($slotDirectory)) {
                    throw new \RuntimeException(
                        'Windows gateway slot ACL target is missing or reparse: '
                            . $slotDirectory
                    );
                }
                $this->assertWindowsTreeHasNoLinks(
                    $slotDirectory,
                    self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                );
            }
            $rootOnlyPaths = [];
            foreach ($this->rootOnlyTrustArtifactPaths() as $rootOnlyPath) {
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
            $this->secureWindowsRebootstrapWorkspaceAcl();
            $this->applyWindowsFixedNamespaceAcl(
                $this->paths->home(),
                self::WINDOWS_CONTROLLER_SERVICE_SID,
                'RX',
            );
            $this->applyWindowsDataPlaneTraversalAcl(
                $this->paths->home(),
                true,
            );
            $this->applyWindowsAcl(
                $this->paths->slotsDir(),
                $serviceIdentity,
                'RX',
                recursive: false,
                inheritChildren: false,
            );
            $this->applyWindowsDataPlaneTraversalAcl(
                $this->paths->slotsDir(),
                false,
            );
            foreach ($slotTrees as $slotDirectory) {
                $this->applyWindowsAcl(
                    $slotDirectory,
                    $serviceIdentity,
                    'RX',
                    maximumEntries: self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                );
                $this->applyWindowsDataPlaneTraversalAcl(
                    $slotDirectory,
                    false,
                );
                $this->applyWindowsDataPlaneTraversalAcl(
                    $slotDirectory . DIRECTORY_SEPARATOR . 'bin',
                    false,
                );
                $this->applyWindowsNginxExecutableAcl(
                    $slotDirectory . DIRECTORY_SEPARATOR . 'bin'
                        . DIRECTORY_SEPARATOR . 'nginx.exe',
                );
            }
            foreach ($readOnlyTrees as $directory) {
                $this->applyWindowsAcl(
                    $directory,
                    $serviceIdentity,
                    'RX',
                    $rootOnlyPaths,
                );
            }
            $launcherRecoveryStatus = $this->paths
                ->launcherRecoveryStatusFile();
            foreach ($mutable as $directory) {
                $this->applyWindowsAcl(
                    $directory,
                    $serviceIdentity,
                    'M',
                    str_starts_with(
                        strtolower($launcherRecoveryStatus),
                        strtolower(\rtrim($directory, '/\\'))
                            . DIRECTORY_SEPARATOR,
                    )
                        ? [$launcherRecoveryStatus]
                        : [],
                );
            }
            $this->applyWindowsFixedNamespaceAcl(
                $this->paths->sealedSnapshotsDir(),
                self::WINDOWS_DATA_PLANE_SERVICE_SID,
                'GX',
            );
            $this->applyWindowsFixedNamespaceAcl(
                $this->paths->snapshotCandidatesDir(),
                self::WINDOWS_CONTROLLER_SERVICE_SID,
                'GA',
            );
            if (\file_exists($launcherRecoveryStatus)
                || \is_link($launcherRecoveryStatus)
            ) {
                $this->applyWindowsDiagnosticAcl(
                    $launcherRecoveryStatus,
                );
            }
            $this->completeWindowsInstallPermissionTransaction();
            return;
        }
        $identity = $this->ensurePosixServiceIdentity('controller');
        $dataPlaneIdentity = $this->ensurePosixServiceIdentity('data-plane');
        $this->assertPosixIdentitySeparation($identity, $dataPlaneIdentity);
        $uid = (int)$identity['uid'];
        $gid = (int)$identity['gid'];
        $dataPlaneGid = (int)$dataPlaneIdentity['gid'];
        foreach ([
            $this->paths->sealedSnapshotsDir(),
            $this->paths->snapshotCandidatesDir(),
        ] as $fixedNamespaceRoot) {
            $status = @\lstat($fixedNamespaceRoot);
            if (!\is_array($status)) {
                if (\file_exists($fixedNamespaceRoot)
                    || \is_link($fixedNamespaceRoot)
                    || !@\mkdir($fixedNamespaceRoot, 0700)
                ) {
                    throw new \RuntimeException(
                        'Unable to create fixed gateway snapshot namespace: '
                            . $fixedNamespaceRoot,
                    );
                }
                $status = @\lstat($fixedNamespaceRoot);
            }
            if (!\is_array($status)
                || \is_link($fixedNamespaceRoot)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (((int)($status['mode'] ?? 0)) & 0022) !== 0
            ) {
                throw new \RuntimeException(
                    'Fixed gateway snapshot namespace is linked, special, or writable: '
                        . $fixedNamespaceRoot,
                );
            }
        }
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
            // The data-plane account receives search-only access at the host
            // root and immutable world-readable code below slots. It cannot
            // list or traverse trust/state and is not a member of the
            // Controller group.
            $this->paths->home() => [0, $gid, 0751],
            $this->paths->slotsDir() => [0, $gid, 0755],
            $this->paths->trustDir() => [0, $gid, 0750],
            // Candidate/backup/journal companions are administrator-only. A
            // sealed candidate may carry Controller-readable leaf modes, but
            // this parent prevents the live service from discovering it before
            // the stopped transaction renames it into A/B.
            $this->paths->rebootstrapDir() => [0, 0, 0700],
            $this->paths->guardianDir() => [0, 0, 0700],
            \dirname($this->paths->launcherFile()) => [0, $gid, 0750],
            $this->paths->runtimeDir() => [$uid, $dataPlaneGid, 0750],
            // Shared control sockets never grant the data-plane group access.
            // The project socket remains reachable through its own mode and
            // the Broker additionally rejects the data-plane kernel peer UID.
            $this->paths->runDir() => [0, $gid, 0771],
            $this->paths->logDir() => [$uid, $dataPlaneGid, 0770],
            $this->paths->stateDir() => [$uid, $gid, 0700],
            // Controller may stage and lstat digest leaves, but sealed leaves
            // are owned by the data plane and are not traversable by it.
            $this->paths->legacySnapshotsDir() => [$uid, $dataPlaneGid, 0710],
            // Published snapshots are immutable data-plane inputs. The
            // controller can enumerate the root but never owns it.
            $this->paths->sealedSnapshotsDir() => [0, $dataPlaneGid, 0710],
            // Candidate material remains controller-private until the native
            // broker seals and atomically promotes one exact generation.
            $this->paths->snapshotCandidatesDir() => [$uid, $gid, 0700],
        ] as $directory => [$owner, $directoryGroup, $mode]) {
            if (!\is_dir($directory)
                || \is_link($directory)
                || (\PHP_OS_FAMILY === 'Linux'
                    && !$this->removeLinuxPosixDirectoryAcl($directory))
                || !@\chown($directory, $owner)
                || !@\chgrp($directory, $directoryGroup)
                || !@\chmod($directory, $mode)
            ) {
                throw new \RuntimeException('Unable to apply gateway privilege separation: ' . $directory);
            }
        }
        foreach ([
            $this->paths->stateDir(),
        ] as $controllerTree) {
            $this->secureControllerTree($controllerTree, $uid, $gid);
        }
        $rootOnlyPaths = [];
        foreach ($this->rootOnlyTrustArtifactPaths() as $rootOnlyPath) {
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
        $this->secureRuntimeTree(
            $this->paths->trustDir(),
            $gid,
            $rootOnlyPaths,
        );
        foreach (['A', 'B'] as $slot) {
            $slotDirectory = $this->paths->slotDir($slot);
            if (\is_dir($slotDirectory)) {
                $this->secureRuntimeTree($slotDirectory, $gid, [], true);
            }
        }
    }

    private function assertPosixServiceTreeSafe(string $root): void
    {
        GatewayBoundedTreeWalker::collect($root);
    }

    private function removeLinuxPosixDirectoryAcl(string $directory): bool
    {
        if (\PHP_OS_FAMILY !== 'Linux' || !\class_exists(\FFI::class)) {
            return false;
        }
        static $ffi = null;
        try {
            $ffi ??= \FFI::cdef(
                'int open(const char *path, int flags, ...);'
                    . ' int fremovexattr(int fd, const char *name);'
                    . ' int fsync(int fd);'
                    . ' int close(int fd);'
                    . ' int *__errno_location(void);',
            );
            $fd = (int)$ffi->open(
                $directory,
                0x10000 | 0x20000 | 0x80000,
            );
            if ($fd < 0) {
                return false;
            }
            try {
                foreach ([
                    'system.posix_acl_access',
                    'system.posix_acl_default',
                ] as $name) {
                    $ffi->__errno_location()[0] = 0;
                    $removed = (int)$ffi->fremovexattr($fd, $name);
                    $error = (int)$ffi->__errno_location()[0];
                    if ($removed !== 0
                        && !\in_array($error, [61, 95], true)
                    ) {
                        return false;
                    }
                }
                return (int)$ffi->fsync($fd) === 0;
            } finally {
                if ((int)$ffi->close($fd) !== 0) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }
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

    private function assertWindowsTreeHasNoLinks(
        string $root,
        int $maximumEntries = GatewayBoundedTreeWalker::MAX_ENTRIES,
    ): void {
        GatewayBoundedTreeWalker::collect(
            $root,
            true,
            false,
            $maximumEntries,
        );
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
__WLS_BOUNDED_WALKER__
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_PATH__'))
$null = @(Get-WlsBoundedTree -RootPath $path)
POWERSHELL;
        $script = \str_replace(
            ['__WLS_BOUNDED_WALKER__', '__WLS_PATH__'],
            [
                $this->windowsBoundedTreeWalkerScript($maximumEntries),
                \base64_encode($root),
            ],
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
            'package-bootstrap.lock',
            'package-install.lock',
            'initial-bootstrap.transaction',
            'platform-definition.transaction',
            'rebootstrap.transaction',
            'rebootstrap-start.authorization',
            'launcher-recovery.ledger',
            'stable-launcher.sha256',
            'guardian.sha256',
            'guardian-generation-head.0',
            'guardian-generation-head.1',
            'guardian-generation-head.lock',
            'guardian-transition.request',
            'guardian-transition.ack',
            'guardian-transition.retirement',
            'guardian-recovery.transaction',
            'ca-bundle.sha256',
            'failed-initial-cleanup.intent',
        ];
    }

    /**
     * Root-only recovery companions carry the same sensitive after-image as
     * their target. They must never inherit Controller-readable tree ACLs.
     *
     * @return list<string>
     */
    private function rootOnlyTrustArtifactPaths(): array
    {
        $trust = $this->paths->trustDir();
        $handle = @\opendir($trust);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Gateway trust root cannot be enumerated for root-only sealing.',
            );
        }
        $roots = \array_fill_keys($this->rootOnlyTrustFiles(), true);
        $paths = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$visited > self::PLATFORM_ATOMIC_RECOVERY_ENTRY_QUOTA) {
                    throw new \RuntimeException(
                        'Gateway trust root exceeds its fixed sealing quota.',
                    );
                }
                $base = (string)\preg_replace(
                    '/\.(?:wls-backup-[a-f0-9]{16}|tmp-[a-f0-9]{24})\z/D',
                    '',
                    $leaf,
                );
                if (!isset($roots[$base])) {
                    continue;
                }
                $path = $trust . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($path);
                if (!\is_array($status)
                    || \is_link($path)
                    || ((((int)($status['mode'] ?? 0)) & 0170000)
                        !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Gateway root-only trust artifact is unsafe: ' . $path,
                    );
                }
                $paths[] = $path;
            }
        } finally {
            @\closedir($handle);
        }
        \sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array{
     *   root:array<string,mixed>,
     *   entries:array<string,array<string,mixed>>
     * }
     */
    private function rebootstrapBackupTopLevelInventory(
        string $backup,
    ): array {
        $this->assertTraversalDeadline();
        $root = $this->rebootstrapBackupObjectRecord($backup, true);
        $handle = @\opendir($backup);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Gateway rebootstrap backup cannot be enumerated for ACL sealing.'
            );
        }
        $allowed = [
            ...self::REBOOTSTRAP_BACKUP_ACL_ROOTS,
            'derived-state.manifest.json',
            'guardian-recovery.authorization',
            'guardian-recovery.inventory',
        ];
        $entries = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $this->assertTraversalDeadline();
                if (++$visited > \count($allowed)
                    || !\in_array($leaf, $allowed, true)
                    || isset($entries[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap backup contains an unexpected ACL root: '
                            . $leaf
                    );
                }
                $directory = !\in_array($leaf, [
                    'derived-state.manifest.json',
                    'guardian-recovery.authorization',
                    'guardian-recovery.inventory',
                ], true);
                $entries[$leaf] = $this->rebootstrapBackupObjectRecord(
                    $backup . DIRECTORY_SEPARATOR . $leaf,
                    $directory,
                );
            }
        } finally {
            @\closedir($handle);
        }
        $after = $this->rebootstrapBackupObjectRecord($backup, true);
        if (!$this->sameRebootstrapBackupObjectIdentity($root, $after)) {
            throw new \RuntimeException(
                'Gateway rebootstrap backup identity changed during ACL inventory.'
            );
        }
        \ksort($entries, SORT_STRING);
        return ['root' => $root, 'entries' => $entries];
    }

    /**
     * Split large roots at their immediate children. A valid slots backup can
     * contain two independently bounded A/B runtime trees, and a derived root
     * can contain the full 16,384-entry manifest closure plus its category
     * directories. Walking either aggregate with the single-tree limit would
     * reject a valid maximum-sized rollback generation.
     *
     * @param array<string,mixed> $root
     * @return array{
     *   root:array<string,mixed>,
     *   segments:array<string,list<array<string,mixed>>>
     * }
     */
    private function rebootstrapBackupAclTreeClosure(
        array $root,
        int &$remainingEntries,
    ): array {
        if (!(bool)($root['directory'] ?? false)) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL tree root is not a directory.'
            );
        }
        if ($remainingEntries < 1) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL closure exceeds its fixed entry quota.'
            );
        }
        --$remainingEntries;
        $this->assertRebootstrapBackupObjectUnchanged($root);
        $path = (string)$root['path'];
        $handle = @\opendir($path);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL tree cannot be enumerated: ' . $path
            );
        }
        $segments = ['.' => [$root]];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $this->assertTraversalDeadline();
                if (++$visited
                    > self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA
                    || isset($segments[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap ACL tree exceeds its immediate-child quota.'
                    );
                }
                $child = $path . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($child);
                $type = \is_array($status)
                    ? (((int)($status['mode'] ?? 0)) & 0170000)
                    : 0;
                if (!\in_array($type, [0040000, 0100000], true)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap ACL tree contains a link or special file: '
                            . $child
                    );
                }
                $record = $this->rebootstrapBackupObjectRecord(
                    $child,
                    $type === 0040000,
                );
                if ($remainingEntries < 1) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap ACL closure exceeds its fixed entry quota.'
                    );
                }
                $records = $record['directory']
                    ? GatewayBoundedTreeWalker::collect(
                        $child,
                        true,
                        false,
                        \min(
                            self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                            \max(1, $remainingEntries - 1),
                        ),
                    )
                    : [$record];
                $segmentCount = \count($records);
                if ($segmentCount > $remainingEntries) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap ACL closure exceeds its fixed entry quota.'
                    );
                }
                $remainingEntries -= $segmentCount;
                $segments[$leaf] = $records;
            }
        } finally {
            @\closedir($handle);
        }
        $this->assertRebootstrapBackupObjectUnchanged($root);
        \ksort($segments, SORT_STRING);
        return ['root' => $root, 'segments' => $segments];
    }

    /**
     * @param array{root:array<string,mixed>,segments:array<string,list<array<string,mixed>>>} $expected
     * @param array{root:array<string,mixed>,segments:array<string,list<array<string,mixed>>>} $current
     */
    private function assertSameRebootstrapBackupAclTreeClosure(
        array $expected,
        array $current,
    ): void {
        if (!$this->sameRebootstrapBackupObjectIdentity(
                $expected['root'],
                $current['root'],
            )
            || \array_keys($expected['segments'])
                !== \array_keys($current['segments'])
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL segmented tree closure changed.'
            );
        }
        foreach ($expected['segments'] as $leaf => $records) {
            $this->assertSameRebootstrapBackupRecordClosure(
                $records,
                $current['segments'][$leaf],
            );
        }
    }

    /**
     * @return array{
     *   path:string,
     *   directory:bool,
     *   device:string,
     *   inode:string,
     *   mode:int,
     *   uid:int,
     *   nlink:int
     * }
     */
    private function rebootstrapBackupObjectRecord(
        string $path,
        bool $directory,
    ): array {
        $this->assertTraversalDeadline();
        $status = @\lstat($path);
        $type = \is_array($status)
            ? (((int)($status['mode'] ?? 0)) & 0170000)
            : 0;
        $expectedType = $directory ? 0040000 : 0100000;
        if (!\is_array($status)
            || \is_link($path)
            || $type !== $expectedType
            || (!$directory && (int)($status['nlink'] ?? 0) !== 1)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL object is linked, special, or hard-linked: '
                    . $path
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $identity = $this->windowsRebootstrapBackupObjectIdentity(
                $path,
                $directory,
            );
        } else {
            if (!\array_key_exists('dev', $status)
                || !\array_key_exists('ino', $status)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ACL object has no stable identity: '
                        . $path
                );
            }
            $identity = [
                'device' => (string)$status['dev'],
                'inode' => (string)$status['ino'],
            ];
        }
        return [
            'path' => $path,
            'directory' => $directory,
            'device' => $identity['device'],
            'inode' => $identity['inode'],
            'mode' => (int)$status['mode'],
            'uid' => (int)($status['uid'] ?? -1),
            'nlink' => (int)($status['nlink'] ?? 0),
        ];
    }

    /** @return array{device:string,inode:string} */
    private function windowsRebootstrapBackupObjectIdentity(
        string $path,
        bool $directory,
    ): array {
        $opened = $this->openWindowsRebootstrapBackupObject(
            $path,
            $directory,
            false,
        );
        $identity = null;
        $failure = null;
        try {
            $identity = [
                'device' => $opened['device'],
                'inode' => $opened['inode'],
            ];
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }
        try {
            $this->closeWindowsRebootstrapBackupObject($opened);
        } catch (\Throwable $closeFailure) {
            if ($failure !== null) {
                throw new \RuntimeException(
                    $closeFailure->getMessage(),
                    0,
                    $failure,
                );
            }
            throw $closeFailure;
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (!\is_array($identity)) {
            throw new \RuntimeException(
                'Windows rebootstrap ACL object identity was not captured.',
            );
        }
        return $identity;
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function withWindowsRebootstrapBackupIdentityHandles(
        array $records,
        \Closure $callback,
    ): void {
        $opened = [];
        $failure = null;
        try {
            foreach ($records as $record) {
                $this->assertTraversalDeadline();
                $object = $this->openWindowsRebootstrapBackupObject(
                    (string)$record['path'],
                    (bool)$record['directory'],
                    true,
                );
                $opened[] = $object;
                if (!\hash_equals(
                        (string)$record['device'],
                        (string)$object['device'],
                    )
                    || !\hash_equals(
                        (string)$record['inode'],
                        (string)$object['inode'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap ACL object changed before its identity handle was acquired: '
                            . (string)$record['path']
                    );
                }
            }
            $callback();
            foreach ($opened as $index => $object) {
                $this->assertTraversalDeadline();
                $identity = $this->windowsRebootstrapBackupHandleIdentity(
                    $object['ffi'],
                    $object['handle'],
                    (bool)$object['directory'],
                    (string)$object['path'],
                );
                $expected = $records[$index];
                if (!\hash_equals(
                        (string)$expected['device'],
                        $identity['device'],
                    )
                    || !\hash_equals(
                        (string)$expected['inode'],
                        $identity['inode'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap ACL handle identity changed during reseal: '
                            . (string)$object['path']
                    );
                }
                $this->assertRebootstrapBackupObjectUnchanged($expected);
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }
        for ($index = \count($opened) - 1; $index >= 0; --$index) {
            try {
                $this->closeWindowsRebootstrapBackupObject($opened[$index]);
            } catch (\Throwable $closeFailure) {
                $failure = $failure === null
                    ? $closeFailure
                    : new \RuntimeException(
                        $closeFailure->getMessage(),
                        0,
                        $failure,
                    );
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return array{
     *   ffi:\FFI,
     *   handle:\FFI\CData,
     *   path:string,
     *   directory:bool,
     *   device:string,
     *   inode:string
     * }
     */
    private function openWindowsRebootstrapBackupObject(
        string $path,
        bool $directory,
        bool $denyDelete,
    ): array {
        $ffi = $this->windowsRebootstrapBackupKernel32();
        $handle = $ffi->CreateFileW(
            $this->windowsTransactionWidePath($ffi, $path),
            0x00000080,
            $denyDelete ? 0x00000003 : 0x00000007,
            null,
            3,
            0x02200000,
            null,
        );
        try {
            $identity = $this->windowsRebootstrapBackupHandleIdentity(
                $ffi,
                $handle,
                $directory,
                $path,
            );
        } catch (\Throwable $throwable) {
            try {
                $this->closeWindowsRebootstrapBackupObject([
                    'ffi' => $ffi,
                    'handle' => $handle,
                    'path' => $path,
                ]);
            } catch (\Throwable $closeFailure) {
                throw new \RuntimeException(
                    $closeFailure->getMessage(),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
        return [
            'ffi' => $ffi,
            'handle' => $handle,
            'path' => $path,
            'directory' => $directory,
            'device' => $identity['device'],
            'inode' => $identity['inode'],
        ];
    }

    /**
     * @return array{device:string,inode:string}
     */
    private function windowsRebootstrapBackupHandleIdentity(
        \FFI $ffi,
        \FFI\CData $handle,
        bool $directory,
        string $path,
    ): array {
        $information = $ffi->new('BY_HANDLE_FILE_INFORMATION');
        if ((int)$ffi->GetFileInformationByHandle(
            $handle,
            \FFI::addr($information),
        ) === 0) {
            throw new \RuntimeException(
                'Windows rebootstrap ACL object cannot be opened no-follow: '
                    . $path
            );
        }
        $attributes = (int)$information->dwFileAttributes;
        $actualDirectory = ($attributes & 0x00000010) !== 0;
        $indexHigh = (int)$information->nFileIndexHigh;
        $indexLow = (int)$information->nFileIndexLow;
        if (($attributes & 0x00000400) !== 0
            || $directory !== $actualDirectory
            || (!$directory && (int)$information->nNumberOfLinks !== 1)
            || ($indexHigh === 0 && $indexLow === 0)
        ) {
            throw new \RuntimeException(
                'Windows rebootstrap ACL object is reparse, linked, or has no file ID: '
                    . $path
            );
        }
        return [
            'device' => \sprintf(
                '%08x',
                (int)$information->dwVolumeSerialNumber,
            ),
            'inode' => \sprintf('%08x%08x', $indexHigh, $indexLow),
        ];
    }

    /** @param array{ffi:\FFI,handle:\FFI\CData,path:string} $object */
    private function closeWindowsRebootstrapBackupObject(array $object): void
    {
        try {
            $closed = (int)$object['ffi']->CloseHandle($object['handle']);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Windows rebootstrap ACL identity handle close failed: '
                    . (string)$object['path'],
                0,
                $throwable,
            );
        }
        if ($closed === 0) {
            throw new \RuntimeException(
                'Windows rebootstrap ACL identity handle did not close cleanly: '
                    . (string)$object['path'],
            );
        }
    }

    private function windowsRebootstrapBackupKernel32(): \FFI
    {
        static $ffi = null;
        if ($ffi instanceof \FFI) {
            return $ffi;
        }
        if (!\class_exists(\FFI::class) || !\function_exists('iconv')) {
            throw new \RuntimeException(
                'Windows rebootstrap backup ACL sealing requires the locked FFI runtime.'
            );
        }
        try {
            $ffi = \FFI::cdef(
                'typedef int BOOL; typedef unsigned long DWORD;'
                    . ' typedef unsigned short WCHAR; typedef void* HANDLE;'
                    . ' typedef struct {'
                    . ' DWORD dwLowDateTime; DWORD dwHighDateTime;'
                    . ' } FILETIME;'
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
                    . ' BOOL CloseHandle(HANDLE);',
                'kernel32.dll',
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Windows rebootstrap backup file-ID verifier is unavailable.',
                0,
                $throwable,
            );
        }
        return $ffi;
    }

    /** @param array<string,mixed> $expected */
    private function assertRebootstrapBackupObjectUnchanged(
        array $expected,
    ): void {
        $this->assertTraversalDeadline();
        $current = $this->rebootstrapBackupObjectRecord(
            (string)$expected['path'],
            (bool)$expected['directory'],
        );
        if (!$this->sameRebootstrapBackupObjectIdentity($expected, $current)) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL object identity changed: '
                    . (string)$expected['path']
            );
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameRebootstrapBackupObjectIdentity(
        array $left,
        array $right,
    ): bool {
        return (bool)$left['directory'] === (bool)$right['directory']
            && \hash_equals((string)$left['path'], (string)$right['path'])
            && \hash_equals((string)$left['device'], (string)$right['device'])
            && \hash_equals((string)$left['inode'], (string)$right['inode']);
    }

    /**
     * @param array{root:array<string,mixed>,entries:array<string,array<string,mixed>>} $expected
     * @param array{root:array<string,mixed>,entries:array<string,array<string,mixed>>} $current
     */
    private function assertRebootstrapBackupTopLevelUnchanged(
        array $expected,
        array $current,
    ): void {
        if (!$this->sameRebootstrapBackupObjectIdentity(
                $expected['root'],
                $current['root'],
            )
            || \array_keys($expected['entries'])
                !== \array_keys($current['entries'])
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL top-level closure changed.'
            );
        }
        foreach ($expected['entries'] as $leaf => $record) {
            if (!$this->sameRebootstrapBackupObjectIdentity(
                $record,
                $current['entries'][$leaf],
            )) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ACL root identity changed: ' . $leaf
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $records
     */
    private function assertRebootstrapBackupRecordsUnchanged(
        array $records,
        bool $requirePrivatePosixModes,
    ): void {
        foreach ($records as $record) {
            $this->assertTraversalDeadline();
            $status = GatewayBoundedTreeWalker::revalidate($record);
            if ($requirePrivatePosixModes
                && ((((int)$status['mode']) & 0022) !== 0)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap POSIX backup is group/other writable: '
                        . (string)$record['path']
                );
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $expected
     * @param list<array<string,mixed>> $current
     */
    private function assertSameRebootstrapBackupRecordClosure(
        array $expected,
        array $current,
    ): void {
        $map = static function (array $records): array {
            $result = [];
            foreach ($records as $record) {
                $path = (string)$record['path'];
                if (isset($result[$path])) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap ACL closure contains duplicate paths.'
                    );
                }
                $result[$path] = $record;
            }
            \ksort($result, SORT_STRING);
            return $result;
        };
        $before = $map($expected);
        $after = $map($current);
        if (\array_keys($before) !== \array_keys($after)) {
            throw new \RuntimeException(
                'Gateway rebootstrap ACL descendant closure changed.'
            );
        }
        foreach ($before as $path => $record) {
            $other = $after[$path];
            if ((bool)$record['directory'] !== (bool)$other['directory']
                || !\hash_equals(
                    (string)$record['device'],
                    (string)$other['device'],
                )
                || !\hash_equals(
                    (string)$record['inode'],
                    (string)$other['inode'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ACL descendant identity changed: '
                        . $path
                );
            }
        }
    }

    /**
     * @param array{root:array<string,mixed>,entries:array<string,array<string,mixed>>} $topLevel
     * @param array<string,array{
     *   root:array<string,mixed>,
     *   segments:array<string,list<array<string,mixed>>>
     * }> $treeClosures
     */
    private function assertPrivatePosixRebootstrapBackup(
        string $backup,
        array $topLevel,
        array $treeClosures,
    ): void {
        $parentStatus = @\lstat(\dirname($backup));
        $rootStatus = @\lstat($backup);
        if (!\is_array($parentStatus)
            || !\is_array($rootStatus)
            || (((int)$rootStatus['mode']) & 0777) !== 0700
            || (int)$rootStatus['uid'] !== (int)$parentStatus['uid']
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap POSIX backup root authority is unsafe.'
            );
        }
        foreach ($treeClosures as $closure) {
            foreach ($closure['segments'] as $records) {
                $this->assertRebootstrapBackupRecordsUnchanged(
                    $records,
                    true,
                );
            }
        }
        foreach ($topLevel['entries'] as $record) {
            $current = $this->rebootstrapBackupObjectRecord(
                (string)$record['path'],
                (bool)$record['directory'],
            );
            if (!$this->sameRebootstrapBackupObjectIdentity($record, $current)
                || (($current['mode'] & 0022) !== 0)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap POSIX top-level backup permission is unsafe: '
                        . (string)$record['path']
                );
            }
        }
        $this->assertRebootstrapBackupTopLevelUnchanged(
            $topLevel,
            $this->rebootstrapBackupTopLevelInventory($backup),
        );
        $verificationRemaining =
            self::REBOOTSTRAP_BACKUP_ACL_TOTAL_ENTRY_QUOTA;
        foreach ($topLevel['entries'] as $entry) {
            if (!(bool)$entry['directory']) {
                --$verificationRemaining;
            }
        }
        foreach ($treeClosures as $leaf => $closure) {
            $this->assertSameRebootstrapBackupAclTreeClosure(
                $closure,
                $this->rebootstrapBackupAclTreeClosure(
                    $topLevel['entries'][$leaf],
                    $verificationRemaining,
                ),
            );
        }
    }

    private function assertTraversalDeadline(): void
    {
        $this->remainingOperationDeadline(1.0);
    }

    /**
     * Seal the fixed rebootstrap workspace without ever aggregating retained
     * backups into one recursive walk. Namespace roots are root-only; each
     * candidate, backup and receipt then receives its own bounded policy.
     */
    private function secureWindowsRebootstrapWorkspaceAcl(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'Windows rebootstrap workspace ACL sealing is unavailable on this platform.'
            );
        }
        $inventory = $this->windowsRebootstrapWorkspaceInventory();
        $serviceIdentity = 'NT SERVICE\\' . self::SERVICE_NAME;
        $this->withWindowsRebootstrapBackupIdentityHandles(
            [$inventory['root']],
            function () use ($inventory, $serviceIdentity): void {
                $this->applyWindowsAcl(
                    $inventory['root']['path'],
                    $serviceIdentity,
                    'NONE',
                    recursive: false,
                    inheritChildren: false,
                );
                $namespaceRecords = \array_values($inventory['namespaces']);
                $this->withWindowsRebootstrapBackupIdentityHandles(
                    $namespaceRecords,
                    function () use (
                        $inventory,
                        $serviceIdentity,
                    ): void {
                        foreach ($inventory['namespaces'] as $leaf => $record) {
                            $this->assertTraversalDeadline();
                            $this->applyWindowsAcl(
                                $record['path'],
                                $serviceIdentity,
                                'NONE',
                                recursive: false,
                                // Capacity descendants are intentionally not
                                // traversed by PHP: the native launcher owns
                                // and verifies as many as 65,536 physical
                                // inode tokens. They must still inherit the
                                // protected SYSTEM/Administrators-only DACL.
                                inheritChildren: \hash_equals(
                                    'capacity',
                                    (string)$leaf,
                                ),
                            );
                        }

                        foreach ($inventory['candidates'] as $record) {
                            $this->assertTraversalDeadline();
                            $records = GatewayBoundedTreeWalker::collect(
                                $record['path'],
                                true,
                                false,
                                self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                            );
                            $this->withWindowsRebootstrapBackupIdentityHandles(
                                $records,
                                function () use (
                                    $record,
                                    $records,
                                    $serviceIdentity,
                                ): void {
                                    $this->applyWindowsAcl(
                                        $record['path'],
                                        $serviceIdentity,
                                        'NONE',
                                        maximumEntries: self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                                        inheritChildren: false,
                                    );
                                    $this->assertSameRebootstrapBackupRecordClosure(
                                        $records,
                                        GatewayBoundedTreeWalker::collect(
                                            $record['path'],
                                            true,
                                            false,
                                            self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA,
                                        ),
                                    );
                                },
                            );
                        }

                        foreach ($inventory['candidate_locks'] as $record) {
                            $this->assertTraversalDeadline();
                            $this->withWindowsRebootstrapBackupIdentityHandles(
                                [$record],
                                function () use (
                                    $record,
                                    $serviceIdentity,
                                ): void {
                                    $this->assertWindowsRebootstrapCandidateLockRecord(
                                        $record,
                                    );
                                    $this->applyWindowsAcl(
                                        $record['path'],
                                        $serviceIdentity,
                                        'NONE',
                                        inheritChildren: false,
                                    );
                                    $this->assertWindowsRebootstrapCandidateLockRecord(
                                        $record,
                                    );
                                },
                            );
                        }

                        foreach ($inventory['backups'] as $nonce => $record) {
                            $this->assertTraversalDeadline();
                            $this->withWindowsRebootstrapBackupIdentityHandles(
                                [$record],
                                function () use ($nonce): void {
                                    $this->secureRebootstrapBackupWithinDeadline(
                                        $nonce,
                                        false,
                                    );
                                },
                            );
                        }

                        foreach ($inventory['collecting_backups'] as $binding) {
                            $this->assertTraversalDeadline();
                            $record = $binding['record'];
                            $receiptRecord = $inventory['receipts'][(string)$binding['nonce']
                                . '.json'];
                            $this->withWindowsRebootstrapBackupIdentityHandles(
                                [$record, $receiptRecord],
                                function () use (
                                    $binding,
                                    $record,
                                    $receiptRecord,
                                ): void {
                                    $this->assertWindowsCollectingRebootstrapBackupReceipt(
                                        $record,
                                        $receiptRecord,
                                        (string)$binding['nonce'],
                                        (string)$binding['collection_nonce'],
                                    );
                                    $this->secureRebootstrapBackupWithinDeadline(
                                        (string)$binding['nonce'],
                                        false,
                                        (string)$record['path'],
                                    );
                                },
                            );
                        }

                        foreach ($inventory['receipts'] as $record) {
                            $this->assertTraversalDeadline();
                            $this->withWindowsRebootstrapBackupIdentityHandles(
                                [$record],
                                function () use (
                                    $record,
                                    $serviceIdentity,
                                ): void {
                                    $this->applyWindowsAcl(
                                        $record['path'],
                                        $serviceIdentity,
                                        'NONE',
                                        inheritChildren: false,
                                    );
                                },
                            );
                        }
                    },
                );

                $this->assertSameWindowsRebootstrapWorkspaceInventory(
                    $inventory,
                    $this->windowsRebootstrapWorkspaceInventory(),
                );
            },
        );
    }

    /**
     * @return array{
     *   root:array<string,mixed>,
     *   namespaces:array<string,array<string,mixed>>,
     *   candidates:array<string,array<string,mixed>>,
     *   candidate_locks:array<string,array<string,mixed>>,
     *   backups:array<string,array<string,mixed>>,
     *   collecting_backups:array<string,array{
     *     record:array<string,mixed>,nonce:string,collection_nonce:string
     *   }>,
     *   receipts:array<string,array<string,mixed>>
     * }
     */
    private function windowsRebootstrapWorkspaceInventory(): array
    {
        $rootPath = $this->paths->rebootstrapDir();
        $root = $this->rebootstrapBackupObjectRecord($rootPath, true);
        $handle = @\opendir($rootPath);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Windows rebootstrap workspace cannot be enumerated.'
            );
        }
        $expected = ['backups', 'candidates', 'capacity', 'receipts'];
        $namespaces = [];
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $this->assertTraversalDeadline();
                if (!\in_array($leaf, $expected, true)
                    || isset($namespaces[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap workspace contains an unexpected root: '
                            . $leaf
                    );
                }
                $namespaces[$leaf] = $this->rebootstrapBackupObjectRecord(
                    $rootPath . DIRECTORY_SEPARATOR . $leaf,
                    true,
                );
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($namespaces, SORT_STRING);
        if (\array_keys($namespaces) !== $expected) {
            throw new \RuntimeException(
                'Windows rebootstrap workspace fixed roots are incomplete.'
            );
        }
        $this->assertRebootstrapBackupObjectUnchanged($root);

        $remaining = self::REBOOTSTRAP_WORKSPACE_TOTAL_ENTRY_QUOTA;
        [$candidates, $candidateLocks] = $this->windowsRebootstrapCandidateWorkspaceInventory(
            $namespaces['candidates'],
            $remaining,
        );
        $backupEntries = $this->windowsRebootstrapNamespaceInventory(
            $namespaces['backups'],
            '/\A[a-f0-9]{32}(?:\.collecting-[a-f0-9]{32})?\z/D',
            true,
            $remaining,
        );
        $receipts = $this->windowsRebootstrapNamespaceInventory(
            $namespaces['receipts'],
            '/\A[a-f0-9]{32}\.json(?:\.(?:wls-backup-[a-f0-9]{16}|tmp-[a-f0-9]{24}|gc-[a-f0-9]{32}))?\z/D',
            false,
            $remaining,
        );
        $receiptNamespaces = [];
        foreach (\array_keys($receipts) as $leaf) {
            if (\preg_match(
                '/\A([a-f0-9]{32})\.json(?<suffix>.*)\z/D',
                $leaf,
                $matches,
            ) !== 1) {
                throw new \RuntimeException(
                    'Windows rebootstrap receipt namespace entry is invalid.',
                );
            }
            $nonce = (string)$matches[1];
            $suffix = (string)$matches['suffix'];
            $receiptNamespaces[$nonce] ??= [
                'canonical' => false,
                'companions' => 0,
                'aliases' => 0,
            ];
            if ($suffix === '') {
                $receiptNamespaces[$nonce]['canonical'] = true;
            } elseif (\str_starts_with($suffix, '.gc-')) {
                ++$receiptNamespaces[$nonce]['aliases'];
            } else {
                ++$receiptNamespaces[$nonce]['companions'];
            }
        }
        foreach ($receiptNamespaces as $binding) {
            if ((int)$binding['aliases'] > 1
                || ((int)$binding['aliases'] === 1
                    && ((bool)$binding['canonical']
                        || (int)$binding['companions'] > 0))
            ) {
                throw new \RuntimeException(
                    'Windows rebootstrap receipt GC alias namespace is ambiguous.',
                );
            }
        }
        $backups = [];
        $collectingBackups = [];
        foreach ($backupEntries as $leaf => $record) {
            if (\preg_match('/\A([a-f0-9]{32})\z/D', $leaf, $matches) === 1) {
                $backups[(string)$matches[1]] = $record;
                continue;
            }
            if (\preg_match(
                '/\A([a-f0-9]{32})\.collecting-([a-f0-9]{32})\z/D',
                $leaf,
                $matches,
            ) !== 1) {
                throw new \RuntimeException(
                    'Windows rebootstrap collecting backup name is invalid.',
                );
            }
            $nonce = (string)$matches[1];
            $collectionNonce = (string)$matches[2];
            if (isset($backups[$nonce])
                || isset($collectingBackups[$nonce])
                || !isset($receipts[$nonce . '.json'])
            ) {
                throw new \RuntimeException(
                    'Windows rebootstrap collecting backup has an ambiguous receipt binding.',
                );
            }
            $this->assertWindowsCollectingRebootstrapBackupReceipt(
                $record,
                $receipts[$nonce . '.json'],
                $nonce,
                $collectionNonce,
            );
            $collectingBackups[$nonce] = [
                'record' => $record,
                'nonce' => $nonce,
                'collection_nonce' => $collectionNonce,
            ];
        }
        \ksort($backups, SORT_STRING);
        \ksort($collectingBackups, SORT_STRING);
        return [
            'root' => $root,
            'namespaces' => $namespaces,
            'candidates' => $candidates,
            'candidate_locks' => $candidateLocks,
            'backups' => $backups,
            'collecting_backups' => $collectingBackups,
            'receipts' => $receipts,
        ];
    }

    /**
     * Accept a collection alias only while its canonical, root-only terminal
     * receipt proves that it is this exact retained generation.  The service
     * identity never receives the administrator token or the receipt ACL;
     * this verification runs solely inside the privileged platform sealer.
     *
     * @param array<string,mixed> $collecting
     * @param array<string,mixed> $receiptRecord
     */
    private function assertWindowsCollectingRebootstrapBackupReceipt(
        array $collecting,
        array $receiptRecord,
        string $nonce,
        string $collectionNonce,
    ): void {
        $this->assertRebootstrapBackupObjectUnchanged($collecting);
        $this->assertRebootstrapBackupObjectUnchanged($receiptRecord);
        if (!\hash_equals(
                $this->paths->rebootstrapReceiptFile($nonce),
                (string)$receiptRecord['path'],
            )
            || !\hash_equals(
                $this->paths->rebootstrapCollectedBackupDir(
                    $nonce,
                    $collectionNonce,
                ),
                (string)$collecting['path'],
            )
        ) {
            throw new \RuntimeException(
                'Windows rebootstrap collecting backup escaped its fixed namespace.',
            );
        }
        $receipt = (new HostGatewayPackageManager($this->paths))
            ->authenticatedTerminalRebootstrapReceiptForPlatformSeal($nonce);
        if (!\in_array(
                (string)$receipt['retained_backup_state'],
                ['RETAINED', 'COLLECTED'],
                true,
            )
            || !\hash_equals(
                $collectionNonce,
                (string)$receipt['backup_collection_nonce'],
            )
            || !\hash_equals(
                (string)$collecting['device'],
                (string)$receipt['backup_collection_device'],
            )
            || !\hash_equals(
                (string)$collecting['inode'],
                (string)$receipt['backup_collection_inode'],
            )
        ) {
            throw new \RuntimeException(
                'Windows rebootstrap collecting backup does not match its authenticated terminal receipt.',
            );
        }
        // A crash after the no-replace rename but before receipt publication
        // intentionally leaves RETAINED here. Both retained states are valid
        // only because the HMAC binds this exact collection root.
        $this->assertRebootstrapBackupObjectUnchanged($receiptRecord);
        $this->assertRebootstrapBackupObjectUnchanged($collecting);
    }

    /**
     * Keep candidate runtime trees and the sibling runtime-artifact lock in
     * distinct inventories. The lock can legitimately outlive the candidate
     * across a crash, but it never names data and is never inherited by the
     * gateway service.
     *
     * @param array<string,mixed> $namespace
     * @return array{
     *   0:array<string,array<string,mixed>>,
     *   1:array<string,array<string,mixed>>
     * }
     */
    private function windowsRebootstrapCandidateWorkspaceInventory(
        array $namespace,
        int &$remaining,
    ): array {
        $this->assertRebootstrapBackupObjectUnchanged($namespace);
        $path = (string)$namespace['path'];
        $handle = @\opendir($path);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Windows rebootstrap candidate namespace cannot be enumerated: '
                    . $path,
            );
        }
        $candidates = [];
        $locks = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $this->assertTraversalDeadline();
                if (++$visited > self::REBOOTSTRAP_WORKSPACE_NAMESPACE_ENTRY_QUOTA
                    || $remaining < 1
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap candidate namespace exceeds its entry quota.',
                    );
                }
                if (\preg_match('/\A([a-f0-9]{32})\z/D', $leaf, $matches) === 1) {
                    $nonce = (string)$matches[1];
                    if (isset($candidates[$nonce])) {
                        throw new \RuntimeException(
                            'Windows rebootstrap candidate namespace has a duplicate runtime.',
                        );
                    }
                    --$remaining;
                    $candidates[$nonce] = $this->rebootstrapBackupObjectRecord(
                        $path . DIRECTORY_SEPARATOR . $leaf,
                        true,
                    );
                    continue;
                }
                if (\preg_match(
                    '/\A([a-f0-9]{32})\.install\.lock\z/D',
                    $leaf,
                    $matches,
                ) !== 1) {
                    throw new \RuntimeException(
                        'Windows rebootstrap candidate namespace entry is invalid: '
                            . $leaf,
                    );
                }
                $nonce = (string)$matches[1];
                if (isset($locks[$nonce])) {
                    throw new \RuntimeException(
                        'Windows rebootstrap candidate namespace has a duplicate install lock.',
                    );
                }
                --$remaining;
                $record = $this->rebootstrapBackupObjectRecord(
                    $path . DIRECTORY_SEPARATOR . $leaf,
                    false,
                );
                $this->assertWindowsRebootstrapCandidateLockRecord($record);
                $locks[$nonce] = $record;
            }
        } finally {
            @\closedir($handle);
        }
        $this->assertRebootstrapBackupObjectUnchanged($namespace);
        \ksort($candidates, SORT_STRING);
        \ksort($locks, SORT_STRING);
        return [$candidates, $locks];
    }

    /** @param array<string,mixed> $record */
    private function assertWindowsRebootstrapCandidateLockRecord(array $record): void
    {
        $this->assertRebootstrapBackupObjectUnchanged($record);
        $status = @\lstat((string)$record['path']);
        if (($record['directory'] ?? true) !== false
            || (int)($record['nlink'] ?? 0) !== 1
            || !\is_array($status)
            || \is_link((string)$record['path'])
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['size'] ?? -1) !== 0
        ) {
            throw new \RuntimeException(
                'Windows rebootstrap candidate install lock is not an empty regular singleton.',
            );
        }
        $this->assertRebootstrapBackupObjectUnchanged($record);
    }

    /**
     * @param array<string,mixed> $namespace
     * @return array<string,array<string,mixed>>
     */
    private function windowsRebootstrapNamespaceInventory(
        array $namespace,
        string $leafPattern,
        bool $directory,
        int &$remaining,
    ): array {
        $this->assertRebootstrapBackupObjectUnchanged($namespace);
        $path = (string)$namespace['path'];
        $handle = @\opendir($path);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Windows rebootstrap namespace cannot be enumerated: ' . $path
            );
        }
        $entries = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $this->assertTraversalDeadline();
                if (++$visited
                        > self::REBOOTSTRAP_WORKSPACE_NAMESPACE_ENTRY_QUOTA
                    || $remaining < 1
                    || \preg_match($leafPattern, $leaf) !== 1
                    || isset($entries[$leaf])
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap namespace entry is invalid or exceeds quota: '
                            . $leaf
                    );
                }
                --$remaining;
                $entries[$leaf] = $this->rebootstrapBackupObjectRecord(
                    $path . DIRECTORY_SEPARATOR . $leaf,
                    $directory,
                );
            }
        } finally {
            @\closedir($handle);
        }
        $this->assertRebootstrapBackupObjectUnchanged($namespace);
        \ksort($entries, SORT_STRING);
        return $entries;
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $current
     */
    private function assertSameWindowsRebootstrapWorkspaceInventory(
        array $expected,
        array $current,
    ): void {
        if (!$this->sameRebootstrapBackupObjectIdentity(
            $expected['root'],
            $current['root'],
        )) {
            throw new \RuntimeException(
                'Windows rebootstrap workspace root identity changed.'
            );
        }
        foreach ([
            'namespaces',
            'candidates',
            'candidate_locks',
            'backups',
            'receipts',
        ] as $kind) {
            if (\array_keys($expected[$kind])
                !== \array_keys($current[$kind])
            ) {
                throw new \RuntimeException(
                    'Windows rebootstrap workspace namespace closure changed: '
                        . $kind
                );
            }
            foreach ($expected[$kind] as $leaf => $record) {
                if (!$this->sameRebootstrapBackupObjectIdentity(
                    $record,
                    $current[$kind][$leaf],
                )) {
                    throw new \RuntimeException(
                        'Windows rebootstrap workspace entry identity changed: '
                            . $kind . '/' . $leaf
                    );
                }
            }
        }
        foreach (['collecting_backups'] as $kind) {
            if (\array_keys($expected[$kind])
                !== \array_keys($current[$kind])
            ) {
                throw new \RuntimeException(
                    'Windows rebootstrap workspace namespace closure changed: '
                        . $kind
                );
            }
            foreach ($expected[$kind] as $nonce => $binding) {
                $currentBinding = $current[$kind][$nonce];
                if (!\hash_equals(
                        (string)$binding['nonce'],
                        (string)$currentBinding['nonce'],
                    )
                    || !\hash_equals(
                        (string)$binding['collection_nonce'],
                        (string)$currentBinding['collection_nonce'],
                    )
                    || !$this->sameRebootstrapBackupObjectIdentity(
                        $binding['record'],
                        $currentBinding['record'],
                    )
                ) {
                    throw new \RuntimeException(
                        'Windows rebootstrap collection binding changed during ACL sealing.',
                    );
                }
            }
        }
    }

    private function ensurePrivateRebootstrapDirectory(string $directory): void
    {
        $root = $this->paths->rebootstrapDir();
        $parent = \dirname($directory);
        $resolvedRoot = \realpath($root);
        $resolvedParent = \realpath($parent);
        $normalize = static fn (string $path): string => \strtolower(
            \rtrim(\str_replace('\\', '/', $path), '/'),
        );
        if (!\is_string($resolvedRoot)
            || !\is_string($resolvedParent)
            || \is_link($parent)
            || !\str_starts_with(
                $normalize($resolvedParent) . '/',
                $normalize($resolvedRoot) . '/',
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap platform backup parent is outside the fixed host root.'
            );
        }
        if (!\file_exists($directory) && !\is_link($directory)) {
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException(
                    'Unable to create gateway rebootstrap platform backup directory.'
                );
            }
        }
        $status = @\lstat($directory);
        $parentStatus = @\lstat($parent);
        if (!\is_array($status)
            || !\is_array($parentStatus)
            || \is_link($directory)
            || !\is_dir($directory)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== 0700
                    || (int)$status['uid'] !== (int)$parentStatus['uid']))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap platform backup directory authority is unsafe.'
            );
        }
    }

    private function writeExactRebootstrapBackup(
        string $path,
        string $contents,
        string $label,
    ): void {
        $existing = \file_exists($path) || \is_link($path)
            ? $this->readStableRegularFile($path, 1_048_576, $label)
            : null;
        if ($existing === null) {
            if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $path,
                1_048_576,
                $label,
            )) {
                // The snapshot is not bound into the signed rebootstrap
                // journal until both exact leaves have published. A missing
                // target therefore proves that a staging-only artifact is an
                // uncommitted first publication. Retained replacement backups
                // remain fatal in the helper below.
                GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                    $path,
                    1_048_576,
                    $label,
                );
            }
            $this->atomicWrite($path, $contents, 0600);
            return;
        }
        if (!\hash_equals($contents, $existing)) {
            throw new \RuntimeException(
                'Existing ' . $label . ' conflicts with the installed definition.'
            );
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertRebootstrapPlatformSnapshot(array $snapshot): void
    {
        $keys = \array_keys($snapshot);
        \sort($keys, SORT_STRING);
        if ($keys !== [
                'definition_sha256',
                'kind',
                'metadata_sha256',
                'profile',
            ]
            || !\in_array((string)($snapshot['kind'] ?? ''), [
                'test-session',
                'launchd-system',
                'systemd-system',
                'windows-service',
            ], true)
            || !\in_array((string)($snapshot['profile'] ?? ''), [
                'default',
                'ipv4-only',
            ], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($snapshot['definition_sha256'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($snapshot['metadata_sha256'] ?? '')) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap platform snapshot contract is invalid.'
            );
        }
    }

    private function applyWindowsDiagnosticAcl(string $path): void
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
        ) {
            throw new \RuntimeException(
                'Windows gateway recovery projection is linked or special.'
            );
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_PATH__'))
$item = Get-Item -LiteralPath $path -Force
$reparse = [IO.FileAttributes]::ReparsePoint
if ($item.PSIsContainer -or ($item.Attributes -band $reparse) -ne 0) {
    throw 'Gateway recovery projection is linked or special.'
}
$systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
$administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
$usersSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-545')
$allow = [Security.AccessControl.AccessControlType]::Allow
$none = [Security.AccessControl.InheritanceFlags]::None
$propagation = [Security.AccessControl.PropagationFlags]::None
$acl = [Security.AccessControl.FileSecurity]::new()
$acl.SetAccessRuleProtection($true, $false)
$acl.SetOwner($administratorsSid)
$expected = @(
    [Security.AccessControl.FileSystemAccessRule]::new(
        $systemSid,
        [Security.AccessControl.FileSystemRights]::FullControl,
        $none,
        $propagation,
        $allow
    ),
    [Security.AccessControl.FileSystemAccessRule]::new(
        $administratorsSid,
        [Security.AccessControl.FileSystemRights]::FullControl,
        $none,
        $propagation,
        $allow
    ),
    [Security.AccessControl.FileSystemAccessRule]::new(
        $usersSid,
        [Security.AccessControl.FileSystemRights]::Read,
        $none,
        $propagation,
        $allow
    )
)
foreach ($rule in $expected) { [void]$acl.AddAccessRule($rule) }
Set-Acl -LiteralPath $path -AclObject $acl
$verified = Get-Acl -LiteralPath $path
$rules = @($verified.GetAccessRules(
    $true,
    $true,
    [Security.Principal.SecurityIdentifier]
))
if (-not $verified.AreAccessRulesProtected -or
    $verified.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne
        $administratorsSid.Value -or
    $rules.Count -ne $expected.Count) {
    throw 'Gateway recovery projection ACL verification failed.'
}
$wanted = @{}
foreach ($rule in $expected) { $wanted[$rule.IdentityReference.Value] = $rule }
foreach ($rule in $rules) {
    $identity = $rule.IdentityReference.Value
    if (-not $wanted.ContainsKey($identity)) {
        throw 'Unexpected gateway recovery projection ACL identity.'
    }
    $match = $wanted[$identity]
    if ($rule.IsInherited -or
        $rule.AccessControlType -ne $match.AccessControlType -or
        [int]$rule.FileSystemRights -ne [int]$match.FileSystemRights) {
        throw 'Gateway recovery projection ACL rights verification failed.'
    }
}
POWERSHELL;
        $script = \str_replace(
            '__WLS_PATH__',
            \base64_encode($path),
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
        ], 'Windows gateway recovery projection ACL');
    }

    private function applyWindowsFixedNamespaceAcl(
        string $directory,
        string $serviceSid,
        string $serviceRights,
    ): void {
        if (!\in_array($serviceRights, ['RX', 'GX', 'GA'], true)
            || \preg_match('/\AS-1-5-80(?:-[0-9]+){5}\z/D', $serviceSid) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Windows fixed namespace ACL profile is invalid.',
            );
        }
        $serviceSddlRights = match ($serviceRights) {
            'RX' => '0x1200a9',
            'GX' => 'GX',
            'GA' => 'GA',
        };
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Windows fixed namespace ACL target is linked or special: '
                    . $directory,
            );
        }
        $before = GatewayBoundedTreeWalker::identity($directory);
        $expected = GatewayWindowsHostRootAuthority::canonicalizeSddl(
            'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;'
                . $serviceSddlRights . ';;;' . $serviceSid . ')',
        );
        $actual = GatewayWindowsHostRootAuthority::applyExactPathSddl(
            $directory,
            true,
            $expected,
            $before,
        );
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                'Windows fixed namespace ACL verification failed.',
            );
        }
        $after = GatewayBoundedTreeWalker::identity($directory);
        if (!\hash_equals((string)$before['device'], (string)$after['device'])
            || !\hash_equals((string)$before['inode'], (string)$after['inode'])
        ) {
            throw new \RuntimeException(
                'Windows fixed namespace identity changed during ACL sealing.',
            );
        }
    }

    private function applyWindowsNginxExecutableAcl(string $path): void
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
        ) {
            throw new \RuntimeException(
                'Windows Nginx executable ACL target is linked or special: '
                    . $path,
            );
        }
        $before = GatewayBoundedTreeWalker::identity($path);
        $expected = GatewayWindowsHostRootAuthority::canonicalizeSddl(
            'O:S-1-5-32-544D:P'
                . '(A;;FA;;;S-1-5-18)'
                . '(A;;FA;;;S-1-5-32-544)'
                . '(A;;0x1200a9;;;' . self::WINDOWS_CONTROLLER_SERVICE_SID . ')'
                . '(A;;0x1200a9;;;' . self::WINDOWS_DATA_PLANE_SERVICE_SID . ')',
        );
        $actual = GatewayWindowsHostRootAuthority::applyExactPathSddl(
            $path,
            false,
            $expected,
            $before,
        );
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                'Windows Nginx executable four-ACE ACL verification failed.',
            );
        }
        $after = GatewayBoundedTreeWalker::identity($path);
        if (!\hash_equals((string)$before['device'], (string)$after['device'])
            || !\hash_equals((string)$before['inode'], (string)$after['inode'])
        ) {
            throw new \RuntimeException(
                'Windows Nginx executable identity changed during ACL sealing.',
            );
        }
    }

    private function applyWindowsDataPlaneTraversalAcl(
        string $path,
        bool $systemOwner,
    ): void {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Windows data-plane traversal ACL target is linked or special: '
                    . $path,
            );
        }
        $before = GatewayBoundedTreeWalker::identity($path);
        $owner = $systemOwner ? 'S-1-5-18' : 'S-1-5-32-544';
        $expected = GatewayWindowsHostRootAuthority::canonicalizeSddl(
            'O:' . $owner . 'D:P'
                . '(A;;FA;;;S-1-5-18)'
                . '(A;;FA;;;S-1-5-32-544)'
                . '(A;;0x1200a9;;;' . self::WINDOWS_CONTROLLER_SERVICE_SID . ')'
                . '(A;;0x20;;;' . self::WINDOWS_DATA_PLANE_SERVICE_SID . ')',
        );
        $actual = GatewayWindowsHostRootAuthority::applyExactPathSddl(
            $path,
            true,
            $expected,
            $before,
        );
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException(
                'Windows data-plane traversal four-ACE ACL verification failed.',
            );
        }
        $after = GatewayBoundedTreeWalker::identity($path);
        if (!\hash_equals((string)$before['device'], (string)$after['device'])
            || !\hash_equals((string)$before['inode'], (string)$after['inode'])
        ) {
            throw new \RuntimeException(
                'Windows data-plane traversal identity changed during ACL sealing.',
            );
        }
    }

    private function applyWindowsAcl(
        string $directory,
        string $serviceIdentity,
        string $serviceRights,
        array $excludedPaths = [],
        int $maximumEntries = GatewayBoundedTreeWalker::MAX_ENTRIES,
        bool $recursive = true,
        bool $inheritChildren = true,
    ): void {
        if (!\in_array($serviceRights, ['RX', 'M', 'NONE'], true)) {
            throw new \InvalidArgumentException(
                'Windows gateway service rights must be RX, M or NONE.'
            );
        }
        if ($maximumEntries < 1
            || $maximumEntries
                > self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA
        ) {
            throw new \InvalidArgumentException(
                'Windows gateway ACL entry limit is outside the safety envelope.'
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
        if ($targetType === 0040000 && $recursive) {
            GatewayBoundedTreeWalker::collect(
                $directory,
                true,
                false,
                $maximumEntries,
            );
        }
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
__WLS_BOUNDED_WALKER__
$path = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_PATH__'))
$serviceName = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_SERVICE__'))
$excludedJson = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__WLS_EXCLUDED__'))
$rightsName = '__WLS_RIGHTS__'
$inheritChildren = __WLS_INHERIT_CHILDREN__
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
    $inheritance = if ($isDirectory -and $inheritChildren) {
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

__WLS_DESCENDANTS__
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
                '__WLS_DESCENDANTS__',
                '__WLS_INHERIT_CHILDREN__',
            ],
            [
                $this->windowsBoundedTreeWalkerScript($maximumEntries),
                \base64_encode($directory),
                \base64_encode($serviceIdentity),
                \base64_encode((string)\json_encode(
                    \array_values($excludedPaths),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )),
                \base64_encode($this->windowsSystemExecutable('icacls.exe')),
                $serviceRights,
                $recursive
                    ? '$descendants = @(Get-WlsBoundedTree -RootPath $path)'
                    : '$descendants = @()',
                $inheritChildren ? '$true' : '$false',
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

    private function windowsBoundedTreeWalkerScript(
        int $maximumEntries = GatewayBoundedTreeWalker::MAX_ENTRIES,
    ): string
    {
        if ($maximumEntries < 1
            || $maximumEntries
                > self::REBOOTSTRAP_BACKUP_ACL_TREE_ENTRY_QUOTA
        ) {
            throw new \InvalidArgumentException(
                'Windows bounded ACL walker entry limit is invalid.'
            );
        }
        return \str_replace(
            '__WLS_MAXIMUM_ENTRIES__',
            (string)$maximumEntries,
            <<<'POWERSHELL'
function Get-WlsBoundedTree([string]$RootPath) {
    $maximumEntries = __WLS_MAXIMUM_ENTRIES__
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
                throw "WLS bounded ACL tree exceeds the $maximumEntries-entry safety limit."
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
POWERSHELL
        );
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

    /**
     * @return array<string|int,mixed>
     */
    private function ensurePosixServiceIdentity(string $role): array
    {
        if (!\in_array($role, ['controller', 'data-plane'], true)) {
            throw new \InvalidArgumentException('Unknown WLS Gateway service identity role.');
        }
        [$controllerAccount, $dataPlaneAccount] = self::posixServiceAccountNames(
            \PHP_OS_FAMILY,
        );
        $account = $role === 'controller' ? $controllerAccount : $dataPlaneAccount;
        $group = $account;
        $identity = \function_exists('posix_getpwnam')
            ? @\posix_getpwnam($account)
            : false;
        $groupIdentity = \function_exists('posix_getgrnam')
            ? @\posix_getgrnam($group)
            : false;
        if (!\is_array($identity)) {
            if (\is_array($groupIdentity)) {
                throw new \RuntimeException(
                    'An orphan WLS Gateway ' . $role . ' group already exists.'
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
                ], 'gateway ' . $role . ' service account creation');
            } elseif (\PHP_OS_FAMILY === 'Darwin') {
                $this->createDarwinServiceIdentity(
                    $account,
                    $group,
                    $role === 'controller'
                        ? 'Weline Gateway Controller'
                        : 'Weline Gateway Data Plane',
                );
            }
            $identity = \function_exists('posix_getpwnam')
                ? @\posix_getpwnam($account)
                : false;
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
                $role,
            )
        ) {
            throw new \RuntimeException(
                'Dedicated WLS Gateway ' . $role . ' identity is unavailable.'
            );
        }
        return $identity;
    }

    /** @return array{string,string} */
    private static function posixServiceAccountNames(string $platform): array
    {
        return match ($platform) {
            'Darwin' => ['_welinegateway', '_welinegateway_nginx'],
            'Linux' => ['weline-gateway', 'weline-gateway-nginx'],
            default => ['', ''],
        };
    }

    /**
     * @param array<string|int,mixed> $controller
     * @param array<string|int,mixed> $dataPlane
     */
    private function assertPosixIdentitySeparation(
        array $controller,
        array $dataPlane,
    ): void {
        $controllerUid = (int)($controller['uid'] ?? 0);
        $controllerGid = (int)($controller['gid'] ?? 0);
        $dataPlaneUid = (int)($dataPlane['uid'] ?? 0);
        $dataPlaneGid = (int)($dataPlane['gid'] ?? 0);
        if ($controllerUid < 1
            || $controllerGid < 1
            || $dataPlaneUid < 1
            || $dataPlaneGid < 1
            || $controllerUid === $dataPlaneUid
            || $controllerGid === $dataPlaneGid
        ) {
            throw new \RuntimeException(
                'WLS Gateway Controller and data-plane identities are not isolated.'
            );
        }
        $dataPlaneGroups = $this->posixSupplementaryGroupIds(
            (string)($dataPlane['name'] ?? ''),
        );
        if (\in_array($controllerGid, $dataPlaneGroups, true)) {
            throw new \RuntimeException(
                'WLS Gateway data-plane identity belongs to the Controller group.'
            );
        }
    }

    /** @return list<int> */
    private function posixSupplementaryGroupIds(string $account): array
    {
        if ($account === ''
            || \preg_match('/\A[_a-z][_a-z0-9-]{0,63}\z/D', $account) !== 1
        ) {
            throw new \RuntimeException('WLS Gateway service account name is invalid.');
        }
        $result = $this->runCommand(['/usr/bin/id', '-G', $account], true);
        if ($result['code'] !== 0
            || \preg_match('/\A[0-9]+(?:[ \t]+[0-9]+)*\s*\z/D', $result['output']) !== 1
        ) {
            throw new \RuntimeException(
                'Unable to verify WLS Gateway supplementary group isolation.'
            );
        }
        $groups = \array_values(\array_unique(\array_map(
            static fn (string $gid): int => (int)$gid,
            \preg_split('/\s+/', \trim($result['output'])) ?: [],
        )));
        \sort($groups, SORT_NUMERIC);
        return $groups;
    }

    private function createDarwinServiceIdentity(
        string $account,
        string $group,
        string $realName,
    ): void
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
                ['RealName', $realName],
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
        string $role = 'controller',
    ): bool {
        [$controllerAccount, $dataPlaneAccount] = self::posixServiceAccountNames(
            $platform,
        );
        $expectedName = match ($role) {
            'controller' => $controllerAccount,
            'data-plane' => $dataPlaneAccount,
            default => '',
        };
        [$expectedHome, $expectedShell] = match ($platform) {
            'Darwin' => ['/var/empty', '/usr/bin/false'],
            'Linux' => ['/nonexistent', '/usr/sbin/nologin'],
            default => ['', ''],
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
        bool $dataPlaneReadable = false,
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
            || !@\chmod($normalizedRoot, $dataPlaneReadable ? 0755 : 0750)
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
                    $entry['directory']
                        ? ($dataPlaneReadable ? 0755 : 0750)
                        : ($entry['executable']
                            ? ($dataPlaneReadable ? 0555 : 0550)
                            : ($dataPlaneReadable ? 0444 : 0440)),
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
        $activeDeadline = $this->activeOperationDeadline();
        if ($activeDeadline !== null) {
            $deadline = \min($deadline, $activeDeadline);
        }
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
            $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(1, \min(
                self::WINDOWS_SERVICE_POLL_MICROSECONDS,
                \ceil($remaining * 1_000_000),
            )));
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
        $activeDeadline = $this->activeOperationDeadline();
        if ($activeDeadline !== null) {
            $deadline = \min($deadline, $activeDeadline);
        }
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
            $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(1, \min(
                self::WINDOWS_SERVICE_POLL_MICROSECONDS,
                \ceil($remaining * 1_000_000),
            )));
        } while (true);

        throw new \RuntimeException(
            'Windows gateway service definition remained registered after deletion.'
        );
    }

    private function assertPlatformServiceStopped(string $kind): void
    {
        if ($this->paths->isTestMode()) {
            if (!\hash_equals('test-session', $kind)) {
                throw new \RuntimeException(
                    'Test gateway cannot prove a production platform service stop.'
                );
            }
            return;
        }
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

    /**
     * @return array{schema:int,kind:string,layout:string,definition_sha256:string,phase:string,at:int,nonce:string}
     */
    private function decodePlatformRemovalFence(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-PLATFORM-REMOVAL\/2\n'
                . 'kind=(test-session|launchd-system|systemd-system|windows-service)\n'
                . 'layout=(current|legacy|other)\n'
                . 'definition_sha256=([a-f0-9]{64})\n'
                . 'phase=(prepared|canonical-removed|definition-removed)\n'
                . 'at=([1-9][0-9]{0,18})\n'
                . 'nonce=([a-f0-9]{32})\n\z/D',
            $raw,
            $matches,
        ) === 1) {
            return [
                'schema' => 2,
                'kind' => (string)$matches[1],
                'layout' => (string)$matches[2],
                'definition_sha256' => (string)$matches[3],
                'phase' => (string)$matches[4],
                'at' => (int)$matches[5],
                'nonce' => (string)$matches[6],
            ];
        }
        if (\preg_match(
            '/\AWLS-PLATFORM-REMOVAL\/1\n'
                . 'kind=(test-session|launchd-system|systemd-system|windows-service)\n'
                . 'at=([1-9][0-9]{0,18})\n'
                . 'nonce=([a-f0-9]{32})\n\z/D',
            $raw,
            $matches,
        ) === 1) {
            return [
                'schema' => 1,
                'kind' => (string)$matches[1],
                'layout' => 'other',
                'definition_sha256' => \str_repeat('0', 64),
                'phase' => 'prepared',
                'at' => (int)$matches[2],
                'nonce' => (string)$matches[3],
            ];
        }
        throw new \RuntimeException(
            'WLS Gateway platform removal fence is malformed.',
        );
    }

    /** @param array{kind:string,layout:string,definition_sha256:string,phase:string,at:int,nonce:string} $fence */
    private function encodePlatformRemovalFence(array $fence): string
    {
        $raw = "WLS-PLATFORM-REMOVAL/2\n"
            . 'kind=' . (string)$fence['kind'] . "\n"
            . 'layout=' . (string)$fence['layout'] . "\n"
            . 'definition_sha256=' . (string)$fence['definition_sha256'] . "\n"
            . 'phase=' . (string)$fence['phase'] . "\n"
            . 'at=' . (string)$fence['at'] . "\n"
            . 'nonce=' . (string)$fence['nonce'] . "\n";
        $decoded = $this->decodePlatformRemovalFence($raw);
        if ((int)$decoded['schema'] !== 2) {
            throw new \RuntimeException(
                'WLS Gateway platform removal fence encoding failed.',
            );
        }
        return $raw;
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

    /**
     * Nesting never extends an already-established lifecycle deadline.
     * Durable publication callbacks are not post-checked here: their journal
     * or exact after-image remains the authoritative commit result.
     */
    private function withOperationDeadline(
        ?float $deadlineMonotonic,
        float $defaultSeconds,
        \Closure $callback,
    ): mixed {
        if (!\is_finite($defaultSeconds) || $defaultSeconds <= 0.0) {
            throw new \RuntimeException(
                'Gateway platform operation timeout is invalid.',
            );
        }
        $now = \hrtime(true) / 1_000_000_000;
        if ($deadlineMonotonic !== null && !\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'Gateway platform operation deadline is invalid.',
            );
        }
        $deadline = $deadlineMonotonic ?? ($now + $defaultSeconds);
        $parent = $this->activeOperationDeadline();
        if ($parent !== null) {
            $deadline = \min($deadline, $parent);
        }
        if ($deadline <= $now) {
            throw new \RuntimeException(
                'Gateway platform operation deadline was exhausted.',
            );
        }
        $this->operationDeadlineStack[] = $deadline;
        try {
            return $callback();
        } finally {
            \array_pop($this->operationDeadlineStack);
        }
    }

    private function activeOperationDeadline(): ?float
    {
        if ($this->operationDeadlineStack === []) {
            return null;
        }
        return $this->operationDeadlineStack[
            \array_key_last($this->operationDeadlineStack)
        ];
    }

    private function remainingOperationDeadline(float $maximumSeconds): float
    {
        if (!\is_finite($maximumSeconds) || $maximumSeconds <= 0.0) {
            throw new \RuntimeException(
                'Gateway platform operation timeout is invalid.',
            );
        }
        $deadline = $this->activeOperationDeadline();
        if ($deadline === null) {
            return $maximumSeconds;
        }
        $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'Gateway platform operation deadline was exhausted.',
            );
        }
        return \min($maximumSeconds, $remaining);
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
        $timeoutSeconds = $this->remainingOperationDeadline(
            $timeoutSeconds ?? 120.0,
        );
        if ($timeoutSeconds < self::MIN_COMMAND_TIMEOUT_SECONDS) {
            throw new \RuntimeException(
                'Gateway platform operation deadline was exhausted before command execution.',
            );
        }
        $result = GatewayBoundedCommandRunner::run(
            $command,
            $timeoutSeconds,
            deadlineMonotonic: $this->activeOperationDeadline(),
        );
        if (!$allowFailure && $result['code'] !== 0) {
            throw new \RuntimeException(
                'Gateway platform command failed: ' . $result['output']
            );
        }
        return $result;
    }
}
