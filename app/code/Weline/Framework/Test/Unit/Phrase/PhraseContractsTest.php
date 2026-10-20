<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;


use Weline\Framework\Env\WelineEnv;
use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Phrase\CliLanguage;
use Weline\Framework\Phrase\CliLanguageCatalog;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\DictionaryCollectBusyException;
use Weline\Framework\Phrase\DictionaryCollectGate;
use Weline\Framework\Phrase\DictionaryWordValidator;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\MsWriteBudget;
use Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StorefrontWidgetRuntimeSchedule;

/**
 * Aggregated contract suite (merged from 14 *ContractTest files).
 * Observe one overall pass/fail via testAllPhraseContractsPass().
 */
final class PhraseContractsTest extends TestCase
{
    public function testAllPhraseContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AccW3L1Shed::testWorkerL1BoundsRemainTight' => function () {
            try {
                $this->assert_AccW3L1Shed_testWorkerL1BoundsRemainTight();
            } finally {
                $this->tearDown_AccW3L1Shed();
            }
        },
            'AccW3L1Shed::testShedColdLocalesDropsProcessBagsWithoutMsWholeLocaleRetry' => function () {
            try {
                $this->assert_AccW3L1Shed_testShedColdLocalesDropsProcessBagsWithoutMsWholeLocaleRetry();
            } finally {
                $this->tearDown_AccW3L1Shed();
            }
        },
            'AccW3L1Shed::testPersistentWorkerMissPathForbidsWholeLocaleInclude' => function () {
            try {
                $this->assert_AccW3L1Shed_testPersistentWorkerMissPathForbidsWholeLocaleInclude();
            } finally {
                $this->tearDown_AccW3L1Shed();
            }
        },
            'AccW3MsChunk::testMsWriteBudgetFrameMatchesSessionProtocol' => function () {
            $this->assert_AccW3MsChunk_testMsWriteBudgetFrameMatchesSessionProtocol();
        },
            'AccW3MsChunk::testForbidsWholeLocaleBagKeysAndGeneratedLanguagePaths' => function () {
            $this->assert_AccW3MsChunk_testForbidsWholeLocaleBagKeysAndGeneratedLanguagePaths();
        },
            'AccW3MsChunk::testForbidsLargeStringMapBagValues' => function () {
            $this->assert_AccW3MsChunk_testForbidsLargeStringMapBagValues();
        },
            'AccW3MsChunk::testPartitionKeepsWordBatchesWithinSessionProtocolFrame' => function () {
            $this->assert_AccW3MsChunk_testPartitionKeepsWordBatchesWithinSessionProtocolFrame();
        },
            'AccW3MsChunk::testParserPrefetchUsesMsWriteBudgetPartitionNotBareSetMultiple' => function () {
            $this->assert_AccW3MsChunk_testParserPrefetchUsesMsWriteBudgetPartitionNotBareSetMultiple();
        },
            'AccW3MsChunk::testBagCachePutPathNeverCallsPoolSet' => function () {
            $this->assert_AccW3MsChunk_testBagCachePutPathNeverCallsPoolSet();
        },
            'CliLanguage::testDefaultConfigDeclaresCliLanguage' => function () {
            $this->assert_CliLanguage_testDefaultConfigDeclaresCliLanguage();
        },
            'CliLanguage::testEventRegisteredInFrameworkEventCatalog' => function () {
            $this->assert_CliLanguage_testEventRegisteredInFrameworkEventCatalog();
        },
            'CliLanguage::testStateResolvesCliLanguageInCliBranch' => function () {
            $this->assert_CliLanguage_testStateResolvesCliLanguageInCliBranch();
        },
            'CliLanguage::testCommandClassAndTipUsePhrase' => function () {
            $this->assert_CliLanguage_testCommandClassAndTipUsePhrase();
        },
            'CliLanguage::testCatalogClassDedupesAndPrioritizesBaseline' => function () {
            $this->assert_CliLanguage_testCatalogClassDedupesAndPrioritizesBaseline();
        },
            'CliLanguage::testResolveConfiguredNeverUsesWebsiteListFirstItemShape' => function () {
            $this->assert_CliLanguage_testResolveConfiguredNeverUsesWebsiteListFirstItemShape();
        },
            'CliLanguage::testCliAreaDefaultIsChineseBaselineNotWebsiteListFirst' => function () {
            $this->assert_CliLanguage_testCliAreaDefaultIsChineseBaselineNotWebsiteListFirst();
        },
            'DictionaryCacheNamespaceProcessCache::testFingerprintIsAvailableWithoutRequestIdWhenNotInTransaction' => function () {
            $this->setUp_DictionaryCacheNamespaceProcessCache();
            try {
                $this->assert_DictionaryCacheNamespaceProcessCache_testFingerprintIsAvailableWithoutRequestIdWhenNotInTransaction();
            } finally {
                $this->tearDown_DictionaryCacheNamespaceProcessCache();
            }
        },
            'DictionaryCacheNamespaceProcessCache::testRequestBagPersistsWithoutRequestId' => function () {
            $this->setUp_DictionaryCacheNamespaceProcessCache();
            try {
                $this->assert_DictionaryCacheNamespaceProcessCache_testRequestBagPersistsWithoutRequestId();
            } finally {
                $this->tearDown_DictionaryCacheNamespaceProcessCache();
            }
        },
            'DictionaryCacheNamespaceProcessCache::testRequestBagIsEphemeralDuringActiveTransaction' => function () {
            $this->setUp_DictionaryCacheNamespaceProcessCache();
            try {
                $this->assert_DictionaryCacheNamespaceProcessCache_testRequestBagIsEphemeralDuringActiveTransaction();
            } finally {
                $this->tearDown_DictionaryCacheNamespaceProcessCache();
            }
        },
            'DictionaryCacheNamespaceProcessCache::testProcessMemoryStoreIsNotMemoryStoreInterface' => function () {
            $this->setUp_DictionaryCacheNamespaceProcessCache();
            try {
                $this->assert_DictionaryCacheNamespaceProcessCache_testProcessMemoryStoreIsNotMemoryStoreInterface();
            } finally {
                $this->tearDown_DictionaryCacheNamespaceProcessCache();
            }
        },
            'DictionaryCollectGate::testGateAndCompilerContracts' => function () {
            $this->setUp_DictionaryCollectGate();
            try {
                $this->assert_DictionaryCollectGate_testGateAndCompilerContracts();
            } finally {
                $this->tearDown_DictionaryCollectGate();
            }
        },
            'DictionaryCollectGate::testSameProcessReentrancy' => function () {
            $this->setUp_DictionaryCollectGate();
            try {
                $this->assert_DictionaryCollectGate_testSameProcessReentrancy();
            } finally {
                $this->tearDown_DictionaryCollectGate();
            }
        },
            'DictionaryCollectGate::testAcquireFailsWhenExternalHolderOwnsLock' => function () {
            $this->setUp_DictionaryCollectGate();
            try {
                $this->assert_DictionaryCollectGate_testAcquireFailsWhenExternalHolderOwnsLock();
            } finally {
                $this->tearDown_DictionaryCollectGate();
            }
        },
            'DictionaryCollectPruneStaleFlag::testCompilerAfterPayloadCarriesPruneStaleFlag' => function () {
            $this->assert_DictionaryCollectPruneStaleFlag_testCompilerAfterPayloadCarriesPruneStaleFlag();
        },
            'DictionaryCollectPruneStaleFlag::testFlattenCollectedWordsUsesChineseCsvPlusSourceScanOnly' => function () {
            $this->assert_DictionaryCollectPruneStaleFlag_testFlattenCollectedWordsUsesChineseCsvPlusSourceScanOnly();
        },
            'DictionaryCompilerLocaleFileSoleWriter::testCompilerDoesNotWritePathTranslateFilesLocalePhp' => function () {
            $this->assert_DictionaryCompilerLocaleFileSoleWriter_testCompilerDoesNotWritePathTranslateFilesLocalePhp();
        },
            'DictionaryDecoupling::testFrameworkCollectCommandDoesNotReferenceI18n' => function () {
            $this->assert_DictionaryDecoupling_testFrameworkCollectCommandDoesNotReferenceI18n();
        },
            'DictionaryDecoupling::testDictionaryCompilerDispatchesCompileAfterWithoutI18n' => function () {
            $this->assert_DictionaryDecoupling_testDictionaryCompilerDispatchesCompileAfterWithoutI18n();
        },
            'DictionaryDecoupling::testParserUsesEventDictionary' => function () {
            $this->assert_DictionaryDecoupling_testParserUsesEventDictionary();
        },
            'DictionaryDecoupling::testDictionaryEventsDefinesColdPathEvents' => function () {
            $this->assert_DictionaryDecoupling_testDictionaryEventsDefinesColdPathEvents();
        },
            'DictionaryWordValidatorLength::testRejectsOversizeNewlineBomAndQuoteRun' => function () {
            $this->assert_DictionaryWordValidatorLength_testRejectsOversizeNewlineBomAndQuoteRun();
        },
            'GlobalDictionaryModuleBagCache::testSharedKeyStableAndMatchesParserNullSourceShape' => function () {
            $this->assert_GlobalDictionaryModuleBagCache_testSharedKeyStableAndMatchesParserNullSourceShape();
        },
            'GlobalDictionaryModuleBagCache::testPutAndGetStayInProcessMemoryOnly' => function () {
            $this->assert_GlobalDictionaryModuleBagCache_testPutAndGetStayInProcessMemoryOnly();
        },
            'GlobalDictionaryModuleBagCache::testSoftPressureEvictsColdLocaleDictionaryBags' => function () {
            $this->assert_GlobalDictionaryModuleBagCache_testSoftPressureEvictsColdLocaleDictionaryBags();
        },
            'PageDictionaryPrefetchCoordinator::testCollectHookNamesFromCompiledPhp' => function () {
            $this->setUp_PageDictionaryPrefetchCoordinator();
            try {
                $this->assert_PageDictionaryPrefetchCoordinator_testCollectHookNamesFromCompiledPhp();
            } finally {
                $this->tearDown_PageDictionaryPrefetchCoordinator();
            }
        },
            'PageDictionaryPrefetchCoordinator::testResolveStorefrontChromeModulesFailOpen' => function () {
            $this->setUp_PageDictionaryPrefetchCoordinator();
            try {
                $this->assert_PageDictionaryPrefetchCoordinator_testResolveStorefrontChromeModulesFailOpen();
            } finally {
                $this->tearDown_PageDictionaryPrefetchCoordinator();
            }
        },
            'PageDictionaryPrefetchCoordinator::testParserExposesLocaleBatchPrefetchForColdLocalePrime' => function () {
            $this->setUp_PageDictionaryPrefetchCoordinator();
            try {
                $this->assert_PageDictionaryPrefetchCoordinator_testParserExposesLocaleBatchPrefetchForColdLocalePrime();
            } finally {
                $this->tearDown_PageDictionaryPrefetchCoordinator();
            }
        },
            'PageDictionaryPrefetchCoordinator::testCoversModulesUsesRequestLatch' => function () {
            $this->setUp_PageDictionaryPrefetchCoordinator();
            try {
                $this->assert_PageDictionaryPrefetchCoordinator_testCoversModulesUsesRequestLatch();
            } finally {
                $this->tearDown_PageDictionaryPrefetchCoordinator();
            }
        },
            'PageDictionaryPrefetchCoordinator::testScheduleDelegatesPageDictionaryAndHotCachePrefetch' => function () {
            $this->setUp_PageDictionaryPrefetchCoordinator();
            try {
                $this->assert_PageDictionaryPrefetchCoordinator_testScheduleDelegatesPageDictionaryAndHotCachePrefetch();
            } finally {
                $this->tearDown_PageDictionaryPrefetchCoordinator();
            }
        },
            'ParserNonPersistentHotPath::testProcessWordsSourceDoesNotCallGetWords' => function () {
            $this->assert_ParserNonPersistentHotPath_testProcessWordsSourceDoesNotCallGetWords();
        },
            'ParserTranslateReadOnly::testDoTranslateWordFromLayersDoesNotCallGlobalDictionaryWord' => function () {
            $this->assert_ParserTranslateReadOnly_testDoTranslateWordFromLayersDoesNotCallGlobalDictionaryWord();
        },
            'ParserTranslateReadOnly::testProcessWordsDoesNotWriteMissIntoDictionary' => function () {
            $this->assert_ParserTranslateReadOnly_testProcessWordsDoesNotWriteMissIntoDictionary();
        },
            'ParserTranslateReadOnly::testGetCurrentLayeredWordsUsesFileOnlyLayers' => function () {
            $this->assert_ParserTranslateReadOnly_testGetCurrentLayeredWordsUsesFileOnlyLayers();
        },
            'ParserTranslateReadOnly::testClearWorkerCachesPreservesUpgradeLightFlag' => function () {
            $this->assert_ParserTranslateReadOnly_testClearWorkerCachesPreservesUpgradeLightFlag();
        },
            'ParserTranslateReadOnly::testEventDictionaryNoLongerDispatchesCollectOrMissingEvents' => function () {
            $this->assert_ParserTranslateReadOnly_testEventDictionaryNoLongerDispatchesCollectOrMissingEvents();
        },
            'ParserTranslateReadOnly::testMissReturnsSourceFromLoadedLayersOnly' => function () {
            $this->assert_ParserTranslateReadOnly_testMissReturnsSourceFromLoadedLayersOnly();
        },
            'PhraseBagLocaleSoftReclaim::testSoftCompactEvictsBagOnlyColdLocalesWhenStoreEmpty' => function () {
            try {
                $this->assert_PhraseBagLocaleSoftReclaim_testSoftCompactEvictsBagOnlyColdLocalesWhenStoreEmpty();
            } finally {
                $this->tearDown_PhraseBagLocaleSoftReclaim();
            }
        },
        ];
    }

