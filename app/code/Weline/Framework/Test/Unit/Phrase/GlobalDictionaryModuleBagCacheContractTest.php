<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;

final class GlobalDictionaryModuleBagCacheContractTest extends TestCase
{
    public function testSharedKeyStableAndMatchesParserNullSourceShape(): void
    {
        $locale = 'lt_LT';
        $module = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
        $expected = 'global_dictionary_module_words|' . $locale . '|v1|' . \sha1($module);
        self::assertSame($expected, GlobalDictionaryModuleBagCache::sharedKey($locale, $module));
        self::assertSame($expected, GlobalDictionaryModuleBagCache::nullSourceSharedKey($locale));
        self::assertSame(3600, GlobalDictionaryModuleBagCache::SHARED_TTL_SECONDS);

        $parserSource = \file_get_contents(
            (string)(new \ReflectionClass(Parser::class))->getFileName()
        );
        self::assertIsString($parserSource);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::get', $parserSource);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::put', $parserSource);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::pool', $parserSource);
        self::assertStringContainsString('整袋不再读写 Shared Memory', $parserSource);
        self::assertStringNotContainsString(
            "'global_dictionary_module_words|' . \$lang . '|v1|' . \\sha1(\$module)",
            $parserSource
        );
    }

    public function testPutAndGetStayInProcessMemoryOnly(): void
    {
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
        $locale = 'hi_IN';
        $module = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
        $bag = ['hello' => 'नमस्ते'];
        self::assertTrue(GlobalDictionaryModuleBagCache::put($locale, $module, $bag));
        self::assertSame($bag, GlobalDictionaryModuleBagCache::get($locale, $module));
        self::assertSame($bag, GlobalDictionaryModuleBagCache::getNullSource($locale));

        $source = \file_get_contents(
            (string)(new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName()
        );
        self::assertIsString($source);
        self::assertStringContainsString('processBags', $source);
        self::assertStringContainsString('touchLocaleBucket', $source);
        self::assertStringNotContainsString('$pool->set(', $source);
        self::assertStringNotContainsString('$pool->get(', $source);
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
    }

    public function testSoftPressureEvictsColdLocaleDictionaryBags(): void
    {
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
        DictionaryCacheNamespace::clearProcessMemoryStore();
        GlobalDictionaryModuleBagCache::bindProcessBag();

        $null = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
        GlobalDictionaryModuleBagCache::put('es_ES', $null, ['a' => 'es']);
        DictionaryCacheNamespace::pinLocaleBucket('es_ES');
        GlobalDictionaryModuleBagCache::put('fr_FR', $null, ['a' => 'fr']);
        GlobalDictionaryModuleBagCache::put('de_DE', $null, ['a' => 'de']);
        // de_DE is newest; fr_FR is colder among unpinned.
        DictionaryCacheNamespace::touchLocaleBucket('de_DE');

        $result = DictionaryCacheNamespace::processMemoryReclaimable()->compact();
        self::assertEmpty($result['skipped'] ?? null);

        self::assertSame(['a' => 'es'], GlobalDictionaryModuleBagCache::getNullSource('es_ES'));
        self::assertTrue(
            GlobalDictionaryModuleBagCache::getNullSource('fr_FR') === null
            || GlobalDictionaryModuleBagCache::getNullSource('de_DE') === null,
            'soft reclaim must drop at least one cold unpinned locale bag',
        );

        DictionaryCacheNamespace::unpinLocaleBucket('es_ES');
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
        DictionaryCacheNamespace::clearProcessMemoryStore();
    }
}
