<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;

/** 域名购买成功：自动启动或推进生命周期编排 */
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
        $startLifecycle = $data['start_lifecycle'] ?? true;
        if ($startLifecycle === false || $startLifecycle === '0' || $startLifecycle === 'no') {
            return;
        }
        $lr = $data['lifecycle_result'] ?? [];
        if (($lr['success'] ?? false) && (int) ($lr['order_id'] ?? 0) > 0) {
            return;
        }

        try {
            /** @var DomainLifecycleOrchestrationService $service */
            $service = ObjectManager::getInstance(DomainLifecycleOrchestrationService::class);
            $service->startPurchasedLifecycle($domain, (int) ($data['account_id'] ?? 0), $data);
        } catch (\Throwable $e) {
            w_log_error('[Weline_Websites] ' . __('域名购买成功后续生命周期步骤失败：%{1}', [$e->getMessage()]));
        }
    }
}
