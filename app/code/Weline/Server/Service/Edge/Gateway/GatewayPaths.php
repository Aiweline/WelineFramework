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
        $slot = \strtoupper(\trim((string)@\file_get_contents($this->activeSlotFile())));
        return \in_array($slot, ['A', 'B'], true) ? $slot : 'A';
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
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                throw new \RuntimeException('Unable to create WLS Gateway directory: ' . $directory);
            }
            if (\is_link($directory)) {
                throw new \RuntimeException('WLS Gateway directory cannot be a symbolic link: ' . $directory);
            }
            $mode = (int)(@\fileperms($directory) ?: 0) & 0777;
            // Production privilege separation deliberately promotes selected
            // roots to 0750/0770 after the service identity exists. Repeated
            // package-lock setup must not silently collapse those directories
            // back to root-only 0700 and strand the downgraded Controller.
            if ($this->isTestMode()
                || !\in_array($mode, [0700, 0750, 0770, 0771], true)
            ) {
                @\chmod($directory, 0700);
            }
        }
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
        $path = \trim(\str_replace("\0", '', $path));
        $isAbsolute = \str_starts_with($path, '/')
            || \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || \str_starts_with($path, '\\\\');
        if (!$isAbsolute || \in_array('..', \preg_split('#[\\\\/]+#', $path) ?: [], true)) {
            throw new \RuntimeException('WLS_GATEWAY_HOME must be an absolute path without traversal.');
        }
        return $path;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $normalize = static fn (string $value): string => \strtolower(
            \rtrim(\str_replace('\\', '/', $value), '/')
        );
        $path = $normalize($path);
        $root = $normalize($root);
        return \str_starts_with($path . '/', $root . '/');
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
        return \rtrim($canonical, '/\\')
            . ($suffix === []
                ? ''
                : DIRECTORY_SEPARATOR . \implode(DIRECTORY_SEPARATOR, $suffix));
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
