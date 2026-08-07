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
    // Keep the signed WLS-UPGRADE/1 envelope byte-compatible with already
    // installed stable launchers. New launchers treat this only as the time to
    // reach OBSERVING; the Broker's monotonic marker owns the full 300s health
    // window after readiness.
    private const UPGRADE_ACTIVATION_TIMEOUT_SECONDS = 300;
    private const UPGRADE_TOTAL_TIMEOUT_SECONDS = 900;
    private const INSTALL_LOCK_TIMEOUT_SECONDS = 30;
    private const SLOT_RETENTION_SECONDS = 86_400;
    private const SLOT_RETENTION_MILLISECONDS = 86_400_000;
    private const MAX_ATOMIC_RECOVERY_BACKUPS_PER_TARGET = 8;
    private const MAX_ATOMIC_RECOVERY_TEMPORARIES_PER_TARGET = 8;
    private const MAX_STABLE_LAUNCHER_CANDIDATES = 8;
    private const MAX_ATOMIC_RECOVERY_DIRECTORY_ENTRIES = 16_384;

    private const REQUIRED_CAPABILITIES = [
        'broker_sideband_actions',
        'dual_control_channels',
        'native_peer_identity',
        'neutral_default_certificate',
        'no_follow_snapshot',
        'privilege_separation',
        'self_contained_nginx',
        'self_contained_php',
        'singleton_fencing',
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly NginxRuntimeArtifact $artifact = new NginxRuntimeArtifact(),
        private readonly ?string $trustedKeysFile = null,
        private readonly ?GatewayPlatformServiceInstaller $platform = null,
    ) {
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
    public function stage(string $packageDirectory, string $profile): array
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException('Gateway profile must be default or ipv4-only.');
        }

        $verified = $this->verifyPackage($packageDirectory, $profile);
        $this->paths->ensureDirectories();
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
            $previousActive = $this->withInstallLock(function () use ($slot): string {
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
                return $this->activeSlotOrEmpty();
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
                    ->secureInstalledRuntimeSlot($slotDirectory);
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
                'release_ready' => (bool)$verified['manifest']['release_ready'],
                'test_mode' => $this->paths->isTestMode(),
                'profile' => $profile,
                'previous_active_slot' => $previousActive,
            ];
        });
    }

    public function activate(string $slot): void
    {
        $slot = \strtoupper(\trim($slot));
        $this->withStagingLock($slot, function () use ($slot): null {
            $verification = $this->artifact->verify($this->paths->slotDir($slot), 'host_gateway');
            if (!($verification['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway slot cannot be activated: ' . (string)($verification['reason'] ?? 'invalid')
                );
            }
            $manifest = $this->installedManifest($slot);
            if (!$this->paths->isTestMode() && !($manifest['release_ready'] ?? false)) {
                throw new \RuntimeException('A non-release-ready gateway slot cannot become active.');
            }
            if ($this->paths->isTestMode() && !($manifest['test_mode'] ?? false)) {
                throw new \RuntimeException('Test gateway cannot activate a production slot.');
            }
            $this->withInstallLock(function () use ($slot): null {
                $this->assertNoFailedInitialBootstrapCleanup();
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
                    return null;
                }
                // First activation has no rollback slot. Publish only the one
                // authoritative pointer: Controller startup deliberately
                // derives the opposite slot when previous-slot is absent.
                // Writing a synthetic previous pointer first creates a power-
                // loss window where the platform service is installed but no
                // active slot can ever be launched or restaged automatically.
                $this->atomicWrite($this->paths->activeSlotFile(), $slot . PHP_EOL, 0640);
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
    public function beginUpgradeActivation(array $staged): array
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
            $preparedAt = \time();
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
            $payload = "WLS-UPGRADE/1\n"
                . 'host_id=' . $this->hostId() . "\n"
                . 'from=' . $from . "\n"
                . 'to=' . $to . "\n"
                . 'prepared_at=' . $preparedAt . "\n"
                . 'deadline=' . $activationDeadline . "\n"
                . 'runtime_generation=' . $runtimeGeneration . "\n"
                . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
            try {
                $signature = \hash_hmac('sha256', $payload, $key);
            } finally {
                \sodium_memzero($key);
            }
            $intent = $payload . 'signature=' . $signature . "\n";
            return $this->withInstallLock(function () use (
                $from,
                $to,
                $runtimeGeneration,
                $preparedAt,
                $activationDeadline,
                $intent,
            ): array {
                $this->assertNoFailedInitialBootstrapCleanup();
                if (!\hash_equals($from, $this->paths->activeSlot())) {
                    throw new \RuntimeException(
                        'Gateway active slot changed before upgrade pointer transaction.'
                    );
                }
                $this->removeTerminalOrphanUpgradeState();
                $this->atomicWrite($this->paths->upgradeIntentFile(), $intent, 0600);
                try {
                    $this->atomicWrite(
                        $this->paths->previousSlotFile(),
                        $from . PHP_EOL,
                        0640,
                    );
                    $this->atomicWrite(
                        $this->paths->activeSlotFile(),
                        $to . PHP_EOL,
                        0640,
                    );
                } catch (\Throwable $throwable) {
                    try {
                        $this->atomicWrite(
                            $this->paths->activeSlotFile(),
                            $from . PHP_EOL,
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
                return [
                    'from' => $from,
                    'to' => $to,
                    'runtime_generation' => $runtimeGeneration,
                    'prepared_at' => $preparedAt,
                    'deadline' => $activationDeadline,
                    'activation_timeout_seconds' => self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS,
                    'observation_seconds' => 300,
                ];
            });
        });
    }

    public function rollbackUpgradeActivation(string $failedSlot, string $previousSlot): void
    {
        $failedSlot = \strtoupper(\trim($failedSlot));
        $previousSlot = \strtoupper(\trim($previousSlot));
        $this->withStagingLocks(
            [$failedSlot, $previousSlot],
            function () use ($failedSlot, $previousSlot): null {
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
                $verification = $this->artifact->verify(
                    $this->paths->slotDir($previousSlot),
                    'host_gateway',
                );
                if (!($verification['ok'] ?? false)) {
                    throw new \RuntimeException(
                        'Gateway previous slot is not valid for upgrade rollback.'
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
                $intentBinding = $this->upgradeIntentBinding($intent);
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
                            $requestedAt = \time();
                            if ($requestedAt < $intentBinding['prepared_at']
                                || $requestedAt > $intentBinding['rollback_deadline']
                            ) {
                                throw new \RuntimeException(
                                    'Gateway upgrade rollback request time is outside the signed transaction.',
                                );
                            }
                            $this->atomicWrite(
                                $rollbackRequest,
                                "WLS-UPGRADE-ROLLBACK/2\n"
                                    . 'intent_sha256=' . $intentBinding['digest'] . "\n"
                                    . 'intent_nonce=' . $intentBinding['nonce'] . "\n"
                                    . 'from=' . $failedSlot . "\n"
                                    . 'to=' . $previousSlot . "\n"
                                    . 'at=' . $requestedAt . "\n"
                                    . 'request_nonce=' . \bin2hex(\random_bytes(16)) . "\n",
                                0600,
                            );
                        }
                        $this->atomicWrite(
                            $this->paths->activeSlotFile(),
                            $previousSlot . PHP_EOL,
                            0640,
                        );
                    }
                    try {
                        $this->atomicWrite(
                            $this->paths->previousSlotFile(),
                            $failedSlot . PHP_EOL,
                            0640,
                        );
                    } catch (\Throwable) {
                        // The signed intent plus request remain authoritative.
                    }
                    return null;
                });
                return null;
            },
        );
    }

    public function discardStaged(string $slot): void
    {
        $slot = \strtoupper(\trim($slot));
        $this->withStagingLock($slot, function () use ($slot): null {
            if (!\in_array($slot, ['A', 'B'], true)) {
                throw new \InvalidArgumentException('Gateway slot must be A or B.');
            }
            [$active, $clearRollbackMarker] = $this->withInstallLock(
                function () use ($slot): array {
                    $active = $this->activeSlotOrEmpty();
                    if ($active === $slot) {
                        throw new \RuntimeException(
                            'Refusing to discard the active gateway slot.'
                        );
                    }
                    return [
                        $active,
                        $this->rolledBackMarkerMatchesSlotArtifact($slot),
                    ];
                },
            );
            if ($active === $slot) {
                throw new \RuntimeException('Refusing to discard the active gateway slot.');
            }
            if ($active === '') {
                // A successful first stage has already installed the stable
                // bootstrap and its trust identity. If a later platform step
                // fails before activation, removing only the inactive slot
                // would leave a host-level half-installation whose launcher is
                // no longer backed by any installed runtime.
                $this->withInstallLock(function () use ($slot): null {
                    $this->prepareFailedInitialBootstrapCleanup($slot);
                    return null;
                });
                $this->recoverFailedInitialBootstrapCleanup($slot);
                return null;
            }
            $this->removeSlotTree($slot);
            $slotDirectory = $this->paths->slotDir($slot);
            if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                throw new \RuntimeException(
                    'Discarded gateway slot tree could not be proven absent.',
                );
            }
            if ($clearRollbackMarker) {
                $this->withInstallLock(function () use ($slot): null {
                    $this->clearInactiveSlotReplacementMarkers($slot);
                    return null;
                });
            }
            return null;
        });
    }

    public function rollbackActivation(string $failedSlot, string $previousSlot): void
    {
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
                    $this->atomicWrite($activeFile, $previousSlot . PHP_EOL, 0640);
                    return null;
                });
            } else {
                $this->withInstallLock(function () use ($failedSlot): null {
                    if (!\hash_equals($failedSlot, $this->activeSlotOrEmpty())) {
                        throw new \RuntimeException(
                            'Gateway active slot changed during installation rollback.'
                        );
                    }
                    $this->prepareFailedInitialBootstrapCleanup($failedSlot);
                    return null;
                });
                $this->recoverFailedInitialBootstrapCleanup($failedSlot);
                return null;
            }
            $this->removeSlotTree($failedSlot);
            return null;
        });
    }

    private function prepareFailedInitialBootstrapCleanup(string $failedSlot): void
    {
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
        $expected = \is_array($releaseManifest)
            ? \strtolower((string)($releaseManifest['components'][$launcherComponent]['sha256'] ?? ''))
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
            || !\hash_equals($expected, $actual)
            || !\hash_equals($expected, $trusted)
        ) {
            throw new \RuntimeException(
                'Failed initial gateway bootstrap identity cannot be safely removed.'
            );
        }
        $nonce = \bin2hex(\random_bytes(8));
        $intent = $this->failedInitialCleanupIntentFile();
        if (\file_exists($intent) || \is_link($intent)) {
            throw new \RuntimeException(
                'A failed initial gateway cleanup intent is already pending.'
            );
        }
        $this->atomicWrite(
            $intent,
            "WLS-FAILED-INITIAL-CLEANUP/1\n"
                . 'slot=' . $failedSlot . "\n"
                . 'launcher_sha256=' . $expected . "\n"
                . 'nonce=' . $nonce . "\n",
            0600,
        );
    }

    private function recoverFailedInitialBootstrapCleanup(?string $expectedSlot = null): void
    {
        $this->assertPlatformRemovalCompleted();
        $intent = $this->failedInitialCleanupIntentFile();
        $contents = $this->readOptionalStableRegularFile(
            $intent,
            256,
            'Failed initial gateway cleanup intent',
        );
        if ($contents === null) {
            return;
        }
        if (\preg_match(
            '/\AWLS-FAILED-INITIAL-CLEANUP\/1\nslot=([AB])\n'
                . 'launcher_sha256=([a-f0-9]{64})\nnonce=([a-f0-9]{16})\n\z/D',
            $contents,
            $matches,
        ) !== 1) {
            throw new \RuntimeException('Failed initial gateway cleanup intent is malformed.');
        }
        $failedSlot = (string)$matches[1];
        $launcherDigest = (string)$matches[2];
        $nonce = (string)$matches[3];
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
        $this->withInstallLock(function () use ($failedSlot, $intentDigest): null {
            $this->assertFailedInitialCleanupIntentDigest($intentDigest);
            $active = $this->activeSlotOrEmpty();
            if ($active !== '' && !\hash_equals($failedSlot, $active)) {
                throw new \RuntimeException(
                    'Failed initial gateway cleanup conflicts with a newer active slot.'
                );
            }
            return null;
        });
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
            256,
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
     *   manifest:array<string,mixed>
     * }
     */
    public function verifyPackage(string $packageDirectory, string $profile): array
    {
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
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $commands[] = [
                $slotDirectory . DIRECTORY_SEPARATOR
                    . \str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $this->componentPath('wls-bounded-command'),
                    ),
                '--self-test',
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
                $this->atomicWrite($identityFile, $actual . PHP_EOL, 0600);
                $trusted = $actual;
            }
            if (!\hash_equals($trusted, $actual)) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity verification failed.'
                );
            }
            if (!\hash_equals($expectedDigest, $actual)) {
                throw new \RuntimeException(
                    'Gateway package requires an incompatible bootstrap generation; explicit host rebootstrap is required before slot activation.'
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
            $this->atomicWrite($identityFile, $expectedDigest . PHP_EOL, 0600);
        }
        $this->copyStableLauncher($source, $target, $expectedDigest);
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
        $this->atomicWrite($file, $hostId . PHP_EOL, 0600);
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
        $this->atomicWrite($file, \bin2hex(\random_bytes(32)) . PHP_EOL, 0600);
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
        if ($this->paths->isTestMode() || \PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $status = @\lstat($path);
        if (!\is_array($status)
            || !$this->isRegularFileStatus($status)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['uid'] ?? -1) !== 0
            || (((int)($status['mode'] ?? 0)) & 0022) !== 0
            || (((int)($status['mode'] ?? 0)) & 0100) === 0
        ) {
            throw new \RuntimeException('Stable gateway launcher permissions are unsafe.');
        }
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
                $mode = $this->paths->isTestMode() ? 0755 : 0550;
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
            'php' => 'bin/php' . $suffix,
            'nginx' => 'bin/nginx' . $suffix,
            'wls-gateway-broker' => 'bin/wls-gateway-broker' . $suffix,
            'wls-gateway-launcher' => 'bin/wls-gateway-launcher' . $suffix,
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $files['wls-bounded-command'] = 'bin/wls-bounded-command.exe';
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
                || ($name !== 'controller'
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
            'sbom.cdx.json',
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
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

    private function installedComponentMode(int $packageMode): int
    {
        if ($this->paths->isTestMode() || \PHP_OS_FAMILY === 'Windows') {
            return $packageMode;
        }
        // The immutable host slot is root-owned and readable by the dedicated
        // Controller group. Record the final sealed mode in the runtime
        // manifest so post-seal verification checks the actual trust state
        // rather than the more permissive package transport mode.
        return ($packageMode & 0111) !== 0 ? 0550 : 0440;
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

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withInstallLock(callable $callback): mixed
    {
        return $this->withHostPackageLock(
            'package-install.lock',
            'installation',
            function () use ($callback): mixed {
                $this->cleanupHostAtomicWriteRecoveryBackupsLocked();
                return $callback();
            },
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
                'maximum_bytes' => 256,
                'mode' => 0600,
                'label' => 'Failed initial gateway cleanup intent',
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
                && ((((int)$status['mode']) & 0777) !== $mode
                    || (int)$status['uid'] !== (int)$parentStatus['uid']
                    || (int)$status['gid'] !== (int)$parentStatus['gid']))
        ) {
            throw new \RuntimeException($label . ' has unsafe authority or permissions.');
        }
        return $status;
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

    /** @return array{from:string,to:string,at:int} */
    private function upgradeRollbackRecoveryBinding(string $contents): array
    {
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

    /** @return array{slot:string,launcher_sha256:string} */
    private function failedInitialRecoveryBinding(string $contents): array
    {
        if (\preg_match(
            '/\AWLS-FAILED-INITIAL-CLEANUP\/1\n'
                . 'slot=([AB])\n'
                . 'launcher_sha256=([a-f0-9]{64})\n'
                . 'nonce=[a-f0-9]{16}\n\z/D',
            $contents,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Failed initial gateway cleanup intent syntax is invalid.'
            );
        }
        return [
            'slot' => (string)$matches[1],
            'launcher_sha256' => (string)$matches[2],
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
    }

    /**
     * @param array{from:string,to:string,runtime_generation:string} $binding
     */
    private function assertHostRecoveryIntentArtifacts(
        array $binding,
        string $label,
    ): void {
        $this->assertHostRecoverySlotArtifact($binding['from'], $label);
        $this->assertHostRecoverySlotArtifact(
            $binding['to'],
            $label,
            $binding['runtime_generation'],
        );
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
    ): mixed
    {
        $this->paths->ensureDirectories();
        ($this->platform ?? new GatewayPlatformServiceInstaller($this->paths))
            ->securePackageTransactionTrust();
        // Package transactions belong to the host trust domain. Keeping this
        // lock in controller-writable state lets a failed or compromised
        // Controller replace the lock and block administrator recovery.
        if (\preg_match('/\Apackage-(?:install|stage-[ab])\.lock\z/D', $lockName) !== 1) {
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
            \usleep(2_000);
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
        $lockBudget = self::INSTALL_LOCK_TIMEOUT_SECONDS * 1_000_000_000;
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
            \usleep(20_000);
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
        if (\file_exists($this->paths->upgradeIntentFile())
            || \is_link($this->paths->upgradeIntentFile())
            || \preg_match(
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
            ) !== 1
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
     *   digest:string,
     *   nonce:string,
     *   from:string,
     *   to:string,
     *   runtime_generation:string,
     *   prepared_at:int,
     *   rollback_deadline:int
     * }
     */
    private function upgradeIntentBinding(string $intent): array
    {
        if (\preg_match(
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
        ) !== 1
            || !\hash_equals($this->hostId(), (string)$match[1])
            || \hash_equals((string)$match[2], (string)$match[3])
            || (int)$match[4] > PHP_INT_MAX - self::UPGRADE_TOTAL_TIMEOUT_SECONDS
            || (int)$match[5]
                !== (int)$match[4] + self::UPGRADE_ACTIVATION_TIMEOUT_SECONDS
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
        if (!\hash_equals($expectedSignature, (string)$match[8])) {
            throw new \RuntimeException(
                'Gateway signed activation intent authentication failed.'
            );
        }
        return [
            'digest' => \hash('sha256', $intent),
            'nonce' => (string)$match[7],
            'from' => (string)$match[2],
            'to' => (string)$match[3],
            'runtime_generation' => (string)$match[6],
            'prepared_at' => (int)$match[4],
            'rollback_deadline' => (int)$match[4] + self::UPGRADE_TOTAL_TIMEOUT_SECONDS,
        ];
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
            '/\AWLS-UPGRADE-ROLLBACK\/2\n'
                . 'intent_sha256=([a-f0-9]{64})\n'
                . 'intent_nonce=([a-f0-9]{32})\n'
                . 'from=([AB])\nto=([AB])\n'
                . 'at=([1-9][0-9]{0,18})\n'
                . 'request_nonce=([a-f0-9]{32})\n\z/D',
            $contents,
            $matches,
        ) !== 1
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
        $requestedAt = (int)$requestedAtText;
        if ($requestedAt < $intentBinding['prepared_at']
            || $requestedAt > $intentBinding['rollback_deadline']
        ) {
            throw new \RuntimeException(
                'Gateway upgrade rollback request time is outside the signed transaction.',
            );
        }
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = \dirname($path);
        $parentStatus = @\lstat($directory);
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
        GatewayProjectStateFilesystem::atomicWrite($path, $contents, $mode, $seal);
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
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command, ?array $windowsHelperProof = null): array
    {
        return GatewayBoundedCommandRunner::run(
            $command,
            windowsHelperProof: $windowsHelperProof,
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
        $resolved = \realpath($directory);
        if (!\is_string($resolved) || \is_link($directory) || !\is_dir($resolved)) {
            throw new \RuntimeException(
                'Gateway slot process ownership is indeterminate for unsafe slot ' . $slot . '.'
            );
        }
        $prefixes = [
            \rtrim($resolved, '/\\') . DIRECTORY_SEPARATOR,
            \rtrim($resolved, '/\\') . (DIRECTORY_SEPARATOR === '/' ? '\\' : '/'),
        ];
        $matchesSlot = static function (string $path) use ($prefixes): bool {
            $path = \preg_replace('/\s+\(deleted\)\z/', '', \trim($path)) ?? '';
            foreach ($prefixes as $prefix) {
                if (\PHP_OS_FAMILY === 'Windows') {
                    if (\str_starts_with(\strtolower($path), \strtolower($prefix))) {
                        return true;
                    }
                } elseif (\str_starts_with($path, $prefix)) {
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
                    'Unable to enumerate Linux processes before gateway slot removal.',
                    0,
                    $exception,
                );
            }
            $visited = 0;
            foreach ($processes as $process) {
                $visited++;
                if ($visited > self::MAX_PROC_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Linux process enumeration exceeded the fixed safety limit.'
                    );
                }
                $pidText = $process->getFilename();
                if (\preg_match('/\A[1-9][0-9]*\z/D', $pidText) !== 1) {
                    continue;
                }
                $executableLink = $process->getPathname() . DIRECTORY_SEPARATOR . 'exe';
                $executable = @\readlink($executableLink);
                if (\is_string($executable) && $matchesSlot($executable)) {
                    throw new \RuntimeException(
                        'Gateway slot ' . $slot . ' is still used by process '
                            . (int)$pidText . '.'
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
                    'Unable to enumerate Windows process ownership before gateway slot removal: '
                        . $result['output']
                );
            }
            foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
                [$pid, $executable] = \array_pad(\explode("\t", $line, 2), 2, '');
                if ($matchesSlot($executable)) {
                    throw new \RuntimeException(
                        'Gateway slot ' . $slot . ' is still used by process '
                            . (int)$pid . '.'
                    );
                }
            }
            return;
        }

        $result = $this->runCommand(['/bin/ps', '-ww', '-axo', 'pid=,comm=']);
        if ($result['code'] !== 0) {
            throw new \RuntimeException(
                'Unable to enumerate POSIX process ownership before gateway slot removal: '
                    . $result['output']
            );
        }
        foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
            if (\preg_match('/^\s*([0-9]+)\s+(.+)$/', $line, $match) === 1
                && $matchesSlot((string)$match[2])
            ) {
                throw new \RuntimeException(
                    'Gateway slot ' . $slot . ' is still used by process '
                        . (int)$match[1] . '.'
                );
            }
        }
    }

    private function removeTree(string $directory): void
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Refusing to remove an unsafe gateway runtime tree: ' . $directory
            );
        }
        $entries = GatewayBoundedTreeWalker::collect(
            $directory,
            true,
            true,
            self::MAX_PACKAGE_COMPONENTS + self::MAX_PACKAGE_DIRECTORIES + 4,
            self::MAX_PACKAGE_PATH_DEPTH,
        );
        foreach ($entries as $entry) {
            $path = $entry['path'];
            GatewayBoundedTreeWalker::revalidate($entry);
            $removed = $entry['directory'] ? @\rmdir($path) : @\unlink($path);
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
