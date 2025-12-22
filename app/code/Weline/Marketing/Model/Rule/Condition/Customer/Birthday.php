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
 * 生日条件
 * 
 * @package Weline_Marketing
 */
class Birthday extends AbstractCondition
{
    public function getCode(): string
    {
        return 'birthday';
    }

    public function getName(): string
    {
        return __('生日');
    }

    public function getDescription(): string
    {
        return __('根据客户生日进行条件判断（支持生日月、生日周、生日当天）');
    }

    public function validate(array $condition, array $context): bool
    {
        $customer = $context['customer'] ?? null;
        if (!$customer) {
            return false;
        }

        $birthday = $customer['birthday'] ?? $customer['birth_date'] ?? null;
        if (!$birthday) {
            return false;
        }

        $type = $condition['type'] ?? 'month'; // month, week, day
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $birthdayTimestamp = is_numeric($birthday) ? $birthday : strtotime($birthday);
        $now = time();

        switch ($type) {
            case 'month':
                // 生日月
                $birthMonth = (int)date('m', $birthdayTimestamp);
                $currentMonth = (int)date('m', $now);
                return $birthMonth === $currentMonth;
            case 'week':
                // 生日周（生日前后3天）
                $birthDayOfYear = (int)date('z', $birthdayTimestamp);
                $currentDayOfYear = (int)date('z', $now);
                $diff = abs($currentDayOfYear - $birthDayOfYear);
                return $diff <= 3 || $diff >= 362; // 跨年情况
            case 'day':
                // 生日当天
                $birthMonthDay = date('m-d', $birthdayTimestamp);
                $currentMonthDay = date('m-d', $now);
                return $birthMonthDay === $currentMonthDay;
            default:
                return false;
        }
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'type',
                'label' => __('类型'),
                'type' => 'select',
                'options' => [
                    'month' => __('生日月'),
                    'week' => __('生日周（前后3天）'),
                    'day' => __('生日当天'),
                ],
                'required' => true,
            ],
        ];
    }
}

