<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Service;

use Weline\Checkout\Model\Order;
use Weline\Checkout\Model\PaymentTransaction;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\EventsManager;

/**
 * 支付服务（基础接口，供支付模块扩展）
 */
class PaymentService
{
    private ConnectionFactory $connectionFactory;
    private EventsManager $eventsManager;

    public function __construct(
        ConnectionFactory $connectionFactory,
        EventsManager $eventsManager
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 验证支付数据
     * 
     * @param array $data
     * @return array 返回验证结果 ['valid' => bool, 'errors' => []]
     */
    public function validatePayment(array $data): array
    {
        // 派遣支付验证前事件
        $this->eventsManager->dispatch('Weline_Checkout::payment::validate::before', [
            'data' => &$data,
        ]);
        
        $errors = [];
        
        // 验证订单ID
        if (empty($data['order_id'])) {
            $errors[] = __('订单ID不能为空');
        }
        
        // 验证支付方式
        if (empty($data['payment_method'])) {
            $errors[] = __('支付方式不能为空');
        }
        
        // 验证金额
        if (empty($data['amount']) || $data['amount'] <= 0) {
            $errors[] = __('支付金额无效');
        }
        
        $result = [
            'valid' => empty($errors),
            'errors' => $errors
        ];
        
        // 派遣支付验证后事件
        $this->eventsManager->dispatch('Weline_Checkout::payment::validate::after', [
            'data' => $data,
            'result' => &$result,
        ]);
        
        return $result;
    }

    /**
     * 处理支付（调用hook，由支付模块实现具体逻辑）
     * 
     * @param int $orderId
     * @param string $paymentMethod
     * @param array $paymentData
     * @return array
     * @throws \Exception
     */
    public function processPayment(int $orderId, string $paymentMethod, array $paymentData = []): array
    {
        // 获取订单
        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        
        if (!$order->getId()) {
            throw new \Exception(__('订单不存在'));
        }
        
        if ($order->isPaid()) {
            throw new \Exception(__('订单已支付'));
        }
        
        // 验证支付数据
        $validation = $this->validatePayment([
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'amount' => $order->getTotalAmount(),
        ]);
        
        if (!$validation['valid']) {
            throw new \Exception(implode(', ', $validation['errors']));
        }
        
        // 派遣支付处理前事件
        $this->eventsManager->dispatch('Weline_Checkout::payment::process::before', [
            'order_id' => $orderId,
            'order' => $order,
            'payment_method' => $paymentMethod,
            'payment_data' => &$paymentData,
        ]);
        
        // 创建支付交易记录
        /** @var PaymentTransaction $transaction */
        $transaction = ObjectManager::getInstance(PaymentTransaction::class);
        $transaction->setData([
            PaymentTransaction::schema_fields_ORDER_ID => $orderId,
            PaymentTransaction::schema_fields_PAYMENT_METHOD => $paymentMethod,
            PaymentTransaction::schema_fields_AMOUNT => $order->getTotalAmount(),
            PaymentTransaction::schema_fields_CURRENCY => $order->getCurrency(),
            PaymentTransaction::schema_fields_STATUS => PaymentTransaction::STATUS_PENDING,
            PaymentTransaction::schema_fields_GATEWAY_RESPONSE => !empty($paymentData['gateway_response'])
                ? (is_array($paymentData['gateway_response'])
                    ? json_encode($paymentData['gateway_response'], JSON_UNESCAPED_UNICODE)
                    : $paymentData['gateway_response'])
                : '',
            PaymentTransaction::schema_fields_CREATED_TIME => date('Y-m-d H:i:s'),
            PaymentTransaction::schema_fields_UPDATED_TIME => date('Y-m-d H:i:s'),
        ]);
        $transaction->save();
        
        // 派遣支付处理后事件（支付模块应该在这里处理实际的支付逻辑）
        $this->eventsManager->dispatch('Weline_Checkout::payment::process::after', [
            'order_id' => $orderId,
            'order' => $order,
            'transaction_id' => $transaction->getId(),
            'transaction' => $transaction,
            'payment_method' => $paymentMethod,
            'payment_data' => $paymentData,
        ]);
        
        return [
            'transaction_id' => $transaction->getId(),
            'order_id' => $orderId,
            'status' => $transaction->getStatus(),
        ];
    }

    /**
     * 处理支付回调
     * 
     * @param array $callbackData
     * @return bool
     * @throws \Exception
     */
    public function handlePaymentCallback(array $callbackData): bool
    {
        // 派遣支付回调前事件
        $this->eventsManager->dispatch('Weline_Checkout::payment::callback::before', [
            'callback_data' => &$callbackData,
        ]);
        
        // 派遣支付回调后事件（支付模块应该在这里处理回调逻辑）
        $this->eventsManager->dispatch('Weline_Checkout::payment::callback::after', [
            'callback_data' => $callbackData,
        ]);
        
        // 根据回调结果派遣支付成功或失败事件
        $status = $callbackData['status'] ?? '';
        if ($status === 'success' || $status === 'paid') {
            $this->eventsManager->dispatch('Weline_Checkout::payment::success', [
                'callback_data' => $callbackData,
                'order_id' => $callbackData['order_id'] ?? null,
            ]);
        } elseif ($status === 'failed' || $status === 'fail') {
            $this->eventsManager->dispatch('Weline_Checkout::payment::failed', [
                'callback_data' => $callbackData,
                'order_id' => $callbackData['order_id'] ?? null,
            ]);
        }
        
        return true;
    }
}

