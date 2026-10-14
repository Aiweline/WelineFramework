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

    public function testTokenSessionBindsSessionFactoryNotBareObjectManager(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Security/Token.php',
        );

        self::assertStringContainsString('SessionFactory', $source);
        self::assertStringContainsString('createSession($area)', $source);
        self::assertStringContainsString('$session->save()', $source);
        // session() 主体禁止再绑裸 OM Session（回退分支除外）
        self::assertMatchesRegularExpression(
            '/public static function session\(\): Session\s*\{(?P<body>.*?)\n    \}/s',
            $source,
        );
        \preg_match(
            '/public static function session\(\): Session\s*\{(?P<body>.*?)\n    \}/s',
            $source,
            $m,
        );
        $body = (string)($m['body'] ?? '');
        self::assertStringContainsString('createSession($area)', $body);
        self::assertStringContainsString('SessionFactory', $body);
    }
}
