<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Currency;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 货币代码条件
 * 
 * @package Weline_Marketing
 */
class CurrencyCode extends AbstractCondition
{
    public function getCode(): string
    {
        return 'currency_code';
    }

    public function getName(): string
    {
        return __('货币代码');
    }

    public function getDescription(): string
    {
        return __('根据货币代码进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $currency = $context['currency'] ?? $context['currency_code'] ?? null;
        if ($currency === null) {
            $order = $context['order'] ?? [];
            $currency = $order['currency'] ?? $order['currency_code'] ?? null;
        }

        if ($currency === null) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($currency, $operator, $value);
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
                    '==' => __('等于'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('货币代码'),
                'type' => 'multiselect',
                'options' => [
                    'CNY' => __('人民币'),
                    'USD' => __('美元'),
                    'EUR' => __('欧元'),
                    'GBP' => __('英镑'),
                    'JPY' => __('日元'),
                ],
                'required' => true,
            ],
        ];
    }
}

