<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\PurgeCoalescer;

/**
 * 合并窗口：窗口内去重攒批，到点一次发出。
 */
class PurgeCoalescerTest extends TestCase
{
    public function testTargetsAreMergedAndDedupedWithinWindow(): void
    {
        $now = 200;
        $coalescer = new PurgeCoalescer(10, static function () use (&$now): int {
            return $now;
        });

        $coalescer->add('a.test', ['/products', '/']);
        $coalescer->add('a.test', ['/', '/cart']);
        $coalescer->add('b.test', ['/about']);

        self::assertSame([], $coalescer->flushDue(), '窗口未到不发出');
        self::assertSame(
            ['a.test' => ['/products', '/', '/cart'], 'b.test' => ['/about']],
            $coalescer->peek(),
            '窗口内去重合并'
        );

        $now += 10;
        $due = $coalescer->flushDue();
        self::assertSame(['/products', '/', '/cart'], $due['a.test'] ?? null);
        self::assertSame(['/about'], $due['b.test'] ?? null);
        self::assertSame([], $coalescer->peek(), '发出后缓冲清空');
    }

    public function testSecondFlushIsEmpty(): void
    {
        $coalescer = new PurgeCoalescer(0, static fn(): int => 1);
        $coalescer->add('a.test', ['/']);

        self::assertSame(['a.test' => ['/']], $coalescer->flushDue());
        self::assertSame([], $coalescer->flushDue(), '不会重复发同一批');
    }
}
