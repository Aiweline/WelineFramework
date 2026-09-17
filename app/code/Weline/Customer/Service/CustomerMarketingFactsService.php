<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客户营销事实：联系方式 + 注册时间；已购摘要经 order_signals（不直读 Order Model）。
 */
final class CustomerMarketingFactsService
{
    /**
     * @return array{customer_id:int,email:string,has_email:bool,created_at:string,order_count:int,last_active_at:string}|null
     */
    public function getFacts(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        try {
            /** @var Customer $model */
            $model = ObjectManager::getInstance(Customer::class);
            $model->load($customerId);
            if (!(int)$model->getId()) {
                return null;
            }
            $email = \trim((string)$model->getData(Customer::schema_fields_email));
            $createdAt = \trim((string)($model->getData('created_at') ?? $model->getData('create_time') ?? ''));
            $purchase = $this->purchaseSummary($customerId);
            $lastActive = \trim((string)($purchase['last_active_at'] ?? ''));
            if ($lastActive === '') {
                $lastActive = $createdAt;
            }

            return [
                'customer_id' => $customerId,
                'email' => $email,
                'has_email' => $email !== '',
                'created_at' => $createdAt,
                'order_count' => (int)($purchase['order_count'] ?? 0),
                'last_active_at' => $lastActive,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{order_count?:int,last_active_at?:string} */
    private function purchaseSummary(int $customerId): array
    {
        if (!\function_exists('w_query')) {
            return ['order_count' => 0, 'last_active_at' => ''];
        }
        try {
            $result = w_query('order_signals', 'get_customer_purchase_summary', [
                'customer_id' => $customerId,
            ]);
            if (\is_array($result) && \is_array($result['item'] ?? null)) {
                return $result['item'];
            }
        } catch (\Throwable) {
        }

        return ['order_count' => 0, 'last_active_at' => ''];
    }
}
