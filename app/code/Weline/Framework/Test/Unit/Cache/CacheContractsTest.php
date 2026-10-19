<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\BatchCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupCapacityPlanner;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupExcludeGate;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupHttpClient;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOfferQueue;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupSitemapParser;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Context;
use Weline\Framework\Cron\StorefrontWarmup;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability\FpcWarmCapability;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Service\MemoryStateFacade;

/**
 * Aggregated contract suite (merged from 11 *ContractTest files).
 * Observe one overall pass/fail via testAllCacheContractsPass().
 */
final class CacheContractsTest extends TestCase
{
    public function testAllCacheContractsPass(): void
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
            'FileAdapterNearLimit::testGetChecksHeadroomForAnySizedBlobNotOnlyHugeFiles' => function () {
            $this->assert_FileAdapterNearLimit_testGetChecksHeadroomForAnySizedBlobNotOnlyHugeFiles();
        },
            'CachePoolBatchAdapter::testGetMultipleAndSetMultiplePreferBatchAdapterOnce' => function () {
            $this->assert_CachePoolBatchAdapter_testGetMultipleAndSetMultiplePreferBatchAdapterOnce();
        },
            'CachePoolBatchAdapter::testDeleteMultiplePreferBatchAdapterOnce' => function () {
            $this->assert_CachePoolBatchAdapter_testDeleteMultiplePreferBatchAdapterOnce();
        },
            'CachePoolBatchAdapter::testNonBatchAdapterFallsBackToPerKeyLoop' => function () {
            $this->assert_CachePoolBatchAdapter_testNonBatchAdapterFallsBackToPerKeyLoop();
        },
            'CachePoolBatchAdapter::testDisabledPoolSkipsBatchAdapterAndCountsMisses' => function () {
            $this->assert_CachePoolBatchAdapter_testDisabledPoolSkipsBatchAdapterAndCountsMisses();
        },
            'CachePoolBatchAdapter::testSetMultipleAppliesJitterOnceForWholeBatch' => function () {
            $this->assert_CachePoolBatchAdapter_testSetMultipleAppliesJitterOnceForWholeBatch();
        },
            'CachePoolBatchAdapter::testEmptyDeleteMultipleDoesNotTouchAdapter' => function () {
            $this->assert_CachePoolBatchAdapter_testEmptyDeleteMultipleDoesNotTouchAdapter();
        },
            'Namespace::testCache01CanonicalPathExpandsParentsWithoutGlobalWebsiteRoot' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testCache01CanonicalPathExpandsParentsWithoutGlobalWebsiteRoot();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testCache01RejectsAmbiguousOrNonCanonicalPaths' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testCache01RejectsAmbiguousOrNonCanonicalPaths();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testCache02FingerprintIsOrderIndependentAndScopesKeysAndTags' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testCache02FingerprintIsOrderIndependentAndScopesKeysAndTags();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testCache03RequestSnapshotFreezesVectorAndReusesReconciledProcessState' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testCache03RequestSnapshotFreezesVectorAndReusesReconciledProcessState();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testCache03ClockJumpDropsUnknownProcessVectorMembers' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testCache03ClockJumpDropsUnknownProcessVectorMembers();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testMissingLoadDoesNotOverwriteConcurrentInvalidation' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testMissingLoadDoesNotOverwriteConcurrentInvalidation();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testMissingLoadPreservesOtherNamespacesFilledDuringYield' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testMissingLoadPreservesOtherNamespacesFilledDuringYield();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'Namespace::testSameClockMissingNamespacesReuseProcessThenBatchOnlyRemainder' => function () {
            $this->setUp_Namespace();
            try {
                $this->assert_Namespace_testSameClockMissingNamespacesReuseProcessThenBatchOnlyRemainder();
            } finally {
                $this->tearDown_Namespace();
            }
        },
            'NamespaceGenerationFreshInstallMissingTable::testReadStoredRowsSoftFailsWhenAuthorityTableMissing' => function () {
            $this->assert_NamespaceGenerationFreshInstallMissingTable_testReadStoredRowsSoftFailsWhenAuthorityTableMissing();
        },
            'NamespaceVersionPrefetchAndHotCacheMget::testPrefetchProcessVectorUsesHashIn' => function () {
            $this->assert_NamespaceVersionPrefetchAndHotCacheMget_testPrefetchProcessVectorUsesHashIn();
        },
            'NamespaceVersionPrefetchAndHotCacheMget::testStorefrontHotCacheStillUsesProtocolMgetPath' => function () {
            $this->assert_NamespaceVersionPrefetchAndHotCacheMget_testStorefrontHotCacheStillUsesProtocolMgetPath();
        },
            'ScopeSharedMemo::testHelperExposesRememberForgetAndGlobalDimension' => function () {
            $this->assert_ScopeSharedMemo_testHelperExposesRememberForgetAndGlobalDimension();
        },
            'ScopeSharedMemo::testPaymentTaxShippingPoolsArePredefined' => function () {
            $this->assert_ScopeSharedMemo_testPaymentTaxShippingPoolsArePredefined();
        },
            'StorefrontWarmupOfferQueue::testOfferQueueAndWarmEffectExistWithoutCdn' => function () {
            $this->assert_StorefrontWarmupOfferQueue_testOfferQueueAndWarmEffectExistWithoutCdn();
        },
            'StorefrontWarmupOfferQueue::testPipelineEnqueuesExpandedUrlsAfterPurge' => function () {
            $this->assert_StorefrontWarmupOfferQueue_testPipelineEnqueuesExpandedUrlsAfterPurge();
        },
            'StorefrontWarmupOfferQueue::testEventXmlRegistersStorefrontWarmupOffer' => function () {
            $this->assert_StorefrontWarmupOfferQueue_testEventXmlRegistersStorefrontWarmupOffer();
        },
            'StorefrontWarmupOfferQueue::testOfferTakeRoundTripOnDisk' => function () {
            $this->assert_StorefrontWarmupOfferQueue_testOfferTakeRoundTripOnDisk();
        },
            'StorefrontWarmupOfferQueue::testOrchestratorDrainsOffersBeforePhases' => function () {
            $this->assert_StorefrontWarmupOfferQueue_testOrchestratorDrainsOffersBeforePhases();
        },
            'StorefrontWarmupOrchestrator::testOrchestratorAndCronLiveInFrameworkCacheDomain' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testOrchestratorAndCronLiveInFrameworkCacheDomain();
        },
            'StorefrontWarmupOrchestrator::testPhaseOrderConstantsAreAThenBThenCThenD' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testPhaseOrderConstantsAreAThenBThenCThenD();
        },
            'StorefrontWarmupOrchestrator::testExcludeGateBlacklistsSearchCartCheckoutAccount' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testExcludeGateBlacklistsSearchCartCheckoutAccount();
        },
            'StorefrontWarmupOrchestrator::testSitemapParserExtractsIndexShardsAndHtmlLocs' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testSitemapParserExtractsIndexShardsAndHtmlLocs();
        },
            'StorefrontWarmupOrchestrator::testClearUsesOrchestratorNotThemeFqcn' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testClearUsesOrchestratorNotThemeFqcn();
        },
            'StorefrontWarmupOrchestrator::testWarmupHttpClientAppendsPierceStamp' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testWarmupHttpClientAppendsPierceStamp();
        },
            'StorefrontWarmupOrchestrator::testWarmupHttpClientNeedBodyForcesOriginAndRefusesHit' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testWarmupHttpClientNeedBodyForcesOriginAndRefusesHit();
        },
            'StorefrontWarmupOrchestrator::testDesignedBudgetAndTrafficBandsWithoutStickyBackoff' => function () {
            $this->assert_StorefrontWarmupOrchestrator_testDesignedBudgetAndTrafficBandsWithoutStickyBackoff();
        },
            'SharedResponseCachePolicyForbiddenEvent::testForbidDispatchesSharedCacheForbiddenEventConstant' => function () {
            $this->assert_SharedResponseCachePolicyForbiddenEvent_testForbidDispatchesSharedCacheForbiddenEventConstant();
        },
            'StorefrontScopeHotCachePrefetchPolicy::testPrefetchPolicyApiExistsAndIsDocumentedForWidgetAssets' => function () {
            $this->assert_StorefrontScopeHotCachePrefetchPolicy_testPrefetchPolicyApiExistsAndIsDocumentedForWidgetAssets();
        },
            'StorefrontScopeHotCachePrefetchPolicy::testCachePoolExposesGetMultipleCustomMatchingGetCustom' => function () {
            $this->assert_StorefrontScopeHotCachePrefetchPolicy_testCachePoolExposesGetMultipleCustomMatchingGetCustom();
        },
            'StorefrontScopeHotCacheProcessL1::testCachePolicyDefaultsStoreProcessL1True' => function () {
            $this->assert_StorefrontScopeHotCacheProcessL1_testCachePolicyDefaultsStoreProcessL1True();
        },
            'StorefrontScopeHotCacheProcessL1::testCachePolicyCanDisableProcessL1' => function () {
            $this->assert_StorefrontScopeHotCacheProcessL1_testCachePolicyCanDisableProcessL1();
        },
            'StorefrontScopeHotCacheProcessL1::testHotCachePipesStoreProcessL1IntoRememberKey' => function () {
            $this->assert_StorefrontScopeHotCacheProcessL1_testHotCachePipesStoreProcessL1IntoRememberKey();
        },
            'StorefrontScopeHotCacheProcessL1::testEstimateSamplesNestedStringBytes' => function () {
            $this->assert_StorefrontScopeHotCacheProcessL1_testEstimateSamplesNestedStringBytes();
        },
        ];
    }

