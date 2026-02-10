<?php

declare(strict_types=1);

namespace Weline\Saas\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Saas\Service\DomainProvisioningService;

/**
 * 域名购买成功：若存在对应 SaaS 配置订单，则自动推进并执行 DNS 步骤
 */
class DomainPurchaseSuccess implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            return;
        }
        $domain = $data['domain'] ?? '';
        if ($domain === '') {
            return;
        }

        try {
            $service = ObjectManager::getInstance(DomainProvisioningService::class);
            $order = $service->getOrderByDomain($domain);
            if ($order === null) {
                return;
            }
            $orderId = $order->getOrderId();
            $service->runStepDns($orderId);
        } catch (\Throwable $e) {
            \error_log('[Weline_Saas] ' . __('域名购买成功后续 DNS 步骤失败：%{1}', [$e->getMessage()]));
        }
    }
}