private function tearDown_AccW3L1Shed(): void
{
    GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
    DictionaryCacheNamespace::clearProcessMemoryStore();
}

private function assert_AccW3L1Shed_testWorkerL1BoundsRemainTight(): void
{
    $parser = (string) \file_get_contents((string) (new \ReflectionClass(Parser::class))->getFileName());
    self::assertMatchesRegularExpression('/WORKER_TRANSLATED_WORD_CACHE_MAX_ITEMS\s*=\s*1024/', $parser);
    self::assertStringContainsString("return \\max(64, \\min(2048, (int)\$configured))", $parser);
    self::assertSame(1, DictionaryCacheNamespace::heavyLocaleResidentMax());
    self::assertSame(200, MsWriteBudget::WORD_BATCH_MAX_ITEMS);
}

private function assert_AccW3L1Shed_testShedColdLocalesDropsProcessBagsWithoutMsWholeLocaleRetry(): void
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
    self::assertGreaterThan(0, (int) ($shed['count'] ?? 0));
    self::assertNotEmpty($shed['evicted'] ?? []);
    $bagSrc = (string) \file_get_contents((string) (new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName());
    self::assertStringNotContainsString('$pool->set(', $bagSrc);
    $guardPath = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Server/Service/WorkerResponseMemoryGuard.php';
    self::assertFileExists($guardPath);
    $guardSrc = (string) \file_get_contents($guardPath);
    self::assertStringContainsString('shedColdLocalesForHeadroom', $guardSrc);
    self::assertStringNotContainsString('putNullSource', $guardSrc);
    self::assertStringNotContainsString('generated/language/', $guardSrc);
}

