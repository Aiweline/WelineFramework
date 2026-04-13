<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Interface;

use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Model\AiModel;

/**
 * 智能体接口
 * 
 * 功能：
 * - 定义智能体的标准接口
 * - 支持 Tool 调用编排循环（plan → act → observe）
 * - 支持流式输出和 SSE 事件推送
 * - 每个智能体自行管理 Tool 调用循环
 */
interface AgentInterface
{
    /**
     * 获取智能体唯一标识码
     * 
     * @return string 如 'pagebuilder_component'
     */
    public function getCode(): string;

    /**
     * 获取智能体显示名称
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * 获取智能体描述（擅长领域）
     * 
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取智能体版本
     * 
     * @return string
     */
    public function getVersion(): string;

    /**
     * 获取支持的场景码列表
     * 
     * @return array<string> 如 ['pagebuilder_component_generation']
     */
    public function getScenarios(): array;

    /**
     * 获取智能体拥有的工具列表
     * 
     * @return ToolInterface[]
     */
    public function getTools(): array;

    /**
     * 获取系统提示词（静态规约部分）
     * 
     * @param array $context 上下文参数（如区域、风格等）
     * @return string
     */
    public function getSystemPrompt(array $context = []): string;

    /**
     * 执行智能体任务
     * 
     * 智能体自行管理 Tool 调用的编排循环：
     * 1. 构建 messages（system prompt + user prompt + tools）
     * 2. 调用 Provider 生成
     * 3. 如果返回 tool_calls，执行 Tool 并将结果回传
     * 4. 循环直到无 tool_call 或达到最大轮次
     * 
     * @param string $prompt 用户提示词
     * @param AiModel $model AI 模型
     * @param array $params 额外参数
     * @param callable|null $streamCallback SSE 事件回调，签名：function(string $eventType, array $data): bool|void
     *   事件类型：'chunk'（文本片段）、'tool_call'（调用工具）、'tool_result'（工具结果）、'iteration'（循环轮次）
     * @return AgentResult
     */
    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult;

    /**
     * 检查是否支持指定模型
     * 
     * @param string $modelCode
     * @return bool
     */
    public function supportsModel(string $modelCode): bool;

    /**
     * 获取 Tool 调用最大轮次（安全阀）
     * 
     * @return int
     */
    public function getMaxIterations(): int;
}
