<?php
declare(strict_types=1);

/**
 * Weline Server - Master 复活失败观察者
 *
 * 监听 Weline_Server::service::master_resurrection_failed，将异常上报至后台消息中心，
 * 便于管理员人工处理（执行 server:start 或 server:restart）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class MasterResurrectionFailedObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $instanceName = $event->getData('instance_name') ?? '';
        $attempts = $event->getData('attempts') ?? 0;
        $message = $event->getData('message') ?? '';

        if ($message === '') {
            $message = \sprintf(
                __('WLS 实例 [%s] Master 复活失败，已尝试 %d 次，请人工检查并执行 server:start 或 server:restart。'),
                $instanceName,
                $attempts
            );
        }

        $title = __('WLS Master 复活失败');

        try {
            w_msg(
                'server_error',
                'error',
                $title,
                $message,
                [
                    'icon' => 'ri-error-warning-line',
                    'source_module' => 'Weline_Server',
                    'metadata' => [
                        'instance_name' => $instanceName,
                        'attempts' => $attempts,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            w_log_error('[MasterResurrectionFailedObserver] w_msg failed: ' . $e->getMessage(), [], 'server');
        }
    }
}
