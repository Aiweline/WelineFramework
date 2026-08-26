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
            "Host: 127.0.0.1\r\n"
            . "X-Forwarded-Host: 127.0.0.1\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n",
            [
                'WLS_PORT' => 3999,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
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
            "Host: 127.0.0.1\r\n"
            . "X-Forwarded-Host: 127.0.0.1\r\n"
            . "X-Forwarded-Proto: http\r\n"
            . "X-Forwarded-Port: 8088\r\n",
            [
                'WLS_PORT' => 3999,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
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
            . "Weline-Original-Ssl: on\r\n",
            [
                'WLS_PORT' => 3999,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('127.0.0.1:8443', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('8443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://127.0.0.1:8443/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('https://127.0.0.1:8443', $request->getBaseHost());
    }

    public function testDispatcherRestoresPublicDefaultHttpsAuthorityInsteadOfInternalWorkerPort(): void
    {
        $request = $this->createRequest(
            "Host: app.example.com:23922\r\n"
            . "Weline-Via-Dispatcher: 1\r\n"
            . "Weline-Original-Host: app.example.com\r\n"
            . "Weline-Original-Scheme: https\r\n"
            . "Weline-Original-Port: 443\r\n"
            . "Weline-Original-Ssl: on\r\n",
            [
                'WLS_PORT' => 23922,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('app.example.com', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://app.example.com/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('https://app.example.com', $request->getBaseHost());
    }

    public function testDirectListenPortFillsHostWhenAuthorityOmitsNonStandardPort(): void
    {
        $request = $this->createRequest(
            "Host: p05113ef3.weline.test\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 9555,
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('p05113ef3.weline.test:9555', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('9555', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame(
            'https://p05113ef3.weline.test:9555/customer/account/logout',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null,
        );
    }

    public function testGlobalsEmulatorKeepsDispatcherPublicAuthoritySnapshot(): void
    {
        $request = $this->createRequest(
            "Host: app.example.com:23922\r\n"
            . "Weline-Via-Dispatcher: 1\r\n"
            . "Weline-Original-Host: app.example.com\r\n"
            . "Weline-Original-Scheme: https\r\n"
            . "Weline-Original-Port: 443\r\n"
            . "Weline-Original-Ssl: on\r\n",
            [
                'WLS_PORT' => 23922,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        $snapshot = $request->getParsedServerSnapshot();
        self::assertSame('app.example.com', $snapshot['HTTP_HOST'] ?? null);
        self::assertSame('443', $snapshot['SERVER_PORT'] ?? null);

        $emulator = new \Weline\Framework\Runtime\GlobalsEmulator();
        try {
            $emulator->emulate($request);
            self::assertSame('app.example.com', $_SERVER['HTTP_HOST'] ?? null);
            self::assertSame('app.example.com', $_SERVER['SERVER_NAME'] ?? null);
            self::assertSame('443', $_SERVER['SERVER_PORT'] ?? null);
            self::assertSame('https', $_SERVER['REQUEST_SCHEME'] ?? null);
            self::assertSame('https://app.example.com/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        } finally {
            $emulator->reset();
        }
    }

    public function testTrustedLocalClientWithoutForwardedHeadersUsesListenPort(): void
    {
        $request = $this->createRequest(
            "Host: p05113ef3.weline.test\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 9555,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('p05113ef3.weline.test:9555', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('9555', $_SERVER['SERVER_PORT'] ?? null);
    }

    public function testTrustedProxyKeepsDefaultHttpsPortInsteadOfInternalWorkerPort(): void
    {
        $request = $this->createRequest(
            "Host: shop.example.test\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 10001,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('shop.example.test', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://shop.example.test/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
    }

    public function testExplicitHttpSchemeIsNotPromotedByEnvHttps(): void
    {
        $request = $this->createRequest(
            "Host: 127.0.0.1:9555\r\n",
            [
                'HTTPS' => '',
                'REQUEST_SCHEME' => 'http',
                'WLS_PORT' => 9555,
            ],
        );

        self::assertFalse($request->isSecure());
        self::assertSame('http', $_SERVER['REQUEST_SCHEME'] ?? null);
        self::assertSame('127.0.0.1:9555', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame(
            'http://127.0.0.1:9555/customer/account/logout',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null,
        );
        self::assertSame('http://127.0.0.1:9555', $request->getBaseHost());
    }

    /**
     * @param array<string, mixed> $serverInfo
     */
    private function createRequest(string $headers, array $serverInfo = []): WlsRequest
    {
        $rawRequest = "GET /customer/account/logout HTTP/1.1\r\n"
            . $headers
            . "Accept: text/html\r\n"
            . "\r\n";

        return WlsRequest::fromRaw($rawRequest, $serverInfo);
    }
}
