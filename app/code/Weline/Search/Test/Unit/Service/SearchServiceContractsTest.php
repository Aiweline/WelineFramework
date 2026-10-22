<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Service\QueueDispatchService;
use Weline\Search\Dto\SearchHit;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Queue\SearchIndexIncrementalQueue;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchProjectionQueueAdmission;
use Weline\Search\Service\SearchProviderIndexService;
use Weline\Search\Service\SearchQueryResultTipCacheWarmer;
use Weline\Search\Service\SearchStorefrontCacheCoordinator;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllSearchServiceContractsPass().
 */
final class SearchServiceContractsTest extends TestCase
{
    public function testAllSearchServiceContractsPass(): void
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
            'SearchProjectionQueueAdmission::testSlotKeyIsStablePerTargetAndScope' => function () {
            $this->assert_SearchProjectionQueueAdmission_testSlotKeyIsStablePerTargetAndScope();
        },
            'SearchProjectionQueueAdmission::testObserverDelegatesToCoalescedAdmissionService' => function () {
            $this->assert_SearchProjectionQueueAdmission_testObserverDelegatesToCoalescedAdmissionService();
        },
            'SearchProviderIndexService::testServiceClassExistsAndExposesRebuildApi' => function () {
            $this->assert_SearchProviderIndexService_testServiceClassExistsAndExposesRebuildApi();
        },
            'SearchProviderLabelSource::testRegistryBuildsAllLabelAsChineseSource' => function () {
            $this->assert_SearchProviderLabelSource_testRegistryBuildsAllLabelAsChineseSource();
        },
            'SearchProviderLabelSource::testFrontendProvidersReturnStableSourcesWithoutPhraseBake' => function () {
            $this->assert_SearchProviderLabelSource_testFrontendProvidersReturnStableSourcesWithoutPhraseBake();
        },
            'SearchQueryResultCache::testQueryResultPolicyCoversCurrencyLangAreaAndSearchPool' => function () {
            $this->assert_SearchQueryResultCache_testQueryResultPolicyCoversCurrencyLangAreaAndSearchPool();
        },
            'SearchQueryResultCache::testQueryResultLogicalKeySeparatesQTypePageAndAutocomplete' => function () {
            $this->assert_SearchQueryResultCache_testQueryResultLogicalKeySeparatesQTypePageAndAutocomplete();
        },
            'SearchQueryResultCache::testSearchHubWiresRememberPolicyForPageQueries' => function () {
            $this->assert_SearchQueryResultCache_testSearchHubWiresRememberPolicyForPageQueries();
        },
            'SearchQueryResultCache::testSearchResultCacheRoundTripPreservesSections' => function () {
            $this->assert_SearchQueryResultCache_testSearchResultCacheRoundTripPreservesSections();
        },
            'SearchQueryResultTipCacheWarmer::testModuleProvidesTipQueryResultWarmer' => function () {
            $this->assert_SearchQueryResultTipCacheWarmer_testModuleProvidesTipQueryResultWarmer();
        },
            'SearchQueryResultTipCacheWarmer::testWarmerTargetsSearchPoolWithBoundedPriority' => function () {
            $this->assert_SearchQueryResultTipCacheWarmer_testWarmerTargetsSearchPoolWithBoundedPriority();
        },
            'SearchQueryResultTipCacheWarmer::testClearWarmsSearchPoolWithoutSearchFqcn' => function () {
            $this->assert_SearchQueryResultTipCacheWarmer_testClearWarmsSearchPoolWithoutSearchFqcn();
        },
            'SearchQueryResultTipCacheWarmer::testWarmerIsBoundedTipNotFullQuerySpace' => function () {
            $this->assert_SearchQueryResultTipCacheWarmer_testWarmerIsBoundedTipNotFullQuerySpace();
        },
            'SearchQueryResultTipCacheWarmer::testProjectionWarmerDocumentsNotQueryResultHit' => function () {
            $this->assert_SearchQueryResultTipCacheWarmer_testProjectionWarmerDocumentsNotQueryResultHit();
        },
        ];
    }

