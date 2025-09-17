<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Shop;
use FlashForge\ShopifyOrderManager\Helper\ShopifyApi;

/**
 * API测试命令行工具
 * 用法：php bin/w shopify:test-api [shop_id]
 */
class ApiTestCommand implements CommandInterface
{
    private Shop $shopModel;
    private ShopifyApi $shopifyApi;

    public function __construct()
    {
        $this->shopModel = ObjectManager::getInstance(Shop::class);
        $this->shopifyApi = ObjectManager::getInstance(ShopifyApi::class);
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        $shopId = $args['shop_id'] ?? null;
        
        echo "🔧 Shopify API连接测试\n";
        echo "==================\n\n";

        try {
            if ($shopId) {
                $this->testSingleShop($shopId);
            } else {
                $this->testAllShops();
            }

        } catch (\Exception $e) {
            echo "❌ 测试失败: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 测试单个店铺
     */
    private function testSingleShop(int $shopId): void
    {
        // 获取店铺信息
        $shop = $this->shopModel->where(Shop::fields_ID, $shopId)->find()->fetch();
        
        if (!$shop->getId()) {
            echo "❌ 错误: 店铺 ID {$shopId} 不存在\n";
            return;
        }

        echo "🏪 测试店铺: {$shop->getData(Shop::fields_NAME)}\n";
        echo "🌐 URL: {$shop->getData(Shop::fields_SHOP_URL)}\n";
        echo "==================\n\n";

        $this->performApiTests($shop->getData());
    }

    /**
     * 测试所有店铺
     */
    private function testAllShops(): void
    {
        $shops = $this->shopModel->getActiveShops();
        
        if (empty($shops)) {
            echo "❌ 没有找到活跃的店铺\n";
            return;
        }

        $totalShops = count($shops);
        $successCount = 0;
        $failedShops = [];

        foreach ($shops as $shop) {
            echo "🏪 测试店铺: {$shop['shop_name']} (ID: {$shop['shop_id']})\n";
            echo "🌐 URL: {$shop['shop_url']}\n";
            
            try {
                $this->performApiTests($shop);
                echo "✅ 测试通过\n";
                $successCount++;
            } catch (\Exception $e) {
                echo "❌ 测试失败: " . $e->getMessage() . "\n";
                $failedShops[] = [
                    'name' => $shop['shop_name'],
                    'error' => $e->getMessage()
                ];
            }
            
            echo "==================\n\n";
        }

        // 显示测试结果汇总
        echo "📊 测试结果汇总:\n";
        echo "==================\n";
        echo "总店铺数: {$totalShops}\n";
        echo "测试通过: {$successCount}\n";
        echo "测试失败: " . ($totalShops - $successCount) . "\n";
        
        if (!empty($failedShops)) {
            echo "\n❌ 失败的店铺:\n";
            foreach ($failedShops as $failed) {
                echo "   {$failed['name']}: {$failed['error']}\n";
            }
        }
    }

    /**
     * 执行API测试
     */
    private function performApiTests(array $shop): void
    {
        // 初始化API
        $this->shopifyApi->init($shop['shop_url'], $shop['access_token']);
        
        // 测试1: 基础连接测试
        echo "1️⃣ 基础连接测试...\n";
        $connectionTest = $this->shopifyApi->testConnection();
        if ($connectionTest) {
            echo "   ✅ API连接正常\n";
        } else {
            throw new \Exception("API连接失败");
        }

        // 测试2: 获取店铺信息
        echo "2️⃣ 获取店铺信息...\n";
        try {
            $shopInfo = $this->shopifyApi->getShopInfo();
            if ($shopInfo) {
                echo "   ✅ 店铺名称: {$shopInfo['name']}\n";
                echo "   ✅ 店铺邮箱: {$shopInfo['email']}\n";
                echo "   ✅ 货币: {$shopInfo['currency']}\n";
                echo "   ✅ 时区: {$shopInfo['timezone']}\n";
            } else {
                throw new \Exception("无法获取店铺信息");
            }
        } catch (\Exception $e) {
            throw new \Exception("获取店铺信息失败: " . $e->getMessage());
        }

        // 测试3: 获取订单列表（单页）
        echo "3️⃣ 获取订单列表（单页）...\n";
        try {
            $orders = $this->shopifyApi->getOrders(['limit' => 5]);
            if (isset($orders['orders'])) {
                $orderCount = count($orders['orders']);
                echo "   ✅ 成功获取 {$orderCount} 个订单\n";
                
                if ($orderCount > 0) {
                    $latestOrder = $orders['orders'][0];
                    echo "   ✅ 最新订单: #{$latestOrder['order_number']} (ID: {$latestOrder['id']})\n";
                    echo "   ✅ 订单状态: {$latestOrder['financial_status']}\n";
                }
            } else {
                throw new \Exception("无法获取订单列表");
            }
        } catch (\Exception $e) {
            throw new \Exception("获取订单列表失败: " . $e->getMessage());
        }

        // 测试3.5: 分页获取订单测试
        echo "3️⃣.5 分页获取订单测试...\n";
        try {
            // 获取近3天的订单，测试分页逻辑
            $threeDaysAgo = date('Y-m-d H:i:s', strtotime('-3 days'));
            $allOrders = $this->shopifyApi->getOrdersByDateRange($threeDaysAgo);
            
            if (isset($allOrders['orders'])) {
                $totalOrderCount = count($allOrders['orders']);
                echo "   ✅ 近3天总订单数: {$totalOrderCount}\n";
                
                if ($totalOrderCount > 0) {
                    $firstOrder = $allOrders['orders'][0];
                    $lastOrder = end($allOrders['orders']);
                    echo "   ✅ 最早订单: #{$firstOrder['order_number']} (ID: {$firstOrder['id']})\n";
                    echo "   ✅ 最晚订单: #{$lastOrder['order_number']} (ID: {$lastOrder['id']})\n";
                    
                    // 检查是否有重复订单
                    $orderIds = array_column($allOrders['orders'], 'id');
                    $uniqueOrderIds = array_unique($orderIds);
                    if (count($orderIds) === count($uniqueOrderIds)) {
                        echo "   ✅ 无重复订单，分页逻辑正常\n";
                    } else {
                        echo "   ⚠️  发现重复订单，分页逻辑可能有问题\n";
                    }
                }
            } else {
                echo "   ⚠️  近3天无订单数据\n";
            }
        } catch (\Exception $e) {
            echo "   ⚠️  分页测试失败: " . $e->getMessage() . "\n";
        }

        // 测试4: 获取单个订单详情
        echo "4️⃣ 获取单个订单详情...\n";
        try {
            // 先获取一个订单ID
            $orders = $this->shopifyApi->getOrders(['limit' => 1]);
            if (isset($orders['orders']) && !empty($orders['orders'])) {
                $orderId = $orders['orders'][0]['id'];
                $orderDetail = $this->shopifyApi->getOrder($orderId);
                
                if (isset($orderDetail['order'])) {
                    $order = $orderDetail['order'];
                    echo "   ✅ 成功获取订单详情: #{$order['order_number']}\n";
                    echo "   ✅ 订单总金额: {$order['total_price']} {$order['currency']}\n";
                    echo "   ✅ 订单状态: {$order['financial_status']}\n";
                } else {
                    throw new \Exception("无法获取订单详情");
                }
            } else {
                echo "   ⚠️  无订单数据，跳过订单详情测试\n";
            }
        } catch (\Exception $e) {
            echo "   ⚠️  获取订单详情失败: " . $e->getMessage() . "\n";
        }

        // 测试5: API限制检查
        echo "5️⃣ API限制检查...\n";
        try {
            $rateLimit = $this->shopifyApi->getRateLimitInfo();
            if ($rateLimit) {
                echo "   ✅ 当前调用次数: {$rateLimit['current']}\n";
                echo "   ✅ 最大调用次数: {$rateLimit['max']}\n";
                echo "   ✅ 重置时间: {$rateLimit['reset_time']}\n";
                
                $usagePercent = ($rateLimit['current'] / $rateLimit['max']) * 100;
                if ($usagePercent > 80) {
                    echo "   ⚠️  警告: API使用率超过80%\n";
                } else {
                    echo "   ✅ API使用率正常\n";
                }
            } else {
                echo "   ⚠️  无法获取API限制信息\n";
            }
        } catch (\Exception $e) {
            echo "   ⚠️  API限制检查失败: " . $e->getMessage() . "\n";
        }

        echo "\n🎉 所有测试完成！\n";
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
Shopify API连接测试命令

用法:
  php bin/w shopify:test-api              - 测试所有活跃店铺的API连接
  php bin/w shopify:test-api shop_id=123  - 测试指定店铺的API连接

参数:
  shop_id  可选，指定要测试的店铺ID

测试项目:
  1. 基础连接测试
  2. 获取店铺信息
  3. 获取订单列表（单页）
  3.5 分页获取订单测试（测试分页逻辑）
  4. 获取单个订单详情
  5. API限制检查

示例:
  php bin/w shopify:test-api
  php bin/w shopify:test-api shop_id=1

说明:
  此命令会测试Shopify API的连接状态和各项功能是否正常。
  建议在配置新店铺或遇到同步问题时运行此命令进行诊断。
        ';
    }
}
