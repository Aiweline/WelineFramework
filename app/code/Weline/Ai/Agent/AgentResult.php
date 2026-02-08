<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Agent;

/**
 * 智能体执行结果封装
 * 
 * 封装智能体执行的完整结果，包括最终内容、工具调用记录、迭代轮次和消息历史
 */
class AgentResult
{
    /**
     * 最终生成的文本内容
     */
    public string $content = '';

    /**
     * 工具调用记录
     * 
     * 格式：[['name' => '...', 'arguments' => [...], 'result' => '...'], ...]
     */
    public array $toolCalls = [];

    /**
     * Tool 调用循环轮次
     */
    public int $iterations = 0;

    /**
     * 完整消息历史（用于继续对话或调试）
     */
    public array $messages = [];

    /**
     * 执行是否成功
     */
    public bool $success = true;

    /**
     * 错误信息（失败时）
     */
    public ?string $error = null;

    /**
     * 智能体代码
     */
    public string $agentCode = '';

    /**
     * 使用的模型代码
     */
    public string $modelCode = '';

    public function __construct(
        string $content = '',
        array $toolCalls = [],
        int $iterations = 0,
        array $messages = [],
        bool $success = true,
        ?string $error = null,
        string $agentCode = '',
        string $modelCode = ''
    ) {
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->iterations = $iterations;
        $this->messages = $messages;
        $this->success = $success;
        $this->error = $error;
        $this->agentCode = $agentCode;
        $this->modelCode = $modelCode;
    }

    /**
     * 转为数组
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'iterations' => $this->iterations,
            'success' => $this->success,
            'error' => $this->error,
            'agent_code' => $this->agentCode,
            'model_code' => $this->modelCode,
        ];
    }

    /**
     * 创建失败结果
     */
    public static function failure(string $error, string $agentCode = ''): self
    {
        return new self(
            content: '',
            success: false,
            error: $error,
            agentCode: $agentCode
        );
    }
}
