<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSitePublishVerificationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\PageRenderService;
use PHPUnit\Framework\TestCase;

final class AiSitePublishVerificationServiceTest extends TestCase
{
    public function testRejectsPublishedVirtualThemePageRenderedAsDefaultTemplate(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with(
                self::isInstanceOf(Page::class),
                PageRenderService::MODE_LIVE,
                null,
                null,
                185
            )
            ->willReturn('<html><body><h1>欢迎访问</h1><p>默认页面模板</p></body></html>');

        $service = new AiSitePublishVerificationService($page, $renderer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('default template markers');
        $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Teen Patti Royal APK']
        );
    }

    public function testRejectsPublishedPageWithPlanningObservationCopy(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->willReturn(
                '<html><body class="pb-ai-site">'
                . '<header>Teen Patti Royal APK</header>'
                . '<!-- Component content/home-page-hero-banner resolved via Weline_Theme virtual theme (theme_id=185) -->'
                . '<main><section class="pb-ai-generated-section"><h1>Teen Patti Royal APK</h1>'
                . '<p>Visitors see three polished cards before publishing and understand how to download.</p>'
                . '</section></main>'
                . '<footer>Teen Patti Royal APK</footer>'
                . '</body></html>'
            );

        $service = new AiSitePublishVerificationService($page, $renderer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internal planning or visitor-observation copy');
        $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Teen Patti Royal APK']
        );
    }

    public function testPassesPublishedVirtualThemePageWithAiThemeMarkersAndBrand(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->willReturn(
                '<html><body class="pb-ai-site">'
                . '<header>Teen Patti Royal APK</header>'
                . '<!-- Component content/home-page-hero-banner resolved via Weline_Theme virtual theme (theme_id=185) -->'
                . '<main><section class="pb-ai-generated-section" style="background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>Teen Patti Royal APK</h1></section></main>'
                . '<footer>Teen Patti Royal APK</footer>'
                . '</body></html>'
            );

        $service = new AiSitePublishVerificationService($page, $renderer);
        $report = $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Teen Patti Royal APK']
        );

        self::assertTrue($report['passed']);
        self::assertTrue((bool)($report['pages']['home_page']['signals']['virtual_theme_marker'] ?? false));
        self::assertTrue((bool)($report['pages']['home_page']['signals']['brand_visible'] ?? false));
    }

    public function testPublishServiceCallsVerificationBeforeReturningSuccess(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePublishService.php');
        self::assertIsString($source);

        self::assertStringContainsString('AiSitePublishVerificationService $publishVerificationService', $source);
        self::assertStringContainsString('$this->publishVerificationService->assertPublishedPagesRenderable(', $source);
        self::assertStringContainsString("'publish_verification' => $verification", $source);
    }

    public function testPublishOperationPersistsVerificationReportInWorkspaceScope(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $methodStart = \strpos($source, 'private function runPublishOperation(');
        self::assertIsInt($methodStart);
        $methodSource = \substr($source, $methodStart);

        self::assertStringContainsString('$scope[\'publish_verification\'] = \\is_array($published[\'publish_verification\'] ?? null)', $methodSource);
        self::assertLessThan(
            \strpos($methodSource, '$this->sessionService->replaceScope($session->getId(), $adminId, $scope);'),
            \strpos($methodSource, '$scope[\'publish_verification\'] = \\is_array($published[\'publish_verification\'] ?? null)'),
            'Publish verification report must be stored before the workspace scope is persisted.'
        );
        self::assertStringNotContainsString('redirect_url', $methodSource);
    }

    public function testSessionScopeKeepsPublishVerificationReport(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');
        self::assertIsString($source);

        self::assertStringContainsString("'publish_verification',", $source);
        self::assertStringContainsString("'materialized_pages_by_type',", $source);
    }

    private function createPageModel(bool $aiHtmlMode): Page
    {
        $page = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'load', 'getId', 'isAiHtmlRenderMode'])
            ->addMethods(['clearQuery'])
            ->getMock();
        $page->method('clearData')->willReturnSelf();
        $page->method('clearQuery')->willReturnSelf();
        $page->method('load')->willReturnSelf();
        $page->method('getId')->willReturn(74);
        $page->method('isAiHtmlRenderMode')->willReturn($aiHtmlMode);

        return $page;
    }
}
