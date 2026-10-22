<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Product\Service\ProductReturnAddress;

/**
 * 把订单商品上的退货地址写回收集事件。没有或读失败就留空。
 */
class BuyerAftersaleProductReturnAddressObserver implements ObserverInterface
{
    public function __construct(private readonly ProductReturnAddress $addresses)
    {
    }

    public function execute(Event &$event): void
    {
        $websiteId = (int)$event->getData('website_id');
        $productIds = $event->getData('product_ids');
        if (!\is_array($productIds)) {
            $event->setData('product_return_addresses', []);

            return;
        }
        $ids = [];
        foreach ($productIds as $productId) {
            $id = (int)$productId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $event->setData('product_return_addresses', $this->addresses->map($websiteId, $ids));
    }
}
