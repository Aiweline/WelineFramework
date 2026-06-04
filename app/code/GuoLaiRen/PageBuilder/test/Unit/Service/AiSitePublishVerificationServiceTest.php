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
                'en_US',
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
            ['site_title' => 'Teen Patti Royal APK', 'target_domain' => 'unit-test.weline.test']
        );
    }

    public function testPublishVerificationDoesNotGateOnGeneratedPlanningCopy(): void
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

        $report = $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Teen Patti Royal APK', 'target_domain' => 'unit-test.weline.test']
        );

        self::assertTrue($report['passed']);
        self::assertTrue((bool)($report['domain']['skipped'] ?? false));
        self::assertTrue((bool)($report['pages']['home_page']['signals']['internal_planning_copy_marker'] ?? false));
        self::assertSame([], $report['pages']['home_page']['failures'] ?? null);
    }

    public function testPublishVerificationPassesWebsiteProfileLocaleToRenderer(): void
    {
        $page = $this->createPageModel(true);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with(
                self::isInstanceOf(Page::class),
                PageRenderService::MODE_LIVE,
                'fr_FR',
                null,
                185
            )
            ->willReturn(
                '<html><body class="pb-ai-site">'
                . '<header>Royal Card Arena</header>'
                . '<main><section class="pb-ai-generated-section"><h1>Royal Card Arena</h1>'
                . '<p>Localized download guidance stays visible after publish verification renders the page.</p>'
                . '</section></main>'
                . '<footer>Royal Card Arena</footer>'
                . '</body></html>'
            );

        $service = new AiSitePublishVerificationService($page, $renderer);
        $report = $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            [
                'site_title' => 'Royal Card Arena',
                'content_locale' => 'fr_FR',
                'target_domain' => 'unit-test.weline.test',
            ]
        );

        self::assertTrue($report['passed']);
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
            ['site_title' => 'Teen Patti Royal APK', 'target_domain' => 'unit-test.weline.test']
        );

        self::assertTrue($report['passed']);
        self::assertTrue((bool)($report['pages']['home_page']['signals']['virtual_theme_marker'] ?? false));
        self::assertTrue((bool)($report['pages']['home_page']['signals']['brand_visible'] ?? false));
    }

    public function testPublishVerificationDoesNotGateOnGeneratedBrandCopy(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->willReturn(
                '<html><body class="pb-ai-site">'
                . '<!-- Component content/home-page-hero-banner resolved via Weline_Theme virtual theme (theme_id=185) -->'
                . '<main><section class="pb-ai-generated-section"><h1>Fresh Android game picks</h1></section></main>'
                . '<footer>Download guidance</footer>'
                . '</body></html>'
            );

        $service = new AiSitePublishVerificationService($page, $renderer);
        $report = $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'India Card Game APK Guide', 'target_domain' => 'unit-test.weline.test']
        );

        self::assertTrue($report['passed']);
        self::assertFalse((bool)($report['pages']['home_page']['signals']['brand_visible'] ?? true));
        self::assertSame([], $report['pages']['home_page']['failures'] ?? null);
    }

    public function testPublishVerificationBlocksInaccessibleOnlineDomain(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::never())->method('render');

        $service = new AiSitePublishVerificationService($page, $renderer, static function (string $domain): array {
            self::assertSame('example.com', $domain);

            return [
                'passed' => false,
                'status_code' => 0,
                'error' => 'domain returned no HTTP response',
                'url' => 'https://example.com/',
            ];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publish domain is not accessible');
        $service->assertPublishedPagesRenderable(
            ['home_page' => ['page_id' => 74]],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Online Site', 'target_domain' => 'example.com']
        );
    }

    public function testPublishVerificationBlocksPlanJsonModuleCountMismatch(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::never())->method('render');

        $service = new AiSitePublishVerificationService($page, $renderer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plan_json module count mismatch');
        $service->assertPublishedPagesRenderable(
            [
                'home_page' => [
                    'page_id' => 74,
                    'block_nodes' => [
                        ['html' => '<section>Hero</section>'],
                    ],
                ],
            ],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Local Site', 'target_domain' => 'unit-test.weline.test'],
            [
                'page_types' => ['home_page'],
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'hero' => [
                                'status' => 1,
                                'section_code' => 'content/home-page-hero',
                                'html' => '<section>Hero</section>',
                            ],
                            'features' => [
                                'status' => 1,
                                'section_code' => 'content/home-page-features',
                                'html' => '<section>Features</section>',
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    public function testPublishVerificationCountsAiLayoutBlocksAgainstPlanJson(): void
    {
        $page = $this->createPageModel(false);
        $renderer = $this->createMock(PageRenderService::class);
        $renderer->expects(self::once())
            ->method('render')
            ->willReturn(
                '<html><body class="pb-ai-site">'
                . '<header>Local Site</header>'
                . '<!-- Component content/home-page-hero resolved via Weline_Theme virtual theme (theme_id=185) -->'
                . '<main><section class="pb-ai-generated-section"><h1>Hero</h1></section>'
                . '<section class="pb-ai-generated-section"><h2>Features</h2></section></main>'
                . '<footer>Local Site</footer>'
                . '</body></html>'
            );

        $service = new AiSitePublishVerificationService($page, $renderer);
        $report = $service->assertPublishedPagesRenderable(
            [
                'home_page' => [
                    'page_id' => 74,
                    'ai_layout' => [
                        'blocks' => [
                            ['html' => '<section>Hero</section>'],
                            ['html' => '<section>Features</section>'],
                        ],
                    ],
                ],
            ],
            185,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            ['site_title' => 'Local Site', 'target_domain' => 'unit-test.weline.test'],
            [
                'page_types' => ['home_page'],
                'plan_json' => [
                    'pages' => [
                        'home_page' => [
                            'hero' => [
                                'status' => 1,
                                'section_code' => 'content/home-page-hero',
                                'html' => '<section>Hero</section>',
                            ],
                            'features' => [
                                'status' => 1,
                                'section_code' => 'content/home-page-features',
                                'html' => '<section>Features</section>',
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertTrue($report['passed']);
        self::assertSame(['home_page' => 2], $report['module_alignment']['actual_blocks_by_page'] ?? []);
    }

    public function testPublishServiceCallsVerificationBeforeReturningSuccess(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePublishService.php');
        self::assertIsString($source);

        self::assertStringContainsString('AiSitePublishVerificationService $publishVerificationService', $source);
        self::assertStringContainsString('$this->publishVerificationService->assertPublishedPagesRenderable(', $source);
        self::assertStringContainsString("'publish_verification' => $verification", $source);
        self::assertStringContainsString("'plan_json' => \\is_array(\$materializationProfile['plan_json'] ?? null) ? \$materializationProfile['plan_json'] : []", $source);
    }

    public function testPublishServiceVerifiesRequestedPageTypesInsteadOfMaterializedSnapshotKeys(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePublishService.php');
        self::assertIsString($source);

        self::assertStringContainsString(
            '$verificationPagesByType = $this->resolvePublishVerificationPagesByType($pageTypes, $materializedPagesByType);',
            $source
        );
        self::assertStringContainsString('$this->sanitizeAiLayoutsForMaterializedPages($verificationPagesByType);', $source);
        self::assertStringContainsString('$verificationPagesByType,', $source);
        self::assertStringContainsString('requested page %{1} was not materialized', $source);
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
