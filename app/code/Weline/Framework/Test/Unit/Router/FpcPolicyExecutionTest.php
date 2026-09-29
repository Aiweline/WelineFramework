<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;

final class FpcPolicyExecutionTest extends TestCase
{
    private FullPageCacheCoordinator $coordinator;
    private array $stored = [];
    private array $ttls = [];
    private array $services = [];

    protected function setUp(): void
    {
        if (Context::hasCurrent()) { Context::leave(); }
        Runtime::setMode(Runtime::WLS);
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('fpc-policy-test');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', 'normal'));
        RequestContext::setWelineUserLang('en_US');
        RequestContext::setWelineUserCurrency('USD');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(RequestContext::scopeIdentity(), 'en_US', 'USD', str_repeat('b', 64), str_repeat('b', 64), true));
        WelineEnv::set('full_request_uri', 'https://example.test/', 'test');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', 'https://example.test/', 'test');
        WelineEnv::set('request.uri', '/', 'test');
        WelineEnv::set('request.method', 'GET', 'test');
        Context::current()->set('input.server.HTTP_HOST', 'example.test');
        Context::current()->set('input.host', 'example.test');
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->method('set')->willReturnCallback(function ($key, $value, $ttl = null): bool { $this->stored[$key] = $value; $this->ttls[$key] = $ttl; return true; });
        $pool->method('get')->willReturnCallback(fn($key) => $this->stored[$key] ?? null);
        $authority = new class implements NamespaceGenerationInterface {
            public function fingerprint(array $namespaces): string { return str_repeat('b', 64); }
            public function bump(string $namespace): array { return []; }
            public function bumpMany(array $namespaces): array { return []; }
        };
        $this->coordinator = new FullPageCacheCoordinator(cachePool: $pool,
            storefrontCacheKeyContextResolver: new StorefrontCacheKeyContextResolver($authority, new NamespacePath()));
        foreach ([\Weline\Framework\Event\EventsManager::class, \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class] as $class) {
            $this->services[$class] = ObjectManager::_getInstance($class);
            ObjectManager::setInstance($class, $this->createStub($class));
        }
        FullPageCacheCoordinator::clearProcessCache();
        $this->snapshot(true, 30);
    }

    protected function tearDown(): void
    {
        foreach ($this->services as $class => $service) {
            if ($service !== null) { ObjectManager::setInstance($class, $service); }
            else { ObjectManager::removeInstance($class); }
        }
        FullPageCacheCoordinator::clearProcessCache();
        WelineEnv::getInstance()->reset();
        RequestContext::cleanup();
        Context::leave();
        Runtime::resetModeCache();
    }

    public function testPublishAndHitUseEffectiveTtlAndReceipt(): void
    {
        $this->coordinator->publishResponse(Response::fromContent('<html><body>policy</body></html>'), '/', [], [], []);
        $key = $this->invoke('getUnifiedCacheKey', 'GET');
        self::assertSame(30, $this->ttls[$key]);
        self::assertSame(30, $this->stored[$key]['policy_ttl']);
        $hit = $this->coordinator->getCachedResponse();
        self::assertNotNull($hit);
        self::assertMatchesRegularExpression('/^public, max-age=(?:28|29|30)$/', $hit->getHeader('CDN-Cache-Control'));
        $receipt = $this->coordinator->resolveRootHomepageProcessReceipt('https://example.test/');
        self::assertIsArray($receipt);
        self::assertSame('/', $receipt['policy_path']);
        self::assertSame($this->stored[$key]['policy_fingerprint'], $receipt['policy_fingerprint']);
        $legacy = $receipt;
        unset($legacy['policy_fingerprint']);
        self::assertNull($this->invoke('internalHomepageReceiptCacheKey', $legacy));
        $this->snapshot(false, 30);
        self::assertFalse($this->coordinator->canServeCachedResponse());
        self::assertFalse($this->coordinator->canBuildCachedResponse());
        self::assertNull($this->coordinator->getCachedResponse());
        self::assertFalse($this->coordinator->warmProcessCacheForInternalReceipt($receipt));
        self::assertNull($this->coordinator->getFormattedProcessCachedResponseForInternalReceipt($receipt));
    }

    public function testTtlChangeChangesEveryKeyAndInvalidatesOldReceipt(): void
    {
        $this->coordinator->publishResponse(Response::fromContent('<html><body>policy</body></html>'), '/', [], [], []);
        $before = $this->invoke('getUnifiedCacheKey', 'GET');
        $lock = $this->invoke('getBuildLockKey', 'GET');
        $receipt = $this->coordinator->resolveRootHomepageProcessReceipt('https://example.test/');
        $this->snapshot(true, 5);
        self::assertNotSame($before, $this->invoke('getUnifiedCacheKey', 'GET'));
        self::assertNotSame($lock, $this->invoke('getBuildLockKey', 'GET'));
        self::assertNull($this->invoke('internalHomepageReceiptCacheKey', $receipt));
        self::assertNull($this->coordinator->getCachedResponse());
    }

    public function testEdgeFreshnessAndFormattedWireAreBounded(): void
    {
        $response = Response::fromContent('body');
        $payload = ['fpc_expires_at' => microtime(true) + 12, 'policy_ttl' => 30];
        $this->invoke('applyFpcHitEdgeCacheHeaders', $response, false, $payload);
        self::assertMatchesRegularExpression('/^public, max-age=(?:10|11|12)$/', $response->getHeader('CDN-Cache-Control'));
        $this->invoke('applyFpcHitEdgeCacheHeaders', $response, true, $payload);
        self::assertSame('no-store', $response->getHeader('CDN-Cache-Control'));
        self::assertSame('no-store', $response->getHeader('Cloudflare-CDN-Cache-Control'));
        $http = "HTTP/1.1 200 OK\r\nCDN-Cache-Control: public, max-age=60\r\nCloudflare-CDN-Cache-Control: public, max-age=60\r\nX-Weline-Fpc-Fresh-Until: " . (microtime(true) + 5) . "\r\nConnection: keep-alive\r\n\r\nbody";
        $wire = $this->invoke('withFormattedResponseConnection', $http, true);
        self::assertStringNotContainsString('X-Weline-Fpc-Fresh-Until', $wire);
        self::assertMatchesRegularExpression('/CDN-Cache-Control: public, max-age=[0-5]\r\n/', $wire);
    }

    public function testFormattedProcessEntryCannotOutlivePublishedFreshness(): void
    {
        $until = microtime(true) + 5;
        $http = "HTTP/1.1 200 OK\r\nCDN-Cache-Control: public, max-age=5\r\nX-Weline-Fpc-Fresh-Until: " . $until . "\r\n\r\nbody";
        $this->invoke('setProcessCachedFormattedResponse', 'policy-wire', $http);
        $expires = (new \ReflectionProperty(FullPageCacheCoordinator::class, 'processFormattedFpcExpiresAt'))->getValue();
        self::assertLessThanOrEqual($until + 0.01, $expires['policy-wire']);
    }

    public function testExplicitReceiptPathIncludesExtraNamespacesWithoutAmbientUri(): void
    {
        RequestContext::remove('request.uri');
        $authority = new class implements NamespaceGenerationInterface {
            public function fingerprint(array $namespaces): string { return hash('sha256', json_encode($namespaces)); }
            public function bump(string $namespace): array { return []; }
            public function bumpMany(array $namespaces): array { return []; }
        };
        $resolver = new StorefrontCacheKeyContextResolver($authority, new NamespacePath());
        $paths = $resolver->namespacePathsForIdentity(RequestContext::scopeIdentity(), [], '/');
        self::assertContains('global/test-fpc-policy', $paths);
    }

    private function snapshot(bool $enabled, int $ttl): void
    {
        $snapshot = ['schema_version' => 'fpc-policy-snapshot.v1', 'revision' => 'test', 'source_version' => 1,
            'declarations' => ['test' => ['declaration_id' => 'test', 'type' => 'fpc', 'path_pattern' => '/',
                'public_path_patterns' => [], 'attrs' => ['enabled' => $enabled, 'ttl' => $ttl], 'namespaces' => ['global/test-fpc-policy']]],
            'scope_chains' => [], 'overrides' => []];
        $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        $hot->forgetRequestMemo('framework.fpc_policy_snapshot', 'snapshot.v1');
        $hot->rememberForRequest('framework.fpc_policy_snapshot', 'snapshot.v1', fn() => $snapshot);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->coordinator, $method))->invoke($this->coordinator, ...$args);
    }
}
