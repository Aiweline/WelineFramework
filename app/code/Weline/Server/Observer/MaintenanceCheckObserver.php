<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * 维护模式状态查询：WLS 下通过 IPC 向 Orchestrator 请求当前维护状态并回写事件 data['result']
 */
class MaintenanceCheckObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $instance = \getenv('WLS_INSTANCE') ?: 'default';
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $status = $gateway->getStatus($instance, 2.0);
            if (!empty($status['success']) && isset($status['data']['maintenance_mode'])) {
                $event->setData('result', (bool)$status['data']['maintenance_mode']);
            }
        } catch (\Throwable) {
            // 不设置 result，Env 将回退到文件检查
        }
    }
}
