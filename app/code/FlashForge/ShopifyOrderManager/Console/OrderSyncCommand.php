<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Helper\OrderSync;
use FlashForge\ShopifyOrderManager\Model\Shop;

/**
 * 订单同步命令行工具
 * 用法：php bin/w shopify:sync [shop_id]
 */
class OrderSyncCommand implements CommandInterface
{
    private OrderSync $orderSync;
    private Shop $shopModel;

    public function __construct()
    {
        $this->orderSync = ObjectManager::getInstance(OrderSync::class);
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
        echo "开始同步店铺 ID: {$shopId}\n";

        try {
            // 获取店铺信息
            $shop = $this->shopModel->where(Shop::fields_ID, $shopId)->find()->fetch();
            
            if (!$shop->getId()) {
                echo "❌ 错误: 店铺 ID {$shopId} 不存在\n";
                return;
            }

            echo "店铺名称: {$shop->getData(Shop::fields_NAME)}\n";
            echo "店铺URL: {$shop->getData(Shop::fields_SHOP_URL)}\n";
            echo "正在同步...\n";

            // 执行同步
            $result = $this->orderSync->syncShopOrders($shop->getData());

            // 输出结果
            if ($result['success']) {
                echo "✅ 同步成功!\n";
                echo "总订单数: {$result['total_orders']}\n";
                echo "新增订单: {$result['new_orders']}\n";
                echo "更新订单: {$result['updated_orders']}\n";
            } else {
                echo "❌ 同步失败: {$result['error']}\n";
            }

        } catch (\Exception $e) {
            echo "❌ 同步过程中发生错误: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 同步所有店铺
     */
    private function syncAllShops(): void
    {
        echo "开始同步所有活跃店铺的订单...\n";

        try {
            $results = $this->orderSync->syncAllShops();

            echo "\n=== 同步结果汇总 ===\n";

            $totalShops = count($results);
            $successCount = 0;
            $totalNewOrders = 0;
            $totalUpdatedOrders = 0;

            foreach ($results as $result) {
                echo "\n店铺: {$result['shop_name']} (ID: {$result['shop_id']})\n";
                
                if ($result['success']) {
                    echo "✅ 状态: 成功\n";
                    echo "   总订单: {$result['total_orders']}\n";
                    echo "   新增: {$result['new_orders']}\n";
                    echo "   更新: {$result['updated_orders']}\n";
                    
                    $successCount++;
                    $totalNewOrders += $result['new_orders'];
                    $totalUpdatedOrders += $result['updated_orders'];
                } else {
                    echo "❌ 状态: 失败\n";
                    echo "   错误: {$result['error']}\n";
                }
            }

            echo "\n=== 总体统计 ===\n";
            echo "店铺总数: {$totalShops}\n";
            echo "成功同步: {$successCount}\n";
            echo "失败数量: " . ($totalShops - $successCount) . "\n";
            echo "新增订单总数: {$totalNewOrders}\n";
            echo "更新订单总数: {$totalUpdatedOrders}\n";

        } catch (\Exception $e) {
            echo "❌ 同步过程中发生错误: " . $e->getMessage() . "\n";
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
}
