<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Api\AgentInterface;
use Weline\Ai\Api\AgentResult;
use Weline\Ai\Api\AiModel;
use Weline\Ai\Api\ToolInterface;
use Weline\Ai\Service\Provider\ProviderRuntime;
use Weline\AiAcquisition\Service\Prompt\PromptLibrary;
use Weline\AiAcquisition\Service\Site\WebsiteProfileService;
use Weline\AiAcquisition\Service\Tool\InspectPageTool;
use Weline\AiAcquisition\Service\Tool\ListExistingLeadsTool;
use Weline\AiAcquisition\Service\Tool\SaveLeadTool;
use Weline\AiAcquisition\Service\Tool\WebSearchTool;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI 获客智能体（code: ai_acquisition）。
 *
 * 标准 function-calling 循环：web_search 找线索 → inspect_page 取证 → list_existing_leads 查重
 * → save_lead 入库；最多 8 轮，工具异常不中断循环。模型与账户配置由 AiService 注入。
 */
class AcquisitionAgent implements AgentInterface
{
    /** 最多 8 轮，防止搜索循环无限消耗 token */
    private const MAX_ITERATIONS = 8;

    /** @var list<ToolInterface>|null */
    private ?array $tools = null;

    public function getCode(): string
    {
        return 'ai_acquisition';
    }

    public function getName(): string
    {
        return (string)__('AI 获客智能体');
    }

    public function getDescription(): string
    {
        return (string)__('在公开网络上检索潜在买家，核实证据并写入线索库；支持站点/产品线/ICP 上下文注入。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['ai_acquisition', 'lead_generation'];
    }

    public function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = [
                ObjectManager::getInstance(WebSearchTool::class),
                ObjectManager::getInstance(InspectPageTool::class),
                ObjectManager::getInstance(ListExistingLeadsTool::class),
                ObjectManager::getInstance(SaveLeadTool::class),
            ];
        }

        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $prompt = ObjectManager::getInstance(PromptLibrary::class)->compose($context);
        $websiteId = (int)($context['website_id'] ?? -1);
        if ($websiteId >= 0 && trim((string)($context['website_profile'] ?? '')) === '') {
            try {
                $profile = ObjectManager::getInstance(WebsiteProfileService::class)->buildPromptContext($websiteId);
                if ($profile !== '') {
                    $prompt .= "\n\n" . $profile;
                }
            } catch (\Throwable) {
                // 画像不可用时仍可使用通用获客提示词
            }
        }

