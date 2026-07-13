<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Interface;

/**
 * 场景适配器接口
 * 
 * 功能：
 * - 定义场景适配器的标准接口
 * - 提供场景专用的AI生成优化
 * - 支持提示词模板和参数处理
 * - 统一的适配器管理规范
 */
/** @deprecated Implement \Weline\Ai\Api\ScenarioAdapterInterface. */
interface ScenarioAdapterInterface extends \Weline\Ai\Api\ScenarioAdapterInterface
{
    /**
     * 获取适配器代码
     * 
     * @return string
     */
    public function getCode(): string;

    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * 获取适配器描述
     * 
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取适配器版本
     * 
     * @return string
     */
    public function getVersion(): string;

    /**
     * 获取支持的模型类型
     * 
     * @return array
     */
    public function getSupportedModelTypes(): array;

    /**
     * 适配提示词
     * 
     * @param string $prompt 原始提示词
     * @param array $params 额外参数
     * @return string 适配后的提示词
     */
    public function adaptPrompt(string $prompt, array $params = []): string;

    /**
     * 处理响应
     * 
     * @param string $response 原始响应
     * @param array $params 额外参数
     * @return string 处理后的响应
     */
    public function processResponse(string $response, array $params = []): string;

    /**
     * 验证输入参数
     * 
     * @param array $params
     * @return array 验证错误列表，空数组表示验证通过
     */
    public function validateParams(array $params = []): array;

    /**
     * 获取参数模板
     * 
     * @return array
     */
    public function getParamTemplate(): array;

    /**
     * 获取使用示例
     * 
     * @return array
     */
    public function getExamples(): array;

    /**
     * 检查是否支持指定模型
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supportsModel(string $modelCode): bool;
}
