<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontNavigationScope;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Observer\DetectWebsite;
use Weline\Websites\Service\Exception\ScopeResolutionException;
use Weline\Websites\Service\ScopeResolver;

/** TEST-P1A-03 and TEST-P1A-04: canonical scope resolution and localization URL ordering. */
final class DetectWebsiteTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $objectManagerInstancesBackup = [];
    private DetectWebsiteScopeResolverSpy $scopeResolver;

    protected function setUp(): void
    {
        parent::setUp();
        DetectWebsite::clearProcessCache();
        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
        $this->scopeResolver = new DetectWebsiteScopeResolverSpy();
        RequestContext::resetWelineVars();
        RequestContext::init();
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);
    }

    protected function tearDown(): void
    {
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup, false);
        DetectWebsite::clearProcessCache();
        RequestContext::resetWelineVars();
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
            'code' => 'ai-card-game',
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

    public function testWebsiteUrlRequiresACompletePathSegmentBoundary(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([[
            'website_id' => 8,
            'code' => 'shop',
            'url' => 'https://example.com/shop',
            'default_currency' => 'USD',
            'default_language' => 'en_US',
            'default_timezone' => 'UTC',
        ]]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        self::assertNull($observer->resolveWebsiteContext('https://example.com/shopper'));
        self::assertSame(8, $observer->resolveWebsiteContext('https://example.com/shop')?->websiteId);
        self::assertSame(8, $observer->resolveWebsiteContext('https://example.com/shop/order')?->websiteId);
    }

    public function testWebsiteUrlUsesLongestCompletePathWithinTheSameHostRank(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([
            [
                'website_id' => 8,
                'code' => 'root',
                'url' => 'https://example.com/',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
            [
                'website_id' => 9,
                'code' => 'shop',
                'url' => 'https://example.com/shop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
        ]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        self::assertSame(9, $observer->resolveWebsiteContext('https://example.com/shop/order')?->websiteId);
        self::assertSame(8, $observer->resolveWebsiteContext('https://example.com/shopper')?->websiteId);
    }

    public function testExactHostOutranksSingleWwwAliasBeforePathSpecificity(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([
            [
                'website_id' => 8,
                'code' => 'exact',
                'url' => 'https://www.example.com/',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
            [
                'website_id' => 9,
                'code' => 'alias-shop',
                'url' => 'https://example.com/shop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
        ]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        self::assertSame(8, $observer->resolveWebsiteContext('https://www.example.com/shop/order')?->websiteId);
    }

    public function testWebsiteDomainRequiresACompletePathSegmentBoundary(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([[
            'website_id' => 9,
            'code' => 'domain-shop',
            'url' => 'https://canonical.example.test',
            'default_currency' => 'USD',
            'default_language' => 'en_US',
            'default_timezone' => 'UTC',
        ]]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([[
            WebsiteDomain::schema_fields_WEBSITE_ID => 9,
            WebsiteDomain::schema_fields_DOMAIN => 'example.com',
            WebsiteDomain::schema_fields_SUB_PATH => 'shop',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        self::assertNull($observer->resolveWebsiteContext('https://example.com/shopper'));
        self::assertSame(9, $observer->resolveWebsiteContext('https://example.com/shop/order')?->websiteId);
    }

    public function testEqualPriorityWebsiteRoutesFailClosed(): void
    {
        $websiteRows = [];
        foreach ([[10, 'shop-a'], [11, 'shop-b']] as [$websiteId, $code]) {
            $websiteRows[] = [
                'website_id' => $websiteId,
                'code' => $code,
                'url' => 'https://example.com/shop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ];
        }

        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub($websiteRows);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        try {
            $observer->resolveWebsiteContext('https://example.com/shop/order');
            self::fail('Equal-priority Website routes must be rejected.');
        } catch (ScopeResolutionException $exception) {
            self::assertSame('website_route_ambiguous', $exception->reason);
            self::assertSame(409, $exception->httpStatus);
        }
    }

    public function testCanonicallyEquivalentWebsitePathsFailClosed(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([
            [
                'website_id' => 12,
                'code' => 'plain-shop',
                'url' => 'https://example.com/shop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
            [
                'website_id' => 13,
                'code' => 'encoded-shop',
                'url' => 'https://example.com/%73hop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
        ]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        $this->assertAmbiguousWebsiteRoute(
            static fn() => $observer->resolveWebsiteContext('https://example.com/shop/item'),
        );
    }

    public function testEqualPriorityDomainAndWebsiteUrlConflictFailClosed(): void
    {
        $instances = $this->objectManagerInstancesBackup;
        $instances[Website::class] = new DetectWebsiteRowsStub([
            [
                'website_id' => 14,
                'code' => 'url-shop',
                'url' => 'https://example.com/shop',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
            [
                'website_id' => 15,
                'code' => 'domain-shop',
                'url' => 'https://canonical.example.test',
                'default_currency' => 'USD',
                'default_language' => 'en_US',
                'default_timezone' => 'UTC',
            ],
        ]);
        $instances[WebsiteDomain::class] = new DetectWebsiteDomainRowsStub([[
            WebsiteDomain::schema_fields_WEBSITE_ID => 15,
            WebsiteDomain::schema_fields_DOMAIN => 'example.com',
            WebsiteDomain::schema_fields_SUB_PATH => 'shop',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]]);
        $this->setObjectManagerInstances($instances);

        $observer = new DetectWebsite();
        $this->setObserverCache($observer, new DetectWebsiteCachePoolSpy());

        $this->assertAmbiguousWebsiteRoute(
            static fn() => $observer->resolveWebsiteContext('https://example.com/shop/item'),
        );
    }

    public function testProcessSiteInstallsScopeOnceAndPublishesMatchingMetadata(): void
    {
        $site = new DetectWebsiteRowsStub([]);
        $site->setData([
            Website::schema_fields_ID => 0,
            Website::schema_fields_CODE => 'default',
            Website::schema_fields_NAME => '系统默认站点',
            Website::schema_fields_URL => 'https://shop.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]);
        $observer = new DetectWebsite();
        $event = new Event(['data' => new DataObject([
            'url' => 'https://shop.example.test/catalog?__store=default&__channel=default',
        ])]);

        $observer->processSite($event, $site);
        $firstMeta = $event->getData('scope_meta');
        $observer->processSite($event, $site);

        self::assertSame(1, $this->scopeResolver->calls);
        self::assertSame($firstMeta, $event->getData('scope_meta'));
        self::assertSame(RequestContext::scopeMetadata(), $event->getData('scope_meta'));
        self::assertSame(0, $event->getData('scope_meta')['website_id'] ?? null);
        self::assertSame('default.default.default', \Weline\Framework\Runtime\ScopeContext::getScope());
    }

    public function testProcessSiteStripsEitherLocalizationOrderBeforeStoreResolution(): void
    {
        $site = new DetectWebsiteRowsStub([]);
        $site->setData([
            Website::schema_fields_ID => 17,
            Website::schema_fields_CODE => 'localized-shop',
            Website::schema_fields_NAME => 'Localized shop',
            Website::schema_fields_URL => 'https://shop.example.test/site',
            Website::schema_fields_DEFAULT_CURRENCY => 'CNY',
            Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN',
            Website::schema_fields_DEFAULT_TIMEZONE => 'Asia/Shanghai',
        ]);
        foreach (['en_US/USD', 'USD/en_US'] as $localizationOrder) {
            RequestContext::resetWelineVars();
            RequestContext::init();
            RequestContext::setWelineUserCurrency('USD');
            RequestContext::setWelineUserLang('en_US');
            $event = new Event(['data' => new DataObject([
                'url' => 'https://shop.example.test/site/' . $localizationOrder . '/store/item?__store=default',
            ])]);

            (new DetectWebsite())->processSite($event, $site);

            self::assertSame(
                'https://shop.example.test/site/store/item?__store=default',
                $this->scopeResolver->lastTrustedRequestUrl,
            );
            self::assertSame('USD', $event->getData('scope_meta')['currency'] ?? null);
            self::assertSame('en_US', $event->getData('scope_meta')['locale'] ?? null);
            self::assertSame('/store/item', $event->getData('scope_route_path'));
        }
    }

    public function testProcessSiteRejectsMissingWebsiteIdInsteadOfCoercingItToZero(): void
    {
        $site = new DetectWebsiteRowsStub([]);
        $site->setData([
            Website::schema_fields_CODE => 'default',
            Website::schema_fields_NAME => '损坏站点',
            Website::schema_fields_URL => 'https://shop.example.test',
            Website::schema_fields_DEFAULT_CURRENCY => 'USD',
            Website::schema_fields_DEFAULT_LANGUAGE => 'en_US',
            Website::schema_fields_DEFAULT_TIMEZONE => 'UTC',
        ]);
        $event = new Event(['data' => new DataObject(['url' => 'https://shop.example.test/'])]);

        try {
            (new DetectWebsite())->processSite($event, $site);
            self::fail('Missing website_id must terminate the request.');
        } catch (NoRouterException $exception) {
            self::assertSame(503, $exception->getCode());
            self::assertNull(RequestContext::scopeIdentity());
            self::assertSame(0, $this->scopeResolver->calls);
        }
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
    private function setObjectManagerInstances(array $instances, bool $withScopeResolver = true): void
    {
        if ($withScopeResolver) {
            $instances[ScopeResolver::class] = $this->scopeResolver;
        }
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

    private function assertAmbiguousWebsiteRoute(callable $operation): void
    {
        try {
            $operation();
            self::fail('Equal-priority Website routes must be rejected.');
        } catch (ScopeResolutionException $exception) {
            self::assertSame('website_route_ambiguous', $exception->reason);
            self::assertSame(409, $exception->httpStatus);
        }
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

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($key);
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

    public function hasData(string $key = ''): bool
    {
        return $key === '' ? $this->data !== [] : \array_key_exists($key, $this->data);
    }

    public function getName(): string
    {
        return (string)($this->data[Website::schema_fields_NAME] ?? '');
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

final class DetectWebsiteScopeResolverSpy extends ScopeResolver
{
    public int $calls = 0;
    public string $lastTrustedRequestUrl = '';

    public function __construct()
    {
    }

    public function resolve(
        int $websiteId,
        string $websiteCode,
        string $trustedRequestUrl,
        array $params = [],
        ?string $defaultRoutePath = null,
    ): StorefrontNavigationScope {
        $this->calls++;
        $this->lastTrustedRequestUrl = $trustedRequestUrl;
        RequestContext::setWelineStoreId(11);
        RequestContext::setWelineStoreCode('default');
        RequestContext::setWelineStoreMode('normal');
        RequestContext::setWelineChannelId(21);
        RequestContext::setWelineChannelCode('default');
        $identity = ScopeIdentity::channel(
            $websiteId,
            $websiteCode,
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        RequestContext::installScopeIdentity($identity);
        RequestContext::setStorefrontRoutePath($defaultRoutePath ?? '/');

        return new StorefrontNavigationScope($identity, $defaultRoutePath ?? '/');
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
