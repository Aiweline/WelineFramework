<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Detect a host/system Nginx binary that is NOT the per-project managed install.
 */
final class HostNginxDetector
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    public function __construct(
        private readonly ?string $excludeInstallRoot = null,
    ) {
    }

    public function detectBinary(): ?string
    {
        $exclude = $this->normalizePath($this->excludeInstallRoot ?? '');
        $cacheKey = $exclude !== '' ? $exclude : '__none__';
        if (\array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        foreach ($this->candidateBinaries() as $candidate) {
            if ($this->isUsableHostBinary($candidate, $exclude)) {
                return self::$cache[$cacheKey] = $candidate;
            }
        }

        $fromPath = $this->detectFromPath($exclude);
        return self::$cache[$cacheKey] = $fromPath;
    }

    public function isPresent(): bool
    {
        return $this->detectBinary() !== null;
    }

    /**
     * @return list<string>
     */
    private function candidateBinaries(): array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return [
                'C:\\nginx\\nginx.exe',
                'C:\\Program Files\\nginx\\nginx.exe',
                'C:\\Program Files (x86)\\nginx\\nginx.exe',
            ];
        }

        return [
            '/www/server/nginx/sbin/nginx', // 宝塔 / aaPanel
            '/usr/sbin/nginx',
            '/usr/bin/nginx',
            '/usr/local/sbin/nginx',
            '/usr/local/bin/nginx',
            '/usr/local/nginx/sbin/nginx',
            '/opt/homebrew/opt/nginx/bin/nginx',
            '/opt/homebrew/bin/nginx',
            '/opt/nginx/sbin/nginx',
        ];
    }

    private function detectFromPath(string $excludeInstallRoot): ?string
    {
        $command = \PHP_OS_FAMILY === 'Windows'
            ? 'where nginx 2>NUL'
            : 'command -v nginx 2>/dev/null';
        $output = [];
        $code = 1;
        @\exec($command, $output, $code);
        if ($code !== 0 || $output === []) {
            return null;
        }
        foreach ($output as $line) {
            $path = \trim((string)$line);
            if ($path !== '' && $this->isUsableHostBinary($path, $excludeInstallRoot)) {
                return $path;
            }
        }
        return null;
    }

    private function isUsableHostBinary(string $path, string $excludeInstallRoot): bool
    {
        $path = \trim($path);
        if ($path === '' || !\is_file($path)) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows' && !\is_executable($path)) {
            return false;
        }
        $normalized = $this->normalizePath($path);
        if ($excludeInstallRoot !== '' && \str_starts_with($normalized, $excludeInstallRoot . '/')) {
            return false;
        }
        return true;
    }

    private function normalizePath(string $path): string
    {
        $path = \str_replace('\\', '/', \trim($path));
        return \rtrim($path, '/');
    }
}
