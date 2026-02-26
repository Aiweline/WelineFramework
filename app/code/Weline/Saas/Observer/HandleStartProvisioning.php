<?php
declare(strict_types=1);

namespace Weline\Saas\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Saas\Service\DomainProvisioningService;

/**
 * @DESC | 响应 PageBuilder 一站式配置启动事件
 */
class HandleStartProvisioning implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            return;
        }

        $domain = trim($data['domain'] ?? '');
        $registrarAccountId = (int) ($data['registrar_account_id'] ?? 0);
        $options = $data['options'] ?? [];

        if ($domain === '' || $registrarAccountId <= 0) {
            return;
        }

        try {
            /** @var DomainProvisioningService $service */
            $service = ObjectManager::getInstance(DomainProvisioningService::class);
            $result = $service->startProvisioning($domain, $registrarAccountId, $options);

            $data['result'] = $result;
            $event->setData('data', $data);
        } catch (\Throwable $e) {
            $data['result'] = [
                'success' => false,
                'message' => __('启动一站式配置失败：%{1}', $e->getMessage()),
            ];
            $event->setData('data', $data);
        }
    }
}
