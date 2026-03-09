<?php

declare(strict_types=1);

namespace Weline\Saas\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Saas\Service\DomainLifecycleOrchestrationService;

/**
 * 证书签发完成：若存在对应生命周期订单，则标记流程完成
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
            /** @var DomainLifecycleOrchestrationService $service */
            $service = ObjectManager::getInstance(DomainLifecycleOrchestrationService::class);
            $service->markCertificateIssued((string) $domain);
        } catch (\Throwable $e) {
            w_log_error('[Weline_Saas] ' . __('证书签发后更新配置订单状态失败：%{1}', [$e->getMessage()]));
        }
    }
}
