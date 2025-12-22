<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition;

use Weline\Marketing\Interface\Rule\ConditionInterface;

/**
 * 条件抽象基类
 * 
 * 提供条件类的通用功能
 * 
 * @package Weline_Marketing
 */
abstract class AbstractCondition implements ConditionInterface
{
    /**
     * 获取条件描述
     *
     * @return string
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * 获取条件配置表单字段
     *
     * @return array
     */
    public function getFormFields(): array
    {
        return [];
    }

    /**
     * 比较值（支持多种操作符）
     *
     * @param mixed $value 实际值
     * @param string $operator 操作符：==, !=, >, >=, <, <=, in, not_in, contains, not_contains
     * @param mixed $expected 期望值
     * @return bool
     */
    protected function compare($value, string $operator, $expected): bool
    {
        switch ($operator) {
            case '==':
            case '=':
                return $value == $expected;
            case '!=':
            case '<>':
                return $value != $expected;
            case '>':
                return $value > $expected;
            case '>=':
                return $value >= $expected;
            case '<':
                return $value < $expected;
            case '<=':
                return $value <= $expected;
            case 'in':
                if (!is_array($expected)) {
                    $expected = [$expected];
                }
                return in_array($value, $expected);
            case 'not_in':
                if (!is_array($expected)) {
                    $expected = [$expected];
                }
                return !in_array($value, $expected);
            case 'contains':
                if (is_array($value)) {
                    return in_array($expected, $value);
                }
                return strpos((string)$value, (string)$expected) !== false;
            case 'not_contains':
                if (is_array($value)) {
                    return !in_array($expected, $value);
                }
                return strpos((string)$value, (string)$expected) === false;
            default:
                return false;
        }
    }
}

