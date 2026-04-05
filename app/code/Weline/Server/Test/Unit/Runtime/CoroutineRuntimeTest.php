<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\EventLoop\EventLoopInterface;
use Weline\Server\Runtime\CoroutineRuntime;
use Weline\Server\Scheduler\FiberScheduler;

final class CoroutineRuntimeTest extends TestCase
{
    public function testWaitDelegatesToLoopWithDefaultTimeout(): void
    {
        $loop = new class implements EventLoopInterface {
            public array $captured = [];

            public function wait(array &$read, array &$write, array &$except, int $timeoutSec, int $timeoutUsec): int|false
            {
                $this->captured = [
                    'timeout_sec' => $timeoutSec,
                    'timeout_usec' => $timeoutUsec,
                ];
                return 0;
            }

            public function backend(): string
            {
                return 'select';
            }
        };

        $runtime = new CoroutineRuntime($loop, new FiberScheduler());
        $read = [];
        $write = [];
        $except = [];
        $result = $runtime->wait($read, $write, $except, 123456);

        self::assertSame(0, $result);
        self::assertSame(0, $loop->captured['timeout_sec']);
        self::assertSame(123456, $loop->captured['timeout_usec']);
        self::assertSame('select', $runtime->getLoopBackend());
    }
}

