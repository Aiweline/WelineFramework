<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\WorkerProvider;

final class WorkerProviderHealthCheckTest extends TestCase
{
    public function testHealthCheckUsesTrackedRootPidWhenChildPidIsStale(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 999999,
            state: ServiceInstance::STATE_READY,
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());

        $result = (new WorkerProvider())->healthCheck($instance);

        self::assertTrue($result->isHealthy());
    }
}
