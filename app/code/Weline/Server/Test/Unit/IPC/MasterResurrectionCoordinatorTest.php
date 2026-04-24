<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterResurrectionCoordinator;
use Weline\Server\IPC\MasterResurrector;

final class MasterResurrectionCoordinatorTest extends TestCase
{
    public function testReturnsFalseWhenInstanceNameOrPortMissing(): void
    {
        $factory = function (): MasterResurrector {
            $this->fail('工厂不应被调用：instance/port 缺失时应直接 short circuit');
        };
        $coordinator = new MasterResurrectionCoordinator($factory);

        $this->assertFalse($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            '',
            9999,
            false
        ));
        $this->assertFalse($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            'ut',
            0,
            false
        ));
    }

    public function testGivesUpWhenShouldResurrectReturnsFalse(): void
    {
        $stub = new class (ControlMessage::RESURRECTION_WORKER, 'ut', '127.0.0.1', 9999) extends MasterResurrector {
            public int $graceCalls = 0;
            public int $attemptCalls = 0;
            public function shouldResurrect(bool $receivedShutdown): bool
            {
                return false;
            }
            public function confirmAfterGrace(): bool
            {
                $this->graceCalls++;
                return true;
            }
            public function attemptResurrect(): bool
            {
                $this->attemptCalls++;
                return true;
            }
        };

        $coordinator = new MasterResurrectionCoordinator(static fn() => $stub);
        $this->assertFalse($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            'ut',
            9999,
            false
        ));
        $this->assertSame(0, $stub->graceCalls);
        $this->assertSame(0, $stub->attemptCalls);
    }

    public function testGivesUpWhenGraceConfirmsRecovery(): void
    {
        $stub = new class (ControlMessage::RESURRECTION_WORKER, 'ut', '127.0.0.1', 9999) extends MasterResurrector {
            public int $graceCalls = 0;
            public int $attemptCalls = 0;
            public function shouldResurrect(bool $receivedShutdown): bool
            {
                return true;
            }
            public function confirmAfterGrace(): bool
            {
                $this->graceCalls++;
                return false;
            }
            public function attemptResurrect(): bool
            {
                $this->attemptCalls++;
                return true;
            }
        };

        $coordinator = new MasterResurrectionCoordinator(static fn() => $stub);
        $this->assertFalse($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            'ut',
            9999,
            false
        ));
        $this->assertSame(1, $stub->graceCalls);
        $this->assertSame(0, $stub->attemptCalls, 'grace 判定 master 恢复时不应走 attempt');
    }

    public function testCallsAttemptWhenShouldAndGraceBothPass(): void
    {
        $stub = new class (ControlMessage::RESURRECTION_WORKER, 'ut', '127.0.0.1', 9999) extends MasterResurrector {
            public int $graceCalls = 0;
            public int $attemptCalls = 0;
            public function shouldResurrect(bool $receivedShutdown): bool
            {
                return true;
            }
            public function confirmAfterGrace(): bool
            {
                $this->graceCalls++;
                return true;
            }
            public function attemptResurrect(): bool
            {
                $this->attemptCalls++;
                return true;
            }
        };

        $coordinator = new MasterResurrectionCoordinator(static fn() => $stub);
        $this->assertTrue($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            'ut',
            9999,
            false
        ));
        $this->assertSame(1, $stub->graceCalls);
        $this->assertSame(1, $stub->attemptCalls);
    }

    public function testSwallowsExceptionsFromAttempt(): void
    {
        $stub = new class (ControlMessage::RESURRECTION_WORKER, 'ut', '127.0.0.1', 9999) extends MasterResurrector {
            public function shouldResurrect(bool $receivedShutdown): bool
            {
                return true;
            }
            public function confirmAfterGrace(): bool
            {
                return true;
            }
            public function attemptResurrect(): bool
            {
                throw new \RuntimeException('boom');
            }
        };

        $coordinator = new MasterResurrectionCoordinator(static fn() => $stub);
        $this->assertTrue($coordinator->handleDisconnect(
            ControlMessage::RESURRECTION_WORKER,
            'ut',
            9999,
            false
        ), 'attempt 异常不应抛出到调用方（避免影响子进程主循环）');
    }
}
