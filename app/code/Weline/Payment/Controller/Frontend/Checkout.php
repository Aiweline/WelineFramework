<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentService;
use Weline\Payment\Service\PaymentMethodManager;

class Checkout extends FrontendController
{
    private PaymentService $paymentService;
    private PaymentMethodManager $methodManager;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->paymentService = $objectManager->getInstance(PaymentService::class);
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
    }

    /**
     * 创建支付
     */
    public function create()
    {
        $methodCode = $this->request->getParam('method_code');
        $orderId = $this->request->getParam('order_id');
        $amount = (float)$this->request->getParam('amount', 0);
        $currency = $this->request->getParam('currency', 'CNY');
        $subject = $this->request->getParam('subject', '');
        $description = $this->request->getParam('description', '');
        
        if (!$methodCode || !$orderId || $amount <= 0) {
            return $this->error(__('缺少必需参数'));
        }
        
        try {
            $orderData = [
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => $currency,
                'subject' => $subject ?: __('订单支付'),
                'description' => $description ?: __('订单号: %{1}', [$orderId]),
                'return_url' => $this->getUrl('*/frontend/checkout/return'),
                'notify_url' => $this->getUrl('*/frontend/callback/notify'),
            ];
            
            $transaction = $this->paymentService->createPayment($methodCode, $orderData);
            
            // 获取支付提供商
            $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
            $provider = $this->methodManager->getProviderInstance($paymentMethod);
            
            if ($provider) {
                $result = $provider->createPayment($orderData);
                
                // 如果返回了支付URL或支付表单，返回给前端
                if ($result->isSuccess()) {
                    return $this->success(__('支付订单创建成功'), [
                        'transaction_no' => $transaction->getData('transaction_no'),
                        'payment_url' => $result->getData('payment_url'),
                        'payment_form' => $result->getData('payment_form'),
                        'qr_code' => $result->getData('qr_code'),
                    ]);
                }
            }
            
            return $this->error(__('创建支付订单失败'));
        } catch (\Exception $e) {
            return $this->error(__('创建支付订单失败: %{1}', [$e->getMessage()]));
        }
    }

    /**
     * 支付返回页面
     */
    public function return()
    {
        $transactionNo = $this->request->getParam('transaction_no');
        
        if (!$transactionNo) {
            $this->getMessageManager()->addError(__('缺少交易号'));
            return $this->redirect('/');
        }
        
        try {
            $transaction = $this->paymentService->queryPaymentStatus($transactionNo);
            
            if ($transaction && $transaction->isSuccess()) {
                $this->getMessageManager()->addSuccess(__('支付成功'));
            } else {
                $this->getMessageManager()->addError(__('支付失败或待处理'));
            }
            
            $this->assign('transaction', $transaction);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('查询支付状态失败: %{1}', [$e->getMessage()]));
        }
        
        return $this->fetch();
    }
}

