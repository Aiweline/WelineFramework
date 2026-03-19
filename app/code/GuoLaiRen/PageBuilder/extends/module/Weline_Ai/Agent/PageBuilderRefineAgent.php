<?php
declare(strict_types=1);

/*
 * PageBuilder 组件微调智能体
 *
 * 通过 Weline_Ai Agent 扩展点实现，自动被 AgentScanner 发现注册
 */

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Agent;

use GuoLaiRen\PageBuilder\Service\AI\Tool\GetComponentFrameworkTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\GetPageLayoutTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ListComponentsTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\PreviewReferenceTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ValidateCodeTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\LocateTemplateErrorTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ReplaceTemplateSnippetTool;
use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * PageBuilder 组件微调智能体
 */
class PageBuilderRefineAgent implements AgentInterface
{
    /**
     * 安全上限：防止死循环
     */
    private const MAX_ITERATIONS = 12;

    /**
     * @var ToolInterface[]|null
     */
    private ?array $tools = null;

    public function getCode(): string
    {
        return 'pagebuilder_component_refine';
    }

    public function getName(): string
    {
        return __('PageBuilder 组件微调智能体');
    }

    public function getDescription(): string
    {
        return __('用于在现有组件基础上进行精确微调，优先保留结构并只修改指定区域');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        // 复用组件生成场景的模型配置
        return ['pagebuilder_component_generation'];
    }

    public function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = [
                ObjectManager::getInstance(PreviewReferenceTool::class),
                ObjectManager::getInstance(GetComponentFrameworkTool::class),
                ObjectManager::getInstance(ListComponentsTool::class),
                ObjectManager::getInstance(ValidateCodeTool::class),
                ObjectManager::getInstance(GetPageLayoutTool::class),
                ObjectManager::getInstance(LocateTemplateErrorTool::class),
                ObjectManager::getInstance(ReplaceTemplateSnippetTool::class),
            ];
        }
        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $category = $context['category'] ?? 'content';
        $styleCode = $context['style_code'] ?? '';

$prompt = <<<'SYSTEM_PROMPT'
You are an excellent full-stack engineer. This is production code. Deliver exceptional code quality.
你是一个专业的 PageBuilder 组件微调智能体。你的任务是在**现有组件代码的基础上**进行精确微调，而不是重新生成整套组件。

## 你的工作方式

你是一个自主智能体，**必须先规划再执行**，流程如下：

### 第一步：规划（必须在第一次回复中完成）
收到用户需求后，先分析并制定执行计划，说明：
- 需要调整的区域/模块
- 你打算调用哪些工具（如有必要）
- 预计需要几步完成

### 第二步：逐步执行
按计划依次调用工具。每一步只调用当前需要的工具。

### 第三步：自主判断完成
当你认为已完成微调并保持原有结构时，直接输出最终的**完整组件代码**。
**不要等到被迫停止——当任务完成时，立即输出最终结果，不再调用任何工具。**

## 核心规则（必须遵守）

1. **只修改必要部分**：保留原有结构、元数据块、字段定义、组件 ID 绑定逻辑。
2. **禁止重写**：不得推翻整套组件，必须在原有代码上精修。
3. **保持结构完整**：必须保留 `@component_start/@component_end` 和 `@fields_start/@fields_end` 等块（如原本存在）。
4. **PHP 变量保持一致**：新增变量必须声明默认值；删除变量需同步删除声明。
5. **配置字段一致性**：不要定义不用的字段，也不要使用未定义字段。
6. **错误修复优先**：如果是报错修复，先调用 `locate_template_error` 获取文件与行号，再用 `replace_template_snippet` 做精准替换。
7. **遵循原有规范**：CSS 选择器前缀、BEM 命名、JS 结构保持一致。
8. **禁止任何注释**：输出代码中不允许出现 HTML/CSS/JS/PHP 注释。
9. **代码必须符合 PHP 8.4 语法**
10. **下载/CTA 按钮**：若组件含下载或 CTA 跳转下载，须 GlrDownloadRegistry::register + data-glr-ref（URL 用 PageHelper::resolveAppDownloadUrl）；禁止为下载写 addEventListener 或 href=\"javascript:void(0)\"。

## 输出格式

当你完成所有工具调用、准备好最终结果时，直接输出**完整组件代码**（不要使用代码块标记）：
- 必须是完整的 phtml 文件内容
- 必须包含原有元数据块与字段定义（若原始代码包含）
- 仅修改必要部分

