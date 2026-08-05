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
            $base = (string)(\getenv('PROGRAMDATA') ?: '');
            if (\trim($base) === '') {
                throw new \RuntimeException('WLS Gateway requires PROGRAMDATA on Windows.');
            }
            return \rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'Weline'
                . DIRECTORY_SEPARATOR . 'Gateway';
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
            ? '/var/run/weline-gateway'
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

    public function serviceDefinitionFile(): string
    {
        if ($this->isTestMode()) {
            return $this->stateDir() . DIRECTORY_SEPARATOR . 'service-definition.test';
        }
        return match (\PHP_OS_FAMILY) {
            'Darwin' => '/Library/LaunchDaemons/com.weline.wls-gateway-v2.plist',
            'Linux' => '/etc/systemd/system/weline-wls-gateway-v2.service',
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
        foreach ([
            $this->home(),
            $this->runtimeDir(),
            $this->runDir(),
            $this->logDir(),
            $this->stateDir(),
            $this->trustDir(),
            $this->slotsDir(),
            $this->home() . DIRECTORY_SEPARATOR . 'snapshots',
            \dirname($this->launcherFile()),
        ] as $directory) {
            $status = $this->ensureDirectory($directory);
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
            $mode = ((int)($status['mode'] ?? 0)) & 0777;
            // Production privilege separation deliberately promotes selected
            // roots to 0750/0770 after the service identity exists. Repeated
            // package-lock setup must not silently collapse those directories
            // back to root-only 0700 and strand the downgraded Controller.
            if ($this->isTestMode()
                || !\in_array($mode, [0700, 0750, 0770, 0771], true)
            ) {
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
