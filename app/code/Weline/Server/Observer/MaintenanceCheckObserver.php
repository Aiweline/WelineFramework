<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * 维护模式状态查询：
 * WLS 下优先尊重 maintenance worker 自身上下文；否则通过 IPC 向 Orchestrator 查询当前维护状态。
 */
class MaintenanceCheckObserver implements ObserverInterface
{
    private const DEFAULT_STATUS_TIMEOUT_SEC = 2.0;
    private const REQUEST_FIBER_STATUS_TIMEOUT_SEC = 0.05;
    public function execute(Event &$event): void
    {
        if ($this->isCurrentProcessMaintenanceWorker()) {
            $event->setData('result', true);
            return;
        }

        $instance = \getenv('WLS_INSTANCE') ?: 'default';
        try {
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $status = $gateway->getStatus($instance, $this->resolveStatusTimeout());
            if (!empty($status['success']) && isset($status['data']['maintenance_mode'])) {
                $event->setData('result', (bool) $status['data']['maintenance_mode']);
            }
        } catch (\Throwable) {
            // 保持降级到文件配置检查
        }
    }

    private function resolveStatusTimeout(): float
    {
        // Request fibers should not spend seconds waiting on control-plane status.
        // If IPC is slow or temporarily unavailable, Env will fall back to the local config value.
        if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
            return self::REQUEST_FIBER_STATUS_TIMEOUT_SEC;
        }

        return self::DEFAULT_STATUS_TIMEOUT_SEC;
    }

    private function isCurrentProcessMaintenanceWorker(): bool
    {
        if ((bool) ErrorContext::get('is_maintenance', false)) {
            return true;
        }

        $processTag = ErrorContext::getProcessTag();
        if (\is_string($processTag) && $processTag !== '' && \str_contains($processTag, 'Maintenance')) {
            return true;
        }

        return false;
    }
}
