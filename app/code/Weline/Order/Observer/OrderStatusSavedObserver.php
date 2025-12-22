<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\OrderStatusService;

/**
 * 订单状态保存后观察者
 * 
 * 处理状态保存后的逻辑：
 * - 清除状态缓存
 * - 更新翻译缓存
 */
class OrderStatusSavedObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $status = $data['status'] ?? null;
        
        if (!$status) {
            return;
        }
        
        // 清除状态服务缓存
        try {
            /** @var OrderStatusService $statusService */
            $statusService = ObjectManager::getInstance(OrderStatusService::class);
            // 状态服务内部有缓存机制，这里可以触发缓存清理
            // 由于OrderStatusService使用了私有属性缓存，需要重新实例化或提供清理方法
            // 暂时留空，等待OrderStatusService添加缓存清理方法
        } catch (\Throwable $e) {
            // 忽略错误，不影响主流程
        }
        
        // 可以在这里添加其他逻辑，如：
        // - 更新其他模块的状态定义缓存
        // - 通知其他系统状态已更新
        // - 记录状态变更日志
    }
}

