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
            // the restricted SID/ACLs, and only then allows it to execute.
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
            $launcher = '"' . $this->paths->launcherFile() . '" --service'
                . ' --home="' . $this->paths->home() . '"'
                . ' --run="' . $this->paths->runDir() . '"';
            $existing = $this->runCommand(
                ['sc.exe', 'query', self::SERVICE_NAME],
                true,
            );
            if ($existing['code'] === 0) {
                $this->mustRun([
                    'sc.exe',
                    'config',
                    self::SERVICE_NAME,
                    'binPath=',
                    $launcher,
                    'start=',
                    'auto',
                ], 'Windows service re-enable');
            } else {
                $this->mustRun([
                    'sc.exe',
                    'create',
                    self::SERVICE_NAME,
                    'binPath=',
                    $launcher,
                    'start=',
                    'auto',
                    'obj=',
                    'LocalSystem',
                ], 'Windows service creation');
            }
            $this->mustRun([
                'sc.exe',
                'sidtype',
                self::SERVICE_NAME,
                'restricted',
            ], 'Windows restricted service SID');
            $this->mustRun([
                'sc.exe',
                'failure',
                self::SERVICE_NAME,
                'reset=',
                '900',
                'actions=',
                'restart/5000/restart/30000/restart/300000',
            ], 'Windows service recovery policy');
            $this->mustRun([
                'sc.exe',
                'failureflag',
                self::SERVICE_NAME,
                '1',
            ], 'Windows non-crash recovery policy');
            $this->ensureServiceIdentityAndPermissions();
            $this->mustRun(['sc.exe', 'start', self::SERVICE_NAME], 'Windows service start');
            return;
        }
        throw new \RuntimeException('Unsupported gateway platform service kind: ' . $kind);
    }

    /** @return array{kind:string,path:string,test_mode:bool} */
    public function installedDefinition(): array
    {
        $file = $this->paths->platformServiceMetadataFile();
        $decoded = \json_decode((string)@\file_get_contents($file), true);
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
            return;
        }
        if ($kind === 'systemd-system') {
            $this->mustRun([
                '/bin/systemctl',
                'disable',
                '--now',
                self::SERVICE_NAME . '.service',
            ], 'systemd persistent stop');
            return;
        }
        if ($kind === 'windows-service') {
            $this->runCommand(['sc.exe', 'stop', self::SERVICE_NAME], true);
            $this->mustRun([
                'sc.exe',
                'config',
                self::SERVICE_NAME,
                'start=',
                'disabled',
            ], 'Windows service persistent stop');
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
            $this->runCommand(['sc.exe', 'stop', self::SERVICE_NAME], true);
            $this->mustRun(
                ['sc.exe', 'start', self::SERVICE_NAME],
                'Windows gateway service restart',
            );
            return;
        }
        throw new \RuntimeException(
            'Unsupported gateway platform service kind: ' . $kind
        );
    }

    public function secureInstalledRuntime(): void
    {
        if ($this->paths->isTestMode()) {
            return;
        }
        $this->assertAdministrator();
        if (\PHP_OS_FAMILY === 'Windows') {
            // The virtual service identity does not exist until the disabled
            // SCM definition is created in start(); ACLs are applied there
            // before the first process is allowed to run.
            return;
        }
        $this->ensureServiceIdentityAndPermissions();
    }

    public function removeDefinition(string $kind): void
    {
        if (!$this->paths->isTestMode()) {
            $this->assertAdministrator();
            if ($kind === 'launchd-system') {
                $this->runCommand([
                    '/bin/launchctl',
                    'bootout',
                    'system/com.weline.wls-gateway-v2',
                ], true);
            } elseif ($kind === 'systemd-system') {
                $this->runCommand([
                    '/bin/systemctl',
                    'disable',
                    '--now',
                    self::SERVICE_NAME . '.service',
                ], true);
                $this->runCommand(['/bin/systemctl', 'daemon-reload'], true);
            } elseif ($kind === 'windows-service') {
                $this->runCommand(['sc.exe', 'stop', self::SERVICE_NAME], true);
                $this->runCommand(['sc.exe', 'delete', self::SERVICE_NAME], true);
            }
        }
        $path = $this->paths->serviceDefinitionFile();
        if (\is_file($path) && !@\unlink($path)) {
            throw new \RuntimeException('Unable to remove the failed gateway service definition.');
        }
        @\unlink($this->paths->platformServiceMetadataFile());
    }

    public function renderDefinition(string $profile): string
    {
        $template = $this->templateFile();
        $contents = @\file_get_contents($template);
        if (!\is_string($contents) || \trim($contents) === '') {
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

    private function assertAdministrator(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $result = $this->runCommand(['fltmc.exe'], true);
            if ($result['code'] !== 0) {
                throw new \RuntimeException('WLS Gateway production installation requires an elevated administrator.');
            }
            return;
        }
        if (!\function_exists('posix_geteuid') || \posix_geteuid() !== 0) {
            throw new \RuntimeException('WLS Gateway production installation requires root.');
        }
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
            foreach ($readOnly as $directory) {
                $this->applyWindowsAcl($directory, $serviceIdentity, 'RX');
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
        $this->secureRuntimeTree($this->paths->trustDir(), $gid);
        $brokerEnrollmentRegistry = $this->paths->trustDir()
            . DIRECTORY_SEPARATOR . 'broker-enrollments.tsv';
        if (\file_exists($brokerEnrollmentRegistry)
            && (\is_link($brokerEnrollmentRegistry)
                || !\is_file($brokerEnrollmentRegistry)
                || !@\chown($brokerEnrollmentRegistry, 0)
                || !@\chgrp($brokerEnrollmentRegistry, $gid)
                || !@\chmod($brokerEnrollmentRegistry, 0600))
        ) {
            throw new \RuntimeException(
                'Gateway Broker enrollment registry permission verification failed.'
            );
        }
        foreach (['A', 'B'] as $slot) {
            $slotDirectory = $this->paths->slotDir($slot);
            if (\is_dir($slotDirectory)) {
                $this->secureRuntimeTree($slotDirectory, $gid);
            }
        }
    }

    private function assertWindowsTreeHasNoLinks(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException(
                    'Windows gateway ACL traversal encountered a reparse point: '
                    . $item->getPathname()
                );
            }
        }
    }

    private function applyWindowsAcl(
        string $directory,
        string $serviceIdentity,
        string $serviceRights,
    ): void {
        $this->mustRun(
            ['icacls.exe', $directory, '/inheritance:r', '/C', '/Q'],
            'Windows gateway ACL inheritance removal',
        );
        $this->runCommand([
            'icacls.exe',
            $directory,
            '/remove:g',
            '*S-1-1-0',
            '*S-1-5-11',
            '*S-1-5-32-545',
            '/C',
            '/Q',
        ], true);
        $this->mustRun([
            'icacls.exe',
            $directory,
            '/grant:r',
            '*S-1-5-18:(OI)(CI)F',
            '*S-1-5-32-544:(OI)(CI)F',
            $serviceIdentity . ':(OI)(CI)' . $serviceRights,
            '/C',
            '/Q',
        ], 'Windows gateway privilege separation');
        $descendant = $this->firstWindowsAclDescendant($directory);
        if ($descendant !== '') {
            $this->mustRun([
                'icacls.exe',
                \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*',
                '/reset',
                '/T',
                '/C',
                '/Q',
            ], 'Windows gateway descendant ACL inheritance reset');
        }
        $verified = $this->runCommand(['icacls.exe', $directory], true);
        if ($verified['code'] !== 0
            || !\str_contains(
                \strtoupper($verified['output']),
                \strtoupper($serviceIdentity),
            )
        ) {
            throw new \RuntimeException(
                'Windows gateway ACL verification failed: ' . $directory
            );
        }
        if ($descendant !== '') {
            $verifiedDescendant = $this->runCommand(
                ['icacls.exe', $descendant],
                true,
            );
            if ($verifiedDescendant['code'] !== 0
                || !\str_contains(
                    \strtoupper($verifiedDescendant['output']),
                    \strtoupper($serviceIdentity),
                )
            ) {
                throw new \RuntimeException(
                    'Windows gateway descendant ACL verification failed: '
                    . $descendant
                );
            }
        }
    }

    private function firstWindowsAclDescendant(string $directory): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            return $item->getPathname();
        }
        return '';
    }

    private function secureControllerTree(string $root, int $owner, int $group): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink()
                || !@\chown($path, $owner)
                || !@\chgrp($path, $group)
                || !@\chmod($path, $item->isDir() ? 0700 : 0600)
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

    private function secureRuntimeTree(string $root, int $group): void
    {
        if (\is_link($root) || !@\chown($root, 0) || !@\chgrp($root, $group) || !@\chmod($root, 0750)) {
            throw new \RuntimeException('Gateway runtime slot root is unsafe.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink()
                || !@\chown($path, 0)
                || !@\chgrp($path, $group)
                || !@\chmod($path, $item->isDir() ? 0750 : ($item->isExecutable() ? 0550 : 0440))
            ) {
                throw new \RuntimeException('Gateway runtime slot permission verification failed: ' . $path);
            }
        }
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0755, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException('Unable to create the gateway service definition directory.');
        }
        if (\is_link($directory) || \is_link($path)) {
            throw new \RuntimeException('Gateway service definition path is unsafe.');
        }
        $temporary = $path . '.candidate.' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage the gateway service definition.');
        }
        try {
            if (@\fwrite($handle, $contents) !== \strlen($contents)) {
                throw new \RuntimeException('Unable to write the gateway service definition.');
            }
            $parent = @\stat($directory);
            if (\is_array($parent)) {
                \function_exists('fchown') && @\fchown($handle, (int)$parent['uid']);
                \function_exists('fchgrp') && @\fchgrp($handle, (int)$parent['gid']);
            }
            @\fflush($handle);
            \function_exists('fsync') && @\fsync($handle);
        } finally {
            @\fclose($handle);
        }
        @\chmod($temporary, $mode);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish the gateway service definition.');
        }
        @\chmod($path, $mode);
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
        $parts = \array_map(static fn (string $part): string => \escapeshellarg($part), $command);
        $output = [];
        $code = 0;
        @\exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        if (!$allowFailure && $code !== 0) {
            throw new \RuntimeException('Gateway platform command failed: ' . \implode("\n", $output));
        }
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }
}
