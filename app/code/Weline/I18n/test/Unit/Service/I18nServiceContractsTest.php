<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Service\ClientI18nBagPublisher;
use Weline\I18n\Service\ClientI18nSeedPhrases;
use Weline\I18n\Service\ClientWordSetStore;
use Weline\I18n\Service\DictionaryAreaPolicy;
use Weline\I18n\Service\LocaleAreaDictionaryLoader;
use Weline\I18n\Service\LocaleCatalogScopeResolver;
use Weline\I18n\Service\TranslationCollector;
use Weline\I18n\Taglib\LanguageSwitcher;

/**
 * Aggregated contract suite (merged from 27 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nServiceContractsPass().
 */
final class I18nServiceContractsTest extends TestCase
{
    public function testAllI18nServiceContractsPass(): void
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
            'ActiveLocaleProcessCache::testActiveLocaleUsesProcessStaticAndResetClearsIt' => function () {
            $this->assert_ActiveLocaleProcessCache_testActiveLocaleUsesProcessStaticAndResetClearsIt();
        },
            'ActiveLocaleProcessCache::testLocaleCatalogChangeClearsActiveLocale' => function () {
            $this->assert_ActiveLocaleProcessCache_testLocaleCatalogChangeClearsActiveLocale();
        },
            'AiTranslationDbOnlyCandidates::testAiTranslationDoesNotScanOrCollectWords' => function () {
            $this->assert_AiTranslationDbOnlyCandidates_testAiTranslationDoesNotScanOrCollectWords();
        },
            'AiTranslationDedupe::testBatchTranslateUsesLocaleLock' => function () {
            $this->setUp_AiTranslationDedupe();
            $this->assert_AiTranslationDedupe_testBatchTranslateUsesLocaleLock();
        },
            'AiTranslationDedupe::testCronEnqueuesInsteadOfDirectTranslate' => function () {
            $this->setUp_AiTranslationDedupe();
            $this->assert_AiTranslationDedupe_testCronEnqueuesInsteadOfDirectTranslate();
        },
            'AiTranslationDedupe::testQueueConsumerPassesScopedWordsAndOwner' => function () {
            $this->setUp_AiTranslationDedupe();
            $this->assert_AiTranslationDedupe_testQueueConsumerPassesScopedWordsAndOwner();
        },
            'AiTranslationDedupe::testQueueServiceDedupesPendingRunningByBizKey' => function () {
            $this->setUp_AiTranslationDedupe();
            $this->assert_AiTranslationDedupe_testQueueServiceDedupesPendingRunningByBizKey();
        },
            'AiTranslationDedupe::testQueueStopsAfterThreeConsecutiveBatchFailures' => function () {
            $this->setUp_AiTranslationDedupe();
            $this->assert_AiTranslationDedupe_testQueueStopsAfterThreeConsecutiveBatchFailures();
        },
            'AiTranslationLocaleIndexPaging::testLocaleTranslatedWordIndexUsesBoundedPaging' => function () {
            $this->setUp_AiTranslationLocaleIndexPaging();
            $this->assert_AiTranslationLocaleIndexPaging_testLocaleTranslatedWordIndexUsesBoundedPaging();
        },
            'AiTranslationLocaleIndexPaging::testPendingWordsUseSqlLimitNotFullTableScan' => function () {
            $this->setUp_AiTranslationLocaleIndexPaging();
            $this->assert_AiTranslationLocaleIndexPaging_testPendingWordsUseSqlLimitNotFullTableScan();
        },
            'AiTranslationLocaleIndexPaging::testCountUntranslatedWordsSkipsSourceLocale' => function () {
            $this->setUp_AiTranslationLocaleIndexPaging();
            $this->assert_AiTranslationLocaleIndexPaging_testCountUntranslatedWordsSkipsSourceLocale();
        },
            'AiTranslationProgress::testProgressServiceExposesLanguageByTypeBoard' => function () {
            $this->setUp_AiTranslationProgress();
            $this->assert_AiTranslationProgress_testProgressServiceExposesLanguageByTypeBoard();
        },
            'AiTranslationPublisherDbSnapshot::testPublishCommittedLocaleBuildsBagFromDbOnly' => function () {
            $this->assert_AiTranslationPublisherDbSnapshot_testPublishCommittedLocaleBuildsBagFromDbOnly();
        },
            'AiTranslationPublisherMemory::testPublishRaisesMemoryCeilingBeforeVarExport' => function () {
            $this->assert_AiTranslationPublisherMemory_testPublishRaisesMemoryCeilingBeforeVarExport();
        },
            'AiTranslationPublisherStream::testPublishCommittedLocaleStreamsDictionaryRowsInsteadOfUnboundedFetchArray' => function () {
            $this->assert_AiTranslationPublisherStream_testPublishCommittedLocaleStreamsDictionaryRowsInsteadOfUnboundedFetchArray();
        },
            'AiTranslationPublisherThreeBags::testPublisherWritesExclusiveAreaBags' => function () {
            $this->assert_AiTranslationPublisherThreeBags_testPublisherWritesExclusiveAreaBags();
        },
            'ClientI18nBagPublisher::testSeedPhrasesIncludeWaitTipAndChrome' => function () {
            $this->assert_ClientI18nBagPublisher_testSeedPhrasesIncludeWaitTipAndChrome();
        },
            'ClientI18nBagPublisher::testWordSetStoreMergesSeeds' => function () {
            $this->assert_ClientI18nBagPublisher_testWordSetStoreMergesSeeds();
        },
            'ClientI18nBagPublisher::testPublisherWritesInstallScriptWithSetDictionary' => function () {
            $this->assert_ClientI18nBagPublisher_testPublisherWritesInstallScriptWithSetDictionary();
        },
            'ClientI18nBagPublisher::testResourceCompilerNoLongerAppendsTranslationCode' => function () {
            $this->assert_ClientI18nBagPublisher_testResourceCompilerNoLongerAppendsTranslationCode();
        },
            'ClientI18nBagPublisher::testFrontendHeadNoLongerEmbedsDictionary' => function () {
            $this->assert_ClientI18nBagPublisher_testFrontendHeadNoLongerEmbedsDictionary();
        },
            'ClientI18nBagPublisher::testBackendHeadNoLongerEmbedsDictionary' => function () {
            $this->assert_ClientI18nBagPublisher_testBackendHeadNoLongerEmbedsDictionary();
        },
            'ClientI18nBagPublisher::testAiTranslationPublisherHooksClientBag' => function () {
            $this->assert_ClientI18nBagPublisher_testAiTranslationPublisherHooksClientBag();
        },
            'CountryLocaleLifecycleInstallActivates::testInstallLocaleDelegatesToActivateLocale' => function () {
            $this->assert_CountryLocaleLifecycleInstallActivates_testInstallLocaleDelegatesToActivateLocale();
        },
            'CountryLocaleLifecycleInstallActivates::testInstallCountryDelegatesToActivateCountry' => function () {
            $this->assert_CountryLocaleLifecycleInstallActivates_testInstallCountryDelegatesToActivateCountry();
        },
            'CountryLocaleLifecycleInstallActivates::testLocaleListingOrdersActiveAndInstalledFirst' => function () {
            $this->assert_CountryLocaleLifecycleInstallActivates_testLocaleListingOrdersActiveAndInstalledFirst();
        },
            'CountryLocaleLifecycleInstallActivates::testCountryListingOrdersActiveAndInstalledFirst' => function () {
            $this->assert_CountryLocaleLifecycleInstallActivates_testCountryListingOrdersActiveAndInstalledFirst();
        },
            'CountryLocaleLifecycleInstallActivates::testInstallAsyncFormsReloadToFirstPage' => function () {
            $this->assert_CountryLocaleLifecycleInstallActivates_testInstallAsyncFormsReloadToFirstPage();
        },
            'DictionaryAreaPolicy::testPathEvidenceAndIntersectionUpgradeToShared' => function () {
            $this->assert_DictionaryAreaPolicy_testPathEvidenceAndIntersectionUpgradeToShared();
        },
            'DictionaryAreaPolicy::testLocaleBagBasenames' => function () {
            $this->assert_DictionaryAreaPolicy_testLocaleBagBasenames();
        },
            'DictionaryAreaPolicy::testResolveRegistrationAreaDefaultsToSharedNotFrontendOnIsBackendZero' => function () {
            $this->assert_DictionaryAreaPolicy_testResolveRegistrationAreaDefaultsToSharedNotFrontendOnIsBackendZero();
        },
            'DictionaryAreaReseed::testReseedServiceFailClosedAndFullCollectHook' => function () {
            $this->assert_DictionaryAreaReseed_testReseedServiceFailClosedAndFullCollectHook();
        },
            'DictionaryAreaReseed::testUpgradeDoesNotMapIsBackendZeroToFrontend' => function () {
            $this->assert_DictionaryAreaReseed_testUpgradeDoesNotMapIsBackendZeroToFrontend();
        },
            'DictionaryAreaReseed::testDesignScanAndCollectDedupe' => function () {
            $this->assert_DictionaryAreaReseed_testDesignScanAndCollectDedupe();
        },
            'DictionaryAreaReseed::testPublisherEmptyAreaFallsBackToSharedNotLegacyIsBackend' => function () {
            $this->assert_DictionaryAreaReseed_testPublisherEmptyAreaFallsBackToSharedNotLegacyIsBackend();
        },
            'DictionaryCollectBatch::testBatchServiceAllocatesAboveCommittedAndDbMax' => function () {
            $this->assert_DictionaryCollectBatch_testBatchServiceAllocatesAboveCommittedAndDbMax();
        },
            'DictionaryCollectBatch::testPersistMarksCollectBatchInChunks' => function () {
            $this->assert_DictionaryCollectBatch_testPersistMarksCollectBatchInChunks();
        },
            'DictionaryCollectPerf::testWebCollectDoesNotDoubleCompileViaConvertToLanguageFile' => function () {
            $this->assert_DictionaryCollectPerf_testWebCollectDoesNotDoubleCompileViaConvertToLanguageFile();
        },
            'DictionaryCollectPerf::testPublisherExposesPublishLocalesAndPublicLoadBaseWordAreas' => function () {
            $this->assert_DictionaryCollectPerf_testPublisherExposesPublishLocalesAndPublicLoadBaseWordAreas();
        },
            'DictionaryCollectPerf::testResourcePublisherSkipsRevisionBumpWhenUnchanged' => function () {
            $this->assert_DictionaryCollectPerf_testResourcePublisherSkipsRevisionBumpWhenUnchanged();
        },
            'DictionaryCollectPerf::testExportLocaleResolverModuleBaselineIsSourcePlusEnUs' => function () {
            $this->assert_DictionaryCollectPerf_testExportLocaleResolverModuleBaselineIsSourcePlusEnUs();
        },
            'DictionaryCollectPerf::testPersistUsesLargerInChunks' => function () {
            $this->assert_DictionaryCollectPerf_testPersistUsesLargerInChunks();
        },
            'DictionaryCollectPersist::testPersistCollectedWordsIsTheSharedDictionaryWritePath' => function () {
            $this->assert_DictionaryCollectPersist_testPersistCollectedWordsIsTheSharedDictionaryWritePath();
        },
            'DictionaryEntryAiTranslate::testServiceExposesPreviewStreamAndSaveMethods' => function () {
            $this->assert_DictionaryEntryAiTranslate_testServiceExposesPreviewStreamAndSaveMethods();
        },
            'DictionaryEntryAiTranslate::testDictionaryControllerExposesSseAndSaveEndpoints' => function () {
            $this->assert_DictionaryEntryAiTranslate_testDictionaryControllerExposesSseAndSaveEndpoints();
        },
            'DictionaryEntryAiTranslate::testAssertEntryAiSseCsrfFailsWithErrorOnlyNoCompleteDone' => function () {
            $this->assert_DictionaryEntryAiTranslate_testAssertEntryAiSseCsrfFailsWithErrorOnlyNoCompleteDone();
        },
            'DictionaryEntryAiTranslate::testDictionaryPhtmlRejectsSuccessFalseDoneAsFalseGreen' => function () {
            $this->assert_DictionaryEntryAiTranslate_testDictionaryPhtmlRejectsSuccessFalseDoneAsFalseGreen();
        },
            'DictionaryEntryAiTranslate::testDictionaryPhtmlAiSseUrlsAreSameOriginPath' => function () {
            $this->assert_DictionaryEntryAiTranslate_testDictionaryPhtmlAiSseUrlsAreSameOriginPath();
        },
            'DictionaryEntryAiTranslate::testAdminQueryProviderMapsEntryAiActionsAndAcl' => function () {
            $this->assert_DictionaryEntryAiTranslate_testAdminQueryProviderMapsEntryAiActionsAndAcl();
        },
            'DictionaryInvalidWordPurge::testPurgeServiceAndConsoleExist' => function () {
            $this->assert_DictionaryInvalidWordPurge_testPurgeServiceAndConsoleExist();
        },
            'DictionaryPublishLocaleCodes::testDictionaryPublishLocaleCodesIgnoresAiMasterSwitchGate' => function () {
            $this->assert_DictionaryPublishLocaleCodes_testDictionaryPublishLocaleCodesIgnoresAiMasterSwitchGate();
        },
            'DictionaryStaleWordPrune::testPruneDeletesPhraseNotOnCurrentBatchAndSkipsMeta' => function () {
            $this->assert_DictionaryStaleWordPrune_testPruneDeletesPhraseNotOnCurrentBatchAndSkipsMeta();
        },
            'DictionaryStaleWordPrune::testPublisherStripsMetaFromFinalExport' => function () {
            $this->assert_DictionaryStaleWordPrune_testPublisherStripsMetaFromFinalExport();
        },
            'LocaleAreaDictionaryLoader::testDetectRequestAreaPrefersRequestContextBackend' => function () {
            $this->assert_LocaleAreaDictionaryLoader_testDetectRequestAreaPrefersRequestContextBackend();
        },
            'LocaleAreaDictionaryLoader::testLoadMergedWorkerForbidsLegacyWholeBag' => function () {
            $this->assert_LocaleAreaDictionaryLoader_testLoadMergedWorkerForbidsLegacyWholeBag();
        },
            'LocaleCatalogRenderContextReader::testResolverPrefersRenderContextLocaleCatalog' => function () {
            $this->assert_LocaleCatalogRenderContextReader_testResolverPrefersRenderContextLocaleCatalog();
        },
            'LocaleCatalogRenderContextReader::testLanguageSwitcherDefaultsPreferReader' => function () {
            $this->assert_LocaleCatalogRenderContextReader_testLanguageSwitcherDefaultsPreferReader();
        },
            'LocaleCatalogScopeResolverResolvedCodes::testResolverUsesResolvedQueryAndPreservesExplicitOrder' => function () {
            $this->assert_LocaleCatalogScopeResolverResolvedCodes_testResolverUsesResolvedQueryAndPreservesExplicitOrder();
        },
            'TaglibLocalFormService::testServiceLoadsInstalledLocalesFromLocaleModel' => function () {
            $this->assert_TaglibLocalFormService_testServiceLoadsInstalledLocalesFromLocaleModel();
        },
            'TaglibLocalFormService::testAdminQueryProviderExposesTaglibLocalLoad' => function () {
            $this->assert_TaglibLocalFormService_testAdminQueryProviderExposesTaglibLocalLoad();
        },
            'TaglibLocalFormService::testWelineUiExposesWhenReadyForLazyComponents' => function () {
            $this->assert_TaglibLocalFormService_testWelineUiExposesWhenReadyForLazyComponents();
        },
            'TranslationCollectorAclAttribute::testCollectExtractsAclSourceNameAndDocument' => function () {
            $this->assert_TranslationCollectorAclAttribute_testCollectExtractsAclSourceNameAndDocument();
        },
            'TranslationCollectorQuotedAtLang::testCollectorStripsWrappingQuotesFromAtLangBrace' => function () {
            $this->assert_TranslationCollectorQuotedAtLang_testCollectorStripsWrappingQuotesFromAtLangBrace();
        },
            'TranslationCollectorQuotedAtLang::testJsExtractorStripsWrappingQuotesFromAtLangBrace' => function () {
            $this->assert_TranslationCollectorQuotedAtLang_testJsExtractorStripsWrappingQuotesFromAtLangBrace();
        },
            'WebsiteLocaleUnionTranslation::testAiTranslationConfigForcesWebsiteUnionLocales' => function () {
            $this->setUp_WebsiteLocaleUnionTranslation();
            $this->assert_WebsiteLocaleUnionTranslation_testAiTranslationConfigForcesWebsiteUnionLocales();
        },
            'WebsiteLocaleUnionTranslation::testWebsiteLanguageChangesSyncTranslationTargets' => function () {
            $this->setUp_WebsiteLocaleUnionTranslation();
            $this->assert_WebsiteLocaleUnionTranslation_testWebsiteLanguageChangesSyncTranslationTargets();
        },
            'WebsiteLocaleUnionTranslation::testTranslationPromptsPreferEcommerceUiQuality' => function () {
            $this->setUp_WebsiteLocaleUnionTranslation();
            $this->assert_WebsiteLocaleUnionTranslation_testTranslationPromptsPreferEcommerceUiQuality();
        },
        ];
    }

