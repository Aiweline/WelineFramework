<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\App\Env;
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

    private function normalizeServices(array $services, bool $withHealthCheck): array
    {
        $result = [];
        foreach ($services as $role => $roleData) {
            $instances = [];
            foreach (($roleData['instances'] ?? []) as $instanceId => $inst) {
                $port = (int)($inst['port'] ?? 0);
                $pid = (int)($inst['pid'] ?? 0);

                // 根据角色类型获取详细健康数据
                $health = null;
                if ($withHealthCheck && $port > 0) {
                    $health = match ($role) {
                        'worker' => $this->fetchWorkerHealth($port),
                        'session_server' => $this->fetchSessionServerHealth($port),
                        'memory_server' => $this->fetchMemoryServerHealth($port),
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

    /**
     * 获取 Worker 健康数据（通过 HTTP 健康检查接口）
     */
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

    /**
     * 获取 Session Server 健康数据（通过 SessionProtocol STATS 命令）
     */
    private function fetchSessionServerHealth(int $port): ?array
    {
        $client = $this->buildStateClient($port, 'session_server.token');
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

    /**
     * 获取 Memory Server 健康数据（通过 SessionProtocol STATS 命令）
     */
    private function fetchMemoryServerHealth(int $port): ?array
    {
        $client = $this->buildStateClient($port, 'memory_server.token');
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

    /**
     * 获取 Dispatcher 健康数据
     * Dispatcher 没有直接的 HTTP 接口，通过检查进程状态获取基本信息
     */
    private function fetchDispatcherHealth(int $port, int $pid): ?array
    {
        // 尝试通过 TCP 连接检测存活
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

        // 如果连接成功，说明 Dispatcher 正在运行
        // 注意：Dispatcher 的详细统计需要通过 IPC 命令获取，这里返回基本信息
        return [
            'status' => 'healthy',
            'memory_usage' => 0,
            'connections' => 0,
            'active_requests' => 0,
            'total_requests' => 0,
            'note' => 'Dispatcher stats require IPC query',
        ];
    }

    /**
     * 获取 Redirect Worker 健康数据
     * Redirect 是简单的 HTTP 重定向进程，统计信息较少
     */
    private function fetchRedirectHealth(int $port, int $pid): ?array
    {
        // 尝试通过 TCP 连接检测存活
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

    /**
     * 构建 SharedStateClient
     */
    private function buildStateClient(int $port, string $tokenFileName): ?SharedStateClient
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
}
