<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\App\Env;

/**
 * Per-project managed Nginx paths under BP (install + runtime isolation).
 */
final class ManagedNginxPaths
{
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
        if (\is_array($this->edgeNginxConfig)) {
            return $this->edgeNginxConfig;
        }
        $env = Env::getInstance()->getConfig();
        if (!\is_array($env)) {
            return [];
        }
        return \is_array($env['wls']['edge']['nginx'] ?? null)
            ? $env['wls']['edge']['nginx']
            : [];
    }

    /**
     * Resolved managed flag.
     *
     * - explicit true/false: honor config
     * - missing / empty / "auto": detect host Nginx; host present → false, else true
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
        return !$this->hostNginxPresent();
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
        return true;
    }

    public function installRoot(): string
    {
        $rel = \trim((string)($this->config()['install_root'] ?? 'extend/server/nginx'));
        $rel = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
        if ($rel === '' || \str_starts_with($rel, DIRECTORY_SEPARATOR) || \preg_match('#^[A-Za-z]:#', $rel) === 1) {
            $rel = 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'nginx';
        }
        return $this->projectRoot() . DIRECTORY_SEPARATOR . $rel;
    }

    public function runtimeRoot(): string
    {
        $rel = \trim((string)($this->config()['runtime_root'] ?? 'var/server/nginx'));
        $rel = \str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
        if ($rel === '' || \str_starts_with($rel, DIRECTORY_SEPARATOR) || \preg_match('#^[A-Za-z]:#', $rel) === 1) {
            $rel = 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'nginx';
        }
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

    public function workerConnections(): int
    {
        $cfg = $this->config();
        $n = (int)($cfg['worker_connections'] ?? 32768);
        return \max(1024, \min(65535, $n));
    }

    public function ensureRuntimeDirectories(): void
    {
        $dirs = [
            $this->confDir(),
            $this->logsDir(),
            $this->runDir(),
            $this->confDir() . DIRECTORY_SEPARATOR . 'conf.d',
            $this->cacheDir(),
        ];
        foreach ($dirs as $dir) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new \RuntimeException('Unable to create managed nginx directory: ' . $dir);
            }
        }
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
}
