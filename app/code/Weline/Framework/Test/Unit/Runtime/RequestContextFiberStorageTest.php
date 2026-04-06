<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

final class RequestContextFiberStorageTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        RequestContext::cleanup();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        Runtime::resetModeCache();
    }

    public function testGenericRequestContextStorageIsFiberLocalInWlsMode(): void
    {
        $fiberA = new \Fiber(static function (): string {
            RequestContext::set('protocol', 'doc/http');
            \Fiber::suspend(RequestContext::get('protocol'));

            return (string) RequestContext::get('protocol');
        });

        $fiberB = new \Fiber(static function (): string {
            RequestContext::set('protocol', 'text/plain');

            return (string) RequestContext::get('protocol');
        });

        self::assertSame('doc/http', $fiberA->start());
        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertSame('text/plain', $fiberB->getReturn());
        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('doc/http', $fiberA->getReturn());
    }

    public function testCleanupOnlyClearsCurrentFiberStorage(): void
    {
        $fiberA = new \Fiber(static function (): string {
            RequestContext::set('request_flag', 'fiber-a');
            \Fiber::suspend();

            return (string) RequestContext::get('request_flag');
        });

        $fiberB = new \Fiber(static function (): bool {
            RequestContext::set('request_flag', 'fiber-b');
            RequestContext::cleanup();

            return RequestContext::has('request_flag');
        });

        $fiberA->start();

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        self::assertFalse($fiberB->getReturn());
        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame('fiber-a', $fiberA->getReturn());
    }

    public function testRequestIdsStayIsolatedAcrossFibers(): void
    {
        $fiberA = new \Fiber(static function (): string {
            RequestContext::init();
            $requestId = (string) RequestContext::getId();
            \Fiber::suspend($requestId);

            return (string) RequestContext::getId();
        });

        $fiberB = new \Fiber(static function (): string {
            RequestContext::init();

            return (string) RequestContext::getId();
        });

        $requestIdA = $fiberA->start();
        self::assertIsString($requestIdA);
        self::assertNotSame('', $requestIdA);

        self::assertNull($fiberB->start());
        self::assertTrue($fiberB->isTerminated());
        $requestIdB = $fiberB->getReturn();
        self::assertIsString($requestIdB);
        self::assertNotSame('', $requestIdB);
        self::assertNotSame($requestIdA, $requestIdB);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame($requestIdA, $fiberA->getReturn());
    }

    public function testCleanupCallbacksAreFiberLocal(): void
    {
        $calls = [];

        $fiberA = new \Fiber(static function () use (&$calls): string {
            RequestContext::onCleanup(static function () use (&$calls): void {
                $calls[] = 'fiber-a';
            }, 'fiber-a');
            RequestContext::set('request_flag', 'fiber-a');
            \Fiber::suspend();
            RequestContext::cleanup();

            return (string) RequestContext::get('request_flag', '');
        });

        $fiberB = new \Fiber(static function () use (&$calls): void {
            RequestContext::onCleanup(static function () use (&$calls): void {
                $calls[] = 'fiber-b';
            }, 'fiber-b');
            RequestContext::cleanup();
        });

        $fiberA->start();
        $fiberB->start();
        self::assertTrue($fiberB->isTerminated());
        self::assertSame(['fiber-b'], $calls);

        self::assertNull($fiberA->resume());
        self::assertTrue($fiberA->isTerminated());
        self::assertSame(['fiber-b', 'fiber-a'], $calls);
        self::assertSame('', $fiberA->getReturn());
    }
}
