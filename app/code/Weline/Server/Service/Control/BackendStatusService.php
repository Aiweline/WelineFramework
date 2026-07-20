<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Server\Service\SharedStateRuntimeScope;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;

class BackendStatusService
{
    public function __construct(
        private readonly IpcControlGateway $gateway
    ) {
    }

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function getStatusDto(string $instanceName = 'default', bool $withHealthCheck = true): array
    {
        $status = $this->gateway->getStatus($instanceName, 4.0);
        if (!$status['success']) {
            return $status;
        }

        $raw = $status['data'];
        $services = $this->normalizeServices($raw['services'] ?? [], $withHealthCheck);

        return [
            'success' => true,
            'message' => (string)__('Status loaded'),
            'data' => [
                'instance' => $instanceName,
                'running' => (bool)($raw['running'] ?? false),
                'shutting_down' => (bool)($raw['shutting_down'] ?? false),
                'control_port' => (int)($raw['control_port'] ?? 0),
                'epoch' => (int)($raw['epoch'] ?? 0),
                'maintenance_mode' => (bool)($raw['maintenance_mode'] ?? false),
                'rolling_restart_in_progress' => (bool)($raw['rolling_restart_in_progress'] ?? false),
                'services' => $services,
                'metrics' => \is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [],
                'timestamp' => \time(),
            ],
        ];
    }

    private function normalizeServices(array $services, bool $withHealthCheck): array
    {
        $result = [];
        foreach ($services as $role => $roleData) {
            $instances = [];
            foreach (($roleData['instances'] ?? []) as $instanceId => $inst) {
                $port = (int)($inst['port'] ?? 0);
                $pid = (int)($inst['pid'] ?? 0);
                $metadata = \is_array($inst['metadata'] ?? null) ? $inst['metadata'] : [];

                $health = null;
                if ($withHealthCheck && $port > 0) {
                    $health = match ($role) {
                        'worker' => $this->fetchWorkerHealth($port),
                        'session_server' => $this->fetchSessionServerHealth(
                            $port,
                            $this->resolveSharedStateTokenFileName('session_server', $port, (array)$inst, $metadata)
                        ),
                        'memory_server' => $this->fetchMemoryServerHealth(
                            $port,
                            $this->resolveSharedStateTokenFileName('memory_server', $port, (array)$inst, $metadata)
                        ),
                        'dispatcher' => $this->fetchDispatcherHealth($port, $pid),
                        'redirect' => $this->fetchRedirectHealth($port, $pid),
                        default => null,
                    };
                }

                $instances[] = [
                    'instance_id' => (int)($inst['instance_id'] ?? $instanceId),
                    'role' => (string)$role,
                    'pid' => $pid,
                    'port' => $port,
                    'state' => (string)($inst['state'] ?? 'unknown'),
                    'uptime' => (float)($inst['uptime'] ?? 0),
                    'restarts' => (int)($inst['restarts'] ?? 0),
                    'heartbeat' => (float)($inst['last_health_check'] ?? 0),
                    'drain_state' => (string)($inst['state'] ?? ''),
                    'memory_usage' => (int)($health['memory_usage'] ?? 0),
                    'connections' => (int)($health['connections'] ?? 0),
                    'active_requests' => (int)($health['active_requests'] ?? 0),
                    'total_requests' => (int)($health['total_requests'] ?? 0),
                    'health_detail' => $health,
                    'metadata' => $metadata,
                ];
            }
            $result[] = [
                'role' => (string)$role,
                'display_name' => (string)($roleData['display_name'] ?? $role),
                'instances' => $instances,
            ];
        }

        return $result;
    }

    private function fetchWorkerHealth(int $port): ?array
    {
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.6);
        if (!$conn) {
            return null;
        }

        \stream_set_timeout($conn, 1);
        $request = "GET /_wls/health?detail=1&fibers=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";
        @\fwrite($conn, $request);
        $response = @\stream_get_contents($conn);
        @\fclose($conn);

        if (!\is_string($response) || $response === '') {
            return null;
        }

        $parts = \explode("\r\n\r\n", $response, 2);
        if (\count($parts) < 2) {
            return null;
        }
        $json = \json_decode($parts[1], true);
        if (!\is_array($json)) {
            return null;
        }

