<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiRuntimeContext;

final class AiRuntimeContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AiRuntimeContext::removeDefaultParams();
    }

    protected function tearDown(): void
    {
        AiRuntimeContext::removeDefaultParams();
        parent::tearDown();
    }

    public function testMergeDefaultParamsKeepsExplicitParamsAuthoritative(): void
    {
        AiRuntimeContext::setDefaultParams([
            'thinking_mode' => true,
            'reasoning_effort' => 'medium',
            'temperature' => 0.2,
        ]);

        $merged = AiRuntimeContext::mergeDefaultParams([
            'temperature' => 0.7,
            'max_tokens' => 1024,
        ]);

        self::assertTrue($merged['thinking_mode']);
        self::assertSame('medium', $merged['reasoning_effort']);
        self::assertSame(0.7, $merged['temperature']);
        self::assertSame(1024, $merged['max_tokens']);
    }

    public function testThinkingModeParamsUsesMediumReasoningByDefault(): void
    {
        self::assertSame([
            'thinking_mode' => true,
            'reasoning_effort' => 'medium',
        ], AiRuntimeContext::thinkingModeParams());
    }
}
