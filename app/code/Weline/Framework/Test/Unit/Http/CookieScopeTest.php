<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Http\HeaderCollector;

/**
 * Framework CookieScope keeps protocol cookies exact and stays identity without
 * module observers. Website isolation lives in Weline_Websites.
 */
final class CookieScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        HeaderCollector::reset();
        parent::tearDown();
    }

    public function testProtocolHostCookieIsNotQualified(): void
    {
        $cookieName = '__Host-Weline-Worker-Backend-Bootstrap-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcde';
        self::assertTrue(CookieScope::isProtocolCookie($cookieName));
        self::assertSame($cookieName, CookieScope::qualifyName($cookieName));
    }

    public function testHeaderCollectorKeepsProtocolCookieRootPath(): void
    {
        $cookieName = '__Host-Weline-Worker-Backend-Bootstrap-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcde';
        $collector = HeaderCollector::getInstance();
        $collector->setCookie($cookieName, 'token-value', \time() + 60, '/', '', true, true, 'Strict');

        $cookies = \array_values($collector->getCookies());
        self::assertCount(1, $cookies);
        self::assertSame($cookieName, $cookies[0]['name']);
        self::assertSame('/', $cookies[0]['path']);
        self::assertSame('', $cookies[0]['domain']);
        self::assertTrue($cookies[0]['secure']);
    }

    public function testDevWorkerBootstrapCookieIsProtocolExempt(): void
    {
        $cookieName = 'Weline-Worker-Backend-Bootstrap-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcde';
        self::assertTrue(CookieScope::isProtocolCookie($cookieName));

        $collector = HeaderCollector::getInstance();
        $collector->setCookie($cookieName, 'dev-token', \time() + 60, '/', '', false, true, 'Strict');
        $cookies = \array_values($collector->getCookies());
        self::assertCount(1, $cookies);
        self::assertSame($cookieName, $cookies[0]['name']);
        self::assertSame('/', $cookies[0]['path']);
    }
}
