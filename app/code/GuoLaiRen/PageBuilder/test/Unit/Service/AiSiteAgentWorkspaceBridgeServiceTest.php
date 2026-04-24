<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceBridgeService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService as WebsitesDomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;

final class AiSiteAgentWorkspaceBridgeServiceTest extends TestCase
{
    public function testBuildRegistrarAccountOptionsFromRowsFormatsLabelsAndSkipsInvalidRows(): void
    {
        $service = $this->createService();

        $options = $service->buildRegistrarAccountOptionsFromRows([
            ['account_id' => 0, 'registrar_name' => 'invalid'],
            ['account_id' => 11, 'registrar_name' => 'Namecheap', 'account_name' => 'Main'],
            ['account_id' => 12, 'registrar_code' => 'spaceship', 'account_name' => 'Backup'],
            ['account_id' => 13, 'account_name' => 'Fallback only'],
        ]);

        self::assertSame([
            [
                'account_id' => 11,
                'label' => 'Namecheap - Main',
                'registrar_name' => 'Namecheap',
                'registrar_code' => '',
                'account_name' => 'Main',
            ],
            [
                'account_id' => 12,
                'label' => 'spaceship - Backup',
                'registrar_name' => 'spaceship',
                'registrar_code' => 'spaceship',
                'account_name' => 'Backup',
            ],
            [
                'account_id' => 13,
                'label' => 'Fallback only',
                'registrar_name' => '',
                'registrar_code' => '',
                'account_name' => 'Fallback only',
            ],
        ], $options);
    }

    public function testBuildWorkspaceRegistrarSelectionFallsBackToViewScopeAndResolvesLabel(): void
    {
        $service = $this->createService();

        $selection = $service->buildWorkspaceRegistrarSelection(
            ['recommended_domain_list' => []],
            [
                'recommended_domain_list' => ['example.com'],
                'preferred_registrar_account_id' => 12,
            ],
            [
                [
                    'account_id' => 12,
                    'label' => 'Spaceship - Backup',
                    'registrar_name' => 'Spaceship',
                    'registrar_code' => 'spaceship',
                    'account_name' => 'Backup',
                ],
            ],
            false
        );

        self::assertSame(['example.com'], $selection['recommended_domain_list']);
        self::assertSame('Spaceship - Backup', $selection['recommended_registrar_label']);
        self::assertSame(12, $selection['preferred_registrar_account_id']);
    }

    public function testBuildLinkedWebsitesScopeFallsBackToTargetDomainRecommendation(): void
    {
        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->willReturn([
                'site_title' => 'Demo',
                'target_domain' => 'Example.COM',
                'user_description' => 'Short brief',
                'page_types' => ['home', 'about'],
            ]);

        $url = $this->createMock(Url::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => 'pb-public'])
            ->willReturn('/backend/workspace?public_id=pb-public');

        $service = $this->createService($scopeCompatibility, $url);
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn([]);
        $session->method('getPublicId')->willReturn('pb-public');

        $result = $service->buildLinkedWebsitesScopeFromPageBuilderSession($session);

        self::assertSame('example.com', $result['target_domain']);
        self::assertSame(['example.com'], $result['recommended_domain_list']);
        self::assertSame(['home', 'about'], $result['recommended_pages']);
        self::assertSame('/backend/workspace?public_id=pb-public', $result['pagebuilder_workspace_url']);
    }

    private function createService(
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?Url $url = null
    ): AiSiteAgentWorkspaceBridgeService {
        return new AiSiteAgentWorkspaceBridgeService(
            $this->createMock(AiSiteAgentSessionService::class),
            $scopeCompatibilityService ?? $this->createStub(AiSiteScopeCompatibilityService::class),
            $url ?? $this->createStub(Url::class),
            $this->createStub(FrameworkQueryService::class),
            $this->createStub(WebsitesSessionService::class),
            $this->createStub(WebsitesDomainPurchaseWorkbenchService::class),
        );
    }
}