private function assert_SearchProjectionQueueAdmission_testSlotKeyIsStablePerTargetAndScope(): void
{
    $admission = new SearchProjectionQueueAdmission($this->createMock(QueueDispatchService::class));
    $scope = ScopeIdentity::store(0, 'default', 'store-a', ScopeIdentity::MODE_NORMAL);
    self::assertSame('slot:product:301:store:0:default:store-a:' . ScopeIdentity::MODE_NORMAL, $admission->slotKey($scope, 'product', 301));
    self::assertSame($admission->slotKey($scope, 'product', 301), $admission->slotKey($scope, 'product', 301));
    self::assertNotSame($admission->slotKey($scope, 'product', 301), $admission->slotKey($scope, 'product', 302));
}

private function assert_SearchProjectionQueueAdmission_testObserverDelegatesToCoalescedAdmissionService(): void
{
    $observer = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Observer/ProductSearchProjectionChangedObserver.php');
    $admission = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Service/SearchProjectionQueueAdmission.php');
    $module = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/etc/module.php');
    self::assertStringContainsString('SearchProjectionQueueAdmissionInterface', $observer);
    self::assertStringContainsString('$this->admission->admit(', $observer);
    self::assertStringNotContainsString("search-projection:' . \$change->eventId()", $observer);
    self::assertStringContainsString('IDEMPOTENCY_SCOPE', $admission);
    self::assertStringContainsString("slotKey . ':followup'", $admission);
    self::assertStringContainsString('requeueQueueSafely', $admission);
    self::assertStringContainsString('SearchProjectionQueueAdmissionInterface', $module);
    self::assertSame(SearchIndexIncrementalQueue::CONTRACT, 'search.incremental_queue.v1');
}

private function assert_SearchProviderIndexService_testServiceClassExistsAndExposesRebuildApi(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Service/SearchProviderIndexService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function rebuild(', $source);
    self::assertStringContainsString('function rebuildAll(', $source);
    self::assertStringContainsString('function enqueueUpsert(', $source);
    self::assertStringContainsString('function enqueueDelete(', $source);
    self::assertTrue(class_exists(SearchProviderIndexService::class));
    self::assertTrue(\Weline\Search\Queue\SearchProviderIndexQueue::shouldApply(5, 5));
    self::assertFalse(\Weline\Search\Queue\SearchProviderIndexQueue::shouldApply(6, 5));
}

private function assert_SearchProviderLabelSource_testRegistryBuildsAllLabelAsChineseSource(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Service/SearchProviderRegistry.php');
    self::assertStringContainsString("'label' => '全部'", $source);
    self::assertStringNotContainsString("__('全部')", $source);
    self::assertStringContainsString('search.provider_types.v5.', $source);
    self::assertStringNotContainsString('search.provider_types.v4.', $source);
}

private function assert_SearchProviderLabelSource_testFrontendProvidersReturnStableSourcesWithoutPhraseBake(): void
{
    $product = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/extends/module/Weline_Search/Searcher/ProductSearchProvider.php');
    $blog = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Blog/extends/module/Weline_Search/Searcher/BlogSearchProvider.php');
    $faq = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Faq/extends/module/Weline_Search/Searcher/FaqSearchProvider.php');
    self::assertStringContainsString("return '商品';", $product);
    self::assertStringNotContainsString("__('商品')", $product);
    self::assertStringContainsString("return '博客';", $blog);
    self::assertStringNotContainsString("__('博客')", $blog);
    self::assertStringContainsString("return '常问问题';", $faq);
    self::assertStringNotContainsString("__('FAQ')", $faq);
    self::assertStringNotContainsString("return 'FAQ';", $faq);
}

private function assert_SearchQueryResultCache_testQueryResultPolicyCoversCurrencyLangAreaAndSearchPool(): void
{
    $policy = SearchStorefrontCacheCoordinator::queryResultPolicy();
    self::assertSame('search.query_result', $policy->resource);
    self::assertSame('search', $policy->pool);
    self::assertSame('channel', $policy->scope);
    self::assertSame(['area', 'currency', 'lang'], $policy->vary);
    self::assertContains('catalog', $policy->dependencies);
    self::assertGreaterThanOrEqual(2000, $policy->singleFlightWaitMs);
    self::assertTrue($policy->allowsEmptyResult);
}

private function assert_SearchQueryResultCache_testQueryResultLogicalKeySeparatesQTypePageAndAutocomplete(): void
{
    $a = SearchStorefrontCacheCoordinator::queryResultLogicalKey('Hanfu', 'all', 1, 24, 'mysql', 'frontend');
    $b = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 1, 24, 'mysql', 'frontend');
    $c = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'product', 1, 24, 'mysql', 'frontend');
    $d = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 2, 24, 'mysql', 'frontend');
    $e = SearchStorefrontCacheCoordinator::queryResultLogicalKey('hanfu', 'all', 1, 24, 'mysql', 'frontend', true);
    self::assertSame($a, $b);
    self::assertNotSame($a, $c);
    self::assertNotSame($a, $d);
    self::assertNotSame($a, $e);
}