private function assert_FileAdapterNearLimit_testGetChecksHeadroomForAnySizedBlobNotOnlyHugeFiles(): void
{
    $fw = '/Users/weline/Project/Official/框架/app/code/Weline/Framework';
    $src = (string) \file_get_contents($fw . '/Cache/Adapter/FileAdapter.php');
    self::assertStringContainsString('NEAR_LIMIT_PAYLOAD_FLOOR_BYTES', $src);
    self::assertStringContainsString('NEAR_LIMIT_HEADROOM_BYTES', $src);
    self::assertStringContainsString('memoryHeadroomBytes() < $needBytes', $src);
    self::assertStringContainsString('never process-fatal OOM', $src);
    self::assertSame('2.5.340', (string) (require $fw . '/etc/module.php')['version']);
}

private function assert_CachePoolBatchAdapter_testGetMultipleAndSetMultiplePreferBatchAdapterOnce(): void
{
    $adapter = new CachePoolBatchSpyAdapter();
    $pool = new CachePool('unit_batch', $adapter, 'batch contract', false, 300, true, 0.0);
    self::assertTrue($pool->setMultiple(['a' => 'alpha', 'b' => false, 'c' => 0], 120));
    self::assertSame(1, $adapter->setMultipleCalls);
    self::assertSame(0, $adapter->setCalls);
    self::assertSame(120, $adapter->lastSetMultipleTtl);
    self::assertCount(3, $adapter->lastSetMultipleValues);
    $result = $pool->getMultiple(['a', 'b', 'c', 'missing']);
    self::assertSame(1, $adapter->getMultipleCalls);
    self::assertSame(0, $adapter->getCalls);
    self::assertSame('alpha', $result['a']);
    self::assertFalse($result['b']);
    self::assertSame(0, $result['c']);
    self::assertNull($result['missing']);
    $stats = $pool->getStats();
    self::assertSame(3, $stats['hits']);
    self::assertSame(1, $stats['misses']);
}

