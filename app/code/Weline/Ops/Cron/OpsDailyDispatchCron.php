<?php

declare(strict_types=1);

namespace Weline\Ops\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsDispatchService;
use Weline\Ops\Service\OpsProgramService;

/**
 * 对已开启 AI 运营的站点触发日更受理（分析 + 分发入队）。
 */
final class OpsDailyDispatchCron implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('Ops 日更调度派工');
    }

    public function execute_name(): string
    {
        return 'ops_daily_dispatch';
    }

    public function tip(): string
    {
        return (string)__('对已启用 AI 运营的网站调用 acceptConfig，生成分析与分发任务并入队');
    }

    public function cron_time(): string
    {
        return '5 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }

    public function execute(): string
    {
        /** @var OpsProgramService $programs */
        $programs = ObjectManager::getInstance(OpsProgramService::class);
        /** @var OpsDispatchService $dispatch */
        $dispatch = ObjectManager::getInstance(OpsDispatchService::class);

        $hour = (int)date('G');
        $accepted = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($programs->listEnabled() as $row) {
            $daily = trim((string)($row['daily_time'] ?? '09:00'));
            $parts = explode(':', $daily);
            $targetHour = (int)($parts[0] ?? 9);
            if ($targetHour !== $hour) {
                ++$skipped;
                continue;
            }
            $result = $dispatch->acceptConfig([
                'website_id' => (int)$row['website_id'],
                'lanes' => ['content', 'social'],
                'topic' => (string)__('日更') . ' ' . date('Y-m-d'),
                'auto_draft' => true,
            ]);
            if ($result['ok'] ?? false) {
                ++$accepted;
            } else {
                ++$failed;
            }
        }

        return sprintf('ops daily dispatch: accepted=%d skipped=%d failed=%d', $accepted, $skipped, $failed);
    }
}
