<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Router\Core;

final class RequestScopedResponseResolutionTest extends TestCase
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

    public function testResolveRequestScopedResponseKeepsRequestOwnedBodyWhenControllerReturnsNothing(): void
    {
        $router = new Core();
        $request = WlsRequest::fromRaw("GET / HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $request->getResponse()
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setBody('<html>ok</html>');

        $requestProperty = new \ReflectionProperty(Core::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($router, $request);

        $method = new ReflectionMethod(Core::class, 'resolveRequestScopedResponse');
        $method->setAccessible(true);

        /** @var Response $response */
        $response = $method->invoke($router, null, '');

        self::assertSame($request->getResponse(), $response);
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('<html>ok</html>', $response->getBody());
    }

    public function testResolveRequestScopedResponseAbsorbsDetachedResponseIntoRequestOwnedResponse(): void
    {
        $router = new Core();
        $request = WlsRequest::fromRaw("GET / HTTP/1.1\r\nHost: example.test\r\n\r\n");
        $request->getResponse()->setHeader('X-Request', 'yes');

        $requestProperty = new \ReflectionProperty(Core::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($router, $request);

        $method = new ReflectionMethod(Core::class, 'resolveRequestScopedResponse');
        $method->setAccessible(true);

        $detached = Response::html('<html>detached</html>', 201);
        $detached->setHeader('X-Detached', 'yes');

        /** @var Response $response */
        $response = $method->invoke($router, $detached, '');

        self::assertSame($request->getResponse(), $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('yes', $response->getHeader('X-Request'));
        self::assertSame('yes', $response->getHeader('X-Detached'));
        self::assertSame('<html>detached</html>', $response->getBody());
    }
}
