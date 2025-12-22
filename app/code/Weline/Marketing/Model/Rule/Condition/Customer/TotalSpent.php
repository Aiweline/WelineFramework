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
 * 累计消费条件
 * 
 * @package Weline_Marketing
 */
class TotalSpent extends AbstractCondition
{
    public function getCode(): string
    {
        return 'total_spent';
    }

    public function getName(): string
    {
        return __('累计消费');
    }

    public function getDescription(): string
    {
        return __('根据客户累计消费金额进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $operator = $condition['operator'] ?? '>=';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $totalSpent = (float)($customer['total_spent'] ?? $customer['lifetime_value'] ?? 0);
        return $this->compare($totalSpent, $operator, (float)$value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('累计消费 >='),
                    '<=' => __('累计消费 <='),
                    '>' => __('累计消费 >'),
                    '<' => __('累计消费 <'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('金额'),
                'type' => 'number',
                'step' => '0.01',
                'required' => true,
            ],
        ];
    }
}

