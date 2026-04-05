<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;

class IpcControlGateway implements IpcControlGatewayInterface
{
    /**
     * CLI/后台异步派发常用 0.8s 级读超时；若与连接共用会导致 stream_socket_client 在 Windows/高负载下频繁误判失败。
     * 连接阶段单独使用不低于本值的超时，读 ACK 仍用调用方传入的 $timeout。
     */
    private const CONTROL_MIN_CONNECT_TIMEOUT_SEC = 12.0;

    /** 控制端口瞬时不可达（Master 忙、系统更新）时短暂重试 */
    private const CONTROL_CONNECT_ATTEMPTS = 3;

    private const CONTROL_CONNECT_RETRY_USEC = 350_000;

    public function command(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 6.0
    ): array {
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_intent'])) {
            $payload['stop_intent'] = 'explicit';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_source'])) {
            $payload['stop_source'] = 'ipc_gateway';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_trace_id'])) {
            $payload['stop_trace_id'] = 'gw-' . \getmypid() . '-' . \time();
        }

        $controlPort = $this->resolveControlPort($instanceName);
        if ($controlPort <= 0) {
            return [
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ];
        }

        return $this->sendCommand($controlPort, ControlMessage::command($action, $reloadType, $payload), $timeout);
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
        return $this->command($instanceName, ControlMessage::ACTION_STATUS, '', [], $timeout);
    }

    public function reloadSslCert(string $instanceName = 'default', array $domains = []): array
    {
        $payload = empty($domains) ? [] : ['domains' => \array_values(\array_unique($domains))];
        return $this->command($instanceName, ControlMessage::ACTION_SSL_CERT_RELOAD, '', $payload);
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
        $master = MasterProcess::getMasterInfo($instanceName);
        return (int)($master['control_port'] ?? 0);
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
        $controlPort = $this->resolveControlPort($instanceName);
        if ($controlPort <= 0) {
            return [
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ];
        }

        return $this->sendCommand(
            $controlPort,
            ControlMessage::command($action, $reloadType, $payload),
            $timeout,
            true,
            $acceptedMessage
        );
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
            $chunk = @\fread($conn, 4096);
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

            SchedulerSystem::usleep(50000);
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
        $connectTimeout = \max($readTimeout, self::CONTROL_MIN_CONNECT_TIMEOUT_SEC);

        $conn = null;
        $errno = 0;
        $errstr = '';
        for ($attempt = 1; $attempt <= self::CONTROL_CONNECT_ATTEMPTS; $attempt++) {
            $conn = @\stream_socket_client(
                "tcp://127.0.0.1:{$controlPort}",
                $errno,
                $errstr,
                $connectTimeout
            );
            if ($conn) {
                break;
            }
            if ($attempt < self::CONTROL_CONNECT_ATTEMPTS) {
                SchedulerSystem::usleep(self::CONTROL_CONNECT_RETRY_USEC);
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
