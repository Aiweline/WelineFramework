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
 * 订单状态观察者
 * 
 * 监听订单状态相关事件，提供状态翻译和样式信息
 */
class OrderStatusObserver implements ObserverInterface
{
    private OrderStatusService $statusService;
    
    public function __construct()
    {
        try {
            $this->statusService = ObjectManager::getInstance(OrderStatusService::class);
        } catch (\Throwable $e) {
            // 如果OrderStatusService不可用，观察者将不处理事件
            $this->statusService = null;
        }
    }
    
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        if ($this->statusService === null) {
            return;
        }
        
        $eventName = $event->getName();
        $data = $event->getData();
        
        switch ($eventName) {
            case 'Weline_Order::query::get_status_label':
                $this->handleGetStatusLabel($event, $data);
                break;
                
            case 'Weline_Order::query::get_status_class':
                $this->handleGetStatusClass($event, $data);
                break;
                
            case 'Weline_Order::query::get_payment_status_label':
                $this->handleGetPaymentStatusLabel($event, $data);
                break;
                
            case 'Weline_Order::query::get_fulfillment_status_label':
                $this->handleGetFulfillmentStatusLabel($event, $data);
                break;
                
            case 'Weline_Order::domain::resolve_status_info':
                $this->handleResolveStatusInfo($event, $data);
                break;
        }
    }
    
    /**
     * 处理获取订单状态标签事件
     * 
     * @param Event $event
     * @param array $data
     * @return void
     */
    private function handleGetStatusLabel(Event &$event, array $data): void
    {
        $status = $data['status'] ?? '';
        if (empty($status)) {
            return;
        }
        
        // 如果已经有标签，不覆盖
        if (!empty($data['label'])) {
            return;
        }
        
        try {
            $label = $this->statusService->getStatusName($status);
            if ($label !== $status) {
                $event->setData('label', $label);
            }
        } catch (\Throwable $e) {
            // 忽略错误，让回退逻辑处理
        }
    }
    
    /**
     * 处理获取订单状态CSS类事件
     * 
     * @param Event $event
     * @param array $data
     * @return void
     */
    private function handleGetStatusClass(Event &$event, array $data): void
    {
        $status = $data['status'] ?? '';
        if (empty($status)) {
            return;
        }
        
        // 如果已经有CSS类，不覆盖
        if (!empty($data['class'])) {
            return;
        }
        
        try {
            $class = $this->statusService->getStatusClass($status);
            if (!empty($class)) {
                $event->setData('class', $class);
            }
        } catch (\Throwable $e) {
            // 忽略错误，让回退逻辑处理
        }
    }
    
    /**
     * 处理获取支付状态标签事件
     * 
     * @param Event $event
     * @param array $data
     * @return void
     */
    private function handleGetPaymentStatusLabel(Event &$event, array $data): void
    {
        $status = $data['status'] ?? '';
        if (empty($status)) {
            return;
        }
        
        // 如果已经有标签，不覆盖
        if (!empty($data['label'])) {
            return;
        }
        
        // 支付状态暂时使用默认翻译，未来可以扩展OrderStatusService支持支付状态
        // 这里可以触发子事件或使用其他方式获取支付状态翻译
    }
    
    /**
     * 处理获取发货状态标签事件
     * 
     * @param Event $event
     * @param array $data
     * @return void
     */
    private function handleGetFulfillmentStatusLabel(Event &$event, array $data): void
    {
        $status = $data['status'] ?? '';
        if (empty($status)) {
            return;
        }
        
        // 如果已经有标签，不覆盖
        if (!empty($data['label'])) {
            return;
        }
        
        // 发货状态暂时使用默认翻译，未来可以扩展OrderStatusService支持发货状态
        // 这里可以触发子事件或使用其他方式获取发货状态翻译
    }
    
    /**
     * 处理解析完整状态信息事件
     * 
     * @param Event $event
     * @param array $data
     * @return void
     */
    private function handleResolveStatusInfo(Event &$event, array $data): void
    {
        $status = $data['status'] ?? '';
        if (empty($status)) {
            return;
        }
        
        try {
            // 获取状态标签
            if (empty($data['label'])) {
                $label = $this->statusService->getStatusName($status);
                if ($label !== $status) {
                    $event->setData('label', $label);
                }
            }
            
            // 获取CSS类
            if (empty($data['class'])) {
                $class = $this->statusService->getStatusClass($status);
                if (!empty($class)) {
                    $event->setData('class', $class);
                }
            }
            
            // 获取图标
            if (empty($data['icon'])) {
                $icon = $this->statusService->getStatusIcon($status);
                if ($icon !== null) {
                    $event->setData('icon', $icon);
                }
            }
        } catch (\Throwable $e) {
            // 忽略错误，让回退逻辑处理
        }
    }
}

