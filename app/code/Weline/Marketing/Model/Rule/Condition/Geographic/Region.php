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
 * 省份/州条件
 * 
 * @package Weline_Marketing
 */
class Region extends AbstractCondition
{
    public function getCode(): string
    {
        return 'region';
    }

    public function getName(): string
    {
        return __('省份/州');
    }

    public function getDescription(): string
    {
        return __('根据省份或州进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $address = $context['address'] ?? $context['shipping_address'] ?? $context['billing_address'] ?? [];
        $region = $address['region'] ?? $address['region_id'] ?? $address['province'] ?? $context['region'] ?? null;

        if ($region === null) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($region, $operator, $value);
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
                'label' => __('省份/州'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

