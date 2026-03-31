<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request\RequestAbstract;

final class RequestBaseUrlNormalizationTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function testBaseUrlMethodsNormalizeSlashlessRequestPath(): void
    {
        $_SERVER = [
            'REQUEST_URI' => 'weshop?foo=1',
            'WELINE_ORIGIN_REQUEST_URI' => 'weshop?foo=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'https',
            'HTTPS' => 'on',
            'HTTP_HOST' => '127.0.0.1:9982',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '9982',
            'QUERY_STRING' => 'foo=1',
        ];

        $request = new class extends RequestAbstract {
        };

        self::assertSame('https://127.0.0.1:9982/weshop', $request->getBaseUrl());
        self::assertSame('https://127.0.0.1:9982/weshop', $request->getOriginBaseUrl());
        self::assertSame('https://127.0.0.1:9982/weshop', $request->getBaseUri());
    }

    public function testBaseHostIgnoresInternalServerPortWhenHostHasNoPortInHttps(): void
    {
        $_SERVER = [
            'REQUEST_URI' => '/hello',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'https',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'www.yonocashgames.com',
            'SERVER_NAME' => 'www.yonocashgames.com',
            // 内部监听端口（例如 WLS Worker/Dispatcher 端口）
            'SERVER_PORT' => '10001',
        ];

        $request = new class extends RequestAbstract {
        };

        self::assertSame('https://www.yonocashgames.com', $request->getBaseHost());
    }
}
