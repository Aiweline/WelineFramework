<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Session\SessionIdResolver;

final class SessionIdResolverTest extends TestCase
{
    private array $serverBackup = [];
    private array $cookieBackup = [];
    private ?Context $contextBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->cookieBackup = $_COOKIE;
        $this->contextBackup = Context::getCurrent();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_COOKIE = $this->cookieBackup;
        Context::leave();
        if ($this->contextBackup !== null) {
            Context::enter($this->contextBackup);
        }
        WelineEnv::getInstance()->reset();
        parent::tearDown();
    }

    public function testContextSessionIdWinsOverCookieHeader(): void
    {
        $_SERVER = ['HTTP_COOKIE' => 'WELINE_SESSID=from-header'];
        $_COOKIE = ['WELINE_SESSID' => 'from-cookie'];
        Context::enter(new Context(['session' => ['id' => 'from-context']]));

        self::assertSame('from-context', SessionIdResolver::fromCurrentRequest());
    }

    public function testFallsBackToCookieHeaderWhenContextHasNoSession(): void
    {
        $_SERVER = ['HTTP_COOKIE' => 'other=1; WELINE_SESSID=from-header; tail=2'];
        $_COOKIE = [];
        Context::enter(new Context(['session' => ['id' => '']]));

        self::assertSame('from-header', SessionIdResolver::fromCurrentRequest());
    }

    public function testFallsBackToCookieBagWhenHeaderMissing(): void
    {
        $_SERVER = [];
        $_COOKIE = ['WELINE_SESSID' => 'from-cookie'];
        Context::leave();

        self::assertSame('from-cookie', SessionIdResolver::fromCurrentRequest());
    }
}
