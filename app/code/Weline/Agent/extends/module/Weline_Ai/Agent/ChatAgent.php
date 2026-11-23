<?php
declare(strict_types=1);

namespace Weline\Agent\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentModelExecutorInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\ToolInterface;

/**
 * 通用对话智能体（角色 leader 任务型委托目标）
 *
 * 当角色绑定 agent_code 且显式走 task-agent 委托路径时，
 * 由本 Agent 承接：单轮/多轮 LLM 对话，支持 history_messages
 * 与系统提示词注入，经 AgentModelExecutor 走统一 Provider 通道。
 * 不带工具循环——工具能力仍由 Weline_Agent AgentEngine 路径提供。
 */
class ChatAgent implements AgentInterface
{
    public function getCode(): string
    {
        return 'chat_agent';
    }

    public function getName(): string
    {
        return __('通用对话智能体');
    }

    public function getDescription(): string
    {
        return __('角色 leader 的默认任务型委托目标：带会话历史的通用 LLM 对话。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['chat', 'assistant', 'general'];
    }

    /** @return list<ToolInterface> */
    public function getTools(): array
    {
        return [];
    }

    public function getSystemPrompt(array $context = []): string
    {
        $override = trim((string)($context['system_prompt'] ?? ''));
        if ($override !== '') {
            return $override;
        }
        $roleName = trim((string)($context['role_name'] ?? ''));
        return $roleName !== ''
            ? "You are {$roleName}, a helpful assistant."
            : 'You are a helpful assistant.';
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return 1;
    }

    /**
     * @param array<string,mixed> $params 支持键：
     *   - model_executor：AgentModelExecutorInterface（AiService 注入）
     *   - history_messages：list<array{role,content}> 开放历史
     *   - system_prompt：覆盖系统提示词
     *   - role_name：角色名（拼入默认系统提示词）
     * @param callable(string,array<string,mixed>):mixed|null $streamCallback
     */
    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $executor = $params['model_executor'] ?? null;
        if (!$executor instanceof AgentModelExecutorInterface) {
            return AgentResult::failure('model_executor is not available', $this->getCode());
        }

        $messages = [];
        $messages[] = [
            'role' => 'system',
            'content' => $this->getSystemPrompt($params),
        ];

        $history = $params['history_messages'] ?? [];
        if (is_array($history)) {
            foreach ($history as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $role = (string)($msg['role'] ?? '');
                $content = (string)($msg['content'] ?? '');
                if (!in_array($role, ['user', 'assistant', 'system'], true) || $content === '') {
                    continue;
                }
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $genParams = $params['generation'] ?? [];
        if (!is_array($genParams)) {
            $genParams = [];
        }

        try {
            $response = $executor->generate($model, $messages, $genParams, $streamCallback);
        } catch (\Throwable $e) {
            return AgentResult::failure(
                'AI 调用失败：' . $e->getMessage(),
                $this->getCode()
            );
        }

        $content = (string)($response['content'] ?? '');
        if ($content === '' && $streamCallback === null) {
            return AgentResult::failure('AI 返回空响应', $this->getCode());
        }

        return new AgentResult(
            content: $content,
            toolCalls: [],
            iterations: 1,
            messages: $messages,
            success: true,
            agentCode: $this->getCode(),
            modelCode: (string)$model->getModelCode(),
        );
    }
}
