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
        $path = $this->paths->serviceDefinitionFile();
        $this->atomicWrite($path, $definition, $this->paths->isTestMode() ? 0600 : 0644);
        $this->atomicWrite(
            $this->paths->platformServiceMetadataFile(),
            \json_encode([
                'schema_version' => 1,
                'kind' => $this->paths->isTestMode() ? 'test-session' : $kind,
                'definition' => $path,
                'profile' => $profile,
                'test_mode' => $this->paths->isTestMode(),
                'installed_at' => \gmdate(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            0600,
        );
        if (!$this->paths->isTestMode() && \PHP_OS_FAMILY !== 'Windows') {
            // The metadata is published after the first privilege-separation
            // pass. Seal the completed trust tree so the controller can prove
            // its platform supervisor before the first start.
            $this->ensureServiceIdentityAndPermissions();
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
        $installed = $this->installedDefinition();
        $expectedKind = $this->paths->isTestMode()
            ? 'test-session'
            : match (\PHP_OS_FAMILY) {
                'Darwin' => 'launchd-system',
                'Linux' => 'systemd-system',
                'Windows' => 'windows-service',
                default => throw new \RuntimeException(
                    'Unsupported WLS Gateway service platform.'
                ),
            };
        $definitionPath = $this->paths->serviceDefinitionFile();
        if (!\hash_equals($expectedKind, (string)$installed['kind'])
            || !\hash_equals($definitionPath, (string)$installed['path'])
        ) {
            throw new \RuntimeException(
                'Installed WLS Gateway platform definition identity is invalid.'
            );
        }
        $this->atomicWrite(
            $definitionPath,
            $this->renderDefinition($profile),
            $this->paths->isTestMode() ? 0600 : 0644,
        );
        if (!$this->paths->isTestMode() && \PHP_OS_FAMILY === 'Windows') {
            // Migrate existing installations that were created with the old
            // restricted service-SID model before the next controlled
            // restart. Exact filesystem ACLs are applied only after the
            // service has stopped in restart(), so the live Controller cannot
            // race the recursive descriptor replacement.
            $this->configureWindowsServiceDefinition(false);
        }
        return $installed;
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
        $group = \is_array($identity) ? (int)($identity['gid'] ?? 0) : 0;
        if ($group < 1) {
            // On a fresh host package verification/staging deliberately runs
            // before the platform definition is published. Provision the
            // dedicated identity at this first immutable-slot boundary so
            // initial installation cannot deadlock on its own ordering.
            $this->ensureServiceIdentityAndPermissions();
            $identity = \function_exists('posix_getpwnam')
                ? @\posix_getpwnam($account)
                : false;
            $group = \is_array($identity) ? (int)($identity['gid'] ?? 0) : 0;
            if ($group < 1) {
                throw new \RuntimeException(
                    'Dedicated WLS Gateway controller identity is unavailable.'
                );
            }
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
        $file = $this->paths->platformServiceMetadataFile();
        $decoded = \json_decode($this->readStableRegularFile(
            $file,
            16_384,
            'WLS Gateway platform service metadata',
        ), true);
        if (!\is_array($decoded)
            || !\is_string($decoded['kind'] ?? null)
            || !\is_string($decoded['definition'] ?? null)
        ) {
            throw new \RuntimeException(
                'WLS Gateway platform service metadata is unavailable.'
            );
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
        $pending = $this->platformRemovalPendingFile();
        $this->atomicWrite(
            $pending,
            "WLS-PLATFORM-REMOVAL/1\n"
                . 'kind=' . $kind . "\n"
                . 'at=' . \time() . "\n"
                . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n",
            0600,
        );
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
                'auto',
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
                'auto',
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
        if (!\is_array($identity)) {
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
                $id = $this->availableDarwinSystemId();
                $this->mustRun(['/usr/bin/dscl', '.', '-create', '/Groups/' . $group], 'gateway group creation');
                $this->mustRun([
                    '/usr/bin/dscl', '.', '-create', '/Groups/' . $group, 'PrimaryGroupID', (string)$id,
                ], 'gateway group id assignment');
                $this->mustRun(['/usr/bin/dscl', '.', '-create', '/Users/' . $account], 'gateway user creation');
                foreach ([
                    ['UniqueID', (string)$id],
                    ['PrimaryGroupID', (string)$id],
                    ['UserShell', '/usr/bin/false'],
                    ['NFSHomeDirectory', '/var/empty'],
                    ['RealName', 'Weline Gateway Controller'],
                ] as [$property, $value]) {
                    $this->mustRun([
                        '/usr/bin/dscl', '.', '-create', '/Users/' . $account, $property, $value,
                    ], 'gateway account property ' . $property);
                }
            }
            $identity = \function_exists('posix_getpwnam') ? @\posix_getpwnam($account) : false;
        }
        if (!\is_array($identity)
            || (int)($identity['uid'] ?? 0) < 1
            || (int)($identity['gid'] ?? 0) < 1
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
                \FFI::cast('char*', $buffer),
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

    private function availableDarwinSystemId(): int
    {
        $result = $this->runCommand(['/usr/bin/dscl', '.', '-list', '/Users', 'UniqueID'], true);
        if ($result['code'] !== 0) {
            throw new \RuntimeException('Unable to enumerate macOS system identities.');
        }
        $used = [];
        foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
            if (\preg_match('/\s([0-9]+)\s*$/', $line, $matches) === 1) {
                $used[(int)$matches[1]] = true;
            }
        }
        for ($candidate = 399; $candidate >= 200; $candidate--) {
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }
        throw new \RuntimeException('No free macOS system UID/GID is available for WLS Gateway.');
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
            $result = $this->runCommand(
                [$this->windowsSystemExecutable('sc.exe'), 'query', self::SERVICE_NAME],
                true,
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
                $this->waitForWindowsServiceDeletion();
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

    private function waitForWindowsServiceDeletion(): void
    {
        $deadline = \hrtime(true) / 1_000_000_000
            + self::WINDOWS_SERVICE_TRANSITION_TIMEOUT_SECONDS;
        do {
            $query = $this->runCommand(
                [$this->windowsSystemExecutable('sc.exe'), 'query', self::SERVICE_NAME],
                true,
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
            $mainPid = \preg_match(
                '/^MainPID=([0-9]+)$/m',
                $query['output'],
                $pidMatch,
            ) === 1 ? (int)$pidMatch[1] : -1;
            $active = \preg_match(
                '/^ActiveState=([^\r\n]+)$/m',
                $query['output'],
                $activeMatch,
            ) === 1 ? (string)$activeMatch[1] : 'unknown';
            if ($mainPid !== 0
                || !\in_array($active, ['inactive', 'failed'], true)
            ) {
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
    private function runCommand(array $command, bool $allowFailure = false): array
    {
        $result = GatewayBoundedCommandRunner::run($command);
        if (!$allowFailure && $result['code'] !== 0) {
            throw new \RuntimeException(
                'Gateway platform command failed: ' . $result['output']
            );
        }
        return $result;
    }
}
