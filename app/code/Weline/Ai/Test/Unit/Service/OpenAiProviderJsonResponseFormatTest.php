<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\OpenAiProvider;

class OpenAiProviderJsonResponseFormatTest extends TestCase
{
    public function testBuildMessagesAddsJsonMentionWhenJsonObjectModeRequiresIt(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, '只输出结构化对象。', [
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('valid JSON', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('只输出结构化对象。', $messages[1]['content']);
    }

    public function testBuildMessagesKeepsExistingJsonInstructionsUntouched(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, 'Return JSON only.', [
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Return JSON only.', $messages[0]['content']);
    }

    public function testBuildMessagesAppendsJsonMentionToExistingSystemMessage(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, '', [
            'messages' => [
                ['role' => 'system', 'content' => 'Be concise.'],
                ['role' => 'user', 'content' => '输出结构化对象。'],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame("Be concise.\n\nReturn the response as valid JSON.", $messages[0]['content']);
    }

    public function testBuildChatCompletionRequestDataDisablesDeepSeekThinkingForJsonObjectByDefault(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('deepseek', 'deepseek-v4-pro');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 256,
        ], true);

        $this->assertSame(['type' => 'disabled'], $requestData['thinking'] ?? null);
        $this->assertSame(['type' => 'json_object'], $requestData['response_format'] ?? null);
    }

    public function testBuildChatCompletionRequestDataKeepsExplicitDeepSeekThinkingControls(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('deepseek', 'deepseek-v4-pro');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'thinking' => ['type' => 'enabled'],
            'reasoning_effort' => 'max',
            'max_tokens' => 256,
        ], true);

        $this->assertSame(['type' => 'enabled'], $requestData['thinking'] ?? null);
        $this->assertSame('max', $requestData['reasoning_effort'] ?? null);
    }

    public function testBuildChatCompletionRequestDataEnablesDeepSeekThinkingFromModeAlias(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('deepseek', 'deepseek-v4-pro');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'thinking_mode' => true,
            'thinking_budget_tokens' => 2048,
            'reasoning_effort' => 'medium',
            'max_tokens' => 256,
        ], true);

        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $requestData['thinking'] ?? null);
        $this->assertSame('medium', $requestData['reasoning_effort'] ?? null);
        $this->assertSame(['type' => 'json_object'], $requestData['response_format'] ?? null);
    }

    public function testBuildChatCompletionRequestDataDoesNotSendThinkingControlsToPlainOpenAiModel(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('openai', 'gpt-4o');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'thinking_mode' => true,
            'reasoning_effort' => 'medium',
            'max_tokens' => 256,
        ], true);

        $this->assertArrayNotHasKey('thinking', $requestData);
        $this->assertArrayNotHasKey('reasoning_effort', $requestData);
    }

    public function testBuildChatCompletionRequestDataSendsReasoningEffortForGpt55(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('openai', 'gpt-5.5');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Solve this complex coding problem.'],
        ], [
            'reasoning_effort' => 'high',
            'max_tokens' => 1024,
        ], true);

        $this->assertSame('high', $requestData['reasoning_effort'] ?? null);
        $this->assertArrayNotHasKey('thinking', $requestData);
    }

    public function testBuildChatCompletionRequestDataDefaultsReasoningEffortToMinimalForJsonReasoningModel(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('openai', 'gpt-5.5');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 512,
        ], true);

        $this->assertSame('minimal', $requestData['reasoning_effort'] ?? null);
    }

    public function testBuildChatCompletionRequestDataMapsQwenThinkingControlsToBooleanToggle(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('qwen', 'qwen3-32b');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Return JSON only.'],
        ], [
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 512,
        ], true);

        $this->assertSame(false, $requestData['enable_thinking'] ?? null);
        $this->assertArrayNotHasKey('thinking', $requestData);
    }

    public function testResolveReasoningOnlyFallbackContentExtractsJsonFromReasoningFence(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod($provider, 'resolveReasoningOnlyFallbackContent');
        $method->setAccessible(true);

        $result = $method->invoke(
            $provider,
            "analysis...\n```json\n{\"ok\":true,\"items\":[1,2]}\n```\nmore notes",
            ['response_format' => ['type' => 'json_object']]
        );

        $this->assertSame('{"ok":true,"items":[1,2]}', $result);
    }

    public function testResolveReasoningOnlyFallbackContentReturnsNullForNonJsonResponseFormat(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod($provider, 'resolveReasoningOnlyFallbackContent');
        $method->setAccessible(true);

        $result = $method->invoke(
            $provider,
            "{\"ok\":true}",
            ['response_format' => ['type' => 'text']]
        );

        $this->assertNull($result);
    }

    public function testBuildChatCompletionRequestDataSendsReasoningEffortForCodexModel(): void
    {
        $provider = new OpenAiProvider();
        $model = $this->createModel('openai', 'gpt-5.3-codex');

        $requestData = $this->invokeBuildChatCompletionRequestData($provider, $model, [], [
            ['role' => 'user', 'content' => 'Refactor this function.'],
        ], [
            'reasoning_effort' => 'medium',
            'max_tokens' => 1024,
        ], true);

        $this->assertSame('medium', $requestData['reasoning_effort'] ?? null);
        $this->assertArrayNotHasKey('thinking', $requestData);
    }

    public function testBillingAndAuthErrorsAreNotRetried(): void
    {
        $provider = new OpenAiProvider();

        $httpMethod = new \ReflectionMethod($provider, 'isNonRetryableApiError');
        $httpMethod->setAccessible(true);
        $messageMethod = new \ReflectionMethod($provider, 'isNonRetryableApiErrorMessage');
        $messageMethod->setAccessible(true);

        $this->assertTrue($httpMethod->invoke($provider, 402, 'Insufficient Balance'));
        $this->assertTrue($messageMethod->invoke($provider, 'Insufficient Balance'));
        $this->assertTrue($messageMethod->invoke($provider, 'You exceeded your current quota, please check your plan and billing details.'));
        $this->assertFalse($messageMethod->invoke($provider, 'temporary upstream timeout'));
    }

    private function invokeBuildMessages(OpenAiProvider $provider, string $prompt, array $params): array
    {
        $reflectionMethod = new \ReflectionMethod($provider, 'buildMessages');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($provider, $prompt, $params);
    }

    private function invokeBuildChatCompletionRequestData(
        OpenAiProvider $provider,
        AiModel $model,
        array $config,
        array $messages,
        array $params,
        bool $stream
    ): array {
        $reflectionMethod = new \ReflectionMethod($provider, 'buildChatCompletionRequestData');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($provider, $model, $config, $messages, $params, $stream);
    }

    private function createModel(string $supplier, string $modelCode): AiModel
    {
        $model = new AiModel();
        $model->setSupplier($supplier);
        $model->setModelCode($modelCode);
        $model->setMaxTokens(4096);

        return $model;
    }
}
