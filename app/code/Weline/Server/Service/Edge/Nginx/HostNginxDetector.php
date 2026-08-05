<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Detect a host/system Nginx binary that is NOT the per-project managed install.
 */
final class HostNginxDetector
{
    private const MAX_PATH_ENV_BYTES = 64 * 1024;
    private const MAX_PATH_DIRECTORIES = 256;

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
        $pathEnvironment = (string)\getenv('PATH');
        if ($pathEnvironment === '' || \strlen($pathEnvironment) > self::MAX_PATH_ENV_BYTES) {
            return null;
        }
        $directories = \explode(PATH_SEPARATOR, $pathEnvironment);
        if (\count($directories) > self::MAX_PATH_DIRECTORIES) {
            return null;
        }
        $binaryNames = \PHP_OS_FAMILY === 'Windows'
            ? ['nginx.exe', 'nginx']
            : ['nginx'];
        foreach ($directories as $directory) {
            $directory = \trim($directory, " \t\n\r\0\x0B\"'");
            if ($directory === '' || \str_contains($directory, "\0")) {
                continue;
            }
            foreach ($binaryNames as $binaryName) {
                $candidate = \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binaryName;
                if ($this->isUsableHostBinary($candidate, $excludeInstallRoot)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function isUsableHostBinary(string $path, string $excludeInstallRoot): bool
    {
        $path = \trim($path);
        if ($path === ''
            || !$this->isAbsolutePath($path)
            || \is_link($path)
            || !\is_file($path)
        ) {
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

    private function isAbsolutePath(string $path): bool
    {
        return \PHP_OS_FAMILY === 'Windows'
            ? \preg_match('/\A(?:[A-Za-z]:[\\\/]|\\\\[^\\\/]+[\\\/][^\\\/]+)/D', $path) === 1
            : \str_starts_with($path, '/');
    }

    private function normalizePath(string $path): string
    {
        $path = \str_replace('\\', '/', \trim($path));
        return \rtrim($path, '/');
    }
}