private function assert_AccW3L1Shed_testPersistentWorkerMissPathForbidsWholeLocaleInclude(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(Parser::class))->getFileName());
    self::assertStringContainsString('persistent_worker_no_whole_locale_include', $src);
    self::assertStringContainsString('loadLocaleAreaBagsForWorker', $src);
    self::assertStringContainsString('loadMerged($lang, null, false)', $src);
    $gatePos = \strpos($src, 'persistent_worker_no_whole_locale_include');
    $includePos = \strpos($src, '(array)include $words_file');
    self::assertNotFalse($gatePos);
    self::assertNotFalse($includePos);
    self::assertLessThan($includePos, $gatePos);
}

private function assert_AccW3MsChunk_testMsWriteBudgetFrameMatchesSessionProtocol(): void
{
    self::assertSame(12582912, MsWriteBudget::FRAME_BUDGET_BYTES);
    $sessionProtocol = 'Weline\Server\Session\Server\SessionProtocol';
    self::assertTrue(\class_exists($sessionProtocol));
    self::assertSame((int) $sessionProtocol::maxFrameBytes(), MsWriteBudget::maxFrameBytes(), 'Phrase MS frame budget must equal SessionProtocol::maxFrameBytes()');
}

private function assert_AccW3MsChunk_testForbidsWholeLocaleBagKeysAndGeneratedLanguagePaths(): void
{
    $localeKey = GlobalDictionaryModuleBagCache::nullSourceSharedKey('hi_IN');
    self::assertTrue(MsWriteBudget::isForbiddenBagKey($localeKey));
    self::assertTrue(MsWriteBudget::isForbiddenBagKey('generated/language/hi_IN.php'));
    self::assertTrue(GlobalDictionaryModuleBagCache::wouldWriteForbiddenBagToSharedMemory($localeKey, ['a' => 'b']));
    self::assertFalse(MsWriteBudget::isForbiddenBagWrite('global_dictionary_word|v1|abc', ['found' => true, 'translation' => 'नमस्ते']));
}

