<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\OpenAiProvider;

final class OpenAiProviderThinkingControlsTest extends TestCase
{
    public function testApplyThinkingControlsFromThinkingArrayAndModeFlag(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(OpenAiProvider::class, 'applyThinkingControls');

        $request = [];
        $method->invokeArgs($provider, [&$request, ['thinking' => ['type' => 'disabled']]]);
        self::assertSame(['type' => 'disabled'], $request['thinking']);

        $request = [];
        $method->invokeArgs($provider, [&$request, ['thinking_mode' => false, 'reasoning_effort' => 'low']]);
        self::assertSame(['type' => 'disabled'], $request['thinking']);
        self::assertSame('low', $request['reasoning_effort']);

        $request = [];
        $method->invokeArgs($provider, [&$request, ['thinking' => 'enabled', 'reasoning_effort' => 'max']]);
        self::assertSame(['type' => 'enabled'], $request['thinking']);
        self::assertSame('max', $request['reasoning_effort']);
    }

    public function testEmptyContentRecoversSiteBriefHeadingFromReasoning(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(OpenAiProvider::class, 'resolveEmptyContentFromReasoningFallback');

        $reasoning = "Plan the brief first.\n\n# Role & System Prompt\nYou are the polish assistant.\n\n# Project Context\nIndia APK site.";
        self::assertSame(
            "# Role & System Prompt\nYou are the polish assistant.\n\n# Project Context\nIndia APK site.",
            $method->invoke($provider, [], '', $reasoning, 'length'),
        );
    }

    public function testEmptyContentOptInCanReturnFullReasoning(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(OpenAiProvider::class, 'resolveEmptyContentFromReasoningFallback');

        self::assertSame(
            'only analysis text',
            $method->invoke(
                $provider,
                ['reasoning_text_fallback' => true],
                '',
                'only analysis text',
                'length',
            ),
        );
        self::assertSame(
            '',
            $method->invoke($provider, [], '', 'only analysis text', 'length'),
        );
    }

    public function testNonEmptyContentWinsOverReasoningFallback(): void
    {
        $provider = new OpenAiProvider();
        $method = new \ReflectionMethod(OpenAiProvider::class, 'resolveEmptyContentFromReasoningFallback');

        self::assertSame(
            'final answer',
            $method->invoke(
                $provider,
                ['reasoning_text_fallback' => true],
                'final answer',
                "# Role & System Prompt\nshould not win",
                'stop',
            ),
        );
    }
}
