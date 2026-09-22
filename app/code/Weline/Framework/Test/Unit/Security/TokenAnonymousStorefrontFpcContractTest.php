<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Security\Token;
use Weline\Framework\Session\SessionCookieNameResolver;

/**
 * Cookieless storefront GET must not allocate Session CSRF cookies — FPC refuse gate.
 */
final class TokenAnonymousStorefrontFpcContractTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
        WelineEnv::set('area', 'frontend', 'unit test');
        WelineEnv::setServer('REQUEST_METHOD', 'GET', 'unit test');
        WelineEnv::setServer('HTTP_COOKIE', '', 'unit test');
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        parent::tearDown();
    }

    public function testMayPersistInSessionFalseWithoutFrontendCookie(): void
    {
        self::assertFalse(SessionCookieNameResolver::hasRequestCookie('frontend'));
        self::assertFalse(Token::mayPersistInSession());
    }

    public function testCreateReturnsEmptyWithoutAllocatingSetCookie(): void
    {
        self::assertSame('', Token::create('csrf', 9, 3600));
        self::assertNull(Token::get('csrf'));

        $cookies = HeaderCollector::getInstance()->getCookies();
        self::assertSame([], $cookies);
    }

    public function testBackendAreaStillMayPersist(): void
    {
        WelineEnv::set('area', 'backend', 'unit test');
        self::assertTrue(Token::mayPersistInSession());
    }
}
