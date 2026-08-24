<?php

declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;
use Weline\Bot\Model\BotChatMessage;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;
use Weline\Bot\Model\BotToolCall;

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
        private readonly ?AiModel $aiModel = null,
    ) {}

    public function execute(
        string $prompt,
        BotRole $role,
        ?BotChatSession $session = null,
        ?callable $streamCallback = null
    ): AgentResult {
        try {
            if ($session === null) {
                $session = $this->sessionManager->createSession($role);
            }

            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_USER,
                $prompt
            );

            $adaptedPrompt = $this->adaptPrompt($prompt, $role, $session);
            $messages = $this->withCurrentPrompt(
                $this->contextBuilder->build($session, $role),
                $adaptedPrompt
            );

            $result = $this->requestAgentResponse($role, $messages);
            $maxIterations = 5;
            $iterations = 0;

            while (!empty($result->toolCalls) && $iterations < $maxIterations) {
                $this->persistAssistantToolRequest($session, $result);

                $toolResults = $this->processToolCalls($result->toolCalls, $role, $session);
                if (!$this->hasSuccessfulToolResult($toolResults)) {
                    return AgentResult::failure(
                        'The required tool call could not be completed with the current role skills or permissions.',
                        (string)$role->getData(BotRole::schema_fields_CODE)
                    );
                }

                $result = $this->submitToolResults($toolResults, $role, $session);
                $iterations++;
            }

            $result->iterations = $iterations;

            if (!empty($result->toolCalls)) {
                return AgentResult::failure(
                    'The AI tool loop exceeded the maximum iteration count. Please simplify the role skills or prompt.',
                    (string)$role->getData(BotRole::schema_fields_CODE)
                );
            }

            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_ASSISTANT,
                $result->content,
                []
            );

            $this->memoryService->extractAndSave($result, $session);

            if ($streamCallback && $result->content !== '') {
                $streamCallback($result->content);
            }

            return $result;
        } catch (\Throwable $e) {
            return AgentResult::failure(
                $this->formatExecutionError($e),
                (string)$role->getData(BotRole::schema_fields_CODE)
            );
        }
    }

    public function executeStream(
        string $prompt,
        BotRole $role,
        ?BotChatSession $session = null,
        ?callable $streamCallback = null
    ): \Generator {
        $result = $this->execute($prompt, $role, $session);

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

    private function adaptPrompt(string $prompt, BotRole $role, BotChatSession $session): string
    {
        $adapterCode = (string)$role->getData(BotRole::schema_fields_SCENARIO_ADAPTER_CODE);
        if ($adapterCode === '') {
            $adapterCode = 'bot_agent';
        }

        $contextId = $session->getData(BotChatSession::schema_fields_CONTEXT_ID);
        $memories = $this->memoryService->getRelevantMemories($contextId, 5);

        $adapterParams = [
            'role_id' => $role->getId(),
            'role_name' => $role->getData(BotRole::schema_fields_NAME),
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

        $botAdapter = new \Weline\Bot\Adapter\BotAgentAdapter();
        return $botAdapter->adaptPrompt($prompt, $adapterParams);
    }

    private function requestAgentResponse(BotRole $role, array $messages): AgentResult
    {
        $modelCode = $this->resolveModelCode($role);
        $scenarioCode = $this->resolveScenarioCode($role);
        $params = $this->buildProviderParams($role, $messages);
        $response = $this->aiService->generateStructured('', $modelCode, $scenarioCode, $params);

        $result = new AgentResult();
        $result->success = true;
        $result->content = (string)($response['content'] ?? '');
        $result->toolCalls = is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];
        $result->agentCode = (string)$role->getData(BotRole::schema_fields_CODE);
        $result->modelCode = $modelCode ?? (string)($response['model'] ?? '');
        $result->messages = [
            'assistant_message' => $response['assistant_message'] ?? null,
            'assistant_content' => $response['assistant_content'] ?? null,
        ];

        return $result;
    }

    private function buildProviderParams(BotRole $role, array $messages): array
    {
        $params = $role->getModelConfig();
        $params['messages'] = $messages;
        $params['is_backend'] = true;

        $tools = $this->skillManager->getToolsForRole($role);
        if (!empty($tools)) {
            $params['tools'] = $tools;
        }

        return $params;
    }

    private function withCurrentPrompt(array $messages, string $prompt): array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? '') === BotChatMessage::ROLE_USER) {
                $messages[$index]['content'] = $prompt;
                return $messages;
            }
        }

        $messages[] = [
            'role' => BotChatMessage::ROLE_USER,
            'content' => $prompt,
        ];

        return $messages;
    }

    private function persistAssistantToolRequest(BotChatSession $session, AgentResult $result): void
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
            BotChatMessage::ROLE_ASSISTANT,
            $content,
            $toolCalls
        );
    }

    private function resolveModelCode(BotRole $role): ?string
    {
        $modelId = (int)$role->getData(BotRole::schema_fields_MODEL_ID);
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

    private function resolveScenarioCode(BotRole $role): string
    {
        $scenarioCode = (string)$role->getData(BotRole::schema_fields_SCENARIO_ADAPTER_CODE);
        return $scenarioCode !== '' ? $scenarioCode : 'bot_agent';
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

    private function processToolCalls(array $toolCalls, BotRole $role, BotChatSession $session): array
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $skillCode = $toolCall['name'] ?? $toolCall['function']['name'] ?? '';
            $arguments = $toolCall['arguments'] ?? $toolCall['function']['arguments'] ?? [];
            $toolCallId = (string)($toolCall['id'] ?? '');

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

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

                $callRecord->markRunning();
                $callRecord->save();

                $result = $skill->execute($arguments, $context);
                $resultData = $result->getData();
                $encodedResult = json_encode($resultData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $callRecord->markSuccess($encodedResult ?: '{}');
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

    private function submitToolResults(array $toolResults, BotRole $role, BotChatSession $session): AgentResult
    {
        foreach ($toolResults as $toolResult) {
            $content = $toolResult['success']
                ? json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode(['error' => $toolResult['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->sessionManager->addMessage(
                $session,
                BotChatMessage::ROLE_TOOL,
                $content ?: '{}',
                [],
                (string)$toolResult['skill_code'],
                (string)$toolResult['tool_call_id']
            );
        }

        return $this->requestAgentResponse($role, $this->contextBuilder->build($session, $role));
    }
}
