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
use Weline\Order\Service\PaymentService;

/**
 * 支付管理控制器
 */
#[Acl('Weline_Order::payment_manage', '支付管理', 'mdi-credit-card', '支付管理', 'Weline_Order::order_manage')]
class Payment extends BackendController
{
    private PaymentService $paymentService;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->paymentService = $objectManager->getInstance(PaymentService::class);
    }
    
    /**
     * 处理支付
     */
    #[Acl('Weline_Order::payment_process', '处理支付', 'mdi-cash', '处理支付')]
    public function process()
    {
        $orderId = (int)$this->request->getPost('order_id');
        $paymentData = [
            'amount' => (float)$this->request->getPost('amount'),
            'payment_method' => trim((string)$this->request->getPost('payment_method')),
            'currency' => trim((string)$this->request->getPost('currency', 'CNY')),
            'transaction_id' => trim((string)$this->request->getPost('transaction_id', '')),
        ];
        
        if (!$orderId || !$paymentData['amount'] || !$paymentData['payment_method']) {
            $this->getMessageManager()->addError(__('参数错误'));
            // 使用显式后台订单详情路由，避免生成错误的模块前缀
            $this->redirect('order/backend/order/view?id=' . $orderId);
            return;
        }
        
        try {
            $this->paymentService->processPayment($orderId, $paymentData);
            $this->getMessageManager()->addSuccess(__('支付处理成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 支付处理完成后返回订单详情
        $this->redirect('order/backend/order/view?id=' . $orderId);
    }
    
    /**
     * 退款支付
     */
    #[Acl('Weline_Order::payment_refund', '退款支付', 'mdi-refund', '退款支付')]
    public function refund()
    {
        $paymentId = (int)$this->request->getPost('payment_id');
        $amount = (float)$this->request->getPost('amount');
        
        if (!$paymentId || !$amount) {
            $this->getMessageManager()->addError(__('参数错误'));
            // 参数错误时返回订单列表
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $payment = $this->paymentService->refundPayment($paymentId, $amount);
            $orderId = (int)$payment->getData(\Weline\Order\Model\OrderPayment::schema_fields_ORDER_ID);
            $this->getMessageManager()->addSuccess(__('退款成功'));
            // 退款成功后返回订单详情
            $this->redirect('order/backend/order/view?id=' . $orderId);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            // 失败时返回订单列表
            $this->redirect('order/backend/order/index');
        }
    }
}

