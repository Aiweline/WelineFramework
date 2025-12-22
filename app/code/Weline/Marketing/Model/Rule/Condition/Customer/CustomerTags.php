<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Customer;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 客户标签条件
 * 
 * @package Weline_Marketing
 */
class CustomerTags extends AbstractCondition
{
    public function getCode(): string
    {
        return 'customer_tags';
    }

    public function getName(): string
    {
        return __('客户标签');
    }

    public function getDescription(): string
    {
        return __('根据客户标签进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $attribute = $condition['attribute'] ?? 'tags';
        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $tags = $customer['tags'] ?? [];
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return $this->compare($tags, $operator, $value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'attribute',
                'label' => __('属性'),
                'type' => 'hidden',
                'value' => 'tags',
            ],
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    'in' => __('包含任一标签'),
                    'not_in' => __('不包含任一标签'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('标签'),
                'type' => 'multiselect',
                'required' => true,
            ],
        ];
    }
}

