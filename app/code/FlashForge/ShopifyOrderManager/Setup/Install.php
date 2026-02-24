<?php

namespace FlashForge\ShopifyOrderManager\Setup;

use FlashForge\ShopifyOrderManager\Model\FeishuConfig;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\Shop;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\InstallInterface;

/**
 * Shopify订单管理模块安装脚本
 */
class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        try {
            $modelSetup = ObjectManager::make(ModelSetup::class);

            $shopModel = ObjectManager::getInstance(Shop::class);
            $modelSetup->putModel($shopModel);
            $shopModel->install($modelSetup, $context);

            $orderModel = ObjectManager::getInstance(Order::class);
            $modelSetup->putModel($orderModel);
            $orderModel->install($modelSetup, $context);

            $orderItemModel = ObjectManager::getInstance(OrderItem::class);
            $modelSetup->putModel($orderItemModel);
            $orderItemModel->install($modelSetup, $context);

            $feishuConfigModel = ObjectManager::getInstance(FeishuConfig::class);
            $modelSetup->putModel($feishuConfigModel);
            $feishuConfigModel->install($modelSetup, $context);

            $context->getPrinter()->success(__('Shopify订单管理模块安装完成'));
            $context->getPrinter()->setup(__('已创建以下数据表: shopify_shops, shopify_orders, shopify_order_items, shopify_feishu_config'));
            
        } catch (\Exception $e) {
            $context->getPrinter()->error(__('安装失败: %{1}', [$e->getMessage()]));
            throw $e;
        }
    }
}
