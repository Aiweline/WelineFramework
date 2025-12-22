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
 * 产品SKU条件
 * 
 * @package Weline_Marketing
 */
class Sku extends AbstractCondition
{
    public function getCode(): string
    {
        return 'product_sku';
    }

    public function getName(): string
    {
        return __('产品SKU');
    }

    public function getDescription(): string
    {
        return __('根据产品SKU进行条件判断');
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

        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            if ($sku && $this->compare($sku, $operator, $value)) {
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
                    'in' => __('包含'),
                    'not_in' => __('不包含'),
                    'contains' => __('包含（模糊）'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('SKU'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

