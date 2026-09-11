<?php

declare(strict_types=1);

namespace Weline\Dropship\Cron;

use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Service\DropshipSettings;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

class DropshipListingSync implements CronTaskInterface
{
    public function name(): string
    {
        return 'Weline_Dropship::listing_sync';
    }

    public function execute_name(): string
    {
        return 'Weline\\Dropship\\Cron\\DropshipListingSync::execute';
    }

    public function tip(): string
    {
        return '扫描货源 listing 入队跟随同步';
    }

    public function cron_time(): string
    {
        return '0 */6 * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }

    public function execute(): string
    {
        /** @var DropshipSettings $settings */
        $settings = ObjectManager::getInstance(DropshipSettings::class);
        if (!$settings->followEnabled()) {
            return '跟随已关闭';
        }

        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $rows = $model->clear()
            ->where(DropshipListing::schema_fields_SYNC_STATUS, DropshipListing::STATUS_ACTIVE)
            ->limit(200)
            ->select()
            ->fetchArray();

        $n = 0;
        foreach ((array)$rows as $row) {
            $listingId = (int)($row['listing_id'] ?? 0);
            if ($listingId <= 0 || !function_exists('w_query')) {
                continue;
            }
            w_query('queue', 'createIfAbsent', [
                'class' => \Weline\Dropship\Queue\DropshipListingSyncConsumer::class,
                'name' => 'Dropship listing sync #' . $listingId,
                'module' => 'Weline_Dropship',
                'content' => ['listing_id' => $listingId],
                'status' => 'pending',
                'auto' => true,
                'biz_key' => 'dropship:listing:sync:' . $listingId,
                'idempotency_scope' => 'dropship_listing_sync',
                'idempotency_key' => 'dropship:listing:sync:' . $listingId . ':' . date('YmdH'),
            ]);
            $n++;
        }

        return 'enqueued ' . $n;
    }
}
