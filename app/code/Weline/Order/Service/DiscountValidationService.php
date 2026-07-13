<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Service;

use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Payment\Api\Discount\DiscountActionSupportInterface;

/**
 * 优惠方式验证服务
 * 
 * 验证优惠规则是否可以应用于订单，检查支付方式是否支持优惠方式
 * 
 * @package Weline_Order
 */
class DiscountValidationService
{
    private ?DiscountActionSupportInterface $discountActionSupport = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    /**
     * 验证支付方式是否支持优惠方式
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param string $actionCode 优惠方式代码
     * @return bool
     */
    public function validateDiscountForPayment(string $paymentMethodCode, string $actionCode): bool
    {
        if (empty($paymentMethodCode) || empty($actionCode)) {
            return false;
        }

        return $this->discountActionSupport()->checkSupport($paymentMethodCode, $actionCode);
    }

    /**
     * 验证支付方式是否支持多个优惠方式
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param array $actionCodes 优惠方式代码数组
     * @return array 返回不支持的优惠方式代码数组
     */
    public function validateDiscountsForPayment(string $paymentMethodCode, array $actionCodes): array
    {
        if (empty($paymentMethodCode) || empty($actionCodes)) {
            return [];
        }

        return $this->discountActionSupport()->validateActions($paymentMethodCode, $actionCodes);
    }

    /**
     * 获取支付方式支持的所有优惠方式
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @return array 支持的优惠方式代码数组
     */
    public function getSupportedActions(string $paymentMethodCode): array
    {
        if (empty($paymentMethodCode)) {
            return [];
        }

        return $this->discountActionSupport()->getSupportedActions($paymentMethodCode);
    }

    /**
     * 验证规则中的动作是否都被支付方式支持
     * 
     * @param string $paymentMethodCode 支付方式代码
     * @param array $ruleActions 规则动作配置数组
     * @return array 返回验证结果 ['valid' => bool, 'unsupported' => array, 'messages' => array]
     */
    public function validateRuleActions(string $paymentMethodCode, array $ruleActions): array
    {
        $result = [
            'valid' => true,
            'unsupported' => [],
            'messages' => [],
        ];

        if (empty($ruleActions)) {
            return $result;
        }

        // 提取所有动作代码
        $actionCodes = [];
        if (isset($ruleActions['type'])) {
            // 单个动作
            $actionCodes[] = $ruleActions['type'];
        } else {
            // 多个动作
            foreach ($ruleActions as $action) {
                if (isset($action['type'])) {
                    $actionCodes[] = $action['type'];
                }
            }
        }

        // 验证每个动作
        $unsupported = $this->validateDiscountsForPayment($paymentMethodCode, $actionCodes);
        
        if (!empty($unsupported)) {
            $result['valid'] = false;
            $result['unsupported'] = $unsupported;
            
            // 获取动作名称
            $allActions = $this->discountActionSupport()->getAllDiscountActions();
            foreach ($unsupported as $code) {
                $actionName = $allActions[$code]['name'] ?? $code;
                $result['messages'][] = sprintf(__('支付方式不支持优惠方式：%s'), $actionName);
            }
        }

        return $result;
    }

    private function discountActionSupport(): DiscountActionSupportInterface
    {
        if ($this->discountActionSupport instanceof DiscountActionSupportInterface) {
            return $this->discountActionSupport;
        }

        $provider = $this->runtimeProviderResolver->resolve(DiscountActionSupportInterface::class);
        if (!$provider instanceof DiscountActionSupportInterface) {
            throw new \RuntimeException('payment_discount_action_support_provider_missing');
        }

        return $this->discountActionSupport = $provider;
    }
}
