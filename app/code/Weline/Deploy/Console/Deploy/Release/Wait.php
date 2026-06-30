<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Release;

use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Wait extends CommandAbstract
{
    public function __construct(
        Printing $printer,
        private readonly DeployReleaseRuntimeService $runtimeService,
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return __('等待指定版本生效（用于 CI 门禁）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:release:wait',
            $this->tip(),
            [
                '--expect=<版本>' => '期望的 deploy_version（必填）',
                '--timeout=<秒>' => '超时秒数（默认 300）',
                '--interval=<秒>' => '轮询间隔秒数（默认 5）',
            ],
            [],
            ['等待 tag 版本' => 'php bin/w deploy:release:wait --expect=v1.0.0 --timeout=300'],
            'php bin/w deploy:release:wait --expect=<版本> [--timeout=<秒>]'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $expect  = $args['expect']  ?? '';
        $timeout = (int)($args['timeout'] ?? 300);
        $interval = (int)($args['interval'] ?? 5);

        if ($expect === '') {
            $this->printer->error(__('缺少 --expect 参数'));
            exit(1);
        }

        $this->printer->setup(__('等待版本：%{1}（超时 %{2} 秒）', [$expect, (string)$timeout]));

        $deadline = time() + $timeout;
        $attempt  = 0;

        while (time() < $deadline) {
            $attempt++;
            $current = $this->runtimeService->getCurrent();
            $version = $current['deploy_version'] ?? '';

            if ($version === $expect) {
                $this->printer->success(__('版本已生效：%{1}（第 %{2} 次检测）', [$version, (string)$attempt]));
                return;
            }

            $this->printer->note(__('第 %{1} 次检测：当前 %{2}，期望 %{3}', [(string)$attempt, $version ?: '(无)', $expect]));
            sleep($interval);
        }

        $this->printer->error(__('超时：版本 %{1} 未在 %{2} 秒内生效', [$expect, (string)$timeout]));
        exit(1);
    }
}
