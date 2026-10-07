<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Domain as DomainModel;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolMaintenanceService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\ServerIpService;

class DomainPoolMaintenanceServiceManageTest extends TestCase
{
    public function testDeletePoolEntryRejectsInvalidId(): void
    {
        $svc = $this->makeService();
        $r = $svc->deletePoolEntry(0);
        self::assertFalse($r['ok']);
        self::assertSame('pool_id 不能为空', $r['msg']);
    }

    public function testUpdatePoolEntryRejectsInvalidId(): void
    {
        $svc = $this->makeService();
        $r = $svc->updatePoolEntry(0, 'note', DomainPool::STATUS_ACTIVE);
        self::assertFalse($r['ok']);
        self::assertSame('pool_id 不能为空', $r['msg']);
    }

    private function makeService(): DomainPoolMaintenanceService
    {
        return new DomainPoolMaintenanceService(
            $this->createMock(DomainPool::class),
            $this->createMock(DomainModel::class),
            $this->createMock(DomainResolveService::class),
            $this->createMock(ServerIpService::class),
        );
    }
}
