<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Host-scoped WLS 2.0 gateway paths.
 *
 * Nothing below this root is project-owned. The project only seeds an
 * immutable runtime slot and submits desired state through wls-edge/2.
 */
final class GatewayPaths
{
    public const PROTOCOL = 'wls-edge/2';
    public const IMPLEMENTATION_LEVEL = 'wls-2.0';
    public const SECURITY_PROFILE = 'native-broker-v1';
    private const SYSTEMD_DEFINITION_DIRECTORY = '/etc/weline-gateway';
    private const SYSTEMD_SERVICE_FILE = 'weline-wls-gateway-v2.service';
    private const SYSTEMD_UNIT_DIRECTORY = '/etc/systemd/system';
    /**
     * Host-gateway upstream sockets can remain attached to one Direct
     * SO_REUSEPORT Worker for this long. Worker reload drain deadlines must
     * outlive this cache before retiring that Worker.
     */
    public const UPSTREAM_KEEPALIVE_TIMEOUT_SEC = 10;

    public function home(): string
    {
        $override = \getenv('WLS_GATEWAY_HOME');
        if ($this->isTestMode()) {
            if ($override === false || \trim((string)$override) === '') {
                throw new \RuntimeException(
                    'WLS_GATEWAY_TEST_MODE requires an explicit WLS_GATEWAY_HOME below the system temporary directory.'
                );
            }
            $home = $this->canonicalizeForContainment((string)$override);
            $temporaryRoot = $this->canonicalizeForContainment(
                (string)\sys_get_temp_dir(),
            );
            if (!$this->pathIsWithin($home, $temporaryRoot) || $home === $temporaryRoot) {
                throw new \RuntimeException(
                    'Test gateway home must be a task-specific child of the system temporary directory.'
                );
            }
            return $home;
        }
        if ($override !== false && \trim((string)$override) !== '') {
            throw new \RuntimeException(
                'WLS_GATEWAY_HOME cannot override the production WLS 2.0 trust root.'
            );
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return GatewayWindowsHostRootAuthority::resolveHome();
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            return '/Library/Application Support/WelineGateway';
        }

        return '/var/lib/weline-gateway';
    }

