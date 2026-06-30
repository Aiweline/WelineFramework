<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Observer\DetectWebsite;

final class DetectWebsiteTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $objectManagerInstancesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        DetectWebsite::clearProcessCache();
        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
        RequestContext::init();
    }

    protected function tearDown(): void
    {
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);
        DetectWebsite::clearProcessCache();
        RequestContext::init();
        parent::tearDown();
    }

    public function testGetSitesUsesProcessCacheAcrossRequests(): void
    {
        $websiteRows = [[
            'website_id' => 1,
            'code' => 'default',
            'url' => 'https://example.com',
            'default_currency' => 'USD',
            'default_language' => 'en_US',
            'default_timezone' => 'UTC',
        ]];

        $domainRows = [[
            WebsiteDomain::schema_fields_WEBSITE_ID => 1,
            WebsiteDomain::schema_fields_DOMAIN => 'example.com',
            WebsiteDomain::schema_fields_SUB_PATH => '',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]];

        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub($websiteRows);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub($domainRows);
        $this->setObjectManagerInstances($instances);

        $cache = new DetectWebsiteCachePoolSpy();
        $firstObserver = new DetectWebsite();
        $this->setObserverCache($firstObserver, $cache);

        $firstEvent = new Event(['get_sites' => true]);
        $firstObserver->execute($firstEvent);
        /** @var array<int, array<string, mixed>> $firstSites */
        $firstSites = $firstEvent->getData('sites');

        RequestContext::init();

        $secondObserver = new DetectWebsite();
        $this->setObserverCache($secondObserver, $cache);

        $secondEvent = new Event(['get_sites' => true]);
        $secondObserver->execute($secondEvent);
        /** @var array<int, array<string, mixed>> $secondSites */
        $secondSites = $secondEvent->getData('sites');

        $this->assertSame(3, $cache->getCalls, 'Expected process cache to skip backing-cache reads on the second request.');
        $this->assertSame($firstSites, $secondSites);
        $this->assertCount(2, $secondSites);
    }

    public function testUrlMatchUsesProcessCacheAcrossObserverInstances(): void
    {
        $websiteRows = [[
            'website_id' => 2,
            'code' => 'shop',
            'url' => 'https://example.com/weshop',
            'default_currency' => 'USD',
            'default_language' => 'en_US',
            'default_timezone' => 'UTC',
        ]];

        $domainRows = [[
            WebsiteDomain::schema_fields_WEBSITE_ID => 2,
            WebsiteDomain::schema_fields_DOMAIN => 'example.com',
            WebsiteDomain::schema_fields_SUB_PATH => 'weshop',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]];

        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub($websiteRows);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub($domainRows);
        $this->setObjectManagerInstances($instances);

        $cache = new DetectWebsiteCachePoolSpy();

        $firstObserver = new DetectWebsite();
        $this->setObserverCache($firstObserver, $cache);
        $firstEvent = new Event(['data' => new DataObject(['url' => 'https://example.com/weshop'])]);
        $firstObserver->execute($firstEvent);

        RequestContext::init();

        $secondObserver = new DetectWebsite();
        $this->setObserverCache($secondObserver, $cache);
        $secondEvent = new Event(['data' => new DataObject(['url' => 'https://example.com/weshop'])]);
        $secondObserver->execute($secondEvent);

        $this->assertSame(2, $firstEvent->getData('website_id'));
        $this->assertSame('shop', $firstEvent->getData('code'));
        $this->assertSame(2, $secondEvent->getData('website_id'));
        $this->assertSame('https://example.com/weshop', $secondEvent->getData('website_url'));
        $this->assertSame(2, $cache->getCalls, 'Expected matched-site resolution to reuse process cache across observer lifecycles.');
    }

    public function testStandardProjectHostIsReservedForSystemHomepage(): void
    {
        $websiteRows = [[
            'website_id' => 268,
            'code' => 'pagebuilder-ai-card-game',
            'url' => 'http://p11005ce4.weline.test',
            'default_currency' => 'USD',
            'default_language' => 'en_US',
            'default_timezone' => 'UTC',
        ]];

        $domainRows = [[
            WebsiteDomain::schema_fields_WEBSITE_ID => 268,
            WebsiteDomain::schema_fields_DOMAIN => 'p11005ce4.weline.test',
            WebsiteDomain::schema_fields_SUB_PATH => '',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]];

        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub($websiteRows);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub($domainRows);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        $sitesEvent = new Event(['get_sites' => true]);
        $observer->execute($sitesEvent);

        $siteUrls = \array_column($sitesEvent->getData('sites') ?: [], 'url');
        $this->assertNotContains('http://p11005ce4.weline.test', $siteUrls);
        $this->assertNotContains('https://p11005ce4.weline.test', $siteUrls);

        RequestContext::init();
        $matchEvent = new Event(['data' => new DataObject(['url' => 'https://p11005ce4.weline.test/'])]);
        $observer->execute($matchEvent);

        $this->assertNull($matchEvent->getData('website_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function getObjectManagerInstances(): array
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);

        /** @var array<string, mixed> $instances */
        $instances = $property->getValue();
        return $instances;
    }

    /**
     * @param array<string, mixed> $instances
     */
    private function setObjectManagerInstances(array $instances): void
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);
        $property->setValue(null, $instances);
    }

    private function setObserverCache(DetectWebsite $observer, CachePoolInterface $cache): void
    {
        $reflection = new ReflectionClass($observer);
        $property = $reflection->getProperty('cache');
        $property->setAccessible(true);
        $property->setValue($observer, $cache);
    }
}

final class DetectWebsiteCachePoolSpy implements CachePoolInterface
{
    public int $getCalls = 0;

    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    public function getIdentity(): string
    {
        return 'detect_website_test';
    }

    public function getTip(): string
    {
        return 'DetectWebsite unit test spy';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
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
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->getIdentity(),
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }
}

final class DetectWebsiteRowsStub extends Website
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        return $this;
    }

    public function getItems(): array
    {
        return $this->rows;
    }

    public function reset(): static
    {
        $this->data = [];
        return $this;
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            $this->data = $key;
            return $this;
        }

        $this->data[$key] = $value;
        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }

    public function getUrl(): string
    {
        return (string)($this->data['url'] ?? '');
    }

    public function getWebsiteId(): int
    {
        return (int)($this->data['website_id'] ?? 0);
    }

    public function getCode(): string
    {
        return (string)($this->data['code'] ?? '');
    }

    public function getDefaultCurrency(): string
    {
        return (string)($this->data['default_currency'] ?? '');
    }

    public function getDefaultLanguage(): string
    {
        return (string)($this->data['default_language'] ?? '');
    }

    public function getDefaultTimezone(): string
    {
        return (string)($this->data['default_timezone'] ?? 'UTC');
    }
}

final class DetectWebsiteDomainRowsStub extends WebsiteDomain
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        return $this;
    }

    public function select(string $fields = ''): static
    {
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        return $this;
    }

    public function getItems(): array
    {
        return $this->rows;
    }
}
