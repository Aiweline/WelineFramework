<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionCookieNameResolver;

final class SessionCookieNameResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testResolveUsesExplicitAuthorityPort(): void
    {
        self::assertSame('WELINE_SESSID', SessionCookieNameResolver::resolve('example.test'));
        self::assertSame('WELINE_SESSID', SessionCookieNameResolver::resolve('example.test:80'));
        self::assertSame('WELINE_SESSID', SessionCookieNameResolver::resolve('example.test:443'));
        self::assertSame('WELINE_SESSID_9502', SessionCookieNameResolver::resolve('example.test:9502'));
        self::assertSame('WELINE_SESSID_9503', SessionCookieNameResolver::resolve('[::1]:9503'));
    }

    public function testCurrentHostFallsBackToListenerPortAndUsesOnlyMatchingCookie(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('session-cookie-name-test');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', 'shop.test');
        Context::current()->set('input.cookie', ['WELINE_SESSID' => str_repeat('a', 32)]);

        self::assertSame('shop.test:9502', SessionCookieNameResolver::currentHost());
        self::assertSame('WELINE_SESSID_9502', SessionCookieNameResolver::resolve());
        self::assertFalse(SessionCookieNameResolver::hasRequestCookie());

        Context::current()->set('input.cookie', [
            'WELINE_SESSID' => str_repeat('a', 32),
            'WELINE_SESSID_9502' => str_repeat('b', 32),
        ]);
        self::assertTrue(SessionCookieNameResolver::hasRequestCookie());
    }

    public function testExplicitAuthorityPortWinsOverDifferentListenerPort(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('session-cookie-authority-test');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9503');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', 'shop.test');

        self::assertSame('shop.test:9503', SessionCookieNameResolver::currentHost());
        self::assertSame('WELINE_SESSID_9503', SessionCookieNameResolver::resolve());
    }
}
