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
 * 产品库存状态条件
 * 
 * @package Weline_Marketing
 */
class StockStatus extends AbstractCondition
{
    public function getCode(): string
    {
        return 'product_stock_status';
    }

    public function getName(): string
    {
        return __('产品库存状态');
    }

    public function getDescription(): string
    {
        return __('根据产品库存状态进行条件判断');
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

        $operator = $condition['operator'] ?? '==';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        foreach ($products as $product) {
            $stockStatus = $product['stock_status'] ?? $product['in_stock'] ?? null;
            if ($stockStatus !== null && $this->compare($stockStatus, $operator, $value)) {
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
                    '==' => __('等于'),
                    '!=' => __('不等于'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('库存状态'),
                'type' => 'select',
                'options' => [
                    'in_stock' => __('有库存'),
                    'out_of_stock' => __('缺货'),
                ],
                'required' => true,
            ],
        ];
    }
}

