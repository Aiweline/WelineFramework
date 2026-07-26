<?php

declare(strict_types=1);

namespace Weline\Visitor\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelStatsDailyAggregateService;

/**
 * G05：像素日聚合 Cron（只写 pixel_stats_daily + job_log；含 §2.5 校验；不删热）。
 */
class PixelStatsDaily implements CronTaskInterface
{
    public function name(): string
    {
        return 'PixelStatsDaily';
    }

    public function execute_name(): string
    {
        return 'pixel_stats_daily';
    }

    public function tip(): string
    {
        return '按站点时区聚合前一完整日像素事件到 pixel_stats_daily，写 job_log；events 与热表偏差超阈值则 failed';
    }

    public function cron_time(): string
    {
        // 每天 01:15，给迟到事件与小时聚合留缓冲
        return '15 1 * * *';
    }

    public function execute(): string
    {
        /** @var PixelStatsDailyAggregateService $service */
        $service = ObjectManager::getInstance(PixelStatsDailyAggregateService::class);
        $summary = $service->runPreviousDayForAll();

        return sprintf(
            'ok=%d failed=%d targets=%d',
            (int)$summary['ok'],
            (int)$summary['failed'],
            \count($summary['results'])
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(60, $minute);
    }
}
