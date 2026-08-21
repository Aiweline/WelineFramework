<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;

/**
 * Per-project managed Nginx paths under BP (install + runtime isolation).
 */
final class ManagedNginxPaths
{
    public const DEFAULT_UPSTREAM_KEEPALIVE_TIMEOUT_SEC = 5;
    public const MIN_UPSTREAM_KEEPALIVE_TIMEOUT_SEC = 1;
    public const MAX_UPSTREAM_KEEPALIVE_TIMEOUT_SEC = 300;

    public function __construct(
        private readonly ?string $projectRoot = null,
        private readonly ?array $edgeNginxConfig = null,
    ) {
    }

    public function projectRoot(): string
    {
        if ($this->projectRoot !== null && $this->projectRoot !== '') {
            return \rtrim($this->projectRoot, '/\\');
        }
        return \defined('BP') ? \rtrim((string)\constant('BP'), '/\\') : \getcwd();
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $config = \is_array($this->edgeNginxConfig) ? $this->edgeNginxConfig : null;
        if (!\is_array($config)) {
            $env = Env::getInstance()->getConfig();
            $config = \is_array($env) && \is_array($env['wls']['edge']['nginx'] ?? null)
                ? $env['wls']['edge']['nginx']
                : [];
        }

        return $this->applyProcessOverrides($config);
    }

    /**
     * Resolved managed flag.
     *
     * - explicit true: WLS owns an opt-in managed Nginx lifecycle
     * - false/missing/auto: external or disabled; binary presence is not live edge readiness
     */
    public function managedEnabled(): bool
    {
        $mode = $this->managedMode();
        if ($mode === 'true') {
            return true;
        }
        if ($mode === 'false') {
            return false;
        }
        return true;
    }

