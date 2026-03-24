<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class StopCommandMasterLivenessFastTest extends TestCase
{
    public function testMasterIsAvailableWhenProcessExistsAndIndexStillOwnsPid(): void
    {
        $stop = new Stop();
        $info = $this->createInstanceInfo(12345);

        $available = $this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStopFromRuntime',
            $info,
            [
                12345 => [
                    'pid' => 12345,
                    'exists' => true,
                    'name' => 'php.exe',
                    'command' => 'php master.php',
                    'memory' => '10 MB',
                    'cpu' => '0%',
                    'start_time' => '',
                ],
            ],
            false
        );

        self::assertTrue($available);
    }

    public function testMasterIsNotAvailableWhenProcessAlreadyExitedFast(): void
    {
        $stop = new Stop();
        $info = $this->createInstanceInfo(12345);

        $available = $this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStopFromRuntime',
            $info,
            [
                12345 => [
                    'pid' => 12345,
                    'exists' => true,
                    'name' => 'php.exe',
                    'command' => 'php unrelated.php',
                    'memory' => '10 MB',
                    'cpu' => '0%',
                    'start_time' => '',
                ],
            ],
            true
        );

        self::assertFalse($available);
    }

    public function testMasterIsNotAvailableWhenProcessMapDoesNotContainPid(): void
    {
        $stop = new Stop();
        $info = $this->createInstanceInfo(12345);

        $available = $this->invokeProtected(
            $stop,
            'isMasterProcessAvailableForStopFromRuntime',
            $info,
            [],
            false
        );

        self::assertFalse($available);
    }

    private function createInstanceInfo(int $masterPid): ServerInstanceInfo
    {
        return new ServerInstanceInfo(
            'default',
            $masterPid,
            19982,
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
