<?php

namespace Weline\Framework\Console\Console\Deploy\Static;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\StaticBuildGc;

final class Gc extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $root = DeployStagingSession::staticRoot();
        // --keep-days=0：忽略代龄，只保留当前代 + 最近 N 代（用于清理同日多次发布的旧代）。
        $keepDays = max(0, (int)($args['keep-days'] ?? 7));
        $keepLast = max(1, (int)($args['keep-last'] ?? 2));
        $dryRun = !empty($args['dry-run']) || !empty($data['dry-run']);
        try {
            $result = StaticBuildGc::prune($root, $keepDays, $keepLast, $dryRun);
        } catch (\Throwable $exception) {
            $this->printer->error(__('静态代 GC 失败：') . $exception->getMessage());
            return 2;
        }
        $this->printer->note(__('静态根：%{1}', [$root]));
        $this->printer->note(__('保留：%{1}', [implode(', ', $result['kept']) ?: '-']));
        $this->printer->success(__('%{1}删除：%{2}', [
            $dryRun ? __('[dry-run] ') : '',
            implode(', ', $result['deleted']) ?: '-',
        ]));

        return 0;
    }

    public function tip(): string
    {
        return '清理旧静态构建代（保留当前代、最近 N 代与保留窗口；--keep-days=0 表示忽略代龄只留最近 N 代；legacy 树不动）。';
    }
}
