<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Service\Query\Store\LockedCacheFrontendWorkerStateStore;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

/**
 * 前台查询的 Worker 状态锁等待属于请求链；调度器在线时必须让出 Fiber。
 */
final class LockedCacheFrontendWorkerStateStoreCooperativeWaitTest extends TestCase
{
    protected function tearDown(): void
    {
        SchedulerSystem::setWaitDispatcher(null);
        SchedulerSystem::disableScheduler();
    }

    public function testLockWaitYieldsFiberInsteadOfBlockingRequest(): void
    {
        SchedulerSystem::enableScheduler();
        $waits = [];
        SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use (&$waits): void {
            $waits[] = [$type, $params];
        });

        $lockFile = \tempnam(\sys_get_temp_dir(), 'worker-lock-coop-');
        $holder = \fopen($lockFile, 'c+b');
        self::assertIsResource($holder);
        self::assertTrue(\flock($holder, \LOCK_EX));

        try {
            $store = new LockedCacheFrontendWorkerStateStore(
                new WlsMemoryAdapter('isolated_lock_coop'),
                $lockFile,
                'cache',
                86400,
                200,
            );
            $fiber = new \Fiber(static fn(): mixed => $store->transaction(static fn(): string => 'ok'));

            $startedAt = \microtime(true);
            $fiber->start();
            $elapsedMs = (\microtime(true) - $startedAt) * 1000;

            self::assertTrue($fiber->isSuspended(), '等锁时必须挂起 Fiber，而不是阻塞 Worker 处理其他请求');
            self::assertNotEmpty($waits, '等锁必须通过调度器派发等待');
            self::assertLessThan(20.0, $elapsedMs, '挂起后瞬时返回，不得把进程硬阻塞到锁释放');

            \flock($holder, \LOCK_UN);
            \fclose($holder);
            $holder = null;

            // 调度器由本测试手动驱动：锁释放后继续 resume，直到事务结束（适配器内部还会再让出几次）。
            $resumes = 0;
            while ($fiber->isSuspended() && $resumes < 20) {
                $fiber->resume();
                ++$resumes;
            }

            self::assertFalse($fiber->isSuspended(), '锁释放后应拿到锁并完成事务');
            self::assertSame('ok', $fiber->getReturn());
        } finally {
            if (\is_resource($holder)) {
                \flock($holder, \LOCK_UN);
                \fclose($holder);
            }
            @\unlink($lockFile);
        }
    }
}
