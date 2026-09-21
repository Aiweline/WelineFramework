<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\RequestContext;

/**
 * Design: no RequestContext id ⇒ process-scoped L1 still works.
 * Only an active DB transaction forces ephemeral localCache.
 */
final class DictionaryCacheNamespaceProcessCacheContractTest extends TestCase
{
    private const TX_STATES_KEY = 'framework.database.transaction_states';

    protected function setUp(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId(null);
        RequestContext::set(self::TX_STATES_KEY, []);
    }

    protected function tearDown(): void
    {
        RequestContext::set(self::TX_STATES_KEY, []);
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testFingerprintIsAvailableWithoutRequestIdWhenNotInTransaction(): void
    {
        self::assertNull(RequestContext::getId());
        self::assertSame(0, TransactionContext::activeTransactionConnectionCount());
        self::assertNotNull(DictionaryCacheNamespace::fingerprint());
        self::assertStringStartsWith(
            DictionaryCacheNamespace::fingerprint() . '|',
            DictionaryCacheNamespace::cacheKey('demo'),
        );
    }

    public function testLocalCachePersistsWithoutRequestId(): void
    {
        self::assertNull(RequestContext::getId());
        self::assertSame(0, TransactionContext::activeTransactionConnectionCount());

        $cache = [];
        $first = &DictionaryCacheNamespace::localCache($cache, 1024);
        $first['word'] = 'cached';

        $second = &DictionaryCacheNamespace::localCache($cache, 1024);
        self::assertSame('cached', $second['word'] ?? null);
        self::assertSame('cached', $cache['word'] ?? null);
    }

    public function testLocalCacheIsEphemeralDuringActiveTransaction(): void
    {
        $query = $this->createStub(QueryInterface::class);
        RequestContext::set(self::TX_STATES_KEY, [
            'probe' => new TransactionState($query, 1, false),
        ]);
        self::assertGreaterThan(0, TransactionContext::activeTransactionConnectionCount());
        self::assertNull(DictionaryCacheNamespace::fingerprint());

        $cache = ['keep' => 1];
        $first = &DictionaryCacheNamespace::localCache($cache, 1024);
        $first['word'] = 'dirty';

        $second = &DictionaryCacheNamespace::localCache($cache, 1024);
        self::assertArrayNotHasKey('word', $second);
        self::assertSame(['keep' => 1], $cache);
    }

    public function testProcessMemoryStoreIsNotMemoryStoreInterface(): void
    {
        $store = DictionaryCacheNamespace::processMemoryStore();
        self::assertInstanceOf(\Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface::class, $store);
        self::assertNotInstanceOf(\Weline\Framework\Cache\Contract\MemoryStoreInterface::class, $store);
    }
}
