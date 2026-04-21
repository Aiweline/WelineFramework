<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Http\Sse;

use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QueueDbWriterTokenUsageTest extends TestCase
{
    public function testNormalizesProviderUsageAliasesAndCostMeta(): void
    {
        $writer = new QueueDbWriter(1, 2, 3);
        $method = new ReflectionMethod(QueueDbWriter::class, 'normalizeTokenUsage');
        $method->setAccessible(true);

        $usage = $method->invoke($writer, [
            'prompt_tokens' => '120',
            'completion_tokens' => 34.4,
            'prompt_tokens_details' => ['cached_tokens' => 9],
            'cost' => ['input' => '0.0012'],
        ]);

        self::assertSame(120, $usage['input_tokens']);
        self::assertSame(34, $usage['output_tokens']);
        self::assertSame(154, $usage['total_tokens']);
        self::assertSame(['cached_tokens' => 9], $usage['token_cost_meta']['prompt_tokens_details']);
        self::assertSame(['input' => '0.0012'], $usage['token_cost_meta']['cost']);
    }

    public function testMergesTokenUsageAcrossMultipleAiCalls(): void
    {
        $writer = new QueueDbWriter(1, 2, 3);
        $method = new ReflectionMethod(QueueDbWriter::class, 'mergeTokenUsage');
        $method->setAccessible(true);

        $merged = $method->invoke(
            $writer,
            [
                'input_tokens' => 100,
                'output_tokens' => 40,
                'total_tokens' => 140,
                'token_cost_meta' => null,
            ],
            [
                'input_tokens' => 25,
                'output_tokens' => 15,
                'total_tokens' => 40,
                'token_cost_meta' => null,
            ]
        );

        self::assertSame(125, $merged['input_tokens']);
        self::assertSame(55, $merged['output_tokens']);
        self::assertSame(180, $merged['total_tokens']);
    }
}
