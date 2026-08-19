<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
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
    private ?array $originalAllowedCurrencyCodeMap = null;
    private ?string $originalAllowedCurrencyCodeScope = null;

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
        $currencyMap = new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap');
        $currencyScope = new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope');
        $this->originalAllowedCurrencyCodeMap = $currencyMap->getValue();
        $this->originalAllowedCurrencyCodeScope = $currencyScope->getValue();
        $currencyMap->setValue(null, ['CNY' => true, 'USD' => true]);
        $currencyScope->setValue(null, (string)\w_env('website_id', '')
            . '|' . (string)\w_env('website.code', '')
            . '|' . (string)WelineEnv::server('WELINE_WEBSITE_ID', ''));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap'))
            ->setValue(null, $this->originalAllowedCurrencyCodeMap);
        (new \ReflectionProperty(State::class, 'allowedCurrencyCodeScope'))
            ->setValue(null, $this->originalAllowedCurrencyCodeScope);
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

    public function testLocalizedHomepageReceiptsPreserveEveryRouteFormAndUnifiedVariant(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        // A path currency is a route identity, not a Website selector option.
        // Keep only CNY in the presentation allowlist and prove /USD/... still
        // receives its own USD variant through the central path parser.
        (new \ReflectionProperty(State::class, 'allowedCurrencyCodeMap'))
            ->setValue(null, ['CNY' => true]);
        $cases = [
            '/USD/' => ['language' => 'zh_Hans_CN', 'currency' => 'USD', 'website_url' => ''],
            '/en_US/' => ['language' => 'en_US', 'currency' => 'CNY', 'website_url' => ''],
            '/USD/en_US/' => ['language' => 'en_US', 'currency' => 'USD', 'website_url' => ''],
            '/en_US/USD/' => ['language' => 'en_US', 'currency' => 'USD', 'website_url' => ''],
            '/site/USD/en_US/' => [
                'language' => 'en_US',
                'currency' => 'USD',
                'website_url' => 'https://example.test/site',
            ],
            '/site/en_US/USD/' => [
                'language' => 'en_US',
                'currency' => 'USD',
                'website_url' => 'https://example.test/site',
            ],
        ];
        $receipts = [];
        $variantTokens = [];

        foreach ($cases as $path => $expected) {
            $fullUri = 'https://example.test' . $path;
            WelineEnv::set('website_url', $expected['website_url'], 'unit-test');
            $this->setFrozenLocaleCurrency($expected['language'], $expected['currency']);
            self::assertSame($expected['language'], StorefrontCacheKeyContext::current()?->lang, $path);
            self::assertSame($expected['currency'], StorefrontCacheKeyContext::current()?->currency, $path);
            $this->setCurrentFpcUri($fullUri, $path);
            $variantMethod = new \ReflectionMethod($coordinator, 'buildCurrentFpcVariant');
            $currentVariant = $variantMethod->invoke($coordinator);
            self::assertSame($expected['language'], $currentVariant['lang'] ?? null, $path);
            self::assertSame($expected['currency'], $currentVariant['currency'] ?? null, $path);
            $variantTokens[$path] = (string)(new \ReflectionMethod(
                $coordinator,
                'variantDebugToken',
            ))->invoke($coordinator, $currentVariant);
            self::assertSame($fullUri, (string)\w_env('full_request_uri', ''), $path);
            $coordinator->publishResponse(
                Response::html('<html><body>localized ' . $path . '</body></html>')
                    ->setHeader('Cache-Control', 'public, max-age=60'),
                $path,
                ['id' => 'home'],
                ['module' => 'Test_Module'],
                [],
                'GET',
            );

            $receipt = $coordinator->resolveLocalizedHomepageProcessReceipt($fullUri);
            self::assertIsArray($receipt, $path);
            self::assertSame($fullUri, $receipt['full_uri'], $path);
            self::assertSame(hash('sha256', $receipt['cache_key']), $receipt['identity_digest'], $path);

            $payloads = (new \ReflectionProperty(
                FullPageCacheCoordinator::class,
                'processFpcPayloadCache',
            ))->getValue();
            self::assertIsArray($payloads);
            $payload = $payloads[$receipt['cache_key']] ?? null;
            self::assertIsArray($payload, $path);
            self::assertSame($expected['language'], $payload['fpc_variant']['lang'] ?? null, $path);
            self::assertSame($expected['currency'], $payload['fpc_variant']['currency'] ?? null, $path);
            $receipts[$path] = $receipt;
        }

        self::assertNotSame(
            $receipts['/USD/en_US/']['cache_key'],
            $receipts['/en_US/USD/']['cache_key'],
            'Equivalent locale dimensions must not merge distinct visitor-facing URI orders.',
        );
        self::assertNotSame(
            $variantTokens['/en_US/'],
            $variantTokens['/USD/en_US/'],
            'A URL currency dimension must survive final cache-key serialization.',
        );
        self::assertSame(
            $variantTokens['/USD/en_US/'],
            $variantTokens['/en_US/USD/'],
            'Equivalent language/currency dimensions must share one semantic variant token.',
        );
        self::assertSame(
            $variantTokens['/site/USD/en_US/'],
            $variantTokens['/site/en_US/USD/'],
            'Website-prefixed routes must preserve the same semantic language/currency dimensions.',
        );

        $catalogFullUri = 'https://example.test/site/USD/en_US/catalog';
        WelineEnv::set('website_url', 'https://example.test/site', 'unit-test');
        $this->setFrozenLocaleCurrency('en_US', 'USD');
        $this->setCurrentFpcUri($catalogFullUri, '/catalog');
        $catalogVariant = $variantMethod->invoke($coordinator);
        self::assertSame('en_US', $catalogVariant['lang'] ?? null);
        self::assertSame('USD', $catalogVariant['currency'] ?? null);
        self::assertFalse(
            $coordinator->isLocalizedHomepageFullUri($catalogFullUri),
            'A localized business route keeps its variant but must not enter the homepage-only receipt path.',
        );
    }

    public function testLocalizedContextUpgradeRetiresFreshAndSchemaNeutralLegacyPayloads(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        $fullUri = 'https://example.test/USD/en_US/';
        $this->setFrozenLocaleCurrency('en_US', 'USD');
        $this->setCurrentFpcUri($fullUri, '/USD/en_US/');

        $variant = (new \ReflectionMethod($coordinator, 'buildCurrentFpcVariant'))->invoke($coordinator);
        self::assertIsArray($variant);
        $suffixWithoutSchema = (string)(new \ReflectionMethod(
            $coordinator,
            'variantSuffixWithoutSchema',
        ))->invoke($coordinator, $variant);
        $decorate = new \ReflectionMethod($coordinator, 'decorateFpcLogicalKey');

        $legacyFresh = KeyBuilder::build('router', (string)$decorate->invoke(
            $coordinator,
            'unified-fpc:' . $fullUri . ':' . $suffixWithoutSchema
                . '|schema=20260722-scope-variant-v2:GET',
            $fullUri,
        ));
        $legacyV4Fresh = KeyBuilder::build('router', (string)$decorate->invoke(
            $coordinator,
            'unified-fpc:' . $fullUri . ':' . $suffixWithoutSchema
                . '|schema=20260818-localization-context-v4:GET',
            $fullUri,
        ));
        $currentFresh = (string)(new \ReflectionMethod(
            $coordinator,
            'buildUnifiedFpcCacheKey',
        ))->invoke($coordinator, $fullUri, 'GET', $variant);

        $legacySchemaNeutral = KeyBuilder::build('router', (string)$decorate->invoke(
            $coordinator,
            'unified-fpc-schema-neutral-stale:' . $fullUri . ':' . $suffixWithoutSchema . ':GET',
            $fullUri,
        ));
        $legacyV4SchemaNeutral = KeyBuilder::build('router', (string)$decorate->invoke(
            $coordinator,
            'unified-fpc-schema-neutral-stale:20260818-localization-context-v4:'
                . $fullUri . ':' . $suffixWithoutSchema . ':GET',
            $fullUri,
        ));
        $currentSchemaNeutral = (string)(new \ReflectionMethod(
            $coordinator,
            'buildSchemaNeutralStaleCacheKey',
        ))->invoke($coordinator, $fullUri, 'GET', $variant);

        self::assertNotSame($legacyFresh, $currentFresh);
        self::assertNotSame($legacyV4Fresh, $currentFresh);
        self::assertNotSame($legacySchemaNeutral, $currentSchemaNeutral);
        self::assertNotSame($legacyV4SchemaNeutral, $currentSchemaNeutral);
    }

    public function testLocalizedHomepageReceiptRejectsAuthorityCookiesAndEvictedPayload(): void
    {
        $coordinator = new FullPageCacheCoordinator(null, new InMemoryCachePool());
        $fullUri = 'https://example.test:8443/USD/en_US/';
        $this->setCurrentFpcUri($fullUri, '/USD/en_US/');
        $coordinator->publishResponse(
            Response::html('<html><body>isolated localized homepage</body></html>')
                ->setHeader('Cache-Control', 'public, max-age=60'),
            '/USD/en_US/',
            ['id' => 'home'],
            ['module' => 'Test_Module'],
            [],
            'GET',
        );

        $receipt = $coordinator->resolveLocalizedHomepageProcessReceipt($fullUri);
        self::assertIsArray($receipt);
        self::assertNull($coordinator->resolveLocalizedHomepageProcessReceipt(
            'https://example.test/USD/en_US/',
        ));
        self::assertNull($coordinator->resolveLocalizedHomepageProcessReceipt(
            $fullUri,
            'WELINE_USER_CURRENCY=USD',
        ));
        self::assertNull($coordinator->resolveLocalizedHomepageProcessReceipt(
            'https://example.test:8443/USD/en_US/catalog',
        ));

        $deletePayload = new \ReflectionMethod($coordinator, 'deleteProcessCachedPayload');
        $deletePayload->invoke($coordinator, $receipt['cache_key']);
        self::assertNull($coordinator->resolveLocalizedHomepageProcessReceipt($fullUri));
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

    private function setCurrentFpcUri(string $fullUri, string $requestUri): void
    {
        WelineEnv::set('full_request_uri', $fullUri, 'unit-test');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', $fullUri, 'unit-test');
        WelineEnv::set('request.uri', $requestUri, 'unit-test');
        Context::current()?->set('input.server.REQUEST_URI', $requestUri);
    }

    private function setFrozenLocaleCurrency(string $language, string $currency): void
    {
        $identity = RequestContext::scopeIdentity();
        self::assertInstanceOf(ScopeIdentity::class, $identity);
        RequestContext::setWelineUserLang($language);
        RequestContext::setWelineUserCurrency($currency);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            $identity,
            $language,
            $currency,
            str_repeat('b', 64),
            str_repeat('b', 64),
            true,
        ));
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
