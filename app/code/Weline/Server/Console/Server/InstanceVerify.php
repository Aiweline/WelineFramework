<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:instance:verify — 校验实例 JSON 与进程/端口一致性（只读）
 */
class InstanceVerify extends CommandAbstract
{
    /** @var list<string> 扫描生成名为 server:instance-verify */
    public const ALIASES = ['server:instance:verify'];

    public function execute(array $args = [], array $data = []): void
    {
        $instanceName = $this->parseInstanceName($args);
        $mgr = ObjectManager::getInstance(ServerInstanceManager::class);
        $raw = $mgr->getRawInstanceData($instanceName);
        if ($raw === null) {
            $this->printer->error(__('实例文件不存在：%{1}', [$instanceName]));
            return;
        }

        $info = $mgr->getInstanceInfo($instanceName, false);
        $masterPid = (int) ($raw['master_pid'] ?? 0);
        $controlPort = (int) ($raw['control_port'] ?? 0);

        $this->printer->setup(__('实例校验：%{1}', [$instanceName]));
        $this->printer->note(__('runtime_state=%{1} last_verified_at=%{2}', [
            (string) ($raw['runtime_state'] ?? ''),
            (string) ($raw['last_verified_at'] ?? ''),
        ]));

        $lines = [];
        $lines[] = 'master_pid=' . $masterPid . ' exists=' . ($masterPid > 0 && Processer::processExists($masterPid) ? '1' : '0');
        if ($masterPid > 0) {
            $cmd = Processer::getProcessCommandLine($masterPid);
            $lines[] = 'master_cmd=' . \substr($cmd, 0, 200);
        }

        $lines[] = 'control_port=' . $controlPort;
        if ($controlPort > 0) {
            $insp = Processer::inspectPortOccupantWithHistory($controlPort);
            $lines[] = 'control_owner_pid=' . (string) ($insp['pid'] ?? 0) . ' scope=' . (string) ($insp['scope'] ?? '');
        }

        if ($info !== null) {
            foreach ($info->services as $svc) {
                $lines[] = $svc->role . ' pid=' . ($svc->pid ?? 0) . ' port=' . ($svc->port ?? 0);
            }
        }

        foreach ($lines as $line) {
            $this->printer->note($line);
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
        return __('实例进程/端口一致性校验');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:instance:verify [instance]',
            __('打印实例元数据与进程/端口探测摘要'),
            [
                '[instance]' => __('实例名（默认 default）'),
            ],
            [],
            [
                __('校验默认实例') => 'php bin/w server:instance:verify',
            ]
        );
    }
}
