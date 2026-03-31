<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

final class PassthroughCoreFirstByteTimeoutTest extends TestCase
{
    private function invokePrivateMethod(object $target, string $method, mixed ...$args): mixed
    {
        $caller = function (string $methodName, array $invokeArgs): mixed {
            return $this->{$methodName}(...$invokeArgs);
        };
        $bound = \Closure::bind($caller, $target, $target);
        self::assertInstanceOf(\Closure::class, $bound);

        return $bound($method, $args);
    }

    public function testSilentWorkerDoesNotFailBeforeRequestBytesReachWorker(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);

        self::assertFalse(
            $this->invokePrivateMethod(
                $core,
                'shouldTreatSilentWorkerAsFailure',
                ['request_sent_at' => 0.0],
                0
            )
        );
    }

    public function testSilentWorkerFailsAfterRequestWasSentAndTimeoutElapsed(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $core->configure(['first_byte_timeout_seconds' => 0.5]);

        self::assertTrue(
            $this->invokePrivateMethod(
                $core,
                'shouldTreatSilentWorkerAsFailure',
                ['request_sent_at' => \microtime(true) - 1.0],
                0
            )
        );
    }

    public function testWorkerTimeoutIsSkippedAfterResponseBytesAlreadyArrived(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $core->configure(['first_byte_timeout_seconds' => 0.5]);

        self::assertFalse(
            $this->invokePrivateMethod(
                $core,
                'shouldTreatSilentWorkerAsFailure',
                ['request_sent_at' => \microtime(true) - 1.0],
                1
            )
        );
    }
}
