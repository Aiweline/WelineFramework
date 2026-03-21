<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Ai\Tool\GetSlotContractTool;
use Weline\Theme\Service\Ai\Tool\GetThemeComponentFrameworkTool;
use Weline\Theme\Service\Ai\Tool\GetThemeVariablesTool;
use Weline\Theme\Service\Ai\Tool\ListThemeComponentsTool;
use Weline\Theme\Service\Ai\Tool\PreviewReferenceThemeComponentTool;
use Weline\Theme\Service\Ai\Tool\RenderVirtualComponentPreviewTool;
use Weline\Theme\Service\Ai\Tool\ValidateThemeComponentTool;

class ThemeComponentBuilderAgent implements AgentInterface
{
    private const MAX_ITERATIONS = 6;

    private ?array $tools = null;

    public function getCode(): string
    {
        return 'theme_component_builder';
    }

    public function getName(): string
    {
        return (string)__('Theme 虚拟部件智能体');
    }

    public function getDescription(): string
    {
        return (string)__('用于生成、微调和验证 Weline Theme 的虚拟部件 JSON 与模板内容。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['theme_component_generation'];
    }

    public function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = [
                ObjectManager::getInstance(GetThemeComponentFrameworkTool::class),
                ObjectManager::getInstance(ListThemeComponentsTool::class),
                ObjectManager::getInstance(PreviewReferenceThemeComponentTool::class),
                ObjectManager::getInstance(GetSlotContractTool::class),
                ObjectManager::getInstance(GetThemeVariablesTool::class),
                ObjectManager::getInstance(ValidateThemeComponentTool::class),
                ObjectManager::getInstance(RenderVirtualComponentPreviewTool::class),
            ];
        }

        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $area = $context['area'] ?? 'frontend';
        $themeId = (int)($context['theme_id'] ?? 0);