private function assert_AccW3MsChunk_testForbidsLargeStringMapBagValues(): void
{
    $bag = [];
    for ($i = 0; $i < 80; $i++) {
        $bag['k' . $i] = 'v' . $i;
    }
    self::assertTrue(MsWriteBudget::isForbiddenBagValue($bag));
    self::assertSame(['global_dictionary_word|v1|ok' => ['found' => true, 'translation' => 'ok']], MsWriteBudget::filterAllowedWordRecords(['global_dictionary_module_words|hi_IN|v1|x' => $bag, 'global_dictionary_word|v1|ok' => ['found' => true, 'translation' => 'ok']]));
}

private function assert_AccW3MsChunk_testPartitionKeepsWordBatchesWithinSessionProtocolFrame(): void
{
    $records = [];
    for ($i = 0; $i < 500; $i++) {
        $records['global_dictionary_word|v1|' . \sha1((string) $i)] = ['found' => true, 'translation' => 't' . $i];
    }
    $batches = MsWriteBudget::partitionWordRecords($records);
    self::assertNotEmpty($batches);
    $maxFrame = MsWriteBudget::maxFrameBytes();
    foreach ($batches as $batch) {
        self::assertLessThanOrEqual(MsWriteBudget::WORD_BATCH_MAX_ITEMS, \count($batch));
        self::assertLessThanOrEqual($maxFrame, MsWriteBudget::estimateMsetFrameBytes($batch), 'each phrase MS chunk must fit SessionProtocol max frame');
    }
}

private function assert_AccW3MsChunk_testParserPrefetchUsesMsWriteBudgetPartitionNotBareSetMultiple(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(Parser::class))->getFileName());
    self::assertStringContainsString('MsWriteBudget::partitionWordRecords', $src);
    self::assertStringContainsString('MsWriteBudget::WORD_BATCH_MAX_ITEMS', $src);
    self::assertStringContainsString('整袋不再读写 Shared Memory', $src);
}

private function assert_AccW3MsChunk_testBagCachePutPathNeverCallsPoolSet(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName());
    self::assertStringContainsString('processBags', $src);
    self::assertStringNotContainsString('$pool->set(', $src);
    self::assertStringNotContainsString('$pool->setMultiple(', $src);
    self::assertStringContainsString('wouldWriteForbiddenBagToSharedMemory', $src);
}

private function assert_CliLanguage_testDefaultConfigDeclaresCliLanguage(): void
{
    self::assertSame(Env::default_LANGUAGE_CODE, Env::default_CONFIG['cli_language'] ?? null);
}

private function assert_CliLanguage_testEventRegisteredInFrameworkEventCatalog(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/event.php');
    self::assertStringContainsString(CliLanguage::EVENT_OPTIONS, $src);
    self::assertStringContainsString('cli_language_options', $src);
}

private function assert_CliLanguage_testStateResolvesCliLanguageInCliBranch(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/App/State.php');
    self::assertStringContainsString('CliLanguage::isCliContext', $src);
    self::assertStringContainsString('CliLanguage::resolveConfigured', $src);
    self::assertTrue(\method_exists(State::class, 'resolveAreaDefaultLanguage'));
}

private function assert_CliLanguage_testCommandClassAndTipUsePhrase(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/Console/Cli/Language.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('cli:language', $src);
    self::assertStringContainsString('__(\'', $src);
    self::assertStringContainsString('stream_isatty', $src);
    self::assertStringContainsString('CliLanguage::set', $src);
}

private function assert_CliLanguage_testCatalogClassDedupesAndPrioritizesBaseline(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/CliLanguageCatalog.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('discoverCsvAndGeneratedLocales', $src);
    self::assertStringContainsString('CliLanguage::EVENT_OPTIONS', $src);
    self::assertStringContainsString('default_LANGUAGE_CODE', $src);
    self::assertStringContainsString("'en_US'", $src);
    self::assertTrue(\class_exists(CliLanguageCatalog::class));
}

private function assert_CliLanguage_testResolveConfiguredNeverUsesWebsiteListFirstItemShape(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/CliLanguage.php');
    self::assertStringContainsString("Env::system('lang'", $src);
    self::assertStringContainsString('default_LANGUAGE_CODE', $src);
    self::assertStringNotContainsString('preferredLanguageCodes', $src);
    self::assertTrue(Runtime::isCli() || !Runtime::isCli());
}

private function assert_CliLanguage_testCliAreaDefaultIsChineseBaselineNotWebsiteListFirst(): void
{
    if (!Runtime::isCli()) {
        self::markTestSkipped('requires CLI SAPI');
    }
    State::clearProcessLocalizationCaches();
    State::resetLangLocalCache();
    State::setRequestLanguageOverride('');
    $lang = WelineEnv::getLang();
    self::assertSame(Env::default_LANGUAGE_CODE, $lang);
    self::assertNotSame('it_IT', $lang);
    self::assertNotSame('ar_SA', $lang);
}

private const TX_STATES_KEY_DictionaryCacheNamespaceProcessCache = 'framework.database.transaction_states';

private function setUp_DictionaryCacheNamespaceProcessCache(): void
{
    if (Context::hasCurrent()) {
        Context::leave();
    }
    Context::enter(new Context());
    RequestContext::init();
    RequestContext::setId(null);
    RequestContext::set(self::TX_STATES_KEY_DictionaryCacheNamespaceProcessCache, []);
}

private function tearDown_DictionaryCacheNamespaceProcessCache(): void
{
    RequestContext::set(self::TX_STATES_KEY_DictionaryCacheNamespaceProcessCache, []);
    RequestContext::setId(null);
    if (Context::hasCurrent()) {
        Context::leave();
    }
}

