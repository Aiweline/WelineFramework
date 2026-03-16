<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotChatSession;

/**
 * 上下文构建器
 *
 * 构建发送给 AI 的完整上下文，包括：
 * - 系统提示词
 * - 历史消息
 * - 相关记忆
 */
class ContextBuilder
{
    public function __construct(
        private readonly ChatSessionManager $sessionManager,
        private readonly MemoryService $memoryService,
    ) {}

    /**
     * 构建完整上下文
     *
     * @param BotChatSession $session
     * @param BotRole $role
     * @param int $maxHistoryMessages 最大历史消息数
     * @param int $maxMemoryItems 最大记忆条目数
     * @return array OpenAI 消息格式数组
     */
    public function build(
        BotChatSession $session,
        BotRole $role,
        int $maxHistoryMessages = 50,
        int $maxMemoryItems = 10
    ): array {
        $messages = [];

        // 1. 系统提示词
        $systemPrompt = $this->buildSystemPrompt($role);
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // 2. 注入相关记忆
        $memories = $this->memoryService->getRelevantMemories(
            $session->getData(BotChatSession::schema_fields_CONTEXT_ID),
            $maxMemoryItems
        );

        if (!empty($memories)) {
            $memoryContext = $this->formatMemories($memories);
            $messages[] = [
                'role' => 'system',
                'content' => $memoryContext,
            ];
        }

        // 3. 历史消息
        $historyMessages = $this->sessionManager->getMessagesForOpenAI($session, $maxHistoryMessages);

        // 过滤掉系统消息（已经在前面添加了）
        $historyMessages = array_filter($historyMessages, function ($msg) {
            return $msg['role'] !== 'system';
        });

        foreach ($historyMessages as $msg) {
            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * 构建系统提示词
     */
    private function buildSystemPrompt(BotRole $role): string
    {
        $basePrompt = $role->getData(BotRole::schema_fields_SYSTEM_PROMPT) ?: '';
        $roleName = $role->getData(BotRole::schema_fields_NAME);
        $skills = $role->getSkills();

        // 构建技能说明
        $skillDescriptions = [];
        if (!empty($skills)) {
            $skillDescriptions[] = "\n\n可用技能：";
            foreach ($skills as $skillCode) {
                // TODO: 获取技能详情
                $skillDescriptions[] = "- {$skillCode}";
            }
        }

        // 组装完整提示词
        $fullPrompt = "你是 {$roleName}。\n\n{$basePrompt}";

        if (!empty($skillDescriptions)) {
            $fullPrompt .= implode("\n", $skillDescriptions);
        }

        // 添加安全提示
        $fullPrompt .= "\n\n重要提醒：\n";
        $fullPrompt .= "- 对于危险操作（如删除文件、执行命令），必须先询问用户确认\n";
        $fullPrompt .= "- 不要泄露敏感信息（如 API Key、密码）\n";
        $fullPrompt .= "- 遇到无法完成的任务，请诚实告知用户\n";

        return $fullPrompt;
    }

    /**
     * 格式化记忆为上下文
     */
    private function formatMemories(array $memories): string
    {
        $lines = ["以下是与当前对话相关的信息，请在回答时参考："];

        foreach ($memories as $memory) {
            $type = $memory->getData('node_type');
            $value = $memory->getData('node_value');

            $typeLabels = [
                'fact' => '事实',
                'preference' => '用户偏好',
                'entity' => '实体',
                'event' => '事件',
            ];

            $typeLabel = $typeLabels[$type] ?? $type;
            $lines[] = "- [{$typeLabel}] {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * 构建精简上下文（用于 Token 受限场景）
     */
    public function buildCompact(
        BotChatSession $session,
        BotRole $role,
        int $maxTokens = 4000
    ): array {
        // 估算每条消息的平均 Token 数
        $avgTokensPerMessage = 100;
        $maxMessages = (int) ($maxTokens / $avgTokensPerMessage);

        // 系统提示词
        $systemPrompt = $this->buildCompactSystemPrompt($role);
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        // 仅保留最近的几条消息
        $historyMessages = $this->sessionManager->getMessagesForOpenAI(
            $session,
            min($maxMessages, 10)
        );

        foreach ($historyMessages as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = $msg;
            }
        }

        return $messages;
    }

    /**
     * 构建精简版系统提示词
     */
    private function buildCompactSystemPrompt(BotRole $role): string
    {
        $name = $role->getData(BotRole::schema_fields_NAME);
        $prompt = $role->getData(BotRole::schema_fields_SYSTEM_PROMPT);

        // 截取前 500 字符
        if (strlen($prompt) > 500) {
            $prompt = mb_substr($prompt, 0, 500) . '...';
        }

        return "你是 {$name}。{$prompt}";
    }
}
