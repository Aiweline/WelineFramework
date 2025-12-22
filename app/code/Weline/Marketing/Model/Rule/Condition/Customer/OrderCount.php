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
 * 订单数量条件
 * 
 * @package Weline_Marketing
 */
class OrderCount extends AbstractCondition
{
    public function getCode(): string
    {
        return 'order_count';
    }

    public function getName(): string
    {
        return __('订单数量');
    }

    public function getDescription(): string
    {
        return __('根据客户订单数量进行条件判断');
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

        $orderCount = (int)($customer['order_count'] ?? 0);
        return $this->compare($orderCount, $operator, (int)$value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('订单数 >='),
                    '<=' => __('订单数 <='),
                    '>' => __('订单数 >'),
                    '<' => __('订单数 <'),
                    '==' => __('订单数 ='),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('数量'),
                'type' => 'number',
                'required' => true,
            ],
        ];
    }
}

