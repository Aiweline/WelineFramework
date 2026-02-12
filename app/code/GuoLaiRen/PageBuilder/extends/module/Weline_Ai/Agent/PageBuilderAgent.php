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
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;

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
     * 安全上限：防止死循环（计划规定 7 轮）
     */
    private const MAX_ITERATIONS = 7;

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
You are an expert front-end/full-stack engineer. Your task is to generate a PageBuilder component that matches how real components are built in this system. Produce production-ready code in one shot.

【第一轮即给全规约】以下一至八章及「当前上下文」已一次性给出全部规则与流程。请直接按「五、一次性生成流程」执行：先调 get_component_framework，再根据用户描述直接输出完整 JSON，无需逐步追问、试探或分多轮补充。

## 一、真实组件如何创建（必学）

真实组件是一个 .phtml 文件，结构为：
1. **配置字段**：通过 @fields_start / @fields_end 定义；后台用 group.key 读写，如 brand.name、links.column1_title。
2. **变量准备**：从 $component_config 或 $getConfig('key', 'default') 读取配置，赋给变量（如 $brandName、$col1Title）；链接等需解析成数组后再在模板中 foreach。
3. **输出安全**：所有动态文本必须用 htmlspecialchars()，禁止裸插用户或配置内容。
4. **样式隔离**：根节点带唯一 id（运行时为 $componentId），所有 CSS 选择器以 #componentId 开头，类名 BEM + 组件前缀。
5. **禁止**：HTML/CSS/JS/PHP 注释、硬编码颜色（用主题变量或配置）、在 php_variables 中写 <?php ?>、if/foreach/function/class、echo/print。

## 二、你的输出如何被使用

系统会按以下顺序拼接成最终 phtml：
1. **框架 PHP 块**：已包含 $getConfig、$componentId 以及当前区域的变量（如 footer 的 $brandLogo、$col1Title、$col2Items、$showSocial、$yearDisplay 等）。你**不要**在 php_variables 中重复声明这些。
2. **你的 html_content**：直接接在框架 PHP 之后，可随意使用上述框架变量。
3. **你的 css_content + css_responsive**：放在 <style> 中。
4. **你的 js_content**：放在 <script> 中，框架会注入 `component`（DOM 元素），用 component.id 不要用 $componentId。

因此：**框架已注入的变量（见下方「当前上下文」）禁止在 php_variables 中声明**；php_variables 只写**组件独有的**、从 $component_config/$getConfig 读取的额外变量。无额外变量时 php_variables 为 ""。

## 三、框架已注入的变量（禁止在 php_variables 中重复声明）

见下方「当前上下文」中的列表。你只需在 html_content 中直接使用，不得在 php_variables 中再次声明。

## 四、必须遵守的规则

### 命名与结构
- 组件代码仅小写字母、数字、连字符（如 footer-links、hero-slider）。
- 不要输出 @component_start、@fields_start 等元数据块；系统会从你的 JSON 生成。

### CSS
- 选择器一律以 `#componentId` 开头（运行时注入），实现样式隔离。
- **在最终 phtml 中**选择器必须写成完整 PHP 短标签 `#<?= $componentId ?>`，禁止写成 `#= $componentId`（缺 <? 和 ?> 会导致选择器无效、样式不生效）。
- 类名与框架一致：footer 框架使用 ai-footer-*（如 ai-footer-social、ai-footer-brand、ai-footer-links），header 使用 ai-header-*。你输出的 HTML、CSS、JS 必须使用与框架相同的类名；禁止为框架已有结构 invent 不存在的类（例如框架已有 .ai-footer-social 时禁止写 .pb-footer-social-icons，JS/CSS 选择器应使用 .ai-footer-social）。
- 类名 BEM + 组件前缀；用 clamp() 做响应式字体；布局用 Grid 或 Flexbox。
- 颜色用主题变量：var(--pb-primary)、var(--pb-accent)、var(--pb-bg)、var(--pb-text)、var(--pb-heading)、var(--pb-link)、var(--pb-link-hover)、var(--pb-text-muted)、var(--pb-border)。禁止硬编码色值。
- 移动端样式写在 css_responsive 字段。

### HTML / PHP
- html_content 为 phtml：可用 <?php if/foreach ?> 做条件与循环；所有动态输出用 htmlspecialchars()。
- 图片必须 alt 和 loading="lazy"；语义化标签；禁止内联样式；禁止任何注释；符合 PHP 8.4 语法。

### php_variables
- 仅声明**框架未提供的**、组件独有的变量；每行一条赋值，以分号结尾；用 $var = $component_config['key'] ?? 默认值 或 $getConfig('key','默认值')。
- 禁止：<?php ?>、if/foreach/function/class、echo/print、大括号 { }。不需要时填 ""。

