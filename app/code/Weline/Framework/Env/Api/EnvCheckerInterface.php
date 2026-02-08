<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Api;

use Weline\Framework\Env\Api\Data\EnvCheckResult;
use Weline\Framework\Env\Api\Data\EnvRequirements;

/**
 * 环境检查器接口
 * 
 * @DESC 定义环境检测的统一接口，支持 CLI 与 Web 共用
 */
interface EnvCheckerInterface
{
    /**
     * 设置要检测的环境需求
     *
     * @param EnvRequirements $requirements 合并后的环境需求
     * @return self
     */
    public function setRequirements(EnvRequirements $requirements): self;

    /**
     * 执行环境检测
     *
     * @return EnvCheckResult 检测结果
     */
    public function check(): EnvCheckResult;

    /**
     * 检测指定的 item 是否已满足（通过调用脚本 --check）
     *
     * @param array $item 单个 item 配置
     * @param string $modulePath 模块根路径
     * @return bool 是否已满足
     */
    public function checkItem(array $item, string $modulePath): bool;
}
