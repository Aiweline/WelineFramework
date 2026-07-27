<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use Fiber;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Api\Runtime\RequestResetter;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Observer\CacheFlushedObserver;

final class RequestStateIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        CacheFlushedObserver::resetRequestState();
    }

    protected function tearDown(): void
    {
        CacheFlushedObserver::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testPeerFiberResetDoesNotClearCacheFlushDeduplicationState(): void
    {
        $observed = [];
        $stateKey = self::requestNotifiedKey();

        $fiberA = new Fiber(function () use (&$observed, $stateKey): void {
            Context::enter(new Context());
            try {
                RequestContext::set($stateKey, true);
                Fiber::suspend('a-ready');

                (new RequestResetter())->resetRequest();
                $observed['a_after_reset'] = RequestContext::get($stateKey, false);
                Fiber::suspend('a-reset');
            } finally {
                (new RequestResetter())->resetRequest();
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed, $stateKey): void {
            Context::enter(new Context());
            try {
                RequestContext::set($stateKey, true);
                Fiber::suspend('b-ready');

                $observed['b_after_a_reset'] = RequestContext::get($stateKey, false);
                Fiber::suspend('b-verified');
            } finally {
                (new RequestResetter())->resetRequest();
                Context::leave();
            }
        });

        self::assertSame('a-ready', $fiberA->start());
        self::assertSame('b-ready', $fiberB->start());
        self::assertSame('a-reset', $fiberA->resume());
        self::assertSame('b-verified', $fiberB->resume());

        self::assertFalse($observed['a_after_reset']);
        self::assertTrue($observed['b_after_a_reset']);

        $fiberA->resume();
        $fiberB->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    public function testRequestResetPreservesProcessMemoryStatistics(): void
    {
        $property = new ReflectionProperty(WlsMemoryAdapter::class, 'stats');
        $property->setAccessible(true);
        $original = $property->getValue();
        $sentinel = ['runtime-test' => ['hits' => 7, 'misses' => 3]];

        try {
            $property->setValue(null, $sentinel);
            (new RequestResetter())->resetRequest();
            self::assertSame($sentinel, $property->getValue());
        } finally {
            $property->setValue(null, $original);
        }
    }

    private static function requestNotifiedKey(): string
    {
        $constant = (new ReflectionClass(CacheFlushedObserver::class))
            ->getReflectionConstant('REQUEST_NOTIFIED_KEY');
        self::assertNotFalse($constant);
        return (string)$constant->getValue();
    }
}
