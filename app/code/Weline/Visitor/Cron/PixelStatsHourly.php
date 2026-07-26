<?php

declare(strict_types=1);

namespace Weline\Visitor\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelStatsHourlyAggregateService;

/**
 * G04：像素小时聚合 Cron（只写 pixel_stats_hourly + job_log；不写日表、不删热）。
 */
class PixelStatsHourly implements CronTaskInterface
{
    public function name(): string
    {
        return 'PixelStatsHourly';
    }

    public function execute_name(): string
    {
        return 'pixel_stats_hourly';
    }

    public function tip(): string
    {
        return '按站点时区聚合上一完整小时像素事件到 pixel_stats_hourly，并写 pixel_stats_job_log（覆盖式重跑）';
    }

    public function cron_time(): string
    {
        // 每小时第 5 分钟，给迟到事件留缓冲
        return '5 * * * *';
    }

    public function execute(): string
    {
        /** @var PixelStatsHourlyAggregateService $service */
        $service = ObjectManager::getInstance(PixelStatsHourlyAggregateService::class);
        $summary = $service->runPreviousHourForAll();

        return sprintf(
            'ok=%d failed=%d targets=%d',
            (int)$summary['ok'],
            (int)$summary['failed'],
            \count($summary['results'])
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(30, $minute);
    }
}
