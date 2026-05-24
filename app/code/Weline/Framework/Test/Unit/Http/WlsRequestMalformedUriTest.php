<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

final class WlsRequestMalformedUriTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        RequestContext::cleanup();
        WelineEnv::getInstance()->reset();
        Runtime::resetModeCache();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testMalformedRequestUriDoesNotThrowValueError(): void
    {
        $request = WlsRequest::fromRaw(
            "GET https://127.0.0.1:bad/catalog HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n",
            ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'https']
        );

        self::assertSame('/', $request->getUri());
        self::assertSame('127.0.0.1', $_SERVER['HTTP_HOST'] ?? null);
    }

    public function testMalformedHostIsRejectedBeforeRouting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Host header value.');

        WlsRequest::fromRaw(
            "GET /catalog HTTP/1.1\r\nHost: 127.0.0.1]\r\nConnection: close\r\n\r\n",
            ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'https']
        );
    }
}
