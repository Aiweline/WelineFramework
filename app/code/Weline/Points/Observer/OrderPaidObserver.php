<?php

declare(strict_types=1);

namespace Weline\Points\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Points\Service\PointsService;

/**
 * Order paid is the single earning entry. Do not also subscribe to
 * order_completed or checkout payment success; that would double count.
 */
final class OrderPaidObserver implements ObserverInterface
{
    public function __construct(
        private readonly PointsService $points,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        try {
            $orderUuid = trim((string) ($data['order_uuid'] ?? ''));
            if ($orderUuid !== '' && interface_exists(\Weline\Points\Api\PointsDiscountSettlementInterface::class)) {
                $settlement = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Points\Api\PointsDiscountSettlementInterface::class,
                );
                if ($settlement instanceof \Weline\Points\Api\PointsDiscountSettlementInterface) {
                    $settlement->commitForOrder($orderUuid, 'order_paid:' . $orderUuid);
                }
            }
            $this->points->awardForOrder($data);
        } catch (\Throwable $exception) {
            if (\function_exists('w_log_error')) {
                w_log_error('Points order_paid side-effect failed: ' . $exception->getMessage(), [
                    'order_id' => (int) ($data['order_id'] ?? 0),
                    'exception' => $exception::class,
                ], 'points');
            }
        }
    }
}
