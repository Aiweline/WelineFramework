<?php

declare(strict_types=1);

namespace Weline\Agent\Service;

use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Agent\Model\AgentChatMessage;
use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentSkill;
use Weline\Agent\Model\AgentToolCall;
use Weline\Framework\Manager\ObjectManager;

class AgentEngine
{
    public function __construct(
        private readonly AiService $aiService,
        private readonly ChatSessionManager $sessionManager,
        private readonly MemoryService $memoryService,
        private readonly SkillPackageManager $skillManager,
        private readonly PermissionValidator $permissionValidator,
        private readonly ContextBuilder $contextBuilder,
        private readonly AgentToolCall $toolCallModel,
        private readonly ?AiModel $aiModel = null,
    ) {}

    /**
     * @param array $turnOpts 回合级选项：
     *   - model_code：覆盖角色模型
     *   - system_prompt_override：系统提示词覆盖（节点/回合级）
     *   - project：['key','name'] 或 project_id 项目标记
     *   - skill_codes：有效技能码（角色∪插件）
     *   - on_event：callable(string $type, array $data) 运行事件（tool_call/tool_result/chunk）
     *   - message_meta：写入消息行的 role_id/agent_code/model_code/project_id/meta_json
     */
    public function execute(
        string $prompt,
        AgentRole $role,
        ?AgentChatSession $session = null,
        ?callable $streamCallback = null,
        array $turnOpts = []
    ): AgentResult {
        try {
            if ($session === null) {
                $session = $this->sessionManager->createSession($role);
            }

            $messageMeta = is_array($turnOpts['message_meta'] ?? null) ? $turnOpts['message_meta'] : [];
            $this->sessionManager->addMessage(
                $session,
                AgentChatMessage::ROLE_USER,
                $prompt,
                [],
                '',
                '',
                $messageMeta
            );

            $adaptedPrompt = $this->adaptPrompt($prompt, $role, $session);
            $messages = $this->withCurrentPrompt(
                $this->contextBuilder->build($session, $role, 50, 10, $this->contextOpts($turnOpts)),
                $adaptedPrompt
            );

            $result = $this->requestAgentResponse($role, $messages, $turnOpts);
            $maxIterations = 5;
            $iterations = 0;

            while (!empty($result->toolCalls) && $iterations < $maxIterations) {
                $this->emitEvent($turnOpts, 'tool_request', [
                    'tool_calls' => $result->toolCalls,
                ]);
                $this->persistAssistantToolRequest($session, $result);

                $toolResults = $this->processToolCalls($result->toolCalls, $role, $session, $turnOpts);
                if (!$this->hasSuccessfulToolResult($toolResults)) {
                    return AgentResult::failure(
                        'The required tool call could not be completed with the current role skills or permissions.',
                        (string)$role->getData(AgentRole::schema_fields_CODE)
                    );
                }

                $result = $this->submitToolResults($toolResults, $role, $session, $turnOpts);
                $iterations++;
            }

            $result->iterations = $iterations;

            if (!empty($result->toolCalls)) {
                return AgentResult::failure(
                    'The AI tool loop exceeded the maximum iteration count. Please simplify the role skills or prompt.',
                    (string)$role->getData(AgentRole::schema_fields_CODE)
                );
            }

            $this->sessionManager->addMessage(
                $session,
                AgentChatMessage::ROLE_ASSISTANT,
                $result->content,
                [],
                '',
                '',
                $messageMeta
            );

            $this->memoryService->extractAndSave($result, $session);

            if ($streamCallback && $result->content !== '') {
                $streamCallback($result->content);
            }

            return $result;
        } catch (\Throwable $e) {
            return AgentResult::failure(
                $this->formatExecutionError($e),
                (string)$role->getData(AgentRole::schema_fields_CODE)
            );
        }
    }

    public function executeStream(
        string $prompt,
        AgentRole $role,
        ?AgentChatSession $session = null,
        ?callable $streamCallback = null,
        array $turnOpts = []
    ): \Generator {
        $result = $this->execute($prompt, $role, $session, $streamCallback, $turnOpts);

        if (!$result->success) {
            $error = '[ERROR] ' . ($result->error ?: 'Unknown error');
            if ($streamCallback) {
                $streamCallback($error);
            }
            yield $error;
            return;
        }

        if ($result->content !== '') {
            if ($streamCallback) {
                $streamCallback($result->content);
            }
            yield $result->content;
        }
    }

