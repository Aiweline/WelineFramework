<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\FreeShippingRule;

/**
 * 免邮规则服务
 * 
 * @package Weline_Shipping
 */
class FreeShippingService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取免邮规则模型实例
     * 
     * @return FreeShippingRule
     */
    private function getModel(): FreeShippingRule
    {
        return $this->objectManager->getInstance(FreeShippingRule::class);
    }

    /**
     * 判断订单是否满足免邮条件
     * 
     * @param float $orderAmount 订单金额
     * @param int|null $memberLevelId 会员等级ID
     * @param int|null $regionId 地区ID
     * @param string|null $couponCode 优惠券代码
     * @return FreeShippingRule|null 满足条件的规则，null表示不满足
     */
    public function checkFreeShipping(
        float $orderAmount = 0,
        ?int $memberLevelId = null,
        ?int $regionId = null,
        ?string $couponCode = null
    ): ?FreeShippingRule {
        // 获取所有启用的免邮规则，按优先级排序
        $rules = $this->getModel()->reset()
            ->where(FreeShippingRule::schema_fields_IS_ACTIVE, 1)
            ->order(FreeShippingRule::schema_fields_PRIORITY, 'DESC')
            ->select()
            ->fetch();
        
        foreach ($rules->getItems() as $rule) {
            if ($this->matchRule($rule, $orderAmount, $memberLevelId, $regionId, $couponCode)) {
                return $rule;
            }
        }
        
        return null;
    }

    /**
     * 判断规则是否匹配
     * 
     * @param FreeShippingRule $rule
     * @param float $orderAmount
     * @param int|null $memberLevelId
     * @param int|null $regionId
     * @param string|null $couponCode
     * @return bool
     */
    private function matchRule(
        FreeShippingRule $rule,
        float $orderAmount,
        ?int $memberLevelId,
        ?int $regionId,
        ?string $couponCode
    ): bool {
        $conditionType = $rule->getData(FreeShippingRule::schema_fields_CONDITION_TYPE);
        
        switch ($conditionType) {
            case FreeShippingRule::CONDITION_ORDER_AMOUNT:
                return $this->matchOrderAmount($rule, $orderAmount);
                
            case FreeShippingRule::CONDITION_MEMBER_LEVEL:
                return $this->matchMemberLevel($rule, $memberLevelId);
                
            case FreeShippingRule::CONDITION_REGION:
                return $this->matchRegion($rule, $regionId);
                
            case FreeShippingRule::CONDITION_COUPON:
                return $this->matchCoupon($rule, $couponCode);
                
            case FreeShippingRule::CONDITION_MIXED:
                return $this->matchMixed($rule, $orderAmount, $memberLevelId, $regionId, $couponCode);
                
            default:
                return false;
        }
    }

    /**
     * 匹配订单金额条件
     * 
     * @param FreeShippingRule $rule
     * @param float $orderAmount
     * @return bool
     */
    private function matchOrderAmount(FreeShippingRule $rule, float $orderAmount): bool
    {
        $minAmount = (float)$rule->getData(FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT);
        return $orderAmount >= $minAmount;
    }

    /**
     * 匹配会员等级条件
     * 
     * @param FreeShippingRule $rule
     * @param int|null $memberLevelId
     * @return bool
     */
    private function matchMemberLevel(FreeShippingRule $rule, ?int $memberLevelId): bool
    {
        if ($memberLevelId === null) {
            return false;
        }
        
        $levelIds = $rule->getMemberLevelIds();
        return in_array($memberLevelId, $levelIds);
    }

    /**
     * 匹配地区条件
     * 
     * @param FreeShippingRule $rule
     * @param int|null $regionId
     * @return bool
     */
    private function matchRegion(FreeShippingRule $rule, ?int $regionId): bool
    {
        if ($regionId === null) {
            return false;
        }
        
        $regionIds = $rule->getRegionIds();
        return in_array($regionId, $regionIds);
    }

    /**
     * 匹配优惠券条件
     * 
     * @param FreeShippingRule $rule
     * @param string|null $couponCode
     * @return bool
     */
    private function matchCoupon(FreeShippingRule $rule, ?string $couponCode): bool
    {
        if ($couponCode === null) {
            return false;
        }
        
        $codes = $rule->getCouponCodes();
        return in_array($couponCode, $codes);
    }

    /**
     * 匹配混合条件
     * 
     * @param FreeShippingRule $rule
     * @param float $orderAmount
     * @param int|null $memberLevelId
     * @param int|null $regionId
     * @param string|null $couponCode
     * @return bool
     */
    private function matchMixed(
        FreeShippingRule $rule,
        float $orderAmount,
        ?int $memberLevelId,
        ?int $regionId,
        ?string $couponCode
    ): bool {
        $config = $rule->getMixedConfig();
        $logic = $config['logic'] ?? 'AND';
        $conditions = $config['conditions'] ?? [];
        
        if (empty($conditions)) {
            return false;
        }
        
        $results = [];
        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? '';
            $operator = $condition['operator'] ?? '';
            $value = $condition['value'] ?? null;
            
            switch ($type) {
                case 'order_amount':
                    $results[] = $this->compareValue($orderAmount, $operator, $value);
                    break;
                    
                case 'member_level':
                    $results[] = $memberLevelId !== null && in_array($memberLevelId, (array)$value);
                    break;
                    
                case 'region':
                    $results[] = $regionId !== null && in_array($regionId, (array)$value);
                    break;
                    
                case 'coupon':
                    $results[] = $couponCode !== null && in_array($couponCode, (array)$value);
                    break;
            }
        }
        
        if ($logic === 'OR') {
            return in_array(true, $results);
        } else {
            return !in_array(false, $results);
        }
    }

    /**
     * 比较值
     * 
     * @param float $actual
     * @param string $operator
     * @param mixed $value
     * @return bool
     */
    private function compareValue(float $actual, string $operator, $value): bool
    {
        switch ($operator) {
            case '>=':
                return $actual >= (float)$value;
            case '<=':
                return $actual <= (float)$value;
            case '>':
                return $actual > (float)$value;
            case '<':
                return $actual < (float)$value;
            case '=':
            case '==':
                return $actual == (float)$value;
            default:
                return false;
        }
    }
}

