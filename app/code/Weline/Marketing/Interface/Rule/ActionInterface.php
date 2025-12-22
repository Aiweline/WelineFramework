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
 * 动作接口
 * 
 * 所有动作类必须实现此接口
 * 
 * @package Weline_Marketing
 */
interface ActionInterface
{
    /**
     * 获取动作代码（唯一标识）
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * 获取动作名称（显示名称）
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取动作描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 执行动作
     *
     * @param array $action 动作配置
     * @param array $context 上下文数据（包含客户、订单、产品等信息）
     * @return array 返回执行结果，包含折扣金额、赠品等信息
     */
    public function execute(array $action, array $context): array;

    /**
     * 获取动作配置表单字段
     *
     * @return array 返回字段配置数组
     */
    public function getFormFields(): array;
}

