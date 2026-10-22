<?php

declare(strict_types=1);

namespace Weline\Search\Cron;

use Weline\Framework\Cron\CronTaskInterface;

/**
 * 保留任务名，避免已安装的 cron 行指向丢失的类。
 * 新鲜度由内容变更队列负责，这里不再整表重建。
 */
class ProviderIndexRebuild implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('Search 内容索引由变更队列追平');
    }

    public function execute_name(): string
    {
        return 'search_provider_index_rebuild';
    }

    public function tip(): string
    {
        return (string)__('不再整表重建。保存或删除进入队列，按版本追平；全量只在手动修复命令');
    }

    public function cron_time(): string
    {
        return '20 3 * * *';
    }

    public function execute(): string
    {
        return (string)__('内容索引由变更队列追平，本任务不整表重建。');
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(60, $minute);
    }
}