        return $prompt;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null,
    ): AgentResult {
        $tools = array_values(array_filter(
            $this->getTools(),
            static fn(ToolInterface $tool): bool => $tool->isEnabled()
        ));
        if ($tools === []) {
            return AgentResult::failure((string)__('获客智能体没有可用工具。'), $this->getCode());
        }

        $toolMap = [];
        $toolDefs = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
            $toolDefs[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParameters(),
            ];
        }

        try {
            $provider = $this->resolveProvider($model, $params);
        } catch (\Throwable $e) {
            return AgentResult::failure(
                (string)__('AI 提供者不可用：%{1}', [$e->getMessage()]),
                $this->getCode()
            );
        }
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($params)],
            ['role' => 'user', 'content' => $prompt],
        ];

        $toolCallLog = [];
        $iterations = 0;
        $finalContent = '';
        $lastContent = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $totalCost = 0.0;

        $emit = static function (string $event, array $data = []) use ($streamCallback): void {
            if ($streamCallback !== null) {
                $streamCallback($event, $data);
            }
        };

        while ($iterations < self::MAX_ITERATIONS) {
            $iterations++;
            $current = $iterations;
            $emit('iteration', ['iteration' => $current, 'max' => self::MAX_ITERATIONS]);

            $request = [
                'messages' => $messages,
                'tools' => $toolDefs,
                'temperature' => (float)($params['temperature'] ?? 0.3),
                'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                'timeout' => (int)($params['timeout'] ?? 180),
            ];

            try {
                if ($useStreamFull) {
                    if ($streamCallback !== null) {
                        $request['on_content'] = static fn(string $chunk): bool
                            => $streamCallback('chunk', ['content' => $chunk, 'iteration' => $current, 'streaming' => true]) !== false;
                        $request['on_reasoning'] = static fn(string $chunk): bool
                            => $streamCallback('thinking', ['content' => $chunk, 'iteration' => $current, 'streaming' => true]) !== false;
                        $request['on_heartbeat'] = static fn(): bool
                            => $streamCallback('heartbeat', ['ts' => time()]) !== false;
                    }
                    /** @var callable(AiModel,string,array):array $streamFull */
                    $streamFull = [$provider, 'generateStreamFull'];
                    $response = $streamFull($model, '', $request);
                } else {
                    $response = $provider->generate($model, '', $request);
                    if ($streamCallback !== null && (string)($response['content'] ?? '') !== '') {
                        $streamCallback('chunk', [
                            'content' => (string)$response['content'],
                            'iteration' => $current,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                return AgentResult::failure(
                    (string)__('AI 调用失败：%{1}', [$e->getMessage()]),
                    $this->getCode()
                );
            }

            // 累计用量与成本（模型价格按每千 token 计，账户配置已由 AiService 注入）
            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            $stepInput = (int)($usage['prompt_tokens'] ?? 0);
            $stepOutput = (int)($usage['completion_tokens'] ?? 0);
            $inputTokens += $stepInput;
            $outputTokens += $stepOutput;
            if ($stepInput > 0 || $stepOutput > 0) {
                $stepCost = $stepInput / 1000 * $model->getTokenPriceInput()
                    + $stepOutput / 1000 * $model->getTokenPriceOutput();
                $totalCost += $stepCost;
                $emit('cost', [
                    'iteration' => $current,
                    'input_tokens' => $stepInput,
                    'output_tokens' => $stepOutput,
                    'cost' => round($stepCost, 6),
                    'total_input_tokens' => $inputTokens,
                    'total_output_tokens' => $outputTokens,
                    'total_cost' => round($totalCost, 6),
                    'currency' => 'CNY',
                ]);
            }

            $lastContent = (string)($response['content'] ?? '');
            $toolCalls = is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];
            if ($toolCalls === []) {
                $finalContent = $lastContent;
                break;
            }

            $messages[] = $response['assistant_message'] ?? [
                'role' => 'assistant',
                'content' => $lastContent,
                'tool_calls' => array_map(static fn(array $call): array => [
                    'id' => (string)($call['id'] ?? uniqid('tc_', true)),
                    'type' => 'function',
                    'function' => [
                        'name' => (string)($call['name'] ?? $call['function']['name'] ?? ''),
                        'arguments' => json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                    ],
                ], $toolCalls),
            ];

            foreach ($toolCalls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $name = (string)($call['name'] ?? $call['function']['name'] ?? '');
                $rawArgs = $call['arguments'] ?? $call['function']['arguments'] ?? [];
                $arguments = is_string($rawArgs) ? (json_decode($rawArgs, true) ?: []) : $rawArgs;
                if (!is_array($arguments)) {
                    $arguments = [];
                }
                $callId = (string)($call['id'] ?? uniqid('tc_', true));

                $emit('tool_call', ['id' => $callId, 'name' => $name, 'arguments' => $arguments]);

                $tool = $toolMap[$name] ?? null;
                $result = $tool !== null
                    ? $this->runTool($tool, $arguments)
                    : ['error' => (string)__('未找到工具：%{1}', [$name])];
                $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    $encoded = '{}';
                }

                $toolCallLog[] = ['name' => $name, 'arguments' => $arguments, 'result' => $result];
                $emit('tool_result', [
                    'id' => $callId,
                    'name' => $name,
                    'result' => mb_strlen($encoded) > 1000 ? mb_substr($encoded, 0, 1000) . '...' : $encoded,
                ]);

                if ($name === 'save_lead' && !isset($result['error'])) {
                    $emit('lead_saved', [
                        'lead_id' => (int)($result['lead_id'] ?? 0),
                        'created' => (bool)($result['created'] ?? false),
                        'status' => (string)($result['status'] ?? ''),
                    ]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $encoded,
                ];
            }
        }

        if ($finalContent === '' && $lastContent !== '') {
            $finalContent = $lastContent;
        }
        if ($finalContent === '') {
            $finalContent = (string)__('已达到最大轮次，未得到最终结论。请缩小检索范围后重试。');
        }

        return new AgentResult(
            content: $finalContent,
            toolCalls: $toolCallLog,
            iterations: $iterations,
            messages: $messages,
            success: true,
            agentCode: $this->getCode(),
            modelCode: $model->getModelCode(),
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

    /**
     * 执行单个工具；工具异常转成 error 结果，不中断循环。
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function runTool(ToolInterface $tool, array $arguments): array
    {
        try {
            $result = $tool->execute($arguments);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * 解析 provider 会话。
     *
     * 生产路径由 AiService 注入 provider_runtime（模型账户配置已注入快照）；
     * 外部/测试可注入 provider_factory（只要能返回 provider 会话）。
     * ProviderFactory 只接受 Weline_Ai 内部 ORM 模型，本智能体拿到的是 Api 快照，
     * 因此统一经 ProviderRuntime 选路。
     *
     * @param array<string,mixed> $params
     * @return object 具备 generate()（流式时还有 generateStreamFull()）的 provider 会话
     */
    private function resolveProvider(AiModel $model, array $params): object
    {
        $candidate = $params['provider_factory'] ?? $params['provider_runtime'] ?? null;
        if (is_object($candidate) && method_exists($candidate, 'getProvider')) {
            $provider = $candidate->getProvider($model);
            if (is_object($provider)) {
                return $provider;
            }
        }

        return ObjectManager::getInstance(ProviderRuntime::class)->getProvider($model);
    }
}
