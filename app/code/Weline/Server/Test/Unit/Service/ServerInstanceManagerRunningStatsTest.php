<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerRunningStatsTest extends TestCase
{
    public function testRuntimeStatsComeFromIpcServicesSnapshot(): void
    {
        $manager = new ServerInstanceManager();
        $method = new \ReflectionMethod($manager, 'collectRuntimeStatsFromIpcStatus');
        $method->setAccessible(true);

        $stats = $method->invoke($manager, [
            'running' => true,
            'services' => [
                'dispatcher' => [
                    'instances' => [
                        ['state' => ServiceInstance::STATE_READY, 'port' => 443],
                    ],
                ],
                'worker' => [
                    'instances' => [
                        ['state' => ServiceInstance::STATE_READY, 'port' => 19982],
                        ['state' => ServiceInstance::STATE_STOPPED, 'port' => 19983],
                        ['state' => ServiceInstance::STATE_DRAINING, 'port' => 19984],
                    ],
                ],
                'session_server' => [
                    'instances' => [
                        ['state' => ServiceInstance::STATE_READY, 'port' => 26422],
                    ],
                ],
            ],
        ]);

        self::assertSame([
            'instance_running' => true,
            'workers' => 2,
            'dispatchers' => 1,
            'ports' => [19982, 19984],
        ], $stats);
    }

    public function testManagedProcessNamesUseOnlyScopedProcessNames(): void
    {
        $manager = new ServerInstanceManager();
        $method = new \ReflectionMethod($manager, 'collectManagedProcessNames');
        $method->setAccessible(true);

        $names = $method->invoke($manager, 'test', ['count' => 2]);
        $scoped = MasterProcess::getScopedInstanceName('test');

        self::assertContains('--name=' . MasterProcess::getMasterProcessName('test'), $names);
        self::assertContains('--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'test', 1), $names);
        self::assertContains('--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'test', 2), $names);

        foreach ($names as $name) {
            self::assertStringStartsWith('--name=weline-wls-', $name);
            self::assertStringContainsString($scoped, $name);
        }
    }

    public function testTrackedPidsUseEndpointFields(): void
    {
        $manager = new ServerInstanceManager();
        $method = new \ReflectionMethod($manager, 'collectTrackedPids');
        $method->setAccessible(true);

        $pids = $method->invoke($manager, 'test', [
            'pid' => 101,
            'launcher_pid' => 202,
            'master_pid' => 303,
            'count' => 0,
        ]);

        \sort($pids);

        self::assertSame([101, 202, 303], $pids);
    }
}
