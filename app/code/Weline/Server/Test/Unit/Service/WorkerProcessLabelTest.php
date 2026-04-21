<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\WorkerProcessLabel;

final class WorkerProcessLabelTest extends TestCase
{
    public function testBuildLogTagDistinguishesMaintenanceWorkers(): void
    {
        self::assertSame(
            'MaintenanceSSL#1:16995@default',
            WorkerProcessLabel::buildLogTag(true, true, 1, 16995, 'default')
        );
        self::assertSame(
            'Maintenance#2:18080@test',
            WorkerProcessLabel::buildLogTag(false, true, 2, 18080, 'test')
        );
    }

    public function testBuildLogTagKeepsNormalWorkerLabels(): void
    {
        self::assertSame(
            'WorkerSSL#1:16895@default',
            WorkerProcessLabel::buildLogTag(true, false, 1, 16895, 'default')
        );
        self::assertSame(
            'Worker#3:18081@test',
            WorkerProcessLabel::buildLogTag(false, false, 3, 18081, 'test')
        );
    }

    public function testBuildProcessTitleIncludesRoleAndTransport(): void
    {
        $defaultScope = MasterProcess::getScopedInstanceName('default');
        $testScope = MasterProcess::getScopedInstanceName('test');

        self::assertSame(
            "weline-wls-maintenance-ssl-{$defaultScope}-1-16995",
            WorkerProcessLabel::buildProcessTitle(true, true, 1, 16995, 'default')
        );
        self::assertSame(
            "weline-wls-worker-http-{$testScope}-2-18080",
            WorkerProcessLabel::buildProcessTitle(false, false, 2, 18080, 'test')
        );
    }
}
