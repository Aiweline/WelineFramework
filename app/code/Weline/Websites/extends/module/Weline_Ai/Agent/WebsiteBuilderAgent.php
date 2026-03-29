<?php
declare(strict_types=1);

/*
 * AI 建站工作台 - 通过 Weline_Ai Agent 扩展点实现
 *
 * 根据用户描述理解需求，推荐域名，自动完成：购买域名 → DNS 解析 → HTTPS → 创建站点
 * 智能体识别与理解由 AI 模块完成
 */

namespace Weline\Websites\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Websites\Service\AI\Tool\CheckDomainAvailabilityTool;
use Weline\Websites\Service\AI\Tool\GetRegistrarAccountsTool;
use Weline\Websites\Service\AI\Tool\PurchaseDomainAndBuildSiteTool;

class WebsiteBuilderAgent implements AgentInterface
{
    private const DEFAULT_MAX_ITERATIONS = 12;
    private const MIN_MAX_ITERATIONS = 1;
    private const HARD_MAX_ITERATIONS = 30;

    private ?array $tools = null;

    public function getCode(): string
    {
        return 'website_builder';
    }

    public function getName(): string
    {
        return __('AI 建站工作台');
    }

    public function getDescription(): string
    {
        return __('根据描述自动购买域名、DNS 解析、HTTPS 申请、创建站点。支持理解用户意图并推荐合适域名。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['website_builder', 'site_builder'];
    }

    public function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = [
                ObjectManager::getInstance(GetRegistrarAccountsTool::class),
                ObjectManager::getInstance(CheckDomainAvailabilityTool::class),
                ObjectManager::getInstance(PurchaseDomainAndBuildSiteTool::class),
            ];
        }
        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $accountId = (int) ($context['account_id'] ?? 0);
        $demoMode = !empty($context['demo_mode']);
        $ctxHint = $accountId > 0
            ? "\n当前上下文：用户已选择账号 ID {$accountId}，可直接使用该 account_id 调用工具。"
            : "\n当前上下文：若用户未指定账号，请先调用 get_registrar_accounts 获取可用账号，选一个 account_id 再执行后续操作。";
        $demoHint = $demoMode
            ? "\n当前环境：演示/沙盒环境。请避免反复重试同类工具；当域名检查返回空结果时，最多换一组候选后立即进入建站或给出单次失败建议。"
            : '';

        return <<<PROMPT
你是 AI 建站工作台中的建站助手，负责根据用户描述理解需求，推荐域名，并执行一站式建站：购买域名 → DNS 解析 → HTTPS → 创建站点。

## 工作流程

1. **理解需求**：从用户描述中提取站点类型、主题、期望域名风格（如电商、博客、企业站）。
2. **推荐域名**：根据描述生成 3-5 个合适的域名建议（如描述「茶叶电商」可推荐 teashop.com、chateaumall.com 等）。
3. **检查可用性**：调用 check_domain_availability 检查推荐域名的可用性。
4. **执行建站**：对第一个可用的域名调用 purchase_domain_and_build_site，完成购买、解析、HTTPS、建站。

## 工具说明

- **get_registrar_accounts**：获取可用域名商账号，返回 account_id。若上下文已有 account_id 可跳过。
- **check_domain_availability**：检查域名是否可注册，传入 account_id 和 domains 数组。
- **purchase_domain_and_build_site**：一站式建站，传入 description（站点描述/名称）、domain、account_id。

## 输出要求

- 最终用简洁中文回复用户：已完成的域名、站点链接、后续操作建议。
- 若某步骤失败，说明失败原因并给出可操作建议。
{$ctxHint}
{$demoHint}
PROMPT;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        $tools = array_filter($this->getTools(), fn(ToolInterface $t) => $t->isEnabled());
        $toolDefs = array_map(fn(ToolInterface $t) => [
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'parameters' => $t->getParameters(),
        ], array_values($tools));
        $toolMap = [];
        foreach ($tools as $t) {
            $toolMap[$t->getName()] = $t;
        }

