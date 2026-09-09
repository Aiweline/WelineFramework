<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestLifecycleTrace;

final class StorefrontScopeHotCacheTraceMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context([
            'meta' => ['type' => 'request', 'mode' => 'fpm'],
            'input' => ['uri' => '/products', 'server' => ['HTTP_X_WELINE_TRACE' => '1']],
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestLifecycleTrace::reset();
        StorefrontScopeHotCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        RequestLifecycleTrace::reset();
        Context::leave();
    }

    public function testDifferentMissesRetainTheirExistingKeysAndFingerprintWithoutExtraIo(): void
    {
        [$service, $adapter] = $this->service(expectedGenerationReads: 3);
        $first = new CachePolicy('unit.labels', 'unit_trace', 'global', dependencies: ['global/i18n'], freshTtlSeconds: 60, staleTtlSeconds: 120);
        $second = new CachePolicy('unit.navigation', 'unit_trace', 'global', dependencies: ['global/i18n'], freshTtlSeconds: 60, staleTtlSeconds: 120);
        self::assertSame('labels', $service->rememberPolicy($first, 'private-key-a', static fn(): string => 'labels'));
        self::assertSame('navigation', $service->rememberPolicy($second, 'private-key-b', static fn(): string => 'navigation'));
        self::assertSame('labels', $service->rememberPolicy($first, 'private-key-a', static fn(): never => throw new \LogicException('L1 should hit')));

        $builders = $this->spans('storefront.cache.builder');
        self::assertCount(2, $builders);
        self::assertSame(['unit.labels', 'unit.navigation'], array_column(array_column($builders, 'meta'), 'resource'));
        self::assertSame(hash('sha256', 'private-key-a'), $builders[0]['meta']['logical_key_hash']);
        self::assertNotSame($builders[0]['meta']['scoped_key_hash'], $builders[1]['meta']['scoped_key_hash']);
        self::assertSame('generation-test-7', $builders[0]['meta']['dependency_fingerprint']);
        self::assertSame(['global/i18n'], $builders[0]['meta']['namespace_paths']);
        self::assertSame(CachePool::class, $builders[0]['meta']['pool_class']);
        self::assertSame(TraceCountingAdapter::class, $builders[0]['meta']['adapter_class']);
        self::assertSame('absent', $builders[0]['meta']['l1_status']);
        self::assertSame('absent', $builders[0]['meta']['l2_status']);
        self::assertSame('absent', $builders[0]['meta']['l2_recheck_status']);
        self::assertSame([180, 180], $adapter->writeTtls);
        self::assertSame(4, $adapter->reads);
        self::assertStringNotContainsString('private-key', json_encode($builders, JSON_THROW_ON_ERROR));
        self::assertSame(2, RequestLifecycleTrace::getAggregateSummary()['phases']['storefront.cache.builder']['calls']);
        self::assertSame('unit.navigation', RequestLifecycleTrace::getAggregateSummary()['phases']['storefront.cache.builder']['meta']['resource']);
    }

    public function testExpiredEntriesExposeTheirExistingDeadlinesAndStillRebuildOnce(): void
    {
        [$service, $adapter] = $this->service(expectedGenerationReads: 2);
        $policy = new CachePolicy('unit.expired', 'unit_trace', 'global', dependencies: ['global/i18n'], freshTtlSeconds: 10, staleTtlSeconds: 20);
        self::assertSame('old', $service->rememberPolicy($policy, 'expired', static fn(): string => 'old'));
        $past = microtime(true) - 100;
        $entries = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache');
        $process = $entries->getValue();
        foreach ($process as &$entry) {
            $entry['fresh_until'] = $past;
            $entry['stale_until'] = $past + 10;
        }
        unset($entry);
        $entries->setValue(null, $process);
        foreach ($adapter->data as &$entry) {
            $entry['fresh_until'] = $past;
            $entry['stale_until'] = $past + 10;
        }
        unset($entry);
        RequestLifecycleTrace::reset();
        self::assertSame('new', $service->rememberPolicy($policy, 'expired', static fn(): string => 'new'));
        $builders = $this->spans('storefront.cache.builder');
        self::assertCount(1, $builders);
        foreach (['l1', 'l2', 'l2_recheck'] as $layer) {
            self::assertSame('expired', $builders[0]['meta'][$layer . '_status']);
            self::assertSame($past, $builders[0]['meta'][$layer . '_fresh_until']);
            self::assertSame($past + 10, $builders[0]['meta'][$layer . '_stale_until']);
        }
        self::assertSame([30, 30], $adapter->writeTtls);
        self::assertSame(4, $adapter->reads);
        $write = $this->spans('storefront.cache.shared_write')[0]['meta'];
        self::assertEqualsWithDelta(20, $write['write_stale_until'] - $write['write_fresh_until'], 0.001);
    }

    private function service(int $expectedGenerationReads): array
    {
        $adapter = new TraceCountingAdapter();
        $pool = new CachePool('unit_trace', $adapter, jitterRatio: 0.0);
        $manager = $this->createMock(CacheManager::class);
        $manager->method('pool')->willReturn($pool);
        $manager->method('registerPolicy')->willReturnArgument(0);
        $generation = $this->createMock(NamespaceGenerationInterface::class);
        $generation->expects(self::exactly($expectedGenerationReads))->method('fingerprint')
            ->with(['global/i18n'])->willReturn('generation-test-7');
        $flight = $this->createMock(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn('test-token');
        return [new StorefrontScopeHotCache($manager, $generation, $flight), $adapter];
    }

    private function spans(string $name): array
    {
        return array_values(array_filter(RequestLifecycleTrace::getSpans(), static fn(array $span): bool => $span['name'] === $name));
    }
}

final class TraceCountingAdapter implements CacheAdapterInterface
{
    public array $data = [];
    public array $writeTtls = [];
    public int $reads = 0;
    public function get(string $key): mixed { $this->reads++; return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl = 0): bool { $this->writeTtls[] = $ttl; $this->data[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
}
