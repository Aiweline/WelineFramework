<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Service;

use Weline\Payment\Interface\PaymentProviderInterface;
use Weline\Payment\Model\PaymentResult;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Model\PaymentMethod;
use Weline\Framework\Manager\ObjectManager;

/**
 * 支付核心服务
 * 
 * 处理支付相关的核心业务逻辑
 */
class PaymentService
{
    private PaymentMethodManager $methodManager;
    private ObjectManager $objectManager;

    public function __construct(
        PaymentMethodManager $methodManager,
        ObjectManager $objectManager
    ) {
        $this->methodManager = $methodManager;
        $this->objectManager = $objectManager;
    }

    /**
     * 创建支付订单
     * 
     * @param string $methodCode 支付方式代码
     * @param array $orderData 订单数据
     * @return PaymentTransaction
     * @throws \Exception
     */
    public function createPayment(string $methodCode, array $orderData): PaymentTransaction
    {
        // 获取支付方式
        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod || !$paymentMethod->isActive()) {
            throw new \Exception(__('支付方式 %{code} 不存在或未启用', ['code' => $methodCode]));
        }

        // 获取支付提供商
        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            throw new \Exception(__('支付提供商实例化失败'));
        }

        // 验证货币支持
        $currency = $orderData['currency'] ?? 'CNY';
        if (!$provider->supportsCurrency($currency)) {
            throw new \Exception(__('支付方式不支持货币 %{currency}', ['currency' => $currency]));
        }

        // 验证金额支持
        $amount = $orderData['amount'] ?? 0;
        if (!$provider->supportsAmount($amount)) {
            throw new \Exception(__('支付金额不在支持范围内'));
        }

        // 创建支付交易记录
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transactionNo = $this->generateTransactionNo();
        
        $transaction->setData(PaymentTransaction::fields_ORDER_ID, $orderData['order_id'] ?? '')
            ->setData(PaymentTransaction::fields_METHOD_CODE, $methodCode)
            ->setData(PaymentTransaction::fields_TRANSACTION_NO, $transactionNo)
            ->setData(PaymentTransaction::fields_AMOUNT, $amount)
            ->setData(PaymentTransaction::fields_CURRENCY, $currency)
            ->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_PENDING)
            ->setRequestData($orderData)
            ->save();

        // 调用支付提供商创建支付
        $result = $provider->createPayment($orderData);
        
        // 更新交易记录
        $transaction->setResponseData($result->getData())
            ->save();

        if ($result->isSuccess()) {
            // 如果支付提供商返回了交易号，更新
            if ($result->getData('transaction_no')) {
                $transaction->setData(PaymentTransaction::fields_TRANSACTION_NO, $result->getData('transaction_no'))
                    ->save();
            }
        } else {
            // 支付创建失败
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_FAILED)
                ->save();
            throw new \Exception($result->getData('message') ?? __('创建支付订单失败'));
        }

        return $transaction;
    }

    /**
     * 处理支付回调
     * 
     * @param string $methodCode 支付方式代码
     * @param array $callbackData 回调数据
     * @return PaymentTransaction|null
     * @throws \Exception
     */
    public function handleCallback(string $methodCode, array $callbackData): ?PaymentTransaction
    {
        // 获取支付方式
        $paymentMethod = $this->methodManager->getMethodByCode($methodCode);
        if (!$paymentMethod) {
            throw new \Exception(__('支付方式 %{code} 不存在', ['code' => $methodCode]));
        }

        // 获取支付提供商
        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            throw new \Exception(__('支付提供商实例化失败'));
        }

        // 验证签名
        $signature = $callbackData['signature'] ?? $callbackData['sign'] ?? '';
        if (!empty($signature)) {
            $data = $callbackData;
            unset($data['signature'], $data['sign']);
            if (!$provider->verifySignature($data, $signature)) {
                throw new \Exception(__('签名验证失败'));
            }
        }

        // 查找交易记录
        $transactionNo = $callbackData['transaction_no'] ?? $callbackData['out_trade_no'] ?? '';
        if (empty($transactionNo)) {
            throw new \Exception(__('回调数据中缺少交易号'));
        }

        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load($transactionNo, PaymentTransaction::fields_TRANSACTION_NO);
        
        if (!$transaction->getId()) {
            throw new \Exception(__('交易记录不存在: %{no}', ['no' => $transactionNo]));
        }

        // 更新回调数据
        $transaction->setCallbackData($callbackData)
            ->save();

        // 调用支付提供商处理回调
        $result = $provider->handleCallback($callbackData);
        
        // 更新交易状态
        if ($result->isSuccess()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_SUCCESS)
                ->setData(PaymentTransaction::fields_PAID_AT, date('Y-m-d H:i:s'))
                ->save();
        } elseif ($result->isFailed()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_FAILED)
                ->save();
        } elseif ($result->isProcessing()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_PROCESSING)
                ->save();
        }

        return $transaction;
    }

    /**
     * 查询支付状态
     * 
     * @param string $transactionNo 交易号
     * @return PaymentTransaction|null
     * @throws \Exception
     */
    public function queryPaymentStatus(string $transactionNo): ?PaymentTransaction
    {
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load($transactionNo, PaymentTransaction::fields_TRANSACTION_NO);
        
        if (!$transaction->getId()) {
            return null;
        }

        // 如果已经是最终状态，直接返回
        if ($transaction->isSuccess() || $transaction->isFailed()) {
            return $transaction;
        }

        // 获取支付提供商查询状态
        $paymentMethod = $this->methodManager->getMethodByCode($transaction->getData(PaymentTransaction::fields_METHOD_CODE));
        if (!$paymentMethod) {
            return $transaction;
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            return $transaction;
        }

        // 查询支付状态
        $result = $provider->queryPaymentStatus($transactionNo);
        
        // 更新交易状态
        if ($result->isSuccess()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_SUCCESS)
                ->setData(PaymentTransaction::fields_PAID_AT, date('Y-m-d H:i:s'))
                ->save();
        } elseif ($result->isFailed()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_FAILED)
                ->save();
        } elseif ($result->isProcessing()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_PROCESSING)
                ->save();
        }

        return $transaction;
    }

    /**
     * 处理退款
     * 
     * @param string $transactionNo 原交易号
     * @param float $amount 退款金额
     * @param string $reason 退款原因
     * @return PaymentResult
     * @throws \Exception
     */
    public function refund(string $transactionNo, float $amount, string $reason = ''): PaymentResult
    {
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load($transactionNo, PaymentTransaction::fields_TRANSACTION_NO);
        
        if (!$transaction->getId()) {
            throw new \Exception(__('交易记录不存在'));
        }

        if (!$transaction->isSuccess()) {
            throw new \Exception(__('只有支付成功的交易才能退款'));
        }

        if ($transaction->isRefunded()) {
            throw new \Exception(__('该交易已经退款'));
        }

        // 获取支付提供商
        $paymentMethod = $this->methodManager->getMethodByCode($transaction->getData(PaymentTransaction::fields_METHOD_CODE));
        if (!$paymentMethod) {
            throw new \Exception(__('支付方式不存在'));
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if (!$provider) {
            throw new \Exception(__('支付提供商实例化失败'));
        }

        // 调用退款
        $result = $provider->refund($transactionNo, $amount, $reason);
        
        // 更新交易状态
        if ($result->isSuccess()) {
            $transaction->setData(PaymentTransaction::fields_STATUS, PaymentTransaction::STATUS_REFUNDED)
                ->save();
        }

        return $result;
    }

    /**
     * 生成交易号
     * 
     * @return string
     */
    private function generateTransactionNo(): string
    {
        return 'PAY' . date('YmdHis') . mt_rand(100000, 999999);
    }
}