    private function adaptPrompt(string $prompt, AgentRole $role, AgentChatSession $session): string
    {
        $adapterCode = (string)$role->getData(AgentRole::schema_fields_SCENARIO_ADAPTER_CODE);
        if ($adapterCode === '') {
            $adapterCode = 'agent';
        }

        $contextId = $session->getData(AgentChatSession::schema_fields_CONTEXT_ID);
        $memories = $this->memoryService->getRelevantMemories($contextId, 5);

        $adapterParams = [
            'role_id' => $role->getId(),
            'role_name' => $role->getData(AgentRole::schema_fields_NAME),
            'skills' => $role->getSkills(),
            'permissions' => $role->getPermissions(),
            'memory' => array_map(static fn($memory) => [
                'type' => $memory->getData('node_type'),
                'value' => $memory->getData('node_value'),
            ], $memories),
        ];

        try {
            $adapter = w_query('ai', 'getAdapter', ['code' => $adapterCode]);
            if ($adapter && isset($adapter['class_name']) && class_exists($adapter['class_name'])) {
                $adapterInstance = new $adapter['class_name']();
                if (method_exists($adapterInstance, 'adaptPrompt')) {
                    return $adapterInstance->adaptPrompt($prompt, $adapterParams);
                }
            }
        } catch (\Throwable) {
        }

        $agentAdapter = new \Weline\Agent\Adapter\AgentAdapter();
        return $agentAdapter->adaptPrompt($prompt, $adapterParams);
    }

    /** 提取传给 ContextBuilder 的选项子集 */
    private function contextOpts(array $turnOpts): array
    {
        $opts = [];
        foreach (['system_prompt_override', 'project', 'project_id', 'skill_codes', 'knowledge_context'] as $key) {
            if (array_key_exists($key, $turnOpts)) {
                $opts[$key] = $turnOpts[$key];
            }
        }
        return $opts;
    }

    /** 统一运行事件出口（on_event 回调），失败静默不阻断主流程 */
    private function emitEvent(array $turnOpts, string $type, array $data = []): void
    {
        $onEvent = $turnOpts['on_event'] ?? null;
        if (is_callable($onEvent)) {
            try {
                $onEvent($type, $data);
            } catch (\Throwable) {
            }
        }
    }

    private function requestAgentResponse(AgentRole $role, array $messages, array $turnOpts = []): AgentResult
    {
        $modelCode = $this->resolveModelCode($role, $turnOpts);
        $scenarioCode = $this->resolveScenarioCode($role);
        $params = $this->buildProviderParams($role, $messages, $turnOpts);
        $response = $this->aiService->generateStructured('', $modelCode, $scenarioCode, $params);

        $result = new AgentResult();
        $result->success = true;
        $result->content = (string)($response['content'] ?? '');
        $result->toolCalls = is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];
        $result->agentCode = (string)$role->getData(AgentRole::schema_fields_CODE);
        $result->modelCode = $modelCode ?? (string)($response['model'] ?? '');
        $result->messages = [
            'assistant_message' => $response['assistant_message'] ?? null,
            'assistant_content' => $response['assistant_content'] ?? null,
        ];

