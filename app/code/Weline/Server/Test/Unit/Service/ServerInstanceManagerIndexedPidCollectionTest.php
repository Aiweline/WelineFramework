<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerIndexedPidCollectionTest extends TestCase
{
    public function testCollectIndexedPidsByInstanceFromPidIndexMatchesCurrentManagedTaskNames(): void
    {
        $manager = new ServerInstanceManager();
        $method = new \ReflectionMethod($manager, 'collectIndexedPidsByInstanceFromPidIndex');
        $method->setAccessible(true);

        $rawData = [
            'count' => 1,
        ];

        $pids = $method->invoke(
            $manager,
            [
                101 => ['pname' => '--name=' . MasterProcess::getMasterProcessName('default'), 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'default', 1) . '"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'other', 1), 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', 'default'), 'jsonPath' => __FILE__ . '.missing'],
            ],
            'default',
            $rawData
        );

        self::assertSame([101, 202], $pids);
    }
}
