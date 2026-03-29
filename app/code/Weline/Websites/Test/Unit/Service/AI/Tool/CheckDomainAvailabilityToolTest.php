<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Service\AI\Tool\CheckDomainAvailabilityTool;

class CheckDomainAvailabilityToolTest extends TestCase
{
    public function testExecuteReturnsSimulatedAvailabilityForLocalDomainsWithoutAccount(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->never())->method('execute');

        $tool = new CheckDomainAvailabilityTool($queryService);
        $result = $tool->execute([
            'domains' => ['weline-dev.local', 'api.localhost'],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('weline-dev.local', $result[0]['domain'] ?? null);
        $this->assertTrue((bool)($result[0]['available'] ?? false));
        $this->assertTrue((bool)($result[0]['simulated'] ?? false));
    }

    public function testExecuteRequiresAccountForNonLocalDomain(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->never())->method('execute');

        $tool = new CheckDomainAvailabilityTool($queryService);
        $result = $tool->execute([
            'domains' => ['example.com'],
        ]);

        $this->assertSame(['error' => 'account_id and domains are required'], $result);
    }

    public function testExecuteUsesSimulatedAvailabilityWhenDemoAccountReturnsEmpty(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->with('websites', 'checkAvailability', [
                'account_id' => 900001,
                'domains' => ['example.com'],
            ])
            ->willReturn([]);

        $tool = new CheckDomainAvailabilityTool($queryService);
        $result = $tool->execute([
            'account_id' => 900001,
            'domains' => ['example.com'],
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('example.com', $result[0]['domain'] ?? null);
        $this->assertTrue((bool)($result[0]['available'] ?? false));
        $this->assertTrue((bool)($result[0]['simulated'] ?? false));
    }
}
