<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Api;

use Weline\Framework\Env\Api\Data\ExecutionResult;

/**
 * 安装脚本执行器接口
 * 
 * @DESC 定义安装脚本执行的统一接口，支持 --check 和 --install 两种动作
 */
interface InstallScriptExecutorInterface
{
    /** 动作：检查是否已安装 */
    public const ACTION_CHECK = 'check';

    /** 动作：执行安装 */
    public const ACTION_INSTALL = 'install';

    /**
     * 执行安装脚本
     *
     * @param string $modulePath 模块根路径
     * @param array $item 当前 item 配置（含 script_linux, script_windows, name, description 等）
     * @param string $envDir env 目录路径（通常为 $modulePath/env/）
     * @param string $action 动作：check 或 install
     * @return ExecutionResult 执行结果
     */
    public function execute(string $modulePath, array $item, string $envDir, string $action): ExecutionResult;

    /**
     * 执行 env/script/ 目录下的所有脚本
     *
     * @param string $modulePath 模块根路径
     * @param string $envDir env 目录路径
     * @param string $action 动作：check 或 install
     * @return ExecutionResult[] 执行结果列表
     */
    public function executeAllScripts(string $modulePath, string $envDir, string $action): array;

    /**
     * 获取当前执行器支持的操作系统
     *
     * @return string 如 'Linux', 'Windows', 'Darwin'
     */
    public function getSupportedOs(): string;

    /**
     * 检测当前系统是否支持
     *
     * @return bool
     */
    public function isSupported(): bool;
}
