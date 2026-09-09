<?php

declare(strict_types=1);

namespace Weline\Geo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Service\FeedScheduleService;

/**
 * Every 10 minutes: sync sources and generate GEO feeds only when content changed.
 */
class AutoGenerateFeed implements CronTaskInterface
{
    public function name(): string
    {
        return 'Weline_Geo::auto_generate_feed';
    }

    public function execute_name(): string
    {
        return 'Weline\Geo\Cron\AutoGenerateFeed::execute';
    }

    public function tip(): string
    {
        return 'Every 10 minutes check GEO feed sources and generate only when content changed';
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 30;
    }

    public function execute(): string
    {
        try {
            /** @var FeedScheduleService $schedule */
            $schedule = ObjectManager::getInstance(FeedScheduleService::class);
            $result = $schedule->tick(true, 5000);

            return (string)($result['message'] ?? 'GEO feed schedule finished');
        } catch (\Throwable $e) {
            $message = '[Weline_Geo] AutoGenerateFeed failed: ' . $e->getMessage();
            w_log_error($message);
            return $message;
        }
    }
}
