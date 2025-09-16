<?php

namespace FlashForge\ShopifyOrderManager\Console;

use FlashForge\ShopifyOrderManager\Model\Shop;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 列出所有店铺的命令行工具
 */
class ListShopsCommand implements CommandInterface
{
    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $shopModel = ObjectManager::getInstance(Shop::class);
            $shops = $shopModel->select()->fetchArray();
            
            if (empty($shops)) {
                echo "❌ 没有找到任何店铺\n";
                echo "请先在后台管理中添加店铺配置\n";
                return;
            }
            
            echo "📋 Shopify店铺列表\n";
            echo "==================\n";
            echo "总店铺数: " . count($shops) . "\n";
            echo "==================\n\n";
            
            $activeCount = 0;
            $inactiveCount = 0;
            
            foreach ($shops as $index => $shop) {
                $status = $shop['status'] == 1 ? '✅ 启用' : '❌ 禁用';
                $lastSync = $shop['last_sync_time'] ?: '从未同步';
                
                if ($shop['status'] == 1) {
                    $activeCount++;
                } else {
                    $inactiveCount++;
                }
                
                echo "🏪 店铺 #" . ($index + 1) . "\n";
                echo "   ID: {$shop['shop_id']}\n";
                echo "   名称: {$shop['shop_name']}\n";
                echo "   URL: {$shop['shop_url']}\n";
                echo "   状态: {$status}\n";
                echo "   最后同步: {$lastSync}\n";
                echo "   创建时间: {$shop['created_at']}\n";
                
                if ($index < count($shops) - 1) {
                    echo "   --------------------\n";
                }
            }
            
            echo "\n📊 统计信息:\n";
            echo "==================\n";
            echo "总店铺数: " . count($shops) . "\n";
            echo "活跃店铺: {$activeCount}\n";
            echo "禁用店铺: {$inactiveCount}\n";
            
        } catch (\Exception $e) {
            echo "❌ 执行失败: " . $e->getMessage() . "\n";
            echo "📝 错误详情: " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '列出所有可用的Shopify店铺';
    }
}