        return [
            'status' => (string)($json['status'] ?? 'unknown'),
            'memory_usage' => (int)($json['memory_usage'] ?? 0),
            'memory_peak' => (int)($json['memory_peak'] ?? 0),
            'connections' => (int)($json['connections'] ?? 0),
            'active_requests' => (int)($json['active_requests'] ?? 0),
            'total_requests' => (int)($json['total_requests'] ?? 0),
            'uptime' => (int)($json['uptime'] ?? 0),
            'php_version' => (string)($json['php_version'] ?? ''),
            'timestamp' => (int)($json['timestamp'] ?? 0),
            'fiber_count' => (int)($json['fiber_count'] ?? 0),
            'fibers' => \is_array($json['fibers'] ?? null) ? $json['fibers'] : [],
        ];
    }

    private function fetchSessionServerHealth(int $port, string $tokenFileName): ?array
    {
        $client = $this->buildStateClient($port, $tokenFileName);
        if ($client === null) {
            return null;
        }

        $response = $client->request(SessionProtocol::CMD_STATS);
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return null;
        }

        $stats = SessionProtocol::getData($response);
        if (!\is_array($stats)) {
            return null;
        }

        return [
            'status' => 'healthy',
            'memory_usage' => (int)($stats['memory_usage'] ?? 0),
            'connections' => (int)($stats['client_count'] ?? 0),
            'active_requests' => 0,
            'total_requests' => (int)($stats['session_count'] ?? 0),
            'session_count' => (int)($stats['session_count'] ?? 0),
            'persisted_count' => (int)($stats['persisted_count'] ?? 0),
            'persist_path' => (string)($stats['persist_path'] ?? ''),
        ];
    }

    private function fetchMemoryServerHealth(int $port, string $tokenFileName): ?array
    {
        $client = $this->buildStateClient($port, $tokenFileName);
        if ($client === null) {
            return null;
        }

        $response = $client->request(SessionProtocol::CMD_STATS);
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return null;
        }

        $stats = SessionProtocol::getData($response);
        if (!\is_array($stats)) {
            return null;
        }

        return [
            'status' => 'healthy',
            'memory_usage' => (int)($stats['memory_usage'] ?? 0),
            'connections' => (int)($stats['client_count'] ?? 0),
            'active_requests' => 0,
            'total_requests' => (int)($stats['session_count'] ?? 0),
            'key_count' => (int)($stats['session_count'] ?? 0),
        ];
    }

    private function fetchDispatcherHealth(int $port, int $pid): ?array
    {
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.3);
        if (!$conn) {
            return [
                'status' => 'unhealthy',
                'memory_usage' => 0,
                'connections' => 0,
                'active_requests' => 0,
                'total_requests' => 0,
            ];
        }
        @\fclose($conn);

        return [
            'status' => 'healthy',
            'memory_usage' => 0,
            'connections' => 0,
            'active_requests' => 0,
            'total_requests' => 0,
            'note' => 'Dispatcher stats require IPC query',
        ];
    }

    private function fetchRedirectHealth(int $port, int $pid): ?array
    {
        $conn = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.3);
        if (!$conn) {
            return [
                'status' => 'unhealthy',
                'memory_usage' => 0,
                'connections' => 0,
                'active_requests' => 0,
                'total_requests' => 0,
            ];
        }
        @\fclose($conn);

        return [
            'status' => 'healthy',
            'memory_usage' => 0,
            'connections' => 0,
            'active_requests' => 0,
            'total_requests' => 0,
        ];
    }

    protected function buildStateClient(int $port, string $tokenFileName): ?SharedStateClient
    {
        try {
            return new SharedStateClient('127.0.0.1', $port, [
                'token_file_name' => $tokenFileName,
                'acquire_timeout' => 0.3,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $metadata
     */
    private function resolveSharedStateTokenFileName(string $role, int $port, array $instance, array $metadata): string
    {
        foreach ([
            $metadata['token_file_name'] ?? null,
            $instance['token_file_name'] ?? null,
            $metadata['configured_token_file_name'] ?? null,
            $instance['configured_token_file_name'] ?? null,
        ] as $candidate) {
            $tokenFileName = \trim((string)$candidate);
            if ($tokenFileName !== '') {
                return $tokenFileName;
            }
        }

        return SharedStateRuntimeScope::defaultTokenFileNameForRole($role, $port);
    }
}
