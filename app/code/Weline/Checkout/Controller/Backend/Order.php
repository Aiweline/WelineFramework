<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Controller\Backend;

use Weline\Checkout\Service\OrderService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Checkout\Model\Order as OrderModel;

#[Acl('Weline_Checkout::order_manage', '订单管理', 'mdi-cart', '订单管理', 'Weline_Backend::business_module')]
class Order extends BackendController
{
    private OrderService $orderService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->orderService = $objectManager->getInstance(OrderService::class);
    }

    /**
     * 订单列表
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_list', '查看订单列表', 'mdi-format-list-bulleted', '查看订单列表')]
    public function index(): string
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        
        $status = $this->request->getParam('status', '');
        $paymentStatus = $this->request->getParam('payment_status', '');
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        
        /** @var OrderModel $orderModel */
        $orderModel = ObjectManager::getInstance(OrderModel::class);
        $query = $orderModel->select();
        
        if ($status) {
            $query->where(OrderModel::fields_STATUS, $status);
        }
        
        if ($paymentStatus) {
            $query->where(OrderModel::fields_PAYMENT_STATUS, $paymentStatus);
        }
        
        if ($keyword) {
            $query->where(OrderModel::fields_ORDER_NUMBER, $keyword, 'LIKE');
        }
        
        $total = $query->count();
        $totalPages = (int)ceil($total / $limit);
        
        $offset = ($page - 1) * $limit;
        $orders = $query->order(OrderModel::fields_CREATED_TIME, 'DESC')
            ->limit($limit, $offset)
            ->fetchArray();
        
        $this->assign('orders', $orders);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('status', $status);
        $this->assign('payment_status', $paymentStatus);
        $this->assign('keyword', $keyword);
        $this->assign('title', __('订单管理'));
        
        return $this->fetch();
    }

    /**
     * 订单详情
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_view', '查看订单详情', 'mdi-eye', '查看订单详情')]
    public function view(): string
    {
        $orderId = (int)$this->request->getParam('order_id');
        $orderNumber = $this->request->getParam('order_number', '');
        
        $order = null;
        if ($orderId) {
            $order = $this->orderService->getOrder($orderId);
        } elseif ($orderNumber) {
            $order = $this->orderService->getOrderByNumber($orderNumber);
        }
        
        if (!$order) {
            $this->getMessageManager()->addError(__('订单不存在'));
            return $this->redirect('checkout/backend/order/index');
        }
        
        $this->assign('order', $order);
        $this->assign('title', __('订单详情'));
        
        return $this->fetch();
    }

    /**
     * 更新订单状态（AJAX）
     * 
     * @return string
     */
    #[Acl('Weline_Checkout::order_update_status', '更新订单状态', 'mdi-pencil', '更新订单状态')]
    public function updateStatus(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $orderId = (int)$this->request->getPost('order_id');
        $status = $this->request->getPost('status', '');

        if (!$orderId || !$status) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('参数不完整')
            ]);
        }

        try {
            $this->orderService->updateOrderStatus($orderId, $status);

            return $this->fetchJson([
                'success' => true,
                'message' => __('订单状态更新成功')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('更新失败：%{1}', $e->getMessage())
            ]);
        }
    }
}

