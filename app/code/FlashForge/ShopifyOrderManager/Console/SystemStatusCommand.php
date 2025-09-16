<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 系统状态命令行工具
 * 用法：php bin/w shopify:status
 */
class SystemStatusCommand implements CommandInterface
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
        echo "📊 Shopify订单管理系统状态\n";
        echo "==================\n\n";

        try {
            $this->showSystemInfo();
            $this->showShopStatus();
            $this->showDataStats();
            $this->showSyncStatus();
            $this->showRecentActivity();
            $this->showSystemHealth();

        } catch (\Exception $e) {
            echo "❌ 获取状态失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 显示系统信息
     */
    private function showSystemInfo(): void
    {
        echo "🖥️  系统信息:\n";
        echo "==================\n";
        echo "系统时间: " . date('Y-m-d H:i:s') . "\n";
        echo "PHP版本: " . PHP_VERSION . "\n";
        echo "内存使用: " . $this->formatBytes(memory_get_usage(true)) . "\n";
        echo "内存峰值: " . $this->formatBytes(memory_get_peak_usage(true)) . "\n";
        echo "磁盘空间: " . $this->getDiskSpace() . "\n";
        echo "\n";
    }

    /**
     * 显示店铺状态
     */
    private function showShopStatus(): void
    {
        echo "🏪 店铺状态:\n";
        echo "==================\n";
        
        $shops = $this->shopModel->select()->fetchArray();
        $activeShops = $this->shopModel->getActiveShops();
        
        echo "总店铺数: " . count($shops) . "\n";
        echo "活跃店铺数: " . count($activeShops) . "\n";
        echo "禁用店铺数: " . (count($shops) - count($activeShops)) . "\n";
        
        if (!empty($shops)) {
            echo "\n店铺详情:\n";
            foreach ($shops as $shop) {
                $status = $shop['status'] == 1 ? '✅ 活跃' : '❌ 禁用';
                $lastSync = $shop['last_sync_time'] ?: '从未同步';
                echo "  {$shop['shop_name']} ({$shop['shop_url']})\n";
                echo "    状态: {$status}\n";
                echo "    最后同步: {$lastSync}\n";
                echo "    创建时间: {$shop['created_at']}\n";
                echo "\n";
            }
        }
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
        
        echo "总订单数: {$totalOrders}\n";
        echo "总订单项目数: {$totalItems}\n";
        
        // 今日统计
        $today = date('Y-m-d');
        $todayOrders = $this->orderModel
            ->where(Order::fields_SHOPIFY_CREATED_AT, $today, '>=')
            ->where(Order::fields_SHOPIFY_CREATED_AT, $today . ' 23:59:59', '<=')
            ->total();
        
        $todayItems = $this->orderItemModel
            ->where(OrderItem::fields_CREATED_AT, $today, '>=')
            ->where(OrderItem::fields_CREATED_AT, $today . ' 23:59:59', '<=')
            ->total();
        
        echo "今日新增订单: {$todayOrders}\n";
        echo "今日新增项目: {$todayItems}\n";
        
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
            $percentage = $totalOrders > 0 ? round(($stat['count'] / $totalOrders) * 100, 1) : 0;
            echo "  {$stat['order_status']}: {$stat['count']} ({$percentage}%)\n";
        }
        
        echo "\n";
    }

    /**
     * 显示同步状态
     */
    private function showSyncStatus(): void
    {
        echo "🔄 同步状态:\n";
        echo "==================\n";
        
        $shops = $this->shopModel->getActiveShops();
        
        if (empty($shops)) {
            echo "❌ 没有活跃的店铺\n\n";
            return;
        }
        
        foreach ($shops as $shop) {
            $lastSync = $shop['last_sync_time'];
            $shopName = $shop['shop_name'];
            
            if ($lastSync) {
                $lastSyncTime = strtotime($lastSync);
                $timeDiff = time() - $lastSyncTime;
                
                if ($timeDiff < 3600) { // 1小时内
                    $status = "✅ 正常";
                    $statusText = "最近同步: " . $this->formatTimeAgo($timeDiff);
                } elseif ($timeDiff < 86400) { // 24小时内
                    $status = "⚠️  延迟";
                    $statusText = "最近同步: " . $this->formatTimeAgo($timeDiff);
                } else {
                    $status = "❌ 异常";
                    $statusText = "最近同步: " . $this->formatTimeAgo($timeDiff);
                }
            } else {
                $status = "❌ 未同步";
                $statusText = "从未同步";
            }
            
            echo "  {$shopName}: {$status}\n";
            echo "    {$statusText}\n";
        }
        
        echo "\n";
    }

    /**
     * 显示最近活动
     */
    private function showRecentActivity(): void
    {
        echo "📈 最近活动:\n";
        echo "==================\n";
        
        // 最近7天的订单趋势
        $recentOrders = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $count = $this->orderModel
                ->where(Order::fields_SHOPIFY_CREATED_AT, $date, '>=')
                ->where(Order::fields_SHOPIFY_CREATED_AT, $date . ' 23:59:59', '<=')
                ->total();
            
            $recentOrders[$date] = $count;
        }
        
        echo "最近7天订单趋势:\n";
        foreach ($recentOrders as $date => $count) {
            $dayName = date('D', strtotime($date));
            echo "  {$dayName} {$date}: {$count} 订单\n";
        }
        
        // 最新订单
        $latestOrders = $this->orderModel
            ->select()
            ->order(Order::fields_SHOPIFY_CREATED_AT, 'DESC')
            ->limit(5)
            ->fetchArray();
        
        if (!empty($latestOrders)) {
            echo "\n最新订单:\n";
            foreach ($latestOrders as $order) {
                $shop = $this->shopModel->where(Shop::fields_ID, $order['shop_id'])->find()->fetch();
                $shopName = $shop->getId() ? $shop->getData(Shop::fields_NAME) : '未知店铺';
                echo "  #{$order['order_number']} - {$shopName} - {$order['order_status']} - {$order['shopify_created_at']}\n";
            }
        }
        
        echo "\n";
    }

    /**
     * 显示系统健康状态
     */
    private function showSystemHealth(): void
    {
        echo "🏥 系统健康状态:\n";
        echo "==================\n";
        
        $healthScore = 100;
        $issues = [];
        
        // 检查店铺状态
        $shops = $this->shopModel->select()->fetchArray();
        $activeShops = $this->shopModel->getActiveShops();
        
        if (empty($activeShops)) {
            $healthScore -= 30;
            $issues[] = "没有活跃的店铺";
        }
        
        // 检查同步状态
        foreach ($activeShops as $shop) {
            if (!$shop['last_sync_time']) {
                $healthScore -= 10;
                $issues[] = "店铺 {$shop['shop_name']} 从未同步";
            } else {
                $lastSyncTime = strtotime($shop['last_sync_time']);
                $timeDiff = time() - $lastSyncTime;
                
                if ($timeDiff > 86400) { // 超过24小时
                    $healthScore -= 15;
                    $issues[] = "店铺 {$shop['shop_name']} 同步延迟";
                }
            }
        }
        
        // 检查数据完整性
        $orphanedItems = $this->orderItemModel
            ->select()
            ->fields('COUNT(*) as count')
            ->where(OrderItem::fields_ORDER_ID, 'NOT IN', 
                $this->orderModel->select()->fields(Order::fields_ID))
            ->fetch()['count'] ?? 0;
        
        if ($orphanedItems > 0) {
            $healthScore -= 20;
            $issues[] = "发现 {$orphanedItems} 个孤立数据";
        }
        
        // 检查内存使用
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        if ($memoryUsage > $memoryLimit * 0.8) {
            $healthScore -= 10;
            $issues[] = "内存使用率过高";
        }
        
        // 显示健康分数
        if ($healthScore >= 90) {
            $status = "✅ 优秀";
            $color = "green";
        } elseif ($healthScore >= 70) {
            $status = "⚠️  良好";
            $color = "yellow";
        } elseif ($healthScore >= 50) {
            $status = "⚠️  一般";
            $color = "orange";
        } else {
            $status = "❌ 需要关注";
            $color = "red";
        }
        
        echo "健康分数: {$healthScore}/100 {$status}\n";
        
        if (!empty($issues)) {
            echo "\n发现的问题:\n";
            foreach ($issues as $issue) {
                echo "  ❌ {$issue}\n";
            }
        } else {
            echo "\n✅ 系统运行正常，没有发现问题\n";
        }
        
        echo "\n";
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 获取磁盘空间
     */
    private function getDiskSpace(): string
    {
        $bytes = disk_free_space('.');
        $total = disk_total_space('.');
        
        if ($bytes === false || $total === false) {
            return '未知';
        }
        
        $used = $total - $bytes;
        $percent = round(($used / $total) * 100, 1);
        
        return $this->formatBytes($used) . ' / ' . $this->formatBytes($total) . " ({$percent}% 已使用)";
    }

    /**
     * 格式化时间差
     */
    private function formatTimeAgo(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' 秒前';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . ' 分钟前';
        } elseif ($seconds < 86400) {
            return floor($seconds / 3600) . ' 小时前';
        } else {
            return floor($seconds / 86400) . ' 天前';
        }
    }

    /**
     * 解析内存限制
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $limit = (int) $limit;
        
        switch ($last) {
            case 'g':
                $limit *= 1024;
            case 'm':
                $limit *= 1024;
            case 'k':
                $limit *= 1024;
        }
        
        return $limit;
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
Shopify订单管理系统状态命令

用法:
  php bin/w shopify:status

显示内容:
  1. 系统信息 (时间、PHP版本、内存使用、磁盘空间)
  2. 店铺状态 (总店铺数、活跃店铺、同步状态)
  3. 数据统计 (订单数、项目数、状态分布)
  4. 同步状态 (各店铺最后同步时间)
  5. 最近活动 (7天趋势、最新订单)
  6. 系统健康 (健康分数、问题诊断)

示例:
  php bin/w shopify:status

说明:
  此命令提供系统运行状态的全面概览，帮助监控系统健康状态
  和及时发现潜在问题。建议定期运行此命令进行系统检查。
        ';
    }
}
