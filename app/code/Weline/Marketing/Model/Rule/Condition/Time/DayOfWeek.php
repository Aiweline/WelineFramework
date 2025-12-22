<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Time;

use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 星期几条件
 * 
 * @package Weline_Marketing
 */
class DayOfWeek extends AbstractCondition
{
    public function getCode(): string
    {
        return 'day_of_week';
    }

    public function getName(): string
    {
        return __('星期几');
    }

    public function getDescription(): string
    {
        return __('根据星期几进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $operator = $condition['operator'] ?? 'in';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $dayOfWeek = (int)date('w'); // 0-6, 0=Sunday

        return $this->compare($dayOfWeek, $operator, $value);
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
                'label' => __('星期'),
                'type' => 'multiselect',
                'options' => [
                    '0' => __('星期日'),
                    '1' => __('星期一'),
                    '2' => __('星期二'),
                    '3' => __('星期三'),
                    '4' => __('星期四'),
                    '5' => __('星期五'),
                    '6' => __('星期六'),
                ],
                'required' => true,
            ],
        ];
    }
}

