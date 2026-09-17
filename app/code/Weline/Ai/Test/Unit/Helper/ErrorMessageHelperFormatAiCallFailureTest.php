<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Helper\ErrorMessageHelper;

final class ErrorMessageHelperFormatAiCallFailureTest extends TestCase
{
    public function testFormatIncludesProviderAndModel(): void
    {
        $message = ErrorMessageHelper::formatAiCallFailureMessage(
            'API请求失败: Could not connect to server',
            'ollama',
            'translategemma',
            'Translate Gemma Local',
            'generate'
        );

        self::assertStringContainsString('供应商：ollama', $message);
        self::assertStringContainsString('模型：Translate Gemma Local (translategemma)', $message);
        self::assertStringContainsString('API请求失败: Could not connect to server', $message);
        self::assertStringStartsWith('AI生成失败', $message);
    }

    public function testFormatStreamKindUsesStreamPrefix(): void
    {
        $message = ErrorMessageHelper::formatAiCallFailureMessage(
            'timeout',
            'openai',
            'gpt-4o-mini',
            'gpt-4o-mini',
            'stream'
        );

        self::assertStringStartsWith('AI流式生成失败', $message);
        self::assertStringContainsString('供应商：openai', $message);
        self::assertStringContainsString('模型：gpt-4o-mini', $message);
        self::assertStringNotContainsString('gpt-4o-mini (gpt-4o-mini)', $message);
    }

    public function testFormatFallsBackWhenProviderMissing(): void
    {
        $message = ErrorMessageHelper::formatAiCallFailureMessage(
            'boom',
            null,
            'local-model',
            null,
            'generate'
        );

        self::assertStringContainsString('模型：local-model', $message);
        self::assertStringNotContainsString('供应商：', $message);
    }
}
