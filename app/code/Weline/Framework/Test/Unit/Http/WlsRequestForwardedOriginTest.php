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
            "Host: p05113ef3.test.weline.com\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 9555,
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('p05113ef3.test.weline.com:9555', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('9555', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame(
            'https://p05113ef3.test.weline.com:9555/customer/account/logout',
            $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null,
        );
    }

    public function testUntrustedForwardedPortIsIgnoredSoDirectWlsKeepsListenPort(): void
    {
        // Client-spoofed X-Forwarded-Port must not rewrite public origin when
        // the transport peer is not a trusted proxy. Direct WLS keeps WLS_PORT.
        $request = $this->createRequest(
            "Host: p05113ef3.test.weline.com\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 9555,
                'WLS_TRUST_FORWARDED_HEADERS' => '0',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('p05113ef3.test.weline.com:9555', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('9555', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://p05113ef3.test.weline.com:9555', $request->getBaseHost());
    }

    public function testWebsiteUrlPublicOriginWinsOverWorkerListenAuthority(): void
    {
        // Nginx→Worker cleartext H1 can leave REQUEST_SCHEME=http and Host:…:9555
        // while Url::parser already bound the storefront public https origin.
        $prevWlsPort = \getenv('WLS_PORT');
        \putenv('WLS_PORT=9555');
        try {
            $request = $this->createRequest(
                "Host: p05113ef3.test.weline.com:9555\r\n",
                [
                    'HTTPS' => '',
                    'REQUEST_SCHEME' => 'http',
                    'WLS_PORT' => 9555,
                    'WLS_TRUST_FORWARDED_HEADERS' => '0',
                ],
            );
            \Weline\Framework\Env\WelineEnv::getInstance()->initFromSnapshot(
                [],
                [],
                [],
                [],
                [
                    'WELINE_WEBSITE_URL' => 'https://p05113ef3.test.weline.com',
                    'HTTP_HOST' => 'p05113ef3.test.weline.com:9555',
                    'REQUEST_SCHEME' => 'http',
                ],
            );

            self::assertSame('https://p05113ef3.test.weline.com', $request->getBaseHost());
        } finally {
            if ($prevWlsPort === false) {
                \putenv('WLS_PORT');
            } else {
                \putenv('WLS_PORT=' . $prevWlsPort);
            }
            \Weline\Framework\Env\WelineEnv::getInstance()->reset();
        }
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
            "Host: p05113ef3.test.weline.com\r\n",
            [
                'HTTPS' => 'on',
                'REQUEST_SCHEME' => 'https',
                'WLS_PORT' => 9555,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('p05113ef3.test.weline.com:9555', $_SERVER['HTTP_HOST'] ?? null);
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

    public function testTrustedProxyRestoresPublicHostFromXForwardedHostWhenWireHostIsLoopback(): void
    {
        $request = $this->createRequest(
            "Host: 127.0.0.1:9510\r\n"
            . "X-Forwarded-Host: www.changanhanfu.com\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n",
            [
                'WLS_PORT' => 9510,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('www.changanhanfu.com', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('443', $_SERVER['SERVER_PORT'] ?? null);
        self::assertSame('https://www.changanhanfu.com/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
        self::assertSame('https://www.changanhanfu.com', $request->getBaseHost());
    }

    public function testTrustedProxyIgnoresXForwardedHostWhenWireHostIsAlreadyPublic(): void
    {
        $request = $this->createRequest(
            "Host: www.changanhanfu.com\r\n"
            . "X-Forwarded-Host: 127.0.0.1:9510\r\n"
            . "X-Forwarded-Proto: https\r\n"
            . "X-Forwarded-Port: 443\r\n",
            [
                'WLS_PORT' => 9510,
                'WLS_TRUST_FORWARDED_HEADERS' => '1',
            ],
        );

        self::assertTrue($request->isSecure());
        self::assertSame('www.changanhanfu.com', $_SERVER['HTTP_HOST'] ?? null);
        self::assertSame('https://www.changanhanfu.com/customer/account/logout', $_SERVER['WELINE_FULL_REQUEST_URI'] ?? null);
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
     * managed nginx（loopback 明文 H1，无 REQUEST_SCHEME）不得被 env.php 的
     * wls.https=true 提升为 https —— 曾把 Google OAuth redirect_uri 生成成
     * http:// 导致线上 Error 400 redirect_uri_mismatch。
     */
    public function testTrustedLoopbackNginxWithoutSchemeIsNotPromotedByEnvHttps(): void
    {
        $envFile = (\defined('BP') ? \BP : '') . 'etc/env.php';
        $existed = \is_file($envFile);
        $backup = $existed ? (string)\file_get_contents($envFile) : '';
        if (!$existed) {
            @\mkdir(\dirname($envFile), 0777, true);
        }
        \file_put_contents($envFile, '<?php return ' . var_export(['wls' => ['https' => true]], true) . ';');
        try {
            $request = $this->createRequest(
                "Host: 127.0.0.1:9555\r\n"
                . "X-Forwarded-Proto: http\r\n",
                [
                    'WLS_TRUST_FORWARDED_HEADERS' => '1',
                    'WLS_PORT' => 9555,
                ],
            );
            self::assertFalse($request->isSecure(), 'XFP:http from trusted loopback edge must stay http');
            self::assertSame('http', $_SERVER['REQUEST_SCHEME'] ?? null);

            // 无任何 scheme 信号时同样不得被 env 提升（回环入口默认是明文）。
            $requestNoSignals = $this->createRequest(
                "Host: www.changhanfu.com\r\n",
                [
                    'WLS_TRUST_FORWARDED_HEADERS' => '1',
                    'WLS_PORT' => 9555,
                ],
            );
            self::assertFalse($requestNoSignals->isSecure(), 'scheme-less trusted edge admission must stay http');
        } finally {
            if ($existed) {
                \file_put_contents($envFile, $backup);
            } else {
                @\unlink($envFile);
            }
        }
    }

    /**
     * 直连 TLS Worker（无转发头、无 scheme 信号）仍允许按 env wls.https=true 识别为 https。
     */
    public function testDirectWorkerWithoutSignalsStillHonorsEnvHttps(): void
    {
        $envFile = (\defined('BP') ? \BP : '') . 'etc/env.php';
        $existed = \is_file($envFile);
        $backup = $existed ? (string)\file_get_contents($envFile) : '';
        if (!$existed) {
            @\mkdir(\dirname($envFile), 0777, true);
        }
        \file_put_contents($envFile, '<?php return ' . var_export(['wls' => ['https' => true]], true) . ';');
        try {
            $request = $this->createRequest(
                "Host: p05113ef3.test.weline.com\r\n",
                [
                    'WLS_TRUST_FORWARDED_HEADERS' => '0',
                    'WLS_PORT' => 9555,
                ],
            );
            self::assertTrue($request->isSecure(), 'direct cleartext-less probe with wls.https=true keeps legacy https fallback');
        } finally {
            if ($existed) {
                \file_put_contents($envFile, $backup);
            } else {
                @\unlink($envFile);
            }
        }
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
