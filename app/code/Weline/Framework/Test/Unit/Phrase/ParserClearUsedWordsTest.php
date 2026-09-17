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
        $ref = new \ReflectionClass(Parser::class);
        $prop = $ref->getProperty('usedWords');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'hello' => 'hello',
            'world' => 'world',
        ]);

        self::assertSame(['hello' => 'hello', 'world' => 'world'], Parser::getUsedWords());
        Parser::clearUsedWords();
        self::assertSame([], Parser::getUsedWords());
    }

    public function testClearWorkerCachesAlsoClearsUsedWords(): void
    {
        $ref = new \ReflectionClass(Parser::class);
        $prop = $ref->getProperty('usedWords');
        $prop->setAccessible(true);
        $prop->setValue(null, ['keep' => 'keep']);

        Parser::clearWorkerCaches();
        self::assertSame([], Parser::getUsedWords());
    }
}
