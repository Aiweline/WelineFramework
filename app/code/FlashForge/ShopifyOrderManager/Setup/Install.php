<?php

namespace FlashForge\ShopifyOrderManager\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\DataInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Model\Shop;
use FlashForge\ShopifyOrderManager\Model\Order;
use FlashForge\ShopifyOrderManager\Model\OrderItem;
use FlashForge\ShopifyOrderManager\Model\FeishuConfig;

/**
 * Shopify订单管理模块安装脚本
 */
class Install implements InstallInterface
{
    /**
     * 执行安装
     */
    public function setup(DataInterface $setup, Context $context): string
    {
        return $this->install($context);
    }

    /**
     * 执行安装
     */
    public function install(Context $context): string
    {
        try {
            // 安装店铺表
            $shopModel = ObjectManager::getInstance(Shop::class);
            $shopModel->install($shopModel->setup(), $context);
            
            // 安装订单表
            $orderModel = ObjectManager::getInstance(Order::class);
            $orderModel->install($orderModel->setup(), $context);
            
            // 安装订单项目表
            $orderItemModel = ObjectManager::getInstance(OrderItem::class);
            $orderItemModel->install($orderItemModel->setup(), $context);
            
            // 安装飞书配置表
            $feishuConfigModel = ObjectManager::getInstance(FeishuConfig::class);
            $feishuConfigModel->install($feishuConfigModel->setup(), $context);

            $context->getOutput()->writeln('<info>Shopify订单管理模块安装完成</info>');
            $context->getOutput()->writeln('<info>已创建以下数据表:</info>');
            $context->getOutput()->writeln('<info>- shopify_shops (店铺配置表)</info>');
            $context->getOutput()->writeln('<info>- shopify_orders (订单表)</info>');
            $context->getOutput()->writeln('<info>- shopify_order_items (订单项目表)</info>');
            $context->getOutput()->writeln('<info>- shopify_feishu_config (飞书配置表)</info>');
            
            return 'Shopify订单管理模块安装完成';
            
        } catch (\Exception $e) {
            $context->getOutput()->writeln('<error>安装失败: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }
}
