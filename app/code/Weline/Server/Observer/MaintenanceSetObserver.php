<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * 维护模式设置：WLS 下通过 IPC 通知 Orchestrator 开启/关闭维护，并标记 data['handled']
 */
class MaintenanceSetObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $enabled = (bool)($event->getData('value') ?? false);
        $instance = \getenv('WLS_INSTANCE') ?: 'default';
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $action = $enabled
                ? ControlMessage::ACTION_MAINTENANCE_ENABLE
                : ControlMessage::ACTION_MAINTENANCE_DISABLE;
            $result = $gateway->command($instance, $action, '', [], 6.0);
            if (!empty($result['success'])) {
                $event->setData('handled', true);
            }
        } catch (\Throwable) {
            // 不设置 handled，Env 将回退到写文件标志
        }
    }
}
