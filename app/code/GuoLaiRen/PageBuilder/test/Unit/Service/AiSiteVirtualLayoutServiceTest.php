<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteVirtualLayoutServiceTest extends TestCase
{
    public function testLoadContextReturnsNullForInvalidInputs(): void
    {
        $sessionSvc = $this->createMock(AiSiteAgentSessionService::class);
        $sessionSvc->expects(self::never())->method('loadByPublicId');
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $vt = $this->createMock(VirtualThemeLayout::class);
        $svc = new AiSiteVirtualLayoutService($sessionSvc, $scopeSvc, $vt);

        self::assertNull($svc->loadContext('', 1, Page::TYPE_HOME));
        self::assertNull($svc->loadContext('pub_x', 0, Page::TYPE_HOME));
        self::assertNull($svc->loadContext('pub_x', 1, '  '));
    }

    public function testLoadContextReturnsNullWhenVirtualThemeIdMissing(): void
    {
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn([
            'virtual_theme_id' => 0,
            'page_types' => [Page::TYPE_HOME],
        ]);
        $session->method('getVirtualThemeId')->willReturn(0);

        $sessionSvc = $this->createMock(AiSiteAgentSessionService::class);
        $sessionSvc->method('loadByPublicId')->willReturn($session);

        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $vt = $this->createMock(VirtualThemeLayout::class);
        $svc = new AiSiteVirtualLayoutService($sessionSvc, $scopeSvc, $vt);

        self::assertNull($svc->loadContext('pub_1', 1, Page::TYPE_HOME));
    }

    public function testSaveVirtualPagePatchMergesPatchAndCallsReplaceScope(): void
    {
        $captured = null;
        $sessionSvc = $this->createMock(AiSiteAgentSessionService::class);
        $sessionSvc->expects(self::once())->method('replaceScope')->willReturnCallback(
            function (int $sid, int $aid, array $scope) use (&$captured): bool {
                $captured = ['sid' => $sid, 'aid' => $aid, 'scope' => $scope];
                return true;
            }
        );
        $scopeSvc = new AiSiteScopeCompatibilityService(LayoutConfigNormalizer::getInstance());
        $vt = $this->createMock(VirtualThemeLayout::class);
        $svc = new AiSiteVirtualLayoutService($sessionSvc, $scopeSvc, $vt);

        $scope = [
            'page_types' => [Page::TYPE_HOME],
            'virtual_pages_by_type' => [],
        ];
        $out = $svc->saveVirtualPagePatch(42, 7, $scope, Page::TYPE_HOME, ['title' => 'Patched']);

        self::assertSame('Patched', $out['title'] ?? null);
        self::assertNotNull($captured);
        self::assertSame(42, $captured['sid']);
        self::assertSame(7, $captured['aid']);
        $vpt = $captured['scope']['virtual_pages_by_type'] ?? [];
        self::assertIsArray($vpt);
        self::assertArrayHasKey(Page::TYPE_HOME, $vpt);
        self::assertSame('Patched', $vpt[Page::TYPE_HOME]['title'] ?? null);
    }
}
