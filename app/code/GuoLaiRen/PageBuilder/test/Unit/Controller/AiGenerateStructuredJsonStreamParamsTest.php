<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiGenerate;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AiGenerate 三个流式 JSON 任务（component-config-stream / page-content-stream /
 * component-stream）必须用结构化 JSON 安全参数调用 AiService::generateStream。
 *
 * 历史故障：DeepSeek V4 / GLM 等 thinking 协议模型在没显式禁用 thinking 时，会
 * 只产出 reasoning_content（思维链）而不返回最终 content；OpenAiProvider 会抛出
 * "AI stream returned reasoning_content only without final content"，前端表现为
 * "AI 生成无任何响应"。
 *
 * 本测试锁定 withStructuredJsonStreamParams() 的不变性：
 *   - 强制 response_format=json_object
 *   - 默认（allowThinkingStream=false）：强制 thinking/thinking_mode/enable_thinking/enable_reasoning 全部禁用；
 *     移除可能由队列默认参数注入的 reasoning_effort / thinking_budget(_tokens)
 *   - allowThinkingStream=true：仍强制 json_object，但不合并禁用 thinking，也不剔除 reasoning_effort 等
 *   - 调用方传入的业务参数（如 component_meta_text_configs / reference_code）必须保留
 */
final class AiGenerateStructuredJsonStreamParamsTest extends TestCase
{
    private function invoke(array $callerParams, bool $allowThinkingStream = false): array
    {
        $controller = new AiGenerate(
            $this->createMock(PageModel::class),
            $this->createMock(Style::class)
        );
        $method = new ReflectionMethod(AiGenerate::class, 'withStructuredJsonStreamParams');
        $method->setAccessible(true);

        return $method->invoke($controller, $callerParams, $allowThinkingStream);
    }

    public function testForcesJsonObjectResponseFormatAndDisablesThinking(): void
    {
        $params = $this->invoke([]);

        self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
        self::assertSame(['type' => 'disabled'], $params['thinking'] ?? null);
        self::assertSame('disabled', $params['thinking_mode'] ?? null);
        self::assertFalse($params['enable_thinking'] ?? true);
        self::assertFalse($params['enable_reasoning'] ?? true);
    }

    public function testStripsLeakedReasoningEffortAndThinkingBudgetParams(): void
    {
        $params = $this->invoke([
            'reasoning_effort' => 'medium',
            'thinking_budget' => 4096,
            'thinking_budget_tokens' => 4096,
        ]);

        self::assertArrayNotHasKey('reasoning_effort', $params);
        self::assertArrayNotHasKey('thinking_budget', $params);
        self::assertArrayNotHasKey('thinking_budget_tokens', $params);
    }

    public function testOverridesCallerLevelThinkingDefaultsAggressively(): void
    {
        $params = $this->invoke([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 2048],
            'thinking_mode' => true,
            'enable_thinking' => true,
            'enable_reasoning' => true,
            'response_format' => null,
        ]);

        self::assertSame(['type' => 'disabled'], $params['thinking']);
        self::assertSame('disabled', $params['thinking_mode']);
        self::assertFalse($params['enable_thinking']);
        self::assertFalse($params['enable_reasoning']);
        self::assertSame(['type' => 'json_object'], $params['response_format']);
    }

    public function testPreservesUnrelatedBusinessParams(): void
    {
        $reasoningCallback = static function (): void {};
        $textConfigs = [['key' => 'texts.title', 'is_list_like' => false]];

        $params = $this->invoke([
            'component_meta_text_configs' => $textConfigs,
            'reference_component' => 'banner',
            'reference_code' => 'demo-banner',
            'reasoning_callback' => $reasoningCallback,
            'user_id' => 99,
            'is_backend' => true,
        ]);

        self::assertSame($textConfigs, $params['component_meta_text_configs']);
        self::assertSame('banner', $params['reference_component']);
        self::assertSame('demo-banner', $params['reference_code']);
        self::assertSame($reasoningCallback, $params['reasoning_callback']);
        self::assertSame(99, $params['user_id']);
        self::assertTrue($params['is_backend']);
    }

    public function testAllowThinkingStreamKeepsJsonObjectWithoutForcingThinkingDisabled(): void
    {
        $params = $this->invoke([
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'reasoning_effort' => 'medium',
        ], true);

        self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
        self::assertSame(['type' => 'enabled'], $params['thinking'] ?? null);
        self::assertTrue((bool) ($params['thinking_mode'] ?? false));
        self::assertSame('medium', $params['reasoning_effort'] ?? null);
    }
}