private function assert_ActiveLocaleProcessCache_testActiveLocaleUsesProcessStaticAndResetClearsIt(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/ActiveLocaleCodeProvider.php');
    self::assertStringContainsString('private static array $processInstalledActiveCodes', $src);
    self::assertStringContainsString('function clearProcessCache', $src);
    self::assertStringContainsString('self::clearProcessCache()', $src);
    self::assertStringContainsString('UNION ALL', $src);
    self::assertStringContainsString('fetchInstalledActiveCodesUnion', $src);
}

private function assert_ActiveLocaleProcessCache_testLocaleCatalogChangeClearsActiveLocale(): void
{
    $lifecycle = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/CountryLocaleLifecycleService.php');
    self::assertStringContainsString('ActiveLocaleCodeProvider::class)->reset()', $lifecycle);
    $observer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/LocaleCatalogChangedClearActiveLocales.php');
    self::assertStringContainsString('ActiveLocaleCodeProvider', $observer);
    $events = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/etc/event.xml');
    self::assertStringContainsString('LocaleCatalogChangedClearActiveLocales', $events);
}

private function assert_AiTranslationDbOnlyCandidates_testAiTranslationDoesNotScanOrCollectWords(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationService.php');
    foreach (['function collectCandidateWords', 'function appendDictionaryWords', 'function appendModuleCsvWords', 'function appendBackendMenuWords', 'function getActiveModuleBasePaths', 'function readCsvWords', 'function getCsvTranslatedWordIndex', 'function getGeneratedTranslatedWordIndex', 'function hasGeneratedTranslation', 'function hasCsvTranslation'] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $source);
    }
    self::assertStringContainsString('NOT EXISTS', $source);
    self::assertStringContainsString('PENDING_READ_PAGE_SIZE = 100', $source);
    self::assertStringContainsString('self::PENDING_READ_PAGE_SIZE', $source);
    self::assertStringNotContainsString('SELECT COUNT(*) AS pending_count FROM', $source);
}

