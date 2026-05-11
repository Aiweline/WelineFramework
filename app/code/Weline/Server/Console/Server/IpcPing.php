<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:ipc:ping — 探测控制端口 IPC 是否可读状态回执
 */
class IpcPing extends CommandAbstract
{
    /** @var list<string> 扫描生成名为 server:ipc-ping，此处注册冒号风格别名 */
    public const ALIASES = ['server:ipc:ping'];

    public function execute(array $args = [], array $data = []): void
    {
        $instanceName = $this->parseInstanceName($args);
        /** @var ServerInstanceManager $mgr */
        $mgr = ObjectManager::getInstance(ServerInstanceManager::class);
        $info = $mgr->getInstanceInfo($instanceName, false);
        if ($info === null) {
            $this->printer->error(__('未找到实例 [%{1}]', [$instanceName]));
            return;
        }

        $cp = $info->controlPort;
        $this->printer->note(__('实例 %{1} control_port=%{2} master_pid=%{3}', [$instanceName, $cp, $info->masterPid]));

        if ($cp <= 0) {
            $this->printer->error(__('控制端口无效'));
            return;
        }

        $conn = @\stream_socket_client('tcp://127.0.0.1:' . $cp, $errno, $errstr, 3);
        if (!$conn) {
            $this->printer->error(__('连接失败：%{1}', [$errstr]));
            return;
        }

        $msg = ControlMessage::command(ControlMessage::ACTION_STATUS, '', []);
        $t0 = \microtime(true);
        @\fwrite($conn, $msg);
        \stream_set_blocking($conn, false);
        $buf = '';
        $deadline = \microtime(true) + 1.5;
        while (\microtime(true) < $deadline) {
            $chunk = @\fread($conn, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                break;
            }
            \usleep(50000);
        }
        @\fclose($conn);
        $dt = \microtime(true) - $t0;

        $inspect = Processer::inspectPortOccupantWithHistory($cp);
        $this->printer->success(__('首包耗时 %{1}s owner_pid=%{2}', [
            \sprintf('%.3f', $dt),
            (string) ($inspect['pid'] ?? 0),
        ]));
        if ($buf !== '') {
            $this->printer->note(\substr($buf, 0, 500));
        }
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
        return __('IPC 连通性探测（status）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:ipc:ping [instance]',
            __('连接实例控制端口并发送 status，输出耗时与占用 PID'),
            [
                '[instance]' => __('实例名（默认 default）'),
            ],
            [],
            [
                __('探测默认实例') => 'php bin/w server:ipc:ping',
            ]
        );
    }
}
