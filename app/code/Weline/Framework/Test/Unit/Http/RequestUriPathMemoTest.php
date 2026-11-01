<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;

/**
 * base URL 只切一次 path：同一 URI 在一请求内重复读取结果稳定，
 * query-only URI 的 base path 仍为空（与历史 explode('?')[0] 语义一致）。
 */
final class RequestUriPathMemoTest extends TestCase
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
        WelineEnv::getInstance()->reset();
        parent::tearDown();
    }

    public function testBaseUrlStaysStableForQueryOnlyUri(): void
    {
        $server = [
            'REQUEST_URI' => '?foo=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'https',
            'HTTPS' => 'on',
            'HTTP_HOST' => '127.0.0.1:9982',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '9982',
            'QUERY_STRING' => 'foo=1',
        ];
        $_SERVER = $server;
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $server);

        $request = new Request();

        self::assertSame('https://127.0.0.1:9982/', $request->getBaseUrl());
        self::assertSame('https://127.0.0.1:9982/', $request->getBaseUrl());
        self::assertSame('https://127.0.0.1:9982/', $request->getBaseUri());
        self::assertSame('', $request->getFirstUrlPath());
    }

    public function testSlashlessPathKeepsHistoricalNormalization(): void
    {
        $server = [
            'REQUEST_URI' => 'weshop?foo=1',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_SCHEME' => 'https',
            'HTTPS' => 'on',
            'HTTP_HOST' => '127.0.0.1:9982',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '9982',
            'QUERY_STRING' => 'foo=1',
        ];
        $_SERVER = $server;
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $server);

        $request = new Request();

        self::assertSame('https://127.0.0.1:9982/weshop', $request->getBaseUrl());
        self::assertSame('weshop', $request->getFirstUrlPath());
    }
}
