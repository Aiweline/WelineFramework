<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\MasterProcess;

/**
 * Stable, host-coordinated pure-WLS fallback port allocator.
 */
final class GatewayPortLeaseAllocator
{
    public function __construct(private readonly GatewayPaths $paths = new GatewayPaths())
    {
    }

    public function allocate(string $instanceName): int
    {
        $this->paths->ensureDirectories();
        $leaseDir = $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'fallback-leases';
        if (!\is_dir($leaseDir) && !@\mkdir($leaseDir, 0700, true) && !\is_dir($leaseDir)) {
            throw new \RuntimeException('Unable to create WLS fallback lease directory.');
        }
        $lockPath = $leaseDir . DIRECTORY_SEPARATOR . 'allocation.lock';
        $lock = @\fopen($lockPath, 'c');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to acquire WLS fallback port allocation lock.');
        }
        try {
            $identity = MasterProcess::getProjectIdentityHash() . ':' . $instanceName;
            $start = 20000 + (\hexdec(\substr(\hash('sha256', $identity), 0, 8)) % 10000);
            for ($offset = 0; $offset < 10000; $offset++) {
                $port = 20000 + (($start - 20000 + $offset) % 10000);
                $socket = @\stream_socket_server(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
                if (!\is_resource($socket)) {
                    continue;
                }
                @\fclose($socket);
                $lease = [
                    'project_identity' => MasterProcess::getProjectIdentityHash(),
                    'instance' => $instanceName,
                    'port' => $port,
                    'pid' => \getmypid(),
                    'allocated_at' => \gmdate(DATE_ATOM),
                ];
                $file = $leaseDir . DIRECTORY_SEPARATOR
                    . \substr(\hash('sha256', $identity), 0, 24) . '.json';
                $encoded = \json_encode($lease, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!\is_string($encoded) || @\file_put_contents($file . '.tmp', $encoded, LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to persist WLS fallback port lease.');
                }
                @\chmod($file . '.tmp', 0600);
                if (!@\rename($file . '.tmp', $file)) {
                    @\unlink($file . '.tmp');
                    throw new \RuntimeException('Unable to publish WLS fallback port lease.');
                }
                return $port;
            }
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }

        throw new \RuntimeException('No free pure-WLS fallback port is available in 20000-29999.');
    }
}
