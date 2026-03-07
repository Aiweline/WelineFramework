<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

class BackendStatusService
{
    public function __construct(
        private readonly IpcControlGateway $gateway
    ) {
    }

    /**
     * @return array{success:bool,message:string,data:array}
     */
    public function getStatusDto(string $instanceName = 'default', bool $withWorkerHealth = true): array
    {
        $status = $this->gateway->getStatus($instanceName, 4.0);
        if (!$status['success']) {
            return $status;
        }

        $raw = $status['data'];
        $services = $this->normalizeServices($raw['services'] ?? [], $withWorkerHealth);

        return [
            'success' => true,
            'message' => (string)__('状态获取成功'),
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

    private function normalizeServices(array $services, bool $withWorkerHealth): array
    {
        $result = [];
        foreach ($services as $role => $roleData) {
            $instances = [];
            foreach (($roleData['instances'] ?? []) as $instanceId => $inst) {
                $port = (int)($inst['port'] ?? 0);
                $health = null;
                if ($withWorkerHealth && $role === 'worker' && $port > 0) {
                    $health = $this->fetchWorkerHealth($port);
                }
                $instances[] = [
                    'instance_id' => (int)($inst['instance_id'] ?? $instanceId),
                    'role' => (string)$role,
                    'pid' => (int)($inst['pid'] ?? 0),
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
                    'worker_health' => $health,
                    'metadata' => \is_array($inst['metadata'] ?? null) ? $inst['metadata'] : [],
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
        $request = "GET /_wls/health?detail=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";
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
        return \is_array($json) ? $json : null;
    }
}
