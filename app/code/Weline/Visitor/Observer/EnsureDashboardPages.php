<?php

declare(strict_types=1);

namespace Weline\Visitor\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

class EnsureDashboardPages implements ObserverInterface
{
    public function __construct(
        private readonly VisitorDashboardPageInstaller $installer
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $event->setData('visitor_dashboard_pages', $this->installer->ensurePages());
        } catch (\Throwable $throwable) {
            w_log_error('初始化 Visitor Dashboard 页面失败: ' . $throwable->getMessage(), [], 'VisitorDashboard');
        }
    }
}
