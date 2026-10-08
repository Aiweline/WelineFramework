<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\HeaderCollector;

final class HeaderCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
        CookieScope::setPolicyResolverOverride(null);
        CookieScope::resetRequestState();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        CookieScope::setPolicyResolverOverride(null);
        CookieScope::resetRequestState();
    }

    public function testStatusCodeIsOnlyMarkedExplicitAfterOverride(): void
    {
        $collector = HeaderCollector::getInstance();

        self::assertSame(200, $collector->getStatusCode());
        self::assertFalse($collector->hasExplicitStatusCode());

        $collector->setStatusCode(200);

        self::assertSame(200, $collector->getStatusCode());
        self::assertTrue($collector->hasExplicitStatusCode());
    }

    public function testResetClearsExplicitStatusOverrideFlag(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setStatusCode(401);

        HeaderCollector::reset();

        $resetCollector = HeaderCollector::getInstance();
        self::assertSame(200, $resetCollector->getStatusCode());
        self::assertFalse($resetCollector->hasExplicitStatusCode());
    }

    public function testCrossWebsiteScopedExpireKeepsExactNameAndDoesNotWipeActiveJar(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w544',
            'name_suffix_pattern' => '/_w\d+$/D',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'cross-website-expire',
        ]);

        $collector = HeaderCollector::getInstance();
        // Assert active grocery jar first.
        $collector->setCookie('WELINE_SESSID', 'active-sid', \time() + 3600, '/', '', true, true, 'Lax');
        // clearCookie / expireSibling may pass the admin jar name while scope is _w544.
        $collector->setCookie('WELINE_SESSID_w0', '', \time() - 42000, '/', '', true, true, 'Lax');

        $byName = [];
        foreach ($collector->getCookies() as $cookie) {
            $byName[(string)$cookie['name']] = $cookie;
        }

        self::assertArrayHasKey('WELINE_SESSID_w544', $byName);
        self::assertSame('active-sid', (string)$byName['WELINE_SESSID_w544']['value']);
        self::assertArrayHasKey('WELINE_SESSID_w0', $byName);
        self::assertSame('', (string)$byName['WELINE_SESSID_w0']['value']);
        self::assertLessThan(\time(), (int)$byName['WELINE_SESSID_w0']['expire']);
    }

    public function testQualifyFromUnscopedStillExpiresBareAliasesOnly(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w0',
            'name_suffix_pattern' => '/_w\d+$/D',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'unscoped-legacy',
        ]);

        $collector = HeaderCollector::getInstance();
        $collector->setCookie('WELINE_SESSID', 'sid', \time() + 3600, '/', '', true, true, 'Lax');

        $byName = [];
        foreach ($collector->getCookies() as $cookie) {
            $byName[(string)$cookie['name']] = $cookie;
        }
        self::assertArrayHasKey('WELINE_SESSID_w0', $byName);
        self::assertSame('sid', (string)$byName['WELINE_SESSID_w0']['value']);
        self::assertArrayHasKey('WELINE_SESSID', $byName);
        self::assertSame('', (string)$byName['WELINE_SESSID']['value']);
        self::assertArrayNotHasKey('WELINE_SESSID_w544', $byName);
    }
}
