<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
    }

    public function testNormalizeArrayCreatesJsonResponse(): void
    {
        $response = Response::normalize(['ok' => true, 'message' => 'done']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame(['ok' => true, 'message' => 'done'], \json_decode($response->getBody(), true));
    }

    public function testNormalizeStringCreatesHtmlResponse(): void
    {
        $response = Response::normalize('<h1>hello</h1>');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('<h1>hello</h1>', $response->getBody());
    }

    public function testResponseTerminateExceptionCanCarryFrameworkResponse(): void
    {
        $response = Response::json(['ok' => true], 202);
        $exception = new ResponseTerminateException($response);

        self::assertSame(202, $exception->getStatusCode());
        self::assertSame($response->getBody(), $exception->getBody());
        self::assertSame($response->getHeader('Content-Type'), $exception->getHeaders()['Content-Type'] ?? null);
        self::assertStringContainsString('HTTP/1.1 202', $exception->toHttpString());
    }

    public function testStandaloneResponseDoesNotPolluteGlobalHeaderCollector(): void
    {
        $globalCollector = HeaderCollector::getInstance();
        $globalCollector->setHeader('X-Global', 'keep');

        $response = Response::json(['ok' => true], 200);
        $response->setHeader('X-Detached', 'yes');

        self::assertSame('keep', $globalCollector->getHeader('X-Global'));
        self::assertNull($globalCollector->getHeader('X-Detached'));
        self::assertSame('yes', $response->getHeader('X-Detached'));
    }
}
