<?php

declare(strict_types=1);

namespace Weline\Shipping\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingLabelOrphanProcessor;

/** Retry pending orphan label cancels (capped attempts). */
final class ShippingLabelOrphanRetry implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('Shipping 孤儿运单取消重试');
    }

    public function execute_name(): string
    {
        return 'shipping_label_orphan_retry';
    }

    public function tip(): string
    {
        return (string)__('履约账本失败后的 cancelShipment 孤儿补偿队列');
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        /** @var ShippingLabelOrphanProcessor $processor */
        $processor = ObjectManager::getInstance(ShippingLabelOrphanProcessor::class);
        $result = $processor->processDue(20);

        return (string)__('孤儿运单处理：done=%{1} dead=%{2} processed=%{3}', [
            (string)(int)($result['done'] ?? 0),
            (string)(int)($result['dead'] ?? 0),
            (string)(int)($result['processed'] ?? 0),
        ]);
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(60, $minute);
    }
}
