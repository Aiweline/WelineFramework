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

        // 测试3: 获取订单列表
        echo "3️⃣ 获取订单列表...\n";
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

        // 测试4: 获取产品列表
        echo "4️⃣ 获取产品列表...\n";
        try {
            $products = $this->shopifyApi->getProducts(['limit' => 5]);
            if (isset($products['products'])) {
                $productCount = count($products['products']);
                echo "   ✅ 成功获取 {$productCount} 个产品\n";
                
                if ($productCount > 0) {
                    $latestProduct = $products['products'][0];
                    echo "   ✅ 最新产品: {$latestProduct['title']} (ID: {$latestProduct['id']})\n";
                }
            } else {
                throw new \Exception("无法获取产品列表");
            }
        } catch (\Exception $e) {
            throw new \Exception("获取产品列表失败: " . $e->getMessage());
        }

        // 测试5: 获取客户列表
        echo "5️⃣ 获取客户列表...\n";
        try {
            $customers = $this->shopifyApi->getCustomers(['limit' => 5]);
            if (isset($customers['customers'])) {
                $customerCount = count($customers['customers']);
                echo "   ✅ 成功获取 {$customerCount} 个客户\n";
            } else {
                throw new \Exception("无法获取客户列表");
            }
        } catch (\Exception $e) {
            throw new \Exception("获取客户列表失败: " . $e->getMessage());
        }

        // 测试6: API限制检查
        echo "6️⃣ API限制检查...\n";
        try {
            $rateLimit = $this->shopifyApi->getRateLimit();
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
  3. 获取订单列表
  4. 获取产品列表
  5. 获取客户列表
  6. API限制检查

示例:
  php bin/w shopify:test-api
  php bin/w shopify:test-api shop_id=1

说明:
  此命令会测试Shopify API的连接状态和各项功能是否正常。
  建议在配置新店铺或遇到同步问题时运行此命令进行诊断。
        ';
    }
}
