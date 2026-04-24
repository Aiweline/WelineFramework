<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
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
}