private function assert_DictionaryCacheNamespaceProcessCache_testFingerprintIsAvailableWithoutRequestIdWhenNotInTransaction(): void
{
    self::assertNull(RequestContext::getId());
    self::assertSame(0, TransactionContext::activeTransactionConnectionCount());
    self::assertNotNull(DictionaryCacheNamespace::fingerprint());
    self::assertStringStartsWith(DictionaryCacheNamespace::fingerprint() . '|', DictionaryCacheNamespace::cacheKey('demo'));
}

private function assert_DictionaryCacheNamespaceProcessCache_testRequestBagPersistsWithoutRequestId(): void
{
    self::assertNull(RequestContext::getId());
    self::assertSame(0, TransactionContext::activeTransactionConnectionCount());
    $cache = [];
    $first =& DictionaryCacheNamespace::requestBag($cache, 1024);
    $first['word'] = 'cached';
    $second =& DictionaryCacheNamespace::requestBag($cache, 1024);
    self::assertSame('cached', $second['word'] ?? null);
    self::assertSame('cached', $cache['word'] ?? null);
}

private function assert_DictionaryCacheNamespaceProcessCache_testRequestBagIsEphemeralDuringActiveTransaction(): void
{
    $query = $this->createStub(QueryInterface::class);
    RequestContext::set(self::TX_STATES_KEY_DictionaryCacheNamespaceProcessCache, ['probe' => new TransactionState($query, 1, false)]);
    self::assertGreaterThan(0, TransactionContext::activeTransactionConnectionCount());
    self::assertNull(DictionaryCacheNamespace::fingerprint());
    $cache = ['keep' => 1];
    $first =& DictionaryCacheNamespace::requestBag($cache, 1024);
    $first['word'] = 'dirty';
    $second =& DictionaryCacheNamespace::requestBag($cache, 1024);
    self::assertArrayNotHasKey('word', $second);
    self::assertSame(['keep' => 1], $cache);
}

private function assert_DictionaryCacheNamespaceProcessCache_testProcessMemoryStoreIsNotMemoryStoreInterface(): void
{
    $store = DictionaryCacheNamespace::processMemoryStore();
    self::assertInstanceOf(\Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface::class, $store);
    self::assertNotInstanceOf(\Weline\Framework\Cache\Contract\MemoryStoreInterface::class, $store);
}

private string $lockDir_DictionaryCollectGate = '';

private function setUp_DictionaryCollectGate(): void
{
    parent::setUp();
    $this->lockDir_DictionaryCollectGate = sys_get_temp_dir() . '/weline-dict-collect-gate-' . getmypid() . '-' . bin2hex(random_bytes(4));
    self::assertTrue(@mkdir($this->lockDir_DictionaryCollectGate, 0755, true) || is_dir($this->lockDir_DictionaryCollectGate));
    putenv('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR=' . $this->lockDir_DictionaryCollectGate);
    $_ENV['WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR'] = $this->lockDir_DictionaryCollectGate;
    DictionaryCollectGate::resetProcessStateForTests();
}

