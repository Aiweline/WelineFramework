<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\PassthroughCore;

final class PassthroughCoreHealthAccountingTest extends TestCase
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

    private function readPrivateProperty(object $target, string $property): mixed
    {
        $reader = function (string $propertyName): mixed {
            return $this->{$propertyName};
        };
        $bound = \Closure::bind($reader, $target, $target);
        self::assertInstanceOf(\Closure::class, $bound);

        return $bound($property);
    }

    private function writePrivateProperty(object $target, string $property, mixed $value): void
    {
        $writer = function (string $propertyName, mixed $propertyValue): void {
            $this->{$propertyName} = $propertyValue;
        };
        $bound = \Closure::bind($writer, $target, $target);
        self::assertInstanceOf(\Closure::class, $bound);

        $bound($property, $value);
    }

    public function testBlacklistedWorkerDoesNotAutoRecoverOnTimerAlone(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $this->writePrivateProperty($core, 'workerHealth', [
            19091 => [
                'failures' => 3,
                'blacklisted_at' => \microtime(true) - 1.0,
                'last_success' => 0.0,
                'total_failures' => 3,
            ],
        ]);

        self::assertTrue($this->invokePrivateMethod($core, 'isWorkerBlacklisted', 19091));
    }

    public function testWorkerSuccessIsRecordedOnlyAfterFirstResponseByte(): void
    {
        $core = new PassthroughCore('127.0.0.1', 19981, 2);
        $this->writePrivateProperty($core, 'connections', [
            123 => [
                'worker' => null,
                'port' => 19091,
                'clientIp' => '127.0.0.1',
                'sni' => '',
                'open_time' => \microtime(true),
                'request_sent_at' => \microtime(true),
                'worker_responded' => false,
            ],
        ]);
        $this->writePrivateProperty($core, 'workerHealth', [
            19091 => [
                'failures' => 2,
                'blacklisted_at' => 0.0,
                'last_success' => 0.0,
                'total_failures' => 2,
            ],
        ]);

        $this->invokePrivateMethod($core, 'markWorkerResponsive', 123, 19091);

        $connections = $this->readPrivateProperty($core, 'connections');
        $workerHealth = $this->readPrivateProperty($core, 'workerHealth');

        self::assertTrue($connections[123]['worker_responded']);
        self::assertSame(0, $workerHealth[19091]['failures']);
        self::assertSame(2, $workerHealth[19091]['total_failures']);
        self::assertGreaterThan(0.0, $workerHealth[19091]['last_success']);

        $this->writePrivateProperty($core, 'workerHealth', [
            19091 => [
                'failures' => 1,
                'blacklisted_at' => 0.0,
                'last_success' => $workerHealth[19091]['last_success'],
                'total_failures' => 2,
            ],
        ]);

        $this->invokePrivateMethod($core, 'markWorkerResponsive', 123, 19091);
        $workerHealthAfterSecondCall = $this->readPrivateProperty($core, 'workerHealth');

        self::assertSame(1, $workerHealthAfterSecondCall[19091]['failures']);
        self::assertSame(2, $workerHealthAfterSecondCall[19091]['total_failures']);
    }
}