        $context = [
            'account_id' => $params['account_id'] ?? 0,
            'demo_mode' => !empty($params['demo_mode']),
        ];
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($context)],
            ['role' => 'user', 'content' => $prompt],
        ];

        /** @var ProviderFactory $providerFactory */
        $providerFactory = $params['provider_factory'] ?? ObjectManager::getInstance(ProviderFactory::class);
        $provider = $providerFactory->getProvider($model);

        $iteration = 0;
        $finalContent = '';
        $siteBuildCompleted = false;
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        $maxIterations = $this->resolveMaxIterations($params);
        while ($iteration < $maxIterations) {
            if ($streamCallback) {
                $streamCallback('agent_status', [
                    'status' => 'calling_ai',
                    'message' => __('正在调用 AI...'),
                    'iteration' => $iteration + 1,
                ]);
            }

            try {
                $genParams = [
                    'messages' => $messages,
                    'tools' => $toolDefs,
                    'temperature' => (float) ($params['temperature'] ?? 0.3),
                    'max_tokens' => (int) ($params['max_tokens'] ?? 8000),
                    'timeout' => (int) ($params['timeout'] ?? 120),
                ];
                if ($useStreamFull && $streamCallback) {
                    $genParams['on_content'] = function (string $chunk) use ($streamCallback) {
                        $streamCallback('ai_response', ['content' => $chunk, 'streaming' => true]);
                    };
                    $genParams['on_heartbeat'] = fn() => $streamCallback && $streamCallback('heartbeat', ['ts' => time()]);
                }
                if ($useStreamFull) {
                    /** @var callable(AiModel,string,array):array $streamFullCallable */
                    $streamFullCallable = [$provider, 'generateStreamFull'];
                    $response = $streamFullCallable($model, '', $genParams);
                } else {
                    $response = $provider->generate($model, '', $genParams);
                }
            } catch (\Throwable $e) {
                return AgentResult::failure(
                    __('AI 调用失败：%{1}', [$e->getMessage()]),
                    $this->getCode()
                );
            }

            $aiContent = $response['content'] ?? '';
            $toolCalls = $response['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                $finalContent = $aiContent;
                if ($streamCallback && !empty($finalContent)) {
                    $streamCallback('chunk', ['content' => $finalContent]);
                }
                break;
            }

            $assistantMsg = $response['assistant_message'] ?? [
                'role' => 'assistant',
                'content' => $aiContent,
                'tool_calls' => array_map(fn($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => \json_encode($tc['arguments'] ?? []),
                    ],
                ], $toolCalls),
            ];
            $messages[] = $assistantMsg;

            foreach ($toolCalls as $tc) {
                $name = $tc['name'];
                $args = \is_array($tc['arguments'] ?? []) ? $tc['arguments'] : (json_decode((string) $tc['arguments'], true) ?: []);
                $id = $tc['id'] ?? uniqid('tc_');

                if ($streamCallback) {
                    $streamCallback('tool_call', ['id' => $id, 'name' => $name, 'arguments' => $args]);
                }
                $tool = $toolMap[$name] ?? null;
                $toolResult = $tool ? $tool->execute($args) : null;
                $result = $tool
                    ? (is_array($toolResult) ? $toolResult : ['raw' => $toolResult])
                    : ['error' => __('工具不存在：%{1}', [$name])];
                $resultStr = \json_encode($result, JSON_UNESCAPED_UNICODE);
                if ($streamCallback) {
                    $streamCallback('tool_result', ['id' => $id, 'name' => $name, 'result' => mb_strlen($resultStr) > 800 ? mb_substr($resultStr, 0, 800) . '...' : $resultStr]);
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $id, 'content' => $resultStr];

                // 关键工具成功后直接收敛，避免模型反复补充导致达到最大轮次。
                if ($name === 'purchase_domain_and_build_site' && !empty($result['success'])) {
                    $siteBuildCompleted = true;
                    $domain = \trim((string)($result['domain'] ?? ''));
                    $message = \trim((string)($result['message'] ?? ''));
                    $finalContent = $message !== ''
                        ? $message
                        : (
                            $domain !== ''
                                ? (string)__('建站流程已完成，域名：%{1}。接下来可进入虚拟主题编辑并完善页面内容。', [$domain])
                                : (string)__('建站流程已完成，接下来可进入虚拟主题编辑并完善页面内容。')
                        );
                }
            }
            if ($siteBuildCompleted) {
                break;
            }
            $iteration++;
        }

        if ($iteration >= $maxIterations && $finalContent === '') {
            return AgentResult::failure(
                __('智能体达到最大轮次，未能完成建站'),
                $this->getCode()
            );
        }

        return new AgentResult(
            content: $finalContent,
            toolCalls: [],
            iterations: $iteration,
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
        return $this->resolveMaxIterations();
    }

    /**
     * 支持通过 execute 参数覆盖轮次：$params['max_iterations']
     * 否则回落到全局配置：websites.ai.website_builder.max_iterations
     */
    private function resolveMaxIterations(array $params = []): int
    {
        $raw = $params['max_iterations'] ?? Env::get(
            'websites.ai.website_builder.max_iterations',
            self::DEFAULT_MAX_ITERATIONS
        );
        $maxIterations = (int)$raw;
        if ($maxIterations < self::MIN_MAX_ITERATIONS) {
            return self::MIN_MAX_ITERATIONS;
        }
        if ($maxIterations > self::HARD_MAX_ITERATIONS) {
            return self::HARD_MAX_ITERATIONS;
        }
        return $maxIterations;
    }
}
