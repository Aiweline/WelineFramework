<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Helper;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * 订单状态辅助类
 * 
 * 提供便捷的静态方法，封装事件调用逻辑，供模板和控制器使用
 */
class StatusHelper
{
    /**
     * 获取订单状态标签
     * 
     * @param string $status 状态代码
     * @return string 状态标签
     */
    public static function getStatusLabel(string $status): string
    {
        return Order::getStatusLabel($status);
    }
    
    /**
     * 获取订单状态CSS类
     * 
     * @param string $status 状态代码
     * @return string CSS类名
     */
    public static function getStatusClass(string $status): string
    {
        return Order::getStatusClassByCode($status);
    }
    
    /**
     * 获取支付状态标签
     * 
     * @param string $status 支付状态代码
     * @return string 状态标签
     */
    public static function getPaymentStatusLabel(string $status): string
    {
        return Order::getPaymentStatusLabel($status);
    }
    
    /**
     * 获取发货状态标签
     * 
     * @param string $status 发货状态代码
     * @return string 状态标签
     */
    public static function getFulfillmentStatusLabel(string $status): string
    {
        return Order::getFulfillmentStatusLabel($status);
    }
    
    /**
     * 解析完整状态信息
     * 
     * 通过事件机制获取订单状态的完整信息（标签、CSS类、图标等）
     * 
     * @param string $status 状态代码
     * @return array 包含label、class、icon、color、description的数组
     */
    public static function resolveStatusInfo(string $status): array
    {
        if (empty($status)) {
            return [
                'label' => '',
                'class' => 'secondary',
                'icon' => null,
                'color' => null,
                'description' => null,
            ];
        }
        
        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            
            // 创建事件数据对象
            $eventData = new DataObject([
                'status' => $status,
                'label' => '',
                'class' => '',
                'icon' => null,
                'color' => null,
                'description' => null,
            ]);
            
            // 触发事件
            $eventsManager->dispatch('Weline_Order::domain::resolve_status_info', $eventData);
            
            // 从事件数据中获取结果
            return [
                'label' => $eventData->getData('label') ?: Order::getStatusLabel($status),
                'class' => $eventData->getData('class') ?: Order::getStatusClassByCode($status),
                'icon' => $eventData->getData('icon'),
                'color' => $eventData->getData('color'),
                'description' => $eventData->getData('description'),
            ];
        } catch (\Throwable $e) {
            // 如果事件系统不可用，使用默认值
            return [
                'label' => Order::getStatusLabel($status),
                'class' => Order::getStatusClassByCode($status),
                'icon' => null,
                'color' => null,
                'description' => null,
            ];
        }
    }
    
    /**
     * 获取状态徽章HTML
     * 
     * 生成完整的状态徽章HTML代码
     * 
     * @param string $status 状态代码
     * @param string|null $icon 图标类名（可选）
     * @return string HTML代码
     */
    public static function getStatusBadge(string $status, ?string $icon = null): string
    {
        $info = self::resolveStatusInfo($status);
        $iconHtml = '';
        
        if ($icon !== null || $info['icon'] !== null) {
            $iconClass = $icon ?? $info['icon'];
            $iconHtml = '<i class="' . htmlspecialchars($iconClass) . '"></i> ';
        }
        
        return sprintf(
            '<span class="badge bg-%s">%s%s</span>',
            htmlspecialchars($info['class']),
            $iconHtml,
            htmlspecialchars($info['label'])
        );
    }
}

