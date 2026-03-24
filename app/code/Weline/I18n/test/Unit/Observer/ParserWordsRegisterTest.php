<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Phrase\Parser;
use Weline\I18n\Observer\ParserWordsRegister;

class ParserWordsRegisterTest extends TestCase
{
    protected function setUp(): void
    {
        $this->setParserState([], []);
    }

    protected function tearDown(): void
    {
        $this->setParserState([], []);
    }

    public function testExecuteCollectsOnlyUsedWordsWithTranslations(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('isBackend')->willReturn(true);

        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::exactly(2))
            ->method('get')
            ->willReturnMap([
                [ParserWordsRegister::BACKEND_WORDS_CACHE_KEY, ['Existing Word' => 'Existing Translation']],
                [ParserWordsRegister::WORDS_CACHE_KEY, ['Global Word' => 'Global Translation']],
            ]);
        $setCalls = [];
        $cache->expects(self::exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$setCalls): bool {
                $setCalls[$key] = $value;
                return true;
            });

        $observer = new ParserWordsRegister($request);
        $this->setObserverCache($observer, $cache);
        $this->setParserState(
            [
                'Used Word' => 'Translated Used Word',
                'Unused Word' => 'Translated Unused Word',
            ],
            ['Used Word' => 'Used Word']
        );

        $event = $this->createMock(Event::class);
        $observer->execute($event);

        self::assertSame(
            [
                'Existing Word' => 'Existing Translation',
                'Used Word' => 'Translated Used Word',
            ],
            $setCalls[ParserWordsRegister::BACKEND_WORDS_CACHE_KEY] ?? null
        );
        self::assertSame(
            [
                'Global Word' => 'Global Translation',
                'Used Word' => 'Translated Used Word',
            ],
            $setCalls[ParserWordsRegister::WORDS_CACHE_KEY] ?? null
        );
    }

    public function testExecuteSkipsCacheWritesWhenNoWordsWereUsed(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects(self::never())->method('isBackend');

        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::never())->method('set');

        $observer = new ParserWordsRegister($request);
        $this->setObserverCache($observer, $cache);
        $this->setParserState(['Used Word' => 'Translated Used Word'], []);

        $event = $this->createMock(Event::class);
        $observer->execute($event);
    }

    private function setObserverCache(ParserWordsRegister $observer, CachePoolInterface $cache): void
    {
        $property = new \ReflectionProperty(ParserWordsRegister::class, 'cache');
        $property->setAccessible(true);
        $property->setValue($observer, $cache);
    }

    /**
     * @param array<string, string> $translations
     * @param array<string, string> $usedWords
     */
    private function setParserState(array $translations, array $usedWords): void
    {
        $wordsProperty = new \ReflectionProperty(Parser::class, 'words');
        $wordsProperty->setAccessible(true);
        $wordsProperty->setValue(null, $translations);

        $usedWordsProperty = new \ReflectionProperty(Parser::class, 'usedWords');
        $usedWordsProperty->setAccessible(true);
        $usedWordsProperty->setValue(null, $usedWords);
    }
}
