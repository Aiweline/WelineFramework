<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Helper\OrderSync;
use FlashForge\ShopifyOrderManager\Helper\FeishuNotify;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 订单同步命令行工具
 * 用法：php bin/w shopify:sync [shop_id]
 */
class OrderSyncCommand implements CommandInterface
{
    private OrderSync $orderSync;
    private FeishuNotify $feishuNotify;
    private Shop $shopModel;

    public function __construct()
    {
        $this->orderSync = ObjectManager::getInstance(OrderSync::class);
        $this->feishuNotify = ObjectManager::getInstance(FeishuNotify::class);
        $this->shopModel = ObjectManager::getInstance(Shop::class);
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        $shopId = $args['shop_id'] ?? null;

        if ($shopId) {
            $this->syncSingleShop($shopId);
        } else {
            $this->syncAllShops();
        }
    }

    /**
     * 同步单个店铺
     */
    private function syncSingleShop(int $shopId): void
    {
        echo "🔄 开始同步店铺 ID: {$shopId}\n";
        echo "==================\n";

        try {
            // 获取店铺信息
            $shop = $this->shopModel->where(Shop::fields_ID, $shopId)->find()->fetch();
            
            if (!$shop->getId()) {
                echo "❌ 错误: 店铺 ID {$shopId} 不存在\n";
                return;
            }

            $shopName = $shop->getData(Shop::fields_NAME);
            $shopUrl = $shop->getData(Shop::fields_SHOP_URL);
            $lastSync = $shop->getData(Shop::fields_LAST_SYNC) ?: '从未同步';

            echo "🏪 店铺名称: {$shopName}\n";
            echo "🌐 店铺URL: {$shopUrl}\n";
            echo "⏰ 最后同步: {$lastSync}\n";
            echo "🔄 正在同步...\n\n";

            $startTime = microtime(true);

            // 执行同步
            try {
                $result = $this->orderSync->syncShopOrders($shop->getData());
            } catch (\Error $e) {
                // 捕获PHP Fatal Error
                echo "❌ PHP Fatal Error: " . $e->getMessage() . "\n";
                echo "📝 错误详情: " . $e->getFile() . ":" . $e->getLine() . "\n";
                
                // 发送飞书错误通知
                $this->feishuNotify->sendErrorNotify(
                    'PHP Fatal Error',
                    "订单同步过程中发生PHP致命错误",
                    [
                        '店铺ID' => $shopId,
                        '店铺名称' => $shopName,
                        '错误信息' => $e->getMessage(),
                        '错误文件' => $e->getFile(),
                        '错误行号' => $e->getLine(),
                        '错误类型' => 'Fatal Error'
                    ]
                );
                
                return;
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // 输出结果
            if ($result['success']) {
                echo "✅ 同步成功! (耗时: {$duration}秒)\n";
                echo "==================\n";
                echo "📊 同步统计:\n";
                echo "   总订单数: {$result['total_orders']}\n";
                echo "   新增订单: {$result['new_orders']}\n";
                echo "   更新订单: {$result['updated_orders']}\n";
                
                if ($result['total_orders'] > 0) {
                    $rate = round($result['total_orders'] / $duration, 2);
                    echo "   处理速度: {$rate} 订单/秒\n";
                }
            } else {
                echo "❌ 同步失败: {$result['error']}\n";
                echo "⏱️  耗时: {$duration}秒\n";
                
                // 发送飞书错误通知
                $this->feishuNotify->sendErrorNotify(
                    'Shopify订单同步失败',
                    "店铺 {$shopName} 订单同步失败",
                    [
                        '店铺ID' => $shopId,
                        '店铺名称' => $shopName,
                        '店铺URL' => $shopUrl,
                        '错误信息' => $result['error'],
                        '耗时' => $duration . '秒'
                    ]
                );
            }

        } catch (\Exception $e) {
            echo "❌ 同步过程中发生错误: " . $e->getMessage() . "\n";
            echo "📝 错误详情: " . $e->getFile() . ":" . $e->getLine() . "\n";
            
            // 发送飞书错误通知
            $this->feishuNotify->sendErrorNotify(
                'Shopify订单同步异常',
                "店铺同步过程中发生未捕获的异常",
                [
                    '店铺ID' => $shopId,
                    '店铺名称' => $shopName ?? '未知',
                    '错误信息' => $e->getMessage(),
                    '错误文件' => $e->getFile(),
                    '错误行号' => $e->getLine(),
                    '错误堆栈' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * 同步所有店铺
     */
    private function syncAllShops(): void
    {
        echo "🔄 开始同步所有活跃店铺的订单...\n";
        echo "==================\n\n";

        $startTime = microtime(true);

        try {
            $results = $this->orderSync->syncAllShops();

            $endTime = microtime(true);
            $totalDuration = round($endTime - $startTime, 2);

            echo "\n📊 同步结果汇总\n";
            echo "==================\n";

            $totalShops = count($results);
            $successCount = 0;
            $totalNewOrders = 0;
            $totalUpdatedOrders = 0;
            $totalProcessedOrders = 0;
            $failedShops = [];

            foreach ($results as $result) {
                echo "\n🏪 店铺: {$result['shop_name']} (ID: {$result['shop_id']})\n";
                
                if ($result['success']) {
                    echo "   ✅ 状态: 成功\n";
                    echo "   📋 总订单: {$result['total_orders']}\n";
                    echo "   🆕 新增: {$result['new_orders']}\n";
                    echo "   🔄 更新: {$result['updated_orders']}\n";
                    
                    // 显示同步详情
                    if (isset($result['latest_order_id'])) {
                        echo "   🔍 最近订单ID: {$result['latest_order_id']}\n";
                    }
                    if (isset($result['sync_from_date'])) {
                        echo "   📅 同步起始日期: {$result['sync_from_date']}\n";
                    }
                    if (isset($result['message'])) {
                        echo "   💬 详情: {$result['message']}\n";
                    }
                    
                    $successCount++;
                    $totalNewOrders += $result['new_orders'];
                    $totalUpdatedOrders += $result['updated_orders'];
                    $totalProcessedOrders += $result['total_orders'];
                } else {
                    echo "   ❌ 状态: 失败\n";
                    echo "   📝 错误: {$result['error']}\n";
                    $failedShops[] = $result['shop_name'];
                }
            }

            echo "\n📈 总体统计\n";
            echo "==================\n";
            echo "店铺总数: {$totalShops}\n";
            echo "成功同步: {$successCount}\n";
            echo "失败数量: " . ($totalShops - $successCount) . "\n";
            echo "新增订单总数: {$totalNewOrders}\n";
            echo "更新订单总数: {$totalUpdatedOrders}\n";
            echo "处理订单总数: {$totalProcessedOrders}\n";
            echo "总耗时: {$totalDuration}秒\n";
            
            if ($totalProcessedOrders > 0) {
                $avgRate = round($totalProcessedOrders / $totalDuration, 2);
                echo "平均处理速度: {$avgRate} 订单/秒\n";
            }

            if (!empty($failedShops)) {
                echo "\n❌ 失败的店铺:\n";
                foreach ($failedShops as $shopName) {
                    echo "   - {$shopName}\n";
                }
            }

            // 显示成功率
            $successRate = $totalShops > 0 ? round(($successCount / $totalShops) * 100, 1) : 0;
            echo "\n🎯 同步成功率: {$successRate}%\n";

        } catch (\Exception $e) {
            echo "❌ 同步过程中发生错误: " . $e->getMessage() . "\n";
            echo "📝 错误详情: " . $e->getFile() . ":" . $e->getLine() . "\n";
            
            // 发送飞书错误通知
            $this->feishuNotify->sendErrorNotify(
                'Shopify批量同步异常',
                "批量同步所有店铺时发生未捕获的异常",
                [
                    '错误信息' => $e->getMessage(),
                    '错误文件' => $e->getFile(),
                    '错误行号' => $e->getLine(),
                    '错误堆栈' => $e->getTraceAsString()
                ]
            );
        }
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
Shopify订单同步命令

用法:
  php bin/w shopify:sync              - 同步所有活跃店铺的订单
  php bin/w shopify:sync shop_id=123  - 同步指定店铺的订单

参数:
  shop_id  可选，指定要同步的店铺ID

示例:
  php bin/w shopify:sync
  php bin/w shopify:sync shop_id=1
        ';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
