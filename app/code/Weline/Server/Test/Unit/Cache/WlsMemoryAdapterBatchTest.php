<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\SharedCacheBatchStateInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

final class WlsMemoryAdapterBatchTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::init();
        WlsMemoryAdapter::clearAllMemory();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        WlsMemoryAdapter::clearAllMemory();
    }

    public function testColdPeerReadsSharedBatchOnceAndKeepsFalseValuesAndMisses(): void
    {
        $storage = [];
        $shared = $this->createMock(SharedCacheBatchStateInterface::class);
        $shared->expects(self::never())->method('getCache');
        $shared->expects(self::never())->method('setCache');
        $shared->expects(self::once())->method('setCacheMultiple')->willReturnCallback(
            static function (string $pool, array $values, int $ttl) use (&$storage): bool {
                self::assertSame('batch', $pool);
                self::assertSame(300, $ttl);
                $storage = $values;
                return true;
            },
        );
        $shared->expects(self::once())->method('getCacheMultiple')->willReturnCallback(
            static function (string $pool, array $keys) use (&$storage): array {
                self::assertSame('batch', $pool);
                self::assertCount(5, $keys);
                return array_intersect_key($storage, array_fill_keys($keys, true));
            },
        );
        $config = ['local_cache_memory_pressure_threshold' => 0.99];
        $writer = new CachePool('batch', new WlsMemoryAdapter('batch', $config, $shared), defaultTtl: 300);
        $reader = new CachePool('batch', new WlsMemoryAdapter('batch', $config, $shared));
        $values = ['title' => 'translated', 'false' => false, 'zero' => 0, 'empty' => []];

        self::assertTrue($writer->setMultiple($values));
        self::assertSame($values + ['missing' => null], $reader->getMultiple([...array_keys($values), 'missing']));
        self::assertSame($values, $reader->getMultiple(array_keys($values)));
        self::assertSame(8, $reader->getStats()['hits']);
        self::assertSame(1, $reader->getStats()['misses']);
    }

    public function testBatchApiRetainsSingleKeyProviderCompatibility(): void
    {
        $shared = $this->createMock(SharedCacheStateInterface::class);
        $shared->expects(self::exactly(2))->method('getCache')->willReturnOnConsecutiveCalls('a', null);
        $shared->expects(self::exactly(2))->method('setCache')->willReturn(true);
        $pool = new CachePool('legacy', new WlsMemoryAdapter('legacy', ['local_cache_size' => 0], $shared));
        self::assertTrue($pool->setMultiple(['first' => 'a', 'second' => 'b']));
        self::assertSame(['first' => 'a', 'missing' => null], $pool->getMultiple(['first', 'missing']));
    }

    public function testSlowBatchPreservesFalseAndNullWithoutBlockingOtherPools(): void
    {
        $shared = $this->createMock(SharedCacheBatchStateInterface::class);
        $values = ['false' => false, 'missing' => null, 'empty' => []];
        $shared->expects(self::once())->method('getCacheMultiple')->willReturnCallback(static function () use ($values): array {
            usleep(35_000);
            return $values;
        });
        $shared->expects(self::once())->method('setCacheMultiple')->willReturnCallback(static function (): bool {
            usleep(35_000);
            return false;
        });
        $shared->expects(self::once())->method('getCache')->willReturn('peer-present');
        $config = ['remote_slow_threshold_ms' => 25, 'local_cache_size' => 0];
        $first = new WlsMemoryAdapter('slow_batch', $config, $shared);
        $peer = new WlsMemoryAdapter('batch_peer', $config, $shared);

        self::assertSame($values, $first->getMultiple(array_keys($values)));
        self::assertTrue($first->isAvailable());
        self::assertFalse($peer->setMultiple(['value' => false]));
        self::assertTrue($peer->isAvailable());
        self::assertSame('peer-present', $peer->get('value'));
    }

    public function testBatchTransportExceptionStillBlocksOtherPoolsInTheRequest(): void
    {
        $shared = $this->createMock(SharedCacheBatchStateInterface::class);
        $shared->expects(self::once())->method('getCacheMultiple')->willThrowException(new \RuntimeException('transport failed'));
        $shared->expects(self::never())->method('getCache');
        $shared->expects(self::never())->method('setCacheMultiple');
        $first = new WlsMemoryAdapter('failed_batch', ['local_cache_size' => 0], $shared);
        $peer = new WlsMemoryAdapter('failed_peer', ['local_cache_size' => 0], $shared);

        self::assertSame(['missing' => null], $first->getMultiple(['missing']));
        self::assertFalse($first->isAvailable());
        self::assertNull($peer->get('present'));
        self::assertFalse($peer->setMultiple(['value' => false]));
    }

    public function testDisabledAndEmptyBatchesDoNotAccessSharedStorage(): void
    {
        $shared = $this->createMock(SharedCacheBatchStateInterface::class);
        foreach (['getCache', 'setCache', 'getCacheMultiple', 'setCacheMultiple'] as $method) {
            $shared->expects(self::never())->method($method);
        }
        $adapter = new WlsMemoryAdapter('disabled', [], $shared);
        $pool = new CachePool('disabled', $adapter, enabled: false);
        self::assertSame(['a' => null], $pool->getMultiple(['a']));
        self::assertTrue($pool->setMultiple(['a' => 1]));
        self::assertSame(1, $pool->getStats()['misses']);
        $enabled = new CachePool('empty', $adapter);
        self::assertSame([], $enabled->getMultiple([]));
        self::assertTrue($enabled->setMultiple([]));
    }
}
