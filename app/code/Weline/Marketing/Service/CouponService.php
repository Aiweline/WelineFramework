<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Rule\Rule;
use Weline\Marketing\Model\RuleUsage\RuleUsage;

/**
 * 优惠券服务
 * 
 * @package Weline_Marketing
 */
class CouponService
{
    /**
     * 创建优惠券
     *
     * @param array $data 优惠券数据
     * @return Coupon
     * @throws \Exception
     */
    public function createCoupon(array $data): Coupon
    {
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        
        // 如果没有提供代码，自动生成
        if (empty($data['code'])) {
            $data['code'] = $this->generateCouponCode();
        }

        $coupon->setData($data);
        $coupon->save();

        return $coupon;
    }

    /**
     * 生成优惠券代码
     *
     * @param int $length 代码长度
     * @return string
     */
    protected function generateCouponCode(int $length = 8): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        $max = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, $max)];
        }

        // 检查是否已存在
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        if ($coupon->load(Coupon::schema_fields_CODE, $code)->getId()) {
            return $this->generateCouponCode($length);
        }

        return $code;
    }

    /**
     * 验证优惠券
     *
     * @param string $code 优惠券代码
     * @param array $context 上下文数据（客户、订单等）
     * @return array|null 返回优惠券信息和规则，如果无效则返回null
     */
    public function validateCoupon(string $code, array $context = []): ?array
    {
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        $coupon->load(Coupon::schema_fields_CODE, $code);

        if (!$coupon->getId()) {
            return null;
        }

        if (!$coupon->isValid()) {
            return null;
        }

        // 检查客户使用次数限制
        if (!empty($context['customer_id'])) {
            $customerId = $context['customer_id'];
            $customerLimit = $coupon->getData(Coupon::schema_fields_CUSTOMER_LIMIT);
            if ($customerLimit) {
                $usageCount = $this->getCustomerUsageCount($coupon->getId(), $customerId);
                if ($usageCount >= $customerLimit) {
                    return null;
                }
            }
        }

        // 检查最小订单金额
        $minAmount = $coupon->getData(Coupon::schema_fields_MIN_AMOUNT);
        if ($minAmount) {
            $subtotal = (float)($context['subtotal'] ?? $context['order']['subtotal'] ?? 0);
            if ($subtotal < $minAmount) {
                return null;
            }
        }

        // 加载关联规则
        $ruleId = $coupon->getData(Coupon::schema_fields_RULE_ID);
        if ($ruleId) {
            /** @var Rule $rule */
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->load($ruleId);
            
            if ($rule->getId()) {
                return [
                    'coupon' => $coupon,
                    'rule' => $rule,
                ];
            }
        }

        return [
            'coupon' => $coupon,
            'rule' => null,
        ];
    }

    /**
     * 使用优惠券
     *
     * @param string $code 优惠券代码
     * @param array $context 上下文数据
     * @return array|null 返回执行结果
     */
    public function useCoupon(string $code, array $context): ?array
    {
        $validation = $this->validateCoupon($code, $context);
        if (!$validation) {
            return null;
        }

        $coupon = $validation['coupon'];
        $rule = $validation['rule'];

        if (!$rule || !$rule->isActive()) {
            return null;
        }

        // 使用规则引擎应用规则
        /** @var RuleEngine $ruleEngine */
        $ruleEngine = ObjectManager::getInstance(RuleEngine::class);
        $result = $ruleEngine->applyRule($rule, $context);

        if ($result) {
            // 记录使用
            $this->recordUsage($coupon->getId(), $rule->getId(), $context, $result['discount_amount'] ?? 0);

            // 更新使用次数
            $coupon->setData(Coupon::schema_fields_USAGE_COUNT, $coupon->getData(Coupon::schema_fields_USAGE_COUNT) + 1);
            $coupon->save();

            $rule->setData(Rule::schema_fields_USAGE_COUNT, $rule->getData(Rule::schema_fields_USAGE_COUNT) + 1);
            $rule->save();
        }

        return $result;
    }

    /**
     * 记录使用
     *
     * @param int $couponId
     * @param int $ruleId
     * @param array $context
     * @param float $discountAmount
     * @return void
     */
    protected function recordUsage(int $couponId, int $ruleId, array $context, float $discountAmount): void
    {
        /** @var RuleUsage $ruleUsage */
        $ruleUsage = ObjectManager::getInstance(RuleUsage::class);
        $ruleUsage->setData(RuleUsage::schema_fields_COUPON_ID, $couponId);
        $ruleUsage->setData(RuleUsage::schema_fields_RULE_ID, $ruleId);
        $ruleUsage->setData(RuleUsage::schema_fields_CUSTOMER_ID, $context['customer_id'] ?? null);
        $ruleUsage->setData(RuleUsage::schema_fields_ORDER_ID, $context['order_id'] ?? null);
        $ruleUsage->setData(RuleUsage::schema_fields_DISCOUNT_AMOUNT, $discountAmount);
        $ruleUsage->save();
    }

    /**
     * 获取客户使用次数
     *
     * @param int $couponId
     * @param int $customerId
     * @return int
     */
    protected function getCustomerUsageCount(int $couponId, int $customerId): int
    {
        /** @var RuleUsage $ruleUsage */
        $ruleUsage = ObjectManager::getInstance(RuleUsage::class);
        $ruleUsage->where(RuleUsage::schema_fields_COUPON_ID, $couponId)
            ->where(RuleUsage::schema_fields_CUSTOMER_ID, $customerId);
        
        return $ruleUsage->count();
    }

    /**
     * 获取优惠券统计
     *
     * @param int $couponId
     * @return array
     */
    public function getStatistics(int $couponId): array
    {
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        $coupon->load($couponId);

        if (!$coupon->getId()) {
            return [];
        }

        /** @var RuleUsage $ruleUsage */
        $ruleUsage = ObjectManager::getInstance(RuleUsage::class);
        $ruleUsage->where(RuleUsage::schema_fields_COUPON_ID, $couponId);
        $totalUsage = $ruleUsage->count();

        $ruleUsage->reset();
        $ruleUsage->where(RuleUsage::schema_fields_COUPON_ID, $couponId);
        $totalDiscount = $ruleUsage->sum(RuleUsage::schema_fields_DISCOUNT_AMOUNT);

        return [
            'coupon_id' => $couponId,
            'code' => $coupon->getData(Coupon::schema_fields_CODE),
            'usage_count' => $coupon->getData(Coupon::schema_fields_USAGE_COUNT),
            'usage_limit' => $coupon->getData(Coupon::schema_fields_USAGE_LIMIT),
            'total_usage' => $totalUsage,
            'total_discount' => $totalDiscount ?? 0,
        ];
    }
}

