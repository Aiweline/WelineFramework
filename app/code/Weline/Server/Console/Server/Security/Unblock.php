<?php
declare(strict_types=1);

/**
 * Weline Server - 解封/清理封禁列表
 *
 * 通过 IPC 通知 Dispatcher 解封指定 IP 或清空全部封禁（含 rate_limit、SSL 握手失败等）。
 * 封禁数据在 Dispatcher 进程内存中，不持久化。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server\Security;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:security:unblock - 解封 IP 或清空全部封禁
 */
class Unblock extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $ip = $args['ip'] ?? $args['i'] ?? null;
        $clearAll = isset($args['clear-all']) || isset($args['clear_all']) || isset($args['all']);

        if ($ip === null || $ip === '') {
            if (!$clearAll) {
                $this->printer->warning(__('请指定 --ip=<IP> 解封单个 IP，或 --clear-all 清空全部封禁'));
                $this->printer->note(__('示例：php bin/w server:security:unblock --ip=101.204.98.197'));
                $this->printer->note(__('示例：php bin/w server:security:unblock --clear-all'));
                return;
            }
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $stats = $manager->getRunningStats();
        if (($stats['dispatchers'] ?? 0) === 0) {
            $this->printer->warning(__('未检测到运行中的 WLS Dispatcher，无需执行解封'));
            return;
        }

        $info = MasterProcess::getMasterEndpoint($instanceName);
        $controlPort = (int) ($info['control_port'] ?? 0);
        if ($controlPort <= 0) {
            $this->printer->warning(__('无法获取控制端口，请检查 Master 是否运行'));
            return;
        }

        $conn = @\stream_socket_client("tcp://127.0.0.1:{$controlPort}", $errno, $errstr, 5);
        if (!$conn) {
            $this->printer->warning(__('无法建立 IPC 连接: %{1}', [$errstr]));
            return;
        }

        $payload = ['clear_all' => $clearAll];
        if ($ip !== null && $ip !== '') {
            $payload['ip'] = $ip;
        }
        $command = ControlMessage::command(
            ControlMessage::ACTION_SECURITY_UNBLOCK,
            '',
            $payload,
            (string)($info['control_token'] ?? '')
        );
        $written = @\fwrite($conn, $command);
        @\stream_set_blocking($conn, true);
        @\stream_set_timeout($conn, 3);
        $response = @\stream_get_contents($conn);
        @\fclose($conn);

        if ($written === false || $written === 0) {
            $this->printer->warning(__('发送命令失败'));
            return;
        }

        $lines = $response !== false ? \explode("\n", \trim($response)) : [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            $msg = ControlMessage::decode($line);
            if ($msg !== null && ($msg['type'] ?? '') === ControlMessage::TYPE_COMMAND_RESULT) {
                $success = !empty($msg['success']);
                $message = $msg['message'] ?? '';
                if ($success) {
                    $this->printer->success($message);
                } else {
                    $this->printer->warning($message);
                }
                return;
            }
        }

        $this->printer->success(__('命令已发送，若 Dispatcher 正在运行应已生效'));
    }

    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        \array_shift($positionalArgs);
        return $positionalArgs[0] ?? 'default';
    }
}
