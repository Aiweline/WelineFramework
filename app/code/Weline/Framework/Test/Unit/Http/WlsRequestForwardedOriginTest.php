<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\WlsRequest;

final class WlsRequestForwardedOriginTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $cookieBackup = [];
    private array $requestBackup = [];
    private array $filesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $this->requestBackup = $_REQUEST;
        $this->filesBackup = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;
        parent::tearDown();
    }

    public function testForwardedHeadersOmitDefaultHttpsPort(): void
    {
        $request = $this->createRequest(
            "Host: 127.0.0.1:3999\r\n"
            . "X-Forwarded-Host: 127.0.0.1\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n"
        );

        self::assertTrue($request->isSecure());
        self::assertSame('127.0.0.1', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://127.0.0.1/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('https://127.0.0.1', $request->getBaseHost());
    }

    public function testForwardedHeadersPreserveNonDefaultPort(): void
    {
        $request = $this->createRequest(
            "Host: 127.0.0.1:3999\r\n"
            . "X-Forwarded-Host: 127.0.0.1\r\n"
            . "X-Forwarded-Proto: http\r\n"
            . "X-Forwarded-Port: 8088\r\n"
        );

        self::assertFalse($request->isSecure());
        self::assertSame('127.0.0.1:8088', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('8088', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('http://127.0.0.1:8088/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('http://127.0.0.1:8088', $request->getBaseHost());
    }

    public function testDispatcherHeadersPreserveNonDefaultHttpsPort(): void
    {
        $request = $this->createRequest(
            "Host: 127.0.0.1:3999\r\n"
            . "Weline-Via-Dispatcher: 1\r\n"
            . "Weline-Original-Host: 127.0.0.1\r\n"
            . "Weline-Original-Scheme: https\r\n"
            . "Weline-Original-Port: 8443\r\n"
            . "Weline-Original-Ssl: on\r\n"
        );

        self::assertTrue($request->isSecure());
        self::assertSame('127.0.0.1:8443', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('8443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://127.0.0.1:8443/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('https://127.0.0.1:8443', $request->getBaseHost());
    }

    private function createRequest(string $headers): WlsRequest
    {
        $rawRequest = "GET /customer/account/logout HTTP/1.1\r\n"
            . $headers
            . "Accept: text/html\r\n"
            . "\r\n";

        return WlsRequest::fromRaw($rawRequest);
    }
}
