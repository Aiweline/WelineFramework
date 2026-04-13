<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Manager\ObjectManager;

final class RequestResponseIsolationTest extends TestCase
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
        ObjectManager::removeInstance(Response::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(Response::class);
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;
        parent::tearDown();
    }

    public function testRequestBuildsItsOwnResponseInsteadOfReusingStaleObjectManagerResponse(): void
    {
        $stale = Response::text('', 200);
        $stale->setHeader('X-Stale', 'yes');
        ObjectManager::setInstance(Response::class, $stale);

        $request = WlsRequest::fromRaw("GET / HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $response = $request->getResponse();

        self::assertNotSame($stale, $response);
        self::assertNull($response->getHeader('X-Stale'));
        self::assertSame('', $response->getBody());
    }
}
