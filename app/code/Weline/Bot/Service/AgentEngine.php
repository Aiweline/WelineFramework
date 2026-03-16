<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Ai\Service\AiService;
use Weline\Ai\Agent\AgentResult;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Model\BotChatMessage;
use Weline\Bot\Model\BotToolCall;
use Weline\Bot\Model\BotSkill;

/**
 * Agent 核心引擎
 *
 * 整合 Weline_Ai 服务，实现 Bot 的核心执行逻辑
 * 
 * 复用 Weline_Ai：
 * - 使用 w_query 获取模型信息
 * - 使用场景适配器增强提示词
 */
class AgentEngine
{
    public function __construct(
        private readonly AiService $aiService,
        private readonly ChatSessionManager $sessionManager,
        private readonly MemoryService $memoryService,
        private readonly SkillPackageManager $skillManager,
        private readonly PermissionValidator $permissionValidator,
        private readonly ContextBuilder $contextBuilder,
        private readonly BotToolCall $toolCallModel,
    ) {}

    /**
     * 执行用户提示词
     *
     * @param string $prompt 用户输入
     * @param BotRole $role 使用的角色
     * @param BotChatSession|null $session 会话（可选，不传则创建新会话）
     * @param callable|null $streamCallback 流式回调
     * @return AgentResult
     */
    public function execute(
        string $prompt,
        BotRole $role,
        ?BotChatSession $session = null,
        ?callable $streamCallback = null
    ): AgentResult {
        try {
            // 1. 确保/创建会话
            if ($session === null) {
                $session = $this->sessionManager->createSession($role);
            }

            // 2. 保存用户消息
            $userMessage = $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_USER,
                $prompt
            );

            // 3. 使用场景适配器适配提示词
            $adaptedPrompt = $this->adaptPrompt($prompt, $role, $session);

            // 4. 构建上下文（历史消息 + 记忆 + 系统提示词）
            $messages = $this->contextBuilder->build($session, $role);

            // 5. 获取角色可用的技能作为 Tools
            $tools = $this->skillManager->getToolsForRole($role);

            // 6. 获取模型 ID 和模型代码
            $modelId = $role->getData(BotRole::schema_fields_MODEL_ID);
            $modelCode = $this->getModelCode($modelId);

            // 7. 调用 AI 服务
            $result = $this->aiService->executeAgent(
                prompt: $adaptedPrompt,
                modelCode: $modelCode,
                tools: $tools,
                messages: $messages,
                systemPrompt: $role->getData(BotRole::schema_fields_SYSTEM_PROMPT),
                streamCallback: $streamCallback
            );

            // 8. 处理 Tool 调用（如果有）并循环执行
            $maxIterations = 5; // 防止无限循环
            $iterations = 0;
            while (!empty($result->toolCalls) && $iterations < $maxIterations) {
                $toolResults = $this->processToolCalls($result->toolCalls, $role, $session);

                // 如果没有成功的工具调用，跳出循环
                if (empty($toolResults)) {
                    break;
                }

                // 将工具结果提交给 AI 进行下一轮处理
                $result = $this->submitToolResults($toolResults, $role, $session, $streamCallback);
                $iterations++;
            }

            // 9. 保存助手回复
            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_ASSISTANT,
                $result->content,
                $result->toolCalls
            );

            // 10. 提取并保存记忆
            $this->memoryService->extractAndSave($result, $session);