private function setUp_AiTranslationDedupe(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationDedupe_testBatchTranslateUsesLocaleLock(): void
{
    $source = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Service/AiTranslationService.php');
    self::assertStringContainsString('AiTranslationBatchLock', $source);
    self::assertStringContainsString('tryAcquire', $source);
    self::assertStringContainsString('已有翻译批次在运行', $source);
}

private function assert_AiTranslationDedupe_testCronEnqueuesInsteadOfDirectTranslate(): void
{
    $source = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Cron/AiTranslation.php');
    self::assertStringContainsString('AiTranslationQueueService', $source);
    self::assertStringContainsString('enqueueEnabledLocales', $source);
    self::assertStringNotContainsString('batchTranslateDictionary', $source);
}

private function assert_AiTranslationDedupe_testQueueConsumerPassesScopedWordsAndOwner(): void
{
    $source = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Queue/AiTranslateQueue.php');
    self::assertStringContainsString('buildBatchScope', $source);
    self::assertStringContainsString('resolveBatchSize', $source);
    self::assertStringContainsString('google_taxonomy.', $source);
    self::assertStringContainsString('$scope', $source);
}

private function assert_AiTranslationDedupe_testQueueServiceDedupesPendingRunningByBizKey(): void
{
    $source = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Service/AiTranslationQueueService.php');
    self::assertStringContainsString('IdempotentQueueAdmission', $source);
    self::assertStringContainsString('IDEMPOTENCY_SCOPE', $source);
    self::assertStringContainsString("admission->admit", $source);
    self::assertStringContainsString('consecutive_failures', $source);
    self::assertStringNotContainsString("w_query('queue', 'create'", $source);
}

private function assert_AiTranslationDedupe_testQueueStopsAfterThreeConsecutiveBatchFailures(): void
{
    $config = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Service/AiTranslationConfig.php');
    $queue = $this->read_AiTranslationDedupe('app/code/Weline/I18n/Queue/AiTranslateQueue.php');
    self::assertStringContainsString('MAX_CONSECUTIVE_BATCH_FAILURES = 3', $config);
    self::assertStringContainsString('advanceConsecutiveFailures', $queue);
    self::assertStringContainsString('isBatchFailure', $queue);
    self::assertStringContainsString('resultIndicatesBusy', $queue);
    self::assertStringContainsString('$stopRound', $queue);
    self::assertStringContainsString('已停止自动续跑', $queue);
    self::assertStringContainsString('不立刻续队', $queue);
    self::assertStringNotContainsString('throw new \RuntimeException', $queue);
}

private function read_AiTranslationDedupe(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function setUp_AiTranslationLocaleIndexPaging(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationLocaleIndexPaging_testLocaleTranslatedWordIndexUsesBoundedPaging(): void
{
    $source = $this->read_AiTranslationLocaleIndexPaging('app/code/Weline/I18n/Service/AiTranslationService.php');
    self::assertStringContainsString('private function getLocaleTranslatedWordIndex(string $localeCode): array', $source);
    self::assertStringContainsString('->limit(self::DEFAULT_SCAN_PAGE_SIZE, $offset)', $source);
    self::assertStringNotContainsString("->where(LocaleDictionary::schema_fields_LOCALE_CODE, \$localeCode)\n            ->select()\n            ->fetchArray();", $source);
}

private function assert_AiTranslationLocaleIndexPaging_testPendingWordsUseSqlLimitNotFullTableScan(): void
{
    $source = $this->read_AiTranslationLocaleIndexPaging('app/code/Weline/I18n/Service/AiTranslationService.php');
    self::assertStringNotContainsString('function appendDictionaryWords', $source);
    self::assertStringNotContainsString('->pagination(', $source);
    self::assertStringContainsString('ORDER BY d.word LIMIT ', $source);
}

private function assert_AiTranslationLocaleIndexPaging_testCountUntranslatedWordsSkipsSourceLocale(): void
{
    $service = $this->read_AiTranslationLocaleIndexPaging('app/code/Weline/I18n/Service/AiTranslationService.php');
    $controller = $this->read_AiTranslationLocaleIndexPaging('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
    self::assertStringContainsString('if ($targetLocale === \'\' || ($targetLocale === $sourceLocale && !$allowKeyOnlyWords))', $service);
    self::assertStringContainsString('$pending = $isSource', $controller);
}

private function read_AiTranslationLocaleIndexPaging(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function setUp_AiTranslationProgress(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationProgress_testProgressServiceExposesLanguageByTypeBoard(): void
{
    $service = $this->read_AiTranslationProgress('app/code/Weline/I18n/Service/AiTranslationProgressService.php');
    $controller = $this->read_AiTranslationProgress('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
    $template = $this->read_AiTranslationProgress('app/code/Weline/I18n/view/templates/backend/AiTranslation/index.phtml');
    $css = $this->read_AiTranslationProgress('app/code/Weline/I18n/view/statics/css/backend-admin.css');
    self::assertStringContainsString('class AiTranslationProgressService', $service);
    self::assertStringContainsString('TYPE_DICTIONARY', $service);
    self::assertStringContainsString('TYPE_LOCAL_MODEL', $service);
    self::assertStringContainsString('TYPE_MODULE_EXPORT', $service);
    self::assertStringContainsString('function buildBoard', $service);
    self::assertStringContainsString('humanizeQueueStatus', $service);
    self::assertStringContainsString('AiTranslationProgressService', $controller);
    self::assertStringContainsString('progress_board', $controller);
    self::assertStringContainsString('buildBoard', $controller);
    self::assertStringContainsString('data-ai-progress-board', $template);
    self::assertStringContainsString('翻译进度', $template);
    self::assertStringContainsString('待写回 CSV', $template);
    self::assertStringContainsString('模块 CSV', $template);
    self::assertStringContainsString('CSV 已对齐', $template);
    self::assertStringContainsString('模块 CSV', $service);
    self::assertStringContainsString('i18n-admin-progress-bar', $template);
    self::assertStringContainsString('语言配置与操作', $template);
    // Primary top tabs: progress vs config (not one stacked page).
    self::assertStringContainsString('data-ai-primary-tabs', $template);
    self::assertStringContainsString('配置与操作', $template);
    self::assertStringContainsString("tab=progress", $template);
    self::assertStringContainsString("'progress', 'locales', 'modules'", $controller);
    self::assertStringContainsString('primary_section', $controller);
    self::assertStringContainsString('进度见「翻译进度」', $template);
    self::assertStringNotContainsString('white-space:pre-wrap', $template);
    // Ops table must not re-render progress columns (dedupe vs progress board).
    self::assertDoesNotMatchRegularExpression('/语言配置与操作[\s\S]*?<th>\s*词典进度\s*<\/th>/u', $template);
    self::assertDoesNotMatchRegularExpression('/语言配置与操作[\s\S]*?<th>\s*待补翻译\s*<\/th>/u', $template);
    self::assertStringNotContainsString('i18n-admin-stat-tiles', $template);
    // Progress board and config ops must not both render unconditionally on one screen.
    self::assertMatchesRegularExpression('/\$primarySection\s*===\s*[\'"]progress[\'"]/u', $template);
    self::assertMatchesRegularExpression('/\$primarySection\s*===\s*[\'"]config[\'"]/u', $template);
    self::assertStringContainsString('.i18n-admin-progress-board', $css);
    self::assertStringContainsString('.i18n-admin-progress-bar', $css);
    self::assertStringContainsString('.i18n-admin-primary-tabs', $css);
    self::assertStringContainsString('var(--color-primary', $css);
}

private function read_AiTranslationProgress(string $relative): string
{
    $root = '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR;
    $path = $root . $relative;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function assert_AiTranslationPublisherDbSnapshot_testPublishCommittedLocaleBuildsBagFromDbOnly(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('loadBaseWordAreas', $source);
    self::assertStringContainsString('fetchIterator', $source);
    self::assertStringNotContainsString('$words = $this->readExistingWords($localeCode);', $source);
    self::assertStringContainsString('empty DB snapshot', $source);
}

private function assert_AiTranslationPublisherMemory_testPublishRaisesMemoryCeilingBeforeVarExport(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('ensurePublishMemoryCeiling', $source);
    self::assertStringContainsString("ini_set('memory_limit', '512M')", $source);
    self::assertStringContainsString('unset($words)', $source);
}

private function assert_AiTranslationPublisherStream_testPublishCommittedLocaleStreamsDictionaryRowsInsteadOfUnboundedFetchArray(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('fetchIterator', $source);
    self::assertStringContainsString('foreach ($query->fetchIterator() as $row)', $source);
    self::assertStringNotContainsString('->select()->fetchArray()', $source);
    self::assertStringNotContainsString("->select()\n                    ->fetchArray()", $source);
}

private function assert_AiTranslationPublisherThreeBags_testPublisherWritesExclusiveAreaBags(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('localeBagBasename', $src);
    self::assertStringContainsString('AREA_SHARED', $src);
    self::assertStringContainsString('AREA_FRONTEND', $src);
    self::assertStringContainsString('AREA_BACKEND', $src);
    self::assertStringContainsString('loadBaseWordAreas', $src);
    self::assertStringContainsString('三袋互斥', $src);
}

private function assert_ClientI18nBagPublisher_testSeedPhrasesIncludeWaitTipAndChrome(): void
{
    $seeds = ClientI18nSeedPhrases::all();
    self::assertContains('请稍等片刻...', $seeds);
    self::assertContains('关闭', $seeds);
    self::assertContains('确定', $seeds);
    self::assertContains('取消', $seeds);
    self::assertContains('请重试', $seeds);
}

private function assert_ClientI18nBagPublisher_testWordSetStoreMergesSeeds(): void
{
    $ok = ClientWordSetStore::save(DictionaryAreaPolicy::AREA_FRONTEND, ['自定义词' => '自定义词']);
    self::assertTrue($ok);
    $words = ClientWordSetStore::load(DictionaryAreaPolicy::AREA_FRONTEND);
    self::assertContains('自定义词', $words);
    self::assertContains('请稍等片刻...', $words);
    self::assertContains('关闭', $words);
}

private function assert_ClientI18nBagPublisher_testPublisherWritesInstallScriptWithSetDictionary(): void
{
    ClientWordSetStore::save(DictionaryAreaPolicy::AREA_FRONTEND, ['请稍等片刻...' => '请稍等片刻...']);
    $publisher = new ClientI18nBagPublisher();
    self::assertTrue($publisher->publishAreaLocale(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US'));
    $path = ClientI18nBagPublisher::bagAbsolutePath(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US');
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringStartsWith('(function(w){', $js);
    self::assertStringContainsString('setDictionary', $js);
    self::assertStringContainsString('请稍等片刻...', $js);
    $meta = ClientI18nBagPublisher::resolveFooterScript(DictionaryAreaPolicy::AREA_FRONTEND, 'en_US');
    self::assertIsArray($meta);
    self::assertStringContainsString('i18n-client/frontend/en_US.js', (string) $meta['url']);
    self::assertNotSame('', (string) $meta['sha']);
}

private function assert_ClientI18nBagPublisher_testResourceCompilerNoLongerAppendsTranslationCode(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/ResourceCompiler.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('ClientWordSetStore::save', $src);
    self::assertStringNotContainsString('generateTranslationCode($translations)', $src);
    self::assertStringContainsString('paths only', $src);
}

private function assert_ClientI18nBagPublisher_testFrontendHeadNoLongerEmbedsDictionary(): void
{
    $path = BP . 'app/code/Weline/Frontend/view/blocks/header/base.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString("'dictionary' => \$i18nWords", $src);
    self::assertStringNotContainsString("'i18n' => \$i18nWords", $src);
    self::assertStringContainsString("'area' => 'frontend'", $src);
}

private function assert_ClientI18nBagPublisher_testBackendHeadNoLongerEmbedsDictionary(): void
{
    $path = BP . 'app/code/Weline/Backend/view/blocks/header/base.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString("'dictionary' => \$i18nWords", $src);
    self::assertStringNotContainsString("'i18n' => \$i18nWords", $src);
}

private function assert_ClientI18nBagPublisher_testAiTranslationPublisherHooksClientBag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('ClientI18nBagPublisher::class', $src);
    self::assertStringContainsString('publishForLocale', $src);
}

private function assert_CountryLocaleLifecycleInstallActivates_testInstallLocaleDelegatesToActivateLocale(): void
{
    $source = (string) file_get_contents(\BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php');
    self::assertStringContainsString('public function installLocale(string $localeCode): array', $source);
    self::assertMatchesRegularExpression('/public function installLocale\(string \$localeCode\): array\s*\{[\s\S]*?return \$this->activateLocale\(\$localeCode\);[\s\S]*?\n    \}/', $source, 'installLocale must default-activate by delegating to activateLocale');
}

private function assert_CountryLocaleLifecycleInstallActivates_testInstallCountryDelegatesToActivateCountry(): void
{
    $source = (string) file_get_contents(\BP . 'app/code/Weline/I18n/Service/CountryLocaleLifecycleService.php');
    self::assertStringContainsString('public function installCountry(string $countryCode): array', $source);
    self::assertMatchesRegularExpression('/public function installCountry\(string \$countryCode\): array\s*\{[\s\S]*?return \$this->activateCountry\(\$countryCode\);[\s\S]*?\n    \}/', $source, 'installCountry must default-activate by delegating to activateCountry');
}

private function assert_CountryLocaleLifecycleInstallActivates_testLocaleListingOrdersActiveAndInstalledFirst(): void
{
    $source = (string) file_get_contents(\BP . 'app/code/Weline/I18n/Controller/Backend/Countries/Locales.php');
    self::assertStringContainsString("->order('main_table.' . Locale::schema_fields_IS_ACTIVE, 'DESC')", $source);
    self::assertStringContainsString("->order('main_table.' . Locale::schema_fields_IS_INSTALL, 'DESC')", $source);
}

private function assert_CountryLocaleLifecycleInstallActivates_testCountryListingOrdersActiveAndInstalledFirst(): void
{
    $source = (string) file_get_contents(\BP . 'app/code/Weline/I18n/Controller/Backend/Countries.php');
    self::assertStringContainsString("->order('main_table.' . CountriesModel::schema_fields_IS_ACTIVE, 'DESC')", $source);
    self::assertStringContainsString("->order('main_table.' . CountriesModel::schema_fields_IS_INSTALL, 'DESC')", $source);
}

private function assert_CountryLocaleLifecycleInstallActivates_testInstallAsyncFormsReloadToFirstPage(): void
{
    $localeTpl = (string) file_get_contents(\BP . 'app/code/Weline/I18n/view/templates/backend/Countries/Locales/getIndex.phtml');
    $countryTpl = (string) file_get_contents(\BP . 'app/code/Weline/I18n/view/templates/backend/Countries/index.phtml');
    $localizationTpl = (string) file_get_contents(\BP . 'app/code/Weline/I18n/view/templates/backend/Localization/index.phtml');
    $js = (string) file_get_contents(\BP . 'app/code/Weline/I18n/view/statics/js/backend-admin.js');
    self::assertStringContainsString('data-async-reload="1"', $localeTpl);
    self::assertStringContainsString('data-async-reload="1"', $countryTpl);
    self::assertStringContainsString('data-async-reload="1"', $localizationTpl);
    self::assertStringContainsString("getAttribute('data-async-reload') === '1'", $js);
    self::assertStringContainsString("next.searchParams.delete('page')", $js);
    self::assertStringContainsString('isInstall', $js);
}

private function assert_DictionaryAreaPolicy_testPathEvidenceAndIntersectionUpgradeToShared(): void
{
    self::assertSame(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/view/templates/frontend/a.phtml'));
    self::assertSame(DictionaryAreaPolicy::AREA_BACKEND, DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/view/templates/backend/a.phtml'));
    // Service 等无区路径 = 无证据，不得记成 shared
    self::assertSame(DictionaryAreaPolicy::EVIDENCE_NONE, DictionaryAreaPolicy::evidenceFromPath('app/code/Weline/X/Service/Foo.php'));
    self::assertSame(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::classifyFromEvidence([DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::AREA_BACKEND]));
    self::assertNull(DictionaryAreaPolicy::classifyFromEvidence([]));
    self::assertSame(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::applyClassified(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::AREA_BACKEND));
    self::assertSame(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::applyClassified(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::AREA_SHARED));
    // 无 exclusive 目标：保留 frontend（禁止 Service/CSV 抬升）
    self::assertSame(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::applyClassified(DictionaryAreaPolicy::AREA_FRONTEND, null));
    self::assertSame(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::upgrade(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::AREA_SHARED));
    self::assertSame(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::upgrade(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::AREA_FRONTEND));
}

private function assert_DictionaryAreaPolicy_testLocaleBagBasenames(): void
{
    self::assertSame('en_US.shared.php', DictionaryAreaPolicy::localeBagBasename('en_US', 'shared'));
    self::assertSame('zh_Hans_CN.frontend.php', DictionaryAreaPolicy::localeBagBasename('zh_Hans_CN', 'frontend'));
}

private function assert_DictionaryAreaPolicy_testResolveRegistrationAreaDefaultsToSharedNotFrontendOnIsBackendZero(): void
{
    self::assertSame(DictionaryAreaPolicy::AREA_SHARED, DictionaryAreaPolicy::resolveRegistrationArea(['is_backend' => 0]));
    self::assertSame(DictionaryAreaPolicy::AREA_BACKEND, DictionaryAreaPolicy::resolveRegistrationArea(['is_backend' => 1]));
    self::assertSame(DictionaryAreaPolicy::AREA_FRONTEND, DictionaryAreaPolicy::resolveRegistrationArea(['area' => 'frontend']));
}

private function assert_DictionaryAreaReseed_testReseedServiceFailClosedAndFullCollectHook(): void
{
    $reseed = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryAreaReseedService.php');
    self::assertStringContainsString('passesFailClosedGate', $reseed);
    self::assertStringContainsString('fail_closed_insufficient_path_evidence', $reseed);
    self::assertStringContainsString('demoteAllNonMetaToShared', $reseed);
    self::assertStringContainsString('applyEvidenceAreas', $reseed);
    $observer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/DictionaryCompileAfterObserver.php');
    self::assertStringContainsString('DictionaryAreaReseedService', $observer);
    self::assertStringContainsString('reseedFromPathEvidence', $observer);
}

private function assert_DictionaryAreaReseed_testUpgradeDoesNotMapIsBackendZeroToFrontend(): void
{
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Setup/Upgrade.php');
    self::assertStringNotContainsString('AREA_FRONTEND', $upgrade);
    self::assertStringNotContainsString('is_backend_v140', $upgrade);
}

private function assert_DictionaryAreaReseed_testDesignScanAndCollectDedupe(): void
{
    $i18n = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Model/I18n.php');
    self::assertStringContainsString('collectDesignThemeAreaEvidence', $i18n);
    self::assertStringContainsString('app/design', $i18n);
    $collect = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php');
    self::assertStringContainsString('pullCompileAfterSummary', $collect);
    self::assertStringNotContainsString('$this->persistCollectedWords($words, \'\', $onProgress)', $collect);
}

private function assert_DictionaryAreaReseed_testPublisherEmptyAreaFallsBackToSharedNotLegacyIsBackend(): void
{
    $publisher = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('AREA_SHARED', $publisher);
    self::assertStringNotContainsString('fromLegacyIsBackend', $publisher);
}

private function assert_DictionaryCollectBatch_testBatchServiceAllocatesAboveCommittedAndDbMax(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectBatchService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("KEY = 'dictionary_collect_batch'", $src);
    self::assertStringContainsString('function allocateNextBatch(', $src);
    self::assertStringContainsString('function commitBatch(', $src);
    self::assertStringContainsString('function getCommittedBatch(', $src);
    self::assertStringContainsString('maxBatchInDictionary', $src);
    self::assertStringContainsString('schema_fields_COLLECT_BATCH', $src);
}

private function assert_DictionaryCollectBatch_testPersistMarksCollectBatchInChunks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('?int $collectBatch = null', $src);
    self::assertStringContainsString('schema_fields_COLLECT_BATCH', $src);
    self::assertStringContainsString("array_chunk(\$existingToMark, 200)", $src);
    self::assertStringContainsString("'touched'", $src);
}

private function assert_DictionaryCollectPerf_testWebCollectDoesNotDoubleCompileViaConvertToLanguageFile(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php');
    self::assertStringContainsString('$this->compiler->compile()', $source);
    self::assertStringContainsString('pullCompileAfterSummary', $source);
    self::assertStringNotContainsString('->convertToLanguageFile(', $source);
    self::assertStringContainsString('禁止再经', $source);
}

private function assert_DictionaryCollectPerf_testPublisherExposesPublishLocalesAndPublicLoadBaseWordAreas(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php');
    self::assertStringContainsString('function publishLocales(', $source);
    self::assertStringContainsString('function loadBaseWordAreas(', $source);
    self::assertStringContainsString('areaByWord ??= $this->loadBaseWordAreas()', $source);
    self::assertStringContainsString("'unchanged' => true", $source);
}

private function assert_DictionaryCollectPerf_testResourcePublisherSkipsRevisionBumpWhenUnchanged(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/I18nResourceChangePublisher.php');
    self::assertStringContainsString('lockForUpdate', $source);
    self::assertStringContainsString("(\$payload['unchanged'] ?? false) === true", $source);
}

private function assert_DictionaryCollectPerf_testExportLocaleResolverModuleBaselineIsSourcePlusEnUs(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryExportLocaleResolver.php');
    self::assertStringContainsString('BASELINE_EN', $source);
    self::assertStringContainsString('en_US', $source);
    self::assertStringContainsString('resolveForCollect', $source);
    self::assertStringContainsString('republishAll', $source);
}

private function assert_DictionaryCollectPerf_testPersistUsesLargerInChunks(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php');
    self::assertStringContainsString('array_chunk($wordKeys, 500)', $source);
    self::assertStringContainsString('array_chunk($existingToMark, 500)', $source);
}

private function assert_DictionaryCollectPersist_testPersistCollectedWordsIsTheSharedDictionaryWritePath(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryCollectService.php');
    self::assertStringContainsString('function persistCollectedWords(', $source);
    self::assertStringContainsString('pullCompileAfterSummary', $source);
    self::assertStringNotContainsString('$this->persistCollectedWords($words, \'\', $onProgress)', $source);
    self::assertStringContainsString('schema_fields_WORD', $source);
    self::assertStringContainsString('schema_fields_LOCALE_CODE', $source);
    self::assertStringContainsString('schema_fields_COLLECT_BATCH', $source);
}

private function assert_DictionaryEntryAiTranslate_testServiceExposesPreviewStreamAndSaveMethods(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryEntryAiTranslateService.php');
    self::assertStringContainsString('function previewCurrentLocale', $source);
    self::assertStringContainsString('function streamCurrentLocale', $source);
    self::assertStringContainsString('function streamOtherLocales', $source);
    self::assertStringContainsString('function saveOtherLocales', $source);
    self::assertStringContainsString('function resolveOtherLocales', $source);
    self::assertStringContainsString('chunkLocalesForMultiTranslate', $source);
    self::assertStringContainsString('translateWordToLocales', $source);
    self::assertStringContainsString('正在批量翻译', $source);
    self::assertStringContainsString('I18nAiTranslationAdapter', $source);
    self::assertStringContainsString('getDictionaryPublishLocaleCodes', $source);
    self::assertStringContainsString('AiTranslationPublisher', $source);
    self::assertStringContainsString('publishLocale', $source);
    self::assertStringContainsString('SseWriter', $source);
    self::assertStringContainsString('sendEvent(\'complete\'', $source);
    self::assertStringContainsString('sendEvent(\'failed\'', $source);
    self::assertStringContainsString('确认后', $source);
    self::assertStringContainsString('isUntranslatedEcho', $source);
    self::assertStringContainsString('未能译出有效译文', $source);
    self::assertStringContainsString('resolveOtherLocalesFailureMessage', $source);
    self::assertStringContainsString('humanizeProviderTranslateError', $source);
    self::assertStringContainsString('Insufficient Balance', $source);
    self::assertStringNotContainsString('Weline\Ai\\', $source);
    self::assertStringNotContainsString('LocalModelTranslationService', $source);
    self::assertStringNotContainsString('ollama', strtolower($source));
}

private function assert_DictionaryEntryAiTranslate_testDictionaryControllerExposesSseAndSaveEndpoints(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Controller/Backend/Dictionary.php');
    self::assertStringContainsString('DictionaryEntryAiTranslateService', $source);
    self::assertStringContainsString('function postEntryAiTranslateStream', $source);
    self::assertStringContainsString('function getEntryAiTranslateStream', $source);
    self::assertStringContainsString('function postEntryAiTranslateOthersStream', $source);
    self::assertStringContainsString('function getEntryAiTranslateOthersStream', $source);
    self::assertStringContainsString('function postEntryAiSaveOthers', $source);
    self::assertStringContainsString('SseWriter', $source);
    self::assertStringContainsString('streamCurrentLocale', $source);
    self::assertStringContainsString('streamOtherLocales', $source);
    self::assertStringContainsString('saveOtherLocales', $source);
    self::assertStringNotContainsString('use Weline\Ai\\', $source);
}

private function assert_DictionaryEntryAiTranslate_testAssertEntryAiSseCsrfFailsWithErrorOnlyNoCompleteDone(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Controller/Backend/Dictionary.php');
    self::assertMatchesRegularExpression('/private function assertEntryAiSseCsrf\(SseWriter \$sse\): bool\s*\{(?P<body>.*?)\n    \}/s', $source, 'assertEntryAiSseCsrf method must exist');
    \preg_match('/private function assertEntryAiSseCsrf\(SseWriter \$sse\): bool\s*\{(?P<body>.*?)\n    \}/s', $source, $m);
    $body = (string) ($m['body'] ?? '');
    self::assertStringContainsString('sendError', $body);
    self::assertStringContainsString('close()', $body);
    self::assertStringNotContainsString('complete(', $body);
    self::assertStringNotContainsString("'success' => false", $body);
}

private function assert_DictionaryEntryAiTranslate_testDictionaryPhtmlRejectsSuccessFalseDoneAsFalseGreen(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/templates/backend/Dictionary.phtml');
    self::assertStringContainsString('function startDictionaryAiSse', $source);
    self::assertStringContainsString('data.success === false', $source);
    self::assertStringContainsString('if (settled || failed)', $source);
    self::assertStringContainsString('handlers.onFailed', $source);
    self::assertStringContainsString('dictionary-edit-sse-csrf', $source);
    self::assertStringContainsString('getDictionaryEditAiCsrf', $source);
    self::assertStringContainsString('#dictionary-edit-modal input[name="csrf"]', $source);
    // onDone 须校验 translation 非空，禁止空译文仍显示「AI 翻译完成」
    self::assertStringContainsString("if (translation === '')", $source);
    self::assertStringContainsString('aiTranslateDone', $source);
    self::assertStringContainsString('aiTranslateFailed', $source);
    // success===false 路径必须走失败文案，不得仅依赖 onDone 成功 toast
    self::assertMatchesRegularExpression('/data\.success === false[\s\S]{0,220}aiTranslateFailed/', $source);
    // 其他语种：空草稿 / 全文回声原文不得弹确认保存
    self::assertStringContainsString('aiOthersEchoFailed', $source);
    self::assertStringContainsString('aiOthersAllSkipped', $source);
    self::assertStringContainsString('echoCount === draftCodes.length', $source);
    self::assertStringContainsString('!draftCodes.length', $source);
    // 回声失败禁止复用成功文案「草稿已就绪」
    self::assertMatchesRegularExpression('/echoCount === draftCodes\.length[\s\S]{0,280}aiOthersEchoFailed/', $source);
    self::assertStringContainsString('其他语言均已有译文，已跳过', $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryEntryAiTranslateService.php'));
    unset($service);
}

private function assert_DictionaryEntryAiTranslate_testDictionaryPhtmlAiSseUrlsAreSameOriginPath(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/templates/backend/Dictionary.phtml');
    // 禁止 AI SSE/save 再用绝对 @backend-url（易剥 :9555 / 改 scheme 丢 Cookie）
    self::assertStringNotContainsString("@backend-url{'*/backend/dictionary/entry-ai-translate-stream'}", $source);
    self::assertStringNotContainsString("@backend-url{'*/backend/dictionary/entry-ai-translate-others-stream'}", $source);
    self::assertStringNotContainsString("@backend-url{'*/backend/dictionary/entry-ai-save-others'}", $source);
    self::assertStringContainsString("getBackendUrlPath('*/backend/dictionary/entry-ai-translate-stream')", $source);
    self::assertStringContainsString("getBackendUrlPath('*/backend/dictionary/entry-ai-translate-others-stream')", $source);
    self::assertStringContainsString("getBackendUrlPath('*/backend/dictionary/entry-ai-save-others')", $source);
    self::assertStringContainsString('function resolveDictionarySameOriginUrl', $source);
    self::assertStringContainsString("xhr.open('POST', resolveDictionarySameOriginUrl(url), true)", $source);
    self::assertStringContainsString('fetch(resolveDictionarySameOriginUrl(entryAiSaveOthersUrl)', $source);
}

private function assert_DictionaryEntryAiTranslate_testAdminQueryProviderMapsEntryAiActionsAndAcl(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    self::assertStringContainsString("'dictionary-entry-ai-translate-stream'", $source);
    self::assertStringContainsString("'dictionary-entry-ai-translate-others-stream'", $source);
    self::assertStringContainsString("'dictionary-entry-ai-save-others'", $source);
    self::assertStringContainsString("'dictionary-entry-ai-translate-stream' => 'Weline_I18n::i18n_ai_translation'", $source);
    self::assertStringContainsString("'dictionary-entry-ai-translate-others-stream' => 'Weline_I18n::i18n_ai_translation'", $source);
    self::assertStringContainsString("'dictionary-entry-ai-save-others' => 'Weline_I18n::i18n_ai_translation'", $source);
    self::assertStringContainsString("'postEntryAiTranslateStream'", $source);
    self::assertStringContainsString("'postEntryAiTranslateOthersStream'", $source);
    self::assertStringContainsString("'postEntryAiSaveOthers'", $source);
    self::assertStringContainsString("'dictionary-edit' => 'Weline_I18n::i18n_dictionaries'", $source);
}

private function assert_DictionaryInvalidWordPurge_testPurgeServiceAndConsoleExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/I18n';
    $service = $root . '/Service/DictionaryInvalidWordPurgeService.php';
    $console = $root . '/Console/I18n/Dictionary/PurgeInvalid.php';
    self::assertFileExists($service);
    self::assertFileExists($console);
    $src = (string) file_get_contents($service);
    self::assertStringContainsString('CHAR_LENGTH(word) >', $src);
    self::assertStringContainsString('bad_locale', $src);
    self::assertStringContainsString('UNHEX(\'EFBBBF\')', $src);
}

private function assert_DictionaryPublishLocaleCodes_testDictionaryPublishLocaleCodesIgnoresAiMasterSwitchGate(): void
{
    $config = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationConfig.php');
    $observer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/DictionaryCompileAfterObserver.php');
    self::assertStringContainsString('function getDictionaryPublishLocaleCodes', $config);
    self::assertStringContainsString('不依赖', $config);
    // getEnabledLocaleCodes 仍保留 AI 总开关早退；导出方法不得复制该早退
    $enabledPos = strpos($config, 'function getEnabledLocaleCodes');
    $publishPos = strpos($config, 'function getDictionaryPublishLocaleCodes');
    self::assertNotFalse($enabledPos);
    self::assertNotFalse($publishPos);
    $publishBody = substr($config, (int) $publishPos, 2200);
    self::assertStringNotContainsString("empty(\$config['enabled'])", $publishBody);
    self::assertStringContainsString('array_unshift($codes, $sourceLocale)', $publishBody);
    self::assertStringContainsString('getDictionaryPublishLocaleCodes', $observer);
    self::assertStringNotContainsString('getEnabledLocaleCodes', $observer);
}

private function assert_DictionaryStaleWordPrune_testPruneDeletesPhraseNotOnCurrentBatchAndSkipsMeta(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/DictionaryStaleWordPruneService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("META_PREFIX = '@meta::'", $src);
    self::assertStringContainsString('function pruneNotCurrentBatch(', $src);
    self::assertStringContainsString('alive_too_small', $src);
    self::assertStringContainsString('invalid_current_batch', $src);
    self::assertStringContainsString('{$batchCol} <> ?', $src);
    self::assertStringContainsString('DELETE FROM', $src);
    self::assertStringNotContainsString('DISTINCT word', $src);
    self::assertStringNotContainsString('pruneFromCollectedWords', $src);
}

private function assert_DictionaryStaleWordPrune_testPublisherStripsMetaFromFinalExport(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationPublisher.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
    self::assertStringContainsString("str_starts_with(\$existingWord, '@meta::')", $src);
    self::assertStringContainsString('unset($words[$existingWord])', $src);
}

private function assert_LocaleAreaDictionaryLoader_testDetectRequestAreaPrefersRequestContextBackend(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocaleAreaDictionaryLoader.php');
    self::assertStringContainsString('RequestContext::isBackendArea()', $src);
    self::assertStringContainsString('allowLegacyUnion', $src);
    if (!RequestContext::isInitialized()) {
        RequestContext::init();
    }
    $prevArea = RequestContext::getWelineArea();
    try {
        RequestContext::setWelineArea(RequestContext::AREA_BACKEND);
        self::assertSame(DictionaryAreaPolicy::AREA_BACKEND, LocaleAreaDictionaryLoader::detectRequestArea());
        RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
        self::assertSame(DictionaryAreaPolicy::AREA_FRONTEND, LocaleAreaDictionaryLoader::detectRequestArea());
    } finally {
        RequestContext::setWelineArea($prevArea !== '' ? $prevArea : RequestContext::AREA_FRONTEND);
    }
}

private function assert_LocaleAreaDictionaryLoader_testLoadMergedWorkerForbidsLegacyWholeBag(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocaleAreaDictionaryLoader.php');
    self::assertStringContainsString('Persistent Worker 禁止整袋', $src);
    self::assertMatchesRegularExpression('/if \(\$merged === \[\] && \$allowLegacyUnion\)/', $src);
}

private function assert_LocaleCatalogRenderContextReader_testResolverPrefersRenderContextLocaleCatalog(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocaleCatalogScopeResolver.php');
    self::assertStringContainsString('StorefrontRenderContextReader', $src);
    self::assertStringContainsString('localeCatalog', $src);
    self::assertStringContainsString('websiteTableSnapshot', $src);
    self::assertTrue(class_exists(LocaleCatalogScopeResolver::class));
}

private function assert_LocaleCatalogRenderContextReader_testLanguageSwitcherDefaultsPreferReader(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSwitcher.php');
    self::assertStringContainsString('StorefrontRenderContextReader', $src);
    $defaultLang = \strpos($src, 'function defaultLanguage');
    self::assertNotFalse($defaultLang);
    $slice = \substr($src, $defaultLang, 2800);
    $readerPos = \strpos($slice, 'StorefrontRenderContextReader');
    $statePos = \strpos($slice, 'State::resolveWebsiteDefaultLanguage');
    self::assertNotFalse($readerPos);
    self::assertNotFalse($statePos);
    self::assertLessThan($statePos, $readerPos);
    self::assertStringNotContainsString('WebsiteData::getDefaultLanguage', $slice);
    $filterPos = \strpos($src, 'function filterFrontendLanguages');
    self::assertNotFalse($filterPos);
    $filterSlice = \substr($src, $filterPos, 1600);
    self::assertStringContainsString('StorefrontRenderContextReader', $filterSlice);
    self::assertTrue(class_exists(LanguageSwitcher::class));
}

private function assert_LocaleCatalogScopeResolverResolvedCodes_testResolverUsesResolvedQueryAndPreservesExplicitOrder(): void
{
    $resolver = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocaleCatalogScopeResolver.php');
    $scope = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/LocaleCatalogScope.php');
    $switcher = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSwitcher.php');
    self::assertStringContainsString('getResolvedLanguageCodes', $resolver);
    self::assertStringContainsString('preserveExplicitOrder', $scope);
    self::assertStringContainsString('preserveExplicitOrder', $resolver);
    self::assertStringContainsString('preserveExplicitOrder', $switcher);
    self::assertStringContainsString('storeId', $scope);
    self::assertStringContainsString('channelId', $scope);
}

private function assert_TaglibLocalFormService_testServiceLoadsInstalledLocalesFromLocaleModel(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/TaglibLocalFormService.php');
    self::assertStringContainsString('Locale::schema_fields_IS_ACTIVE', $source);
    self::assertStringContainsString('Locale::schema_fields_IS_INSTALL', $source);
    self::assertStringContainsString('resolveSiteLocaleCodes', $source);
    self::assertStringContainsString("w_query('websites', 'getWebsiteLanguageCodes'", $source);
    self::assertStringContainsString('resolveCurrentWebsiteId', $source);
    self::assertStringNotContainsString('getActiveLocalsModel', $source);
    self::assertStringContainsString('getLocaleLanguageSelfName', $source);
    self::assertStringContainsString('function aiTranslate', $source);
    self::assertStringContainsString('I18nAiTranslationAdapter', $source);
    self::assertStringContainsString('Weline\I18n\Api\Localization\LocalModel', $source);
    self::assertStringNotContainsString('use Weline\I18n\LocalModel;', $source);
    self::assertStringContainsString('当前站点未配置可编辑的关联语言', $source);
}

private function assert_TaglibLocalFormService_testAdminQueryProviderExposesTaglibLocalLoad(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    self::assertStringContainsString("'taglib-local-load'", $source);
    self::assertStringContainsString("'taglib-local-ai'", $source);
    self::assertStringContainsString('aiTranslateTaglibLocalForm', $source);
    self::assertStringContainsString('TaglibLocalFormService', $source);
}

private function assert_TaglibLocalFormService_testWelineUiExposesWhenReadyForLazyComponents(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/weline-ui.js');
    self::assertStringContainsString('function whenReady', $source);
    self::assertStringContainsString('instances.set(element, map);', $source);
    self::assertStringContainsString('weline:ui:component:ready', $source);
    self::assertStringContainsString('whenReady,', $source);
}

private function assert_TranslationCollectorAclAttribute_testCollectExtractsAclSourceNameAndDocument(): void
{
    $dir = sys_get_temp_dir() . '/weline-i18n-acl-' . bin2hex(random_bytes(4));
    self::assertTrue(mkdir($dir, 0777, true));
    $php = <<<'PHP'
    <?php
    namespace Demo;
    use Weline\Framework\Acl\Acl;
    #[Acl('Demo::resource', '演示资源名', 'mdi-test', '演示资源说明', 'Demo::parent')]
    class Sample
    {
        #[Acl('Demo::action', '演示动作', 'mdi-action', '演示动作说明')]
        public function run(): void {}
    }
    PHP;
    file_put_contents($dir . '/Sample.php', $php);
    try {
        $collector = new TranslationCollector();
        $words = $collector->collect($dir, 'Demo_Module');
        self::assertArrayHasKey('演示资源名', $words);
        self::assertArrayHasKey('演示资源说明', $words);
        self::assertArrayHasKey('演示动作', $words);
        self::assertArrayHasKey('演示动作说明', $words);
        self::assertArrayNotHasKey('Demo::resource', $words);
        self::assertArrayNotHasKey('mdi-test', $words);
        self::assertSame('Acl', $words['演示资源名']['context'] ?? null);
    } finally {
        @unlink($dir . '/Sample.php');
        @rmdir($dir);
    }
}

private function assert_TranslationCollectorQuotedAtLang_testCollectorStripsWrappingQuotesFromAtLangBrace(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/TranslationCollector.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('剥离包裹引号', $src);
    self::assertStringContainsString("@lang\\{(.*?)}", $src);
    self::assertStringContainsString('$quoted[2]', $src);
}

private function assert_TranslationCollectorQuotedAtLang_testJsExtractorStripsWrappingQuotesFromAtLangBrace(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Helper/JsTranslationsExtractor.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('剥离包裹引号', $src);
    self::assertStringContainsString('$quoted[2]', $src);
}

private function setUp_WebsiteLocaleUnionTranslation(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_WebsiteLocaleUnionTranslation_testAiTranslationConfigForcesWebsiteUnionLocales(): void
{
    $source = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/I18n/Service/AiTranslationConfig.php');
    self::assertStringContainsString('getWebsiteAssignedLocaleCodes', $source);
    self::assertStringContainsString('getTranslationCandidateLocaleCodes', $source);
    self::assertStringContainsString('isWebsiteAssignedLocale', $source);
    self::assertStringContainsString('tracksWebsiteLocaleUnion', $source);
    self::assertStringContainsString('left the union (site language removed) → skip', $source);
    self::assertStringContainsString('removed from all sites → skip', $source);
}

private function assert_WebsiteLocaleUnionTranslation_testWebsiteLanguageChangesSyncTranslationTargets(): void
{
    $sync = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/I18n/Service/WebsiteLocaleTranslationSync.php');
    $assignment = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/Websites/Service/Localization/WebsiteLanguageAssignment.php');
    $model = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/Websites/Model/WebsiteLanguage.php');
    $cron = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/I18n/Cron/AiTranslation.php');
    self::assertStringContainsString('ensureWebsiteUnionReady', $sync);
    self::assertStringContainsString('onWebsiteLocalesChanged', $sync);
    self::assertStringContainsString('live union', $sync);
    self::assertStringContainsString('removed union members are skipped', $sync);
    self::assertStringContainsString('LocalModelTranslationQueueService', $sync);
    self::assertStringContainsString('enqueueLocalModelTranslation', $sync);
    self::assertStringContainsString('_local_model', $sync);
    self::assertStringContainsString('WebsiteLocaleTranslationSync', $assignment);
    self::assertStringContainsString('WebsiteLocaleTranslationSync', $model);
    self::assertStringContainsString('ensureWebsiteUnionReady', $cron);
}

private function assert_WebsiteLocaleUnionTranslation_testTranslationPromptsPreferEcommerceUiQuality(): void
{
    $service = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/Ai/Service/TranslationService.php');
    $adapter = $this->read_WebsiteLocaleUnionTranslation('app/code/Weline/Ai/Adapter/TranslationAdapter.php');
    self::assertStringContainsString('professional translator for a multi-website ecommerce', $service);
    self::assertStringContainsString('advanced maintenance', $service);
    self::assertStringContainsString('Never return the source text unchanged', $service);
    self::assertStringContainsString('ecommerce admin and storefront UI', $adapter);
    self::assertStringContainsString('advanced maintenance', $adapter);
}

private function read_WebsiteLocaleUnionTranslation(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}
}
