<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order as OrderModel;
use Weline\Order\Service\OrderService;
use Weline\Order\Service\OrderStateMachine;

/**
 * 订单管理控制器
 */
#[Acl('Weline_Order::order_manage', '订单管理', 'mdi-cart', '订单管理', 'Weline_Backend::order_group')]
class Order extends BackendController
{
    private OrderService $orderService;
    private OrderStateMachine $stateMachine;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->orderService = $objectManager->getInstance(OrderService::class);
        $this->stateMachine = $objectManager->getInstance(OrderStateMachine::class);
    }
    
    /**
     * 订单列表页面
     */
    #[Acl('Weline_Order::order_list', '查看订单列表', 'mdi-format-list-bulleted', '查看订单列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        $pageSize = $pageSize > 0 ? min($pageSize, 100) : 20;
        
        $filters = [
            'page' => $page,
            'page_size' => $pageSize,
        ];
        
        // 搜索条件
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
        $total = count($orders);
        $totalPages = (int)ceil($total / $pageSize);
        
        $this->assign('orders', $orders);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('total_pages', $totalPages);
        $this->assign('filters', $filters);
        
        return $this->fetch();
    }
    
    /**
     * 订单详情页面
     */
    #[Acl('Weline_Order::order_view', '查看订单详情', 'mdi-eye', '查看订单详情')]
    public function view()
    {
        $orderId = (int)$this->request->getParam('id');
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $order = $this->orderService->getOrder($orderId);
            $items = $this->orderService->getOrderItems($orderId);
            
            // 获取支付记录
            $paymentService = ObjectManager::getInstance(\Weline\Order\Service\PaymentService::class);
            $payments = $paymentService->getPaymentHistory($orderId);
            
            // 获取发货记录
            $fulfillmentService = ObjectManager::getInstance(\Weline\Order\Service\FulfillmentService::class);
            $shipments = $fulfillmentService->getShipments($orderId);
            
            // 获取退款记录
            $refundService = ObjectManager::getInstance(\Weline\Order\Service\RefundService::class);
            $refunds = $refundService->getRefundHistory($orderId);
            
            // 获取发票记录
            $invoiceService = ObjectManager::getInstance(\Weline\Order\Service\InvoiceService::class);
            $invoices = $invoiceService->getInvoiceList($orderId);
            
            // 获取订单历史
            $historyModel = ObjectManager::getInstance(\Weline\Order\Model\OrderHistory::class);
            $history = $historyModel->reset()
                ->where(\Weline\Order\Model\OrderHistory::schema_fields_ORDER_ID, $orderId)
                ->order(\Weline\Order\Model\OrderHistory::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 获取可用状态转换
            $currentStatus = $order->getData(OrderModel::schema_fields_STATUS);
            $availableTransitions = $this->stateMachine->getAvailableTransitions($currentStatus);
            
            $this->assign('order', $order);
            $this->assign('items', $items);
            $this->assign('payments', $payments);
            $this->assign('shipments', $shipments);
            $this->assign('refunds', $refunds);
            $this->assign('invoices', $invoices);
            $this->assign('history', $history);
            $this->assign('available_transitions', $availableTransitions);
            $this->assign('current_status', $currentStatus);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }
    
    /**
     * 订单编辑页面
     */
    #[Acl('Weline_Order::order_edit', '编辑订单', 'mdi-pencil', '编辑订单')]
    public function edit()
    {
        $orderId = (int)$this->request->getParam('id');
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $order = $this->orderService->getOrder($orderId);
            $items = $this->orderService->getOrderItems($orderId);
            
            $this->assign('order', $order);
            $this->assign('items', $items);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/index');
        }
    }
    
    /**
     * 保存订单
     */
    #[Acl('Weline_Order::order_save', '保存订单', 'mdi-content-save', '保存订单')]
    public function save()
    {
        $data = $this->request->getPost();
        $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
        
        try {
            if ($orderId) {
                // 更新订单
                $this->orderService->updateOrder($orderId, $data);
                $message = __('订单更新成功');
            } else {
                // 创建订单
                $order = $this->orderService->createOrder($data);
                $orderId = $order->getId();
                $message = __('订单创建成功');
            }
            
            $this->getMessageManager()->addSuccess($message);
            $this->redirect('*/view?id=' . $orderId);
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/edit' . ($orderId ? '?id=' . $orderId : ''));
        }
    }
    
    /**
     * 取消订单
     */
    #[Acl('Weline_Order::order_cancel', '取消订单', 'mdi-cancel', '取消订单')]
    public function cancel()
    {
        $orderId = (int)$this->request->getParam('id');
        $reason = trim((string)$this->request->getPost('reason', ''));
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->orderService->cancelOrder($orderId, $reason);
            $this->getMessageManager()->addSuccess(__('订单取消成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/view?id=' . $orderId);
    }
    
    /**
     * 更新订单状态
     */
    #[Acl('Weline_Order::order_update_status', '更新订单状态', 'mdi-update', '更新订单状态')]
    public function updateStatus()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $newStatus = trim((string)$this->request->getPost('status'));
        $comment = trim((string)$this->request->getPost('comment', ''));
        $notifyCustomer = (bool)$this->request->getPost('notify_customer', false);
        
        if (!$orderId || !$newStatus) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('*/index');
            return;
        }
        
        try {
            $this->stateMachine->transition($orderId, $newStatus, $comment, $notifyCustomer);
            $this->getMessageManager()->addSuccess(__('订单状态更新成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        $this->redirect('*/view?id=' . $orderId);
    }
}

