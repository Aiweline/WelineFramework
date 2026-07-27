<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Url;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsFiberContext;

final class WlsFiberContextConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        HeaderCollector::reset();
        SseContext::reset();
        RequestContext::cleanup();
        Context::leave();
        Url::resetWlsFiberInterleavedParserScratch();
        parent::tearDown();
    }

    public function testSyncFromServerOverridesStaleInitializedContextServerSnapshot(): void
    {
        Context::enter(new Context([
            'input' => [
                'server' => ['WELINE_AREA' => 'frontend'],
            ],
            'route' => [
                'area' => 'frontend',
            ],
            'runtime' => [
                'request_context' => [
                    'initialized' => true,
                    'storage' => ['env.area' => 'frontend'],
                ],
            ],
        ]));

        $_SERVER['WELINE_AREA'] = 'backend';
        $_SERVER['WELINE_USER_LANG'] = 'en_US';

        RequestContext::syncFromServer();

        self::assertSame('backend', Context::current()->get('route.area'));
        self::assertSame('backend', $_SERVER['WELINE_AREA']);
        self::assertSame('backend', RequestContext::getWelineArea());
        self::assertSame('en_US', RequestContext::getWelineUserLang());
    }

    public function testRestoreSyncsRequestContextWelineStaticsAfterGlobalCleanup(): void
    {
        $_SERVER['WELINE_AREA'] = 'backend';
        $_SERVER['WELINE_USER_LANG'] = 'en_US';
        RequestContext::syncFromServer();

        $ctx = WlsFiberContext::capture();

        RequestContext::cleanup();

        self::assertSame(RequestContext::AREA_FRONTEND, RequestContext::getWelineArea());

        $ctx->restore();

        self::assertSame('backend', $_SERVER['WELINE_AREA']);
        self::assertSame('backend', RequestContext::getWelineArea());
        self::assertSame('en_US', RequestContext::getWelineUserLang());
    }

    public function testRestoreSuperglobalsIncludeCookieRequestFiles(): void
    {
        $_SERVER['WELINE_AREA'] = 'frontend';
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2'];
        $_COOKIE = ['sid' => 'abc'];
        $_REQUEST = ['a' => '1', 'b' => '2'];
        $_FILES = [];

        $ctx = WlsFiberContext::capture();

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];
        $_SERVER = ['WELINE_AREA' => 'frontend'];

        $ctx->restore();

        self::assertSame(['a' => '1'], $_GET);
        self::assertSame(['b' => '2'], $_POST);
        self::assertSame(['sid' => 'abc'], $_COOKIE);
        self::assertSame(['a' => '1', 'b' => '2'], $_REQUEST);
    }

    public function testRestoreReinstatesCapturedResponseProtocolState(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'text/html; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'doc/http');
        $collector->setCookie('sid', 'doc-session', 0, '/');
        $collector->setStatusCode(202);

        $ctx = WlsFiberContext::capture();

        $collector->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'text/http');
        $collector->setCookie('sid', 'text-session', 0, '/');
        $collector->setStatusCode(500);

        $ctx->restore();

        self::assertSame('text/html; charset=utf-8', $collector->getHeader('Content-Type'));
        self::assertSame('doc/http', $collector->getHeader('X-WLS-Link-Protocol'));
        self::assertSame(202, $collector->getStatusCode());
        self::assertTrue($collector->hasExplicitStatusCode());
        self::assertSame('doc-session', $collector->getCookies()['sid']['value'] ?? null);
    }

    public function testRestoreWithoutResponseStateDoesNotReplayStaleHeaders(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'text/html; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'doc/http');
        $collector->setStatusCode(201);

        $ctx = WlsFiberContext::capture();

        $collector->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'text/http');
        $collector->setStatusCode(500);

        $ctx->restore(false);

        self::assertSame('text/plain; charset=utf-8', $collector->getHeader('Content-Type'));
        self::assertSame('text/http', $collector->getHeader('X-WLS-Link-Protocol'));
        self::assertSame(500, $collector->getStatusCode());
    }

    public function testRestoreClearsUrlInterleavedParserScratch(): void
    {
        $_SERVER['HTTP_HOST'] = 'sess-a.test:9001';
        $_SERVER['REQUEST_URI'] = '/a';
        Url::$parserServer = [
            'HTTP_HOST' => 'sess-a.test:9001',
            'WELINE_WEBSITE_URL' => 'http://sess-a.test:9001',
        ];
        Url::$parserCache = ['/backend/foo' => ['server' => ['stub' => true]]];

        $ctx = WlsFiberContext::capture();

        Url::$parserServer = ['HTTP_HOST' => 'sess-b.test:9002'];
        Url::$parserCache = ['/other' => []];
        $_SERVER['HTTP_HOST'] = 'sess-b.test:9002';

        $ctx->restore();

        self::assertSame([], Url::$parserServer);
        self::assertSame([], Url::$parserCache);
        self::assertSame('sess-a.test:9001', $_SERVER['HTTP_HOST']);
    }

    public function testRestoreAlsoReinstatesCapturedContextSnapshot(): void
    {
        Context::enter(new Context([
            'route' => ['area' => 'backend'],
            'input' => ['uri' => '/captured'],
        ]));

        $ctx = WlsFiberContext::capture();

        Context::enter(new Context([
            'route' => ['area' => 'frontend'],
            'input' => ['uri' => '/stale'],
        ]));

        $ctx->restore();

        self::assertSame('backend', Context::current()->get('route.area'));
        self::assertSame('/captured', Context::current()->get('input.uri'));
    }

    public function testExplicitTargetFiberRestoreKeepsMainAndPeerRequestScopesIsolated(): void
    {
        Runtime::setMode('wls');
        ObjectManager::clearInstances();
        StateManager::registerStaticReset(
            WlsFiberContextTargetFiberTestDouble::class,
            'requestProjection',
            null,
        );

        try {
            Context::enter(new Context([
                'meta' => ['id' => 'main-context'],
                'route' => ['area' => 'system'],
            ]));
            RequestContext::setId('main-request');
            HeaderCollector::getInstance()->setHeader('X-Owner', 'main');

            $mainObject = new WlsFiberContextTargetFiberTestDouble();
            $mainObject->owner = 'main';
            ObjectManager::setInstance(WlsFiberContextTargetFiberTestDouble::class, $mainObject);

            $createFiber = static function (array $fixture): \Fiber {
                return new \Fiber(static function () use ($fixture): array {
                    $_SERVER = [
                        'REQUEST_METHOD' => 'GET',
                        'REQUEST_URI' => '/' . $fixture['owner'],
                        'HTTP_HOST' => $fixture['owner'] . '.test',
                        'HTTP_COOKIE' => 'sid=' . $fixture['cookie'],
                        'WELINE_AREA' => RequestContext::AREA_FRONTEND,
                        'WELINE_WEBSITE_ID' => (string)$fixture['website_id'],
                        'WELINE_WEBSITE_CODE' => $fixture['website_code'],
                        'WELINE_STORE_ID' => (string)$fixture['store_id'],
                        'WELINE_STORE_CODE' => $fixture['store_code'],
                        'WELINE_STORE_MODE' => ScopeIdentity::MODE_NORMAL,
                        'WELINE_CHANNEL_ID' => (string)$fixture['channel_id'],
                        'WELINE_CHANNEL_CODE' => $fixture['channel_code'],
                        'WELINE_USER_LANG' => $fixture['locale'],
                        'WELINE_USER_CURRENCY' => $fixture['currency'],
                    ];
                    $_GET = ['owner' => $fixture['owner']];
                    $_POST = [];
                    $_COOKIE = ['sid' => $fixture['cookie']];
                    $_REQUEST = $_GET;
                    $_FILES = [];

                    Context::enter(Context::fromGlobals(['id' => $fixture['context_id']]));
                    RequestContext::init();
                    RequestContext::setId($fixture['request_id']);
                    RequestContext::installScopeIdentity(ScopeIdentity::channel(
                        $fixture['website_id'],
                        $fixture['website_code'],
                        $fixture['store_code'],
                        $fixture['channel_code'],
                        ScopeIdentity::MODE_NORMAL,
                    ));
                    RequestContext::setWelineUserLang($fixture['locale']);
                    RequestContext::setWelineUserCurrency($fixture['currency']);
                    RequestContext::set('p1b003.scope_token_sentinel', $fixture['scope_token']);
                    RequestContext::set('p1b003.acl_sentinel', $fixture['acl']);
                    RequestContext::set('p1b003.session_sentinel', $fixture['session']);

                    HeaderCollector::getInstance()->setHeader('X-Owner', $fixture['owner']);
                    $fiberObject = new WlsFiberContextTargetFiberTestDouble();
                    $fiberObject->owner = $fixture['owner'];
                    ObjectManager::setInstance(WlsFiberContextTargetFiberTestDouble::class, $fiberObject);
                    WlsFiberContextTargetFiberTestDouble::$requestProjection = $fixture['owner'];

                    SseContext::reset();
                    SseContext::setWriteCallback(
                        static fn (string $payload): string => $fixture['owner'] . ':' . $payload
                    );
                    SseContext::setAliveCallback(static fn (): bool => true);
                    SseContext::enableSse();

                    \Fiber::suspend('suspended-' . $fixture['owner']);

                    $writeCallback = SseContext::getWriteCallback();

                    return [
                        'context_id' => Context::current()->get('meta.id'),
                        'request_id' => RequestContext::getId(),
                        'scope' => RequestContext::scopeIdentity()?->canonicalKey(),
                        'store_id' => RequestContext::getWelineStoreId(),
                        'channel_id' => RequestContext::getWelineChannelId(),
                        'locale' => RequestContext::getWelineUserLang(),
                        'currency' => RequestContext::getWelineUserCurrency(),
                        'scope_token' => RequestContext::get('p1b003.scope_token_sentinel'),
                        'acl' => RequestContext::get('p1b003.acl_sentinel'),
                        'session' => RequestContext::get('p1b003.session_sentinel'),
                        'cookie' => $_COOKIE['sid'] ?? null,
                        'uri' => $_SERVER['REQUEST_URI'] ?? null,
                        'header_owner' => HeaderCollector::getInstance()->getHeader('X-Owner'),
                        'object_owner' => ObjectManager::_getInstance(WlsFiberContextTargetFiberTestDouble::class)?->owner,
                        'static_owner' => WlsFiberContextTargetFiberTestDouble::$requestProjection,
                        'sse_owner' => \is_callable($writeCallback) ? $writeCallback('probe') : null,
                    ];
                });
            };

            $fixtureA = [
                'owner' => 'a',
                'context_id' => 'context-a',
                'request_id' => 'request-a',
                'website_id' => 11,
                'website_code' => 'site-a',
                'store_id' => 21,
                'store_code' => 'store-a',
                'channel_id' => 31,
                'channel_code' => 'channel-a',
                'locale' => 'en_US',
                'currency' => 'USD',
                'cookie' => 'cookie-a',
                'scope_token' => 'token-a',
                'acl' => 'acl-a',
                'session' => 'session-a',
            ];
            $fixtureB = [
                'owner' => 'b',
                'context_id' => 'context-b',
                'request_id' => 'request-b',
                'website_id' => 12,
                'website_code' => 'site-b',
                'store_id' => 22,
                'store_code' => 'store-b',
                'channel_id' => 32,
                'channel_code' => 'channel-b',
                'locale' => 'fr_FR',
                'currency' => 'EUR',
                'cookie' => 'cookie-b',
                'scope_token' => 'token-b',
                'acl' => 'acl-b',
                'session' => 'session-b',
            ];

            $fiberA = $createFiber($fixtureA);
            self::assertSame('suspended-a', $fiberA->start());
            $contextA = WlsFiberContext::captureForFiber($fiberA);

            $fiberB = $createFiber($fixtureB);
            self::assertSame('suspended-b', $fiberB->start());
            $contextB = WlsFiberContext::captureForFiber($fiberB);

            $_SERVER = ['REQUEST_URI' => '/main-projection'];
            $_COOKIE = ['sid' => 'main-projection'];
            SseContext::reset();
            SseContext::setWriteCallback(static fn (string $payload): string => 'main:' . $payload);
            WlsFiberContextTargetFiberTestDouble::$requestProjection = 'main';

            $contextA->restoreForFiber($fiberA);

            self::assertSame('main-context', Context::current()->get('meta.id'));
            self::assertSame('main-request', RequestContext::getId());
            self::assertNull(RequestContext::scopeIdentity());
            self::assertSame('main', HeaderCollector::getInstance()->getHeader('X-Owner'));
            self::assertSame(
                'main',
                ObjectManager::_getInstance(WlsFiberContextTargetFiberTestDouble::class)?->owner
            );
            self::assertSame('cookie-a', $_COOKIE['sid'] ?? null);
            self::assertSame('/a', $_SERVER['REQUEST_URI'] ?? null);

            self::assertNull($fiberA->resume());
            self::assertTrue($fiberA->isTerminated());

            $contextB->restoreForFiber($fiberB);

            self::assertSame('main-context', Context::current()->get('meta.id'));
            self::assertSame('main-request', RequestContext::getId());
            self::assertNull(RequestContext::scopeIdentity());
            self::assertSame('main', HeaderCollector::getInstance()->getHeader('X-Owner'));
            self::assertSame(
                'main',
                ObjectManager::_getInstance(WlsFiberContextTargetFiberTestDouble::class)?->owner
            );
            self::assertSame('cookie-b', $_COOKIE['sid'] ?? null);
            self::assertSame('/b', $_SERVER['REQUEST_URI'] ?? null);

            self::assertNull($fiberB->resume());
            self::assertTrue($fiberB->isTerminated());

            self::assertSame([
                'context_id' => 'context-a',
                'request_id' => 'request-a',
                'scope' => 'channel|11|site-a|store-a|channel-a|normal|v1',
                'store_id' => 21,
                'channel_id' => 31,
                'locale' => 'en_US',
                'currency' => 'USD',
                'scope_token' => 'token-a',
                'acl' => 'acl-a',
                'session' => 'session-a',
                'cookie' => 'cookie-a',
                'uri' => '/a',
                'header_owner' => 'a',
                'object_owner' => 'a',
                'static_owner' => 'a',
                'sse_owner' => 'a:probe',
            ], $fiberA->getReturn());
            self::assertSame([
                'context_id' => 'context-b',
                'request_id' => 'request-b',
                'scope' => 'channel|12|site-b|store-b|channel-b|normal|v1',
                'store_id' => 22,
                'channel_id' => 32,
                'locale' => 'fr_FR',
                'currency' => 'EUR',
                'scope_token' => 'token-b',
                'acl' => 'acl-b',
                'session' => 'session-b',
                'cookie' => 'cookie-b',
                'uri' => '/b',
                'header_owner' => 'b',
                'object_owner' => 'b',
                'static_owner' => 'b',
                'sse_owner' => 'b:probe',
            ], $fiberB->getReturn());

            self::assertSame('main-context', Context::current()->get('meta.id'));
            self::assertSame('main-request', RequestContext::getId());
        } finally {
            StateManager::unregisterStaticReset(
                WlsFiberContextTargetFiberTestDouble::class,
                'requestProjection',
            );
            WlsFiberContextTargetFiberTestDouble::$requestProjection = null;
            ObjectManager::clearInstances();
            Runtime::resetModeCache();
        }
    }

    public function testTargetRestoreDefaultsStaticStateRegisteredAfterCapture(): void
    {
        Runtime::setMode('wls');
        WlsFiberContextTargetFiberTestDouble::$lateProjection = null;

        $fiber = new \Fiber(static function (): ?string {
            Context::enter(new Context(['meta' => ['id' => 'late-static-owner']]));
            \Fiber::suspend('late-static-ready');
            return WlsFiberContextTargetFiberTestDouble::$lateProjection;
        });

        try {
            self::assertSame('late-static-ready', $fiber->start());
            $context = WlsFiberContext::captureForFiber($fiber);

            StateManager::registerStaticReset(
                WlsFiberContextTargetFiberTestDouble::class,
                'lateProjection',
                null,
            );
            WlsFiberContextTargetFiberTestDouble::$lateProjection = 'peer-fiber-value';

            $context->restoreForFiber($fiber);
            self::assertNull($fiber->resume());
            self::assertTrue($fiber->isTerminated());
            self::assertNull($fiber->getReturn());
        } finally {
            StateManager::unregisterStaticReset(
                WlsFiberContextTargetFiberTestDouble::class,
                'lateProjection',
            );
            WlsFiberContextTargetFiberTestDouble::$lateProjection = null;
            Runtime::resetModeCache();
        }
    }

    public function testTargetRestoreFailsBeforeResumeWhenOwnedContextWasLost(): void
    {
        $fiber = new \Fiber(static function (): void {
            Context::enter(new Context(['meta' => ['id' => 'request-owner']]));
            \Fiber::suspend('owned');
            Context::leave();
            \Fiber::suspend('owner-removed');
        });

        self::assertSame('owned', $fiber->start());
        $snapshot = WlsFiberContext::captureForFiber($fiber);
        self::assertSame('owner-removed', $fiber->resume());
        self::assertNull(Context::getForFiber($fiber));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('WLS request Fiber has no owned Context before resume.');
        try {
            $snapshot->restoreForFiber($fiber);
        } finally {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
    }
}

final class WlsFiberContextTargetFiberTestDouble
{
    public static ?string $requestProjection = null;

    public static ?string $lateProjection = null;

    public ?string $owner = null;
}
