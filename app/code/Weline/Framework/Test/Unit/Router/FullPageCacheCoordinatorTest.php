<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Session\SessionCookieNameResolver;

final class FullPageCacheCoordinatorTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $_SERVER = [];
        if (Context::hasCurrent()) {
            Context::leave();
        }
        WelineEnv::getInstance()->reset();
        Runtime::setMode(Runtime::WLS);
        FullPageCacheCoordinator::clearProcessCache();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('fpc-coordinator-test');
        Context::current()->set('input.server.HTTP_HOST', 'example.test');
        Context::current()->set('input.server.SERVER_PORT', 443);
        Context::current()->set('input.host', 'example.test');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            RequestContext::scopeIdentity(),
            'zh_Hans_CN',
            'CNY',
            str_repeat('b', 64),
            str_repeat('b', 64),
            true,
        ));
        WelineEnv::set('full_request_uri', 'https://example.test/', 'unit-test');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', 'https://example.test/', 'unit-test');
        WelineEnv::set('request.uri', '/', 'unit-test');
        WelineEnv::set('request.method', 'GET', 'unit-test');
        WelineEnv::set('is_backend', false, 'unit-test');
        WelineEnv::set('is_static_file', false, 'unit-test');
        WelineEnv::set('response.from_cache', false, 'unit-test');
    }

    protected function tearDown(): void
    {
        WelineEnv::getInstance()->reset();
        FullPageCacheCoordinator::clearProcessCache();
        Runtime::resetModeCache();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        $_SERVER = $this->originalServer;
    }

    public function testGetCachedResponseRestoresLegacyHeadersAndStatus(): void
    {
        $pool = new InMemoryCachePool();
        $coordinator = new FullPageCacheCoordinator(null, $pool);
        $pool->set(
            $this->buildCurrentUnifiedFpcCacheKey($coordinator, 'GET'),
            [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 201,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>cached</body></html>',
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => [
                    'Content-Type: text/html; charset=utf-8',
                    'Cache-Control: public, max-age=60',
                    'Content-Length: 999',
                    'X-Frame-Options: ALLOWALL',
                    'Access-Control-Allow-Origin: https://stale-policy.example',
                ],
            ]
        );

        $response = $coordinator->getCachedResponse('GET');

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('<html><body>cached</body></html>', $response->getBody());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('public, max-age=60', $response->getHeader('Cache-Control'));
        self::assertNull($response->getHeader('Content-Length'));
        self::assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('1; mode=block', $response->getHeader('X-XSS-Protection'));
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('HIT', $response->getHeader('X-Weline-FPC'));
        self::assertTrue((bool)WelineEnv::get('response.from_cache', false));
    }

    public function testCooperativeFpcYieldIsOptInForPersistentRequests(): void
    {
        $method = new \ReflectionMethod(FullPageCacheCoordinator::class, 'cooperativeBuildYield');
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);
        self::assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('wls.performance.fpc_cooperative_yield_enabled', $source);
        self::assertStringContainsString('false', $source);
        self::assertStringContainsString('SchedulerSystem::yield()', $source);
    }

    public function testBuildLockAllowsSinglePublisherAndFollowersReadPublishedResponse(): void
    {
        $adapter = new class extends WlsMemoryAdapter {
            /**
             * @var array<string, mixed>
             */
            private array $lockStore = [];

            public function __construct()
            {
            }

            public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
            {
                $current = $this->lockStore[$key] ?? null;
                if ($current !== $expected) {
                    return false;
                }

                if ($value === null) {
                    unset($this->lockStore[$key]);
                } else {
                    $this->lockStore[$key] = $value;
                }

                return true;
            }
        };

        $pool = new InMemoryCachePool($adapter);
        $coordinator = new FullPageCacheCoordinator(null, $pool);

        $lockA = $coordinator->acquireBuildLock('GET');
        $lockB = $coordinator->acquireBuildLock('GET');

        self::assertNotNull($lockA);
        self::assertNull($lockB);

        $response = Response::html('<html><body>fresh</body></html>', 200)
            ->setHeader('Cache-Control', 'public, max-age=30');
        $coordinator->publishResponse($response, '/', ['id' => 'home'], ['module' => 'Test_Module'], [], 'GET');

        $published = $coordinator->waitForPublishedResponse('GET', 1);
        self::assertInstanceOf(Response::class, $published);
        self::assertSame('<html><body>fresh</body></html>', $published->getBody());
        self::assertSame('public, max-age=30', $published->getHeader('Cache-Control'));
        self::assertSame('HIT', $published->getHeader('X-Weline-FPC'));

        $coordinator->releaseBuildLock($lockA);
        $lockC = $coordinator->acquireBuildLock('GET');
        self::assertNotNull($lockC);
        $coordinator->releaseBuildLock($lockC);
    }

    public function testPortQualifiedLoggedInSessionBypassesAllCurrentRequestFpcGates(): void
    {
        $sid = str_repeat('a', 32);
        Context::current()->set('input.server.HTTP_HOST', '127.0.0.1:9502');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', '127.0.0.1');
        Context::current()->set('input.server.HTTP_COOKIE', 'WELINE_SESSID_9502=' . $sid);
        WelineEnv::setServer('HTTP_COOKIE', 'WELINE_SESSID_9502=' . $sid, 'unit-test');
        $this->setKnownLoggedInSession($sid);

        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        self::assertSame('WELINE_SESSID_9502', SessionCookieNameResolver::resolve());
        self::assertSame(
            'WELINE_SESSID_9502=' . $sid,
            Context::current()->server('HTTP_COOKIE', ''),
        );
        self::assertTrue($coordinator->hasLoggedInFrontendSessionForCache());
        self::assertFalse($coordinator->canServeCachedResponse('GET'));
        self::assertFalse($coordinator->canBuildCachedResponse('GET'));
        self::assertFalse($coordinator->canPublishResponse(
            Response::html('<html>private</html>')->setHeader('Cache-Control', 'public'),
            'GET',
        ));
    }

    public function testPreRouterSessionCheckUsesFullUriAuthorityAndFailsClosedForInvalidSid(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        $method = new \ReflectionMethod($coordinator, 'cookieHeaderHasLoggedInFrontendSession');
        $method->setAccessible(true);
        $sid = str_repeat('c', 32);
        $this->setKnownLoggedInSession($sid);

        self::assertTrue($method->invoke(
            $coordinator,
            'WELINE_SESSID_9502=' . $sid,
            'https://127.0.0.1:9502/',
        ));
        self::assertFalse($method->invoke(
            $coordinator,
            'WELINE_SESSID=' . $sid,
            'https://127.0.0.1:9502/',
        ));
        self::assertTrue($method->invoke(
            $coordinator,
            'WELINE_SESSID_9502=invalid',
            'https://127.0.0.1:9502/',
        ));
    }

    public function testCanPublishResponseRejectsCookiesPrivateDirectivesAndUnsafeVary(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        $public = Response::html('<html>public</html>')->setHeader('Cache-Control', 'public, max-age=60');
        self::assertTrue($coordinator->canPublishResponse($public, 'GET'));

        $managedCookie = Response::html('<html>cookie</html>')->setHeader('Cache-Control', 'public');
        $managedCookie->setCookie('customer', 'secret');
        self::assertNull($managedCookie->getHeader('Set-Cookie'));
        self::assertNotSame([], $managedCookie->getCookies());
        self::assertFalse($coordinator->canPublishResponse($managedCookie, 'GET'));

        $missingContentType = new Response(true);
        $missingContentType->setHttpResponseCode(200);
        $missingContentType->setBody('<html>missing type</html>');
        self::assertFalse($coordinator->canPublishResponse($missingContentType, 'GET'));
        self::assertFalse($coordinator->canPublishResponse(Response::text('plain text'), 'GET'));

        foreach (
            [
                ['Cache-Control', 'private'],
                ['Cache-Control', 'no-store'],
                ['Cache-Control', 'no-cache'],
                ['Cache-Control', 'max-age=0'],
                ['Pragma', 'no-cache'],
                ['Set-Cookie', 'customer=secret'],
                ['Vary', '*'],
                ['Vary', 'Authorization'],
            ] as [$header, $value]
        ) {
            $response = Response::html('<html>unsafe</html>')->setHeader('Cache-Control', 'public');
            $response->setHeader($header, $value);
            self::assertFalse(
                $coordinator->canPublishResponse($response, 'GET'),
                $header . ': ' . $value,
            );
        }

        Context::current()->set('input.server.HTTP_AUTHORIZATION', 'Bearer private-token');
        self::assertFalse($coordinator->canBuildCachedResponse('GET'));
        Context::current()->set('input.server.HTTP_AUTHORIZATION', '');
    }

    public function testNamespaceFailurePausesServeBuildAndPublishWithoutShortKeyFallback(): void
    {
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            RequestContext::scopeIdentity(),
            'zh_Hans_CN',
            'CNY',
            null,
            str_repeat('d', 64),
            false,
            'storefront_namespace_pending',
        ));
        $resolver = new StorefrontCacheKeyContextResolver(
            new FailingNamespaceGenerationAuthority(),
            new NamespacePath(),
        );
        $coordinator = new FullPageCacheCoordinator(
            null,
            new InMemoryCachePool(),
            null,
            null,
            null,
            null,
            $resolver,
        );

        self::assertFalse($coordinator->canServeCachedResponse('GET'));
        self::assertFalse($coordinator->canBuildCachedResponse('GET'));
        self::assertFalse($coordinator->canPublishResponse(
            Response::html('<html>must not publish</html>')->setHeader('Cache-Control', 'public'),
            'GET',
        ));
        $failed = StorefrontCacheKeyContext::current();
        self::assertInstanceOf(StorefrontCacheKeyContext::class, $failed);
        self::assertFalse($failed->cacheable);
        self::assertSame('storefront_namespace_unavailable', $failed->failureCode);
        self::assertStringContainsString(
            'cache_version=' . $failed->cacheKeyFingerprint,
            KeyBuilder::applyDimensionFlags('fenced', true, false, false, false),
        );
    }

    public function testAllFpcKeySurfacesChangeTogetherAcrossFrozenScopes(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        $scopeA = $this->fpcSurfaceIdentities($coordinator);

        $this->replaceFrozenScope(
            ScopeIdentity::channel(7, 'shop_a', 'outlet', 'app', ScopeIdentity::MODE_TEST),
            str_repeat('e', 64),
        );
        $scopeB = $this->fpcSurfaceIdentities($coordinator);

        foreach (array_keys($scopeA) as $surface) {
            self::assertNotSame($scopeA[$surface], $scopeB[$surface], $surface);
        }
    }

    private function buildCurrentUnifiedFpcCacheKey(FullPageCacheCoordinator $coordinator, string $method): string
    {
        $variantMethod = new \ReflectionMethod($coordinator, 'buildCurrentFpcVariant');
        $variantMethod->setAccessible(true);
        $variant = $variantMethod->invoke($coordinator);

        self::assertIsArray($variant);

        $keyMethod = new \ReflectionMethod($coordinator, 'buildUnifiedFpcCacheKey');
        $keyMethod->setAccessible(true);

        return (string)$keyMethod->invoke($coordinator, 'https://example.test/', $method, $variant);
    }

    private function setKnownLoggedInSession(string $sessionId): void
    {
        $cache = new \ReflectionProperty(FullPageCacheCoordinator::class, 'frontendLoginSessionCache');
        $expires = new \ReflectionProperty(FullPageCacheCoordinator::class, 'frontendLoginSessionCacheExpiresAt');
        $cache->setValue(null, [$sessionId => true]);
        $expires->setValue(null, [$sessionId => microtime(true) + 60.0]);
    }

    /** @return array<string,string> */
    private function fpcSurfaceIdentities(FullPageCacheCoordinator $coordinator): array
    {
        $fullUri = 'https://example.test/';
        $variantMethod = new \ReflectionMethod($coordinator, 'buildCurrentFpcVariant');
        $variant = $variantMethod->invoke($coordinator);
        self::assertIsArray($variant);

        $unifiedMethod = new \ReflectionMethod($coordinator, 'buildUnifiedFpcCacheKey');
        $unified = (string)$unifiedMethod->invoke($coordinator, $fullUri, 'GET', $variant);
        $lockMethod = new \ReflectionMethod($coordinator, 'buildBuildLockKeyForFullUri');
        $lock = (string)$lockMethod->invoke($coordinator, $fullUri, 'GET', $variant);
        $staleMethod = new \ReflectionMethod($coordinator, 'buildStaleCacheKey');
        $stale = (string)$staleMethod->invoke($coordinator, $unified);
        $neutralMethod = new \ReflectionMethod($coordinator, 'buildSchemaNeutralStaleCacheKey');
        $neutral = (string)$neutralMethod->invoke($coordinator, $fullUri, 'GET', $variant);
        $formattedMethod = new \ReflectionMethod($coordinator, 'buildFormattedFastHttpCacheKey');
        $formatted = (string)$formattedMethod->invoke($coordinator, $unified);
        $receiptMethod = new \ReflectionMethod($coordinator, 'buildInternalHomepageWarmupReceipt');
        $receipt = $receiptMethod->invoke($coordinator, $fullUri, $variant, $unified);
        self::assertIsArray($receipt);

        return [
            'variant' => json_encode($variant, JSON_UNESCAPED_SLASHES) ?: '',
            'fresh' => $unified,
            'stale' => $stale,
            'schema_neutral_stale' => $neutral,
            'lock' => $lock,
            'formatted' => $formatted,
            'receipt' => (string)$receipt['identity_digest'],
        ];
    }

    private function replaceFrozenScope(ScopeIdentity $identity, string $fingerprint): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('fpc-scope-' . bin2hex(random_bytes(4)));
        Context::current()->set('input.server.HTTP_HOST', 'example.test');
        Context::current()->set('input.server.SERVER_PORT', 443);
        Context::current()->set('input.host', 'example.test');
        RequestContext::installScopeIdentity($identity);
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            $identity,
            'zh_Hans_CN',
            'CNY',
            $fingerprint,
            $fingerprint,
            true,
        ));
    }
}

final class InMemoryCachePool implements CachePoolInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $store = [];

    public function __construct(private readonly ?object $adapter = null)
    {
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function getIdentity(): string
    {
        return 'router';
    }

    public function getTip(): string
    {
        return 'unit-test';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
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
            'identity' => 'router',
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function getAdapter(): ?object
    {
        return $this->adapter;
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

final class FailingNamespaceGenerationAuthority implements NamespaceGenerationInterface
{
    public function fingerprint(array $namespaces): string
    {
        throw new \RuntimeException('namespace unavailable');
    }

    public function bumpMany(array $namespaces): array
    {
        throw new \RuntimeException('namespace unavailable');
    }

    public function bump(string $namespace): array
    {
        throw new \RuntimeException('namespace unavailable');
    }
}
