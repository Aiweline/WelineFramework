<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcBypassEvaluator;
use Weline\Framework\Http\Fpc\FpcBypassFactsBuilder;

final class FpcBypassFactsBuilderContractTest extends TestCase
{
    public function testBuildIncludesEditorAndCdnEnvKeys(): void
    {
        $facts = FpcBypassFactsBuilder::build(
            '/?x=1',
            ['x' => '1'],
            '',
            [],
            false,
        );
        self::assertArrayHasKey('env', $facts);
        self::assertArrayHasKey('editor_mode', $facts['env']);
        self::assertArrayHasKey('cdn_fpc_dev_mode', $facts['env']);
        self::assertSame('', $facts['env']['cdn_fpc_dev_mode']);
    }

    public function testCdnDevModeFlagTriggersBypassWithProviderRule(): void
    {
        $facts = FpcBypassFactsBuilder::build('/', [], '', [], true);
        self::assertSame('1', $facts['env']['cdn_fpc_dev_mode']);
        self::assertTrue(FpcBypassEvaluator::shouldBypass($facts, [
            [
                'id' => 'cdn.fpc_dev_mode',
                'match' => ['env_flags' => ['cdn_fpc_dev_mode']],
                'effect' => 'bypass_serve_and_publish',
            ],
        ]));
        $factsOff = FpcBypassFactsBuilder::build('/', [], '', [], false);
        self::assertFalse(FpcBypassEvaluator::shouldBypass($factsOff, [
            [
                'id' => 'cdn.fpc_dev_mode',
                'match' => ['env_flags' => ['cdn_fpc_dev_mode']],
            ],
        ]));
    }
}
