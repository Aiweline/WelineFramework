<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;

class IpcControlGateway implements IpcControlGatewayInterface
{
    public function command(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 6.0
    ): array {
        $master = MasterProcess::getMasterInfo($instanceName);
        if (!$master || empty($master['control_port'])) {
            return [
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ];
        }

        $controlPort = (int)($master['control_port'] ?? 0);
        if ($controlPort <= 0) {
            return [
                'success' => false,
                'message' => (string)__('实例 %{1} 的控制端口无效。', [$instanceName]),
                'data' => [],
            ];
        }

        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, $timeout);
        if (!$conn) {
            return [
                'success' => false,
                'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                'data' => ['errno' => (int)$errno],
            ];
        }

        \stream_set_timeout($conn, (int)\ceil($timeout));
        \stream_set_blocking($conn, false);

        $command = ControlMessage::command($action, $reloadType, $payload);
        $written = @\fwrite($conn, $command);
        if ($written === false || $written === 0) {
            @\fclose($conn);
            return [
                'success' => false,
                'message' => (string)__('发送控制命令失败，请检查 Orchestrator IPC 连接状态。'),
                'data' => [],
            ];
        }

        $buffer = '';
        $deadline = \microtime(true) + $timeout;
        while (\microtime(true) < $deadline) {
            $chunk = @\fread($conn, 4096);
            if ($chunk === false) {
                @\fclose($conn);
                return [
                    'success' => false,
                    'message' => (string)__('读取控制命令响应失败。'),
                    'data' => [],
                ];
            }

            if ($chunk !== '') {
                $buffer .= $chunk;
                $messages = ControlMessage::extractMessages($buffer);
                foreach ($messages as $message) {
                    if (($message['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                        continue;
                    }
                    @\fclose($conn);
                    return [
                        'success' => (bool)($message['success'] ?? false),
                        'message' => (string)($message['message'] ?? ''),
                        'data' => \is_array($message['data'] ?? null) ? $message['data'] : [],
                    ];
                }
            }
            SchedulerSystem::usleep(50000);
        }

        @\fclose($conn);
        return [
            'success' => false,
            'message' => (string)__('等待控制命令响应超时（%{1}s）。', [\round($timeout, 1)]),
            'data' => [],
        ];
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
}
