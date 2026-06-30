<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class StopCommandMasterLivenessFastTest extends TestCase
{
    public function testMasterAvailabilityUsesEndpointMetadataWithoutPidProbe(): void
    {
        $stop = new class extends Stop {
        };

        self::assertTrue($this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStop',
            $this->createInstanceInfo(12345)
        ));
    }

    public function testMasterAvailabilityReturnsFalseWhenEndpointCannotUseIpc(): void
    {
        $stop = new class extends Stop {
        };

        self::assertFalse($this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStop',
            $this->createInstanceInfo(0)
        ));
        self::assertFalse($this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStop',
            $this->createInstanceInfo(12345, 0)
        ));
    }

    private function createInstanceInfo(int $masterPid, int $controlPort = 19982): ServerInstanceInfo
    {
        return new ServerInstanceInfo(
            'default',
            $masterPid,
            $controlPort,
            '127.0.0.1',
            9982,
            false,
            false,
            1,
            10000,
            0,
            '2026-03-23 00:00:00',
            1774195200,
            []
        );
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
