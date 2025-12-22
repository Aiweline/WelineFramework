<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Order;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 订单金额条件
 * 
 * @package Weline_Marketing
 */
class Subtotal extends AbstractCondition
{
    public function getCode(): string
    {
        return 'order_subtotal';
    }

    public function getName(): string
    {
        return __('订单金额');
    }

    public function getDescription(): string
    {
        return __('根据订单小计金额进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $order = $context['order'] ?? [];
        $subtotal = (float)($order['subtotal'] ?? $order['total'] ?? $context['subtotal'] ?? 0);

        $operator = $condition['operator'] ?? '>=';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($subtotal, $operator, (float)$value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('订单金额 >='),
                    '<=' => __('订单金额 <='),
                    '>' => __('订单金额 >'),
                    '<' => __('订单金额 <'),
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

