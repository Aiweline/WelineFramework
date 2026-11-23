<?php
declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Agent\Model\AgentChatMessage;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentProject;
use Weline\Framework\Manager\ObjectManager;

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
     * @param AgentChatSession $session
     * @param AgentRole $role
     * @param int $maxHistoryMessages 最大历史消息数
     * @param int $maxMemoryItems 最大记忆条目数
     * @return array OpenAI 消息格式数组
     */
    public function build(
        AgentChatSession $session,
        AgentRole $role,
        int $maxHistoryMessages = 50,
        int $maxMemoryItems = 10,
        array $opts = []
    ): array {
        $messages = [];

        // 1. 系统提示词（节点/回合级覆盖优先于角色默认）
        $systemPrompt = $this->buildSystemPrompt($role, $opts);
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // 2. 注入相关记忆
        $memories = $this->memoryService->getRelevantMemories(
            $session->getData(AgentChatSession::schema_fields_CONTEXT_ID),
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

        // 过滤掉系统消息（已经在前面添加了）+ 跨角色历史净化
        $historyMessages = array_filter($historyMessages, function ($msg) {
            return $msg['role'] !== 'system';
        });
        $historyMessages = $this->sanitizeCrossRoleHistory(array_values($historyMessages), $role);

        foreach ($historyMessages as $msg) {
            $messages[] = $msg;
        }

        return $messages;
    }

    /**
     * 跨角色历史净化
     *
     * 同一会话内不同角色的回合共享历史；为避免角色提示词/记忆串扰：
     * - assistant 消息保留（内容本身是共享上下文）
     * - 孤立的 tool/tool_call_id 消息（其发起 assistant 消息已不在窗口内）降级为 system 摘要
     * - user 消息原样保留
     *
     * @param list<array<string,mixed>> $history
     * @return list<array<string,mixed>>
     */
    private function sanitizeCrossRoleHistory(array $history, AgentRole $role): array
    {
        $validToolCallIds = [];
        foreach ($history as $msg) {
            $toolCalls = $msg['tool_calls'] ?? null;
            if (is_array($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    $id = $tc['id'] ?? ($tc['tool_call_id'] ?? '');
                    if ($id !== '') {
                        $validToolCallIds[(string)$id] = true;
                    }
                }
            }
        }

        $result = [];
        foreach ($history as $msg) {
            $msgRole = (string)($msg['role'] ?? '');
            if ($msgRole === AgentChatMessage::ROLE_TOOL) {
                $callId = (string)($msg['tool_call_id'] ?? '');
                if ($callId === '' || !isset($validToolCallIds[$callId])) {
                    // 孤立工具结果：压缩为一行 system 摘要，避免污染上下文
                    $result[] = [
                        'role' => 'system',
                        'content' => '[历史工具结果摘要] ' . mb_substr((string)($msg['content'] ?? ''), 0, 200),
                    ];
                    continue;
                }
            }
            $result[] = $msg;
        }

        return $result;
    }

    /**
     * 构建系统提示词
     *
     * $opts 支持：
     * - system_prompt_override：节点/回合级系统提示词覆盖（非空时代替角色默认提示词主体）
     * - project：['key'=>..., 'name'=>...] 项目标记，注入 [PROJECT:key name]
     * - skill_codes：有效技能码列表（角色∪插件），缺省用角色自身技能
     */
    private function buildSystemPrompt(AgentRole $role, array $opts = []): string
    {
        $override = trim((string)($opts['system_prompt_override'] ?? ''));
        $basePrompt = $override !== ''
            ? $override
            : ((string)$role->getData(AgentRole::schema_fields_SYSTEM_PROMPT) ?: '');
        $roleName = $role->getData(AgentRole::schema_fields_NAME);
        $skills = $opts['skill_codes'] ?? $role->getSkills();
        if (!is_array($skills)) {
            $skills = [];
        }

        // 项目标记（团队边界上下文）
        $projectMarker = '';
        $project = $opts['project'] ?? null;
        if (is_array($project)) {
            $key = trim((string)($project['key'] ?? ''));
            $name = trim((string)($project['name'] ?? ''));
            if ($key !== '') {
                $projectMarker = "\n\n[PROJECT:{$key}" . ($name !== '' ? " {$name}" : '') . ']';
            }
        } elseif (($projectId = (int)($opts['project_id'] ?? 0)) > 0) {
            $projectMarker = $this->loadProjectMarker($projectId);
        }

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
        $fullPrompt = "你是 {$roleName}。\n\n{$basePrompt}{$projectMarker}";

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
     * 按 project_id 加载项目标记（会话未显式传 project 数组时兜底）
     */
    private function loadProjectMarker(int $projectId): string
    {
        try {
            $prototype = ObjectManager::getInstance(AgentProject::class);
            $project = clone $prototype;
            $project->load($projectId);
            if (!$project->getId()) {
                return '';
            }
            $key = (string)$project->getData(AgentProject::schema_fields_PROJECT_KEY);
            $name = (string)$project->getData(AgentProject::schema_fields_NAME);
            if ($key === '') {
                return '';
            }
            return "\n\n[PROJECT:{$key}" . ($name !== '' ? " {$name}" : '') . ']';
        } catch (\Throwable) {
            return '';
        }
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
        AgentChatSession $session,
        AgentRole $role,
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
    private function buildCompactSystemPrompt(AgentRole $role): string
    {
        $name = $role->getData(AgentRole::schema_fields_NAME);
        $prompt = $role->getData(AgentRole::schema_fields_SYSTEM_PROMPT);

        // 截取前 500 字符
        if (strlen($prompt) > 500) {
            $prompt = mb_substr($prompt, 0, 500) . '...';
        }

        return "你是 {$name}。{$prompt}";
    }
}
