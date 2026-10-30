<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * 客户已购事实（只读），供 customer_signals / 分群使用。
 */
final class CustomerPurchaseFactsService
{
    /**
     * @return array{order_count:int,last_paid_at:string,last_active_at:string}
     */
    public function summarize(int $customerId): array
    {
        if ($customerId <= 0) {
            return ['order_count' => 0, 'last_paid_at' => '', 'last_active_at' => ''];
        }

        try {
            /** @var Order $model */
            $model = ObjectManager::getInstance(Order::class);
            // 行在 fetchArray() 的返回值上；对 $model 调 getItems() 恒为空。
            $items = $model->clear()
                ->where(Order::schema_fields_CUSTOMER_ID, $customerId)
                ->where(Order::schema_fields_PAYMENT_STATUS, Order::PAYMENT_STATUS_PAID)
                ->order('created_at', 'DESC')
                ->select()
                ->fetchArray();
            $count = \count($items);
            $lastPaid = '';
            if ($count > 0 && \is_array($items[0] ?? null)) {
                $lastPaid = \trim((string)($items[0]['created_at']
                    ?? $items[0]['create_time']
                    ?? ''));
            }
            return [
                'order_count' => $count,
                'last_paid_at' => $lastPaid,
                'last_active_at' => $lastPaid,
            ];
        } catch (\Throwable) {
            return ['order_count' => 0, 'last_paid_at' => '', 'last_active_at' => ''];
        }
    }
}
