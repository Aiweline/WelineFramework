<?php
declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;

/** 响应 PageBuilder 一站式配置启动事件 */
class HandleStartProvisioning implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            $data = ['result' => ['success' => false, 'message' => __('事件数据格式错误')]];
            $event->setData('data', $data);

            return;
        }

        $domain = trim($data['domain'] ?? '');
        $registrarAccountId = (int) ($data['registrar_account_id'] ?? 0);
        $options = $data['options'] ?? [];

        if ($domain === '') {
            $data['result'] = [
                'success' => false,
                'message' => __('域名不能为空'),
            ];
            $event->setData('data', $data);

            return;
        }

        if ($registrarAccountId <= 0) {
            $data['result'] = [
                'success' => false,
                'message' => __('请选择域名商账号（registrar_account_id 无效）'),
            ];
            $event->setData('data', $data);

            return;
        }

        try {
            /** @var DomainLifecycleOrchestrationService $service */
            $service = ObjectManager::getInstance(DomainLifecycleOrchestrationService::class);
            $data['result'] = $service->startProvisioning($domain, $registrarAccountId, $options);
            $event->setData('data', $data);
        } catch (\Throwable $e) {
            $data['result'] = [
                'success' => false,
                'message' => __('启动一站式配置失败：%{1}', [$e->getMessage()]),
            ];
            $event->setData('data', $data);
        }
    }
}
