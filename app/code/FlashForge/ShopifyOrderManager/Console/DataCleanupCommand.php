<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 数据清理命令行工具
 * 用法：php bin/w shopify:cleanup [--dry-run] [--days=90]
 */
class DataCleanupCommand implements CommandInterface
{
    private Order $orderModel;
    private OrderItem $orderItemModel;
    private Shop $shopModel;

    public function __construct()
    {
        $this->orderModel = ObjectManager::getInstance(Order::class);
        $this->orderItemModel = ObjectManager::getInstance(OrderItem::class);
        $this->shopModel = ObjectManager::getInstance(Shop::class);
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        $dryRun = isset($args['dry-run']) || isset($args['dry_run']);
        $days = intval($args['days'] ?? 90);
        
        echo "🧹 Shopify数据清理工具\n";
        echo "==================\n\n";

        if ($dryRun) {
            echo "🔍 预览模式 - 不会实际删除数据\n";
        } else {
            echo "⚠️  实际删除模式 - 将永久删除数据\n";
        }
        
        echo "📅 清理超过 {$days} 天的数据\n";
        echo "==================\n\n";

        try {
            $this->cleanupOrders($days, $dryRun);
            $this->cleanupOrderItems($days, $dryRun);
            $this->cleanupOrphanedData($dryRun);
            $this->optimizeTables($dryRun);

        } catch (\Exception $e) {
            echo "❌ 清理失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 清理过期订单
     */
    private function cleanupOrders(int $days, bool $dryRun): void
    {
        echo "📋 清理过期订单...\n";
        
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // 统计要删除的订单
        $ordersToDelete = $this->orderModel
            ->where(Order::fields_SHOPIFY_CREATED_AT, $cutoffDate, '<')
            ->where(Order::fields_ORDER_STATUS, Order::STATUS_CANCELLED, '=', 'OR')
            ->where(Order::fields_ORDER_STATUS, Order::STATUS_REFUNDED, '=', 'OR')
            ->total();

        echo "   找到 {$ordersToDelete} 个过期订单\n";

        if ($ordersToDelete > 0) {
            if ($dryRun) {
                echo "   🔍 预览: 将删除 {$ordersToDelete} 个订单\n";
            } else {
                // 开始事务
                $this->orderModel->beginTransaction();
                
                try {
                    // 先删除相关的订单项目
                    $orderIds = $this->orderModel
                        ->where(Order::fields_SHOPIFY_CREATED_AT, $cutoffDate, '<')
                        ->where(Order::fields_ORDER_STATUS, Order::STATUS_CANCELLED, '=', 'OR')
                        ->where(Order::fields_ORDER_STATUS, Order::STATUS_REFUNDED, '=', 'OR')
                        ->select()
                        ->fields(Order::fields_ID)
                        ->fetchArray();

                    $deletedItems = 0;
                    foreach ($orderIds as $order) {
                        $deletedItems += $this->orderItemModel
                            ->where(OrderItem::fields_ORDER_ID, $order['order_id'])
                            ->delete()
                            ->fetch();
                    }

                    // 删除订单
                    $deletedOrders = $this->orderModel
                        ->where(Order::fields_SHOPIFY_CREATED_AT, $cutoffDate, '<')
                        ->where(Order::fields_ORDER_STATUS, Order::STATUS_CANCELLED, '=', 'OR')
                        ->where(Order::fields_ORDER_STATUS, Order::STATUS_REFUNDED, '=', 'OR')
                        ->delete()
                        ->fetch();

                    $this->orderModel->commit();
                    
                    echo "   ✅ 已删除 {$deletedOrders} 个订单和 {$deletedItems} 个订单项目\n";
                    
                } catch (\Exception $e) {
                    $this->orderModel->rollBack();
                    throw $e;
                }
            }
        } else {
            echo "   ✅ 没有需要清理的订单\n";
        }
        
        echo "\n";
    }

    /**
     * 清理过期订单项目
     */
    private function cleanupOrderItems(int $days, bool $dryRun): void
    {
        echo "📦 清理过期订单项目...\n";
        
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // 统计要删除的订单项目
        $itemsToDelete = $this->orderItemModel
            ->where(OrderItem::fields_CREATED_AT, $cutoffDate, '<')
            ->total();

        echo "   找到 {$itemsToDelete} 个过期订单项目\n";

        if ($itemsToDelete > 0) {
            if ($dryRun) {
                echo "   🔍 预览: 将删除 {$itemsToDelete} 个订单项目\n";
            } else {
                $deletedItems = $this->orderItemModel
                    ->where(OrderItem::fields_CREATED_AT, $cutoffDate, '<')
                    ->delete()
                    ->fetch();
                
                echo "   ✅ 已删除 {$deletedItems} 个订单项目\n";
            }
        } else {
            echo "   ✅ 没有需要清理的订单项目\n";
        }
        
        echo "\n";
    }

    /**
     * 清理孤立数据
     */
    private function cleanupOrphanedData(bool $dryRun): void
    {
        echo "🔗 清理孤立数据...\n";
        
        // 清理没有对应订单的订单项目
        $orphanedItems = $this->orderItemModel
            ->select()
            ->fields('COUNT(*) as count')
            ->where(OrderItem::fields_ORDER_ID, 'NOT IN', 
                $this->orderModel->select()->fields(Order::fields_ID))
            ->fetch()['count'] ?? 0;

        echo "   找到 {$orphanedItems} 个孤立的订单项目\n";

        if ($orphanedItems > 0) {
            if ($dryRun) {
                echo "   🔍 预览: 将删除 {$orphanedItems} 个孤立的订单项目\n";
            } else {
                $deletedItems = $this->orderItemModel
                    ->where(OrderItem::fields_ORDER_ID, 'NOT IN', 
                        $this->orderModel->select()->fields(Order::fields_ID))
                    ->delete()
                    ->fetch();
                
                echo "   ✅ 已删除 {$deletedItems} 个孤立的订单项目\n";
            }
        } else {
            echo "   ✅ 没有孤立的订单项目\n";
        }
        
        echo "\n";
    }

    /**
     * 优化数据表
     */
    private function optimizeTables(bool $dryRun): void
    {
        echo "⚡ 优化数据表...\n";
        
        if ($dryRun) {
            echo "   🔍 预览: 将优化以下数据表\n";
            echo "     - shopify_orders\n";
            echo "     - shopify_order_items\n";
            echo "     - shopify_shops\n";
        } else {
            try {
                // 优化订单表
                $this->orderModel->getConnection()->query("OPTIMIZE TABLE shopify_orders");
                echo "   ✅ 已优化 shopify_orders 表\n";
                
                // 优化订单项目表
                $this->orderItemModel->getConnection()->query("OPTIMIZE TABLE shopify_order_items");
                echo "   ✅ 已优化 shopify_order_items 表\n";
                
                // 优化店铺表
                $this->shopModel->getConnection()->query("OPTIMIZE TABLE shopify_shops");
                echo "   ✅ 已优化 shopify_shops 表\n";
                
            } catch (\Exception $e) {
                echo "   ⚠️  表优化失败: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n";
    }

    /**
     * 显示数据统计
     */
    private function showDataStats(): void
    {
        echo "📊 数据统计:\n";
        echo "==================\n";
        
        $totalOrders = $this->orderModel->total();
        $totalItems = $this->orderItemModel->total();
        $totalShops = $this->shopModel->total();
        
        echo "总订单数: {$totalOrders}\n";
        echo "总订单项目数: {$totalItems}\n";
        echo "总店铺数: {$totalShops}\n";
        
        // 按状态统计订单
        $statusStats = $this->orderModel
            ->select()
            ->fields([
                Order::fields_ORDER_STATUS,
                'COUNT(*) as count'
            ])
            ->group(Order::fields_ORDER_STATUS)
            ->fetchArray();
        
        echo "\n订单状态分布:\n";
        foreach ($statusStats as $stat) {
            echo "  {$stat['order_status']}: {$stat['count']}\n";
        }
        
        echo "\n";
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
Shopify数据清理命令

用法:
  php bin/w shopify:cleanup                    - 清理超过90天的过期数据
  php bin/w shopify:cleanup --dry-run          - 预览模式，不实际删除数据
  php bin/w shopify:cleanup --days=30          - 清理超过30天的数据
  php bin/w shopify:cleanup --dry-run --days=60 - 预览清理超过60天的数据

参数:
  --dry-run, --dry_run  预览模式，只显示将要删除的数据，不实际删除
  --days                指定清理多少天前的数据，默认90天

清理内容:
  1. 过期订单 (超过指定天数的已取消/已退款订单)
  2. 过期订单项目 (超过指定天数的订单项目)
  3. 孤立数据 (没有对应订单的订单项目)
  4. 数据表优化 (优化表结构和索引)

示例:
  php bin/w shopify:cleanup
  php bin/w shopify:cleanup --dry-run
  php bin/w shopify:cleanup --days=30
  php bin/w shopify:cleanup --dry-run --days=180

注意事项:
  - 建议先使用 --dry-run 参数预览要删除的数据
  - 删除操作不可逆，请谨慎使用
  - 建议在系统维护时间执行此命令
  - 清理完成后会自动优化数据表
        ';
    }
}
