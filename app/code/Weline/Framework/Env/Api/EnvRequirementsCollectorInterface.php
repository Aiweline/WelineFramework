<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Api;

use Weline\Framework\Env\Api\Data\EnvRequirements;

/**
 * 环境需求收集器接口
 * 
 * @DESC 定义从 Composer、InstallData、模块 env/ 收集环境需求的统一接口
 */
interface EnvRequirementsCollectorInterface
{
    /**
     * 收集所有环境需求
     *
     * @param bool $includeDisabled 是否包含已禁用的模块（默认 false，仅已启用模块）
     * @return EnvRequirements 合并后的环境需求
     */
    public function collect(bool $includeDisabled = false): EnvRequirements;

    /**
     * 从 Composer 收集（项目根 + 已启用模块的 composer.json）
     *
     * @return EnvRequirements
     */
    public function collectFromComposer(): EnvRequirements;

    /**
     * 从 InstallData 收集（框架默认 env 需求）
     *
     * @return EnvRequirements
     */
    public function collectFromInstallData(): EnvRequirements;

    /**
     * 从指定模块的 env/requirements.php 收集
     *
     * @param string $moduleName 模块名称
     * @param string $modulePath 模块根路径
     * @return EnvRequirements
     */
    public function collectFromModule(string $moduleName, string $modulePath): EnvRequirements;

    /**
     * 从所有已启用模块收集
     *
     * @return EnvRequirements
     */
    public function collectFromAllModules(): EnvRequirements;
}
