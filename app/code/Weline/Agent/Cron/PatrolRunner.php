<?php
declare(strict_types=1);

namespace Weline\Agent\Cron;

use Weline\Agent\Service\PatrolService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 智能体状态巡检（P6）
 *
 * 每 5 分钟：同步流程节点 inspect_interval → AgentPatrol，
 * 执行到期巡检（状态 hash 比对；连续 3 次无变化且过截止 → 深度巡检）。
 */
class PatrolRunner implements CronTaskInterface
{
    public function name(): string
    {
        return '智能体状态巡检';
    }

    public function execute_name(): string
    {
        return 'agent_patrol_runner';
    }

    public function tip(): string
    {
        return '流程节点/项目状态巡检：hash 无变化计数，超时深度巡检';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }

    public function execute(): string
    {
        $service = ObjectManager::getInstance(PatrolService::class);
        $stats = $service->runDue();
        return sprintf(
            'patrol checked=%d changed=%d deep=%d errors=%d',
            $stats['checked'] ?? 0,
            $stats['changed'] ?? 0,
            $stats['deep'] ?? 0,
            $stats['errors'] ?? 0
        );
    }
}
