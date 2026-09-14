<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Time;

use Weline\Framework\DateTime\Timezone;
use Weline\Marketing\Model\Rule\Condition\AbstractCondition;

/**
 * 日期范围条件（库内 UTC 窗；录入经 Framework Timezone）
 *
 * @package Weline_Marketing
 */
class DateRange extends AbstractCondition
{
    public function getCode(): string
    {
        return 'date_range';
    }

    public function getName(): string
    {
        return __('日期范围');
    }

    public function getDescription(): string
    {
        return __('根据日期范围进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $startDate = $condition['start_date'] ?? null;
        $endDate = $condition['end_date'] ?? null;

        if ($startDate === null && $endDate === null) {
            return false;
        }

        return Timezone::isWithinUtcWindow(
            $startDate !== null && $startDate !== '' ? (string)$startDate : null,
            $endDate !== null && $endDate !== '' ? (string)$endDate : null,
        );
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'start_date',
                'label' => __('开始日期'),
                'type' => 'datetime',
                'required' => false,
            ],
            [
                'name' => 'end_date',
                'label' => __('结束日期'),
                'type' => 'datetime',
                'required' => false,
            ],
        ];
    }
}
