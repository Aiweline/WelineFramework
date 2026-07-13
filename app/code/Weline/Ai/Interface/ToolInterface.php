<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Interface;

/**
 * 智能体工具接口
 * 
 * 功能：
 * - 定义 AI 智能体可调用的工具的标准接口
 * - 工具名称、描述、参数定义供 AI function calling 使用
 * - 框架中间格式（与 OpenAI/Anthropic 无关），由 Provider 负责转换
 */
/** @deprecated Implement \Weline\Ai\Api\ToolInterface. */
interface ToolInterface extends \Weline\Ai\Api\ToolInterface
{
    /**
     * 获取工具名称（函数名）
     * 
     * 命名规范：snake_case，如 'preview_reference_component'
     * 
     * @return string
     */
    public function getName(): string;

    /**
     * 获取工具描述（AI 可见）
     * 
     * 描述应简洁清晰，让 AI 理解何时该调用此工具
     * 
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取工具参数定义（JSON Schema 格式）
     * 
     * 返回格式：
     * [
     *     'type' => 'object',
     *     'properties' => [
     *         'param_name' => [
     *             'type' => 'string',
     *             'description' => '参数说明'
     *         ]
     *     ],
     *     'required' => ['param_name']
     * ]
     * 
     * @return array
     */
    public function getParameters(): array;

    /**
     * 执行工具
     * 
     * @param array $args AI 传入的参数（已按 getParameters() 格式解析）
     * @return mixed 执行结果（将被 json_encode 后回传给 AI）
     */
    public function execute(array $args): mixed;

    /**
     * 工具是否启用
     * 
     * @return bool
     */
    public function isEnabled(): bool;
}