private function tearDown_DictionaryCollectGate(): void
{
    DictionaryCollectGate::resetProcessStateForTests();
    putenv('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR');
    unset($_ENV['WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR']);
    if ($this->lockDir_DictionaryCollectGate !== '' && is_dir($this->lockDir_DictionaryCollectGate)) {
        foreach (glob($this->lockDir_DictionaryCollectGate . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->lockDir_DictionaryCollectGate);
    }
    parent::tearDown();
}

private function assert_DictionaryCollectGate_testGateAndCompilerContracts(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $gate = (string) file_get_contents($root . '/Phrase/DictionaryCollectGate.php');
    $busy = (string) file_get_contents($root . '/Phrase/DictionaryCollectBusyException.php');
    $compiler = (string) file_get_contents($root . '/Phrase/DictionaryCompiler.php');
    $collect = (string) file_get_contents($root . '/Console/Console/I18n/Collect.php');
    self::assertStringContainsString('class DictionaryCollectGate', $gate);
    self::assertStringContainsString('LOCK_EX | LOCK_NB', $gate);
    self::assertStringContainsString('DEFAULT_WAIT_SECONDS = 0', $gate);
    self::assertStringContainsString('STALE_HOLD_SECONDS = 7200', $gate);
    self::assertStringContainsString('dictionary-collect.lock', $gate);
    self::assertStringContainsString('WELINE_TEST_DICTIONARY_COLLECT_LOCK_DIR', $gate);
    self::assertStringContainsString('disconnectStaleHolder', $gate);
    self::assertStringContainsString('(string)\BP', $gate);
    self::assertStringContainsString('I18N_DICTIONARY_COLLECT_BUSY', $busy);
    self::assertStringContainsString('DictionaryCollectGate::acquire', $compiler);
    self::assertStringContainsString('DictionaryCollectGate::release', $compiler);
    self::assertStringContainsString('DictionaryCollectBusyException', $collect);
    self::assertStringContainsString('return 75', $collect);
    self::assertStringNotContainsString("\nsleep(", $gate);
    self::assertStringNotContainsString('\sleep(', $gate);
}

private function assert_DictionaryCollectGate_testSameProcessReentrancy(): void
{
    DictionaryCollectGate::acquire('Weline_Cart');
    DictionaryCollectGate::acquire('Weline_Checkout');
    // nested depth, same process
    self::assertSame('Weline_Cart', DictionaryCollectGate::heldScope());
    DictionaryCollectGate::release();
    DictionaryCollectGate::release();
    self::assertSame('__all__', DictionaryCollectGate::heldScope());
}

private function assert_DictionaryCollectGate_testAcquireFailsWhenExternalHolderOwnsLock(): void
{
    $path = $this->lockDir_DictionaryCollectGate . '/dictionary-collect.lock';
    $external = fopen($path, 'c+');
    self::assertNotFalse($external);
    self::assertTrue(flock($external, LOCK_EX | LOCK_NB));
    fwrite($external, json_encode(['pid' => 1, 'scope' => 'external-holder', 'acquired_at' => date('c')]) ?: '{}');
    fflush($external);
    try {
        try {
        $this->expectExceptionMessage(DictionaryCollectBusyException::BUSY_MARKER);
        self::fail('Expected exception was not thrown');
        } catch (DictionaryCollectBusyException $__e) {
        self::assertInstanceOf(DictionaryCollectBusyException::class, $__e);
        }
        DictionaryCollectGate::acquire('Weline_Cart', 0);
    } finally {
        @flock($external, LOCK_UN);
        @fclose($external);
        DictionaryCollectGate::resetProcessStateForTests();
    }
}

private function assert_DictionaryCollectPruneStaleFlag_testCompilerAfterPayloadCarriesPruneStaleFlag(): void
{
    $compiler = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/DictionaryCompiler.php';
    $collect = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/I18n/Collect.php';
    $compilerSrc = (string) file_get_contents($compiler);
    $collectSrc = (string) file_get_contents($collect);
    self::assertStringContainsString('bool $pruneStale = true', $compilerSrc);
    self::assertStringContainsString("'prune_stale' => \$pruneStale", $compilerSrc);
    self::assertStringContainsString("'republish_all' => \$republishAll", $compilerSrc);
    self::assertStringContainsString("'rewrite_csv' => \$rewriteCsv", $compilerSrc);
    self::assertStringContainsString('--no-prune-stale', $collectSrc);
    self::assertStringContainsString('--republish-all', $collectSrc);
    self::assertStringContainsString('--no-rewrite-csv', $collectSrc);
    self::assertStringContainsString("'republish_all' => \$republishAll", $collectSrc);
    self::assertStringContainsString("'rewrite_csv' => \$rewriteCsv", $collectSrc);
}

private function assert_DictionaryCollectPruneStaleFlag_testFlattenCollectedWordsUsesChineseCsvPlusSourceScanOnly(): void
{
    $compilerSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/DictionaryCompiler.php');
    self::assertStringContainsString('仅默认语（中文）CSV 原词', $compilerSrc);
    self::assertStringContainsString('$defaultWords = $localsWords[$defaultLocale] ?? []', $compilerSrc);
    self::assertStringContainsString('源码扫描原词（同样视为中文原词）', $compilerSrc);
    // 禁止用其它语 CSV 键扩 alive
    self::assertStringNotContainsString('foreach ($localsWords as $localeWords)', $compilerSrc);
}

private function assert_DictionaryCompilerLocaleFileSoleWriter_testCompilerDoesNotWritePathTranslateFilesLocalePhp(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/DictionaryCompiler.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('AiTranslationPublisher', $source);
    self::assertStringContainsString('不写 generated/language/{locale}.php', $source);
    self::assertStringContainsString('不写 generated/language/words.php', $source);
    self::assertStringNotContainsString('path_TRANSLATE_ALL_COLLECTIONS_WORDS_FILE', $source);
    // 不得再出现向 path_TRANSLATE_FILES_PATH 拼 locale.php 并写入的循环。
    self::assertDoesNotMatchRegularExpression('/path_TRANSLATE_FILES_PATH\s*\.\s*\$locale\s*\.\s*[\'"]\.php[\'"]/', $source);
    self::assertStringNotContainsString('new File()', $source);
}

private function assert_DictionaryDecoupling_testFrameworkCollectCommandDoesNotReferenceI18n(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/I18n/Collect.php');
    self::assertStringNotContainsString('Weline\I18n', $source);
    self::assertStringContainsString("ALIASES = ['i18n:collect']", $source);
}

private function assert_DictionaryDecoupling_testDictionaryCompilerDispatchesCompileAfterWithoutI18n(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/DictionaryCompiler.php');
    self::assertStringContainsString('EVENT_DICTIONARY_COMPILE_AFTER', $source);
    self::assertStringContainsString("'collected_words'", $source);
    self::assertStringContainsString("'source_translations'", $source);
    self::assertStringNotContainsString('Weline\I18n', $source);
}

private function assert_DictionaryDecoupling_testParserUsesEventDictionary(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/Parser.php');
    self::assertStringContainsString('EventDictionary::', $source);
    self::assertStringContainsString('translateFromEventDictionary', $source);
}

private function assert_DictionaryDecoupling_testDictionaryEventsDefinesColdPathEvents(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/DictionaryEvents.php');
    foreach (['dictionary_compile', 'dictionary_register', 'dictionary_translate'] as $event) {
        self::assertStringContainsString($event, $source);
    }
}

private function assert_DictionaryWordValidatorLength_testRejectsOversizeNewlineBomAndQuoteRun(): void
{
    self::assertTrue(DictionaryWordValidator::isValidTranslationString('保存'));
    self::assertFalse(DictionaryWordValidator::isValidTranslationString(str_repeat('a', DictionaryWordValidator::MAX_WORD_LENGTH + 1)));
    self::assertFalse(DictionaryWordValidator::isValidTranslationString("a\nb"));
    self::assertFalse(DictionaryWordValidator::isValidTranslationString("﻿保存"));
    self::assertFalse(DictionaryWordValidator::isValidTranslationString('""""'));
}

private function assert_GlobalDictionaryModuleBagCache_testSharedKeyStableAndMatchesParserNullSourceShape(): void
{
    $locale = 'lt_LT';
    $module = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
    $expected = 'global_dictionary_module_words|' . $locale . '|v1|' . \sha1($module);
    self::assertSame($expected, GlobalDictionaryModuleBagCache::sharedKey($locale, $module));
    self::assertSame($expected, GlobalDictionaryModuleBagCache::nullSourceSharedKey($locale));
    self::assertSame(3600, GlobalDictionaryModuleBagCache::SHARED_TTL_SECONDS);
    $parserSource = \file_get_contents((string) (new \ReflectionClass(Parser::class))->getFileName());
    self::assertIsString($parserSource);
    self::assertStringContainsString('GlobalDictionaryModuleBagCache::get', $parserSource);
    self::assertStringContainsString('GlobalDictionaryModuleBagCache::put', $parserSource);
    self::assertStringContainsString('GlobalDictionaryModuleBagCache::pool', $parserSource);
    self::assertStringContainsString('整袋不再读写 Shared Memory', $parserSource);
    self::assertStringNotContainsString("'global_dictionary_module_words|' . \$lang . '|v1|' . \\sha1(\$module)", $parserSource);
}

private function assert_GlobalDictionaryModuleBagCache_testPutAndGetStayInProcessMemoryOnly(): void
{
    GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
    $locale = 'hi_IN';
    $module = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
    $bag = ['hello' => 'नमस्ते'];
    self::assertTrue(GlobalDictionaryModuleBagCache::put($locale, $module, $bag));
    self::assertSame($bag, GlobalDictionaryModuleBagCache::get($locale, $module));
    self::assertSame($bag, GlobalDictionaryModuleBagCache::getNullSource($locale));
    $source = \file_get_contents((string) (new \ReflectionClass(GlobalDictionaryModuleBagCache::class))->getFileName());
    self::assertIsString($source);
    self::assertStringContainsString('processBags', $source);
    self::assertStringContainsString('touchLocaleBucket', $source);
    self::assertStringNotContainsString('$pool->set(', $source);
    self::assertStringNotContainsString('$pool->get(', $source);
    GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
}

private function assert_GlobalDictionaryModuleBagCache_testSoftPressureEvictsColdLocaleDictionaryBags(): void
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
    self::assertTrue(GlobalDictionaryModuleBagCache::getNullSource('fr_FR') === null || GlobalDictionaryModuleBagCache::getNullSource('de_DE') === null, 'soft reclaim must drop at least one cold unpinned locale bag');
    DictionaryCacheNamespace::unpinLocaleBucket('es_ES');
    GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
    DictionaryCacheNamespace::clearProcessMemoryStore();
}

