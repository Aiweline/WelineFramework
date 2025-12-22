<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Customer;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * VIP等级条件
 * 
 * @package Weline_Marketing
 */
class VipLevel extends AbstractCondition
{
    public function getCode(): string
    {
        return 'vip_level';
    }

    public function getName(): string
    {
        return __('VIP等级');
    }

    public function getDescription(): string
    {
        return __('根据客户VIP等级进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $vipLevel = $customer['vip_level'] ?? $customer['vip'] ?? null;
        return $this->compare($vipLevel, $operator, $value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    'in' => __('属于'),
                    'not_in' => __('不属于'),
                    '>=' => __('等级 >='),
                    '<=' => __('等级 <='),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('VIP等级'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

