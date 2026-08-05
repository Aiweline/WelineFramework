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
use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
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
            // 安装 CheckoutGroup 拓扑根
            $checkoutGroupModel = ObjectManager::getInstance(CheckoutGroup::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($checkoutGroupModel);
            $checkoutGroupModel->install($modelSetup, $context);

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

            // 安装履约单元表（P2D-002 stub；仓维行为归 P3）
            $fulfillmentUnitModel = ObjectManager::getInstance(FulfillmentUnit::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($fulfillmentUnitModel);
            $fulfillmentUnitModel->install($modelSetup, $context);

            // 安装 kind-qualified 展示号注册表（P2D-003 / DEC-017）
            $displayNumberRegistry = ObjectManager::getInstance(DisplayNumberRegistry::class);
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($displayNumberRegistry);
            $displayNumberRegistry->install($modelSetup, $context);
            
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

            $context->getPrinter()->success(\__('订单管理模块安装完成'));
            $context->getPrinter()->setup(\__('已创建以下数据表: weline_checkout_group, weline_order, weline_order_item, weline_fulfillment_unit, weline_display_number_registry, weline_order_payment, weline_order_shipment, weline_order_refund, weline_order_invoice, weline_order_history, weline_order_status, weline_order_status_translation'));
            
        } catch (\Exception $e) {
            $context->getPrinter()->error(\__('安装失败: %{1}', [$e->getMessage()]));
            throw $e;
        }
    }
}
