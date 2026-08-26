<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Product\Api\ProductDownloadEntitlementInterface;

final class GrantDownloadEntitlementsOnOrderPaid implements ObserverInterface
{
    public function __construct(
        private readonly ProductDownloadEntitlementInterface $entitlements,
    ) {
    }

    public function execute(Event &$event): void
    {
        $orderUuid = trim((string)$event->getData('order_uuid'));
        if ($orderUuid === '') {
            throw new \UnexpectedValueException('download_order_uuid_missing');
        }
        $this->entitlements->grantForPaidOrder($orderUuid);
    }
}