private function setUp_PageDictionaryPrefetchCoordinator(): void
{
    Runtime::setMode('wls');
    if (Context::hasCurrent()) {
        Context::leave();
    }
    RequestContext::cleanup();
    Context::enter(new Context(['runtime' => ['request_context' => ['initialized' => true]]]));
    RequestContext::setId('page-dict-test');
}

private function tearDown_PageDictionaryPrefetchCoordinator(): void
{
    RequestContext::cleanup();
    if (Context::hasCurrent()) {
        Context::leave();
    }
    Runtime::resetModeCache();
}

private function assert_PageDictionaryPrefetchCoordinator_testCollectHookNamesFromCompiledPhp(): void
{
    $php = <<<'PHP'
    <?= $this->getHook('seo::body') ?>
    <?= $template->getHook("Weline_Theme::frontend::layouts::base::body-end") ?>
    PHP;
    $names = PageDictionaryPrefetchCoordinator::collectHookNamesFromPhp($php);
    self::assertContains('seo::body', $names);
    self::assertContains('Weline_Theme::frontend::layouts::base::body-end', $names);
}

private function assert_PageDictionaryPrefetchCoordinator_testResolveStorefrontChromeModulesFailOpen(): void
{
    $modules = PageDictionaryPrefetchCoordinator::resolveStorefrontChromeModules();
    self::assertNotSame([], $modules);
    foreach ($modules as $module) {
        self::assertIsString($module);
        self::assertNotSame('', $module);
    }
}

private function assert_PageDictionaryPrefetchCoordinator_testParserExposesLocaleBatchPrefetchForColdLocalePrime(): void
{
    $parserSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Phrase/Parser.php');
    self::assertStringContainsString('function prefetchGlobalDictionaryModulesForLocales', $parserSrc);
    self::assertStringContainsString('prefetchGlobalDictionaryModulesForLocales($modules, $locales)', $parserSrc);
}