        return $result;
    }

    private function buildProviderParams(AgentRole $role, array $messages, array $turnOpts = []): array
    {
        $params = $role->getModelConfig();
        $params['messages'] = $messages;
        $params['is_backend'] = true;

        $extraCodes = $turnOpts['skill_codes'] ?? [];
        $tools = $this->skillManager->getToolsForRole(
            $role,
            is_array($extraCodes) ? $extraCodes : []
        );
        if (!empty($tools)) {
            $params['tools'] = $tools;
        }

        return $params;
    }

    private function withCurrentPrompt(array $messages, string $prompt): array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? '') === AgentChatMessage::ROLE_USER) {
                $messages[$index]['content'] = $prompt;
                return $messages;
            }
        }

        $messages[] = [
            'role' => AgentChatMessage::ROLE_USER,
            'content' => $prompt,
        ];

        return $messages;
    }

    private function persistAssistantToolRequest(AgentChatSession $session, AgentResult $result): void
    {
        $assistantMessage = is_array($result->messages['assistant_message'] ?? null)
            ? $result->messages['assistant_message']
            : null;

        $toolCalls = $assistantMessage['tool_calls'] ?? $result->toolCalls;
        if (!is_array($toolCalls) || empty($toolCalls)) {
            $toolCalls = $result->toolCalls;
        }

        $content = (string)($assistantMessage['content'] ?? $result->content);
        $this->sessionManager->addMessage(
            $session,
            AgentChatMessage::ROLE_ASSISTANT,
            $content,
            $toolCalls
        );
    }

    private function resolveModelCode(AgentRole $role, array $turnOpts = []): ?string
    {
        $override = trim((string)($turnOpts['model_code'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        $modelId = (int)$role->getData(AgentRole::schema_fields_MODEL_ID);
        if ($modelId <= 0) {
            return null;
        }

        $modelPrototype = $this->aiModel ?? ObjectManager::getInstance(AiModel::class);
        $model = clone $modelPrototype;
        $model->load($modelId);
        if (!$model->getId()) {
            return null;
        }

        return (string)$model->getData(AiModel::schema_fields_MODEL_CODE);
    }

    private function resolveScenarioCode(AgentRole $role): string
    {
        $scenarioCode = (string)$role->getData(AgentRole::schema_fields_SCENARIO_ADAPTER_CODE);
        return $scenarioCode !== '' ? $scenarioCode : 'agent';
    }

    private function hasSuccessfulToolResult(array $toolResults): bool
    {
        foreach ($toolResults as $toolResult) {
            if (!empty($toolResult['success'])) {
                return true;
            }
        }

        return false;
    }

    private function formatExecutionError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'The AI assistant failed to execute. Please try again later.';
        }

        if (str_contains($message, 'QueryProviderInterface') || str_contains($message, 'executeAgent(') || str_contains($message, 'executeAgentStream')) {
            return 'The AI assistant is not fully configured yet. Please configure an AI model and provider account first.';
        }

        return $message;
    }

    private function processToolCalls(array $toolCalls, AgentRole $role, AgentChatSession $session, array $turnOpts = []): array
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $skillCode = $toolCall['name'] ?? $toolCall['function']['name'] ?? '';
            $arguments = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
            $toolCallId = (string)($toolCall['id'] ?? '');

            $this->emitEvent($turnOpts, 'tool_call', [
                'tool_call_id' => $toolCallId,
                'skill_code' => (string)$skillCode,
                'arguments' => is_string($arguments) ? $arguments : json_encode($arguments, JSON_UNESCAPED_UNICODE),
            ]);

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $callRecord = clone $this->toolCallModel;
            $callRecord->setData(AgentToolCall::schema_fields_SESSION_ID, $session->getId());
            $callRecord->setData(AgentToolCall::schema_fields_SKILL_CODE, $skillCode);
            $callRecord->setData(AgentToolCall::schema_fields_TOOL_CALL_ID, $toolCallId);
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
                $skillModel = $this->skillManager->getSkill($skillCode);
                if (!$skillModel) {
                    $callRecord->markFailed('Skill not found: ' . $skillCode);
                    $toolResult['error'] = 'Skill not found: ' . $skillCode;
                    $toolResults[] = $toolResult;
                    continue;
                }

                if (!$this->permissionValidator->validate($skillCode, $arguments, $role)) {
                    $callRecord->markFailed('Permission denied');
                    $toolResult['error'] = 'Permission denied';
                    $toolResults[] = $toolResult;
                    continue;
                }

                $skillClassName = $skillModel->getData(AgentSkill::schema_fields_CLASS_NAME);
                if (!class_exists($skillClassName)) {
                    $callRecord->markFailed('Skill class not found: ' . $skillClassName);
                    $toolResult['error'] = 'Skill class not found';
                    $toolResults[] = $toolResult;
                    continue;
                }

                /** @var \Weline\Agent\Interface\SkillInterface $skill */
                $skill = new $skillClassName();
                $context = new SkillContext($role, $session);

                $callRecord->markRunning();
                $callRecord->save();

                $result = $skill->execute($arguments, $context);
                $resultData = $result->getData();
                $encodedResult = json_encode($resultData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $callRecord->markSuccess($encodedResult ?: '{}');
                $callRecord->save();

                $this->emitEvent($turnOpts, 'tool_result', [
                    'tool_call_id' => $toolCallId,
                    'skill_code' => (string)$skillCode,
                    'success' => true,
                    'result' => $resultData,
                ]);

                $toolResult['success'] = true;
                $toolResult['result'] = $resultData;
                $toolResults[] = $toolResult;
            } catch (\Throwable $e) {
                $callRecord->markFailed($e->getMessage());
                $callRecord->save();

                $this->emitEvent($turnOpts, 'tool_result', [
                    'tool_call_id' => $toolCallId,
                    'skill_code' => (string)$skillCode,
                    'success' => false,
                    'error' => $e->getMessage(),
                ]);

                $toolResult['error'] = $e->getMessage();
                $toolResults[] = $toolResult;
            }
        }

        return $toolResults;
    }

    private function submitToolResults(array $toolResults, AgentRole $role, AgentChatSession $session, array $turnOpts = []): AgentResult
    {
        foreach ($toolResults as $toolResult) {
            $content = $toolResult['success']
                ? json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode(['error' => $toolResult['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->sessionManager->addMessage(
                $session,
                AgentChatMessage::ROLE_TOOL,
                $content ?: '{}',
                [],
                (string)$toolResult['skill_code'],
                (string)$toolResult['tool_call_id']
            );
        }

        return $this->requestAgentResponse($role, $this->contextBuilder->build($session, $role, 50, 10, $this->contextOpts($turnOpts)), $turnOpts);
    }
}