### js_content
- 框架已提供变量 `component`（当前组件 DOM）；用 component.id，禁止写 $componentId 或 PHP。
- 选择器必须与 HTML 中的 class 一致（如框架为 .ai-footer-social 则用 component.querySelectorAll('.ai-footer-social a')，禁止写不存在的 .pb-footer-social-icons 等）。
- 禁止使用 alert()；请使用 FrontendToast.warning / .error 或主题提供的 toast（若存在）。
- 直接写逻辑，不要 DOMContentLoaded 或 IIFE 包裹。不需要时填 ""。

### extra_fields
- 一行一条：group:组名 => 组标题，或 key => 标签:类型:默认值|选项；类型为 text、textarea、number、color、select、image。不需要时填 ""。
- 每行仅一个星号开头（* key => ...），禁止 "* * key" 双星号开头。

## 五、一次性生成流程（第一轮就按此执行，勿探讨）

1. **第一步（必做）**：调用 get_component_framework(category) 获取当前区域的框架说明与**框架已注入变量**列表。
2. **第二步（可选）**：若需参考同区域风格，调用 list_components。
3. **第三步**：根据用户描述与框架信息，**直接输出一份完整 JSON**（name、description、html_content、css_content、css_responsive、js_content、php_variables、extra_fields），不要分步「先写 HTML 再补 CSS」、不要先回复「好的我来生成」再出 JSON。
4. **第四步**：调用 validate_code 校验；若通过则**立即**在下一轮只输出最终 JSON（且仅 JSON，无任何前后文字）；若不通过则根据错误修正后**再输出一次**完整 JSON。不要多轮「我明白了再修正」。

## 六、最终输出格式（严格遵守）

