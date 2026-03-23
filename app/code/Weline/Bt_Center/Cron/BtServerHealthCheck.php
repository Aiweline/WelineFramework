<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Cron;

use Weline\Bt_Center\Service\BtServerMonitorService;
use Weline\Cron\CronTaskInterface;

class BtServerHealthCheck implements CronTaskInterface
{
    public function __construct(
        private readonly BtServerMonitorService $monitorService
    ) {
    }

    public function name(): string
    {
        return __('BT 服务器健康检查');
    }

    public function execute_name(): string
    {
        return 'bt_server_health_check';
    }

    public function tip(): string
    {
        return __('每 10 分钟检查一次 BT 面板是否可访问，并在状态变化时发送通知。');
    }

    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    public function execute(): string
    {
        $stats = $this->monitorService->run();
        $message = (string) __(
            'BT 健康检查完成：检测 %{1} 台，可访问 %{2} 台，不可访问 %{3} 台，状态变化 %{4} 台，已通知 %{5} 次',
            [
                (string) $stats['checked'],
                (string) $stats['up'],
                (string) $stats['down'],
                (string) $stats['changed'],
                (string) $stats['notified'],
            ]
        );
        w_log_info('[BtServerHealthCheck] ' . $message, [], 'bt_center_health');
        return $message;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 20;
    }
}
