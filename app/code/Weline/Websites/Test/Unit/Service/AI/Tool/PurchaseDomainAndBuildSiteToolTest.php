<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AI\Tool;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AI\Tool\PurchaseDomainAndBuildSiteTool;
use Weline\Websites\Service\WebsiteAgentService;

class PurchaseDomainAndBuildSiteToolTest extends TestCase
{
    public function testExecuteAllowsLocalDomainWithoutAccountId(): void
    {
        $agentService = $this->createMock(WebsiteAgentService::class);
        $agentService->expects($this->once())
            ->method('buildFromDescription')
            ->with('weline-dev.local', 'weline-dev.local', 900001, null)
            ->willReturn(['success' => true, 'domain' => 'weline-dev.local']);

        $tool = new PurchaseDomainAndBuildSiteTool($agentService);
        $result = $tool->execute([
            'domain' => 'weline-dev.local',
        ]);

        $this->assertTrue((bool)($result['success'] ?? false));
    }

    public function testExecuteRequiresAccountIdForNonLocalDomain(): void
    {
        $agentService = $this->createMock(WebsiteAgentService::class);
        $agentService->expects($this->never())->method('buildFromDescription');

        $tool = new PurchaseDomainAndBuildSiteTool($agentService);
        $result = $tool->execute([
            'domain' => 'example.com',
        ]);

        $this->assertSame(
            ['success' => false, 'message' => 'domain and account_id are required'],
            $result
        );
    }
}
