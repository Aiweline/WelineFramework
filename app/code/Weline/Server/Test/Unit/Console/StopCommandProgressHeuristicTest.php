<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandProgressHeuristicTest extends TestCase
{
    public function testWaitsForMasterExitWhenExplicitMasterExitMessageArrives(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '所有子进程已完整退出，Master 即将结束主循环',
            [],
            0
        ));
    }

    public function testWaitsForMasterExitWhenAllChildProcessesAlreadyReportedExit(): void
    {
        $stop = new Stop();

        self::assertTrue($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '  ✓ Dispatcher(PID:5332) 已断开连接',
            [5332 => true, 54540 => true, 27176 => true, 55448 => true, 38624 => true],
            5
        ));
    }

    public function testDoesNotSwitchToMasterExitEarlyWhenChildrenRemain(): void
    {
        $stop = new Stop();

        self::assertFalse($this->invokeProtected(
            $stop,
            'shouldWaitForMasterExitAfterProgress',
            '阶段5/6: 校验子进程退出状态',
            [5332 => true, 54540 => true],
            5
        ));
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
