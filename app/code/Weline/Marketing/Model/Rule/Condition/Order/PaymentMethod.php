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
 * 支付方式条件
 * 
 * @package Weline_Marketing
 */
class PaymentMethod extends AbstractCondition
{
    public function getCode(): string
    {
        return 'payment_method';
    }

    public function getName(): string
    {
        return __('支付方式');
    }

    public function getDescription(): string
    {
        return __('根据支付方式进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $order = $context['order'] ?? [];
        $paymentMethod = $order['payment_method'] ?? $context['payment_method'] ?? null;

        if ($paymentMethod === null) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($paymentMethod, $operator, $value);
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
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('支付方式'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