            return $result;

        } catch (\Throwable $e) {
            return AgentResult::failure(
                $e->getMessage(),
                $role->getData(BotRole::schema_fields_CODE)
            );
        }
    }

    /**
     * 流式执行
     */
    public function executeStream(
        string $prompt,
        BotRole $role,
        ?BotChatSession $session = null,
        ?callable $streamCallback = null
    ): \Generator {
        // 创建结果对象
        $result = new AgentResult();

        try {
            // 确保会话
            if ($session === null) {
                $session = $this->sessionManager->createSession($role);
            }

            // 保存用户消息
            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_USER,
                $prompt
            );

            // 使用场景适配器适配提示词
            $adaptedPrompt = $this->adaptPrompt($prompt, $role, $session);

            // 构建上下文
            $messages = $this->contextBuilder->build($session, $role);

            // 获取 Tools
            $tools = $this->skillManager->getToolsForRole($role);

            // 获取模型代码
            $modelId = $role->getData(BotRole::schema_fields_MODEL_ID);
            $modelCode = $this->getModelCode($modelId);

            // 流式调用 AI
            $streamGenerator = $this->aiService->executeAgentStream(
                prompt: $adaptedPrompt,
                modelCode: $modelCode,
                tools: $tools,
                messages: $messages,
                systemPrompt: $role->getData(BotRole::schema_fields_SYSTEM_PROMPT)
            );

            $fullContent = '';
            foreach ($streamGenerator as $chunk) {
                if (isset($chunk['content'])) {
                    $fullContent .= $chunk['content'];
                    if ($streamCallback) {
                        $streamCallback($chunk['content']);
                    }
                    yield $chunk['content'];
                }
                if (isset($chunk['tool_calls'])) {
                    $result->toolCalls = $chunk['tool_calls'];
                }
            }

            $result->content = $fullContent;
            $result->success = true;

            // 保存助手回复
            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_ASSISTANT,
                $fullContent,
                $result->toolCalls
            );

            // 保存记忆
            $this->memoryService->extractAndSave($result, $session);

        } catch (\Throwable $e) {
            $result->success = false;
            $result->error = $e->getMessage();
            yield "[ERROR] " . $e->getMessage();
        }
    }

    /**
     * 使用场景适配器适配提示词
     */
    private function adaptPrompt(string $prompt, BotRole $role, BotChatSession $session): string
    {
        $adapterCode = $role->getData(BotRole::schema_fields_SCENARIO_ADAPTER_CODE);
        
        if (empty($adapterCode)) {
            // 使用 Bot 默认适配器
            $adapterCode = 'bot_agent';
        }

        // 获取相关记忆
        $contextId = $session->getData(BotChatSession::schema_fields_CONTEXT_ID);
        $memories = $this->memoryService->getRelevantMemories($contextId, 5);

        // 准备适配器参数
        $adapterParams = [
            'role_id' => $role->getId(),
            'role_name' => $role->getData(BotRole::schema_fields_NAME),
            'skills' => $role->getSkills(),
            'permissions' => $role->getPermissions(),
            'memory' => array_map(fn($m) => [
                'type' => $m->getData('node_type'),
                'value' => $m->getData('node_value'),
            ], $memories),
        ];

        // 通过 w_query 调用适配器
        try {
            $adapter = w_query('ai', 'getAdapter', ['code' => $adapterCode]);
            if ($adapter && isset($adapter['class_name'])) {
                $adapterClass = $adapter['class_name'];
                if (class_exists($adapterClass)) {
                    $adapterInstance = new $adapterClass();
                    if (method_exists($adapterInstance, 'adaptPrompt')) {
                        return $adapterInstance->adaptPrompt($prompt, $adapterParams);
                    }
                }
            }
        } catch (\Throwable) {
            // 适配器调用失败，使用默认适配
        }

        // 回退：使用内置 BotAgentAdapter
        $botAdapter = new \Weline\Bot\Adapter\BotAgentAdapter();
        return $botAdapter->adaptPrompt($prompt, $adapterParams);
    }

    /**
     * 通过 w_query 获取模型代码
     */
    private function getModelCode(?int $modelId): ?string
    {
        if (!$modelId) {
            // 获取默认模型
            $defaultModel = w_query('ai', 'getDefaultModel', []);
            return $defaultModel['model_code'] ?? null;
        }

        try {
            $model = w_query('ai', 'getModel', ['id' => $modelId]);
            return $model['model_code'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 处理 Tool 调用
     *
     * @return array 工具调用结果列表，包含 tool_call_id 和 result
     */
    private function processToolCalls(array $toolCalls, BotRole $role, BotChatSession $session): array
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $skillCode = $toolCall['name'] ?? $toolCall['function']['name'] ?? '';
            $arguments = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
            $toolCallId = $toolCall['id'] ?? '';

            // 解析参数
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            // 创建调用记录
            $callRecord = clone $this->toolCallModel;
            $callRecord->setData(BotToolCall::schema_fields_SESSION_ID, $session->getId());
            $callRecord->setData(BotToolCall::schema_fields_SKILL_CODE, $skillCode);
            $callRecord->setData(BotToolCall::schema_fields_TOOL_CALL_ID, $toolCallId);
            $callRecord->setArguments($arguments);
            $callRecord->save();

            $toolResult = [
                'tool_call_id' => $toolCallId,
                'skill_code' => $skillCode,
                'success' => false,
                'result' => null,
                'error' => null,
            ];

            try {
                // 获取技能模型记录
                $skillModel = $this->skillManager->getSkill($skillCode);
                if (!$skillModel) {
                    $callRecord->markFailed('Skill not found: ' . $skillCode);
                    $toolResult['error'] = 'Skill not found: ' . $skillCode;
                    $toolResults[] = $toolResult;
                    continue;
                }

                // 权限验证
                if (!$this->permissionValidator->validate($skillCode, $arguments, $role)) {
                    $callRecord->markFailed('Permission denied');
                    $toolResult['error'] = 'Permission denied';
                    $toolResults[] = $toolResult;
                    continue;
                }

                // 实例化技能类并执行
                $skillClassName = $skillModel->getData(BotSkill::schema_fields_CLASS_NAME);
                if (!class_exists($skillClassName)) {
                    $callRecord->markFailed('Skill class not found: ' . $skillClassName);
                    $toolResult['error'] = 'Skill class not found';
                    $toolResults[] = $toolResult;
                    continue;
                }

                /** @var \Weline\Bot\Interface\SkillInterface $skill */
                $skill = new $skillClassName();
                $context = new SkillContext($role, $session);

                // 标记开始执行
                $callRecord->markRunning();
                $callRecord->save();

                // 执行技能
                $result = $skill->execute($arguments, $context);

                // 记录结果
                $resultData = $result->getData();
                $callRecord->markSuccess(json_encode($resultData, JSON_UNESCAPED_UNICODE));
                $callRecord->save();

                $toolResult['success'] = true;
                $toolResult['result'] = $resultData;
                $toolResults[] = $toolResult;

            } catch (\Throwable $e) {
                $callRecord->markFailed($e->getMessage());
                $callRecord->save();

                $toolResult['error'] = $e->getMessage();
                $toolResults[] = $toolResult;
            }
        }

        return $toolResults;
    }

    /**
     * 将工具调用结果提交给 AI 进行下一轮处理
     *
     * @param array $toolResults 工具调用结果
     * @param BotRole $role 角色
     * @param BotChatSession $session 会话
     * @param callable|null $streamCallback 流式回调
     * @return AgentResult
     */
    private function submitToolResults(array $toolResults, BotRole $role, BotChatSession $session, ?callable $streamCallback = null): AgentResult
    {
        // 构建工具结果消息
        foreach ($toolResults as $toolResult) {
            $content = $toolResult['success']
                ? json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE)
                : json_encode(['error' => $toolResult['error']], JSON_UNESCAPED_UNICODE);

            // 保存工具结果消息
            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_TOOL,
                $content,
                [],
                $toolResult['skill_code'],
                $toolResult['tool_call_id']
            );
        }

        // 获取更新后的消息历史
        $messages = $this->contextBuilder->build($session, $role);

        // 获取模型
        $modelId = $role->getData(BotRole::schema_fields_MODEL_ID);
        $modelCode = $this->getModelCode($modelId);

        // 重新调用 AI
        return $this->aiService->executeAgent(
            prompt: '',
            modelCode: $modelCode,
            tools: $this->skillManager->getToolsForRole($role),
            messages: $messages,
            systemPrompt: $role->getData(BotRole::schema_fields_SYSTEM_PROMPT),
            streamCallback: $streamCallback
        );
    }
}
