<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Phrase\Parser;

/**
 * __()/translate 只读文件与缓存层：禁止 DB 全局词典、dictionary_collect、缺词写回。
 */
final class ParserTranslateReadOnlyContractTest extends TestCase
{
    public function testDoTranslateWordFromLayersDoesNotCallGlobalDictionaryWord(): void
    {
        $source = (string)file_get_contents(
            (new \ReflectionClass(Parser::class))->getFileName()
        );
        $start = strpos($source, 'private static function doTranslateWordFromLayers(');
        $end = strpos($source, 'private static function translationFromLoadedLayers(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringNotContainsString('loadGlobalDictionaryWord(', $method);
        self::assertStringNotContainsString('reportMissing(', $method);
        self::assertStringContainsString('getPrefetchedGlobalWord(', $method);
        self::assertStringContainsString('只读已装入的文件层', $method);
    }

    public function testProcessWordsDoesNotWriteMissIntoDictionary(): void
    {
        $source = (string)file_get_contents(
            (new \ReflectionClass(Parser::class))->getFileName()
        );
        $start = strpos($source, 'protected static function processWords(string $words): string');
        $end = strpos($source, 'public static function getUsedWords(): array', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringNotContainsString('self::$words[$words] = $words', $method);
        self::assertStringNotContainsString('reportMissing(', $method);
        self::assertStringContainsString('禁止写回词典', $method);
    }

    public function testGetCurrentLayeredWordsUsesFileOnlyLayers(): void
    {
        $source = (string)file_get_contents(
            (new \ReflectionClass(Parser::class))->getFileName()
        );
        $start = strpos($source, 'private static function getCurrentLayeredWords(): array');
        $end = strpos($source, 'private static function buildLayeredWordsCacheKey(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('getLayeredWords($lang, $modules, $locales)', $method);
        self::assertStringNotContainsString('includeGlobalDictionary', $method);
    }

    public function testClearWorkerCachesPreservesUpgradeLightFlag(): void
    {
        $source = (string)file_get_contents(
            (new \ReflectionClass(Parser::class))->getFileName()
        );
        $start = strpos($source, 'public static function clearWorkerCaches(): void');
        $end = strpos($source, 'private static function bindDictionaryProcessBags(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringNotContainsString('self::$setupUpgradeLightDictionary = false', $method);
    }

    public function testEventDictionaryNoLongerDispatchesCollectOrMissingEvents(): void
    {
        $source = (string)file_get_contents(
            (new \ReflectionClass(EventDictionary::class))->getFileName()
        );
        self::assertStringContainsString('function peekState(string $locale): array', $source);
        self::assertStringNotContainsString('ensureCollected', $source);
        self::assertStringNotContainsString('dictionary_collect', $source);
        self::assertStringNotContainsString('word_missing', $source);
        self::assertStringNotContainsString('reportMissing', $source);
    }

    public function testMissReturnsSourceFromLoadedLayersOnly(): void
    {
        $layers = [
            'cache_key' => 'fixture-readonly',
            'lang' => 'en_US',
            'locales' => ['en_US'],
            'modules' => ['Weline_Test'],
            'module_words' => ['Weline_Test' => ['Known' => 'Known EN']],
            'locale_words' => [],
            'locale_word_layers' => [],
            'global_words' => [],
            'global_dictionary_loaded' => true,
        ];
        $method = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
        $method->setAccessible(true);

        self::assertSame('Known EN', $method->invoke(null, 'Known', $layers));
        self::assertSame('Unknown source', $method->invoke(null, 'Unknown source', $layers));
    }
}
