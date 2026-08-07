<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Websites\Observer\CookieScopeResolve;

final class CookieScopeResolveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CookieScope::setPolicyResolverOverride(static function (): array {
            $data = [
                'active' => false,
                'name_suffix' => '',
                'name_suffix_pattern' => '',
                'mount_path' => '/',
                'expire_unscoped_aliases' => false,
                'revision' => '',
            ];
            $event = new Event($data);
            (new CookieScopeResolve())->execute($event);
            $modified = $event->getEvenData();
            if (\is_array($modified)) {
                foreach ($modified as $key => $value) {
                    $data[$key] = $value;
                }
            }

            return $data;
        });
    }

    protected function tearDown(): void
    {
        CookieScope::setPolicyResolverOverride(null);
        HeaderCollector::reset();
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testObserverMapsWebsiteMountOntoFrameworkNeutralFields(): void
    {
        $this->enterRequest(257, 'https://shop.test/aisite_accept_ok');

        $event = new Event([
            'active' => false,
            'name_suffix' => '',
            'name_suffix_pattern' => '',
            'mount_path' => '/',
            'expire_unscoped_aliases' => false,
            'revision' => '',
        ]);
        (new CookieScopeResolve())->execute($event);

        self::assertTrue((bool)$event->getData('active'));
        self::assertSame('_w257', (string)$event->getData('name_suffix'));
        self::assertSame('/_w\d+$/', (string)$event->getData('name_suffix_pattern'));
        self::assertSame('/aisite_accept_ok', (string)$event->getData('mount_path'));
        self::assertTrue((bool)$event->getData('expire_unscoped_aliases'));
        self::assertSame('257|https://shop.test/aisite_accept_ok', (string)$event->getData('revision'));
    }

    public function testDispatchQualifiesSessionCookieForPathMountedWebsite(): void
    {
        $this->enterRequest(257, 'https://shop.test/aisite_accept_ok');

        self::assertSame('/aisite_accept_ok', CookieScope::resolvePath('/'));
        self::assertSame('WELINE_SESSID_w257', CookieScope::qualifyName('WELINE_SESSID'));
        self::assertSame('WELINE_SESSID_9502_w257', SessionCookieNameResolver::resolve('shop.test:9502'));
        self::assertTrue(CookieScope::shouldExpireUnscopedAliases());
    }

    public function testRootWebsiteKeepsRootPathButStillQualifiesName(): void
    {
        $this->enterRequest(251, 'https://shop.test/');

        self::assertSame('/', CookieScope::resolvePath('/'));
        self::assertSame('WELINE_SESSID_w251', SessionCookieNameResolver::resolve('shop.test'));
    }

    public function testHeaderCollectorEmitsIsolatedCookieAndKeepsProtocolExact(): void
    {
        $this->enterRequest(257, 'https://shop.test/aisite_accept_ok');
        $collector = HeaderCollector::getInstance();
        $collector->setCookie('WELINE_SESSID', 'abc123', 0, '/', '', true, true, 'Lax');

        $byName = [];
        foreach ($collector->getCookies() as $cookie) {
            $byName[(string)$cookie['name']] = $cookie;
        }
        self::assertArrayHasKey('WELINE_SESSID_w257', $byName);
        self::assertSame('/aisite_accept_ok', $byName['WELINE_SESSID_w257']['path']);
        self::assertArrayHasKey('WELINE_SESSID', $byName);
        self::assertSame('', $byName['WELINE_SESSID']['value']);

        HeaderCollector::reset();
        $hostName = '__Host-Weline-Worker-Backend-Bootstrap-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcde';
        $collector = HeaderCollector::getInstance();
        $collector->setCookie($hostName, 'token', \time() + 60, '/', '', true, true, 'Strict');
        $cookies = \array_values($collector->getCookies());
        self::assertCount(1, $cookies);
        self::assertSame($hostName, $cookies[0]['name']);
        self::assertSame('/', $cookies[0]['path']);
    }

    private function enterRequest(int $websiteId, string $websiteUrl): void
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
        WelineEnv::set('is_backend', false, 'CookieScopeResolveTest');
        WelineEnv::set('area', 'frontend', 'CookieScopeResolveTest');
        Context::current()->set('input.server.HTTP_HOST', 'shop.test');
        Context::current()->set('input.host', 'shop.test');
    }
}
