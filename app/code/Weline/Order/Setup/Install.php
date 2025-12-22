<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Order\Model\OrderPayment;
use Weline\Order\Model\OrderShipment;
use Weline\Order\Model\OrderRefund;
use Weline\Order\Model\OrderInvoice;
use Weline\Order\Model\OrderHistory;
use Weline\Order\Model\OrderStatus;
use Weline\Order\Model\OrderStatusTranslation;
use Weline\Order\Service\OrderStatusService;

/**
 * 订单管理模块安装脚本
 */
class Install implements InstallInterface
{
    /**
     * 执行安装
     */
    public function setup(Setup $setup, Context $context): void
    {
        try {
            // 安装订单主表
            $orderModel = ObjectManager::getInstance(Order::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderModel);
            $orderModel->install($modelSetup, $context);
            
            // 安装订单项表
            $orderItemModel = ObjectManager::getInstance(OrderItem::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderItemModel);
            $orderItemModel->install($modelSetup, $context);
            
            // 安装支付记录表
            $orderPaymentModel = ObjectManager::getInstance(OrderPayment::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderPaymentModel);
            $orderPaymentModel->install($modelSetup, $context);
            
            // 安装发货记录表
            $orderShipmentModel = ObjectManager::getInstance(OrderShipment::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderShipmentModel);
            $orderShipmentModel->install($modelSetup, $context);
            
            // 安装退款记录表
            $orderRefundModel = ObjectManager::getInstance(OrderRefund::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderRefundModel);
            $orderRefundModel->install($modelSetup, $context);
            
            // 安装发票表
            $orderInvoiceModel = ObjectManager::getInstance(OrderInvoice::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderInvoiceModel);
            $orderInvoiceModel->install($modelSetup, $context);
            
            // 安装订单历史表
            $orderHistoryModel = ObjectManager::getInstance(OrderHistory::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderHistoryModel);
            $orderHistoryModel->install($modelSetup, $context);
            
            // 安装订单状态表
            $orderStatusModel = ObjectManager::getInstance(OrderStatus::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderStatusModel);
            $orderStatusModel->install($modelSetup, $context);
            
            // 安装订单状态翻译表
            $orderStatusTranslationModel = ObjectManager::getInstance(OrderStatusTranslation::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($orderStatusTranslationModel);
            $orderStatusTranslationModel->install($modelSetup, $context);
            
            // 初始化默认订单状态
            $statusService = ObjectManager::getInstance(OrderStatusService::class);
            $statusService->initDefaultStatuses();

            $context->getOutput()->writeln('<info>订单管理模块安装完成</info>');
            $context->getOutput()->writeln('<info>已创建以下数据表:</info>');
            $context->getOutput()->writeln('<info>- weline_order (订单主表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_item (订单项表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_payment (支付记录表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_shipment (发货记录表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_refund (退款记录表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_invoice (发票表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_history (订单历史表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_status (订单状态表)</info>');
            $context->getOutput()->writeln('<info>- weline_order_status_translation (订单状态翻译表)</info>');
            
        } catch (\Exception $e) {
            $context->getOutput()->writeln('<error>安装失败: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }
}

