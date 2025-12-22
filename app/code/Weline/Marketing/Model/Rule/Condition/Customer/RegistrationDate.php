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
 * 注册时间条件
 * 
 * @package Weline_Marketing
 */
class RegistrationDate extends AbstractCondition
{
    public function getCode(): string
    {
        return 'registration_date';
    }

    public function getName(): string
    {
        return __('注册时间');
    }

    public function getDescription(): string
    {
        return __('根据客户注册时间进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $operator = $condition['operator'] ?? '>=';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $registrationDate = $customer['created_at'] ?? $customer['registration_date'] ?? null;
        if (!$registrationDate) {
            return false;
        }

        return $this->compare($registrationDate, $operator, $value);
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('注册时间 >='),
                    '<=' => __('注册时间 <='),
                    '==' => __('注册时间 ='),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('日期'),
                'type' => 'date',
                'required' => true,
            ],
        ];
    }
}

