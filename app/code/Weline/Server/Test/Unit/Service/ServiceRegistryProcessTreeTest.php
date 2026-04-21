<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceRegistry;

final class ServiceRegistryProcessTreeTest extends TestCase
{
    public function testManagedPidIndexesResolveServiceRootAndLauncherPids(): void
    {
        $registry = new ServiceRegistry();
        $instance = new ServiceInstance(role: 'worker', instanceId: 1, pid: 202);
        $instance->setProcessTreePids(202, 1202, 1302);

        $registry->addInstance($instance);

        self::assertSame($instance, $registry->getInstanceByPid(202));
        self::assertSame($instance, $registry->getInstanceByRootPid(1202));
        self::assertSame($instance, $registry->getInstanceByLauncherPid(1302));
        self::assertSame($instance, $registry->getInstanceByManagedPid(1302));
    }

    public function testUpdateAndRemoveInstanceRefreshManagedPidIndexes(): void
    {
        $registry = new ServiceRegistry();
        $instance = new ServiceInstance(role: 'worker', instanceId: 1, pid: 202);
        $instance->setProcessTreePids(202, 1202, 1302);
        $registry->addInstance($instance);

        $instance->setProcessTreePids(302, 2302, 2402);
        $registry->updateInstance($instance);

        self::assertNull($registry->getInstanceByManagedPid(202));
        self::assertNull($registry->getInstanceByManagedPid(1202));
        self::assertSame($instance, $registry->getInstanceByManagedPid(302));
        self::assertSame($instance, $registry->getInstanceByManagedPid(2302));
        self::assertSame($instance, $registry->getInstanceByManagedPid(2402));

        $registry->removeInstance('worker', 1);

        self::assertNull($registry->getInstanceByManagedPid(302));
        self::assertNull($registry->getInstanceByManagedPid(2302));
        self::assertNull($registry->getInstanceByManagedPid(2402));
    }
}
