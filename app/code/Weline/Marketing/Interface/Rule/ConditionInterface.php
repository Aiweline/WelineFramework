<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Interface\Rule;

/**
 * 条件接口
 * 
 * 所有条件类必须实现此接口
 * 
 * @package Weline_Marketing
 */
interface ConditionInterface
{
    /**
     * 获取条件代码（唯一标识）
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * 获取条件名称（显示名称）
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取条件描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 验证条件是否满足
     *
     * @param array $condition 条件配置
     * @param array $context 上下文数据（包含客户、订单、产品等信息）
     * @return bool
     */
    public function validate(array $condition, array $context): bool;

    /**
     * 获取条件配置表单字段
     *
     * @return array 返回字段配置数组
     */
    public function getFormFields(): array;
}

