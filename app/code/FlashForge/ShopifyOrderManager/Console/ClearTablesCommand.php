<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order as OrderModel;
use FlashForge\ShopifyOrderManager\Model\OrderItem;

/**
 * 清空Shopify订单表命令
 */
class ClearTablesCommand implements CommandInterface
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
        echo "开始清空Shopify订单相关表...\n";
        
        // 获取清空前的数据统计
        $orderCount = $this->orderModel->total();
        $itemCount = $this->orderItemModel->total();
        
        echo "清空前数据统计:\n";
        echo "  订单表 (shopify_orders): {$orderCount} 条记录\n";
        echo "  订单项目表 (shopify_order_items): {$itemCount} 条记录\n\n";
        
        if ($orderCount == 0 && $itemCount == 0) {
            echo "两个表都已经是空的，无需清空。\n";
            return;
        }
        
        // 确认操作
        echo "⚠️  警告：此操作将永久删除所有Shopify订单数据！\n";
        echo "请确认是否继续？(输入 'yes' 确认): ";
        
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (trim($line) !== 'yes') {
            echo "操作已取消。\n";
            return;
        }
        
        try {
            // 先清空订单项目表（因为有外键约束）
            echo "正在清空订单项目表...\n";
            $this->orderItemModel->getConnection()->query("TRUNCATE TABLE shopify_order_items");
            echo "✅ 订单项目表已清空\n";
            
            // 再清空订单表
            echo "正在清空订单表...\n";
            $this->orderModel->getConnection()->query("TRUNCATE TABLE shopify_orders");
            echo "✅ 订单表已清空\n";
            
            // 验证清空结果
            $newOrderCount = $this->orderModel->total();
            $newItemCount = $this->orderItemModel->total();
            
            echo "\n清空后数据统计:\n";
            echo "  订单表 (shopify_orders): {$newOrderCount} 条记录\n";
            echo "  订单项目表 (shopify_order_items): {$newItemCount} 条记录\n";
            
            if ($newOrderCount == 0 && $newItemCount == 0) {
                echo "✅ 所有表已成功清空！\n";
            } else {
                echo "❌ 清空操作可能未完全成功\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ 清空操作失败: " . $e->getMessage() . "\n";
            echo "错误文件: " . $e->getFile() . "\n";
            echo "错误行号: " . $e->getLine() . "\n";
        }
    }

    public function tip(): string
    {
        return '清空Shopify订单和订单项目表的所有数据';
    }
}
