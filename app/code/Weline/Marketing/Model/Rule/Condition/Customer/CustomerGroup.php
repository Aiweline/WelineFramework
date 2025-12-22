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
 * 客户组条件
 * 
 * @package Weline_Marketing
 */
class CustomerGroup extends AbstractCondition
{
    public function getCode(): string
    {
        return 'customer_group';
    }

    public function getName(): string
    {
        return __('客户组');
    }

    public function getDescription(): string
    {
        return __('根据客户所属的客户组进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $attribute = $condition['attribute'] ?? 'customer_group';
        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $customerGroup = $customer['group_id'] ?? $customer['customer_group'] ?? null;
        return $this->compare($customerGroup, $operator, $value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'attribute',
                'label' => __('属性'),
                'type' => 'hidden',
                'value' => 'customer_group',
            ],
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    'in' => __('属于'),
                    'not_in' => __('不属于'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('客户组'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