private function assert_CachePoolBatchAdapter_testDeleteMultiplePreferBatchAdapterOnce(): void
{
    $adapter = new CachePoolBatchSpyAdapter();
    $pool = new CachePool('unit_batch_del', $adapter, 'batch delete', false, 300, true, 0.0);
    self::assertTrue($pool->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]));
    $adapter->setMultipleCalls = 0;
    self::assertTrue($pool->deleteMultiple(['a', 'b', 'missing']));
    self::assertSame(1, $adapter->deleteMultipleCalls);
    self::assertSame(0, $adapter->deleteCalls);
    self::assertCount(3, $adapter->lastDeleteMultipleKeys);
    self::assertNull($pool->get('a'));
    self::assertNull($pool->get('b'));
    self::assertSame(3, $pool->get('c'));
}

private function assert_CachePoolBatchAdapter_testNonBatchAdapterFallsBackToPerKeyLoop(): void
{
    $adapter = new CachePoolSingleKeySpyAdapter();
    $pool = new CachePool('unit_single', $adapter, 'fallback', false, 300, true, 0.0);
    self::assertTrue($pool->setMultiple(['x' => 1, 'y' => 2], 60));
    self::assertSame(2, $adapter->setCalls);
    $result = $pool->getMultiple(['x', 'y', 'z']);
    self::assertSame(3, $adapter->getCalls);
    self::assertSame(1, $result['x']);
    self::assertSame(2, $result['y']);
    self::assertNull($result['z']);
    self::assertTrue($pool->deleteMultiple(['x', 'y']));
    self::assertSame(2, $adapter->deleteCalls);
    self::assertNull($pool->get('x'));
}

private function assert_CachePoolBatchAdapter_testDisabledPoolSkipsBatchAdapterAndCountsMisses(): void
{
    $adapter = new CachePoolBatchSpyAdapter();
    $pool = new CachePool('unit_disabled_batch', $adapter, 'disabled', false, 300, false);
    self::assertTrue($pool->setMultiple(['k' => 'v']));
    self::assertSame(['k' => null], $pool->getMultiple(['k']));
    self::assertSame(0, $adapter->setMultipleCalls);
    self::assertSame(0, $adapter->getMultipleCalls);
    self::assertSame(1, $pool->getStats()['misses']);
}

private function assert_CachePoolBatchAdapter_testSetMultipleAppliesJitterOnceForWholeBatch(): void
{
    $adapter = new CachePoolBatchSpyAdapter();
    // jitter_ratio=0.5 on TTL>=60 so applyJitter can move the value.
    $pool = new CachePool('unit_jitter_batch', $adapter, 'jitter', false, 300, true, 0.5);
    self::assertTrue($pool->setMultiple(['one' => 1, 'two' => 2], 100));
    self::assertSame(1, $adapter->setMultipleCalls);
    self::assertCount(2, $adapter->lastSetMultipleValues);
    // Both keys share the same already-jittered TTL argument.
    self::assertGreaterThanOrEqual(50, $adapter->lastSetMultipleTtl);
    self::assertLessThanOrEqual(150, $adapter->lastSetMultipleTtl);
}

private function assert_CachePoolBatchAdapter_testEmptyDeleteMultipleDoesNotTouchAdapter(): void
{
    $adapter = new CachePoolBatchSpyAdapter();
    $pool = new CachePool('unit_empty_del', $adapter, 'empty', false, 300, true, 0.0);
    self::assertTrue($pool->deleteMultiple([]));
    self::assertSame(0, $adapter->deleteMultipleCalls);
    self::assertSame(0, $adapter->deleteCalls);
}

private function setUp_Namespace(): void
{
    if (Context::hasCurrent()) {
        Context::leave();
    }
    Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
    RequestContext::setId('namespace-contract-request');
}

private function tearDown_Namespace(): void
{
    RequestContext::cleanup();
    if (Context::hasCurrent()) {
        Context::leave();
    }
}

private function assert_Namespace_testCache01CanonicalPathExpandsParentsWithoutGlobalWebsiteRoot(): void
{
    $paths = new NamespacePath();
    $leaf = $paths->website('default', ['catalog', 'product 7']);
    self::assertSame('website/default/catalog/product%207', $leaf);
    self::assertSame(['website/default', 'website/default/catalog', 'website/default/catalog/product%207'], $paths->ancestors($leaf));
    self::assertSame(['global/websites-registry', 'website/default', 'website/default/catalog', 'website/default/catalog/product%207'], $paths->expandAncestors([$leaf, 'global/websites-registry']));
    self::assertNotContains('website', $paths->ancestors($leaf));
}