当你准备交卷时，**整条消息有且仅有一个合法 JSON 对象**：
- 禁止在 JSON 前加「好的，以下是…」等说明。
- 禁止用 \`\`\`json 包裹。
- 禁止在 JSON 后加任何文字。
- 在 JSON 中，所有字符串值内**禁止使用真实换行**；换行必须写成 \n。如 extra_fields 等多行内容，用 \n 连接成一行，不要在多行字符串里写真实换行。
系统会对整条消息做 JSON 解析，非 JSON 会导致失败。

必需字段（均为字符串）：name, description, html_content（必填不能为空）, css_content, css_responsive, js_content, php_variables（仅简单赋值每行分号结尾，禁止含大括号 { }）, extra_fields。

## 七、可用工具

- get_component_framework(category) — **必调**。获取当前区域框架与框架已注入变量。
- list_components — 可选。列出该区域已有组件。
- preview_reference_component — 可选。查看某参考组件完整代码。
- validate_code — 校验你生成的 JSON；通过后再输出最终 JSON。
- get_page_layout — 可选。获取页面布局信息。

## 八、重要提醒

- **一次到位**：信息够就直接生成完整 JSON，不要多轮试探。
- **框架变量勿重复声明**：当前区域变量见「当前上下文」，禁止在 php_variables 中声明。
- **validate_code 通过即交卷**：下一轮只输出纯 JSON，不再调用工具、不加说明。
- **输出只能是 JSON**：最后一条消息 = 单个合法 JSON，从 { 到 }。
SYSTEM_PROMPT;

        // 追加区域和风格上下文（含框架已注入变量列表，与校验白名单一致）
        if (!empty($category)) {
            $prompt .= "\n\n## 当前上下文\n- 组件区域：{$category}";
            $frameworkBuilder = ObjectManager::getInstance(FrameworkBuilder::class);
            $frameworkVars = $frameworkBuilder->getFrameworkProvidedVariables($category);
            $prompt .= "\n- 框架已注入变量（禁止在 php_variables 中声明）：" . implode(', ', $frameworkVars);
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

                // AI 调用前通知：让前端知道正在连接 AI
                if ($streamCallback) {
                    $streamCallback('agent_status', [
                        'status' => 'calling_ai',
                        'message' => __('正在调用 AI 模型...'),
                        'iteration' => $currentIteration,
                    ]);
                    $streamCallback('heartbeat', ['ts' => time()]);
                }

                if ($useStreamFull) {
                    // 流式调用：实时推送 thinking/content，保持 SSE 连接活跃
                    $response = $provider->generateStreamFull($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.7),
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
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
                    // 回退：非流式调用（provider 不支持 generateStreamFull）
                    $response = $provider->generate($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float)($params['temperature'] ?? 0.7),
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
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

                // 通知：AI 完成了所有工具调用，返回最终结果
                if ($streamCallback) {
                    $streamCallback('agent_status', [
                        'status' => 'finalizing',
                        'message' => __('AI 已完成所有调用，正在输出最终结果...'),
                        'iteration' => $currentIteration,
                        'content_length' => strlen($finalContent),
                    ]);
                }

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

            // 业界标准：validate_code 通过后，用「无 tools + JSON 模式」再调一轮，强制只出 JSON，降低解析失败率
            $lastToolResultDecoded = is_array($toolResult) ? $toolResult : (json_decode(is_string($toolResult) ? $toolResult : json_encode($toolResult), true) ?: []);
            if ($toolName === 'validate_code' && !empty($lastToolResultDecoded['valid'])) {
                if ($streamCallback) {
                    $streamCallback('agent_status', [
                        'status' => 'finalizing',
                        'message' => __('验证通过，正在输出最终 JSON...'),
                        'iteration' => $iteration + 1,
                    ]);
                }
                $finalMessages = $messages;
                $finalMessages[] = [
                    'role' => 'user',
                    'content' => 'Validation passed. Reply with ONLY the component JSON object. No explanation, no markdown, no text before or after. The entire message must be a single valid JSON object starting with { and ending with }. In JSON string values use \n for newlines; do not use literal line breaks.',
                ];
                $jsonOnlyParams = [
                    'messages' => $finalMessages,
                    'temperature' => 0.3,
                    'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                    'timeout' => (int)($params['timeout'] ?? 180),
                    'response_format' => ['type' => 'json_object'],
                ];
                try {
                    if ($useStreamFull) {
                        $jsonResponse = $provider->generateStreamFull($model, '', array_merge($jsonOnlyParams, [
                            'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                                $streamCallback('thinking', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                            } : null,
                            'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                                $streamCallback('ai_response', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                            } : null,
                            'on_heartbeat' => $streamCallback ? function () use ($streamCallback) {
                                $streamCallback('heartbeat', ['ts' => time()]);
                            } : null,
                            'on_waiting' => $streamCallback ? function (int $elapsed) use ($streamCallback, $iteration) {
                                $streamCallback('agent_status', [
                                    'status' => 'waiting_ai',
                                    'message' => __('等待最终 JSON 输出... (已等待 %{1} 秒)', [$elapsed]),
                                    'iteration' => $iteration + 1,
                                    'elapsed' => $elapsed,
                                ]);
                            } : null,
                        ]));
                    } else {
                        $jsonResponse = $provider->generate($model, '', $jsonOnlyParams);
                    }
                    $finalContent = $jsonResponse['content'] ?? '';
                    if (!empty($finalContent)) {
                        if ($streamCallback) {
                            $streamCallback('chunk', ['content' => $finalContent]);
                        }
                        break;
                    }
                } catch (\Throwable $e) {
                    // 忽略，继续正常下一轮
                }
            }

            $iteration++;

            // 迭代间通知 + 心跳：保持 SSE 连接活跃
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
                    'content' => 'Reply with ONLY the component JSON object. No explanation, no markdown. The entire message must be a single valid JSON starting with { and ending with }. html_content must contain full HTML. In JSON string values use \n for newlines; do not use literal line breaks.',
                ];

                if ($useStreamFull) {
                    $forceResponse = $provider->generateStreamFull($model, '', [
                        'messages' => $finalMessages,
                        'temperature' => 0.3,
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'response_format' => ['type' => 'json_object'],
                        'on_reasoning' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                            $streamCallback('thinking', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                        } : null,
                        'on_content' => $streamCallback ? function (string $chunk) use ($streamCallback, $iteration) {
                            $streamCallback('ai_response', ['content' => $chunk, 'iteration' => $iteration + 1, 'streaming' => true]);
                        } : null,
                        'on_heartbeat' => $streamCallback ? function () use ($streamCallback) {
                            $streamCallback('heartbeat', ['ts' => time()]);
                        } : null,
                        'on_waiting' => $streamCallback ? function (int $elapsed) use ($streamCallback, $iteration) {
                            $streamCallback('agent_status', [
                                'status' => 'waiting_ai',
                                'message' => __('等待 AI 最终响应... (已等待 %{1} 秒)', [$elapsed]),
                                'iteration' => $iteration + 1,
                                'elapsed' => $elapsed,
                            ]);
                        } : null,
                    ]);
                } else {
                    $forceResponse = $provider->generate($model, '', [
                        'messages' => $finalMessages,
                        'temperature' => 0.3,
                        'max_tokens' => (int)($params['max_tokens'] ?? 16000),
                        'timeout' => (int)($params['timeout'] ?? 180),
                        'response_format' => ['type' => 'json_object'],
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
