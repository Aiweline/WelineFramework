<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Product;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 产品品牌条件
 * 
 * @package Weline_Marketing
 */
class Brand extends AbstractCondition
{
    public function getCode(): string
    {
        return 'product_brand';
    }

    public function getName(): string
    {
        return __('产品品牌');
    }

    public function getDescription(): string
    {
        return __('根据产品品牌进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $products = $context['products'] ?? [];
        if (empty($products)) {
            $products = $context['items'] ?? [];
        }

        if (empty($products)) {
            return false;
        }

        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($products as $product) {
            $brandId = $product['brand_id'] ?? null;
            if ($brandId && $this->compare($brandId, $operator, $value)) {
                return true;
            }
        }

        return false;
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
                'label' => __('品牌'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