private function assert_Namespace_testCache01RejectsAmbiguousOrNonCanonicalPaths(): void
{
    $paths = new NamespacePath();
    foreach (['', '@clock', 'website', '/website/default', 'website//default', 'website/default/', 'catalog/default', 'website/../catalog', 'website/default/product%2f7'] as $invalid) {
        try {
            $paths->canonicalize($invalid);
            self::fail('Invalid namespace path must be rejected: ' . $invalid);
        } catch (\InvalidArgumentException) {
            self::assertTrue(true, $invalid);
        }
    }
}

private function assert_Namespace_testCache02FingerprintIsOrderIndependentAndScopesKeysAndTags(): void
{
    $decorator = new NamespaceKeyDecorator();
    $vector = ['website/default/catalog' => 4, 'website/default' => 2];
    $reversed = array_reverse($vector, true);
    $fingerprint = $decorator->fingerprint($vector);
    self::assertSame($fingerprint, $decorator->fingerprint($reversed));
    self::assertNotSame($fingerprint, $decorator->fingerprint(['website/default/catalog' => 5, 'website/default' => 2]));
    $key = $decorator->decorate('product/7', $fingerprint);
    $tag = $decorator->decorateTag('catalog', $fingerprint);
    self::assertSame('product/7', $decorator->undecorate($key, $fingerprint));
    self::assertSame('catalog', $decorator->undecorateTag($tag, $fingerprint));
    self::assertNull($decorator->undecorate($key, str_repeat('a', 64)));
}

private function assert_Namespace_testCache03RequestSnapshotFreezesVectorAndReusesReconciledProcessState(): void
{
    $snapshot = new NamespaceGenerationSnapshot();
    $ancestors = ['website/default', 'website/default/catalog'];
    $calls = [];
    $loader = static function (array $namespaces) use (&$calls): array {
        $calls[] = $namespaces;
        if ($namespaces === [NamespacePath::AUTHORITY_CLOCK]) {
            return [NamespacePath::AUTHORITY_CLOCK => 7];
        }
        return ['website/default' => 2, 'website/default/catalog' => 4];
    };
    self::assertSame(['authority_clock' => 7, 'generations' => ['website/default' => 2, 'website/default/catalog' => 4]], $snapshot->resolve($ancestors, $loader));
    self::assertSame([[NamespacePath::AUTHORITY_CLOCK], $ancestors], $calls);
    $snapshot->resolve($ancestors, static function (): array {
        self::fail('Frozen request vector must not return to storage.');
    });
    $snapshot->beginRequest();
    $newRequestCalls = [];
    $resolved = $snapshot->resolve($ancestors, static function (array $namespaces) use (&$newRequestCalls): array {
        $newRequestCalls[] = $namespaces;
        return [NamespacePath::AUTHORITY_CLOCK => 7];
    });
    self::assertSame([[NamespacePath::AUTHORITY_CLOCK]], $newRequestCalls);
    self::assertSame(4, $resolved['generations']['website/default/catalog']);
}

private function assert_Namespace_testCache03ClockJumpDropsUnknownProcessVectorMembers(): void
{
    $snapshot = new NamespaceGenerationSnapshot();
    $snapshot->replaceProcessSnapshot(3, ['website/default' => 2, 'website/other' => 9]);
    $snapshot->advance(5, ['website/default' => 4]);
    self::assertSame(['authority_clock' => 5, 'generations' => ['website/default' => 4]], $snapshot->processSnapshot());
}

private function assert_Namespace_testMissingLoadDoesNotOverwriteConcurrentInvalidation(): void
{
    $snapshot = new NamespaceGenerationSnapshot();
    $snapshot->replaceProcessSnapshot(1, ['website/default' => 1]);
    $snapshot->resolve(['website/default'], static fn(): array => [NamespacePath::AUTHORITY_CLOCK => 1]);
    $resolved = $snapshot->resolve(['website/default/catalog'], static function () use ($snapshot): array {
        $writer = new \Fiber(static function () use ($snapshot): void {
            Context::enter(new Context());
            try {
                RequestContext::setId('namespace-concurrent-writer');
                $snapshot->advance(2, ['website/default/catalog' => 9]);
            } finally {
                Context::leave();
            }
        });
        $writer->start();
        return ['website/default/catalog' => 1];
    });
    self::assertSame(1, $resolved['authority_clock']);
    self::assertSame(1, $resolved['generations']['website/default/catalog']);
    self::assertSame(2, $snapshot->processSnapshot()['authority_clock']);
    self::assertSame(9, $snapshot->processSnapshot()['generations']['website/default/catalog']);
}

private function assert_Namespace_testMissingLoadPreservesOtherNamespacesFilledDuringYield(): void
{
    $snapshot = new NamespaceGenerationSnapshot();
    $snapshot->replaceProcessSnapshot(1, ['website/default' => 1]);
    $snapshot->resolve(['website/default'], static fn(): array => [NamespacePath::AUTHORITY_CLOCK => 1]);
    $snapshot->resolve(['website/default/catalog'], static function () use ($snapshot): array {
        $reader = new \Fiber(static function () use ($snapshot): void {
            Context::enter(new Context());
            try {
                RequestContext::setId('namespace-concurrent-reader');
                $snapshot->resolve(['website/default/price'], static fn(array $keys): array => $keys === [NamespacePath::AUTHORITY_CLOCK] ? [NamespacePath::AUTHORITY_CLOCK => 1] : ['website/default/price' => 4]);
            } finally {
                Context::leave();
            }
        });
        $reader->start();
        return ['website/default/catalog' => 2];
    });
    self::assertSame(['website/default' => 1, 'website/default/catalog' => 2, 'website/default/price' => 4], $snapshot->processSnapshot()['generations']);
}

