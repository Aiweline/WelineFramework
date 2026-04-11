<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\WlsRuntime;

final class WlsRuntimePendingResponseStatusTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
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

        self::assertSame(['status_code' => 200, 'explicit' => true], $status);
        self::assertSame(['status_code' => null, 'explicit' => false], $runtime->consumePendingResponseStatus());
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
        self::assertSame(['status_code' => 202, 'explicit' => true], $resultB['status']);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());

        $resultA = $fiberA->getReturn();
        self::assertSame('text/html; charset=utf-8', $resultA['headers']['Content-Type'] ?? null);
        self::assertSame('fiber-a', $resultA['cookies']['sid']['value'] ?? null);
        self::assertSame(['status_code' => 201, 'explicit' => true], $resultA['status']);
    }
}
