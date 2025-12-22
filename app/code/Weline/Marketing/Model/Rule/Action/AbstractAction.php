<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Action;

use Weline\Marketing\Interface\Rule\ActionInterface;

/**
 * 动作抽象基类
 * 
 * 提供动作类的通用功能
 * 
 * @package Weline_Marketing
 */
abstract class AbstractAction implements ActionInterface
{
    /**
     * 获取动作描述
     *
     * @return string
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * 获取动作配置表单字段
     *
     * @return array
     */
    public function getFormFields(): array
    {
        return [];
    }

    /**
     * 计算折扣金额
     *
     * @param float $amount 原始金额
     * @param string $type 折扣类型：percentage百分比, fixed_amount固定金额
     * @param float $value 折扣值
     * @param float|null $maxDiscount 最大折扣金额（仅百分比类型）
     * @return float
     */
    protected function calculateDiscount(float $amount, string $type, float $value, ?float $maxDiscount = null): float
    {
        if ($type === 'percentage') {
            $discount = $amount * ($value / 100);
            if ($maxDiscount !== null && $discount > $maxDiscount) {
                $discount = $maxDiscount;
            }
            return round($discount, 2);
        } elseif ($type === 'fixed_amount') {
            return min($value, $amount);
        }
        return 0;
    }
}

