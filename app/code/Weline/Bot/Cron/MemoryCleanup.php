<?php
declare(strict_types=1);

namespace Weline\Bot\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Bot\Service\MemoryService;

/**
 * 记忆清理任务
 *
 * 清理过期和低价值的记忆
 */
class MemoryCleanup implements CronTaskInterface
{
    public function __construct(
        private readonly MemoryService $memoryService,
    ) {}

    public function name(): string
    {
        return 'Bot 记忆清理';
    }

    public function execute_name(): string
    {
        return 'bot_memory_cleanup';
    }

    public function tip(): string
    {
        return '清理过期和低价值的记忆节点';
    }

    public function cron_time(): string
    {
        return '0 3 * * *'; // 每天凌晨 3 点执行
    }

    public function execute(): string
    {
        $cleaned = $this->memoryService->cleanup(100);
        return "已清理 {$cleaned} 条过期记忆";
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 10;
    }
}
