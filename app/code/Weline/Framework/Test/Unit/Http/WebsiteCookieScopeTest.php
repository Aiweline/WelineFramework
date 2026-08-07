<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\WebsiteCookieScope;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionCookieNameResolver;

final class WebsiteCookieScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testPathMountedWebsiteQualifiesNameAndPath(): void
    {
        $this->enterStorefront(257, 'https://shop.test/aisite_accept_ok');

        self::assertTrue(WebsiteCookieScope::isStorefrontIsolationActive());
        self::assertSame('/aisite_accept_ok', WebsiteCookieScope::path());
        self::assertSame('/aisite_accept_ok', WebsiteCookieScope::resolvePath('/'));
        self::assertSame('WELINE_SESSID_w257', WebsiteCookieScope::qualifyName('WELINE_SESSID'));
        self::assertSame('WELINE_SESSID_9502_w257', SessionCookieNameResolver::resolve('shop.test:9502'));
        self::assertSame('/aisite_accept_ok', SessionCookieNameResolver::resolvePath('/'));
    }

    public function testRootWebsiteKeepsRootPathButStillQualifiesName(): void
    {
        $this->enterStorefront(251, 'https://shop.test/');

        self::assertSame('/', WebsiteCookieScope::path());
        self::assertSame('WELINE_SESSID_w251', SessionCookieNameResolver::resolve('shop.test'));
    }

    public function testSiblingWebsitesDoNotShareCookieNames(): void
    {
        $this->enterStorefront(10, 'https://shop.test/a');
        $nameA = SessionCookieNameResolver::resolve('shop.test');

        $this->enterStorefront(20, 'https://shop.test/b');
        $nameB = SessionCookieNameResolver::resolve('shop.test');

        self::assertSame('WELINE_SESSID_w10', $nameA);
        self::assertSame('WELINE_SESSID_w20', $nameB);
        self::assertNotSame($nameA, $nameB);
    }

    public function testBackendQualifiesNameAndPathLikeStorefront(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('cookie-scope-backend');
        RequestContext::setWelineWebsiteId(257);
        RequestContext::setWelineWebsiteUrl('https://shop.test/aisite_accept_ok');
        WelineEnv::set('is_backend', true, 'WebsiteCookieScopeTest');
        WelineEnv::set('area', 'backend', 'WebsiteCookieScopeTest');

        self::assertTrue(WebsiteCookieScope::isIsolationActive());
        self::assertSame('/aisite_accept_ok', WebsiteCookieScope::resolvePath('/'));
        self::assertSame('WELINE_SESSID_w257', WebsiteCookieScope::qualifyName('WELINE_SESSID'));
        self::assertSame('WELINE_SESSID_9502_w257', SessionCookieNameResolver::resolve('shop.test:9502'));
    }

    public function testHeaderCollectorEmitsIsolatedStorefrontCookie(): void
    {
        $this->enterStorefront(257, 'https://shop.test/aisite_accept_ok');
        $collector = HeaderCollector::getInstance();
        $collector->setCookie('WELINE_SESSID', 'abc123', 0, '/', '', true, true, 'Lax');

        $cookies = \array_values($collector->getCookies());
        self::assertCount(2, $cookies);
        $byName = [];
        foreach ($cookies as $cookie) {
            $byName[(string)$cookie['name']] = $cookie;
        }
        self::assertArrayHasKey('WELINE_SESSID_w257', $byName);
        self::assertSame('/aisite_accept_ok', $byName['WELINE_SESSID_w257']['path']);
        self::assertSame('abc123', $byName['WELINE_SESSID_w257']['value']);
        self::assertArrayHasKey('WELINE_SESSID', $byName);
        self::assertSame('/', $byName['WELINE_SESSID']['path']);
        self::assertSame('', $byName['WELINE_SESSID']['value']);
        self::assertLessThan(\time(), (int)$byName['WELINE_SESSID']['expire']);
    }

    public function testHeaderCollectorExpiresPortQualifiedLegacySessionAlias(): void
    {
        $this->enterStorefront(257, 'https://shop.test/aisite_accept_ok');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test:9502');
        Context::current()->set('input.server.SERVER_PORT', 9502);

        $collector = HeaderCollector::getInstance();
        $collector->setCookie('WELINE_SESSID_9502_w257', 'abc123', 0, '/', '', true, true, 'Lax');

        $byName = [];
        foreach ($collector->getCookies() as $cookie) {
            $byName[(string)$cookie['name']] = $cookie;
        }
        self::assertArrayHasKey('WELINE_SESSID_9502_w257', $byName);
        self::assertSame('abc123', $byName['WELINE_SESSID_9502_w257']['value']);
        self::assertArrayHasKey('WELINE_SESSID_9502', $byName);
        self::assertSame('', $byName['WELINE_SESSID_9502']['value']);
        self::assertArrayHasKey('WELINE_SESSID', $byName);
        self::assertSame('', $byName['WELINE_SESSID']['value']);
    }

    private function enterStorefront(int $websiteId, string $websiteUrl): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        HeaderCollector::reset();

        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('cookie-scope-' . $websiteId);
        RequestContext::setWelineWebsiteId($websiteId);
        RequestContext::setWelineWebsiteUrl($websiteUrl);
        WelineEnv::set('is_backend', false, 'WebsiteCookieScopeTest');
        WelineEnv::set('area', 'frontend', 'WebsiteCookieScopeTest');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test');
        Context::current()->set('input.host', 'shop.test');
    }
}
