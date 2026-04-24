<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;

/**
 * P1-1 / P1-2 覆盖：
 *   - IPC 连接正常时跳过昂贵的 PID 检测，视为 Master 存活。
 *   - 同时要求「次数 >= 阈值」和「持续观测 >= graceSec」才判定 Master 脱离，抵御瞬时抖动误判。
 */
class DispatcherMasterHeartbeatWindowTest extends TestCase
{
    private string $instanceName = 'ai-test-heartbeat';
    private string $instanceFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $baseDir = BP . 'var' . DS . 'server' . DS . 'instances';
        if (!\is_dir($baseDir)) {
            \mkdir($baseDir, 0777, true);
        }
        $this->instanceFile = $baseDir . DS . $this->instanceName . '.json';
    }

    protected function tearDown(): void
    {
        if (\is_file($this->instanceFile)) {
            @\unlink($this->instanceFile);
        }
        parent::tearDown();
    }

    public function testIpcAliveResetsMasterMissingObservationsWithoutBlockingPidCheck(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->seedInstanceFile(\PHP_INT_MAX); // 不存在的 PID，若真去做 PID 探活必然 missing

        $ipc = $this->createMock(ChildControlClientInterface::class);
        $ipc->method('isConnected')->willReturn(true);

        $this->setProperty($dispatcher, 'instanceName', $this->instanceName);
        $this->setProperty($dispatcher, 'masterCheckInterval', 0);
        $this->setProperty($dispatcher, 'maxMasterMissing', 2);
        $this->setProperty($dispatcher, 'masterMissingGraceSec', 0.01);
        $this->setProperty($dispatcher, 'masterMissingCount', 1);
        $this->setProperty($dispatcher, 'masterMissingSince', \microtime(true) - 10.0);
        $this->setProperty($dispatcher, 'ipcClient', $ipc);
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);

        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');

        // IPC 连接正常 => masterAlive=true，计数和窗口都应被重置
        self::assertSame(0, $this->getProperty($dispatcher, 'masterMissingCount'));
        self::assertSame(0.0, $this->getProperty($dispatcher, 'masterMissingSince'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));
    }

    public function testThresholdReachedButGraceNotMetKeepsDispatcherRunning(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->seedInstanceFile(\PHP_INT_MAX);

        $this->setProperty($dispatcher, 'instanceName', $this->instanceName);
        $this->setProperty($dispatcher, 'masterCheckInterval', 0);
        $this->setProperty($dispatcher, 'maxMasterMissing', 2);
        $this->setProperty($dispatcher, 'masterMissingGraceSec', 30.0); // 宽限期很长
        $this->setProperty($dispatcher, 'ipcClient', null); // IPC 断开 => 回退 PID 检测
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);

        // 第 1 次：missing 计入，持续时间尚未开始
        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');
        self::assertSame(1, $this->getProperty($dispatcher, 'masterMissingCount'));
        self::assertGreaterThan(0.0, $this->getProperty($dispatcher, 'masterMissingSince'));
        self::assertTrue($this->getProperty($dispatcher, 'running'));

        // 第 2 次：count 达阈值，但持续窗口（30s）远未满足
        $this->setProperty($dispatcher, 'lastMasterCheck', 0);
        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');
        self::assertSame(2, $this->getProperty($dispatcher, 'masterMissingCount'));
        self::assertTrue(
            $this->getProperty($dispatcher, 'running'),
            'count 达阈值但持续观察 < graceSec 时不得判定 Master 脱离'
        );
    }

    public function testThresholdAndGraceBothMetStopsDispatcher(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->seedInstanceFile(\PHP_INT_MAX);

        $this->setProperty($dispatcher, 'instanceName', $this->instanceName);
        $this->setProperty($dispatcher, 'masterCheckInterval', 0);
        $this->setProperty($dispatcher, 'maxMasterMissing', 2);
        $this->setProperty($dispatcher, 'masterMissingGraceSec', 0.0); // 立即满足持续窗口
        $this->setProperty($dispatcher, 'ipcClient', null);
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);

        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');
        self::assertTrue($this->getProperty($dispatcher, 'running'));

        $this->setProperty($dispatcher, 'lastMasterCheck', 0);
        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');
        self::assertFalse(
            $this->getProperty($dispatcher, 'running'),
            '达阈值且持续时间满足时应判定 Master 脱离，running 置 false'
        );
    }

    public function testShutdownSignalShortCircuitsHeartbeatCheck(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $this->seedInstanceFile(\PHP_INT_MAX);

        $this->setProperty($dispatcher, 'instanceName', $this->instanceName);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', true);
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'masterMissingCount', 0);

        $this->invokePrivate($dispatcher, 'checkMasterHeartbeat');

        // shutdown 已发 => 不应计入 missing
        self::assertSame(0, $this->getProperty($dispatcher, 'masterMissingCount'));
    }

    private function seedInstanceFile(int $masterPid): void
    {
        \file_put_contents(
            $this->instanceFile,
            \json_encode([
                'master_pid' => $masterPid,
                'master_enabled' => true,
            ])
        );
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
