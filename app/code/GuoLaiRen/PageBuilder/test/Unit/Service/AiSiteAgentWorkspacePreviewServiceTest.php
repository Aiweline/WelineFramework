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
    public function testBuildUnavailablePayloadReflectsConfirmedPlanJsonState(): void
    {
        $scope = ['plan_json' => ['confirmed' => 1]];

        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn($scope);

        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadByPublicId')
            ->with('demo-public', 8)
            ->willReturn($session);
        $sessionService->expects(self::once())
            ->method('loadScopeForStage')
            ->with($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            ->willReturn($scope);

        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->with($scope)
            ->willReturn($scope);

        $service = new AiSiteAgentWorkspacePreviewService(
            $sessionService,
            $scopeCompatibility,
            $this->createStub(AiSiteVirtualLayoutService::class),
            $this->createStub(PageRenderService::class),
        );

        $payload = $service->buildUnavailablePayload(8, 'demo-public', 'home');

        self::assertSame([
            'session_accessible' => true,
            'plan_json' => ['confirmed' => 1],
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
            'plan_json' => [
                'confirmed' => 1,
                'pages' => [
                    Page::TYPE_HOME => [
                        'title' => 'Demo Home',
                        'handle' => '',
                        'locale' => 'en_US',
                        'style_code' => 'default',
                        'style_settings' => [],
                        'hero' => [
                            'status' => 0,
                            'fields' => [],
                        ],
                    ],
                ],
            ],
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
        self::assertSame(0, $context['plan_json_pages'][Page::TYPE_HOME]['hero']['status'] ?? null);
    }

    public function testBuildPreviewContextFallsBackToVirtualThemeLayoutWhenPlanJsonPagesAreMissing(): void
    {
        $session = $this->createStub(AiSiteAgentSession::class);
        $scope = [
            'site_title' => 'Demo Site',
            'plan_json' => [
                'confirmed' => 1,
                'pages' => [],
            ],
        ];
        $layout = [
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'content' => [
                ['code' => 'content/fake-hero', 'enabled' => true, 'config' => []],
            ],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
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
            ->willReturn($layout);

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
            ->method('localizeSharedLayoutConfigForScope')
            ->with($layout, $scope, Page::TYPE_HOME)
            ->willReturn($layout);

        $service = new AiSiteAgentWorkspacePreviewService(
            $this->createStub(AiSiteAgentSessionService::class),
            $scopeCompatibility,
            $virtualLayoutService,
            $this->createStub(PageRenderService::class),
        );

        $context = $service->buildPreviewContext(8, 'demo-public', Page::TYPE_HOME);

        self::assertIsArray($context);
        self::assertSame(Page::RENDER_MODE_THEME, $context['page']->getData(Page::schema_fields_RENDER_MODE));
        self::assertSame('virtual_theme_layout', $context['plan_json_pages'][Page::TYPE_HOME]['preview_source'] ?? null);
        self::assertSame('Demo Site', $context['page']->getData(Page::schema_fields_TITLE));
    }

    public function testBuildPreviewContextCanUseVirtualThemeIdFromPreviewUrlWhenScopeContextIsIncomplete(): void
    {
        $scope = [
            'site_title' => 'URL Theme Site',
            'plan_json' => [
                'confirmed' => 1,
                'pages' => [],
            ],
        ];
        $session = $this->createStub(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn($scope);
        $layout = [
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'content' => [
                ['code' => 'content/url-theme-hero', 'enabled' => true, 'config' => []],
            ],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
        ];

        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadByPublicId')
            ->with('demo-public', 8)
            ->willReturn($session);
        $sessionService->expects(self::once())
            ->method('loadScopeForStage')
            ->with($session, AiSiteAgentSession::STAGE_VISUAL_EDIT, ['plan_json'])
            ->willReturn($scope);

        $virtualLayoutService = $this->createMock(AiSiteVirtualLayoutService::class);
        $virtualLayoutService->expects(self::once())
            ->method('loadContext')
            ->with('demo-public', 8, Page::TYPE_HOME)
            ->willReturn(null);
        $virtualLayoutService->expects(self::once())
            ->method('getResolvedLayout')
            ->with(784, Page::TYPE_HOME)
            ->willReturn($layout);

        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::exactly(2))
            ->method('normalizeScope')
            ->willReturnArgument(0);
        $scopeCompatibility->expects(self::exactly(2))
            ->method('normalizePreviewContentLocale')
            ->willReturnArgument(0);
        $scopeCompatibility->expects(self::once())
            ->method('resolvePreviewContentLocale')
            ->willReturn('en_US');
        $scopeCompatibility->expects(self::once())
            ->method('localizeSharedLayoutConfigForScope')
            ->willReturn($layout);

        $service = new AiSiteAgentWorkspacePreviewService(
            $sessionService,
            $scopeCompatibility,
            $virtualLayoutService,
            $this->createStub(PageRenderService::class),
        );

        $context = $service->buildPreviewContext(8, 'demo-public', Page::TYPE_HOME, '', '', 784);

        self::assertIsArray($context);
        self::assertSame(784, $context['virtual_theme_id']);
        self::assertSame(Page::RENDER_MODE_THEME, $context['page']->getData(Page::schema_fields_RENDER_MODE));
        self::assertSame('virtual_theme_layout', $context['plan_json_pages'][Page::TYPE_HOME]['preview_source'] ?? null);
    }

    public function testBuildPreviewContextDoesNotUseMaterializedAiHtmlAsTruthSource(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentWorkspacePreviewService.php');
        self::assertIsString($source);

        self::assertStringContainsString('Page::RENDER_MODE_AI_HTML', $source);
        self::assertStringNotContainsString('resolveMaterializedAiHtmlPreviewData', $source);
        self::assertStringNotContainsString('loadMaterializedAiHtmlPreviewPageRow', $source);
        self::assertStringNotContainsString('$materializedPreview', $source);
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
