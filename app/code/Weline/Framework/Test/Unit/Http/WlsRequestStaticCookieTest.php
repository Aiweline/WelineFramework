<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\WlsRequest;

final class WlsRequestStaticCookieTest extends TestCase
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

    public function testStaticResourceRequestKeepsIncomingCookies(): void
    {
        $rawRequest = "GET /Weline/Theme/view/theme/backend/assets/css/theme.css HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Cookie: WELINE_SESSID=29057858abcdef; foo=bar\r\n"
            . "Accept: text/css,*/*;q=0.1\r\n"
            . "\r\n";

        WlsRequest::fromRaw($rawRequest, ['HTTPS' => 'on', 'REQUEST_SCHEME' => 'https']);

        self::assertSame('29057858abcdef', $_COOKIE['WELINE_SESSID'] ?? null);
        self::assertSame('bar', $_COOKIE['foo'] ?? null);
        self::assertSame('WELINE_SESSID=29057858abcdef; foo=bar', $_SERVER['HTTP_COOKIE'] ?? null);
        self::assertTrue((bool) ($_SERVER['WELINE_IS_STATIC_FILE'] ?? false));
    }
}
