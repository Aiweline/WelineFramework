<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:ipc:stop-test — 发送 stop_test（不执行真实停机）
 */
class IpcStopTest extends CommandAbstract
{
    /** @var list<string> 扫描生成名为 server:ipc-stop-test */
    public const ALIASES = ['server:ipc:stop-test'];

    public function execute(array $args = [], array $data = []): void
    {
        $instanceName = $this->parseInstanceName($args);
        $mgr = ObjectManager::getInstance(ServerInstanceManager::class);
        $info = $mgr->getInstanceInfo($instanceName, false);
        if ($info === null) {
            $this->printer->error(__('未找到实例 [%{1}]', [$instanceName]));
            return;
        }

        $cp = $info->controlPort;
        if ($cp <= 0) {
            $this->printer->error(__('控制端口无效'));
            return;
        }

        $conn = @\stream_socket_client('tcp://127.0.0.1:' . $cp, $errno, $errstr, 3);
        if (!$conn) {
            $this->printer->error(__('连接失败：%{1}', [$errstr]));
            return;
        }

        $msg = ControlMessage::command(ControlMessage::ACTION_STOP_TEST, '', []);
        @\fwrite($conn, $msg);
        \stream_set_blocking($conn, true);
        \stream_set_timeout($conn, 2);
        $line = @\fgets($conn);
        @\fclose($conn);

        if ($line === false || $line === '') {
            $this->printer->warning(__('未收到回执'));
            return;
        }

        $decoded = \json_decode(\trim($line), true);
        if (\is_array($decoded)) {
            echo \json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        $this->printer->note(\trim($line));
    }

    protected function parseInstanceName(array $args): string
    {
        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $positional[] = $arg;
            }
        }
        \array_shift($positional);

        return (string) ($positional[0] ?? 'default');
    }

    public function tip(): string
    {
        return __('IPC stop_test 诊断');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:ipc:stop-test [instance]',
            __('发送 stop_test，输出 Orchestrator 诊断 JSON'),
            [
                '[instance]' => __('实例名（默认 default）'),
            ],
            [],
            [
                __('诊断默认实例') => 'php bin/w server:ipc:stop-test',
            ]
        );
    }
}