## 可用工具

- `list_components` — 列出当前区域已有组件，参考风格
- `preview_reference_component` — 查看某个参考组件的完整代码
- `get_component_framework` — 获取当前区域的框架模板结构
- `validate_code` — 验证字段级规范（如 CSS/JS 禁止模式）
- `get_page_layout` — 获取页面整体布局信息

## 重要提醒

- **避免重复调用工具**：信息足够就开始微调
- **保留原结构**：只改指定区域
- **输出完整代码**：最终输出必须是完整的 phtml
SYSTEM_PROMPT;

        if (!empty($category)) {
            $prompt .= "\n\n## 当前上下文\n- 组件区域：{$category}";
        }
        if (!empty($styleCode)) {
            $prompt .= "\n- 模板风格：{$styleCode}";
        }
        
        // 语言要求：根据页面语言生成对应语言的内容
        $language = $context['language'] ?? '';
        if (!empty($language)) {
            $languageMap = [
                'zh_Hans_CN' => '简体中文',
                'zh-CN' => '简体中文',
                'zh_CN' => '简体中文',
                'zh' => '中文',
                'en_US' => 'English',
                'en' => 'English',
                'ja_JP' => '日本語',
                'ja' => '日本語',
                'ko_KR' => '한국어',
                'ko' => '한국어',
            ];
            $languageName = $languageMap[$language] ?? $language;
            $prompt .= "\n\n## 语言要求（CRITICAL）\n";
            $prompt .= "- **目标语言**：{$languageName}\n";
            $prompt .= "- **所有用户可见文本**（按钮文字、标题、描述、占位符等）必须使用 **{$languageName}** 语言\n";
            $prompt .= "- 代码注释、技术标识符（变量名、CSS类名）保持英文\n";
            $prompt .= "- 示例：如果目标语言是「简体中文」，按钮应该是「了解更多」而不是「Learn More」";
        }

        return $prompt;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        $tools = $this->getTools();
        $enabledTools = array_filter($tools, fn(ToolInterface $t) => $t->isEnabled());

        $toolDefs = array_map(fn(ToolInterface $t) => [
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'parameters' => $t->getParameters(),
        ], array_values($enabledTools));

        $toolMap = [];
        foreach ($enabledTools as $t) {
            $toolMap[$t->getName()] = $t;
        }

        $context = [
            'category' => $params['category'] ?? 'content',
            'style_code' => $params['style_code'] ?? '',
            'language' => $params['language'] ?? '',
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($context)],
            ['role' => 'user', 'content' => $prompt],
        ];

        /** @var ProviderFactory $providerFactory */
        $providerFactory = $params['provider_factory'] ?? ObjectManager::getInstance(ProviderFactory::class);
        $provider = $providerFactory->getProvider($model);

        $iteration = 0;
        $allToolCalls = [];
        $finalContent = '';
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        while ($iteration < self::MAX_ITERATIONS) {
            if ($streamCallback) {
                $streamCallback('iteration', [
                    'iteration' => $iteration + 1,
                    'max' => self::MAX_ITERATIONS,
                ]);
            }

            try {
                $currentIteration = $iteration + 1;

                if ($streamCallback) {
                    $streamCallback('agent_status', [
                        'status' => 'calling_ai',
                        'message' => __('正在调用 AI 模型...'),
                        'iteration' => $currentIteration,
                    ]);
                    $streamCallback('heartbeat', ['ts' => time()]);
                }

                if ($useStreamFull) {
                    $response = $provider->generateStreamFull($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.6),
                        'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration) {
                            $streamCallback('thinking', [
                                'content' => $chunk,
                                'iteration' => $currentIteration,
                                'streaming' => true,
                            ]);
                        } : null,
                        'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $currentIteration) {
                            $streamCallback('ai_response', [
                                'content' => $chunk,
                                'iteration' => $currentIteration,
                                'streaming' => true,
                            ]);
                        } : null,
                        'on_heartbeat' => $streamCallback ? function () use ($streamCallback) {
                            $streamCallback('heartbeat', ['ts' => time()]);
                        } : null,
                        'on_waiting' => $streamCallback ? function (int $elapsed) use ($streamCallback, $currentIteration) {
                            $streamCallback('agent_status', [
                                'status' => 'waiting_ai',
                                'message' => __('等待 AI 响应中... (已等待 %{1} 秒)', [$elapsed]),
                                'iteration' => $currentIteration,
                                'elapsed' => $elapsed,
                            ]);
                        } : null,
                    ]);
                } else {
                    $response = $provider->generate($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.6),
                        'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                    ]);

                    $reasoningContent = $response['reasoning_content'] ?? '';
                    $aiContentBlock = $response['content'] ?? '';

                    if ($streamCallback && !empty($reasoningContent)) {
                        $streamCallback('thinking', [
                            'content' => $reasoningContent,
                            'iteration' => $currentIteration,
                        ]);
                    }
                    if ($streamCallback && !empty($aiContentBlock)) {
                        $streamCallback('ai_response', [
                            'content' => $aiContentBlock,
                            'iteration' => $currentIteration,
                            'has_tool_calls' => !empty($response['tool_calls']),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $errorMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
                return AgentResult::failure(
                    __('AI 调用失败：%{1}', [$errorMsg]),
                    $this->getCode()
                );
            }

            if (empty($response['tool_calls'])) {
                $finalContent = $response['content'] ?? '';

                if ($streamCallback) {
                    $streamCallback('agent_status', [
                        'status' => 'finalizing',
                        'message' => __('AI 已完成所有调用，正在输出最终结果...'),
                        'iteration' => $iteration + 1,
                        'content_length' => strlen($finalContent),
                    ]);
                }
                if ($streamCallback && !empty($finalContent)) {
                    $streamCallback('chunk', ['content' => $finalContent]);
                }
                break;
            }

            $assistantMsg = $response['assistant_message'] ?? [
                'role' => 'assistant',
                'content' => $response['content'] ?? null,
                'tool_calls' => array_map(fn($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => json_encode($tc['arguments']),
                    ],
                ], $response['tool_calls']),
            ];
            $messages[] = $assistantMsg;

            foreach ($response['tool_calls'] as $tc) {
                $toolName = $tc['name'];
                $toolArgs = $tc['arguments'] ?? [];
                $toolCallId = $tc['id'] ?? uniqid('tc_');

                if ($streamCallback) {
                    $streamCallback('tool_call', [
                        'id' => $toolCallId,
                        'name' => $toolName,
                        'arguments' => $toolArgs,
                    ]);
                }

                $tool = $toolMap[$toolName] ?? null;
                if ($tool) {
                    if ($streamCallback) {
                        $streamCallback('agent_status', [
                            'status' => 'executing_tool',
                            'message' => __('正在执行工具: %{1}', [$toolName]),
                            'tool_name' => $toolName,
                        ]);
                    }
                    try {
                        $toolResult = $tool->execute($toolArgs);
                    } catch (\Throwable $e) {
                        $toolResult = ['error' => $e->getMessage()];
                    }
                } else {
                    $toolResult = ['error' => __('工具不存在：%{1}', [$toolName])];
                }

                $resultStr = is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE);

                if ($streamCallback) {
                    $streamCallback('tool_result', [
                        'id' => $toolCallId,
                        'name' => $toolName,
                        'result' => mb_strlen($resultStr) > 500
                            ? mb_substr($resultStr, 0, 500) . '...'
                            : $resultStr,
                        'result_size' => strlen($resultStr),
                    ]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $resultStr,
                ];

                $allToolCalls[] = [
                    'name' => $toolName,
                    'arguments' => $toolArgs,
                    'result_size' => strlen($resultStr),
                ];
            }

            $iteration++;

            if ($streamCallback) {
                $streamCallback('agent_status', [
                    'status' => 'next_iteration',
                    'message' => __('工具执行完成，准备第 %{1} 轮 AI 调用...', [$iteration + 1]),
                    'iteration' => $iteration,
                    'tool_calls_so_far' => count($allToolCalls),
                ]);
                $streamCallback('heartbeat', ['ts' => time(), 'iteration' => $iteration]);
            }
        }

        if ($iteration >= self::MAX_ITERATIONS && empty($finalContent)) {
            return AgentResult::failure(
                __('智能体达到最大循环轮次（%{1}），可能任务过于复杂', [self::MAX_ITERATIONS]),
                $this->getCode()
            );
        }

        return new AgentResult(
            content: $finalContent,
            toolCalls: $allToolCalls,
            iterations: $iteration + 1,
            messages: $messages,
            success: true,
            error: null,
            agentCode: $this->getCode()
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
