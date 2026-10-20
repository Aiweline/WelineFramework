<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;

/**
 * Master PID guard contract.
 *
 * 原「缺失次数 + grace window」语义已随 Dispatcher 重构移除；当前契约为：
 *   - 每 MASTER_PID_CHECK_INTERVAL_SEC 最多探测一次；
 *   - 存活则清零 masterDeadCount；
 *   - 不可达累计到 MASTER_PID_DEAD_THRESHOLD 即 running=false；
 *   - 收到 shutdown 或 masterGuard 启用时短路。
 */
final class DispatcherMasterHeartbeatWindowTest extends TestCase
{
    public function testAliveMasterResetsDeadCount(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'masterPid', \getmypid());
        $this->setProperty($dispatcher, 'lastMasterPidCheck', 0.0);
        $this->setProperty($dispatcher, 'masterDeadCount', 2);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'masterGuard', null);
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertSame(0, $this->getProperty($dispatcher, 'masterDeadCount'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));
    }

    public function testDeadMasterStopsDispatcherAtThreshold(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'masterPid', 2_147_483_647);
        $this->setProperty($dispatcher, 'lastMasterPidCheck', 0.0);
        $this->setProperty($dispatcher, 'masterDeadCount', 0);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'masterGuard', null);
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertSame(1, $this->getProperty($dispatcher, 'masterDeadCount'));
        self::assertFalse($this->getProperty($dispatcher, 'running'));
    }

    public function testCheckIntervalSkipsProbeWithinWindow(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'masterPid', 2_147_483_647);
        $this->setProperty($dispatcher, 'lastMasterPidCheck', \hrtime(true) / 1_000_000_000);
        $this->setProperty($dispatcher, 'masterDeadCount', 0);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'masterGuard', null);
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertSame(0, $this->getProperty($dispatcher, 'masterDeadCount'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));
    }

    public function testShutdownSignalShortCircuitsPidProbe(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->setProperty($dispatcher, 'masterPid', 2_147_483_647);
        $this->setProperty($dispatcher, 'lastMasterPidCheck', 0.0);
        $this->setProperty($dispatcher, 'masterDeadCount', 0);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', true);
        $this->setProperty($dispatcher, 'masterGuard', null);
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertSame(0, $this->getProperty($dispatcher, 'masterDeadCount'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));
    }

    public function testMasterGuardExitStopsDispatcher(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $guard = $this->createMock(ChildMasterGuard::class);
        $guard->method('shouldExit')->willReturn(true);
        $guard->method('getLastExitReason')->willReturn('unit-test');
        $this->setProperty($dispatcher, 'masterGuard', $guard);
        $this->setProperty($dispatcher, 'masterPid', \getmypid());
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertFalse($this->getProperty($dispatcher, 'running'));
    }

    public function testEnabledMasterGuardResetsDeadCountWithoutPidProbe(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $guard = $this->createMock(ChildMasterGuard::class);
        $guard->method('shouldExit')->willReturn(false);
        $guard->method('isEnabled')->willReturn(true);
        $this->setProperty($dispatcher, 'masterGuard', $guard);
        $this->setProperty($dispatcher, 'masterDeadCount', 5);
        $this->setProperty($dispatcher, 'running', true);

        $this->invokePrivate($dispatcher, 'checkMasterPidAlive');

        self::assertSame(0, $this->getProperty($dispatcher, 'masterDeadCount'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);

        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();

        return $dispatcher;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    private function getProperty(object $target, string $name): mixed
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);

        return $property->getValue($target);
    }

    private function invokePrivate(object $target, string $method): mixed
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target);
    }
}
