<?php
declare(strict_types=1);

/*
 * PageBuilder 组件构建智能体
 * 
 * 通过 Weline_Ai Agent 扩展点实现，自动被 AgentScanner 发现注册
 */

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\AI\Tool\PreviewReferenceTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\GetComponentFrameworkTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ListComponentsTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\ValidateCodeTool;
use GuoLaiRen\PageBuilder\Service\AI\Tool\GetPageLayoutTool;

/**
 * PageBuilder 组件构建智能体
 * 
 * 功能：
 * - 智能组件生成：使用 Tool 调用获取参考组件、框架模板、验证代码
 * - 内置 CSS 变量表、BEM 命名、框架模板结构等静态规约
 * - 支持流式输出和 Tool 调用可视化
 */
class PageBuilderAgent implements AgentInterface
{
    /**
     * 安全上限：防止死循环（正常任务不应触及此值）
     */
    private const MAX_ITERATIONS = 20;

    /**
     * @var ToolInterface[]|null
     */
    private ?array $tools = null;

    public function getCode(): string
    {
        return 'pagebuilder_component';
    }

    public function getName(): string
    {
        return __('PageBuilder 组件构建智能体');
    }

    public function getDescription(): string
    {
        return __('擅长生成 PageBuilder 页面组件，支持查询参考组件、获取框架模板、验证代码等工具调用');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
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
            ];
        }
        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        $category = $context['category'] ?? 'content';
        $styleCode = $context['style_code'] ?? '';

        $prompt = <<<'SYSTEM_PROMPT'
你是一个专业的 PageBuilder 组件构建智能体。你的任务是根据用户描述生成高质量的 PageBuilder 组件。

## 你的工作方式

你是一个自主智能体，**必须先规划再执行**，流程如下：

### 第一步：规划（必须在第一次回复中完成）
收到用户需求后，先分析并制定执行计划，说明：
- 组件的核心功能和视觉目标
- 你打算调用哪些工具，以什么顺序
- 预计需要几步完成

### 第二步：逐步执行
按计划依次调用工具。每一步只调用当前需要的工具。

### 第三步：自主判断完成
当你认为已经收集足够信息并且代码已通过验证，直接输出最终 JSON 结果。
**不要等到被迫停止——当任务完成时，立即输出 JSON 结果，不再调用任何工具。**

## 核心规约

### CSS 主题变量（必须使用，禁止硬编码颜色）
- `var(--pb-primary)` — 品牌主色
- `var(--pb-accent)` — 强调色（按钮、悬停）
- `var(--pb-bg)` — 背景色
- `var(--pb-text)` — 正文文字色
- `var(--pb-heading)` — 标题文字色
- `var(--pb-link)` / `var(--pb-link-hover)` — 链接色
- `var(--pb-text-muted)` — 次要文字色
- `var(--pb-border)` — 边框色

### CSS 规范
- 所有选择器必须以 `#componentId` 开头（运行时自动注入）进行样式隔离
- CSS 类名使用 BEM 命名 + 组件前缀
- 使用 `clamp()` 实现响应式字体
- 使用 CSS Grid 或 Flexbox 布局
- 移动端样式放在 `css_responsive` 字段中

### HTML 规范
- 所有动态文本必须使用 `htmlspecialchars()`
- 图片必须包含 `alt` 和 `loading="lazy"` 属性
- 使用语义化标签
- 避免内联样式

### php_variables 规范
- 仅用于变量声明：`$myVar = $getConfig('key', 'default');`
- 每行以分号结尾
- 禁止：PHP 标签、if/foreach/function/class、echo/print
- 不需要额外变量时返回空字符串

### js_content 规范
- 框架提供 `component` 变量（DOM 元素）
- 直接写逻辑，不要 DOMContentLoaded 或 IIFE 包裹
- 禁止：PHP 标签或 `$componentId`，使用 `component.id`
- 不需要 JS 时返回空字符串

