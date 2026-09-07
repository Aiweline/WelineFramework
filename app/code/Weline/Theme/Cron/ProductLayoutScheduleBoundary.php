<?php

declare(strict_types=1);

namespace Weline\Theme\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ProductLayoutScheduleService;

/**
 * Scan product layout schedule start/end boundaries and bust target caches.
 */
class ProductLayoutScheduleBoundary implements CronTaskInterface
{
    public function name(): string
    {
        return (string)__('产品布局定时边界缓存清理');
    }

    public function execute_name(): string
    {
        return 'theme_product_layout_schedule_boundary';
    }

    public function tip(): string
    {
        return (string)__('扫描产品/分类默认布局定时的生效与失效边界，按目标清理详情/FPC 缓存（不写回永久选择）');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        try {
            /** @var ProductLayoutScheduleService $schedules */
            $schedules = ObjectManager::getInstance(ProductLayoutScheduleService::class);
            $events = $schedules->processBoundariesNear(new \DateTimeImmutable('now'), 90);
            $count = count($events);

            return (string)__('产品布局定时边界处理完成：%{1} 条', $count);
        } catch (\Throwable $e) {
            w_log_error('theme_product_layout_schedule_boundary_failed: ' . $e->getMessage());

            return (string)__('产品布局定时边界处理失败：%{1}', $e->getMessage());
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(5, $minute);
    }
}
