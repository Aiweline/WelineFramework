<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 订单统计命令行工具
 * 用法：php bin/w shopify:stats [shop_id] [--period=7d]
 */
class OrderStatsCommand implements CommandInterface
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
        $shopId = $args['shop_id'] ?? null;
        $period = $args['period'] ?? '7d';
        
        echo "📊 Shopify订单统计报告\n";
        echo "==================\n\n";

        try {
            // 解析时间周期
            $dateRange = $this->parsePeriod($period);
            
            if ($shopId) {
                $this->showShopStats($shopId, $dateRange);
            } else {
                $this->showAllShopsStats($dateRange);
            }

        } catch (\Exception $e) {
            echo "❌ 统计失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 显示单个店铺统计
     */
    private function showShopStats(int $shopId, array $dateRange): void
    {
        // 获取店铺信息
        $shop = $this->shopModel->where(Shop::fields_ID, $shopId)->find()->fetch();
        
        if (!$shop->getId()) {
            echo "❌ 错误: 店铺 ID {$shopId} 不存在\n";
            return;
        }

        echo "🏪 店铺: {$shop->getData(Shop::fields_NAME)}\n";
        echo "🌐 URL: {$shop->getData(Shop::fields_SHOP_URL)}\n";
        echo "📅 统计周期: {$dateRange['start']} 至 {$dateRange['end']}\n";
        echo "==================\n\n";

        // 订单统计
        $orderStats = $this->getOrderStats($shopId, $dateRange);
        $this->displayOrderStats($orderStats);

        // 订单项目统计
        $itemStats = $this->getItemStats($shopId, $dateRange);
        $this->displayItemStats($itemStats);

        // 销售趋势
        $this->showSalesTrend($shopId, $dateRange);
    }

    /**
     * 显示所有店铺统计
     */
    private function showAllShopsStats(array $dateRange): void
    {
        echo "📅 统计周期: {$dateRange['start']} 至 {$dateRange['end']}\n";
        echo "==================\n\n";

        $shops = $this->shopModel->getActiveShops();
        
        if (empty($shops)) {
            echo "❌ 没有找到活跃的店铺\n";
            return;
        }

        $totalStats = [
            'total_orders' => 0,
            'total_revenue' => 0,
            'total_items' => 0,
            'total_tax' => 0,
            'total_discounts' => 0
        ];

        foreach ($shops as $shop) {
            echo "🏪 {$shop['shop_name']} (ID: {$shop['shop_id']})\n";
            echo "🌐 {$shop['shop_url']}\n";
            
            $orderStats = $this->getOrderStats($shop['shop_id'], $dateRange);
            $itemStats = $this->getItemStats($shop['shop_id'], $dateRange);
            
            echo "   订单数: {$orderStats['total_orders']}\n";
            echo "   销售额: $" . number_format($orderStats['total_revenue'], 2) . "\n";
            echo "   商品数: {$itemStats['total_items']}\n";
            echo "   税费: $" . number_format($orderStats['total_tax'], 2) . "\n";
            echo "   折扣: $" . number_format($orderStats['total_discounts'], 2) . "\n";
            echo "   --------------------\n";
            
            // 累计统计
            $totalStats['total_orders'] += $orderStats['total_orders'];
            $totalStats['total_revenue'] += $orderStats['total_revenue'];
            $totalStats['total_items'] += $itemStats['total_items'];
            $totalStats['total_tax'] += $orderStats['total_tax'];
            $totalStats['total_discounts'] += $orderStats['total_discounts'];
        }

        echo "\n📈 总体统计:\n";
        echo "==================\n";
        echo "总订单数: {$totalStats['total_orders']}\n";
        echo "总销售额: $" . number_format($totalStats['total_revenue'], 2) . "\n";
        echo "总商品数: {$totalStats['total_items']}\n";
        echo "总税费: $" . number_format($totalStats['total_tax'], 2) . "\n";
        echo "总折扣: $" . number_format($totalStats['total_discounts'], 2) . "\n";
    }

    /**
     * 获取订单统计
     */
    private function getOrderStats(int $shopId, array $dateRange): array
    {
        $query = $this->orderModel
            ->where(Order::fields_SHOP_ID, $shopId)
            ->where(Order::fields_SHOPIFY_CREATED_AT, $dateRange['start'], '>=')
            ->where(Order::fields_SHOPIFY_CREATED_AT, $dateRange['end'] . ' 23:59:59', '<=');

        // 总订单数
        $totalOrders = $query->total();

        // 总销售额
        $totalRevenue = $query->select()
            ->fields('SUM(' . Order::fields_TOTAL_PRICE . ') as total_revenue')
            ->fetch()['total_revenue'] ?? 0;

        // 总税费
        $totalTax = $query->select()
            ->fields('SUM(' . Order::fields_TOTAL_TAX . ') as total_tax')
            ->fetch()['total_tax'] ?? 0;

        // 总折扣
        $totalDiscounts = $query->select()
            ->fields('SUM(' . Order::fields_TOTAL_DISCOUNTS . ') as total_discounts')
            ->fetch()['total_discounts'] ?? 0;

        // 按状态统计
        $statusStats = $query->select()
            ->fields([
                Order::fields_ORDER_STATUS,
                'COUNT(*) as count'
            ])
            ->group(Order::fields_ORDER_STATUS)
            ->fetchArray();

        return [
            'total_orders' => $totalOrders,
            'total_revenue' => floatval($totalRevenue),
            'total_tax' => floatval($totalTax),
            'total_discounts' => floatval($totalDiscounts),
            'status_stats' => $statusStats
        ];
    }

    /**
     * 获取订单项目统计
     */
    private function getItemStats(int $shopId, array $dateRange): array
    {
        $query = $this->orderItemModel
            ->where(OrderItem::fields_SHOP_ID, $shopId)
            ->where(OrderItem::fields_CREATED_AT, $dateRange['start'], '>=')
            ->where(OrderItem::fields_CREATED_AT, $dateRange['end'] . ' 23:59:59', '<=');

        // 总商品数
        $totalItems = $query->total();

        // 总数量
        $totalQuantity = $query->select()
            ->fields('SUM(' . OrderItem::fields_QUANTITY . ') as total_quantity')
            ->fetch()['total_quantity'] ?? 0;

        // 热销商品
        $topProducts = $query->select()
            ->fields([
                OrderItem::fields_PRODUCT_TITLE,
                OrderItem::fields_SKU,
                'SUM(' . OrderItem::fields_QUANTITY . ') as total_quantity',
                'SUM(' . OrderItem::fields_PRICE . ' * ' . OrderItem::fields_QUANTITY . ') as total_revenue'
            ])
            ->group(OrderItem::fields_PRODUCT_TITLE)
            ->order('total_quantity', 'DESC')
            ->limit(10)
            ->fetchArray();

        return [
            'total_items' => $totalItems,
            'total_quantity' => intval($totalQuantity),
            'top_products' => $topProducts
        ];
    }

    /**
     * 显示订单统计
     */
    private function displayOrderStats(array $stats): void
    {
        echo "📋 订单统计:\n";
        echo "   总订单数: {$stats['total_orders']}\n";
        echo "   总销售额: $" . number_format($stats['total_revenue'], 2) . "\n";
        echo "   总税费: $" . number_format($stats['total_tax'], 2) . "\n";
        echo "   总折扣: $" . number_format($stats['total_discounts'], 2) . "\n";
        
        if (!empty($stats['status_stats'])) {
            echo "   状态分布:\n";
            foreach ($stats['status_stats'] as $status) {
                echo "     {$status['order_status']}: {$status['count']}\n";
            }
        }
        echo "\n";
    }

    /**
     * 显示订单项目统计
     */
    private function displayItemStats(array $stats): void
    {
        echo "📦 商品统计:\n";
        echo "   总商品数: {$stats['total_items']}\n";
        echo "   总数量: {$stats['total_quantity']}\n";
        
        if (!empty($stats['top_products'])) {
            echo "   热销商品 (Top 5):\n";
            $count = 0;
            foreach ($stats['top_products'] as $product) {
                if ($count >= 5) break;
                echo "     {$product['product_title']} (SKU: {$product['sku']})\n";
                echo "       数量: {$product['total_quantity']}, 销售额: $" . number_format($product['total_revenue'], 2) . "\n";
                $count++;
            }
        }
        echo "\n";
    }

    /**
     * 显示销售趋势
     */
    private function showSalesTrend(int $shopId, array $dateRange): void
    {
        echo "📈 销售趋势 (按天):\n";
        
        $dailyStats = $this->orderModel
            ->where(Order::fields_SHOP_ID, $shopId)
            ->where(Order::fields_SHOPIFY_CREATED_AT, $dateRange['start'], '>=')
            ->where(Order::fields_SHOPIFY_CREATED_AT, $dateRange['end'] . ' 23:59:59', '<=')
            ->select()
            ->fields([
                'DATE(' . Order::fields_SHOPIFY_CREATED_AT . ') as date',
                'COUNT(*) as orders',
                'SUM(' . Order::fields_TOTAL_PRICE . ') as revenue'
            ])
            ->group('DATE(' . Order::fields_SHOPIFY_CREATED_AT . ')')
            ->order('date', 'ASC')
            ->fetchArray();

        foreach ($dailyStats as $day) {
            echo "   {$day['date']}: {$day['orders']} 订单, $" . number_format($day['revenue'], 2) . "\n";
        }
        echo "\n";
    }

    /**
     * 解析时间周期
     */
    private function parsePeriod(string $period): array
    {
        $end = date('Y-m-d');
        
        switch ($period) {
            case '1d':
                $start = date('Y-m-d', strtotime('-1 day'));
                break;
            case '7d':
                $start = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30d':
                $start = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90d':
                $start = date('Y-m-d', strtotime('-90 days'));
                break;
            default:
                if (preg_match('/^(\d+)d$/', $period, $matches)) {
                    $days = intval($matches[1]);
                    $start = date('Y-m-d', strtotime("-{$days} days"));
                } else {
                    $start = date('Y-m-d', strtotime('-7 days'));
                }
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
Shopify订单统计命令

用法:
  php bin/w shopify:stats                    - 显示所有店铺的统计信息
  php bin/w shopify:stats shop_id=123        - 显示指定店铺的统计信息
  php bin/w shopify:stats --period=30d       - 显示最近30天的统计信息
  php bin/w shopify:stats shop_id=123 --period=7d  - 显示指定店铺最近7天的统计信息

参数:
  shop_id  可选，指定要统计的店铺ID
  period   可选，统计周期 (1d, 7d, 30d, 90d 或自定义天数，如 15d)

示例:
  php bin/w shopify:stats
  php bin/w shopify:stats shop_id=1
  php bin/w shopify:stats --period=30d
  php bin/w shopify:stats shop_id=1 --period=7d
        ';
    }
}
