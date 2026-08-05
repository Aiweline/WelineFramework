<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestExitException;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\WlsFiberContext;
use Weline\Server\Service\WorkerResponseMemoryGuard;

require_once BP . 'app/code/Weline/Server/bin/worker_runtime_common.php';

final class WorkerRequestFiberCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::clearIncompleteRequestFiberCancelStreak();
    }

    protected function tearDown(): void
    {
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::clearIncompleteRequestFiberCancelStreak();
        RequestContext::cleanup();
        Context::leave();
        Runtime::resetModeCache();
        parent::tearDown();
    }

    public function testCancellationUnwindsFiberFinallyBeforeScopeIsDiscarded(): void
    {
        Context::enter(new Context(['meta' => ['id' => 'main']]));
        $finallyCalls = 0;
        $fiber = new \Fiber(static function () use (&$finallyCalls): void {
            Context::enter(new Context(['meta' => ['id' => 'request']]));
            RequestContext::set('sentinel', 'fiber-a');
            try {
                \Fiber::suspend();
            } finally {
                ++$finallyCalls;
                RequestContext::cleanup();
                Context::leave();
            }
        });

        self::assertNull($fiber->start());
        $snapshot = WlsFiberContext::captureForFiber($fiber);

        self::assertTrue(wlsUnwindRequestFiberForCancellation($fiber, $snapshot, 'unit_test'));
        self::assertTrue($fiber->isTerminated());
        self::assertSame(1, $finallyCalls);
        self::assertSame('main', Context::current()->get('meta.id'));
        self::assertNull(WorkerResponseMemoryGuard::consumeDrainAfterResponseReason());
        self::assertSame(0, WorkerResponseMemoryGuard::incompleteRequestFiberCancelStreak());
    }

    public function testCancellationThatSuspendsOnceCompletesOnRetryThrow(): void
    {
        $fiber = new \Fiber(static function (): void {
            Context::enter(new Context(['meta' => ['id' => 'request']]));
            try {
                \Fiber::suspend();
            } catch (RequestExitException) {
                \Fiber::suspend();
            } finally {
                RequestContext::cleanup();
                Context::leave();
            }
        });

        self::assertNull($fiber->start());
        $snapshot = WlsFiberContext::captureForFiber($fiber);

        self::assertTrue(wlsUnwindRequestFiberForCancellation($fiber, $snapshot, 'unit_test_retry'));
        self::assertTrue($fiber->isTerminated());
        self::assertNull(WorkerResponseMemoryGuard::consumeDrainAfterResponseReason());
        self::assertSame(0, WorkerResponseMemoryGuard::incompleteRequestFiberCancelStreak());
    }

    public function testStubbornIncompleteCancelDefersQuarantineUntilStreakThreshold(): void
    {
        $threshold = WorkerResponseMemoryGuard::INCOMPLETE_REQUEST_FIBER_CANCEL_QUARANTINE_STREAK;
        $fiber = $this->createStubbornCancelFiber();
        self::assertNull($fiber->start());
        $snapshot = WlsFiberContext::captureForFiber($fiber);

        for ($i = 1; $i < $threshold; $i++) {
            self::assertFalse(
                wlsUnwindRequestFiberForCancellation($fiber, $snapshot, 'unit_test_incomplete_' . $i)
            );
            self::assertNull(WorkerResponseMemoryGuard::consumeDrainAfterResponseReason());
            self::assertSame($i, WorkerResponseMemoryGuard::incompleteRequestFiberCancelStreak());
            self::assertFalse($fiber->isTerminated());
            self::assertTrue($fiber->isSuspended());
        }

        self::assertFalse(
            wlsUnwindRequestFiberForCancellation($fiber, $snapshot, 'unit_test_incomplete_final')
        );
        self::assertSame(
            'request_fiber_cancel_incomplete',
            WorkerResponseMemoryGuard::consumeDrainAfterResponseReason(),
        );
        self::assertSame($threshold, WorkerResponseMemoryGuard::incompleteRequestFiberCancelStreak());
    }

    public function testRequestEnterAndLeaveNeverCleanMainContextFallback(): void
    {
        Context::enter(new Context(['meta' => ['id' => 'main']]));
        RequestContext::set('main-sentinel', 'preserved');

        $fiber = new \Fiber(static function (): array {
            wlsFiberRequestContextEnter(null, 'connection-7');
            $inside = [
                'context_id' => Context::current()->get('meta.id'),
                'connection_id' => RequestContext::getConnectionId(),
            ];
            wlsFiberRequestContextLeave();

            return $inside;
        });

        self::assertNull($fiber->start());
        self::assertTrue($fiber->isTerminated());
        $inside = $fiber->getReturn();
        self::assertIsString($inside['context_id']);
        self::assertNotSame('', $inside['context_id']);
        self::assertSame('connection-7', $inside['connection_id']);
        self::assertSame('preserved', RequestContext::get('main-sentinel'));
        self::assertSame('main', Context::current()->get('meta.id'));
    }

    public function testLeaveAfterRuntimeContextLeaveDoesNotFallBackToMainCleanup(): void
    {
        Context::enter(new Context(['meta' => ['id' => 'main']]));
        RequestContext::set('main-sentinel', 'preserved');

        $fiber = new \Fiber(static function (): void {
            Context::enter(new Context(['meta' => ['id' => 'request']]));
            RequestContext::set('fiber-sentinel', 'request-only');
            Context::leave();
            wlsFiberRequestContextLeave();
        });

        self::assertNull($fiber->start());
        self::assertTrue($fiber->isTerminated());
        self::assertSame('preserved', RequestContext::get('main-sentinel'));
        self::assertSame('main', Context::current()->get('meta.id'));
    }

    private function createStubbornCancelFiber(): \Fiber
    {
        return new \Fiber(static function (): void {
            Context::enter(new Context(['meta' => ['id' => 'request']]));
            try {
                while (true) {
                    try {
                        \Fiber::suspend();
                    } catch (RequestExitException) {
                        // Ignore cancel and re-suspend so multi-throw cannot finish.
                    }
                }
            } finally {
                RequestContext::cleanup();
                Context::leave();
            }
        });
    }
}