    /**
     * Configured managed mode: true|false|auto.
     */
    public function managedMode(): string
    {
        $cfg = $this->config();
        if (!\array_key_exists('managed', $cfg)) {
            return 'auto';
        }
        $raw = $cfg['managed'];
        if (\is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }
        if (\is_int($raw) || \is_float($raw)) {
            return ((int)$raw !== 0) ? 'true' : 'false';
        }
        $normalized = \strtolower(\trim((string)$raw));
        if ($normalized === '' || \in_array($normalized, ['auto', 'detect'], true)) {
            return 'auto';
        }
        if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 'true';
        }
        if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 'false';
        }
        return 'auto';
    }

    public function hostNginxPresent(): bool
    {
        return $this->detectHostNginxBinary() !== null;
    }

    public function detectHostNginxBinary(): ?string
    {
        return (new HostNginxDetector($this->installRoot()))->detectBinary();
    }

    public function autoStartEnabled(): bool
    {
        $cfg = $this->config();
        if (\array_key_exists('auto_start', $cfg)) {
            return $this->toBool($cfg['auto_start'], true);
        }
        return $this->managedEnabled();
    }

    public function installRoot(): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return $this->windowsLocalRoot() . DIRECTORY_SEPARATOR . 'install';
        }
        $default = \PHP_OS_FAMILY === 'Linux'
            ? 'extend/server/nginx-' . $this->platformScope()
            : 'extend/server/nginx';
        $rel = $this->projectRelativePath(
            (string)($this->config()['install_root'] ?? $default),
            $default,
            'install_root',
        );
        return $this->projectRoot() . DIRECTORY_SEPARATOR . $rel;
    }

    public function runtimeRoot(): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return $this->windowsLocalRoot() . DIRECTORY_SEPARATOR . 'runtime';
        }
        $default = \PHP_OS_FAMILY === 'Linux'
            ? 'var/server/nginx-' . $this->platformScope()
            : 'var/server/nginx';
        $rel = $this->projectRelativePath(
            (string)($this->config()['runtime_root'] ?? $default),
            $default,
            'runtime_root',
        );
        return $this->projectRoot() . DIRECTORY_SEPARATOR . $rel;
    }

    public function confDir(): string
    {
        return $this->runtimeRoot() . DIRECTORY_SEPARATOR . 'conf';
    }

    public function logsDir(): string
    {
        return $this->runtimeRoot() . DIRECTORY_SEPARATOR . 'logs';
    }

    public function runDir(): string
    {
        return $this->runtimeRoot() . DIRECTORY_SEPARATOR . 'run';
    }

    public function confFile(): string
    {
        return $this->confDir() . DIRECTORY_SEPARATOR . 'nginx.conf';
    }

    public function pidFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'nginx.pid';
    }

    public function lifecycleLockFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'managed-nginx.lifecycle.lock';
    }

    public function ownerFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'managed-nginx.owner.json';
    }

    public function ownerIntentFile(): string
    {
        return $this->runDir() . DIRECTORY_SEPARATOR . 'managed-nginx.owner.intent.json';
    }

    public function binary(): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $win = $this->installRoot() . DIRECTORY_SEPARATOR . 'nginx.exe';
            if (\is_file($win)) {
                return $win;
            }
        }
        $sbin = $this->installRoot() . DIRECTORY_SEPARATOR . 'sbin' . DIRECTORY_SEPARATOR . 'nginx';
        if (\is_file($sbin)) {
            return $sbin;
        }
        return $this->installRoot() . DIRECTORY_SEPARATOR . 'nginx';
    }

    public function manifestFile(): string
    {
        return $this->installRoot() . DIRECTORY_SEPARATOR . 'wls-nginx-manifest.json';
    }

    public function isInstalled(): bool
    {
        $bin = $this->binary();
        if (!\is_file($bin)) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return true;
        }
        return \is_executable($bin);
    }

    public function cacheDir(): string
    {
        return $this->runtimeRoot() . DIRECTORY_SEPARATOR . 'cache';
    }

    public function edgeCacheEnabled(): bool
    {
        $cfg = $this->config();
        if (\array_key_exists('edge_cache', $cfg)) {
            return $this->toBool($cfg['edge_cache'], true);
        }
        return true;
    }

    public function edgeCacheTtlSec(): int
    {
        $cfg = $this->config();
        $ttl = (int)($cfg['edge_cache_ttl_sec'] ?? 60);
        return \max(1, \min(3600, $ttl));
    }

    public function edgeCacheMaxSizeMb(): int
    {
        $cfg = $this->config();
        $mb = (int)($cfg['edge_cache_max_size_mb'] ?? 1024);
        return \max(64, \min(8192, $mb));
    }

    public function edgeCacheKeysZoneMb(): int
    {
        $cfg = $this->config();
        $mb = (int)($cfg['edge_cache_keys_zone_mb'] ?? 128);
        return \max(16, \min(512, $mb));
    }

    public function gzipEnabled(): bool
    {
        $cfg = $this->config();
        if (\array_key_exists('gzip', $cfg)) {
            return $this->toBool($cfg['gzip'], true);
        }
        return true;
    }

    public function gzipCompLevel(): int
    {
        $cfg = $this->config();
        $level = (int)($cfg['gzip_comp_level'] ?? 2);
        return \max(1, \min(9, $level));
    }

    public function upstreamKeepalive(): int
    {
        $cfg = $this->config();
        $n = (int)($cfg['upstream_keepalive'] ?? 256);
        return \max(16, \min(1024, $n));
    }

    public function upstreamKeepaliveTimeoutSec(): int
    {
        $cfg = $this->config();
        $seconds = (int)($cfg['upstream_keepalive_timeout_sec']
            ?? self::DEFAULT_UPSTREAM_KEEPALIVE_TIMEOUT_SEC);
        return \max(
            self::MIN_UPSTREAM_KEEPALIVE_TIMEOUT_SEC,
            \min(self::MAX_UPSTREAM_KEEPALIVE_TIMEOUT_SEC, $seconds)
        );
    }

    public function workerConnections(): int
    {
        $cfg = $this->config();
        $n = (int)($cfg['worker_connections'] ?? 32768);
        return \max(1024, \min(65535, $n));
    }

    public function tempDir(): string
    {
        return $this->runtimeRoot() . DIRECTORY_SEPARATOR . 'temp';
    }

    /**
     * @return list<string>
     */
    public function nginxTempSubdirs(): array
    {
        return [
            'client_body_temp',
            'proxy_temp',
            'fastcgi_temp',
            'uwsgi_temp',
            'scgi_temp',
            'proxy_cache_temp',
        ];
    }

    public function ensureRuntimeDirectories(): void
    {
        $dirs = [
            $this->confDir(),
            $this->logsDir(),
            $this->runDir(),
            $this->confDir() . DIRECTORY_SEPARATOR . 'conf.d',
            $this->cacheDir(),
            $this->tempDir(),
        ];
        foreach ($this->nginxTempSubdirs() as $sub) {
            $dirs[] = $this->tempDir() . DIRECTORY_SEPARATOR . $sub;
        }
        foreach ($dirs as $dir) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new \RuntimeException('Unable to create managed nginx directory: ' . $dir);
            }
        }
    }

    /**
     * Process-scoped overrides exist for isolated validation and container/VM
     * deployments. They never relax managed/auto-start ownership and are
     * inherited by Master, reload, doctor and stop commands.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function applyProcessOverrides(array $config): array
    {
        foreach ([
            'listen_http' => 'WLS_NGINX_LISTEN_HTTP',
            'listen_https' => 'WLS_NGINX_LISTEN_HTTPS',
        ] as $key => $environmentName) {
            $raw = \getenv($environmentName);
            if ($raw === false || \trim((string)$raw) === '') {
                continue;
            }
            $normalized = \trim((string)$raw);
            if (!\ctype_digit($normalized)) {
                throw new \RuntimeException($environmentName . ' must be an integer port.');
            }
            $port = (int)$normalized;
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException($environmentName . ' must be in 1..65535.');
            }
            $config[$key] = $port;
        }

        foreach ([
            'install_root' => 'WLS_NGINX_INSTALL_ROOT',
            'runtime_root' => 'WLS_NGINX_RUNTIME_ROOT',
        ] as $key => $environmentName) {
            $raw = \getenv($environmentName);
            if ($raw === false || \trim((string)$raw) === '') {
                continue;
            }
            $normalized = \str_replace(\chr(92), '/', \trim((string)$raw));
            $segments = \explode('/', $normalized);
            if ($normalized === ''
                || \str_starts_with($normalized, '/')
                || \preg_match('/^[A-Za-z]:/', $normalized) === 1
                || \in_array('..', $segments, true)
                || \str_contains($normalized, \chr(0))
            ) {
                throw new \RuntimeException($environmentName . ' must be a project-relative path without traversal.');
            }
            $config[$key] = $normalized;
        }

        return $config;
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value !== 0;
        }
        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            return $default;
        }
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function platformScope(): string
    {
        $arch = \strtolower((string)\php_uname('m'));
        $arch = \preg_replace('/[^a-z0-9._-]+/', '-', $arch) ?: 'unknown';

        return \strtolower(\PHP_OS_FAMILY) . '-' . $arch;
    }

    private function projectRelativePath(string $configured, string $default, string $label): string
    {
        $configured = \trim($configured);
        $candidate = $configured !== '' ? $configured : $default;
        $normalized = \str_replace('\\', '/', $candidate);
        $segments = \explode('/', $normalized);
        if ($normalized === ''
            || \str_contains($normalized, "\0")
            || \str_starts_with($normalized, '/')
            || \str_starts_with($normalized, '//')
            || \preg_match('/\A[A-Za-z]:/D', $normalized) === 1
            || \in_array('', $segments, true)
            || \in_array('.', $segments, true)
            || \in_array('..', $segments, true)
        ) {
            throw new \RuntimeException(
                'Managed nginx ' . $label . ' must be a canonical project-relative path without traversal.',
            );
        }

        return \implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function windowsLocalRoot(): string
    {
        $base = \trim((string)(\getenv('LOCALAPPDATA') ?: \getenv('TEMP') ?: \sys_get_temp_dir()));
        $normalizedBase = \str_replace('\\', '/', $base);
        $realBase = $base !== '' && !\str_contains($base, "\0")
            ? @\realpath($base)
            : false;
        if (!\is_string($realBase)
            || $realBase === ''
            || !\is_dir($realBase)
            || \preg_match('/\A[A-Za-z]:\//D', $normalizedBase) !== 1
            || \str_starts_with($normalizedBase, '//')
        ) {
            throw new \RuntimeException(
                'Windows managed nginx requires a local drive-absolute LOCALAPPDATA or TEMP directory.',
            );
        }
        $expectedBase = \strtolower(\rtrim($normalizedBase, '/'));
        $actualBase = \strtolower(\rtrim(\str_replace('\\', '/', $realBase), '/'));
        if ($expectedBase === '' || !\hash_equals($expectedBase, $actualBase)) {
            throw new \RuntimeException(
                'Windows managed nginx local root must not traverse aliases or reparse points.',
            );
        }
        $projectIdentity = \substr(
            \Weline\Server\Service\MasterProcess::getProjectIdentityHash(),
            0,
            20,
        );
        if (\preg_match('/\A[a-f0-9]{20}\z/D', $projectIdentity) !== 1) {
            throw new \RuntimeException('Windows managed nginx project identity is invalid.');
        }

        return \rtrim($realBase, '/\\')
            . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'ManagedNginx'
            . DIRECTORY_SEPARATOR . $projectIdentity;
    }
}
