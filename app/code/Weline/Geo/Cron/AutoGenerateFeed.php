<?php

declare(strict_types=1);

namespace Weline\Geo\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Service\FeedQueueService;

/**
 * Periodically enqueues GEO feed generation for enabled feeds.
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
        return 'Automatically generate GEO feed files based on each feed update frequency';
    }

    public function cron_time(): string
    {
        return '*/15 * * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 30;
    }

    public function execute(): string
    {
        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feeds = $feedModel
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->select()
                ->fetchArray();

            if (empty($feeds)) {
                return 'No enabled GEO feeds need generation';
            }

            /** @var FeedQueueService $queueService */
            $queueService = ObjectManager::getInstance(FeedQueueService::class);
            $now = time();
            $checked = 0;
            $enqueued = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($feeds as $feedData) {
                $checked++;
                $feedId = (int)($feedData[Feed::schema_fields_ID] ?? 0);
                if ($feedId <= 0) {
                    $skipped++;
                    continue;
                }

                $frequency = (string)($feedData[Feed::schema_fields_UPDATE_FREQUENCY] ?? Feed::FREQUENCY_DAILY);
                $lastGeneratedAt = (int)($feedData[Feed::schema_fields_LAST_GENERATED_AT] ?? 0);
                if (!$this->shouldGenerate($frequency, $lastGeneratedAt, $now)) {
                    $skipped++;
                    continue;
                }

                try {
                    $queueService->enqueueFeedGenerate($feedId, 'json_feed', true);
                    $enqueued++;
                } catch (\Throwable $e) {
                    $errors++;
                    w_log_error(sprintf(
                        '[Weline_Geo] AutoGenerateFeed enqueue failed: feed_id=%d, error=%s',
                        $feedId,
                        $e->getMessage()
                    ));
                }
            }

            return sprintf(
                'GEO feed auto generation checked=%d, enqueued=%d, skipped=%d, errors=%d',
                $checked,
                $enqueued,
                $skipped,
                $errors
            );
        } catch (\Throwable $e) {
            $message = '[Weline_Geo] AutoGenerateFeed failed: ' . $e->getMessage();
            w_log_error($message);
            return $message;
        }
    }

    private function shouldGenerate(string $frequency, int $lastGeneratedAt, int $now): bool
    {
        if ($lastGeneratedAt <= 0) {
            return true;
        }

        $interval = match ($frequency) {
            Feed::FREQUENCY_REALTIME => 300,
            Feed::FREQUENCY_HOURLY => 3600,
            Feed::FREQUENCY_WEEKLY => 604800,
            Feed::FREQUENCY_DAILY => 86400,
            default => 86400,
        };

        return ($now - $lastGeneratedAt) >= $interval;
    }
}
