<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Release;

use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Output\Cli\Printing;

class Status extends CommandAbstract
{
    public function __construct(
        Printing $printer,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployReleaseHistoryService $historyService,
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return __('查看当前部署版本');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:release:status',
            $this->tip(),
            ['--history' => '显示最近 5 条发布历史'],
            [],
            ['查看版本' => 'php bin/w deploy:release:status', '查看历史' => 'php bin/w deploy:release:status --history'],
            'php bin/w deploy:release:status'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $current = $this->runtimeService->getCurrent();

        if (!$current) {
            $this->printer->warning(__('尚未发布过'));
            return;
        }

        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('当前部署版本'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note(__('版本：%{1}', [$current['deploy_version']]));
        $this->printer->note(__('发布 ID：%{1}', [$current['release_id']]));
        $this->printer->note(__('ref 类型：%{1}', [$current['git_ref_type']]));
        $this->printer->note(__('commit：%{1}', [substr($current['git_commit'] ?? '', 0, 12)]));
        if (!empty($current['git_tag'])) {
            $this->printer->note(__('tag：%{1}', [$current['git_tag']]));
        }
        $this->printer->note(__('Worker ID：%{1}', [$current['worker_build_id']]));
        $this->printer->note(__('部署时间：%{1}', [date('Y-m-d H:i:s', $current['deployed_at'] ?? 0)]));
        $this->printer->note(__('部署模式：%{1}', [$current['deploy_mode'] ?? 'dev']));
        $this->printer->note('');

        if (isset($args['history'])) {
            $records = $this->historyService->getRecent(5);
            if ($records) {
                $this->printer->setup(__('最近发布历史'));
                $this->printer->setup('───────────────────────────────────────────────────────────────');
                foreach ($records as $record) {
                    $status = $record->getData('status');
                    $icon   = $status === 'success' ? '[OK]' : ($status === 'failed' ? '[FAIL]' : '[..]');
                    $this->printer->note(sprintf(
                        '%s %s | %s | %s | %s',
                        $icon,
                        date('Y-m-d H:i', (int)$record->getData('started_at')),
                        $record->getData('deploy_version'),
                        $record->getData('git_ref_type'),
                        $status
                    ));
                }
            }
        }
        $this->printer->note('');
    }
}
