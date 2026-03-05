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
use Weline\Order\Service\RefundService;

/**
 * 退款管理控制器
 */
#[Acl('Weline_Order::refund_manage', '退款管理', 'mdi-cash-refund', '退款管理', 'Weline_Order::order_manage')]
class Refund extends BackendController
{
    private RefundService $refundService;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->refundService = $objectManager->getInstance(RefundService::class);
    }
    
    /**
     * 创建退款
     */
    #[Acl('Weline_Order::refund_create', '创建退款', 'mdi-plus-circle', '创建退款记录')]
    public function create()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $refundData = [
            'amount' => (float)$this->request->getPost('amount'),
            'reason' => trim((string)$this->request->getPost('reason', '')),
        ];
        
        if (!$orderId || !$refundData['amount']) {
            $this->getMessageManager()->addError(__('参数错误'));
            $this->redirect('order/backend/order/view?id=' . $orderId);
            return;
        }
        
        try {
            $this->refundService->createRefund($orderId, $refundData);
            $this->getMessageManager()->addSuccess(__('退款申请创建成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 创建退款申请后返回订单详情
        $this->redirect('order/backend/order/view?id=' . $orderId);
    }
    
    /**
     * 处理退款
     */
    #[Acl('Weline_Order::refund_process', '处理退款', 'mdi-check-circle', '处理退款申请')]
    public function process()
    {
        $refundId = (int)$this->request->getParam('id');
        
        if (!$refundId) {
            $this->getMessageManager()->addError(__('退款ID不能为空'));
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $refund = $this->refundService->processRefund($refundId);
            $orderId = (int)$refund->getData(\Weline\Order\Model\OrderRefund::schema_fields_ORDER_ID);
            $this->getMessageManager()->addSuccess(__('退款处理成功'));
            $this->redirect('order/backend/order/view?id=' . $orderId);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('order/backend/order/index');
        }
    }
}

