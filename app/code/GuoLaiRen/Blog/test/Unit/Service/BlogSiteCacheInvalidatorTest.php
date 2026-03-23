<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Test\Unit\Service;

use GuoLaiRen\Blog\Service\BlogSiteCacheInvalidator;
use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;

final class BlogSiteCacheInvalidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::removeInstance(CacheManager::class);
        ObjectManager::removeInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class);

        parent::tearDown();
    }

    public function testInvalidateSiteIdsClearsApplicationCachesAndPurgesMatchingDomains(): void
    {
        $routerPool = new BlogFakeCachePool('router');
        $rewritePool = new BlogFakeCachePool('url_rewrite');
        $cacheManager = new class($routerPool, $rewritePool) extends CacheManager {
            public function __construct(
                private object $routerPool,
                private object $rewritePool
            ) {
            }

            public function pool(string $identity): CachePoolInterface
            {
                return match ($identity) {
                    'router' => $this->routerPool,
                    'url_rewrite' => $this->rewritePool,
                    default => throw new \InvalidArgumentException('Unexpected pool: ' . $identity),
                };
            }
        };

        $dispatchService = new class extends \Weline\Server\Service\Control\BroadcastControlDispatchService {
            public int $cacheCalls = 0;

            public function __construct()
            {
            }

            public function cacheClear(?string $instanceName = null, float $timeout = 3.0): array
            {
                $this->cacheCalls++;

                return ['success' => true];
            }
        };

        ObjectManager::setInstance(CacheManager::class, $cacheManager);
        ObjectManager::setInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class, $dispatchService);

        $domainModel = new FakeDomainCollection([
            new FakeDomainRecord(11, 3, 'site-3.example'),
            new FakeDomainRecord(22, 7, 'site-7.example'),
            new FakeDomainRecord(33, 7, 'site-7-disabled.example', 0),
        ]);

        $purged = [];
        $cachePurger = $this->createMock(CachePurger::class);
        $cachePurger->expects($this->exactly(2))
            ->method('purge')
            ->willReturnCallback(function (int|string $domain, string $mode) use (&$purged): array {
                $purged[] = [$domain, $mode];

                return ['success' => true];
            });

        $service = new BlogSiteCacheInvalidator($domainModel, $cachePurger);
        $result = $service->invalidateSiteIds([3, 7, 7, 0]);

        $this->assertTrue($result['application_cache_cleared']);
        $this->assertSame([3, 7], $result['site_ids']);
        $this->assertSame([[11, 'everything'], [22, 'everything']], $purged);
        $this->assertCount(2, $result['purged_domains']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $routerPool->clearCalls);
        $this->assertSame(1, $rewritePool->clearCalls);
        $this->assertSame(1, $dispatchService->cacheCalls);
    }

    public function testInvalidateSiteIdsContinuesWhenCdnPurgeFails(): void
    {
        $routerPool = new BlogFakeCachePool('router');
        $rewritePool = new BlogFakeCachePool('url_rewrite');
        $cacheManager = new class($routerPool, $rewritePool) extends CacheManager {
            public function __construct(
                private object $routerPool,
                private object $rewritePool
            ) {
            }

            public function pool(string $identity): CachePoolInterface
            {
                return match ($identity) {
                    'router' => $this->routerPool,
                    'url_rewrite' => $this->rewritePool,
                    default => throw new \InvalidArgumentException('Unexpected pool: ' . $identity),
                };
            }
        };

        $dispatchService = new class extends \Weline\Server\Service\Control\BroadcastControlDispatchService {
            public int $cacheCalls = 0;

            public function __construct()
            {
            }

            public function cacheClear(?string $instanceName = null, float $timeout = 3.0): array
            {
                $this->cacheCalls++;

                return ['success' => true];
            }
        };

        ObjectManager::setInstance(CacheManager::class, $cacheManager);
        ObjectManager::setInstance(\Weline\Server\Service\Control\BroadcastControlDispatchService::class, $dispatchService);

        $domainModel = new FakeDomainCollection([
            new FakeDomainRecord(44, 9, 'site-9.example'),
        ]);
        $cachePurger = $this->createMock(CachePurger::class);
        $cachePurger->expects($this->once())
            ->method('purge')
            ->with(44, 'everything')
            ->willThrowException(new \RuntimeException('purge failed'));

        $service = new BlogSiteCacheInvalidator($domainModel, $cachePurger);
        $result = $service->invalidateSiteIds([9]);

        $this->assertTrue($result['application_cache_cleared']);
        $this->assertSame([9], $result['site_ids']);
        $this->assertSame([], $result['purged_domains']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('purge failed', $result['errors'][0]['message']);
        $this->assertSame(1, $routerPool->clearCalls);
        $this->assertSame(1, $rewritePool->clearCalls);
        $this->assertSame(1, $dispatchService->cacheCalls);
    }
}

final class BlogFakeCachePool implements CachePoolInterface
{
    public int $clearCalls = 0;

    public function __construct(
        private readonly string $identity
    ) {
    }

    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        $this->clearCalls++;

        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return $this->identity;
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        return [];
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->identity,
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }
}

final class FakeDomainCollection extends Domain
{
    /**
     * @param list<FakeDomainRecord> $items
     */
    public function __construct(
        private array $records = []
    ) {
    }

    private int $siteId = 0;
    private int $enabled = 0;

    public function clear(bool $with_query = true): static
    {
        $this->siteId = 0;
        $this->enabled = 0;

        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        if ($field === Domain::schema_fields_SITE_ID) {
            $this->siteId = (int)$value;
        }
        if ($field === Domain::schema_fields_ENABLED) {
            $this->enabled = (int)$value;
        }

        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    public function fetch(): static
    {
        return $this;
    }

    /**
     * @return list<FakeDomainRecord>
     */
    public function getItems(): array
    {
        return array_values(array_filter(
            $this->records,
            fn (FakeDomainRecord $item): bool => ((int)$item->getData(Domain::schema_fields_SITE_ID) === $this->siteId)
                && ($this->enabled === 0 || (int)$item->getData(Domain::schema_fields_ENABLED) === $this->enabled)
        ));
    }
}

final class FakeDomainRecord extends Domain
{
    public function __construct(
        private readonly int $domainId,
        private readonly int $siteId,
        private readonly string $domainName,
        private readonly int $enabled = 1
    ) {
    }

    public function getData(string $key = '', $index = null): mixed
    {
        return match ($key) {
            Domain::schema_fields_DOMAIN_ID => $this->domainId,
            Domain::schema_fields_SITE_ID => $this->siteId,
            Domain::schema_fields_DOMAIN_NAME => $this->domainName,
            Domain::schema_fields_ENABLED => $this->enabled,
            default => null,
        };
    }
}
