<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;

final class StorefrontCacheKeyContextTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }


    public function testTranslationFallbackSnapshotOnlyFollowsRelevantLocaleGenerations(): void
    {
        $originalDefault = \Weline\Framework\App\Env::get('website.language', '');
        $authority = new TestNamespaceGenerationAuthority();
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        try {
            \Weline\Framework\App\Env::set('website.language', 'fr_FR');
            $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
            RequestContext::setWelineUserLang('de_DE');
            $first = $resolver->freezeCurrent();
            self::assertSame('fr_FR', $first->defaultLocale);
            self::assertSame(['de_DE', 'en_US', 'fr_FR'], $first->translationLocales);

            $authority->bump('global/i18n/ja_JP');
            $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
            RequestContext::setWelineUserLang('de_DE');
            $unrelated = $resolver->freezeCurrent();
            self::assertSame($first->namespaceFingerprint, $unrelated->namespaceFingerprint);
            $authority->bump('global/i18n/fr_FR');
            self::assertSame($unrelated, $resolver->freezeCurrent(), 'The active request keeps its vector.');
            $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
            RequestContext::setWelineUserLang('de_DE');
            self::assertNotSame($first->namespaceFingerprint, $resolver->freezeCurrent()->namespaceFingerprint);

            \Weline\Framework\App\Env::set('website.language', 'it_IT');
            $changedDefault = $resolver->freezeCurrent();
            self::assertSame(['de_DE', 'en_US', 'it_IT'], $changedDefault->translationLocales);
            self::assertSame('it_IT', $changedDefault->keyDimensions()['default_locale']);
            RequestContext::setWelineUserLang('zh_Hans_CN');
            self::assertSame(['zh_Hans_CN'], $resolver->freezeCurrent()->translationLocales);
        } finally {
            \Weline\Framework\App\Env::set('website.language', $originalDefault);
        }
    }

    public function testScopeAndVersionAreFrozenOnceAndSharedByAllKeyBuilders(): void
    {
        $authority = new TestNamespaceGenerationAuthority();
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_DEV);

        $context = $resolver->freezeCurrent();
        $ordinary = KeyBuilder::applyDimensionFlags('menu', true, true, true, true);
        $environment = KeyBuilder::environmentHash(['surface' => 'header']);
        $router = KeyBuilder::buildUnifiedRequestCacheKey('https://example.test/catalog', 'GET');

        self::assertTrue($context->cacheable);
        self::assertSame(1, $authority->fingerprintCalls);
        self::assertStringContainsString('website=shop_a', $ordinary);
        self::assertStringContainsString('store=retail', $ordinary);
        self::assertStringContainsString('channel=web', $ordinary);
        self::assertStringContainsString('store_mode=dev', $ordinary);
        self::assertStringContainsString('cache_version=' . $context->cacheKeyFingerprint, $ordinary);
        self::assertNotSame('', $environment);
        self::assertNotSame('', $router);
        self::assertSame(1, $authority->fingerprintCalls, 'KeyBuilder must only read the frozen context.');

        $authority->bump('website/shop_a/catalog');
        self::assertSame($context, $resolver->freezeCurrent());
        self::assertSame($ordinary, KeyBuilder::applyDimensionFlags('menu', true, true, true, true));
        self::assertSame(1, $authority->fingerprintCalls, 'Current request must keep its original vector.');

        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_DEV);
        $next = $resolver->freezeCurrent();
        self::assertNotSame($context->cacheKeyFingerprint, $next->cacheKeyFingerprint);
        self::assertSame(2, $authority->fingerprintCalls);
    }

    public function testEnvironmentLocaleAndCurrencyRemainFrozenAfterLiveStatePollution(): void
    {
        $resolver = new StorefrontCacheKeyContextResolver(
            new TestNamespaceGenerationAuthority(),
            new NamespacePath(),
        );
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
        $resolver->freezeCurrent();
        $flags = [
            'area' => false,
            'area_route' => false,
            'website' => true,
            'website_url' => false,
            'host' => false,
            'base_url' => false,
            'lang' => true,
            'lang_local' => true,
            'currency' => true,
        ];
        $before = KeyBuilder::environmentContext([], $flags);

        RequestContext::setWelineUserLang('fr_FR');
        RequestContext::setWelineUserCurrency('EUR');
        $after = KeyBuilder::environmentContext([], $flags);

        self::assertSame($before, $after);
        self::assertSame('en_US', $after['lang']);
        self::assertSame('en_US', $after['lang_local']);
        self::assertSame('USD', $after['currency']);
    }

    public function testGlobalTranslationChangeInvalidatesTheNextRequestFpcKey(): void
    {
        $authority = new TestNamespaceGenerationAuthority();
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
        $resolver->freezeCurrent();
        $before = KeyBuilder::buildUnifiedRequestCacheKey('https://example.test/catalog', 'GET');
        $authority->bump('global/i18n');
        self::assertSame($before, KeyBuilder::buildUnifiedRequestCacheKey('https://example.test/catalog', 'GET'));

        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
        $resolver->freezeCurrent();
        self::assertNotSame($before, KeyBuilder::buildUnifiedRequestCacheKey('https://example.test/catalog', 'GET'));
    }

    public function testEveryRequiredVersionDimensionChangesTheNextRequestKey(): void
    {
        foreach (['config', 'catalog', 'price', 'theme'] as $dimension) {
            $authority = new TestNamespaceGenerationAuthority();
            $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
            $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
            $resolver->freezeCurrent();
            $before = KeyBuilder::applyDimensionFlags('page', true, true, true, true);

            $authority->bump('website/shop_a/' . $dimension);
            $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
            $resolver->freezeCurrent();
            $after = KeyBuilder::applyDimensionFlags('page', true, true, true, true);

            self::assertNotSame($before, $after, $dimension);
            RequestContext::cleanup();
            Context::leave();
        }
    }

    public function testSameUrlDiffersAcrossStoreChannelModeAndContextVersion(): void
    {
        $authority = new TestNamespaceGenerationAuthority();
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $keys = [];
        foreach (
            [
                ['retail', 'web', ScopeIdentity::MODE_NORMAL, 'v1'],
                ['outlet', 'web', ScopeIdentity::MODE_NORMAL, 'v1'],
                ['retail', 'app', ScopeIdentity::MODE_NORMAL, 'v1'],
                ['retail', 'web', ScopeIdentity::MODE_TEST, 'v1'],
                ['retail', 'web', ScopeIdentity::MODE_NORMAL, 'v2'],
            ] as [$store, $channel, $mode, $contextVersion]
        ) {
            $this->enterScope('shop_a', $store, $channel, $mode, $contextVersion);
            $resolver->freezeCurrent();
            $keys[] = KeyBuilder::buildUnifiedRequestCacheKey('https://example.test/catalog', 'GET');
            RequestContext::cleanup();
            Context::leave();
        }

        self::assertCount(count($keys), array_unique($keys));
    }

    public function testProvisionalFencePreventsRecursiveNamespaceLookup(): void
    {
        $authority = new TestNamespaceGenerationAuthority();
        $authority->duringFingerprint = static function (): void {
            $key = KeyBuilder::applyDimensionFlags('namespace-read', true, false, false, false);
            self::assertStringContainsString('scope_state=request-fence', $key);
        };
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);

        $context = $resolver->freezeCurrent();

        self::assertTrue($context->cacheable);
        self::assertSame(1, $authority->fingerprintCalls);
        self::assertStringContainsString(
            'scope_state=frozen',
            KeyBuilder::applyDimensionFlags('namespace-read', true, false, false, false),
        );
    }

    public function testCurrentFenceRetainsResolvedWebsiteBeforeStorefrontFreeze(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('website-before-scope');
        RequestContext::setWelineWebsiteId(7);
        RequestContext::setWelineWebsiteCode('site_a');

        $context = StorefrontCacheKeyContext::currentOrRequestFence();

        self::assertFalse($context->cacheable);
        self::assertTrue($context->hasWebsiteScope());
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $context->scopeIdentity?->scopeKind);
        self::assertSame(7, $context->scopeIdentity?->websiteId);
        self::assertSame('site_a', $context->scopeIdentity?->websiteCode);
    }

    public function testFingerprintFailureKeepsFenceMarkerWithoutRequestUniqueCacheVersion(): void
    {
        $authority = new TestNamespaceGenerationAuthority();
        $authority->failFingerprint = true;
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
        $first = $resolver->freezeCurrent();
        $firstKey = KeyBuilder::applyDimensionFlags('page', true, false, false, false);

        self::assertFalse($first->cacheable);
        self::assertSame('storefront_namespace_unavailable', $first->failureCode);
        self::assertStringContainsString('scope_state=request-fence', $firstKey);
        self::assertStringNotContainsString('cache_version=', $firstKey);
        self::assertStringNotContainsString($first->cacheKeyFingerprint, $firstKey);
        self::assertStringNotContainsString('website=default', $firstKey);

        $this->enterScope('shop_a', 'retail', 'web', ScopeIdentity::MODE_NORMAL);
        $second = $resolver->freezeCurrent();
        $secondKey = KeyBuilder::applyDimensionFlags('page', true, false, false, false);
        self::assertNotSame($first->cacheKeyFingerprint, $second->cacheKeyFingerprint);
        // Publishable dimension keys stay stable; request fingerprints stay out of keys.
        self::assertSame($firstKey, $secondKey);
    }

    public function testCustomFullEscapeDoesNotReadOrRequireStorefrontContext(): void
    {
        self::assertSame('global-registry', KeyBuilder::applyDimensionFlags('global-registry'));
        self::assertNull(StorefrontCacheKeyContext::current());
    }

    public function testPersistentNoContextFenceIsNeverSharedAcrossCalls(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }

        Runtime::setMode(Runtime::WLS);
        $first = StorefrontCacheKeyContext::currentOrRequestFence();
        $second = StorefrontCacheKeyContext::currentOrRequestFence();

        self::assertFalse($first->cacheable);
        self::assertFalse($second->cacheable);
        self::assertNotSame($first->cacheKeyFingerprint, $second->cacheKeyFingerprint);
        self::assertNull(StorefrontCacheKeyContext::current());
    }

    public function testCliNoContextFenceIsStableOnlyWithinCurrentBootstrapScope(): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }

        Runtime::setMode(Runtime::CLI);
        $first = StorefrontCacheKeyContext::currentOrRequestFence();
        $second = StorefrontCacheKeyContext::currentOrRequestFence();

        self::assertFalse($first->cacheable);
        self::assertFalse($second->cacheable);
        self::assertSame($first->cacheKeyFingerprint, $second->cacheKeyFingerprint);
        self::assertNull(StorefrontCacheKeyContext::current());
    }

    private function enterScope(
        string $website,
        string $store,
        string $channel,
        string $mode,
        string $contextVersion = ScopeIdentity::CONTEXT_VERSION,
    ): void {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('cache-context-' . bin2hex(random_bytes(4)));
        Context::current()->set('input.server.WELINE_AREA', 'frontend');
        Context::current()->set('input.server.WELINE_FULL_REQUEST_URI', 'https://example.test/catalog');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            $website === 'default' ? 0 : 7,
            $website,
            $store,
            $channel,
            $mode,
            $contextVersion,
        ));
        RequestContext::setWelineUserLang('en_US');
        RequestContext::setWelineUserCurrency('USD');
    }
}

final class TestNamespaceGenerationAuthority implements NamespaceGenerationInterface
{
    /** @var array<string,int> */
    private array $generations = [];
    public int $fingerprintCalls = 0;
    public bool $failFingerprint = false;
    public ?\Closure $duringFingerprint = null;

    public function fingerprint(array $namespaces): string
    {
        $this->fingerprintCalls++;
        ($this->duringFingerprint) && ($this->duringFingerprint)();
        if ($this->failFingerprint) {
            throw new \RuntimeException('fingerprint unavailable');
        }
        $vector = [];
        foreach ($namespaces as $namespace) {
            $vector[$namespace] = $this->generations[$namespace] ?? 0;
        }
        ksort($vector, SORT_STRING);
        return hash('sha256', json_encode($vector, JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function bumpMany(array $namespaces): array
    {
        $changes = [];
        foreach (array_values(array_unique($namespaces)) as $namespace) {
            $changes[$namespace] = $this->generations[$namespace] = ($this->generations[$namespace] ?? 0) + 1;
        }
        return ['authority_clock' => array_sum($this->generations), 'changes' => $changes];
    }

    public function bump(string $namespace): array
    {
        return $this->bumpMany([$namespace]);
    }
}
