<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Policy\ProcessMemoryEvictionPolicy;
use Weline\Framework\Cache\Store\ProcessMemoryStore;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\ModuleProcessCacheResetterRegistry;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessMemoryReclaimableAdapter;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsConcurrency;
use Weline\I18n\Api\Runtime\ProcessCacheResetter as I18nProcessCacheResetter;
use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Product acceptance for process-l1-pressure-eviction (UC-1 … UC-6).
 *
 * Spec: Framework/doc/开发/spec/process-l1-pressure-eviction.md
 */
final class ProcessL1PressureEvictionAcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId(null);
        Parser::clearWorkerCaches();
        ObjectManager::clearInstances();
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::resetThresholdCache();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        ObjectManager::clearInstances();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::resetThresholdCache();
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    /** UC-1: soft eviction shrinks unpinned entries; request path still readable. */
    public function testUc1SoftEvictionShrinksUnpinnedStore(): void
    {
        $store = new ProcessMemoryStore(64);
        foreach (['a', 'b', 'c', 'd'] as $i => $k) {
            $store->set($k, 'v' . $i, null, 40);
        }
        $store->pin('a');
        $before = $store->getItemCount();
        $evicted = $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_SOFT);
        self::assertNotSame([], $evicted);
        self::assertLessThan($before, $store->getItemCount());
        self::assertSame('v0', $store->get('a'));
    }

    /** UC-1 + Guard: soft pressure uses Phrase reclaimable, not full wipe. */
    public function testUc1GuardSoftReclaimsColdLocaleWithoutFullWipe(): void
    {
        $localeBag = [];
        DictionaryCacheNamespace::bindProcessBag('workerLocaleWords', $localeBag);
        foreach (['fr_FR', 'de_DE', 'es_ES'] as $locale) {
            $bag = &DictionaryCacheNamespace::localCache($localeBag, 1024, [$locale]);
            $bag['fp|' . $locale . '|k'] = $locale;
            DictionaryCacheNamespace::touchLocaleBucket($locale);
        }
        DictionaryCacheNamespace::touchLocaleBucket('de_DE');
        DictionaryCacheNamespace::touchLocaleBucket('es_ES');
        DictionaryCacheNamespace::pinLocaleBucket('es_ES');

        $this->writeStaticProperty(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', [
            'soft' => 0.70,
            'hard' => 0.85,
        ]);

        $result = $this->withMemoryPressure(0.78, static fn (): array => WorkerResponseMemoryGuard::compact());

        self::assertIsArray($result['runtime_cache_compactions'] ?? null);
        self::assertNotSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('es_ES'));
        self::assertArrayHasKey('fp|es_ES|k', $localeBag);
        // At least one cold locale should be gone after soft reclaim.
        $remaining = 0;
        foreach (['fr_FR', 'de_DE', 'es_ES'] as $locale) {
            if (DictionaryCacheNamespace::processMemoryStore()->keysInBucket($locale) !== []) {
                $remaining++;
            }
        }
        self::assertLessThan(3, $remaining);
        self::assertGreaterThanOrEqual(1, $remaining);
    }

    /** UC-2: hard clears unpinned; drain flag at hard pressure. */
    public function testUc2HardClearsUnpinnedAndDrainRequested(): void
    {
        $store = new ProcessMemoryStore(64);
        $store->set('keep', 'x', null, 10);
        $store->set('drop', 'y', null, 10);
        $store->pin('keep');
        $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_HARD);
        self::assertSame(1, $store->getItemCount());
        self::assertSame('x', $store->get('keep'));

        $this->writeStaticProperty(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', [
            'soft' => 0.70,
            'hard' => 0.85,
        ]);
        $result = $this->withMemoryPressure(0.90, static fn (): array => WorkerResponseMemoryGuard::compact());
        self::assertTrue($result['drain_requested']);
        self::assertTrue($result['cycle_collection_skipped']);
        self::assertSame(
            'memory_pressure_hard_before_gc',
            WorkerResponseMemoryGuard::consumeDrainAfterResponseReason()
        );
    }

    /** UC-2: Guard hard fully clears Phrase store. */
    public function testUc2GuardHardClearsPhraseStore(): void
    {
        $cache = [];
        $bag = &DictionaryCacheNamespace::localCache($cache, 1024, ['en_US']);
        $bag['fp|en_US|k'] = 'v';
        DictionaryCacheNamespace::touchLocaleBucket('en_US');
        self::assertGreaterThan(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());

        WorkerResponseMemoryGuard::compactRuntimeCaches(true);
        self::assertSame(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());
    }

    /** UC-3: locale bucket eviction without thrashing pinned hot locale. */
    public function testUc3LocaleBucketEvictionPrefersCold(): void
    {
        $localeBag = [];
        DictionaryCacheNamespace::bindProcessBag('workerLocaleWords', $localeBag);
        foreach (['aa_AA', 'bb_BB', 'cc_CC'] as $locale) {
            $bag = &DictionaryCacheNamespace::localCache($localeBag, 1024, [$locale]);
            $bag['fp|' . $locale . '|k'] = $locale;
            DictionaryCacheNamespace::touchLocaleBucket($locale);
        }
        DictionaryCacheNamespace::touchLocaleBucket('bb_BB');
        DictionaryCacheNamespace::touchLocaleBucket('cc_CC');

        DictionaryCacheNamespace::processMemoryReclaimable()->evict(1);
        self::assertSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('aa_AA'));
        self::assertNotSame([], DictionaryCacheNamespace::processMemoryStore()->keysInBucket('cc_CC'));
    }

    /** UC-4: explicit cache_clear via I18n Resetter empties Store; pressure reason alone does not. */
    public function testUc4ExplicitCacheClearEmptiesStoreUnlikePressureReason(): void
    {
        $cache = [];
        $bag = &DictionaryCacheNamespace::localCache($cache, 1024, ['zh_Hans_CN']);
        $bag['fp|zh_Hans_CN|k'] = 'v';
        DictionaryCacheNamespace::touchLocaleBucket('zh_Hans_CN');
        self::assertGreaterThan(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());

        $resetter = new I18nProcessCacheResetter();
        $pressureOnly = $resetter->resetProcessCaches(new ProcessCacheResetContext(
            ProcessCacheResetContext::REASON_MEMORY_PRESSURE,
            false
        ));
        self::assertSame(0, $pressureOnly);
        self::assertGreaterThan(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());

        $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext(
            ProcessCacheResetContext::REASON_CACHE_CLEAR,
            true
        ));
        self::assertGreaterThan(0, $cleared);
        self::assertSame(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());
    }

    /** UC-4: registry cache_clear path also empties Store. */
    public function testUc4RegistryCacheClearEmptiesStore(): void
    {
        $cache = [];
        $bag = &DictionaryCacheNamespace::localCache($cache, 1024, ['ru_RU']);
        $bag['fp|ru_RU|k'] = 'v';
        DictionaryCacheNamespace::touchLocaleBucket('ru_RU');

        $n = ObjectManager::getInstance(ModuleProcessCacheResetterRegistry::class)
            ->reset(new ProcessCacheResetContext(ProcessCacheResetContext::REASON_CACHE_CLEAR, true));
        self::assertGreaterThan(0, $n);
        self::assertSame(0, DictionaryCacheNamespace::processMemoryStore()->getItemCount());
    }

    /** UC-5: pin survives soft and hard store eviction. */
    public function testUc5PinSurvivesSoftAndHard(): void
    {
        $store = new ProcessMemoryStore(64);
        $store->set('hot', '1', 'ja_JP', 20);
        $store->set('cold', '2', 'ko_KR', 20);
        $store->pinBucket('ja_JP');
        $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_SOFT);
        $store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_HARD);
        self::assertSame('1', $store->get('hot'));
        self::assertNull($store->get('cold'));
    }

    /** UC-5 + EARS-9: peer Fiber blocks reclaim. */
    public function testUc5PeerFiberBlocksReclaim(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => 1);
        $store = new ProcessMemoryStore(64);
        $store->set('k', 'v', null, 10);
        $adapter = new ProcessMemoryReclaimableAdapter($store, 'acc_peer');
        $result = $adapter->compact();
        self::assertTrue($result['skipped']);
        self::assertSame(1, $store->getItemCount());
    }

    /** UC-6: OM soft does not clearMemory on PressureAware (shared L2 contract stays separate). */
    public function testUc6OmSoftDoesNotBlindClearMemoryStore(): void
    {
        $probe = new class implements MemoryStoreInterface, \Weline\Framework\Cache\Contract\MemoryPressureAwareInterface {
            /** @var list<string> */
            public array $items = ['a', 'b', 'c', 'd'];
            public bool $cleared = false;

            public function getMemoryUsage(): int
            {
                return \count($this->items) * 32;
            }

            public function getMemoryItemCount(): int
            {
                return \count($this->items);
            }

            public function getMaxItems(): int
            {
                return 16;
            }

            public function getMaxMemory(): int
            {
                return 1024;
            }

            public function evict(int $count): int
            {
                $n = 0;
                while ($n < $count && $this->items !== []) {
                    \array_shift($this->items);
                    $n++;
                }

                return $n;
            }

            public function clearMemory(): void
            {
                $this->items = [];
                $this->cleared = true;
            }

            public function warmUp(int $limit = 1000): int
            {
                return 0;
            }

            public function relievePressure(bool $aggressive): int
            {
                if ($aggressive) {
                    $n = \count($this->items);
                    $this->clearMemory();

                    return $n;
                }

                return $this->evict(\max(1, (int)\ceil(\count($this->items) / 2)));
            }
        };
        ObjectManager::setInstance('ProcessL1AccSoftProbe', $probe);
        $result = ObjectManager::relieveMemoryPressure(false);
        self::assertFalse($probe->cleared);
        self::assertSame(0, $result['memory_store_clears']);
        self::assertSame(2, $result['memory_store_evictions']);
        self::assertSame(2, $probe->getMemoryItemCount());
    }

    /** UC-6: ProcessMemoryStore is not MemoryStoreInterface (Adapter L1 stays separate). */
    public function testUc6ProcessStoreDoesNotImplementMemoryStoreInterface(): void
    {
        $store = DictionaryCacheNamespace::processMemoryStore();
        self::assertNotInstanceOf(MemoryStoreInterface::class, $store);
    }

    private function writeStaticProperty(string $class, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }

    private function withMemoryPressure(float $pressure, callable $callback): mixed
    {
        $previousLimit = \ini_get('memory_limit');
        \gc_collect_cycles();
        if (\function_exists('gc_mem_caches')) {
            \gc_mem_caches();
        }
        $usage = \memory_get_usage(true);
        $targetLimit = \max((int)\ceil($usage / $pressure), $usage + 1024 * 1024);
        if (@\ini_set('memory_limit', (string)$targetLimit) === false) {
            self::markTestSkipped('Unable to lower memory_limit for memory pressure test.');
        }
        try {
            return $callback();
        } finally {
            if ($previousLimit !== false) {
                @\ini_set('memory_limit', (string)$previousLimit);
            }
        }
    }
}
