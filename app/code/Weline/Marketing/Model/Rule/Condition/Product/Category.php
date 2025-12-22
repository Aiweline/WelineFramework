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
 * 产品分类条件
 * 
 * @package Weline_Marketing
 */
class Category extends AbstractCondition
{
    public function getCode(): string
    {
        return 'product_category';
    }

    public function getName(): string
    {
        return __('产品分类');
    }

    public function getDescription(): string
    {
        return __('根据产品分类进行条件判断');
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
            $categoryIds = $product['category_id'] ?? $product['category_ids'] ?? [];
            if (is_string($categoryIds)) {
                $categoryIds = explode(',', $categoryIds);
            }
            if (!is_array($categoryIds)) {
                $categoryIds = [$categoryIds];
            }

            if ($operator === 'in') {
                if (array_intersect($categoryIds, $value)) {
                    return true;
                }
            } else {
                if (!array_intersect($categoryIds, $value)) {
                    return true;
                }
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
                'label' => __('分类'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

