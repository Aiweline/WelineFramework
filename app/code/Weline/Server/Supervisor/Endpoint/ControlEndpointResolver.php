<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Endpoint;

final class ControlEndpointResolver
{
    private const UNIX_SOCKET_PATH_MAX_BYTES = 103;
    private const SOCKET_ID_HASH_HEX_LENGTH = 24;
    private const PRIVATE_DIRECTORY_MODE = 0700;

    public function __construct(
        private readonly string $basePath,
        private readonly int $tcpBasePort = 26000,
        private readonly int $tcpPortSpan = 2000,
    ) {
    }

    public function resolve(string $instanceName, ?string $osFamily = null): ControlEndpoint
    {
        $osFamily ??= \PHP_OS_FAMILY;
        if (\strtolower($osFamily) === 'windows') {
            return ControlEndpoint::tcp('127.0.0.1', $this->stablePort($instanceName));
        }

        return ControlEndpoint::unix($this->unixSocketPath($instanceName));
    }

    public function unixSocketPath(string $instanceName): string
    {
        $runDirectory = $this->normalizePath($this->basePath)
            . DIRECTORY_SEPARATOR
            . 'var'
            . DIRECTORY_SEPARATOR
            . 'server'
            . DIRECTORY_SEPARATOR
            . 'run';
        $legacyPath = $runDirectory
            . DIRECTORY_SEPARATOR
            . $this->sanitizeInstanceName($instanceName)
            . DIRECTORY_SEPARATOR
            . 'supervisor.sock';
        if ($this->fitsUnixSocketPath($legacyPath)) {
            return $legacyPath;
        }

        $socketId = $this->socketIdentityHash($instanceName);
        $projectPrivateDirectory = $runDirectory . DIRECTORY_SEPARATOR . '.s';
        $projectPath = $projectPrivateDirectory
            . DIRECTORY_SEPARATOR
            . $socketId
            . '.sock';
        if ($this->fitsUnixSocketPath($projectPath)) {
            $this->ensurePrivateDirectory($projectPrivateDirectory);

            return $projectPath;
        }

        $tempPrivateDirectory = $this->normalizePath(\sys_get_temp_dir())
            . DIRECTORY_SEPARATOR
            . 'weline-'
            . $this->effectiveUserId();
        $tempPath = $tempPrivateDirectory
            . DIRECTORY_SEPARATOR
            . $socketId
            . '.sock';
        if ($this->fitsUnixSocketPath($tempPath)) {
            $this->ensurePrivateDirectory($tempPrivateDirectory);

            return $tempPath;
        }

        throw new \RuntimeException(\sprintf(
            'Unable to resolve supervisor UNIX socket within the %d-byte path limit (legacy=%d, project=%d, temp=%d).',
            self::UNIX_SOCKET_PATH_MAX_BYTES,
            \strlen($legacyPath),
            \strlen($projectPath),
            \strlen($tempPath),
        ));
    }

    public function stablePort(string $instanceName): int
    {
        $span = \max(1, $this->tcpPortSpan);
        $hash = \sprintf('%u', \crc32($this->sanitizeInstanceName($instanceName)));

        return $this->tcpBasePort + ((int)$hash % $span);
    }

    private function sanitizeInstanceName(string $instanceName): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', $instanceName) ?? '';
        $safe = \trim($safe, '.-_');

        return $safe !== '' ? $safe : 'default';
    }

    private function fitsUnixSocketPath(string $path): bool
    {
        return \strlen($path) <= self::UNIX_SOCKET_PATH_MAX_BYTES;
    }

    private function socketIdentityHash(string $instanceName): string
    {
        $identity = $this->canonicalBasePath() . "\0" . $instanceName;

        return \substr(\hash('sha256', $identity), 0, self::SOCKET_ID_HASH_HEX_LENGTH);
    }

    private function canonicalBasePath(): string
    {
        $normalized = $this->normalizePath($this->basePath);
        $realPath = @\realpath($normalized !== '' ? $normalized : DIRECTORY_SEPARATOR);

        return \is_string($realPath) && $realPath !== ''
            ? $this->normalizePath($realPath)
            : $normalized;
    }

    private function effectiveUserId(): int
    {
        if (\function_exists('posix_geteuid')) {
            $uid = \posix_geteuid();
            if (\is_int($uid) && $uid >= 0) {
                return $uid;
            }
        }

        return \getmyuid();
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (\is_link($directory)) {
            throw new \RuntimeException("Supervisor socket directory must not be a symbolic link: {$directory}");
        }
        if (!\is_dir($directory)
            && !@\mkdir($directory, self::PRIVATE_DIRECTORY_MODE, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException("Unable to create private supervisor socket directory: {$directory}");
        }

        \clearstatcache(true, $directory);
        if (\is_link($directory) || !\is_dir($directory)) {
            throw new \RuntimeException("Invalid private supervisor socket directory: {$directory}");
        }

        $owner = @\fileowner($directory);
        if (\is_int($owner) && $owner !== $this->effectiveUserId()) {
            throw new \RuntimeException("Supervisor socket directory is not owned by the current user: {$directory}");
        }

        @\chmod($directory, self::PRIVATE_DIRECTORY_MODE);
        \clearstatcache(true, $directory);
        $permissions = @\fileperms($directory);
        if (!\is_int($permissions) || ($permissions & 0777) !== self::PRIVATE_DIRECTORY_MODE) {
            throw new \RuntimeException("Supervisor socket directory permissions must be 0700: {$directory}");
        }
    }

    private function normalizePath(string $path): string
    {
        return \rtrim($path, "\\/");
    }
}