### extra_fields 规范
- 格式：一行一个，`group:group_name => Group Title` 或 `group_name.field_name => Label:Type:Default|Options`
- 类型：`text`, `textarea`, `number`, `color`, `select`, `image`
- 不需要额外字段时返回空字符串

## 输出格式

当你完成所有工具调用、准备好最终结果时，直接返回纯 JSON（不要代码块标记）：
```
{
    "name": "组件名称",
    "description": "组件描述",
    "html_content": "HTML 模板内容",
    "css_content": "CSS 样式（选择器以 #componentId 开头）",
    "css_responsive": "响应式 CSS",
    "js_content": "JavaScript 逻辑",
    "php_variables": "PHP 变量声明",
    "extra_fields": "额外配置字段定义"
}
```

## 可用工具

- `list_components` — 列出该区域已有的组件，了解现有组件的风格和结构
- `preview_reference_component` — 查看某个参考组件的完整代码
- `get_component_framework` — 获取当前区域的框架模板结构
- `validate_code` — 验证你生成的代码是否符合规约
- `get_page_layout` — 获取页面整体布局信息

## 重要提醒

- **高效执行**：不要重复调用同一工具获取相同信息
- **及时完成**：信息够了就直接生成代码，验证通过就立即输出 JSON
- **不要空转**：如果 validate_code 返回通过，立即输出最终 JSON 结果，不要再调用其他工具
SYSTEM_PROMPT;

        // 追加区域和风格上下文
        if (!empty($category)) {
            $prompt .= "\n\n## 当前上下文\n- 组件区域：{$category}";
        }
        if (!empty($styleCode)) {
            $prompt .= "\n- 模板风格：{$styleCode}";
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

        // 构建 Tool 定义（框架中间格式）
        $toolDefs = array_map(fn(ToolInterface $t) => [
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'parameters' => $t->getParameters(),
        ], array_values($enabledTools));

        // 构建 Tool 名称映射
        $toolMap = [];
        foreach ($enabledTools as $t) {
            $toolMap[$t->getName()] = $t;
        }

        // 构建上下文
        $context = [
            'category' => $params['category'] ?? 'content',
            'style_code' => $params['style_code'] ?? '',
        ];

        // 构建初始消息
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($context)],
            ['role' => 'user', 'content' => $prompt],
        ];

        // 获取 ProviderFactory
        /** @var ProviderFactory $providerFactory */
        $providerFactory = $params['provider_factory'] ?? ObjectManager::getInstance(ProviderFactory::class);
        $provider = $providerFactory->getProvider($model);

        $iteration = 0;
        $allToolCalls = [];
        $finalContent = '';

        // 检查 Provider 是否支持 generateStreamFull（流式 + tool_calls）
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        // Tool 调用编排循环
        while ($iteration < self::MAX_ITERATIONS) {
            // 通知迭代开始
            if ($streamCallback) {
                $streamCallback('iteration', [
                    'iteration' => $iteration + 1,
                    'max' => self::MAX_ITERATIONS,
                ]);
            }

            try {
                $currentIteration = $iteration + 1;

                if ($useStreamFull) {
                    // 流式调用：实时推送 thinking/content，保持 SSE 连接活跃
                    $response = $provider->generateStreamFull($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.7),
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
                    ]);
                } else {
                    // 回退：非流式调用（provider 不支持 generateStreamFull）
                    $response = $provider->generate($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.7),
                        'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                    ]);

                    // 非流式：整块推送 thinking 和 content
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
                // 清理 ANSI 颜色码
                $errorMsg = preg_replace('/\x1b\[[0-9;]*m/', '', $e->getMessage());
                return AgentResult::failure(
                    __('AI 调用失败：%{1}', [$errorMsg]),
                    $this->getCode()
                );
            }

            $finishReason = $response['finish_reason'] ?? '';
            $aiContent = $response['content'] ?? '';

            // 无 tool_calls → 最终结果
            if (empty($response['tool_calls'])) {
                $finalContent = $aiContent;

                // 推送最终内容
                if ($streamCallback && !empty($finalContent)) {
                    $streamCallback('chunk', ['content' => $finalContent]);
                }
                break;
            }

            // 处理 tool_calls
            // 将 assistant 的 tool_calls 消息加入历史（OpenAI 格式）
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

                // 通知前端 Tool 调用
                if ($streamCallback) {
                    $streamCallback('tool_call', [
                        'id' => $toolCallId,
                        'name' => $toolName,
                        'arguments' => $toolArgs,
                    ]);
                }

                // 执行 Tool
                $tool = $toolMap[$toolName] ?? null;
                if ($tool) {
                    try {
                        $toolResult = $tool->execute($toolArgs);
                    } catch (\Throwable $e) {
                        $toolResult = ['error' => $e->getMessage()];
                    }
                } else {
                    $toolResult = ['error' => __('工具不存在：%{1}', [$toolName])];
                }

                $resultStr = is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE);

                // 通知前端 Tool 结果
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

                // 将 Tool 结果加入消息历史
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
        }

        // 达到安全上限：尝试用已有内容兜底
        if ($iteration >= self::MAX_ITERATIONS && empty($finalContent)) {
            // 最后一次调用不带 tools，强制 AI 输出最终结果
            if ($streamCallback) {
                $streamCallback('ai_response', [
                    'content' => __('已达安全上限，正在整理最终结果...'),
                    'iteration' => $iteration + 1,
                    'streaming' => false,
                ]);
            }

            try {
                $finalMessages = $messages;
                $finalMessages[] = [
                    'role' => 'user',
                    'content' => '请立即输出最终的 JSON 结果。不要再调用任何工具，直接返回完整的组件 JSON。',
                ];

                if ($useStreamFull) {
                    $forceResponse = $provider->generateStreamFull($model, '', [
                        'messages' => $finalMessages,
                        // 不传 tools，强制 AI 只输出文本
                        'temperature' => 0.3,
                        'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                            $streamCallback('thinking', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                        } : null,
                        'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                            $streamCallback('ai_response', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                        } : null,
                    ]);
                } else {
                    $forceResponse = $provider->generate($model, '', [
                        'messages' => $finalMessages,
                        'temperature' => 0.3,
                        'max_tokens' => (int)($params['max_tokens'] ?? 8000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                    ]);
                }

                $finalContent = $forceResponse['content'] ?? '';
            } catch (\Throwable $e) {
                // 兜底失败，返回错误
            }

            if (!empty($finalContent)) {
                if ($streamCallback) {
                    $streamCallback('chunk', ['content' => $finalContent]);
                }

                return new AgentResult(
                    content: $finalContent,
                    toolCalls: $allToolCalls,
                    iterations: $iteration + 1,
                    messages: $messages,
                    success: true,
                    agentCode: $this->getCode(),
                    modelCode: $model->getModelCode()
                );
            }

            return new AgentResult(
                content: $response['content'] ?? '',
                toolCalls: $allToolCalls,
                iterations: $iteration,
                messages: $messages,
                success: false,
                error: __('智能体达到安全上限（%{1}轮），但未能生成最终结果', [self::MAX_ITERATIONS]),
                agentCode: $this->getCode(),
                modelCode: $model->getModelCode()
            );
        }

        return new AgentResult(
            content: $finalContent,
            toolCalls: $allToolCalls,
            iterations: $iteration,
            messages: $messages,
            success: true,
            agentCode: $this->getCode(),
            modelCode: $model->getModelCode()
        );
    }

    public function supportsModel(string $modelCode): bool
    {
        // 支持所有模型
        return true;
    }

    public function getMaxIterations(): int
    {
        return self::MAX_ITERATIONS;
    }
}
