<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\Parser;

final class ParserClearUsedWordsTest extends TestCase
{
    protected function tearDown(): void
    {
        Parser::clearUsedWords();
    }

    public function testClearUsedWordsEmptiesRequestPhraseBag(): void
    {
        $method = new \ReflectionMethod(Parser::class, 'requestState');
        $method->setAccessible(true);
        /** @var \Weline\Framework\Phrase\ParserRequestState $state */
        $state = $method->invoke(null);
        $state->usedWords = [
            'hello' => 'hello',
            'world' => 'world',
        ];

        self::assertSame(['hello' => 'hello', 'world' => 'world'], Parser::getUsedWords());
        Parser::clearUsedWords();
        self::assertSame([], Parser::getUsedWords());
    }

    public function testClearWorkerCachesAlsoClearsUsedWords(): void
    {
        $method = new \ReflectionMethod(Parser::class, 'requestState');
        $method->setAccessible(true);
        /** @var \Weline\Framework\Phrase\ParserRequestState $state */
        $state = $method->invoke(null);
        $state->usedWords = ['keep' => 'keep'];

        Parser::clearWorkerCaches();
        self::assertSame([], Parser::getUsedWords());
    }
}
