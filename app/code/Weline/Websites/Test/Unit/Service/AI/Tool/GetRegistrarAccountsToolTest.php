<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Service\AI\Tool\GetRegistrarAccountsTool;

class GetRegistrarAccountsToolTest extends TestCase
{
    public function testExecuteFallsBackToSimulatedAccountsWhenProviderReturnsEmpty(): void
    {
        $queryService = $this->createMock(FrameworkQueryService::class);
        $queryService->expects($this->once())
            ->method('execute')
            ->with('websites', 'getRegistrarAccounts', ['status' => 'active'])
            ->willReturn([]);

        $tool = new GetRegistrarAccountsTool($queryService);
        $result = $tool->execute([]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertSame(900001, $result[0]['account_id'] ?? null);
        $this->assertTrue((bool)($result[0]['simulated'] ?? false));
    }
}
