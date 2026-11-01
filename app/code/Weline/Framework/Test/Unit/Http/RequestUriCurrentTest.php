<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\RequestUri;

/**
 * 当前请求 URI 的唯一读取口：先读 Context，没有 Context 才回落 $_SERVER。
 */
final class RequestUriCurrentTest extends TestCase
{
    private array $serverBackup = [];
    private ?Context $contextBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->contextBackup = Context::getCurrent();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Context::leave();
        if ($this->contextBackup !== null) {
            Context::enter($this->contextBackup);
        }
        parent::tearDown();
    }

    public function testContextUriWinsOverSuperglobal(): void
    {
        $_SERVER = ['REQUEST_URI' => '/from-server?x=1'];
        Context::enter(Context::fromGlobals());

        self::assertSame('/from-server?x=1', RequestUri::current());

        Context::current()->set('input.uri', '/from-context?y=2');

        self::assertSame('/from-context?y=2', RequestUri::current());
    }

    public function testFallsBackToSuperglobalWithoutContext(): void
    {
        $_SERVER = ['REQUEST_URI' => '/from-server?x=1'];
        Context::leave();

        self::assertSame('/from-server?x=1', RequestUri::current());
    }

    public function testReturnsEmptyWhenNothingKnown(): void
    {
        $_SERVER = [];
        Context::leave();

        self::assertSame('', RequestUri::current());
    }

    public function testPathStripsQueryString(): void
    {
        $_SERVER = ['REQUEST_URI' => '/a/b?x=1'];
        Context::enter(Context::fromGlobals());

        self::assertSame('/a/b', RequestUri::path());
    }
}
