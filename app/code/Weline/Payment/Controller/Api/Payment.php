<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Api;

use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentService;

class Payment extends AbstractRestController
{
    private PaymentMethodManager $methodManager;
    private PaymentService $paymentService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
        $this->paymentService = $objectManager->getInstance(PaymentService::class);
    }

    /**
     * 获取可用的支付方式列表
     */
    public function getMethods()
    {
        $methods = $this->methodManager->getActiveMethods();
        
        $result = [];
        foreach ($methods as $method) {
            $provider = $this->methodManager->getProviderInstance($method);
            if ($provider) {
                $result[] = [
                    'code' => $method->getData('code'),
                    'name' => $method->getData('name'),
                    'icon_url' => $provider->getIconUrl(),
                    'description' => $provider->getDescription(),
                ];
            }
        }
        
        return $this->success(__('获取支付方式列表成功'), $result);
    }

    /**
     * 创建支付订单
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
     * 查询支付状态
     */
    public function query()
    {
        $transactionNo = $this->request->getParam('transaction_no');
        
        if (!$transactionNo) {
            return $this->error(__('缺少交易号'));
        }
        
        try {
            $transaction = $this->paymentService->queryPaymentStatus($transactionNo);
            
            if (!$transaction) {
                return $this->error(__('交易记录不存在'));
            }
            
            return $this->success(__('查询成功'), [
                'transaction_no' => $transaction->getData('transaction_no'),
                'status' => $transaction->getData('status'),
                'amount' => $transaction->getData('amount'),
                'currency' => $transaction->getData('currency'),
            ]);
        } catch (\Exception $e) {
            return $this->error(__('查询失败: %{1}', [$e->getMessage()]));
        }
    }
}

