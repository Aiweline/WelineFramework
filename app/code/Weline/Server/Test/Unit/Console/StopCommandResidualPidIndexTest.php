<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandResidualPidIndexTest extends TestCase
{
    public function testCollectIndexedResidualPidsFromPidIndexIgnoresHistoricalNameIndexStyleNoise(): void
    {
        $stop = new Stop();

        $pids = $this->invokeProtected(
            $stop,
            'collectIndexedResidualPidsFromPidIndex',
            [
                101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="weline-wls-worker-default-1"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=weline-wls-worker-other-1', 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=weline-wls-session-default', 'jsonPath' => __FILE__ . '.missing'],
                505 => ['pname' => '--name=weline-master-default-worker-3', 'jsonPath' => __FILE__],
            ],
            'default',
            202
        );

        self::assertSame([101, 505], $pids);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