private function assert_Namespace_testSameClockMissingNamespacesReuseProcessThenBatchOnlyRemainder(): void
{
    $snapshot = new NamespaceGenerationSnapshot();
    $snapshot->replaceProcessSnapshot(7, ['website/default' => 2, 'website/default/catalog' => 4]);
    $calls = [];
    $loader = static function (array $namespaces) use (&$calls): array {
        $calls[] = $namespaces;
        if ($namespaces === [NamespacePath::AUTHORITY_CLOCK]) {
            return [NamespacePath::AUTHORITY_CLOCK => 7];
        }
        $out = [];
        foreach ($namespaces as $namespace) {
            $out[$namespace] = $namespace === 'website/default/price' ? 9 : 0;
        }
        return $out;
    };
    $first = $snapshot->resolve(['website/default'], $loader);
    self::assertSame(2, $first['generations']['website/default']);
    self::assertSame([[NamespacePath::AUTHORITY_CLOCK]], $calls);
    $second = $snapshot->resolve(['website/default', 'website/default/catalog', 'website/default/price'], $loader);
    self::assertSame(['website/default' => 2, 'website/default/catalog' => 4, 'website/default/price' => 9], $second['generations']);
    self::assertSame([[NamespacePath::AUTHORITY_CLOCK], ['website/default/price']], $calls);
    self::assertSame(9, $snapshot->processSnapshot()['generations']['website/default/price']);
}

private function assert_NamespaceGenerationFreshInstallMissingTable_testReadStoredRowsSoftFailsWhenAuthorityTableMissing(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Namespace/NamespaceGenerationRepository.php');
    self::assertStringContainsString('isGenerationAuthorityTableMissing', $src);
    self::assertStringContainsString('Fresh install / setup:upgrade', $src);
    self::assertStringContainsString('42P01', $src);
    self::assertStringContainsString('Base table or view not found', $src);
    self::assertStringContainsString(NamespaceVersion::schema_table, $src);
    self::assertMatchesRegularExpression('/catch\s*\(\s*\\\\Throwable\s+\$e\s*\)\s*\{[^}]*isGenerationAuthorityTableMissing/s', $src);
    self::assertTrue(method_exists(NamespaceGenerationRepository::class, 'canonicalize'));
}

