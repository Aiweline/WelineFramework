<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Timeouts;

class IpcControlGateway implements IpcControlGatewayInterface
{
    public function command(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 6.0
    ): array {
        $requestId = (string)($payload['msg_id'] ?? ControlCommandResult::requestId($action));
        $payload['msg_id'] = $requestId;
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_intent'])) {
            $payload['stop_intent'] = 'explicit';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_source'])) {
            $payload['stop_source'] = 'ipc_gateway';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_trace_id'])) {
            $payload['stop_trace_id'] = 'gw-' . \getmypid() . '-' . \time();
        }

        $endpoint = $this->resolveControlEndpoint($instanceName);
        $controlPort = (int)$endpoint['port'];
        if ($controlPort <= 0) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ], $instanceName, $action, $requestId);
        }

        $result = $this->sendCommand(
            $controlPort,
            ControlMessage::command($action, $reloadType, $payload, (string)$endpoint['control_token']),
            $timeout
        );

        return ControlCommandResult::normalize($result, $instanceName, $action, $requestId);
    }

    public function reloadAsync(
        string $instanceName,
        string $reloadType,
        float $timeout = 5.0
    ): array {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_RELOAD,
            $reloadType,
            [],
            $timeout,
            'Reload initiated'
        );
    }

    public function cacheClear(string $instanceName, float $timeout = 5.0): array
    {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Cache clear queued'
        );
    }

    public function setMaintenanceMode(
        string $instanceName,
        bool $enabled,
        float $timeout = 6.0,
        bool $dispatcherOnly = false
    ): array
    {
        return $this->commandAsync(
            $instanceName,
            $enabled ? ControlMessage::ACTION_MAINTENANCE_ENABLE : ControlMessage::ACTION_MAINTENANCE_DISABLE,
            '',
            $dispatcherOnly ? ['dispatcher_only' => true] : [],
            $timeout,
            $enabled ? 'Maintenance enable queued' : 'Maintenance disable queued'
        );
    }

    public function routingCacheClear(string $instanceName, float $timeout = 5.0): array
    {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Routing cache clear queued'
        );
    }

    public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
    {
        return $this->command($instanceName, ControlMessage::ACTION_STATUS, '', [], $timeout ?: Timeouts::CONTROL_CMD_STATUS_READ_SEC);
    }

    public function getStatusBrief(string $instanceName = 'default', float $timeout = 1.5): array
    {
        return $this->command($instanceName, ControlMessage::ACTION_STATUS, '', ['brief' => true], $timeout);
    }

    public function reloadSslCert(string $instanceName = 'default', array $domains = []): array
    {
        $payload = empty($domains) ? [] : ['domains' => \array_values(\array_unique($domains))];
        return $this->command($instanceName, ControlMessage::ACTION_SSL_CERT_RELOAD, '', $payload, Timeouts::CONTROL_CMD_DEFAULT_READ_SEC);
    }

    public function securityUnblock(string $instanceName = 'default', ?string $ip = null, bool $clearAll = false): array
    {
        $payload = ['clear_all' => $clearAll];
        if ($ip !== null && $ip !== '') {
            $payload['ip'] = $ip;
        }

        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SECURITY_UNBLOCK,
            '',
            $payload,
            Timeouts::CONTROL_CMD_DEFAULT_READ_SEC
        );
    }

    public function scaleWorkers(string $instanceName, int $targetWorkers, float $timeout = 10.0): array
    {
        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SCALE_WORKERS,
            '',
            ['target_workers' => $targetWorkers],
            $timeout
        );
    }

    public function scalingStatus(string $instanceName, float $timeout = 4.0): array
    {
        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SCALING_STATUS,
            '',
            [],
            $timeout
        );
    }

    // ==================== 并发批量派发（P0-3） ====================
    //
    // 旧 BroadcastControlDispatchService::dispatchToRunningInstances 使用 foreach 串行调用
    // 每个实例的 sendCommand，每次最长阻塞 $timeout。N 个实例最差 N × timeout。
    //
    // 下列 *Many 方法 + sendCommandsParallel 改为：
    //   1) 先对所有实例快速完成 connect + fwrite（单次 write 通常 <1ms）
    //   2) 用 stream_select 在一个总超时窗口内并发等待 N 个 ACK
    //
    // 总耗时从 N × timeout 降为 max(单实例 RTT) ≈ timeout（最差场景）。
    //
    // 说明：
    // - Interface IpcControlGatewayInterface 未扩展，仅在具体实现上添加，避免破坏实现方。
    // - 内部不触发 Fiber，依赖 stream_select 多路复用在单进程事件环内等待 ACK。

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function reloadAsyncMany(array $instanceNames, string $reloadType, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_RELOAD,
            $reloadType,
            [],
            $timeout,
            'Reload initiated'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function cacheClearMany(array $instanceNames, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Cache clear queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function setMaintenanceModeMany(
        array $instanceNames,
        bool $enabled,
        float $timeout = 6.0,
        bool $dispatcherOnly = false
    ): array {
        return $this->commandAsyncMany(
            $instanceNames,
            $enabled ? ControlMessage::ACTION_MAINTENANCE_ENABLE : ControlMessage::ACTION_MAINTENANCE_DISABLE,
            '',
            $dispatcherOnly ? ['dispatcher_only' => true] : [],
            $timeout,
            $enabled ? 'Maintenance enable queued' : 'Maintenance disable queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function routingCacheClearMany(array $instanceNames, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Routing cache clear queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @param string[] $domains
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function reloadSslCertMany(array $instanceNames, array $domains = [], float $timeout = 6.0): array
    {
        $payload = empty($domains) ? [] : ['domains' => \array_values(\array_unique($domains))];
        return $this->commandMany(
            $instanceNames,
            ControlMessage::ACTION_SSL_CERT_RELOAD,
            '',
            $payload,
            $timeout
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    protected function commandAsyncMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout,
        string $acceptedMessage
    ): array {
        return $this->dispatchCommandMany(
            $instanceNames,
            $action,
            $reloadType,
            $payload,
            $timeout,
            true,
            $acceptedMessage
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    protected function commandMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout
    ): array {
        return $this->dispatchCommandMany(
            $instanceNames,
            $action,
            $reloadType,
            $payload,
            $timeout,
            false,
            ''
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    private function dispatchCommandMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout,
        bool $asyncAck,
        string $acceptedMessage
    ): array {
        $results = [];
        $commands = [];
        foreach ($instanceNames as $name) {
            $endpoint = $this->resolveControlEndpoint($name);
            $port = (int)$endpoint['port'];
            $requestId = ControlCommandResult::requestId($action);
            if ($port <= 0) {
                $results[$name] = ControlCommandResult::normalize([
                    'success' => false,
                    'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$name]),
                    'data' => [],
                ], $name, $action, $requestId, $asyncAck);
                continue;
            }
            $payloadWithId = $payload;
            $payloadWithId['msg_id'] = $requestId;
            $commands[$name] = [
                'port' => $port,
                'action' => $action,
                'request_id' => $requestId,
                'async' => $asyncAck,
                'command' => ControlMessage::command(
                    $action,
                    $reloadType,
                    $payloadWithId,
                    (string)$endpoint['control_token']
                ),
            ];
        }

        if ($commands === []) {
            return $results;
        }

        $parallel = $this->sendCommandsParallel($commands, $timeout, $asyncAck, $acceptedMessage);
        foreach ($parallel as $name => $r) {
            $meta = $commands[$name] ?? [];
            $results[$name] = ControlCommandResult::normalize(
                $r,
                $name,
                (string)($meta['action'] ?? $action),
                (string)($meta['request_id'] ?? ''),
                (bool)($meta['async'] ?? $asyncAck)
            );
        }
        return $results;
    }

    /**
     * 对一组 (instance => controlPort) 并发发送同一条控制命令，用 stream_select 多路复用等待 ACK。
     *
     * 语义复用自 sendCommand：
     *   - $acceptWriteTimeoutAsAsyncAck=true：读超时时以 "已接受" 返回
     *   - false：读超时返回 timed_out 错误
     *
     * @param array<string, array{port:int,command:string}> $instanceCommands
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    private function sendCommandsParallel(
        array $instanceCommands,
        float $timeout,
        bool $acceptWriteTimeoutAsAsyncAck,
        string $acceptedMessage
    ): array {
        $results = [];
        if ($instanceCommands === []) {
            return $results;
        }

        $readTimeout = \max(0.05, $timeout);
        $connectTimeout = \max(Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC, $readTimeout);

        /** @var array<string, resource> $connections */
        $connections = [];
        /** @var array<string, string> $buffers */
        $buffers = [];

        foreach ($instanceCommands as $instance => $endpoint) {
            $port = (int)($endpoint['port'] ?? 0);
            $command = (string)($endpoint['command'] ?? '');
            $errno = 0;
            $errstr = '';
            $conn = null;
            for ($attempt = 1; $attempt <= Timeouts::CONTROL_CONNECT_ATTEMPTS; $attempt++) {
                $conn = @\stream_socket_client(
                    "tcp://127.0.0.1:{$port}",
                    $errno,
                    $errstr,
                    $connectTimeout
                );
                if ($conn) {
                    break;
                }
                if ($attempt < Timeouts::CONTROL_CONNECT_ATTEMPTS) {
                    SchedulerSystem::usleep(Timeouts::CONTROL_CONNECT_RETRY_USEC);
                }
            }
            if (!$conn) {
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                    'data' => ['errno' => (int)$errno],
                ];
                continue;
            }

            $written = @\fwrite($conn, $command);
            if ($written === false || $written === 0) {
                @\fclose($conn);
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('发送控制命令失败，请检查 Orchestrator IPC 连接状态。'),
                    'data' => [],
                ];
                continue;
            }

            \stream_set_blocking($conn, false);
            $connections[$instance] = $conn;
            $buffers[$instance] = '';
        }

        $deadline = \microtime(true) + $readTimeout;
        while ($connections !== []) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $readable = \array_values($connections);
            $write = null;
            $except = null;
            $sec = (int)\floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $ready = @\stream_select($readable, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($readable as $readyConn) {
                $readyInstance = null;
                foreach ($connections as $inst => $c) {
                    if ($c === $readyConn) {
                        $readyInstance = $inst;
                        break;
                    }
                }
                if ($readyInstance === null) {
                    continue;
                }

                $chunk = @\fread($readyConn, 65536);
                if ($chunk === false) {
                    $results[$readyInstance] = [
                        'success' => false,
                        'message' => (string)__('读取控制命令响应失败。'),
                        'data' => [],
                    ];
                    @\fclose($readyConn);
                    unset($connections[$readyInstance], $buffers[$readyInstance]);
                    continue;
                }

                if ($chunk !== '') {
                    $buffers[$readyInstance] .= $chunk;
                    $parsed = false;
                    foreach (ControlMessage::extractMessages($buffers[$readyInstance]) as $message) {
                        if (($message['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                            continue;
                        }
                        $results[$readyInstance] = [
                            'success' => (bool)($message['success'] ?? false),
                            'message' => (string)($message['message'] ?? ''),
                            'data' => \is_array($message['data'] ?? null) ? $message['data'] : [],
                        ];
                        $parsed = true;
                        break;
                    }
                    if ($parsed) {
                        @\fclose($readyConn);
                        unset($connections[$readyInstance], $buffers[$readyInstance]);
                        continue;
                    }
                }

                if (\feof($readyConn)) {
                    if (!isset($results[$readyInstance])) {
                        $results[$readyInstance] = [
                            'success' => false,
                            'message' => (string)__('读取控制命令响应失败。'),
                            'data' => [],
                            'timed_out' => false,
                        ];
                    }
                    @\fclose($readyConn);
                    unset($connections[$readyInstance], $buffers[$readyInstance]);
                }
            }
        }

        // 剩余未回 ACK 的连接 → 按 write-timeout 回退语义处理
        foreach ($connections as $instance => $conn) {
            if ($acceptWriteTimeoutAsAsyncAck) {
                $results[$instance] = [
                    'success' => true,
                    'message' => $acceptedMessage !== '' ? $acceptedMessage : (string)__('控制命令已发送'),
                    'data' => [
                        'async' => true,
                        'accepted' => true,
                        'accepted_via' => 'write_timeout_fallback',
                    ],
                ];
            } else {
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('等待控制命令响应超时（%{1}s）。', [\round($readTimeout, 1)]),
                    'data' => [],
                    'timed_out' => true,
                ];
            }
            @\fclose($conn);
        }

        return $results;
    }

    /**
     * Master 未运行时，按进程管理器启动 WLS（仅用于后台 start 兜底）
     */
    public function startInstance(string $instanceName = 'default', int $workers = 0): array
    {
        $command = PHP_BINARY . ' ' . BP . 'bin/w server:start ' . \escapeshellarg($instanceName);
        if ($workers > 0) {
            $command .= ' -c ' . $workers;
        }

        $pid = Processer::create($command, false);
        if ($pid <= 0) {
            return [
                'success' => false,
                'message' => (string)__('启动命令已提交，但未返回有效 PID，请稍后刷新状态确认。'),
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('启动命令已提交，Master PID: %{1}', [$pid]),
            'data' => ['pid' => $pid],
        ];
    }

    private function resolveControlPort(string $instanceName): int
    {
        return (int)$this->resolveControlEndpoint($instanceName)['port'];
    }

    /**
     * @return array{port:int,control_token:string}
     */
    private function resolveControlEndpoint(string $instanceName): array
    {
        $master = MasterProcess::getMasterEndpoint($instanceName);
        return [
            'port' => (int)($master['control_port'] ?? 0),
            'control_token' => (string)($master['control_token'] ?? ''),
        ];
    }

    /**
     * 异步控制命令：写入成功后优先等待短 ACK；若 Master 忙于主循环导致超时，则按“已接受”返回。
     *
     * @return array{success:bool,message:string,data:array}
     */
    protected function commandAsync(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 5.0,
        string $acceptedMessage = 'Command queued'
    ): array {
        $requestId = (string)($payload['msg_id'] ?? ControlCommandResult::requestId($action));
        $payload['msg_id'] = $requestId;
        $endpoint = $this->resolveControlEndpoint($instanceName);
        $controlPort = (int)$endpoint['port'];
        if ($controlPort <= 0) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ], $instanceName, $action, $requestId, true);
        }

        $result = $this->sendCommand(
            $controlPort,
            ControlMessage::command($action, $reloadType, $payload, (string)$endpoint['control_token']),
            $timeout,
            true,
            $acceptedMessage
        );

        return ControlCommandResult::normalize($result, $instanceName, $action, $requestId, true);
    }

    /**
     * @param resource $conn
     * @return array{success:bool,message:string,data:array,timed_out?:bool}
     */
    private function readCommandResult($conn, float $timeout): array
    {
        \stream_set_timeout($conn, (int)\ceil($timeout));
        \stream_set_blocking($conn, false);

        $buffer = '';
        $deadline = \microtime(true) + $timeout;
        while (\microtime(true) < $deadline) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $read = [$conn];
            $write = null;
            $except = null;
            $sec = (int)\floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);
            $ready = @\stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to read control command response.',
                    'data' => [],
                ];
            }
            if ($ready === 0) {
                continue;
            }

            $chunk = @\fread($conn, 65536);
            if ($chunk === false) {
                return [
                    'success' => false,
                    'message' => (string)__('读取控制命令响应失败。'),
                    'data' => [],
                ];
            }

            if ($chunk !== '') {
                $buffer .= $chunk;
                foreach (ControlMessage::extractMessages($buffer) as $message) {
                    if (($message['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                        continue;
                    }

                    return [
                        'success' => (bool)($message['success'] ?? false),
                        'message' => (string)($message['message'] ?? ''),
                        'data' => \is_array($message['data'] ?? null) ? $message['data'] : [],
                    ];
                }
            }

            if (\feof($conn)) {
                return [
                    'success' => false,
                    'message' => (string)__('读取控制命令响应失败。'),
                    'data' => [],
                    'timed_out' => false,
                ];
            }

        }

        return [
            'success' => false,
            'message' => (string)__('等待控制命令响应超时（%{1}s）。', [\round($timeout, 1)]),
            'data' => [],
            'timed_out' => true,
        ];
    }

    /**
     * @return array{success:bool,message:string,data:array}
     */
    private function sendCommand(
        int $controlPort,
        string $command,
        float $timeout,
        bool $acceptWriteTimeoutAsAsyncAck = false,
        string $acceptedMessage = ''
    ): array
    {
        $readTimeout = \max(0.05, $timeout);
        $connectTimeout = \max(Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC, $readTimeout);

        $conn = null;
        $errno = 0;
        $errstr = '';
        for ($attempt = 1; $attempt <= Timeouts::CONTROL_CONNECT_ATTEMPTS; $attempt++) {
            $conn = @\stream_socket_client(
                "tcp://127.0.0.1:{$controlPort}",
                $errno,
                $errstr,
                $connectTimeout
            );
            if ($conn) {
                break;
            }
            if ($attempt < Timeouts::CONTROL_CONNECT_ATTEMPTS) {
                SchedulerSystem::usleep(Timeouts::CONTROL_CONNECT_RETRY_USEC);
            }
        }
        if (!$conn) {
            return [
                'success' => false,
                'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                'data' => ['errno' => (int)$errno],
            ];
        }

        try {
            $written = @\fwrite($conn, $command);
            if ($written === false || $written === 0) {
                return [
                    'success' => false,
                    'message' => (string)__('发送控制命令失败，请检查 Orchestrator IPC 连接状态。'),
                    'data' => [],
                ];
            }

            $result = $this->readCommandResult($conn, $readTimeout);
            if ($acceptWriteTimeoutAsAsyncAck && !empty($result['timed_out'])) {
                return [
                    'success' => true,
                    'message' => $acceptedMessage !== '' ? $acceptedMessage : (string)__('控制命令已发送'),
                    'data' => [
                        'async' => true,
                        'accepted' => true,
                        'accepted_via' => 'write_timeout_fallback',
                    ],
                ];
            }

            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => (string)($result['message'] ?? ''),
                'data' => \is_array($result['data'] ?? null) ? $result['data'] : [],
            ];
        } finally {
            @\fclose($conn);
        }
    }
}
