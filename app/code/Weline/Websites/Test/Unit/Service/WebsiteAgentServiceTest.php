<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Service\DomainPurchaseService;
use Weline\Websites\Service\DomainResolveService;
use Weline\Websites\Service\WebsiteAgentService;

class WebsiteAgentServiceTest extends TestCase
{
    public function testRecommendAvailableDomainReturnsFirstAvailableCandidateInPriorityOrder(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->with(
                'websites',
                'checkAvailability',
                $this->callback(function (array $params): bool {
                    $this->assertSame(7, $params['account_id'] ?? null);
                    $this->assertSame('beanlane.com', $params['domains'][0] ?? null);
                    $this->assertContains('beanlane.net', $params['domains'] ?? []);
                    return true;
                })
            )
            ->willReturn([
                ['domain' => 'beanlane.com', 'available' => false],
                ['domain' => 'beanlane.net', 'available' => true],
                ['domain' => 'coffee.com', 'available' => true],
            ]);

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Coffee brand storefront',
            7,
            'beanlane.com'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('beanlane.net', $result['domain'] ?? '');
        $this->assertSame('beanlane.com', $result['candidate_domains'][0] ?? '');
        $this->assertSame('beanlane.net', $result['checked_results'][1]['domain'] ?? '');
        $this->assertTrue((bool)($result['checked_results'][1]['available'] ?? false));
    }

    public function testRecommendAvailableDomainReturnsFailureWhenNoCandidateIsAvailable(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->willReturn([
                ['domain' => 'atlaslab.com', 'available' => false],
                ['domain' => 'atlaslab.net', 'available' => false],
                ['domain' => 'atlaslab.site', 'available' => false, 'error' => 'registry timeout'],
            ]);

        $service = $this->createService($queryService);
        $result = $service->recommendAvailableDomain(
            'Atlas lab marketing site',
            9,
            'atlaslab.com'
        );

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('domain', $result);
        $this->assertNotEmpty($result['candidate_domains']);
        $this->assertCount(5, $result['checked_results']);
        $this->assertSame('atlaslab.site', $result['checked_results'][2]['domain'] ?? '');
        $this->assertSame('registry timeout', $result['checked_results'][2]['error'] ?? '');
    }

    private function createService(FrameworkQueryService $queryService): WebsiteAgentService
    {
        return new WebsiteAgentService(
            $this->createMock(DomainPurchaseService::class),
            $this->createMock(DomainResolveService::class),
            $queryService
        );
    }
}
