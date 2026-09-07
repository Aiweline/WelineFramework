<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\PostResponseTaskQueue;

final class PostResponseTaskQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        while (PostResponseTaskQueue::pendingCount() > 0) {
            PostResponseTaskQueue::drain(100.0, 1000);
        }

        parent::tearDown();
    }

    public function testDrainCanLimitTasksPerCall(): void
    {
        $processed = [];
        $drainingObserved = false;

        PostResponseTaskQueue::enqueue('unit-a', static function () use (&$processed, &$drainingObserved): void {
            $processed[] = 'a';
            $drainingObserved = PostResponseTaskQueue::isDraining();
        });
        PostResponseTaskQueue::enqueue('unit-b', static function () use (&$processed): void {
            $processed[] = 'b';
        });
        PostResponseTaskQueue::enqueue('unit-c', static function () use (&$processed): void {
            $processed[] = 'c';
        });

        self::assertSame(1, PostResponseTaskQueue::drain(100.0, 1));
        self::assertSame(['a'], $processed);
        self::assertTrue($drainingObserved);
        self::assertFalse(PostResponseTaskQueue::isDraining());
        self::assertSame(2, PostResponseTaskQueue::pendingCount());

        self::assertSame(2, PostResponseTaskQueue::drain(100.0, 10));
        self::assertSame(['a', 'b', 'c'], $processed);
        self::assertSame(0, PostResponseTaskQueue::pendingCount());
    }

    public function testDrainLeavesIdleGraceTasksUntilReady(): void
    {
        $processed = false;
        PostResponseTaskQueue::enqueue('delayed', static function () use (&$processed): void {
            $processed = true;
        }, \microtime(true) + 0.02);

        self::assertSame(0, PostResponseTaskQueue::drain(100.0, 10));
        self::assertFalse($processed);
        self::assertSame(1, PostResponseTaskQueue::pendingCount());

        \usleep(30000);
        self::assertSame(1, PostResponseTaskQueue::drain(100.0, 10));
        self::assertTrue($processed);
    }
}
