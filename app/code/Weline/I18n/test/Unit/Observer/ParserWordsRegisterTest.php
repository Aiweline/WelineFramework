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

        $backendWords = [
            'Existing Word' => 'Existing Translation',
            'Used Word' => 'Translated Used Word',
        ];
        $globalWords = [
            'Global Word' => 'Global Translation',
            'Used Word' => 'Translated Used Word',
        ];
        $batchWords = ['Used Word' => 'Translated Used Word'];

        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::exactly(4))
            ->method('get')
            ->willReturnMap([
                [$this->buildBatchMarkerKey(ParserWordsRegister::BACKEND_WORDS_CACHE_KEY, $batchWords), false],
                [ParserWordsRegister::BACKEND_WORDS_CACHE_KEY, ['Existing Word' => 'Existing Translation']],
                [$this->buildBatchMarkerKey(ParserWordsRegister::WORDS_CACHE_KEY, $batchWords), false],
                [ParserWordsRegister::WORDS_CACHE_KEY, ['Global Word' => 'Global Translation']],
            ]);
        $setCalls = [];
        $cache->expects(self::exactly(4))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value, int $ttl = 0) use (&$setCalls): bool {
                $setCalls[$key] = ['value' => $value, 'ttl' => $ttl];
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
            $backendWords,
            $setCalls[ParserWordsRegister::BACKEND_WORDS_CACHE_KEY]['value'] ?? null
        );
        self::assertSame(
            $globalWords,
            $setCalls[ParserWordsRegister::WORDS_CACHE_KEY]['value'] ?? null
        );
        self::assertSame(86400, $setCalls[$this->buildBatchMarkerKey(ParserWordsRegister::BACKEND_WORDS_CACHE_KEY, $batchWords)]['ttl'] ?? null);
        self::assertSame(86400, $setCalls[$this->buildBatchMarkerKey(ParserWordsRegister::WORDS_CACHE_KEY, $batchWords)]['ttl'] ?? null);
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

    public function testExecuteSkipsDictionaryMergeWhenBatchWasAlreadyRecorded(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('isBackend')->willReturn(true);

        $words = ['Used Word' => 'Translated Used Word'];
        $cache = $this->createMock(CachePoolInterface::class);
        $cache->expects(self::exactly(2))
            ->method('get')
            ->willReturnMap([
                [$this->buildBatchMarkerKey(ParserWordsRegister::BACKEND_WORDS_CACHE_KEY, $words), 1],
                [$this->buildBatchMarkerKey(ParserWordsRegister::WORDS_CACHE_KEY, $words), 1],
            ]);
        $cache->expects(self::never())->method('set');

        $observer = new ParserWordsRegister($request);
        $this->setObserverCache($observer, $cache);
        $this->setParserState($words, ['Used Word' => 'Used Word']);

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

        // usedWords 已从 Parser 静态属性迁到 ParserRequestState（Parser 只保留静态 $words）；
        // 按仓内迁移后写法（Framework/test/Unit/Phrase/ParserClearUsedWordsTest）经 requestState() 取 state 再赋值。
        $stateMethod = new \ReflectionMethod(Parser::class, 'requestState');
        $stateMethod->setAccessible(true);
        /** @var \Weline\Framework\Phrase\ParserRequestState $state */
        $state = $stateMethod->invoke(null);
        $state->usedWords = $usedWords;

        // getUsedWordsWithTranslations() 的译文走「分层词典 $layerCacheKey.'|'.$word」缓存；
        // 预填该缓存，使本测试不依赖运行环境的真实词典文件（原实现只设 usedWords，
        // 在 en_US 环境无对应条目时会回落到原词，导致断言假失败）。
        $layersMethod = new \ReflectionMethod(Parser::class, 'getCurrentLayeredWords');
        $layersMethod->setAccessible(true);
        $layerCacheKey = (string)($layersMethod->invoke(null)['cache_key'] ?? '');
        foreach ($translations as $word => $translation) {
            $state->translatedWords[$layerCacheKey . '|' . $word] = $translation;
        }
    }

    /**
     * @param array<string, string> $words
     */
    private function buildBatchMarkerKey(string $cacheKey, array $words): string
    {
        $observer = new ParserWordsRegister($this->createMock(Request::class));
        $method = new \ReflectionMethod(ParserWordsRegister::class, 'buildBatchMarkerKey');
        $method->setAccessible(true);

        return $method->invoke($observer, $cacheKey, $words);
    }
}
