<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order as OrderModel;
use FlashForge\ShopifyOrderManager\Model\OrderItem;

/**
 * 清理重复订单项目命令
 */
class CleanDuplicateItemsCommand implements CommandInterface
{
    private OrderModel $orderModel;
    private OrderItem $orderItemModel;

    public function __construct()
    {
        $this->orderModel = ObjectManager::getInstance(OrderModel::class);
        $this->orderItemModel = ObjectManager::getInstance(OrderItem::class);
    }

    public function execute(array $args = [], array $data = []): void
    {
        echo "开始检查重复的订单项目...\n";
        
        // 获取所有订单
        $orders = $this->orderModel->select()->fetchArray();
        $totalOrders = count($orders);
        $totalDuplicates = 0;
        $ordersWithDuplicates = 0;
        
        echo "检查 {$totalOrders} 个订单...\n";
        
        foreach ($orders as $order) {
            $orderId = $order['order_id'];
            $orderNumber = $order['order_number'];
            
            // 获取订单项目统计
            $stats = $this->orderItemModel->getOrderItemStats($orderId);
            
            if (!empty($stats['duplicates'])) {
                $ordersWithDuplicates++;
                $duplicateCount = array_sum($stats['duplicates']);
                $totalDuplicates += $duplicateCount;
                
                echo "订单 {$orderNumber} (ID: {$orderId}) 发现 {$duplicateCount} 个重复项目:\n";
                
                foreach ($stats['duplicates'] as $key => $count) {
                    echo "  - {$key}: {$count} 个重复\n";
                }
                
                // 清理重复项目
                $cleanedCount = $this->orderItemModel->cleanDuplicateItems($orderId);
                echo "  已清理 {$cleanedCount} 个重复项目\n";
            }
        }
        
        echo "检查完成！\n";
        echo "总订单数: {$totalOrders}\n";
        echo "有重复项目的订单数: {$ordersWithDuplicates}\n";
        echo "总重复项目数: {$totalDuplicates}\n";
        
        if ($totalDuplicates > 0) {
            echo "已清理所有重复项目！\n";
        } else {
            echo "没有发现重复项目！\n";
        }
    }

    public function tip(): string
    {
        return '清理重复的订单项目';
    }
}
