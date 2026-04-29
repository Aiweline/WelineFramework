<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimePendingResponseStatusTest extends TestCase
{
    private ?Context $previousContext = null;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
    }

    public function testSnapshotAndConsumePendingResponseStatusPreservesExplicitOverride(): void
    {
        $runtime = new class extends WlsRuntime {
            public function capture(HeaderCollector $collector): void
            {
                $this->snapshotPendingResponseState($collector);
            }
        };

        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'application/json');
        $collector->setStatusCode(200);

        $runtime->capture($collector);
        $status = $runtime->consumePendingResponseStatus();

        self::assertSame(['status_code' => 200, 'explicit' => true, 'sse_started' => false], $status);
        self::assertSame(['status_code' => null, 'explicit' => false, 'sse_started' => false], $runtime->consumePendingResponseStatus());
        self::assertSame(['Content-Type' => 'application/json'], $runtime->consumePendingHeaders());
    }

    public function testPendingResponseStateStaysFiberLocalAcrossConcurrentFibers(): void
    {
        $runtime = new class extends WlsRuntime {
            public function capture(HeaderCollector $collector): void
            {
                $this->snapshotPendingResponseState($collector);
            }
        };

        $fiberA = new \Fiber(static function () use ($runtime): array {
            $collector = HeaderCollector::getInstance();
            $collector->setHeader('Content-Type', 'text/html; charset=utf-8');
            $collector->setCookie('sid', 'fiber-a', 0, '/');
            $collector->setStatusCode(201);
            $runtime->capture($collector);

            \Fiber::suspend();

            return [
                'headers' => $runtime->consumePendingHeaders(),
                'cookies' => $runtime->consumePendingCookies(),
                'status' => $runtime->consumePendingResponseStatus(),
            ];
        });

        $fiberB = new \Fiber(static function () use ($runtime): array {
            $collector = HeaderCollector::getInstance();
            $collector->setHeader('Content-Type', 'application/json; charset=utf-8');
            $collector->setCookie('sid', 'fiber-b', 0, '/');
            $collector->setStatusCode(202);
            $runtime->capture($collector);

            return [
                'headers' => $runtime->consumePendingHeaders(),
                'cookies' => $runtime->consumePendingCookies(),
                'status' => $runtime->consumePendingResponseStatus(),
            ];
        });

        self::assertNull($fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());

        $resultB = $fiberB->getReturn();
        self::assertSame('application/json; charset=utf-8', $resultB['headers']['Content-Type'] ?? null);
        self::assertSame('fiber-b', $resultB['cookies']['sid']['value'] ?? null);
        self::assertSame(['status_code' => 202, 'explicit' => true, 'sse_started' => false], $resultB['status']);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());

        $resultA = $fiberA->getReturn();
        self::assertSame('text/html; charset=utf-8', $resultA['headers']['Content-Type'] ?? null);
        self::assertSame('fiber-a', $resultA['cookies']['sid']['value'] ?? null);
        self::assertSame(['status_code' => 201, 'explicit' => true, 'sse_started' => false], $resultA['status']);
    }

    public function testSseHandledMarkerDoesNotRequireEventSourceAcceptHeader(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::init();
        RequestContext::set(RequestContext::SSE_WRITER_KEY, true);

        $request = WlsRequest::fromRaw(
            "POST /stream HTTP/1.1\r\nHost: example.test\r\nAccept: */*\r\nX-Requested-With: XMLHttpRequest\r\n\r\n"
        );

        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod(WlsRuntime::class, 'isSseStreamHandledInCurrentRequest');
        $method->setAccessible(true);

        self::assertTrue((bool)$method->invoke($runtime, $request));
    }

    public function testSnapshotPendingResponseStateMarksSseContentTypeWhenStreamAlreadyStarted(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::init();
        RequestContext::set(RequestContext::SSE_WRITER_KEY, true);

        $runtime = new class extends WlsRuntime {
            public function capture(HeaderCollector $collector): void
            {
                $this->snapshotPendingResponseState($collector);
            }
        };

        $collector = HeaderCollector::getInstance();
        $runtime->capture($collector);

        $headers = $runtime->consumePendingHeaders();
        $status = $runtime->consumePendingResponseStatus();

        self::assertSame('text/event-stream; charset=utf-8', $headers['Content-Type'] ?? null);
        self::assertTrue((bool)($status['sse_started'] ?? false));
    }

    public function testSnapshotPendingResponseStateOverwritesConflictingContentTypeWhenSseStarted(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::init();
        RequestContext::set(RequestContext::SSE_WRITER_KEY, true);

        $runtime = new class extends WlsRuntime {
            public function capture(HeaderCollector $collector): void
            {
                $this->snapshotPendingResponseState($collector);
            }
        };

        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $runtime->capture($collector);

        $headers = $runtime->consumePendingHeaders();
        self::assertSame('text/event-stream; charset=utf-8', $headers['Content-Type'] ?? null);
    }
}