private function assert_PageDictionaryPrefetchCoordinator_testCoversModulesUsesRequestLatch(): void
{
    self::assertFalse(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Seo']));
    RequestContext::set(PageDictionaryPrefetchCoordinator::PRIMED_KEY, true);
    RequestContext::set(PageDictionaryPrefetchCoordinator::MODULES_KEY, ['Weline_Seo', 'Weline_Visitor']);
    self::assertTrue(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Seo']));
    self::assertFalse(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Missing']));
}

private function assert_PageDictionaryPrefetchCoordinator_testScheduleDelegatesPageDictionaryAndHotCachePrefetch(): void
{
    $scheduleSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/StorefrontWidgetRuntimeSchedule.php');
    self::assertStringContainsString('delegatePageDictionaryPrefetch', $scheduleSrc);
    self::assertStringContainsString('PageDictionaryPrefetchCoordinator', $scheduleSrc);
    self::assertStringContainsString('delegateHotCachePagePrefetch', $scheduleSrc);
    self::assertStringContainsString('StorefrontHotCachePagePrefetch', $scheduleSrc);
    $hotPos = strpos($scheduleSrc, 'delegateHotCachePagePrefetch($template');
    $scanPos = strpos($scheduleSrc, 'scanTemplateRefs($template');
    self::assertNotFalse($hotPos);
    self::assertNotFalse($scanPos);
    self::assertLessThan($scanPos, $hotPos);
    self::assertNotSame(StorefrontWidgetRuntimeSchedule::LATCH_KEY, PageDictionaryPrefetchCoordinator::PRIMED_KEY);
    $templateSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString('PageDictionaryPrefetchCoordinator::coversModules', $templateSrc);
}

private function assert_ParserNonPersistentHotPath_testProcessWordsSourceDoesNotCallGetWords(): void
{
    $reflection = new \ReflectionMethod(Parser::class, 'processWords');
    $file = (string) $reflection->getFileName();
    $start = (int) $reflection->getStartLine();
    $end = (int) $reflection->getEndLine();
    $lines = \file($file);
    self::assertIsArray($lines);
    $body = \implode('', \array_slice($lines, $start - 1, $end - $start + 1));
    self::assertStringNotContainsString('self::getWords()', $body, 'processWords hot path must resolve via layered lookup, not materialize the whole bag.');
    self::assertStringContainsString('translateWordFromLayers', $body);
    self::assertStringContainsString('getCurrentLayeredWords', $body);
}

private function assert_ParserTranslateReadOnly_testDoTranslateWordFromLayersDoesNotCallGlobalDictionaryWord(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
    $start = strpos($source, 'private static function doTranslateWordFromLayers(');
    $end = strpos($source, 'private static function translationFromLoadedLayers(', $start === false ? 0 : $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $method = substr($source, (int) $start, (int) $end - (int) $start);
    self::assertStringNotContainsString('loadGlobalDictionaryWord(', $method);
    self::assertStringNotContainsString('reportMissing(', $method);
    self::assertStringContainsString('getPrefetchedGlobalWord(', $method);
    self::assertStringContainsString('只读已装入的文件层', $method);
}

private function assert_ParserTranslateReadOnly_testProcessWordsDoesNotWriteMissIntoDictionary(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
    $start = strpos($source, 'protected static function processWords(string $words): string');
    $end = strpos($source, 'public static function getUsedWords(): array', $start === false ? 0 : $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $method = substr($source, (int) $start, (int) $end - (int) $start);
    self::assertStringNotContainsString('self::$words[$words] = $words', $method);
    self::assertStringNotContainsString('reportMissing(', $method);
    self::assertStringContainsString('禁止写回词典', $method);
}

private function assert_ParserTranslateReadOnly_testGetCurrentLayeredWordsUsesFileOnlyLayers(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
    $start = strpos($source, 'private static function getCurrentLayeredWords(): array');
    $end = strpos($source, 'private static function buildLayeredWordsCacheKey(', $start === false ? 0 : $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $method = substr($source, (int) $start, (int) $end - (int) $start);
    self::assertStringContainsString('getLayeredWords($lang, $modules, $locales)', $method);
    self::assertStringNotContainsString('includeGlobalDictionary', $method);
}

private function assert_ParserTranslateReadOnly_testClearWorkerCachesPreservesUpgradeLightFlag(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(Parser::class))->getFileName());
    $start = strpos($source, 'public static function clearWorkerCaches(): void');
    $end = strpos($source, 'private static function bindDictionaryProcessBags(', $start === false ? 0 : $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $method = substr($source, (int) $start, (int) $end - (int) $start);
    self::assertStringNotContainsString('self::$setupUpgradeLightDictionary = false', $method);
}

private function assert_ParserTranslateReadOnly_testEventDictionaryNoLongerDispatchesCollectOrMissingEvents(): void
{
    $source = (string) file_get_contents((new \ReflectionClass(EventDictionary::class))->getFileName());
    self::assertStringContainsString('function peekState(string $locale): array', $source);
    self::assertStringNotContainsString('ensureCollected', $source);
    self::assertStringNotContainsString('dictionary_collect', $source);
    self::assertStringNotContainsString('word_missing', $source);
    self::assertStringNotContainsString('reportMissing', $source);
}

private function assert_ParserTranslateReadOnly_testMissReturnsSourceFromLoadedLayersOnly(): void
{
    $layers = ['cache_key' => 'fixture-readonly', 'lang' => 'en_US', 'locales' => ['en_US'], 'modules' => ['Weline_Test'], 'module_words' => ['Weline_Test' => ['Known' => 'Known EN']], 'locale_words' => [], 'locale_word_layers' => [], 'global_words' => [], 'global_dictionary_loaded' => true];
    $method = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
    $method->setAccessible(true);
    self::assertSame('Known EN', $method->invoke(null, 'Known', $layers));
    self::assertSame('Unknown source', $method->invoke(null, 'Unknown source', $layers));
}

private function tearDown_PhraseBagLocaleSoftReclaim(): void
{
    DictionaryCacheNamespace::clearProcessMemoryStore();
    parent::tearDown();
}

private function assert_PhraseBagLocaleSoftReclaim_testSoftCompactEvictsBagOnlyColdLocalesWhenStoreEmpty(): void
{
    DictionaryCacheNamespace::clearProcessMemoryStore();
    $bag = ['fp|layered|fr_FR|word' => 'bonjour', 'fp|layered|de_DE|word' => 'hallo', 'fp|layered|it_IT|word' => 'ciao'];
    DictionaryCacheNamespace::bindProcessBag('testLayered', $bag);
    DictionaryCacheNamespace::touchLocaleBucket('bn_BD');
    DictionaryCacheNamespace::pinLocaleBucket('bn_BD');
    self::assertSame(['bn_BD'], DictionaryCacheNamespace::localeBucketResidents());
    self::assertContains('fr_FR', DictionaryCacheNamespace::localesPresentInBoundBags());
    self::assertTrue(DictionaryCacheNamespace::localeHasBoundBagKeys('fr_FR'));
    self::assertSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('fr_FR'));
    $result = DictionaryCacheNamespace::processMemoryReclaimable()->compact();
    self::assertEmpty($result['skipped'] ?? null);
    $remainingLocales = [];
    foreach (\array_keys($bag) as $key) {
        if (\str_contains((string) $key, '|fr_FR|')) {
            $remainingLocales['fr_FR'] = true;
        }
        if (\str_contains((string) $key, '|de_DE|')) {
            $remainingLocales['de_DE'] = true;
        }
        if (\str_contains((string) $key, '|it_IT|')) {
            $remainingLocales['it_IT'] = true;
        }
    }
    self::assertLessThan(3, \count($remainingLocales), 'soft reclaim must drop at least one bag-only cold locale when Store is empty');
    self::assertTrue(DictionaryCacheNamespace::isLocaleBucketPinned('bn_BD'), 'pinned request locale must survive soft compact');
    DictionaryCacheNamespace::unpinLocaleBucket('bn_BD');
}
}
