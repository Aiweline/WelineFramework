<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;

final class SchedulerSystemSyncAwaitWindowTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::enableIoWait();
        SchedulerSystem::setWaitDispatcher(function (string $type, array $payload): void {
            $this->dispatched[] = [$type, $payload];
        });
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testReadySocketWithinSyncWindowDoesNotSuspendFiber(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fiber = new \Fiber(static fn(): bool => SchedulerSystem::awaitReadable($a, 0.05, 0.002));
        $result = $fiber->start();

        self::assertTrue($fiber->isTerminated(), 'ready local socket must not hand the Fiber to the scheduler');
        self::assertTrue($fiber->getReturn());
        self::assertNull($result);
        self::assertSame([], $this->dispatched);
    }

    public function testSocketNotReadyWithinSyncWindowFallsBackToSchedulerWait(): void
    {
        [$a, $peer] = $this->socketPair();

        $fiber = new \Fiber(static fn(): bool => SchedulerSystem::awaitReadable($a, 0.05, 0.001));
        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertCount(1, $this->dispatched);
        self::assertSame('io_readable', $this->dispatched[0][0]);
        self::assertLessThan(0.05, $this->dispatched[0][1]['timeout'], 'sync window time is charged to the deadline');
        $fiber->resume(true);
        self::assertTrue($fiber->getReturn());
        \fclose($peer);
    }

    public function testZeroWindowKeepsImmediateSuspendBehaviour(): void
    {
        [$a, $b] = $this->socketPair();
        \fwrite($b, 'x');

        $fiber = new \Fiber(static fn(): bool => SchedulerSystem::awaitReadable($a, 0.05));
        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertCount(1, $this->dispatched);
        $fiber->resume(true);
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        \stream_set_blocking($pair[0], false);
        \stream_set_blocking($pair[1], false);

        return $pair;
    }
}
