<?php
declare(strict_types=1);

namespace Weline\Saas\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Model\ProvisioningStep;
use Weline\Saas\Service\DomainLifecycleOrchestrationService;
use Weline\Saas\Service\DomainProvisioningService;
use Weline\Websites\Model\Domain;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\DomainPoolResolveService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\HealthCheckService;

class DomainLifecycleOrchestrationServiceTest extends TestCase
{
    private DomainLifecycleOrchestrationService $service;

    /** @var DomainProvisioningService&MockObject */
    private DomainProvisioningService $provisioningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisioningService = $this->createMock(DomainProvisioningService::class);

        $this->service = new DomainLifecycleOrchestrationService(
            $this->provisioningService,
            $this->createMock(ProvisioningOrder::class),
            $this->createMock(ProvisioningStep::class),
            $this->createMock(Domain::class),
            $this->createMock(DomainPool::class),
            $this->createMock(DomainResolveService::class),
            $this->createMock(DomainPoolResolveService::class),
            $this->createMock(HealthCheckService::class)
        );
    }

    public function testStartPurchasedLifecycleRejectsInvalidInput(): void
    {
        $result = $this->service->startPurchasedLifecycle('   ', 0, []);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    public function testProcessOrderRejectsMissingOrder(): void
    {
        $result = $this->service->processOrder(0);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
    }

    public function testGetDomainLifecycleStatusReturnsFailureWhenOrderMissing(): void
    {
        $this->provisioningService
            ->method('getOrderByDomain')
            ->with('example.com')
            ->willReturn(null);

        $result = $this->service->getDomainLifecycleStatus('example.com');

        $this->assertFalse($result['success']);
    }

    public function testNormalizeSubdomainsFallsBackToDefaultSet(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('normalizeSubdomains');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, ' , ');

        $this->assertSame(['@', 'www'], $result);
    }
}
