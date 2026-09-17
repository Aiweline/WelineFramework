<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionCookieNameResolver;

final class SessionCookieNameResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        CookieScope::setPolicyResolverOverride(null);
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
        self::assertSame(
            'WELINE_SESSID_9502',
            SessionCookieNameResolver::resolveUnscopedFor(SessionCookieNameResolver::LEGACY_NAME),
        );
        // Legacy jar entries remain readable so CookieScope/QueryBin migrations
        // do not invent a second empty session.
        self::assertTrue(SessionCookieNameResolver::hasRequestCookie('backend'));
        self::assertSame(str_repeat('a', 32), SessionCookieNameResolver::readRequestSessionId(null, 'backend'));

        Context::current()->set('input.cookie', [
            'WELINE_SESSID' => str_repeat('a', 32),
            'WELINE_SESSID_9502' => str_repeat('b', 32),
        ]);
        self::assertTrue(SessionCookieNameResolver::hasRequestCookie('backend'));
        self::assertSame(str_repeat('b', 32), SessionCookieNameResolver::readRequestSessionId(null, 'backend'));
    }

    public function testReadRequestSessionIdPrefersActiveScopeThenFallsBackToUnscopedAlias(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'test',
        ]);
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('session-cookie-alias-fallback');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9502');
        Context::current()->set('input.host', 'shop.test:9502');
        Context::current()->set('input.cookie', [
            'WELINE_SESSID_9502' => str_repeat('u', 32),
        ]);

        self::assertSame('WELINE_SESSID_9502_w0', SessionCookieNameResolver::resolveFor(
            SessionCookieNameResolver::LEGACY_NAME,
        ));
        self::assertContains('WELINE_SESSID_9502', SessionCookieNameResolver::requestCookieCandidates(null, 'backend'));
        self::assertSame(str_repeat('u', 32), SessionCookieNameResolver::readRequestSessionId(null, 'backend'));

        Context::current()->set('input.cookie', [
            'WELINE_SESSID_9502' => str_repeat('u', 32),
            'WELINE_SESSID_9502_w0' => str_repeat('s', 32),
        ]);
        self::assertSame(str_repeat('s', 32), SessionCookieNameResolver::readRequestSessionId(null, 'backend'));
    }

    public function testTrustedProxyHttpsPortWinsOverInternalWlsWorkerPort(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('session-cookie-trusted-proxy-test');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test');
        Context::current()->set('input.server.SERVER_PORT', 443);
        Context::current()->set('input.server.WLS_PORT', 23922);
        Context::current()->set('input.host', 'shop.test');

        self::assertSame('shop.test', SessionCookieNameResolver::currentHost());
        self::assertSame(
            'WELINE_SESSID',
            SessionCookieNameResolver::resolveUnscopedFor(SessionCookieNameResolver::LEGACY_NAME),
        );
    }

    public function testExplicitAuthorityPortWinsOverDifferentListenerPort(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('session-cookie-authority-test');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9503');
        Context::current()->set('input.server.SERVER_PORT', 9502);
        Context::current()->set('input.host', 'shop.test');

        self::assertSame('shop.test:9503', SessionCookieNameResolver::currentHost());
        self::assertSame(
            'WELINE_SESSID_9503',
            SessionCookieNameResolver::resolveUnscopedFor(SessionCookieNameResolver::LEGACY_NAME),
        );
    }

    public function testCustomerAreaUsesIsolatedCookieFamily(): void
    {
        self::assertSame(
            'WELINE_CUSTOMER_SESSID',
            SessionCookieNameResolver::legacyNameForArea('frontend'),
        );
        self::assertSame(
            'WELINE_SESSID',
            SessionCookieNameResolver::legacyNameForArea('backend'),
        );
        self::assertSame(
            'WELINE_CUSTOMER_SESSID_9502',
            SessionCookieNameResolver::resolveUnscopedFor(
                SessionCookieNameResolver::CUSTOMER_NAME,
                'shop.test:9502',
            ),
        );
        self::assertSame(
            'WELINE_CUSTOMER_SESSID_9502',
            SessionCookieNameResolver::resolve('shop.test:9502', 'frontend'),
        );
        self::assertSame(
            'WELINE_SESSID_9502',
            SessionCookieNameResolver::resolve('shop.test:9502', 'backend'),
        );

        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('customer-cookie-family');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9502');
        Context::current()->set('input.host', 'shop.test:9502');
        Context::current()->set('input.cookie', [
            'WELINE_SESSID_9502' => str_repeat('a', 32),
            'WELINE_CUSTOMER_SESSID_9502' => str_repeat('c', 32),
        ]);

        self::assertSame(
            str_repeat('c', 32),
            SessionCookieNameResolver::readRequestSessionId(null, 'frontend'),
        );
        self::assertSame(
            str_repeat('a', 32),
            SessionCookieNameResolver::readRequestSessionId(null, 'backend'),
        );
        self::assertNotContains(
            'WELINE_SESSID_9502',
            SessionCookieNameResolver::requestCookieCandidates(null, 'frontend'),
        );
    }

    public function testUnscopedNameRemainsAvailableWhenWebsitePolicyQualifiesActiveCookie(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'test',
        ]);

        self::assertSame('WELINE_SESSID_9502_w0', SessionCookieNameResolver::resolveFor(
            SessionCookieNameResolver::LEGACY_NAME,
            'shop.test:9502',
        ));
        self::assertSame(
            'WELINE_SESSID_9502',
            SessionCookieNameResolver::resolveUnscopedFor(SessionCookieNameResolver::LEGACY_NAME, 'shop.test:9502'),
        );
    }
}
