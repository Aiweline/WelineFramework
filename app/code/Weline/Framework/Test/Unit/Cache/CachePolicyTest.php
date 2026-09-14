<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class CachePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        parent::tearDown();
    }

    public function testRequestMemoApiExists(): void
    {
        self::assertTrue(method_exists(StorefrontScopeHotCache::class, 'rememberForRequest'));
    }

    public function testRequestMemoCachesNullAndSeparatesResourcesAndContexts(): void
    {
        $cache = new StorefrontScopeHotCache();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('memo-request-one');
        $calls = 0;
        $builder = static function () use (&$calls): mixed { $calls++; return null; };
        self::assertNull($cache->rememberForRequest('eav.metadata', 'entity-1', $builder));
        self::assertNull($cache->rememberForRequest('eav.metadata', 'entity-1', $builder));
        self::assertSame(1, $calls);
        self::assertSame('other', $cache->rememberForRequest('eav.options', 'entity-1', static fn(): string => 'other'));
        RequestContext::cleanup();
        Context::leave();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('memo-request-two');
        self::assertNull($cache->rememberForRequest('eav.metadata', 'entity-1', $builder));
        self::assertSame(2, $calls);
    }

    public function testRequestMemoWithoutContextComputesEveryTime(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        $cache = new StorefrontScopeHotCache();
        $calls = 0;
        $builder = static function () use (&$calls): int { return ++$calls; };
        self::assertSame(1, $cache->rememberForRequest('eav.metadata', 'entity-1', $builder));
        self::assertSame(2, $cache->rememberForRequest('eav.metadata', 'entity-1', $builder));
        self::assertFalse(Context::hasCurrent());
    }

    public function testDeclarativePolicyApiExists(): void
    {
        self::assertTrue(class_exists(CachePolicy::class), 'CachePolicy must declare resource, scope and cache dependencies centrally.');
    }

    public function testWebsiteResourceSharesAcrossChildrenAndIgnoresUnrelatedFrozenVersions(): void
    {
        $policy = new CachePolicy('category.tree', 'product', 'website', [], ['catalog'], 60, 300);
        $a = $this->context('site_a', 'store_a', 'web', 'en_US', 'USD', 'a');
        $b = $this->context('site_a', 'store_b', 'app', 'zh_Hans_CN', 'CNY', 'b');
        $c = $this->context('site_b', 'store_a', 'web');
        self::assertSame(KeyBuilder::policyKey($policy, 'root', 'catalog-v1', $a), KeyBuilder::policyKey($policy, 'root', 'catalog-v1', $b));
        self::assertNotSame(KeyBuilder::policyKey($policy, 'root', 'catalog-v1', $a), KeyBuilder::policyKey($policy, 'root', 'catalog-v1', $c));
        self::assertNotSame(KeyBuilder::policyKey($policy, 'root', 'catalog-v1', $a), KeyBuilder::policyKey($policy, 'root', 'catalog-v2', $a));
        self::assertSame(['global/storefront/catalog', 'website/site_a/catalog'], $policy->namespacePaths($a->scopeIdentity));
    }

    public function testGlobalLocaleResourceSharesAcrossTenantsAndCurrency(): void
    {
        $policy = new CachePolicy('ui.words', 'phrase', 'global', ['lang'], [], 60, 300);
        $a = $this->context('site_a', 'retail', 'web', 'en_US', 'USD');
        $b = $this->context('site_b', 'wholesale', 'app', 'en_US', 'CNY');
        $c = $this->context('site_b', 'wholesale', 'app', 'zh_Hans_CN', 'CNY');
        self::assertSame(KeyBuilder::policyKey($policy, 'module-v1', '', $a), KeyBuilder::policyKey($policy, 'module-v1', '', $b));
        self::assertNotSame(KeyBuilder::policyKey($policy, 'module-v1', '', $a), KeyBuilder::policyKey($policy, 'module-v1', '', $c));
        self::assertArrayNotHasKey('website', KeyBuilder::policyDimensions($policy, $a));
    }

    public function testScopedPresentationDependsOnTheCanonicalGlobalDictionaryVersion(): void
    {
        $context = $this->context('site_a', 'retail', 'web');
        $policy = new CachePolicy('search.types', 'view', 'channel', ['lang'], ['catalog', 'global/i18n']);
        $namespaces = $policy->namespacePaths($context->scopeIdentity);
        self::assertContains('global/i18n/content', $namespaces);
        self::assertNotContains('global/storefront/global/i18n', $namespaces);
        self::assertNotContains('website/site_a/global/i18n/store/retail/normal/channel/web', $namespaces);
        self::assertCount(3, $namespaces);

        $generations = new PolicyGenerations();
        $before = $generations->fingerprint($namespaces);
        $generations->domainVersions['global/i18n'] = 2;
        self::assertNotSame($before, $generations->fingerprint($namespaces));
        $catalog = new CachePolicy('category.raw', 'product', 'website', [], ['catalog']);
        self::assertSame($before, $generations->fingerprint($catalog->namespacePaths($context->scopeIdentity)));
    }

    public function testStoreResourceSharesChannelsButKeepsModeAndParentIdentity(): void
    {
        $policy = new CachePolicy('store.menu', 'product', 'store', [], ['catalog'], 60, 300);
        $a = $this->context('site_a', 'retail', 'web');
        $b = $this->context('site_a', 'retail', 'app');
        $c = $this->context('site_a', 'wholesale', 'web');
        self::assertSame(KeyBuilder::policyKey($policy, 'root', 'v1', $a), KeyBuilder::policyKey($policy, 'root', 'v1', $b));
        self::assertNotSame(KeyBuilder::policyKey($policy, 'root', 'v1', $a), KeyBuilder::policyKey($policy, 'root', 'v1', $c));
        self::assertSame(['global/storefront/catalog', 'website/site_a/catalog/store/retail/normal'], $policy->namespacePaths($a->scopeIdentity));
    }
    public function testTranslatedResourceDependsOnItsLanguagesAcrossStorefrontScopes(): void
    {
        $policy = new CachePolicy('product.translated_labels', 'product', 'channel', ['lang'], ['global/i18n']);
        $first = $this->context('site_a', 'retail', 'web');
        $second = $this->context('site_b', 'wholesale', 'app');
        $languages = ['fr-FR', 'en_US', 'zh_Hans_CN', 'fr_FR'];
        $expected = ['global/i18n/en_US', 'global/i18n/fr_FR', 'global/i18n/zh_Hans_CN'];

        self::assertSame($expected, $policy->namespacePaths($first->scopeIdentity, $languages));
        self::assertSame($expected, $policy->namespacePaths($second->scopeIdentity, $languages));
        self::assertNotSame(
            $policy->namespacePaths($first->scopeIdentity, ['fr_FR', 'en_US', 'de_DE']),
            $policy->namespacePaths($first->scopeIdentity, $languages),
            'A different fallback language must change the invalidation dependency set.',
        );
    }

    public function testUnspecifiedTranslationLanguagesRetainGlobalInvalidation(): void
    {
        $policy = new CachePolicy('product.translated_labels', 'product', 'channel', ['lang'], ['global/i18n']);
        $context = $this->context('site_a', 'retail', 'web');
        self::assertSame(['global/i18n/content'], $policy->namespacePaths($context->scopeIdentity));
    }

    public function testGlobalTranslatedResourceKeyIncludesOrderedFallbackChain(): void
    {
        $policy = new CachePolicy('global.translated_labels', 'i18n', 'global', ['lang'], ['global/i18n']);
        $first = new StorefrontCacheKeyContext(null, 'fr_FR', 'EUR', null, str_repeat('a', 64), false, '', 'zh_Hans_CN', ['fr_FR', 'en_US', 'zh_Hans_CN']);
        $same = new StorefrontCacheKeyContext(null, 'fr_FR', 'EUR', null, str_repeat('b', 64), false, '', 'zh_Hans_CN', ['fr_FR', 'en_US', 'zh_Hans_CN']);
        $otherDefault = new StorefrontCacheKeyContext(null, 'fr_FR', 'EUR', null, str_repeat('c', 64), false, '', 'de_DE', ['fr_FR', 'en_US', 'de_DE']);
        $otherOrder = new StorefrontCacheKeyContext(null, 'fr_FR', 'EUR', null, str_repeat('d', 64), false, '', 'zh_Hans_CN', ['fr_FR', 'zh_Hans_CN', 'en_US']);

        $key = KeyBuilder::policyKey($policy, 'labels', 'same-authority', $first);
        self::assertSame($key, KeyBuilder::policyKey($policy, 'labels', 'same-authority', $same));
        self::assertNotSame($key, KeyBuilder::policyKey($policy, 'labels', 'same-authority', $otherDefault));
        self::assertNotSame($key, KeyBuilder::policyKey($policy, 'labels', 'same-authority', $otherOrder));

        $structural = new CachePolicy('global.structural_data', 'i18n', 'global', [], ['catalog']);
        self::assertSame(
            KeyBuilder::policyKey($structural, 'data', 'same-authority', $first),
            KeyBuilder::policyKey($structural, 'data', 'same-authority', $otherDefault),
        );
    }



    public function testUnresolvedResourceStaysBehindRequestFenceWithoutInventingDefaultTenant(): void
    {
        $policy = new CachePolicy('category.tree', 'product', 'website', [], ['catalog'], 60, 300);
        $a = new StorefrontCacheKeyContext(null, 'en_US', 'USD', null, str_repeat('a', 64), false);
        $b = new StorefrontCacheKeyContext(null, 'en_US', 'USD', null, str_repeat('b', 64), false);
        $dimensions = KeyBuilder::policyDimensions($policy, $a);
        self::assertSame('request-fence', $dimensions['scope_state']);
        self::assertArrayNotHasKey('website', $dimensions);
        self::assertNotSame(KeyBuilder::policyKey($policy, 'root', '', $a), KeyBuilder::policyKey($policy, 'root', '', $b));
    }

    public function testWebsitePolicySharesAfterWebsiteResolutionBeforeChannelFreeze(): void
    {
        $policy = new CachePolicy('websites.store_catalog', 'website', 'website', [], ['catalog'], 60, 300);
        $a = new StorefrontCacheKeyContext(
            ScopeIdentity::website(7, 'site_a'),
            'en_US',
            'USD',
            null,
            str_repeat('a', 64),
            false,
        );
        $b = new StorefrontCacheKeyContext(
            ScopeIdentity::website(7, 'site_a'),
            'en_US',
            'USD',
            null,
            str_repeat('b', 64),
            false,
        );

        $dimensions = KeyBuilder::policyDimensions($policy, $a);
        self::assertSame('website', $dimensions['scope_state']);
        self::assertSame('site_a', $dimensions['website']);
        self::assertArrayNotHasKey('request_fence', $dimensions);
        self::assertSame(
            KeyBuilder::policyKey($policy, 'all', 'catalog-v1', $a),
            KeyBuilder::policyKey($policy, 'all', 'catalog-v1', $b),
        );
    }

    public function testPolicyTtlIsPartOfCacheIdentityAndRegistryIsInspectable(): void
    {
        $short = new CachePolicy('category.tree', 'product', 'website', [], ['catalog'], 30, 60);
        $long = new CachePolicy('category.tree', 'product', 'website', [], ['catalog'], 60, 300);
        $context = $this->context('site_a', 'retail', 'web');
        self::assertNotSame(KeyBuilder::policyKey($short, 'root', 'v1', $context), KeyBuilder::policyKey($long, 'root', 'v1', $context));
        $manager = (new \ReflectionClass(CacheManager::class))->newInstanceWithoutConstructor();
        $manager->registerPolicy($short);
        self::assertSame($short, $manager->getPolicy('category.tree'));
        self::assertSame(['category.tree' => $short], $manager->getPolicies());
    }

    public function testHotCacheColdMissRechecksSharedEntryAfterAcquiringLock(): void
    {
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter());
        $manager = new PolicyCacheManager($pool);
        $flight = new PolicySingleFlight();
        $flight->onAcquire = static function () use ($pool): void {
            $pool->set('cold', ['payload' => 'peer-result', 'fresh_until' => microtime(true) + 60, 'stale_until' => microtime(true) + 300, 'version' => 1], 360);
        };
        $cache = new StorefrontScopeHotCache($manager, null, $flight);
        $calls = 0;
        $actual = $cache->remember('policy_test', 'cold', 60, static function () use (&$calls): string {
            $calls++;
            return 'duplicate-build';
        }, []);
        self::assertSame('peer-result', $actual);
        self::assertSame(0, $calls);
        self::assertSame(1, $flight->acquired);
        self::assertSame(1, $flight->released);
    }

    public function testPolicyHotCacheUsesOnlyDeclaredGenerationAndCanForget(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('cache-policy-test');
        StorefrontCacheKeyContext::install($this->context('site_a', 'retail', 'web'));
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter());
        $manager = new PolicyCacheManager($pool);
        $generations = new PolicyGenerations();
        $cache = new StorefrontScopeHotCache($manager, $generations, new PolicySingleFlight());
        $policy = new CachePolicy('category.tree', 'policy_test', 'website', [], ['catalog'], 60, 300);
        $calls = 0;
        $builder = static function () use (&$calls): int { return ++$calls; };
        self::assertSame(1, $cache->rememberPolicy($policy, 'root', $builder));
        self::assertSame(1, $cache->rememberPolicy('category.tree', 'root', $builder));
        self::assertSame(['global/storefront/catalog', 'website/site_a/catalog'], $generations->lastNamespaces);
        $generations->version = 'v2';
        self::assertSame(2, $cache->rememberPolicy($policy, 'root', $builder));
        $cache->forgetPolicy($policy, 'root');
        self::assertSame(3, $cache->rememberPolicy($policy, 'root', $builder));
    }

    public function testGlobalResourceSharesBeforeAndAfterStorefrontFreeze(): void
    {
        $policy = new CachePolicy('ui.words', 'phrase', 'global', ['lang'], [], 60, 300);
        $a = new StorefrontCacheKeyContext(null, 'en_US', 'USD', null, str_repeat('a', 64), false);
        $b = new StorefrontCacheKeyContext(null, 'en_US', 'CNY', null, str_repeat('b', 64), false);
        $frozen = $this->context('site_a', 'retail', 'web');
        self::assertSame(KeyBuilder::policyKey($policy, 'module-v1', '', $a), KeyBuilder::policyKey($policy, 'module-v1', '', $b));
        self::assertSame(KeyBuilder::policyKey($policy, 'module-v1', '', $a), KeyBuilder::policyKey($policy, 'module-v1', '', $frozen));
    }

    public function testGlobalConfigChangeInvalidatesOnlyPoliciesDependingOnConfig(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('global-config-policy-inheritance');
        StorefrontCacheKeyContext::install($this->context('site_a', 'retail', 'web'));
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter());
        $generations = new PolicyGenerations();
        $cache = new StorefrontScopeHotCache(new PolicyCacheManager($pool), $generations, new PolicySingleFlight());
        $menu = new CachePolicy('store.menu', 'policy_test', 'store', ['lang'], ['catalog', 'config'], 60, 300);
        $category = new CachePolicy('category.tree', 'policy_test', 'website', [], ['catalog'], 60, 300);
        $menuCalls = 0;
        $categoryCalls = 0;
        $menuBuilder = static function () use (&$menuCalls): int { return ++$menuCalls; };
        $categoryBuilder = static function () use (&$categoryCalls): int { return ++$categoryCalls; };
        self::assertSame(1, $cache->rememberPolicy($menu, 'root', $menuBuilder));
        self::assertSame(1, $cache->rememberPolicy($category, 'root', $categoryBuilder));
        $generations->domainVersions['global/storefront/config'] = 2;
        self::assertSame(2, $cache->rememberPolicy($menu, 'root', $menuBuilder));
        self::assertSame(1, $cache->rememberPolicy($category, 'root', $categoryBuilder));
    }

    public function testGlobalDependencyInvalidatesEvenBeforeStorefrontScopeIsFrozen(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('global-policy-before-scope');
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter());
        $generations = new PolicyGenerations();
        $cache = new StorefrontScopeHotCache(new PolicyCacheManager($pool), $generations, new PolicySingleFlight());
        $policy = new CachePolicy('ui.words', 'policy_test', 'global', ['lang'], ['ui'], 60, 300);
        $calls = 0;
        $builder = static function () use (&$calls): int { return ++$calls; };
        self::assertSame(1, $cache->rememberPolicy($policy, 'module-v1', $builder));
        $generations->version = 'v2';
        self::assertSame(2, $cache->rememberPolicy($policy, 'module-v1', $builder));
        self::assertSame(['global/storefront/ui'], $generations->lastNamespaces);
    }

    public function testPolicyStaleRefreshUsesFrozenKeyAndSharedSingleFlight(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setId('cache-policy-refresh');
        $context = $this->context('site_a', 'retail', 'web');
        StorefrontCacheKeyContext::install($context);
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter(), environmentScoped: true);
        $flight = new PolicySingleFlight();
        $generations = new PolicyGenerations();
        $cache = new StorefrontScopeHotCache(new PolicyCacheManager($pool), $generations, $flight);
        $policy = new CachePolicy('category.tree', 'policy_test', 'website', [], ['catalog'], 60, 300);
        $oldKey = KeyBuilder::policyKey($policy, 'root', 'v1', $context);
        $pool->setCustom($oldKey, ['payload' => 'stale', 'fresh_until' => microtime(true) - 1, 'stale_until' => microtime(true) + 300, 'version' => 1], 300);
        self::assertSame('stale', $cache->rememberPolicy($policy, 'root', static fn(): string => 'refreshed'));
        $generations->version = 'v2';
        PostResponseTaskQueue::drain(100.0, 1000);
        self::assertSame('refreshed', $pool->getCustom($oldKey)['payload']);
        self::assertNull($pool->getCustom(KeyBuilder::policyKey($policy, 'root', 'v2', $context)));
        self::assertSame(1, $flight->acquired);
        self::assertSame(1, $flight->released);
    }

    public function testProcessCacheHasBoundedLeastRecentlyUsedEntries(): void
    {
        $pool = new CachePool('policy_test', new PolicyMemoryAdapter());
        $cache = new StorefrontScopeHotCache(new PolicyCacheManager($pool), null, new PolicySingleFlight(), 2);
        foreach (['a', 'b', 'a', 'c'] as $key) {
            $cache->remember('policy_test', $key, 60, static fn(): string => $key, []);
        }
        $entries = (new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache'))->getValue();
        self::assertCount(2, $entries);
        self::assertArrayHasKey('policy_test|a', $entries);
        self::assertArrayHasKey('policy_test|c', $entries);
    }

    private function context(string $website, string $store, string $channel, string $lang = 'en_US', string $currency = 'USD', string $version = 'a'): StorefrontCacheKeyContext
    {
        return new StorefrontCacheKeyContext(ScopeIdentity::channel(7, $website, $store, $channel, 'normal'), $lang, $currency, str_repeat($version, 64), str_repeat($version, 64), true);
    }
}


