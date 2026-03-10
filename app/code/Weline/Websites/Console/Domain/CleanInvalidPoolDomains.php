<?php

declare(strict_types=1);

/**
 * 清理域名池中的非法域名记录
 *
 * 删除因 JSON 字符串被错误当作前缀而产生的域名，如 ["@","www"].example.com
 */

namespace Weline\Websites\Console\Domain;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Websites\Model\DomainPool;

class CleanInvalidPoolDomains extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $dryRun = \in_array('--dry-run', $args, true);
        if ($dryRun) {
            $printing->printing(__('（试运行模式，不会实际删除）'), 'note');
        }

        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $all = $poolModel->clearQuery()
            ->fields(DomainPool::schema_fields_ID . ',' . DomainPool::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();

        $toDelete = [];
        foreach ($all as $row) {
            $domain = (string) ($row[DomainPool::schema_fields_DOMAIN] ?? '');
            if (\str_contains($domain, '[') || \str_contains($domain, ']')) {
                $toDelete[] = $row;
            }
        }

        if ($toDelete === []) {
            return __('未发现非法域名记录');
        }

        $printing->printing(__('发现 %{1} 条非法域名记录：', [\count($toDelete)]), 'warning');
        foreach ($toDelete as $r) {
            $printing->printing('  - ' . ($r[DomainPool::schema_fields_DOMAIN] ?? ''), 'error');
        }

        if (!$dryRun) {
            foreach ($toDelete as $r) {
                $pool = clone $poolModel;
                $pool->load($r[DomainPool::schema_fields_ID]);
                if ($pool->getPoolId()) {
                    $pool->delete();
                }
            }
            $printing->printing(__('已删除 %{1} 条非法记录', [\count($toDelete)]), 'success');
        }

        return \sprintf(__('处理完成：%d 条非法记录'), \count($toDelete));
    }
}
