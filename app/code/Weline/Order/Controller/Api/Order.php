<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\OrderService;
use Weline\Order\Service\OrderStateMachine;
use Weline\Order\Service\PaymentService;
use Weline\Order\Service\FulfillmentService;
use Weline\Order\Service\RefundService;
use Weline\Order\Service\InvoiceService;

/**
 * 订单API控制器
 */
class Order extends BackendRestController
{
    private OrderService $orderService;
    private OrderStateMachine $stateMachine;
    private PaymentService $paymentService;
    private FulfillmentService $fulfillmentService;
    private RefundService $refundService;
    private InvoiceService $invoiceService;
    
    public function __construct(ObjectManager $objectManager)
    {
        parent::__construct();
        $this->orderService = $objectManager->getInstance(OrderService::class);
        $this->stateMachine = $objectManager->getInstance(OrderStateMachine::class);
        $this->paymentService = $objectManager->getInstance(PaymentService::class);
        $this->fulfillmentService = $objectManager->getInstance(FulfillmentService::class);
        $this->refundService = $objectManager->getInstance(RefundService::class);
        $this->invoiceService = $objectManager->getInstance(InvoiceService::class);
    }
    
    /**
     * 获取订单列表 (GET)
     */
    public function getList()
    {
        try {
            $filters = [
                'page' => max(1, (int)($this->request->getParam('page') ?? 1)),
                'page_size' => max(1, min(100, (int)($this->request->getParam('page_size') ?? 20))),
            ];
            
            if ($status = $this->request->getParam('status')) {
                $filters['status'] = $status;
            }
            
            if ($customerId = $this->request->getParam('customer_id')) {
                $filters['customer_id'] = (int)$customerId;
            }
            
            if ($orderNumber = $this->request->getParam('order_number')) {
                $filters['order_number'] = $orderNumber;
            }
            
            if ($paymentStatus = $this->request->getParam('payment_status')) {
                $filters['payment_status'] = $paymentStatus;
            }
            
            if ($fulfillmentStatus = $this->request->getParam('fulfillment_status')) {
                $filters['fulfillment_status'] = $fulfillmentStatus;
            }
            
            if ($keyword = trim((string)$this->request->getParam('keyword'))) {
                $filters['keyword'] = $keyword;
            }
            
            $orders = $this->orderService->getOrderList($filters);
            
            return $this->success(__('获取订单列表成功'), [
                'orders' => $orders,
                'filters' => $filters,
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 获取订单详情 (GET)
     */
    public function getDetail()
    {
        try {
            $orderId = (int)$this->request->getParam('id');
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $order = $this->orderService->getOrder($orderId);
            $items = $this->orderService->getOrderItems($orderId);
            $payments = $this->paymentService->getPaymentHistory($orderId);
            $shipments = $this->fulfillmentService->getShipments($orderId);
            $refunds = $this->refundService->getRefundHistory($orderId);
            $invoices = $this->invoiceService->getInvoiceList($orderId);
            
            // 获取订单历史
            $historyModel = ObjectManager::getInstance(\Weline\Order\Model\OrderHistory::class);
            $history = $historyModel->reset()
                ->where(\Weline\Order\Model\OrderHistory::fields_ORDER_ID, $orderId)
                ->order(\Weline\Order\Model\OrderHistory::fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 获取可用状态转换
            $currentStatus = $order->getData(\Weline\Order\Model\Order::fields_STATUS);
            $availableTransitions = $this->stateMachine->getAvailableTransitions($currentStatus);
            
            return $this->success(__('获取订单详情成功'), [
                'order' => $order->getData(),
                'items' => array_map(function($item) {
                    return $item->getData();
                }, $items),
                'payments' => array_map(function($payment) {
                    return $payment->getData();
                }, $payments),
                'shipments' => array_map(function($shipment) {
                    return $shipment->getData();
                }, $shipments),
                'refunds' => array_map(function($refund) {
                    return $refund->getData();
                }, $refunds),
                'invoices' => array_map(function($invoice) {
                    return $invoice->getData();
                }, $invoices),
                'history' => array_map(function($h) {
                    return $h->getData();
                }, $history),
                'available_transitions' => $availableTransitions,
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 创建订单 (POST)
     */
    public function postCreate()
    {
        try {
            $data = $this->request->getBodyParams();
            
            if (empty($data)) {
                return $this->error(__('订单数据不能为空'), '', 400);
            }
            
            $order = $this->orderService->createOrder($data);
            
            return $this->success(__('订单创建成功'), [
                'order_id' => $order->getId(),
                'order_number' => $order->getData(\Weline\Order\Model\Order::fields_ORDER_NUMBER),
            ], 201);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 更新订单 (PUT)
     */
    public function putUpdate()
    {
        try {
            $orderId = (int)$this->request->getParam('id');
            $data = $this->request->getBodyParams();
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            if (empty($data)) {
                return $this->error(__('更新数据不能为空'), '', 400);
            }
            
            $order = $this->orderService->updateOrder($orderId, $data);
            
            return $this->success(__('订单更新成功'), [
                'order_id' => $order->getId(),
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 取消订单 (POST)
     */
    public function postCancel()
    {
        try {
            $orderId = (int)$this->request->getParam('id');
            $data = $this->request->getBodyParams();
            $reason = $data['reason'] ?? '';
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $this->orderService->cancelOrder($orderId, $reason);
            
            return $this->success(__('订单取消成功'));
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 更新订单状态 (POST)
     */
    public function postUpdateStatus()
    {
        try {
            $data = $this->request->getBodyParams();
            $orderId = (int)($data['order_id'] ?? 0);
            $newStatus = trim((string)($data['status'] ?? ''));
            $comment = trim((string)($data['comment'] ?? ''));
            $notifyCustomer = (bool)($data['notify_customer'] ?? false);
            
            if (!$orderId || !$newStatus) {
                return $this->error(__('参数错误'), '', 400);
            }
            
            $this->stateMachine->transition($orderId, $newStatus, $comment, $notifyCustomer);
            
            return $this->success(__('订单状态更新成功'));
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 处理支付 (POST)
     */
    public function postPayment()
    {
        try {
            $data = $this->request->getBodyParams();
            $orderId = (int)($data['order_id'] ?? 0);
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $payment = $this->paymentService->processPayment($orderId, $data);
            
            return $this->success(__('支付处理成功'), [
                'payment_id' => $payment->getId(),
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 创建发货记录 (POST)
     */
    public function postShipment()
    {
        try {
            $data = $this->request->getBodyParams();
            $orderId = (int)($data['order_id'] ?? 0);
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $shipment = $this->fulfillmentService->createShipment($orderId, $data);
            
            return $this->success(__('发货记录创建成功'), [
                'shipment_id' => $shipment->getId(),
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 创建退款 (POST)
     */
    public function postRefund()
    {
        try {
            $data = $this->request->getBodyParams();
            $orderId = (int)($data['order_id'] ?? 0);
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $refund = $this->refundService->createRefund($orderId, $data);
            
            return $this->success(__('退款申请创建成功'), [
                'refund_id' => $refund->getId(),
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
    
    /**
     * 生成发票 (POST)
     */
    public function postInvoice()
    {
        try {
            $data = $this->request->getBodyParams();
            $orderId = (int)($data['order_id'] ?? 0);
            
            if (!$orderId) {
                return $this->error(__('订单ID不能为空'), '', 400);
            }
            
            $invoice = $this->invoiceService->generateInvoice($orderId);
            
            return $this->success(__('发票生成成功'), [
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->getData(\Weline\Order\Model\OrderInvoice::fields_INVOICE_NUMBER),
            ]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), '', 500);
        }
    }
}

