<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;

/**
 * TEST-SEC-08 子集：HTTPS 下 Cookie 强制 Secure。
 */
final class HeaderCollectorHttpsCookieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HeaderCollector::reset();
        $_SERVER['HTTPS'] = 'on';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        HeaderCollector::reset();
        parent::tearDown();
    }

    public function testHttpsForcesSecureCookieFlag(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setCookie('sess', 'abc', 0, '/', '', false, true, 'Lax');
        $cookies = \array_values($collector->getCookies());
        self::assertNotEmpty($cookies);
        self::assertTrue((bool)$cookies[0]['secure']);
        self::assertTrue((bool)$cookies[0]['httpOnly']);
        self::assertSame('Lax', $cookies[0]['sameSite']);
    }
}