private function assert_SearchQueryResultCache_testSearchHubWiresRememberPolicyForPageQueries(): void
{
    $hub = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Service/SearchHubService.php');
    self::assertStringContainsString('queryResultPolicy', $hub);
    self::assertStringContainsString('queryResultLogicalKey', $hub);
    self::assertStringContainsString('rememberPolicy', $hub);
    self::assertStringContainsString('fromCacheArray', $hub);
    self::assertStringContainsString('Autocomplete is short-lived', $hub);
}

private function assert_SearchQueryResultCache_testSearchResultCacheRoundTripPreservesSections(): void
{
    $hit = new SearchHit('product', 'product', '42', 'Title', '/p/42', ['sku' => 'X'], 1.5);
    $original = new SearchResult(ok: true, type: 'all', hits: [], hitCount: 1, sections: ['product' => [$hit]], meta: ['result_cache' => 'miss_build'], elapsedMs: 12.5, engine: 'mysql');
    $restored = SearchResult::fromCacheArray($original->toCacheArray());
    self::assertTrue($restored->ok);
    self::assertSame('all', $restored->type);
    self::assertSame(1, $restored->hitCount);
    self::assertArrayHasKey('product', $restored->sections);
    self::assertSame('42', $restored->sections['product'][0]->entityId);
    self::assertSame('X', $restored->sections['product'][0]->payload['sku'] ?? null);
}

private function assert_SearchQueryResultTipCacheWarmer_testModuleProvidesTipQueryResultWarmer(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertSame(SearchQueryResultTipCacheWarmer::class, $provides['cache.warmer.search.query_result_tip'] ?? null);
    self::assertSame('1.4.39', (string) ($module['version'] ?? ''));
}

private function assert_SearchQueryResultTipCacheWarmer_testWarmerTargetsSearchPoolWithBoundedPriority(): void
{
    $warmer = new SearchQueryResultTipCacheWarmer();
    self::assertSame('search', $warmer->getTargetPool());
    self::assertSame('search.query_result_tip', $warmer->getName());
    self::assertSame(225, $warmer->getPriority());
    self::assertGreaterThan(220, $warmer->getPriority());
    self::assertLessThan(230, $warmer->getPriority());
}

private function assert_SearchQueryResultTipCacheWarmer_testClearWarmsSearchPoolWithoutSearchFqcn(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/Console/Cache/Clear.php');
    self::assertStringContainsString("warmUp('search')", $src);
    self::assertStringNotContainsString('SearchQueryResultTipCacheWarmer', $src);
}

private function assert_SearchQueryResultTipCacheWarmer_testWarmerIsBoundedTipNotFullQuerySpace(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Search' . '/Service/SearchQueryResultTipCacheWarmer.php');
    self::assertStringContainsString('TIP_QS', $src);
    self::assertStringContainsString("'hanfu'", $src);
    self::assertStringContainsString("'silk'", $src);
    self::assertStringContainsString('TIP_LANGS', $src);
    self::assertStringContainsString('TIP_CURRENCIES', $src);
    self::assertStringContainsString('TIP_SCOPES', $src);
    self::assertStringContainsString("'bn_BD'", $src);
    self::assertStringContainsString("'GBP'", $src);
    self::assertStringContainsString('queryResultPolicy', $src);
    self::assertStringContainsString('queryResultLogicalKey', $src);
    self::assertStringContainsString('SearchHubService', $src);
    self::assertStringContainsString('never warm the full query space', $src);
    self::assertStringContainsString('Sticky hit2 alone is NOT an acceptance pass', $src);
    self::assertStringNotContainsString('getWebsiteLanguageCodes', $src);
    self::assertStringNotContainsString('HotWordsService', $src);
    self::assertStringNotContainsString('static $', $src);
}

private function assert_SearchQueryResultTipCacheWarmer_testProjectionWarmerDocumentsNotQueryResultHit(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/Service/SearchProjectionScopeCacheWarmer.php');
    self::assertStringContainsString('NOT proof that tip search.query_result', $src);
    self::assertStringContainsString('SearchQueryResultTipCacheWarmer', $src);
}
}
