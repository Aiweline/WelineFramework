<?php
declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\ProvisioningOrder;

/** 响应 PageBuilder 配置订单查询事件 */
class ProvideProvisioningOrders implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            return;
        }

        try {
            $filter = $data['filter'] ?? [];
            $orders = $data['orders'] ?? [];

            /** @var ProvisioningOrder $orderModel */
            $orderModel = ObjectManager::getInstance(ProvisioningOrder::class);
            $query = $orderModel->clearQuery();

            if (!empty($filter['status'])) {
                $query->where(ProvisioningOrder::schema_fields_STATUS, $filter['status']);
            }
            if (!empty($filter['domain'])) {
                $query->where(ProvisioningOrder::schema_fields_DOMAIN, '%' . $filter['domain'] . '%', 'LIKE');
            }

            $rows = $query->order(ProvisioningOrder::schema_fields_ORDER_ID, 'DESC')
                ->select()
                ->fetchArray();

            foreach ($rows as $row) {
                $orders[] = [
                    'order_id' => (int) ($row[ProvisioningOrder::schema_fields_ORDER_ID] ?? 0),
                    'domain' => $row[ProvisioningOrder::schema_fields_DOMAIN] ?? '',
                    'status' => $row[ProvisioningOrder::schema_fields_STATUS] ?? 'pending',
                    'current_step' => $row[ProvisioningOrder::schema_fields_CURRENT_STEP] ?? '',
                    'registrar_account_id' => (int) ($row[ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID] ?? 0),
                    'cdn_vendor' => $row[ProvisioningOrder::schema_fields_CDN_VENDOR] ?? '',
                    'apply_ssl' => (bool) ($row[ProvisioningOrder::schema_fields_APPLY_SSL] ?? false),
                    'error_message' => $row[ProvisioningOrder::schema_fields_ERROR_MESSAGE] ?? '',
                    'created_at' => $row[ProvisioningOrder::schema_fields_CREATED_AT] ?? '',
                    'updated_at' => $row[ProvisioningOrder::schema_fields_UPDATED_AT] ?? '',
                ];
            }

            $data['orders'] = $orders;
            $event->setData('data', $data);
        } catch (\Throwable $e) {
            w_log_error(__('查询配置订单失败：%{1}', $e->getMessage()));
        }
    }
}
