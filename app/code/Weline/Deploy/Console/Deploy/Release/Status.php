<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Release;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployGitMetadataService;
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
        private readonly DeployGitMetadataService $gitMetadata,
        private readonly DeployConfigService $deployConfig,
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

        $this->printWorktreeDiagnostics($current);

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

    /**
     * 版本戳只记录发布时的 commit；core:update 同步过的树可能领先 HEAD 且带未提交改动。
     * 发布前必须让操作者看见「HEAD vs 版本戳」和脏文件数，否则 force-reset 会静默覆盖。
     *
     * @param array<string, mixed> $current
     */
    private function printWorktreeDiagnostics(array $current): void
    {
        try {
            $root = trim((string)($this->deployConfig->getSettings()['deploy_root'] ?? ''));
            if ($root === '') {
                $root = \defined('BP') ? (string)BP : '';
            }
            if ($root === '' || !is_dir($root)) {
                return;
            }

            $head = $this->gitMetadata->getFullCommit($root);
            if ($head === '') {
                $this->printer->warning(__('工作区不是可读的 Git 仓库，无法核对 HEAD 与版本戳。'));
                return;
            }

            $branch = $this->gitMetadata->getCurrentBranch($root);
            $dirty = $this->gitMetadata->getDirtyFileCount($root);
            $stampCommit = trim((string)($current['git_commit'] ?? ''));
            $headShort = substr($head, 0, 12);
            $stampShort = $stampCommit !== '' ? substr($stampCommit, 0, 12) : '(未记录)';

            $this->printer->setup(__('工作区诊断'));
            $this->printer->note(__('HEAD：%{1}（%{2}）', [
                $headShort,
                $branch !== '' ? $branch : __('游离 HEAD'),
            ]));
            $this->printer->note(__('版本戳 commit：%{1}', [$stampShort]));

            if ($stampCommit === '') {
                $this->printer->warning(__('版本戳缺少 commit，无法判断工作区是否领先版本戳。'));
            } elseif ($stampCommit !== $head) {
                $this->printer->warning(__(
                    'HEAD 与版本戳不一致：工作区可能由 core:update 同步后未提交；'
                    . ' 下一次 force-reset 发布会覆盖这些差异。'
                ));
            }

            if ($dirty > 0) {
                $this->printer->warning(__(
                    '工作区有 %{1} 个未提交改动；发布前先确认要不要保留（core:update 不会替你提交）。',
                    [(string)$dirty]
                ));
            } else {
                $this->printer->note(__('工作区无未提交改动。'));
            }

            $this->printer->note('');
        } catch (\Throwable $throwable) {
            $this->printer->warning(__('工作区诊断不可用：%{1}', [$throwable->getMessage()]));
        }
    }
}
