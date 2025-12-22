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
 * 订单商品数量条件
 * 
 * @package Weline_Marketing
 */
class ItemCount extends AbstractCondition
{
    public function getCode(): string
    {
        return 'order_item_count';
    }

    public function getName(): string
    {
        return __('订单商品数量');
    }

    public function getDescription(): string
    {
        return __('根据订单中商品数量进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $items = $context['items'] ?? $context['products'] ?? [];
        $itemCount = count($items);

        $operator = $condition['operator'] ?? '>=';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        return $this->compare($itemCount, $operator, (int)$value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('商品数 >='),
                    '<=' => __('商品数 <='),
                    '>' => __('商品数 >'),
                    '<' => __('商品数 <'),
                    '==' => __('商品数 ='),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('数量'),
                'type' => 'number',
                'required' => true,
            ],
        ];
    }
}