        return <<<PROMPT
You are a Weline Theme virtual component engineer.

Your goal is to generate one valid JSON object for a virtual theme component.
Always work against the target theme inheritance chain and slot contracts.

Rules:
1. First call `get_theme_component_framework`.
2. If a reference component is supplied, call `preview_reference_component`.
3. Use `get_slot_contract` when slot placement matters.
4. Use `get_theme_variables` before hardcoding visual decisions.
5. Use `validate_theme_component` before final output.
6. Final answer must be a single JSON object only, no markdown, no explanation.

Required JSON fields:
- name
- description
- category
- component_code
- template_content
- config_schema_json
- default_config_json
- meta_json

Target context:
- theme_id: {$themeId}
- area: {$area}

Template constraints:
- Virtual component source of truth is database content, not repo files.
- Output should be compatible with Weline Theme runtime materialization.
- Prefer slot-aware, configurable markup.
PROMPT;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        $tools = array_values(array_filter($this->getTools(), static fn(ToolInterface $tool): bool => $tool->isEnabled()));
        $toolMap = [];
        $toolDefs = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
            $toolDefs[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($params)],
            ['role' => 'user', 'content' => $prompt],
        ];

        /** @var ProviderFactory $providerFactory */
        $providerFactory = $params['provider_factory'] ?? ObjectManager::getInstance(ProviderFactory::class);
        $provider = $providerFactory->getProvider($model);
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        $iteration = 0;
        $toolCalls = [];
        $finalContent = '';

        while ($iteration < self::MAX_ITERATIONS) {
            $currentIteration = $iteration + 1;
            $streamCallback && $streamCallback('iteration', [
                'iteration' => $currentIteration,
                'max' => self::MAX_ITERATIONS,
            ]);

            try {
                if ($useStreamFull) {
                    $response = $provider->generateStreamFull($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.4),
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration): void {
                            $streamCallback('thinking', ['content' => $chunk, 'iteration' => $currentIteration, 'streaming' => true]);
                        } : null,
                        'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration): void {
                            $streamCallback('chunk', ['content' => $chunk, 'iteration' => $currentIteration, 'streaming' => true]);
                        } : null,
                        'on_heartbeat' => $streamCallback ? function () use ($streamCallback): void {
                            $streamCallback('heartbeat', ['ts' => time()]);
                        } : null,
                    ]);
                } else {
                    $response = $provider->generate($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.4),
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                    ]);
                    if ($streamCallback && !empty($response['reasoning_content'])) {
                        $streamCallback('thinking', ['content' => (string)$response['reasoning_content'], 'iteration' => $currentIteration]);
                    }
                    if ($streamCallback && !empty($response['content'])) {
                        $streamCallback('chunk', ['content' => (string)$response['content'], 'iteration' => $currentIteration]);
                    }
                }
            } catch (\Throwable $throwable) {
                return AgentResult::failure(
                    (string)__('AI 调用失败：%{1}', [$throwable->getMessage()]),
                    $this->getCode()
                );
            }

            $assistantMessage = $response['assistant_message'] ?? [
                'role' => 'assistant',
                'content' => $response['content'] ?? null,
                'tool_calls' => array_map(static fn(array $toolCall): array => [
                    'id' => $toolCall['id'] ?? uniqid('tc_', true),
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall['name'] ?? '',
                        'arguments' => json_encode($toolCall['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                    ],
                ], $response['tool_calls'] ?? []),
            ];

            if (empty($response['tool_calls'])) {
                $finalContent = (string)($response['content'] ?? '');
                break;
            }

            $messages[] = $assistantMessage;
            $validated = false;

            foreach ($response['tool_calls'] as $toolCall) {
                $toolName = (string)($toolCall['name'] ?? '');
                $arguments = is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
                $toolCallId = (string)($toolCall['id'] ?? uniqid('tc_', true));

                $streamCallback && $streamCallback('tool_call', [
                    'id' => $toolCallId,
                    'name' => $toolName,
                    'arguments' => $arguments,
                ]);

                $tool = $toolMap[$toolName] ?? null;
                try {
                    $toolResult = $tool ? $tool->execute($arguments) : ['error' => 'tool not found'];
                } catch (\Throwable $throwable) {
                    $toolResult = ['error' => $throwable->getMessage()];
                }

                $resultString = is_string($toolResult) ? $toolResult : (string)json_encode($toolResult, JSON_UNESCAPED_UNICODE);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $resultString,
                ];
                $toolCalls[] = [
                    'name' => $toolName,
                    'arguments' => $arguments,
                    'result_size' => strlen($resultString),
                ];

                $streamCallback && $streamCallback('tool_result', [
                    'id' => $toolCallId,
                    'name' => $toolName,
                    'result' => mb_strlen($resultString) > 500 ? mb_substr($resultString, 0, 500) . '...' : $resultString,
                ]);

                if ($toolName === 'validate_theme_component') {
                    $decoded = is_array($toolResult) ? $toolResult : (json_decode($resultString, true) ?: []);
                    $validated = !empty($decoded['valid']);
                }
            }

            if ($validated) {
                try {
                    $forceMessages = $messages;
                    $forceMessages[] = [
                        'role' => 'user',
                        'content' => 'Validation passed. Reply with ONLY one valid JSON object. No markdown, no commentary, no code fences.',
                    ];
                    $forceParams = [
                        'messages' => $forceMessages,
                        'temperature' => 0.2,
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'response_format' => ['type' => 'json_object'],
                    ];
                    $forced = $useStreamFull
                        ? $provider->generateStreamFull($model, '', array_merge($forceParams, [
                            'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration): void {
                                $streamCallback('thinking', ['content' => $chunk, 'iteration' => $currentIteration, 'streaming' => true]);
                            } : null,
                            'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration): void {
                                $streamCallback('chunk', ['content' => $chunk, 'iteration' => $currentIteration, 'streaming' => true]);
                            } : null,
                            'on_heartbeat' => $streamCallback ? function () use ($streamCallback): void {
                                $streamCallback('heartbeat', ['ts' => time()]);
                            } : null,
                        ]))
                        : $provider->generate($model, '', $forceParams);
                    $finalContent = (string)($forced['content'] ?? '');
                    break;
                } catch (\Throwable) {
                }
            }

            $streamCallback && $streamCallback('agent_status', [
                'status' => 'next_iteration',
                'message' => (string)__('准备进入下一轮智能体调用'),
                'iteration' => $currentIteration,
            ]);

            $iteration++;
        }

        if ($finalContent === '') {
            return new AgentResult(
                content: '',
                toolCalls: $toolCalls,
                iterations: $iteration,
                messages: $messages,
                success: false,
                error: (string)__('智能体未能生成最终 JSON 输出'),
                agentCode: $this->getCode(),
                modelCode: $model->getModelCode()
            );
        }

        return new AgentResult(
            content: $finalContent,
            toolCalls: $toolCalls,
            iterations: max(1, $iteration),
            messages: $messages,
            success: true,
            agentCode: $this->getCode(),
            modelCode: $model->getModelCode()
        );
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return self::MAX_ITERATIONS;
    }
}
