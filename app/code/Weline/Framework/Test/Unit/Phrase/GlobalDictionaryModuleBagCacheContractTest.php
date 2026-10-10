<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
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
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::sharedKey', $parserSource);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::pool', $parserSource);
        self::assertStringNotContainsString(
            "'global_dictionary_module_words|' . \$lang . '|v1|' . \\sha1(\$module)",
            $parserSource
        );
    }
}
