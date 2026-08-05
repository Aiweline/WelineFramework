<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Interface;

use Weline\Payment\Model\PaymentResult;

/**
 * 支付提供商接口
 * 
 * 所有支付提供商必须实现此接口
 */
interface PaymentProviderInterface
{
    /**
     * 获取支付方式代码（唯一标识）
     * 
     * @return string
     */
    public function getCode(): string;
    
    /**
     * 获取支付方式名称
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * 获取支付方式描述
     * 
     * @return string
     */
    public function getDescription(): string;
    
    /**
     * 获取支付方式图标URL
     * 
     * @return string|null
     */
    public function getIconUrl(): ?string;
    
    /**
     * 创建支付订单
     * 
     * @param array $orderData 订单数据，包含：
     *   - order_id: 订单ID
     *   - amount: 支付金额
     *   - currency: 货币代码
     *   - subject: 订单标题
     *   - description: 订单描述
     *   - return_url: 支付成功返回URL
     *   - notify_url: 支付回调通知URL
     *   - extra: 其他扩展数据
     * 
     * @return PaymentResult
     */
    public function createPayment(array $orderData): PaymentResult;
    
    /**
     * 处理支付回调
     * 
     * @param array $callbackData 回调数据
     * @return PaymentResult
     */
    public function handleCallback(array $callbackData): PaymentResult;
    
    /**
     * 查询支付状态
     * 
     * @param string $transactionNo 交易号
     * @return PaymentResult
     */
    public function queryPaymentStatus(string $transactionNo): PaymentResult;
    
    /**
     * 处理退款
     * 
     * @param string $transactionNo 原交易号
     * @param float $amount 退款金额
     * @param string $reason 退款原因
     * @return PaymentResult
     */
    public function refund(string $transactionNo, float $amount, string $reason = ''): PaymentResult;
    
    /**
     * 验证签名
     * 
     * @param array $data 待验证数据
     * @param string $signature 签名
     * @return bool
     */
    public function verifySignature(array $data, string $signature): bool;
    
    /**
     * 获取支付配置表单字段
     * 
     * 返回配置字段数组，用于后台配置界面
     * 格式：
     * [
     *   'field_name' => [
     *     'label' => '字段标签',
     *     'type' => 'text|password|select|textarea',
     *     'required' => true|false,
     *     'options' => [...], // 当type为select时使用
     *     'default' => '默认值',
     *     'description' => '字段说明'
     *   ]
     * ]
     * 
     * @return array
     */
    public function getConfigFields(): array;
    
    /**
     * 设置配置
     * 
     * @param array $config 配置数据
     * @return void
     */
    public function setConfig(array $config): void;
    
    /**
     * 获取配置
     * 
     * @return array
     */
    public function getConfig(): array;
    
    /**
     * 是否支持该货币
     * 
     * @param string $currency 货币代码
     * @return bool
     */
    public function supportsCurrency(string $currency): bool;
    
    /**
     * 是否支持该金额范围
     * 
     * @param float $amount 金额
     * @return bool
     */
    public function supportsAmount(float $amount): bool;
    
    /**
     * 获取支持的优惠方式代码列表
     * 
     * 返回支持的营销模块优惠方式代码数组，如：
     * ['discount_fixed_amount', 'discount_percentage', 'free_shipping']
     * 
     * 如果返回空数组，表示支持所有优惠方式
     * 如果返回null，表示不支持任何优惠方式
     * 
     * @return array|null
     */
    public function getSupportedDiscountActions(): ?array;
    
    /**
     * 检查是否支持特定优惠方式
     * 
     * @param string $actionCode 优惠方式代码（如：discount_fixed_amount）
     * @return bool
     */
    public function supportsDiscountAction(string $actionCode): bool;
}

