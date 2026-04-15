<?php
declare(strict_types=1);

namespace Weline\Server\Supervisor\Endpoint;

final class ControlEndpointResolver
{
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
        return $this->normalizePath($this->basePath)
            . DIRECTORY_SEPARATOR
            . 'var'
            . DIRECTORY_SEPARATOR
            . 'server'
            . DIRECTORY_SEPARATOR
            . 'run'
            . DIRECTORY_SEPARATOR
            . $this->sanitizeInstanceName($instanceName)
            . DIRECTORY_SEPARATOR
            . 'supervisor.sock';
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

    private function normalizePath(string $path): string
    {
        return \rtrim($path, "\\/");
    }
}
