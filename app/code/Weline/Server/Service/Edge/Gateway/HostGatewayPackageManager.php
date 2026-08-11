<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\Edge\Nginx\Runtime\NginxRuntimeArtifact;

/**
 * Verifies and installs one self-contained WLS 2.0 host-gateway release.
 *
 * Production releases are externally signed. Test packages are accepted only
 * when GatewayPaths has already proved the isolated test-root/high-port
 * contract; a test package can never report release_ready.
 */
final class HostGatewayPackageManager
{
    private const MAX_PROC_DIRECTORY_ENTRIES = 262_144;
    public const MANIFEST_SCHEMA = 2;
    public const MAX_PACKAGE_BYTES = 536_870_912;
    // Reserve two runtime-artifact entries (the verified manifest and its
    // detached signature) plus the synthetic release/ directory.
    public const MAX_PACKAGE_COMPONENTS = 4094;
    public const MAX_PACKAGE_DIRECTORIES = 8191;
    public const MAX_PACKAGE_PATH_DEPTH = 64;
    // WLS-UPGRADE/2 retains wall timestamps for display only. Eligibility is
    // always derived from the signed boot identity and monotonic deadlines.
    private const UPGRADE_ACTIVATION_TIMEOUT_SECONDS = 300;
    private const UPGRADE_TOTAL_TIMEOUT_SECONDS = 900;
    private const UPGRADE_ACTIVATION_TIMEOUT_MILLISECONDS = 300_000;
    private const INSTALL_LOCK_TIMEOUT_SECONDS = 30;
    private const PACKAGE_OPERATION_TIMEOUT_SECONDS = 900.0;
    private const MIN_COMMAND_TIMEOUT_SECONDS = 0.1;
    private const SLOT_RETENTION_SECONDS = 86_400;
    private const SLOT_RETENTION_MILLISECONDS = 86_400_000;
    private const MAX_ATOMIC_RECOVERY_BACKUPS_PER_TARGET = 8;
    private const MAX_ATOMIC_RECOVERY_TEMPORARIES_PER_TARGET = 8;
    private const MAX_STABLE_LAUNCHER_CANDIDATES = 8;
    private const MAX_ATOMIC_RECOVERY_DIRECTORY_ENTRIES = 16_384;
    private const UPGRADE_TOTAL_TIMEOUT_MILLISECONDS = 900_000;
    private const REBOOTSTRAP_JOURNAL_SCHEMA = 4;
    private const REBOOTSTRAP_JOURNAL_MAX_BYTES = 131_072;
    private const REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES = 2_048;
    private const REBOOTSTRAP_RETENTION_SECONDS = 86_400;
    private const MAX_REBOOTSTRAP_RECEIPTS = 4096;
    private const REBOOTSTRAP_RECEIPT_RECENT_RETENTION = 1024;
    private const REBOOTSTRAP_RECEIPT_RAW_MAX_ENTRIES = 16_384;
    private const REBOOTSTRAP_RETAINED_BACKUP_STATES = [
        'NONE',
        'RETAINED',
        'COLLECTED',
    ];
    private const REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES = 4_194_304;
    private const REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES = 16_384;
    private const REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES = 8_589_934_592;
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_MAX_BYTES = 8_192;
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_TOTAL_MAX_BYTES = 2_097_152;
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL = 'original';
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED = 'sealed-backup';
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED =
        'original-or-sealed';
    private const REBOOTSTRAP_DERIVED_WINDOWS_ACL_CONTENT_ONLY = 'content-only';
    private const REBOOTSTRAP_COLLECTION_MAX_ENTRIES = 65_536;
    private const MAX_RETAINED_REBOOTSTRAP_GENERATIONS = 2;
    private const REBOOTSTRAP_GENERATION_RESERVE_BYTES = 10_737_418_240;
    private const REBOOTSTRAP_GENERATION_RESERVE_INODES = 65_536;
    private const REBOOTSTRAP_TEST_RESERVE_BYTES = 8_388_608;
    private const REBOOTSTRAP_TEST_RESERVE_INODES = 128;
    private const REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES = 16_384;
    private const REBOOTSTRAP_CAPACITY_MOVE_CLOSURE_MAX_ENTRIES = 65_536;
    private const LINUX_MOUNTINFO_MAX_BYTES = 4_194_304;
    private const REBOOTSTRAP_CAPACITY_STATES = [
        'NONE',
        'ALLOCATING',
        'HELD',
        'RELEASING',
        'RELEASED',
    ];
    private const REBOOTSTRAP_CAPACITY_INSPECT_SCHEMA =
        'wls-capacity-inspect/1';
    private const REBOOTSTRAP_CAPACITY_INSPECT_STATES = [
        'NONE',
        'ALLOCATING',
        'HELD',
        'RELEASING',
    ];
    private const REBOOTSTRAP_CAPACITY_EVIDENCE_STATES = [
        'NONE',
        'RETAINED',
        'COLLECTING',
        'COLLECTED',
    ];
    private const REBOOTSTRAP_RETAINED_TOTAL_MAX_BYTES = 21_474_836_480;
    private const REBOOTSTRAP_PHASES = [
        'PREPARING',
        'PREPARED',
        'STOP_COMMITTED',
        'QUIESCED',
        'OLD_GENERATION_STASHED',
        'NEW_GENERATION_PUBLISHED',
        'PLATFORM_REFRESHED',
        'START_AUTHORIZED',
        'OBSERVING',
        'COMMITTED',
        'ROLLING_BACK',
        'ROLLBACK_START_AUTHORIZED',
        'ROLLBACK_OBSERVING',
        'ROLLED_BACK',
    ];
    private const REBOOTSTRAP_JOURNAL_FIELDS = [
        'schema_version',
        'operation',
        'nonce',
        'host_id',
        'phase',
        'package_digest',
        'package_version',
        'profile',
        'origin_boot_id',
        'recovery_boot_id',
        'created_at',
        'updated_at',
        'target_slot',
        'runtime_generation',
        'candidate_launcher_sha256',
        'candidate_launcher_size',
        'candidate_launcher_mode',
        'candidate_ca_bundle_sha256',
        'old_active_slot',
        'old_previous_slot',
        'old_launcher_sha256',
        'old_launcher_size',
        'old_launcher_mode',
        'old_ca_bundle_sha256',
        'old_slots',
        'trust_rotation',
        'derived_policy_sha256',
        'old_derived_manifest_sha256',
        'platform_snapshot',
        'admin_stopped_digest',
        'admin_stopped_contents_b64',
        'gateway_epoch',
        'old_gateway_epoch',
        'new_gateway_epoch',
        'capacity_reserve_state',
        'capacity_reserve_bytes',
        'capacity_reserve_inodes',
        'capacity_reserve_volume_id',
        'capacity_reserve_manifest_sha256',
        'capacity_reserve_release_sha256',
        'capacity_reserve_release_reason',
        'capacity_evidence_state',
        'failure_reason',
        'retained_backup_state',
        'backup_collection_nonce',
        'backup_collection_device',
        'backup_collection_inode',
        'retention_until',
        'retention_host_boot_id',
        'retained_monotonic_ms',
        'retention_deadline_monotonic_ms',
        'terminal_at',
        'signature',
    ];

    /**
     * Exact after-image observed by this instance when an atomic replacement
     * committed before its durability confirmation threw. It is deliberately
     * reset for every write and is useful only while the owning package lock
     * is still held.
     *
     * @var array{
     *   path:string,
     *   sha256:string,
     *   size:int,
     *   mode:int,
     *   status:array<string|int,mixed>
     * }|null
     */
    private ?array $lastAtomicWriteCommittedAfterImage = null;

    /** @var list<float> */
    private array $operationDeadlineStack = [];

    private const REQUIRED_CAPABILITIES = [
        'broker_sideband_actions',
        'certificate_public_trust_bundle',
        'certificate_snapshot_seal',
        'dual_control_channels',
        'native_peer_identity',
        'neutral_default_certificate',
        'no_follow_snapshot',
        'physical_rebootstrap_capacity_reserve',
        'privilege_separation',
        'self_contained_nginx',
        'self_contained_php',
        'singleton_fencing',
        'stable_launcher_rollback_target_proof',
    ];

    /**
     * Exact durable host-state contract for the first production WLS 2.0
     * release. Pre-release host slots that do not carry this signed contract
     * are deliberately not A/B rollback targets: their Controller may reject
     * or quarantine the current security ledger and certificate snapshots.
     */
    public const DURABLE_STATE_CONTRACT = [
        'schema_version' => 2,
        'security_ledger_read_schema' => 8,
        'security_ledger_write_schema' => 8,
        'snapshot_receipt_read_schema' => 2,
        'snapshot_receipt_write_schema' => 2,
        'snapshot_namespace' => 'snapshots-v2',
        'nonce_wal_schema' => 1,
        'nginx_test_schema' => 1,
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly NginxRuntimeArtifact $artifact = new NginxRuntimeArtifact(),
        private readonly ?string $trustedKeysFile = null,
        private readonly ?GatewayPlatformServiceInstaller $platform = null,
        private readonly ?\Closure $wallClock = null,
        private readonly ?\Closure $monotonicClockMilliseconds = null,
        private readonly ?\Closure $bootIdentity = null,
        private readonly ?\Closure $beforeStageVerification = null,
    ) {
    }

    /**
     * Fail closed while a whole-host launcher generation transaction owns the
     * host namespace. Ordinary A/B/install operations must never race it.
     */
    public function assertNoActiveRebootstrap(
        string $operation,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($operation): void {
                $this->assertNoActiveRebootstrapWithinDeadline($operation);
            },
        );
    }

    private function assertNoActiveRebootstrapWithinDeadline(
        string $operation,
    ): void {
        $this->paths->ensureDirectories();
        $this->withInstallLock(function () use ($operation): null {
            $this->assertNoRebootstrapTransactionLocked($operation);
            $this->collectExpiredRebootstrapBackupsLocked();
            return null;
        });
    }

    /** @return array<string,mixed>|null */
    public function rebootstrapStatus(
        ?string $nonce = null,
        ?float $deadlineMonotonic = null,
    ): ?array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): ?array => $this->rebootstrapStatusWithinDeadline($nonce),
        );
    }

    /** @return array<string,mixed>|null */
    private function rebootstrapStatusWithinDeadline(?string $nonce): ?array
    {
        $this->paths->ensureDirectories();
        return $this->withInstallLock(function () use ($nonce): ?array {
            $journal = $this->readRebootstrapJournalLocked();
            if ($journal === null) {
                return null;
            }
            if ($nonce !== null
                && !\hash_equals(
                    $this->normalizeRebootstrapNonce($nonce),
                    (string)$journal['nonce'],
                )
            ) {
                throw new \RuntimeException(
                    'A different gateway rebootstrap transaction owns this host.'
                );
            }
            return $this->publicRebootstrapJournal($journal);
        });
    }

    /**
     * Read one terminal receipt for the privileged platform ACL sealer.
     *
     * This deliberately performs no atomic-recovery cleanup and takes no
     * install lock: the caller already owns the platform-install lock while
     * sealing an existing workspace.  Its only purpose is to authenticate
     * the immutable receipt that binds a crash-replay collection alias to a
     * retained backup.  The administrator token remains root-only, so the
     * gateway service cannot use this verification path to gain receipt
     * access.
     *
     * @return array<string,mixed>
     */
    public function authenticatedTerminalRebootstrapReceiptForPlatformSeal(
        string $nonce,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $receipt = $this->decodeRebootstrapDocument(
            $this->readStableRegularFile(
                $this->paths->rebootstrapReceiptFile($nonce),
                self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                'Gateway rebootstrap terminal receipt for platform ACL seal',
            ),
            'Gateway rebootstrap terminal receipt for platform ACL seal',
        );
        if (!\hash_equals($nonce, (string)$receipt['nonce'])
            || !\in_array(
                (string)$receipt['phase'],
                ['COMMITTED', 'ROLLED_BACK'],
                true,
            )
            || !\in_array(
                (string)$receipt['retained_backup_state'],
                ['RETAINED', 'COLLECTED'],
                true,
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt is not a terminal retained-generation receipt.'
            );
        }
        return $receipt;
    }

    /**
     * @return array{
     *   slot:string,
     *   slot_dir:string,
     *   runtime_generation:string,
     *   package_digest:string,
     *   release_ready:bool,
     *   test_mode:bool,
     *   profile:string,
     *   previous_active_slot:string
     * }
     */
    public function stage(
        string $packageDirectory,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->stageWithinDeadline(
                $packageDirectory,
                $profile,
            ),
        );
    }

    /**
     * Serialize the complete first-install lifecycle across projects. The
     * callback owns discovery recheck, stage, platform definition, activation,
     * start and trusted status observation under this one host-root lock.
     *
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    public function withInitialBootstrapLock(
        \Closure $callback,
        ?float $deadlineMonotonic = null,
    ): mixed {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): mixed => $this->withHostPackageLock(
                'package-bootstrap.lock',
                'initial bootstrap',
                $callback,
                self::PACKAGE_OPERATION_TIMEOUT_SECONDS,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function stageWithinDeadline(
        string $packageDirectory,
        string $profile,
    ): array {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException('Gateway profile must be default or ipv4-only.');
        }

        if ($this->beforeStageVerification !== null) {
            if (!$this->paths->isTestMode()) {
                throw new \RuntimeException(
                    'Gateway stage verification hooks are forbidden in production.',
                );
            }
            ($this->beforeStageVerification)();
        }
        $verified = $this->verifyPackage($packageDirectory, $profile);
        $this->paths->ensureDirectories();
        $this->assertNoActiveRebootstrap('Gateway package staging');
        // A crashed first activation may leave its cleanup intent bound to
        // the slot that is still named active. Computing/locking only the
        // current inactive slot would then reject the exact recovery that must
        // run before a new staging decision. Fence both slot namespaces,
        // recover the host transaction first, and derive the inactive slot
        // only from the recovered pointer state.
        $this->withStagingLocks(['A', 'B'], function (): null {
            $this->recoverFailedInitialBootstrapCleanup();
            return null;
        });
        $slot = $this->paths->inactiveSlot();
        return $this->withStagingLock($slot, function () use (
            $verified,
            $profile,
            $slot,
        ): array {
            $previousActive = $this->withInstallLock(function () use (
                $slot,
                $verified,
            ): string {
                $this->assertNoRebootstrapTransactionLocked(
                    'Gateway package staging',
                );
                if (!\hash_equals($slot, $this->paths->inactiveSlot())) {
                    throw new \RuntimeException(
                        'Gateway inactive slot changed before staging lock acquisition.'
                    );
                }
                // An upgrade intent is the root-owned recovery journal for a
                // possibly half-switched A/B pointer. Even if its referenced
                // slot tree is unexpectedly absent, staging new bytes into
                // that namespace would destroy the launcher's ability to
                // distinguish recovery from a new candidate.
                if (\file_exists($this->paths->upgradeIntentFile())
                    || \is_link($this->paths->upgradeIntentFile())
                ) {
                    throw new \RuntimeException(
                        'An active gateway upgrade transaction blocks package staging.'
                    );
                }
                if ((\file_exists($this->paths->slotDir($slot))
                        || \is_link($this->paths->slotDir($slot)))
                    && !$this->inactiveSlotMayBeReplaced($slot)
                ) {
                    throw new \RuntimeException(
                        'Inactive gateway slot is still retained for rollback and cannot be replaced.'
                    );
                }
                $this->ensureHostIdLocked();
                GatewayInitialBootstrapCrashSimulation::hit(
                    'stage-after-host-id',
                    $this->paths,
                );
                $active = $this->activeSlotOrEmpty();
                if ($active !== '') {
                    $this->assertOrdinaryUpgradeTrustBundleStable(
                        $verified['manifest'],
                        $active,
                    );
                }
                return $active;
            });
            $slotDirectory = $this->paths->slotDir($slot);
            if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                $this->removeSlotTree($slot);
                if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                    throw new \RuntimeException(
                        'Expired inactive gateway slot could not be safely removed.'
                    );
                }
                $this->withInstallLock(function () use ($slot): null {
                    $this->clearInactiveSlotReplacementMarkers($slot);
                    return null;
                });
            } else {
                // A crash may occur after a failed candidate tree was removed
                // but before its terminal marker was consumed. With the slot
                // proven absent, no rollback artifact remains to retain.
                $this->withInstallLock(function () use ($slot): null {
                    $this->clearInactiveSlotReplacementMarkers($slot, true);
                    return null;
                });
            }

            $components = [];
            foreach ($verified['manifest']['components'] as $relative => $definition) {
                $components[(string)$relative] = [
                    'source' => $verified['package_dir'] . DIRECTORY_SEPARATOR
                        . \str_replace('/', DIRECTORY_SEPARATOR, (string)$relative),
                    'mode' => $this->installedComponentMode((int)$definition['mode']),
                    'sha256' => (string)$definition['sha256'],
                    'size' => (int)$definition['size'],
                ];
            }
            $components['release/manifest.json'] = [
                // Publish the exact bytes whose signature and component map
                // were verified. Never reopen the project-owned path here.
                'contents' => $verified['manifest_bytes'],
                'mode' => $this->installedComponentMode(0600),
                'sha256' => $verified['package_digest'],
                'size' => \strlen($verified['manifest_bytes']),
            ];
            if ($verified['signature_bytes'] !== '') {
                $components['release/manifest.sig'] = [
                    'contents' => $verified['signature_bytes'],
                    'mode' => $this->installedComponentMode(0600),
                    'sha256' => \hash('sha256', $verified['signature_bytes']),
                    'size' => \strlen($verified['signature_bytes']),
                ];
            }

            $artifactManifest = [];
            try {
                $hostId = $this->hostId();
                // NginxRuntimeArtifact may have created part of the immutable
                // tree before a copy/hash/fsync failure is reported. Keep the
                // install itself inside the slot rollback boundary; otherwise
                // that partial tree has no retention marker yet and every
                // later stage is permanently fenced from replacing it.
                $artifactManifest = $this->artifact->install(
                    $slotDirectory,
                    'host_gateway',
                    $components,
                    [
                        'package_digest' => $verified['package_digest'],
                        'package_version' => (string)$verified['manifest']['version'],
                        'protocol_min' => (int)$verified['manifest']['protocol_min'],
                        'protocol_max' => (int)$verified['manifest']['protocol_max'],
                        'security_profile' => (string)$verified['manifest']['security_profile'],
                        'implementation_level' => (string)$verified['manifest']['implementation_level'],
                        'capabilities' => $verified['manifest']['capabilities'],
                        'durable_state_contract'
                            => $verified['manifest']['durable_state_contract'],
                        'host_id' => $hostId,
                        'slot' => $slot,
                        'listen_profile' => $profile,
                        'test_mode' => $this->paths->isTestMode(),
                        'release_ready' => (bool)$verified['manifest']['release_ready'],
                    ],
                );
                // NginxRuntimeArtifact deliberately publishes private 0700
                // slots. A production POSIX Controller is then dropped to the
                // dedicated service account, so the completed immutable slot
                // must be sealed root-owned but group-readable/executable
                // before any activation can reference it.
                ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
                    ->secureInstalledRuntimeSlot(
                        $slotDirectory,
                        $this->activeOperationDeadline(),
                    );
                // Permission sealing is a separate platform operation. Rehash
                // the complete slot only after that boundary, before any
                // package executable can run with installer privileges.
                $sealed = $this->artifact->verify($slotDirectory, 'host_gateway');
                if (!($sealed['ok'] ?? false)
                    || !\hash_equals(
                        (string)$artifactManifest['runtime_generation'],
                        (string)($sealed['runtime_generation'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Gateway runtime slot changed before privileged self-test.'
                    );
                }
                $this->runSlotSelfTests($slotDirectory);
                $launcherComponent = $this->componentPath('wls-gateway-launcher');
                $guardianComponent = \PHP_OS_FAMILY === 'Windows'
                    ? $this->componentPath('wls-gateway-guardian')
                    : $launcherComponent;
                // The stable launcher and its trust identity are host-global,
                // not slot-local. Serialize their publication with cleanup,
                // activation and the other slot's staging transaction, and
                // revalidate the pointer/cleanup fences in that same critical
                // section before exposing the bootstrap bytes.
                $this->withInstallLock(function () use (
                    $slot,
                    $previousActive,
                    $slotDirectory,
                    $launcherComponent,
                    $guardianComponent,
                    $verified,
                ): null {
                    $this->assertNoFailedInitialBootstrapCleanup();
                    if (!\hash_equals($previousActive, $this->activeSlotOrEmpty())
                        || !\hash_equals($slot, $this->paths->inactiveSlot())
                    ) {
                        throw new \RuntimeException(
                            'Gateway slot pointers changed before bootstrap publication.'
                        );
                    }
                    $this->ensureAdministratorCredentialLocked();
                    $this->installImmutableGuardian(
                        $slotDirectory . DIRECTORY_SEPARATOR
                            . \str_replace('/', DIRECTORY_SEPARATOR, $guardianComponent),
                        (string)$verified['manifest']['components'][$guardianComponent]['sha256'],
                        \PHP_OS_FAMILY === 'Windows',
                    );
                    $this->installStableLauncher(
                        $slotDirectory . DIRECTORY_SEPARATOR
                            . \str_replace('/', DIRECTORY_SEPARATOR, $launcherComponent),
                        (string)$verified['manifest']['components'][$launcherComponent]['sha256'],
                    );
                    return null;
                });
            } catch (\Throwable $throwable) {
                $this->removeSlotTree($slot);
                throw $throwable;
            }

            $this->withInstallLock(function () use ($slot, $previousActive): null {
                $this->assertNoFailedInitialBootstrapCleanup();
                if (!\hash_equals($previousActive, $this->activeSlotOrEmpty())
                    || !\hash_equals($slot, $this->paths->inactiveSlot())
                ) {
                    throw new \RuntimeException(
                        'Gateway slot pointers changed during staged package publication.'
                    );
                }
                return null;
            });

            return [
                'slot' => $slot,
                'slot_dir' => $slotDirectory,
                'runtime_generation' => (string)$artifactManifest['runtime_generation'],
                'package_digest' => $verified['package_digest'],
                'manifest_digest' => $verified['manifest_digest'],
                'signature_digest' => $verified['signature_digest'],
                'platform' => (string)$verified['manifest']['platform'],
                'arch' => \strtolower(\trim((string)$verified['manifest']['arch'])),
                'release_ready' => (bool)$verified['manifest']['release_ready'],
                'test_mode' => $this->paths->isTestMode(),
                'profile' => $profile,
                'previous_active_slot' => $previousActive,
            ];
        });
    }

    /**
     * Recover only the exact immutable first-install slot selected by the
     * durable bootstrap journal. No project bytes are reopened and no slot is
     * guessed from directory presence alone.
     *
     * @return array<string,mixed>|null
     */
    public function recoverInitialStagedPackage(
        string $packageDigest,
        string $profile,
        ?string $expectedSlot = null,
        ?float $deadlineMonotonic = null,
    ): ?array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($packageDigest, $profile, $expectedSlot): ?array {
                $packageDigest = \strtolower(\trim($packageDigest));
                $profile = \strtolower(\trim($profile));
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $packageDigest) !== 1
                    || !\hash_equals('default', $profile)
                ) {
                    throw new \RuntimeException(
                        'Gateway initial staged-package recovery fingerprint is invalid.',
                    );
                }
                $slot = $expectedSlot === null || \trim($expectedSlot) === ''
                    ? 'B'
                    : \strtoupper(\trim($expectedSlot));
                if (!\in_array($slot, ['A', 'B'], true)) {
                    throw new \RuntimeException(
                        'Gateway initial staged-package recovery slot is invalid.',
                    );
                }
                $slotDirectory = $this->paths->slotDir($slot);
                if (!\file_exists($slotDirectory) && !\is_link($slotDirectory)) {
                    return null;
                }
                $active = $this->activeSlotOrEmpty();
                if ($active !== '' && !\hash_equals($slot, $active)) {
                    throw new \RuntimeException(
                        'REPAIR_REQUIRED: Gateway initial staged slot conflicts with the active pointer.',
                    );
                }
                $proof = $this->verifiedStableLauncherSlotProof(
                    $slot,
                    'Gateway initial bootstrap replay',
                );
                $manifest = $this->installedManifest($slot);
                $releaseManifestPath = $slotDirectory . DIRECTORY_SEPARATOR
                    . 'release' . DIRECTORY_SEPARATOR . 'manifest.json';
                $releaseManifestBytes = $this->readStableRegularFile(
                    $releaseManifestPath,
                    8_388_608,
                    'Gateway replay release manifest',
                );
                $releaseManifest = \json_decode($releaseManifestBytes, true);
                if (!\is_array($releaseManifest)) {
                    throw new \RuntimeException(
                        'Gateway replay release manifest is invalid.',
                    );
                }
                $signatureBytes = $this->readOptionalStableRegularFile(
                    $slotDirectory . DIRECTORY_SEPARATOR . 'release'
                        . DIRECTORY_SEPARATOR . 'manifest.sig',
                    16_384,
                    'Gateway replay release signature',
                ) ?? '';
                if (!\hash_equals(
                        $packageDigest,
                        \strtolower(\trim((string)($proof['package_digest'] ?? ''))),
                    )
                    || !\hash_equals(
                        $profile,
                        \strtolower(\trim((string)($manifest['listen_profile'] ?? ''))),
                    )
                ) {
                    throw new \RuntimeException(
                        'REPAIR_REQUIRED: Gateway initial staged slot belongs to a different package fingerprint.',
                    );
                }
                return [
                    'slot' => $slot,
                    'slot_dir' => $slotDirectory,
                    'runtime_generation' => (string)$proof['runtime_generation'],
                    'package_digest' => $packageDigest,
                    'manifest_digest' => \hash('sha256', $releaseManifestBytes),
                    'signature_digest' => \hash('sha256', $signatureBytes),
                    'platform' => (string)($releaseManifest['platform'] ?? ''),
                    'arch' => \strtolower(\trim((string)(
                        $releaseManifest['arch'] ?? ''
                    ))),
                    'release_ready' => (bool)($manifest['release_ready'] ?? false),
                    'test_mode' => $this->paths->isTestMode(),
                    'profile' => $profile,
                    'previous_active_slot' => '',
                ];
            },
        );
    }

    /**
     * Prepare and self-test a launcher-generation candidate outside A/B. No
     * running service, slot pointer, global launcher, or platform definition is
     * changed by this phase.
     *
     * @return array<string,mixed>
     */
    public function prepareRebootstrapCandidate(
        string $packageDirectory,
        string $profile,
        string $nonce,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->prepareRebootstrapCandidateWithinDeadline(
                $packageDirectory,
                $profile,
                $nonce,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function prepareRebootstrapCandidateWithinDeadline(
        string $packageDirectory,
        string $profile,
        string $nonce,
    ): array {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException(
                'Gateway profile must be default or ipv4-only.'
            );
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'Windows whole-host rebootstrap is unavailable until the native '
                    . 'Recovery Guardian transition protocol is implemented; '
                    . 'ordinary A/B activation remains available.',
            );
        }
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $verified = $this->verifyPackage($packageDirectory, $profile);
        if (!$this->paths->isTestMode()
            && ($verified['manifest']['release_ready'] ?? false) !== true
        ) {
            throw new \RuntimeException(
                'A production gateway rebootstrap requires a release-ready signed package.'
            );
        }
        $packageDigest = (string)$verified['package_digest'];
        $launcherComponent = $this->componentPath('wls-gateway-launcher');
        $launcherDefinition = (array)(
            $verified['manifest']['components'][$launcherComponent] ?? []
        );
        $candidateLauncherDigest = \strtolower(\trim((string)(
            $launcherDefinition['sha256'] ?? ''
        )));
        $candidateLauncherSize = $launcherDefinition['size'] ?? null;
        $candidateSlotLauncherMode = \is_int($launcherDefinition['mode'] ?? null)
            ? $this->installedComponentMode((int)$launcherDefinition['mode'])
            : -1;
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $candidateLauncherDigest) !== 1
            || !\is_int($candidateLauncherSize)
            || $candidateLauncherSize < 1
            || $candidateLauncherSize > self::MAX_PACKAGE_BYTES
            || !\in_array($candidateSlotLauncherMode, [0500, 0555, 0700, 0755], true)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap candidate launcher declaration is invalid.'
            );
        }
        $candidateLauncherMode = $this->stableLauncherPosixMode();
        $candidateCaBundleDigest = $this->releaseTrustBundleDigest(
            $verified['manifest'],
            'Gateway rebootstrap candidate CA trust bundle',
        );
        $this->paths->ensureDirectories();

        return $this->withStagingLocks(['A', 'B'], function () use (
            $verified,
            $profile,
            $nonce,
            $packageDigest,
            $launcherComponent,
            $candidateLauncherDigest,
            $candidateLauncherSize,
            $candidateLauncherMode,
            $candidateCaBundleDigest,
        ): array {
            $journal = $this->withInstallLock(function () use (
                $verified,
                $profile,
                $nonce,
                $packageDigest,
                $candidateLauncherDigest,
                $candidateLauncherSize,
                $candidateLauncherMode,
                $candidateCaBundleDigest,
            ): array {
                $existing = $this->readRebootstrapJournalLocked();
                if ($existing !== null) {
                    $this->assertSameRebootstrapRequest(
                        $existing,
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    return $existing;
                }
                $this->collectExpiredRebootstrapBackupsLocked();
                $receipt = $this->readRebootstrapReceiptLocked($nonce);
                if ($receipt !== null) {
                    $this->assertSameRebootstrapRequest(
                        $receipt,
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    return $receipt;
                }
                $this->assertRebootstrapRetentionBudgetAvailableLocked();
                if (\file_exists($this->paths->upgradeIntentFile())
                    || \is_link($this->paths->upgradeIntentFile())
                ) {
                    throw new \RuntimeException(
                        'An A/B upgrade transaction must finish before gateway rebootstrap.'
                    );
                }
                GatewayProjectStateFilesystem::assertMoveNoReplaceRuntimeCapability(
                    $this->paths->rebootstrapDir(),
                );
                $old = $this->verifiedRebootstrapOldGeneration();
                if (\hash_equals(
                        (string)$old['launcher_sha256'],
                        $candidateLauncherDigest,
                    )
                    && \hash_equals(
                        (string)$old['ca_bundle_sha256'],
                        $candidateCaBundleDigest,
                    )
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap requires a different signed launcher or CA trust generation; use ordinary A/B upgrade when both are unchanged.'
                    );
                }
                $oldActiveSlot = (string)$old['active_slot'];
                $oldActive = $old['slots'][$oldActiveSlot] ?? null;
                if (!\is_array($oldActive)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap active generation lacks its runtime identity.',
                    );
                }
                (new GatewayGuardianGenerationHead($this->paths))->initializeStable(
                    $this->hostId(),
                    (string)$old['launcher_sha256'],
                    (string)$old['ca_bundle_sha256'],
                    (string)($oldActive['runtime_generation'] ?? ''),
                    $this->hostBootIdentityNow(),
                );
                $now = $this->wallClockNow();
                $boot = $this->hostBootIdentityNow();
                return $this->writeRebootstrapJournalLocked([
                    'schema_version' => self::REBOOTSTRAP_JOURNAL_SCHEMA,
                    'operation' => 'rebootstrap',
                    'nonce' => $nonce,
                    'host_id' => $this->hostId(),
                    'phase' => 'PREPARING',
                    'package_digest' => $packageDigest,
                    'package_version' => (string)$verified['manifest']['version'],
                    'profile' => $profile,
                    'origin_boot_id' => $boot,
                    'recovery_boot_id' => $boot,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'target_slot' => 'A',
                    'runtime_generation' => '',
                    'candidate_launcher_sha256' => $candidateLauncherDigest,
                    'candidate_launcher_size' => $candidateLauncherSize,
                    'candidate_launcher_mode' => $candidateLauncherMode,
                    'candidate_ca_bundle_sha256' => $candidateCaBundleDigest,
                    'old_active_slot' => (string)$old['active_slot'],
                    'old_previous_slot' => (string)$old['previous_slot'],
                    'old_launcher_sha256' => (string)$old['launcher_sha256'],
                    'old_launcher_size' => (int)$old['launcher_size'],
                    'old_launcher_mode' => (int)$old['launcher_mode'],
                    'old_ca_bundle_sha256' => (string)$old['ca_bundle_sha256'],
                    'old_slots' => (array)$old['slots'],
                    'trust_rotation' => !\hash_equals(
                        (string)$old['ca_bundle_sha256'],
                        $candidateCaBundleDigest,
                    ),
                    'derived_policy_sha256' => '',
                    'old_derived_manifest_sha256' => '',
                    'platform_snapshot' => null,
                    'admin_stopped_digest' => '',
                    'admin_stopped_contents_b64' => '',
                    'gateway_epoch' => '',
                    'old_gateway_epoch' => '',
                    'new_gateway_epoch' => '',
                    'capacity_reserve_state' => 'NONE',
                    'capacity_reserve_bytes' => $this->rebootstrapCapacityBytes(),
                    'capacity_reserve_inodes' => $this->rebootstrapCapacityInodes(),
                    'capacity_reserve_volume_id' => '',
                    'capacity_reserve_manifest_sha256' => '',
                    'capacity_reserve_release_sha256' => '',
                    'capacity_reserve_release_reason' => '',
                    'capacity_evidence_state' => 'NONE',
                    'failure_reason' => '',
                    'retained_backup_state' => 'NONE',
                    'backup_collection_nonce' => '',
                    'backup_collection_device' => '',
                    'backup_collection_inode' => '',
                    'retention_until' => 0,
                    'retention_host_boot_id' => '',
                    'retained_monotonic_ms' => 0,
                    'retention_deadline_monotonic_ms' => 0,
                    'terminal_at' => 0,
                    'signature' => '',
                ]);
            });
            $this->injectRebootstrapCrashAfterPhase(
                (string)$journal['phase'],
            );
            if (!\hash_equals('PREPARING', (string)$journal['phase'])) {
                $this->assertPreparedRebootstrapCandidate($journal);
                return $this->publicRebootstrapJournal($journal);
            }

            $candidateDirectory = $this->paths->rebootstrapCandidateDir($nonce);
            $candidatePrepared = false;
            try {
                if (\file_exists($candidateDirectory) || \is_link($candidateDirectory)) {
                    $this->assertNoLiveProcessesForRuntimePaths([
                        $candidateDirectory,
                    ], 'gateway rebootstrap candidate cleanup');
                    $this->removeTree($candidateDirectory);
                }
                $components = $this->packageComponentsForInstall($verified);
                $artifactManifest = $this->artifact->install(
                    $candidateDirectory,
                    'host_gateway',
                    $components,
                    [
                        'package_digest' => $packageDigest,
                        'package_version' => (string)$verified['manifest']['version'],
                        'protocol_min' => (int)$verified['manifest']['protocol_min'],
                        'protocol_max' => (int)$verified['manifest']['protocol_max'],
                        'security_profile' => (string)$verified['manifest']['security_profile'],
                        'implementation_level' => (string)$verified['manifest']['implementation_level'],
                        'capabilities' => $verified['manifest']['capabilities'],
                        'durable_state_contract'
                            => $verified['manifest']['durable_state_contract'],
                        'host_id' => $this->hostId(),
                        'slot' => 'A',
                        'listen_profile' => $profile,
                        'test_mode' => $this->paths->isTestMode(),
                        'release_ready' => (bool)$verified['manifest']['release_ready'],
                    ],
                );
                ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
                    ->secureRebootstrapCandidateRuntime(
                        $candidateDirectory,
                        $nonce,
                        $this->activeOperationDeadline(),
                    );
                $sealed = $this->artifact->verify(
                    $candidateDirectory,
                    'host_gateway',
                );
                if (($sealed['ok'] ?? false) !== true
                    || !\hash_equals(
                        (string)$artifactManifest['runtime_generation'],
                        (string)($sealed['runtime_generation'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap candidate changed before self-test.'
                    );
                }
                $candidateLauncher = $candidateDirectory . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, $launcherComponent);
                $launcherDigest = $this->digestStableRegularFile(
                    $candidateLauncher,
                    self::MAX_PACKAGE_BYTES,
                    'Gateway rebootstrap candidate launcher',
                );
                if (!\hash_equals(
                        $candidateLauncherDigest,
                        $launcherDigest['sha256'],
                    )
                    || $candidateLauncherSize !== $launcherDigest['size']
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap candidate launcher changed before self-test.'
                    );
                }
                $actualCandidateCa = $this->verifiedRebootstrapCandidateTrustBundle(
                    $candidateDirectory,
                    'Gateway rebootstrap candidate',
                );
                if (!\hash_equals(
                    $candidateCaBundleDigest,
                    $actualCandidateCa,
                )) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap candidate CA trust bundle changed before self-test.',
                    );
                }
                $this->runSlotSelfTests($candidateDirectory);
                $journal = $this->withInstallLock(function () use (
                    $nonce,
                    $packageDigest,
                    $profile,
                    $artifactManifest,
                    &$candidatePrepared,
                ): array {
                    $current = $this->requiredRebootstrapJournalLocked(
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    if (!\hash_equals('PREPARING', (string)$current['phase'])) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap phase changed during candidate self-test.'
                        );
                    }
                    $current['runtime_generation'] = (string)(
                        $artifactManifest['runtime_generation'] ?? ''
                    );
                    $current['phase'] = 'PREPARED';
                    $current = $this->writeRebootstrapJournalLocked($current);
                    $candidatePrepared = true;
                    $this->retirePreparedRebootstrapCandidateInstallLockLocked(
                        $current,
                    );
                    return $current;
                });
                $this->injectRebootstrapCrashAfterPhase('PREPARED');
                $this->assertPreparedRebootstrapCandidate($journal);
                return $this->publicRebootstrapJournal($journal);
            } catch (\Throwable $throwable) {
                if ($throwable instanceof GatewayRebootstrapCrashSimulation
                    || $candidatePrepared
                ) {
                    throw $throwable;
                }
                $cleanupFailure = null;
                try {
                    if (\file_exists($candidateDirectory)
                        || \is_link($candidateDirectory)
                    ) {
                        $this->assertNoLiveProcessesForRuntimePaths(
                            [$candidateDirectory],
                            'failed gateway rebootstrap candidate cleanup',
                        );
                        $this->removeTree($candidateDirectory);
                    }
                    $this->withInstallLock(function () use (
                        $nonce,
                        $packageDigest,
                        $profile,
                    ): null {
                        $current = $this->requiredRebootstrapJournalLocked(
                            $nonce,
                            $packageDigest,
                            $profile,
                        );
                        if (\hash_equals('PREPARING', (string)$current['phase'])) {
                            GatewayProjectStateFilesystem::removeRegular(
                                $this->paths->rebootstrapJournalFile(),
                                'failed pre-stop gateway rebootstrap journal',
                            );
                        }
                        return null;
                    });
                } catch (\Throwable $cleanup) {
                    $cleanupFailure = $cleanup;
                }
                if ($cleanupFailure instanceof \Throwable) {
                    throw new \RuntimeException(
                        GatewayBoundedText::singleLine(
                            $throwable->getMessage(),
                            1536,
                            'Gateway rebootstrap candidate preparation failed.',
                        ) . ' Candidate cleanup also failed: '
                            . GatewayBoundedText::singleLine(
                                $cleanupFailure->getMessage(),
                                512,
                                'cleanup failed',
                            ),
                        0,
                        $throwable,
                    );
                }
                throw $throwable;
            }
        });
    }

    /**
     * Persist bounded, authenticated evidence without changing phase.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function recordRebootstrapEvidence(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $phase,
        array $evidence,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->mutateRebootstrapJournal(
                $nonce,
                $packageDigest,
                $profile,
                $phase,
                $phase,
                $evidence,
            ),
        );
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function advanceRebootstrapPhase(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $expectedPhase,
        string $nextPhase,
        array $evidence = [],
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->mutateRebootstrapJournal(
                $nonce,
                $packageDigest,
                $profile,
                $expectedPhase,
                $nextPhase,
                $evidence,
            ),
        );
    }

    /**
     * Materialize and authenticate the physical same-volume recovery budget
     * while the old gateway is still serving traffic. A logical free-space
     * check is not sufficient: STOP_COMMITTED is forbidden until this method
     * has durably published HELD.
     *
     * @return array<string,mixed>
     */
    public function ensureRebootstrapCapacityReserve(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->withInstallLockRaw(function () use (
                $nonce,
                $packageDigest,
                $profile,
            ): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                $packageDigest = \strtolower(\trim($packageDigest));
                $profile = \strtolower(\trim($profile));
                $journal = $this->requiredRebootstrapJournalLocked(
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                if (!\hash_equals('PREPARED', (string)$journal['phase'])) {
                    throw new \RuntimeException(
                        'Gateway capacity reserve can only be established before the maintenance stop.',
                    );
                }
                $this->assertPreparedRebootstrapCandidate($journal);
                $this->assertRebootstrapDerivedNamespacesShareCapacityVolume(
                    $journal,
                );
                $state = (string)$journal['capacity_reserve_state'];
                if (\hash_equals('HELD', $state)) {
                    $this->verifyRebootstrapCapacityReserveHeldLocked($journal);
                    return $this->publicRebootstrapJournal($journal);
                }
                if (!\in_array($state, ['NONE', 'ALLOCATING'], true)) {
                    throw new \RuntimeException(
                        'Gateway capacity reserve cannot be established from state '
                            . $state . '.',
                    );
                }
                if (\hash_equals('NONE', $state)) {
                    $journal['capacity_reserve_state'] = 'ALLOCATING';
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    $this->injectRebootstrapCrash(
                        'capacity-reserve:after-allocating-journal',
                    );
                }
                $journal = $this->bindAllocatingRebootstrapCapacityReserveLocked(
                    $journal,
                );
                return $this->publicRebootstrapJournal($journal);
            }),
        );
    }

    /** @return array<string,mixed> */
    public function verifyRebootstrapCapacityReserveHeld(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->withInstallLockRaw(function () use (
                $nonce,
                $packageDigest,
                $profile,
            ): array {
                $journal = $this->requiredRebootstrapJournalLocked(
                    $this->normalizeRebootstrapNonce($nonce),
                    \strtolower(\trim($packageDigest)),
                    \strtolower(\trim($profile)),
                );
                $this->verifyRebootstrapCapacityReserveHeldLocked($journal);
                return $this->publicRebootstrapJournal($journal);
            }),
        );
    }

    /**
     * Bind one pre-stop ALLOCATING journal to the exact native HELD reserve.
     *
     * Native create is deliberately idempotent: it either completes an
     * authenticated allocation left in the allocating namespace or proves the
     * exact HELD tree that a crash left after its durable rename. This method
     * is the only bridge from that unbound native state to normal HELD
     * lifecycle authority. In particular, callers must not use
     * complete-release to delete an unbound HELD tree.
     *
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function bindAllocatingRebootstrapCapacityReserveLocked(
        array $journal,
    ): array {
        if (!\hash_equals(
            'ALLOCATING',
            (string)($journal['capacity_reserve_state'] ?? ''),
        ) || !\hash_equals('PREPARED', (string)($journal['phase'] ?? ''))) {
            throw new \RuntimeException(
                'Only a prepared ALLOCATING capacity reserve can be bound.',
            );
        }
        $this->assertPreparedRebootstrapCandidate($journal);
        $this->assertRebootstrapDerivedNamespacesShareCapacityVolume($journal);
        $native = $this->runRebootstrapCapacityCommand(
            $journal,
            'create',
        );
        $this->assertNativeCapacityHeldEvidence($native, $journal);
        // Native creation has made the nonce-owned HELD tree durable, but PHP
        // has not yet bound its evidence into the signed transaction journal.
        // A pre-stop cancellation must resume this binding before it can
        // invoke the normal authenticated release transition.
        $this->injectRebootstrapCrash(
            'capacity-reserve:after-native-create-before-held-manifest',
        );
        $nonce = (string)$journal['nonce'];
        $manifestFile = $this->paths
            ->rebootstrapCapacityHeldManifestFile($nonce);
        if (\file_exists($manifestFile) || \is_link($manifestFile)) {
            $manifest = $this->readRebootstrapCapacityEvidence(
                $manifestFile,
                'HELD',
                $journal,
            );
            $this->assertCapacityEvidenceMatchesNative(
                $manifest,
                $native,
            );
        } else {
            $manifest = $this->writeRebootstrapCapacityEvidence(
                $manifestFile,
                $this->capacityEvidenceDocument(
                    $journal,
                    $native,
                    'HELD',
                    '',
                ),
            );
        }
        $manifestBytes = $this->readStableRegularFile(
            $manifestFile,
            self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
            'Gateway rebootstrap HELD capacity manifest',
        );
        $journal['capacity_reserve_state'] = 'HELD';
        $journal['capacity_reserve_volume_id'] =
            (string)$manifest['volume_id'];
        $journal['capacity_reserve_manifest_sha256'] =
            \hash('sha256', $manifestBytes);
        $journal['capacity_reserve_release_sha256'] = '';
        $journal['capacity_reserve_release_reason'] = '';
        $journal = $this->writeRebootstrapJournalLocked($journal);
        $this->injectRebootstrapCrash(
            'capacity-reserve:after-held-journal',
        );
        $this->verifyRebootstrapCapacityReserveHeldLocked($journal);
        return $journal;
    }

    /**
     * Read the native, candidate-bound namespace without allocating any new
     * reserve.  This is deliberately narrower than create: a cancellation
     * may only bind a reserve that is already an exact HELD tree.
     *
     * @param array<string,mixed> $journal
     * @return array{schema:string,state:string}
     */
    private function inspectAllocatingRebootstrapCapacityReserveLocked(
        array $journal,
    ): array {
        if (!\hash_equals(
            'ALLOCATING',
            (string)($journal['capacity_reserve_state'] ?? ''),
        ) || !\hash_equals('PREPARED', (string)($journal['phase'] ?? ''))) {
            throw new \RuntimeException(
                'Only a prepared ALLOCATING capacity reserve can be inspected.',
            );
        }
        $native = $this->runRebootstrapCapacityCommand($journal, 'inspect');
        $keys = \array_keys($native);
        \sort($keys, SORT_STRING);
        if ($keys !== ['schema', 'state']
            || !\is_string($native['schema'] ?? null)
            || !\is_string($native['state'] ?? null)
            || !\hash_equals(
                self::REBOOTSTRAP_CAPACITY_INSPECT_SCHEMA,
                (string)($native['schema'] ?? ''),
            )
            || !\in_array(
                (string)($native['state'] ?? ''),
                self::REBOOTSTRAP_CAPACITY_INSPECT_STATES,
                true,
            )
        ) {
            throw new \RuntimeException(
                'Gateway native capacity inspect evidence violates '
                    . self::REBOOTSTRAP_CAPACITY_INSPECT_SCHEMA . '.',
            );
        }
        /** @var array{schema:string,state:string} $native */
        return $native;
    }

    /**
     * Clean the two states which carry no HELD authority.  The native
     * complete-release operation is allowed to remove only an exact partial
     * ALLOCATING tree (or no tree); its zero-reserve release receipt prevents
     * the journal from ever claiming that an unauthenticated HELD manifest
     * existed.
     *
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function releaseUnboundAllocatingCapacityReserveLocked(
        array $journal,
        string $nativeState,
    ): array {
        if (!\hash_equals(
            'ALLOCATING',
            (string)($journal['capacity_reserve_state'] ?? ''),
        ) || !\hash_equals('PREPARED', (string)($journal['phase'] ?? ''))
            || !\in_array($nativeState, ['NONE', 'ALLOCATING'], true)
            || (string)$journal['capacity_reserve_volume_id'] !== ''
            || (string)$journal['capacity_reserve_manifest_sha256'] !== ''
            || (string)$journal['capacity_reserve_release_sha256'] !== ''
            || (string)$journal['capacity_reserve_release_reason'] !== '') {
            throw new \RuntimeException(
                'Only a prepared ALLOCATING capacity reserve can be cleaned without a manifest.',
            );
        }
        $nonce = (string)$journal['nonce'];
        $releasedFile = $this->paths
            ->rebootstrapCapacityReleasedReceiptFile($nonce);
        foreach ([
            $this->paths->rebootstrapCapacityHeldManifestFile($nonce),
            $this->paths->rebootstrapCapacityReleasingReceiptFile($nonce),
        ] as $evidence) {
            if (\file_exists($evidence) || \is_link($evidence)) {
                throw new \RuntimeException(
                    'Gateway unbound ALLOCATING cancellation found unexpected capacity evidence.',
                );
            }
        }

        $released = null;
        if (\file_exists($releasedFile) || \is_link($releasedFile)) {
            // A release receipt can be durable while the ALLOCATING journal
            // still owns the transaction. Only the exact empty cancel
            // receipt is allowed to bridge that publication boundary.
            $finalInspection = $this
                ->inspectAllocatingRebootstrapCapacityReserveLocked($journal);
            if (!\hash_equals('NONE', $nativeState)
                || !\hash_equals('NONE', (string)$finalInspection['state'])) {
                throw new \RuntimeException(
                    'Gateway unbound ALLOCATING cancellation found a receipt alongside live native capacity.',
                );
            }
            $released = $this->readRebootstrapCapacityEvidence(
                $releasedFile,
                'RELEASED',
                $journal,
            );
            if (!\hash_equals('cancel', (string)$released['release_reason'])
                || !\hash_equals(
                    \str_repeat('0', 64),
                    (string)$released['volume_id'],
                )
                || !\hash_equals(
                    \str_repeat('0', 64),
                    (string)$released['entry_set_sha256'],
                )
                || !\hash_equals(
                    \str_repeat('0', 64),
                    (string)$released['anchor_set_sha256'],
                )) {
                throw new \RuntimeException(
                    'Gateway unbound ALLOCATING cancellation receipt is not the exact empty cancel receipt.',
                );
            }
        } else {
            $native = $this->runRebootstrapCapacityCommand(
                $journal,
                'complete-release',
                'cancel',
            );
            if (\array_keys($native) !== ['state']
                || !\is_string($native['state'] ?? null)
                || !\hash_equals('RELEASED', (string)($native['state'] ?? ''))) {
                throw new \RuntimeException(
                    'Gateway native capacity cleanup did not return its exact RELEASED receipt.',
                );
            }
            $finalInspection = $this
                ->inspectAllocatingRebootstrapCapacityReserveLocked($journal);
            if (!\hash_equals('NONE', (string)$finalInspection['state'])) {
                throw new \RuntimeException(
                    'Gateway native capacity cleanup did not leave the exact NONE state.',
                );
            }
            // A crash here leaves ALLOCATING plus native NONE. The next
            // attempt re-inspects NONE and repeats idempotent cleanup instead
            // of allocating a new multi-gigabyte reserve.
            $this->injectRebootstrapCrash(
                'capacity-reserve:after-native-unbound-cancel-before-released-journal',
            );
            $released = $this->writeRebootstrapCapacityEvidence(
                $releasedFile,
                $this->capacityEmptyReleasedDocument($journal, 'cancel'),
            );
            // Atomic receipt publication can commit before the journal. Its
            // next replay authenticates and promotes exactly this after-image.
            $this->injectRebootstrapCrash(
                'capacity-reserve:after-unbound-released-receipt-before-journal',
            );
        }
        if (!\is_array($released)) {
            throw new \RuntimeException(
                'Gateway unbound ALLOCATING cancellation did not retain its authenticated release receipt.',
            );
        }
        $journal['capacity_reserve_state'] = 'RELEASED';
        $journal['capacity_reserve_volume_id'] =
            (string)$released['volume_id'];
        $journal['capacity_reserve_manifest_sha256'] = '';
        $journal['capacity_reserve_release_reason'] = 'cancel';
        $journal['capacity_reserve_release_sha256'] = \hash(
            'sha256',
            $this->readStableRegularFile(
                $releasedFile,
                self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
                'Gateway unbound ALLOCATING RELEASED capacity receipt',
            ),
        );
        $journal = $this->writeRebootstrapJournalLocked($journal);
        $this->injectRebootstrapCrash(
            'capacity-reserve:after-released-journal',
        );
        $this->assertRebootstrapCapacityReleasedLocked($journal);
        return $journal;
    }

    /**
     * Release only the authenticated reserve owned by this exact transaction.
     * The raw package lock deliberately skips generic recovery cleanup so the
     * release still works when both data blocks and inode metadata are full.
     *
     * @return array<string,mixed>
     */
    public function releaseRebootstrapCapacityReserve(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->withInstallLockRaw(function () use (
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                $packageDigest = \strtolower(\trim($packageDigest));
                $profile = \strtolower(\trim($profile));
                $reason = \strtolower(\trim($reason));
                if (!\in_array($reason, ['forward', 'rollback', 'cancel'], true)) {
                    throw new \InvalidArgumentException(
                        'Gateway capacity release reason is invalid.',
                    );
                }
                $journal = $this->requiredRebootstrapJournalLocked(
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                $this->assertCapacityReleaseAllowed($journal, $reason);
                $state = (string)$journal['capacity_reserve_state'];
                if (\hash_equals('NONE', $state)) {
                    if (!\hash_equals('cancel', $reason)) {
                        throw new \RuntimeException(
                            'Gateway stopped-generation recovery requires a previously HELD capacity reserve.',
                        );
                    }
                    return $this->publicRebootstrapJournal($journal);
                }
                if (\hash_equals('RELEASED', $state)) {
                    if (!\hash_equals(
                        $reason,
                        (string)$journal['capacity_reserve_release_reason'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway capacity reserve was released for a different recovery path.',
                        );
                    }
                    $this->assertRebootstrapCapacityReleasedLocked($journal);
                    return $this->publicRebootstrapJournal($journal);
                }
                if (\hash_equals('ALLOCATING', $state)) {
                    if (!\hash_equals('cancel', $reason)) {
                        throw new \RuntimeException(
                            'An incomplete capacity allocation can only be cancelled before stop.',
                        );
                    }
                    $inspection = $this
                        ->inspectAllocatingRebootstrapCapacityReserveLocked(
                            $journal,
                        );
                    $nativeState = (string)$inspection['state'];
                    if (\in_array($nativeState, ['NONE', 'ALLOCATING'], true)) {
                        return $this->publicRebootstrapJournal(
                            $this->releaseUnboundAllocatingCapacityReserveLocked(
                                $journal,
                                $nativeState,
                            ),
                        );
                    }
                    if (\hash_equals('HELD', $nativeState)) {
                        // The inspect result proves an exact native HELD tree;
                        // create then re-verifies that identity before PHP
                        // writes the first signed manifest binding.
                        $journal = $this
                            ->bindAllocatingRebootstrapCapacityReserveLocked(
                                $journal,
                            );
                        $state = 'HELD';
                    } else {
                        // A PHP ALLOCATING journal has no authority to adopt
                        // a begun release.  Keeping it untouched lets the
                        // explicitly authenticated recovery path resolve it.
                        throw new \RuntimeException(
                            'Gateway native capacity inspect found RELEASING '
                                . 'state for an unbound ALLOCATING journal.',
                        );
                    }
                }
                $releasingFile = $this->paths
                    ->rebootstrapCapacityReleasingReceiptFile($nonce);
                if (\hash_equals('HELD', $state)) {
                    // At the public stop boundary verification is strict
                    // HELD. Release replay is different: the native helper
                    // may already have durably marked/dropped control credits
                    // before PHP persisted RELEASING, so authenticate the
                    // manifest here and let begin-release validate that
                    // narrowly authorized transition.
                    $held = $this
                        ->authenticatedRebootstrapCapacityHeldManifestLocked(
                            $journal,
                        );
                    $native = $this->runRebootstrapCapacityCommand(
                        $journal,
                        'begin-release',
                        $reason,
                    );
                    $this->assertNativeCapacityReleasingEvidence(
                        $native,
                        $journal,
                    );
                    $this->assertCapacityEvidenceMatchesNative(
                        $held,
                        $native,
                    );
                    // The native begin-release transition is durable before
                    // PHP can publish its RELEASING receipt. A crash here
                    // leaves the authenticated HELD journal intact; replay
                    // must therefore re-run the narrowly authorized native
                    // transition rather than treating it as a fresh reserve.
                    $this->injectRebootstrapCrash(
                        'capacity-reserve:after-native-begin-before-releasing-journal',
                    );
                    $releasing = $this->writeRebootstrapCapacityEvidence(
                        $releasingFile,
                        $this->capacityEvidenceDocument(
                            $journal,
                            $native,
                            'RELEASING',
                            $reason,
                            (int)$held['created_at'],
                        ),
                    );
                    $journal['capacity_reserve_state'] = 'RELEASING';
                    $journal['capacity_reserve_release_reason'] = $reason;
                    $journal['capacity_reserve_release_sha256'] = \hash(
                        'sha256',
                        $this->readStableRegularFile(
                            $releasingFile,
                            self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
                            'Gateway RELEASING capacity receipt',
                        ),
                    );
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    $this->injectRebootstrapCrash(
                        'capacity-reserve:after-releasing-journal',
                    );
                } elseif (\hash_equals('RELEASING', $state)) {
                    $releasing = $this->readRebootstrapCapacityEvidence(
                        $releasingFile,
                        'RELEASING',
                        $journal,
                    );
                    $this->assertCapacityReceiptDigest(
                        $journal,
                        $releasingFile,
                    );
                    if (!\hash_equals(
                        $reason,
                        (string)$releasing['release_reason'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway capacity release replay changed its reason.',
                        );
                    }
                } else {
                    throw new \RuntimeException(
                        'Gateway capacity reserve cannot be released from state '
                            . $state . '.',
                    );
                }
                $this->runRebootstrapCapacityCommand(
                    $journal,
                    'complete-release',
                    $reason,
                );
                $releasedFile = $this->paths
                    ->rebootstrapCapacityReleasedReceiptFile($nonce);
                $released = $releasing;
                $released['state'] = 'RELEASED';
                $released['physical_bytes'] = 0;
                $released['inode_count'] = 0;
                $released['signature'] = '';
                $this->writeRebootstrapCapacityEvidence(
                    $releasedFile,
                    $released,
                );
                $journal['capacity_reserve_state'] = 'RELEASED';
                $journal['capacity_reserve_release_sha256'] = \hash(
                    'sha256',
                    $this->readStableRegularFile(
                        $releasedFile,
                        self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
                        'Gateway RELEASED capacity receipt',
                    ),
                );
                $journal = $this->writeRebootstrapJournalLocked($journal);
                $this->injectRebootstrapCrash(
                    'capacity-reserve:after-released-journal',
                );
                $this->assertRebootstrapCapacityReleasedLocked($journal);
                return $this->publicRebootstrapJournal($journal);
            }),
        );
    }

    private function rebootstrapCapacityBytes(): int
    {
        return $this->paths->isTestMode()
            ? self::REBOOTSTRAP_TEST_RESERVE_BYTES
            : self::REBOOTSTRAP_GENERATION_RESERVE_BYTES;
    }

    private function rebootstrapCapacityInodes(): int
    {
        return $this->paths->isTestMode()
            ? self::REBOOTSTRAP_TEST_RESERVE_INODES
            : self::REBOOTSTRAP_GENERATION_RESERVE_INODES;
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function runRebootstrapCapacityCommand(
        array $journal,
        string $operation,
        string $reason = '',
    ): array {
        if (!\in_array($operation, [
            'create',
            'verify',
            'inspect',
            'begin-release',
            'complete-release',
        ], true)) {
            throw new \InvalidArgumentException(
                'Gateway native capacity operation is invalid.',
            );
        }
        if (\hash_equals('inspect', $operation)
            && \PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'Gateway native capacity inspect requires the '
                    . self::REBOOTSTRAP_CAPACITY_INSPECT_SCHEMA
                    . ' Windows capability; this launcher must fail closed '
                    . 'until that native contract is installed.',
            );
        }
        $candidate = $this->paths->rebootstrapCandidateDir(
            (string)$journal['nonce'],
        );
        $launcher = $candidate . DIRECTORY_SEPARATOR
            . \str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $this->componentPath('wls-gateway-launcher'),
            );
        $this->assertStableFileDigest(
            $launcher,
            (string)$journal['candidate_launcher_sha256'],
            (int)$journal['candidate_launcher_size'],
            (int)$journal['candidate_launcher_mode'],
            'Gateway rebootstrap capacity launcher',
        );
        $command = [
            $launcher,
            '--capacity-reserve=' . $operation,
            '--home=' . $this->paths->home(),
            '--nonce=' . (string)$journal['nonce'],
            '--bytes=' . (string)$journal['capacity_reserve_bytes'],
            '--inodes=' . (string)$journal['capacity_reserve_inodes'],
            '--platform-definition=' . $this->paths->serviceDefinitionFile(),
            '--test-mode=' . ($this->paths->isTestMode() ? '1' : '0'),
        ];
        if ($reason !== '') {
            $command[] = '--release-reason=' . $reason;
        }
        if ((string)$journal['capacity_reserve_manifest_sha256'] !== '') {
            $command[] = '--expected-manifest-sha256='
                . (string)$journal['capacity_reserve_manifest_sha256'];
        }
        $windowsHelperProof = null;
        if (\PHP_OS_FAMILY === 'Windows') {
            $windowsHelperProof = $this->boundedCommandHelperProof(
                (string)$journal['old_active_slot'],
            );
        }
        $result = $this->runCommand(
            $command,
            $windowsHelperProof,
            600.0,
        );
        if ((int)$result['code'] !== 0) {
            throw new \RuntimeException(
                'Gateway native capacity ' . $operation . ' failed: '
                    . GatewayBoundedText::singleLine(
                        (string)$result['output'],
                        1024,
                        'native capacity operation failed',
                    ),
            );
        }
        try {
            $decoded = \json_decode(
                \trim((string)$result['output']),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Gateway native capacity evidence is not valid JSON.',
                0,
                $exception,
            );
        }
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \RuntimeException(
                'Gateway native capacity evidence is not an object.',
            );
        }
        return $decoded;
    }

    private function assertStableFileDigest(
        string $file,
        string $expectedDigest,
        int $expectedSize,
        int $expectedMode,
        string $label,
    ): void {
        $before = @\lstat($file);
        $digest = $this->digestStableRegularFile(
            $file,
            self::MAX_PACKAGE_BYTES,
            $label,
        );
        $after = @\lstat($file);
        if (!\is_array($before)
            || !\is_array($after)
            || \is_link($file)
            || !$this->isRegularFileStatus($before)
            || (int)($before['nlink'] ?? 0) !== 1
            || !$this->sameFileState($before, $after)
            || !\hash_equals($expectedDigest, (string)$digest['sha256'])
            || $expectedSize !== (int)$digest['size']
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$before['mode']) & 0777) !== $expectedMode))
        ) {
            throw new \RuntimeException(
                $label . ' changed before the native capacity operation.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function verifyRebootstrapCapacityReserveHeldLocked(
        array $journal,
    ): array {
        $this->assertRebootstrapDerivedNamespacesShareCapacityVolume($journal);
        $manifest = $this->authenticatedRebootstrapCapacityHeldManifestLocked(
            $journal,
        );
        $native = $this->runRebootstrapCapacityCommand($journal, 'verify');
        $this->assertNativeCapacityHeldEvidence($native, $journal);
        $this->assertCapacityEvidenceMatchesNative($manifest, $native);
        return $manifest;
    }

    /**
     * Prove before STOP_COMMITTED that every manifest-owned source object can
     * be renamed into the same-volume rebootstrap backup. Checking only the
     * fixed category roots is insufficient because a nested POSIX mount or a
     * Windows junction can otherwise fail after public traffic has stopped.
     */
    private function assertRebootstrapDerivedNamespacesShareCapacityVolume(
        array $journal,
    ): void {
        $homeIdentity = GatewayBoundedTreeWalker::identity(
            $this->paths->home(),
        );
        $expectedDevice = (string)$homeIdentity['device'];
        $mountPoints = $this->linuxMountInfoSnapshot();
        $visited = 0;
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $this->assertRebootstrapCapacityMoveClosureAt(
                $definition['root'],
                'derived state ' . $category,
                $expectedDevice,
                $mountPoints,
                $visited,
                $definition['preserved'] !== [],
            );
        }

        $nonce = (string)$journal['nonce'];
        $this->assertRebootstrapCapacityMoveClosureAt(
            $this->paths->rebootstrapCandidateDir($nonce),
            'prepared candidate',
            $expectedDevice,
            $mountPoints,
            $visited,
            true,
        );
        $this->assertRebootstrapCapacityMoveClosureAt(
            $this->paths->rebootstrapBackupDir($nonce),
            'transaction backup',
            $expectedDevice,
            $mountPoints,
            $visited,
            false,
        );
        foreach (['A', 'B'] as $slot) {
            $path = $this->paths->slotDir($slot);
            $required = \is_array(((array)$journal['old_slots'])[$slot] ?? null);
            if (!$required && (\file_exists($path) || \is_link($path))) {
                throw new \RuntimeException(
                    'Gateway rebootstrap found an unbound old slot before stop: '
                        . $slot . '.',
                );
            }
            if ($required) {
                $this->assertRebootstrapCapacityMoveClosureAt(
                    $path,
                    'old slot ' . $slot,
                    $expectedDevice,
                    $mountPoints,
                    $visited,
                    true,
                );
            }
        }
        foreach ([
            'stable launcher' => $this->paths->launcherFile(),
            'stable launcher identity' => $this->paths->trustDir()
                . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
            'active slot pointer' => $this->paths->activeSlotFile(),
        ] as $label => $path) {
            $this->assertRebootstrapCapacityMoveClosureAt(
                $path,
                $label,
                $expectedDevice,
                $mountPoints,
                $visited,
                true,
            );
        }
        $previous = $this->paths->previousSlotFile();
        if ((string)$journal['old_previous_slot'] === '') {
            if (\file_exists($previous) || \is_link($previous)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap found an unbound previous-slot pointer before stop.',
                );
            }
        } else {
            $this->assertRebootstrapCapacityMoveClosureAt(
                $previous,
                'previous slot pointer',
                $expectedDevice,
                $mountPoints,
                $visited,
                true,
            );
        }
        if ($mountPoints !== $this->linuxMountInfoSnapshot()) {
            throw new \RuntimeException(
                'Linux mount topology changed during the rebootstrap pre-stop proof.',
            );
        }
    }

    /**
     * @param array<string,true> $mountPoints
     */
    private function assertRebootstrapCapacityMoveClosureAt(
        string $path,
        string $label,
        string $expectedDevice,
        array $mountPoints,
        int &$visited,
        bool $required,
    ): void {
        $parent = \dirname($path);
        $parentIdentity = GatewayBoundedTreeWalker::identity($parent);
        if (!\hash_equals(
            $expectedDevice,
            (string)$parentIdentity['device'],
        ) || $this->rebootstrapCapacityPathIsNestedMount(
            $parent,
            $mountPoints,
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap move parent is mounted or cross-volume: '
                    . $label . '.',
            );
        }
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path) || $required) {
                throw new \RuntimeException(
                    'Gateway rebootstrap move source is unavailable or unsafe: '
                        . $label . '.',
                );
            }
            return;
        }
        if (\is_link($path)
            || (!$this->isRegularFileStatus($status)
                && ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap move source is linked or special: '
                    . $label . '.',
            );
        }
        $records = $this->isRegularFileStatus($status)
            ? [GatewayBoundedTreeWalker::identity($path)]
            : GatewayBoundedTreeWalker::collect(
                $path,
                true,
                false,
                self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES,
                GatewayBoundedTreeWalker::MAX_DEPTH,
                fn (): null => $this->deadlineProgress(
                    'proving same-volume ' . $label,
                ),
            );
        foreach ($records as $record) {
            if (++$visited
                > self::REBOOTSTRAP_CAPACITY_MOVE_CLOSURE_MAX_ENTRIES
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap same-volume proof exceeds its fixed entry limit.',
                );
            }
            GatewayBoundedTreeWalker::revalidate($record);
            if (!\hash_equals(
                $expectedDevice,
                (string)$record['device'],
            ) || $this->rebootstrapCapacityPathIsNestedMount(
                (string)$record['path'],
                $mountPoints,
            )) {
                throw new \RuntimeException(
                    'Gateway rebootstrap move source is mounted or cross-volume: '
                        . $label . '.',
                );
            }
        }
    }

    /** @param array<string,true> $mountPoints */
    private function rebootstrapCapacityPathIsNestedMount(
        string $path,
        array $mountPoints,
    ): bool {
        if (\PHP_OS_FAMILY !== 'Linux') {
            return false;
        }
        $resolved = \realpath($path);
        if (!\is_string($resolved)) {
            throw new \RuntimeException(
                'Gateway rebootstrap move source cannot be canonicalized.',
            );
        }
        $resolved = \rtrim(\str_replace('\\', '/', $resolved), '/');
        $home = \rtrim(
            \str_replace('\\', '/', (string)\realpath($this->paths->home())),
            '/',
        );
        return $resolved !== $home && isset($mountPoints[$resolved]);
    }

    /** @return array<string,true> */
    private function linuxMountInfoSnapshot(): array
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            return [];
        }
        $contents = @\file_get_contents('/proc/self/mountinfo');
        if (!\is_string($contents)
            || $contents === ''
            || \strlen($contents) > self::LINUX_MOUNTINFO_MAX_BYTES
            || \str_contains($contents, "\0")
        ) {
            throw new \RuntimeException(
                'Linux mount topology is unavailable for the rebootstrap pre-stop proof.',
            );
        }
        $mountPoints = [];
        foreach (\preg_split('/\n/', \rtrim($contents, "\n")) ?: [] as $line) {
            $fields = \explode(' ', $line);
            if (\count($fields) < 10
                || !isset($fields[4])
                || !\str_starts_with($fields[4], '/')
            ) {
                throw new \RuntimeException(
                    'Linux mount topology violates the strict mountinfo contract.',
                );
            }
            $path = \str_replace(
                ['\\040', '\\011', '\\012', '\\134'],
                [' ', "\t", "\n", '\\'],
                $fields[4],
            );
            if ($path === ''
                || \str_contains($path, "\0")
                || \str_contains($path, "\n")
            ) {
                throw new \RuntimeException(
                    'Linux mount topology contains an unsafe mount path.',
                );
            }
            $mountPoints[\rtrim($path, '/')] = true;
        }
        \ksort($mountPoints, SORT_STRING);
        return $mountPoints;
    }

    /** @param array<string,mixed> $journal */
    private function authenticatedRebootstrapCapacityHeldManifestLocked(
        array $journal,
    ): array {
        if (!\hash_equals(
            'HELD',
            (string)$journal['capacity_reserve_state'],
        )) {
            throw new \RuntimeException(
                'Gateway maintenance stop requires a HELD physical capacity reserve.',
            );
        }
        $manifestFile = $this->paths->rebootstrapCapacityHeldManifestFile(
            (string)$journal['nonce'],
        );
        $manifest = $this->readRebootstrapCapacityEvidence(
            $manifestFile,
            'HELD',
            $journal,
        );
        $manifestBytes = $this->readStableRegularFile(
            $manifestFile,
            self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
            'Gateway rebootstrap HELD capacity manifest',
        );
        if (!\hash_equals(
            (string)$journal['capacity_reserve_manifest_sha256'],
            \hash('sha256', $manifestBytes),
        ) || !\hash_equals(
            (string)$journal['capacity_reserve_volume_id'],
            (string)$manifest['volume_id'],
        )) {
            throw new \RuntimeException(
                'Gateway HELD capacity manifest is not bound to the rebootstrap journal.',
            );
        }
        return $manifest;
    }

    /**
     * @param array<string,mixed> $native
     * @param array<string,mixed> $journal
     */
    private function assertNativeCapacityHeldEvidence(
        array $native,
        array $journal,
    ): void {
        $this->assertNativeCapacityEvidenceShape($native, 'HELD');
        if ((int)$native['physical_bytes']
                < (int)$journal['capacity_reserve_bytes']
            || (int)$native['inode_count']
                !== (int)$journal['capacity_reserve_inodes']
        ) {
            throw new \RuntimeException(
                'Gateway native capacity proof did not physically allocate its declared byte/inode targets.',
            );
        }
    }

    /**
     * @param array<string,mixed> $native
     * @param array<string,mixed> $journal
     */
    private function assertNativeCapacityReleasingEvidence(
        array $native,
        array $journal,
    ): void {
        $this->assertNativeCapacityEvidenceShape($native, 'RELEASING');
        if ((int)$native['physical_bytes']
                < (int)$journal['capacity_reserve_bytes']
            || (int)$native['inode_count']
                !== (int)$journal['capacity_reserve_inodes']
        ) {
            throw new \RuntimeException(
                'Gateway RELEASING capacity proof lost its target allocation before the durable release transition.',
            );
        }
    }

    /** @param array<string,mixed> $native */
    private function assertNativeCapacityEvidenceShape(
        array $native,
        string $state,
    ): void {
        $keys = \array_keys($native);
        \sort($keys, SORT_STRING);
        if ($keys !== [
                'anchor_set_sha256',
                'entry_set_sha256',
                'inode_count',
                'physical_bytes',
                'state',
                'volume_id',
            ]
            || !\hash_equals($state, (string)($native['state'] ?? ''))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($native['volume_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($native['entry_set_sha256'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($native['anchor_set_sha256'] ?? '')) !== 1
            || !\is_int($native['physical_bytes'] ?? null)
            || (int)$native['physical_bytes'] < 0
            || !\is_int($native['inode_count'] ?? null)
            || (int)$native['inode_count'] < 0
        ) {
            throw new \RuntimeException(
                'Gateway native capacity evidence violates its strict contract.',
            );
        }
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function assertCapacityEvidenceMatchesNative(
        array $left,
        array $right,
    ): void {
        foreach ([
            'volume_id',
            'physical_bytes',
            'inode_count',
            'entry_set_sha256',
            'anchor_set_sha256',
        ] as $field) {
            if ((string)$left[$field] !== (string)$right[$field]) {
                throw new \RuntimeException(
                    'Gateway capacity evidence changed after authentication: '
                        . $field . '.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $native
     * @return array<string,mixed>
     */
    private function capacityEvidenceDocument(
        array $journal,
        array $native,
        string $state,
        string $reason,
        ?int $createdAt = null,
    ): array {
        return [
            'schema' => 'wls-rebootstrap-capacity/1',
            'host_id' => (string)$journal['host_id'],
            'nonce' => (string)$journal['nonce'],
            'package_digest' => (string)$journal['package_digest'],
            'profile' => (string)$journal['profile'],
            'launcher_sha256' => (string)$journal['candidate_launcher_sha256'],
            'state' => $state,
            'volume_id' => (string)$native['volume_id'],
            'target_bytes' => (int)$journal['capacity_reserve_bytes'],
            'target_inodes' => (int)$journal['capacity_reserve_inodes'],
            'physical_bytes' => (int)$native['physical_bytes'],
            'inode_count' => (int)$native['inode_count'],
            'entry_set_sha256' => (string)$native['entry_set_sha256'],
            'anchor_set_sha256' => (string)$native['anchor_set_sha256'],
            'release_reason' => $reason,
            'created_at' => $createdAt ?? $this->wallClockNow(),
            'signature' => '',
        ];
    }

    /** @param array<string,mixed> $journal */
    private function capacityEmptyReleasedDocument(
        array $journal,
        string $reason,
    ): array {
        return $this->capacityEvidenceDocument(
            $journal,
            [
                'volume_id' => \str_repeat('0', 64),
                'physical_bytes' => 0,
                'inode_count' => 0,
                'entry_set_sha256' => \str_repeat('0', 64),
                'anchor_set_sha256' => \str_repeat('0', 64),
            ],
            'RELEASED',
            $reason,
        );
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function writeRebootstrapCapacityEvidence(
        string $file,
        array $document,
    ): array {
        $document['signature'] = '';
        $this->assertRebootstrapCapacityEvidenceContract($document, false);
        $unsigned = $document;
        unset($unsigned['signature']);
        $key = $this->administratorHmacKey(
            'sign the gateway rebootstrap capacity evidence',
        );
        try {
            $document['signature'] = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($unsigned),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        $this->assertRebootstrapCapacityEvidenceContract($document);
        $encoded = GatewayClient::canonicalJson($document) . "\n";
        if (\strlen($encoded)
            > self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity evidence exceeds its fixed limit.',
            );
        }
        $this->atomicWrite($file, $encoded, 0600);
        return $document;
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function readRebootstrapCapacityEvidence(
        string $file,
        string $state,
        array $journal,
    ): array {
        $contents = $this->readStableRegularFile(
            $file,
            self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
            'Gateway rebootstrap capacity evidence',
        );
        $document = $this->decodeRebootstrapCapacityEvidence(
            $contents,
            'Gateway rebootstrap capacity evidence',
        );
        if (!\hash_equals($state, (string)$document['state'])
            || !\hash_equals((string)$journal['host_id'], (string)$document['host_id'])
            || !\hash_equals((string)$journal['nonce'], (string)$document['nonce'])
            || !\hash_equals((string)$journal['package_digest'], (string)$document['package_digest'])
            || !\hash_equals((string)$journal['profile'], (string)$document['profile'])
            || !\hash_equals((string)$journal['candidate_launcher_sha256'], (string)$document['launcher_sha256'])
            || (int)$journal['capacity_reserve_bytes'] !== (int)$document['target_bytes']
            || (int)$journal['capacity_reserve_inodes'] !== (int)$document['target_inodes']
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity evidence is not bound to this transaction.',
            );
        }
        return $document;
    }

    /** @return array<string,mixed> */
    private function decodeRebootstrapCapacityEvidence(
        string $contents,
        string $label,
    ): array {
        $document = \json_decode($contents, true);
        if (!\is_array($document)
            || \array_is_list($document)
            || !\hash_equals(
                GatewayClient::canonicalJson($document) . "\n",
                $contents,
            )
        ) {
            throw new \RuntimeException(
                $label . ' is not canonical JSON.',
            );
        }
        $this->assertRebootstrapCapacityEvidenceContract($document);
        $signature = (string)$document['signature'];
        $unsigned = $document;
        unset($unsigned['signature']);
        $key = $this->administratorHmacKey(
            'verify the gateway rebootstrap capacity evidence',
        );
        try {
            $expected = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($unsigned),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals($expected, $signature)) {
            throw new \RuntimeException(
                $label . ' authentication failed.',
            );
        }
        return $document;
    }

    /** @param array<string,mixed> $document */
    private function assertRebootstrapCapacityEvidenceContract(
        array $document,
        bool $requireSignature = true,
    ): void {
        $keys = \array_keys($document);
        \sort($keys, SORT_STRING);
        if ($keys !== [
                'anchor_set_sha256',
                'created_at',
                'entry_set_sha256',
                'host_id',
                'inode_count',
                'launcher_sha256',
                'nonce',
                'package_digest',
                'physical_bytes',
                'profile',
                'release_reason',
                'schema',
                'signature',
                'state',
                'target_bytes',
                'target_inodes',
                'volume_id',
            ]
            || !\hash_equals('wls-rebootstrap-capacity/1', (string)($document['schema'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($document['host_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($document['nonce'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['package_digest'] ?? '')) !== 1
            || !\in_array((string)($document['profile'] ?? ''), ['default', 'ipv4-only'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['launcher_sha256'] ?? '')) !== 1
            || !\in_array((string)($document['state'] ?? ''), ['HELD', 'RELEASING', 'RELEASED'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['volume_id'] ?? '')) !== 1
            || !\is_int($document['target_bytes'] ?? null)
            || (int)$document['target_bytes'] < 1
            || !\is_int($document['target_inodes'] ?? null)
            || (int)$document['target_inodes'] < 1
            || !\is_int($document['physical_bytes'] ?? null)
            || (int)$document['physical_bytes'] < 0
            || !\is_int($document['inode_count'] ?? null)
            || (int)$document['inode_count'] < 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['entry_set_sha256'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['anchor_set_sha256'] ?? '')) !== 1
            || !\in_array((string)($document['release_reason'] ?? ''), ['', 'forward', 'rollback', 'cancel'], true)
            || !\is_int($document['created_at'] ?? null)
            || (int)$document['created_at'] < 1
            || ($requireSignature
                && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($document['signature'] ?? '')) !== 1)
            || (!$requireSignature && (string)($document['signature'] ?? '') !== '')
            || (\hash_equals('HELD', (string)($document['state'] ?? ''))
                && ((string)$document['release_reason'] !== ''
                    || (int)$document['physical_bytes'] < (int)$document['target_bytes']
                    || (int)$document['inode_count'] !== (int)$document['target_inodes']))
            || (\in_array((string)($document['state'] ?? ''), ['RELEASING', 'RELEASED'], true)
                && (string)$document['release_reason'] === '')
            || (\hash_equals('RELEASED', (string)($document['state'] ?? ''))
                && ((int)$document['physical_bytes'] !== 0
                    || (int)$document['inode_count'] !== 0))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity evidence violates its strict contract.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertCapacityReleaseAllowed(
        array $journal,
        string $reason,
    ): void {
        $phase = (string)$journal['phase'];
        $allowed = match ($reason) {
            'forward' => \hash_equals('QUIESCED', $phase),
            'cancel' => \hash_equals('PREPARED', $phase)
                && (string)$journal['admin_stopped_digest'] === '',
            'rollback' => !\in_array($phase, ['PREPARING', 'COMMITTED', 'ROLLED_BACK'], true)
                && (!\hash_equals('PREPARED', $phase)
                    || (string)$journal['admin_stopped_digest'] !== ''),
            default => false,
        };
        if (!$allowed) {
            throw new \RuntimeException(
                'Gateway capacity reserve release is not allowed from '
                    . $phase . ' for ' . $reason . '.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertCapacityReceiptDigest(
        array $journal,
        string $file,
    ): void {
        $contents = $this->readStableRegularFile(
            $file,
            self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
            'Gateway rebootstrap capacity release receipt',
        );
        if (!\hash_equals(
            (string)$journal['capacity_reserve_release_sha256'],
            \hash('sha256', $contents),
        )) {
            throw new \RuntimeException(
                'Gateway capacity release receipt is not bound to the journal.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapCapacityReleasedLocked(
        array $journal,
    ): void {
        if (!\hash_equals(
            'RELEASED',
            (string)$journal['capacity_reserve_state'],
        )) {
            throw new \RuntimeException(
                'Gateway generation mutation requires a RELEASED capacity reserve.',
            );
        }
        $nonce = (string)$journal['nonce'];
        $releasedFile = $this->paths
            ->rebootstrapCapacityReleasedReceiptFile($nonce);
        $released = $this->readRebootstrapCapacityEvidence(
            $releasedFile,
            'RELEASED',
            $journal,
        );
        $this->assertCapacityReceiptDigest($journal, $releasedFile);
        if (!\hash_equals(
            (string)$journal['capacity_reserve_release_reason'],
            (string)$released['release_reason'],
        )) {
            throw new \RuntimeException(
                'Gateway RELEASED capacity receipt changed its recovery path.',
            );
        }
        $allocating = $this->paths->rebootstrapCapacityDir()
            . DIRECTORY_SEPARATOR . $nonce . '.allocating';
        foreach ([
            $allocating,
            $this->paths->rebootstrapCapacityHeldDir($nonce),
            $this->paths->rebootstrapCapacityReleasingDir($nonce),
        ] as $live) {
            if (\file_exists($live) || \is_link($live)) {
                throw new \RuntimeException(
                    'Gateway capacity reserve remains live after RELEASED: '
                        . \basename($live) . '.',
                );
            }
        }
    }

    /**
     * Atomically replace the stopped old launcher generation with the prepared
     * generation. The platform service must remain disabled and ADMIN_STOPPED
     * must remain present; callers prove those external facts immediately
     * before invoking this method.
     *
     * @return array<string,mixed>
     */
    public function publishRebootstrapGeneration(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->publishRebootstrapGenerationWithinDeadline(
                $nonce,
                $packageDigest,
                $profile,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function publishRebootstrapGenerationWithinDeadline(
        string $nonce,
        string $packageDigest,
        string $profile,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $profile = \strtolower(\trim($profile));
        return $this->withStagingLocks(['A', 'B'], function () use (
            $nonce,
            $packageDigest,
            $profile,
        ): array {
            return $this->withInstallLock(function () use (
                $nonce,
                $packageDigest,
                $profile,
            ): array {
                $journal = $this->requiredRebootstrapJournalLocked(
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                $phase = (string)$journal['phase'];
                if (\in_array($phase, [
                    'NEW_GENERATION_PUBLISHED',
                    'PLATFORM_REFRESHED',
                    'START_AUTHORIZED',
                    'OBSERVING',
                    'COMMITTED',
                ], true)) {
                    $this->assertPublishedRebootstrapGeneration($journal);
                    return $this->publicRebootstrapJournal($journal);
                }
                if (!\in_array($phase, [
                    'QUIESCED',
                    'OLD_GENERATION_STASHED',
                ], true)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap generation cannot publish from phase '
                        . $phase . '.'
                    );
                }
                $this->assertRebootstrapCapacityReleasedLocked($journal);
                $this->assertPreparedRebootstrapCandidate($journal);
                $backup = $this->paths->rebootstrapBackupDir($nonce);
                $this->ensurePrivateRebootstrapDirectory($backup);
                $this->ensurePrivateRebootstrapDirectory(
                    $backup . DIRECTORY_SEPARATOR . 'slots',
                );
                $this->ensurePrivateRebootstrapDirectory(
                    $backup . DIRECTORY_SEPARATOR . 'trust',
                );
                $this->ensurePrivateRebootstrapDirectory(
                    $backup . DIRECTORY_SEPARATOR . 'bin',
                );

                if (\hash_equals('QUIESCED', $phase)) {
                    $derived = $this->prepareRebootstrapDerivedManifest(
                        $journal,
                        $backup,
                    );
                    if ((string)$journal['old_derived_manifest_sha256'] === '') {
                        $journal['old_derived_manifest_sha256'] = $derived['digest'];
                        $journal['derived_policy_sha256'] = $derived['policy_digest'];
                        $journal = $this->writeRebootstrapJournalLocked($journal);
                        $this->injectRebootstrapCrash(
                            'after:DERIVED_MANIFEST_BOUND',
                        );
                    } elseif (!\hash_equals(
                        (string)$journal['old_derived_manifest_sha256'],
                        (string)$derived['digest'],
                    ) || !\hash_equals(
                        (string)$journal['derived_policy_sha256'],
                        (string)$derived['policy_digest'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap derived-state manifest changed after binding.',
                        );
                    }
                    $this->stashOldRebootstrapGeneration($journal, $backup);
                    $this->assertRetainedRebootstrapBackup(
                        $journal,
                        $backup,
                    );
                    $journal['phase'] = 'OLD_GENERATION_STASHED';
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    $this->injectRebootstrapCrashAfterPhase(
                        'OLD_GENERATION_STASHED',
                    );
                }
                if (!(bool)$journal['trust_rotation']) {
                    $this->publishRebootstrapDerivedWorkingCopy($journal);
                }
                $this->publishPreparedRebootstrapGeneration($journal, $backup);
                $journal['phase'] = 'NEW_GENERATION_PUBLISHED';
                $journal = $this->writeRebootstrapJournalLocked($journal);
                $this->injectRebootstrapCrashAfterPhase(
                    'NEW_GENERATION_PUBLISHED',
                );
                $this->assertPublishedRebootstrapGeneration($journal);
                return $this->publicRebootstrapJournal($journal);
            });
        });
    }

    /**
     * Restore the complete old launcher generation while the platform service
     * is persistently stopped. The journal deliberately remains ROLLING_BACK
     * until the Manager has also restored the platform definition.
     *
     * @return array<string,mixed>
     */
    public function beginRebootstrapRollback(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use (
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                $profile = \strtolower(\trim($profile));
                return $this->withInstallLock(function () use (
                    $nonce,
                    $packageDigest,
                    $profile,
                    $reason,
                ): array {
                    $journal = $this->requiredRebootstrapJournalLocked(
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    $phase = (string)$journal['phase'];
                    if (\in_array($phase, [
                        'ROLLING_BACK',
                        'ROLLBACK_START_AUTHORIZED',
                        'ROLLBACK_OBSERVING',
                        'ROLLED_BACK',
                    ], true)) {
                        if (\hash_equals('ROLLING_BACK', $phase)) {
                            (new GatewayGuardianTransitionProtocol($this->paths))
                                ->requestRollback($journal);
                        }
                        return $this->publicRebootstrapJournal($journal);
                    }
                    if (\hash_equals('COMMITTED', $phase)) {
                        throw new \RuntimeException(
                            'A committed gateway rebootstrap cannot enter maintenance rollback.',
                        );
                    }
                    if (\hash_equals('PREPARED', $phase)
                        && (string)$journal['admin_stopped_digest'] === ''
                    ) {
                        throw new \RuntimeException(
                            'A pre-stop gateway rebootstrap must use cancelPreparedRebootstrap; whole-generation rollback requires a committed ADMIN_STOPPED fence.',
                        );
                    }
                    if (!$this->rebootstrapTransitionAllowed(
                        $phase,
                        'ROLLING_BACK',
                    )) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap cannot begin rollback from phase '
                                . $phase . '.',
                        );
                    }
                    $journal['phase'] = 'ROLLING_BACK';
                    $journal['failure_reason'] = GatewayBoundedText::singleLine(
                        $reason,
                        2048,
                        'Gateway rebootstrap failed.',
                    );
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    (new GatewayGuardianTransitionProtocol($this->paths))
                        ->requestRollback($journal);
                    $this->injectRebootstrapCrashAfterPhase('ROLLING_BACK');
                    return $this->publicRebootstrapJournal($journal);
                });
            },
        );
    }

    /**
     * Revoke a rollback start authorization after the restored generation
     * failed its continuous health observation. The next replay must stop the
     * platform, re-prove quiescence and explicitly authorize a fresh start;
     * repeatedly observing the same failed process is never a terminal state.
     *
     * @return array<string,mixed>
     */
    public function retryRebootstrapRollbackObservation(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use (
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                $profile = \strtolower(\trim($profile));
                return $this->withInstallLock(function () use (
                    $nonce,
                    $packageDigest,
                    $profile,
                    $reason,
                ): array {
                    $journal = $this->requiredRebootstrapJournalLocked(
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    $phase = (string)$journal['phase'];
                    if (\hash_equals('ROLLING_BACK', $phase)) {
                        return $this->publicRebootstrapJournal($journal);
                    }
                    if (!\hash_equals('ROLLBACK_OBSERVING', $phase)
                        || !$this->rebootstrapTransitionAllowed(
                            $phase,
                            'ROLLING_BACK',
                        )
                    ) {
                        throw new \RuntimeException(
                            'Gateway rollback observation cannot be retried from phase '
                                . $phase . '.',
                        );
                    }
                    $journal['phase'] = 'ROLLING_BACK';
                    $journal['failure_reason'] = GatewayBoundedText::singleLine(
                        $reason,
                        2048,
                        'Gateway rollback health observation failed.',
                    );
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    $this->injectRebootstrapCrashAfterPhase('ROLLING_BACK');
                    return $this->publicRebootstrapJournal($journal);
                });
            },
        );
    }

    /**
     * Restore the complete old launcher generation while the platform service
     * is persistently stopped. Callers must first durably enter ROLLING_BACK
     * with beginRebootstrapRollback(), then prove quiescence.
     *
     * @return array<string,mixed>
     */
    public function rollbackRebootstrapGeneration(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->rollbackRebootstrapGenerationWithinDeadline(
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function rollbackRebootstrapGenerationWithinDeadline(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $profile = \strtolower(\trim($profile));
        return $this->withStagingLocks(['A', 'B'], function () use (
            $nonce,
            $packageDigest,
            $profile,
            $reason,
        ): array {
            return $this->withInstallLock(function () use (
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ): array {
                $journal = $this->requiredRebootstrapJournalLocked(
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                if (\hash_equals('COMMITTED', (string)$journal['phase'])) {
                    throw new \RuntimeException(
                        'A committed gateway rebootstrap cannot be rolled back by the maintenance transaction.'
                    );
                }
                if (\in_array((string)$journal['phase'], [
                    'ROLLBACK_START_AUTHORIZED',
                    'ROLLBACK_OBSERVING',
                    'ROLLED_BACK',
                ], true)) {
                    $this->assertRebootstrapDerivedWorkingGenerationAbsent(
                        $journal,
                    );
                    $current = $this->verifiedRebootstrapOldGeneration();
                    $this->assertOldGenerationMatchesRebootstrapJournal(
                        $journal,
                        $current,
                    );
                    return $this->publicRebootstrapJournal($journal);
                }
                if (!\hash_equals('ROLLING_BACK', (string)$journal['phase'])) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap generation restore requires a durable ROLLING_BACK phase.',
                    );
                }
                if ((string)$journal['admin_stopped_digest'] !== '') {
                    $this->assertRebootstrapCapacityReleasedLocked($journal);
                }
                $this->restoreOldRebootstrapGeneration($journal);
                return $this->publicRebootstrapJournal($journal);
            });
        });
    }

    /**
     * Cancel a prepared transaction before ADMIN_STOPPED was committed. The
     * old gateway may still be serving, so only the isolated candidate is
     * inspected and removed.
     *
     * @return array<string,mixed>
     */
    public function cancelPreparedRebootstrap(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->cancelPreparedRebootstrapAfterCapacityRelease(
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function cancelPreparedRebootstrapAfterCapacityRelease(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
    ): array {
        $current = $this->rebootstrapStatus(
            $nonce,
            $this->activeOperationDeadline(),
        );
        if (\is_array($current)
            && !\in_array(
                (string)($current['capacity_reserve_state'] ?? ''),
                ['NONE', 'RELEASED'],
                true,
            )
        ) {
            $this->releaseRebootstrapCapacityReserve(
                $nonce,
                $packageDigest,
                $profile,
                'cancel',
                $this->activeOperationDeadline(),
            );
        }
        return $this->cancelPreparedRebootstrapWithinDeadline(
            $nonce,
            $packageDigest,
            $profile,
            $reason,
        );
    }

    /** @return array<string,mixed> */
    private function cancelPreparedRebootstrapWithinDeadline(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $reason,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $profile = \strtolower(\trim($profile));
        return $this->withStagingLocks(['A', 'B'], function () use (
            $nonce,
            $packageDigest,
            $profile,
            $reason,
        ): array {
            return $this->withInstallLock(function () use (
                $nonce,
                $packageDigest,
                $profile,
                $reason,
            ): array {
                $journal = $this->requiredRebootstrapJournalLocked(
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                if (!\hash_equals('PREPARED', (string)$journal['phase'])
                    && !\hash_equals('ROLLING_BACK', (string)$journal['phase'])
                ) {
                    throw new \RuntimeException(
                        'Only a pre-stop prepared gateway rebootstrap can be cancelled without quiescing the old generation.'
                    );
                }
                $this->assertPreStopCancellationEvidenceLocked(
                    $journal,
                    false,
                );
                $this->assertPreStopCancellationTopologyLocked($journal);
                $candidate = $this->paths->rebootstrapCandidateDir($nonce);
                if (\file_exists($candidate) || \is_link($candidate)) {
                    $this->assertNoLiveProcessesForRuntimePaths(
                        [$candidate],
                        'pre-stop gateway rebootstrap cancellation',
                    );
                    $this->assertRebootstrapRuntimeDirectory(
                        $candidate,
                        (string)$journal['runtime_generation'],
                        'cancelled gateway rebootstrap candidate',
                    );
                    $quarantine = $this->paths
                        ->rebootstrapRollbackNewGenerationDir($nonce)
                        . DIRECTORY_SEPARATOR . 'candidate';
                    $this->ensurePrivateRebootstrapDirectory(
                        $this->paths->rebootstrapBackupDir($nonce),
                    );
                    $this->ensurePrivateRebootstrapDirectory(
                        \dirname($quarantine),
                    );
                    $this->isolateRebootstrapNewRuntimeDirectory(
                        $candidate,
                        $quarantine,
                        (string)$journal['runtime_generation'],
                        'cancelled gateway rebootstrap candidate',
                    );
                }
                $current = $this->verifiedRebootstrapOldGeneration();
                $this->assertOldGenerationMatchesRebootstrapJournal(
                    $journal,
                    $current,
                );
                $journal['phase'] = 'ROLLING_BACK';
                $journal['failure_reason'] = GatewayBoundedText::singleLine(
                    $reason,
                    2048,
                    'Gateway rebootstrap cancelled before stop.',
                );
                $journal = $this->writeRebootstrapJournalLocked($journal);
                $this->injectRebootstrapCrashAfterPhase('ROLLING_BACK');
                return $this->publicRebootstrapJournal($journal);
            });
        });
    }

    /**
     * A ROLLING_BACK phase is shared by the pre-stop cancellation path and
     * the post-stop whole-generation rollback path. Public cancellation APIs
     * must therefore prove the complete negative ADMIN_STOPPED/mutation
     * closure instead of treating the phase name as sufficient authority.
     *
     * @param array<string,mixed> $journal
     */
    private function assertPreStopCancellationEvidenceLocked(
        array $journal,
        bool $terminal,
    ): void {
        $phase = (string)$journal['phase'];
        $allowedPhases = $terminal
            ? ['ROLLED_BACK']
            : ['PREPARED', 'ROLLING_BACK'];
        foreach ([
            'admin_stopped_digest',
            'admin_stopped_contents_b64',
            'gateway_epoch',
            'old_gateway_epoch',
            'new_gateway_epoch',
            'derived_policy_sha256',
            'old_derived_manifest_sha256',
        ] as $field) {
            if ((string)$journal[$field] !== '') {
                throw new \RuntimeException(
                    'Gateway pre-stop cancellation found post-stop or generation-mutation evidence: '
                        . $field . '.',
                );
            }
        }
        if (!\in_array($phase, $allowedPhases, true)
            || (int)$journal['retention_until'] !== 0
            || (string)$journal['retention_host_boot_id'] !== ''
            || (int)$journal['retained_monotonic_ms'] !== 0
            || (int)$journal['retention_deadline_monotonic_ms'] !== 0
            || (!$terminal
                && ((string)$journal['retained_backup_state'] !== 'NONE'
                    || (string)$journal['backup_collection_nonce'] !== ''
                    || (string)$journal['backup_collection_device'] !== ''
                    || (string)$journal['backup_collection_inode'] !== ''
                    || (string)$journal['capacity_evidence_state'] !== 'NONE'
                    || (int)$journal['terminal_at'] !== 0))
            || ($terminal
                && (!\in_array(
                        (string)$journal['retained_backup_state'],
                        ['RETAINED', 'COLLECTED'],
                        true,
                    )
                    || (int)$journal['terminal_at'] < (int)$journal['created_at']))
        ) {
            throw new \RuntimeException(
                'Gateway pre-stop cancellation evidence is inconsistent with its lifecycle.',
            );
        }
        $capacityState = (string)$journal['capacity_reserve_state'];
        if (\hash_equals('NONE', $capacityState)) {
            if ((string)$journal['capacity_reserve_volume_id'] !== ''
                || (string)$journal['capacity_reserve_manifest_sha256'] !== ''
                || (string)$journal['capacity_reserve_release_sha256'] !== ''
                || (string)$journal['capacity_reserve_release_reason'] !== ''
            ) {
                throw new \RuntimeException(
                    'Gateway pre-stop cancellation has unbound capacity evidence.',
                );
            }
            return;
        }
        if (!\hash_equals('RELEASED', $capacityState)
            || !\hash_equals(
                'cancel',
                (string)$journal['capacity_reserve_release_reason'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway pre-stop cancellation requires NONE capacity or a cancel-bound RELEASED reserve.',
            );
        }
        if (!$terminal) {
            $this->assertRebootstrapCapacityReleasedLocked($journal);
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertPreStopCancellationTopologyLocked(
        array $journal,
    ): void {
        $nonce = (string)$journal['nonce'];
        $candidate = $this->paths->rebootstrapCandidateDir($nonce);
        $quarantineRoot = $this->paths
            ->rebootstrapRollbackNewGenerationDir($nonce);
        $quarantinedCandidate = $quarantineRoot
            . DIRECTORY_SEPARATOR . 'candidate';
        $candidateExists = \file_exists($candidate) || \is_link($candidate);
        $quarantinedExists = \file_exists($quarantinedCandidate)
            || \is_link($quarantinedCandidate);
        if ($candidateExists === $quarantinedExists
            || (\hash_equals('ROLLING_BACK', (string)$journal['phase'])
                && $candidateExists)
        ) {
            throw new \RuntimeException(
                'Gateway pre-stop cancellation candidate topology is ambiguous.',
            );
        }
        $runtime = $candidateExists ? $candidate : $quarantinedCandidate;
        $this->assertRebootstrapRuntimeDirectory(
            $runtime,
            (string)$journal['runtime_generation'],
            'pre-stop cancellation candidate',
        );

        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $expected = [];
        if ($journal['platform_snapshot'] !== null) {
            $expected[] = 'platform';
        }
        if ($quarantinedExists) {
            $expected[] = 'new-generation';
        }
        if ($expected === []) {
            if (\file_exists($backup) || \is_link($backup)) {
                throw new \RuntimeException(
                    'Gateway pre-stop cancellation found an unbound generation backup.',
                );
            }
        } else {
            $this->assertExactRebootstrapDirectoryEntries(
                $backup,
                $expected,
                'Gateway pre-stop cancellation backup root',
            );
        }
        if ($journal['platform_snapshot'] !== null) {
            $this->assertExactRebootstrapDirectoryEntries(
                $backup . DIRECTORY_SEPARATOR . 'platform',
                ['definition.before', 'metadata.before'],
                'Gateway pre-stop cancellation platform backup',
            );
            $this->assertRebootstrapPlatformBackup($journal, $backup);
        }
        if ($quarantinedExists) {
            $this->assertExactRebootstrapDirectoryEntries(
                $quarantineRoot,
                ['candidate'],
                'Gateway pre-stop cancellation runtime quarantine',
            );
        }
        $authorization = $this->paths->rebootstrapStartAuthorizationFile();
        if (\file_exists($authorization) || \is_link($authorization)) {
            throw new \RuntimeException(
                'Gateway pre-stop cancellation found a published start authorization.',
            );
        }
    }

    public function rebootstrapAdminStoppedIntent(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): string {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): string => $this->rebootstrapAdminStoppedIntentWithinDeadline(
                $nonce,
                $packageDigest,
                $profile,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function assertRebootstrapPreStartClosure(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($nonce, $packageDigest, $profile): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                return $this->withInstallLock(function () use (
                    $nonce,
                    $packageDigest,
                    $profile,
                ): array {
                    $journal = $this->requiredRebootstrapJournalLocked(
                        $nonce,
                        $packageDigest,
                        \strtolower(\trim($profile)),
                    );
                    if (!\hash_equals(
                        'START_AUTHORIZED',
                        (string)$journal['phase'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap pre-start proof requires START_AUTHORIZED.',
                        );
                    }
                    $this->assertPublishedRebootstrapGeneration($journal);
                    $this->assertRetainedRebootstrapBackup(
                        $journal,
                        $this->paths->rebootstrapBackupDir($nonce),
                    );
                    $this->assertRetainedRebootstrapDerivedBackup(
                        $journal,
                        $this->paths->rebootstrapBackupDir($nonce),
                        self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                    );
                    $this->assertRebootstrapStartAuthorizationLocked($journal);
                    if ((bool)$journal['trust_rotation']) {
                        $this->assertRebootstrapDerivedLiveNamespaceEmpty(
                            $journal,
                            'authorized gateway trust-generation start',
                            ['trust/ca-bundle.sha256'],
                        );
                    }
                    return $this->publicRebootstrapJournal($journal);
                });
            },
        );
    }

    /** @return array<string,mixed> */
    public function assertRebootstrapRollbackPreStartClosure(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($nonce, $packageDigest, $profile): array {
                $nonce = $this->normalizeRebootstrapNonce($nonce);
                return $this->withInstallLock(function () use (
                    $nonce,
                    $packageDigest,
                    $profile,
                ): array {
                    $journal = $this->requiredRebootstrapJournalLocked(
                        $nonce,
                        $packageDigest,
                        \strtolower(\trim($profile)),
                    );
                    if (!\hash_equals(
                        'ROLLBACK_START_AUTHORIZED',
                        (string)$journal['phase'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway rollback pre-start proof requires ROLLBACK_START_AUTHORIZED.',
                        );
                    }
                    $current = $this->verifiedRebootstrapOldGeneration();
                    $this->assertOldGenerationMatchesRebootstrapJournal(
                        $journal,
                        $current,
                    );
                    $this->assertRebootstrapPlatformBackup(
                        $journal,
                        $this->paths->rebootstrapBackupDir($nonce),
                    );
                    $this->assertRebootstrapStartAuthorizationLocked($journal);
                    $this->assertRebootstrapDerivedWorkingGenerationAbsent(
                        $journal,
                    );
                    $this->assertRetainedRebootstrapDerivedBackup(
                        $journal,
                        $this->paths->rebootstrapBackupDir($nonce),
                        self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                        true,
                    );
                    $this->assertRebootstrapRestoredOldDerivedLiveInventory(
                        $journal,
                        'authorized gateway rollback old derived generation',
                    );
                    return $this->publicRebootstrapJournal($journal);
                });
            },
        );
    }

    private function rebootstrapAdminStoppedIntentWithinDeadline(
        string $nonce,
        string $packageDigest,
        string $profile,
    ): string {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        return $this->withInstallLock(function () use (
            $nonce,
            $packageDigest,
            $profile,
        ): string {
            $journal = $this->requiredRebootstrapJournalLocked(
                $nonce,
                $packageDigest,
                \strtolower(\trim($profile)),
            );
            if (\hash_equals(
                'START_AUTHORIZED',
                (string)$journal['phase'],
            )) {
                $this->assertPublishedRebootstrapGeneration($journal);
                $this->assertRetainedRebootstrapBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                );
                $this->assertRetainedRebootstrapDerivedBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                    self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                );
            }
            $encoded = (string)$journal['admin_stopped_contents_b64'];
            $contents = $encoded === '' ? false : \base64_decode($encoded, true);
            if (!\is_string($contents)
                || !\hash_equals(
                    (string)$journal['admin_stopped_digest'],
                    \hash('sha256', $contents),
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ADMIN_STOPPED evidence is unavailable.'
                );
            }
            return $contents;
        });
    }

    /** @return array<string,mixed> */
    public function completeRebootstrapRollback(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->finishRebootstrapTransaction(
                $nonce,
                $packageDigest,
                $profile,
                'ROLLBACK_OBSERVING',
                'ROLLED_BACK',
                false,
            ),
        );
    }

    /**
     * Finish the explicit pre-stop cancellation path. This path is valid only
     * when no ADMIN_STOPPED evidence was ever committed and the old gateway
     * generation therefore never left service.
     *
     * @return array<string,mixed>
     */
    public function completePreparedRebootstrapCancellation(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->finishRebootstrapTransaction(
                $nonce,
                $packageDigest,
                $profile,
                'ROLLING_BACK',
                'ROLLED_BACK',
                false,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function commitRebootstrap(
        string $nonce,
        string $packageDigest,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->finishRebootstrapTransaction(
                $nonce,
                $packageDigest,
                $profile,
                'OBSERVING',
                'COMMITTED',
                true,
            ),
        );
    }

    public function activate(
        string $slot,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($slot): void {
                $this->activateWithinDeadline($slot);
            },
        );
    }

    private function activateWithinDeadline(string $slot): void
    {
        $slot = \strtoupper(\trim($slot));
        $this->withStagingLock($slot, function () use ($slot): null {
            $verification = $this->artifact->verify($this->paths->slotDir($slot), 'host_gateway');
            if (!($verification['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway slot cannot be activated: ' . (string)($verification['reason'] ?? 'invalid')
                );
            }
            $manifest = $this->verifiedSlotDurableStateManifest(
                $slot,
                'Gateway activation',
            );
            if (!$this->paths->isTestMode() && !($manifest['release_ready'] ?? false)) {
                throw new \RuntimeException('A non-release-ready gateway slot cannot become active.');
            }
            if ($this->paths->isTestMode() && !($manifest['test_mode'] ?? false)) {
                throw new \RuntimeException('Test gateway cannot activate a production slot.');
            }
            $this->withInstallLock(function () use ($slot): null {
                $this->assertNoRebootstrapTransactionLocked(
                    'Gateway slot activation',
                );
                $this->assertNoFailedInitialBootstrapCleanup();
                $slotProof = $this->verifiedStableLauncherSlotProof(
                    $slot,
                    'Gateway activation commit',
                );
                if (\file_exists($this->paths->upgradeIntentFile())
                    || \is_link($this->paths->upgradeIntentFile())
                ) {
                    throw new \RuntimeException(
                        'A signed gateway upgrade transaction already owns slot activation.'
                    );
                }
                $active = $this->activeSlotOrEmpty();
                if ($active !== '' && !\hash_equals($active, $slot)) {
                    throw new \RuntimeException(
                        'An existing gateway must switch A/B slots through the signed upgrade activation transaction.'
                    );
                }
                if (\hash_equals($active, $slot)) {
                    $baseline = $this->trustBundleBaselineProof(
                        'Gateway active CA trust baseline',
                    );
                    if (!\hash_equals(
                        (string)$slotProof['ca_bundle_sha256'],
                        (string)$baseline['sha256'],
                    )) {
                        throw new \RuntimeException(
                            'Gateway active slot differs from the host CA trust baseline.',
                        );
                    }
                    return null;
                }
                // The first activation changes two host trust facts: the CA
                // baseline and active-slot. Publish a durable cleanup/commit
                // intent before either one so a power loss cannot strand an
                // unowned baseline that blocks every later installation.
                $this->prepareFailedInitialBootstrapCleanup(
                    $slot,
                    'activate',
                    $slotProof,
                );
                $this->ensureTrustBundleBaselineLocked(
                    (string)$slotProof['ca_bundle_sha256'],
                );
                $activationFence = $this->firstActivationSlotFence($slot);
                // First activation has no rollback slot. Publish only the one
                // authoritative pointer: Controller startup deliberately
                // derives the opposite slot when previous-slot is absent.
                // Writing a synthetic previous pointer first creates a power-
                // loss window where the platform service is installed but no
                // active slot can ever be launched or restaged automatically.
                try {
                    $this->atomicWrite(
                        $this->paths->activeSlotFile(),
                        $slot . "\n",
                        0640,
                    );
                } catch (\Throwable $throwable) {
                    // rename/ReplaceFileW is the commit point. A parent-
                    // directory fsync error can therefore arrive after the
                    // exact active pointer is already visible. Reconcile only
                    // that exact pointer plus the unchanged immutable slot and
                    // both manifest identities while both package locks remain
                    // held. Any competing or partial after-image still fails.
                    if (!$this->firstActivationAfterImageMatches(
                        $slot,
                        $activationFence,
                    )) {
                        throw $throwable;
                    }
                }
                $this->commitFirstActivationIntentLocked($slot, $slotProof);
                return null;
            });
            return null;
        });
    }

    /**
     * Persist a signed activation intent before switching the root-owned
     * active-slot pointer. The Broker starts the full five-minute monotonic
     * observation window only after the candidate is ready. A crash between
     * the two root writes leaves the old slot active; a crash after the pointer
     * write is reconciled by the stable launcher.
     *
     * @param array<string,mixed> $staged
     * @return array<string,mixed>
     */
    public function beginUpgradeActivation(
        array $staged,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->beginUpgradeActivationWithinDeadline($staged),
        );
    }

    /** @param array<string,mixed> $staged */
    private function beginUpgradeActivationWithinDeadline(array $staged): array
    {
        $to = \strtoupper(\trim((string)($staged['slot'] ?? '')));
        return $this->withStagingLock($to, function () use ($staged, $to): array {
            $from = \strtoupper(\trim((string)(
                $staged['previous_active_slot'] ?? ''
            )));
            $runtimeGeneration = \strtolower(\trim((string)(
                $staged['runtime_generation'] ?? ''
            )));
            if (!\in_array($from, ['A', 'B'], true)
                || !\in_array($to, ['A', 'B'], true)
                || $from === $to
                || !\hash_equals($from, $this->paths->activeSlot())
                || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade activation fence does not match the staged A/B slot.'
                );
            }
            try {
                $verification = $this->artifact->verify(
                    $this->paths->slotDir($to),
                    'host_gateway',
                );
                if (!($verification['ok'] ?? false)
                    || !\hash_equals(
                        $runtimeGeneration,
                        (string)($verification['runtime_generation'] ?? ''),
                    )
                ) {
                    throw new \RuntimeException(
                        'Gateway staged slot changed before upgrade activation.'
                    );
                }
                $this->verifiedSlotDurableStateManifest(
                    $from,
                    'Gateway A/B upgrade previous',
                );
                $this->verifiedSlotDurableStateManifest(
                    $to,
                    'Gateway A/B upgrade candidate',
                );
                $this->assertInstalledTrustBundlesMatch($from, $to);
            } catch (\Throwable $throwable) {
                throw $this->stableLauncherRebootstrapRequired(
                    'Gateway A/B upgrade launcher prerequisite',
                    $throwable,
                );
            }
            $secret = \strtolower(\trim($this->readStableRegularFile(
                $this->paths->adminTokenFile(),
                65,
                'Gateway administrator credential',
            )));
            $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
                ? \hex2bin($secret)
                : false;
            if (!\is_string($key) || \strlen($key) !== 32) {
                throw new \RuntimeException(
                    'Gateway administrator credential cannot sign the upgrade intent.'
                );
            }
            // Wall time is retained only for diagnostics. Every component that
            // decides whether this transaction may activate or roll back is
            // bound to the same host boot and monotonic interval below.
            $preparedAt = $this->wallClockNow();
            if ($preparedAt <= 0
                || $preparedAt
                    > PHP_INT_MAX - self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade activation time is outside the supported range.'
                );
            }
            $activationDeadline = $preparedAt
                + self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS;
            $preparedMonotonic = $this->monotonicClockMillisecondsNow();
            if ($preparedMonotonic <= 0
                || $preparedMonotonic
                    > PHP_INT_MAX - self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade monotonic activation time is outside the supported range.'
                );
            }
            $hostBootId = $this->hostBootIdentityNow();
            $intentNonce = \bin2hex(\random_bytes(16));
            $activationDeadlineMonotonic = $preparedMonotonic
                + self::UPGRADE_ACTIVATION_TIMEOUT_MILLISECONDS;
            $rollbackDeadlineMonotonic = $preparedMonotonic
                + self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS;
            $payload = "WLS-UPGRADE/2\n"
                . 'host_id=' . $this->hostId() . "\n"
                . 'from=' . $from . "\n"
                . 'to=' . $to . "\n"
                . 'prepared_at=' . $preparedAt . "\n"
                . 'deadline=' . $activationDeadline . "\n"
                . 'runtime_generation=' . $runtimeGeneration . "\n"
                . 'host_boot_id=' . $hostBootId . "\n"
                . 'prepared_monotonic_ms=' . $preparedMonotonic . "\n"
                . 'activation_deadline_monotonic_ms='
                    . $activationDeadlineMonotonic . "\n"
                . 'rollback_deadline_monotonic_ms='
                    . $rollbackDeadlineMonotonic . "\n"
                . 'nonce=' . $intentNonce . "\n";
            try {
                $signature = \hash_hmac('sha256', $payload, $key);
                $intent = $payload . 'signature=' . $signature . "\n";
                $rollbackContext = $this->signAutomaticRollbackContext(
                    $intent,
                    $intentNonce,
                    $from,
                    $to,
                    $runtimeGeneration,
                    $hostBootId,
                    $preparedMonotonic,
                    $key,
                );
            } finally {
                \sodium_memzero($key);
            }
            return $this->withInstallLock(function () use (
                $from,
                $to,
                $runtimeGeneration,
                $preparedAt,
                $activationDeadline,
                $hostBootId,
                $preparedMonotonic,
                $activationDeadlineMonotonic,
                $rollbackDeadlineMonotonic,
                $intent,
                $rollbackContext,
            ): array {
                $this->assertNoFailedInitialBootstrapCleanup();
                if (!\hash_equals($from, $this->paths->activeSlot())) {
                    throw new \RuntimeException(
                        'Gateway active slot changed before upgrade pointer transaction.'
                    );
                }
                $this->assertNoLiveUpgradeTransactionBeforeIntentLocked(
                    $from,
                    $to,
                    'Gateway A/B upgrade activation',
                );
                $intentBinding = $this->upgradeIntentBinding($intent);
                $launcherProof = $this->verifiedStableLauncherUpgradeProof(
                    [$from, $to],
                    'Gateway A/B upgrade activation',
                    $intentBinding,
                );
                try {
                    $this->removeTerminalOrphanUpgradeState();
                } catch (\Throwable $throwable) {
                    throw $this->stableLauncherRebootstrapRequired(
                        'Gateway A/B upgrade activation transaction cleanup',
                        $throwable,
                    );
                }
                $this->atomicWrite($this->paths->upgradeIntentFile(), $intent, 0600);
                try {
                    $this->atomicWrite(
                        $this->paths->previousSlotFile(),
                        $from . "\n",
                        0640,
                    );
                    $this->atomicWrite(
                        $this->paths->activeSlotFile(),
                        $to . "\n",
                        0640,
                    );
                } catch (\Throwable $throwable) {
                    try {
                        $this->atomicWrite(
                            $this->paths->activeSlotFile(),
                            $from . "\n",
                            0640,
                        );
                    } catch (\Throwable) {
                    }
                    // The signed intent is the only durable recovery journal for
                    // a crash after active-slot publication. Never delete it from
                    // an exception path: the stable launcher must finish either
                    // rollback or commit idempotently on the next start.
                    throw $throwable;
                }
                $launcherProofAfter = $this->verifiedStableLauncherUpgradeProof(
                    [$from, $to],
                    'Gateway A/B upgrade activation after publication',
                    $intentBinding,
                );
                $this->assertStableLauncherUpgradeProofUnchanged(
                    $launcherProof,
                    $launcherProofAfter,
                    'Gateway A/B upgrade activation publication',
                );
                return [
                    'from' => $from,
                    'to' => $to,
                    'runtime_generation' => $runtimeGeneration,
                    'prepared_at' => $preparedAt,
                    'deadline' => $activationDeadline,
                    'host_boot_id' => $hostBootId,
                    'prepared_monotonic_ms' => $preparedMonotonic,
                    'activation_deadline_monotonic_ms'
                        => $activationDeadlineMonotonic,
                    'rollback_deadline_monotonic_ms'
                        => $rollbackDeadlineMonotonic,
                    'activation_timeout_seconds' => self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS,
                    'observation_seconds' => 300,
                    'rollback_context' => $rollbackContext,
                ];
            });
        });
    }

    /** @param array<string,mixed> $automaticRollbackContext */
    public function rollbackUpgradeActivation(
        string $failedSlot,
        string $previousSlot,
        array $automaticRollbackContext,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use (
                $failedSlot,
                $previousSlot,
                $automaticRollbackContext,
            ): void {
                $this->rollbackUpgradeActivationWithinDeadline(
                    $failedSlot,
                    $previousSlot,
                    $automaticRollbackContext,
                );
            },
        );
    }

    /** @param array<string,mixed> $automaticRollbackContext */
    private function rollbackUpgradeActivationWithinDeadline(
        string $failedSlot,
        string $previousSlot,
        array $automaticRollbackContext,
    ): void {
        $failedSlot = \strtoupper(\trim($failedSlot));
        $previousSlot = \strtoupper(\trim($previousSlot));
        $this->withStagingLocks(
            [$failedSlot, $previousSlot],
            function () use (
                $failedSlot,
                $previousSlot,
                $automaticRollbackContext,
            ): null {
                $activeSlot = $this->paths->activeSlot();
                if (!\in_array($failedSlot, ['A', 'B'], true)
                    || !\in_array($previousSlot, ['A', 'B'], true)
                    || $failedSlot === $previousSlot
                    || (!\hash_equals($failedSlot, $activeSlot)
                        && !\hash_equals($previousSlot, $activeSlot))
                ) {
                    throw new \RuntimeException(
                        'Gateway upgrade rollback fence no longer matches the active slot.'
                    );
                }
                try {
                    $verification = $this->artifact->verify(
                        $this->paths->slotDir($previousSlot),
                        'host_gateway',
                    );
                    if (!($verification['ok'] ?? false)) {
                        throw new \RuntimeException(
                            'Gateway previous slot is not valid for upgrade rollback.'
                        );
                    }
                    $this->verifiedSlotDurableStateManifest(
                        $previousSlot,
                        'Gateway A/B upgrade rollback target',
                    );
                } catch (\Throwable $throwable) {
                    throw $this->stableLauncherRebootstrapRequired(
                        'Gateway A/B upgrade rollback launcher prerequisite',
                        $throwable,
                    );
                }
                $rollbackRequest = $this->paths->stateDir() . DIRECTORY_SEPARATOR
                    . 'upgrade-rollback.request';
                $intentFile = $this->paths->upgradeIntentFile();
                $intent = GatewayProjectStateFilesystem::readOptional(
                    $intentFile,
                    4096,
                    'gateway upgrade intent',
                );
                if ($intent === null) {
                    throw new \RuntimeException(
                        'Gateway upgrade rollback requires the signed activation intent.'
                    );
                }
                try {
                    $intentBinding = $this->upgradeIntentBinding($intent);
                } catch (\Throwable $throwable) {
                    throw $this->stableLauncherRebootstrapRequired(
                        'Gateway A/B upgrade rollback retained intent',
                        $throwable,
                    );
                }
                if (!\hash_equals($failedSlot, $intentBinding['to'])
                    || !\hash_equals($previousSlot, $intentBinding['from'])
                ) {
                    throw new \RuntimeException(
                        'Gateway upgrade rollback direction does not match the signed activation intent.'
                    );
                }
                $this->withInstallLock(function () use (
                    $failedSlot,
                    $previousSlot,
                    $intent,
                    $intentBinding,
                    $rollbackRequest,
                    $automaticRollbackContext,
                ): null {
                    $this->assertNoFailedInitialBootstrapCleanup();
                    $activeSlot = $this->paths->activeSlot();
                    $currentIntent = GatewayProjectStateFilesystem::readOptional(
                        $this->paths->upgradeIntentFile(),
                        4096,
                        'gateway upgrade intent',
                    );
                    if ($currentIntent === null
                        || !\hash_equals(\hash('sha256', $intent), \hash('sha256', $currentIntent))
                        || (!\hash_equals($failedSlot, $activeSlot)
                            && !\hash_equals($previousSlot, $activeSlot))
                    ) {
                        throw new \RuntimeException(
                            'Gateway upgrade rollback transaction changed before commit.'
                        );
                    }
                    $launcherProof = $this->verifiedStableLauncherUpgradeProof(
                        [$failedSlot, $previousSlot],
                        'Gateway A/B upgrade rollback',
                        $intentBinding,
                    );
                    $requestedMonotonic = $this->validateAutomaticRollbackContext(
                        $automaticRollbackContext,
                        $intentBinding,
                        $failedSlot,
                        $previousSlot,
                    );
                    if (\hash_equals($failedSlot, $activeSlot)) {
                        $validateRequest = function (string $contents) use (
                            $intentBinding,
                            $failedSlot,
                            $previousSlot,
                        ): void {
                            $this->validateUpgradeRollbackRequest(
                                $contents,
                                $intentBinding,
                                $failedSlot,
                                $previousSlot,
                            );
                        };
                        // withInstallLock has already prevalidated and
                        // collected the complete host recovery closure. Keep
                        // the target-local guard here as the rollback action's
                        // explicit defense-in-depth contract; under the shared
                        // lock it is now either a no-op or rejects a path that
                        // changed outside the authoritative writer set.
                        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                            $rollbackRequest,
                            512,
                            'Gateway upgrade rollback request',
                            $validateRequest,
                        );
                        $currentRequest = GatewayProjectStateFilesystem::readOptional(
                            $rollbackRequest,
                            512,
                            'gateway upgrade rollback request',
                        );
                        if ($currentRequest !== null) {
                            $validateRequest($currentRequest);
                        } else {
                            $this->atomicWrite(
                                $rollbackRequest,
                                "WLS-UPGRADE-ROLLBACK/3\n"
                                    . 'intent_sha256=' . $intentBinding['digest'] . "\n"
                                    . 'intent_nonce=' . $intentBinding['nonce'] . "\n"
                                    . 'from=' . $failedSlot . "\n"
                                    . 'to=' . $previousSlot . "\n"
                                    . 'host_boot_id='
                                        . $intentBinding['host_boot_id'] . "\n"
                                    . 'requested_monotonic_ms='
                                        . $requestedMonotonic . "\n"
                                    . 'request_nonce=' . \bin2hex(\random_bytes(16)) . "\n",
                                0600,
                            );
                        }
                        $this->atomicWrite(
                            $this->paths->activeSlotFile(),
                            $previousSlot . "\n",
                            0640,
                        );
                    }
                    try {
                        $this->atomicWrite(
                            $this->paths->previousSlotFile(),
                            $failedSlot . "\n",
                            0640,
                        );
                    } catch (\Throwable) {
                        // The signed intent plus request remain authoritative.
                    }
                    $launcherProofAfter = $this->verifiedStableLauncherUpgradeProof(
                        [$failedSlot, $previousSlot],
                        'Gateway A/B upgrade rollback after publication',
                        $intentBinding,
                    );
                    $this->assertStableLauncherUpgradeProofUnchanged(
                        $launcherProof,
                        $launcherProofAfter,
                        'Gateway A/B upgrade rollback publication',
                    );
                    return null;
                });
                return null;
            },
        );
    }

    public function discardStaged(
        string $slot,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($slot): void {
                $this->discardStagedWithinDeadline($slot);
            },
        );
    }

    private function discardStagedWithinDeadline(string $slot): void
    {
        $slot = \strtoupper(\trim($slot));
        $this->withStagingLock($slot, function () use ($slot): null {
            if (!\in_array($slot, ['A', 'B'], true)) {
                throw new \InvalidArgumentException('Gateway slot must be A or B.');
            }
            // Finish an interrupted first-activation transaction before
            // deciding whether this slot is active or merely staged. If its
            // pointer committed, recovery preserves it and the active-slot
            // guard below refuses deletion. If it did not commit, recovery
            // removes the whole initial bootstrap idempotently.
            $this->recoverFailedInitialBootstrapCleanup($slot);
            if (!\file_exists($this->paths->slotDir($slot))
                && !\is_link($this->paths->slotDir($slot))
                && $this->activeSlotOrEmpty() === ''
            ) {
                return null;
            }
            $initialCleanup = $this->withInstallLock(function () use ($slot): bool {
                $this->assertNoFailedInitialBootstrapCleanup();
                $active = $this->activeSlotOrEmpty();
                $this->assertNoSlotDeletionRecoveryTransactionLocked(
                    $slot,
                    'Gateway staged-slot discard',
                );
                if ($active === $slot) {
                    throw new \RuntimeException(
                        'Refusing to discard the active gateway slot.'
                    );
                }
                if ($active === '') {
                    return true;
                }
                $inactive = $active === 'A' ? 'B' : 'A';
                if (!\hash_equals($inactive, $slot)) {
                    throw new \RuntimeException(
                        'Gateway staged-slot discard target is no longer the exact inactive slot.',
                    );
                }
                $clearRollbackMarker = $this->rolledBackMarkerMatchesSlotArtifact(
                    $slot,
                );
                $this->removeDiscardTargetLocked($slot, $active);
                $slotDirectory = $this->paths->slotDir($slot);
                if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                    throw new \RuntimeException(
                        'Discarded gateway slot tree could not be proven absent.',
                    );
                }
                if ($clearRollbackMarker) {
                    $this->clearInactiveSlotReplacementMarkers($slot);
                }
                return false;
            });
            if ($initialCleanup) {
                // A successful first stage has already installed the stable
                // bootstrap and its trust identity. If a later platform step
                // fails before activation, removing only the inactive slot
                // would leave a host-level half-installation whose launcher is
                // no longer backed by any installed runtime.
                $this->withInstallLock(function () use ($slot): null {
                    $this->assertNoFailedInitialBootstrapCleanup();
                    if ($this->activeSlotOrEmpty() !== '') {
                        throw new \RuntimeException(
                            'Gateway active slot changed before initial staged-slot cleanup.',
                        );
                    }
                    $this->assertNoSlotDeletionRecoveryTransactionLocked(
                        $slot,
                        'Gateway initial staged-slot discard',
                    );
                    $this->prepareFailedInitialBootstrapCleanup($slot, 'cleanup');
                    return null;
                });
                $this->recoverFailedInitialBootstrapCleanup($slot);
                return null;
            }
            return null;
        });
    }

    /** The caller must hold trust/package-install.lock. */
    private function assertNoLiveUpgradeTransactionBeforeIntentLocked(
        string $from,
        string $to,
        string $operation,
    ): void {
        try {
            $intent = $this->readOptionalStableRegularFile(
                $this->paths->upgradeIntentFile(),
                4096,
                'Existing gateway upgrade intent',
            );
            if ($intent !== null) {
                $binding = $this->upgradeIntentBinding($intent);
                $this->verifiedStableLauncherUpgradeProof(
                    [$binding['from'], $binding['to']],
                    $operation . ' existing transaction',
                    $binding,
                );
                throw new \RuntimeException(
                    'A proved live gateway upgrade transaction already owns A/B activation.',
                );
            }
            foreach ($this->nonTerminalUpgradeTransactionFiles() as $path => $label) {
                if (\file_exists($path) || \is_link($path)) {
                    throw $this->stableLauncherRebootstrapRequired(
                        $operation . ' found ambiguous ' . $label,
                    );
                }
            }
            if (!\in_array($from, ['A', 'B'], true)
                || !\in_array($to, ['A', 'B'], true)
                || \hash_equals($from, $to)
            ) {
                throw new \RuntimeException(
                    'Gateway A/B activation direction is no longer exact.',
                );
            }
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \RuntimeException
                && (\str_contains($throwable->getMessage(), 'already owns A/B activation')
                    || \str_contains(
                        $throwable->getMessage(),
                        'ordinary A/B activation is not permitted',
                    ))
            ) {
                throw $throwable;
            }
            throw $this->stableLauncherRebootstrapRequired($operation, $throwable);
        }
    }

    /** The caller must hold trust/package-install.lock. */
    private function assertNoSlotDeletionRecoveryTransactionLocked(
        string $slot,
        string $operation,
    ): void {
        try {
            $intent = $this->readOptionalStableRegularFile(
                $this->paths->upgradeIntentFile(),
                4096,
                'Gateway staged-slot discard upgrade intent',
            );
            if ($intent !== null) {
                $binding = $this->upgradeIntentBinding($intent);
                $this->verifiedStableLauncherUpgradeProof(
                    [$binding['from'], $binding['to']],
                    $operation . ' live transaction',
                    $binding,
                );
                throw new \RuntimeException(
                    'A live gateway upgrade transaction references slot ' . $slot
                        . ' and blocks deletion.',
                );
            }
            $files = $this->nonTerminalUpgradeTransactionFiles();
            $files[$this->paths->trustDir() . DIRECTORY_SEPARATOR . 'upgrade-state']
                = 'upgrade transaction state';
            foreach ($files as $path => $label) {
                if (\file_exists($path) || \is_link($path)) {
                    throw $this->stableLauncherRebootstrapRequired(
                        $operation . ' found ambiguous ' . $label,
                    );
                }
            }
            $retentionFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'slot-retention';
            $retention = $this->readOptionalStableRegularFile(
                $retentionFile,
                512,
                'Gateway staged-slot discard retention evidence',
            );
            if ($retention !== null) {
                $binding = $this->slotRetentionRecoveryBinding($retention);
                if (\hash_equals($slot, $binding['slot'])) {
                    throw new \RuntimeException(
                        'Gateway slot-retention evidence references slot ' . $slot
                            . ' and blocks deletion.',
                    );
                }
            }
            $rolledBackFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rolled-back';
            $rolledBack = $this->readOptionalStableRegularFile(
                $rolledBackFile,
                384,
                'Gateway staged-slot discard rollback evidence',
            );
            if ($rolledBack !== null) {
                if (\preg_match(
                    '/\AWLS-UPGRADE-ROLLED-BACK\/3\n'
                        . 'intent_sha256=[a-f0-9]{64}\n'
                        . 'intent_nonce=[a-f0-9]{32}\n'
                        . 'from=([AB])\nto=([AB])\n'
                        . 'runtime_generation=[a-f0-9]{64}\n'
                        . 'at=[0-9]+\n\z/D',
                    $rolledBack,
                    $matches,
                ) !== 1
                    || \hash_equals((string)$matches[1], (string)$matches[2])
                ) {
                    throw $this->stableLauncherRebootstrapRequired(
                        $operation . ' found ambiguous upgrade rollback evidence',
                    );
                }
                if (\hash_equals($slot, (string)$matches[1])
                    || \hash_equals($slot, (string)$matches[2])
                ) {
                    throw new \RuntimeException(
                        'Gateway rollback evidence references slot ' . $slot
                            . ' and blocks deletion.',
                    );
                }
            }
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \RuntimeException
                && (\str_contains($throwable->getMessage(), 'blocks deletion')
                    || \str_contains(
                        $throwable->getMessage(),
                        'ordinary A/B activation is not permitted',
                    ))
            ) {
                throw $throwable;
            }
            throw $this->stableLauncherRebootstrapRequired($operation, $throwable);
        }
    }

    /** @return array<string,string> */
    private function nonTerminalUpgradeTransactionFiles(): array
    {
        return [
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request' => 'upgrade rollback request',
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-observing' => 'upgrade observation marker',
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-healthy' => 'upgrade health marker',
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback-healthy' => 'upgrade rollback health marker',
        ];
    }

    /** The caller must hold trust/package-install.lock and the slot staging lock. */
    private function removeDiscardTargetLocked(string $slot, string $expectedActive): void
    {
        $directory = $this->paths->slotDir($slot);
        $before = @\lstat($directory);
        if (!\is_array($before)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Gateway staged-slot discard target is indeterminate or unsafe.',
                );
            }
            return;
        }
        if (\is_link($directory)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Gateway staged-slot discard target is linked or special.',
            );
        }
        $this->assertSlotHasNoLiveProcesses($slot, $directory);
        $entries = $this->collectRemovableTree($directory);

        // Process enumeration and bounded tree collection can be slow on a
        // loaded host. Re-read every authoritative fence after both and
        // immediately before the selected entries' first unlink, while the
        // global install lock and exact slot staging lock are still held.
        $active = $this->activeSlotOrEmpty();
        $inactive = $active === 'A' ? 'B' : ($active === 'B' ? 'A' : '');
        $this->assertNoFailedInitialBootstrapCleanup();
        $this->assertNoSlotDeletionRecoveryTransactionLocked(
            $slot,
            'Gateway staged-slot discard final fence',
        );
        $after = @\lstat($directory);
        if (!\hash_equals($expectedActive, $active)
            || !\hash_equals($slot, $inactive)
            || !\is_array($after)
            || !$this->sameFileState($before, $after)
        ) {
            throw new \RuntimeException(
                'Gateway staged-slot discard target changed before deletion.',
            );
        }
        $this->removeCollectedTree($directory, $entries);
    }

    public function rollbackActivation(
        string $failedSlot,
        string $previousSlot,
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($failedSlot, $previousSlot): void {
                $this->rollbackActivationWithinDeadline(
                    $failedSlot,
                    $previousSlot,
                );
            },
        );
    }

    private function rollbackActivationWithinDeadline(
        string $failedSlot,
        string $previousSlot,
    ): void {
        $failedSlot = \strtoupper(\trim($failedSlot));
        $previousSlot = \strtoupper(\trim($previousSlot));
        $slots = [$failedSlot];
        if (\in_array($previousSlot, ['A', 'B'], true)) {
            $slots[] = $previousSlot;
        }
        $this->withStagingLocks($slots, function () use ($failedSlot, $previousSlot): null {
            $activeFile = $this->paths->activeSlotFile();
            if (\in_array($previousSlot, ['A', 'B'], true)) {
                $verification = $this->artifact->verify(
                    $this->paths->slotDir($previousSlot),
                    'host_gateway',
                );
                if (!($verification['ok'] ?? false)) {
                    throw new \RuntimeException('Previous gateway slot is not valid for rollback.');
                }
                $this->verifiedSlotDurableStateManifest(
                    $previousSlot,
                    'Gateway installation rollback target',
                );
                $this->withInstallLock(function () use (
                    $activeFile,
                    $failedSlot,
                    $previousSlot,
                ): null {
                    $this->assertNoFailedInitialBootstrapCleanup();
                    if (!\hash_equals($failedSlot, $this->activeSlotOrEmpty())) {
                        throw new \RuntimeException(
                            'Gateway active slot changed during installation rollback.'
                        );
                    }
                    $this->verifiedSlotDurableStateManifest(
                        $previousSlot,
                        'Gateway installation rollback commit target',
                    );
                    $this->atomicWrite($activeFile, $previousSlot . "\n", 0640);
                    return null;
                });
            } else {
                // An activation call can throw after its durable pointer
                // committed but before it removed the first-activation intent.
                // Reconcile that transaction first; then create the distinct
                // cleanup intent used by this explicit rollback.
                $this->recoverFailedInitialBootstrapCleanup($failedSlot);
                if (!\file_exists($this->paths->slotDir($failedSlot))
                    && !\is_link($this->paths->slotDir($failedSlot))
                    && $this->activeSlotOrEmpty() === ''
                ) {
                    return null;
                }
                $this->withInstallLock(function () use ($failedSlot): null {
                    if (!\hash_equals($failedSlot, $this->activeSlotOrEmpty())) {
                        throw new \RuntimeException(
                            'Gateway active slot changed during installation rollback.'
                        );
                    }
                    $this->prepareFailedInitialBootstrapCleanup(
                        $failedSlot,
                        'cleanup',
                    );
                    GatewayProjectStateFilesystem::removeRegular(
                        $this->paths->activeSlotFile(),
                        'failed initial gateway activation rollback pointer',
                    );
                    return null;
                });
                $this->recoverFailedInitialBootstrapCleanup($failedSlot);
                return null;
            }
            $this->removeSlotTree($failedSlot);
            return null;
        });
    }

    /** @param array<string,mixed>|null $slotProof */
    private function prepareFailedInitialBootstrapCleanup(
        string $failedSlot,
        string $mode = 'cleanup',
        ?array $slotProof = null,
    ): void
    {
        $failedSlot = \strtoupper(\trim($failedSlot));
        $mode = \strtolower(\trim($mode));
        if (!\in_array($failedSlot, ['A', 'B'], true)
            || !\in_array($mode, ['activate', 'cleanup'], true)
        ) {
            throw new \InvalidArgumentException(
                'Failed initial gateway transaction arguments are invalid.',
            );
        }
        $slotDirectory = $this->paths->slotDir($failedSlot);
        $releaseManifestFile = $slotDirectory . DIRECTORY_SEPARATOR
            . 'release' . DIRECTORY_SEPARATOR . 'manifest.json';
        try {
            $releaseManifest = \json_decode(
                $this->readStableRegularFile(
                    $releaseManifestFile,
                    16_777_216,
                    'Installed gateway release manifest',
                ),
                true,
            );
        } catch (\Throwable) {
            $releaseManifest = null;
        }
        $launcherComponent = $this->componentPath('wls-gateway-launcher');
        $slotProof ??= $this->verifiedStableLauncherSlotProof(
            $failedSlot,
            'Failed initial gateway transaction',
        );
        $expected = \strtolower(\trim((string)(
            $slotProof['launcher_sha256'] ?? ''
        )));
        $caBundleDigest = \strtolower(\trim((string)(
            $slotProof['ca_bundle_sha256'] ?? ''
        )));
        $declaredLauncher = \is_array($releaseManifest)
            ? \strtolower(\trim((string)(
                $releaseManifest['components'][$launcherComponent]['sha256'] ?? ''
            )))
            : '';
        $declaredCaBundle = \is_array($releaseManifest)
            ? $this->releaseTrustBundleDigest(
                $releaseManifest,
                'Failed initial gateway CA trust baseline',
            )
            : '';
        $launcher = $this->paths->launcherFile();
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        try {
            $actual = $this->digestStableRegularFile(
                $launcher,
                self::MAX_PACKAGE_BYTES,
                'Stable gateway launcher',
            )['sha256'];
            $trusted = \strtolower(\trim($this->readStableRegularFile(
                $identity,
                65,
                'Stable gateway launcher identity',
            )));
        } catch (\Throwable) {
            $actual = '';
            $trusted = '';
        }
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $caBundleDigest) !== 1
            || !\hash_equals($expected, $declaredLauncher)
            || !\hash_equals($caBundleDigest, $declaredCaBundle)
            || !\hash_equals($expected, $actual)
            || !\hash_equals($expected, $trusted)
        ) {
            throw new \RuntimeException(
                'Failed initial gateway bootstrap identity cannot be safely removed.'
            );
        }
        $nonce = \bin2hex(\random_bytes(8));
        $intent = $this->failedInitialCleanupIntentFile();
        $existing = $this->readOptionalStableRegularFile(
            $intent,
            384,
            'Failed initial gateway transaction intent',
        );
        if ($existing !== null) {
            $binding = $this->failedInitialRecoveryBinding($existing);
            if (!\hash_equals($mode, (string)$binding['mode'])
                || !\hash_equals($failedSlot, (string)$binding['slot'])
                || !\hash_equals($expected, (string)$binding['launcher_sha256'])
                || !\hash_equals(
                    $caBundleDigest,
                    (string)$binding['ca_bundle_sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'A different failed initial gateway transaction is already pending.',
                );
            }
            return;
        }
        $this->atomicWrite(
            $intent,
            "WLS-FAILED-INITIAL-CLEANUP/2\n"
                . 'mode=' . $mode . "\n"
                . 'slot=' . $failedSlot . "\n"
                . 'launcher_sha256=' . $expected . "\n"
                . 'ca_bundle_sha256=' . $caBundleDigest . "\n"
                . 'nonce=' . $nonce . "\n",
            0600,
        );
    }

    /** @param array<string,mixed> $slotProof */
    private function commitFirstActivationIntentLocked(
        string $slot,
        array $slotProof,
    ): void {
        $intent = $this->failedInitialCleanupIntentFile();
        $contents = $this->readOptionalStableRegularFile(
            $intent,
            384,
            'First gateway activation transaction intent',
        );
        if ($contents === null) {
            throw new \RuntimeException(
                'First gateway activation transaction intent disappeared before commit.',
            );
        }
        $binding = $this->failedInitialRecoveryBinding($contents);
        $baseline = $this->trustBundleBaselineProof(
            'First gateway activation CA trust baseline',
        );
        if (!\hash_equals('activate', (string)$binding['mode'])
            || !\hash_equals($slot, (string)$binding['slot'])
            || !\hash_equals(
                (string)$slotProof['launcher_sha256'],
                (string)$binding['launcher_sha256'],
            )
            || !\hash_equals(
                (string)$slotProof['ca_bundle_sha256'],
                (string)$binding['ca_bundle_sha256'],
            )
            || !\hash_equals(
                (string)$baseline['sha256'],
                (string)$binding['ca_bundle_sha256'],
            )
            || !\hash_equals($slot, $this->activeSlotOrEmpty())
        ) {
            throw new \RuntimeException(
                'First gateway activation transaction after-image is inconsistent.',
            );
        }
        GatewayProjectStateFilesystem::removeRegular(
            $intent,
            'committed first gateway activation transaction intent',
        );
    }

    private function recoverFailedInitialBootstrapCleanup(?string $expectedSlot = null): void
    {
        $intent = $this->failedInitialCleanupIntentFile();
        $contents = $this->readOptionalStableRegularFile(
            $intent,
            384,
            'Failed initial gateway cleanup intent',
        );
        if ($contents === null) {
            return;
        }
        $binding = $this->failedInitialRecoveryBinding($contents);
        $mode = (string)$binding['mode'];
        $failedSlot = (string)$binding['slot'];
        $launcherDigest = (string)$binding['launcher_sha256'];
        $caBundleDigest = (string)$binding['ca_bundle_sha256'];
        $nonce = (string)$binding['nonce'];
        if ($caBundleDigest === ''
            && (\file_exists($this->paths->caBundleBaselineFile())
                || \is_link($this->paths->caBundleBaselineFile()))
        ) {
            // Version-1 cleanup intents predate the explicit CA digest. Bind a
            // migrated cleanup to the immutable slot and exact root-owned
            // baseline before deleting either; an unverifiable legacy state is
            // retained for administrator repair instead of guessed away.
            $legacyProof = $this->verifiedStableLauncherSlotProof(
                $failedSlot,
                'Legacy failed initial gateway transaction',
            );
            $legacyBaseline = $this->trustBundleBaselineProof(
                'Legacy failed initial gateway CA trust baseline',
            );
            if (!\hash_equals(
                    $launcherDigest,
                    (string)$legacyProof['launcher_sha256'],
                )
                || !\hash_equals(
                    (string)$legacyBaseline['sha256'],
                    (string)$legacyProof['ca_bundle_sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Legacy failed initial gateway transaction cannot be bound to the host CA trust baseline.',
                );
            }
            $caBundleDigest = (string)$legacyBaseline['sha256'];
        }
        $intentDigest = \hash('sha256', $contents);
        if ($expectedSlot !== null
            && !\hash_equals(\strtoupper(\trim($expectedSlot)), $failedSlot)
        ) {
            throw new \RuntimeException(
                'Failed initial gateway cleanup no longer matches its rollback slot.'
            );
        }

        // Snapshot the root-owned pointer under the global package lock, but
        // never hold it while enumerating all host processes. Every competing
        // pointer transaction is fenced by the still-present cleanup intent.
        $committed = $this->withInstallLock(function () use (
            $mode,
            $failedSlot,
            $launcherDigest,
            $caBundleDigest,
            $intent,
            $intentDigest,
        ): bool {
            $this->assertFailedInitialCleanupIntentDigest($intentDigest);
            $active = $this->activeSlotOrEmpty();
            if ($active !== '' && !\hash_equals($failedSlot, $active)) {
                throw new \RuntimeException(
                    'Failed initial gateway cleanup conflicts with a newer active slot.'
                );
            }
            if (!\hash_equals('activate', $mode)
                || !\hash_equals($failedSlot, $active)
            ) {
                return false;
            }
            $proof = $this->verifiedStableLauncherSlotProof(
                $failedSlot,
                'Recovered first gateway activation',
            );
            $baseline = $this->trustBundleBaselineProof(
                'Recovered first gateway activation CA trust baseline',
            );
            if (!\hash_equals(
                    $launcherDigest,
                    (string)$proof['launcher_sha256'],
                )
                || !\hash_equals(
                    $caBundleDigest,
                    (string)$proof['ca_bundle_sha256'],
                )
                || !\hash_equals(
                    $caBundleDigest,
                    (string)$baseline['sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Recovered first gateway activation closure is inconsistent.',
                );
            }
            GatewayProjectStateFilesystem::removeRegular(
                $intent,
                'recovered committed first gateway activation transaction',
            );
            return true;
        });
        if ($committed) {
            return;
        }
        $this->assertPlatformRemovalCompleted();
        // Prove the complete Broker/Controller/Nginx executable set has left
        // the failed slot before removing the active pointer, stable launcher
        // recovery anchor, or any immutable runtime bytes. Unknown ownership
        // retains the whole bootstrap for an administrator-assisted retry.
        $failedSlotDirectory = $this->paths->slotDir($failedSlot);
        if (\file_exists($failedSlotDirectory) || \is_link($failedSlotDirectory)) {
            $this->assertSlotHasNoLiveProcesses(
                $failedSlot,
                $failedSlotDirectory,
            );
        }
        $launcher = $this->paths->launcherFile();
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        $this->withInstallLock(function () use (
            $failedSlot,
            $launcherDigest,
            $nonce,
            $intentDigest,
            $launcher,
            $identity,
            $caBundleDigest,
        ): null {
            $this->assertFailedInitialCleanupIntentDigest($intentDigest);
            $active = $this->activeSlotOrEmpty();
            if ($active !== '' && !\hash_equals($failedSlot, $active)) {
                throw new \RuntimeException(
                    'Failed initial gateway cleanup conflicts with a newer active slot.'
                );
            }
            if ($active !== '') {
                GatewayProjectStateFilesystem::removeRegular(
                    $this->paths->activeSlotFile(),
                    'failed initial gateway activation',
                );
            }
            $this->isolateAndRemoveFailedInitialFile(
                $launcher,
                $launcher . '.failed-initial.' . $nonce,
                function (string $path) use ($launcherDigest): void {
                    $actual = $this->digestStableRegularFile(
                        $path,
                        self::MAX_PACKAGE_BYTES,
                        'Failed initial gateway launcher',
                    )['sha256'];
                    if (!\hash_equals($launcherDigest, $actual)) {
                        throw new \RuntimeException(
                            'Failed initial gateway launcher changed during cleanup recovery.'
                        );
                    }
                },
                'failed initial gateway launcher',
            );
            $this->isolateAndRemoveFailedInitialFile(
                $identity,
                $identity . '.failed-initial.' . $nonce,
                function (string $path) use ($launcherDigest): void {
                    $trusted = \strtolower(\trim($this->readStableRegularFile(
                        $path,
                        65,
                        'Failed initial gateway launcher identity',
                    )));
                    if (!\hash_equals($launcherDigest, $trusted)) {
                        throw new \RuntimeException(
                            'Failed initial gateway launcher identity changed during cleanup recovery.'
                        );
                    }
                },
                'failed initial gateway launcher identity',
            );
            if ($caBundleDigest !== '') {
                $baseline = $this->paths->caBundleBaselineFile();
                $this->isolateAndRemoveFailedInitialFile(
                    $baseline,
                    $baseline . '.failed-initial.' . $nonce,
                    function (string $path) use ($caBundleDigest): void {
                        $contents = $this->readStableRegularFile(
                            $path,
                            65,
                            'Failed initial gateway CA trust baseline',
                        );
                        if (!\hash_equals($caBundleDigest . "\n", $contents)) {
                            throw new \RuntimeException(
                                'Failed initial gateway CA trust baseline changed during cleanup recovery.',
                            );
                        }
                    },
                    'failed initial gateway CA trust baseline',
                );
            }
            if (\file_exists($this->paths->previousSlotFile())
                || \is_link($this->paths->previousSlotFile())
            ) {
                GatewayProjectStateFilesystem::removeRegular(
                    $this->paths->previousSlotFile(),
                    'failed initial gateway previous-slot state',
                );
            }
            return null;
        });

        $this->removeSlotTree($failedSlot);
        $this->withInstallLock(function () use (
            $failedSlot,
            $intent,
            $intentDigest,
        ): null {
            $this->assertFailedInitialCleanupIntentDigest($intentDigest);
            if ($this->activeSlotOrEmpty() !== ''
                || \file_exists($this->paths->slotDir($failedSlot))
                || \is_link($this->paths->slotDir($failedSlot))
                || \file_exists($this->paths->caBundleBaselineFile())
                || \is_link($this->paths->caBundleBaselineFile())
            ) {
                throw new \RuntimeException(
                    'Failed initial gateway cleanup cannot commit while runtime state remains.'
                );
            }
            GatewayProjectStateFilesystem::removeRegular(
                $intent,
                'completed failed initial gateway cleanup intent',
            );
            return null;
        });
    }

    private function assertNoFailedInitialBootstrapCleanup(): void
    {
        $intent = $this->failedInitialCleanupIntentFile();
        if (\file_exists($intent) || \is_link($intent)) {
            throw new \RuntimeException(
                'A failed initial gateway cleanup transaction blocks slot activation.'
            );
        }
    }

    private function assertFailedInitialCleanupIntentDigest(string $expectedDigest): void
    {
        $current = $this->readOptionalStableRegularFile(
            $this->failedInitialCleanupIntentFile(),
            384,
            'Failed initial gateway cleanup intent',
        );
        if ($current === null
            || !\hash_equals($expectedDigest, \hash('sha256', $current))
        ) {
            throw new \RuntimeException(
                'Failed initial gateway cleanup transaction changed during recovery.'
            );
        }
    }

    /** @param \Closure(string):void $verify */
    private function isolateAndRemoveFailedInitialFile(
        string $stable,
        string $isolated,
        \Closure $verify,
        string $label,
    ): void {
        $stableExists = \file_exists($stable) || \is_link($stable);
        $isolatedExists = \file_exists($isolated) || \is_link($isolated);
        if ($stableExists && $isolatedExists) {
            throw new \RuntimeException(
                'Failed initial gateway cleanup contains two competing ' . $label . ' files.'
            );
        }
        if ($stableExists) {
            $verify($stable);
            if (!@\rename($stable, $isolated)) {
                throw new \RuntimeException('Unable to isolate the ' . $label . '.');
            }
            $this->syncParentDirectory(\dirname($stable), $label . ' isolation');
            $isolatedExists = true;
        }
        if ($isolatedExists) {
            $verify($isolated);
            GatewayProjectStateFilesystem::removeRegular($isolated, $label);
        }
    }

    private function failedInitialCleanupIntentFile(): string
    {
        return $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'failed-initial-cleanup.intent';
    }

    /** @return array<string,mixed> */
    public function installedManifest(string $slot): array
    {
        $file = $this->paths->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json';
        $decoded = \json_decode($this->readStableRegularFile(
            $file,
            16_777_216,
            'Installed gateway slot manifest',
        ), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Installed gateway slot manifest is invalid.');
        }
        return $decoded;
    }

    private function assertExactDurableStateContract(
        mixed $contract,
        string $label,
    ): void {
        if (!\is_array($contract)
            || \array_is_list($contract)
            || \count($contract) !== \count(self::DURABLE_STATE_CONTRACT)
        ) {
            throw new \RuntimeException(
                $label . ' does not declare the exact WLS 2.0 durable-state contract v2.'
            );
        }
        foreach (self::DURABLE_STATE_CONTRACT as $field => $expected) {
            if (!\array_key_exists($field, $contract)
                || $contract[$field] !== $expected
            ) {
                throw new \RuntimeException(
                    $label . ' does not declare the exact WLS 2.0 durable-state contract v2.'
                );
            }
        }
    }

    /**
     * Prove the immutable installed artifact and its signed durable-state
     * contract before an A/B pointer can make this slot executable.
     *
     * @return array<string,mixed>
     */
    private function verifiedSlotDurableStateManifest(
        string $slot,
        string $operation,
    ): array {
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \RuntimeException(
                $operation . ' durable-state target is not an A/B host slot.'
            );
        }
        $verification = $this->artifact->verify(
            $this->paths->slotDir($slot),
            'host_gateway',
        );
        $runtimeGeneration = \strtolower(\trim((string)(
            $verification['runtime_generation'] ?? ''
        )));
        if (($verification['ok'] ?? false) !== true
            || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
        ) {
            throw new \RuntimeException(
                $operation . ' requires an immutable verified host-gateway slot.'
            );
        }
        $manifest = $this->installedManifest($slot);
        if ((int)($manifest['schema_version'] ?? 0)
                !== NginxRuntimeArtifact::SCHEMA_VERSION
            || !\hash_equals('host_gateway', (string)($manifest['role'] ?? ''))
            || !\hash_equals($slot, (string)($manifest['slot'] ?? ''))
            || !\hash_equals(
                $runtimeGeneration,
                \strtolower(\trim((string)(
                    $manifest['runtime_generation'] ?? ''
                ))),
            )
        ) {
            throw new \RuntimeException(
                $operation . ' installed host-slot manifest is not bound to its immutable artifact.'
            );
        }
        try {
            $this->assertExactDurableStateContract(
                $manifest['durable_state_contract'] ?? null,
                $operation . ' host slot ' . $slot,
            );
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                $exception->getMessage()
                    . ' Stop the gateway and perform an explicit full host rebootstrap '
                    . 'from a signed contract-v2 package; ordinary A/B activation is not permitted.',
                0,
                $exception,
            );
        }
        return $manifest;
    }

    /**
     * Bind one immutable A/B runtime generation to the exact stable-launcher
     * bytes whose signed release manifest declares rollback-target proof. The
     * capability is deliberately versionless only at the JSON key level: its
     * first production contract is exact `true`, while the immutable launcher
     * digest and slot runtime generation provide the concrete implementation
     * generation. WLS 2.0 v1 freezes that host bootstrap generation across an
     * ordinary A/B transaction; changing it requires a stopped, full host
     * rebootstrap and deliberately gives up the old slot as an automatic
     * rollback target. An old pre-release slot cannot acquire this authority
     * from a newer sibling slot.
     *
     * @return array{
     *   slot:string,
     *   runtime_generation:string,
     *   package_digest:string,
     *   launcher_sha256:string,
     *   launcher_size:int,
     *   launcher_mode:int,
     *   launcher_identity:array<string|int,mixed>,
     *   ca_bundle_sha256:string,
     *   ca_bundle_size:int,
     *   ca_bundle_mode:int,
     *   ca_bundle_identity:array<string|int,mixed>
     * }
     */
    private function verifiedStableLauncherSlotProof(
        string $slot,
        string $operation,
    ): array {
        try {
            $slot = \strtoupper(\trim($slot));
            $manifest = $this->verifiedSlotDurableStateManifest($slot, $operation);
            $slotDirectory = $this->paths->slotDir($slot);
            $runtimeGeneration = \strtolower(\trim((string)(
                $manifest['runtime_generation'] ?? ''
            )));
            $packageDigest = \strtolower(\trim((string)(
                $manifest['package_digest'] ?? ''
            )));
            $launcherComponent = $this->componentPath('wls-gateway-launcher');
            $installedLauncher = \is_array(
                $manifest['components'][$launcherComponent] ?? null,
            ) ? $manifest['components'][$launcherComponent] : [];
            $installedRelease = \is_array(
                $manifest['components']['release/manifest.json'] ?? null,
            ) ? $manifest['components']['release/manifest.json'] : [];
            $caComponent = 'share/ca-bundle.pem';
            $installedCaBundle = \is_array(
                $manifest['components'][$caComponent] ?? null,
            ) ? $manifest['components'][$caComponent] : [];
            $releaseFile = $slotDirectory . DIRECTORY_SEPARATOR . 'release'
                . DIRECTORY_SEPARATOR . 'manifest.json';
            $releaseBytes = $this->readStableRegularFile(
                $releaseFile,
                16_777_216,
                $operation . ' signed release manifest',
            );
            $release = \json_decode($releaseBytes, true);
            if (!\is_array($release)) {
                throw new \RuntimeException(
                    'Signed stable-launcher release manifest is not valid JSON.',
                );
            }
            $releaseLauncher = \is_array(
                $release['components'][$launcherComponent] ?? null,
            ) ? $release['components'][$launcherComponent] : [];
            $releaseCaBundle = \is_array(
                $release['components'][$caComponent] ?? null,
            ) ? $release['components'][$caComponent] : [];
            $launcherDigest = \strtolower(\trim((string)(
                $releaseLauncher['sha256'] ?? ''
            )));
            $launcherSize = $releaseLauncher['size'] ?? null;
            $releaseLauncherMode = $releaseLauncher['mode'] ?? null;
            $installedLauncherMode = $installedLauncher['mode'] ?? null;
            $expectedInstalledLauncherMode = \is_int($releaseLauncherMode)
                ? $this->installedComponentMode($releaseLauncherMode)
                : -1;
            $slotLauncher = $slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $launcherComponent);
            $slotLauncherBefore = @\lstat($slotLauncher);
            $slotLauncherDigest = $this->digestStableRegularFile(
                $slotLauncher,
                self::MAX_PACKAGE_BYTES,
                $operation . ' slot stable gateway launcher',
            );
            $slotLauncherAfter = @\lstat($slotLauncher);
            $caBundleDigest = \strtolower(\trim((string)(
                $releaseCaBundle['sha256'] ?? ''
            )));
            $caBundleSize = $releaseCaBundle['size'] ?? null;
            $releaseCaBundleMode = $releaseCaBundle['mode'] ?? null;
            $installedCaBundleMode = $installedCaBundle['mode'] ?? null;
            $expectedInstalledCaBundleMode = \is_int($releaseCaBundleMode)
                ? $this->installedComponentMode($releaseCaBundleMode)
                : -1;
            $slotCaBundle = $slotDirectory . DIRECTORY_SEPARATOR . 'share'
                . DIRECTORY_SEPARATOR . 'ca-bundle.pem';
            $slotCaBundleBefore = @\lstat($slotCaBundle);
            $slotCaBundleDigest = $this->digestStableRegularFile(
                $slotCaBundle,
                4_194_304,
                $operation . ' slot CA trust bundle',
            );
            $slotCaBundleAfter = @\lstat($slotCaBundle);
            $releaseDigest = \hash('sha256', $releaseBytes);
            if ((int)($release['schema_version'] ?? 0) !== self::MANIFEST_SCHEMA
                || !\hash_equals(
                    GatewayPaths::SECURITY_PROFILE,
                    (string)($release['security_profile'] ?? ''),
                )
                || !\hash_equals(
                    GatewayPaths::IMPLEMENTATION_LEVEL,
                    (string)($release['implementation_level'] ?? ''),
                )
                || ($release['capabilities'][
                    'stable_launcher_rollback_target_proof'
                ] ?? null) !== true
                || ($manifest['capabilities'][
                    'stable_launcher_rollback_target_proof'
                ] ?? null) !== true
                || ($release['capabilities'][
                    'certificate_public_trust_bundle'
                ] ?? null) !== true
                || ($manifest['capabilities'][
                    'certificate_public_trust_bundle'
                ] ?? null) !== true
                || (\PHP_OS_FAMILY === 'Windows'
                    && (($release['capabilities'][
                        'windows_named_pipe_deadline_transport'
                    ] ?? null) !== true
                        || ($manifest['capabilities'][
                            'windows_named_pipe_deadline_transport'
                        ] ?? null) !== true))
                || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $packageDigest) !== 1
                || !\hash_equals($packageDigest, $releaseDigest)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $launcherDigest) !== 1
                || !\is_int($launcherSize)
                || $launcherSize < 1
                || $launcherSize > self::MAX_PACKAGE_BYTES
                || !\is_int($releaseLauncherMode)
                || $releaseLauncherMode
                    !== $this->expectedPackageComponentMode($launcherComponent)
                || !\is_int($installedLauncherMode)
                || $installedLauncherMode !== $expectedInstalledLauncherMode
                || !\hash_equals(
                    $launcherDigest,
                    \strtolower(\trim((string)($installedLauncher['sha256'] ?? ''))),
                )
                || $launcherSize !== ($installedLauncher['size'] ?? null)
                || !\hash_equals(
                    $releaseDigest,
                    \strtolower(\trim((string)($installedRelease['sha256'] ?? ''))),
                )
                || \strlen($releaseBytes) !== ($installedRelease['size'] ?? null)
                || !\is_array($slotLauncherBefore)
                || !\is_array($slotLauncherAfter)
                || !$this->sameFileState(
                    $slotLauncherBefore,
                    $slotLauncherAfter,
                )
                || !$this->isRegularFileStatus($slotLauncherAfter)
                || (int)($slotLauncherAfter['nlink'] ?? 0) !== 1
                || !\hash_equals($launcherDigest, $slotLauncherDigest['sha256'])
                || $launcherSize !== $slotLauncherDigest['size']
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)($slotLauncherAfter['mode'] ?? 0)) & 0777)
                        !== $expectedInstalledLauncherMode))
                || \preg_match('/\A[a-f0-9]{64}\z/D', $caBundleDigest) !== 1
                || !\is_int($caBundleSize)
                || $caBundleSize < 1
                || $caBundleSize > 4_194_304
                || !\is_int($releaseCaBundleMode)
                || $releaseCaBundleMode !== 0644
                || !\is_int($installedCaBundleMode)
                || $installedCaBundleMode !== $expectedInstalledCaBundleMode
                || !\hash_equals(
                    $caBundleDigest,
                    \strtolower(\trim((string)(
                        $installedCaBundle['sha256'] ?? ''
                    ))),
                )
                || $caBundleSize !== ($installedCaBundle['size'] ?? null)
                || !\is_array($slotCaBundleBefore)
                || !\is_array($slotCaBundleAfter)
                || !$this->sameFileState($slotCaBundleBefore, $slotCaBundleAfter)
                || !$this->isRegularFileStatus($slotCaBundleAfter)
                || (int)($slotCaBundleAfter['nlink'] ?? 0) !== 1
                || !\hash_equals(
                    $caBundleDigest,
                    (string)$slotCaBundleDigest['sha256'],
                )
                || $caBundleSize !== (int)$slotCaBundleDigest['size']
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)($slotCaBundleAfter['mode'] ?? 0)) & 0777)
                        !== $expectedInstalledCaBundleMode))
            ) {
                throw new \RuntimeException(
                    'Signed and installed stable-launcher capability closure does not match.',
                );
            }
            $this->assertExactDurableStateContract(
                $release['durable_state_contract'] ?? null,
                $operation . ' signed release package',
            );
            if (!$this->paths->isTestMode()) {
                $signatureBytes = $this->readStableRegularFile(
                    $slotDirectory . DIRECTORY_SEPARATOR . 'release'
                        . DIRECTORY_SEPARATOR . 'manifest.sig',
                    16_384,
                    $operation . ' signed release signature',
                );
                $installedSignature = \is_array(
                    $manifest['components']['release/manifest.sig'] ?? null,
                ) ? $manifest['components']['release/manifest.sig'] : [];
                if (!\hash_equals(
                        \strtolower(\trim((string)(
                            $installedSignature['sha256'] ?? ''
                        ))),
                        \hash('sha256', $signatureBytes),
                    )
                    || \strlen($signatureBytes) !== ($installedSignature['size'] ?? null)
                ) {
                    throw new \RuntimeException(
                        'Installed stable-launcher release signature closure does not match.',
                    );
                }
                $this->verifyReleaseSignature(
                    $releaseBytes,
                    $signatureBytes,
                    (string)($release['signing_key_id'] ?? ''),
                );
            }

            return [
                'slot' => $slot,
                'runtime_generation' => $runtimeGeneration,
                'package_digest' => $packageDigest,
                'launcher_sha256' => $launcherDigest,
                'launcher_size' => $launcherSize,
                'launcher_mode' => $expectedInstalledLauncherMode,
                'launcher_identity' => $slotLauncherAfter,
                'ca_bundle_sha256' => $caBundleDigest,
                'ca_bundle_size' => $caBundleSize,
                'ca_bundle_mode' => $expectedInstalledCaBundleMode,
                'ca_bundle_identity' => $slotCaBundleAfter,
            ];
        } catch (\Throwable $throwable) {
            throw $this->stableLauncherRebootstrapRequired($operation, $throwable);
        }
    }

    /**
     * @param list<string> $slots
     * @param array<string,mixed>|null $intentBinding
     * @return array{
     *   launcher_sha256:string,
     *   launcher_size:int,
     *   launcher_identity:array<string|int,mixed>,
     *   trust_identity:array<string|int,mixed>,
     *   slots:array<string,array{
     *     runtime_generation:string,
     *     package_digest:string,
     *     launcher_sha256:string,
     *     launcher_size:int,
     *     launcher_mode:int,
     *     launcher_identity:array<string|int,mixed>
     *   }>
     * }
     */
    private function verifiedStableLauncherUpgradeProof(
        array $slots,
        string $operation,
        ?array $intentBinding = null,
    ): array {
        try {
            $slots = \array_values(\array_unique(\array_map(
                static fn (string $slot): string => \strtoupper(\trim($slot)),
                $slots,
            )));
            \sort($slots, SORT_STRING);
            if ($slots === [] || \count($slots) > 2) {
                throw new \RuntimeException(
                    'Stable-launcher proof requires one or two exact A/B slots.',
                );
            }
            $proofs = [];
            $expectedDigest = '';
            $expectedSize = 0;
            $expectedMode = 0;
            $expectedCaBundleDigest = '';
            $expectedCaBundleSize = 0;
            $expectedCaBundleMode = 0;
            foreach ($slots as $slot) {
                if (!\in_array($slot, ['A', 'B'], true)) {
                    throw new \RuntimeException(
                        'Stable-launcher proof contains a non-A/B slot.',
                    );
                }
                $proof = $this->verifiedStableLauncherSlotProof($slot, $operation);
                if ($expectedDigest === '') {
                    $expectedDigest = $proof['launcher_sha256'];
                    $expectedSize = $proof['launcher_size'];
                    $expectedMode = $proof['launcher_mode'];
                    $expectedCaBundleDigest = $proof['ca_bundle_sha256'];
                    $expectedCaBundleSize = $proof['ca_bundle_size'];
                    $expectedCaBundleMode = $proof['ca_bundle_mode'];
                } elseif (!\hash_equals(
                        $expectedDigest,
                        $proof['launcher_sha256'],
                    )
                    || $expectedSize !== $proof['launcher_size']
                    || $expectedMode !== $proof['launcher_mode']
                    || !\hash_equals(
                        $expectedCaBundleDigest,
                        $proof['ca_bundle_sha256'],
                    )
                    || $expectedCaBundleSize !== $proof['ca_bundle_size']
                    || $expectedCaBundleMode !== $proof['ca_bundle_mode']
                ) {
                    throw new \RuntimeException(
                        'A/B slots do not declare one stable launcher and CA trust generation.',
                    );
                }
                $proofs[$slot] = [
                    'runtime_generation' => $proof['runtime_generation'],
                    'package_digest' => $proof['package_digest'],
                    'launcher_sha256' => $proof['launcher_sha256'],
                    'launcher_size' => $proof['launcher_size'],
                    'launcher_mode' => $proof['launcher_mode'],
                    'launcher_identity' => $proof['launcher_identity'],
                    'ca_bundle_sha256' => $proof['ca_bundle_sha256'],
                    'ca_bundle_size' => $proof['ca_bundle_size'],
                    'ca_bundle_mode' => $proof['ca_bundle_mode'],
                    'ca_bundle_identity' => $proof['ca_bundle_identity'],
                ];
            }
            if ($intentBinding !== null
                && (($intentBinding['protocol'] ?? 0) !== 2
                    || ($intentBinding['legacy'] ?? true) !== false
                    || !isset(
                        $proofs[(string)($intentBinding['from'] ?? '')],
                        $proofs[(string)($intentBinding['to'] ?? '')],
                    )
                    || !\hash_equals(
                        (string)$proofs[(string)$intentBinding['to']][
                            'runtime_generation'
                        ],
                        (string)($intentBinding['runtime_generation'] ?? ''),
                    ))
            ) {
                throw new \RuntimeException(
                    'Signed upgrade intent is not bound to the proved launcher A/B generation.',
                );
            }

            $launcher = $this->paths->launcherFile();
            $launcherBefore = @\lstat($launcher);
            $actual = $this->digestStableRegularFile(
                $launcher,
                self::MAX_PACKAGE_BYTES,
                $operation . ' stable gateway launcher',
            );
            $launcherAfter = @\lstat($launcher);
            $this->assertStableLauncherPermissions($launcher);
            $identityFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256';
            $identityBefore = @\lstat($identityFile);
            $trusted = \strtolower(\trim($this->readStableRegularFile(
                $identityFile,
                65,
                $operation . ' stable gateway launcher identity',
            )));
            $identityAfter = @\lstat($identityFile);
            $caBaseline = $this->trustBundleBaselineProof(
                $operation . ' host CA trust baseline',
            );
            if (!\is_array($launcherBefore)
                || !\is_array($launcherAfter)
                || !$this->sameFileState($launcherBefore, $launcherAfter)
                || !\is_array($identityBefore)
                || !\is_array($identityAfter)
                || !$this->sameFileState($identityBefore, $identityAfter)
                || !\hash_equals($expectedDigest, $actual['sha256'])
                || $expectedSize !== $actual['size']
                || !\hash_equals($expectedDigest, $trusted)
                || !\hash_equals(
                    $expectedCaBundleDigest,
                    (string)$caBaseline['sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Published stable-launcher bytes or trust identity do not match the proved A/B generation.',
                );
            }

            return [
                'launcher_sha256' => $expectedDigest,
                'launcher_size' => $expectedSize,
                'launcher_identity' => $launcherAfter,
                'trust_identity' => $identityAfter,
                'ca_bundle_sha256' => $expectedCaBundleDigest,
                'ca_bundle_baseline_identity' => $caBaseline['identity'],
                'slots' => $proofs,
            ];
        } catch (\Throwable $throwable) {
            if ($throwable instanceof \RuntimeException
                && \str_contains(
                    $throwable->getMessage(),
                    'ordinary A/B activation is not permitted',
                )
            ) {
                throw $throwable;
            }
            throw $this->stableLauncherRebootstrapRequired($operation, $throwable);
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assertStableLauncherUpgradeProofUnchanged(
        array $before,
        array $after,
        string $operation,
    ): void {
        if (!\hash_equals(
                (string)($before['launcher_sha256'] ?? ''),
                (string)($after['launcher_sha256'] ?? ''),
            )
            || (int)($before['launcher_size'] ?? -1)
                !== (int)($after['launcher_size'] ?? -2)
            || ($before['slots'] ?? null) !== ($after['slots'] ?? null)
            || !\is_array($before['launcher_identity'] ?? null)
            || !\is_array($after['launcher_identity'] ?? null)
            || !$this->sameFileState(
                $before['launcher_identity'],
                $after['launcher_identity'],
            )
            || !\is_array($before['trust_identity'] ?? null)
            || !\is_array($after['trust_identity'] ?? null)
            || !$this->sameFileState(
                $before['trust_identity'],
                $after['trust_identity'],
            )
            || !\hash_equals(
                (string)($before['ca_bundle_sha256'] ?? ''),
                (string)($after['ca_bundle_sha256'] ?? ''),
            )
            || !\is_array($before['ca_bundle_baseline_identity'] ?? null)
            || !\is_array($after['ca_bundle_baseline_identity'] ?? null)
            || !$this->sameFileState(
                $before['ca_bundle_baseline_identity'],
                $after['ca_bundle_baseline_identity'],
            )
        ) {
            throw $this->stableLauncherRebootstrapRequired($operation);
        }
    }

    private function stableLauncherRebootstrapRequired(
        string $operation,
        ?\Throwable $previous = null,
    ): \RuntimeException {
        return new \RuntimeException(
            $operation
                . ' cannot prove the stable_launcher_rollback_target_proof generation. '
                . 'Preserve every upgrade and rollback artifact. Stable launcher generation '
                . 'changed; stop the gateway and perform an explicit full host rebootstrap '
                . 'from a signed package; ordinary A/B activation is not permitted.',
            0,
            $previous,
        );
    }

    /**
     * Resolve one helper only from an already sealed host-runtime artifact.
     * Candidate-slot self-tests use this before activation; a running host
     * Controller uses the slot containing its own locked PHP binary. No PATH,
     * environment, or project executable is accepted by this contract.
     *
     * @return array{path:string,size:int,sha256:string,source:string}
     */
    public function boundedCommandHelperProof(string $slot): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException(
                'The native bounded-command helper is a Windows-only component.'
            );
        }
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Gateway helper slot must be A or B.');
        }
        $slotDirectory = $this->paths->slotDir($slot);
        $verification = $this->artifact->verify($slotDirectory, 'host_gateway');
        if (!(bool)($verification['ok'] ?? false)) {
            throw new \RuntimeException(
                'Gateway bounded-command helper slot is not an immutable verified runtime.'
            );
        }
        $manifest = $this->installedManifest($slot);
        $relative = $this->componentPath('wls-bounded-command');
        $definition = \is_array($manifest['components'][$relative] ?? null)
            ? $manifest['components'][$relative]
            : [];
        $expectedSize = (int)($definition['size'] ?? -1);
        $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
        if (($manifest['schema_version'] ?? null) !== NginxRuntimeArtifact::SCHEMA_VERSION
            || !\hash_equals('host_gateway', (string)($manifest['role'] ?? ''))
            || $expectedSize < 1
            || $expectedSize > 16_777_216
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway bounded-command helper manifest entry is invalid.'
            );
        }
        $path = $slotDirectory . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $bytes = $this->readStableRegularFile(
            $path,
            16_777_216,
            'Gateway bounded-command helper',
        );
        $canonical = \realpath($path);
        if (!\is_string($canonical)
            || !\hash_equals(
                \strtolower(\str_replace('\\', '/', $path)),
                \strtolower(\str_replace('\\', '/', $canonical)),
            )
            || \strlen($bytes) !== $expectedSize
            || !\hash_equals($expectedDigest, \hash('sha256', $bytes))
        ) {
            throw new \RuntimeException(
                'Gateway bounded-command helper changed after slot verification.'
            );
        }

        return [
            'path' => $canonical,
            'size' => $expectedSize,
            'sha256' => $expectedDigest,
            'source' => 'host-slot-' . $slot,
        ];
    }

    /**
     * @return array{
     *   package_dir:string,
     *   manifest_file:string,
     *   signature_file:string,
     *   manifest_bytes:string,
     *   signature_bytes:string,
     *   package_digest:string,
     *   manifest_digest:string,
     *   signature_digest:string,
     *   manifest:array<string,mixed>
     * }
     */
    public function verifyPackage(
        string $packageDirectory,
        string $profile,
        ?float $deadlineMonotonic = null,
    ): array {
        return $this->withOperationDeadline(
            $deadlineMonotonic,
            fn (): array => $this->verifyPackageWithinDeadline(
                $packageDirectory,
                $profile,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function verifyPackageWithinDeadline(
        string $packageDirectory,
        string $profile,
    ): array {
        $realPackage = \realpath($packageDirectory);
        if (!\is_string($realPackage)
            || !\is_dir($realPackage)
            || \is_link($packageDirectory)
            || \str_contains($packageDirectory, "\0")
        ) {
            throw new \RuntimeException('Gateway package directory is missing or unsafe.');
        }
        $manifestFile = $realPackage . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!\is_file($manifestFile) || \is_link($manifestFile)) {
            throw new \RuntimeException('Gateway release manifest is missing or unsafe.');
        }
        $manifestBytes = $this->readStableRegularFile(
            $manifestFile,
            8_388_608,
            'Gateway release manifest',
        );
        $manifest = \json_decode($manifestBytes, true);
        if (!\is_array($manifest)
            || (int)($manifest['schema_version'] ?? 0) !== self::MANIFEST_SCHEMA
            || (int)($manifest['protocol_min'] ?? 0) > 2
            || (int)($manifest['protocol_max'] ?? 0) < 2
            || !\hash_equals(GatewayPaths::SECURITY_PROFILE, (string)($manifest['security_profile'] ?? ''))
            || !\hash_equals(GatewayPaths::IMPLEMENTATION_LEVEL, (string)($manifest['implementation_level'] ?? ''))
            || !\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))
            || !\hash_equals($this->normalizedArch(), $this->normalizeArch((string)($manifest['arch'] ?? '')))
            || \trim((string)($manifest['version'] ?? '')) === ''
            || !\is_array($manifest['components'] ?? null)
            || !\is_array($manifest['capabilities'] ?? null)
        ) {
            throw new \RuntimeException('Gateway release manifest contract or target does not match this host.');
        }
        $this->assertExactDurableStateContract(
            $manifest['durable_state_contract'] ?? null,
            'Gateway release package',
        );
        if ($manifest['components'] === []
            || \count($manifest['components']) > self::MAX_PACKAGE_COMPONENTS
        ) {
            throw new \RuntimeException(
                'Gateway release manifest exceeds its fixed component limit.'
            );
        }
        $this->assertPackageComponentTopology($manifest['components']);
        $declaredProfiles = \array_values(\array_map('strval', (array)($manifest['listen_profiles'] ?? [])));
        if (!\in_array($profile, $declaredProfiles, true)) {
            throw new \RuntimeException('Gateway package does not support the requested listen profile.');
        }
        $testPackage = (string)($manifest['package_profile'] ?? '') === 'test';
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            $testRuntimeDependency = $testPackage
                && $this->paths->isTestMode()
                && \in_array(
                    $capability,
                    ['self_contained_nginx', 'self_contained_php'],
                    true,
                );
            if (($manifest['capabilities'][$capability] ?? false) !== true
                && !$testRuntimeDependency
            ) {
                throw new \RuntimeException('Gateway package capability is missing: ' . $capability);
            }
        }
        if (\PHP_OS_FAMILY === 'Windows'
            && ($manifest['capabilities']['windows_kernel32_ffi_atomic_write'] ?? false)
                !== true
        ) {
            throw new \RuntimeException(
                'Windows gateway package does not declare the locked kernel32 FFI atomic-write capability.'
            );
        }
        if (\PHP_OS_FAMILY === 'Windows'
            && ($manifest['capabilities']['windows_named_pipe_deadline_transport']
                ?? false) !== true
        ) {
            throw new \RuntimeException(
                'Windows gateway package does not declare the bounded named-pipe deadline transport capability.'
            );
        }

        if ($this->paths->isTestMode()) {
            if (!$testPackage || ($manifest['release_ready'] ?? true) !== false) {
                throw new \RuntimeException('Test mode only accepts non-release-ready test packages.');
            }
        } elseif ($testPackage || ($manifest['release_ready'] ?? false) !== true) {
            throw new \RuntimeException('Production install requires a release-ready production package.');
        }

        $totalBytes = \strlen($manifestBytes);
        foreach ($this->requiredComponents() as $required) {
            if (!\is_array($manifest['components'][$required] ?? null)) {
                throw new \RuntimeException('Gateway package component is missing: ' . $required);
            }
        }
        $canonicalComponentKeys = [];
        $declaredDirectories = [];
        foreach ($manifest['components'] as $relative => $definition) {
            if (!\is_string($relative)) {
                throw new \RuntimeException(
                    'Gateway package component paths must be JSON object strings.'
                );
            }
            $canonical = $this->validateRelativePath($relative);
            if (!\hash_equals($relative, $canonical)) {
                throw new \RuntimeException(
                    'Gateway package component path is not canonical: ' . $relative
                );
            }
            $collisionKey = \strtolower($canonical);
            if (isset($canonicalComponentKeys[$collisionKey])) {
                throw new \RuntimeException(
                    'Gateway package component paths collide across supported filesystems: '
                        . $relative
                );
            }
            $canonicalComponentKeys[$collisionKey] = true;
            $relative = $canonical;
            $segments = \explode('/', $collisionKey);
            \array_pop($segments);
            while ($segments !== []) {
                $declaredDirectories[\implode('/', $segments)] = true;
                if (\count($declaredDirectories) > self::MAX_PACKAGE_DIRECTORIES) {
                    throw new \RuntimeException(
                        'Gateway release manifest exceeds its fixed directory limit.'
                    );
                }
                \array_pop($segments);
            }
            if (!\is_array($definition)) {
                throw new \RuntimeException('Gateway package component definition is invalid.');
            }
            $source = $realPackage . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!\is_file($source) || \is_link($source)) {
                throw new \RuntimeException('Gateway package component is missing or is a link: ' . $relative);
            }
            $realSource = \realpath($source);
            if (!\is_string($realSource) || !$this->pathIsWithin($realSource, $realPackage)) {
                throw new \RuntimeException('Gateway package component escaped its package root: ' . $relative);
            }
            $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
            $inspected = $this->digestStableRegularFile(
                $source,
                self::MAX_PACKAGE_BYTES,
                'Gateway package component ' . $relative,
            );
            $actualDigest = $inspected['sha256'];
            $size = $inspected['size'];
            $mode = $definition['mode'] ?? null;
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                || !\is_string($actualDigest)
                || !\hash_equals($expectedDigest, $actualDigest)
                || (int)($definition['size'] ?? -1) !== $size
                || !\is_int($mode)
                || $mode !== $this->expectedPackageComponentMode($relative)
            ) {
                throw new \RuntimeException('Gateway package component verification failed: ' . $relative);
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException('Gateway package exceeds the fixed size limit.');
            }
        }
        $this->assertCertificateTrustBundle(
            $this->verifiedComponentBytes(
                $realPackage,
                'share/ca-bundle.pem',
                $manifest,
            ),
        );
        $provenanceBytes = $this->verifiedComponentBytes(
            $realPackage,
            'provenance.json',
            $manifest,
        );
        $provenance = $testPackage
            ? null
            : $this->verifyProductionProvenance($provenanceBytes, $manifest);
        $this->verifySbom(
            $this->verifiedComponentBytes($realPackage, 'sbom.cdx.json', $manifest),
            $provenance,
        );
        if (\trim($this->verifiedComponentBytes(
            $realPackage,
            'LICENSES.txt',
            $manifest,
        )) === '') {
            throw new \RuntimeException('Gateway package license inventory is empty.');
        }

        $signatureFile = $realPackage . DIRECTORY_SEPARATOR . 'manifest.sig';
        $signatureBytes = '';
        if (!$this->paths->isTestMode()) {
            $signatureBytes = $this->readStableRegularFile(
                $signatureFile,
                16_384,
                'Gateway release signature',
            );
            $this->verifyReleaseSignature(
                $manifestBytes,
                $signatureBytes,
                (string)($manifest['signing_key_id'] ?? ''),
            );
        } elseif (\is_link($signatureFile)) {
            throw new \RuntimeException('Test package signature path cannot be a symbolic link.');
        } elseif (\is_file($signatureFile)) {
            $signatureBytes = $this->readStableRegularFile(
                $signatureFile,
                16_384,
                'Gateway test release signature',
            );
        }
        if (\strlen($signatureBytes) > self::MAX_PACKAGE_BYTES - $totalBytes) {
            throw new \RuntimeException('Gateway package exceeds the fixed size limit.');
        }

        return [
            'package_dir' => $realPackage,
            'manifest_file' => $manifestFile,
            'signature_file' => $signatureBytes !== '' ? $signatureFile : '',
            'manifest_bytes' => $manifestBytes,
            'signature_bytes' => $signatureBytes,
            'package_digest' => \hash('sha256', $manifestBytes),
            'manifest_digest' => \hash('sha256', $manifestBytes),
            'signature_digest' => \hash('sha256', $signatureBytes),
            'manifest' => $manifest,
        ];
    }

    private function runSlotSelfTests(string $slotDirectory): void
    {
        $php = $slotDirectory . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('php'));
        $controller = $slotDirectory . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'controller.php';
        $commands = [
            [$php, $controller, '--self-test'],
            [$php, '--version'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('nginx')), '-V'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-broker')), '--self-test'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-launcher')), '--self-test'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-launcher')), '--rollback-target-proof-self-test'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-launcher')), '--recovery-ledger-self-test'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-launcher')), '--capacity-reserve-contract-self-test'],
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $commands[] = [
                $slotDirectory . DIRECTORY_SEPARATOR
                    . \str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $this->componentPath('wls-gateway-guardian'),
                    ),
                '--self-test',
            ];
            $commands[] = [
                $slotDirectory . DIRECTORY_SEPARATOR
                    . \str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $this->componentPath('wls-bounded-command'),
                    ),
                '--self-test',
            ];
            $commands[] = [
                $slotDirectory . DIRECTORY_SEPARATOR
                    . \str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $this->componentPath('wls-bounded-command'),
                    ),
                '--pipe-deadline-self-test',
            ];
            \array_splice($commands, 2, 0, [[
                $php,
                '-r',
                self::windowsAtomicFfiProbeScript(),
            ]]);
        }
        $windowsHelperProof = null;
        if (\PHP_OS_FAMILY === 'Windows') {
            $slot = \strtoupper(\basename($slotDirectory));
            $windowsHelperProof = $this->boundedCommandHelperProof($slot);
        }
        foreach ($commands as $command) {
            $result = $this->runCommand($command, $windowsHelperProof);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Gateway package component self-test failed: '
                    . \basename($command[0]) . ': ' . $result['output']
                );
            }
        }
    }

    private static function windowsAtomicFfiProbeScript(): string
    {
        return <<<'PHP'
$mode = strtolower(trim((string)ini_get('ffi.enable')));
if (!extension_loaded('FFI')
    || !class_exists('FFI')
    || in_array($mode, ['', '0', 'off', 'false'], true)
) {
    fwrite(STDERR, "FFI extension or ffi.enable is unavailable.\n");
    exit(70);
}
try {
    $ffi = FFI::cdef(
        'typedef unsigned long DWORD; DWORD GetLastError(void);',
        'kernel32.dll',
    );
    $ffi->GetLastError();
} catch (Throwable $throwable) {
    fwrite(STDERR, "kernel32 FFI::cdef failed.\n");
    exit(71);
}
PHP;
    }

    private function installStableLauncher(string $source, string $expectedDigest): void
    {
        $expectedDigest = \strtolower(\trim($expectedDigest));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1) {
            throw new \RuntimeException('Stable gateway launcher digest is invalid.');
        }
        $target = $this->paths->launcherFile();
        $identityFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        $actual = null;
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $actual = $this->digestStableRegularFile(
                $target,
                self::MAX_PACKAGE_BYTES,
                'Stable gateway launcher',
            )['sha256'];
            $this->assertStableLauncherPermissions($target);
        } elseif (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException('Stable gateway launcher target is unsafe.');
        }

        $trustedBytes = $this->readOptionalStableRegularFile(
            $identityFile,
            65,
            'Stable gateway launcher identity',
        );
        $trusted = $trustedBytes === null
            ? null
            : \strtolower(\trim($trustedBytes));
        if ($trusted !== null
            && \preg_match('/\A[a-f0-9]{64}\z/D', $trusted) !== 1
        ) {
            throw new \RuntimeException(
                'Stable gateway launcher identity verification failed.'
            );
        }

        if ($actual !== null) {
            if ($trusted === null) {
                if (!$this->launcherDigestBackedByInstalledSlot($actual)) {
                    throw new \RuntimeException(
                        'Stable gateway launcher cannot establish a trusted legacy identity.'
                    );
                }
                $this->atomicWrite($identityFile, $actual . "\n", 0600);
                $trusted = $actual;
            }
            if (!\hash_equals($trusted, $actual)) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity verification failed.'
                );
            }
            if (!\hash_equals($expectedDigest, $actual)) {
                throw new \RuntimeException(
                    'Stable launcher generation changed; stop the gateway and perform an '
                        . 'explicit full host rebootstrap from a signed package; ordinary '
                        . 'A/B activation is not permitted.'
                );
            }
            return;
        }

        if ($trusted !== null && !\hash_equals($expectedDigest, $trusted)) {
            throw new \RuntimeException(
                'Stable gateway launcher recovery identity does not match this package.'
            );
        }
        if ($trusted === null) {
            // Publish the root-owned recovery identity first. A power loss
            // between the two atomic publications can then be completed on
            // the next installation attempt without trusting an orphan path.
            $this->atomicWrite($identityFile, $expectedDigest . "\n", 0600);
        }
        $this->copyStableLauncher($source, $target, $expectedDigest);
    }

    /**
     * Seed the frozen v1 Guardian from its verified package component. Later
     * A/B and rebootstrap generations only verify this copy; they can never
     * replace it. Guardian upgrades require a future explicit protocol.
     */
    private function installImmutableGuardian(
        string $source,
        string $bootstrapDigest,
        bool $requirePackageMatch,
    ): void {
        $bootstrapDigest = \strtolower(\trim($bootstrapDigest));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $bootstrapDigest) !== 1) {
            throw new \RuntimeException('Recovery Guardian bootstrap digest is invalid.');
        }
        $target = $this->paths->guardianFile();
        $identityFile = $this->paths->guardianDigestFile();
        $this->recoverGuardianIdentityAtomicArtifacts($target, $identityFile);
        $identity = $this->readOptionalStableRegularFile(
            $identityFile,
            65,
            'Recovery Guardian identity',
        );
        $trusted = $identity === null ? null : \strtolower(\trim($identity));
        if ($trusted !== null
            && \preg_match('/\A[a-f0-9]{64}\z/D', $trusted) !== 1
        ) {
            throw new \RuntimeException('Recovery Guardian identity is invalid.');
        }

        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $actual = $this->digestStableRegularFile(
                $target,
                self::MAX_PACKAGE_BYTES,
                'Immutable Recovery Guardian',
            );
            $this->assertStableLauncherPermissions($target);
            if ($trusted === null) {
                if (!\hash_equals($bootstrapDigest, $actual['sha256'])) {
                    throw new \RuntimeException(
                        'Orphan Recovery Guardian does not match the signed bootstrap package.',
                    );
                }
                $this->cleanupGuardianPublicationCandidates(
                    $target,
                    $bootstrapDigest,
                );
                if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                    $identityFile,
                    65,
                    'Recovery Guardian identity',
                )) {
                    GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                        $identityFile,
                        65,
                        'Recovery Guardian identity',
                    );
                }
                GatewayProjectStateFilesystem::atomicWrite(
                    $identityFile,
                    $bootstrapDigest . "\n",
                    0600,
                );
                return;
            }
            if (!\hash_equals($trusted, $actual['sha256'])) {
                throw new \RuntimeException(
                    'Immutable Recovery Guardian lacks its exact trusted identity.',
                );
            }
            if ($requirePackageMatch
                && !\hash_equals($trusted, $bootstrapDigest)
            ) {
                throw new \RuntimeException(
                    'Signed Windows Recovery Guardian differs from the immutable v1 Guardian.',
                );
            }
            $this->cleanupGuardianPublicationCandidates($target, $trusted);
            return;
        }
        if (\file_exists($target) || \is_link($target) || $trusted !== null) {
            throw new \RuntimeException(
                'Immutable Recovery Guardian publication is incomplete or unsafe.',
            );
        }

        $this->cleanupGuardianPublicationCandidates($target, $bootstrapDigest);
        $this->copyStableLauncher($source, $target, $bootstrapDigest);

        if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $identityFile,
            65,
            'Recovery Guardian identity',
        )) {
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $identityFile,
                65,
                'Recovery Guardian identity',
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $identityFile,
            $bootstrapDigest . "\n",
            0600,
        );
        $published = \strtolower(\trim(GatewayProjectStateFilesystem::read(
            $identityFile,
            65,
            'Published Recovery Guardian identity',
        )));
        if (!\hash_equals($bootstrapDigest, $published)) {
            throw new \RuntimeException(
                'Published Recovery Guardian identity changed.',
            );
        }
    }

    private function recoverGuardianIdentityAtomicArtifacts(
        string $target,
        string $identityFile,
    ): void {
        if (!GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $identityFile,
            65,
            'Recovery Guardian identity',
        )) {
            return;
        }
        if (!\file_exists($identityFile) && !\is_link($identityFile)) {
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $identityFile,
                65,
                'Recovery Guardian identity',
            );
            return;
        }
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $identityFile,
            65,
            'Recovery Guardian identity',
            function (string $raw) use ($target): void {
                if (\preg_match('/\A([a-f0-9]{64})\n\z/D', $raw, $matches) !== 1) {
                    throw new \RuntimeException(
                        'Recovery Guardian identity syntax is invalid.',
                    );
                }
                $actual = $this->digestStableRegularFile(
                    $target,
                    self::MAX_PACKAGE_BYTES,
                    'Immutable Recovery Guardian',
                );
                $this->assertStableLauncherPermissions($target);
                if (!\hash_equals((string)$matches[1], $actual['sha256'])) {
                    throw new \RuntimeException(
                        'Recovery Guardian identity does not bind its executable.',
                    );
                }
            },
        );
    }

    private function cleanupGuardianPublicationCandidates(
        string $target,
        string $expectedDigest,
    ): void {
        $directory = \dirname($target);
        $prefix = \basename(\str_replace('\\', '/', $target)) . '.candidate.';
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the immutable Recovery Guardian directory.',
            );
        }
        $candidates = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > 64) {
                    throw new \RuntimeException(
                        'Recovery Guardian directory exceeds its fixed entry quota.',
                    );
                }
                if ($leaf === '.' || $leaf === '..'
                    || \hash_equals($leaf, \basename(\str_replace('\\', '/', $target)))
                ) {
                    continue;
                }
                if (!\str_starts_with($leaf, $prefix)
                    || \preg_match(
                        '/\A' . \preg_quote($prefix, '/') . '[a-f0-9]{16}\z/D',
                        $leaf,
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        'Recovery Guardian directory contains an unexpected entry.',
                    );
                }
                $candidates[] = $directory . DIRECTORY_SEPARATOR . $leaf;
            }
        } finally {
            @\closedir($handle);
        }
        \sort($candidates, SORT_STRING);
        $parent = @\lstat($directory);
        if (!\is_array($parent) || \is_link($directory)) {
            throw new \RuntimeException(
                'Recovery Guardian publication directory authority is invalid.',
            );
        }
        foreach ($candidates as $candidate) {
            $before = @\lstat($candidate);
            $digest = $this->digestStableRegularFile(
                $candidate,
                self::MAX_PACKAGE_BYTES,
                'Recovery Guardian publication candidate',
            );
            $after = @\lstat($candidate);
            if (!\is_array($before)
                || !\is_array($after)
                || !$this->sameFileState($before, $after)
                || !$this->isRegularFileStatus($after)
                || (int)($after['nlink'] ?? 0) !== 1
                || (int)($after['size'] ?? -1) < 0
                || (int)($after['size'] ?? -1) > self::MAX_PACKAGE_BYTES
                || (\PHP_OS_FAMILY !== 'Windows'
                    && ((int)($after['uid'] ?? -1) !== (int)($parent['uid'] ?? -2)
                        || (int)($after['gid'] ?? -1) !== (int)($parent['gid'] ?? -2)
                        || ((((int)($after['mode'] ?? 0)) & 0022) !== 0)))
            ) {
                throw new \RuntimeException(
                    'Recovery Guardian publication candidate is not recoverable.',
                );
            }
            if (\hash_equals($expectedDigest, $digest['sha256'])) {
                $this->assertStableLauncherPermissions($candidate);
            }
        }
        foreach ($candidates as $candidate) {
            $identity = @\lstat($candidate);
            if (!\is_array($identity)
                || !GatewayProjectStateFilesystem::removeRegular(
                    $candidate,
                    'Recovery Guardian publication candidate',
                    $identity,
                )
            ) {
                throw new \RuntimeException(
                    'Unable to retire a verified Recovery Guardian candidate.',
                );
            }
        }
    }

    private function hostId(): string
    {
        $file = $this->paths->hostIdFile();
        $existing = $this->readOptionalStableRegularFile(
            $file,
            33,
            'Gateway host identity',
        );
        if ($existing !== null) {
            $existing = \strtolower(\trim($existing));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $existing) !== 1) {
                throw new \RuntimeException('Gateway host identity is invalid.');
            }
            return $existing;
        }
        throw new \RuntimeException('Gateway host identity is missing.');
    }

    /** The caller must hold trust/package-install.lock. */
    private function ensureHostIdLocked(): string
    {
        $file = $this->paths->hostIdFile();
        $existing = $this->readOptionalStableRegularFile(
            $file,
            33,
            'Gateway host identity',
        );
        if ($existing !== null) {
            $existing = \strtolower(\trim($existing));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $existing) !== 1) {
                throw new \RuntimeException('Gateway host identity is invalid.');
            }
            return $existing;
        }
        $hostId = \bin2hex(\random_bytes(16));
        $this->atomicWrite($file, $hostId . "\n", 0600);
        return $hostId;
    }

    /** The caller must hold trust/package-install.lock. */
    private function ensureAdministratorCredentialLocked(): void
    {
        $file = $this->paths->adminTokenFile();
        $existing = $this->readOptionalStableRegularFile(
            $file,
            65,
            'Gateway administrator credential',
        );
        if ($existing !== null) {
            if (\preg_match(
                '/\A[a-f0-9]{64}\z/D',
                \strtolower(\trim($existing)),
            ) !== 1) {
                throw new \RuntimeException(
                    'Gateway administrator credential is invalid.'
                );
            }
            return;
        }
        $this->atomicWrite($file, \bin2hex(\random_bytes(32)) . "\n", 0600);
    }

    private function verifyReleaseSignature(string $manifest, string $signatureBytes, string $keyId): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')
            || $keyId === ''
        ) {
            throw new \RuntimeException('Gateway release signature prerequisites are unavailable.');
        }
        $keysFile = $this->trustedKeysFile
            ?? \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR . 'trusted-release-keys.json';
        $keys = \json_decode($this->readStableRegularFile(
            $keysFile,
            1_048_576,
            'Gateway trusted release key set',
        ), true);
        $key = null;
        foreach ((array)($keys['keys'] ?? []) as $candidate) {
            if (\is_array($candidate)
                && ($candidate['enabled'] ?? false) === true
                && \hash_equals($keyId, (string)($candidate['id'] ?? ''))
                && \hash_equals('ed25519', (string)($candidate['algorithm'] ?? ''))
            ) {
                $key = \base64_decode((string)($candidate['public_key_base64'] ?? ''), true);
                break;
            }
        }
        $signature = \base64_decode(\trim($signatureBytes), true);
        if (!\is_string($key)
            || \strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !\is_string($signature)
            || \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached($signature, $manifest, $key)
        ) {
            throw new \RuntimeException('Gateway release signature is not trusted.');
        }
    }

    private function readStableRegularFile(string $path, int $maximumBytes, string $label): string
    {
        return $this->consumeStableRegularFile($path, $maximumBytes, $label, true)['bytes'];
    }

    private function readOptionalStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
    ): ?string {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException($label . ' path is indeterminate or unsafe.');
            }
            return null;
        }
        return $this->readStableRegularFile($path, $maximumBytes, $label);
    }

    /**
     * @return array{
     *   slot_identity:array<string|int,mixed>,
     *   runtime_generation:string,
     *   installed_manifest:array{
     *     identity:array<string|int,mixed>,sha256:string,size:int
     *   },
     *   release_manifest:array{
     *     identity:array<string|int,mixed>,sha256:string,size:int
     *   }
     * }
     */
    private function firstActivationSlotFence(string $slot): array
    {
        $slotDirectory = $this->paths->slotDir($slot);
        $slotBefore = @\lstat($slotDirectory);
        if (!\is_array($slotBefore)
            || \is_link($slotDirectory)
            || ((((int)($slotBefore['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Gateway first-activation slot identity is unsafe.'
            );
        }
        $verification = $this->artifact->verify($slotDirectory, 'host_gateway');
        $runtimeGeneration = \strtolower(\trim((string)(
            $verification['runtime_generation'] ?? ''
        )));
        if (($verification['ok'] ?? false) !== true
            || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway first-activation slot is not an immutable verified runtime.'
            );
        }
        $installedManifest = $this->stableActivationManifestFence(
            $slotDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
            'Installed gateway slot manifest',
        );
        $decoded = \json_decode($installedManifest['bytes'], true);
        if (!\is_array($decoded)
            || !\hash_equals(
                $runtimeGeneration,
                \strtolower(\trim((string)($decoded['runtime_generation'] ?? ''))),
            )
        ) {
            throw new \RuntimeException(
                'Gateway first-activation manifest does not bind the verified runtime generation.'
            );
        }
        $releaseManifest = $this->stableActivationManifestFence(
            $slotDirectory . DIRECTORY_SEPARATOR . 'release'
                . DIRECTORY_SEPARATOR . 'manifest.json',
            'Installed gateway release manifest',
        );
        $caBaseline = $this->trustBundleBaselineProof(
            'Gateway first-activation CA trust baseline',
        );
        $slotAfter = @\lstat($slotDirectory);
        if (!\is_array($slotAfter)
            || !$this->sameFileState($slotBefore, $slotAfter)
        ) {
            throw new \RuntimeException(
                'Gateway first-activation slot changed while its identity was fenced.'
            );
        }

        return [
            'slot_identity' => $slotAfter,
            'runtime_generation' => $runtimeGeneration,
            'installed_manifest' => [
                'identity' => $installedManifest['identity'],
                'sha256' => $installedManifest['sha256'],
                'size' => $installedManifest['size'],
            ],
            'release_manifest' => [
                'identity' => $releaseManifest['identity'],
                'sha256' => $releaseManifest['sha256'],
                'size' => $releaseManifest['size'],
            ],
            'ca_bundle_baseline' => $caBaseline,
        ];
    }

    /**
     * @return array{
     *   bytes:string,
     *   identity:array<string|int,mixed>,
     *   sha256:string,
     *   size:int
     * }
     */
    private function stableActivationManifestFence(string $path, string $label): array
    {
        $before = @\lstat($path);
        $consumed = $this->consumeStableRegularFile(
            $path,
            16_777_216,
            $label,
            true,
        );
        $after = @\lstat($path);
        if (!\is_array($before)
            || !\is_array($after)
            || !$this->sameFileState($before, $after)
        ) {
            throw new \RuntimeException($label . ' changed while being fenced.');
        }
        return [
            'bytes' => $consumed['bytes'],
            'identity' => $after,
            'sha256' => $consumed['sha256'],
            'size' => $consumed['size'],
        ];
    }

    /**
     * @param array{
     *   slot_identity:array<string|int,mixed>,
     *   runtime_generation:string,
     *   installed_manifest:array{
     *     identity:array<string|int,mixed>,sha256:string,size:int
     *   },
     *   release_manifest:array{
     *     identity:array<string|int,mixed>,sha256:string,size:int
     *   }
     * } $expected
     */
    private function firstActivationAfterImageMatches(
        string $slot,
        array $expected,
    ): bool {
        try {
            if (!$this->atomicWriteCommittedAfterImageMatches(
                $this->paths->activeSlotFile(),
                $slot . "\n",
                0640,
            )
                || !\hash_equals($slot, $this->activeSlotOrEmpty())
            ) {
                return false;
            }
            foreach ([
                $this->paths->previousSlotFile(),
                $this->paths->upgradeIntentFile(),
            ] as $mustRemainAbsent) {
                if (\is_array(@\lstat($mustRemainAbsent))
                    || \file_exists($mustRemainAbsent)
                    || \is_link($mustRemainAbsent)
                ) {
                    return false;
                }
            }
            $actual = $this->firstActivationSlotFence($slot);
            if (!$this->firstActivationFenceMatches($expected, $actual)) {
                return false;
            }
            // A visible rename is not yet a durable activation on POSIX. Retry
            // the exact missing durability barrier, then re-prove both the
            // pointer receipt and immutable slot fence before accepting it.
            GatewayProjectStateFilesystem::syncDirectory(
                \dirname($this->paths->activeSlotFile()),
            );
            if (!$this->atomicWriteCommittedAfterImageMatches(
                $this->paths->activeSlotFile(),
                $slot . "\n",
                0640,
            )
                || !\hash_equals($slot, $this->activeSlotOrEmpty())
            ) {
                return false;
            }
            return $this->firstActivationFenceMatches(
                $expected,
                $this->firstActivationSlotFence($slot),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function firstActivationFenceMatches(array $expected, array $actual): bool
    {
        if (!\hash_equals(
            (string)$expected['runtime_generation'],
            (string)$actual['runtime_generation'],
        )
            || !$this->sameFileState(
                (array)$expected['slot_identity'],
                (array)$actual['slot_identity'],
            )
        ) {
            return false;
        }
        foreach (['installed_manifest', 'release_manifest'] as $manifest) {
            if (!$this->sameFileState(
                (array)$expected[$manifest]['identity'],
                (array)$actual[$manifest]['identity'],
            )
                || !\hash_equals(
                    (string)$expected[$manifest]['sha256'],
                    (string)$actual[$manifest]['sha256'],
                )
                || (int)$expected[$manifest]['size']
                    !== (int)$actual[$manifest]['size']
            ) {
                return false;
            }
        }
        if (!\is_array($expected['ca_bundle_baseline'] ?? null)
            || !\is_array($actual['ca_bundle_baseline'] ?? null)
            || !\hash_equals(
                (string)$expected['ca_bundle_baseline']['sha256'],
                (string)$actual['ca_bundle_baseline']['sha256'],
            )
            || !\is_array($expected['ca_bundle_baseline']['identity'] ?? null)
            || !\is_array($actual['ca_bundle_baseline']['identity'] ?? null)
            || !$this->sameFileState(
                $expected['ca_bundle_baseline']['identity'],
                $actual['ca_bundle_baseline']['identity'],
            )
        ) {
            return false;
        }
        return true;
    }

    private function activeSlotOrEmpty(): string
    {
        $contents = $this->readOptionalStableRegularFile(
            $this->paths->activeSlotFile(),
            2,
            'Gateway active-slot pointer',
        );
        if ($contents === null) {
            return '';
        }
        $slot = \strtoupper(\trim($contents));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \RuntimeException('Gateway active-slot pointer is invalid.');
        }
        return $slot;
    }

    /** @param array<string,mixed> $candidate */
    private function assertOrdinaryUpgradeTrustBundleStable(
        array $candidate,
        string $activeSlot,
    ): void {
        $active = $this->verifiedInstalledReleaseManifest(
            $activeSlot,
            'Gateway active trust baseline',
        );
        $candidateDigest = $this->releaseTrustBundleDigest(
            $candidate,
            'Gateway candidate trust baseline',
        );
        $activeDigest = $this->releaseTrustBundleDigest(
            $active,
            'Gateway active trust baseline',
        );
        $baseline = $this->trustBundleBaselineProof(
            'Gateway host CA trust baseline',
        );
        if (!\hash_equals($activeDigest, $candidateDigest)
            || !\hash_equals($activeDigest, (string)$baseline['sha256'])
        ) {
            throw new \RuntimeException(
                'Gateway CA trust baseline changes require an administrator-stopped full rebootstrap.'
            );
        }
    }

    private function assertInstalledTrustBundlesMatch(
        string $from,
        string $to,
    ): void {
        $fromManifest = $this->verifiedInstalledReleaseManifest(
            $from,
            'Gateway A/B source trust baseline',
        );
        $toManifest = $this->verifiedInstalledReleaseManifest(
            $to,
            'Gateway A/B candidate trust baseline',
        );
        $fromDigest = $this->releaseTrustBundleDigest(
            $fromManifest,
            'Gateway A/B source trust baseline',
        );
        $toDigest = $this->releaseTrustBundleDigest(
            $toManifest,
            'Gateway A/B candidate trust baseline',
        );
        $baseline = $this->trustBundleBaselineProof(
            'Gateway host CA trust baseline',
        );
        if (!\hash_equals($fromDigest, $toDigest)
            || !\hash_equals($fromDigest, (string)$baseline['sha256'])
        ) {
            throw new \RuntimeException(
                'Gateway A/B activation cannot change the signed CA trust baseline.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function verifiedInstalledReleaseManifest(
        string $slot,
        string $label,
    ): array {
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \RuntimeException($label . ' slot is invalid.');
        }
        $verification = $this->artifact->verify(
            $this->paths->slotDir($slot),
            'host_gateway',
        );
        if (!($verification['ok'] ?? false)) {
            throw new \RuntimeException($label . ' immutable slot is invalid.');
        }
        $manifest = \json_decode($this->readStableRegularFile(
            $this->paths->slotDir($slot) . DIRECTORY_SEPARATOR . 'release'
                . DIRECTORY_SEPARATOR . 'manifest.json',
            16_777_216,
            $label . ' release manifest',
        ), true);
        if (!\is_array($manifest) || \array_is_list($manifest)) {
            throw new \RuntimeException($label . ' release manifest is invalid.');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function releaseTrustBundleDigest(
        array $manifest,
        string $label,
    ): string {
        $digest = \strtolower(\trim((string)(
            $manifest['components']['share/ca-bundle.pem']['sha256'] ?? ''
        )));
        if (($manifest['capabilities']['certificate_public_trust_bundle'] ?? false)
                !== true
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || (int)($manifest['components']['share/ca-bundle.pem']['size'] ?? 0)
                < 1
            || (int)($manifest['components']['share/ca-bundle.pem']['size'] ?? 0)
                > 4_194_304
            || (int)($manifest['components']['share/ca-bundle.pem']['mode'] ?? 0)
                !== 0644
        ) {
            throw new \RuntimeException(
                $label . ' lacks the signed immutable CA trust component.'
            );
        }
        return $digest;
    }

    /** @return array{sha256:string,identity:array<string|int,mixed>} */
    private function trustBundleBaselineProof(string $label): array
    {
        $path = $this->paths->caBundleBaselineFile();
        $before = $this->assertHostRecoveryFileAuthority($path, 0600, $label);
        $contents = $this->readHostRecoveryFile($path, 65, 0600, $label);
        $after = $this->assertHostRecoveryFileAuthority($path, 0600, $label);
        $digest = \strtolower(\trim($contents));
        if (!$this->sameFileState($before, $after)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($digest . "\n", $contents)
        ) {
            throw new \RuntimeException($label . ' is malformed or unstable.');
        }
        return [
            'sha256' => $digest,
            'identity' => $after,
        ];
    }

    /** @return array{sha256:string,identity:array<string|int,mixed>} */
    private function ensureTrustBundleBaselineLocked(string $digest): array
    {
        $digest = \strtolower(\trim($digest));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new \RuntimeException(
                'Gateway CA trust baseline candidate digest is invalid.',
            );
        }
        $path = $this->paths->caBundleBaselineFile();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Gateway CA trust baseline path is indeterminate or unsafe.',
                );
            }
            $contents = $digest . "\n";
            try {
                $this->atomicWrite($path, $contents, 0600);
            } catch (\Throwable $throwable) {
                if (!$this->atomicWriteCommittedAfterImageMatches(
                    $path,
                    $contents,
                    0600,
                )) {
                    throw $throwable;
                }
            }
            // The Windows trust directory is Controller-readable. A newly
            // inherited baseline must be re-sealed to SYSTEM/Administrators
            // before either the PHP or native launcher proof can consume it.
            ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
                ->securePackageTransactionTrust($this->activeOperationDeadline());
        }
        $proof = $this->trustBundleBaselineProof(
            'Gateway host CA trust baseline',
        );
        if (!\hash_equals($digest, $proof['sha256'])) {
            throw new \RuntimeException(
                'Gateway CA trust baseline changes require an administrator-stopped full rebootstrap.',
            );
        }
        return $proof;
    }

    private function launcherDigestBackedByInstalledSlot(string $digest): bool
    {
        return $this->launcherSourceBackedByInstalledSlot($digest) !== null;
    }

    private function launcherSourceBackedByInstalledSlot(string $digest): ?string
    {
        $slots = [];
        $active = $this->activeSlotOrEmpty();
        if ($active !== '') {
            $slots[] = $active;
        }
        foreach (['A', 'B'] as $slot) {
            if (!\in_array($slot, $slots, true)) {
                $slots[] = $slot;
            }
        }
        $launcherComponent = $this->componentPath('wls-gateway-launcher');
        foreach ($slots as $slot) {
            $slotDirectory = $this->paths->slotDir($slot);
            try {
                $verification = $this->artifact->verify($slotDirectory, 'host_gateway');
            } catch (\Throwable) {
                continue;
            }
            if (!($verification['ok'] ?? false)) {
                continue;
            }
            try {
                $release = \json_decode($this->readStableRegularFile(
                    $slotDirectory . DIRECTORY_SEPARATOR . 'release'
                        . DIRECTORY_SEPARATOR . 'manifest.json',
                    16_777_216,
                    'Installed gateway release manifest',
                ), true);
            } catch (\Throwable) {
                continue;
            }
            $declared = \is_array($release)
                ? \strtolower(\trim((string)(
                    $release['components'][$launcherComponent]['sha256'] ?? ''
                )))
                : '';
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $declared) === 1
                && \hash_equals($declared, $digest)
            ) {
                $source = $slotDirectory . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, $launcherComponent);
                try {
                    $actual = $this->digestStableRegularFile(
                        $source,
                        self::MAX_PACKAGE_BYTES,
                        'Installed stable gateway launcher',
                    );
                } catch (\Throwable) {
                    continue;
                }
                if ($actual['size'] > 0
                    && \hash_equals($digest, $actual['sha256'])
                ) {
                    return $source;
                }
            }
        }
        return null;
    }

    private function assertStableLauncherPermissions(string $path): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $status = @\lstat($path);
        $expectedMode = $this->stableLauncherPosixMode();
        if (!\is_array($status)
            || !$this->isRegularFileStatus($status)
            || (int)($status['nlink'] ?? 0) !== 1
            || (!$this->paths->isTestMode()
                && (int)($status['uid'] ?? -1) !== 0)
            || ((((int)($status['mode'] ?? 0)) & 0777) !== $expectedMode)
        ) {
            throw new \RuntimeException('Stable gateway launcher permissions are unsafe.');
        }
    }

    private function stableLauncherPosixMode(): int
    {
        return $this->paths->isTestMode() ? 0755 : 0550;
    }

    private function copyStableLauncher(
        string $source,
        string $target,
        string $expectedDigest,
    ): void {
        $sourceStatus = @\lstat($source);
        $targetParent = \dirname($target);
        $parentStatus = @\lstat($targetParent);
        if (!\is_array($sourceStatus)
            || !$this->isRegularFileStatus($sourceStatus)
            || (int)($sourceStatus['nlink'] ?? 0) !== 1
            || (int)($sourceStatus['size'] ?? -1) < 1
            || (int)($sourceStatus['size'] ?? -1) > self::MAX_PACKAGE_BYTES
            || \is_link($source)
            || !\is_array($parentStatus)
            || \is_link($targetParent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || \file_exists($target)
            || \is_link($target)
        ) {
            throw new \RuntimeException('Stable gateway launcher publication path is unsafe.');
        }
        $sourceHandle = @\fopen($source, 'rb');
        $temporary = $target . '.candidate.' . \bin2hex(\random_bytes(8));
        $targetHandle = @\fopen($temporary, 'xb');
        if (!\is_resource($sourceHandle) || !\is_resource($targetHandle)) {
            \is_resource($sourceHandle) && @\fclose($sourceHandle);
            \is_resource($targetHandle) && @\fclose($targetHandle);
            @\unlink($temporary);
            throw new \RuntimeException('Unable to stage the stable gateway launcher.');
        }
        $failure = null;
        try {
            $opened = @\fstat($sourceHandle);
            if (!\is_array($opened)
                || !$this->sameFileState($sourceStatus, $opened)
                || !$this->isRegularFileStatus($opened)
                || (int)($opened['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException(
                    'Stable gateway launcher source changed before copying.'
                );
            }
            $hash = \hash_init('sha256');
            $size = 0;
            while (!\feof($sourceHandle)) {
                $chunk = @\fread($sourceHandle, 1_048_576);
                if (!\is_string($chunk)
                    || ($chunk === '' && !\feof($sourceHandle))
                ) {
                    throw new \RuntimeException(
                        'Unable to read the stable gateway launcher source.'
                    );
                }
                if ($chunk === '') {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > self::MAX_PACKAGE_BYTES) {
                    throw new \RuntimeException('Stable gateway launcher is too large.');
                }
                \hash_update($hash, $chunk);
                $this->writeAll($targetHandle, $chunk);
            }
            $after = @\fstat($sourceHandle);
            $pathAfter = @\lstat($source);
            $actualDigest = \hash_final($hash);
            if (!\is_array($after)
                || !\is_array($pathAfter)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
                || (int)($after['size'] ?? -1) !== $size
                || !\hash_equals($expectedDigest, $actualDigest)
            ) {
                throw new \RuntimeException(
                    'Stable gateway launcher source changed or failed identity verification.'
                );
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                $mode = $this->stableLauncherPosixMode();
                $uid = isset($parentStatus['uid']) ? (int)$parentStatus['uid'] : -1;
                $gid = isset($parentStatus['gid']) ? (int)$parentStatus['gid'] : -1;
                $ownerOk = $uid >= 0 && (
                    \function_exists('fchown')
                        ? @\fchown($targetHandle, $uid)
                        : @\chown($temporary, $uid)
                );
                $groupOk = $gid >= 0 && (
                    \function_exists('fchgrp')
                        ? @\fchgrp($targetHandle, $gid)
                        : @\chgrp($temporary, $gid)
                );
                $modeOk = \function_exists('fchmod')
                    ? @\fchmod($targetHandle, $mode)
                    : @\chmod($temporary, $mode);
                if (!$ownerOk || !$groupOk || !$modeOk) {
                    throw new \RuntimeException(
                        'Unable to seal stable gateway launcher permissions.'
                    );
                }
            }
            if (!@\fflush($targetHandle)
                || (\function_exists('fsync') && !@\fsync($targetHandle))
            ) {
                throw new \RuntimeException(
                    'Unable to durably stage the stable gateway launcher.'
                );
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($targetHandle);
            @\fclose($sourceHandle);
        }
        if ($failure instanceof \Throwable) {
            @\unlink($temporary);
            throw $failure;
        }
        if (!@\rename($temporary, $target)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish the stable gateway launcher.');
        }
        $published = $this->digestStableRegularFile(
            $target,
            self::MAX_PACKAGE_BYTES,
            'Published stable gateway launcher',
        );
        if (!\hash_equals($expectedDigest, $published['sha256'])) {
            throw new \RuntimeException('Published stable gateway launcher identity changed.');
        }
        $this->assertStableLauncherPermissions($target);
        $this->syncParentDirectory($targetParent, 'stable gateway launcher');
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $offset = 0;
        $length = \strlen($contents);
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Unable to write a gateway host file.');
            }
            $offset += $written;
        }
    }

    private function syncParentDirectory(string $directory, string $label): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return;
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . ' parent for sync.');
        }
        try {
            if (!@\fsync($handle)) {
                throw new \RuntimeException('Unable to durably publish ' . $label . '.');
            }
        } finally {
            @\fclose($handle);
        }
    }

    /** @return array{sha256:string,size:int} */
    private function digestStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
    ): array {
        $consumed = $this->consumeStableRegularFile($path, $maximumBytes, $label, false);
        return [
            'sha256' => $consumed['sha256'],
            'size' => $consumed['size'],
        ];
    }

    /** @return array{bytes:string,sha256:string,size:int} */
    private function consumeStableRegularFile(
        string $path,
        int $maximumBytes,
        string $label,
        bool $captureBytes,
    ): array {
        $pathStatus = @\lstat($path);
        if ($maximumBytes < 1
            || !\is_array($pathStatus)
            || !$this->isRegularFileStatus($pathStatus)
            || (int)($pathStatus['nlink'] ?? 0) !== 1
            || (int)($pathStatus['size'] ?? -1) < 0
            || \is_link($path)
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
                || !$this->isRegularFileStatus($openedStatus)
                || (int)($openedStatus['nlink'] ?? 0) !== 1
                || (int)($openedStatus['size'] ?? -1) > $maximumBytes
            ) {
                throw new \RuntimeException($label . ' changed before verification.');
            }
            $hash = \hash_init('sha256');
            $bytes = '';
            $size = 0;
            while (!\feof($handle)) {
                $this->assertOperationDeadlineAvailable(
                    'reading ' . $label,
                );
                $chunk = @\fread($handle, 1_048_576);
                if (!\is_string($chunk) || ($chunk === '' && !\feof($handle))) {
                    throw new \RuntimeException('Unable to read ' . $label . '.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > $maximumBytes) {
                    throw new \RuntimeException($label . ' exceeds its fixed size limit.');
                }
                \hash_update($hash, $chunk);
                if ($captureBytes) {
                    $bytes .= $chunk;
                }
            }
            $afterStatus = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($afterStatus)
                || !\is_array($pathAfter)
                || !$this->sameFileState($openedStatus, $afterStatus)
                || !$this->sameFileState($afterStatus, $pathAfter)
                || (int)($afterStatus['size'] ?? -1) !== $size
            ) {
                throw new \RuntimeException($label . ' changed during verification.');
            }
            return [
                'bytes' => $bytes,
                'sha256' => \hash_final($hash),
                'size' => $size,
            ];
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $status */
    private function isRegularFileStatus(array $status): bool
    {
        return (((int)($status['mode'] ?? 0)) & 0170000) === 0100000;
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

    /**
     * @param array<string,array<string,mixed>>|null $provenance
     */
    private function verifySbom(string $bytes, ?array $provenance = null): void
    {
        $decoded = \json_decode($bytes, true);
        if (!\is_array($decoded)
            || !\hash_equals('CycloneDX', (string)($decoded['bomFormat'] ?? ''))
            || !\is_array($decoded['components'] ?? null)
            || $decoded['components'] === []
            || \count($decoded['components']) > self::MAX_PACKAGE_COMPONENTS
        ) {
            throw new \RuntimeException('Gateway CycloneDX SBOM is missing or invalid.');
        }
        if ($provenance === null) {
            return;
        }
        $sbomComponents = [];
        foreach ($decoded['components'] as $component) {
            if (\is_array($component)
                && \is_string($component['name'] ?? null)
                && (string)$component['name'] !== ''
            ) {
                $sbomComponents[(string)$component['name']] = $component;
            }
        }
        foreach ($provenance as $name => $definition) {
            $component = $sbomComponents[$name] ?? null;
            $hashMatched = false;
            foreach ((array)($component['hashes'] ?? []) as $hash) {
                if (\is_array($hash)
                    && \hash_equals('SHA-256', (string)($hash['alg'] ?? ''))
                    && \hash_equals(
                        (string)$definition['binary_sha256'],
                        \strtolower((string)($hash['content'] ?? '')),
                    )
                ) {
                    $hashMatched = true;
                    break;
                }
            }
            $licenseMatched = false;
            foreach ((array)($component['licenses'] ?? []) as $license) {
                if (\is_array($license)
                    && \hash_equals(
                        (string)$definition['license'],
                        (string)($license['license']['name'] ?? ''),
                    )
                ) {
                    $licenseMatched = true;
                    break;
                }
            }
            if (!\is_array($component)
                || !\hash_equals(
                    (string)$definition['version'],
                    (string)($component['version'] ?? ''),
                )
                || !$hashMatched
                || !$licenseMatched
            ) {
                throw new \RuntimeException(
                    'Gateway CycloneDX SBOM does not match provenance: ' . $name
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,array<string,mixed>>
     */
    private function verifyProductionProvenance(
        string $bytes,
        array $manifest,
    ): array {
        $decoded = \json_decode($bytes, true);
        if (!\is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0) !== 1
            || !\hash_equals(
                (string)$manifest['platform'],
                (string)($decoded['target']['platform'] ?? ''),
            )
            || !\hash_equals(
                $this->normalizeArch((string)$manifest['arch']),
                $this->normalizeArch((string)($decoded['target']['arch'] ?? '')),
            )
            || !\is_array($decoded['components'] ?? null)
            || \count($decoded['components']) > self::MAX_PACKAGE_COMPONENTS
        ) {
            throw new \RuntimeException('Gateway production provenance is missing or invalid.');
        }
        $suffix = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $files = [
            'controller' => 'app/controller.php',
            'ca-bundle' => 'share/ca-bundle.pem',
            'php' => 'bin/php' . $suffix,
            'nginx' => 'bin/nginx' . $suffix,
            'wls-gateway-broker' => 'bin/wls-gateway-broker' . $suffix,
            'wls-gateway-launcher' => 'bin/wls-gateway-launcher' . $suffix,
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $files['wls-gateway-guardian'] = 'bin/wls-gateway-guardian.exe';
            $files['wls-bounded-command'] = 'bin/wls-bounded-command.exe';
        }
        $expectedNames = \array_keys($files);
        $actualNames = \array_keys($decoded['components']);
        foreach ($actualNames as $name) {
            if (!\is_string($name)) {
                throw new \RuntimeException(
                    'Gateway production provenance component names must be strings.'
                );
            }
        }
        \sort($expectedNames, SORT_STRING);
        \sort($actualNames, SORT_STRING);
        if ($actualNames !== $expectedNames) {
            throw new \RuntimeException(
                'Gateway production provenance component topology is not the locked WLS 2.0 set.'
            );
        }
        $verified = [];
        foreach ($files as $name => $relative) {
            $definition = $decoded['components'][$name] ?? null;
            $componentDigest = \strtolower((string)(
                $manifest['components'][$relative]['sha256'] ?? ''
            ));
            if (!\is_array($definition)
                || \trim((string)($definition['version'] ?? '')) === ''
                || \trim((string)($definition['source_url'] ?? '')) === ''
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['source_sha256'] ?? '')),
                ) !== 1
                || \trim((string)($definition['license'] ?? '')) === ''
                || !\hash_equals(
                    \strtolower((string)($definition['binary_sha256'] ?? '')),
                    $componentDigest,
                )
                || (!\in_array($name, ['controller', 'ca-bundle'], true)
                    && ($definition['self_contained'] ?? false) !== true)
            ) {
                throw new \RuntimeException(
                    'Gateway production provenance does not match component: ' . $name
                );
            }
            $definition['binary_sha256'] = \strtolower(
                (string)$definition['binary_sha256'],
            );
            $verified[$name] = $definition;
        }
        return $verified;
    }

    private function assertCertificateTrustBundle(string $source): void
    {
        if ($source === '' || \strlen($source) > 4_194_304) {
            throw new \RuntimeException(
                'Gateway certificate trust bundle is outside its fixed size bound.'
            );
        }
        $matches = [];
        $count = \preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $source,
            $matches,
        );
        $remainder = \preg_replace(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            '',
            $source,
        );
        if (!\is_int($count)
            || $count < 1
            || $count > 512
            || !\is_string($remainder)
            || \trim($remainder) !== ''
        ) {
            throw new \RuntimeException(
                'Gateway certificate trust bundle has an invalid PEM topology.'
            );
        }
        $roots = [];
        foreach ((array)($matches[0] ?? []) as $block) {
            $certificate = @\openssl_x509_read((string)$block);
            $normalized = '';
            $parsed = $certificate !== false
                ? @\openssl_x509_parse($certificate, false)
                : false;
            if ($certificate === false
                || !\is_array($parsed)
                || !@\openssl_x509_export($certificate, $normalized, true)
            ) {
                throw new \RuntimeException(
                    'Gateway certificate trust bundle contains invalid X.509 material.'
                );
            }
            $basicConstraints = \strtoupper((string)(
                $parsed['extensions']['basicConstraints'] ?? ''
            ));
            $keyUsage = \strtolower((string)(
                $parsed['extensions']['keyUsage'] ?? ''
            ));
            $subject = GatewayClient::canonicalJson(
                (array)($parsed['subject'] ?? []),
            );
            $issuer = GatewayClient::canonicalJson(
                (array)($parsed['issuer'] ?? []),
            );
            $publicKey = @\openssl_pkey_get_public($certificate);
            if (!\str_contains($basicConstraints, 'CA:TRUE')
                || ($keyUsage !== ''
                    && !\str_contains($keyUsage, 'certificate sign')
                    && !\str_contains($keyUsage, 'key cert sign'))
                || !\hash_equals($subject, $issuer)
                || $publicKey === false
                || @\openssl_x509_verify($certificate, $publicKey) !== 1
            ) {
                throw new \RuntimeException(
                    'Gateway certificate trust bundle contains a non-root authority.'
                );
            }
            $body = \preg_replace(
                '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
                '',
                $normalized,
            );
            $der = \is_string($body) ? \base64_decode($body, true) : false;
            if (!\is_string($der) || $der === '') {
                throw new \RuntimeException(
                    'Gateway certificate trust root cannot be normalized to DER.'
                );
            }
            $fingerprint = \hash('sha256', $der);
            if (isset($roots[$fingerprint])) {
                throw new \RuntimeException(
                    'Gateway certificate trust bundle contains duplicate roots.'
                );
            }
            $roots[$fingerprint] = \rtrim($normalized) . "\n";
        }
        \ksort($roots, SORT_STRING);
        if (!\hash_equals(\implode('', $roots), $source)) {
            throw new \RuntimeException(
                'Gateway certificate trust bundle is not canonical.'
            );
        }
    }

    /** @param array<string,mixed> $manifest */
    private function verifiedComponentBytes(
        string $package,
        string $relative,
        array $manifest,
    ): string {
        $definition = $manifest['components'][$relative] ?? null;
        if (!\is_array($definition)) {
            throw new \RuntimeException(
                'Gateway package metadata component is undeclared: ' . $relative
            );
        }
        $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
        $expectedSize = (int)($definition['size'] ?? -1);
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
            || $expectedSize < 0
            || $expectedSize > 33_554_432
        ) {
            throw new \RuntimeException(
                'Gateway package metadata component contract is invalid: ' . $relative
            );
        }
        $path = $package . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $consumed = $this->consumeStableRegularFile(
            $path,
            33_554_432,
            'Gateway package metadata component ' . $relative,
            true,
        );
        if (!\hash_equals($expectedDigest, $consumed['sha256'])
            || $expectedSize !== $consumed['size']
        ) {
            throw new \RuntimeException(
                'Gateway package metadata component changed after verification: ' . $relative
            );
        }
        return $consumed['bytes'];
    }

    /** @param array<string,mixed> $components */
    private function assertPackageComponentTopology(array $components): void
    {
        $identities = [];
        $directories = [];
        foreach ($components as $relative => $definition) {
            if (!\is_string($relative) || !\is_array($definition)) {
                throw new \RuntimeException(
                    'Gateway package component envelope is invalid.'
                );
            }
            $canonical = $this->validateRelativePath($relative);
            if (!\hash_equals($relative, $canonical)) {
                throw new \RuntimeException(
                    'Gateway package component path is not canonical: ' . $relative
                );
            }
            $identity = \strtolower($canonical);
            if (\hash_equals('release', $identity)
                || \str_starts_with($identity, 'release/')
            ) {
                throw new \RuntimeException(
                    'Gateway package component path uses the reserved installed release namespace: '
                        . $relative
                );
            }
            if (isset($identities[$identity])) {
                throw new \RuntimeException(
                    'Gateway package component paths collide across supported filesystems: '
                        . $relative
                );
            }
            if (isset($directories[$identity])) {
                throw new \RuntimeException(
                    'Gateway package component path collides with a declared directory: '
                        . $relative
                );
            }
            $identities[$identity] = true;
            $segments = \explode('/', $identity);
            \array_pop($segments);
            while ($segments !== []) {
                $directory = \implode('/', $segments);
                if (isset($identities[$directory])) {
                    throw new \RuntimeException(
                        'Gateway package component path collides with a declared directory: '
                            . $relative
                    );
                }
                $directories[$directory] = true;
                if (\count($directories) > self::MAX_PACKAGE_DIRECTORIES) {
                    throw new \RuntimeException(
                        'Gateway release manifest exceeds its fixed directory limit.'
                    );
                }
                \array_pop($segments);
            }
        }
    }

    private function validateRelativePath(string $relative): string
    {
        if ($relative === ''
            || !\hash_equals($relative, \trim($relative))
            || \strlen($relative) > 1024
            || \str_contains($relative, '\\')
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:/', $relative) === 1
            || \str_contains($relative, "\0")
            || \preg_match('/\A[\x20-\x7e]+\z/D', $relative) !== 1
        ) {
            throw new \RuntimeException('Gateway package paths must be relative and contained.');
        }
        $segments = \explode('/', $relative);
        if (\count($segments) > self::MAX_PACKAGE_PATH_DEPTH) {
            throw new \RuntimeException(
                'Gateway package path exceeds its fixed directory depth limit.'
            );
        }
        foreach ($segments as $segment) {
            $base = \strtoupper((string)\strtok($segment, '.'));
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \strlen($segment) > 255
                || \preg_match('/[\x00-\x1f\x7f:*?"<>|]/D', $segment) === 1
                || \str_ends_with($segment, '.')
                || \str_ends_with($segment, ' ')
                || \in_array($base, [
                    'CON', 'PRN', 'AUX', 'NUL', 'CONIN$', 'CONOUT$',
                    'COM1', 'COM2', 'COM3', 'COM4', 'COM5',
                    'COM6', 'COM7', 'COM8', 'COM9',
                    'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5',
                    'LPT6', 'LPT7', 'LPT8', 'LPT9',
                ], true)
            ) {
                throw new \RuntimeException(
                    'Gateway package paths contain an unsafe platform segment.'
                );
            }
        }
        return $relative;
    }

    private function normalizedArch(): string
    {
        return $this->normalizeArch((string)\php_uname('m'));
    }

    /** @return list<string> */
    private function requiredComponents(): array
    {
        $required = [
            'app/controller.php',
            $this->componentPath('nginx'),
            $this->componentPath('php'),
            $this->componentPath('wls-gateway-broker'),
            $this->componentPath('wls-gateway-launcher'),
            'LICENSES.txt',
            'provenance.json',
            'share/ca-bundle.pem',
            'sbom.cdx.json',
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $required[] = $this->componentPath('wls-gateway-guardian');
            $required[] = $this->componentPath('wls-bounded-command');
        }

        return $required;
    }

    private function expectedPackageComponentMode(string $relative): int
    {
        if (\in_array($relative, [
            'app/controller.php',
            'LICENSES.txt',
            'provenance.json',
            'share/ca-bundle.pem',
            'sbom.cdx.json',
        ], true)) {
            return 0644;
        }

        $executables = [
            $this->componentPath('nginx'),
            $this->componentPath('php'),
            $this->componentPath('wls-gateway-broker'),
            $this->componentPath('wls-gateway-launcher'),
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $executables[] = $this->componentPath('wls-gateway-guardian');
            $executables[] = $this->componentPath('wls-bounded-command');
        }
        if (\in_array($relative, $executables, true)) {
            return 0755;
        }

        throw new \RuntimeException(
            'Gateway package component path is outside the locked WLS 2.0 release set: '
                . $relative
        );
    }

    private function componentPath(string $name): string
    {
        return 'bin/' . $name . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    /**
     * @param array<string,mixed> $verified
     * @return array<string,array<string,mixed>>
     */
    private function packageComponentsForInstall(array $verified): array
    {
        $components = [];
        foreach ((array)$verified['manifest']['components'] as $relative => $definition) {
            if (!\is_array($definition)) {
                throw new \RuntimeException(
                    'Gateway verified package component definition is invalid.'
                );
            }
            $components[(string)$relative] = [
                'source' => (string)$verified['package_dir'] . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, (string)$relative),
                'mode' => $this->installedComponentMode((int)$definition['mode']),
                'sha256' => (string)$definition['sha256'],
                'size' => (int)$definition['size'],
            ];
        }
        $components['release/manifest.json'] = [
            'contents' => (string)$verified['manifest_bytes'],
            'mode' => $this->installedComponentMode(0600),
            'sha256' => (string)$verified['package_digest'],
            'size' => \strlen((string)$verified['manifest_bytes']),
        ];
        if ((string)$verified['signature_bytes'] !== '') {
            $components['release/manifest.sig'] = [
                'contents' => (string)$verified['signature_bytes'],
                'mode' => $this->installedComponentMode(0600),
                'sha256' => \hash('sha256', (string)$verified['signature_bytes']),
                'size' => \strlen((string)$verified['signature_bytes']),
            ];
        }
        return $components;
    }

    /**
     * @return array{
     *   active_slot:string,
     *   previous_slot:string,
     *   launcher_sha256:string,
     *   launcher_size:int,
     *   launcher_mode:int,
     *   ca_bundle_sha256:string,
     *   slots:array{A:array<string,string>|null,B:array<string,string>|null}
     * }
     */
    private function verifiedRebootstrapOldGeneration(): array
    {
        $active = $this->activeSlotOrEmpty();
        if (!\in_array($active, ['A', 'B'], true)) {
            throw new \RuntimeException(
                'Gateway rebootstrap requires an existing active A/B generation.'
            );
        }
        $slots = [];
        foreach (['A', 'B'] as $slot) {
            $directory = $this->paths->slotDir($slot);
            if (\file_exists($directory) || \is_link($directory)) {
                if (!\is_dir($directory) || \is_link($directory)) {
                    throw new \RuntimeException(
                        'Gateway old slot namespace is unsafe: ' . $slot
                    );
                }
                $slots[] = $slot;
            }
        }
        if (!\in_array($active, $slots, true)) {
            throw new \RuntimeException(
                'Gateway active slot is missing before rebootstrap.'
            );
        }
        $proof = $this->verifiedStableLauncherUpgradeProof(
            $slots,
            'Gateway rebootstrap old generation',
        );
        $previousBytes = $this->readOptionalStableRegularFile(
            $this->paths->previousSlotFile(),
            2,
            'Gateway previous-slot pointer',
        );
        $previous = $previousBytes === null
            ? ''
            : \strtoupper(\trim($previousBytes));
        if (!\in_array($previous, ['', 'A', 'B'], true)
            || ($previous !== '' && !isset($proof['slots'][$previous]))
        ) {
            throw new \RuntimeException(
                'Gateway previous-slot pointer is not bound to the old generation.'
            );
        }
        $journalSlots = ['A' => null, 'B' => null];
        foreach ((array)$proof['slots'] as $slot => $slotProof) {
            $journalSlots[(string)$slot] = [
                'slot' => (string)$slot,
                'runtime_generation' => (string)$slotProof['runtime_generation'],
                'package_digest' => (string)$slotProof['package_digest'],
                'launcher_sha256' => (string)$slotProof['launcher_sha256'],
            ];
        }
        return [
            'active_slot' => $active,
            'previous_slot' => $previous,
            'launcher_sha256' => (string)$proof['launcher_sha256'],
            'launcher_size' => (int)$proof['launcher_size'],
            'launcher_mode' => $this->stableLauncherPosixMode(),
            'ca_bundle_sha256' => (string)$proof['ca_bundle_sha256'],
            'slots' => $journalSlots,
        ];
    }

    /** @param array<string,mixed> $journal */
    private function assertSameRebootstrapRequest(
        array $journal,
        string $nonce,
        string $packageDigest,
        string $profile,
    ): void {
        if (!\hash_equals((string)$journal['nonce'], $nonce)
            || !\hash_equals((string)$journal['package_digest'], $packageDigest)
            || !\hash_equals((string)$journal['profile'], $profile)
        ) {
            throw new \RuntimeException(
                'A different gateway rebootstrap nonce, package, or profile already owns this host.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function requiredRebootstrapJournalLocked(
        string $nonce,
        string $packageDigest,
        string $profile,
    ): array {
        $journal = $this->readRebootstrapJournalLocked();
        if ($journal === null) {
            throw new \RuntimeException(
                'Gateway rebootstrap journal is missing.'
            );
        }
        $this->assertSameRebootstrapRequest(
            $journal,
            $nonce,
            $packageDigest,
            $profile,
        );
        return $journal;
    }

    /** @param array<string,mixed> $journal */
    private function assertPreparedRebootstrapCandidate(array $journal): void
    {
        $phase = (string)$journal['phase'];
        if (\hash_equals('PREPARING', $phase)
            || (string)$journal['runtime_generation'] === ''
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap candidate is not fully prepared.'
            );
        }
        if (\in_array($phase, [
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
            'OBSERVING',
            'COMMITTED',
            'ROLLING_BACK',
            'ROLLED_BACK',
        ], true)) {
            return;
        }
        $candidate = $this->paths->rebootstrapCandidateDir(
            (string)$journal['nonce'],
        );
        $candidateExists = \file_exists($candidate) || \is_link($candidate);
        $publishedTarget = $this->paths->slotDir('A');
        $targetExists = \file_exists($publishedTarget)
            || \is_link($publishedTarget);
        if (\hash_equals('OLD_GENERATION_STASHED', $phase)) {
            if ($candidateExists === $targetExists) {
                throw new \RuntimeException(
                    'Gateway rebootstrap candidate must exist at exactly one publication location.'
                );
            }
            $candidate = $candidateExists ? $candidate : $publishedTarget;
        } elseif (!$candidateExists) {
            throw new \RuntimeException(
                'Gateway rebootstrap prepared candidate is missing.'
            );
        }
        $verification = $this->artifact->verify($candidate, 'host_gateway');
        if (($verification['ok'] ?? false) !== true
            || !\hash_equals(
                (string)$journal['runtime_generation'],
                (string)($verification['runtime_generation'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap prepared candidate identity is invalid.'
            );
        }
        $launcher = $candidate . DIRECTORY_SEPARATOR
            . \str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $this->componentPath('wls-gateway-launcher'),
            );
        $digest = $this->digestStableRegularFile(
            $launcher,
            self::MAX_PACKAGE_BYTES,
            'Gateway rebootstrap prepared candidate launcher',
        );
        if (!\hash_equals(
                (string)$journal['candidate_launcher_sha256'],
                $digest['sha256'],
            )
            || (int)$journal['candidate_launcher_size'] !== $digest['size']
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap prepared launcher identity is invalid.'
            );
        }
        $candidateCa = $this->verifiedRebootstrapCandidateTrustBundle(
            $candidate,
            'Gateway rebootstrap prepared candidate',
        );
        if (!\hash_equals(
            (string)$journal['candidate_ca_bundle_sha256'],
            $candidateCa,
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap prepared CA trust identity is invalid.',
            );
        }
    }

    private function verifiedRebootstrapCandidateTrustBundle(
        string $candidateDirectory,
        string $label,
    ): string {
        $releaseFile = $candidateDirectory . DIRECTORY_SEPARATOR . 'release'
            . DIRECTORY_SEPARATOR . 'manifest.json';
        $release = \json_decode($this->readStableRegularFile(
            $releaseFile,
            16_777_216,
            $label . ' signed release manifest',
        ), true);
        if (!\is_array($release) || \array_is_list($release)) {
            throw new \RuntimeException($label . ' release manifest is invalid.');
        }
        $expected = $this->releaseTrustBundleDigest(
            $release,
            $label . ' CA trust bundle',
        );
        $definition = \is_array(
            $release['components']['share/ca-bundle.pem'] ?? null,
        ) ? $release['components']['share/ca-bundle.pem'] : [];
        $actual = $this->digestStableRegularFile(
            $candidateDirectory . DIRECTORY_SEPARATOR . 'share'
                . DIRECTORY_SEPARATOR . 'ca-bundle.pem',
            4_194_304,
            $label . ' installed CA trust bundle',
        );
        if (!\hash_equals($expected, (string)$actual['sha256'])
            || (int)($definition['size'] ?? 0) !== (int)$actual['size']
        ) {
            throw new \RuntimeException(
                $label . ' signed and installed CA trust bundle differ.',
            );
        }
        return $expected;
    }

    private function installedComponentMode(int $packageMode): int
    {
        if ($this->paths->isTestMode() || \PHP_OS_FAMILY === 'Windows') {
            return $packageMode;
        }
        // The immutable host slot is root-owned and readable by the dedicated
        // Controller group. Record the final sealed mode in the runtime
        // manifest so post-seal verification checks the actual trust state
        // rather than the more permissive package transport mode.
        return ($packageMode & 0111) !== 0 ? 0555 : 0444;
    }

    private function normalizeArch(string $arch): string
    {
        return match (\strtolower(\trim($arch))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower(\trim($arch)),
        };
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return \str_starts_with($path . '/', $root . '/');
    }

    private function normalizeRebootstrapNonce(string $nonce): string
    {
        $nonce = \strtolower(\trim($nonce));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway rebootstrap nonce must be 32 lowercase hexadecimal characters.'
            );
        }
        return $nonce;
    }

    private function assertNoRebootstrapTransactionLocked(string $operation): void
    {
        $journal = $this->readRebootstrapJournalLocked();
        if ($journal !== null) {
            throw new \RuntimeException(
                $operation . ' is blocked by gateway rebootstrap transaction '
                    . (string)$journal['nonce'] . ' at phase '
                    . (string)$journal['phase'] . '.'
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function readRebootstrapJournalLocked(): ?array
    {
        $file = $this->paths->rebootstrapJournalFile();
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap journal path is indeterminate.'
                );
            }
            return null;
        }
        $bytes = $this->readStableRegularFile(
            $file,
            self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
            'Gateway rebootstrap journal',
        );
        $journal = $this->decodeRebootstrapDocument(
            $bytes,
            'Gateway rebootstrap journal',
        );
        $this->retirePreparedRebootstrapCandidateInstallLockLocked($journal);
        return $journal;
    }

    /**
     * The caller must hold trust/package-install.lock. PREPARING deliberately
     * keeps the exact-slot installer lock because the immutable candidate may
     * still be under construction. Every later authenticated phase proves
     * that installer authority has ended, so the empty single-link lock is a
     * bounded crash artifact rather than durable gateway state.
     *
     * @param array<string,mixed> $journal
     */
    private function retirePreparedRebootstrapCandidateInstallLockLocked(
        array $journal,
    ): void {
        if (\hash_equals('PREPARING', (string)$journal['phase'])) {
            return;
        }
        $lock = $this->paths->rebootstrapCandidateDir(
            (string)$journal['nonce'],
        ) . '.install.lock';
        $status = @\lstat($lock);
        if (!\is_array($status)) {
            if (\file_exists($lock) || \is_link($lock)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap candidate install lock is indeterminate.',
                );
            }
            return;
        }
        if (\is_link($lock)
            || !$this->isRegularFileStatus($status)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['size'] ?? -1) !== 0
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap candidate install lock is not an empty regular single-link file.',
            );
        }
        $identity = GatewayBoundedTreeWalker::identity($lock);
        $verified = GatewayBoundedTreeWalker::revalidate($identity);
        if ((int)($verified['size'] ?? -1) !== 0
            || !$this->sameFileState($status, $verified)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap candidate install lock changed before retirement.',
            );
        }
        $this->injectRebootstrapCrash(
            'candidate-install-lock:before-retire',
        );
        GatewayProjectStateFilesystem::removeRegular(
            $lock,
            'prepared gateway rebootstrap candidate install lock',
            $verified,
        );
        if (\file_exists($lock) || \is_link($lock)) {
            throw new \RuntimeException(
                'Gateway rebootstrap candidate install lock remained after retirement.',
            );
        }
        $this->injectRebootstrapCrash(
            'candidate-install-lock:after-retire',
        );
    }

    /** @return array<string,mixed> */
    private function decodeRebootstrapDocument(string $bytes, string $label): array
    {
        $journal = \json_decode($bytes, true);
        if (!\is_array($journal) || \array_is_list($journal)) {
            throw new \RuntimeException($label . ' is invalid JSON.');
        }
        $actual = \array_keys($journal);
        $expected = self::REBOOTSTRAP_JOURNAL_FIELDS;
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected
            || ($journal['schema_version'] ?? null)
                !== self::REBOOTSTRAP_JOURNAL_SCHEMA
            || !\hash_equals('rebootstrap', (string)($journal['operation'] ?? ''))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['signature'] ?? '')) !== 1
        ) {
            throw new \RuntimeException(
                $label . ' schema or signature field is invalid.'
            );
        }
        $signed = $journal;
        $signature = (string)$signed['signature'];
        unset($signed['signature']);
        $key = $this->administratorHmacKey(
            'verify the gateway rebootstrap document',
        );
        try {
            $expectedSignature = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($signed),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException(
                $label . ' authentication failed.'
            );
        }
        $this->assertRebootstrapJournalContract($journal);
        return $journal;
    }

    /** @return array<string,mixed>|null */
    private function readRebootstrapReceiptLocked(string $nonce): ?array
    {
        $file = $this->paths->rebootstrapReceiptFile($nonce);
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $file,
            self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
            'Gateway rebootstrap terminal receipt',
            function (string $contents): void {
                $this->decodeRebootstrapDocument(
                    $contents,
                    'Gateway rebootstrap terminal receipt recovery target',
                );
            },
        );
        $status = @\lstat($file);
        if (!\is_array($status)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt path is indeterminate.'
                );
            }
            return null;
        }
        return $this->decodeRebootstrapDocument(
            $this->readStableRegularFile(
                $file,
                self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                'Gateway rebootstrap terminal receipt',
            ),
            'Gateway rebootstrap terminal receipt',
        );
    }

    /** @param array<string,mixed> $journal */
    private function writeRebootstrapJournalLocked(array $journal): array
    {
        $journal = $this->signRebootstrapDocumentLocked($journal);
        $encoded = GatewayClient::canonicalJson($journal) . "\n";
        $currentEncoded = $this->readOptionalStableRegularFile(
            $this->paths->rebootstrapJournalFile(),
            self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
            'Current gateway rebootstrap journal before replacement',
        );
        $currentAuthorization = null;
        if ($currentEncoded !== null) {
            $currentAuthorization = $this->rebootstrapStartAuthorizationDescriptor(
                $this->decodeRebootstrapDocument(
                    $currentEncoded,
                    'Current gateway rebootstrap journal before replacement',
                ),
                $currentEncoded,
            );
        }
        $nextAuthorization = $this->rebootstrapStartAuthorizationDescriptor(
            $journal,
            $encoded,
        );

        if ($nextAuthorization === null) {
            // Revoke before publishing a non-authorized phase. This prevents
            // a native reader from combining the old authorized journal with
            // a marker that was deleted only after the replacement.
            $this->removeRebootstrapStartAuthorizationLocked();
            $this->injectRebootstrapStartAuthorizationCrash(
                'revoked-before-journal',
            );
        } else {
            if ($currentAuthorization !== null
                && $this->sameRebootstrapStartAuthorizationIdentity(
                    $currentAuthorization,
                    $nextAuthorization,
                )
            ) {
                // The bridge is published first. A crash before, during or
                // after the journal replace therefore authorizes exactly the
                // old and/or new authenticated after-image, never a third one.
                $bridge = $nextAuthorization;
                $bridge['journal_sha256_primary'] =
                    $currentAuthorization['journal_sha256_primary'];
                $bridge['journal_sha256_secondary'] =
                    $nextAuthorization['journal_sha256_primary'];
                $this->writeRebootstrapStartAuthorizationLocked($bridge);
                $this->injectRebootstrapStartAuthorizationCrash(
                    'bridge-before-journal',
                );
            } else {
                // Publishing the future digest before a non-authorized ->
                // authorized transition is fail-closed: it remains inert
                // until the exact journal after-image is durable.
                $this->writeRebootstrapStartAuthorizationLocked(
                    $nextAuthorization,
                );
                $this->injectRebootstrapStartAuthorizationCrash(
                    'future-marker-before-journal',
                );
            }
        }
        $this->atomicWrite(
            $this->paths->rebootstrapJournalFile(),
            $encoded,
            0600,
        );
        $this->injectRebootstrapStartAuthorizationCrash(
            'journal-before-final-marker',
        );
        if ($nextAuthorization !== null) {
            $this->writeRebootstrapStartAuthorizationLocked(
                $nextAuthorization,
            );
            $this->injectRebootstrapStartAuthorizationCrash(
                'final-marker',
            );
        }
        return $journal;
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,string>|null
     */
    private function rebootstrapStartAuthorizationDescriptor(
        array $journal,
        string $journalEncoded,
    ): ?array {
        $phase = (string)($journal['phase'] ?? '');
        $forward = \in_array($phase, [
            'START_AUTHORIZED',
            'OBSERVING',
            'COMMITTED',
        ], true);
        $rollback = \in_array($phase, [
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true);
        if (!$forward && !$rollback) {
            return null;
        }

        $slot = $forward
            ? (string)$journal['target_slot']
            : (string)$journal['old_active_slot'];
        $runtimeGeneration = (string)$journal['runtime_generation'];
        $launcher = (string)$journal['candidate_launcher_sha256'];
        if ($rollback) {
            $oldSlots = (array)$journal['old_slots'];
            $oldSlot = $oldSlots[$slot] ?? null;
            if (!\is_array($oldSlot)) {
                throw new \RuntimeException(
                    'Gateway rollback start authorization lacks its old active slot.',
                );
            }
            $runtimeGeneration = (string)($oldSlot['runtime_generation'] ?? '');
            $launcher = (string)$journal['old_launcher_sha256'];
        }
        $descriptor = [
            'host_id' => (string)$journal['host_id'],
            'nonce' => (string)$journal['nonce'],
            'purpose' => $forward ? 'forward' : 'rollback',
            'journal_sha256_primary' => \hash('sha256', $journalEncoded),
            'journal_sha256_secondary' => \str_repeat('0', 64),
            'active_slot' => $slot,
            'runtime_generation' => $runtimeGeneration,
            'stable_launcher_sha256' => $launcher,
        ];
        foreach ([
            'host_id' => 32,
            'nonce' => 32,
            'journal_sha256_primary' => 64,
            'journal_sha256_secondary' => 64,
            'runtime_generation' => 64,
            'stable_launcher_sha256' => 64,
        ] as $field => $length) {
            if (\preg_match(
                '/\A[a-f0-9]{' . $length . '}\z/D',
                $descriptor[$field],
            ) !== 1) {
                throw new \RuntimeException(
                    'Gateway rebootstrap start authorization has an invalid '
                        . $field . '.',
                );
            }
        }
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \RuntimeException(
                'Gateway rebootstrap start authorization has an invalid slot.',
            );
        }
        return $descriptor;
    }

    /**
     * @param array<string,string> $left
     * @param array<string,string> $right
     */
    private function sameRebootstrapStartAuthorizationIdentity(
        array $left,
        array $right,
    ): bool {
        foreach ([
            'host_id',
            'nonce',
            'purpose',
            'active_slot',
            'runtime_generation',
            'stable_launcher_sha256',
        ] as $field) {
            if (!\hash_equals(
                (string)($left[$field] ?? ''),
                (string)($right[$field] ?? ''),
            )) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapStartAuthorizationLocked(
        array $journal,
    ): void {
        $journalFile = $this->paths->rebootstrapJournalFile();
        $authorizationFile = $this->paths
            ->rebootstrapStartAuthorizationFile();
        $journalContents = $this->readHostRecoveryFile(
            $journalFile,
            self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
            0600,
            'Gateway rebootstrap pre-start journal',
        );
        $current = $this->decodeRebootstrapDocument(
            $journalContents,
            'Gateway rebootstrap pre-start journal',
        );
        if (!\hash_equals(
            GatewayClient::canonicalJson($journal),
            GatewayClient::canonicalJson($current),
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap pre-start journal changed during authorization validation.',
            );
        }
        $expected = $this->rebootstrapStartAuthorizationDescriptor(
            $current,
            $journalContents,
        );
        if ($expected === null) {
            throw new \RuntimeException(
                'Gateway rebootstrap phase has no native start authorization.',
            );
        }
        $authorizationContents = $this->readHostRecoveryFile(
            $authorizationFile,
            self::REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES,
            0600,
            'Gateway rebootstrap pre-start authorization',
        );
        $authorization = $this->decodeRebootstrapStartAuthorization(
            $authorizationContents,
        );
        if (!$this->sameRebootstrapStartAuthorizationIdentity(
            $authorization,
            $expected,
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap pre-start authorization identity is invalid.',
            );
        }
        $journalDigest = (string)$expected['journal_sha256_primary'];
        if (!\hash_equals(
                $journalDigest,
                (string)$authorization['journal_sha256_primary'],
            )
            && !\hash_equals(
                $journalDigest,
                (string)$authorization['journal_sha256_secondary'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap pre-start authorization does not bind the current journal.',
            );
        }
        if (!\hash_equals(
                $journalContents,
                $this->readHostRecoveryFile(
                    $journalFile,
                    self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                    0600,
                    'Gateway rebootstrap pre-start journal recheck',
                ),
            )
            || !\hash_equals(
                $authorizationContents,
                $this->readHostRecoveryFile(
                    $authorizationFile,
                    self::REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES,
                    0600,
                    'Gateway rebootstrap pre-start authorization recheck',
                ),
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap pre-start authorization changed during validation.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function convergeRebootstrapStartAuthorizationLocked(
        array $journal,
    ): void {
        $phase = (string)($journal['phase'] ?? '');
        if (!\in_array($phase, [
            'START_AUTHORIZED',
            'OBSERVING',
            'COMMITTED',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true)) {
            return;
        }

        // A crash after the journal replace but before the final marker leaves
        // a valid two-digest bridge. A replay must first prove that bridge,
        // then collapse it to the exact current journal digest.
        $this->assertRebootstrapStartAuthorizationLocked($journal);
        $journalContents = $this->readHostRecoveryFile(
            $this->paths->rebootstrapJournalFile(),
            self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
            0600,
            'Gateway rebootstrap replay journal',
        );
        $current = $this->decodeRebootstrapDocument(
            $journalContents,
            'Gateway rebootstrap replay journal',
        );
        if (!\hash_equals(
            GatewayClient::canonicalJson($journal),
            GatewayClient::canonicalJson($current),
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap replay journal changed during authorization convergence.',
            );
        }
        $expected = $this->rebootstrapStartAuthorizationDescriptor(
            $current,
            $journalContents,
        );
        if ($expected === null) {
            throw new \RuntimeException(
                'Gateway rebootstrap replay phase has no native start authorization.',
            );
        }
        $authorizationContents = $this->readHostRecoveryFile(
            $this->paths->rebootstrapStartAuthorizationFile(),
            self::REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES,
            0600,
            'Gateway rebootstrap replay start authorization',
        );
        $authorization = $this->decodeRebootstrapStartAuthorization(
            $authorizationContents,
        );
        if (\hash_equals(
                (string)$expected['journal_sha256_primary'],
                (string)$authorization['journal_sha256_primary'],
            )
            && \hash_equals(
                (string)$expected['journal_sha256_secondary'],
                (string)$authorization['journal_sha256_secondary'],
            )
        ) {
            return;
        }
        $this->writeRebootstrapStartAuthorizationLocked($expected);
        $this->assertRebootstrapStartAuthorizationLocked($current);
    }

    /** @param array<string,string> $descriptor */
    private function writeRebootstrapStartAuthorizationLocked(
        array $descriptor,
    ): void {
        $unsigned = "WLS-REBOOTSTRAP-START/1\n"
            . 'host_id=' . $descriptor['host_id'] . "\n"
            . 'nonce=' . $descriptor['nonce'] . "\n"
            . 'purpose=' . $descriptor['purpose'] . "\n"
            . 'journal_sha256_primary='
                . $descriptor['journal_sha256_primary'] . "\n"
            . 'journal_sha256_secondary='
                . $descriptor['journal_sha256_secondary'] . "\n"
            . 'active_slot=' . $descriptor['active_slot'] . "\n"
            . 'runtime_generation=' . $descriptor['runtime_generation'] . "\n"
            . 'stable_launcher_sha256='
                . $descriptor['stable_launcher_sha256'] . "\n";
        $key = $this->administratorHmacKey(
            'authorize the native gateway rebootstrap start',
        );
        try {
            $signature = \hash_hmac('sha256', $unsigned, $key);
        } finally {
            \sodium_memzero($key);
        }
        $encoded = $unsigned . 'signature=' . $signature . "\n";
        if (\strlen($encoded)
            > self::REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap start authorization exceeds its fixed limit.',
            );
        }
        $this->atomicWrite(
            $this->paths->rebootstrapStartAuthorizationFile(),
            $encoded,
            0600,
        );
    }

    private function removeRebootstrapStartAuthorizationLocked(): void
    {
        $file = $this->paths->rebootstrapStartAuthorizationFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $file,
            'gateway rebootstrap native start authorization',
        );
    }

    /** @param array<string,mixed> $journal */
    private function signRebootstrapDocumentLocked(array $journal): array
    {
        $journal['schema_version'] = self::REBOOTSTRAP_JOURNAL_SCHEMA;
        $journal['operation'] = 'rebootstrap';
        // Wall time is diagnostic only. A backward correction or a reboot may
        // never make an otherwise valid recovery journal unparseable.
        $journal['updated_at'] = \max(
            (int)($journal['created_at'] ?? 0),
            (int)($journal['updated_at'] ?? 0),
            $this->wallClockNow(),
        );
        $journal['recovery_boot_id'] = $this->hostBootIdentityNow();
        $journal['signature'] = '';
        $this->assertRebootstrapJournalContract($journal, false);
        $signed = $journal;
        unset($signed['signature']);
        $key = $this->administratorHmacKey(
            'sign the gateway rebootstrap journal',
        );
        try {
            $journal['signature'] = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($signed),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        $this->assertRebootstrapJournalContract($journal);
        $encoded = GatewayClient::canonicalJson($journal) . "\n";
        if (\strlen($encoded) > self::REBOOTSTRAP_JOURNAL_MAX_BYTES) {
            throw new \RuntimeException(
                'Gateway rebootstrap journal exceeds its fixed size limit.'
            );
        }
        return $journal;
    }

    /** @param array<string,mixed> $receipt */
    private function writeRebootstrapReceiptLocked(array $receipt): array
    {
        $receipt = $this->signRebootstrapDocumentLocked($receipt);
        $encoded = GatewayClient::canonicalJson($receipt) . "\n";
        $this->atomicWrite(
            $this->paths->rebootstrapReceiptFile((string)$receipt['nonce']),
            $encoded,
            0600,
        );
        return $receipt;
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapJournalContract(
        array $journal,
        bool $requireSignature = true,
    ): void {
        $actual = \array_keys($journal);
        $expected = self::REBOOTSTRAP_JOURNAL_FIELDS;
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        $oldSlots = $journal['old_slots'] ?? null;
        $slotKeys = \is_array($oldSlots) ? \array_keys($oldSlots) : [];
        \sort($slotKeys, SORT_STRING);
        if ($actual !== $expected
            || ($journal['schema_version'] ?? null)
                !== self::REBOOTSTRAP_JOURNAL_SCHEMA
            || !\hash_equals('rebootstrap', (string)($journal['operation'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($journal['nonce'] ?? '')) !== 1
            || !\hash_equals($this->hostId(), (string)($journal['host_id'] ?? ''))
            || !\in_array((string)($journal['phase'] ?? ''), self::REBOOTSTRAP_PHASES, true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['package_digest'] ?? '')) !== 1
            || !\is_string($journal['package_version'] ?? null)
            || \trim((string)$journal['package_version']) === ''
            || \strlen((string)$journal['package_version']) > 128
            || !\in_array((string)($journal['profile'] ?? ''), ['default', 'ipv4-only'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['origin_boot_id'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['recovery_boot_id'] ?? '')) !== 1
            || !\is_int($journal['created_at'] ?? null)
            || (int)$journal['created_at'] < 1
            || !\is_int($journal['updated_at'] ?? null)
            || (int)$journal['updated_at'] < (int)$journal['created_at']
            || !\hash_equals('A', (string)($journal['target_slot'] ?? ''))
            || !\is_string($journal['runtime_generation'] ?? null)
            || ((string)$journal['runtime_generation'] !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', (string)$journal['runtime_generation']) !== 1)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['candidate_launcher_sha256'] ?? '')) !== 1
            || !\is_int($journal['candidate_launcher_size'] ?? null)
            || (int)$journal['candidate_launcher_size'] < 1
            || (int)$journal['candidate_launcher_size'] > self::MAX_PACKAGE_BYTES
            || !\is_int($journal['candidate_launcher_mode'] ?? null)
            || !\in_array((int)$journal['candidate_launcher_mode'], [0500, 0550, 0555, 0700, 0755], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['candidate_ca_bundle_sha256'] ?? '')) !== 1
            || !\in_array((string)($journal['old_active_slot'] ?? ''), ['A', 'B'], true)
            || !\in_array((string)($journal['old_previous_slot'] ?? ''), ['', 'A', 'B'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['old_launcher_sha256'] ?? '')) !== 1
            || !\is_int($journal['old_launcher_size'] ?? null)
            || (int)$journal['old_launcher_size'] < 1
            || (int)$journal['old_launcher_size'] > self::MAX_PACKAGE_BYTES
            || !\is_int($journal['old_launcher_mode'] ?? null)
            || !\in_array((int)$journal['old_launcher_mode'], [0500, 0550, 0555, 0700, 0755], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['old_ca_bundle_sha256'] ?? '')) !== 1
            || $slotKeys !== ['A', 'B']
            || !$this->rebootstrapOldSlotContractValid($oldSlots['A'] ?? null, 'A')
            || !$this->rebootstrapOldSlotContractValid($oldSlots['B'] ?? null, 'B')
            || !\is_bool($journal['trust_rotation'] ?? null)
            || !\is_string($journal['derived_policy_sha256'] ?? null)
            || ((string)$journal['derived_policy_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['derived_policy_sha256'],
                ) !== 1)
            || !\is_string($journal['old_derived_manifest_sha256'] ?? null)
            || ((string)$journal['old_derived_manifest_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['old_derived_manifest_sha256'],
                ) !== 1)
            || !$this->rebootstrapPlatformSnapshotValid($journal['platform_snapshot'] ?? null)
            || !\is_string($journal['admin_stopped_digest'] ?? null)
            || ((string)$journal['admin_stopped_digest'] !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', (string)$journal['admin_stopped_digest']) !== 1)
            || !\is_string($journal['admin_stopped_contents_b64'] ?? null)
            || \strlen((string)$journal['admin_stopped_contents_b64']) > 8192
            || ((string)$journal['admin_stopped_contents_b64'] !== ''
                && \base64_decode((string)$journal['admin_stopped_contents_b64'], true) === false)
            || !\is_string($journal['gateway_epoch'] ?? null)
            || ((string)$journal['gateway_epoch'] !== ''
                && \preg_match('/\A[a-f0-9]{32}\z/D', (string)$journal['gateway_epoch']) !== 1)
            || !\is_string($journal['old_gateway_epoch'] ?? null)
            || ((string)$journal['old_gateway_epoch'] !== ''
                && \preg_match('/\A[a-f0-9]{32}\z/D', (string)$journal['old_gateway_epoch']) !== 1)
            || !\is_string($journal['new_gateway_epoch'] ?? null)
            || ((string)$journal['new_gateway_epoch'] !== ''
                && \preg_match('/\A[a-f0-9]{32}\z/D', (string)$journal['new_gateway_epoch']) !== 1)
            || !\in_array(
                (string)($journal['capacity_reserve_state'] ?? ''),
                self::REBOOTSTRAP_CAPACITY_STATES,
                true,
            )
            || !\is_int($journal['capacity_reserve_bytes'] ?? null)
            || (int)$journal['capacity_reserve_bytes']
                !== $this->rebootstrapCapacityBytes()
            || !\is_int($journal['capacity_reserve_inodes'] ?? null)
            || (int)$journal['capacity_reserve_inodes']
                !== $this->rebootstrapCapacityInodes()
            || !\is_string($journal['capacity_reserve_volume_id'] ?? null)
            || ((string)$journal['capacity_reserve_volume_id'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['capacity_reserve_volume_id'],
                ) !== 1)
            || !\is_string($journal['capacity_reserve_manifest_sha256'] ?? null)
            || ((string)$journal['capacity_reserve_manifest_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['capacity_reserve_manifest_sha256'],
                ) !== 1)
            || !\is_string($journal['capacity_reserve_release_sha256'] ?? null)
            || ((string)$journal['capacity_reserve_release_sha256'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['capacity_reserve_release_sha256'],
                ) !== 1)
            || !\in_array(
                (string)($journal['capacity_reserve_release_reason'] ?? ''),
                ['', 'forward', 'rollback', 'cancel'],
                true,
            )
            || !\in_array(
                (string)($journal['capacity_evidence_state'] ?? ''),
                self::REBOOTSTRAP_CAPACITY_EVIDENCE_STATES,
                true,
            )
            || !\is_string($journal['failure_reason'] ?? null)
            || \strlen((string)$journal['failure_reason']) > 2048
            || !\in_array(
                (string)($journal['retained_backup_state'] ?? ''),
                self::REBOOTSTRAP_RETAINED_BACKUP_STATES,
                true,
            )
            || !\is_string($journal['backup_collection_nonce'] ?? null)
            || ((string)$journal['backup_collection_nonce'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)$journal['backup_collection_nonce'],
                ) !== 1)
            || !\is_string($journal['backup_collection_device'] ?? null)
            || ((string)$journal['backup_collection_device'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{1,32}\z/D',
                    (string)$journal['backup_collection_device'],
                ) !== 1)
            || !\is_string($journal['backup_collection_inode'] ?? null)
            || ((string)$journal['backup_collection_inode'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{1,32}\z/D',
                    (string)$journal['backup_collection_inode'],
                ) !== 1)
            || !\is_int($journal['retention_until'] ?? null)
            || (int)$journal['retention_until'] < 0
            || !\is_string($journal['retention_host_boot_id'] ?? null)
            || ((string)$journal['retention_host_boot_id'] !== ''
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)$journal['retention_host_boot_id'],
                ) !== 1)
            || !\is_int($journal['retained_monotonic_ms'] ?? null)
            || (int)$journal['retained_monotonic_ms'] < 0
            || !\is_int($journal['retention_deadline_monotonic_ms'] ?? null)
            || (int)$journal['retention_deadline_monotonic_ms'] < 0
            || !\is_int($journal['terminal_at'] ?? null)
            || (int)$journal['terminal_at'] < 0
            || ($requireSignature
                && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['signature'] ?? '')) !== 1)
            || (!$requireSignature && (string)($journal['signature'] ?? '') !== '')
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap journal violates the strict schema-4 contract.'
            );
        }
        $retentionWall = (int)$journal['retention_until'];
        $retentionBoot = (string)$journal['retention_host_boot_id'];
        $retainedMonotonic = (int)$journal['retained_monotonic_ms'];
        $retentionDeadline = (int)$journal['retention_deadline_monotonic_ms'];
        if (($retentionWall === 0
                && ($retentionBoot !== ''
                    || $retainedMonotonic !== 0
                    || $retentionDeadline !== 0))
            || ($retentionWall > 0
                && ($retentionBoot === ''
                    || $retainedMonotonic < 1
                    || $retainedMonotonic
                        > PHP_INT_MAX - self::REBOOTSTRAP_RETENTION_SECONDS * 1000
                    || $retentionDeadline
                        !== $retainedMonotonic
                            + self::REBOOTSTRAP_RETENTION_SECONDS * 1000))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap retention evidence is inconsistent.'
            );
        }
        $phase = (string)$journal['phase'];
        $trustRotation = (bool)$journal['trust_rotation'];
        $oldCa = (string)$journal['old_ca_bundle_sha256'];
        $candidateCa = (string)$journal['candidate_ca_bundle_sha256'];
        $derivedPolicy = (string)$journal['derived_policy_sha256'];
        $derivedManifest = (string)$journal['old_derived_manifest_sha256'];
        $oldEpoch = (string)$journal['old_gateway_epoch'];
        $newEpoch = (string)$journal['new_gateway_epoch'];
        $capacityState = (string)$journal['capacity_reserve_state'];
        $capacityVolume = (string)$journal['capacity_reserve_volume_id'];
        $capacityManifest = (string)$journal['capacity_reserve_manifest_sha256'];
        $capacityRelease = (string)$journal['capacity_reserve_release_sha256'];
        $capacityReason = (string)$journal['capacity_reserve_release_reason'];
        $capacityEvidenceState = (string)$journal['capacity_evidence_state'];
        $retainedBackupState = (string)$journal['retained_backup_state'];
        $collectionNonce = (string)$journal['backup_collection_nonce'];
        $collectionDevice = (string)$journal['backup_collection_device'];
        $collectionInode = (string)$journal['backup_collection_inode'];
        $collectionBound = $collectionNonce !== '';
        $terminalAt = (int)$journal['terminal_at'];
        $terminalPhase = \in_array(
            $phase,
            ['COMMITTED', 'ROLLED_BACK'],
            true,
        );
        if ((\hash_equals('PREPARING', $phase)
                && (string)$journal['runtime_generation'] !== '')
            || (!\hash_equals('PREPARING', $phase)
                && (string)$journal['runtime_generation'] === '')
            || (\hash_equals('COMMITTED', $phase) && $retentionWall < 1)
            || (!\hash_equals('COMMITTED', $phase) && $retentionWall !== 0)
            || ($terminalPhase && \hash_equals('NONE', $retainedBackupState))
            || (!$terminalPhase && !\hash_equals('NONE', $retainedBackupState))
            || (($collectionNonce === '') !== ($collectionDevice === ''))
            || (($collectionNonce === '') !== ($collectionInode === ''))
            || (!$terminalPhase && $collectionBound)
            || (\hash_equals('NONE', $retainedBackupState)
                && $collectionBound)
            || $trustRotation !== !\hash_equals($oldCa, $candidateCa)
            || (\in_array($phase, [
                    'OLD_GENERATION_STASHED',
                    'NEW_GENERATION_PUBLISHED',
                    'PLATFORM_REFRESHED',
                    'START_AUTHORIZED',
                    'OBSERVING',
                    'COMMITTED',
                ], true)
                && ($derivedManifest === '' || $derivedPolicy === ''))
            || (($derivedManifest === '') !== ($derivedPolicy === ''))
            || !\hash_equals((string)$journal['gateway_epoch'], $oldEpoch)
            || (\in_array($phase, ['OBSERVING', 'COMMITTED'], true)
                && $newEpoch === '')
            || ($newEpoch !== ''
                && ($oldEpoch === ''
                    || ($trustRotation && \hash_equals($oldEpoch, $newEpoch))
                    || (!$trustRotation && !\hash_equals($oldEpoch, $newEpoch))))
            || (\in_array($capacityState, ['NONE', 'ALLOCATING'], true)
                && ($capacityVolume !== ''
                    || $capacityManifest !== ''
                    || $capacityRelease !== ''
                    || $capacityReason !== ''))
            || (\in_array($capacityState, ['HELD', 'RELEASING'], true)
                && ($capacityVolume === '' || $capacityManifest === ''))
            || (\hash_equals('RELEASED', $capacityState)
                && ($capacityVolume === ''
                    || (!\hash_equals('cancel', $capacityReason)
                        && $capacityManifest === '')))
            || (\hash_equals('HELD', $capacityState)
                && ($capacityRelease !== '' || $capacityReason !== ''))
            || (\in_array($capacityState, ['RELEASING', 'RELEASED'], true)
                && ($capacityRelease === '' || $capacityReason === ''))
            || (\hash_equals('STOP_COMMITTED', $phase)
                && !\hash_equals('HELD', $capacityState))
            || (\hash_equals('QUIESCED', $phase)
                && !\in_array($capacityState, [
                    'HELD',
                    'RELEASING',
                    'RELEASED',
                ], true))
            || (\in_array($phase, [
                    'OLD_GENERATION_STASHED',
                    'NEW_GENERATION_PUBLISHED',
                    'PLATFORM_REFRESHED',
                    'START_AUTHORIZED',
                    'OBSERVING',
                    'COMMITTED',
                    'ROLLBACK_START_AUTHORIZED',
                    'ROLLBACK_OBSERVING',
                ], true)
                && !\hash_equals('RELEASED', $capacityState))
            || (\hash_equals('ROLLED_BACK', $phase)
                && (string)$journal['admin_stopped_digest'] !== ''
                && !\hash_equals('RELEASED', $capacityState))
            || (\hash_equals('ROLLED_BACK', $phase)
                && (string)$journal['admin_stopped_digest'] === ''
                && !\in_array($capacityState, ['NONE', 'RELEASED'], true))
            || (!$terminalPhase
                && !\hash_equals('NONE', $capacityEvidenceState))
            || ($terminalPhase
                && \hash_equals('RELEASED', $capacityState)
                && !\in_array($capacityEvidenceState, [
                    'RETAINED',
                    'COLLECTING',
                    'COLLECTED',
                ], true))
            || ($terminalPhase
                && !\hash_equals('RELEASED', $capacityState)
                && !\hash_equals('NONE', $capacityEvidenceState))
            || (!$terminalPhase && $terminalAt !== 0)
            || ($terminalPhase && $terminalAt < (int)$journal['created_at'])
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap phase evidence is inconsistent.'
            );
        }
        $decodedIntent = (string)$journal['admin_stopped_contents_b64'] === ''
            ? ''
            : (string)\base64_decode(
                (string)$journal['admin_stopped_contents_b64'],
                true,
            );
        if (($decodedIntent === '') !== ((string)$journal['admin_stopped_digest'] === '')
            || ($decodedIntent !== ''
                && !\hash_equals(
                    (string)$journal['admin_stopped_digest'],
                    \hash('sha256', $decodedIntent),
                ))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ADMIN_STOPPED evidence is inconsistent.'
            );
        }
        $requiresStoppedEvidence = \in_array($phase, [
            'STOP_COMMITTED',
            'QUIESCED',
            'OLD_GENERATION_STASHED',
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
            'OBSERVING',
            'COMMITTED',
        ], true) || (\in_array($phase, [
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true)
            && $decodedIntent !== '');
        if (($decodedIntent === '') !== ((string)$journal['gateway_epoch'] === '')
            || ($requiresStoppedEvidence
                && ($journal['platform_snapshot'] === null
                    || $decodedIntent === ''))
            || (!$requiresStoppedEvidence
                && $decodedIntent !== '')
            || (!$requiresStoppedEvidence
                && $decodedIntent === ''
                && $journal['platform_snapshot'] !== null
                && !\hash_equals('PREPARED', $phase)
                && !\in_array($phase, [
                    'ROLLING_BACK',
                    'ROLLBACK_START_AUTHORIZED',
                    'ROLLBACK_OBSERVING',
                    'ROLLED_BACK',
                ], true))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap stop evidence is inconsistent with its phase.'
            );
        }
        if ($decodedIntent !== ''
            && (\preg_match(
                '/\AWLS-ADMIN-STOPPED\/1\n'
                    . 'host_id=[a-f0-9]{32}\n'
                    . 'epoch=([a-f0-9]{32})\n/D',
                $decodedIntent,
                $intentMatches,
            ) !== 1
                || !\hash_equals(
                    (string)$journal['gateway_epoch'],
                    (string)($intentMatches[1] ?? ''),
                ))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap ADMIN_STOPPED epoch binding is invalid.'
            );
        }
    }

    private function rebootstrapOldSlotContractValid(mixed $slot, string $name): bool
    {
        if ($slot === null) {
            return true;
        }
        if (!\is_array($slot) || \array_is_list($slot)) {
            return false;
        }
        $keys = \array_keys($slot);
        \sort($keys, SORT_STRING);
        return $keys === [
                'launcher_sha256',
                'package_digest',
                'runtime_generation',
                'slot',
            ]
            && \hash_equals($name, (string)($slot['slot'] ?? ''))
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($slot['runtime_generation'] ?? '')) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($slot['package_digest'] ?? '')) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($slot['launcher_sha256'] ?? '')) === 1;
    }

    private function rebootstrapPlatformSnapshotValid(mixed $snapshot): bool
    {
        if ($snapshot === null) {
            return true;
        }
        if (!\is_array($snapshot) || \array_is_list($snapshot)) {
            return false;
        }
        $keys = \array_keys($snapshot);
        \sort($keys, SORT_STRING);
        return $keys === [
                'definition_sha256',
                'kind',
                'metadata_sha256',
                'profile',
            ]
            && \in_array((string)($snapshot['kind'] ?? ''), [
                'test-session',
                'launchd-system',
                'systemd-system',
                'windows-service',
            ], true)
            && \in_array((string)($snapshot['profile'] ?? ''), ['default', 'ipv4-only'], true)
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($snapshot['definition_sha256'] ?? '')) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($snapshot['metadata_sha256'] ?? '')) === 1;
    }

    private function administratorHmacKey(string $operation): string
    {
        $secret = \strtolower(\trim($this->readStableRegularFile(
            $this->paths->adminTokenFile(),
            65,
            'Gateway administrator credential',
        )));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hex2bin($secret)
            : false;
        if (!\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'Gateway administrator credential cannot ' . $operation . '.'
            );
        }
        return $key;
    }

    /** @param array<string,mixed> $journal */
    private function publicRebootstrapJournal(array $journal): array
    {
        unset(
            $journal['signature'],
            $journal['admin_stopped_contents_b64'],
        );
        $journal['active'] = !\in_array(
            (string)$journal['phase'],
            ['COMMITTED', 'ROLLED_BACK'],
            true,
        );
        return $journal;
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function mutateRebootstrapJournal(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $expectedPhase,
        string $nextPhase,
        array $evidence,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $packageDigest = \strtolower(\trim($packageDigest));
        $profile = \strtolower(\trim($profile));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $packageDigest) !== 1
            || !\in_array($profile, ['default', 'ipv4-only'], true)
            || !\in_array($expectedPhase, self::REBOOTSTRAP_PHASES, true)
            || !\in_array($nextPhase, self::REBOOTSTRAP_PHASES, true)
        ) {
            throw new \InvalidArgumentException(
                'Gateway rebootstrap journal mutation arguments are invalid.'
            );
        }
        return $this->withInstallLock(function () use (
            $nonce,
            $packageDigest,
            $profile,
            $expectedPhase,
            $nextPhase,
            $evidence,
        ): array {
            $journal = $this->requiredRebootstrapJournalLocked(
                $nonce,
                $packageDigest,
                $profile,
            );
            $current = (string)$journal['phase'];
            if (!\hash_equals($current, $expectedPhase)
                && !\hash_equals($current, $nextPhase)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap phase mismatch: expected '
                        . $expectedPhase . ' but found ' . $current . '.'
                );
            }
            if (!\hash_equals($expectedPhase, $nextPhase)
                && \hash_equals($current, $expectedPhase)
                && !$this->rebootstrapTransitionAllowed(
                    $expectedPhase,
                    $nextPhase,
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap phase transition is not allowed: '
                    . $expectedPhase . ' -> ' . $nextPhase . '.'
                );
            }
            $phaseReplay = !\hash_equals($expectedPhase, $nextPhase)
                && \hash_equals($current, $nextPhase);
            $journal = $this->applyRebootstrapEvidence(
                $journal,
                $evidence,
                $expectedPhase,
                $nextPhase,
            );
            if (\hash_equals('STOP_COMMITTED', $nextPhase)
                || \hash_equals('QUIESCED', $nextPhase)
            ) {
                $this->verifyRebootstrapCapacityReserveHeldLocked($journal);
            }
            if (\in_array($nextPhase, [
                'OLD_GENERATION_STASHED',
                'NEW_GENERATION_PUBLISHED',
                'PLATFORM_REFRESHED',
                'START_AUTHORIZED',
                'OBSERVING',
                'COMMITTED',
                'ROLLBACK_START_AUTHORIZED',
                'ROLLBACK_OBSERVING',
            ], true)) {
                $this->assertRebootstrapCapacityReleasedLocked($journal);
            }
            if (\hash_equals('START_AUTHORIZED', $nextPhase)) {
                $this->assertPublishedRebootstrapGeneration($journal);
                $this->assertRetainedRebootstrapBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                );
                $this->assertRetainedRebootstrapDerivedBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                    self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                );
            }
            if (\hash_equals('ROLLBACK_START_AUTHORIZED', $nextPhase)) {
                $this->assertOldGenerationMatchesRebootstrapJournal(
                    $journal,
                    $this->verifiedRebootstrapOldGeneration(),
                );
                $this->assertRebootstrapPlatformBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                );
                $this->assertRebootstrapRestoredOldDerivedLiveInventory(
                    $journal,
                    'gateway rollback start authorization old derived generation',
                );
                $this->assertRetainedRebootstrapDerivedBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir($nonce),
                    self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                    true,
                );
            }
            if (!$phaseReplay) {
                $journal['phase'] = $nextPhase;
                $journal = $this->writeRebootstrapJournalLocked($journal);
            } else {
                $this->convergeRebootstrapStartAuthorizationLocked($journal);
            }
            if (\hash_equals('OBSERVING', $nextPhase)) {
                (new GatewayGuardianTransitionProtocol($this->paths))
                    ->beginCandidateObservation($journal);
            }
            $this->injectRebootstrapCrashAfterPhase($nextPhase);
            return $this->publicRebootstrapJournal($journal);
        });
    }

    private function rebootstrapTransitionAllowed(string $from, string $to): bool
    {
        $forward = [
            'PREPARING' => ['PREPARED'],
            'PREPARED' => ['STOP_COMMITTED', 'ROLLING_BACK'],
            'STOP_COMMITTED' => ['QUIESCED', 'ROLLING_BACK'],
            'QUIESCED' => ['OLD_GENERATION_STASHED', 'ROLLING_BACK'],
            'OLD_GENERATION_STASHED' => [
                'NEW_GENERATION_PUBLISHED',
                'ROLLING_BACK',
            ],
            'NEW_GENERATION_PUBLISHED' => [
                'PLATFORM_REFRESHED',
                'ROLLING_BACK',
            ],
            'PLATFORM_REFRESHED' => ['START_AUTHORIZED', 'ROLLING_BACK'],
            'START_AUTHORIZED' => ['OBSERVING', 'ROLLING_BACK'],
            'OBSERVING' => ['COMMITTED', 'ROLLING_BACK'],
            'ROLLING_BACK' => ['ROLLBACK_START_AUTHORIZED'],
            'ROLLBACK_START_AUTHORIZED' => [
                'ROLLBACK_OBSERVING',
                'ROLLING_BACK',
            ],
            'ROLLBACK_OBSERVING' => ['ROLLED_BACK', 'ROLLING_BACK'],
        ];
        return \in_array($to, $forward[$from] ?? [], true);
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    private function applyRebootstrapEvidence(
        array $journal,
        array $evidence,
        string $expectedPhase,
        string $nextPhase,
    ): array {
        $keys = \array_keys($evidence);
        $transition = $expectedPhase . '->' . $nextPhase;
        $allowed = match ($transition) {
            'PREPARED->PREPARED' => ['platform_snapshot'],
            'PREPARED->STOP_COMMITTED' => [
                'admin_stopped_contents',
                'gateway_epoch',
            ],
            'START_AUTHORIZED->OBSERVING' => ['new_gateway_epoch'],
            'ROLLING_BACK->ROLLING_BACK' => ['failure_reason'],
            default => [],
        };
        foreach ($keys as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(
                    'Gateway rebootstrap evidence field ' . $key
                        . ' is not allowed for ' . $transition . '.'
                );
            }
        }
        if (\array_key_exists('platform_snapshot', $evidence)) {
            $snapshot = $evidence['platform_snapshot'];
            if (!$this->rebootstrapPlatformSnapshotValid($snapshot)
                || $snapshot === null
            ) {
                throw new \InvalidArgumentException(
                    'Gateway rebootstrap platform snapshot evidence is invalid.'
                );
            }
            if ($journal['platform_snapshot'] !== null
                && $journal['platform_snapshot'] !== $snapshot
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap platform snapshot changed during recovery.'
                );
            }
            $journal['platform_snapshot'] = $snapshot;
        }
        if (\array_key_exists('admin_stopped_contents', $evidence)) {
            $contents = $evidence['admin_stopped_contents'];
            if (!\is_string($contents)
                || $contents === ''
                || \strlen($contents) > 4096
            ) {
                throw new \InvalidArgumentException(
                    'Gateway rebootstrap ADMIN_STOPPED evidence is invalid.'
                );
            }
            $digest = \hash('sha256', $contents);
            $encoded = \base64_encode($contents);
            if ((string)$journal['admin_stopped_digest'] !== ''
                && (!\hash_equals(
                        (string)$journal['admin_stopped_digest'],
                        $digest,
                    )
                    || !\hash_equals(
                        (string)$journal['admin_stopped_contents_b64'],
                        $encoded,
                    ))
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap ADMIN_STOPPED evidence changed during recovery.'
                );
            }
            $journal['admin_stopped_digest'] = $digest;
            $journal['admin_stopped_contents_b64'] = $encoded;
        }
        if (\array_key_exists('gateway_epoch', $evidence)) {
            $epoch = \strtolower(\trim((string)$evidence['gateway_epoch']));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1) {
                throw new \InvalidArgumentException(
                    'Gateway rebootstrap epoch evidence is invalid.'
                );
            }
            if ((string)$journal['gateway_epoch'] !== ''
                && !\hash_equals((string)$journal['gateway_epoch'], $epoch)
            ) {
                throw new \RuntimeException(
                    'Gateway epoch changed during clean rebootstrap.'
                );
            }
            $journal['gateway_epoch'] = $epoch;
            $journal['old_gateway_epoch'] = $epoch;
        }
        if (\array_key_exists('new_gateway_epoch', $evidence)) {
            $epoch = \strtolower(\trim((string)$evidence['new_gateway_epoch']));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1
                || (string)$journal['old_gateway_epoch'] === ''
                || ((bool)$journal['trust_rotation']
                    ? \hash_equals(
                        (string)$journal['old_gateway_epoch'],
                        $epoch,
                    )
                    : !\hash_equals(
                        (string)$journal['old_gateway_epoch'],
                        $epoch,
                    ))
            ) {
                throw new \InvalidArgumentException(
                    'Gateway rebootstrap new epoch evidence violates the trust-generation contract.',
                );
            }
            if ((string)$journal['new_gateway_epoch'] !== ''
                && !\hash_equals(
                    (string)$journal['new_gateway_epoch'],
                    $epoch,
                )
            ) {
                throw new \RuntimeException(
                    'Gateway new epoch changed during rebootstrap observation.',
                );
            }
            $journal['new_gateway_epoch'] = $epoch;
        }
        if (\array_key_exists('failure_reason', $evidence)) {
            $journal['failure_reason'] = GatewayBoundedText::singleLine(
                (string)$evidence['failure_reason'],
                2048,
                'Gateway rebootstrap failed.',
            );
        }
        return $journal;
    }

    /**
     * Durable state is a member of the CA trust generation. A CA-changing
     * rebootstrap preserves only the host administrator identity and the
     * transaction/platform files needed to finish or roll back the operation.
     * Every other selected top-level entry is sealed in the nonce backup.
     *
     * @return array<string,array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string}>
     */
    private function rebootstrapDerivedNamespaces(): array
    {
        $statePreserved = [];
        $serviceDefinition = $this->paths->serviceDefinitionFile();
        if ($this->samePlatformPath(
            \dirname($serviceDefinition),
            $this->paths->stateDir(),
        )) {
            $statePreserved[] = \basename(\str_replace('\\', '/', $serviceDefinition));
        }
        $statePreserved[] = 'recovery.reserve';

        return [
            'state' => [
                'root' => $this->paths->stateDir(),
                'root_id' => 'host/state',
                'preserved' => $statePreserved,
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_STATE,
            ],
            'trust' => [
                'root' => $this->paths->trustDir(),
                'root_id' => 'host/trust',
                'preserved' => [
                    'admin.token',
                    'host-id',
                    'rebootstrap.transaction',
                    'rebootstrap-start.authorization',
                    'admin-stopped.intent',
                    'package-install.lock',
                    'package-stage-a.lock',
                    'package-stage-b.lock',
                    'platform-service.json',
                    'platform-definition.transaction',
                    'systemd-layout-migration.transaction',
                    'platform-removal.pending',
                    'stable-launcher.sha256',
                    'guardian.sha256',
                    'guardian-generation-head.0',
                    'guardian-generation-head.1',
                    'guardian-generation-head.lock',
                    'guardian-transition.request',
                    'guardian-transition.ack',
                    'guardian-transition.retirement',
                    'guardian-recovery.transaction',
                    'active-slot',
                    'previous-slot',
                ],
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_TRUST,
            ],
            'snapshots' => [
                'root' => $this->paths->legacySnapshotsDir(),
                'root_id' => 'host/snapshots-v1',
                'preserved' => [],
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_SNAPSHOTS_V1,
            ],
            'snapshots-v2' => [
                'root' => $this->paths->sealedSnapshotsDir(),
                'root_id' => 'host/snapshots-v2',
                'preserved' => [],
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_SNAPSHOTS_V2,
            ],
            'snapshot-candidates-v2' => [
                'root' => $this->paths->snapshotCandidatesDir(),
                'root_id' => 'host/snapshot-candidates-v2',
                'preserved' => [],
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_SNAPSHOT_CANDIDATES_V2,
            ],
            'runtime-conf' => [
                'root' => $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'conf',
                'root_id' => 'host/runtime/conf',
                'preserved' => [],
                'policy' => 'restore',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_RUNTIME_CHILD,
            ],
            'runtime-temp' => [
                'root' => $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'temp',
                'root_id' => 'host/runtime/temp',
                'preserved' => [],
                'policy' => 'ephemeral',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_RUNTIME_CHILD,
            ],
            'runtime-shadow' => [
                'root' => $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow',
                'root_id' => 'host/runtime/shadow',
                'preserved' => [],
                'policy' => 'ephemeral',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_RUNTIME_CHILD,
            ],
            'runtime-run' => [
                'root' => $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'run',
                'root_id' => 'host/runtime/run',
                'preserved' => [],
                'policy' => 'ephemeral',
                'authority_profile'
                    => GatewayPlatformServiceInstaller::DERIVED_AUTHORITY_RUNTIME_CHILD,
            ],
        ];
    }

    private function samePlatformPath(string $left, string $right): bool
    {
        $left = \rtrim(\str_replace('\\', '/', $left), '/');
        $right = \rtrim(\str_replace('\\', '/', $right), '/');
        return \PHP_OS_FAMILY === 'Windows'
            ? \strcasecmp($left, $right) === 0
            : \hash_equals($left, $right);
    }

    /**
     * @param array<string,array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string}>|null $definitions
     * @return array<string,array{root_id:string,policy:string,preserved:list<string>,authority_profile:string,root_authority_policy:string}>
     */
    private function rebootstrapDerivedPolicyDescriptor(
        ?array $definitions = null,
    ): array {
        $definitions ??= $this->rebootstrapDerivedNamespaces();
        $descriptor = [];
        foreach ($definitions as $category => $definition) {
            $preserved = $definition['preserved'];
            \sort($preserved, SORT_STRING);
            $descriptor[$category] = [
                'root_id' => $definition['root_id'],
                'policy' => $definition['policy'],
                'preserved' => $preserved,
                'authority_profile' => $definition['authority_profile'],
                'root_authority_policy'
                    => $this->rebootstrapDerivedRootAuthorityPolicy($definition),
            ];
        }
        \ksort($descriptor, SORT_STRING);
        return $descriptor;
    }

    /**
     * @param array<string,array{
     *   root_id:string,
     *   policy:string,
     *   preserved:list<string>,
     *   authority_profile:string,
     *   root_authority_policy:string
     * }> $descriptor
     */
    private function rebootstrapDerivedPolicyDigest(array $descriptor): string
    {
        return \hash('sha256', GatewayClient::canonicalJson($descriptor));
    }

    /** @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition */
    private function rebootstrapDerivedRootAuthorityPolicy(
        array $definition,
    ): string {
        $lifecycle = $definition['preserved'] === []
            ? 'recreate-sealed'
            : 'preserve-identity';
        return $definition['authority_profile'] . '-' . $lifecycle;
    }

    /**
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     * @return array{
     *   authority_policy:string,
     *   authority_sha256:string,
     *   device:string,
     *   gid:int,
     *   inode:string,
     *   mode:int,
     *   parent_authority_sha256:string,
     *   parent_authority_policy:string,
     *   parent_device:string,
     *   parent_gid:int,
     *   parent_inode:string,
     *   parent_mode:int,
     *   parent_uid:int,
     *   parent_windows_sddl_b64:string,
     *   present:bool,
     *   uid:int,
     *   windows_sddl_b64:string
     * }
     */
    private function captureRebootstrapDerivedRootProof(
        array $definition,
        string $label,
    ): array {
        $root = $definition['root'];
        $parent = \dirname($root);
        $parentStatus = @\lstat($parent);
        if (!\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' parent authority is unsafe.');
        }
        $parentIdentity = GatewayBoundedTreeWalker::identity($parent);
        $parentDevice = \PHP_OS_FAMILY === 'Windows'
            ? (string)$parentIdentity['device']
            : (string)($parentStatus['dev'] ?? '');
        $parentInode = \PHP_OS_FAMILY === 'Windows'
            ? (string)$parentIdentity['inode']
            : (string)($parentStatus['ino'] ?? '');
        $platform = $this->platform
            ?? new GatewayPlatformServiceInstaller($this->paths);
        $rootAuthorityProfile = $platform
            ->rebootstrapDerivedRootAuthorityProfile($root);
        if (!\hash_equals(
            $definition['authority_profile'],
            $rootAuthorityProfile,
        )) {
            throw new \RuntimeException(
                $label . ' authority profile differs from its fixed namespace.',
            );
        }
        $parentAuthorityProfile = $platform
            ->rebootstrapDerivedRootAuthorityProfile($parent);
        $authorityPolicy = $this->rebootstrapDerivedRootAuthorityPolicy(
            $definition,
        );
        $parentAuthorityPolicy = $parentAuthorityProfile
            . '-fixed-parent';
        $parentMode = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : ((int)$parentStatus['mode'] & 0777);
        $parentUid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($parentStatus['uid'] ?? -1);
        $parentGid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($parentStatus['gid'] ?? -1);
        if (\PHP_OS_FAMILY !== 'Windows'
            && (($parentMode & 0700) !== 0700
                || ($parentMode & 0022) !== 0
                || $parentUid < 0
                || $parentGid < 0)
        ) {
            throw new \RuntimeException($label . ' parent authority is unsafe.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $platform->assertRebootstrapDerivedRootPosixAuthority(
                    $parent,
                    $parentUid,
                    $parentGid,
                    $parentDevice,
                    $parentInode,
                    ((int)$parentStatus['mode']) & 0170000,
                    (int)($parentStatus['nlink'] ?? 0),
                    $parentMode,
                    $parentAuthorityProfile,
                );
        }
        $parentWindowsAuthority = \PHP_OS_FAMILY === 'Windows'
            ? $platform->captureRebootstrapDerivedRootAuthority(
                $parent,
                $parentIdentity,
                $parentAuthorityProfile,
            )
            : ['sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson([
                    'policy' => $parentAuthorityPolicy,
                    'scope' => 'parent',
                    'mode' => $parentMode,
                    'uid' => $parentUid,
                    'gid' => $parentGid,
                ]),
            ), 'sddl_b64' => ''];
        $status = @\lstat($root);
        if (!\is_array($status)) {
            if (\file_exists($root) || \is_link($root)) {
                throw new \RuntimeException($label . ' is indeterminate.');
            }
            if ($definition['preserved'] !== []) {
                throw new \RuntimeException(
                    $label . ' with preserved host state must already exist.',
                );
            }
            return [
                'authority_policy' => $authorityPolicy,
                'authority_sha256' => \hash(
                    'sha256',
                    $authorityPolicy . "\nabsent\n",
                ),
                'device' => '',
                'gid' => 0,
                'inode' => '',
                'mode' => 0,
                'parent_authority_sha256'
                    => (string)$parentWindowsAuthority['sha256'],
                'parent_authority_policy' => $parentAuthorityPolicy,
                'parent_device' => $parentDevice,
                'parent_gid' => $parentGid,
                'parent_inode' => $parentInode,
                'parent_mode' => $parentMode,
                'parent_uid' => $parentUid,
                'parent_windows_sddl_b64'
                    => (string)$parentWindowsAuthority['sddl_b64'],
                'present' => false,
                'uid' => 0,
                'windows_sddl_b64' => '',
            ];
        }
        if (\is_link($root)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' is linked or special.');
        }
        $identity = GatewayBoundedTreeWalker::identity($root);
        $device = \PHP_OS_FAMILY === 'Windows'
            ? (string)$identity['device']
            : (string)($status['dev'] ?? '');
        $inode = \PHP_OS_FAMILY === 'Windows'
            ? (string)$identity['inode']
            : (string)($status['ino'] ?? '');
        $mode = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : ((int)$status['mode'] & 0777);
        $uid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($status['uid'] ?? -1);
        $gid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($status['gid'] ?? -1);
        if (\PHP_OS_FAMILY !== 'Windows'
            && (($mode & 0700) !== 0700
                || ($mode & 0022) !== 0
                || $uid < 0
                || $gid < 0)
        ) {
            throw new \RuntimeException($label . ' authority is unsafe.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $platform->assertRebootstrapDerivedRootPosixAuthority(
                    $root,
                    $uid,
                    $gid,
                    $device,
                    $inode,
                    ((int)$status['mode']) & 0170000,
                    (int)($status['nlink'] ?? 0),
                    $mode,
                    $rootAuthorityProfile,
                );
        }
        $windowsAuthority = \PHP_OS_FAMILY === 'Windows'
            ? $platform->captureRebootstrapDerivedRootAuthority(
                $root,
                $identity,
                $rootAuthorityProfile,
            )
            : ['sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson([
                    'policy' => $authorityPolicy,
                    'mode' => $mode,
                    'uid' => $uid,
                    'gid' => $gid,
                ]),
            ), 'sddl_b64' => ''];
        return [
            'authority_policy' => $authorityPolicy,
            'authority_sha256' => (string)$windowsAuthority['sha256'],
            'device' => $device,
            'gid' => $gid,
            'inode' => $inode,
            'mode' => $mode,
            'parent_authority_sha256'
                => (string)$parentWindowsAuthority['sha256'],
            'parent_authority_policy' => $parentAuthorityPolicy,
            'parent_device' => $parentDevice,
            'parent_gid' => $parentGid,
            'parent_inode' => $parentInode,
            'parent_mode' => $parentMode,
            'parent_uid' => $parentUid,
            'parent_windows_sddl_b64'
                => (string)$parentWindowsAuthority['sddl_b64'],
            'present' => true,
            'uid' => $uid,
            'windows_sddl_b64' => (string)$windowsAuthority['sddl_b64'],
        ];
    }

    /**
     * @param array<string,mixed> $proof
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     */
    private function assertRebootstrapDerivedRootProofContract(
        array $proof,
        array $definition,
        string $label,
    ): void {
        $keys = \array_keys($proof);
        \sort($keys, SORT_STRING);
        $present = $proof['present'] ?? null;
        $platformIdentity = static function (mixed $value): bool {
            if (!\is_string($value)) {
                return false;
            }
            return \PHP_OS_FAMILY === 'Windows'
                ? \preg_match('/\A[a-f0-9]{8,32}\z/D', $value) === 1
                : \preg_match('/\A[0-9]+\z/D', $value) === 1;
        };
        if ($keys !== [
                'authority_policy',
                'authority_sha256',
                'device',
                'gid',
                'inode',
                'mode',
                'parent_authority_policy',
                'parent_authority_sha256',
                'parent_device',
                'parent_gid',
                'parent_inode',
                'parent_mode',
                'parent_uid',
                'parent_windows_sddl_b64',
                'present',
                'uid',
                'windows_sddl_b64',
            ]
            || !\is_bool($present)
            || !\hash_equals(
                $this->rebootstrapDerivedRootAuthorityPolicy($definition),
                (string)($proof['authority_policy'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['authority_sha256'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($proof['parent_authority_sha256'] ?? ''),
            ) !== 1
            || !\hash_equals(
                ($this->platform
                    ?? new GatewayPlatformServiceInstaller($this->paths))
                    ->rebootstrapDerivedRootAuthorityProfile(
                        \dirname($definition['root']),
                    ) . '-fixed-parent',
                (string)($proof['parent_authority_policy'] ?? ''),
            )
            || !$platformIdentity($proof['parent_device'] ?? null)
            || !$platformIdentity($proof['parent_inode'] ?? null)
            || !\is_int($proof['mode'] ?? null)
            || !\is_int($proof['uid'] ?? null)
            || !\is_int($proof['gid'] ?? null)
            || !\is_int($proof['parent_mode'] ?? null)
            || !\is_int($proof['parent_uid'] ?? null)
            || !\is_int($proof['parent_gid'] ?? null)
            || ($present === true
                && (!$platformIdentity($proof['device'] ?? null)
                    || !$platformIdentity($proof['inode'] ?? null)))
            || ($present === false
                && (!\hash_equals('', (string)($proof['device'] ?? ''))
                    || !\hash_equals('', (string)($proof['inode'] ?? ''))
                    || (int)$proof['mode'] !== 0
                    || (int)$proof['uid'] !== 0
                    || (int)$proof['gid'] !== 0
                    || !\hash_equals(
                        '',
                        (string)($proof['windows_sddl_b64'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$proof['authority_sha256'],
                        \hash(
                            'sha256',
                            (string)$proof['authority_policy'] . "\nabsent\n",
                        ),
                    )
                    || $definition['preserved'] !== []))
            || (\PHP_OS_FAMILY === 'Windows'
                && ((int)$proof['mode'] !== 0
                    || (int)$proof['uid'] !== 0
                    || (int)$proof['gid'] !== 0
                    || (int)$proof['parent_mode'] !== 0
                    || (int)$proof['parent_uid'] !== 0
                    || (int)$proof['parent_gid'] !== 0
                    || !$this->rebootstrapWindowsSddlProofValid(
                        (string)($proof['parent_windows_sddl_b64'] ?? ''),
                        (string)$proof['parent_authority_sha256'],
                    )
                    || ($present === true
                        && (!$this->rebootstrapWindowsSddlProofValid(
                            (string)($proof['windows_sddl_b64'] ?? ''),
                            (string)$proof['authority_sha256'],
                        )))))
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)$proof['parent_mode'] & 0700) !== 0700
                    || ((int)$proof['parent_mode'] & 0022) !== 0
                    || (int)$proof['parent_uid'] < 0
                    || (int)$proof['parent_gid'] < 0
                    || !\hash_equals(
                        '',
                        (string)($proof['parent_windows_sddl_b64'] ?? ''),
                    )
                    || !\hash_equals(
                        (string)$proof['parent_authority_sha256'],
                        \hash(
                            'sha256',
                            GatewayClient::canonicalJson([
                                'policy'
                                    => (string)$proof['parent_authority_policy'],
                                'scope' => 'parent',
                                'mode' => (int)$proof['parent_mode'],
                                'uid' => (int)$proof['parent_uid'],
                                'gid' => (int)$proof['parent_gid'],
                            ]),
                        ),
                    )
                    || ($present === true
                        && (((int)$proof['mode'] & 0700) !== 0700
                            || ((int)$proof['mode'] & 0022) !== 0
                            || (int)$proof['uid'] < 0
                            || (int)$proof['gid'] < 0
                            || !\hash_equals(
                                '',
                                (string)($proof['windows_sddl_b64'] ?? ''),
                            )
                            || !\hash_equals(
                                (string)$proof['authority_sha256'],
                                \hash(
                                    'sha256',
                                    GatewayClient::canonicalJson([
                                        'policy' => (string)$proof['authority_policy'],
                                        'mode' => (int)$proof['mode'],
                                        'uid' => (int)$proof['uid'],
                                        'gid' => (int)$proof['gid'],
                                    ]),
                                ),
                            )))))
        ) {
            throw new \RuntimeException($label . ' proof is invalid.');
        }
    }

    private function rebootstrapWindowsSddlProofValid(
        string $encoded,
        string $expectedSha256,
    ): bool {
        $sddl = \base64_decode($encoded, true);
        return \is_string($sddl)
            && $sddl !== ''
            && \strlen($sddl) <= 8192
            && !\str_contains($sddl, "\0")
            && \hash_equals(\base64_encode($sddl), $encoded)
            && \hash_equals($expectedSha256, \hash('sha256', $sddl));
    }

    /**
     * @param array<string,mixed> $expected
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     */
    private function assertRebootstrapDerivedRootAt(
        array $expected,
        array $definition,
        string $label,
        bool $requireOriginalIdentity = true,
    ): void {
        $this->assertRebootstrapDerivedRootProofContract(
            $expected,
            $definition,
            $label,
        );
        $actual = $this->captureRebootstrapDerivedRootProof(
            $definition,
            $label,
        );
        if (!$requireOriginalIdentity) {
            $actual['device'] = $expected['device'];
            $actual['inode'] = $expected['inode'];
        }
        if (!\hash_equals(
            GatewayClient::canonicalJson($expected),
            GatewayClient::canonicalJson($actual),
        )) {
            throw new \RuntimeException($label . ' authority or identity changed.');
        }
    }

    /**
     * @param array<string,mixed> $expected
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     */
    private function assertRebootstrapDerivedParentAt(
        array $expected,
        array $definition,
        string $label,
    ): void {
        $this->assertRebootstrapDerivedRootProofContract(
            $expected,
            $definition,
            $label,
        );
        $parent = \dirname($definition['root']);
        $status = @\lstat($parent);
        if (!\is_array($status)
            || \is_link($parent)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' parent authority is unsafe.');
        }
        $identity = GatewayBoundedTreeWalker::identity($parent);
        $device = \PHP_OS_FAMILY === 'Windows'
            ? (string)$identity['device']
            : (string)($status['dev'] ?? '');
        $inode = \PHP_OS_FAMILY === 'Windows'
            ? (string)$identity['inode']
            : (string)($status['ino'] ?? '');
        $mode = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : ((int)$status['mode'] & 0777);
        $uid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($status['uid'] ?? -1);
        $gid = \PHP_OS_FAMILY === 'Windows'
            ? 0
            : (int)($status['gid'] ?? -1);
        if (\PHP_OS_FAMILY !== 'Windows'
            && (($mode & 0700) !== 0700
                || ($mode & 0022) !== 0
                || $uid < 0
                || $gid < 0)
        ) {
            throw new \RuntimeException($label . ' parent authority is unsafe.');
        }
        $platform = $this->platform
            ?? new GatewayPlatformServiceInstaller($this->paths);
        $parentAuthorityProfile = $platform
            ->rebootstrapDerivedRootAuthorityProfile($parent);
        $parentAuthorityPolicy = $parentAuthorityProfile . '-fixed-parent';
        if (!\hash_equals(
            $parentAuthorityPolicy,
            (string)$expected['parent_authority_policy'],
        )) {
            throw new \RuntimeException(
                $label . ' parent authority policy changed.',
            );
        }
        $authority = \PHP_OS_FAMILY === 'Windows'
            ? $platform->captureRebootstrapDerivedRootAuthority(
                $parent,
                $identity,
                $parentAuthorityProfile,
            )
            : ['sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson([
                    'policy' => $parentAuthorityPolicy,
                    'scope' => 'parent',
                    'mode' => $mode,
                    'uid' => $uid,
                    'gid' => $gid,
                ]),
            ), 'sddl_b64' => ''];
        if (\PHP_OS_FAMILY !== 'Windows') {
            $platform->assertRebootstrapDerivedRootPosixAuthority(
                    $parent,
                    $uid,
                    $gid,
                    $device,
                    $inode,
                    ((int)$status['mode']) & 0170000,
                    (int)($status['nlink'] ?? 0),
                    $mode,
                    $parentAuthorityProfile,
                );
        }
        $expectedParent = [
            'authority_sha256'
                => (string)$expected['parent_authority_sha256'],
            'device' => (string)$expected['parent_device'],
            'gid' => (int)$expected['parent_gid'],
            'inode' => (string)$expected['parent_inode'],
            'mode' => (int)$expected['parent_mode'],
            'uid' => (int)$expected['parent_uid'],
            'windows_sddl_b64'
                => (string)$expected['parent_windows_sddl_b64'],
        ];
        $actualParent = [
            'authority_sha256' => (string)$authority['sha256'],
            'device' => $device,
            'gid' => $gid,
            'inode' => $inode,
            'mode' => $mode,
            'uid' => $uid,
            'windows_sddl_b64' => (string)$authority['sddl_b64'],
        ];
        if (!\hash_equals(
            GatewayClient::canonicalJson($expectedParent),
            GatewayClient::canonicalJson($actualParent),
        )) {
            throw new \RuntimeException(
                $label . ' parent authority or identity changed.',
            );
        }
    }

    /**
     * @param array<string,mixed> $journal
     * @return array{digest:string,policy_digest:string,manifest:array<string,mixed>}
     */
    private function prepareRebootstrapDerivedManifest(
        array $journal,
        string $backup,
    ): array {
        $diskPressure = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'disk-pressure.marker';
        if (\file_exists($diskPressure) || \is_link($diskPressure)) {
            throw new \RuntimeException(
                'Gateway CA trust rebootstrap is blocked while disk pressure is latched.',
            );
        }
        $file = $this->paths->rebootstrapDerivedManifestFile(
            (string)$journal['nonce'],
        );
        $this->recoverRebootstrapDerivedManifestAtomicWrite($journal);
        if (\file_exists($file) || \is_link($file)) {
            $loaded = $this->readRebootstrapDerivedManifest($journal);
            $this->assertRebootstrapDerivedManifestLocations(
                $loaded['manifest'],
                $backup,
                'existing gateway rebootstrap derived-state manifest',
            );
            return $loaded;
        }
        if ((string)$journal['old_derived_manifest_sha256'] !== '') {
            throw new \RuntimeException(
                'Bound gateway rebootstrap derived-state manifest is missing.',
            );
        }
        $derivedRoot = $this->paths->rebootstrapDerivedBackupDir(
            (string)$journal['nonce'],
        );
        if (\file_exists($derivedRoot) || \is_link($derivedRoot)) {
            $entries = $this->rebootstrapRawTopLevelEntries(
                $derivedRoot,
                [],
                'Gateway rebootstrap derived backup root',
            );
            if ($entries !== []) {
                throw new \RuntimeException(
                    'Unbound gateway rebootstrap derived backup is not empty.',
                );
            }
        }

        $entryCount = 0;
        $totalBytes = 0;
        $windowsAclBytes = 0;
        $categories = [];
        $oldBaseline = $this->trustBundleBaselineProof(
            'Gateway rebootstrap old CA trust baseline',
        );
        if (!\hash_equals(
            (string)$journal['old_ca_bundle_sha256'],
            (string)$oldBaseline['sha256'],
        )) {
            throw new \RuntimeException(
                'Gateway old CA trust baseline changed before derived-state capture.',
            );
        }
        $definitions = $this->rebootstrapDerivedNamespaces();
        $policyDescriptor = $this->rebootstrapDerivedPolicyDescriptor(
            $definitions,
        );
        $policyDigest = $this->rebootstrapDerivedPolicyDigest(
            $policyDescriptor,
        );
        foreach ($definitions as $category => $definition) {
            $rootProof = $this->captureRebootstrapDerivedRootProof(
                $definition,
                'Gateway rebootstrap ' . $category . ' namespace root',
            );
            $entries = [];
            foreach ($this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap ' . $category . ' namespace',
            ) as $leaf => $path) {
                $entries[$leaf] = $this->captureRebootstrapDerivedClosure(
                    $path,
                    'Gateway rebootstrap ' . $category . '/' . $leaf,
                    $entryCount,
                    $totalBytes,
                    $definition['authority_profile'],
                    $windowsAclBytes,
                );
            }
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                'Gateway rebootstrap ' . $category . ' namespace root recheck',
            );
            $categories[$category] = [
                'root_id' => $definition['root_id'],
                'policy' => $definition['policy'],
                'authority_profile' => $definition['authority_profile'],
                'preserved' => $policyDescriptor[$category]['preserved'],
                'root' => $rootProof,
                'entries' => $entries,
            ];
        }
        $manifest = [
            'schema_version' => 4,
            'operation' => 'wls-rebootstrap-derived-state',
            'nonce' => (string)$journal['nonce'],
            'host_id' => (string)$journal['host_id'],
            'old_ca_bundle_sha256' => (string)$journal['old_ca_bundle_sha256'],
            'derived_policy_sha256' => $policyDigest,
            'entry_count' => $entryCount,
            'total_bytes' => $totalBytes,
            'windows_acl_bytes' => $windowsAclBytes,
            'categories' => $categories,
        ];
        $this->assertRebootstrapDerivedManifestContract($manifest, $journal);
        $contents = GatewayClient::canonicalJson($manifest) . "\n";
        if (\strlen($contents) > self::REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest exceeds its fixed size limit.',
            );
        }
        $this->atomicWrite($file, $contents, 0600);
        return [
            'digest' => \hash('sha256', $contents),
            'policy_digest' => $policyDigest,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string,mixed> $journal
     * @return array{digest:string,policy_digest:string,manifest:array<string,mixed>}
     */
    private function readRebootstrapDerivedManifest(array $journal): array
    {
        $this->recoverRebootstrapDerivedManifestAtomicWrite($journal);
        $contents = $this->readStableRegularFile(
            $this->paths->rebootstrapDerivedManifestFile(
                (string)$journal['nonce'],
            ),
            self::REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES,
            'Gateway rebootstrap derived-state manifest',
        );
        $manifest = \json_decode($contents, true);
        if (!\is_array($manifest)
            || \array_is_list($manifest)
            || !\hash_equals(
                GatewayClient::canonicalJson($manifest) . "\n",
                $contents,
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest is not canonical JSON.',
            );
        }
        $this->assertRebootstrapDerivedManifestContract($manifest, $journal);
        $digest = \hash('sha256', $contents);
        $expected = (string)$journal['old_derived_manifest_sha256'];
        if ($expected !== '' && !\hash_equals($expected, $digest)) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest digest changed.',
            );
        }
        return [
            'digest' => $digest,
            'policy_digest' => (string)$manifest['derived_policy_sha256'],
            'manifest' => $manifest,
        ];
    }

    /** @param array<string,mixed> $journal */
    private function recoverRebootstrapDerivedManifestAtomicWrite(
        array $journal,
    ): void {
        $file = $this->paths->rebootstrapDerivedManifestFile(
            (string)$journal['nonce'],
        );
        if (!\file_exists($file)
            && !\is_link($file)
            && GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $file,
                self::REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES,
                'Gateway rebootstrap derived-state manifest',
            )
        ) {
            if ((string)$journal['old_derived_manifest_sha256'] !== ''
                || !\hash_equals('QUIESCED', (string)$journal['phase'])
            ) {
                throw new \RuntimeException(
                    'Bound gateway rebootstrap derived-state manifest is missing with retained recovery evidence.',
                );
            }
            $derivedRoot = $this->paths->rebootstrapDerivedBackupDir(
                (string)$journal['nonce'],
            );
            if ($this->rebootstrapRawTopLevelEntries(
                $derivedRoot,
                [],
                'Unbound gateway rebootstrap derived backup',
            ) !== []) {
                throw new \RuntimeException(
                    'Unbound derived-state manifest staging cannot be discarded after state movement.',
                );
            }
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $file,
                self::REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES,
                'Gateway rebootstrap derived-state manifest',
            );
        }
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $file,
            self::REBOOTSTRAP_DERIVED_MANIFEST_MAX_BYTES,
            'Gateway rebootstrap derived-state manifest',
            function (string $contents) use ($journal): void {
                $manifest = \json_decode($contents, true);
                if (!\is_array($manifest)
                    || \array_is_list($manifest)
                    || !\hash_equals(
                        GatewayClient::canonicalJson($manifest) . "\n",
                        $contents,
                    )
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state recovery candidate is not canonical JSON.',
                    );
                }
                $this->assertRebootstrapDerivedManifestContract(
                    $manifest,
                    $journal,
                );
                $bound = (string)$journal['old_derived_manifest_sha256'];
                if ($bound !== ''
                    && !\hash_equals($bound, \hash('sha256', $contents))
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state recovery candidate differs from its bound digest.',
                    );
                }
            },
        );
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $journal
     */
    private function assertRebootstrapDerivedManifestContract(
        array $manifest,
        array $journal,
    ): void {
        $keys = \array_keys($manifest);
        \sort($keys, SORT_STRING);
        $expectedKeys = [
            'categories',
            'derived_policy_sha256',
            'entry_count',
            'host_id',
            'nonce',
            'old_ca_bundle_sha256',
            'operation',
            'schema_version',
            'total_bytes',
            'windows_acl_bytes',
        ];
        if ($keys !== $expectedKeys
            || ($manifest['schema_version'] ?? null) !== 4
            || !\hash_equals(
                'wls-rebootstrap-derived-state',
                (string)($manifest['operation'] ?? ''),
            )
            || !\hash_equals(
                (string)$journal['nonce'],
                (string)($manifest['nonce'] ?? ''),
            )
            || !\hash_equals(
                (string)$journal['host_id'],
                (string)($manifest['host_id'] ?? ''),
            )
            || !\hash_equals(
                (string)$journal['old_ca_bundle_sha256'],
                (string)($manifest['old_ca_bundle_sha256'] ?? ''),
            )
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($manifest['derived_policy_sha256'] ?? ''),
            ) !== 1
            || ((string)($journal['derived_policy_sha256'] ?? '') !== ''
                && !\hash_equals(
                    (string)$journal['derived_policy_sha256'],
                    (string)$manifest['derived_policy_sha256'],
                ))
            || !\is_int($manifest['entry_count'] ?? null)
            || (int)$manifest['entry_count'] < 0
            || (int)$manifest['entry_count']
                > self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES
            || !\is_int($manifest['total_bytes'] ?? null)
            || (int)$manifest['total_bytes'] < 0
            || (int)$manifest['total_bytes']
                > self::REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES
            || !\is_int($manifest['windows_acl_bytes'] ?? null)
            || (int)$manifest['windows_acl_bytes'] < 0
            || (int)$manifest['windows_acl_bytes']
                > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_TOTAL_MAX_BYTES
            || (\PHP_OS_FAMILY !== 'Windows'
                && (int)$manifest['windows_acl_bytes'] !== 0)
            || !\is_array($manifest['categories'] ?? null)
            || \array_is_list($manifest['categories'])
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest schema is invalid.',
            );
        }

        $definitions = $this->rebootstrapDerivedNamespaces();
        $expectedPolicyDescriptor = $this->rebootstrapDerivedPolicyDescriptor(
            $definitions,
        );
        $manifestPolicyDescriptor = [];
        $categoryKeys = \array_keys($manifest['categories']);
        $definitionKeys = \array_keys($definitions);
        \sort($categoryKeys, SORT_STRING);
        \sort($definitionKeys, SORT_STRING);
        if ($categoryKeys !== $definitionKeys) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state categories are incomplete.',
            );
        }
        $entryCount = 0;
        $totalBytes = 0;
        $windowsAclBytes = 0;
        foreach ($definitions as $category => $definition) {
            $categoryValue = $manifest['categories'][$category] ?? null;
            if (!\is_array($categoryValue) || \array_is_list($categoryValue)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived-state category is invalid: '
                        . $category,
                );
            }
            $valueKeys = \array_keys($categoryValue);
            \sort($valueKeys, SORT_STRING);
            if ($valueKeys !== [
                    'authority_profile',
                    'entries',
                    'policy',
                    'preserved',
                    'root',
                    'root_id',
                ]
                || !\is_array($categoryValue['root'] ?? null)
                || \array_is_list($categoryValue['root'])
                || !\is_array($categoryValue['entries'] ?? null)
                || ($categoryValue['entries'] !== []
                    && \array_is_list($categoryValue['entries']))
                || !\is_string($categoryValue['root_id'] ?? null)
                || !\hash_equals(
                    $definition['root_id'],
                    (string)$categoryValue['root_id'],
                )
                || !\is_string($categoryValue['policy'] ?? null)
                || !\hash_equals(
                    $definition['policy'],
                    (string)$categoryValue['policy'],
                )
                || !\is_string($categoryValue['authority_profile'] ?? null)
                || !\hash_equals(
                    $definition['authority_profile'],
                    (string)$categoryValue['authority_profile'],
                )
                || !\is_array($categoryValue['preserved'] ?? null)
                || !\array_is_list($categoryValue['preserved'])
                || $categoryValue['preserved']
                    !== $expectedPolicyDescriptor[$category]['preserved']
                || (!(bool)($categoryValue['root']['present'] ?? false)
                    && $categoryValue['entries'] !== [])
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived-state category contract is invalid: '
                    . $category,
                );
            }
            $manifestPolicyDescriptor[$category] = [
                'root_id' => (string)$categoryValue['root_id'],
                'policy' => (string)$categoryValue['policy'],
                'preserved' => $categoryValue['preserved'],
                'authority_profile'
                    => (string)$categoryValue['authority_profile'],
                'root_authority_policy'
                    => (string)($categoryValue['root']['authority_policy'] ?? ''),
            ];
            $this->assertRebootstrapDerivedRootProofContract(
                $categoryValue['root'],
                $definition,
                'Gateway rebootstrap derived-state category root ' . $category,
            );
            $caseFolded = [];
            foreach ($categoryValue['entries'] as $leaf => $closure) {
                if (!\is_string($leaf)
                    || !$this->rebootstrapDerivedLeafValid($leaf)
                    || !\is_array($closure)
                    || \array_is_list($closure)
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state entry is invalid.',
                    );
                }
                $folded = \PHP_OS_FAMILY === 'Windows'
                    ? \strtolower($leaf)
                    : $leaf;
                if (isset($caseFolded[$folded])) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state entry names collide.',
                    );
                }
                $caseFolded[$folded] = true;
                [$closureEntries, $closureBytes, $closureAclBytes] =
                    $this->assertRebootstrapDerivedClosureContract(
                        $closure,
                        $definition['authority_profile'],
                    );
                $entryCount += $closureEntries;
                $totalBytes += $closureBytes;
                $windowsAclBytes += $closureAclBytes;
                if ($entryCount > self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES
                    || $totalBytes > self::REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES
                    || $windowsAclBytes
                        > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_TOTAL_MAX_BYTES
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state manifest exceeds its safety envelope.',
                    );
                }
            }
        }
        \ksort($manifestPolicyDescriptor, SORT_STRING);
        $manifestPolicyDigest = $this->rebootstrapDerivedPolicyDigest(
            $manifestPolicyDescriptor,
        );
        $expectedPolicyDigest = $this->rebootstrapDerivedPolicyDigest(
            $expectedPolicyDescriptor,
        );
        if (!\hash_equals(
                (string)$manifest['derived_policy_sha256'],
                $manifestPolicyDigest,
            )
            || !\hash_equals($expectedPolicyDigest, $manifestPolicyDigest)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state policy differs from the in-flight host transaction.',
            );
        }
        $trustCategory = $manifest['categories']['trust'] ?? null;
        $baselineClosure = \is_array($trustCategory)
            && \is_array($trustCategory['entries'] ?? null)
                ? ($trustCategory['entries']['ca-bundle.sha256'] ?? null)
                : null;
        $baselineRecord = \is_array($baselineClosure)
            && \is_array($baselineClosure['records'] ?? null)
                ? ($baselineClosure['records'][0] ?? null)
                : null;
        $baselineContents = (string)$journal['old_ca_bundle_sha256'] . "\n";
        if (($trustCategory['root']['present'] ?? false) !== true
            || !\is_array($baselineClosure)
            || !\is_array($baselineRecord)
            || !\hash_equals('file', (string)($baselineClosure['kind'] ?? ''))
            || (int)($baselineClosure['entry_count'] ?? 0) !== 1
            || (int)($baselineClosure['total_bytes'] ?? -1) !== 65
            || !\hash_equals('.', (string)($baselineRecord['path'] ?? ''))
            || !\hash_equals('file', (string)($baselineRecord['kind'] ?? ''))
            || (int)($baselineRecord['size'] ?? -1) !== 65
            || !\hash_equals(
                \hash('sha256', $baselineContents),
                (string)($baselineRecord['sha256'] ?? ''),
            )
            || (int)($baselineRecord['mode'] ?? -1)
                !== (\PHP_OS_FAMILY === 'Windows' ? 0 : 0600)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest lacks the exact old CA trust baseline.',
            );
        }
        if ($entryCount !== (int)$manifest['entry_count']
            || $totalBytes !== (int)$manifest['total_bytes']
            || $windowsAclBytes !== (int)$manifest['windows_acl_bytes']
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state manifest totals are invalid.',
            );
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function assertRebootstrapDerivedClosureContract(
        array $closure,
        string $authorityProfile,
    ): array {
        $keys = \array_keys($closure);
        \sort($keys, SORT_STRING);
        if ($keys !== [
                'entry_count',
                'kind',
                'records',
                'sha256',
                'total_bytes',
            ]
            || !\in_array((string)($closure['kind'] ?? ''), ['directory', 'file'], true)
            || !\is_int($closure['entry_count'] ?? null)
            || (int)$closure['entry_count'] < 1
            || (int)$closure['entry_count']
                > self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES
            || !\is_int($closure['total_bytes'] ?? null)
            || (int)$closure['total_bytes'] < 0
            || (int)$closure['total_bytes']
                > self::REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($closure['sha256'] ?? ''),
            ) !== 1
            || !\is_array($closure['records'] ?? null)
            || !\array_is_list($closure['records'])
            || \count($closure['records']) !== (int)$closure['entry_count']
            || !\hash_equals(
                (string)$closure['sha256'],
                \hash('sha256', GatewayClient::canonicalJson($closure['records'])),
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state closure is invalid.',
            );
        }
        $paths = [];
        $bytes = 0;
        $windowsAclBytes = 0;
        foreach ($closure['records'] as $index => $record) {
            if (!\is_array($record) || \array_is_list($record)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived-state record is invalid.',
                );
            }
            $recordKeys = \array_keys($record);
            \sort($recordKeys, SORT_STRING);
            $kind = (string)($record['kind'] ?? '');
            $expected = \PHP_OS_FAMILY === 'Windows'
                ? ($kind === 'file'
                    ? [
                        'acl_profile',
                        'acl_sha256',
                        'gid',
                        'kind',
                        'mode',
                        'owner_sid',
                        'path',
                        'sddl_b64',
                        'sha256',
                        'size',
                        'uid',
                    ]
                    : [
                        'acl_profile',
                        'acl_sha256',
                        'gid',
                        'kind',
                        'mode',
                        'owner_sid',
                        'path',
                        'sddl_b64',
                        'uid',
                    ])
                : ($kind === 'file'
                    ? ['gid', 'kind', 'mode', 'path', 'sha256', 'size', 'uid']
                    : ['gid', 'kind', 'mode', 'path', 'uid']);
            $path = (string)($record['path'] ?? '');
            if ($recordKeys !== $expected
                || !\in_array($kind, ['directory', 'file'], true)
                || !$this->rebootstrapDerivedRelativePathValid($path)
                || !\is_int($record['mode'] ?? null)
                || (int)$record['mode'] < 0
                || (int)$record['mode'] > 0777
                || !\is_int($record['uid'] ?? null)
                || (int)$record['uid'] < 0
                || !\is_int($record['gid'] ?? null)
                || (int)$record['gid'] < 0
                || (\PHP_OS_FAMILY === 'Windows'
                    && ((int)$record['mode'] !== 0
                        || (int)$record['uid'] !== 0
                        || (int)$record['gid'] !== 0))
                || isset($paths[$path])
                || ($index === 0
                    && (!\hash_equals('.', $path)
                        || !\hash_equals((string)$closure['kind'], $kind)))
                || ($index > 0 && \hash_equals('.', $path))
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived-state record contract is invalid.',
                );
            }
            $paths[$path] = true;
            if (\PHP_OS_FAMILY === 'Windows') {
                $sddlBase64 = $record['sddl_b64'] ?? null;
                $sddl = \is_string($sddlBase64)
                    ? \base64_decode($sddlBase64, true)
                    : false;
                if (!\is_string($record['acl_profile'] ?? null)
                    || !\hash_equals(
                        $authorityProfile,
                        (string)$record['acl_profile'],
                    )
                    || !\is_string($record['owner_sid'] ?? null)
                    || !\in_array(
                        (string)$record['owner_sid'],
                        ['S-1-5-18', 'S-1-5-32-544'],
                        true,
                    )
                    || !\is_string($sddl)
                    || $sddl === ''
                    || \strlen($sddl)
                        > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_MAX_BYTES
                    || \str_contains($sddl, "\0")
                    || !\hash_equals(\base64_encode($sddl), $sddlBase64)
                    || \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)($record['acl_sha256'] ?? ''),
                    ) !== 1
                    || !\hash_equals(
                        (string)$record['acl_sha256'],
                        \hash('sha256', $sddl),
                    )
                    || $windowsAclBytes
                        > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_TOTAL_MAX_BYTES
                            - \strlen($sddl)
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state Windows ACL record is invalid.',
                    );
                }
                $windowsAclBytes += \strlen($sddl);
            }
            if ($kind === 'file') {
                if (!\is_int($record['size'] ?? null)
                    || (int)$record['size'] < 0
                    || (int)$record['size'] > self::MAX_PACKAGE_BYTES
                    || \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)($record['sha256'] ?? ''),
                    ) !== 1
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state file record is invalid.',
                    );
                }
                $bytes += (int)$record['size'];
            }
        }
        if ($bytes !== (int)$closure['total_bytes']) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state closure byte total is invalid.',
            );
        }
        return [(int)$closure['entry_count'], $bytes, $windowsAclBytes];
    }

    private function rebootstrapDerivedLeafValid(string $leaf): bool
    {
        return $leaf !== ''
            && $leaf !== '.'
            && $leaf !== '..'
            && \strlen($leaf) <= 255
            && !\str_contains($leaf, "\0")
            && !\str_contains($leaf, '/')
            && !\str_contains($leaf, '\\');
    }

    private function rebootstrapDerivedRelativePathValid(string $relative): bool
    {
        if ($relative === '.') {
            return true;
        }
        if ($relative === ''
            || \strlen($relative) > 32768
            || \str_contains($relative, "\0")
            || \str_contains($relative, '\\')
            || \str_starts_with($relative, '/')
        ) {
            return false;
        }
        foreach (\explode('/', $relative) as $segment) {
            if (!$this->rebootstrapDerivedLeafValid($segment)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<string> $preserved
     * @return array<string,string>
     */
    private function rebootstrapRawTopLevelEntries(
        string $root,
        array $preserved,
        string $label,
    ): array {
        $before = @\lstat($root);
        if (!\is_array($before)) {
            if (\file_exists($root) || \is_link($root)) {
                throw new \RuntimeException($label . ' path is indeterminate.');
            }
            return [];
        }
        if (\is_link($root)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' root is linked or special.');
        }
        $rootIdentityBefore = GatewayBoundedTreeWalker::identity($root);
        $preservedProofs = [];
        foreach ($preserved as $preservedLeaf) {
            if (!$this->rebootstrapDerivedLeafValid($preservedLeaf)) {
                throw new \RuntimeException(
                    $label . ' contains an invalid preserved descriptor.',
                );
            }
            $path = $root . DIRECTORY_SEPARATOR . $preservedLeaf;
            $status = @\lstat($path);
            if (!\is_array($status)) {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException(
                        $label . ' preserved path is indeterminate.',
                    );
                }
                $status = null;
            } elseif (\is_link($path)) {
                throw new \RuntimeException(
                    $label . ' preserved path is linked.',
                );
            }
            $nativeIdentity = \is_array($status)
                ? GatewayBoundedTreeWalker::identity($path)
                : null;
            $preservedProofs[$preservedLeaf] = [
                'path' => $path,
                'status' => $status,
                'identity' => $nativeIdentity,
                'matched' => false,
            ];
        }
        $handle = @\opendir($root);
        if (!\is_resource($handle)) {
            throw new \RuntimeException($label . ' cannot be enumerated.');
        }
        $entries = [];
        $folded = [];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (($visited & 255) === 0) {
                    $this->assertOperationDeadlineAvailable(
                        'enumerating ' . $label,
                    );
                }
                if (++$visited > self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES
                    || !$this->rebootstrapDerivedLeafValid($leaf)
                ) {
                    throw new \RuntimeException(
                        $label . ' exceeds its fixed entry/name safety limit.',
                    );
                }
                $identity = \PHP_OS_FAMILY === 'Windows'
                    ? \strtolower($leaf)
                    : $leaf;
                if (isset($folded[$identity])) {
                    throw new \RuntimeException(
                        $label . ' contains colliding entry names.',
                    );
                }
                $folded[$identity] = true;
                $entryPath = $root . DIRECTORY_SEPARATOR . $leaf;
                $entryStatus = @\lstat($entryPath);
                if (!\is_array($entryStatus) || \is_link($entryPath)) {
                    throw new \RuntimeException(
                        $label . ' contains an indeterminate or linked entry.',
                    );
                }
                $entryIdentity = GatewayBoundedTreeWalker::identity($entryPath);
                $isPreserved = false;
                foreach ($preservedProofs as $preservedLeaf => &$proof) {
                    $preservedIdentity = $proof['identity'];
                    $sameObject = \is_array($preservedIdentity)
                        && \hash_equals(
                            (string)$preservedIdentity['device'],
                            (string)$entryIdentity['device'],
                        )
                        && \hash_equals(
                            (string)$preservedIdentity['inode'],
                            (string)$entryIdentity['inode'],
                        );
                    $caseAlias = \strcasecmp($leaf, $preservedLeaf) === 0;
                    if ($sameObject || $caseAlias) {
                        if (!\hash_equals($leaf, $preservedLeaf)
                            || !$sameObject
                            || (bool)$proof['matched']
                        ) {
                            throw new \RuntimeException(
                                $label . ' contains a noncanonical or duplicate preserved entry: '
                                    . $leaf . '.',
                            );
                        }
                        $proof['matched'] = true;
                        $isPreserved = true;
                        break;
                    }
                }
                unset($proof);
                if (!$isPreserved) {
                    $entries[$leaf] = $entryPath;
                }
            }
        } finally {
            @\closedir($handle);
        }
        $after = @\lstat($root);
        $rootIdentityAfter = GatewayBoundedTreeWalker::identity($root);
        if (!\is_array($after)
            || !$this->sameFileState($before, $after)
            || !\hash_equals(
                (string)$rootIdentityBefore['device'],
                (string)$rootIdentityAfter['device'],
            )
            || !\hash_equals(
                (string)$rootIdentityBefore['inode'],
                (string)$rootIdentityAfter['inode'],
            )
        ) {
            throw new \RuntimeException($label . ' changed during enumeration.');
        }
        foreach ($preservedProofs as $preservedLeaf => $proof) {
            if (!\is_array($proof['status'])) {
                continue;
            }
            $current = @\lstat((string)$proof['path']);
            $currentIdentity = GatewayBoundedTreeWalker::identity(
                (string)$proof['path'],
            );
            if (!(bool)$proof['matched']
                || !\is_array($current)
                || \is_link((string)$proof['path'])
                || !$this->sameFileState($proof['status'], $current)
                || !\hash_equals(
                    (string)$proof['identity']['device'],
                    (string)$currentIdentity['device'],
                )
                || !\hash_equals(
                    (string)$proof['identity']['inode'],
                    (string)$currentIdentity['inode'],
                )
            ) {
                throw new \RuntimeException(
                    $label . ' preserved entry changed during enumeration: '
                        . $preservedLeaf . '.',
                );
            }
        }
        \ksort($entries, SORT_STRING);
        return $entries;
    }

    /** @return array<string,mixed> */
    private function captureRebootstrapDerivedClosure(
        string $path,
        string $label,
        int &$entryCount,
        int &$totalBytes,
        ?string $windowsAuthorityProfile = null,
        ?int &$windowsAclBytes = null,
    ): array {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || (!$this->isRegularFileStatus($status)
                && ((((int)$status['mode']) & 0170000) !== 0040000))
            || ($this->isRegularFileStatus($status)
                && (int)($status['nlink'] ?? 0) !== 1)
        ) {
            throw new \RuntimeException($label . ' is linked or special.');
        }
        $topLevelFile = $this->isRegularFileStatus($status);
        $records = $topLevelFile
            ? [GatewayBoundedTreeWalker::identity($path)]
            : GatewayBoundedTreeWalker::collect(
                $path,
                true,
                false,
                self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES,
                GatewayBoundedTreeWalker::MAX_DEPTH,
                fn (): null => $this->deadlineProgress(
                    'capturing ' . $label,
                ),
            );
        \usort(
            $records,
            static function (array $left, array $right) use ($path): int {
                $leftRelative = \hash_equals($left['path'], $path)
                    ? '.'
                    : \str_replace('\\', '/', \substr(
                        $left['path'],
                        \strlen($path) + 1,
                    ));
                $rightRelative = \hash_equals($right['path'], $path)
                    ? '.'
                    : \str_replace('\\', '/', \substr(
                        $right['path'],
                        \strlen($path) + 1,
                    ));
                if ($leftRelative === '.') {
                    return $rightRelative === '.' ? 0 : -1;
                }
                if ($rightRelative === '.') {
                    return 1;
                }
                return \strcmp($leftRelative, $rightRelative);
            },
        );
        $closureRecords = [];
        $observedStatuses = [];
        $closureBytes = 0;
        $platform = null;
        foreach ($records as $record) {
            $entryStatus = GatewayBoundedTreeWalker::revalidate($record);
            if (!\is_array($entryStatus)
                || \is_link($record['path'])
                || ($topLevelFile
                    && (!$this->isRegularFileStatus($entryStatus)
                        || (int)($entryStatus['nlink'] ?? 0) !== 1))
            ) {
                throw new \RuntimeException(
                    $label . ' entry changed before closure capture.',
                );
            }
            $relative = \hash_equals($record['path'], $path)
                ? '.'
                : \str_replace('\\', '/', \substr(
                    $record['path'],
                    \strlen($path) + 1,
                ));
            $kind = $record['directory'] ? 'directory' : 'file';
            $closureRecord = [
                'path' => $relative,
                'kind' => $kind,
                'mode' => \PHP_OS_FAMILY === 'Windows'
                    ? 0
                    : ((int)$entryStatus['mode'] & 0777),
                'uid' => \PHP_OS_FAMILY === 'Windows'
                    ? 0
                    : (int)($entryStatus['uid'] ?? -1),
                'gid' => \PHP_OS_FAMILY === 'Windows'
                    ? 0
                    : (int)($entryStatus['gid'] ?? -1),
            ];
            if ($closureRecord['uid'] < 0 || $closureRecord['gid'] < 0) {
                throw new \RuntimeException(
                    $label . ' ownership cannot be resolved.',
                );
            }
            $platform ??= $this->platform
                ?? new GatewayPlatformServiceInstaller($this->paths);
            if (\PHP_OS_FAMILY !== 'Windows') {
                $platform->assertRebootstrapDerivedDescendantPosixAclFree(
                    (string)$record['path'],
                    (bool)$record['directory'],
                );
            }
            if (\PHP_OS_FAMILY === 'Windows'
                && $windowsAuthorityProfile !== null
            ) {
                $authority = $platform
                    ->captureRebootstrapDerivedDescendantAuthority(
                        (string)$record['path'],
                        (bool)$record['directory'],
                        $windowsAuthorityProfile,
                    );
                $authorityKeys = \array_keys($authority);
                \sort($authorityKeys, SORT_STRING);
                $sddl = \is_string($authority['sddl_b64'] ?? null)
                    ? \base64_decode((string)$authority['sddl_b64'], true)
                    : false;
                if ($authorityKeys !== [
                        'acl_profile',
                        'owner_sid',
                        'sddl_b64',
                        'sha256',
                    ]
                    || !\hash_equals(
                        $windowsAuthorityProfile,
                        (string)($authority['acl_profile'] ?? ''),
                    )
                    || !\is_string($sddl)
                    || $sddl === ''
                    || \strlen($sddl)
                        > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_MAX_BYTES
                    || !\hash_equals(
                        \base64_encode($sddl),
                        (string)$authority['sddl_b64'],
                    )
                    || !\hash_equals(
                        (string)($authority['sha256'] ?? ''),
                        \hash('sha256', $sddl),
                    )
                ) {
                    throw new \RuntimeException(
                        $label . ' Windows ACL authority proof is malformed.',
                    );
                }
                $windowsAclBytes ??= 0;
                if ($windowsAclBytes
                    > self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_TOTAL_MAX_BYTES
                        - \strlen($sddl)
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state Windows ACL proofs exceed their fixed limit.',
                    );
                }
                $windowsAclBytes += \strlen($sddl);
                $closureRecord['acl_profile'] = $windowsAuthorityProfile;
                $closureRecord['owner_sid'] = (string)$authority['owner_sid'];
                $closureRecord['sddl_b64'] = (string)$authority['sddl_b64'];
                $closureRecord['acl_sha256'] = (string)$authority['sha256'];
            }
            if (!$record['directory']) {
                $digest = $this->digestStableRegularFile(
                    $record['path'],
                    self::MAX_PACKAGE_BYTES,
                    $label . ' file ' . $relative,
                );
                $closureRecord['sha256'] = $digest['sha256'];
                $closureRecord['size'] = $digest['size'];
                $closureBytes += $digest['size'];
            }
            $observedStatuses[] = [$record, $entryStatus];
            $closureRecords[] = $closureRecord;
        }
        foreach ($observedStatuses as [$record, $before]) {
            $after = GatewayBoundedTreeWalker::revalidate($record);
            if (!\is_array($after) || !$this->sameFileState($before, $after)) {
                throw new \RuntimeException(
                    $label . ' changed while its closure was captured.',
                );
            }
        }
        $entryCount += \count($closureRecords);
        $totalBytes += $closureBytes;
        if ($entryCount > self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES
            || $totalBytes > self::REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived state exceeds its fixed safety envelope.',
            );
        }
        return [
            'kind' => $topLevelFile ? 'file' : 'directory',
            'entry_count' => \count($closureRecords),
            'total_bytes' => $closureBytes,
            'sha256' => \hash(
                'sha256',
                GatewayClient::canonicalJson($closureRecords),
            ),
            'records' => $closureRecords,
        ];
    }

    /** @param array<string,mixed> $expected */
    private function assertRebootstrapDerivedClosureAt(
        string $path,
        array $expected,
        string $label,
        string $authorityProfile,
        string $windowsAuthority = self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL,
    ): void {
        if (!\in_array($windowsAuthority, [
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL,
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED,
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_CONTENT_ONLY,
        ], true)) {
            throw new \RuntimeException(
                'Gateway rebootstrap derived-state ACL location is invalid.',
            );
        }
        $entries = 0;
        $bytes = 0;
        $windowsAclBytes = 0;
        $captureOriginalWindowsAuthority = \PHP_OS_FAMILY === 'Windows'
            && \hash_equals(
                self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL,
                $windowsAuthority,
            );
        $actual = $this->captureRebootstrapDerivedClosure(
            $path,
            $label,
            $entries,
            $bytes,
            $captureOriginalWindowsAuthority ? $authorityProfile : null,
            $windowsAclBytes,
        );
        $comparison = $expected;
        if (\PHP_OS_FAMILY === 'Windows'
            && !$captureOriginalWindowsAuthority
        ) {
            $comparison = $this->withoutRebootstrapDerivedWindowsAclProofs(
                $expected,
            );
            if (!\hash_equals(
                self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_CONTENT_ONLY,
                $windowsAuthority,
            )) {
                $this->assertRebootstrapDerivedWindowsAuthorityAt(
                    $path,
                    $expected,
                    $label,
                    $authorityProfile,
                    $windowsAuthority,
                );
            }
        }
        if (!\hash_equals(
            GatewayClient::canonicalJson($comparison),
            GatewayClient::canonicalJson($actual),
        )) {
            throw new \RuntimeException($label . ' closure changed.');
        }
    }

    /** @param array<string,mixed> $closure @return array<string,mixed> */
    private function withoutRebootstrapDerivedWindowsAclProofs(
        array $closure,
    ): array {
        $records = [];
        foreach ((array)($closure['records'] ?? []) as $record) {
            if (!\is_array($record) || \array_is_list($record)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived-state ACL normalization received an invalid record.',
                );
            }
            unset(
                $record['acl_profile'],
                $record['acl_sha256'],
                $record['owner_sid'],
                $record['sddl_b64'],
            );
            $records[] = $record;
        }
        $closure['records'] = $records;
        $closure['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($records),
        );
        return $closure;
    }

    /** @param array<string,mixed> $closure */
    private function assertRebootstrapDerivedWindowsAuthorityAt(
        string $path,
        array $closure,
        string $label,
        string $authorityProfile,
        string $windowsAuthority,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $platform = $this->platform
            ?? new GatewayPlatformServiceInstaller($this->paths);
        foreach ((array)$closure['records'] as $record) {
            $relative = (string)($record['path'] ?? '');
            $entry = \hash_equals('.', $relative)
                ? $path
                : $path . DIRECTORY_SEPARATOR . \str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative,
                );
            $directory = \hash_equals(
                'directory',
                (string)($record['kind'] ?? ''),
            );
            $assertOriginal = function () use (
                $platform,
                $entry,
                $directory,
                $authorityProfile,
                $record,
                $label,
                $relative,
            ): void {
                $actual = $platform
                    ->captureRebootstrapDerivedDescendantAuthority(
                        $entry,
                        $directory,
                        $authorityProfile,
                    );
                $expected = [
                    'acl_profile' => (string)$record['acl_profile'],
                    'owner_sid' => (string)$record['owner_sid'],
                    'sddl_b64' => (string)$record['sddl_b64'],
                    'sha256' => (string)$record['acl_sha256'],
                ];
                if (!\hash_equals(
                    GatewayClient::canonicalJson($expected),
                    GatewayClient::canonicalJson($actual),
                )) {
                    throw new \RuntimeException(
                        $label . ' Windows ACL changed at ' . $relative . '.',
                    );
                }
            };
            if (\hash_equals(
                self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_SEALED,
                $windowsAuthority,
            )) {
                $platform->assertRebootstrapDerivedBackupDescendantAuthority(
                    $entry,
                    $directory,
                );
                continue;
            }
            try {
                $assertOriginal();
            } catch (\Throwable $originalFailure) {
                if (!\hash_equals(
                    self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED,
                    $windowsAuthority,
                )) {
                    throw $originalFailure;
                }
                try {
                    $platform
                        ->assertRebootstrapDerivedBackupDescendantAuthority(
                            $entry,
                            $directory,
                        );
                } catch (\Throwable $sealedFailure) {
                    throw new \RuntimeException(
                        $label . ' Windows ACL is neither the original profile nor the sealed backup profile at '
                            . $relative . '.',
                        0,
                        $sealedFailure,
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $closure */
    private function restoreRebootstrapDerivedWindowsAuthorityAt(
        string $path,
        array $closure,
        string $label,
        string $authorityProfile,
        string $firstAclCrashPoint,
    ): void {
        if (\PHP_OS_FAMILY !== 'Windows') {
            $this->assertRebootstrapDerivedClosureAt(
                $path,
                $closure,
                $label,
                $authorityProfile,
            );
            return;
        }
        $this->assertRebootstrapDerivedClosureAt(
            $path,
            $closure,
            $label . ' content preflight',
            $authorityProfile,
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_CONTENT_ONLY,
        );
        $platform = $this->platform
            ?? new GatewayPlatformServiceInstaller($this->paths);
        foreach ((array)$closure['records'] as $index => $record) {
            $relative = (string)$record['path'];
            $entry = \hash_equals('.', $relative)
                ? $path
                : $path . DIRECTORY_SEPARATOR . \str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative,
                );
            $platform->restoreRebootstrapDerivedDescendantAuthority(
                $entry,
                \hash_equals('directory', (string)$record['kind']),
                $authorityProfile,
                (string)$record['owner_sid'],
                (string)$record['sddl_b64'],
                (string)$record['acl_sha256'],
            );
            if ($index === 0) {
                $this->injectRebootstrapCrash($firstAclCrashPoint);
            }
        }
        $this->assertRebootstrapDerivedClosureAt(
            $path,
            $closure,
            $label,
            $authorityProfile,
        );
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function assertRebootstrapDerivedManifestLocations(
        array $manifest,
        string $backup,
        string $label,
    ): void {
        $derivedRoot = $backup . DIRECTORY_SEPARATOR . 'derived';
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $this->assertRebootstrapDerivedRootAt(
                (array)$manifest['categories'][$category]['root'],
                $definition,
                $label . ' live root ' . $category,
            );
            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                $label . ' live ' . $category,
            );
            $storedRoot = $derivedRoot . DIRECTORY_SEPARATOR . $category;
            $stored = $this->rebootstrapRawTopLevelEntries(
                $storedRoot,
                [],
                $label . ' stored ' . $category,
            );
            $expected = (array)$manifest['categories'][$category]['entries'];
            foreach ([\array_keys($live), \array_keys($stored)] as $observed) {
                foreach ($observed as $leaf) {
                    if (!\array_key_exists($leaf, $expected)) {
                        throw new \RuntimeException(
                            $label . ' contains an undeclared ' . $category
                                . '/' . $leaf . ' entry.',
                        );
                    }
                }
            }
            foreach ($expected as $leaf => $closure) {
                $inLive = isset($live[$leaf]);
                $inStored = isset($stored[$leaf]);
                if ($inLive === $inStored) {
                    throw new \RuntimeException(
                        $label . ' requires ' . $category . '/' . $leaf
                            . ' at exactly one old-generation location.',
                    );
                }
                $this->assertRebootstrapDerivedClosureAt(
                    $inLive ? $live[$leaf] : $stored[$leaf],
                    $closure,
                    $label . ' ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
            }
        }
    }

    /** @param array<string,mixed> $journal */
    private function stashRebootstrapDerivedState(
        array $journal,
        string $backup,
    ): void {
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        $this->assertRebootstrapDerivedManifestLocations(
            $manifest,
            $backup,
            'gateway rebootstrap derived-state stash',
        );
        $derivedRoot = $this->paths->rebootstrapDerivedBackupDir(
            (string)$journal['nonce'],
        );
        $this->ensurePrivateRebootstrapDirectory($derivedRoot);
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $storedRoot = $derivedRoot . DIRECTORY_SEPARATOR . $category;
            $this->ensurePrivateRebootstrapDirectory($storedRoot);
            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap live ' . $category,
            );
            foreach ((array)$manifest['categories'][$category]['entries'] as $leaf => $closure) {
                if (!isset($live[$leaf])) {
                    continue;
                }
                $destination = $storedRoot . DIRECTORY_SEPARATOR . $leaf;
                if (\file_exists($destination) || \is_link($destination)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap derived-state destination already exists.',
                    );
                }
                $this->assertRebootstrapDerivedClosureAt(
                    $live[$leaf],
                    $closure,
                    'old gateway derived state ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
                GatewayProjectStateFilesystem::moveNoReplace(
                    $live[$leaf],
                    $destination,
                    'Gateway old derived-state stash '
                        . $category . '/' . $leaf,
                );
                $this->assertRebootstrapDerivedClosureAt(
                    $destination,
                    $closure,
                    'stored old gateway derived state ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
            }
        }
        $this->assertRetainedRebootstrapDerivedBackup(
            $journal,
            $backup,
            self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL,
        );
        $this->assertRebootstrapDerivedLiveNamespaceEmpty(
            $journal,
            'stashed old gateway trust generation',
        );
    }

    /**
     * A same-CA launcher replacement receives a byte-for-byte working copy of
     * the old durable state. The retained source stays immutable so rollback
     * can quarantine mutations made by the new Controller and restore the
     * original after-image.
     *
     * @param array<string,mixed> $journal
     */
    private function publishRebootstrapDerivedWorkingCopy(
        array $journal,
    ): void {
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        $nonce = (string)$journal['nonce'];
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $sourceRoot = $this->paths->rebootstrapDerivedBackupDir($nonce);
        $workingRoot = $backup . DIRECTORY_SEPARATOR . 'working-generation';
        $this->ensurePrivateRebootstrapDirectory($workingRoot);

        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $expected = \hash_equals('restore', (string)$definition['policy'])
                ? (array)$manifest['categories'][$category]['entries']
                : [];
            $workingCategory = $workingRoot . DIRECTORY_SEPARATOR . $category;
            $this->ensurePrivateRebootstrapDirectory($workingCategory);
            $storedRoot = $sourceRoot . DIRECTORY_SEPARATOR . $category;

            // A stream copy publishes through a deterministic regular-file
            // temporary. Discard only those manifest-bound names after a
            // crash; no recursive partial-tree deletion is needed.
            foreach ($expected as $leaf => $closure) {
                $this->cleanupRebootstrapDerivedCopyTemporaries(
                    $workingCategory . DIRECTORY_SEPARATOR . $leaf,
                    $closure,
                );
            }
            $staged = $this->rebootstrapRawTopLevelEntries(
                $workingCategory,
                [],
                'Gateway rebootstrap derived working staging ' . $category,
            );
            foreach (\array_keys($staged) as $leaf) {
                if (!\array_key_exists($leaf, $expected)) {
                    throw new \RuntimeException(
                        'Gateway derived working staging contains an unexpected entry: '
                            . $category . '/' . $leaf . '.',
                    );
                }
            }
            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap derived working live ' . $category,
            );
            foreach (\array_keys($live) as $leaf) {
                if (!\array_key_exists($leaf, $expected)) {
                    throw new \RuntimeException(
                        'Gateway derived working namespace contains an unexpected entry: '
                            . $category . '/' . $leaf . '.',
                    );
                }
            }

            foreach ($expected as $leaf => $closure) {
                $source = $storedRoot . DIRECTORY_SEPARATOR . $leaf;
                $staging = $workingCategory . DIRECTORY_SEPARATOR . $leaf;
                $target = $definition['root'] . DIRECTORY_SEPARATOR . $leaf;
                $targetExists = \file_exists($target) || \is_link($target);
                $stagingExists = \file_exists($staging) || \is_link($staging);
                if ($targetExists && $stagingExists) {
                    throw new \RuntimeException(
                        'Gateway derived working entry exists at both staging and live paths.',
                    );
                }
                $this->assertRebootstrapDerivedClosureAt(
                    $source,
                    $closure,
                    'retained old gateway derived source '
                        . $category . '/' . $leaf,
                    $definition['authority_profile'],
                    self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED,
                );
                if ($targetExists) {
                    $this->restoreRebootstrapDerivedWindowsAuthorityAt(
                        $target,
                        $closure,
                        'published gateway derived working copy '
                            . $category . '/' . $leaf,
                        $definition['authority_profile'],
                        'derived-working:after-first-acl',
                    );
                    continue;
                }
                $this->copyRebootstrapDerivedClosure(
                    $source,
                    $staging,
                    $closure,
                    'gateway derived working copy ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
                GatewayProjectStateFilesystem::moveNoReplace(
                    $staging,
                    $target,
                    'Gateway derived working-copy publication '
                        . $category . '/' . $leaf,
                );
                $this->injectRebootstrapCrash(
                    'derived-working:after-move-before-acl',
                );
                $this->restoreRebootstrapDerivedWindowsAuthorityAt(
                    $target,
                    $closure,
                    'published gateway derived working copy '
                        . $category . '/' . $leaf,
                    $definition['authority_profile'],
                    'derived-working:after-first-acl',
                );
            }
            if ($this->rebootstrapRawTopLevelEntries(
                $workingCategory,
                [],
                'Gateway completed derived working staging ' . $category,
            ) !== [] || !@\rmdir($workingCategory)) {
                throw new \RuntimeException(
                    'Gateway derived working staging category did not retire cleanly.',
                );
            }
            $this->syncRebootstrapDirectory($workingRoot);
        }
        if (!@\rmdir($workingRoot)) {
            throw new \RuntimeException(
                'Gateway derived working staging root did not retire cleanly.',
            );
        }
        $this->syncRebootstrapDirectory($backup);
        $this->assertRebootstrapDerivedWorkingCopy(
            $journal,
            'published same-CA gateway derived working copy',
        );
    }

    /** @param array<string,mixed> $closure */
    private function cleanupRebootstrapDerivedCopyTemporaries(
        string $target,
        array $closure,
    ): void {
        foreach ((array)$closure['records'] as $record) {
            if (!\hash_equals('file', (string)($record['kind'] ?? ''))) {
                continue;
            }
            $relative = (string)$record['path'];
            $path = \hash_equals('.', $relative)
                ? $target
                : $target . DIRECTORY_SEPARATOR . \str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative,
                );
            $temporary = $path . '.wls-rebootstrap-copy-'
                . \substr((string)$record['sha256'], 0, 16);
            if (\file_exists($temporary) || \is_link($temporary)) {
                GatewayProjectStateFilesystem::removeRegular(
                    $temporary,
                    'partial gateway derived working-copy file',
                );
            }
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapDerivedWorkingGenerationAbsent(
        array $journal,
    ): void {
        $workingRoot = $this->paths->rebootstrapBackupDir(
            (string)$journal['nonce'],
        ) . DIRECTORY_SEPARATOR . 'working-generation';
        $status = @\lstat($workingRoot);
        if (\is_array($status)
            || \file_exists($workingRoot)
            || \is_link($workingRoot)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap working-generation must be absent before rollback can advance.',
            );
        }
    }

    /**
     * Remove only the manifest-bound staging tree produced while publishing a
     * same-CA derived-state working copy. The complete child-first plan is
     * selected and no-follow validated before the first mutation; every
     * removal is identity-revalidated so an interrupted pass can be replayed
     * from the remaining subset without widening the deletion namespace.
     *
     * @param array<string,mixed> $journal
     */
    private function cleanupRebootstrapDerivedWorkingGeneration(
        array $journal,
    ): void {
        $workingRoot = $this->paths->rebootstrapBackupDir(
            (string)$journal['nonce'],
        ) . DIRECTORY_SEPARATOR . 'working-generation';
        $status = @\lstat($workingRoot);
        if (!\is_array($status)) {
            if (\file_exists($workingRoot) || \is_link($workingRoot)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap working-generation path is indeterminate.',
                );
            }
            return;
        }
        if ((string)$journal['old_derived_manifest_sha256'] === '') {
            throw new \RuntimeException(
                'Gateway rebootstrap found an unbound working-generation tree.',
            );
        }
        if ((bool)$journal['trust_rotation']) {
            throw new \RuntimeException(
                'Gateway trust-rotation rollback found an unowned working-generation tree.',
            );
        }
        $manifest = $this->readRebootstrapDerivedManifest($journal)['manifest'];
        $records = $this->collectRebootstrapDerivedWorkingGenerationRemoval(
            $workingRoot,
            $manifest,
        );
        if ($this->paths->isTestMode()
            && \hash_equals(
                'working-generation:enospc-before-removal',
                \trim((string)(
                    \getenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT') ?: ''
                )),
            )
        ) {
            throw new \RuntimeException(
                'Simulated ENOSPC before working-generation rollback cleanup.',
            );
        }

        $removed = 0;
        foreach ($records as $record) {
            if (($removed & 255) === 0) {
                $this->assertOperationDeadlineAvailable(
                    'removing gateway rebootstrap working-generation staging',
                );
            }
            $path = (string)$record['path'];
            $verified = GatewayBoundedTreeWalker::revalidate($record);
            if (($record['directory'] ?? false) === true) {
                if (!@\rmdir($path)) {
                    throw new \RuntimeException(
                        'Unable to remove a verified gateway rebootstrap working-generation directory: '
                            . $path,
                    );
                }
                $this->syncRebootstrapDirectory(\dirname($path));
            } else {
                GatewayProjectStateFilesystem::removeRegular(
                    $path,
                    'gateway rebootstrap working-generation file',
                    $verified,
                );
            }
            ++$removed;
            if ($removed === 1) {
                $this->injectRebootstrapCrash(
                    'working-generation:after-first-removal',
                );
            }
        }
        $this->assertRebootstrapDerivedWorkingGenerationAbsent($journal);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return list<array<string,mixed>>
     */
    private function collectRebootstrapDerivedWorkingGenerationRemoval(
        string $workingRoot,
        array $manifest,
    ): array {
        $rootRecord = GatewayBoundedTreeWalker::identity($workingRoot);
        if (($rootRecord['directory'] ?? false) !== true) {
            throw new \RuntimeException(
                'Gateway rebootstrap working-generation root is not a directory.',
            );
        }
        $manifestCategories = (array)$manifest['categories'];
        $maximumRecords = (int)$manifest['entry_count']
            + \count($manifestCategories) + 1;
        $records = [];
        $selected = [];
        $append = function (array $record) use (
            &$records,
            &$selected,
            $maximumRecords,
        ): void {
            $path = (string)($record['path'] ?? '');
            if ($path === ''
                || isset($selected[$path])
                || \count($records) >= $maximumRecords
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap working-generation removal plan exceeds its manifest boundary.',
                );
            }
            $selected[$path] = true;
            $records[] = $record;
        };
        $observedBytes = 0;
        $categories = $this->rebootstrapRawTopLevelEntries(
            $workingRoot,
            [],
            'Gateway rebootstrap rollback working-generation root',
        );
        GatewayBoundedTreeWalker::revalidate($rootRecord);
        foreach ($categories as $category => $categoryPath) {
            if (!\array_key_exists($category, $manifestCategories)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap found an unexpected working-generation category: '
                        . $category . '.',
                );
            }
            $categoryRecord = GatewayBoundedTreeWalker::identity($categoryPath);
            if (($categoryRecord['directory'] ?? false) !== true) {
                throw new \RuntimeException(
                    'Gateway rebootstrap working-generation category is not a directory: '
                        . $category . '.',
                );
            }
            $categoryManifest = (array)$manifestCategories[$category];
            $expected = \hash_equals(
                'restore',
                (string)$categoryManifest['policy'],
            ) ? (array)$categoryManifest['entries'] : [];
            $descriptors = $this
                ->rebootstrapDerivedWorkingTopLevelDescriptors(
                    $category,
                    $expected,
                );
            $observedManifestRecords = [];
            foreach ($this->rebootstrapRawTopLevelEntries(
                $categoryPath,
                [],
                'Gateway rebootstrap rollback working-generation category '
                    . $category,
            ) as $leaf => $path) {
                $descriptor = $descriptors[$leaf] ?? null;
                if (!\is_array($descriptor)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap found an unexpected working-generation leaf: '
                            . $category . '/' . $leaf . '.',
                    );
                }
                if (\hash_equals('directory', (string)$descriptor['kind'])) {
                    foreach ($this->collectRebootstrapDerivedWorkingClosure(
                        $path,
                        (array)$descriptor['closure'],
                        $category . '/' . (string)$descriptor['leaf'],
                        $observedBytes,
                    ) as $record) {
                        $append($record);
                    }
                    continue;
                }
                $record = GatewayBoundedTreeWalker::identity($path);
                $this->assertRebootstrapDerivedWorkingFileRecord(
                    $record,
                    $descriptor,
                    $category . '/' . $leaf,
                    $observedManifestRecords,
                    $observedBytes,
                );
                $append($record);
            }
            GatewayBoundedTreeWalker::revalidate($categoryRecord);
            $append($categoryRecord);
        }
        GatewayBoundedTreeWalker::revalidate($rootRecord);
        $append($rootRecord);
        return $records;
    }

    /**
     * @param array<string,array<string,mixed>> $expected
     * @return array<string,array<string,mixed>>
     */
    private function rebootstrapDerivedWorkingTopLevelDescriptors(
        string $category,
        array $expected,
    ): array {
        $descriptors = [];
        $register = static function (
            string $path,
            array $descriptor,
        ) use (&$descriptors, $category): void {
            if (isset($descriptors[$path])) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived manifest has an ambiguous working-generation name: '
                        . $category . '/' . $path . '.',
                );
            }
            $descriptors[$path] = $descriptor;
        };
        foreach ($expected as $leaf => $closure) {
            $root = (array)($closure['records'][0] ?? []);
            $kind = (string)$closure['kind'];
            $register($leaf, [
                'leaf' => $leaf,
                'record_id' => $leaf,
                'variant' => 'exact',
                'kind' => $kind,
                'record' => $root,
                'closure' => $closure,
            ]);
            if (\hash_equals('file', $kind)) {
                $temporary = $leaf . '.wls-rebootstrap-copy-'
                    . \substr((string)$root['sha256'], 0, 16);
                $register($temporary, [
                    'leaf' => $leaf,
                    'record_id' => $leaf,
                    'variant' => 'temporary',
                    'kind' => 'file',
                    'record' => $root,
                    'closure' => $closure,
                ]);
            }
        }
        return $descriptors;
    }

    /**
     * @param array<string,mixed> $closure
     * @return list<array<string,mixed>>
     */
    private function collectRebootstrapDerivedWorkingClosure(
        string $root,
        array $closure,
        string $label,
        int &$observedBytes,
    ): array {
        $descriptors = [];
        $register = static function (
            string $path,
            array $descriptor,
        ) use (&$descriptors, $label): void {
            if (isset($descriptors[$path])) {
                throw new \RuntimeException(
                    'Gateway rebootstrap derived manifest has an ambiguous working-generation path: '
                        . $label . '/' . $path . '.',
                );
            }
            $descriptors[$path] = $descriptor;
        };
        foreach ((array)$closure['records'] as $manifestRecord) {
            $relative = (string)$manifestRecord['path'];
            $kind = (string)$manifestRecord['kind'];
            $register($relative, [
                'record_id' => $relative,
                'variant' => 'exact',
                'kind' => $kind,
                'record' => $manifestRecord,
            ]);
            if (\hash_equals('file', $kind)) {
                $register(
                    $relative . '.wls-rebootstrap-copy-'
                        . \substr((string)$manifestRecord['sha256'], 0, 16),
                    [
                        'record_id' => $relative,
                        'variant' => 'temporary',
                        'kind' => 'file',
                        'record' => $manifestRecord,
                    ],
                );
            }
        }
        $records = GatewayBoundedTreeWalker::collect(
            $root,
            true,
            true,
            self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES,
            GatewayBoundedTreeWalker::MAX_DEPTH,
            fn (): null => $this->deadlineProgress(
                'selecting gateway rebootstrap working-generation closure '
                    . $label,
            ),
        );
        $observedManifestRecords = [];
        foreach ($records as $record) {
            $path = (string)$record['path'];
            $relative = \hash_equals($root, $path)
                ? '.'
                : \str_replace('\\', '/', \substr(
                    $path,
                    \strlen($root) + 1,
                ));
            $descriptor = $descriptors[$relative] ?? null;
            if (!\is_array($descriptor)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap found an unexpected working-generation path: '
                        . $label . '/' . $relative . '.',
                );
            }
            if (\hash_equals('directory', (string)$descriptor['kind'])) {
                if (($record['directory'] ?? false) !== true) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap working-generation directory kind changed: '
                            . $label . '/' . $relative . '.',
                    );
                }
                GatewayBoundedTreeWalker::revalidate($record);
                continue;
            }
            $this->assertRebootstrapDerivedWorkingFileRecord(
                $record,
                $descriptor,
                $label . '/' . $relative,
                $observedManifestRecords,
                $observedBytes,
            );
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $descriptor
     * @param array<string,string> $observedManifestRecords
     */
    private function assertRebootstrapDerivedWorkingFileRecord(
        array $record,
        array $descriptor,
        string $label,
        array &$observedManifestRecords,
        int &$observedBytes,
    ): void {
        if (($record['directory'] ?? true) === true) {
            throw new \RuntimeException(
                'Gateway rebootstrap working-generation file became a directory: '
                    . $label . '.',
            );
        }
        $recordId = (string)$descriptor['record_id'];
        $variant = (string)$descriptor['variant'];
        if (isset($observedManifestRecords[$recordId])) {
            throw new \RuntimeException(
                'Gateway rebootstrap working-generation contains both exact and temporary forms: '
                    . $label . '.',
            );
        }
        $verified = GatewayBoundedTreeWalker::revalidate($record);
        $size = (int)($verified['size'] ?? -1);
        $expectedSize = (int)((array)$descriptor['record'])['size'];
        if ($size < 0
            || (\hash_equals('exact', $variant)
                ? $size !== $expectedSize
                : $size > $expectedSize)
            || $observedBytes > self::REBOOTSTRAP_DERIVED_TOTAL_MAX_BYTES
                - $size
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap working-generation file exceeds its manifest boundary: '
                    . $label . '.',
            );
        }
        $observedManifestRecords[$recordId] = $variant;
        $observedBytes += $size;
    }

    /** @param array<string,mixed> $closure */
    private function copyRebootstrapDerivedClosure(
        string $source,
        string $destination,
        array $closure,
        string $label,
        string $authorityProfile,
    ): void {
        $records = (array)$closure['records'];
        $directoryMetadata = [];
        foreach ($records as $record) {
            $relative = (string)$record['path'];
            $sourcePath = \hash_equals('.', $relative)
                ? $source
                : $source . DIRECTORY_SEPARATOR . \str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative,
                );
            $targetPath = \hash_equals('.', $relative)
                ? $destination
                : $destination . DIRECTORY_SEPARATOR . \str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative,
                );
            if (\hash_equals('directory', (string)$record['kind'])) {
                if (!\file_exists($targetPath) && !\is_link($targetPath)) {
                    if (!@\mkdir($targetPath, 0700)) {
                        throw new \RuntimeException(
                            'Unable to create ' . $label . ' directory.',
                        );
                    }
                }
                $identity = GatewayBoundedTreeWalker::identity($targetPath);
                if (($identity['directory'] ?? false) !== true) {
                    throw new \RuntimeException(
                        $label . ' staging directory is unsafe.',
                    );
                }
                $directoryMetadata[] = [$targetPath, $record];
                continue;
            }
            $this->copyRebootstrapDerivedRegularFile(
                $sourcePath,
                $targetPath,
                $record,
                $label . ' file ' . $relative,
            );
        }
        foreach (\array_reverse($directoryMetadata) as [$path, $record]) {
            $this->applyRebootstrapDerivedCopiedMetadata($path, $record);
            $this->syncRebootstrapDirectory($path);
        }
        $this->assertRebootstrapDerivedClosureAt(
            $destination,
            $closure,
            $label,
            $authorityProfile,
            \PHP_OS_FAMILY === 'Windows'
                ? self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_CONTENT_ONLY
                : self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL,
        );
    }

    /** @param array<string,mixed> $record */
    private function copyRebootstrapDerivedRegularFile(
        string $source,
        string $target,
        array $record,
        string $label,
    ): void {
        if (\file_exists($target) || \is_link($target)) {
            $this->assertRebootstrapDerivedCopiedFile($target, $record, $label);
            return;
        }
        $temporary = $target . '.wls-rebootstrap-copy-'
            . \substr((string)$record['sha256'], 0, 16);
        if (\file_exists($temporary) || \is_link($temporary)) {
            GatewayProjectStateFilesystem::removeRegular(
                $temporary,
                'partial ' . $label,
            );
        }
        $sourceHandle = @\fopen($source, 'rb');
        $targetHandle = @\fopen($temporary, 'xb');
        if (!\is_resource($sourceHandle) || !\is_resource($targetHandle)) {
            \is_resource($sourceHandle) && @\fclose($sourceHandle);
            \is_resource($targetHandle) && @\fclose($targetHandle);
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        $failure = null;
        try {
            $before = @\fstat($sourceHandle);
            if (!\is_array($before)
                || !$this->isRegularFileStatus($before)
                || (int)($before['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException($label . ' source is unsafe.');
            }
            $hash = \hash_init('sha256');
            $size = 0;
            while (!\feof($sourceHandle)) {
                $this->assertOperationDeadlineAvailable('copying ' . $label);
                $chunk = @\fread($sourceHandle, 1_048_576);
                if (!\is_string($chunk)
                    || ($chunk === '' && !\feof($sourceHandle))
                ) {
                    throw new \RuntimeException('Unable to read ' . $label . '.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > self::MAX_PACKAGE_BYTES) {
                    throw new \RuntimeException($label . ' exceeds its file limit.');
                }
                \hash_update($hash, $chunk);
                $this->writeAll($targetHandle, $chunk);
            }
            $after = @\fstat($sourceHandle);
            if (!\is_array($after)
                || !$this->sameFileState($before, $after)
                || $size !== (int)$record['size']
                || !\hash_equals(
                    (string)$record['sha256'],
                    \hash_final($hash),
                )
            ) {
                throw new \RuntimeException($label . ' source changed while copying.');
            }
            $this->applyRebootstrapDerivedCopiedMetadata(
                $temporary,
                $record,
                $targetHandle,
            );
            if (!@\fflush($targetHandle)
                || (\function_exists('fsync') && !@\fsync($targetHandle))
            ) {
                throw new \RuntimeException('Unable to durably copy ' . $label . '.');
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($targetHandle);
            @\fclose($sourceHandle);
        }
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        GatewayProjectStateFilesystem::moveNoReplace(
            $temporary,
            $target,
            'Gateway derived working-copy file publication',
        );
        $this->assertRebootstrapDerivedCopiedFile($target, $record, $label);
    }

    /** @param array<string,mixed> $record @param resource|null $handle */
    private function applyRebootstrapDerivedCopiedMetadata(
        string $path,
        array $record,
        $handle = null,
    ): void {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $ownerOk = \is_resource($handle) && \function_exists('fchown')
            ? @\fchown($handle, (int)$record['uid'])
            : @\chown($path, (int)$record['uid']);
        $groupOk = \is_resource($handle) && \function_exists('fchgrp')
            ? @\fchgrp($handle, (int)$record['gid'])
            : @\chgrp($path, (int)$record['gid']);
        $modeOk = \is_resource($handle) && \function_exists('fchmod')
            ? @\fchmod($handle, (int)$record['mode'])
            : @\chmod($path, (int)$record['mode']);
        if (!$ownerOk || !$groupOk || !$modeOk) {
            throw new \RuntimeException(
                'Unable to preserve gateway derived working-copy metadata.',
            );
        }
    }

    /** @param array<string,mixed> $record */
    private function assertRebootstrapDerivedCopiedFile(
        string $path,
        array $record,
        string $label,
    ): void {
        $status = @\lstat($path);
        $digest = $this->digestStableRegularFile(
            $path,
            self::MAX_PACKAGE_BYTES,
            $label,
        );
        if (!\is_array($status)
            || !$this->isRegularFileStatus($status)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)$digest['size'] !== (int)$record['size']
            || !\hash_equals(
                (string)$record['sha256'],
                (string)$digest['sha256'],
            )
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== (int)$record['mode']
                    || (int)$status['uid'] !== (int)$record['uid']
                    || (int)$status['gid'] !== (int)$record['gid']))
        ) {
            throw new \RuntimeException($label . ' after-image is invalid.');
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapDerivedWorkingCopy(
        array $journal,
        string $label,
    ): void {
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        $this->assertRetainedRebootstrapDerivedBackup(
            $journal,
            $this->paths->rebootstrapBackupDir((string)$journal['nonce']),
        );
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $expected = \hash_equals('restore', (string)$definition['policy'])
                ? (array)$manifest['categories'][$category]['entries']
                : [];
            $this->assertRebootstrapDerivedRootAt(
                (array)$manifest['categories'][$category]['root'],
                $definition,
                $label . ' root ' . $category,
            );
            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                $label . ' ' . $category,
            );
            if (\array_keys($live) !== \array_keys($expected)) {
                throw new \RuntimeException(
                    $label . ' inventory differs for ' . $category . '.',
                );
            }
            foreach ($expected as $leaf => $closure) {
                $this->assertRebootstrapDerivedClosureAt(
                    $live[$leaf],
                    $closure,
                    $label . ' ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $journal
     * @param list<string> $allowedKeys
     */
    private function assertRebootstrapDerivedLiveNamespaceEmpty(
        array $journal,
        string $label,
        array $allowedKeys = [],
    ): void {
        $this->assertRetainedRebootstrapDerivedBackup(
            $journal,
            $this->paths->rebootstrapBackupDir((string)$journal['nonce']),
        );
        $allowed = \array_fill_keys($allowedKeys, true);
        $manifest = $this->readRebootstrapDerivedManifest($journal)['manifest'];
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $this->assertRebootstrapDerivedRootAt(
                (array)$manifest['categories'][$category]['root'],
                $definition,
                $label . ' root ' . $category,
            );
            foreach ($this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                $label . ' ' . $category,
            ) as $leaf => $_path) {
                $key = $category . '/' . $leaf;
                if (!isset($allowed[$key])) {
                    throw new \RuntimeException(
                        $label . ' contains unexpected live derived state: '
                            . $key . '.',
                    );
                }
                unset($allowed[$key]);
            }
        }
        if ($allowed !== []) {
            throw new \RuntimeException(
                $label . ' is missing an expected new-generation entry.',
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertRetainedRebootstrapDerivedBackup(
        array $journal,
        string $backup,
        string $windowsAuthority = self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED,
        bool $afterRollback = false,
    ): void {
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        $derivedRoot = $backup . DIRECTORY_SEPARATOR . 'derived';
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $stored = $this->rebootstrapRawTopLevelEntries(
                $derivedRoot . DIRECTORY_SEPARATOR . $category,
                [],
                'Retained gateway rebootstrap derived backup ' . $category,
            );
            $expected = $afterRollback
                && \hash_equals('restore', (string)$definition['policy'])
                    ? []
                    : (array)$manifest['categories'][$category]['entries'];
            if (\array_keys($stored) !== \array_keys($expected)) {
                throw new \RuntimeException(
                    'Retained gateway rebootstrap derived backup inventory changed: '
                        . $category . '.',
                );
            }
            foreach ($expected as $leaf => $closure) {
                $this->assertRebootstrapDerivedClosureAt(
                    $stored[$leaf],
                    $closure,
                    'retained old gateway derived state '
                        . $category . '/' . $leaf,
                    $definition['authority_profile'],
                    $windowsAuthority,
                );
            }
        }
    }

    /** @param array<string,mixed> $journal */
    private function restoreRebootstrapDerivedState(array $journal): void
    {
        if ((string)$journal['old_derived_manifest_sha256'] === '') {
            return;
        }
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        $backup = $this->paths->rebootstrapBackupDir((string)$journal['nonce']);
        $derivedRoot = $this->paths->rebootstrapDerivedBackupDir(
            (string)$journal['nonce'],
        );
        $quarantineRoot = $this->paths->rebootstrapNewDerivedQuarantineDir(
            (string)$journal['nonce'],
        );
        $this->ensurePrivateRebootstrapDirectory($quarantineRoot);
        $quarantineEntries = 0;
        $quarantineBytes = 0;
        $knownCategories = \array_fill_keys(
            \array_keys($this->rebootstrapDerivedNamespaces()),
            true,
        );
        foreach ($this->rebootstrapRawTopLevelEntries(
            $quarantineRoot,
            [],
            'Gateway rebootstrap existing new-derived quarantine',
        ) as $category => $categoryPath) {
            if (!isset($knownCategories[$category])
                || !\is_dir($categoryPath)
                || \is_link($categoryPath)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap quarantine contains an invalid category.',
                );
            }
            foreach ($this->rebootstrapRawTopLevelEntries(
                $categoryPath,
                [],
                'Gateway rebootstrap existing quarantine ' . $category,
            ) as $leaf => $path) {
                $this->captureRebootstrapDerivedClosure(
                    $path,
                    'existing new gateway derived state '
                        . $category . '/' . $leaf,
                    $quarantineEntries,
                    $quarantineBytes,
                );
            }
        }

        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $storedRoot = $derivedRoot . DIRECTORY_SEPARATOR . $category;
            $quarantineCategory = $quarantineRoot . DIRECTORY_SEPARATOR . $category;
            $this->ensurePrivateRebootstrapDirectory($quarantineCategory);
            $rootProof = (array)$manifest['categories'][$category]['root'];
            $this->reconcileRebootstrapDerivedRootForRollback(
                $category,
                $definition,
                $rootProof,
                $quarantineCategory,
                $quarantineEntries,
                $quarantineBytes,
            );
            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap rollback live ' . $category,
            );
            $stored = $this->rebootstrapRawTopLevelEntries(
                $storedRoot,
                [],
                'Gateway rebootstrap rollback stored ' . $category,
            );
            $expected = (array)$manifest['categories'][$category]['entries'];
            $restoreOldEntries = \hash_equals(
                'restore',
                (string)$definition['policy'],
            );

            foreach ($live as $leaf => $path) {
                $oldRemainedLive = $restoreOldEntries
                    && \array_key_exists($leaf, $expected)
                    && !isset($stored[$leaf]);
                if ($oldRemainedLive) {
                    $this->restoreRebootstrapDerivedWindowsAuthorityAt(
                        $path,
                        $expected[$leaf],
                        'old gateway derived state retained live '
                            . $category . '/' . $leaf,
                        $definition['authority_profile'],
                        'derived-rollback:after-first-acl',
                    );
                    continue;
                }
                $destination = $quarantineCategory . DIRECTORY_SEPARATOR . $leaf;
                if (\file_exists($destination) || \is_link($destination)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap rollback quarantine already owns '
                            . $category . '/' . $leaf . '.',
                    );
                }
                $this->captureRebootstrapDerivedClosure(
                    $path,
                    'new gateway derived state ' . $category . '/' . $leaf,
                    $quarantineEntries,
                    $quarantineBytes,
                );
                GatewayProjectStateFilesystem::moveNoReplace(
                    $path,
                    $destination,
                    'Gateway new derived-state quarantine '
                        . $category . '/' . $leaf,
                );
            }

            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap rollback post-quarantine ' . $category,
            );
            $stored = $this->rebootstrapRawTopLevelEntries(
                $storedRoot,
                [],
                'Gateway rebootstrap rollback stored ' . $category,
            );
            foreach ($restoreOldEntries ? $expected : [] as $leaf => $closure) {
                if (isset($stored[$leaf])) {
                    if (isset($live[$leaf])) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap rollback old derived entry exists twice.',
                        );
                    }
                    $this->assertRebootstrapDerivedClosureAt(
                        $stored[$leaf],
                        $closure,
                        'stored old gateway derived state '
                            . $category . '/' . $leaf,
                        $definition['authority_profile'],
                        self::REBOOTSTRAP_DERIVED_WINDOWS_ACL_ORIGINAL_OR_SEALED,
                    );
                    GatewayProjectStateFilesystem::moveNoReplace(
                        $stored[$leaf],
                        $definition['root'] . DIRECTORY_SEPARATOR . $leaf,
                        'Gateway old derived-state restore '
                            . $category . '/' . $leaf,
                    );
                    $this->injectRebootstrapCrash(
                        'derived-rollback:after-move-before-acl',
                    );
                    $this->restoreRebootstrapDerivedWindowsAuthorityAt(
                        $definition['root'] . DIRECTORY_SEPARATOR . $leaf,
                        $closure,
                        'restored old gateway derived state '
                            . $category . '/' . $leaf,
                        $definition['authority_profile'],
                        'derived-rollback:after-first-acl',
                    );
                }
            }

            $restored = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                'Gateway rebootstrap restored ' . $category,
            );
            $restoredExpected = $restoreOldEntries ? $expected : [];
            if (\array_keys($restored) !== \array_keys($restoredExpected)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap restored derived inventory differs: '
                        . $category . '.',
                );
            }
            foreach ($restoredExpected as $leaf => $closure) {
                $this->assertRebootstrapDerivedClosureAt(
                    $restored[$leaf],
                    $closure,
                    'restored old gateway derived state '
                        . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
            }
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                'restored gateway derived root ' . $category,
                requireOriginalIdentity: $definition['preserved'] !== [],
            );
        }
    }

    /**
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     * @param array<string,mixed> $rootProof
     */
    private function reconcileRebootstrapDerivedRootForRollback(
        string $category,
        array $definition,
        array $rootProof,
        string $quarantineCategory,
        int &$quarantineEntries,
        int &$quarantineBytes,
    ): void {
        $label = 'Gateway rebootstrap rollback root ' . $category;
        $this->assertRebootstrapDerivedRootProofContract(
            $rootProof,
            $definition,
            $label,
        );
        $this->assertRebootstrapDerivedParentAt(
            $rootProof,
            $definition,
            $label,
        );
        $root = $definition['root'];
        $rootAfterImage = $quarantineCategory . DIRECTORY_SEPARATOR
            . '.wls-root-after-image';
        $afterImageExists = \file_exists($rootAfterImage)
            || \is_link($rootAfterImage);

        if ($definition['preserved'] !== []) {
            if ($afterImageExists) {
                throw new \RuntimeException(
                    $label . ' cannot quarantine a preserved namespace root.',
                );
            }
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                $label,
            );
            return;
        }

        $rootStatus = @\lstat($root);
        $rootExists = \is_array($rootStatus);
        if (!$rootExists && (\file_exists($root) || \is_link($root))) {
            throw new \RuntimeException($label . ' is indeterminate.');
        }
        $matchesExpected = false;
        if ($rootExists) {
            try {
                $this->assertRebootstrapDerivedRootAt(
                    $rootProof,
                    $definition,
                    $label,
                    requireOriginalIdentity: !$afterImageExists,
                );
                $matchesExpected = true;
            } catch (\RuntimeException) {
                $matchesExpected = false;
            }
        }

        if ($rootExists && !$matchesExpected) {
            if ($afterImageExists) {
                throw new \RuntimeException(
                    $label . ' changed again after its first quarantine.',
                );
            }
            $this->captureRebootstrapDerivedClosure(
                $root,
                $label . ' new-generation after-image',
                $quarantineEntries,
                $quarantineBytes,
            );
            GatewayProjectStateFilesystem::moveNoReplace(
                $root,
                $rootAfterImage,
                $label . ' quarantine',
            );
            $this->syncRebootstrapDirectory(\dirname($root));
            $afterImageExists = true;
            $rootExists = false;
        }

        if (($rootProof['present'] ?? false) === true) {
            if (!$rootExists) {
                $this->createAndSealRebootstrapDerivedRoot(
                    $definition,
                    $rootProof,
                    $label,
                );
            }
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                $label,
                requireOriginalIdentity: !$afterImageExists,
            );
            return;
        }
        if ($rootExists || \file_exists($root) || \is_link($root)) {
            throw new \RuntimeException(
                $label . ' must remain absent after quarantine.',
            );
        }
    }

    /**
     * @param array{root:string,root_id:string,preserved:list<string>,policy:string,authority_profile:string} $definition
     * @param array<string,mixed> $rootProof
     */
    private function createAndSealRebootstrapDerivedRoot(
        array $definition,
        array $rootProof,
        string $label,
    ): void {
        $root = $definition['root'];
        $parent = \dirname($root);
        $this->assertRebootstrapDerivedParentAt(
            $rootProof,
            $definition,
            $label . ' recreation preflight',
        );
        if (!@\mkdir($root, 0700)) {
            throw new \RuntimeException($label . ' cannot be recreated safely.');
        }
        $this->syncRebootstrapDirectory($parent);
        if (\PHP_OS_FAMILY !== 'Windows') {
            if (!@\chown($root, (int)$rootProof['uid'])
                || !@\chgrp($root, (int)$rootProof['gid'])
                || !@\chmod($root, (int)$rootProof['mode'])
            ) {
                throw new \RuntimeException(
                    $label . ' authority could not be restored.',
                );
            }
        } else {
            $currentIdentity = GatewayBoundedTreeWalker::identity($root);
            ($this->platform
                ?? new GatewayPlatformServiceInstaller($this->paths))
                ->restoreRebootstrapDerivedRootAuthority(
                    $root,
                    (string)$rootProof['windows_sddl_b64'],
                    (string)$rootProof['authority_sha256'],
                    $currentIdentity,
                    $definition['authority_profile'],
                );
        }
        $this->syncRebootstrapDirectory($root);
        $this->syncRebootstrapDirectory($parent);
    }

    /** @param array<string,mixed> $journal */
    private function stashOldRebootstrapGeneration(
        array $journal,
        string $backup,
    ): void {
        $anyBackup = false;
        foreach (['A', 'B'] as $slot) {
            if (\file_exists($backup . DIRECTORY_SEPARATOR . 'slots'
                    . DIRECTORY_SEPARATOR . $slot)
                || \is_link($backup . DIRECTORY_SEPARATOR . 'slots'
                    . DIRECTORY_SEPARATOR . $slot)
            ) {
                $anyBackup = true;
            }
        }
        foreach (['launcher', 'stable-launcher.sha256', 'active-slot', 'previous-slot'] as $leaf) {
            $path = $leaf === 'launcher'
                ? $backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $leaf
                : $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR . $leaf;
            if (\file_exists($path) || \is_link($path)) {
                $anyBackup = true;
            }
        }
        if (!$anyBackup) {
            $current = $this->verifiedRebootstrapOldGeneration();
            $this->assertOldGenerationMatchesRebootstrapJournal($journal, $current);
            $runtimePaths = [$this->paths->launcherFile()];
            foreach (['A', 'B'] as $slot) {
                if ($journal['old_slots'][$slot] !== null) {
                    $runtimePaths[] = $this->paths->slotDir($slot);
                }
            }
            $this->assertNoLiveProcessesForRuntimePaths(
                $runtimePaths,
                'gateway rebootstrap old-generation stash',
            );
        }

        foreach (['A', 'B'] as $slot) {
            $source = $this->paths->slotDir($slot);
            $destination = $backup . DIRECTORY_SEPARATOR . 'slots'
                . DIRECTORY_SEPARATOR . $slot;
            $closure = $journal['old_slots'][$slot];
            if ($closure === null) {
                if (\file_exists($source) || \is_link($source)
                    || \file_exists($destination) || \is_link($destination)
                ) {
                    throw new \RuntimeException(
                        'Unexpected gateway slot exists outside the old-generation closure: '
                            . $slot
                    );
                }
                continue;
            }
            $this->reconcileRebootstrapDirectoryMove(
                $source,
                $destination,
                (string)$closure['runtime_generation'],
                'old gateway slot ' . $slot,
            );
        }
        $this->reconcileRebootstrapFileMove(
            $this->paths->launcherFile(),
            $backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'launcher',
            (string)$journal['old_launcher_sha256'],
            (int)$journal['old_launcher_size'],
            (int)$journal['old_launcher_mode'],
            'old stable gateway launcher',
        );
        $identityContents = (string)$journal['old_launcher_sha256'] . "\n";
        $this->reconcileRebootstrapFileMove(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            \hash('sha256', $identityContents),
            \strlen($identityContents),
            0600,
            'old stable launcher trust identity',
        );
        $activeContents = (string)$journal['old_active_slot'] . "\n";
        $this->reconcileRebootstrapFileMove(
            $this->paths->activeSlotFile(),
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'active-slot',
            \hash('sha256', $activeContents),
            \strlen($activeContents),
            0640,
            'old active-slot pointer',
        );
        $previous = (string)$journal['old_previous_slot'];
        $previousBackup = $backup . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'previous-slot';
        if ($previous === '') {
            if (\file_exists($this->paths->previousSlotFile())
                || \is_link($this->paths->previousSlotFile())
                || \file_exists($previousBackup)
                || \is_link($previousBackup)
            ) {
                throw new \RuntimeException(
                    'Unexpected previous-slot pointer exists during gateway rebootstrap.'
                );
            }
        } else {
            $previousContents = $previous . "\n";
            $this->reconcileRebootstrapFileMove(
                $this->paths->previousSlotFile(),
                $previousBackup,
                \hash('sha256', $previousContents),
                \strlen($previousContents),
                0640,
                'old previous-slot pointer',
            );
        }
        $this->stashRebootstrapDerivedState($journal, $backup);
    }

    /** @param array<string,mixed> $journal */
    private function publishPreparedRebootstrapGeneration(
        array $journal,
        string $backup,
    ): void {
        $candidate = $this->paths->rebootstrapCandidateDir(
            (string)$journal['nonce'],
        );
        $target = $this->paths->slotDir('A');
        $this->reconcileRebootstrapDirectoryMove(
            $candidate,
            $target,
            (string)$journal['runtime_generation'],
            'prepared gateway rebootstrap candidate',
        );
        $launcherSource = $target . DIRECTORY_SEPARATOR . \str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->componentPath('wls-gateway-launcher'),
        );
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        $identityContents = (string)$journal['candidate_launcher_sha256']
            . "\n";
        $existingIdentity = $this->readOptionalStableRegularFile(
            $identity,
            65,
            'New stable gateway launcher identity',
        );
        if ($existingIdentity === null) {
            $this->atomicWrite($identity, $identityContents, 0600);
        } elseif (!\hash_equals($identityContents, $existingIdentity)) {
            throw new \RuntimeException(
                'New stable launcher identity conflicts with the rebootstrap journal.'
            );
        }
        $this->installStableLauncher(
            $launcherSource,
            (string)$journal['candidate_launcher_sha256'],
        );
        $this->ensureTrustBundleBaselineLocked(
            (string)$journal['candidate_ca_bundle_sha256'],
        );
        if ((bool)$journal['trust_rotation']) {
            $this->assertRebootstrapDerivedLiveNamespaceEmpty(
                $journal,
                'new gateway trust generation publication',
                ['trust/ca-bundle.sha256'],
            );
        } else {
            $this->assertRebootstrapDerivedWorkingCopy(
                $journal,
                'new same-CA gateway generation publication',
            );
        }
        if (\file_exists($this->paths->previousSlotFile())
            || \is_link($this->paths->previousSlotFile())
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap cannot publish over an unexpected previous-slot pointer.'
            );
        }
        $this->atomicWrite($this->paths->activeSlotFile(), "A\n", 0640);
        if (\file_exists($this->paths->slotDir('B'))
            || \is_link($this->paths->slotDir('B'))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap published an unexpected mixed B slot.'
            );
        }
        // backup is intentionally retained; touching it here only proves that
        // the path did not disappear during the new-generation publication.
        if (!\is_dir($backup) || \is_link($backup)) {
            throw new \RuntimeException(
                'Gateway rebootstrap old-generation backup disappeared.'
            );
        }
    }

    /** @param array<string,mixed> $journal */
    private function assertPublishedRebootstrapGeneration(array $journal): void
    {
        $verification = $this->artifact->verify(
            $this->paths->slotDir('A'),
            'host_gateway',
        );
        if (($verification['ok'] ?? false) !== true
            || !\hash_equals(
                (string)$journal['runtime_generation'],
                (string)($verification['runtime_generation'] ?? ''),
            )
            || !\hash_equals('A', $this->paths->activeSlot())
            || \file_exists($this->paths->previousSlotFile())
            || \is_link($this->paths->previousSlotFile())
            || \file_exists($this->paths->slotDir('B'))
            || \is_link($this->paths->slotDir('B'))
            || \file_exists($this->paths->rebootstrapCandidateDir(
                (string)$journal['nonce'],
            ))
            || \is_link($this->paths->rebootstrapCandidateDir(
                (string)$journal['nonce'],
            ))
        ) {
            throw new \RuntimeException(
                'Published gateway rebootstrap runtime closure is invalid.'
            );
        }
        $proof = $this->verifiedStableLauncherUpgradeProof(
            ['A'],
            'Published gateway rebootstrap generation',
        );
        if (!\hash_equals(
                (string)$journal['candidate_launcher_sha256'],
                (string)$proof['launcher_sha256'],
            )
            || (int)$journal['candidate_launcher_size']
                !== (int)$proof['launcher_size']
            || !\hash_equals(
                (string)$journal['candidate_ca_bundle_sha256'],
                (string)$proof['ca_bundle_sha256'],
            )
        ) {
            throw new \RuntimeException(
                'Published gateway rebootstrap launcher closure is invalid.'
            );
        }
        if ((bool)$journal['trust_rotation']) {
            if (\in_array((string)$journal['phase'], [
                'NEW_GENERATION_PUBLISHED',
                'PLATFORM_REFRESHED',
            ], true)) {
                $this->assertRebootstrapDerivedLiveNamespaceEmpty(
                    $journal,
                    'published new gateway trust generation',
                    ['trust/ca-bundle.sha256'],
                );
            } else {
                $this->assertRetainedRebootstrapDerivedBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir(
                        (string)$journal['nonce'],
                    ),
                );
            }
        } elseif (\in_array((string)$journal['phase'], [
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
        ], true)) {
            $this->assertRebootstrapDerivedWorkingCopy(
                $journal,
                'published same-CA gateway generation',
            );
        }
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $current
     */
    private function assertOldGenerationMatchesRebootstrapJournal(
        array $journal,
        array $current,
    ): void {
        if (!\hash_equals(
                (string)$journal['old_active_slot'],
                (string)$current['active_slot'],
            )
            || !\hash_equals(
                (string)$journal['old_previous_slot'],
                (string)$current['previous_slot'],
            )
            || !\hash_equals(
                (string)$journal['old_launcher_sha256'],
                (string)$current['launcher_sha256'],
            )
            || (int)$journal['old_launcher_size']
                !== (int)$current['launcher_size']
            || (int)$journal['old_launcher_mode']
                !== (int)$current['launcher_mode']
            || !\hash_equals(
                (string)$journal['old_ca_bundle_sha256'],
                (string)$current['ca_bundle_sha256'],
            )
            || !\hash_equals(
                GatewayClient::canonicalJson((array)$journal['old_slots']),
                GatewayClient::canonicalJson((array)$current['slots']),
            )
        ) {
            throw new \RuntimeException(
                'Gateway old generation changed after rebootstrap preparation.'
            );
        }
    }

    private function reconcileRebootstrapDirectoryMove(
        string $source,
        string $destination,
        string $runtimeGeneration,
        string $label,
    ): void {
        $sourceExists = \file_exists($source) || \is_link($source);
        $destinationExists = \file_exists($destination) || \is_link($destination);
        if ($sourceExists && $destinationExists) {
            $sourceIdentity = GatewayBoundedTreeWalker::identity($source);
            $destinationIdentity = GatewayBoundedTreeWalker::identity(
                $destination,
            );
            if (\hash_equals(
                    (string)$sourceIdentity['device'],
                    (string)$destinationIdentity['device'],
                )
                && \hash_equals(
                    (string)$sourceIdentity['inode'],
                    (string)$destinationIdentity['inode'],
                )
            ) {
                throw new \RuntimeException(
                    $label . ' directory move replay exposed two aliases; the supported filesystem must not hard-link directories.',
                );
            }
            throw new \RuntimeException(
                $label . ' exists at two different transaction identities.',
            );
        }
        if (!$sourceExists && !$destinationExists) {
            throw new \RuntimeException(
                $label . ' is missing from both transaction locations.'
            );
        }
        $current = $sourceExists ? $source : $destination;
        $this->assertRebootstrapRuntimeDirectory(
            $current,
            $runtimeGeneration,
            $label,
        );
        if ($sourceExists) {
            GatewayProjectStateFilesystem::moveNoReplace(
                $source,
                $destination,
                'Gateway rebootstrap ' . $label,
            );
            $this->assertRebootstrapRuntimeDirectory(
                $destination,
                $runtimeGeneration,
                $label,
            );
        }
    }

    private function assertRebootstrapRuntimeDirectory(
        string $directory,
        string $runtimeGeneration,
        string $label,
    ): void {
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException($label . ' directory is unsafe.');
        }
        $verification = $this->artifact->verify($directory, 'host_gateway');
        if (($verification['ok'] ?? false) !== true
            || !\hash_equals(
                $runtimeGeneration,
                (string)($verification['runtime_generation'] ?? ''),
            )
        ) {
            throw new \RuntimeException($label . ' runtime identity is invalid.');
        }
    }

    private function reconcileRebootstrapFileMove(
        string $source,
        string $destination,
        string $sha256,
        int $size,
        int $mode,
        string $label,
    ): void {
        $sourceExists = \file_exists($source) || \is_link($source);
        $destinationExists = \file_exists($destination) || \is_link($destination);
        if ($sourceExists && $destinationExists) {
            GatewayProjectStateFilesystem::reconcileMovedRegularAlias(
                $source,
                $destination,
                'Gateway rebootstrap ' . $label,
            );
            $this->assertRebootstrapRegularFile(
                $destination,
                $sha256,
                $size,
                $mode,
                $label,
            );
            return;
        }
        if (!$sourceExists && !$destinationExists) {
            throw new \RuntimeException(
                $label . ' is missing from both transaction locations.'
            );
        }
        $current = $sourceExists ? $source : $destination;
        $this->assertRebootstrapRegularFile(
            $current,
            $sha256,
            $size,
            $mode,
            $label,
        );
        if ($sourceExists) {
            GatewayProjectStateFilesystem::moveNoReplace(
                $source,
                $destination,
                'Gateway rebootstrap ' . $label,
            );
            $this->assertRebootstrapRegularFile(
                $destination,
                $sha256,
                $size,
                $mode,
                $label,
            );
        }
    }

    private function assertRebootstrapRegularFile(
        string $path,
        string $sha256,
        int $size,
        int $mode,
        string $label,
    ): void {
        $digest = $this->digestStableRegularFile(
            $path,
            \max(1, $size),
            $label,
        );
        $status = @\lstat($path);
        if (!\is_array($status)
            || !\hash_equals($sha256, $digest['sha256'])
            || $size !== $digest['size']
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$status['mode']) & 0777) !== $mode))
        ) {
            throw new \RuntimeException($label . ' identity or mode is invalid.');
        }
    }

    private function ensurePrivateRebootstrapDirectory(string $directory): void
    {
        $root = $this->paths->rebootstrapDir();
        $parent = \dirname($directory);
        $resolvedParent = \realpath($parent);
        $resolvedRoot = \realpath($root);
        if (!\is_string($resolvedParent)
            || !\is_string($resolvedRoot)
            || !$this->pathIsWithin($resolvedParent, $resolvedRoot)
            || \is_link($parent)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap directory parent is outside the fixed host root.'
            );
        }
        if (!\file_exists($directory) && !\is_link($directory)) {
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException(
                    'Unable to create gateway rebootstrap directory: ' . $directory
                );
            }
            $this->syncRebootstrapDirectory($parent);
        }
        $status = @\lstat($directory);
        $parentStatus = @\lstat($parent);
        if (!\is_array($status)
            || !\is_array($parentStatus)
            || \is_link($directory)
            || !\is_dir($directory)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)$status['mode'] & 0777) !== 0700
                    || (int)$status['uid'] !== (int)$parentStatus['uid']))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap directory authority is unsafe: ' . $directory
            );
        }
    }

    private function syncRebootstrapDirectory(string $directory): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('fsync')) {
            return;
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to open gateway rebootstrap directory for durability sync.'
            );
        }
        try {
            if (!@\fsync($handle)) {
                throw new \RuntimeException(
                    'Unable to durably sync gateway rebootstrap directory.'
                );
            }
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string,mixed> $journal */
    private function restoreOldRebootstrapGeneration(array $journal): void
    {
        $this->assertRebootstrapDerivedRollbackRootPreflight($journal);
        $nonce = (string)$journal['nonce'];
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $candidate = $this->paths->rebootstrapCandidateDir($nonce);
        $newGeneration = $this->paths
            ->rebootstrapRollbackNewGenerationDir($nonce);
        $newGenerationSlots = $newGeneration . DIRECTORY_SEPARATOR . 'slots';
        $this->ensurePrivateRebootstrapDirectory($newGeneration);
        $this->ensurePrivateRebootstrapDirectory($newGenerationSlots);
        $runtimeProofPaths = [];
        foreach ([
            $candidate,
            $this->paths->slotDir('A'),
            $this->paths->slotDir('B'),
            $newGeneration . DIRECTORY_SEPARATOR . 'candidate',
            $newGenerationSlots . DIRECTORY_SEPARATOR . 'A',
            $newGenerationSlots . DIRECTORY_SEPARATOR . 'B',
        ] as $path) {
            if (\file_exists($path) || \is_link($path)) {
                $runtimeProofPaths[] = $path;
            }
        }
        foreach (['A', 'B'] as $slot) {
            $path = $backup . DIRECTORY_SEPARATOR . 'slots'
                . DIRECTORY_SEPARATOR . $slot;
            if (\file_exists($path) || \is_link($path)) {
                $runtimeProofPaths[] = $path;
            }
        }
        foreach ([
            $this->paths->launcherFile(),
            $backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'launcher',
        ] as $path) {
            if (\file_exists($path) || \is_link($path)) {
                $runtimeProofPaths[] = $path;
            }
        }
        if ($runtimeProofPaths !== []) {
            $this->assertNoLiveProcessesForRuntimePaths(
                \array_values(\array_unique($runtimeProofPaths)),
                'gateway rebootstrap whole-generation rollback',
            );
        }

        $this->isolateRebootstrapNewRuntimeDirectory(
            $candidate,
            $newGeneration . DIRECTORY_SEPARATOR . 'candidate',
            (string)$journal['runtime_generation'],
            'gateway rebootstrap rollback candidate',
        );

        foreach (['A', 'B'] as $slot) {
            $source = $this->paths->slotDir($slot);
            $stored = $backup . DIRECTORY_SEPARATOR . 'slots'
                . DIRECTORY_SEPARATOR . $slot;
            $old = $journal['old_slots'][$slot];
            if ($old === null) {
                if (\file_exists($stored) || \is_link($stored)) {
                    throw new \RuntimeException(
                        'Rollback backup contains an undeclared old gateway slot '
                            . $slot . '.'
                    );
                }
                $this->isolateRebootstrapNewRuntimeDirectory(
                    $source,
                    $newGenerationSlots . DIRECTORY_SEPARATOR . $slot,
                    (string)$journal['runtime_generation'],
                    'new gateway rollback slot ' . $slot,
                );
                continue;
            }
            if (\file_exists($stored) || \is_link($stored)) {
                $this->assertRebootstrapRuntimeDirectory(
                    $stored,
                    (string)$old['runtime_generation'],
                    'stored old gateway slot ' . $slot,
                );
                if (\file_exists($source) || \is_link($source)) {
                    $this->isolateRebootstrapNewRuntimeDirectory(
                        $source,
                        $newGenerationSlots . DIRECTORY_SEPARATOR . $slot,
                        (string)$journal['runtime_generation'],
                        'new gateway rollback slot ' . $slot,
                    );
                } elseif (\file_exists(
                    $newGenerationSlots . DIRECTORY_SEPARATOR . $slot,
                ) || \is_link(
                    $newGenerationSlots . DIRECTORY_SEPARATOR . $slot,
                )) {
                    $this->assertRebootstrapRuntimeDirectory(
                        $newGenerationSlots . DIRECTORY_SEPARATOR . $slot,
                        (string)$journal['runtime_generation'],
                        'isolated new gateway rollback slot ' . $slot,
                    );
                }
                GatewayProjectStateFilesystem::moveNoReplace(
                    $stored,
                    $source,
                    'Gateway old slot restore ' . $slot,
                );
            }
            $this->assertRebootstrapRuntimeDirectory(
                $source,
                (string)$old['runtime_generation'],
                'restored old gateway slot ' . $slot,
            );
        }

        $this->restoreRebootstrapFile(
            $this->paths->launcherFile(),
            $backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'launcher',
            (string)$journal['old_launcher_sha256'],
            (int)$journal['old_launcher_size'],
            (int)$journal['old_launcher_mode'],
            (string)$journal['candidate_launcher_sha256'],
            (int)$journal['candidate_launcher_size'],
            (int)$journal['candidate_launcher_mode'],
            'stable gateway launcher',
        );
        $oldIdentity = (string)$journal['old_launcher_sha256'] . "\n";
        $newIdentity = (string)$journal['candidate_launcher_sha256'] . "\n";
        $this->restoreRebootstrapFile(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            \hash('sha256', $oldIdentity),
            \strlen($oldIdentity),
            0600,
            \hash('sha256', $newIdentity),
            \strlen($newIdentity),
            0600,
            'stable launcher trust identity',
        );
        $oldActive = (string)$journal['old_active_slot'] . "\n";
        $this->restoreRebootstrapFile(
            $this->paths->activeSlotFile(),
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'active-slot',
            \hash('sha256', $oldActive),
            \strlen($oldActive),
            0640,
            \hash('sha256', "A\n"),
            2,
            0640,
            'active-slot pointer',
        );
        $oldPrevious = (string)$journal['old_previous_slot'];
        $previousBackup = $backup . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'previous-slot';
        if ($oldPrevious === '') {
            if (\file_exists($previousBackup) || \is_link($previousBackup)) {
                throw new \RuntimeException(
                    'Rollback backup contains an undeclared previous-slot pointer.'
                );
            }
            if (\file_exists($this->paths->previousSlotFile())
                || \is_link($this->paths->previousSlotFile())
            ) {
                throw new \RuntimeException(
                    'Rollback found an unexpected previous-slot pointer.'
                );
            }
        } else {
            $contents = $oldPrevious . "\n";
            $this->restoreRebootstrapFile(
                $this->paths->previousSlotFile(),
                $previousBackup,
                \hash('sha256', $contents),
                \strlen($contents),
                0640,
                '',
                0,
                0640,
                'previous-slot pointer',
            );
        }
        $this->cleanupRebootstrapDerivedWorkingGeneration($journal);
        $this->restoreRebootstrapDerivedState($journal);
        $current = $this->verifiedRebootstrapOldGeneration();
        $this->assertOldGenerationMatchesRebootstrapJournal($journal, $current);
    }

    /** @param array<string,mixed> $journal */
    private function assertRebootstrapDerivedRollbackRootPreflight(
        array $journal,
    ): void {
        if ((string)$journal['old_derived_manifest_sha256'] === '') {
            return;
        }
        $manifest = $this->readRebootstrapDerivedManifest($journal)['manifest'];
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $rootProof = (array)$manifest['categories'][$category]['root'];
            $label = 'Gateway rollback derived root preflight ' . $category;
            $this->assertRebootstrapDerivedRootProofContract(
                $rootProof,
                $definition,
                $label,
            );
            $parent = \dirname($definition['root']);
            $parentIdentity = GatewayBoundedTreeWalker::identity($parent);
            if (!\hash_equals(
                    (string)$rootProof['parent_device'],
                    (string)$parentIdentity['device'],
                )
                || !\hash_equals(
                    (string)$rootProof['parent_inode'],
                    (string)$parentIdentity['inode'],
                )
            ) {
                throw new \RuntimeException(
                    $label . ' parent identity changed.',
                );
            }
            if ($definition['preserved'] !== []) {
                $this->assertRebootstrapDerivedRootAt(
                    $rootProof,
                    $definition,
                    $label,
                );
                continue;
            }
            $status = @\lstat($definition['root']);
            if (\is_array($status) && \is_link($definition['root'])) {
                throw new \RuntimeException($label . ' is linked.');
            }
            if (!\is_array($status)
                && (\file_exists($definition['root'])
                    || \is_link($definition['root']))
            ) {
                throw new \RuntimeException($label . ' is indeterminate.');
            }
        }
    }

    /**
     * Revalidate the restored old-generation live after-image without using
     * the same-CA working-copy verifier. Restore-policy leaves have moved
     * from backup to live, ephemeral-policy leaves deliberately remain out
     * of live, and preserved transaction/administrator entries are outside
     * the derived manifest closure.
     *
     * @param array<string,mixed> $journal
     */
    private function assertRebootstrapRestoredOldDerivedLiveInventory(
        array $journal,
        string $label,
    ): void {
        if ((string)$journal['old_derived_manifest_sha256'] === '') {
            return;
        }
        $loaded = $this->readRebootstrapDerivedManifest($journal);
        $manifest = $loaded['manifest'];
        foreach ($this->rebootstrapDerivedNamespaces() as $category => $definition) {
            $categoryManifest = (array)$manifest['categories'][$category];
            $rootProof = (array)$categoryManifest['root'];
            $requireOriginalIdentity = $definition['preserved'] !== [];
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                $label . ' root ' . $category,
                requireOriginalIdentity: $requireOriginalIdentity,
            );
            $currentRootBefore = $this->captureRebootstrapDerivedRootProof(
                $definition,
                $label . ' current root ' . $category,
            );

            $live = $this->rebootstrapRawTopLevelEntries(
                $definition['root'],
                $definition['preserved'],
                $label . ' ' . $category,
            );
            $expected = \hash_equals(
                'restore',
                (string)$definition['policy'],
            ) ? (array)$categoryManifest['entries'] : [];
            if (\array_keys($live) !== \array_keys($expected)) {
                throw new \RuntimeException(
                    $label . ' inventory differs for ' . $category . '.',
                );
            }
            foreach ($expected as $leaf => $closure) {
                $this->assertRebootstrapDerivedClosureAt(
                    $live[$leaf],
                    $closure,
                    $label . ' ' . $category . '/' . $leaf,
                    $definition['authority_profile'],
                );
            }
            $currentRootAfter = $this->captureRebootstrapDerivedRootProof(
                $definition,
                $label . ' stable current root ' . $category,
            );
            if (!\hash_equals(
                GatewayClient::canonicalJson($currentRootBefore),
                GatewayClient::canonicalJson($currentRootAfter),
            )) {
                throw new \RuntimeException(
                    $label . ' current root changed while validating '
                        . $category . '.',
                );
            }
            $this->assertRebootstrapDerivedRootAt(
                $rootProof,
                $definition,
                $label . ' stable root ' . $category,
                requireOriginalIdentity: $requireOriginalIdentity,
            );
        }
    }

    private function isolateRebootstrapNewRuntimeDirectory(
        string $source,
        string $destination,
        string $runtimeGeneration,
        string $label,
    ): void {
        $sourceExists = \file_exists($source) || \is_link($source);
        $destinationExists = \file_exists($destination)
            || \is_link($destination);
        if (!$sourceExists && !$destinationExists) {
            return;
        }
        if ($sourceExists && $destinationExists) {
            throw new \RuntimeException(
                $label . ' exists at both live and rollback-quarantine paths.',
            );
        }
        if ($sourceExists) {
            $this->assertRebootstrapRuntimeDirectory(
                $source,
                $runtimeGeneration,
                $label,
            );
            $this->ensurePrivateRebootstrapDirectory(\dirname($destination));
            GatewayProjectStateFilesystem::moveNoReplace(
                $source,
                $destination,
                'Gateway rollback runtime quarantine: ' . $label,
            );
        }
        $this->assertRebootstrapRuntimeDirectory(
            $destination,
            $runtimeGeneration,
            'isolated ' . $label,
        );
    }

    private function restoreRebootstrapFile(
        string $target,
        string $stored,
        string $oldSha256,
        int $oldSize,
        int $oldMode,
        string $newSha256,
        int $newSize,
        int $newMode,
        string $label,
    ): void {
        if (\file_exists($stored) || \is_link($stored)) {
            $this->assertRebootstrapRegularFile(
                $stored,
                $oldSha256,
                $oldSize,
                $oldMode,
                'stored old ' . $label,
            );
            if (\file_exists($target) || \is_link($target)) {
                if ($newSha256 === '') {
                    throw new \RuntimeException(
                        'Unexpected new ' . $label . ' blocks whole-generation rollback.'
                    );
                }
                $this->assertRebootstrapRegularFile(
                    $target,
                    $newSha256,
                    $newSize,
                    $newMode,
                    'new ' . $label,
                );
                GatewayProjectStateFilesystem::removeRegular(
                    $target,
                    'new ' . $label . ' during gateway rebootstrap rollback',
                );
            }
            GatewayProjectStateFilesystem::moveNoReplace(
                $stored,
                $target,
                'Gateway old ' . $label . ' restore',
            );
        }
        $this->assertRebootstrapRegularFile(
            $target,
            $oldSha256,
            $oldSize,
            $oldMode,
            'restored old ' . $label,
        );
    }

    /**
     * Collect only authenticated terminal whole-generation backups. A
     * committed old generation is eligible exclusively under the signed boot
     * identity and monotonic deadline. A reboot, a future monotonic value, or
     * an unusable local monotonic context reanchors a complete 24-hour window.
     *
     * The package-install lock must be held by the caller.
     */
    private function collectExpiredRebootstrapBackupsLocked(): void
    {
        if ($this->readRebootstrapJournalLocked() !== null) {
            return;
        }
        foreach ($this->rebootstrapReceiptNoncesLocked(false) as $nonce) {
            $receipt = $this->readRebootstrapReceiptLocked($nonce);
            if ($receipt === null
                || !\hash_equals($nonce, (string)$receipt['nonce'])
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt namespace is inconsistent.'
                );
            }
            $phase = (string)$receipt['phase'];
            if (!\in_array($phase, ['COMMITTED', 'ROLLED_BACK'], true)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt is not terminal.'
                );
            }
            $backup = $this->paths->rebootstrapBackupDir($nonce);
            $candidate = $this->paths->rebootstrapCandidateDir($nonce);
            $backupExists = \file_exists($backup) || \is_link($backup);
            $candidateExists = \file_exists($candidate) || \is_link($candidate);
            if ($candidateExists) {
                throw new \RuntimeException(
                    'A terminal gateway rebootstrap retained an unexpected candidate runtime.'
                );
            }
            $retainedState = (string)$receipt['retained_backup_state'];
            if ((string)$receipt['backup_collection_nonce'] !== '') {
                $this->completeRebootstrapBackupCollectionLocked($receipt);
                continue;
            }
            if (\hash_equals('COLLECTED', $retainedState)) {
                if ($backupExists) {
                    throw new \RuntimeException(
                        'Collected gateway rebootstrap receipt has an unbound backup.',
                    );
                }
                continue;
            }
            if (!\hash_equals('RETAINED', $retainedState)
                || !$backupExists
            ) {
                throw new \RuntimeException(
                    'Retained gateway rebootstrap backup is missing or has invalid state.',
                );
            }
            $this->assertTerminalRebootstrapBackupClosure(
                $receipt,
                $backup,
            );
            if (\hash_equals('ROLLED_BACK', $phase)) {
                $this->assertNoLiveProcessesForRuntimePaths(
                    [$backup],
                    'rolled-back gateway rebootstrap collection',
                );
                $receipt = $this->beginRebootstrapBackupCollectionLocked(
                    $receipt,
                );
                $this->completeRebootstrapBackupCollectionLocked($receipt);
                continue;
            }

            $nowBoot = $this->hostBootIdentityNow();
            $nowMonotonic = $this->monotonicClockMillisecondsNow();
            $retained = (int)$receipt['retained_monotonic_ms'];
            $deadline = (int)$receipt['retention_deadline_monotonic_ms'];
            if (!\hash_equals(
                    $nowBoot,
                    (string)$receipt['retention_host_boot_id'],
                )
                || $retained < 1
                || $deadline <= $retained
                || $nowMonotonic < $retained
            ) {
                if ($nowMonotonic > PHP_INT_MAX
                    - self::REBOOTSTRAP_RETENTION_SECONDS * 1000
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap retention clock cannot be reanchored.'
                    );
                }
                $wall = \max(
                    (int)$receipt['created_at'],
                    (int)$receipt['updated_at'],
                    $this->wallClockNow(),
                );
                if ($wall > PHP_INT_MAX - self::REBOOTSTRAP_RETENTION_SECONDS) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap retention wall time cannot be reanchored.'
                    );
                }
                $receipt['retention_until'] = $wall
                    + self::REBOOTSTRAP_RETENTION_SECONDS;
                $receipt['retention_host_boot_id'] = $nowBoot;
                $receipt['retained_monotonic_ms'] = $nowMonotonic;
                $receipt['retention_deadline_monotonic_ms'] = $nowMonotonic
                    + self::REBOOTSTRAP_RETENTION_SECONDS * 1000;
                $this->writeRebootstrapReceiptLocked($receipt);
                continue;
            }
            if ($nowMonotonic < $deadline) {
                continue;
            }
            $this->assertNoLiveProcessesForRuntimePaths(
                [$backup],
                'expired gateway rebootstrap collection',
            );
            $receipt = $this->beginRebootstrapBackupCollectionLocked($receipt);
            $this->completeRebootstrapBackupCollectionLocked($receipt);
        }
        // Historical installations may legitimately enter with more than the
        // product receipt limit. Complete collection/binding replay first,
        // then GC authenticated history before enforcing that limit.
        $this->rebootstrapReceiptNoncesLocked();
    }

    /**
     * Reserve one worst-case whole-generation rollback closure before a new
     * transaction can enter PREPARING. Existing unexpired guarantees are
     * never evicted to make room.
     */
    private function assertRebootstrapRetentionBudgetAvailableLocked(): void
    {
        $retainedCount = 0;
        $retainedBytes = 0;
        $visited = 0;
        foreach ($this->rebootstrapReceiptNoncesLocked() as $nonce) {
            if (($visited++ & 63) === 0) {
                $this->assertOperationDeadlineAvailable(
                    'checking the gateway rebootstrap retention budget',
                );
            }
            $receipt = $this->readRebootstrapReceiptLocked($nonce);
            if ($receipt === null
                || !\hash_equals(
                    'RETAINED',
                    (string)$receipt['retained_backup_state'],
                )
            ) {
                continue;
            }
            if ((string)$receipt['backup_collection_nonce'] !== '') {
                throw new \RuntimeException(
                    'Gateway rebootstrap collection must finish before another rebootstrap can reserve rollback capacity.',
                );
            }
            $backup = $this->paths->rebootstrapBackupDir($nonce);
            $this->assertTerminalRebootstrapBackupClosure($receipt, $backup);
            $retainedCount++;
            $retainedBytes += $this->rebootstrapTreeBytes(
                $backup,
                $receipt,
                'retained gateway rebootstrap budget',
            );
            if ($retainedCount >= self::MAX_RETAINED_REBOOTSTRAP_GENERATIONS
                || $retainedBytes > self::REBOOTSTRAP_RETAINED_TOTAL_MAX_BYTES
                    - self::REBOOTSTRAP_GENERATION_RESERVE_BYTES
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap rollback retention budget is exhausted; wait for an authenticated LKG retention window to expire.',
                );
            }
        }
    }

    /** @param array<string,mixed> $receipt */
    private function rebootstrapTreeBytes(
        string $root,
        array $receipt,
        string $label,
    ): int {
        $bytes = 0;
        foreach ($this->collectRebootstrapBackupRemovalRecords(
            $root,
            $receipt,
            $label,
        ) as $record) {
            $status = GatewayBoundedTreeWalker::revalidate($record);
            if (($record['directory'] ?? false) === false) {
                $size = (int)($status['size'] ?? -1);
                if ($size < 0
                    || $bytes > self::REBOOTSTRAP_RETAINED_TOTAL_MAX_BYTES
                        - $size
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap retained byte budget overflowed.',
                    );
                }
                $bytes += $size;
            }
        }
        return $bytes;
    }

    /**
     * Capacity evidence is a member of the terminal receipt closure. The
     * signed COLLECTING state is published before the first evidence unlink;
     * the RELEASED digest alias then makes every crash boundary replayable.
     *
     * @param array<string,mixed> $receiptInventory
     * @return array<string,mixed>
     */
    private function collectTerminalRebootstrapCapacityEvidenceLocked(
        array $receiptInventory,
    ): array {
        foreach ($receiptInventory['receipts'] as $nonce => $binding) {
            if ($binding['companions'] === []) {
                continue;
            }
            if (!\is_array($binding['canonical'])) {
                throw new \RuntimeException(
                    'Gateway capacity-evidence GC cannot recover an unpaired terminal receipt.',
                );
            }
            $this->readRebootstrapReceiptLocked((string)$nonce);
        }
        $receiptInventory = $this->rebootstrapReceiptGcInventoryLocked();
        for ($pass = 0; $pass < 3; $pass++) {
            $capacityInventory = $this->rebootstrapCapacityGcInventoryLocked();
            if ($this->rebootstrapJournalGcFenceBlockedLocked()) {
                return $receiptInventory;
            }
            $plan = $this->rebootstrapCapacityGcPlan(
                $receiptInventory,
                $capacityInventory,
            );
            if ($plan === []) {
                return $receiptInventory;
            }
            $recheckedReceipts = $this->rebootstrapReceiptGcInventoryLocked();
            $recheckedCapacity = $this->rebootstrapCapacityGcInventoryLocked();
            $this->assertSameRebootstrapReceiptGcInventory(
                $receiptInventory,
                $recheckedReceipts,
            );
            $this->assertSameRebootstrapCapacityGcInventory(
                $capacityInventory,
                $recheckedCapacity,
            );
            $recheckedPlan = $this->rebootstrapCapacityGcPlan(
                $recheckedReceipts,
                $recheckedCapacity,
            );
            if (!\hash_equals(
                GatewayClient::canonicalJson($this->rebootstrapCapacityGcPlanProof($plan)),
                GatewayClient::canonicalJson(
                    $this->rebootstrapCapacityGcPlanProof($recheckedPlan),
                ),
            )) {
                throw new \RuntimeException(
                    'Gateway rebootstrap capacity-evidence GC plan changed before collection.',
                );
            }

            $beganCollection = false;
            foreach ($recheckedPlan as $entry) {
                if (!\hash_equals('begin', (string)$entry['action'])) {
                    continue;
                }
                $receipt = $entry['receipt'];
                $receipt['capacity_evidence_state'] = 'COLLECTING';
                $this->writeRebootstrapReceiptLocked($receipt);
                $this->injectRebootstrapCrash(
                    'capacity-evidence-gc:after-collecting-receipt',
                );
                $beganCollection = true;
            }
            if ($beganCollection) {
                $receiptInventory = $this->rebootstrapReceiptGcInventoryLocked();
                continue;
            }

            foreach ($recheckedPlan as $entry) {
                $receipt = $entry['receipt'];
                $nonce = (string)$receipt['nonce'];
                $capacity = $entry['capacity'];
                if (\hash_equals('replay', (string)$entry['action'])) {
                    $released = \is_array($capacity['released'])
                        ? $capacity['released']
                        : $capacity['released_alias'];
                    if (!\is_array($released)) {
                        throw new \RuntimeException(
                            'Gateway capacity-evidence GC lost its RELEASED crash marker.',
                        );
                    }
                    $alias = $this->paths->rebootstrapCapacityReleasedGcFile(
                        $nonce,
                        (string)$released['sha256'],
                    );
                    if (\hash_equals('released', (string)$released['kind'])) {
                        GatewayBoundedTreeWalker::revalidate(
                            $released['identity'],
                        );
                        GatewayProjectStateFilesystem::moveNoReplace(
                            (string)$released['path'],
                            $alias,
                            'Gateway RELEASED capacity-evidence GC fence',
                        );
                        $this->injectRebootstrapCrash(
                            'capacity-evidence-gc:after-released-move',
                        );
                    } elseif (!\hash_equals(
                        (string)$released['path'],
                        $alias,
                    )) {
                        throw new \RuntimeException(
                            'Gateway RELEASED capacity-evidence GC alias is not canonical.',
                        );
                    }
                    $aliasContents = $this->readHostRecoveryFile(
                        $alias,
                        self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
                        0600,
                        'Gateway RELEASED capacity-evidence GC alias',
                    );
                    $aliasDocument = $this->decodeRebootstrapCapacityEvidence(
                        $aliasContents,
                        'Gateway RELEASED capacity-evidence GC alias',
                    );
                    $this->assertRebootstrapCapacityGcEvidenceBound(
                        [
                            'kind' => 'released-alias',
                            'contents' => $aliasContents,
                            'sha256' => \hash('sha256', $aliasContents),
                            'document' => $aliasDocument,
                        ],
                        $receipt,
                    );
                    foreach (['held', 'releasing'] as $earlierKind) {
                        $earlier = $capacity[$earlierKind];
                        if (!\is_array($earlier)) {
                            continue;
                        }
                        GatewayBoundedTreeWalker::revalidate(
                            $earlier['identity'],
                        );
                        GatewayProjectStateFilesystem::removeRegular(
                            (string)$earlier['path'],
                            'authenticated gateway capacity '
                                . $earlierKind . ' evidence',
                            GatewayBoundedTreeWalker::revalidate(
                                $earlier['identity'],
                            ),
                        );
                        $this->injectRebootstrapCrash(
                            'capacity-evidence-gc:after-earlier-delete',
                        );
                    }
                    $aliasIdentity = GatewayBoundedTreeWalker::identity($alias);
                    GatewayProjectStateFilesystem::removeRegular(
                        $alias,
                        'authenticated gateway RELEASED capacity-evidence GC alias',
                        GatewayBoundedTreeWalker::revalidate($aliasIdentity),
                    );
                    $this->injectRebootstrapCrash(
                        'capacity-evidence-gc:after-released-delete',
                    );
                }
                $after = $this->rebootstrapCapacityGcInventoryLocked();
                if (isset($after['nonces'][$nonce])) {
                    throw new \RuntimeException(
                        'Gateway capacity evidence remained after authenticated collection.',
                    );
                }
                $receipt['capacity_evidence_state'] = 'COLLECTED';
                $this->writeRebootstrapReceiptLocked($receipt);
                $this->injectRebootstrapCrash(
                    'capacity-evidence-gc:after-collected-receipt',
                );
            }
            return $this->rebootstrapReceiptGcInventoryLocked();
        }
        throw new \RuntimeException(
            'Gateway capacity-evidence GC did not converge within its bounded state transitions.',
        );
    }

    /**
     * @return array{records:array<string,array<string,mixed>>,nonces:array<string,array<string,mixed>>}
     */
    private function rebootstrapCapacityGcInventoryLocked(): array
    {
        $directory = $this->paths->rebootstrapCapacityDir();
        $before = @\lstat($directory);
        if (!\is_array($before)
            || \is_link($directory)
            || !\is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity directory is unsafe.',
            );
        }
        $raw = $this->rebootstrapRawTopLevelEntries(
            $directory,
            [],
            'Gateway rebootstrap raw capacity namespace',
        );
        if (\count($raw) > self::REBOOTSTRAP_RECEIPT_RAW_MAX_ENTRIES) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity namespace exceeds its fixed raw entry quota.',
            );
        }
        $records = [];
        $nonces = [];
        foreach ($raw as $leaf => $path) {
            $nonce = '';
            $kind = '';
            $digestPrefix = '';
            if (\preg_match(
                '/\A([a-f0-9]{32})\.(allocating|held|releasing)\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'live-' . (string)$matches[2];
            } elseif (\preg_match(
                '/\A([a-f0-9]{32})\.(held|releasing|released)\.json\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = (string)$matches[2];
            } elseif (\preg_match(
                '/\A([a-f0-9]{32})\.released\.json\.gc-([a-f0-9]{32})\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'released-alias';
                $digestPrefix = (string)$matches[2];
            } else {
                throw new \RuntimeException(
                    'Gateway rebootstrap capacity namespace contains a malformed or noncanonical entry: '
                        . $leaf . '.',
                );
            }
            $identity = GatewayBoundedTreeWalker::identity($path);
            $record = [
                'path' => $path,
                'leaf' => $leaf,
                'nonce' => $nonce,
                'kind' => $kind,
                'identity' => $identity,
            ];
            if (\str_starts_with($kind, 'live-')) {
                if (\is_link($path) || !\is_dir($path)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap live capacity entry is linked or non-directory: '
                            . $leaf . '.',
                    );
                }
            } else {
                $contents = $this->readHostRecoveryFile(
                    $path,
                    self::REBOOTSTRAP_CAPACITY_EVIDENCE_MAX_BYTES,
                    0600,
                    'Gateway capacity namespace entry ' . $leaf,
                );
                $document = $this->decodeRebootstrapCapacityEvidence(
                    $contents,
                    'Gateway capacity namespace entry ' . $leaf,
                );
                $sha256 = \hash('sha256', $contents);
                $expectedState = match ($kind) {
                    'held' => 'HELD',
                    'releasing' => 'RELEASING',
                    'released', 'released-alias' => 'RELEASED',
                    default => '',
                };
                if (!\hash_equals($nonce, (string)$document['nonce'])
                    || !\hash_equals($expectedState, (string)$document['state'])
                    || ($kind === 'released-alias'
                        && !\hash_equals(
                            \substr($sha256, 0, 32),
                            $digestPrefix,
                        ))
                ) {
                    throw new \RuntimeException(
                        'Gateway capacity namespace entry is not its named evidence: '
                            . $leaf . '.',
                    );
                }
                $record['contents'] = $contents;
                $record['sha256'] = $sha256;
                $record['document'] = $document;
            }
            $records[$leaf] = $record;
            $nonces[$nonce] ??= [
                'live' => [],
                'held' => null,
                'releasing' => null,
                'released' => null,
                'released_alias' => null,
            ];
            if (\str_starts_with($kind, 'live-')) {
                $liveKind = \substr($kind, 5);
                if (isset($nonces[$nonce]['live'][$liveKind])) {
                    throw new \RuntimeException(
                        'Gateway capacity namespace has a duplicate live state.',
                    );
                }
                $nonces[$nonce]['live'][$liveKind] = $record;
            } else {
                $slot = $kind === 'released-alias'
                    ? 'released_alias'
                    : $kind;
                if (\is_array($nonces[$nonce][$slot])) {
                    throw new \RuntimeException(
                        'Gateway capacity namespace has duplicate evidence.',
                    );
                }
                $nonces[$nonce][$slot] = $record;
            }
        }
        foreach ($nonces as $nonce => $capacity) {
            if (\is_array($capacity['released'])
                && \is_array($capacity['released_alias'])
            ) {
                throw new \RuntimeException(
                    'Gateway capacity namespace has both canonical and GC-alias RELEASED evidence: '
                        . $nonce . '.',
                );
            }
            \ksort($nonces[$nonce]['live'], SORT_STRING);
        }
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record['identity']);
        }
        $after = @\lstat($directory);
        if (!\is_array($after) || !$this->sameFileState($before, $after)) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity directory changed during authenticated inventory.',
            );
        }
        \ksort($records, SORT_STRING);
        \ksort($nonces, SORT_STRING);
        return ['records' => $records, 'nonces' => $nonces];
    }

    /**
     * @param array<string,mixed> $receiptInventory
     * @param array<string,mixed> $capacityInventory
     * @return array<string,array<string,mixed>>
     */
    private function rebootstrapCapacityGcPlan(
        array $receiptInventory,
        array $capacityInventory,
    ): array {
        foreach ($capacityInventory['nonces'] as $nonce => $_capacity) {
            $binding = $receiptInventory['receipts'][$nonce] ?? null;
            if (!\is_array($binding)
                || !\is_array($binding['canonical'])
                || \is_array($binding['alias'])
            ) {
                throw new \RuntimeException(
                    'Gateway capacity evidence has no canonical authenticated terminal receipt: '
                        . $nonce . '.',
                );
            }
        }
        $plan = [];
        foreach ($receiptInventory['receipts'] as $nonce => $binding) {
            $capacity = $capacityInventory['nonces'][$nonce] ?? [
                'live' => [],
                'held' => null,
                'releasing' => null,
                'released' => null,
                'released_alias' => null,
            ];
            $record = $binding['canonical'];
            if (!\is_array($record)) {
                continue;
            }
            $receipt = $record['receipt'];
            $state = (string)$receipt['capacity_evidence_state'];
            $hasCapacity = isset($capacityInventory['nonces'][$nonce]);
            if (\in_array($state, ['NONE', 'COLLECTED'], true)) {
                if ($hasCapacity) {
                    throw new \RuntimeException(
                        'Collected gateway receipt retained capacity namespace evidence.',
                    );
                }
                continue;
            }
            if (!\hash_equals(
                'RELEASED',
                (string)$receipt['capacity_reserve_state'],
            )) {
                throw new \RuntimeException(
                    'Gateway capacity-evidence collection is not bound to RELEASED.',
                );
            }
            foreach (['held', 'releasing', 'released', 'released_alias'] as $kind) {
                if (\is_array($capacity[$kind])) {
                    $this->assertRebootstrapCapacityGcEvidenceBound(
                        $capacity[$kind],
                        $receipt,
                    );
                }
            }
            $released = \is_array($capacity['released'])
                ? $capacity['released']
                : $capacity['released_alias'];
            if (\hash_equals('RETAINED', $state)
                && (!\is_array($capacity['released'])
                    || \is_array($capacity['released_alias']))
            ) {
                throw new \RuntimeException(
                    'Retained gateway capacity evidence lost its canonical RELEASED receipt.',
                );
            }
            if (\hash_equals('COLLECTING', $state)
                && $hasCapacity
                && !\is_array($released)
            ) {
                throw new \RuntimeException(
                    'Collecting gateway capacity evidence lost its RELEASED crash marker.',
                );
            }
            if (\is_array($capacity['releasing']) && \is_array($released)) {
                foreach ([
                    'anchor_set_sha256',
                    'created_at',
                    'entry_set_sha256',
                    'release_reason',
                    'volume_id',
                ] as $field) {
                    if ((string)$capacity['releasing']['document'][$field]
                        !== (string)$released['document'][$field]
                    ) {
                        throw new \RuntimeException(
                            'Gateway RELEASING and RELEASED capacity evidence disagree: '
                                . $field . '.',
                        );
                    }
                }
            }
            if ($capacity['live'] !== [] || $binding['companions'] !== []) {
                continue;
            }
            $plan[$nonce] = [
                'action' => \hash_equals('RETAINED', $state)
                    ? 'begin'
                    : ($hasCapacity ? 'replay' : 'finalize'),
                'receipt' => $receipt,
                'receipt_sha256' => (string)$record['sha256'],
                'capacity' => $capacity,
            ];
        }
        \ksort($plan, SORT_STRING);
        return $plan;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $receipt */
    private function assertRebootstrapCapacityGcEvidenceBound(
        array $record,
        array $receipt,
    ): void {
        $document = $record['document'];
        if (!\hash_equals((string)$receipt['host_id'], (string)$document['host_id'])
            || !\hash_equals((string)$receipt['nonce'], (string)$document['nonce'])
            || !\hash_equals((string)$receipt['package_digest'], (string)$document['package_digest'])
            || !\hash_equals((string)$receipt['candidate_launcher_sha256'], (string)$document['launcher_sha256'])
            || (int)$receipt['capacity_reserve_bytes'] !== (int)$document['target_bytes']
            || (int)$receipt['capacity_reserve_inodes'] !== (int)$document['target_inodes']
            || !\hash_equals(
                (string)$receipt['capacity_reserve_volume_id'],
                (string)$document['volume_id'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway capacity evidence does not bind its terminal receipt.',
            );
        }
        $kind = (string)$record['kind'];
        if (\hash_equals('held', $kind)) {
            if (!\hash_equals(
                (string)$receipt['capacity_reserve_manifest_sha256'],
                (string)$record['sha256'],
            )) {
                throw new \RuntimeException(
                    'Gateway HELD capacity evidence does not match its receipt digest.',
                );
            }
            return;
        }
        if (\in_array($kind, ['released', 'released-alias'], true)) {
            if (!\hash_equals(
                    (string)$receipt['capacity_reserve_release_sha256'],
                    (string)$record['sha256'],
                )
                || !\hash_equals(
                    (string)$receipt['capacity_reserve_release_reason'],
                    (string)$document['release_reason'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway RELEASED capacity evidence does not match its receipt digest/state.',
                );
            }
            return;
        }
        if (!\hash_equals(
            (string)$receipt['capacity_reserve_release_reason'],
            (string)$document['release_reason'],
        )) {
            throw new \RuntimeException(
                'Gateway RELEASING capacity evidence changed its release reason.',
            );
        }
    }

    /** @param array<string,mixed> $plan @return array<string,array<string,string>> */
    private function rebootstrapCapacityGcPlanProof(array $plan): array
    {
        $proof = [];
        foreach ($plan as $nonce => $entry) {
            $evidence = [];
            foreach (['held', 'releasing', 'released', 'released_alias'] as $kind) {
                $record = $entry['capacity'][$kind];
                $evidence[$kind] = \is_array($record)
                    ? (string)$record['sha256']
                    : '';
            }
            $proof[$nonce] = [
                'action' => (string)$entry['action'],
                'receipt_sha256' => (string)$entry['receipt_sha256'],
                ...$evidence,
            ];
        }
        return $proof;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function assertSameRebootstrapCapacityGcInventory(
        array $before,
        array $after,
    ): void {
        if (\array_keys($before['records']) !== \array_keys($after['records'])) {
            throw new \RuntimeException(
                'Gateway rebootstrap capacity namespace changed before GC.',
            );
        }
        foreach ($before['records'] as $leaf => $record) {
            $current = $after['records'][$leaf];
            if (!\hash_equals((string)$record['kind'], (string)$current['kind'])
                || !\hash_equals((string)$record['nonce'], (string)$current['nonce'])
                || !\hash_equals(
                    (string)$record['identity']['device'],
                    (string)$current['identity']['device'],
                )
                || !\hash_equals(
                    (string)$record['identity']['inode'],
                    (string)$current['identity']['inode'],
                )
                || ((isset($record['sha256']) || isset($current['sha256']))
                    && !\hash_equals(
                        (string)($record['sha256'] ?? ''),
                        (string)($current['sha256'] ?? ''),
                    ))
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap capacity entry changed before GC: '
                        . $leaf . '.',
                );
            }
        }
    }

    /** @return list<string> */
    private function rebootstrapReceiptNoncesLocked(
        bool $enforceProductLimit = true,
    ): array {
        $inventory = $this->rebootstrapReceiptGcInventoryLocked();
        $inventory = $this->collectTerminalRebootstrapCapacityEvidenceLocked(
            $inventory,
        );
        $inventory = $this->collectTerminalRebootstrapReceiptsLocked(
            $inventory,
        );
        $nonces = [];
        foreach ($inventory['receipts'] as $nonce => $binding) {
            if (\is_array($binding['canonical'])) {
                $nonces[] = $nonce;
            }
        }
        \sort($nonces, SORT_STRING);
        if ($enforceProductLimit
            && \count($nonces) > self::MAX_REBOOTSTRAP_RECEIPTS
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap terminal receipt quota is exhausted.'
            );
        }
        return $nonces;
    }

    /**
     * Enumerate and authenticate the complete raw receipt namespace without
     * mutating atomic recovery evidence. Every malformed/case alias,
     * ambiguous GC alias and receipt/HMAC mismatch fails before GC can move
     * or unlink its first entry.
     *
     * @return array{records:array<string,array<string,mixed>>,receipts:array<string,array<string,mixed>>}
     */
    private function rebootstrapReceiptGcInventoryLocked(): array
    {
        $directory = $this->paths->rebootstrapReceiptsDir();
        $directoryBefore = @\lstat($directory);
        if (!\is_array($directoryBefore)
            || \is_link($directory)
            || !\is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt directory is unsafe.',
            );
        }
        $raw = $this->rebootstrapRawTopLevelEntries(
            $directory,
            [],
            'Gateway rebootstrap raw receipt namespace',
        );
        if (\count($raw) > self::REBOOTSTRAP_RECEIPT_RAW_MAX_ENTRIES) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt directory exceeds its fixed raw entry quota.',
            );
        }
        $records = [];
        $receipts = [];
        foreach ($raw as $leaf => $path) {
            $nonce = '';
            $kind = '';
            $gcDigestPrefix = '';
            if (\preg_match(
                '/\A([a-f0-9]{32})\.json\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'canonical';
            } elseif (\preg_match(
                '/\A([a-f0-9]{32})\.json\.wls-backup-[a-f0-9]{16}\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'backup';
            } elseif (\preg_match(
                '/\A([a-f0-9]{32})\.json\.tmp-[a-f0-9]{24}\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'staging';
            } elseif (\preg_match(
                '/\A([a-f0-9]{32})\.json\.gc-([a-f0-9]{32})\z/D',
                $leaf,
                $matches,
            ) === 1) {
                $nonce = (string)$matches[1];
                $kind = 'gc-alias';
                $gcDigestPrefix = (string)$matches[2];
            } else {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt directory contains a malformed or noncanonical entry: '
                        . $leaf . '.',
                );
            }
            $contents = $this->readHostRecoveryFile(
                $path,
                self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                0600,
                'Gateway rebootstrap receipt namespace entry ' . $leaf,
            );
            $receipt = $this->decodeRebootstrapDocument(
                $contents,
                'Gateway rebootstrap receipt namespace entry ' . $leaf,
            );
            if (!\hash_equals($nonce, (string)$receipt['nonce'])
                || !\in_array(
                    (string)$receipt['phase'],
                    ['COMMITTED', 'ROLLED_BACK'],
                    true,
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt namespace entry is not its named terminal receipt: '
                        . $leaf . '.',
                );
            }
            $sha256 = \hash('sha256', $contents);
            if ($kind === 'gc-alias'
                && !\hash_equals(\substr($sha256, 0, 32), $gcDigestPrefix)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt GC alias digest is invalid: '
                        . $leaf . '.',
                );
            }
            $record = [
                'path' => $path,
                'leaf' => $leaf,
                'nonce' => $nonce,
                'kind' => $kind,
                'contents' => $contents,
                'sha256' => $sha256,
                'identity' => GatewayBoundedTreeWalker::identity($path),
                'receipt' => $receipt,
            ];
            $records[$leaf] = $record;
            $receipts[$nonce] ??= [
                'canonical' => null,
                'companions' => [],
                'alias' => null,
            ];
            if ($kind === 'canonical') {
                if (\is_array($receipts[$nonce]['canonical'])) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap receipt namespace has duplicate canonical receipts.',
                    );
                }
                $receipts[$nonce]['canonical'] = $record;
            } elseif ($kind === 'gc-alias') {
                if (\is_array($receipts[$nonce]['alias'])) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap receipt namespace has multiple GC aliases.',
                    );
                }
                $receipts[$nonce]['alias'] = $record;
            } else {
                $receipts[$nonce]['companions'][$leaf] = $record;
                $kinds = \array_count_values(\array_map(
                    static fn (array $companion): string => (string)$companion['kind'],
                    $receipts[$nonce]['companions'],
                ));
                if (($kinds['backup'] ?? 0)
                        > self::MAX_ATOMIC_RECOVERY_BACKUPS_PER_TARGET
                    || ($kinds['staging'] ?? 0)
                        > self::MAX_ATOMIC_RECOVERY_TEMPORARIES_PER_TARGET
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap receipt atomic companion quota is exhausted.',
                    );
                }
            }
        }
        foreach ($receipts as $nonce => $binding) {
            if (\is_array($binding['alias'])
                && (\is_array($binding['canonical'])
                    || $binding['companions'] !== [])
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt GC alias conflicts with its canonical namespace: '
                        . $nonce . '.',
                );
            }
            if (!\is_array($binding['canonical'])
                && $binding['companions'] !== []
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt atomic companion has no canonical target: '
                        . $nonce . '.',
                );
            }
        }
        foreach ($records as $record) {
            GatewayBoundedTreeWalker::revalidate($record['identity']);
        }
        $directoryAfter = @\lstat($directory);
        if (!\is_array($directoryAfter)
            || !$this->sameFileState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt directory changed during authenticated inventory.',
            );
        }
        \ksort($records, SORT_STRING);
        \ksort($receipts, SORT_STRING);
        return ['records' => $records, 'receipts' => $receipts];
    }

    /**
     * @return array{journal_blocked:bool,backups:array<string,bool>,collecting:array<string,bool>,candidates:array<string,bool>,candidate_locks:array<string,bool>,capacity_live:array<string,bool>}
     */
    private function rebootstrapReceiptGcBlockersLocked(): array
    {
        $journalBlocked = $this->rebootstrapJournalGcFenceBlockedLocked();
        $backups = [];
        $collecting = [];
        foreach ($this->rebootstrapRawTopLevelEntries(
            $this->paths->rebootstrapBackupsDir(),
            [],
            'Gateway rebootstrap receipt-GC backup namespace',
        ) as $leaf => $_path) {
            if (\preg_match('/\A([a-f0-9]{32})\z/D', $leaf, $matches) === 1) {
                $backups[(string)$matches[1]] = true;
                continue;
            }
            if (\preg_match(
                '/\A([a-f0-9]{32})\.collecting-[a-f0-9]{32}\z/D',
                $leaf,
                $matches,
            ) !== 1) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt-GC backup namespace contains a malformed or noncanonical entry.',
                );
            }
            $nonce = (string)$matches[1];
            if (isset($collecting[$nonce])) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt-GC backup namespace has multiple collecting aliases.',
                );
            }
            $collecting[$nonce] = true;
        }
        $candidates = [];
        $candidateLocks = [];
        foreach ($this->rebootstrapRawTopLevelEntries(
            $this->paths->rebootstrapCandidatesDir(),
            [],
            'Gateway rebootstrap receipt-GC candidate namespace',
        ) as $leaf => $_path) {
            if (\preg_match('/\A([a-f0-9]{32})\z/D', $leaf, $matches) === 1) {
                $candidates[(string)$matches[1]] = true;
                continue;
            }
            if (\preg_match(
                '/\A([a-f0-9]{32})\.install\.lock\z/D',
                $leaf,
                $matches,
            ) !== 1) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt-GC candidate namespace contains a malformed or noncanonical entry.',
                );
            }
            $nonce = (string)$matches[1];
            if (isset($candidateLocks[$nonce])) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt-GC candidate namespace has duplicate install locks.',
                );
            }
            $candidateLocks[$nonce] = true;
        }
        foreach ([&$backups, &$collecting, &$candidates, &$candidateLocks] as &$set) {
            \ksort($set, SORT_STRING);
        }
        unset($set);
        $capacityLive = [];
        foreach ($this->rebootstrapCapacityGcInventoryLocked()['nonces']
            as $nonce => $capacity
        ) {
            if ($capacity['live'] !== []) {
                $capacityLive[(string)$nonce] = true;
            }
        }
        \ksort($capacityLive, SORT_STRING);
        return [
            'journal_blocked' => $journalBlocked,
            'backups' => $backups,
            'collecting' => $collecting,
            'candidates' => $candidates,
            'candidate_locks' => $candidateLocks,
            'capacity_live' => $capacityLive,
        ];
    }

    private function rebootstrapJournalGcFenceBlockedLocked(): bool
    {
        $journal = $this->paths->rebootstrapJournalFile();
        $journalStatus = @\lstat($journal);
        if (!\is_array($journalStatus)
            && (\file_exists($journal) || \is_link($journal))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap journal path is indeterminate during receipt GC.',
            );
        }
        $blocked = \is_array($journalStatus)
            || GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $journal,
                self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                'Gateway rebootstrap journal receipt-GC fence',
            );
        $after = @\lstat($journal);
        if ((\is_array($journalStatus) !== \is_array($after))
            || (!\is_array($after)
                && (\file_exists($journal) || \is_link($journal)))
            || (\is_array($journalStatus)
                && (!\is_array($after)
                    || !$this->sameFileState($journalStatus, $after)))
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap journal fence changed during receipt GC preflight.',
            );
        }
        return $blocked;
    }

    /**
     * @param array<string,mixed> $inventory
     * @param array<string,mixed> $blockers
     * @return array<string,array<string,mixed>>
     */
    private function rebootstrapReceiptGcPlan(
        array $inventory,
        array $blockers,
    ): array {
        if ((bool)$blockers['journal_blocked']) {
            return [];
        }
        $canonical = [];
        foreach ($inventory['receipts'] as $nonce => $binding) {
            if (\is_array($binding['canonical'])) {
                $canonical[] = $binding['canonical'];
            }
        }
        \usort(
            $canonical,
            static function (array $left, array $right): int {
                // This signed wall timestamp is deterministic audit retention,
                // not a security ordering primitive. A host wall-clock
                // rollback can place a later transaction earlier; all
                // recovery/capacity bindings are already empty before any
                // receipt can reach this policy.
                $time = (int)$left['receipt']['terminal_at']
                    <=> (int)$right['receipt']['terminal_at'];
                return $time !== 0
                    ? $time
                    : \strcmp((string)$left['nonce'], (string)$right['nonce']);
            },
        );
        $protected = [];
        foreach (\array_slice(
            $canonical,
            -self::REBOOTSTRAP_RECEIPT_RECENT_RETENTION,
        ) as $record) {
            $protected[(string)$record['nonce']] = true;
        }
        $plan = [];
        foreach ($inventory['receipts'] as $nonce => $binding) {
            $alias = $binding['alias'];
            if (\is_array($alias)) {
                if (!$this->rebootstrapReceiptGcRecordEligible(
                    $alias,
                    $blockers,
                )) {
                    if ($this->rebootstrapReceiptGcBindingEmpty($alias['receipt'])) {
                        continue;
                    }
                    throw new \RuntimeException(
                        'Gateway rebootstrap receipt GC alias is no longer an eligible terminal receipt.',
                    );
                }
                $plan[(string)$alias['leaf']] = [
                    'action' => 'replay',
                    'record' => $alias,
                ];
                continue;
            }
            $record = $binding['canonical'];
            if (!\is_array($record)
                || isset($protected[$nonce])
                || $binding['companions'] !== []
                || !$this->rebootstrapReceiptGcRecordEligible(
                    $record,
                    $blockers,
                )
            ) {
                continue;
            }
            $plan[(string)$record['leaf']] = [
                'action' => 'move',
                'record' => $record,
            ];
        }
        \ksort($plan, SORT_STRING);
        return $plan;
    }

    /** @param array<string,mixed> $receipt */
    private function rebootstrapReceiptGcBindingEmpty(array $receipt): bool
    {
        return \hash_equals(
                'COLLECTED',
                (string)$receipt['retained_backup_state'],
            )
            && (string)$receipt['backup_collection_nonce'] === ''
            && (string)$receipt['backup_collection_device'] === ''
            && (string)$receipt['backup_collection_inode'] === ''
            && \in_array(
                (string)$receipt['capacity_evidence_state'],
                ['NONE', 'COLLECTED'],
                true,
            );
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $blockers */
    private function rebootstrapReceiptGcRecordEligible(
        array $record,
        array $blockers,
    ): bool {
        $nonce = (string)$record['nonce'];
        return $this->rebootstrapReceiptGcBindingEmpty($record['receipt'])
            && !isset($blockers['backups'][$nonce])
            && !isset($blockers['collecting'][$nonce])
            && !isset($blockers['candidates'][$nonce])
            && !isset($blockers['candidate_locks'][$nonce])
            && !isset($blockers['capacity_live'][$nonce]);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function assertSameRebootstrapReceiptGcInventory(
        array $before,
        array $after,
    ): void {
        if (\array_keys($before['records']) !== \array_keys($after['records'])) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt namespace changed before GC.',
            );
        }
        foreach ($before['records'] as $leaf => $record) {
            $current = $after['records'][$leaf];
            if (!\hash_equals(
                    (string)$record['sha256'],
                    (string)$current['sha256'],
                )
                || !\hash_equals(
                    (string)$record['kind'],
                    (string)$current['kind'],
                )
                || !\hash_equals(
                    (string)$record['nonce'],
                    (string)$current['nonce'],
                )
                || !\hash_equals(
                    (string)$record['identity']['device'],
                    (string)$current['identity']['device'],
                )
                || !\hash_equals(
                    (string)$record['identity']['inode'],
                    (string)$current['identity']['inode'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt entry changed before GC: '
                        . $leaf . '.',
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $inventory
     * @return array<string,mixed>
     */
    private function collectTerminalRebootstrapReceiptsLocked(
        array $inventory,
    ): array {
        $blockers = $this->rebootstrapReceiptGcBlockersLocked();
        $plan = $this->rebootstrapReceiptGcPlan($inventory, $blockers);
        if ($plan === []) {
            return $inventory;
        }
        $rechecked = $this->rebootstrapReceiptGcInventoryLocked();
        $recheckedBlockers = $this->rebootstrapReceiptGcBlockersLocked();
        $this->assertSameRebootstrapReceiptGcInventory(
            $inventory,
            $rechecked,
        );
        if (!\hash_equals(
            GatewayClient::canonicalJson($blockers),
            GatewayClient::canonicalJson($recheckedBlockers),
        )) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt GC blockers changed before collection.',
            );
        }
        $recheckedPlan = $this->rebootstrapReceiptGcPlan(
            $rechecked,
            $recheckedBlockers,
        );
        if (\array_keys($plan) !== \array_keys($recheckedPlan)) {
            throw new \RuntimeException(
                'Gateway rebootstrap receipt GC plan changed before collection.',
            );
        }
        foreach ($plan as $leaf => $entry) {
            $current = $recheckedPlan[$leaf] ?? null;
            if (!\is_array($current)
                || !\hash_equals(
                    (string)$entry['action'],
                    (string)$current['action'],
                )
                || !\hash_equals(
                    (string)$entry['record']['sha256'],
                    (string)$current['record']['sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt GC plan identity changed before collection.',
                );
            }
        }
        foreach ($recheckedPlan as $entry) {
            $record = $entry['record'];
            $source = (string)$record['path'];
            $alias = $this->paths->rebootstrapReceiptGcFile(
                (string)$record['nonce'],
                (string)$record['sha256'],
            );
            if (\hash_equals('move', (string)$entry['action'])) {
                GatewayBoundedTreeWalker::revalidate($record['identity']);
                GatewayProjectStateFilesystem::moveNoReplace(
                    $source,
                    $alias,
                    'Gateway terminal rebootstrap receipt GC fence',
                );
                $this->injectRebootstrapCrash('receipt-gc:after-move');
            } elseif (!\hash_equals($source, $alias)) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt GC replay alias is not canonical.',
                );
            }
            $contents = $this->readHostRecoveryFile(
                $alias,
                self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                0600,
                'Gateway rebootstrap receipt GC alias',
            );
            $receipt = $this->decodeRebootstrapDocument(
                $contents,
                'Gateway rebootstrap receipt GC alias',
            );
            if (!\hash_equals((string)$record['contents'], $contents)
                || !\hash_equals(
                    (string)$record['sha256'],
                    \hash('sha256', $contents),
                )
                || !\hash_equals(
                    (string)$record['nonce'],
                    (string)$receipt['nonce'],
                )
                || !$this->rebootstrapReceiptGcBindingEmpty($receipt)
            ) {
                throw new \RuntimeException(
                    'Gateway rebootstrap receipt GC alias failed its authenticated digest recheck.',
                );
            }
            $identity = GatewayBoundedTreeWalker::identity($alias);
            GatewayProjectStateFilesystem::removeRegular(
                $alias,
                'authenticated terminal gateway rebootstrap receipt GC alias',
                GatewayBoundedTreeWalker::revalidate($identity),
            );
            $this->injectRebootstrapCrash('receipt-gc:after-delete');
        }
        return $this->rebootstrapReceiptGcInventoryLocked();
    }

    /** @param array<string,mixed> $receipt */
    private function assertRetainedRebootstrapBackup(
        array $receipt,
        string $backup,
    ): void {
        if (!\is_dir($backup) || \is_link($backup)) {
            throw new \RuntimeException(
                'Retained gateway rebootstrap backup is missing or unsafe.'
            );
        }
        $rootEntries = ['bin', 'platform', 'slots', 'trust'];
        $rootEntries[] = 'derived';
        $rootEntries[] = 'derived-state.manifest.json';
        $guardianAuthorization = $this->paths
            ->guardianRecoveryAuthorizationFile((string)$receipt['nonce']);
        $guardianInventory = $this->paths
            ->guardianRecoveryInventoryFile((string)$receipt['nonce']);
        $hasGuardianAuthorization = \file_exists($guardianAuthorization)
            || \is_link($guardianAuthorization);
        $hasGuardianInventory = \file_exists($guardianInventory)
            || \is_link($guardianInventory);
        if ($hasGuardianAuthorization !== $hasGuardianInventory) {
            throw new \RuntimeException(
                'Retained gateway rebootstrap Guardian evidence is incomplete.',
            );
        }
        if ($hasGuardianAuthorization) {
            $rootEntries[] = 'guardian-recovery.authorization';
            $rootEntries[] = 'guardian-recovery.inventory';
        }
        $this->assertExactRebootstrapDirectoryEntries(
            $backup,
            $rootEntries,
            'Retained gateway rebootstrap backup root',
        );
        $slotEntries = [];
        foreach (['A', 'B'] as $slot) {
            if (($receipt['old_slots'][$slot] ?? null) !== null) {
                $slotEntries[] = $slot;
            }
        }
        $this->assertExactRebootstrapDirectoryEntries(
            $backup . DIRECTORY_SEPARATOR . 'slots',
            $slotEntries,
            'Retained gateway rebootstrap slot inventory',
        );
        $this->assertExactRebootstrapDirectoryEntries(
            $backup . DIRECTORY_SEPARATOR . 'bin',
            ['launcher'],
            'Retained gateway rebootstrap launcher inventory',
        );
        $trustEntries = ['active-slot', 'stable-launcher.sha256'];
        if ((string)$receipt['old_previous_slot'] !== '') {
            $trustEntries[] = 'previous-slot';
        }
        $this->assertExactRebootstrapDirectoryEntries(
            $backup . DIRECTORY_SEPARATOR . 'trust',
            $trustEntries,
            'Retained gateway rebootstrap trust inventory',
        );
        $this->assertExactRebootstrapDirectoryEntries(
            $backup . DIRECTORY_SEPARATOR . 'platform',
            $receipt['platform_snapshot'] === null
                ? []
                : ['definition.before', 'metadata.before'],
            'Retained gateway rebootstrap platform inventory',
        );
        $this->assertExactRebootstrapDirectoryEntries(
            $backup . DIRECTORY_SEPARATOR . 'derived',
            \array_keys($this->rebootstrapDerivedNamespaces()),
            'Retained gateway rebootstrap derived-category inventory',
        );
        foreach (['A', 'B'] as $slot) {
            $path = $backup . DIRECTORY_SEPARATOR . 'slots'
                . DIRECTORY_SEPARATOR . $slot;
            $closure = $receipt['old_slots'][$slot] ?? null;
            if ($closure === null) {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException(
                        'Retained gateway rebootstrap backup has an unexpected slot '
                            . $slot . '.'
                    );
                }
                continue;
            }
            $this->assertRebootstrapRuntimeDirectory(
                $path,
                (string)$closure['runtime_generation'],
                'retained old gateway slot ' . $slot,
            );
        }
        $this->assertRebootstrapRegularFile(
            $backup . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                . 'launcher',
            (string)$receipt['old_launcher_sha256'],
            (int)$receipt['old_launcher_size'],
            (int)$receipt['old_launcher_mode'],
            'retained old stable gateway launcher',
        );
        $identity = (string)$receipt['old_launcher_sha256'] . "\n";
        $this->assertRebootstrapRegularFile(
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            \hash('sha256', $identity),
            \strlen($identity),
            0600,
            'retained old stable launcher identity',
        );
        $active = (string)$receipt['old_active_slot'] . "\n";
        $this->assertRebootstrapRegularFile(
            $backup . DIRECTORY_SEPARATOR . 'trust' . DIRECTORY_SEPARATOR
                . 'active-slot',
            \hash('sha256', $active),
            \strlen($active),
            0640,
            'retained old active-slot pointer',
        );
        $previous = (string)$receipt['old_previous_slot'];
        $previousPath = $backup . DIRECTORY_SEPARATOR . 'trust'
            . DIRECTORY_SEPARATOR . 'previous-slot';
        if ($previous === '') {
            if (\file_exists($previousPath) || \is_link($previousPath)) {
                throw new \RuntimeException(
                    'Retained gateway rebootstrap backup has an unexpected previous-slot pointer.'
                );
            }
        } else {
            $contents = $previous . "\n";
            $this->assertRebootstrapRegularFile(
                $previousPath,
                \hash('sha256', $contents),
                \strlen($contents),
                0640,
                'retained old previous-slot pointer',
            );
        }
        $this->assertRetainedRebootstrapDerivedBackup($receipt, $backup);
        $this->assertRebootstrapPlatformBackup($receipt, $backup);
    }

    /** @param list<string> $expected */
    private function assertExactRebootstrapDirectoryEntries(
        string $directory,
        array $expected,
        string $label,
    ): void {
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException($label . ' is missing or unsafe.');
        }
        $actual = \array_keys($this->rebootstrapRawTopLevelEntries(
            $directory,
            [],
            $label,
        ));
        \sort($actual, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                $label . ' differs from the signed transaction closure.',
            );
        }
    }

    /** @param array<string,mixed> $receipt */
    private function assertRebootstrapPlatformBackup(
        array $receipt,
        string $backup,
    ): void {
        if (!\is_dir($backup) || \is_link($backup)) {
            throw new \RuntimeException(
                'Gateway rebootstrap backup is missing or unsafe.'
            );
        }
        $snapshot = $receipt['platform_snapshot'] ?? null;
        if ($snapshot === null) {
            return;
        }
        if (!\is_array($snapshot)) {
            throw new \RuntimeException(
                'Gateway rebootstrap platform snapshot is invalid.'
            );
        }
        foreach ([
            'definition.before' => [
                (string)$snapshot['definition_sha256'],
                1_048_576,
            ],
            'metadata.before' => [
                (string)$snapshot['metadata_sha256'],
                16_384,
            ],
        ] as $leaf => [$digest, $maximum]) {
            $path = $backup . DIRECTORY_SEPARATOR . 'platform'
                . DIRECTORY_SEPARATOR . $leaf;
            $actual = $this->digestStableRegularFile(
                $path,
                $maximum,
                'Gateway rebootstrap platform backup ' . $leaf,
            );
            if (!\hash_equals($digest, (string)$actual['sha256'])) {
                throw new \RuntimeException(
                    'Gateway rebootstrap platform backup digest changed.'
                );
            }
            $this->assertRebootstrapRegularFile(
                $path,
                $digest,
                (int)$actual['size'],
                0600,
                'Gateway rebootstrap platform backup ' . $leaf,
            );
        }
    }

    /** @param array<string,mixed> $receipt */
    private function assertTerminalRebootstrapBackupStateLocked(
        array $receipt,
    ): void {
        $phase = (string)$receipt['phase'];
        $state = (string)$receipt['retained_backup_state'];
        if (!\in_array($phase, ['COMMITTED', 'ROLLED_BACK'], true)
            || !\in_array($state, ['RETAINED', 'COLLECTED'], true)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap terminal backup state is invalid.',
            );
        }
        if (\hash_equals('ROLLED_BACK', $phase)) {
            $this->assertRebootstrapDerivedWorkingGenerationAbsent($receipt);
        }
        $nonce = (string)$receipt['nonce'];
        $candidate = $this->paths->rebootstrapCandidateDir($nonce);
        if (\file_exists($candidate) || \is_link($candidate)) {
            throw new \RuntimeException(
                'A terminal gateway rebootstrap retained an unexpected candidate runtime.',
            );
        }
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $backupExists = \file_exists($backup) || \is_link($backup);
        if (\hash_equals('RETAINED', $state)) {
            if ((string)$receipt['backup_collection_nonce'] !== '') {
                $this->completeRebootstrapBackupCollectionLocked($receipt);
                return;
            }
            $this->assertTerminalRebootstrapBackupClosure(
                $receipt,
                $backup,
            );
            return;
        }
        if ((string)$receipt['backup_collection_nonce'] !== '') {
            $this->completeRebootstrapBackupCollectionLocked($receipt);
            return;
        }
        if ($backupExists) {
            throw new \RuntimeException(
                'Collected gateway rebootstrap receipt has an unbound backup.',
            );
        }
    }

    /** @param array<string,mixed> $receipt */
    private function assertTerminalRebootstrapBackupClosure(
        array $receipt,
        string $backup,
    ): void {
        if (\hash_equals('COMMITTED', (string)$receipt['phase'])) {
            $this->assertRetainedRebootstrapBackup($receipt, $backup);
            return;
        }
        $this->assertRebootstrapPlatformBackup($receipt, $backup);
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function beginRebootstrapBackupCollectionLocked(
        array $receipt,
    ): array {
        if (!\hash_equals(
                'RETAINED',
                (string)$receipt['retained_backup_state'],
            )
            || (string)$receipt['backup_collection_nonce'] !== ''
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap backup collection is already bound or unavailable.',
            );
        }
        $backup = $this->paths->rebootstrapBackupDir(
            (string)$receipt['nonce'],
        );
        $this->assertTerminalRebootstrapBackupClosure($receipt, $backup);
        $identity = GatewayBoundedTreeWalker::identity($backup);
        if (($identity['directory'] ?? false) !== true) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection source is not a directory.',
            );
        }
        $receipt['backup_collection_nonce'] = \bin2hex(\random_bytes(16));
        $receipt['backup_collection_device'] = (string)$identity['device'];
        $receipt['backup_collection_inode'] = (string)$identity['inode'];
        return $this->writeRebootstrapReceiptLocked($receipt);
    }

    /** @param array<string,mixed> $receipt */
    private function completeRebootstrapBackupCollectionLocked(
        array $receipt,
    ): void {
        $nonce = (string)$receipt['nonce'];
        $collectionNonce = (string)$receipt['backup_collection_nonce'];
        if ($collectionNonce === '') {
            throw new \RuntimeException(
                'Gateway rebootstrap collection receipt is not bound.',
            );
        }
        $backup = $this->paths->rebootstrapBackupDir($nonce);
        $collecting = $this->paths->rebootstrapCollectedBackupDir(
            $nonce,
            $collectionNonce,
        );
        $backupExists = \file_exists($backup) || \is_link($backup);
        $collectingExists = \file_exists($collecting) || \is_link($collecting);
        $state = (string)$receipt['retained_backup_state'];
        if (\hash_equals('RETAINED', $state)) {
            if ($backupExists === $collectingExists) {
                throw new \RuntimeException(
                    'Gateway rebootstrap collection source must exist at exactly one bound location.',
                );
            }
            $current = $backupExists ? $backup : $collecting;
            $this->assertRebootstrapCollectionRootIdentity(
                $receipt,
                $current,
            );
            $this->assertTerminalRebootstrapBackupClosure(
                $receipt,
                $current,
            );
            if ($backupExists) {
                GatewayProjectStateFilesystem::moveNoReplace(
                    $backup,
                    $collecting,
                    'Gateway rebootstrap backup collection fence',
                );
                $this->assertRebootstrapCollectionRootIdentity(
                    $receipt,
                    $collecting,
                );
                $this->assertTerminalRebootstrapBackupClosure(
                    $receipt,
                    $collecting,
                );
            }
            $receipt['retained_backup_state'] = 'COLLECTED';
            $receipt = $this->writeRebootstrapReceiptLocked($receipt);
            $state = 'COLLECTED';
            $collectingExists = true;
        }
        if (!\hash_equals('COLLECTED', $state)) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection state is invalid.',
            );
        }
        if (\file_exists($backup) || \is_link($backup)) {
            throw new \RuntimeException(
                'Collected gateway rebootstrap backup reappeared at its live retention path.',
            );
        }
        $collectingExists = \file_exists($collecting) || \is_link($collecting);
        if (!$collectingExists) {
            $this->clearCompletedRebootstrapCollectionBindingLocked(
                $receipt,
                $collecting,
            );
            return;
        }
        $this->assertRebootstrapCollectionRootIdentity($receipt, $collecting);
        $entries = $this->collectRebootstrapBackupRemovalRecords(
            $collecting,
            $receipt,
            'collecting a retained gateway rebootstrap backup',
        );
        $rootRecord = null;
        foreach ($entries as $entry) {
            if ((int)($entry['depth'] ?? -1) === 0
                && \hash_equals($collecting, (string)($entry['path'] ?? ''))
            ) {
                $rootRecord = $entry;
                break;
            }
        }
        if (!\is_array($rootRecord)
            || ($rootRecord['directory'] ?? false) !== true
            || !\hash_equals(
                (string)$receipt['backup_collection_device'],
                (string)($rootRecord['device'] ?? ''),
            )
            || !\hash_equals(
                (string)$receipt['backup_collection_inode'],
                (string)($rootRecord['inode'] ?? ''),
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection walk selected another root identity.',
            );
        }
        $this->assertRebootstrapCollectionRootIdentity($receipt, $collecting);
        $this->removeCollectedTree($collecting, $entries);
        $this->clearCompletedRebootstrapCollectionBindingLocked(
            $receipt,
            $collecting,
        );
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function clearCompletedRebootstrapCollectionBindingLocked(
        array $receipt,
        string $collecting,
    ): array {
        if (!\hash_equals(
                'COLLECTED',
                (string)$receipt['retained_backup_state'],
            )
            || (string)$receipt['backup_collection_nonce'] === ''
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection binding cannot be cleared from this state.',
            );
        }
        $backup = $this->paths->rebootstrapBackupDir(
            (string)$receipt['nonce'],
        );
        if (\file_exists($backup)
            || \is_link($backup)
            || \file_exists($collecting)
            || \is_link($collecting)
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection binding cannot clear before both backup names are absent.',
            );
        }
        $receipt['backup_collection_nonce'] = '';
        $receipt['backup_collection_device'] = '';
        $receipt['backup_collection_inode'] = '';
        return $this->writeRebootstrapReceiptLocked($receipt);
    }

    /** @param array<string,mixed> $receipt */
    private function assertRebootstrapCollectionRootIdentity(
        array $receipt,
        string $path,
    ): void {
        $identity = GatewayBoundedTreeWalker::identity($path);
        if (($identity['directory'] ?? false) !== true
            || !\hash_equals(
                (string)$receipt['backup_collection_device'],
                (string)$identity['device'],
            )
            || !\hash_equals(
                (string)$receipt['backup_collection_inode'],
                (string)$identity['inode'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway rebootstrap collection root identity changed.',
            );
        }
    }

    /**
     * Collect a child-first removal plan without adding backup wrapper depth
     * to the independently bounded slot and derived-state closures.
     *
     * @param array<string,mixed> $receipt
     * @return list<array<string,mixed>>
     */
    private function collectRebootstrapBackupRemovalRecords(
        string $root,
        array $receipt,
        string $label,
    ): array {
        $records = [];
        $seen = [];
        $append = function (array $record) use (
            &$records,
            &$seen,
            $label,
        ): void {
            $path = (string)($record['path'] ?? '');
            if ($path === '' || isset($seen[$path])) {
                throw new \RuntimeException(
                    $label . ' selected a duplicate or invalid removal path.',
                );
            }
            if (\count($records) >= self::REBOOTSTRAP_COLLECTION_MAX_ENTRIES) {
                throw new \RuntimeException(
                    $label . ' exceeds the aggregate removal-entry budget.',
                );
            }
            $seen[$path] = true;
            $records[] = $record;
        };
        $collectTree = function (
            string $path,
            int $maximumEntries,
        ) use ($append, $label): void {
            foreach (GatewayBoundedTreeWalker::collect(
                $path,
                true,
                true,
                $maximumEntries,
                GatewayBoundedTreeWalker::MAX_DEPTH,
                fn (): null => $this->deadlineProgress($label),
            ) as $record) {
                $append($record);
            }
        };
        $collectLeaf = function (string $path) use (
            $append,
            $collectTree,
        ): void {
            $identity = GatewayBoundedTreeWalker::identity($path);
            if (($identity['directory'] ?? false) === true) {
                $collectTree(
                    $path,
                    self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES,
                );
                return;
            }
            $append($identity);
        };

        $rootEntries = $this->rebootstrapRawTopLevelEntries(
            $root,
            [],
            $label . ' root',
        );
        $allowedRoot = [
            'bin',
            'platform',
            'slots',
            'trust',
            'guardian-recovery.authorization',
            'guardian-recovery.inventory',
        ];
        $allowedRoot[] = 'derived';
        $allowedRoot[] = 'derived-state.manifest.json';
        if (\in_array((string)$receipt['phase'], [
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
            'ROLLED_BACK',
        ], true)) {
            $allowedRoot[] = 'derived';
            $allowedRoot[] = 'derived-state.manifest.json';
            $allowedRoot[] = 'new-derived';
            $allowedRoot[] = 'new-generation';
        }
        $allowedRoot = \array_fill_keys(\array_unique($allowedRoot), true);
        foreach ($rootEntries as $leaf => $path) {
            if (!isset($allowedRoot[$leaf])) {
                throw new \RuntimeException(
                    $label . ' contains an unexpected backup root entry: '
                        . $leaf . '.',
                );
            }
            if (\in_array($leaf, [
                'derived-state.manifest.json',
                'guardian-recovery.authorization',
                'guardian-recovery.inventory',
            ], true)) {
                $identity = GatewayBoundedTreeWalker::identity($path);
                if (($identity['directory'] ?? true) === true) {
                    throw new \RuntimeException(
                        $label . ' derived-state manifest is not a regular file.',
                    );
                }
                $collectLeaf($path);
                continue;
            }
            if (!\is_dir($path) || \is_link($path)) {
                throw new \RuntimeException(
                    $label . ' backup wrapper is not a real directory: '
                        . $leaf . '.',
                );
            }
            if (\hash_equals('slots', $leaf)) {
                $slots = $this->rebootstrapRawTopLevelEntries(
                    $path,
                    [],
                    $label . ' slots',
                );
                foreach ($slots as $slot => $slotPath) {
                    if (!\in_array($slot, ['A', 'B'], true)) {
                        throw new \RuntimeException(
                            $label . ' contains a non-A/B retained slot.',
                        );
                    }
                    $collectTree(
                        $slotPath,
                        self::MAX_PACKAGE_COMPONENTS
                            + self::MAX_PACKAGE_DIRECTORIES + 4,
                    );
                }
                $append(GatewayBoundedTreeWalker::identity($path));
                continue;
            }
            if (\in_array($leaf, ['derived', 'new-derived'], true)) {
                $categories = $this->rebootstrapRawTopLevelEntries(
                    $path,
                    [],
                    $label . ' ' . $leaf,
                );
                $allowedCategories = \array_fill_keys(
                    \array_keys($this->rebootstrapDerivedNamespaces()),
                    true,
                );
                foreach ($categories as $category => $categoryPath) {
                    if (!isset($allowedCategories[$category])
                        || !\is_dir($categoryPath)
                        || \is_link($categoryPath)
                    ) {
                        throw new \RuntimeException(
                            $label . ' contains an invalid derived backup category.',
                        );
                    }
                    foreach ($this->rebootstrapRawTopLevelEntries(
                        $categoryPath,
                        [],
                        $label . ' ' . $leaf . '/' . $category,
                    ) as $derivedPath) {
                        $collectLeaf($derivedPath);
                    }
                    $append(GatewayBoundedTreeWalker::identity($categoryPath));
                }
                $append(GatewayBoundedTreeWalker::identity($path));
                continue;
            }
            if (\hash_equals('new-generation', $leaf)) {
                $generationEntries = $this->rebootstrapRawTopLevelEntries(
                    $path,
                    [],
                    $label . ' new-generation',
                );
                foreach ($generationEntries as $generationLeaf => $generationPath) {
                    if (!\in_array($generationLeaf, ['candidate', 'slots'], true)
                        || !\is_dir($generationPath)
                        || \is_link($generationPath)
                    ) {
                        throw new \RuntimeException(
                            $label . ' contains an invalid new-generation quarantine entry.',
                        );
                    }
                    if (\hash_equals('candidate', $generationLeaf)) {
                        $collectTree(
                            $generationPath,
                            self::MAX_PACKAGE_COMPONENTS
                                + self::MAX_PACKAGE_DIRECTORIES + 4,
                        );
                        continue;
                    }
                    $isolatedSlots = $this->rebootstrapRawTopLevelEntries(
                        $generationPath,
                        [],
                        $label . ' new-generation slots',
                    );
                    foreach ($isolatedSlots as $slot => $slotPath) {
                        if (!\in_array($slot, ['A', 'B'], true)
                            || !\is_dir($slotPath)
                            || \is_link($slotPath)
                        ) {
                            throw new \RuntimeException(
                                $label . ' contains an invalid isolated new slot.',
                            );
                        }
                        $collectTree(
                            $slotPath,
                            self::MAX_PACKAGE_COMPONENTS
                                + self::MAX_PACKAGE_DIRECTORIES + 4,
                        );
                    }
                    $append(GatewayBoundedTreeWalker::identity($generationPath));
                }
                $append(GatewayBoundedTreeWalker::identity($path));
                continue;
            }
            $allowedLeaves = match ($leaf) {
                'bin' => ['launcher'],
                'trust' => [
                    'active-slot',
                    'previous-slot',
                    'stable-launcher.sha256',
                ],
                'platform' => ['definition.before', 'metadata.before'],
                default => [],
            };
            $shallow = $this->rebootstrapRawTopLevelEntries(
                $path,
                [],
                $label . ' ' . $leaf,
            );
            foreach (\array_keys($shallow) as $child) {
                if (!\in_array($child, $allowedLeaves, true)) {
                    throw new \RuntimeException(
                        $label . ' contains an unexpected ' . $leaf
                            . ' backup entry.',
                    );
                }
            }
            $collectTree(
                $path,
                self::REBOOTSTRAP_DERIVED_TOP_LEVEL_MAX_ENTRIES,
            );
        }
        $append(GatewayBoundedTreeWalker::identity($root));
        return $records;
    }

    /** @return array<string,mixed> */
    private function finishRebootstrapTransaction(
        string $nonce,
        string $packageDigest,
        string $profile,
        string $expectedPhase,
        string $terminalPhase,
        bool $retainOldGeneration,
    ): array {
        $nonce = $this->normalizeRebootstrapNonce($nonce);
        $packageDigest = \strtolower(\trim($packageDigest));
        $profile = \strtolower(\trim($profile));
        $preparedCancellation = \hash_equals('ROLLING_BACK', $expectedPhase)
            && \hash_equals('ROLLED_BACK', $terminalPhase)
            && !$retainOldGeneration;
        return $this->withStagingLocks(['A', 'B'], function () use (
            $nonce,
            $packageDigest,
            $profile,
            $expectedPhase,
            $terminalPhase,
            $retainOldGeneration,
            $preparedCancellation,
        ): array {
            return $this->withInstallLock(function () use (
                $nonce,
                $packageDigest,
                $profile,
                $expectedPhase,
                $terminalPhase,
                $retainOldGeneration,
                $preparedCancellation,
            ): array {
                $journal = $this->readRebootstrapJournalLocked();
                if ($journal === null) {
                    $receipt = $this->readRebootstrapReceiptLocked($nonce);
                    if ($receipt === null) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap transaction and terminal receipt are missing.'
                        );
                    }
                    $this->assertSameRebootstrapRequest(
                        $receipt,
                        $nonce,
                        $packageDigest,
                        $profile,
                    );
                    if (!\hash_equals($terminalPhase, (string)$receipt['phase'])) {
                        throw new \RuntimeException(
                            'Gateway rebootstrap nonce already has a different terminal result.'
                        );
                    }
                    if ($preparedCancellation) {
                        $this->assertPreStopCancellationEvidenceLocked(
                            $receipt,
                            true,
                        );
                    }
                    $this->assertTerminalRebootstrapBackupStateLocked($receipt);
                    (new GatewayGuardianTransitionProtocol($this->paths))
                        ->retireHandshake($receipt);
                    $receipt = $this->readRebootstrapReceiptLocked($nonce)
                        ?? throw new \RuntimeException(
                            'Gateway rebootstrap terminal receipt disappeared during replay.',
                        );
                    return $this->publicRebootstrapJournal($receipt);
                }
                $this->assertSameRebootstrapRequest(
                    $journal,
                    $nonce,
                    $packageDigest,
                    $profile,
                );
                if ($preparedCancellation) {
                    $terminalCancellation = \hash_equals(
                        'ROLLED_BACK',
                        (string)$journal['phase'],
                    );
                    $this->assertPreStopCancellationEvidenceLocked(
                        $journal,
                        $terminalCancellation,
                    );
                    $this->assertPreStopCancellationTopologyLocked($journal);
                }
                if (!\hash_equals((string)$journal['phase'], $expectedPhase)
                    && !\hash_equals((string)$journal['phase'], $terminalPhase)
                ) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap cannot finish from phase '
                            . (string)$journal['phase'] . '.'
                    );
                }
                if (\hash_equals('COMMITTED', $terminalPhase)) {
                    (new GatewayGuardianTransitionProtocol($this->paths))
                        ->assertCommitAcknowledged(
                            $journal,
                            $this->activeOperationDeadline(),
                        );
                }
                if (\hash_equals('ROLLED_BACK', $terminalPhase)
                    && !$preparedCancellation) {
                    (new GatewayGuardianTransitionProtocol($this->paths))
                        ->assertRollbackAcknowledged(
                            $journal,
                            $this->activeOperationDeadline(),
                        );
                }
                if (!\hash_equals((string)$journal['phase'], $terminalPhase)) {
                    if ($retainOldGeneration) {
                        $this->assertPublishedRebootstrapGeneration($journal);
                        $this->assertRetainedRebootstrapBackup(
                            $journal,
                            $this->paths->rebootstrapBackupDir($nonce),
                        );
                        $retained = $this->monotonicClockMillisecondsNow();
                        if ($retained < 1
                            || $retained > PHP_INT_MAX
                                - self::REBOOTSTRAP_RETENTION_SECONDS * 1000
                        ) {
                            throw new \RuntimeException(
                                'Gateway rebootstrap retention clock is outside the supported range.'
                            );
                        }
                        $wall = \max(
                            $this->wallClockNow(),
                            (int)$journal['created_at'],
                        );
                        if ($wall > PHP_INT_MAX - self::REBOOTSTRAP_RETENTION_SECONDS) {
                            throw new \RuntimeException(
                                'Gateway rebootstrap retention wall timestamp is outside the supported range.'
                            );
                        }
                        $journal['retention_until'] = $wall
                            + self::REBOOTSTRAP_RETENTION_SECONDS;
                        $journal['retention_host_boot_id'] = $this->hostBootIdentityNow();
                        $journal['retained_monotonic_ms'] = $retained;
                        $journal['retention_deadline_monotonic_ms'] = $retained
                            + self::REBOOTSTRAP_RETENTION_SECONDS * 1000;
                        $journal['retained_backup_state'] = 'RETAINED';
                    } else {
                        $current = $this->verifiedRebootstrapOldGeneration();
                        $this->assertOldGenerationMatchesRebootstrapJournal(
                            $journal,
                            $current,
                        );
                        $journal['retention_until'] = 0;
                        $journal['retention_host_boot_id'] = '';
                        $journal['retained_monotonic_ms'] = 0;
                        $journal['retention_deadline_monotonic_ms'] = 0;
                        $backup = $this->paths->rebootstrapBackupDir($nonce);
                        if (\file_exists($backup) || \is_link($backup)) {
                            $this->assertRebootstrapPlatformBackup(
                                $journal,
                                $backup,
                            );
                            $journal['retained_backup_state'] = 'RETAINED';
                        } else {
                            $journal['retained_backup_state'] = 'COLLECTED';
                        }
                    }
                    if (\hash_equals('ROLLED_BACK', $terminalPhase)) {
                        $this->assertRebootstrapDerivedWorkingGenerationAbsent(
                            $journal,
                        );
                    }
                    if (!\hash_equals(
                            'ROLLED_BACK',
                            $terminalPhase,
                        )
                        || (string)$journal['admin_stopped_digest'] !== ''
                    ) {
                        $this->assertRebootstrapCapacityReleasedLocked($journal);
                    } elseif (!\in_array(
                        (string)$journal['capacity_reserve_state'],
                        ['NONE', 'RELEASED'],
                        true,
                    )) {
                        throw new \RuntimeException(
                            'Gateway pre-stop rollback retained a live capacity reserve.',
                        );
                    }
                    $journal['phase'] = $terminalPhase;
                    $journal['terminal_at'] = \max(
                        (int)$journal['created_at'],
                        (int)$journal['updated_at'],
                        $this->wallClockNow(),
                    );
                    $journal['capacity_evidence_state'] = \hash_equals(
                        'RELEASED',
                        (string)$journal['capacity_reserve_state'],
                    ) ? 'RETAINED' : 'NONE';
                    $journal = $this->writeRebootstrapJournalLocked($journal);
                    $this->injectRebootstrapCrashAfterPhase($terminalPhase);
                }
                $encoded = GatewayClient::canonicalJson($journal) . "\n";
                $receiptFile = $this->paths->rebootstrapReceiptFile($nonce);
                if (!\file_exists($receiptFile)
                    && !\is_link($receiptFile)
                    && GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                        $receiptFile,
                        self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                        'Gateway rebootstrap terminal receipt',
                    )
                ) {
                    GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                        $receiptFile,
                        self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                        'Gateway rebootstrap terminal receipt',
                    );
                }
                $existingReceipt = $this->readOptionalStableRegularFile(
                    $receiptFile,
                    self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                    'Gateway rebootstrap terminal receipt',
                );
                if ($existingReceipt === null) {
                    $this->atomicWrite($receiptFile, $encoded, 0600);
                    $this->injectRebootstrapCrash(
                        'after-receipt:' . $terminalPhase,
                    );
                } elseif (!\hash_equals($encoded, $existingReceipt)) {
                    throw new \RuntimeException(
                        'Gateway rebootstrap terminal receipt conflicts with the journal.'
                    );
                }
                $this->assertTerminalRebootstrapBackupStateLocked($journal);
                GatewayProjectStateFilesystem::removeRegular(
                    $this->paths->rebootstrapJournalFile(),
                    'terminal gateway rebootstrap journal',
                );
                // A stale authorization is inert once the bound journal is
                // absent. Remove it only after journal retirement so a crash
                // at a terminal publication boundary can still start the
                // exact terminal generation.
                $this->removeRebootstrapStartAuthorizationLocked();
                (new GatewayGuardianTransitionProtocol($this->paths))
                    ->retireHandshake($journal);
                return $this->publicRebootstrapJournal($journal) + [
                    'receipt' => $receiptFile,
                ];
            });
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withInstallLock(callable $callback): mixed
    {
        return $this->withInstallLockRaw(
            function () use ($callback): mixed {
                $this->cleanupHostAtomicWriteRecoveryBackupsLocked();
                return $callback();
            },
        );
    }

    /**
     * Acquire the already-established package lock without allocating or
     * cleaning any companion files. Physical capacity release must be able to
     * enter here even when the durable volume has no free blocks or inodes.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withInstallLockRaw(callable $callback): mixed
    {
        return $this->withHostPackageLock(
            'package-install.lock',
            'installation',
            $callback,
        );
    }

    /**
     * Recover every crash artifact created by a package-install.lock writer.
     * ReplaceFileW backups, PHP atomic-write temporaries and stable-launcher
     * candidates form one host-state closure: no artifact may be collected
     * until every reserved namespace, paired target and cross-target binding
     * has passed a stable no-follow inspection.
     */
    private function cleanupHostAtomicWriteRecoveryBackupsLocked(): void
    {
        $targets = $this->hostAtomicRecoveryTargets();
        $inventory = $this->discoverHostRecoveryArtifacts($targets);
        if ($this->discardRecoverableUnpairedHostFirstPublicationsLocked(
            $targets,
            $inventory,
        )) {
            $inventory = $this->discoverHostRecoveryArtifacts($targets);
        }
        if ($inventory['backups'] === []
            && $inventory['temporaries'] === []
            && $inventory['launcher_candidates'] === []
        ) {
            return;
        }

        $current = [];
        $currentIdentity = [];
        foreach ($targets as $key => $target) {
            $before = @\lstat($target['path']);
            $contents = $this->readHostRecoveryFileOptional(
                $target['path'],
                $target['maximum_bytes'],
                $target['mode'],
                $target['label'] . ' recovery target',
            );
            if ($contents === null) {
                if (isset($inventory['backups'][$key])
                    || isset($inventory['temporaries'][$key])
                ) {
                    throw new \RuntimeException(
                        $target['label']
                            . ' recovery artifact paired target is missing or unsafe.'
                    );
                }
                $currentIdentity[$key] = null;
                continue;
            }
            $after = @\lstat($target['path']);
            if (!\is_array($before)
                || !\is_array($after)
                || !$this->sameFileState($before, $after)
            ) {
                throw new \RuntimeException(
                    $target['label'] . ' recovery target changed during closure validation.'
                );
            }
            $this->validateHostRecoveryContents(
                $key,
                $contents,
                $target['label'] . ' recovery target',
            );
            $current[$key] = $contents;
            $currentIdentity[$key] = [
                'identity' => $after,
                'sha256' => \hash('sha256', $contents),
            ];
        }

        $backups = [];
        foreach ($inventory['backups'] as $key => $artifacts) {
            $target = $targets[$key];
            foreach ($artifacts as $artifact) {
                $before = @\lstat($artifact['path']);
                if (!\is_array($before)
                    || !$this->sameFileState($artifact['identity'], $before)
                ) {
                    throw new \RuntimeException(
                        $target['label'] . ' recovery backup changed before validation.'
                    );
                }
                $label = $target['label'] . ' recovery backup';
                $contents = $this->readHostRecoveryFile(
                    $artifact['path'],
                    $target['maximum_bytes'],
                    $target['mode'],
                    $label,
                );
                $after = @\lstat($artifact['path']);
                if (!\is_array($after)
                    || !$this->sameFileState($artifact['identity'], $after)
                ) {
                    throw new \RuntimeException(
                        $label . ' changed during closure validation.'
                    );
                }
                $this->validateHostRecoveryContents($key, $contents, $label);
                $backups[$key][] = [
                    'path' => $artifact['path'],
                    'contents' => $contents,
                    'label' => $label,
                ];
            }
        }
        $this->validateHostRecoveryClosure($current, $backups);
        $launcherPlan = $this->stableLauncherRecoveryPlan(
            $inventory['launcher_candidates'],
            $current,
        );

        $this->assertHostRecoveryClosureUnchanged(
            $targets,
            $currentIdentity,
            $inventory,
        );
        if ($launcherPlan['source'] !== null) {
            $this->copyStableLauncher(
                $launcherPlan['source'],
                $this->paths->launcherFile(),
                $launcherPlan['expected_digest'],
            );
            $inventory['directories'][\dirname($this->paths->launcherFile())]
                = $this->stableRecoveryDirectoryIdentity(
                    \dirname($this->paths->launcherFile()),
                    'Stable gateway launcher recovery directory',
                );
            $launcherPlan = $this->stableLauncherRecoveryPlan(
                $inventory['launcher_candidates'],
                $current,
            );
            if ($launcherPlan['source'] !== null) {
                throw new \RuntimeException(
                    'Stable gateway launcher recovery did not publish its trusted target.'
                );
            }
            $this->assertHostRecoveryClosureUnchanged(
                $targets,
                $currentIdentity,
                $inventory,
            );
        }
        $this->assertStableLauncherRecoveryTargetUnchanged($launcherPlan);
        $this->assertHostRecoveryUpgradeLauncherProofs($current, $backups);

        // The package lock fences all official writers. The second stable
        // closure check above is the final mutation barrier for untrusted
        // local path changes; removals remain bound to each selected inode.
        foreach (['backups', 'temporaries'] as $kind) {
            foreach ($inventory[$kind] as $key => $artifacts) {
                foreach ($artifacts as $artifact) {
                    $this->removeSelectedHostRecoveryArtifact(
                        $artifact['path'],
                        $targets[$key]['label'] . ' recovery artifact',
                        $artifact['identity'],
                    );
                }
            }
        }
        foreach ($inventory['launcher_candidates'] as $artifact) {
            $this->removeSelectedHostRecoveryArtifact(
                $artifact['path'],
                'Stable gateway launcher recovery candidate',
                $artifact['identity'],
            );
        }
    }

    /**
     * An interrupted first publication has no paired target by definition.
     * Only transaction files whose publication fences every later mutation,
     * plus a CA baseline owned by one of those committed transactions, may
     * discard staging-only evidence and retry.
     *
     * @param array<string,array{path:string,maximum_bytes:int,mode:int,label:string}> $targets
     * @param array<string,mixed> $inventory
     */
    private function discardRecoverableUnpairedHostFirstPublicationsLocked(
        array $targets,
        array $inventory,
    ): bool {
        $discarded = false;
        foreach (['failed-initial-cleanup', 'rebootstrap-journal'] as $key) {
            if (!isset($inventory['temporaries'][$key])) {
                continue;
            }
            if (isset($inventory['backups'][$key])
                || \file_exists($targets[$key]['path'])
                || \is_link($targets[$key]['path'])
            ) {
                continue;
            }
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $targets[$key]['path'],
                $targets[$key]['maximum_bytes'],
                $targets[$key]['label'],
            );
            $discarded = true;
        }
        if ($discarded) {
            $inventory = $this->discoverHostRecoveryArtifacts($targets);
        }

        if ($this->discardRebootstrapPublishedGenerationFirstPublicationsLocked(
            $targets,
            $inventory,
        )) {
            $discarded = true;
            $inventory = $this->discoverHostRecoveryArtifacts($targets);
        }

        $caKey = 'ca-bundle-baseline';
        if (!isset($inventory['temporaries'][$caKey])) {
            return $discarded;
        }
        if (isset($inventory['backups'][$caKey])
            || \file_exists($targets[$caKey]['path'])
            || \is_link($targets[$caKey]['path'])
        ) {
            return $discarded;
        }

        $owned = false;
        $failedIntent = $this->readOptionalStableRegularFile(
            $this->failedInitialCleanupIntentFile(),
            384,
            'Failed initial gateway transaction intent',
        );
        if ($failedIntent !== null) {
            $binding = $this->failedInitialRecoveryBinding($failedIntent);
            $owned = \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$binding['ca_bundle_sha256'],
            ) === 1;
        }
        if (!$owned) {
            $journal = $this->readRebootstrapJournalLocked();
            if ($journal !== null
                && (bool)$journal['trust_rotation']
                && (string)$journal['old_derived_manifest_sha256'] !== ''
                && \in_array((string)$journal['phase'], [
                    'OLD_GENERATION_STASHED',
                    'ROLLING_BACK',
                ], true)
            ) {
                $this->assertRetainedRebootstrapDerivedBackup(
                    $journal,
                    $this->paths->rebootstrapBackupDir(
                        (string)$journal['nonce'],
                    ),
                );
                $owned = true;
            }
        }
        if (!$owned) {
            throw new \RuntimeException(
                'Gateway CA trust baseline has unpaired staging without a committed transaction owner.',
            );
        }
        GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
            $targets[$caKey]['path'],
            $targets[$caKey]['maximum_bytes'],
            $targets[$caKey]['label'],
        );
        return true;
    }

    /**
     * Recover the two ordered first-publication trust leaves written after
     * the old whole generation has been durably stashed. The signed journal,
     * complete old backup and exact candidate-at-slot-A topology jointly own
     * these staging files; no other phase may discard them.
     *
     * @param array<string,array{path:string,maximum_bytes:int,mode:int,label:string}> $targets
     * @param array<string,mixed> $inventory
     */
    private function discardRebootstrapPublishedGenerationFirstPublicationsLocked(
        array $targets,
        array $inventory,
    ): bool {
        $identityKey = 'stable-launcher-identity';
        $activeKey = 'active-slot';
        if (!isset($inventory['temporaries'][$identityKey])
            && !isset($inventory['temporaries'][$activeKey])
        ) {
            return false;
        }
        $journal = $this->readRebootstrapJournalLocked();
        if ($journal === null
            || !\hash_equals(
                'OLD_GENERATION_STASHED',
                (string)$journal['phase'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway generation trust leaf has unpaired staging without an old-generation-stashed transaction.',
            );
        }
        $backup = $this->paths->rebootstrapBackupDir(
            (string)$journal['nonce'],
        );
        $this->assertRetainedRebootstrapBackup($journal, $backup);
        $this->assertPreparedRebootstrapCandidate($journal);
        $candidate = $this->paths->rebootstrapCandidateDir(
            (string)$journal['nonce'],
        );
        if (\file_exists($candidate)
            || \is_link($candidate)
            || !\is_dir($this->paths->slotDir('A'))
            || \is_link($this->paths->slotDir('A'))
        ) {
            throw new \RuntimeException(
                'Gateway generation trust staging is not paired with the published candidate topology.',
            );
        }

        $discarded = false;
        if (isset($inventory['temporaries'][$identityKey])) {
            if (isset($inventory['backups'][$identityKey])
                || \file_exists($targets[$identityKey]['path'])
                || \is_link($targets[$identityKey]['path'])
                || \file_exists($this->paths->activeSlotFile())
                || \is_link($this->paths->activeSlotFile())
            ) {
                throw new \RuntimeException(
                    'Gateway stable-launcher identity first-publication closure is inconsistent.',
                );
            }
            $launcher = $this->paths->launcherFile();
            if (\file_exists($launcher) || \is_link($launcher)) {
                $this->assertRebootstrapRegularFile(
                    $launcher,
                    (string)$journal['candidate_launcher_sha256'],
                    (int)$journal['candidate_launcher_size'],
                    (int)$journal['candidate_launcher_mode'],
                    'partially published stable gateway launcher',
                );
            }
            $baseline = $this->paths->caBundleBaselineFile();
            if (\file_exists($baseline) || \is_link($baseline)) {
                $contents = (string)$journal['candidate_ca_bundle_sha256'] . "\n";
                $this->assertRebootstrapRegularFile(
                    $baseline,
                    \hash('sha256', $contents),
                    \strlen($contents),
                    0600,
                    'partially published gateway CA trust baseline',
                );
            }
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $targets[$identityKey]['path'],
                $targets[$identityKey]['maximum_bytes'],
                $targets[$identityKey]['label'],
            );
            $discarded = true;
        }

        if (isset($inventory['temporaries'][$activeKey])) {
            if (isset($inventory['backups'][$activeKey])
                || \file_exists($targets[$activeKey]['path'])
                || \is_link($targets[$activeKey]['path'])
            ) {
                throw new \RuntimeException(
                    'Gateway active-slot first-publication closure is inconsistent.',
                );
            }
            $proof = $this->verifiedStableLauncherUpgradeProof(
                ['A'],
                'Gateway active-slot first-publication recovery',
            );
            if (!\hash_equals(
                    (string)$journal['candidate_launcher_sha256'],
                    (string)$proof['launcher_sha256'],
                )
                || (int)$journal['candidate_launcher_size']
                    !== (int)$proof['launcher_size']
                || !\hash_equals(
                    (string)$journal['candidate_ca_bundle_sha256'],
                    (string)$proof['ca_bundle_sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway active-slot first-publication proof differs from the signed rebootstrap generation.',
                );
            }
            GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                $targets[$activeKey]['path'],
                $targets[$activeKey]['maximum_bytes'],
                $targets[$activeKey]['label'],
            );
            $discarded = true;
        }
        return $discarded;
    }

    /**
     * @param array<string,array{path:string,maximum_bytes:int,mode:int,label:string}> $targets
     * @return array{
     *   directories:array<string,array<string|int,mixed>>,
     *   backups:array<string,list<array{path:string,identity:array<string|int,mixed>,sha256:string}>>,
     *   temporaries:array<string,list<array{path:string,identity:array<string|int,mixed>,sha256:string}>>,
     *   launcher_candidates:list<array{path:string,identity:array<string|int,mixed>,sha256:string}>
     * }
     */
    private function discoverHostRecoveryArtifacts(array $targets): array
    {
        $namespaces = [];
        foreach ($targets as $key => $target) {
            $directory = \dirname($target['path']);
            $leaf = \basename(\str_replace('\\', '/', $target['path']));
            $namespaces[$directory][] = [
                'key' => $key,
                'kind' => 'backups',
                'prefix' => $leaf . '.wls-backup-',
                'suffix' => '[a-f0-9]{16}',
                'maximum_bytes' => $target['maximum_bytes'],
                'mode' => $target['mode'],
                'allow_empty_unsealed' => false,
                'label' => $target['label'] . ' recovery backup',
                'quota' => self::MAX_ATOMIC_RECOVERY_BACKUPS_PER_TARGET,
                'quota_label' => 'recovery backup quota',
            ];
            $namespaces[$directory][] = [
                'key' => $key,
                'kind' => 'temporaries',
                'prefix' => $leaf . '.tmp-',
                'suffix' => '[a-f0-9]{24}',
                'maximum_bytes' => $target['maximum_bytes'],
                'mode' => $target['mode'],
                'allow_empty_unsealed' => true,
                'label' => $target['label'] . ' recovery temporary',
                'quota' => self::MAX_ATOMIC_RECOVERY_TEMPORARIES_PER_TARGET,
                'quota_label' => 'recovery temporary quota',
            ];
        }
        $launcher = $this->paths->launcherFile();
        $namespaces[\dirname($launcher)][] = [
            'key' => 'stable-launcher',
            'kind' => 'launcher_candidates',
            'prefix' => \basename(\str_replace('\\', '/', $launcher)) . '.candidate.',
            'suffix' => '[a-f0-9]{16}',
            'maximum_bytes' => self::MAX_PACKAGE_BYTES,
            'mode' => null,
            'allow_empty_unsealed' => false,
            'label' => 'Stable gateway launcher recovery candidate',
            'quota' => self::MAX_STABLE_LAUNCHER_CANDIDATES,
            'quota_label' => 'recovery candidate quota',
        ];

        $inventory = [
            'directories' => [],
            'backups' => [],
            'temporaries' => [],
            'launcher_candidates' => [],
        ];
        foreach ($namespaces as $directory => $definitions) {
            $directoryBefore = $this->stableRecoveryDirectoryIdentity(
                $directory,
                'Gateway host recovery directory',
            );
            $handle = @\opendir($directory);
            if (!\is_resource($handle)) {
                throw new \RuntimeException(
                    'Unable to enumerate the gateway host recovery directory.'
                );
            }
            $selected = [];
            $counts = [];
            $visited = 0;
            try {
                while (($leaf = @\readdir($handle)) !== false) {
                    if (++$visited > self::MAX_ATOMIC_RECOVERY_DIRECTORY_ENTRIES) {
                        throw new \RuntimeException(
                            'Gateway host recovery directory exceeds its fixed raw entry quota.'
                        );
                    }
                    foreach ($definitions as $definition) {
                        $reserved = \PHP_OS_FAMILY === 'Windows'
                            ? \str_starts_with(
                                \strtolower($leaf),
                                \strtolower($definition['prefix']),
                            )
                            : \str_starts_with($leaf, $definition['prefix']);
                        if (!$reserved) {
                            continue;
                        }
                        $pattern = '/\A' . \preg_quote($definition['prefix'], '/')
                            . $definition['suffix'] . '\z/D';
                        if (\preg_match($pattern, $leaf) !== 1) {
                            throw new \RuntimeException(
                                $definition['label']
                                    . ' namespace contains a malformed reserved leaf.'
                            );
                        }
                        $countKey = $definition['kind'] . ':' . $definition['key'];
                        $counts[$countKey] = ($counts[$countKey] ?? 0) + 1;
                        if ($counts[$countKey] > $definition['quota']) {
                            throw new \RuntimeException(
                                $definition['label'] . ' ' . $definition['quota_label']
                                    . ' is exhausted.'
                            );
                        }
                        $selected[] = [
                            'definition' => $definition,
                            'path' => $directory . DIRECTORY_SEPARATOR . $leaf,
                        ];
                        break;
                    }
                }
            } finally {
                @\closedir($handle);
            }
            \usort(
                $selected,
                static fn (array $left, array $right): int =>
                    \strcmp($left['path'], $right['path']),
            );
            foreach ($selected as $selection) {
                $definition = $selection['definition'];
                $artifact = $this->inspectHostRecoveryArtifact(
                    $selection['path'],
                    $definition['maximum_bytes'],
                    $definition['label'],
                    $definition['mode'],
                    $definition['allow_empty_unsealed'],
                );
                if ($definition['kind'] === 'launcher_candidates') {
                    $inventory['launcher_candidates'][] = $artifact;
                } else {
                    $inventory[$definition['kind']][$definition['key']][] = $artifact;
                }
            }
            $directoryAfter = $this->stableRecoveryDirectoryIdentity(
                $directory,
                'Gateway host recovery directory',
            );
            if (!$this->sameFileState($directoryBefore, $directoryAfter)) {
                throw new \RuntimeException(
                    'Gateway host recovery directory changed during discovery.'
                );
            }
            $inventory['directories'][$directory] = $directoryAfter;
        }
        return $inventory;
    }

    /** @return array<string|int,mixed> */
    private function stableRecoveryDirectoryIdentity(string $path, string $label): array
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException($label . ' is linked, missing, or special.');
        }
        return $status;
    }

    /**
     * @return array{path:string,identity:array<string|int,mixed>,sha256:string}
     */
    private function inspectHostRecoveryArtifact(
        string $path,
        int $maximumBytes,
        string $label,
        ?int $requiredMode,
        bool $allowEmptyUnsealed,
    ): array {
        $parent = \dirname($path);
        $parentBefore = $this->stableRecoveryDirectoryIdentity(
            $parent,
            $label . ' parent',
        );
        $before = @\lstat($path);
        if (!\is_array($before)
            || \is_link($path)
            || !$this->isRegularFileStatus($before)
            || (int)$before['nlink'] !== 1
            || (int)$before['size'] < 0
            || (int)$before['size'] > $maximumBytes
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((int)$before['uid'] !== (int)$parentBefore['uid']
                    || (int)$before['gid'] !== (int)$parentBefore['gid']
                    || ($requiredMode !== null
                        ? (!$allowEmptyUnsealed || (int)$before['size'] > 0
                            ? (((int)$before['mode']) & 0777) !== $requiredMode
                            : (((int)$before['mode']) & 0022) !== 0)
                        : (((int)$before['mode']) & 0022) !== 0)))
        ) {
            throw new \RuntimeException($label . ' has unsafe authority or permissions.');
        }
        $digest = $this->digestStableRegularFile($path, $maximumBytes, $label);
        $after = @\lstat($path);
        $parentAfter = $this->stableRecoveryDirectoryIdentity(
            $parent,
            $label . ' parent',
        );
        if (!\is_array($after)
            || !$this->sameFileState($before, $after)
            || !$this->sameFileState($parentBefore, $parentAfter)
        ) {
            throw new \RuntimeException($label . ' changed during inspection.');
        }
        return [
            'path' => $path,
            'identity' => $after,
            'sha256' => $digest['sha256'],
        ];
    }

    /**
     * @param list<array{path:string,identity:array<string|int,mixed>,sha256:string}> $candidates
     * @param array<string,string> $current
     * @return array{
     *   source:?string,
     *   expected_digest:string,
     *   target_identity:?array<string|int,mixed>
     * }
     */
    private function stableLauncherRecoveryPlan(array $candidates, array $current): array
    {
        if ($candidates === []) {
            return [
                'source' => null,
                'expected_digest' => '',
                'target_identity' => null,
            ];
        }
        $identity = \strtolower(\trim((string)(
            $current['stable-launcher-identity'] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1) {
            throw new \RuntimeException(
                'Stable gateway launcher identity cannot authorize candidate recovery.'
            );
        }

        $target = $this->paths->launcherFile();
        $targetStatus = @\lstat($target);
        if (\is_array($targetStatus)) {
            $actual = $this->digestStableRegularFile(
                $target,
                self::MAX_PACKAGE_BYTES,
                'Stable gateway launcher recovery target',
            );
            $this->assertStableLauncherPermissions($target);
            if (!\hash_equals($identity, $actual['sha256'])) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity recovery target is invalid.'
                );
            }
            $targetAfter = @\lstat($target);
            if (!\is_array($targetAfter)
                || !$this->sameFileState($targetStatus, $targetAfter)
            ) {
                throw new \RuntimeException(
                    'Stable gateway launcher recovery target changed during validation.'
                );
            }
            return [
                'source' => null,
                'expected_digest' => $identity,
                'target_identity' => $targetAfter,
            ];
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'Stable gateway launcher recovery target is indeterminate or unsafe.'
            );
        }
        foreach ($candidates as $candidate) {
            if (\hash_equals($identity, $candidate['sha256'])) {
                return [
                    'source' => $candidate['path'],
                    'expected_digest' => $identity,
                    'target_identity' => null,
                ];
            }
        }
        $source = $this->launcherSourceBackedByInstalledSlot($identity);
        if ($source !== null) {
            return [
                'source' => $source,
                'expected_digest' => $identity,
                'target_identity' => null,
            ];
        }
        throw new \RuntimeException(
            'Stable gateway launcher candidates cannot reconstruct the trusted target.'
        );
    }

    /**
     * @param array{
     *   source:?string,
     *   expected_digest:string,
     *   target_identity:?array<string|int,mixed>
     * } $plan
     */
    private function assertStableLauncherRecoveryTargetUnchanged(array $plan): void
    {
        if ($plan['target_identity'] === null) {
            return;
        }
        $target = $this->paths->launcherFile();
        $actual = @\lstat($target);
        if (!\is_array($actual)
            || !$this->sameFileState($plan['target_identity'], $actual)
        ) {
            throw new \RuntimeException(
                'Stable gateway launcher recovery target changed before cleanup.'
            );
        }
        $digest = $this->digestStableRegularFile(
            $target,
            self::MAX_PACKAGE_BYTES,
            'Stable gateway launcher recovery target',
        );
        $this->assertStableLauncherPermissions($target);
        if (!\hash_equals($plan['expected_digest'], $digest['sha256'])) {
            throw new \RuntimeException(
                'Stable gateway launcher recovery target changed before cleanup.'
            );
        }
    }

    /**
     * @param array<string,array{path:string,maximum_bytes:int,mode:int,label:string}> $targets
     * @param array<string,array{identity:array<string|int,mixed>,sha256:string}|null> $currentIdentity
     * @param array{
     *   directories:array<string,array<string|int,mixed>>,
     *   backups:array<string,list<array{path:string,identity:array<string|int,mixed>,sha256:string}>>,
     *   temporaries:array<string,list<array{path:string,identity:array<string|int,mixed>,sha256:string}>>,
     *   launcher_candidates:list<array{path:string,identity:array<string|int,mixed>,sha256:string}>
     * } $inventory
     */
    private function assertHostRecoveryClosureUnchanged(
        array $targets,
        array $currentIdentity,
        array $inventory,
    ): void {
        foreach ($inventory['directories'] as $path => $expected) {
            $actual = $this->stableRecoveryDirectoryIdentity(
                $path,
                'Gateway host recovery directory',
            );
            if (!$this->sameFileState($expected, $actual)) {
                throw new \RuntimeException(
                    'Gateway host recovery directory changed before cleanup.'
                );
            }
        }
        foreach ($targets as $key => $target) {
            $snapshot = $currentIdentity[$key] ?? null;
            if ($snapshot === null) {
                if (\is_array(@\lstat($target['path']))
                    || \file_exists($target['path'])
                    || \is_link($target['path'])
                ) {
                    throw new \RuntimeException(
                        $target['label'] . ' recovery target appeared before cleanup.'
                    );
                }
                continue;
            }
            $actual = @\lstat($target['path']);
            if (!\is_array($actual)
                || !$this->sameFileState($snapshot['identity'], $actual)
            ) {
                throw new \RuntimeException(
                    $target['label'] . ' recovery target changed before cleanup.'
                );
            }
            $contents = $this->readHostRecoveryFile(
                $target['path'],
                $target['maximum_bytes'],
                $target['mode'],
                $target['label'] . ' recovery target',
            );
            if (!\hash_equals($snapshot['sha256'], \hash('sha256', $contents))) {
                throw new \RuntimeException(
                    $target['label'] . ' recovery target changed before cleanup.'
                );
            }
        }
        foreach (['backups', 'temporaries', 'launcher_candidates'] as $kind) {
            $sets = $kind === 'launcher_candidates'
                ? [$inventory[$kind]]
                : $inventory[$kind];
            foreach ($sets as $artifacts) {
                foreach ($artifacts as $artifact) {
                    $actual = @\lstat($artifact['path']);
                    if (!\is_array($actual)
                        || !$this->sameFileState($artifact['identity'], $actual)
                    ) {
                        throw new \RuntimeException(
                            'Gateway host recovery artifact changed before cleanup.'
                        );
                    }
                }
            }
        }
    }

    /** @param array<string|int,mixed> $identity */
    private function removeSelectedHostRecoveryArtifact(
        string $path,
        string $label,
        array $identity,
    ): void {
        $actual = @\lstat($path);
        if (!\is_array($actual) || !$this->sameFileState($identity, $actual)) {
            throw new \RuntimeException($label . ' changed before collection.');
        }
        if (!GatewayProjectStateFilesystem::removeRegular($path, $label, $identity)) {
            throw new \RuntimeException('Unable to collect ' . $label . '.');
        }
    }

    /**
     * @return array<string,array{
     *   path:string,
     *   maximum_bytes:int,
     *   mode:int,
     *   label:string
     * }>
     */
    private function hostAtomicRecoveryTargets(): array
    {
        return [
            'active-slot' => [
                'path' => $this->paths->activeSlotFile(),
                'maximum_bytes' => 2,
                'mode' => 0640,
                'label' => 'Gateway active-slot',
            ],
            'previous-slot' => [
                'path' => $this->paths->previousSlotFile(),
                'maximum_bytes' => 2,
                'mode' => 0640,
                'label' => 'Gateway previous-slot',
            ],
            'host-id' => [
                'path' => $this->paths->hostIdFile(),
                'maximum_bytes' => 33,
                'mode' => 0600,
                'label' => 'Gateway host identity',
            ],
            'admin-token' => [
                'path' => $this->paths->adminTokenFile(),
                'maximum_bytes' => 65,
                'mode' => 0600,
                'label' => 'Gateway administrator credential',
            ],
            'stable-launcher-identity' => [
                'path' => $this->paths->trustDir() . DIRECTORY_SEPARATOR
                    . 'stable-launcher.sha256',
                'maximum_bytes' => 65,
                'mode' => 0600,
                'label' => 'Stable gateway launcher identity',
            ],
            'guardian-identity' => [
                'path' => $this->paths->guardianDigestFile(),
                'maximum_bytes' => 65,
                'mode' => 0600,
                'label' => 'Recovery Guardian identity',
            ],
            'ca-bundle-baseline' => [
                'path' => $this->paths->caBundleBaselineFile(),
                'maximum_bytes' => 65,
                'mode' => 0600,
                'label' => 'Gateway CA trust baseline',
            ],
            'upgrade-intent' => [
                'path' => $this->paths->upgradeIntentFile(),
                'maximum_bytes' => 4096,
                'mode' => 0600,
                'label' => 'Gateway upgrade intent',
            ],
            'upgrade-rollback-request' => [
                'path' => $this->paths->stateDir() . DIRECTORY_SEPARATOR
                    . 'upgrade-rollback.request',
                'maximum_bytes' => 512,
                'mode' => 0600,
                'label' => 'Gateway upgrade rollback request',
            ],
            'slot-retention' => [
                'path' => $this->paths->trustDir() . DIRECTORY_SEPARATOR
                    . 'slot-retention',
                'maximum_bytes' => 512,
                'mode' => 0600,
                'label' => 'Gateway slot retention marker',
            ],
            'failed-initial-cleanup' => [
                'path' => $this->failedInitialCleanupIntentFile(),
                'maximum_bytes' => 384,
                'mode' => 0600,
                'label' => 'Failed initial gateway cleanup intent',
            ],
            'rebootstrap-journal' => [
                'path' => $this->paths->rebootstrapJournalFile(),
                'maximum_bytes' => self::REBOOTSTRAP_JOURNAL_MAX_BYTES,
                'mode' => 0600,
                'label' => 'Gateway rebootstrap journal',
            ],
            'rebootstrap-start-authorization' => [
                'path' => $this->paths->rebootstrapStartAuthorizationFile(),
                'maximum_bytes' => self::REBOOTSTRAP_START_AUTHORIZATION_MAX_BYTES,
                'mode' => 0600,
                'label' => 'Gateway native rebootstrap start authorization',
            ],
        ];
    }

    private function readHostRecoveryFileOptional(
        string $path,
        int $maximumBytes,
        int $mode,
        string $label,
    ): ?string {
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException($label . ' path is indeterminate or unsafe.');
            }
            return null;
        }
        return $this->readHostRecoveryFile($path, $maximumBytes, $mode, $label);
    }

    private function readHostRecoveryFile(
        string $path,
        int $maximumBytes,
        int $mode,
        string $label,
    ): string {
        $before = $this->assertHostRecoveryFileAuthority($path, $mode, $label);
        $contents = $this->readStableRegularFile($path, $maximumBytes, $label);
        $after = $this->assertHostRecoveryFileAuthority($path, $mode, $label);
        if (!$this->sameFileState($before, $after)
            || (int)($before['uid'] ?? -1) !== (int)($after['uid'] ?? -1)
            || (int)($before['gid'] ?? -1) !== (int)($after['gid'] ?? -1)
        ) {
            throw new \RuntimeException($label . ' changed during authority validation.');
        }
        return $contents;
    }

    /** @return array<string|int,mixed> */
    private function assertHostRecoveryFileAuthority(
        string $path,
        int $mode,
        string $label,
    ): array {
        $parent = \dirname($path);
        $parentStatus = @\lstat($parent);
        $status = @\lstat($path);
        if (!\is_array($parentStatus)
            || \is_link($parent)
            || ((((int)$parentStatus['mode']) & 0170000) !== 0040000)
            || !\is_array($status)
            || \is_link($path)
            || !$this->isRegularFileStatus($status)
            || (int)$status['nlink'] !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && (!\in_array(
                        ((int)$status['mode']) & 0777,
                        $this->hostRecoveryAllowedPosixModes($path, $mode),
                        true,
                    )
                    || (int)$status['uid'] !== (int)$parentStatus['uid']
                    || (int)$status['gid'] !== (int)$parentStatus['gid']))
        ) {
            throw new \RuntimeException($label . ' has unsafe authority or permissions.');
        }
        return $status;
    }

    /** @return list<int> */
    private function hostRecoveryAllowedPosixModes(
        string $path,
        int $declaredMode,
    ): array {
        $modes = [$declaredMode];
        if ($this->paths->isTestMode()
            || !$this->samePlatformPath(
                \dirname($path),
                $this->paths->trustDir(),
            )
        ) {
            return $modes;
        }
        $leaf = \basename(\str_replace('\\', '/', $path));
        $leaf = (string)\preg_replace(
            '/\.(?:wls-backup-[a-f0-9]{16}|tmp-[a-f0-9]{24})\z/D',
            '',
            $leaf,
        );
        $rootOnly = [
            'broker-enrollments.tsv',
            'broker-security-v2.tsv',
            'package-install.lock',
            'platform-definition.transaction',
            'systemd-layout-migration.transaction',
            'rebootstrap.transaction',
            'rebootstrap-start.authorization',
            'launcher-recovery.ledger',
            'stable-launcher.sha256',
            'guardian.sha256',
            'ca-bundle.sha256',
            'failed-initial-cleanup.intent',
        ];
        if (!\in_array($leaf, $rootOnly, true)) {
            $modes[] = 0440;
        }
        return \array_values(\array_unique($modes));
    }

    private function validateHostRecoveryContents(
        string $key,
        string $contents,
        string $label,
    ): void {
        try {
            switch ($key) {
                case 'active-slot':
                case 'previous-slot':
                    if (\preg_match('/\A[AB]\n\z/D', $contents) !== 1) {
                        throw new \RuntimeException('Slot pointer syntax is invalid.');
                    }
                    return;
                case 'host-id':
                    if (\preg_match('/\A[a-f0-9]{32}\n\z/D', $contents) !== 1) {
                        throw new \RuntimeException('Host identity syntax is invalid.');
                    }
                    return;
                case 'admin-token':
                case 'stable-launcher-identity':
                case 'guardian-identity':
                case 'ca-bundle-baseline':
                    if (\preg_match('/\A[a-f0-9]{64}\n\z/D', $contents) !== 1) {
                        throw new \RuntimeException('Digest identity syntax is invalid.');
                    }
                    return;
                case 'upgrade-intent':
                    $this->upgradeIntentBinding($contents);
                    return;
                case 'upgrade-rollback-request':
                    $this->upgradeRollbackRecoveryBinding($contents);
                    return;
                case 'slot-retention':
                    $this->slotRetentionRecoveryBinding($contents);
                    return;
                case 'failed-initial-cleanup':
                    $this->failedInitialRecoveryBinding($contents);
                    return;
                case 'rebootstrap-journal':
                    $this->decodeRebootstrapDocument(
                        $contents,
                        'Gateway rebootstrap recovery document',
                    );
                    return;
                case 'rebootstrap-start-authorization':
                    $this->decodeRebootstrapStartAuthorization($contents);
                    return;
                default:
                    throw new \LogicException('Unknown host recovery target.');
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                $label . ' is invalid: ' . $throwable->getMessage(),
                0,
                $throwable,
            );
        }
    }

    /** @return array<string,string> */
    private function decodeRebootstrapStartAuthorization(
        string $contents,
    ): array {
        $matched = \preg_match(
            '/\AWLS-REBOOTSTRAP-START\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'purpose=(forward|rollback)\n'
                . 'journal_sha256_primary=([a-f0-9]{64})\n'
                . 'journal_sha256_secondary=([a-f0-9]{64})\n'
                . 'active_slot=([AB])\n'
                . 'runtime_generation=([a-f0-9]{64})\n'
                . 'stable_launcher_sha256=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new \RuntimeException(
                'Gateway rebootstrap start authorization syntax is invalid.',
            );
        }
        $signatureOffset = \strrpos($contents, 'signature=');
        if (!\is_int($signatureOffset)) {
            throw new \RuntimeException(
                'Gateway rebootstrap start authorization signature is absent.',
            );
        }
        $key = $this->administratorHmacKey(
            'verify the native gateway rebootstrap start authorization',
        );
        try {
            $expected = \hash_hmac(
                'sha256',
                \substr($contents, 0, $signatureOffset),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals($expected, (string)$matches[9])) {
            throw new \RuntimeException(
                'Gateway rebootstrap start authorization authentication failed.',
            );
        }
        return [
            'host_id' => (string)$matches[1],
            'nonce' => (string)$matches[2],
            'purpose' => (string)$matches[3],
            'journal_sha256_primary' => (string)$matches[4],
            'journal_sha256_secondary' => (string)$matches[5],
            'active_slot' => (string)$matches[6],
            'runtime_generation' => (string)$matches[7],
            'stable_launcher_sha256' => (string)$matches[8],
        ];
    }

    /** @return array{from:string,to:string,at:int} */
    private function upgradeRollbackRecoveryBinding(string $contents): array
    {
        if (\preg_match(
            '/\AWLS-UPGRADE-ROLLBACK\/3\n'
                . 'intent_sha256=[a-f0-9]{64}\n'
                . 'intent_nonce=[a-f0-9]{32}\n'
                . 'from=([AB])\nto=([AB])\n'
                . 'host_boot_id=[a-f0-9]{64}\n'
                . 'requested_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'request_nonce=[a-f0-9]{32}\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $requested = $this->boundedDecimalInteger((string)$matches[3]);
            if ($requested === null
                || \hash_equals((string)$matches[1], (string)$matches[2])
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade rollback request is malformed or bound to another transaction.'
                );
            }
            return [
                'from' => (string)$matches[1],
                'to' => (string)$matches[2],
                // Compatibility name used only by recovery-closure callers.
                'at' => $requested,
            ];
        }
        if (\preg_match(
            '/\AWLS-UPGRADE-ROLLBACK\/2\n'
                . 'intent_sha256=[a-f0-9]{64}\n'
                . 'intent_nonce=[a-f0-9]{32}\n'
                . 'from=([AB])\nto=([AB])\n'
                . 'at=([1-9][0-9]{0,18})\n'
                . 'request_nonce=[a-f0-9]{32}\n\z/D',
            $contents,
            $matches,
        ) !== 1
            || \hash_equals((string)$matches[1], (string)$matches[2])
        ) {
            throw new \RuntimeException(
                'Gateway upgrade rollback request is malformed or bound to another transaction.'
            );
        }
        $at = $this->boundedDecimalInteger((string)$matches[3]);
        if ($at === null || $at <= 0) {
            throw new \RuntimeException('Gateway rollback request time is invalid.');
        }
        return [
            'from' => (string)$matches[1],
            'to' => (string)$matches[2],
            'at' => $at,
        ];
    }

    /** @return array{slot:string} */
    private function slotRetentionRecoveryBinding(string $contents): array
    {
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\/3\n'
                . 'intent_sha256=[a-f0-9]{64}\n'
                . 'intent_nonce=[a-f0-9]{32}\n'
                . 'slot=([AB])\n'
                . 'boot_id=[0-9A-Za-z-]{1,64}\n'
                . 'retained_at=([1-9][0-9]{0,18})\n'
                . 'retain_until=([1-9][0-9]{0,18})\n'
                . 'retained_since_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'retain_until_monotonic_ms=([1-9][0-9]{0,18})\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $retainedAt = $this->boundedDecimalInteger((string)$matches[2]);
            $retainUntil = $this->boundedDecimalInteger((string)$matches[3]);
            $retainedMonotonic = $this->boundedDecimalInteger((string)$matches[4]);
            $retainUntilMonotonic = $this->boundedDecimalInteger((string)$matches[5]);
            if ($retainedAt === null
                || $retainUntil === null
                || $retainedMonotonic === null
                || $retainUntilMonotonic === null
                || $retainedAt > PHP_INT_MAX - self::SLOT_RETENTION_SECONDS
                || $retainUntil !== $retainedAt + self::SLOT_RETENTION_SECONDS
                || $retainedMonotonic
                    > PHP_INT_MAX - self::SLOT_RETENTION_MILLISECONDS
                || $retainUntilMonotonic
                    !== $retainedMonotonic + self::SLOT_RETENTION_MILLISECONDS
            ) {
                throw new \RuntimeException('Gateway slot retention window is invalid.');
            }
            return ['slot' => (string)$matches[1]];
        }
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\/2\n'
                . 'intent_sha256=[a-f0-9]{64}\n'
                . 'intent_nonce=[a-f0-9]{32}\n'
                . 'slot=([AB])\nretain_until=([1-9][0-9]{0,18})\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            if ($this->boundedDecimalInteger((string)$matches[2]) === null) {
                throw new \RuntimeException('Gateway slot retention time is invalid.');
            }
            return ['slot' => (string)$matches[1]];
        }
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\/1\n'
                . 'slot=([AB])\nretain_until=([1-9][0-9]{0,18})\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            if ($this->boundedDecimalInteger((string)$matches[2]) === null) {
                throw new \RuntimeException('Gateway slot retention time is invalid.');
            }
            return ['slot' => (string)$matches[1]];
        }
        throw new \RuntimeException('Gateway slot retention syntax is incomplete or invalid.');
    }

    /**
     * @return array{
     *   mode:string,
     *   slot:string,
     *   launcher_sha256:string,
     *   ca_bundle_sha256:string,
     *   nonce:string
     * }
     */
    private function failedInitialRecoveryBinding(string $contents): array
    {
        if (\preg_match(
            '/\AWLS-FAILED-INITIAL-CLEANUP\/2\n'
                . 'mode=(activate|cleanup)\n'
                . 'slot=([AB])\n'
                . 'launcher_sha256=([a-f0-9]{64})\n'
                . 'ca_bundle_sha256=([a-f0-9]{64})\n'
                . 'nonce=([a-f0-9]{16})\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            return [
                'mode' => (string)$matches[1],
                'slot' => (string)$matches[2],
                'launcher_sha256' => (string)$matches[3],
                'ca_bundle_sha256' => (string)$matches[4],
                'nonce' => (string)$matches[5],
            ];
        }
        if (\preg_match(
            '/\AWLS-FAILED-INITIAL-CLEANUP\/1\n'
                . 'slot=([AB])\n'
                . 'launcher_sha256=([a-f0-9]{64})\n'
                . 'nonce=([a-f0-9]{16})\n\z/D',
            $contents,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Failed initial gateway cleanup intent syntax is invalid.'
            );
        }
        return [
            'mode' => 'cleanup',
            'slot' => (string)$matches[1],
            'launcher_sha256' => (string)$matches[2],
            'ca_bundle_sha256' => '',
            'nonce' => (string)$matches[3],
        ];
    }

    /**
     * @param array<string,string> $current
     * @param array<string,list<array{path:string,contents:string,label:string}>> $backups
     */
    private function validateHostRecoveryClosure(array $current, array $backups): void
    {
        $targets = $this->hostAtomicRecoveryTargets();
        foreach (['active-slot', 'previous-slot'] as $key) {
            if (isset($current[$key])) {
                $this->assertHostRecoverySlotArtifact(
                    \trim($current[$key]),
                    $targets[$key]['label'] . ' recovery target',
                );
            }
            foreach ($backups[$key] ?? [] as $backup) {
                $this->assertHostRecoverySlotArtifact(
                    \trim($backup['contents']),
                    $backup['label'],
                );
            }
        }

        $active = isset($current['active-slot'])
            ? \trim($current['active-slot'])
            : null;
        $previous = isset($current['previous-slot'])
            ? \trim($current['previous-slot'])
            : null;
        $intentBinding = null;
        if (isset($current['upgrade-intent'])) {
            $intentBinding = $this->upgradeIntentBinding($current['upgrade-intent']);
            if ($active === null) {
                throw new \RuntimeException(
                    'Gateway upgrade intent recovery target is invalid.'
                );
            }
            $this->assertHostRecoveryIntentArtifacts(
                $intentBinding,
                'Gateway upgrade intent recovery target',
            );
        } elseif ($active !== null
            && $previous !== null
            && \hash_equals($active, $previous)
        ) {
            throw new \RuntimeException(
                'Gateway slot pointer recovery closure is invalid.'
            );
        }
        if ($previous !== null
            && $active === null
            && !isset($current['failed-initial-cleanup'])
        ) {
            throw new \RuntimeException(
                'Gateway previous-slot recovery target is invalid.'
            );
        }

        foreach ($backups['upgrade-intent'] ?? [] as $backup) {
            $binding = $this->upgradeIntentBinding($backup['contents']);
            $this->assertHostRecoveryIntentArtifacts($binding, $backup['label']);
        }

        if (isset($current['upgrade-rollback-request'])) {
            if ($intentBinding === null) {
                throw new \RuntimeException(
                    'Gateway upgrade rollback request recovery target is invalid.'
                );
            }
            $this->validateUpgradeRollbackRequest(
                $current['upgrade-rollback-request'],
                $intentBinding,
                $intentBinding['to'],
                $intentBinding['from'],
            );
        }
        foreach ($backups['upgrade-rollback-request'] ?? [] as $backup) {
            if ($intentBinding === null) {
                throw new \RuntimeException($backup['label'] . ' is invalid.');
            }
            $this->validateUpgradeRollbackRequest(
                $backup['contents'],
                $intentBinding,
                $intentBinding['to'],
                $intentBinding['from'],
            );
        }

        if (isset($current['slot-retention'])) {
            $retention = $this->slotRetentionRecoveryBinding(
                $current['slot-retention'],
            );
            $this->assertHostRecoverySlotArtifact(
                $retention['slot'],
                'Gateway slot retention marker recovery target',
            );
        }
        foreach ($backups['slot-retention'] ?? [] as $backup) {
            $retention = $this->slotRetentionRecoveryBinding($backup['contents']);
            $this->assertHostRecoverySlotArtifact(
                $retention['slot'],
                $backup['label'],
            );
        }

        foreach ([
            'host-id',
            'admin-token',
            'stable-launcher-identity',
            'guardian-identity',
            'ca-bundle-baseline',
            'failed-initial-cleanup',
        ] as $immutableKey) {
            foreach ($backups[$immutableKey] ?? [] as $backup) {
                if (!isset($current[$immutableKey])
                    || !\hash_equals($current[$immutableKey], $backup['contents'])
                ) {
                    throw new \RuntimeException($backup['label'] . ' is invalid.');
                }
            }
        }
        if (isset($current['stable-launcher-identity'])) {
            $this->assertStableLauncherRecoveryIdentity(
                \trim($current['stable-launcher-identity']),
                $current,
            );
        }
        if (isset($current['guardian-identity'])) {
            $guardianDigest = \trim($current['guardian-identity']);
            $actualGuardian = $this->digestStableRegularFile(
                $this->paths->guardianFile(),
                self::MAX_PACKAGE_BYTES,
                'Immutable Recovery Guardian',
            );
            $this->assertStableLauncherPermissions($this->paths->guardianFile());
            if (!\hash_equals($guardianDigest, $actualGuardian['sha256'])) {
                throw new \RuntimeException(
                    'Recovery Guardian identity does not bind its executable.',
                );
            }
        }
        $caBaseline = isset($current['ca-bundle-baseline'])
            ? \trim($current['ca-bundle-baseline'])
            : '';
        if (($active !== null || $previous !== null) && $caBaseline === '') {
            throw new \RuntimeException(
                'Gateway slot pointer recovery closure lacks its CA trust baseline.',
            );
        }
        if ($caBaseline !== ''
            && $active === null
            && $previous === null
            && !isset($current['failed-initial-cleanup'])
            && !isset($current['rebootstrap-journal'])
        ) {
            throw new \RuntimeException(
                'Gateway CA trust baseline has no activation or rebootstrap owner.',
            );
        }
        if ($caBaseline !== '') {
            foreach (\array_values(\array_unique(\array_filter([
                $active,
                $previous,
            ], static fn (mixed $slot): bool => \is_string($slot) && $slot !== '')))
                as $slot
            ) {
                $proof = $this->verifiedStableLauncherSlotProof(
                    (string)$slot,
                    'Gateway host recovery CA trust closure',
                );
                if (!\hash_equals(
                    $caBaseline,
                    (string)$proof['ca_bundle_sha256'],
                )) {
                    throw new \RuntimeException(
                        'Gateway slot recovery closure differs from the host CA trust baseline.',
                    );
                }
            }
        }
        if (isset($current['failed-initial-cleanup'])) {
            $failedInitial = $this->failedInitialRecoveryBinding(
                $current['failed-initial-cleanup'],
            );
            $intentCa = (string)$failedInitial['ca_bundle_sha256'];
            if ($intentCa !== ''
                && $caBaseline !== ''
                && !\hash_equals($intentCa, $caBaseline)
            ) {
                throw new \RuntimeException(
                    'Failed initial gateway transaction differs from the host CA trust baseline.',
                );
            }
        }
        if (isset($current['rebootstrap-journal'])) {
            $currentRebootstrap = $this->decodeRebootstrapDocument(
                $current['rebootstrap-journal'],
                'Gateway rebootstrap journal recovery target',
            );
            foreach ($backups['rebootstrap-journal'] ?? [] as $backup) {
                $previousRebootstrap = $this->decodeRebootstrapDocument(
                    $backup['contents'],
                    $backup['label'],
                );
                $this->assertRebootstrapRecoveryDocumentsCompatible(
                    $previousRebootstrap,
                    $currentRebootstrap,
                    $backup['label'],
                );
            }
        } elseif (isset($backups['rebootstrap-journal'])) {
            throw new \RuntimeException(
                'Gateway rebootstrap recovery backup has no paired journal.'
            );
        }
    }

    /**
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     */
    private function assertRebootstrapRecoveryDocumentsCompatible(
        array $previous,
        array $current,
        string $label,
    ): void {
        foreach ([
            'operation',
            'nonce',
            'host_id',
            'package_digest',
            'package_version',
            'profile',
            'origin_boot_id',
            'created_at',
            'target_slot',
            'candidate_launcher_sha256',
            'candidate_launcher_size',
            'candidate_launcher_mode',
            'candidate_ca_bundle_sha256',
            'old_active_slot',
            'old_previous_slot',
            'old_launcher_sha256',
            'old_launcher_size',
            'old_launcher_mode',
            'old_ca_bundle_sha256',
            'old_slots',
            'trust_rotation',
        ] as $field) {
            if (($previous[$field] ?? null) !== ($current[$field] ?? null)) {
                throw new \RuntimeException(
                    $label . ' belongs to another gateway rebootstrap closure.'
                );
            }
        }
        foreach ([
            'runtime_generation',
            'derived_policy_sha256',
            'old_derived_manifest_sha256',
            'platform_snapshot',
            'admin_stopped_digest',
            'admin_stopped_contents_b64',
            'gateway_epoch',
            'old_gateway_epoch',
            'new_gateway_epoch',
            'backup_collection_nonce',
            'backup_collection_device',
            'backup_collection_inode',
        ] as $field) {
            $before = $previous[$field] ?? null;
            $after = $current[$field] ?? null;
            $beforeEmpty = $before === null || $before === '';
            $afterEmpty = $after === null || $after === '';
            if ($before === $after || ($beforeEmpty && !$afterEmpty)) {
                continue;
            }
            throw new \RuntimeException(
                $label . ' reverses or changes gateway rebootstrap binding '
                . $field . '.',
            );
        }
        $retainedStateRank = [
            'NONE' => 0,
            'RETAINED' => 1,
            'COLLECTED' => 2,
        ];
        $beforeRetainedState = (string)$previous['retained_backup_state'];
        $afterRetainedState = (string)$current['retained_backup_state'];
        if ($retainedStateRank[$afterRetainedState]
            < $retainedStateRank[$beforeRetainedState]
        ) {
            throw new \RuntimeException(
                $label . ' reverses the gateway rebootstrap retained-backup state.',
            );
        }
        if (!$this->rebootstrapPhaseCanRecoverTo(
            (string)$previous['phase'],
            (string)$current['phase'],
        )) {
            throw new \RuntimeException(
                $label . ' has an impossible gateway rebootstrap phase relation.'
            );
        }
    }

    private function rebootstrapPhaseCanRecoverTo(
        string $previous,
        string $current,
    ): bool {
        if (\hash_equals($previous, $current)) {
            return true;
        }
        $edges = [
            'PREPARING' => ['PREPARED'],
            'PREPARED' => ['STOP_COMMITTED', 'ROLLING_BACK'],
            'STOP_COMMITTED' => ['QUIESCED', 'ROLLING_BACK'],
            'QUIESCED' => ['OLD_GENERATION_STASHED', 'ROLLING_BACK'],
            'OLD_GENERATION_STASHED' => [
                'NEW_GENERATION_PUBLISHED',
                'ROLLING_BACK',
            ],
            'NEW_GENERATION_PUBLISHED' => [
                'PLATFORM_REFRESHED',
                'ROLLING_BACK',
            ],
            'PLATFORM_REFRESHED' => ['START_AUTHORIZED', 'ROLLING_BACK'],
            'START_AUTHORIZED' => ['OBSERVING', 'ROLLING_BACK'],
            'OBSERVING' => ['COMMITTED', 'ROLLING_BACK'],
            'ROLLING_BACK' => ['ROLLBACK_START_AUTHORIZED'],
            'ROLLBACK_START_AUTHORIZED' => [
                'ROLLBACK_OBSERVING',
                'ROLLING_BACK',
            ],
            'ROLLBACK_OBSERVING' => ['ROLLED_BACK', 'ROLLING_BACK'],
        ];
        $pending = [$previous];
        $visited = [];
        while ($pending !== []) {
            $phase = \array_shift($pending);
            if (!\is_string($phase) || isset($visited[$phase])) {
                continue;
            }
            $visited[$phase] = true;
            foreach ($edges[$phase] ?? [] as $next) {
                if (\hash_equals($next, $current)) {
                    return true;
                }
                $pending[] = $next;
            }
        }
        return false;
    }

    /**
     * @param array{from:string,to:string,runtime_generation:string} $binding
     */
    private function assertHostRecoveryIntentArtifacts(
        array $binding,
        string $label,
    ): void {
        if (($binding['protocol'] ?? 0) !== 2
            || ($binding['legacy'] ?? true) !== false
        ) {
            throw $this->stableLauncherRebootstrapRequired($label);
        }
        $this->assertHostRecoverySlotArtifact($binding['from'], $label);
        $this->assertHostRecoverySlotArtifact(
            $binding['to'],
            $label,
            $binding['runtime_generation'],
        );
        $from = $this->verifiedStableLauncherSlotProof($binding['from'], $label);
        $to = $this->verifiedStableLauncherSlotProof($binding['to'], $label);
        if (!\hash_equals($from['launcher_sha256'], $to['launcher_sha256'])
            || $from['launcher_size'] !== $to['launcher_size']
            || $from['launcher_mode'] !== $to['launcher_mode']
            || !\hash_equals(
                $to['runtime_generation'],
                (string)$binding['runtime_generation'],
            )
        ) {
            throw $this->stableLauncherRebootstrapRequired($label);
        }
    }

    /**
     * No recovery artifact may be collected while its retained upgrade intent
     * is executable by an unproved host-global launcher.
     *
     * @param array<string,string> $current
     * @param array<string,list<array{path:string,contents:string,label:string}>> $backups
     */
    private function assertHostRecoveryUpgradeLauncherProofs(
        array $current,
        array $backups,
    ): void {
        $intents = [];
        if (isset($current['upgrade-intent'])) {
            $intents[] = [
                'contents' => $current['upgrade-intent'],
                'label' => 'Gateway upgrade intent recovery target',
            ];
        }
        foreach ($backups['upgrade-intent'] ?? [] as $backup) {
            $intents[] = [
                'contents' => $backup['contents'],
                'label' => $backup['label'],
            ];
        }
        foreach ($intents as $intent) {
            $binding = $this->upgradeIntentBinding($intent['contents']);
            $this->verifiedStableLauncherUpgradeProof(
                [$binding['from'], $binding['to']],
                $intent['label'],
                $binding,
            );
        }
    }

    private function assertHostRecoverySlotArtifact(
        string $slot,
        string $label,
        ?string $expectedRuntimeGeneration = null,
    ): void {
        try {
            $verification = $this->artifact->verify(
                $this->paths->slotDir($slot),
                'host_gateway',
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException($label . ' is invalid.', 0, $throwable);
        }
        if (!$verification['ok']
            || ($expectedRuntimeGeneration !== null
                && !\hash_equals(
                    $expectedRuntimeGeneration,
                    $verification['runtime_generation'],
                ))
        ) {
            throw new \RuntimeException($label . ' is invalid.');
        }
    }

    /** @param array<string,string> $current */
    private function assertStableLauncherRecoveryIdentity(
        string $expectedDigest,
        array $current,
    ): void {
        $launcher = $this->paths->launcherFile();
        $status = @\lstat($launcher);
        if (\is_array($status)) {
            $actual = $this->digestStableRegularFile(
                $launcher,
                self::MAX_PACKAGE_BYTES,
                'Stable gateway launcher recovery target',
            )['sha256'];
            $this->assertStableLauncherPermissions($launcher);
            if (!\hash_equals($expectedDigest, $actual)) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity recovery target is invalid.'
                );
            }
            return;
        }
        if (\file_exists($launcher) || \is_link($launcher)) {
            throw new \RuntimeException(
                'Stable gateway launcher identity recovery target is invalid.'
            );
        }
        if ($this->launcherDigestBackedByInstalledSlot($expectedDigest)) {
            return;
        }
        if (isset($current['failed-initial-cleanup'])) {
            $failed = $this->failedInitialRecoveryBinding(
                $current['failed-initial-cleanup'],
            );
            if (\hash_equals($expectedDigest, $failed['launcher_sha256'])) {
                return;
            }
        }
        throw new \RuntimeException(
            'Stable gateway launcher identity recovery target is invalid.'
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withStagingLock(string $slot, callable $callback): mixed
    {
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Gateway staging slot must be A or B.');
        }
        return $this->withHostPackageLock(
            'package-stage-' . \strtolower($slot) . '.lock',
            'slot ' . $slot . ' staging',
            $callback,
        );
    }

    /**
     * @template T
     * @param list<string> $slots
     * @param callable():T $callback
     * @return T
     */
    private function withStagingLocks(array $slots, callable $callback): mixed
    {
        $slots = \array_values(\array_unique(\array_map(
            static fn (string $slot): string => \strtoupper(\trim($slot)),
            $slots,
        )));
        \sort($slots, SORT_STRING);
        $acquire = function (int $offset) use (&$acquire, $slots, $callback): mixed {
            if (!isset($slots[$offset])) {
                return $callback();
            }
            return $this->withStagingLock(
                $slots[$offset],
                static fn (): mixed => $acquire($offset + 1),
            );
        };
        return $acquire(0);
    }

    /**
     * Lock ordering is always sorted slot-staging lock(s), then the global
     * package lock for a short pointer/intent/terminal transaction.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withHostPackageLock(
        string $lockName,
        string $label,
        callable $callback,
        float $maximumLockWaitSeconds = self::INSTALL_LOCK_TIMEOUT_SECONDS,
    ): mixed
    {
        $this->paths->ensureDirectories();
        ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
            ->securePackageTransactionTrust($this->activeOperationDeadline());
        // Package transactions belong to the host trust domain. Keeping this
        // lock in controller-writable state lets a failed or compromised
        // Controller replace the lock and block administrator recovery.
        if (\preg_match('/\Apackage-(?:bootstrap|install|stage-[ab])\.lock\z/D', $lockName) !== 1) {
            throw new \LogicException('Unsupported host-gateway package lock name.');
        }
        $lockFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR . $lockName;
        $trustStatus = @\lstat($this->paths->trustDir());
        if (!\is_array($trustStatus)
            || \is_link($this->paths->trustDir())
            || ((((int)($trustStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('The host-gateway trust directory is unsafe.');
        }
        $lockStatus = false;
        $handle = false;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $lockStatus = @\lstat($lockFile);
            if (\is_array($lockStatus)) {
                if (\is_link($lockFile)
                    || !$this->isRegularFileStatus($lockStatus)
                    || (int)($lockStatus['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'The host-gateway ' . $label . ' lock path is unsafe.'
                    );
                }
                $handle = @\fopen($lockFile, 'r+b');
            } else {
                if (\file_exists($lockFile) || \is_link($lockFile)) {
                    throw new \RuntimeException(
                        'The host-gateway ' . $label . ' lock path is indeterminate.'
                    );
                }
                $handle = @\fopen($lockFile, 'x+b');
            }
            if (\is_resource($handle)) {
                break;
            }
            // A concurrent installer can create the root-owned lock between
            // lstat and fopen(x). Re-resolve that exact path instead of
            // reporting a false package-install failure.
            $this->assertOperationDeadlineAvailable(
                'opening the host-gateway ' . $label . ' lock',
            );
            \usleep((int)\max(1, \min(
                2_000,
                \floor($this->remainingOperationDeadline(0.002) * 1_000_000),
            )));
        }
        $openedStatus = \is_resource($handle) ? @\fstat($handle) : false;
        if (!\is_resource($handle)
            || !\is_array($openedStatus)
            || !$this->isRegularFileStatus($openedStatus)
            || (int)($openedStatus['nlink'] ?? 0) !== 1
            || (\is_array($lockStatus)
                && !$this->sameFileState($lockStatus, $openedStatus))
        ) {
            \is_resource($handle) && @\fclose($handle);
            throw new \RuntimeException('The host-gateway ' . $label . ' lock is unsafe.');
        }
        // The launcher and every project-side package transaction serialize on
        // this exact host-trust inode. A bounded monotonic wait avoids both an
        // uninterruptible CLI hang and wall-clock jumps changing lock policy.
        $lockStarted = \hrtime(true);
        if (!\is_finite($maximumLockWaitSeconds)
            || $maximumLockWaitSeconds <= 0.0
            || $maximumLockWaitSeconds > self::PACKAGE_OPERATION_TIMEOUT_SECONDS
        ) {
            @\fclose($handle);
            throw new \RuntimeException(
                'The host-gateway lock wait budget is invalid.',
            );
        }
        $lockBudgetSeconds = $this->remainingOperationDeadline(
            $maximumLockWaitSeconds,
        );
        $lockBudget = (int)\max(
            1,
            \floor($lockBudgetSeconds * 1_000_000_000),
        );
        if ($lockStarted < 0 || $lockStarted > PHP_INT_MAX - $lockBudget) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException(
                'The host-gateway installation lock clock is outside the supported range.'
            );
        }
        $lockDeadline = $lockStarted + $lockBudget;
        while (!@\flock($handle, LOCK_EX | LOCK_NB)) {
            if (\hrtime(true) >= $lockDeadline) {
                @\fclose($handle);
                throw new \RuntimeException(
                    'Timed out acquiring the host-gateway ' . $label . ' lock.'
                );
            }
            $remainingNanoseconds = $lockDeadline - \hrtime(true);
            if ($remainingNanoseconds <= 0) {
                continue;
            }
            \usleep((int)\min(20_000, \max(
                1,
                \floor($remainingNanoseconds / 1_000),
            )));
        }
        $pathAfter = @\lstat($lockFile);
        if (!\is_array($pathAfter)
            || !$this->sameFileState($openedStatus, $pathAfter)
        ) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException(
                'The host-gateway ' . $label . ' lock changed while being acquired.'
            );
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $uid = isset($trustStatus['uid']) ? (int)$trustStatus['uid'] : -1;
            $gid = isset($trustStatus['gid']) ? (int)$trustStatus['gid'] : -1;
            $ownerOk = $uid >= 0 && (
                \function_exists('fchown')
                    ? @\fchown($handle, $uid)
                    : @\chown($lockFile, $uid)
            );
            $groupOk = $gid >= 0 && (
                \function_exists('fchgrp')
                    ? @\fchgrp($handle, $gid)
                    : @\chgrp($lockFile, $gid)
            );
            $modeOk = \function_exists('fchmod')
                ? @\fchmod($handle, 0600)
                : @\chmod($lockFile, 0600);
            if (!$ownerOk || !$groupOk || !$modeOk) {
                @\flock($handle, LOCK_UN);
                @\fclose($handle);
                throw new \RuntimeException(
                    'Unable to seal the host-gateway ' . $label . ' lock.'
                );
            }
        }
        $sealedStatus = @\fstat($handle);
        $sealedPath = @\lstat($lockFile);
        if (!\is_array($sealedStatus)
            || !\is_array($sealedPath)
            || !$this->sameFileState($sealedStatus, $sealedPath)
            || !$this->isRegularFileStatus($sealedStatus)
            || (int)($sealedStatus['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($sealedStatus['mode'] ?? 0)) & 0777) !== 0600
                    || (int)($sealedStatus['uid'] ?? -1) !== (int)$trustStatus['uid']
                    || (int)($sealedStatus['gid'] ?? -1) !== (int)$trustStatus['gid']))
        ) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException(
                'The host-gateway ' . $label . ' lock seal could not be verified.'
            );
        }
        try {
            return $callback();
        } finally {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    private function inactiveSlotMayBeReplaced(string $slot): bool
    {
        if (\file_exists($this->paths->upgradeIntentFile())
            || \is_link($this->paths->upgradeIntentFile())
        ) {
            return false;
        }
        $retention = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'slot-retention';
        $retentionPresent = \file_exists($retention) || \is_link($retention);
        if (!$retentionPresent) {
            $rolledBack = $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rolled-back';
            if ($this->rolledBackMarkerMatchesSlotArtifact($slot)) {
                return true;
            }
        }
        $contents = $this->readOptionalStableRegularFile(
            $retention,
            512,
            'Gateway slot retention marker',
        );
        if ($contents === null) {
            return false;
        }
        $retainedSlot = null;
        $intentDigest = '';
        $intentNonce = '';
        $markerBootId = '';
        $retainedSinceMonotonic = null;
        $retainUntilMonotonic = null;
        $markerIsV3 = false;
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/3\\n'
                . 'intent_sha256=([a-f0-9]{64})\\n'
                . 'intent_nonce=([a-f0-9]{32})\\n'
                . 'slot=([AB])\\n'
                . 'boot_id=([0-9A-Za-z-]{1,64})\\n'
                . 'retained_at=([0-9]+)\\n'
                . 'retain_until=([0-9]+)\\n'
                . 'retained_since_monotonic_ms=([0-9]+)\\n'
                . 'retain_until_monotonic_ms=([0-9]+)\\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $intentDigest = (string)$matches[1];
            $intentNonce = (string)$matches[2];
            $retainedSlot = (string)$matches[3];
            $markerBootId = (string)$matches[4];
            $retainedSinceMonotonic = $this->boundedDecimalInteger(
                (string)$matches[7],
            );
            $retainUntilMonotonic = $this->boundedDecimalInteger(
                (string)$matches[8],
            );
            $markerIsV3 = $this->boundedDecimalInteger((string)$matches[5]) !== null
                && $this->boundedDecimalInteger((string)$matches[6]) !== null;
        } elseif (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/3\\n'
                . 'intent_sha256=([a-f0-9]{64})\\n'
                . 'intent_nonce=([a-f0-9]{32})\\n'
                . 'slot=([AB])\\n'
                . '(?:boot_id=[0-9A-Za-z-]{1,64}\\n)?'
                . '(?:retained_at=[0-9]+\\n)?'
                . '(?:retain_until=[0-9]+\\n)?'
                . '(?:retained_since_monotonic_ms=[0-9]+\\n)?'
                . '(?:retain_until_monotonic_ms=[0-9]+\\n)?\z/D',
            $contents,
            $matches,
        ) === 1) {
            // A partially written/legacy-v3 marker has enough exact binding
            // to preserve the slot, but never enough evidence to expire it.
            $intentDigest = (string)$matches[1];
            $intentNonce = (string)$matches[2];
            $retainedSlot = (string)$matches[3];
        } elseif (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/2\\n'
                . 'intent_sha256=([a-f0-9]{64})\\n'
                . 'intent_nonce=([a-f0-9]{32})\\n'
                . 'slot=([AB])\\nretain_until=[0-9]+\\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $intentDigest = (string)$matches[1];
            $intentNonce = (string)$matches[2];
            $retainedSlot = (string)$matches[3];
        } elseif (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/1\\nslot=([AB])\\nretain_until=[0-9]+\\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $retainedSlot = (string)$matches[1];
        }
        if ($retainedSlot === null
            || !\hash_equals($slot, $retainedSlot)
        ) {
            return false;
        }

        try {
            $hostBootId = GatewayHostBootIdentity::current();
        } catch (\Throwable) {
            return false;
        }
        $monotonicNow = \intdiv(\hrtime(true), 1_000_000);
        $validMonotonicWindow = $markerIsV3
            && $retainedSinceMonotonic !== null
            && $retainUntilMonotonic !== null
            && $retainedSinceMonotonic > 0
            && $retainedSinceMonotonic
                <= PHP_INT_MAX - self::SLOT_RETENTION_MILLISECONDS
            && $retainUntilMonotonic
                === $retainedSinceMonotonic + self::SLOT_RETENTION_MILLISECONDS;
        if ($validMonotonicWindow
            && \hash_equals($hostBootId, $markerBootId)
            && $monotonicNow >= $retainedSinceMonotonic
            && $monotonicNow >= $retainUntilMonotonic
        ) {
            return true;
        }

        if (!$validMonotonicWindow
            || !\hash_equals($hostBootId, $markerBootId)
            || $monotonicNow < $retainedSinceMonotonic
        ) {
            if ($intentDigest === '' || $intentNonce === '') {
                $intentNonce = \bin2hex(\random_bytes(16));
                $intentDigest = \hash(
                    'sha256',
                    "WLS-SLOT-RETENTION-MIGRATION/3\n"
                        . $slot . "\n"
                        . $intentNonce . "\n"
                        . \hash('sha256', $contents),
                );
            }
            $this->writeSlotRetentionMarker(
                $retention,
                $intentDigest,
                $intentNonce,
                $slot,
                $hostBootId,
                $monotonicNow,
            );
        }

        return false;
    }

    private function writeSlotRetentionMarker(
        string $path,
        string $intentDigest,
        string $intentNonce,
        string $slot,
        string $hostBootId,
        int $retainedSinceMonotonic,
    ): void {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $intentDigest) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $intentNonce) !== 1
            || !\in_array($slot, ['A', 'B'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) !== 1
            || $retainedSinceMonotonic <= 0
            || $retainedSinceMonotonic
                > PHP_INT_MAX - self::SLOT_RETENTION_MILLISECONDS
        ) {
            throw new \RuntimeException('Gateway slot retention migration identity is invalid.');
        }
        $retainedAt = \time();
        if ($retainedAt <= 0
            || $retainedAt > PHP_INT_MAX - self::SLOT_RETENTION_SECONDS
        ) {
            throw new \RuntimeException('Gateway slot retention display time is invalid.');
        }
        $this->atomicWrite(
            $path,
            "WLS-SLOT-RETENTION/3\n"
                . 'intent_sha256=' . $intentDigest . "\n"
                . 'intent_nonce=' . $intentNonce . "\n"
                . 'slot=' . $slot . "\n"
                . 'boot_id=' . $hostBootId . "\n"
                . 'retained_at=' . $retainedAt . "\n"
                . 'retain_until=' . ($retainedAt + self::SLOT_RETENTION_SECONDS) . "\n"
                . 'retained_since_monotonic_ms=' . $retainedSinceMonotonic . "\n"
                . 'retain_until_monotonic_ms='
                    . ($retainedSinceMonotonic + self::SLOT_RETENTION_MILLISECONDS)
                    . "\n",
            0600,
        );
    }

    private function boundedDecimalInteger(string $value): ?int
    {
        if (\preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) !== 1) {
            return null;
        }
        $maximum = (string)PHP_INT_MAX;
        if (\strlen($value) > \strlen($maximum)
            || (\strlen($value) === \strlen($maximum)
                && \strcmp($value, $maximum) > 0)
        ) {
            return null;
        }

        return (int)$value;
    }

    private function wallClockNow(): int
    {
        $now = $this->wallClock !== null
            ? ($this->wallClock)()
            : \time();
        if (!\is_int($now) || $now <= 0) {
            throw new \RuntimeException(
                'Gateway diagnostic wall clock is outside the supported range.'
            );
        }
        return $now;
    }

    private function monotonicClockMillisecondsNow(): int
    {
        $now = $this->monotonicClockMilliseconds !== null
            ? ($this->monotonicClockMilliseconds)()
            : \intdiv(\hrtime(true), 1_000_000);
        if (!\is_int($now) || $now <= 0) {
            throw new \RuntimeException(
                'Gateway monotonic clock is outside the supported range.'
            );
        }
        return $now;
    }

    private function hostBootIdentityNow(): string
    {
        $bootId = \strtolower(\trim((string)(
            $this->bootIdentity !== null
                ? ($this->bootIdentity)()
                : GatewayHostBootIdentity::current()
        )));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $bootId) !== 1) {
            throw new \RuntimeException(
                'Gateway host boot identity is outside the supported format.'
            );
        }
        return $bootId;
    }

    private function injectRebootstrapCrashAfterPhase(string $phase): void
    {
        $this->injectRebootstrapCrash('after:' . $phase);
    }

    private function injectRebootstrapStartAuthorizationCrash(
        string $stage,
    ): void {
        if (!$this->paths->isTestMode()) {
            return;
        }
        $this->injectRebootstrapCrash('start-authorization:' . $stage);
    }

    private function injectRebootstrapCrash(string $point): void
    {
        if (!$this->paths->isTestMode()) {
            return;
        }
        $requested = \trim((string)(
            \getenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT') ?: ''
        ));
        if ($requested === '' || !\hash_equals($requested, $point)) {
            return;
        }
        throw new GatewayRebootstrapCrashSimulation(
            'Simulated hard crash at gateway rebootstrap boundary ' . $point . '.'
        );
    }

    /**
     * Eligibility checks are deliberately side-effect free. Markers are
     * consumed only after the inactive slot tree is proven absent, so an I/O
     * failure during removal leaves the exact same retry authority intact.
     */
    private function clearInactiveSlotReplacementMarkers(
        string $slot,
        bool $slotAlreadyAbsent = false,
    ): void
    {
        $rolledBack = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rolled-back';
        $rollback = $this->readOptionalStableRegularFile(
            $rolledBack,
            384,
            'Gateway upgrade rollback marker',
        );
        if ($rollback !== null) {
            $matchesSlot = \preg_match(
                '/\AWLS-UPGRADE-ROLLED-BACK\\/3\\n'
                    . 'intent_sha256=[a-f0-9]{64}\\n'
                    . 'intent_nonce=[a-f0-9]{32}\\n'
                    . 'from=[AB]\\nto=' . \preg_quote($slot, '/')
                    . '\\nruntime_generation=[a-f0-9]{64}\\n'
                    . 'at=[0-9]+\\n\z/D',
                $rollback,
            ) === 1;
            if ($matchesSlot) {
                GatewayProjectStateFilesystem::removeRegular(
                    $rolledBack,
                    'gateway upgrade rollback marker',
                );
            }
        }

        $retention = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'slot-retention';
        $contents = $this->readOptionalStableRegularFile(
            $retention,
            512,
            'Gateway slot retention marker',
        );
        if ($contents === null) {
            return;
        }
        if ($slotAlreadyAbsent && (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/3\\n'
                . 'intent_sha256=[a-f0-9]{64}\\n'
                . 'intent_nonce=[a-f0-9]{32}\\n'
                . 'slot=' . \preg_quote($slot, '/') . '\\n/',
            $contents,
        ) === 1 || \preg_match(
            '/\AWLS-SLOT-RETENTION\\/2\\n'
                . 'intent_sha256=[a-f0-9]{64}\\n'
                . 'intent_nonce=[a-f0-9]{32}\\n'
                . 'slot=' . \preg_quote($slot, '/') . '\\n/',
            $contents,
        ) === 1 || \preg_match(
            '/\AWLS-SLOT-RETENTION\\/1\\nslot='
                . \preg_quote($slot, '/') . '\\n/',
            $contents,
        ) === 1)) {
            GatewayProjectStateFilesystem::removeRegular(
                $retention,
                'orphan gateway slot retention marker',
            );
            return;
        }
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/3\\n'
                . 'intent_sha256=[a-f0-9]{64}\\n'
                . 'intent_nonce=[a-f0-9]{32}\\n'
                . 'slot=' . \preg_quote($slot, '/') . '\\n'
                . 'boot_id=([0-9A-Za-z-]{1,64})\\n'
                . 'retained_at=[0-9]+\\n'
                . 'retain_until=[0-9]+\\n'
                . 'retained_since_monotonic_ms=([0-9]+)\\n'
                . 'retain_until_monotonic_ms=([0-9]+)\\n\z/D',
            $contents,
            $matches,
        ) !== 1) {
            return;
        }
        try {
            $hostBootId = GatewayHostBootIdentity::current();
        } catch (\Throwable) {
            return;
        }
        $started = $this->boundedDecimalInteger((string)$matches[2]);
        $deadline = $this->boundedDecimalInteger((string)$matches[3]);
        if (!\hash_equals($hostBootId, (string)$matches[1])
            || $started === null
            || $deadline === null
            || $started <= 0
            || $started > PHP_INT_MAX - self::SLOT_RETENTION_MILLISECONDS
            || $deadline !== $started + self::SLOT_RETENTION_MILLISECONDS
            || \intdiv(\hrtime(true), 1_000_000) < $deadline
        ) {
            return;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $retention,
            'gateway slot retention marker',
        );
    }

    private function rolledBackMarkerMatchesSlotArtifact(string $slot): bool
    {
        $marker = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rolled-back';
        $contents = $this->readOptionalStableRegularFile(
            $marker,
            384,
            'Gateway upgrade rollback marker',
        );
        if ($contents === null || \preg_match(
            '/\AWLS-UPGRADE-ROLLED-BACK\\/3\\n'
                . 'intent_sha256=[a-f0-9]{64}\\n'
                . 'intent_nonce=[a-f0-9]{32}\\n'
                . 'from=([AB])\\nto=([AB])\\n'
                . 'runtime_generation=([a-f0-9]{64})\\n'
                . 'at=[0-9]+\\n\z/D',
            $contents,
            $matches,
        ) !== 1
            || !\hash_equals($slot, (string)$matches[2])
            || \hash_equals((string)$matches[1], (string)$matches[2])
        ) {
            return false;
        }
        try {
            $verification = $this->artifact->verify(
                $this->paths->slotDir($slot),
                'host_gateway',
            );
        } catch (\Throwable) {
            return false;
        }

        return ($verification['ok'] ?? false) === true
            && \hash_equals(
                (string)$matches[3],
                (string)($verification['runtime_generation'] ?? ''),
            );
    }

    private function removeTerminalOrphanUpgradeState(): void
    {
        $state = $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'upgrade-state';
        $contents = $this->readOptionalStableRegularFile(
            $state,
            1024,
            'Gateway upgrade transaction state',
        );
        if ($contents === null) {
            return;
        }
        $terminalV2 = \preg_match(
                '/\AWLS-UPGRADE-STATE\\/2\\n'
                    . 'intent_sha256=[a-f0-9]{64}\\n'
                    . 'intent_nonce=[a-f0-9]{32}\\n'
                    . 'from=[AB]\\nto=[AB]\\n'
                    . 'runtime_generation=[a-f0-9]{64}\\n'
                    . 'boot_id=[0-9A-Za-z-]+\\n'
                    . 'phase=(?:COMMITTED|ROLLED_BACK)\\n'
                    . 'attempts=[0-3]\\n'
                    . 'observation_started_monotonic_ms=[0-9]+\\n'
                    . 'observation_deadline_monotonic_ms=[0-9]+\\n'
                    . 'total_deadline=[0-9]+\\n\z/D',
                $contents,
            ) === 1;
        $terminalV3 = \preg_match(
            '/\AWLS-UPGRADE-STATE\\/3\\n'
                . 'intent_sha256=[a-f0-9]{64}\\n'
                . 'intent_nonce=[a-f0-9]{32}\\n'
                . 'from=[AB]\\nto=[AB]\\n'
                . 'runtime_generation=[a-f0-9]{64}\\n'
                . 'boot_id=[a-f0-9]{64}\\n'
                . 'phase=(COMMITTED|ROLLED_BACK)\\n'
                . 'attempts=[0-3]\\n'
                . 'prepared_monotonic_ms=([1-9][0-9]{0,18})\\n'
                . 'observation_started_monotonic_ms=([0-9]{1,19})\\n'
                . 'observation_deadline_monotonic_ms=([0-9]{1,19})\\n'
                . 'total_deadline_monotonic_ms=([1-9][0-9]{0,18})\\n\z/D',
            $contents,
            $v3,
        ) === 1;
        if ($terminalV3) {
            $prepared = $this->boundedDecimalInteger((string)$v3[2]);
            $observationStarted = $this->boundedDecimalInteger((string)$v3[3]);
            $observationDeadline = $this->boundedDecimalInteger((string)$v3[4]);
            $totalDeadline = $this->boundedDecimalInteger((string)$v3[5]);
            $terminalV3 = $prepared !== null
                && $observationStarted !== null
                && $observationDeadline !== null
                && $totalDeadline !== null
                && $prepared <= PHP_INT_MAX
                    - self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
                && $totalDeadline === $prepared
                    + self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
                && ((string)$v3[1] === 'ROLLED_BACK'
                    ? ($observationStarted === 0 && $observationDeadline === 0)
                    : ($observationStarted > 0
                        && $observationStarted <= PHP_INT_MAX - 300_000
                        && $observationDeadline === $observationStarted + 300_000));
        }
        if (\file_exists($this->paths->upgradeIntentFile())
            || \is_link($this->paths->upgradeIntentFile())
            || (!$terminalV2 && !$terminalV3)
        ) {
            throw new \RuntimeException(
                'A non-terminal or ambiguously bound gateway upgrade transaction blocks a new activation.'
            );
        }
        GatewayProjectStateFilesystem::removeRegular(
            $state,
            'terminal gateway upgrade transaction state',
        );
    }

    private function assertPlatformRemovalCompleted(): void
    {
        $pending = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'platform-removal.pending';
        if (\file_exists($pending) || \is_link($pending)) {
            throw new \RuntimeException(
                'Gateway platform removal is not verified; installed runtime cleanup is fenced.'
            );
        }
    }

    /**
     * @return array{
     *   protocol:int,
     *   legacy:bool,
     *   digest:string,
     *   nonce:string,
     *   from:string,
     *   to:string,
     *   runtime_generation:string,
     *   prepared_at:int,
     *   rollback_deadline:int,
     *   host_boot_id:string,
     *   prepared_monotonic_ms:int,
     *   activation_deadline_monotonic_ms:int,
     *   rollback_deadline_monotonic_ms:int
     * }
     */
    private function upgradeIntentBinding(string $intent): array
    {
        $protocol = 0;
        $legacy = false;
        $hostBootId = '';
        $preparedMonotonic = 0;
        $activationDeadlineMonotonic = 0;
        $rollbackDeadlineMonotonic = 0;
        if (\preg_match(
            '/\AWLS-UPGRADE\/2\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'from=([AB])\n'
                . 'to=([AB])\n'
                . 'prepared_at=([1-9][0-9]{0,18})\n'
                . 'deadline=([1-9][0-9]{0,18})\n'
                . 'runtime_generation=([a-f0-9]{64})\n'
                . 'host_boot_id=([a-f0-9]{64})\n'
                . 'prepared_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'activation_deadline_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'rollback_deadline_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $intent,
            $match,
        ) === 1) {
            $protocol = 2;
            $hostBootId = (string)$match[7];
            $preparedMonotonic = $this->boundedDecimalInteger((string)$match[8]) ?? 0;
            $activationDeadlineMonotonic = $this->boundedDecimalInteger(
                (string)$match[9],
            ) ?? 0;
            $rollbackDeadlineMonotonic = $this->boundedDecimalInteger(
                (string)$match[10],
            ) ?? 0;
            $nonce = (string)$match[11];
            $signature = (string)$match[12];
        } elseif (\preg_match(
            '/\AWLS-UPGRADE\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'from=([AB])\n'
                . 'to=([AB])\n'
                . 'prepared_at=([1-9][0-9]{0,18})\n'
                . 'deadline=([1-9][0-9]{0,18})\n'
                . 'runtime_generation=([a-f0-9]{64})\n'
                . 'nonce=([a-f0-9]{32})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $intent,
            $match,
        ) === 1) {
            $protocol = 1;
            $legacy = true;
            $nonce = (string)$match[7];
            $signature = (string)$match[8];
        } else {
            throw new \RuntimeException(
                'Gateway signed activation intent cannot be bound to rollback state.'
            );
        }
        $preparedAt = $this->boundedDecimalInteger((string)$match[4]) ?? 0;
        $activationDeadline = $this->boundedDecimalInteger((string)$match[5]) ?? 0;
        if (!\hash_equals($this->hostId(), (string)$match[1])
            || \hash_equals((string)$match[2], (string)$match[3])
            || $preparedAt <= 0
            || $preparedAt > PHP_INT_MAX - self::UPGRADE_TOTAL_TIMEOUT_SECONDS
            || $activationDeadline
                !== $preparedAt + self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS
            || (!$legacy
                && ($preparedMonotonic <= 0
                    || $preparedMonotonic
                        > PHP_INT_MAX - self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
                    || $activationDeadlineMonotonic
                        !== $preparedMonotonic
                            + self::UPGRADE_ACTIVATION_TIMEOUT_MILLISECONDS
                    || $rollbackDeadlineMonotonic
                        !== $preparedMonotonic
                            + self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS))
        ) {
            throw new \RuntimeException(
                'Gateway signed activation intent cannot be bound to rollback state.'
            );
        }
        $signatureOffset = \strrpos($intent, 'signature=');
        $secret = \strtolower(\trim($this->readStableRegularFile(
            $this->paths->adminTokenFile(),
            65,
            'Gateway administrator credential',
        )));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hex2bin($secret)
            : false;
        if ($signatureOffset === false || !\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'Gateway signed activation intent cannot be authenticated.'
            );
        }
        try {
            $expectedSignature = \hash_hmac(
                'sha256',
                \substr($intent, 0, $signatureOffset),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException(
                'Gateway signed activation intent authentication failed.'
            );
        }
        return [
            'protocol' => $protocol,
            'legacy' => $legacy,
            'digest' => \hash('sha256', $intent),
            'nonce' => $nonce,
            'from' => (string)$match[2],
            'to' => (string)$match[3],
            'runtime_generation' => (string)$match[6],
            'prepared_at' => $preparedAt,
            'rollback_deadline' => $preparedAt + self::UPGRADE_TOTAL_TIMEOUT_SECONDS,
            'host_boot_id' => $hostBootId,
            'prepared_monotonic_ms' => $preparedMonotonic,
            'activation_deadline_monotonic_ms' => $activationDeadlineMonotonic,
            'rollback_deadline_monotonic_ms' => $rollbackDeadlineMonotonic,
        ];
    }

    /**
     * @param string $key 32-byte administrator HMAC key
     * @return array{
     *   schema:string,
     *   intent_sha256:string,
     *   intent_nonce:string,
     *   from:string,
     *   to:string,
     *   runtime_generation:string,
     *   host_boot_id:string,
     *   prepared_monotonic_ms:int,
     *   rollback_deadline_monotonic_ms:int,
     *   context_nonce:string,
     *   signature:string
     * }
     */
    private function signAutomaticRollbackContext(
        string $intent,
        string $intentNonce,
        string $from,
        string $to,
        string $runtimeGeneration,
        string $hostBootId,
        int $preparedMonotonic,
        string $key,
    ): array {
        $context = [
            'schema' => 'wls-upgrade-automatic-rollback/1',
            'intent_sha256' => \hash('sha256', $intent),
            'intent_nonce' => $intentNonce,
            'from' => $to,
            'to' => $from,
            'runtime_generation' => $runtimeGeneration,
            'host_boot_id' => $hostBootId,
            'prepared_monotonic_ms' => $preparedMonotonic,
            'rollback_deadline_monotonic_ms' => $preparedMonotonic
                + self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS,
            'context_nonce' => \bin2hex(\random_bytes(16)),
        ];
        $context['signature'] = \hash_hmac(
            'sha256',
            $this->automaticRollbackContextPayload($context),
            $key,
        );
        return $context;
    }

    /**
     * @param array<string,mixed> $context
     * @param array{
     *   digest:string,
     *   nonce:string,
     *   from:string,
     *   to:string,
     *   runtime_generation:string,
     *   prepared_at:int,
     *   rollback_deadline:int
     * } $intentBinding
     */
    private function validateAutomaticRollbackContext(
        array $context,
        array $intentBinding,
        string $failedSlot,
        string $previousSlot,
    ): int {
        $preparedMonotonic = $context['prepared_monotonic_ms'] ?? null;
        $rollbackDeadline = $context['rollback_deadline_monotonic_ms'] ?? null;
        if (!\hash_equals(
                'wls-upgrade-automatic-rollback/1',
                (string)($context['schema'] ?? ''),
            )
            || !\hash_equals(
                $intentBinding['digest'],
                (string)($context['intent_sha256'] ?? ''),
            )
            || !\hash_equals(
                $intentBinding['nonce'],
                (string)($context['intent_nonce'] ?? ''),
            )
            || !\hash_equals($failedSlot, (string)($context['from'] ?? ''))
            || !\hash_equals($previousSlot, (string)($context['to'] ?? ''))
            || !\hash_equals(
                $intentBinding['runtime_generation'],
                (string)($context['runtime_generation'] ?? ''),
            )
            || ($intentBinding['legacy'] ?? true) === true
            || !\hash_equals(
                (string)$intentBinding['host_boot_id'],
                (string)($context['host_boot_id'] ?? ''),
            )
            || (int)$intentBinding['prepared_monotonic_ms'] !== $preparedMonotonic
            || (int)$intentBinding['rollback_deadline_monotonic_ms']
                !== $rollbackDeadline
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($context['host_boot_id'] ?? ''),
            ) !== 1
            || !\is_int($preparedMonotonic)
            || !\is_int($rollbackDeadline)
            || $preparedMonotonic <= 0
            || $preparedMonotonic
                > PHP_INT_MAX - self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
            || $rollbackDeadline !== $preparedMonotonic
                + self::UPGRADE_TOTAL_TIMEOUT_MILLISECONDS
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($context['context_nonce'] ?? ''),
            ) !== 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($context['signature'] ?? ''),
            ) !== 1
        ) {
            throw new \RuntimeException(
                'Gateway automatic rollback context is malformed or bound to another transaction.'
            );
        }

        $secret = \strtolower(\trim($this->readStableRegularFile(
            $this->paths->adminTokenFile(),
            65,
            'Gateway administrator credential',
        )));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hex2bin($secret)
            : false;
        if (!\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'Gateway administrator credential cannot verify automatic rollback.'
            );
        }
        try {
            $expectedSignature = \hash_hmac(
                'sha256',
                $this->automaticRollbackContextPayload($context),
                $key,
            );
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals(
            $expectedSignature,
            (string)$context['signature'],
        )) {
            throw new \RuntimeException(
                'Gateway automatic rollback context authentication failed.'
            );
        }

        $currentBootId = $this->hostBootIdentityNow();
        if (!\hash_equals(
            $currentBootId,
            (string)$context['host_boot_id'],
        )) {
            throw new \RuntimeException(
                'Gateway automatic rollback context belongs to another host boot; persistent launcher/LKG recovery is required.'
            );
        }
        $monotonicNow = $this->monotonicClockMillisecondsNow();
        if ($monotonicNow < $preparedMonotonic
            || $monotonicNow > $rollbackDeadline
        ) {
            throw new \RuntimeException(
                'Gateway automatic rollback is outside its same-boot monotonic transaction window.'
            );
        }
        return $monotonicNow;
    }

    /** @param array<string,mixed> $context */
    private function automaticRollbackContextPayload(array $context): string
    {
        return "WLS-UPGRADE-AUTOMATIC-ROLLBACK/1\n"
            . 'intent_sha256=' . (string)($context['intent_sha256'] ?? '') . "\n"
            . 'intent_nonce=' . (string)($context['intent_nonce'] ?? '') . "\n"
            . 'from=' . (string)($context['from'] ?? '') . "\n"
            . 'to=' . (string)($context['to'] ?? '') . "\n"
            . 'runtime_generation='
                . (string)($context['runtime_generation'] ?? '') . "\n"
            . 'host_boot_id=' . (string)($context['host_boot_id'] ?? '') . "\n"
            . 'prepared_monotonic_ms=' . (string)(
                $context['prepared_monotonic_ms'] ?? ''
            ) . "\n"
            . 'rollback_deadline_monotonic_ms=' . (string)(
                $context['rollback_deadline_monotonic_ms'] ?? ''
            ) . "\n"
            . 'context_nonce=' . (string)($context['context_nonce'] ?? '') . "\n";
    }

    /**
     * @param array{
     *   digest:string,
     *   nonce:string,
     *   prepared_at:int,
     *   rollback_deadline:int
     * } $intentBinding
     */
    private function validateUpgradeRollbackRequest(
        string $contents,
        array $intentBinding,
        string $failedSlot,
        string $previousSlot,
    ): void {
        if (\preg_match(
            '/\AWLS-UPGRADE-ROLLBACK\/3\n'
                . 'intent_sha256=([a-f0-9]{64})\n'
                . 'intent_nonce=([a-f0-9]{32})\n'
                . 'from=([AB])\nto=([AB])\n'
                . 'host_boot_id=([a-f0-9]{64})\n'
                . 'requested_monotonic_ms=([1-9][0-9]{0,18})\n'
                . 'request_nonce=([a-f0-9]{32})\n\z/D',
            $contents,
            $matches,
        ) === 1) {
            $requested = $this->boundedDecimalInteger((string)$matches[6]);
            if (($intentBinding['legacy'] ?? true) === true
                || !\hash_equals($intentBinding['digest'], (string)$matches[1])
                || !\hash_equals($intentBinding['nonce'], (string)$matches[2])
                || !\hash_equals($failedSlot, (string)$matches[3])
                || !\hash_equals($previousSlot, (string)$matches[4])
                || \hash_equals((string)$matches[3], (string)$matches[4])
                || !\hash_equals(
                    (string)$intentBinding['host_boot_id'],
                    (string)$matches[5],
                )
                || $requested === null
                || $requested < (int)$intentBinding['prepared_monotonic_ms']
                || $requested
                    > (int)$intentBinding['rollback_deadline_monotonic_ms']
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade rollback request is malformed or bound to another transaction.',
                );
            }
            return;
        }
        if (\preg_match(
            '/\AWLS-UPGRADE-ROLLBACK\/2\n'
                . 'intent_sha256=([a-f0-9]{64})\n'
                . 'intent_nonce=([a-f0-9]{32})\n'
                . 'from=([AB])\nto=([AB])\n'
                . 'at=([1-9][0-9]{0,18})\n'
                . 'request_nonce=([a-f0-9]{32})\n\z/D',
            $contents,
            $matches,
        ) !== 1
            || ($intentBinding['legacy'] ?? false) !== true
            || !\hash_equals($intentBinding['digest'], (string)$matches[1])
            || !\hash_equals($intentBinding['nonce'], (string)$matches[2])
            || !\hash_equals($failedSlot, (string)$matches[3])
            || !\hash_equals($previousSlot, (string)$matches[4])
            || \hash_equals((string)$matches[3], (string)$matches[4])
        ) {
            throw new \RuntimeException(
                'Gateway upgrade rollback request is malformed or bound to another transaction.',
            );
        }
        $requestedAtText = (string)$matches[5];
        $maximum = (string)PHP_INT_MAX;
        if (\strlen($requestedAtText) > \strlen($maximum)
            || (\strlen($requestedAtText) === \strlen($maximum)
                && \strcmp($requestedAtText, $maximum) > 0)
        ) {
            throw new \RuntimeException(
                'Gateway upgrade rollback request time is outside the supported range.',
            );
        }
        // Legacy /2 requests have no boot-bound monotonic authority. They are
        // recognized only as an idempotent request for the safer old-slot
        // direction already proved above; wall time must never re-authorize or
        // block that fail-safe recovery decision.
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $this->lastAtomicWriteCommittedAfterImage = null;
        $directory = \dirname($path);
        $parentStatus = @\lstat($directory);
        $existing = @\lstat($path);
        if (!\is_array($parentStatus)
            || \is_link($directory)
            || !\is_dir($directory)
            || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Gateway host state parent is unsafe.');
        }
        $seal = function ($handle, string $candidate) use ($parentStatus): void {
            if (\PHP_OS_FAMILY !== 'Windows') {
                if (!isset($parentStatus['uid'], $parentStatus['gid'])
                ) {
                    throw new \RuntimeException(
                        'Unable to resolve gateway host state parent ownership.'
                    );
                }
                $ownerApplied = \function_exists('fchown')
                    && @\fchown($handle, (int)$parentStatus['uid']);
                if (!$ownerApplied && \function_exists('chown')) {
                    $ownerApplied = @\chown($candidate, (int)$parentStatus['uid']);
                }
                $groupApplied = \function_exists('fchgrp')
                    && @\fchgrp($handle, (int)$parentStatus['gid']);
                if (!$groupApplied && \function_exists('chgrp')) {
                    $groupApplied = @\chgrp($candidate, (int)$parentStatus['gid']);
                }
                if (!$ownerApplied || !$groupApplied || \is_link($candidate)) {
                    throw new \RuntimeException(
                        'Unable to seal gateway host state ownership.'
                    );
                }
            }
        };
        try {
            GatewayProjectStateFilesystem::atomicWrite(
                $path,
                $contents,
                $mode,
                $seal,
            );
        } catch (\Throwable $throwable) {
            try {
                $this->lastAtomicWriteCommittedAfterImage =
                    $this->captureAtomicWriteCommittedAfterImage(
                        $path,
                        $contents,
                        $mode,
                        $parentStatus,
                        \is_array($existing) ? $existing : null,
                    );
            } catch (\Throwable) {
                $this->lastAtomicWriteCommittedAfterImage = null;
            }
            throw $throwable;
        }
        $published = @\lstat($path);
        if (!\is_array($published)
            || !$this->isRegularFileStatus($published)
            || (int)($published['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((int)($published['mode'] ?? 0)) & 0777) !== $mode)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((int)($published['uid'] ?? -1) !== (int)$parentStatus['uid']
                    || (int)($published['gid'] ?? -1) !== (int)$parentStatus['gid']))
        ) {
            throw new \RuntimeException('Published gateway host state is unsafe.');
        }
    }

    /**
     * @param array<string|int,mixed> $parentStatus
     * @param array<string|int,mixed>|null $previousIdentity
     * @return array{
     *   path:string,
     *   sha256:string,
     *   size:int,
     *   mode:int,
     *   identity:array<string|int,mixed>
     * }|null
     */
    private function captureAtomicWriteCommittedAfterImage(
        string $path,
        string $contents,
        int $mode,
        array $parentStatus,
        ?array $previousIdentity,
    ): ?array {
        $published = @\lstat($path);
        if (!\is_array($published)
            || \is_link($path)
            || !$this->isRegularFileStatus($published)
            || (int)($published['nlink'] ?? 0) !== 1
            || (int)($published['size'] ?? -1) !== \strlen($contents)
            || ($previousIdentity !== null
                && $this->sameFileState($previousIdentity, $published))
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($published['mode'] ?? 0)) & 0777) !== $mode
                    || (int)($published['uid'] ?? -1)
                        !== (int)($parentStatus['uid'] ?? -2)
                    || (int)($published['gid'] ?? -1)
                        !== (int)($parentStatus['gid'] ?? -2)))
        ) {
            return null;
        }
        $consumed = $this->consumeStableRegularFile(
            $path,
            \max(1, \strlen($contents)),
            'Committed gateway host state after-image',
            true,
        );
        $after = @\lstat($path);
        if (!\is_array($after)
            || !$this->sameFileState($published, $after)
            || !\hash_equals($contents, $consumed['bytes'])
        ) {
            return null;
        }
        return [
            'path' => $path,
            'sha256' => $consumed['sha256'],
            'size' => $consumed['size'],
            'mode' => $mode,
            'identity' => $after,
        ];
    }

    private function atomicWriteCommittedAfterImageMatches(
        string $path,
        string $contents,
        int $mode,
    ): bool {
        $receipt = $this->lastAtomicWriteCommittedAfterImage;
        if ($receipt === null
            || !\hash_equals($path, $receipt['path'])
            || !\hash_equals(\hash('sha256', $contents), $receipt['sha256'])
            || \strlen($contents) !== $receipt['size']
            || $mode !== $receipt['mode']
        ) {
            return false;
        }
        try {
            $before = @\lstat($path);
            $consumed = $this->consumeStableRegularFile(
                $path,
                \max(1, \strlen($contents)),
                'Committed gateway host state after-image',
                true,
            );
            $after = @\lstat($path);
            return \is_array($before)
                && \is_array($after)
                && $this->sameFileState($receipt['identity'], $before)
                && $this->sameFileState($before, $after)
                && \hash_equals($contents, $consumed['bytes'])
                && \hash_equals($receipt['sha256'], $consumed['sha256']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Nested package phases inherit the earliest lifecycle deadline. A durable
     * write callback is intentionally not post-checked: its exact after-image
     * or journal remains the authoritative commit result.
     */
    private function withOperationDeadline(
        ?float $deadlineMonotonic,
        \Closure $callback,
    ): mixed {
        $now = \hrtime(true) / 1_000_000_000;
        if ($deadlineMonotonic !== null && !\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException(
                'Gateway package operation deadline is invalid.',
            );
        }
        $deadline = $deadlineMonotonic
            ?? ($now + self::PACKAGE_OPERATION_TIMEOUT_SECONDS);
        $parent = $this->activeOperationDeadline();
        if ($parent !== null) {
            $deadline = \min($deadline, $parent);
        }
        if ($deadline <= $now) {
            throw new \RuntimeException(
                'Gateway package operation deadline was exhausted.',
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
                'Gateway package operation timeout is invalid.',
            );
        }
        $deadline = $this->activeOperationDeadline();
        if ($deadline === null) {
            return $maximumSeconds;
        }
        $remaining = $deadline - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'Gateway package operation deadline was exhausted.',
            );
        }
        return \min($maximumSeconds, $remaining);
    }

    private function assertOperationDeadlineAvailable(string $stage): void
    {
        try {
            $this->remainingOperationDeadline(
                self::PACKAGE_OPERATION_TIMEOUT_SECONDS,
            );
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                'Gateway package operation deadline was exhausted while '
                    . $stage . '.',
                0,
                $exception,
            );
        }
    }

    private function deadlineProgress(string $stage): null
    {
        $this->assertOperationDeadlineAvailable($stage);
        return null;
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(
        array $command,
        ?array $windowsHelperProof = null,
        float $maximumSeconds = 120.0,
    ): array
    {
        $timeoutSeconds = $this->remainingOperationDeadline($maximumSeconds);
        if ($timeoutSeconds < self::MIN_COMMAND_TIMEOUT_SECONDS) {
            throw new \RuntimeException(
                'Gateway package operation deadline was exhausted before command execution.',
            );
        }
        return GatewayBoundedCommandRunner::run(
            $command,
            $timeoutSeconds,
            windowsHelperProof: $windowsHelperProof,
            deadlineMonotonic: $this->activeOperationDeadline(),
        );
    }

    private function removeSlotTree(string $slot): void
    {
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Gateway slot must be A or B.');
        }
        $directory = $this->paths->slotDir($slot);
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        $this->assertSlotHasNoLiveProcesses($slot, $directory);
        $this->removeTree($directory);
    }

    private function assertSlotHasNoLiveProcesses(string $slot, string $directory): void
    {
        $this->assertNoLiveProcessesForRuntimePaths(
            [$directory],
            'gateway slot ' . $slot,
        );
    }

    /**
     * Prove that no process executable resolves to an exact host launcher or
     * below one of the supplied immutable runtime roots. An indeterminate
     * process table is a hard failure; this method never sends a signal.
     *
     * @param list<string> $paths
     */
    public function assertNoLiveProcessesForRuntimePaths(
        array $paths,
        string $operation = 'gateway runtime mutation',
        ?float $deadlineMonotonic = null,
    ): void {
        $this->withOperationDeadline(
            $deadlineMonotonic,
            function () use ($paths, $operation): void {
                $this->assertNoLiveProcessesForRuntimePathsWithinDeadline(
                    $paths,
                    $operation,
                );
            },
        );
    }

    /** @param list<string> $paths */
    private function assertNoLiveProcessesForRuntimePathsWithinDeadline(
        array $paths,
        string $operation,
    ): void {
        if ($paths === [] || \count($paths) > 8) {
            throw new \InvalidArgumentException(
                'Gateway process proof requires one to eight exact runtime paths.'
            );
        }
        $files = [];
        $prefixes = [];
        foreach ($paths as $path) {
            if (!\is_string($path)
                || $path === ''
                || \str_contains($path, "\0")
                || \is_link($path)
            ) {
                throw new \RuntimeException(
                    'Gateway process proof path is unsafe for ' . $operation . '.'
                );
            }
            $resolved = \realpath($path);
            if (!\is_string($resolved)) {
                throw new \RuntimeException(
                    'Gateway process proof path is missing for ' . $operation . ': '
                        . $path
                );
            }
            if (\is_dir($resolved)) {
                $prefixes[] = \rtrim($resolved, '/\\') . '/';
                $prefixes[] = \rtrim($resolved, '/\\') . '\\';
            } elseif (\is_file($resolved)) {
                $files[] = $resolved;
            } else {
                throw new \RuntimeException(
                    'Gateway process proof path is special for ' . $operation . '.'
                );
            }
        }
        $normalize = static function (string $path): string {
            $path = \preg_replace('/\s+\(deleted\)\z/', '', \trim($path)) ?? '';
            $path = \str_replace('\\', '/', $path);
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
        };
        $normalizedFiles = \array_map($normalize, $files);
        $normalizedPrefixes = \array_map($normalize, $prefixes);
        $matches = static function (string $path) use (
            $normalize,
            $normalizedFiles,
            $normalizedPrefixes,
        ): bool {
            $path = $normalize($path);
            if (\in_array($path, $normalizedFiles, true)) {
                return true;
            }
            foreach ($normalizedPrefixes as $prefix) {
                if (\str_starts_with($path, $prefix)) {
                    return true;
                }
            }
            return false;
        };

        if (\PHP_OS_FAMILY === 'Linux' && \is_dir('/proc')) {
            try {
                $processes = new \FilesystemIterator(
                    '/proc',
                    \FilesystemIterator::SKIP_DOTS
                        | \FilesystemIterator::CURRENT_AS_FILEINFO
                        | \FilesystemIterator::KEY_AS_PATHNAME,
                );
            } catch (\UnexpectedValueException $exception) {
                throw new \RuntimeException(
                    'Unable to enumerate Linux processes for ' . $operation . '.',
                    0,
                    $exception,
                );
            }
            $visited = 0;
            foreach ($processes as $process) {
                if (($visited & 255) === 0) {
                    $this->assertOperationDeadlineAvailable(
                        'enumerating Linux processes for ' . $operation,
                    );
                }
                if (++$visited > self::MAX_PROC_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Linux process enumeration exceeded the fixed safety limit.'
                    );
                }
                $pid = $process->getFilename();
                if (\preg_match('/\A[1-9][0-9]*\z/D', $pid) !== 1) {
                    continue;
                }
                $executable = @\readlink(
                    $process->getPathname() . DIRECTORY_SEPARATOR . 'exe',
                );
                if (\is_string($executable) && $matches($executable)) {
                    throw new \RuntimeException(
                        'Gateway runtime for ' . $operation
                            . ' is still used by process ' . (int)$pid . '.'
                    );
                }
            }
            return;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $script = '$ErrorActionPreference="Stop"; '
                . 'Get-CimInstance Win32_Process | ForEach-Object {'
                . ' if ($null -ne $_.ExecutablePath) {'
                . ' [Console]::Out.WriteLine(([string]$_.ProcessId) + "`t" + $_.ExecutablePath)'
                . ' }}';
            $result = $this->runCommand([
                ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
                    ->windowsPowerShellExecutable(),
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                $script,
            ]);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Unable to enumerate Windows processes for ' . $operation
                        . ': ' . $result['output']
                );
            }
            foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
                [$pid, $executable] = \array_pad(\explode("\t", $line, 2), 2, '');
                if ($matches($executable)) {
                    throw new \RuntimeException(
                        'Gateway runtime for ' . $operation
                            . ' is still used by process ' . (int)$pid . '.'
                    );
                }
            }
            return;
        }

        $result = $this->runCommand(['/bin/ps', '-ww', '-axo', 'pid=,comm=']);
        if ($result['code'] !== 0) {
            throw new \RuntimeException(
                'Unable to enumerate POSIX processes for ' . $operation
                    . ': ' . $result['output']
            );
        }
        foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
            if (\preg_match('/^\s*([0-9]+)\s+(.+)$/', $line, $match) === 1
                && $matches((string)$match[2])
            ) {
                throw new \RuntimeException(
                    'Gateway runtime for ' . $operation
                        . ' is still used by process ' . (int)$match[1] . '.'
                );
            }
        }
    }

    private function removeTree(string $directory): void
    {
        $entries = $this->collectRemovableTree($directory);
        $this->removeCollectedTree($directory, $entries);
    }

    /** @return array<int,array<string,mixed>> */
    private function collectRemovableTree(string $directory): array
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return [];
        }
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Refusing to remove an unsafe gateway runtime tree: ' . $directory
            );
        }
        return GatewayBoundedTreeWalker::collect(
            $directory,
            true,
            true,
            self::MAX_PACKAGE_COMPONENTS + self::MAX_PACKAGE_DIRECTORIES + 4,
            self::MAX_PACKAGE_PATH_DEPTH,
            fn (): null => $this->deadlineProgress(
                'selecting a gateway runtime tree for removal',
            ),
        );
    }

    /** @param array<int,array<string,mixed>> $entries */
    private function removeCollectedTree(string $directory, array $entries): void
    {
        if ($entries === []) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Gateway runtime tree appeared after removal selection: ' . $directory
                );
            }
            return;
        }
        $visited = 0;
        foreach ($entries as $entry) {
            if (($visited++ & 255) === 0) {
                $this->assertOperationDeadlineAvailable(
                    'removing a verified gateway runtime tree',
                );
            }
            $path = (string)($entry['path'] ?? '');
            GatewayBoundedTreeWalker::revalidate($entry);
            $removed = ($entry['directory'] ?? false) === true
                ? @\rmdir($path)
                : @\unlink($path);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove a verified gateway runtime entry: ' . $path
                );
            }
        }
        if (\file_exists($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Unable to completely remove the gateway runtime tree: ' . $directory
            );
        }
        $this->syncParentDirectory(\dirname($directory), 'gateway runtime tree removal');
    }
}
