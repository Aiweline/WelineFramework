<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Geographic;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 国家条件
 * 
 * @package Weline_Marketing
 */
class Country extends AbstractCondition
{
    public function getCode(): string
    {
        return 'country';
    }

    public function getName(): string
    {
        return __('国家');
    }

    public function getDescription(): string
    {
        return __('根据国家进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $address = $context['address'] ?? $context['shipping_address'] ?? $context['billing_address'] ?? [];
        $country = $address['country'] ?? $address['country_id'] ?? $context['country'] ?? null;

        if ($country === null) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($country, $operator, $value);
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
                'label' => __('国家'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

