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
        echo "开始检查重复的订单项目（基于shop_id和shopify_line_item_id）...\n";
        
        // 查找重复的订单项目 - 基于shop_id和shopify_line_item_id组合
        $duplicates = $this->orderItemModel->getConnection()->query("
            SELECT shop_id, shopify_item_id, COUNT(*) as count, GROUP_CONCAT(item_id) as item_ids
            FROM shopify_order_items 
            WHERE shopify_item_id IS NOT NULL AND shopify_item_id != ''
            GROUP BY shop_id, shopify_item_id 
            HAVING COUNT(*) > 1
        ")->fetchArray();
        
        $totalDuplicates = 0;
        $cleanedCount = 0;
        
        if (empty($duplicates)) {
            echo "没有发现重复的订单项目！\n";
            return;
        }
        
        echo "发现 " . count($duplicates) . " 组重复的订单项目:\n";
        
        foreach ($duplicates as $duplicate) {
            $shopId = $duplicate['shop_id'];
            $shopifyItemId = $duplicate['shopify_item_id'];
            $count = $duplicate['count'];
            $itemIds = explode(',', $duplicate['item_ids']);
            
            $totalDuplicates += ($count - 1); // 保留一个，删除其余的
            
            echo "店铺ID: {$shopId}, Shopify项目ID: {$shopifyItemId}, 重复数量: {$count}\n";
            echo "  项目IDs: " . implode(', ', $itemIds) . "\n";
            
            // 保留第一个，删除其余的
            $keepId = array_shift($itemIds);
            $deleteIds = $itemIds;
            
            if (!empty($deleteIds)) {
                $deleteIdsStr = implode(',', $deleteIds);
                $result = $this->orderItemModel->getConnection()->query("
                    DELETE FROM shopify_order_items 
                    WHERE item_id IN ({$deleteIdsStr})
                ");
                $deleted = $result ? count($deleteIds) : 0;
                
                $cleanedCount += $deleted;
                echo "  保留项目ID: {$keepId}, 删除项目IDs: " . implode(', ', $deleteIds) . " (共{$deleted}个)\n";
            }
        }
        
        echo "清理完成！\n";
        echo "总重复项目数: {$totalDuplicates}\n";
        echo "已清理项目数: {$cleanedCount}\n";
    }

    public function tip(): string
    {
        return '清理重复的订单项目';
    }
}
