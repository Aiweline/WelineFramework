<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Security\Token;
use Weline\Framework\Session\SessionCookieNameResolver;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSecurityContractsPass().
 */
final class SecurityContractsTest extends TestCase
{
    public function testAllSecurityContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'TokenAnonymousStorefrontFpc::testMayPersistInSessionFalseWithoutFrontendCookie' => function () {
            $this->setUp_TokenAnonymousStorefrontFpc();
            try {
                $this->assert_TokenAnonymousStorefrontFpc_testMayPersistInSessionFalseWithoutFrontendCookie();
            } finally {
                $this->tearDown_TokenAnonymousStorefrontFpc();
            }
        },
            'TokenAnonymousStorefrontFpc::testCreateReturnsEmptyWithoutAllocatingSetCookie' => function () {
            $this->setUp_TokenAnonymousStorefrontFpc();
            try {
                $this->assert_TokenAnonymousStorefrontFpc_testCreateReturnsEmptyWithoutAllocatingSetCookie();
            } finally {
                $this->tearDown_TokenAnonymousStorefrontFpc();
            }
        },
            'TokenAnonymousStorefrontFpc::testBackendAreaStillMayPersist' => function () {
            $this->setUp_TokenAnonymousStorefrontFpc();
            try {
                $this->assert_TokenAnonymousStorefrontFpc_testBackendAreaStillMayPersist();
            } finally {
                $this->tearDown_TokenAnonymousStorefrontFpc();
            }
        },
            'TokenAnonymousStorefrontFpc::testTokenSessionBindsSessionFactoryNotBareObjectManager' => function () {
            $this->setUp_TokenAnonymousStorefrontFpc();
            try {
                $this->assert_TokenAnonymousStorefrontFpc_testTokenSessionBindsSessionFactoryNotBareObjectManager();
            } finally {
                $this->tearDown_TokenAnonymousStorefrontFpc();
            }
        },
        ];
    }

private function setUp_TokenAnonymousStorefrontFpc(): void
{
    HeaderCollector::reset();
    WelineEnv::set('area', 'frontend', 'unit test');
    WelineEnv::setServer('REQUEST_METHOD', 'GET', 'unit test');
    WelineEnv::setServer('HTTP_COOKIE', '', 'unit test');
}

private function tearDown_TokenAnonymousStorefrontFpc(): void
{
    HeaderCollector::reset();
    parent::tearDown();
}

private function assert_TokenAnonymousStorefrontFpc_testMayPersistInSessionFalseWithoutFrontendCookie(): void
{
    self::assertFalse(SessionCookieNameResolver::hasRequestCookie('frontend'));
    self::assertFalse(Token::mayPersistInSession());
}

private function assert_TokenAnonymousStorefrontFpc_testCreateReturnsEmptyWithoutAllocatingSetCookie(): void
{
    self::assertSame('', Token::create('csrf', 9, 3600));
    self::assertNull(Token::get('csrf'));
    $cookies = HeaderCollector::getInstance()->getCookies();
    self::assertSame([], $cookies);
}

private function assert_TokenAnonymousStorefrontFpc_testBackendAreaStillMayPersist(): void
{
    WelineEnv::set('area', 'backend', 'unit test');
    self::assertTrue(Token::mayPersistInSession());
}

private function assert_TokenAnonymousStorefrontFpc_testTokenSessionBindsSessionFactoryNotBareObjectManager(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Security/Token.php');
    self::assertStringContainsString('SessionFactory', $source);
    self::assertStringContainsString('createSession($area)', $source);
    self::assertStringContainsString('$session->save()', $source);
    // session() 主体禁止再绑裸 OM Session（回退分支除外）
    self::assertMatchesRegularExpression('/public static function session\(\): Session\s*\{(?P<body>.*?)\n    \}/s', $source);
    \preg_match('/public static function session\(\): Session\s*\{(?P<body>.*?)\n    \}/s', $source, $m);
    $body = (string) ($m['body'] ?? '');
    self::assertStringContainsString('createSession($area)', $body);
    self::assertStringContainsString('SessionFactory', $body);
}
}