private function assert_NamespaceVersionPrefetchAndHotCacheMget_testPrefetchProcessVectorUsesHashIn(): void
{
    self::assertTrue(method_exists(NamespaceGenerationRepository::class, 'prefetchProcessVector'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Namespace/NamespaceGenerationRepository.php');
    self::assertStringContainsString('function prefetchProcessVector', $src);
    self::assertStringContainsString('readStoredRows($hashes)', $src);
    self::assertStringContainsString('replaceProcessSnapshot', $src);
    self::assertStringContainsString("schema_fields_HASH, array_keys(\$expectedByHash), 'IN'", $src);
}

private function assert_NamespaceVersionPrefetchAndHotCacheMget_testStorefrontHotCacheStillUsesProtocolMgetPath(): void
{
    self::assertTrue(method_exists(StorefrontScopeHotCache::class, 'prefetchPolicy'));
    $hotSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontScopeHotCache.php');
    self::assertStringContainsString('readSharedMultiple', $hotSrc);
    self::assertStringContainsString('storefront.cache.shared_read_batch', $hotSrc);
    self::assertTrue(method_exists(MemoryStateFacade::class, 'getCacheMultiple'));
    $facadeSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Server/Service/MemoryStateFacade.php');
    self::assertStringContainsString("cache_mget", $facadeSrc);
    self::assertStringContainsString('function getCacheMultiple', $facadeSrc);
}

private function assert_ScopeSharedMemo_testHelperExposesRememberForgetAndGlobalDimension(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/ScopeSharedMemo.php');
    self::assertStringContainsString('function remember', $src);
    self::assertStringContainsString('function forget', $src);
    self::assertStringContainsString('function rememberScoped', $src);
    self::assertStringContainsString('function forgetScoped', $src);
    self::assertStringContainsString('function logicalKey', $src);
    self::assertStringContainsString("'website' => false", $src);
    self::assertStringContainsString('StorefrontScopeHotCache', $src);
    self::assertStringContainsString('canonicalKey()', $src);
    $fn = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Common/functions.php');
    self::assertStringContainsString('function w_scope_key', $fn);
}

private function assert_ScopeSharedMemo_testPaymentTaxShippingPoolsArePredefined(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/CacheManager.php');
    self::assertStringContainsString("'payment'", $src);
    self::assertStringContainsString("'tax'", $src);
    self::assertStringContainsString("'shipping'", $src);
    self::assertStringContainsString("'seo'", $src);
}

private function assert_StorefrontWarmupOfferQueue_testOfferQueueAndWarmEffectExistWithoutCdn(): void
{
    self::assertTrue(class_exists(StorefrontWarmupOfferQueue::class));
    self::assertSame(256, StorefrontWarmupOfferQueue::MAX_OFFER_BATCH);
    self::assertSame(512, StorefrontWarmupOfferQueue::MAX_PENDING);
    self::assertSame('warm_fpc_urls', InvalidationEffect::CODE_WARM_FPC_URLS);
    $cap = new FpcWarmCapability();
    self::assertSame('fpc_warm', $cap->code());
    self::assertContains(InvalidationEffect::CODE_WARM_FPC_URLS, $cap->supportedEffects());
    $src = (string) file_get_contents((new \ReflectionClass(FpcWarmCapability::class))->getFileName() ?: '');
    self::assertStringContainsString('StorefrontWarmupOfferQueue', $src);
    self::assertStringNotContainsString('Weline\Cdn\\', $src);
    self::assertStringNotContainsString('send_warmup', $src);
    self::assertStringNotContainsString('curl_', $src);
}

private function assert_StorefrontWarmupOfferQueue_testPipelineEnqueuesExpandedUrlsAfterPurge(): void
{
    $pipeline = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Changed/ChangedPipeline.php');
    self::assertStringContainsString('CODE_WARM_FPC_URLS', $pipeline);
    self::assertStringContainsString('expandUrlMatrix', $pipeline);
    self::assertStringContainsString("\$effect->payload['urls']", $pipeline);
    self::assertStringContainsString('Closed loop', $pipeline);
}

private function assert_StorefrontWarmupOfferQueue_testEventXmlRegistersStorefrontWarmupOffer(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Framework::storefront_warmup_offer', $xml);
    self::assertStringContainsString('StorefrontWarmupOfferObserver', $xml);
}

private function assert_StorefrontWarmupOfferQueue_testOfferTakeRoundTripOnDisk(): void
{
    self::assertTrue(\defined('BP') || \is_dir('/Users/weline/Project/Official/框架/app'), 'repo root must be resolvable');
    $queue = new StorefrontWarmupOfferQueue();
    $marker = 'https://offer-contract-' . \uniqid('', true) . '.example/product/x';
    $rel = '/offer-rel-' . \uniqid('', true);
    $accepted = $queue->offer([$marker, $marker, $rel], 'unit');
    self::assertSame(2, $accepted, 'dedupe identical absolute URL; keep relative');
    $foundMarker = false;
    $foundRel = false;
    $restore = [];
    // take until both test URLs appear or queue empty; restore unrelated rows
    for ($i = 0; $i < 40; $i++) {
        $batch = $queue->take(3);
        if ($batch === []) {
            break;
        }
        foreach ($batch as $row) {
            $u = (string) ($row['url'] ?? '');
            if ($u === $marker) {
                $foundMarker = true;
                continue;
            }
            if ($u === $rel) {
                $foundRel = true;
                continue;
            }
            $restore[] = $row;
        }
        if ($foundMarker && $foundRel) {
            break;
        }
    }
    foreach ($restore as $row) {
        $queue->offer([(string) $row['url']], (string) ($row['source'] ?? 'restore'), is_array($row['meta'] ?? null) ? $row['meta'] : []);
    }
    self::assertTrue($foundMarker, 'absolute offer must be take()-able');
    self::assertTrue($foundRel, 'relative offer must be take()-able');
}

private function assert_StorefrontWarmupOfferQueue_testOrchestratorDrainsOffersBeforePhases(): void
{
    $src = (string) file_get_contents((new \ReflectionClass(\Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator::class))->getFileName() ?: '');
    self::assertStringContainsString('drainOneOffer', $src);
    self::assertStringContainsString('StorefrontWarmupOfferQueue', $src);
    self::assertStringContainsString('getAbsolute', $src);
    self::assertStringContainsString('OFFER_DRAIN_PER_SLICE', $src);
}

private function assert_StorefrontWarmupOrchestrator_testOrchestratorAndCronLiveInFrameworkCacheDomain(): void
{
    $orchestrator = new \ReflectionClass(StorefrontWarmupOrchestrator::class);
    self::assertStringContainsString('Framework/Cache/Service/StorefrontWarmup', \str_replace('\\', '/', $orchestrator->getFileName() ?: ''));
    $cron = new \ReflectionClass(StorefrontWarmup::class);
    self::assertStringContainsString('Framework/Cron', \str_replace('\\', '/', $cron->getFileName() ?: ''));
    self::assertStringNotContainsString('Framework/Cache/Cron', \str_replace('\\', '/', $cron->getFileName() ?: ''));
    $cronSource = (string) \file_get_contents($cron->getFileName() ?: '');
    self::assertStringContainsString("'* * * * *'", $cronSource);
    self::assertStringContainsString('function locked()', $cronSource);
    self::assertStringContainsString('return true;', $cronSource);
    self::assertStringNotContainsString('Weline\Server\\', $cronSource);
    self::assertStringNotContainsString('WlsRuntime', $cronSource);
}

private function assert_StorefrontWarmupOrchestrator_testPhaseOrderConstantsAreAThenBThenCThenD(): void
{
    self::assertSame('A', StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
    self::assertSame('B', StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS);
    self::assertSame('C', StorefrontWarmupProgressLedger::PHASE_C_EXTRAS);
    self::assertSame('D', StorefrontWarmupProgressLedger::PHASE_D_LOCS);
}

private function assert_StorefrontWarmupOrchestrator_testExcludeGateBlacklistsSearchCartCheckoutAccount(): void
{
    $gate = new StorefrontWarmupExcludeGate();
    foreach (['/search', '/cart', '/checkout', '/account', '/customer', '/helppay/x'] as $path) {
        $result = $gate->evaluate($path);
        self::assertFalse($result['allow'], $path);
        self::assertSame('blacklist', $result['reason'], $path);
    }
}

private function assert_StorefrontWarmupOrchestrator_testSitemapParserExtractsIndexShardsAndHtmlLocs(): void
{
    $parser = new StorefrontWarmupSitemapParser();
    $index = <<<'XML'
    <?xml version="1.0"?>
    <sitemapindex>
      <sitemap><loc>https://example.test/sitemap-products.xml</loc></sitemap>
      <sitemap><loc>https://example.test/sitemap-blog.xml</loc></sitemap>
    </sitemapindex>
    XML;
    $docs = $parser->extractSitemapDocUrls($index);
    self::assertContains('https://example.test/sitemap-products.xml', $docs);
    self::assertSame('/sitemap-products.xml', $parser->locToPath($docs[0], false));
    $urlset = <<<'XML'
    <?xml version="1.0"?>
    <urlset>
      <url><loc>https://example.test/guide/shipping</loc></url>
      <url><loc>https://example.test/sitemap-products.xml</loc></url>
      <url><loc>https://example.test/search?q=x</loc></url>
    </urlset>
    XML;
    $locs = $parser->extractPageLocs($urlset);
    self::assertContains('https://example.test/guide/shipping', $locs);
    self::assertSame('/guide/shipping', $parser->locToPath('https://example.test/guide/shipping', true));
    self::assertNull($parser->locToPath('https://example.test/sitemap-products.xml', true));
}

private function assert_StorefrontWarmupOrchestrator_testClearUsesOrchestratorNotThemeFqcn(): void
{
    $clear = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Console/Cache/Clear.php';
    $source = (string) \file_get_contents($clear);
    self::assertStringContainsString('StorefrontWarmupOrchestrator', $source);
    self::assertStringContainsString('runAfterClear', $source);
    self::assertStringNotContainsString('StorefrontFpcWarmer', $source);
    self::assertStringNotContainsString('WlsRuntime', $source);
}

private function assert_StorefrontWarmupOrchestrator_testWarmupHttpClientAppendsPierceStamp(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontWarmup/StorefrontWarmupHttpClient.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('appendPierceQuery', $src);
    self::assertStringContainsString('FpcPierceStamp::buildQuerySuffix', $src);
    self::assertStringContainsString('Staging warm seeds hardlinks from live', $src);
}

private function assert_StorefrontWarmupOrchestrator_testWarmupHttpClientNeedBodyForcesOriginAndRefusesHit(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontWarmup/StorefrontWarmupHttpClient.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('x-wls-fpc-bypass: 1', $src);
    self::assertStringContainsString('fpc_hit_refuse_body', $src);
    self::assertStringContainsString('isFpcHit', $src);
    self::assertStringContainsString('requestTarget', $src);
    // Visitor/live assert path must allow no-pierce / no-bypass body fetch.
    self::assertStringContainsString('Post-promote visitor assert', $src);
    self::assertStringContainsString('bool $pierce = true', $src);
    self::assertStringContainsString('bool $bypass = false', $src);
}

private function assert_StorefrontWarmupOrchestrator_testDesignedBudgetAndTrafficBandsWithoutStickyBackoff(): void
{
    self::assertTrue(class_exists(StorefrontWarmupCapacityPlanner::class));
    self::assertTrue(method_exists(StorefrontWarmupHttpClient::class, 'getMany'));

    $planner = new StorefrontWarmupCapacityPlanner();
    $state = [
        'phase' => StorefrontWarmupProgressLedger::PHASE_D_LOCS,
        'locs' => \array_fill(0, 100, '/p'),
        'loc_cursor' => 0,
        'sitemap_docs' => [],
        'extras' => [],
    ];
    $full = $planner->plan($state, [
        'ratio' => 0.10,
        'start_threshold' => 0.40,
        'abort_threshold' => 0.70,
    ]);
    self::assertSame(StorefrontWarmupCapacityPlanner::BAND_FULL, $full['band']);
    self::assertGreaterThan(6, (int)$full['designed_shard']);
    self::assertGreaterThan(6, (int)$full['effective_shard']);
    self::assertGreaterThanOrEqual(2, (int)$full['designed_concurrency']);
    self::assertSame((int)$full['designed_shard'], (int)$full['effective_shard']);

    $scale = $planner->plan($state, [
        'ratio' => 0.50,
        'start_threshold' => 0.40,
        'abort_threshold' => 0.70,
    ]);
    self::assertSame(StorefrontWarmupCapacityPlanner::BAND_SCALE, $scale['band']);
    self::assertLessThan((int)$scale['designed_shard'], (int)$scale['effective_shard']);
    self::assertGreaterThan(0, (int)$scale['effective_shard']);

    $yield = $planner->plan($state, [
        'ratio' => 0.80,
        'start_threshold' => 0.40,
        'abort_threshold' => 0.70,
    ]);
    self::assertSame(StorefrontWarmupCapacityPlanner::BAND_YIELD, $yield['band']);
    self::assertSame(0, (int)$yield['effective_concurrency']);

    $orchSrc = (string) \file_get_contents((new \ReflectionClass(StorefrontWarmupOrchestrator::class))->getFileName() ?: '');
    self::assertStringContainsString('StorefrontWarmupCapacityPlanner', $orchSrc);
    self::assertStringContainsString('getMany', $orchSrc);
    self::assertStringContainsString('yield_traffic', $orchSrc);
    self::assertStringContainsString('advanceBatch', $orchSrc);
    self::assertStringNotContainsString('+ 60.0', $orchSrc);
    self::assertStringNotContainsString('+60.0', $orchSrc);
    self::assertStringNotContainsString('DEFAULT_SHARD = 6', $orchSrc);
    self::assertStringContainsString('256', (string) \file_get_contents((new \ReflectionClass(StorefrontWarmupCapacityPlanner::class))->getFileName() ?: ''));

    $httpSrc = (string) \file_get_contents((new \ReflectionClass(StorefrontWarmupHttpClient::class))->getFileName() ?: '');
    self::assertStringContainsString('function getMany', $httpSrc);
    self::assertStringContainsString('curl_multi_init', $httpSrc);
}

private function assert_SharedResponseCachePolicyForbiddenEvent_testForbidDispatchesSharedCacheForbiddenEventConstant(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/SharedResponseCachePolicy.php');
    self::assertStringContainsString("EVENT_SHARED_CACHE_FORBIDDEN = 'Weline_Framework::response::shared_cache_forbidden'", $src);
    self::assertStringContainsString('dispatch(self::EVENT_SHARED_CACHE_FORBIDDEN', $src);
    self::assertStringContainsString("'cache_control'", $src);
    self::assertStringContainsString('private, no-store, max-age=0, must-revalidate', $src);
    self::assertStringContainsString('X-Weline-Shared-Cache-Forbidden', $src);
}

private function assert_StorefrontScopeHotCachePrefetchPolicy_testPrefetchPolicyApiExistsAndIsDocumentedForWidgetAssets(): void
{
    self::assertTrue(method_exists(StorefrontScopeHotCache::class, 'prefetchPolicy'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontScopeHotCache.php');
    self::assertStringContainsString('storefront.cache.shared_read_batch', $src);
    self::assertStringContainsString('readSharedMultiple', $src);
    self::assertStringContainsString('getMultipleCustom', $src);
}

private function assert_StorefrontScopeHotCachePrefetchPolicy_testCachePoolExposesGetMultipleCustomMatchingGetCustom(): void
{
    $poolSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Pool/CachePool.php');
    self::assertStringContainsString('function getMultipleCustom', $poolSrc);
    self::assertStringContainsString('buildCustomKey', $poolSrc);
    $nsSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Pool/NamespaceScopedCachePool.php');
    self::assertStringContainsString('function getMultipleCustom', $nsSrc);
}

private function assert_StorefrontScopeHotCacheProcessL1_testCachePolicyDefaultsStoreProcessL1True(): void
{
    $policy = new CachePolicy(resource: 'test.resource', pool: 'product', scope: 'channel');
    self::assertTrue($policy->storeProcessL1);
}

private function assert_StorefrontScopeHotCacheProcessL1_testCachePolicyCanDisableProcessL1(): void
{
    $policy = new CachePolicy(resource: 'product.filter_panel.v3', pool: 'product', scope: 'channel', storeProcessL1: false);
    self::assertFalse($policy->storeProcessL1);
    self::assertFalse($policy->toArray()['store_process_l1']);
}

private function assert_StorefrontScopeHotCacheProcessL1_testHotCachePipesStoreProcessL1IntoRememberKey(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(StorefrontScopeHotCache::class))->getFileName());
    self::assertStringContainsString('$policy->storeProcessL1', $src);
    self::assertStringContainsString('maybeStoreProcessEntry', $src);
    self::assertStringContainsString('PRODUCT_POOL_MAX_BYTES', $src);
    self::assertStringContainsString('CARD_POOL_MAX_BYTES', $src);
    self::assertStringContainsString('estimateMixedBytes', $src);
    self::assertStringContainsString('oldestProductProcessKey', $src);
}

private function assert_StorefrontScopeHotCacheProcessL1_testEstimateSamplesNestedStringBytes(): void
{
    $ref = new \ReflectionClass(StorefrontScopeHotCache::class);
    $method = $ref->getMethod('estimateProcessEntryBytes');
    $method->setAccessible(true);
    $huge = ['a' => \str_repeat('x', 300000)];
    $bytes = (int) $method->invoke(null, ['payload' => $huge]);
    self::assertGreaterThan(262144, $bytes);
}
}

final class CachePoolBatchSpyAdapter implements BatchCacheAdapterInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $deleteCalls = 0;
    public int $getMultipleCalls = 0;
    public int $setMultipleCalls = 0;
    public int $deleteMultipleCalls = 0;
    public int $lastSetMultipleTtl = 0;
    /** @var array<string, mixed> */
    public array $lastSetMultipleValues = [];
    /** @var list<string> */
    public array $lastDeleteMultipleKeys = [];
    /** @var array<string, mixed> */
    private array $storage = [];
    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->storage[$key] = $value;
        return true;
    }
    public function delete(string $key): bool
    {
        $this->deleteCalls++;
        unset($this->storage[$key]);
        return true;
    }
    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->storage);
    }
    public function getMultiple(array $keys): array
    {
        $this->getMultipleCalls++;
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->storage[$key] ?? null;
        }
        return $out;
    }
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $this->setMultipleCalls++;
        $this->lastSetMultipleTtl = $ttl;
        $this->lastSetMultipleValues = $values;
        foreach ($values as $key => $value) {
            $this->storage[(string) $key] = $value;
        }
        return true;
    }
    public function deleteMultiple(array $keys): bool
    {
        $this->deleteMultipleCalls++;
        $this->lastDeleteMultipleKeys = \array_values(\array_map(static fn($key): string => (string) $key, $keys));
        foreach ($keys as $key) {
            unset($this->storage[(string) $key]);
        }
        return true;
    }
}

final class CachePoolSingleKeySpyAdapter implements CacheAdapterInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $deleteCalls = 0;
    /** @var array<string, mixed> */
    private array $storage = [];
    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->storage[$key] = $value;
        return true;
    }
    public function delete(string $key): bool
    {
        $this->deleteCalls++;
        unset($this->storage[$key]);
        return true;
    }
    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->storage);
    }
}
