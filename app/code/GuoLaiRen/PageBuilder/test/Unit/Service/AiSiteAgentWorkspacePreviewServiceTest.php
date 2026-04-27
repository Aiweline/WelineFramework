<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspacePreviewService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspacePreviewServiceTest extends TestCase
{
    public function testBuildUnavailablePayloadReflectsConfirmedTaskPlanState(): void
    {
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getScopeArray')->willReturn(['task_plan_confirmed' => 1]);

        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadByPublicId')
            ->with('demo-public', 8)
            ->willReturn($session);
        $sessionService->expects(self::once())
            ->method('loadScopeForStage')
            ->with($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            ->willReturn(['task_plan_confirmed' => 1]);

        $scopeCompatibility = $this->createMock(AiSiteScopeCompatibilityService::class);
        $scopeCompatibility->expects(self::once())
            ->method('normalizeScope')
            ->with(['task_plan_confirmed' => 1])
            ->willReturn(['task_plan_confirmed' => 1]);

        $service = new AiSiteAgentWorkspacePreviewService(
            $sessionService,
            $scopeCompatibility,
            $this->createStub(AiSiteVirtualLayoutService::class),
            $this->createStub(PageRenderService::class),
        );

        $payload = $service->buildUnavailablePayload(8, 'demo-public', 'home');

        self::assertSame([
            'session_accessible' => true,
            'task_plan_confirmed' => true,
            'page_type' => 'home',
        ], $payload);
    }

    public function testInjectWorkspacePreviewNavLinksAppendsVisualEditorMessageBridge(): void
    {
        $service = new AiSiteAgentWorkspacePreviewService(
            $this->createStub(AiSiteAgentSessionService::class),
            $this->createStub(AiSiteScopeCompatibilityService::class),
            $this->createStub(AiSiteVirtualLayoutService::class),
            $this->createStub(PageRenderService::class),
        );

        $html = $service->injectWorkspacePreviewNavLinks(
            '<html><body><a href="/about">About</a></body></html>',
            [
                'home' => ['handle' => ''],
                'about' => ['handle' => 'about'],
            ]
        );

        self::assertStringContainsString('PageBuilderVisualEditor', $html);
        self::assertStringContainsString('"page_type":"home"', $html);
        self::assertStringContainsString('"page_type":"about"', $html);
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
}
