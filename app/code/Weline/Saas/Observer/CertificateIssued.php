<?php

declare(strict_types=1);

namespace Weline\Saas\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Service\DomainProvisioningService;

/**
 * 证书签发完成：若存在对应 SaaS 配置订单且当前在 SSL 步骤，则标记流程完成
 */
class CertificateIssued implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        if (empty($domain)) {
            return;
        }

        try {
            $service = ObjectManager::getInstance(DomainProvisioningService::class);
            $order = $service->getOrderByDomain((string) $domain);
            if ($order === null) {
                return;
            }
            if ($order->getStatus() !== ProvisioningOrder::STATUS_STEP_SSL) {
                return;
            }
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
            $order->setData(ProvisioningOrder::fields_CURRENT_STEP, '');
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, '');
            $order->save();
        } catch (\Throwable $e) {
            w_log_error('[Weline_Saas] ' . __('证书签发后更新配置订单状态失败：%{1}', [$e->getMessage()]));
        }
    }
}
