<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\{CacheManager,CachePolicy};
use Weline\Framework\Cache\Contract\{CacheAdapterInterface,NamespaceGenerationInterface};
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Runtime\{RequestContext,ScopeIdentity};
use Weline\SystemConfig\Api\Scope\{ScopeContext,ScopeHierarchyInterface};
use Weline\Theme\Api\Scoped\{ThemeResolvedValue,ThemeScopedWorkspaceInterface};
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

final class ThemeContextReuseTest extends TestCase
{
    private string $generation = 'v1';
    private int $resolutions = 0;
    private array $entries = [];

    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        if (Context::hasCurrent()) { RequestContext::cleanup(); Context::leave(); }
    }

    public function testPublicBindingReusesAcrossRequestsAndPeerCacheButModelsAreRequestCopies(): void
    {
        $service = $this->service();
        $this->request('one');
        $first = $service->resolveTheme('frontend', null, false);
        $first->setData('name', 'caller mutation');
        for ($i = 0; $i < 24; $i++) {
            self::assertSame('published', $service->resolveTheme('frontend', null, false)->getData('name'));
        }
        self::assertSame(1, ThemeContextReuseModel::$loads);
        self::assertSame(1, $this->resolutions);
        $this->request('two');
        self::assertSame(7, $service->resolveTheme('frontend', null, false)->getId());
        self::assertSame(2, ThemeContextReuseModel::$loads);
        self::assertSame(1, $this->resolutions);
        StorefrontScopeHotCache::resetProcessCache(); // Empty peer L1, retain the shared adapter.
        $this->request('peer');
        self::assertSame(7, $service->resolveTheme('frontend', null, false)->getId());
        self::assertSame(3, ThemeContextReuseModel::$loads);
        self::assertSame(1, $this->resolutions);
        $this->generation = 'v2';
        $this->request('after-publish');
        self::assertSame(7, $service->resolveTheme('frontend', null, false)->getId());
        self::assertSame(2, $this->resolutions);
    }

    public function testExplicitScopesAndAreasCannotBorrowTheCurrentRequestBinding(): void
    {
        $service = $this->service();
        $this->request('scope');
        $service->resolveTheme('frontend', null, false);
        $service->resolveTheme('backend', null, false);
        $service->resolveThemeForScope('frontend', ScopeIdentity::website(2, 'other'));
        self::assertSame(3, $this->resolutions);
    }

    public function testMissingReleaseDoesNotFreezeLegacyActiveThemeInSharedCache(): void
    {
        $service = $this->service(false);
        $this->request('legacy-one');
        self::assertSame(9, $service->resolveTheme('frontend', null, false)->getId());
        ThemeContextReuseModel::$activeId = 10;
        $this->request('legacy-two');
        self::assertSame(10, $service->resolveTheme('frontend', null, false)->getId());
        self::assertSame(1, $this->resolutions);
    }

    public function testTransactionReadsNeverPublishOrReuseThemeSnapshots(): void
    {
        $service = $this->service();
        $this->request('transaction');
        $query = $this->createMock(\Weline\Framework\Database\Connection\Api\Sql\QueryInterface::class);
        RequestContext::set('framework.database.transaction_states', [
            'unit' => new \Weline\Framework\Database\Transaction\TransactionState($query, 1, true),
        ]);
        $service->resolveTheme('frontend', null, false);
        $service->resolveTheme('frontend', null, false);
        self::assertSame(2, $this->resolutions);
        self::assertSame(2, ThemeContextReuseModel::$loads);
        self::assertSame([], $this->entries);
        $this->request('committed');
        $service->resolveTheme('frontend', null, false);
        self::assertSame(3, $this->resolutions);
    }

    public function testExplicitThemeRetainsCallerIdentityAndBypassesPublicResolution(): void
    {
        $service = $this->service();
        $this->request('explicit');
        $theme = (new ThemeContextReuseModel())->setData(['id'=>42]);
        self::assertSame($theme, $service->resolveTheme('frontend', $theme));
        self::assertSame(0, $this->resolutions);
        self::assertSame([], $this->entries);
    }

    private function request(string $id): void
    {
        if (Context::hasCurrent()) { RequestContext::cleanup(); Context::leave(); }
        Context::enter(new Context(['meta'=>['type'=>'request','mode'=>'wls']]));
        RequestContext::setId($id);
        RequestContext::installScopeIdentity(ScopeIdentity::website(1, 'site'));
    }

    private function service(bool $published = true): ThemeContextService
    {
        ThemeContextReuseModel::$loads = 0;
        ThemeContextReuseModel::$activeId = 9;
        $adapter = $this->createMock(CacheAdapterInterface::class);
        $adapter->method('get')->willReturnCallback(fn(string $key): mixed => $this->entries[$key] ?? null);
        $adapter->method('set')->willReturnCallback(function(string $key, mixed $value): bool { $this->entries[$key] = $value; return true; });
        $pool = new CachePool('theme_context_unit', $adapter, jitterRatio: 0.0);
        $manager = $this->createMock(CacheManager::class);
        $manager->method('pool')->willReturn($pool);
        $manager->method('registerPolicy')->willReturnCallback(static fn(CachePolicy $policy): CachePolicy => $policy);
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturnCallback(fn(): string => $this->generation);
        $hotCache = new StorefrontScopeHotCache($manager, $generations);
        $scopes = $this->createMock(ScopeHierarchyInterface::class);
        $scopes->method('contextFromIdentity')->willReturnCallback(static fn(ScopeIdentity $identity): ScopeContext => new ScopeContext($identity, 'site.default.default', 'normal', ['site.default.default']));
        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->method('resolvePublishedTheme')->willReturnCallback(function() use ($published): ThemeResolvedValue {
            $this->resolutions++;
            return new ThemeResolvedValue($published ? 7 : ThemeContextReuseModel::$activeId, null, false, $published ? 'site.default.default' : 'theme-package-default', $published ? 101 : null, false, false);
        });
        return new class(new ThemeContextReuseModel(), null, $workspace, $scopes, null, $hotCache) extends ThemeContextService {
            public function themeSupportsArea(WelineTheme $theme, string $area): bool { return true; }
        };
    }
}

final class ThemeContextReuseModel extends WelineTheme
{
    public static int $loads = 0;
    public static int $activeId = 9;
    public function clearData(bool $with_query = true): static { $this->setData([]); return $this; }
    public function clearQuery(): static { return $this; }
    public function load(string|int $field_or_pk_value, $value = null, bool $forceReload = false): \Weline\Framework\Database\AbstractModel
    {
        self::$loads++;
        return $this->setData(['id'=>(int)$field_or_pk_value, 'name'=>'published']);
    }
    public function getActiveTheme(?string $area = null): static { return $this->setData(['id'=>self::$activeId, 'name'=>'legacy']); }
}
