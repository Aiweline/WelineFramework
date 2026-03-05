<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderInvoice;

/**
 * 发票服务
 * 
 * @package Weline_Order
 */
class InvoiceService
{
    private ObjectManager $objectManager;
    private OrderService $orderService;
    
    public function __construct(
        ObjectManager $objectManager,
        OrderService $orderService
    ) {
        $this->objectManager = $objectManager;
        $this->orderService = $orderService;
    }
    
    /**
     * 获取发票模型实例
     * 
     * @return OrderInvoice
     */
    private function getInvoiceModel(): OrderInvoice
    {
        return $this->objectManager->getInstance(OrderInvoice::class);
    }
    
    /**
     * 生成发票
     * 
     * @param int $orderId 订单ID
     * @return OrderInvoice
     * @throws \Exception
     */
    public function generateInvoice(int $orderId): OrderInvoice
    {
        $order = $this->orderService->getOrder($orderId);
        
        // 检查是否已生成发票
        $existingInvoice = $this->getInvoiceModel()->reset()
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderId)
            ->where(OrderInvoice::schema_fields_STATUS, OrderInvoice::STATUS_ISSUED)
            ->find()
            ->fetch();
        
        if ($existingInvoice->getId()) {
            throw new \Exception(__('订单已生成发票'));
        }
        
        // 创建发票
        $invoice = $this->getInvoiceModel()->reset();
        $invoice->setData(OrderInvoice::schema_fields_ORDER_ID, $orderId);
        $invoice->setData(OrderInvoice::schema_fields_INVOICE_NUMBER, $invoice->generateInvoiceNumber());
        $invoice->setData(OrderInvoice::schema_fields_AMOUNT, $order->getData(Order::schema_fields_GRAND_TOTAL));
        $invoice->setData(OrderInvoice::schema_fields_STATUS, OrderInvoice::STATUS_ISSUED);
        $invoice->setData(OrderInvoice::schema_fields_ISSUED_AT, date('Y-m-d H:i:s'));
        $invoice->save();
        
        return $invoice;
    }
    
    /**
     * 打印发票
     * 
     * @param int $invoiceId 发票ID
     * @return OrderInvoice
     * @throws \Exception
     */
    public function printInvoice(int $invoiceId): OrderInvoice
    {
        $invoice = $this->getInvoiceModel()->reset()->load($invoiceId);
        
        if (!$invoice->getId()) {
            throw new \Exception(__('发票不存在'));
        }
        
        return $invoice;
    }
    
    /**
     * 获取发票列表
     * 
     * @param int $orderId 订单ID
     * @return array
     */
    public function getInvoiceList(int $orderId): array
    {
        $collection = $this->getInvoiceModel()->reset()
            ->where(OrderInvoice::schema_fields_ORDER_ID, $orderId)
            ->order(OrderInvoice::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch();
        
        return $collection->getItems();
    }
}

