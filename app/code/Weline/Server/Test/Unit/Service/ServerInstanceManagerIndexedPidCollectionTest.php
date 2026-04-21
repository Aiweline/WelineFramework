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
            'services' => [
                'worker' => [
                    'instances' => [
                        [
                            'metadata' => [
                                'process_name' => 'weline-wls-worker-default-1',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $pids = $method->invoke(
            $manager,
            [
                101 => ['pname' => '--name=' . MasterProcess::getMasterProcessName('default'), 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="weline-wls-worker-default-1"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=weline-wls-worker-other-1', 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=weline-wls-session-default', 'jsonPath' => __FILE__ . '.missing'],
            ],
            'default',
            $rawData
        );

        self::assertSame([101, 202], $pids);
    }

    public function testCollectServiceRecordTrackedPidsIncludesPersistedProcessTreeHints(): void
    {
        $manager = new ServerInstanceManager();
        $method = new \ReflectionMethod($manager, 'collectServiceRecordTrackedPids');
        $method->setAccessible(true);

        $pids = $method->invoke($manager, [
            'pid' => 202,
            'root_pid' => 1202,
            'launcher_pid' => 1302,
            'metadata' => [
                'service_pid' => 2202,
                'root_pid' => 3202,
                'launcher_pid' => 3302,
            ],
        ]);

        self::assertSame([202, 1202, 1302, 2202, 3202, 3302], $pids);
    }
}
