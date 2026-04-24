<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime\Async;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Runtime\Async\AsyncBizAdapters;
use Weline\Server\Scheduler\FiberScheduler;

final class AsyncBizAdaptersTest extends TestCase
{
    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testDispatchReturnsCallbackResult(): void
    {
        $adapter = new AsyncBizAdapters();
        $result = $adapter->dispatch(static fn(): string => 'ok');
        self::assertSame('ok', $result);
    }

    public function testDispatchDoesNotSuspendRequestFiberAtFrameworkBoundary(): void
    {
        $scheduler = new FiberScheduler();
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use ($scheduler): void {
            $fiber = $params['fiber'] ?? \Fiber::getCurrent();
            if ($type === 'yield' && $fiber instanceof \Fiber) {
                $scheduler->addYieldTimer($fiber);
            }
        });

        $adapter = new AsyncBizAdapters();
        $fiber = new \Fiber(static fn(): string => $adapter->dispatch(static fn(): string => 'ok'));

        $fiber->start();

        self::assertTrue($fiber->isTerminated());
        self::assertSame('ok', $fiber->getReturn());
        self::assertFalse($scheduler->hasPendingTimers());
    }

    public function testFileGetContentsWithYieldReadsTempFile(): void
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls_async_biz_' . \bin2hex(\random_bytes(6)) . '.txt';
        self::assertNotFalse(\file_put_contents($path, 'payload'));
        try {
            $data = AsyncBizAdapters::fileGetContentsWithYield($path);
            self::assertSame('payload', $data);
        } finally {
            @\unlink($path);
        }
    }

    public function testFileGetContentsWithYieldReturnsFalseForMissingFile(): void
    {
        $path = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'wls_async_biz_missing_' . \bin2hex(\random_bytes(8));
        self::assertFalse(AsyncBizAdapters::fileGetContentsWithYield($path));
    }
}