    public function runtimeDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'runtime';
    }

    public function runDir(): string
    {
        if ($this->isTestMode()) {
            return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'run';
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'run';
        }
        return \PHP_OS_FAMILY === 'Darwin'
            ? '/private/var/run/weline-gateway'
            : '/run/weline-gateway';
    }

    public function logDir(): string
    {
        return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'logs';
    }

    public function stateDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'state';
    }

    public function trustDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'trust';
    }

    public function slotsDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'slots';
    }

    /**
     * Immutable WLS 2.0 v1 Recovery Guardian generation. Platform services
     * point only at this directory; ordinary A/B and launcher rebootstrap
     * transactions are never allowed to replace it.
     */
    public function guardianDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'guardian'
            . DIRECTORY_SEPARATOR . 'v1';
    }

    public function guardianFile(): string
    {
        return $this->guardianDir() . DIRECTORY_SEPARATOR
            . (\PHP_OS_FAMILY === 'Windows'
                ? 'wls-gateway-guardian.exe'
                : 'wls-gateway-guardian');
    }

    public function guardianDigestFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'guardian.sha256';
    }

    public function guardianGenerationHeadFile(int $slot): string
    {
        if (!\in_array($slot, [0, 1], true)) {
            throw new \InvalidArgumentException(
                'Gateway Guardian generation-head slot must be 0 or 1.',
            );
        }
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-generation-head.' . $slot;
    }

    public function guardianGenerationHeadLockFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-generation-head.lock';
    }

    public function guardianTransitionRequestFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-transition.request';
    }

    public function guardianTransitionAcknowledgementFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-transition.ack';
    }

    public function guardianRecoveryTransactionFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-recovery.transaction';
    }

    public function guardianTransitionRetirementFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'guardian-transition.retirement';
    }

    public function guardianStatusFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'guardian.status';
    }

    public function guardianRecoveryAuthorizationFile(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'guardian-recovery.authorization';
    }

    public function guardianRecoveryInventoryFile(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'guardian-recovery.inventory';
    }

    /**
     * Root/LocalSystem-only workspace for an explicit whole-host launcher
     * rebootstrap. It is deliberately outside A/B so a fully verified
     * candidate can be self-tested without consuming either rollback slot.
     */
    public function rebootstrapDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'rebootstrap';
    }

    public function rebootstrapCandidatesDir(): string
    {
        return $this->rebootstrapDir() . DIRECTORY_SEPARATOR . 'candidates';
    }

    public function rebootstrapBackupsDir(): string
    {
        return $this->rebootstrapDir() . DIRECTORY_SEPARATOR . 'backups';
    }

    public function rebootstrapReceiptsDir(): string
    {
        return $this->rebootstrapDir() . DIRECTORY_SEPARATOR . 'receipts';
    }

    /**
     * Root-only physical capacity reserved before a whole-host maintenance
     * stop. Native launchers exclusively own descendants of this directory.
     */
    public function rebootstrapCapacityDir(): string
    {
        return $this->rebootstrapDir() . DIRECTORY_SEPARATOR . 'capacity';
    }

    public function rebootstrapCapacityHeldDir(string $nonce): string
    {
        return $this->rebootstrapCapacityDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.held';
    }

    public function rebootstrapCapacityReleasingDir(string $nonce): string
    {
        return $this->rebootstrapCapacityDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.releasing';
    }

    public function rebootstrapCapacityHeldManifestFile(string $nonce): string
    {
        return $this->rebootstrapCapacityDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.held.json';
    }

    public function rebootstrapCapacityReleasingReceiptFile(string $nonce): string
    {
        return $this->rebootstrapCapacityDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.releasing.json';
    }

    public function rebootstrapCapacityReleasedReceiptFile(string $nonce): string
    {
        return $this->rebootstrapCapacityDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.released.json';
    }

    public function rebootstrapCapacityReleasedGcFile(
        string $nonce,
        string $receiptSha256,
    ): string {
        $receiptSha256 = \strtolower(\trim($receiptSha256));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $receiptSha256) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway released-capacity receipt digest must be 64 lowercase hexadecimal characters.',
            );
        }
        return $this->rebootstrapCapacityReleasedReceiptFile($nonce)
            . '.gc-' . \substr($receiptSha256, 0, 32);
    }

    public function rebootstrapJournalFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'rebootstrap.transaction';
    }

    /** Root-only forward-recovery journal for first host installation. */
    public function initialBootstrapJournalFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'initial-bootstrap.transaction';
    }

    /**
     * Native-launcher-readable, administrator-authenticated permission to
     * start one exact rebootstrap journal/image pair.
     */
    public function rebootstrapStartAuthorizationFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'rebootstrap-start.authorization';
    }

    public function launcherRecoveryLedgerFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'launcher-recovery.ledger';
    }

    public function launcherRecoveryStatusFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR
            . 'launcher-recovery.status';
    }

    public function rebootstrapCandidateDir(string $nonce): string
    {
        return $this->rebootstrapCandidatesDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce);
    }

    public function rebootstrapBackupDir(string $nonce): string
    {
        return $this->rebootstrapBackupsDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce);
    }

    /**
     * Fixed crash-replay quarantine for the candidate/new runtime image that
     * is displaced while the retained old generation is restored.
     */
    public function rebootstrapRollbackNewGenerationDir(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'new-generation';
    }

    public function rebootstrapCollectedBackupDir(
        string $nonce,
        string $collectionNonce,
    ): string {
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $collectionNonce) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway rebootstrap collection nonce is invalid.',
            );
        }
        return $this->rebootstrapBackupsDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.collecting-'
            . $collectionNonce;
    }

    public function rebootstrapReceiptFile(string $nonce): string
    {
        return $this->rebootstrapReceiptsDir() . DIRECTORY_SEPARATOR
            . $this->rebootstrapNonce($nonce) . '.json';
    }

    public function rebootstrapReceiptGcFile(
        string $nonce,
        string $receiptSha256,
    ): string {
        $receiptSha256 = \strtolower(\trim($receiptSha256));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $receiptSha256) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway rebootstrap receipt GC digest is invalid.',
            );
        }
        return $this->rebootstrapReceiptFile($nonce) . '.gc-'
            . \substr($receiptSha256, 0, 32);
    }

    public function rebootstrapDerivedManifestFile(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'derived-state.manifest.json';
    }

    public function rebootstrapDerivedBackupDir(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'derived';
    }

    public function rebootstrapNewDerivedQuarantineDir(string $nonce): string
    {
        return $this->rebootstrapBackupDir($nonce) . DIRECTORY_SEPARATOR
            . 'new-derived';
    }

    public function legacySnapshotsDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'snapshots';
    }

    public function sealedSnapshotsDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'snapshots-v2';
    }

    public function snapshotCandidatesDir(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'snapshot-candidates-v2';
    }

    public function slotDir(string $slot): string
    {
        $slot = \strtoupper(\trim($slot));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \InvalidArgumentException('Gateway slot must be A or B.');
        }
        return $this->slotsDir() . DIRECTORY_SEPARATOR . $slot;
    }

    public function activeSlotFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'active-slot';
    }

    public function previousSlotFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'previous-slot';
    }

    public function upgradeIntentFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'upgrade.intent';
    }

    public function adminTokenFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'admin.token';
    }

    /** Host-global CA identity; ordinary A/B slots must match it exactly. */
    public function caBundleBaselineFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'ca-bundle.sha256';
    }

    public function adminStoppedIntentFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'admin-stopped.intent';
    }

    /**
     * @deprecated WLS 2.0 has no shared project/admin token. This alias is
     * retained only for callers compiled against the checkpoint API.
     */
    public function tokenFile(): string
    {
        return $this->adminTokenFile();
    }

    public function hostIdFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'host-id';
    }

    public function platformServiceMetadataFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR . 'platform-service.json';
    }

    /**
     * Durable forward-recovery journal for the schema-1 Linux unit-layout
     * migration.  It is separate from the definition/metadata publication
     * journal because the canonical systemd path changes type from a regular
     * file to a symlink during this one-time transition.
     */
    public function systemdLayoutMigrationTransactionFile(): string
    {
        return $this->trustDir() . DIRECTORY_SEPARATOR
            . 'systemd-layout-migration.transaction';
    }

    public function endpointFile(): string
    {
        return $this->stateDir() . DIRECTORY_SEPARATOR . 'control-endpoint.json';
    }

    public function controllerPidFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'controller.pid';
    }

    public function controllerLogFile(): string
    {
        return $this->logDir() . DIRECTORY_SEPARATOR . 'controller.log';
    }

    public function unixSocketFile(): string
    {
        return $this->projectSocketFile();
    }

    public function projectSocketFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'project.sock';
    }

    public function adminSocketFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'admin.sock';
    }

    public function controllerSocketFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'controller.sock';
    }

    public function launcherFile(): string
    {
        return $this->home() . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . (\PHP_OS_FAMILY === 'Windows'
                ? 'wls-gateway-launcher.exe'
                : 'wls-gateway-launcher');
    }

    /**
     * The mutable systemd unit belongs in a root-only directory outside the
     * controller's regular host state.  The canonical systemd search path
     * contains only an exact link to this target, so Recovery Guardian can
     * atomically restore the target without making /etc/systemd/system
     * writable inside ProtectSystem=strict.
     */
    public function systemdDefinitionDirectory(): string
    {
        if ($this->isTestMode()) {
            return $this->stateDir() . DIRECTORY_SEPARATOR
                . 'systemd-definition';
        }
        return self::SYSTEMD_DEFINITION_DIRECTORY;
    }

    public function systemdServiceDefinitionFile(): string
    {
        return $this->systemdDefinitionDirectory() . DIRECTORY_SEPARATOR
            . self::SYSTEMD_SERVICE_FILE;
    }

    /**
     * The canonical unit-search path.  In production it must be an exact
     * absolute symlink to systemdServiceDefinitionFile(); it is never a
     * mutable WLS state target.
     */
    public function systemdServiceLinkFile(): string
    {
        if ($this->isTestMode()) {
            return $this->stateDir() . DIRECTORY_SEPARATOR
                . 'systemd-service-link.test';
        }
        return self::SYSTEMD_UNIT_DIRECTORY . DIRECTORY_SEPARATOR
            . self::SYSTEMD_SERVICE_FILE;
    }

    /**
     * Schema-1 Linux metadata used the canonical unit path as its mutable
     * definition.  Keep the spelling explicit so status/remove can recognise
     * that exact pre-release layout without treating arbitrary unit files as
     * WLS-owned.
     */
    public function legacySystemdServiceDefinitionFile(): string
    {
        return $this->systemdServiceLinkFile();
    }

    /**
     * Create the single mutable systemd-unit directory only during an
     * administrator-controlled install/repair path.  Routine host-path
     * discovery must not call this method: an existing directory is verified
     * rather than repaired, and a malformed/foreign directory is rejected.
     */
    public function ensureSystemdDefinitionDirectory(): void
    {
        $directory = $this->systemdDefinitionDirectory();
        if ($this->isTestMode()) {
            $status = $this->ensureDirectory($directory);
            if (!\is_array($status)
                || \is_link($directory)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway test systemd definition directory is unsafe.',
                );
            }
            if ((((int)($status['mode'] ?? 0)) & 0777) !== 0700
                && !@\chmod($directory, 0700)
            ) {
                throw new \RuntimeException(
                    'Unable to restrict the WLS Gateway test systemd definition directory.',
                );
            }
            $this->assertSystemdDefinitionDirectoryAuthority();
            return;
        }
        if (\PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException(
                'The WLS Gateway systemd definition directory is only available on Linux.',
            );
        }

        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)
                || !@\mkdir($directory, 0700)
            ) {
                throw new \RuntimeException(
                    'Unable to create the root-owned WLS Gateway systemd definition directory.',
                );
            }
            if (!@\chmod($directory, 0700)) {
                throw new \RuntimeException(
                    'Unable to seal the WLS Gateway systemd definition directory.',
                );
            }
            GatewayProjectStateFilesystem::syncDirectory(\dirname($directory));
            $status = @\lstat($directory);
        }
        $this->assertSystemdDefinitionDirectoryAuthority();
    }

    /**
     * Read-only authority proof for the mutable systemd target directory.
     * Status paths deliberately use this instead of ensureSystemdDefinitionDirectory():
     * a missing or foreign directory must never become an installer side effect.
     */
    public function assertSystemdDefinitionDirectoryAuthority(): void
    {
        $directory = $this->systemdDefinitionDirectory();
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (((int)($status['mode'] ?? 0)) & 0777) !== 0700
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd definition directory authority is unsafe.',
            );
        }
        if ($this->isTestMode()) {
            $home = @\lstat($this->home());
            if (!\is_array($home)
                || (int)($status['uid'] ?? -1) !== (int)($home['uid'] ?? -2)
                || (int)($status['gid'] ?? -1) !== (int)($home['gid'] ?? -2)
            ) {
                throw new \RuntimeException(
                    'WLS Gateway test systemd definition directory authority is unsafe.',
                );
            }
            return;
        }
        if (\PHP_OS_FAMILY !== 'Linux'
            || (int)($status['uid'] ?? -1) !== 0
            || (int)($status['gid'] ?? -1) !== 0
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd definition directory authority is unsafe.',
            );
        }
        $this->assertProductionPosixDirectoryAclFree($directory);
        $after = @\lstat($directory);
        if (!\is_array($after)
            || !$this->sameFileState($status, $after)
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd definition directory changed during verification.',
            );
        }
    }

    /**
     * Verify the parent that owns the canonical systemd link.  The Guardian
     * never receives write access here; this proof makes the one installer
     * rename safe from non-root replacement and rejects a non-standard unit
     * namespace instead of following it.
     */
    public function assertSystemdUnitLinkDirectoryAuthority(): void
    {
        $directory = \dirname($this->systemdServiceLinkFile());
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd unit directory is unsafe.',
            );
        }
        if ($this->isTestMode()) {
            $home = @\lstat($this->home());
            if (!\is_array($home)
                || (int)($status['uid'] ?? -1) !== (int)($home['uid'] ?? -2)
                || (int)($status['gid'] ?? -1) !== (int)($home['gid'] ?? -2)
                || (((int)($status['mode'] ?? 0)) & 0777) !== 0700
            ) {
                throw new \RuntimeException(
                    'WLS Gateway test canonical systemd unit directory authority is unsafe.',
                );
            }
            return;
        }
        if (\PHP_OS_FAMILY !== 'Linux'
            || !\hash_equals(self::SYSTEMD_UNIT_DIRECTORY, $directory)
            || (int)($status['uid'] ?? -1) !== 0
            || (int)($status['gid'] ?? -1) !== 0
            || ((((int)($status['mode'] ?? 0)) & 0022) !== 0)
        ) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd unit directory authority is unsafe.',
            );
        }
        $this->assertProductionPosixDirectoryAclFree($directory);
        $after = @\lstat($directory);
        if (!\is_array($after)
            || !$this->sameFileState($status, $after)
        ) {
            throw new \RuntimeException(
                'WLS Gateway canonical systemd unit directory changed during verification.',
            );
        }
    }

    public function serviceDefinitionFile(): string
    {
        if ($this->isTestMode()) {
            return $this->stateDir() . DIRECTORY_SEPARATOR . 'service-definition.test';
        }
        return match (\PHP_OS_FAMILY) {
            'Darwin' => '/Library/LaunchDaemons/com.weline.wls-gateway-v2.plist',
            'Linux' => $this->systemdServiceDefinitionFile(),
            'Windows' => $this->stateDir() . DIRECTORY_SEPARATOR . 'windows-service.json',
            default => throw new \RuntimeException('Unsupported WLS Gateway platform.'),
        };
    }

    public function publicHttpPort(): int
    {
        return $this->publicPortFromEnvironment('WLS_GATEWAY_LISTEN_HTTP', 80);
    }

    public function publicHttpsPort(): int
    {
        return $this->publicPortFromEnvironment('WLS_GATEWAY_LISTEN_HTTPS', 443);
    }

    /**
     * @return array{transport:string,address:string}
     */
    public function desiredEndpoint(string $channel = 'project'): array
    {
        $channel = $this->normalizeChannel($channel);
        if (\PHP_OS_FAMILY === 'Windows') {
            return [
                'transport' => 'pipe',
                'address' => '\\\\.\\pipe\\weline-wls-gateway-v2-' . $channel,
            ];
        }

        return [
            'transport' => 'unix',
            'address' => 'unix://' . ($channel === 'admin'
                ? $this->adminSocketFile()
                : $this->projectSocketFile()),
        ];
    }

    /**
     * @return array{transport:string,address:string}
     */
    public function endpoint(string $channel = 'project'): array
    {
        // Production endpoints are fixed trust paths. A mutable endpoint file
        // cannot redirect a project or administrator client to another local
        // process.
        return $this->desiredEndpoint($channel);
    }

    public function activeSlot(): string
    {
        $file = $this->activeSlotFile();
        $pathStatus = @\lstat($file);
        if (!\is_array($pathStatus)) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException('Gateway active-slot path is unsafe.');
            }
            return 'A';
        }
        if (\is_link($file)
            || ((((int)($pathStatus['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($pathStatus['nlink'] ?? 0) !== 1
            || (int)($pathStatus['size'] ?? -1) < 1
            || (int)($pathStatus['size'] ?? -1) > 2
        ) {
            throw new \RuntimeException('Gateway active-slot path is unsafe.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to read the gateway active-slot pointer.');
        }
        try {
            $openedStatus = @\fstat($handle);
            $contents = @\stream_get_contents($handle, 3);
            $afterStatus = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if (!\is_array($openedStatus)
                || !\is_array($afterStatus)
                || !\is_array($pathAfter)
                || !$this->sameFileState($pathStatus, $openedStatus)
                || !$this->sameFileState($openedStatus, $afterStatus)
                || !$this->sameFileState($afterStatus, $pathAfter)
                || !\is_string($contents)
                || (int)($afterStatus['size'] ?? -1) !== \strlen($contents)
            ) {
                throw new \RuntimeException(
                    'Gateway active-slot pointer changed while being read.'
                );
            }
        } finally {
            @\fclose($handle);
        }
        $slot = \strtoupper(\trim($contents));
        if (!\in_array($slot, ['A', 'B'], true)) {
            throw new \RuntimeException('Gateway active-slot pointer is invalid.');
        }
        return $slot;
    }

    public function inactiveSlot(): string
    {
        return $this->activeSlot() === 'A' ? 'B' : 'A';
    }

    public function ensureDirectories(): void
    {
        $testMode = $this->isTestMode();
        $directories = [
            $this->home(),
            $this->runtimeDir(),
            $this->runDir(),
            $this->logDir(),
            $this->stateDir(),
            $this->trustDir(),
            $this->slotsDir(),
            $this->guardianDir(),
            $this->rebootstrapDir(),
            $this->rebootstrapCandidatesDir(),
            $this->rebootstrapBackupsDir(),
            $this->rebootstrapCapacityDir(),
            $this->rebootstrapReceiptsDir(),
            $this->legacySnapshotsDir(),
            \dirname($this->launcherFile()),
        ];
        if ($testMode) {
            // Isolated tests have one owner and no host privilege boundary.
            // Production fixed snapshot roots are created only by the
            // privileged platform/native initializer with their exact owner,
            // group and ACL profiles.
            $directories[] = $this->sealedSnapshotsDir();
            $directories[] = $this->snapshotCandidatesDir();
        }
        $allowProductionBootstrap = !$testMode
            && $this->productionBootstrapAuthorityAllowed();

        if (\PHP_OS_FAMILY === 'Windows' && !$testMode) {
            $authoritativeHome = GatewayWindowsHostRootAuthority::ensureHome();
            if (\strcasecmp($authoritativeHome, $this->home()) !== 0) {
                throw new \RuntimeException(
                    'Windows gateway trust-root authority changed during initialization.',
                );
            }
            GatewayWindowsHostRootAuthority::ensureBootstrapDirectories(
                \array_values(\array_filter(
                    $directories,
                    fn (string $directory): bool => \strcasecmp(
                        $directory,
                        $authoritativeHome,
                    ) !== 0,
                )),
                $allowProductionBootstrap,
            );
        }
        foreach ($directories as $directory) {
            $status = \PHP_OS_FAMILY === 'Windows' && !$testMode
                ? @\lstat($directory)
                : $this->ensureDirectory($directory);
            if (!\is_array($status)
                || \is_link($directory)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException('WLS Gateway directory cannot be a symbolic link: ' . $directory);
            }
            if (\PHP_OS_FAMILY === 'Windows') {
                // Windows directory authorization is enforced by the
                // installer through exact DACLs; POSIX chmod bits returned by
                // PHP are neither authoritative nor consistently mutable.
                continue;
            }
            if (!$testMode) {
                // This helper is called by status, lock and recovery paths.
                // It may validate an established privilege boundary but must
                // never rewrite one as a side effect.
                $this->assertProductionPosixDirectoryAuthority(
                    $directory,
                    $status,
                    $allowProductionBootstrap,
                );
                continue;
            }
            if ((((int)($status['mode'] ?? 0)) & 0777) !== 0700) {
                if (!@\chmod($directory, 0700)) {
                    throw new \RuntimeException(
                        'Unable to restrict WLS Gateway directory: ' . $directory
                    );
                }
                $verified = @\lstat($directory);
                if (!\is_array($verified)
                    || ((((int)($verified['mode'] ?? 0)) & 0777) !== 0700)
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway directory mode did not become private: ' . $directory
                    );
                }
            }
        }
    }

    /** @param array<string|int,mixed> $status */
    private function assertProductionPosixDirectoryAuthority(
        string $directory,
        array $status,
        bool $allowBootstrap,
    ): void {
        $bootstrap = [[0, 0, 0700]];
        $profiles = [
            $this->guardianDir() => $bootstrap,
            $this->rebootstrapDir() => $bootstrap,
            $this->rebootstrapCandidatesDir() => $bootstrap,
            $this->rebootstrapBackupsDir() => $bootstrap,
            $this->rebootstrapCapacityDir() => $bootstrap,
            $this->rebootstrapReceiptsDir() => $bootstrap,
        ];
        $controllerAccount = \PHP_OS_FAMILY === 'Darwin'
            ? '_welinegateway'
            : 'weline-gateway';
        $dataPlaneAccount = \PHP_OS_FAMILY === 'Darwin'
            ? '_welinegateway_nginx'
            : 'weline-gateway-nginx';
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
        if (\is_array($controller)
            && \is_array($controllerGroup)
            && \is_array($dataPlane)
            && \is_array($dataPlaneGroup)
            && (string)($controller['name'] ?? '') === $controllerAccount
            && (string)($controllerGroup['name'] ?? '') === $controllerAccount
            && (int)($controller['gid'] ?? -1)
                === (int)($controllerGroup['gid'] ?? -2)
            && (string)($dataPlane['name'] ?? '') === $dataPlaneAccount
            && (string)($dataPlaneGroup['name'] ?? '') === $dataPlaneAccount
            && (int)($dataPlane['gid'] ?? -1)
                === (int)($dataPlaneGroup['gid'] ?? -2)
        ) {
            $controllerUid = (int)$controller['uid'];
            $controllerGid = (int)$controller['gid'];
            $dataPlaneGid = (int)$dataPlane['gid'];
            $profiles += [
                $this->home() => [
                    [0, $controllerGid, 0751],
                ],
                $this->runtimeDir() => [
                    [$controllerUid, $dataPlaneGid, 0750],
                ],
                $this->runDir() => [
                    [0, $controllerGid, 0771],
                ],
                $this->logDir() => [
                    [$controllerUid, $dataPlaneGid, 0770],
                ],
                $this->stateDir() => [
                    [$controllerUid, $controllerGid, 0700],
                ],
                $this->trustDir() => [
                    [0, $controllerGid, 0750],
                ],
                $this->slotsDir() => [
                    [0, $controllerGid, 0755],
                ],
                $this->legacySnapshotsDir() => [
                    [$controllerUid, $dataPlaneGid, 0710],
                ],
                \dirname($this->launcherFile()) => [
                    [0, $controllerGid, 0750],
                ],
            ];
        }
        $allowed = $profiles[$directory] ?? [];
        if ($allowBootstrap && !\in_array($bootstrap[0], $allowed, true)) {
            $allowed[] = $bootstrap[0];
        }
        $actual = [
            (int)($status['uid'] ?? -1),
            (int)($status['gid'] ?? -1),
            ((int)($status['mode'] ?? 0)) & 0777,
        ];
        if (!\in_array($actual, $allowed, true)) {
            throw new \RuntimeException(
                'WLS Gateway directory authority differs from its fixed profile: '
                    . $directory,
            );
        }
        $this->assertProductionPosixDirectoryAclFree($directory);
        $after = @\lstat($directory);
        if (!\is_array($after)) {
            throw new \RuntimeException(
                'WLS Gateway directory authority disappeared during validation: '
                    . $directory,
            );
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'ctime'] as $field) {
            if (!\array_key_exists($field, $status)
                || !\array_key_exists($field, $after)
                || (int)$status[$field] !== (int)$after[$field]
            ) {
                throw new \RuntimeException(
                    'WLS Gateway directory authority changed during validation: '
                        . $directory,
                );
            }
        }
    }

    private function assertProductionPosixDirectoryAclFree(
        string $directory,
    ): void {
        if (!\class_exists(\FFI::class)) {
            throw new \RuntimeException(
                'WLS Gateway production directory ACL verification requires FFI.',
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
                            . ' int open(const char *path, int flags, ...);'
                            . ' acl_t acl_get_fd_np(int fd, int type);'
                            . ' int acl_get_entry(acl_t acl, int entry_id,'
                            . ' acl_entry_t *entry);'
                            . ' int acl_free(void *obj);'
                            . ' int close(int fd);'
                            . ' int *__error(void);',
                    ),
                    default => throw new \RuntimeException(
                        'Unsupported WLS Gateway POSIX ACL platform.',
                    ),
                };
            }
            $ffi = $bindings[$platform];
            $flags = $platform === 'Linux'
                ? (0x10000 | 0x20000 | 0x80000)
                : (0x100000 | 0x100 | 0x1000000);
            $fd = (int)$ffi->open($directory, $flags);
            if ($fd < 0) {
                throw new \RuntimeException(
                    'WLS Gateway production directory ACL handle cannot be opened.',
                );
            }
            try {
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
                                'WLS Gateway production directory has an ACL or indeterminate ACL state.',
                            );
                        }
                    }
                } else {
                    $ffi->__error()[0] = 0;
                    $acl = $ffi->acl_get_fd_np($fd, 0x00000100);
                    if (\FFI::isNull($acl)) {
                        if ((int)$ffi->__error()[0] !== 2) {
                            throw new \RuntimeException(
                                'WLS Gateway production macOS ACL state is indeterminate.',
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
                                'WLS Gateway production macOS directory has an ACL or indeterminate ACL state.',
                            );
                        }
                    }
                }
            } finally {
                if ((int)$ffi->close($fd) !== 0) {
                    throw new \RuntimeException(
                        'WLS Gateway production directory ACL handle did not close cleanly.',
                    );
                }
            }
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'WLS Gateway production directory ACL verification failed closed.',
                0,
                $exception,
            );
        }
    }

    private function productionBootstrapAuthorityAllowed(): bool
    {
        if ($this->isTestMode()) {
            return false;
        }
        $metadata = @\lstat($this->platformServiceMetadataFile());
        $definition = @\lstat($this->serviceDefinitionFile());
        $legacyDefinition = \PHP_OS_FAMILY === 'Linux'
            ? @\lstat($this->legacySystemdServiceDefinitionFile())
            : false;
        if (!\is_array($metadata)
            && !\file_exists($this->platformServiceMetadataFile())
            && !\is_link($this->platformServiceMetadataFile())
            && !\is_array($definition)
            && !\file_exists($this->serviceDefinitionFile())
            && !\is_link($this->serviceDefinitionFile())
            && !\is_array($legacyDefinition)
            && (\PHP_OS_FAMILY !== 'Linux'
                || (!\file_exists($this->legacySystemdServiceDefinitionFile())
                    && !\is_link($this->legacySystemdServiceDefinitionFile())))
        ) {
            return true;
        }
        foreach ([
            $this->trustDir() . DIRECTORY_SEPARATOR
                . 'platform-definition.transaction',
        ] as $evidence) {
            $status = @\lstat($evidence);
            if (\is_array($status)
                && !\is_link($evidence)
                && ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
                && (int)($status['nlink'] ?? 0) === 1
                && (\PHP_OS_FAMILY === 'Windows'
                    || ((int)($status['uid'] ?? -1) === 0
                        && (((int)($status['mode'] ?? 0)) & 0077) === 0))
            ) {
                return true;
            }
        }
        return false;
    }

    private function rebootstrapNonce(string $nonce): string
    {
        $nonce = \strtolower(\trim($nonce));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway rebootstrap transaction nonce must be 32 lowercase hexadecimal characters.'
            );
        }
        return $nonce;
    }

    /** @return array<string|int,mixed> */
    private function ensureDirectory(string $directory): array
    {
        if ($directory === ''
            || \str_contains($directory, "\0")
            || \strlen($directory) > 4096
        ) {
            throw new \RuntimeException('WLS Gateway directory path is invalid.');
        }
        $pending = [];
        $current = $directory;
        while (!\is_dir($current)) {
            if (\file_exists($current) || \is_link($current)) {
                throw new \RuntimeException(
                    'WLS Gateway directory path is linked or special: ' . $current
                );
            }
            $pending[] = $current;
            $parent = \dirname($current);
            if ($parent === $current || $parent === '' || $parent === '.') {
                throw new \RuntimeException(
                    'WLS Gateway directory has no trusted existing parent: ' . $directory
                );
            }
            $current = $parent;
        }
        $ancestor = @\lstat($current);
        if (!\is_array($ancestor)
            || \is_link($current)
            || ((((int)($ancestor['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'WLS Gateway directory ancestor is unsafe: ' . $current
            );
        }
        foreach (\array_reverse($pending) as $path) {
            if (!@\mkdir($path, 0700) || !\is_dir($path)) {
                throw new \RuntimeException(
                    'Unable to create WLS Gateway directory: ' . $path
                );
            }
            $created = @\lstat($path);
            if (!\is_array($created)
                || \is_link($path)
                || ((((int)($created['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Created WLS Gateway directory is unsafe: ' . $path
                );
            }
        }
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            throw new \RuntimeException(
                'Unable to inspect WLS Gateway directory: ' . $directory
            );
        }
        return $status;
    }

    public function isTestMode(): bool
    {
        return (string)\getenv('WLS_GATEWAY_TEST_MODE') === '1';
    }

    private function publicPortFromEnvironment(string $name, int $default): int
    {
        $raw = \getenv($name);
        if ($raw === false || \trim((string)$raw) === '') {
            if ($this->isTestMode()) {
                throw new \RuntimeException($name . ' is required in WLS_GATEWAY_TEST_MODE.');
            }
            return $default;
        }
        $normalized = \trim((string)$raw);
        if (!\ctype_digit($normalized)) {
            throw new \RuntimeException($name . ' must be an integer port.');
        }
        $port = (int)$normalized;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException($name . ' must be in 1..65535.');
        }
        if (!$this->isTestMode() && $port !== $default) {
            throw new \RuntimeException($name . ' cannot override a production public port.');
        }
        if ($this->isTestMode() && $port <= 1024) {
            throw new \RuntimeException($name . ' must be above 1024 in WLS_GATEWAY_TEST_MODE.');
        }
        return $port;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if (\str_contains($path, "\0")) {
            throw new \RuntimeException('WLS_GATEWAY_HOME contains a null byte.');
        }
        $path = \trim($path);
        $isAbsolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if ($this->isFilesystemRoot($path)) {
            throw new \RuntimeException('WLS_GATEWAY_HOME cannot be a filesystem root.');
        }
        $segments = \preg_split('#[\\\\/]+#', $path) ?: [];
        if (!$isAbsolute
            || \in_array('.', $segments, true)
            || \in_array('..', $segments, true)
        ) {
            throw new \RuntimeException('WLS_GATEWAY_HOME must be an absolute path without traversal.');
        }
        return $path;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        if ($this->isFilesystemRoot($path) || $this->isFilesystemRoot($root)) {
            return false;
        }
        $normalize = static function (string $value): string {
            $value = \rtrim(\str_replace('\\', '/', $value), '/');
            return \PHP_OS_FAMILY === 'Windows' ? \strtolower($value) : $value;
        };
        $path = $normalize($path);
        $root = $normalize($root);
        return $path !== ''
            && $root !== ''
            && \str_starts_with($path . '/', $root . '/');
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
     * Resolve the existing prefix while retaining an uncreated safe suffix.
     *
     * macOS commonly exposes the temporary directory through /var while the
     * native no-follow Broker correctly resolves it as /private/var. Both the
     * containment check and the returned host path must use the same canonical
     * identity or a valid test gateway cannot authorize certificate roots.
     */
    private function canonicalizeForContainment(string $path): string
    {
        $path = \rtrim($this->normalizeAbsolutePath($path), '/\\');
        if ($path === '' || $this->isFilesystemRoot($path)) {
            throw new \RuntimeException(
                'WLS_GATEWAY_HOME cannot be a filesystem root.'
            );
        }
        $probe = $path;
        $suffix = [];
        while (!\file_exists($probe) && !\is_link($probe)) {
            $leaf = \basename($probe);
            $parent = \dirname($probe);
            if ($leaf === '' || $leaf === '.' || $leaf === '..' || $parent === $probe) {
                throw new \RuntimeException(
                    'WLS_GATEWAY_HOME cannot resolve a safe existing ancestor.'
                );
            }
            \array_unshift($suffix, $leaf);
            $probe = $parent;
        }
        $canonical = \realpath($probe);
        if (!\is_string($canonical) || $canonical === '') {
            throw new \RuntimeException(
                'WLS_GATEWAY_HOME cannot resolve a safe existing ancestor.'
            );
        }
        $resolved = \rtrim($canonical, '/\\')
            . ($suffix === []
                ? ''
                : DIRECTORY_SEPARATOR . \implode(DIRECTORY_SEPARATOR, $suffix));
        if ($resolved === '' || $this->isFilesystemRoot($resolved)) {
            throw new \RuntimeException(
                'WLS_GATEWAY_HOME cannot resolve to a filesystem root.'
            );
        }
        return $resolved;
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        if (\preg_match('#\A/+\z#D', $normalized) === 1) {
            return true;
        }
        $normalized = \rtrim($normalized, '/');
        return \preg_match('/\A[A-Za-z]:\z/D', $normalized) === 1
            || \preg_match('#\A//(?![?.](?:/|\z))[^/]+(?:/[^/]+)?\z#D', $normalized) === 1
            || \preg_match('#\A//[?.]/[A-Za-z]:\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/UNC(?:/[^/]+(?:/[^/]+)?)?\z#Di', $normalized) === 1
            || \preg_match('#\A//[?.]/Volume\{[0-9A-Fa-f-]+\}\z#Di', $normalized) === 1;
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = \strtolower(\trim($channel));
        if (!\in_array($channel, ['admin', 'project'], true)) {
            throw new \InvalidArgumentException('Gateway channel must be admin or project.');
        }
        return $channel;
    }
}