final class PolicyCacheManager extends CacheManager
{
    public function __construct(private CachePoolInterface $testPool) {}
    public function pool(string $identity): CachePoolInterface { return $this->testPool; }
}

final class PolicyMemoryAdapter implements CacheAdapterInterface
{
    private array $values = [];
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl = 0): bool { $this->values[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->values[$key]); return true; }
    public function clear(): bool { $this->values = []; return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
}

final class PolicySingleFlight implements SingleFlightInterface
{
    public int $acquired = 0;
    public int $released = 0;
    public mixed $onAcquire = null;
    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        $this->acquired++;
        if ($this->onAcquire !== null) { ($this->onAcquire)(); }
        return 'token';
    }
    public function release(string $key, string $token): void { $this->released++; }
}

final class PolicyGenerations implements NamespaceGenerationInterface
{
    public string $version = 'v1';
    public array $lastNamespaces = [];
    public array $domainVersions = [];
    public function fingerprint(array $namespaces): string
    {
        $this->lastNamespaces = $namespaces;
        $expanded = (new \Weline\Framework\Cache\Namespace\NamespacePath())->expandAncestors($namespaces);
        $changes = array_intersect_key($this->domainVersions, array_fill_keys($expanded, true));
        ksort($changes, SORT_STRING);
        return $this->version . ($changes === [] ? '' : serialize($changes));
    }
    public function bumpMany(array $namespaces): array { return ['authority_clock' => 1, 'changes' => []]; }
    public function bump(string $namespace): array { return $this->bumpMany([$namespace]); }
}
