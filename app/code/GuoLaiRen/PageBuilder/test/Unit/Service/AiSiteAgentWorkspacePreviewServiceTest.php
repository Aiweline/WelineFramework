<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspacePreviewService;
use GuoLaiRen\PageBuilder\Service\AiSitePreviewLinkRewriteService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

final class AiSiteAgentWorkspacePreviewServiceTest extends TestCase
{
    public function testBuildUnavailablePayloadReflectsConfirmedBuildPlanState(): void
    {
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn(['build_plan_confirmed' => 1]);

        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadByPublicId')
            ->with('demo-public', 8)
            ->willReturn($session);
        $sessionService->expects(self::once())
            ->method('loadScopeForStage')
            ->with($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            ->willReturn(['build_plan_confirmed' => 1]);

        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->with(['build_plan_confirmed' => 1])
            ->willReturn(['build_plan_confirmed' => 1]);

        $service = new AiSiteAgentWorkspacePreviewService(
            $sessionService,
            $scopeCompatibility,
            $this->createStub(AiSiteVirtualLayoutService::class),
            $this->createStub(PageRenderService::class),
        );

        $payload = $service->buildUnavailablePayload(8, 'demo-public', 'home');

        self::assertSame([
            'session_accessible' => true,
            'build_plan_confirmed' => true,
            'page_type' => 'home',
        ], $payload);
    }

    public function testInjectWorkspacePreviewNavLinksAppendsVisualEditorMessageBridge(): void
    {
        $visualUrlService = new AiSiteVisualUrlService($this->createUrlMock());
        $service = new AiSiteAgentWorkspacePreviewService(
            $this->createStub(AiSiteAgentSessionService::class),
            $this->createStub(AiSiteScopeCompatibilityService::class),
            $this->createStub(AiSiteVirtualLayoutService::class),
            $this->createStub(PageRenderService::class),
            new AiSitePreviewLinkRewriteService($visualUrlService),
            $visualUrlService,
        );

        $html = $service->injectWorkspacePreviewNavLinks(
            '<html><body><header><a href="/about">About</a></header></body></html>',
            [
                Page::TYPE_HOME => ['handle' => ''],
                Page::TYPE_ABOUT => ['handle' => 'about'],
            ],
            'demo-public',
            22
        );

        self::assertStringContainsString('PageBuilderVisualEditor', $html);
        self::assertStringContainsString('"page_type":"home_page"', $html);
        self::assertStringContainsString('"page_type":"about_page"', $html);
        self::assertStringContainsString('"preview_url":"https:\/\/backend.test\/pagebuilder\/backend\/ai-site-agent\/workspace-preview?public_id=demo-public&page_type=about_page&preview=1&visual_editor=1&virtual_theme_id=22"', $html);
        self::assertStringContainsString('link.setAttribute(\'href\', String(page.preview_url));', $html);
        self::assertMatchesRegularExpression('/<\/script>\n<\/body>/u', $html);
    }

    public function testBuildPreviewContextDoesNotTriggerAiPlaceholderGeneration(): void
    {
        $session = $this->createStub(AiSiteAgentSession::class);
        $scope = [
            'page_types' => [Page::TYPE_HOME],
            'draft_website_id' => 9,
            'virtual_pages_by_type' => [],
        ];

        $virtualLayoutService = $this->createMock(AiSiteVirtualLayoutService::class);
        $virtualLayoutService->expects(self::once())
            ->method('loadContext')
            ->with('demo-public', 8, Page::TYPE_HOME)
            ->willReturn([
                'session' => $session,
                'scope' => $scope,
                'virtual_theme_id' => 22,
            ]);
        $virtualLayoutService->expects(self::once())
            ->method('getResolvedLayout')
            ->with(22, Page::TYPE_HOME)
            ->willReturn([]);

        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->with($scope)
            ->willReturn($scope);
        $scopeCompatibility->expects(self::once())
            ->method('normalizePreviewContentLocale')
            ->with($scope, '')
            ->willReturn($scope);
        $scopeCompatibility->expects(self::once())
            ->method('resolvePreviewContentLocale')
            ->with($scope, '')
            ->willReturn('en_US');
        $scopeCompatibility->expects(self::once())
            ->method('resolveScopedPageTypes')
            ->with($scope)
            ->willReturn([Page::TYPE_HOME]);
        $scopeCompatibility->expects(self::once())
            ->method('buildVirtualPagesByType')
            ->with([Page::TYPE_HOME], $scope, false)
            ->willReturn([
                Page::TYPE_HOME => [
                    'title' => 'Demo Home',
                    'handle' => '',
                    'locale' => 'en_US',
                    'style_code' => 'default',
                    'style_settings' => [],
                    'blocks' => [],
                ],
            ]);
        $scopeCompatibility->expects(self::once())
            ->method('resolvePreviewPageType')
            ->with($this->isType('array'), Page::TYPE_HOME)
            ->willReturn(Page::TYPE_HOME);
        $scopeCompatibility->expects(self::once())
            ->method('localizeSharedLayoutConfigForScope')
            ->with([], $scope, Page::TYPE_HOME)
            ->willReturn([]);

        $service = new AiSiteAgentWorkspacePreviewService(
            $this->createStub(AiSiteAgentSessionService::class),
            $scopeCompatibility,
            $virtualLayoutService,
            $this->createStub(PageRenderService::class),
        );

        $context = $service->buildPreviewContext(8, 'demo-public', Page::TYPE_HOME);

        self::assertIsArray($context);
        self::assertSame(22, $context['virtual_theme_id']);
        self::assertSame(Page::TYPE_HOME, $context['page']->getData(Page::schema_fields_TYPE));
    }

    public function testBuildPreviewContextFallsBackToMaterializedAiHtmlBlocksWhenSessionBlocksAreCompacted(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentWorkspacePreviewService.php');
        self::assertIsString($source);

        self::assertStringContainsString('$materializedPreview = $this->resolveMaterializedAiHtmlPreviewData($scope, $pageType);', $source);
        self::assertStringContainsString('$virtualPage[\'blocks\'] = $virtualBlocks;', $source);
        self::assertStringContainsString('Page::RENDER_MODE_AI_HTML', $source);
        self::assertStringContainsString('loadMaterializedAiHtmlPreviewPageRow', $source);
    }

    public function testAiHtmlPreviewKeepsSharedHeaderAndFooter(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        self::assertStringContainsString('renderVisualMode($headerHtml, $aiHtml, $footerHtml', $source);
        self::assertStringContainsString('renderAiHtmlDocument($headerHtml, $aiHtml, $footerHtml', $source);
    }

    private function createUrlMock(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $query = $params === [] ? '' : ('?' . \http_build_query($params));
                return 'https://backend.test/' . \ltrim($path, '/') . $query;
            }
        );

        return $url;
    }
}
