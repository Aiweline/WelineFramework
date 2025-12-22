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
 * 城市条件
 * 
 * @package Weline_Marketing
 */
class City extends AbstractCondition
{
    public function getCode(): string
    {
        return 'city';
    }

    public function getName(): string
    {
        return __('城市');
    }

    public function getDescription(): string
    {
        return __('根据城市进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $address = $context['address'] ?? $context['shipping_address'] ?? $context['billing_address'] ?? [];
        $city = $address['city'] ?? $context['city'] ?? null;

        if ($city === null) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($city, $operator, $value);
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
                'label' => __('城市'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

