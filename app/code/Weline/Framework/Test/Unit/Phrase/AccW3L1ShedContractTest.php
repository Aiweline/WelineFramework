<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\MsWriteBudget;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;

/**
 * ACC-W3-L1-SHED / UC-W3-02：近顶只卸 L1；禁止 OOM 重试整袋进 MS。
 */
final class AccW3L1ShedContractTest extends TestCase
{
    protected function tearDown(): void
    {
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
        DictionaryCacheNamespace::clearProcessMemoryStore();
    }

    public function testWorkerL1BoundsRemainTight(): void
    {
        $parser = (string)\file_get_contents(
            (string)(new \ReflectionClass(Parser::class))->getFileName()
        );
        self::assertMatchesRegularExpression(
            '/WORKER_TRANSLATED_WORD_CACHE_MAX_ITEMS\s*=\s*1024/',
            $parser
        );
        self::assertStringContainsString("return \\max(64, \\min(2048, (int)\$configured))", $parser);
        self::assertSame(1, DictionaryCacheNamespace::heavyLocaleResidentMax());
        self::assertSame(200, MsWriteBudget::WORD_BATCH_MAX_ITEMS);
    }

    public function testShedColdLocalesDropsProcessBagsWithoutMsWholeLocaleRetry(): void
    {
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
        DictionaryCacheNamespace::clearProcessMemoryStore();
        GlobalDictionaryModuleBagCache::bindProcessBag();

        $null = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
        GlobalDictionaryModuleBagCache::put('es_ES', $null, ['a' => 'es']);
        GlobalDictionaryModuleBagCache::put('fr_FR', $null, ['a' => 'fr']);
        GlobalDictionaryModuleBagCache::put('de_DE', $null, ['a' => 'de']);
        DictionaryCacheNamespace::touchLocaleBucket('de_DE');

        $shed = DictionaryCacheNamespace::shedColdLocalesForHeadroom(1);
        self::assertGreaterThan(0, (int)($shed['count'] ?? 0));
        self::assertNotEmpty($shed['evicted'] ?? []);

        $bagSrc = (string)\file_get_contents(
            (string)(new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName()
        );
        self::assertStringNotContainsString('$pool->set(', $bagSrc);

        $guardPath = \dirname(__DIR__, 4) . '/Server/Service/WorkerResponseMemoryGuard.php';
        self::assertFileExists($guardPath);
        $guardSrc = (string)\file_get_contents($guardPath);
        self::assertStringContainsString('shedColdLocalesForHeadroom', $guardSrc);
        self::assertStringNotContainsString('putNullSource', $guardSrc);
        self::assertStringNotContainsString('generated/language/', $guardSrc);
    }

    public function testPersistentWorkerMissPathForbidsWholeLocaleInclude(): void
    {
        $src = (string)\file_get_contents(
            (string)(new \ReflectionClass(Parser::class))->getFileName()
        );
        self::assertStringContainsString('persistent_worker_no_whole_locale_include', $src);
        self::assertStringContainsString('Misses resolve via module CSV + word-level Shared/DB', $src);
        $gatePos = \strpos($src, 'persistent_worker_no_whole_locale_include');
        $includePos = \strpos($src, '(array)include $words_file');
        self::assertNotFalse($gatePos);
        self::assertNotFalse($includePos);
        self::assertLessThan($includePos, $gatePos);
    }
}
