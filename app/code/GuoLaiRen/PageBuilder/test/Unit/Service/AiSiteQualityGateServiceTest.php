<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use PHPUnit\Framework\TestCase;

final class AiSiteQualityGateServiceTest extends TestCase
{
    public function testInspectScopePassesForRenderedStageOneContentWithThemeAndSvgFallback(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>皇家棋牌游戏</header><main><section class="pb-test-section" style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>页脚导航</footer>',
        ]);

        self::assertTrue($report['passed'], \json_encode($report, \JSON_UNESCAPED_UNICODE));
    }

    public function testInspectScopeFailsForInternalCopyAndBrokenImage(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><section><h2>AI content placeholder</h2><img src="https://example.com/hero.jpg" alt="hero"></section><footer>Footer</footer>',
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
    }

    public function testInspectScopeRejectsStockImageUrlWithoutFileExtension(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img src="://images.unsplash.com/photo-1601370690183-1c7796ecec61?w=1200&q=80" alt="game table"><h1>Premium card play for every table</h1><p>Clear rules, safe access, and fast support for players.</p></section></main><footer>Footer</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
        self::assertContains(
            '://images.unsplash.com/photo-1601370690183-1c7796ecec61?w=1200&q=80',
            $report['page_reports']['home_page']['visuals']['broken_images'] ?? []
        );
    }

    public function testInspectScopeAllowsVisitorFormPlaceholderAttributes(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p><form aria-label="APK support form"><input name="name" placeholder="Your name"><input name="phone" placeholder="+91 phone number"><button type="button">Request APK link</button></form></section></main><footer>Footer</footer>',
        ]);

        self::assertTrue(
            (bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? false),
            \json_encode($this->findItem($report['items'], 'content_quality'), \JSON_UNESCAPED_UNICODE)
        );
    }

    public function testInspectScopeStillRejectsVisiblePlaceholderCopy(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>AI content placeholder</p></section></main><footer>Footer</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
    }

    public function testInspectScopeRejectsVisiblePlanningObservationCopy(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>访客看到真实玩家好评和清晰的下载步骤，信任感增强，并知道如何立即下载。</h1><p>体验 Teen Patti、Rummy 等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
    }

    public function testInspectScopeRejectsBlueprintPlanningCopyInVisibleContent(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>这个页面的核心亮点</h1><p>用更清晰的卡片层级展示卖点、差异点和信任信息，避免所有内容挤成同一种视觉。</p><article><h3>Download & Play</h3><p>把主行动直接放在卡片中，减少犹豫。</p></article></section></main><footer>Footer</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        $badMatches = \implode("\n", $report['page_reports']['home_page']['bad_matches'] ?? []);
        self::assertStringContainsString('卡片层级', $badMatches);
        self::assertStringContainsString('主行动', $badMatches);
    }

    public function testInspectScopeRejectsWebsiteProfileLeakInVisibleCopy(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Teenipiya websiteProfile</header><main><section style="color:#111827;background:linear-gradient(135deg,#fff7d6,#ffffff);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><h1>Premium Teen Patti APK Download</h1><p>Clear rules, secure access, and fast support for every player.</p></section></main><footer>Support and legal links</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        self::assertContains('websiteProfile', $report['page_reports']['home_page']['bad_matches'] ?? []);
    }

    public function testInspectScopeRejectsInstructionShapedAltText(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Royal Indian Games</header><main><section style="color:#111827;background:linear-gradient(135deg,#fff7d6,#ffffff);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img src="/pub/media/page-build/site/ai-generated/hero.jpg" alt="Introduce brand story and mission to build initial trust"><h1>Premium Teen Patti APK Download</h1><p>Clear rules, secure access, and fast support for every player.</p></section></main><footer>Support and legal links</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        $badMatches = \implode("\n", $report['page_reports']['home_page']['bad_matches'] ?? []);
        self::assertStringContainsString('Introduce brand story and mission', $badMatches);
    }

    public function testInspectScopeRejectsLargeWrongLanguageBlockOutsideSiteTitle(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'zh_Hans_CN';
        $scope['website_profile']['site_title'] = 'Royal Indian Games';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>Best of three cards classic Indian poker. Play with friends or join tables.</p><a>Download & Play</a></section></main><footer>页脚导航</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'language_consistency')['ok'] ?? true));
        self::assertNotSame([], $report['page_reports']['home_page']['language_violations'] ?? []);
    }

    public function testInspectScopeRejectsVisibleContractFieldLeak(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>皇家棋牌游戏</header><main><section class="pb-test-section" style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>content_contract: page_goal and block_goal describe why_this_block exists.</p></section></main><footer>页脚导航</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        $badMatches = \implode("\n", $report['page_reports']['home_page']['bad_matches'] ?? []);
        self::assertStringContainsString('content_contract', $badMatches);
    }

    public function testInspectScopeRejectsFailedBuildTaskEvenWhenNoTaskIsPending(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['build_tasks']['page:home_page:hero_banner']['status'] = 'failed';
        $scope['build_tasks']['page:home_page:hero_banner']['message'] = 'AI validation rejected this block.';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>皇家棋牌游戏</header><main><section class="pb-test-section" style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>页脚导航</footer>',
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'build_tasks_done')['ok'] ?? true));
    }

    public function testInspectScopeIgnoresHeadTitleForLanguageConsistency(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<html><head><title>&#20013;&#25991;&#26631;&#39064;</title></head><body><header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>Premium Teen Patti APK Download</h1><p>Clear rules, secure access, and fast support for every player.</p></section></main><footer>Support and legal links</footer></body></html>',
        ]);

        self::assertTrue(
            (bool)($this->findItem($report['items'], 'language_consistency')['ok'] ?? false),
            \json_encode($report['page_reports']['home_page']['language_violations'] ?? [], \JSON_UNESCAPED_UNICODE)
        );
    }

    public function testContentQualityIgnoresNonVisibleSrcAndHrefPaths(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img src="/pub/media/page-build/site/ai-generated/content/home-page-hero-banner.jpg" alt="Game Showcase featuring premium Indian-style games"><img src="/pub/media/page-build/site/ai-generated/content/home-page-trust-security.jpg" alt="Trust Security - Certified card games and secure APK downloads"><h1>Premium Teen Patti APK Download</h1><p>Clear rules, secure access, and fast support for every player.</p><a href="/content/home-page-hero-banner">Download APK</a></section></main><footer>Support and legal links</footer>',
        ]);

        $contentItem = $this->findItem($report['items'], 'content_quality');
        self::assertTrue((bool)($contentItem['ok'] ?? false), \json_encode($contentItem, \JSON_UNESCAPED_UNICODE));
        self::assertSame([], $report['page_reports']['home_page']['bad_matches'] ?? ['unexpected']);
    }

    public function testInspectScopeRejectsMissingAiGeneratedResponsiveSupport(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>皇家棋牌游戏</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>页脚导航</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'responsive_support')['ok'] ?? true));
        self::assertSame([], $report['page_reports']['home_page']['responsive_signals'] ?? ['unexpected']);
    }

    public function testInspectScopeRejectsMalformedGeneratedHtmlTags(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><h1>专为印度玩家打造的棋牌娱乐殿堂</h1>< class="pb-card-icon" aria-hidden="true"></class><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        $contentItem = $this->findItem($report['items'], 'content_quality');
        self::assertFalse((bool)($contentItem['ok'] ?? true));
        self::assertContains('malformed opening tag', $report['page_reports']['home_page']['bad_matches'] ?? []);
        self::assertContains('invalid class tag', $report['page_reports']['home_page']['bad_matches'] ?? []);
    }

    public function testInspectScopePassesForVirtualDraftRenderedOverrideWithoutEntityPageId(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        unset($scope['pagebuilder_pages_by_type']);
        $scope['workspace_track'] = AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME;
        $scope['virtual_theme_id'] = 99;

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>涓撲负鍗板害鐜╁鎵撻€犵殑妫嬬墝濞变箰娈垮爞</h1><p>浣撻獙Teen Patti銆丷ummy绛夌粡鍏告父鎴忥紝浜彈瀹夊叏鍏钩鐨勭幇浠ｅ寲绀惧尯</p></section></main><footer>Footer</footer>',
        ]);

        self::assertTrue((bool)($this->findItem($report['items'], 'required_pages_render')['ok'] ?? false));
    }

    public function testInspectScopeRequiresRealAssetsWhenManifestDeclaresImageSlots(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['asset_manifest'] = [
            'slots' => [
                ['slot_id' => 'page:home_page:content-home-page-hero-banner', 'slot_type' => 'hero_image', 'final_url' => ''],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>涓撲负鍗板害鐜╁鎵撻€犵殑妫嬬墝濞变箰娈垮爞</h1><p>浣撻獙Teen Patti銆丷ummy绛夌粡鍏告父鎴忥紝浜彈瀹夊叏鍏钩鐨勭幇浠ｅ寲绀惧尯</p></section></main><footer>Footer</footer>',
        ]);

        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
    }

    public function testInspectScopeRejectsGeneratedImageWhenRequiredSlotMarkerIsMissing(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['asset_manifest'] = [
            'slots' => [
                'page:home_page:content-home-page-hero-banner' => [
                    'slot_type' => 'hero_image',
                    'status' => 'done',
                    'source' => 'generated',
                    'final_url' => '/pub/media/page-build/site/ai-generated/hero.webp',
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img src="/pub/media/page-build/site/ai-generated/hero.webp" alt="Premium Teen Patti table"><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        $visuals = $report['page_reports']['home_page']['visuals'] ?? [];
        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
        self::assertSame(
            ['page:home_page:content-home-page-hero-banner'],
            $visuals['missing_required_image_slot_ids'] ?? []
        );
    }

    public function testInspectScopePassesWhenRequiredGeneratedImageSlotIsUsed(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['asset_manifest'] = [
            'slots' => [
                'page:home_page:content-home-page-hero-banner' => [
                    'slot_type' => 'hero_image',
                    'status' => 'done',
                    'source' => 'generated',
                    'final_url' => '/pub/media/page-build/site/ai-generated/hero.webp',
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img data-pb-ai-asset-slot="page:home_page:content-home-page-hero-banner" src="/pub/media/page-build/site/ai-generated/hero.webp" alt="Premium Teen Patti table"><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        $visuals = $report['page_reports']['home_page']['visuals'] ?? [];
        self::assertTrue((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? false), \json_encode($visuals, \JSON_UNESCAPED_UNICODE));
        self::assertSame(
            ['page:home_page:content-home-page-hero-banner'],
            $visuals['used_required_image_slot_ids'] ?? []
        );
    }

    public function testInspectScopeRejectsRequiredImageSlotWhenSrcDoesNotMatchFinalUrl(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['content_locale'] = 'en_US';
        $scope['default_locale'] = 'en_US';
        $scope['asset_manifest'] = [
            'slots' => [
                'page:home_page:content-home-page-hero-banner' => [
                    'slot_type' => 'hero_image',
                    'status' => 'done',
                    'source' => 'generated',
                    'final_url' => '/pub/media/page-build/site/ai-generated/hero.webp',
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle()
                . '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img data-pb-ai-image-role="generated-asset" data-pb-ai-asset-slot="page:home_page:content-home-page-hero-banner" src="/pub/media/page-build/site/ai-generated/other.webp" alt="Premium Teen Patti table"><h1>Premium Teen Patti APK Download</h1><p>Clear rules, secure access, and fast support for every player.</p></section></main><footer>Support and legal links</footer>',
        ]);

        $visuals = $report['page_reports']['home_page']['visuals'] ?? [];
        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
        self::assertSame([], $visuals['used_required_image_slot_ids'] ?? ['unexpected']);
        self::assertSame(
            ['page:home_page:content-home-page-hero-banner'],
            $visuals['missing_required_image_slot_ids'] ?? []
        );
    }

    public function testInspectScopeRejectsLocalComposedFallbackAsRealImageAsset(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['asset_manifest'] = [
            'slots' => [
                [
                    'slot_id' => 'hero',
                    'slot_type' => 'hero_image',
                    'status' => 'done',
                    'final_url' => '/pub/media/page-build/site/ai-generated/hero-local.jpg',
                    'variants' => [[
                        'url' => '/pub/media/page-build/site/ai-generated/hero-local.jpg',
                        'mode' => 'local_composed',
                        'model' => 'local-premium-composition-v1',
                        'generation_fallback_reason' => 'No bounded text-to-image generator is configured.',
                    ]],
                ],
            ],
        ];
        $scope['verified_assets'] = ['hero' => '/pub/media/page-build/site/ai-generated/hero-local.jpg'];

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><img src="/pub/media/page-build/site/ai-generated/hero-local.jpg" alt="Premium Teen Patti table"><h1>娑撴挷璐熼崡鏉垮閻溾晛顔嶉幍鎾烩偓鐘垫畱濡澧濇繛鍙樼濞堝灝鐖?/h1><p>娴ｆ捇鐛橳een Patti閵嗕阜ummy缁涘绮￠崗鍛婄埗閹村骏绱濒禍顐㈠綀鐎瑰鍙忛崗顒€閽╅惃鍕箛娴狅絽瀵茬粈鎯у隘</p></section></main><footer>Footer</footer>',
        ]);

        $visualItem = $this->findItem($report['items'], 'visual_assets_safe');
        self::assertFalse((bool)($visualItem['ok'] ?? true));
        self::assertSame([], $report['page_reports']['home_page']['visuals']['real_asset_urls'] ?? ['unexpected']);
    }

    public function testNormalizeQualityItemsReconnectsCanonicalFieldAndDropsUnknownItems(): void
    {
        $service = $this->createService();
        $reflection = new \ReflectionMethod(AiSiteQualityGateService::class, 'normalizeQualityItems');
        $reflection->setAccessible(true);

        $items = [
            [
                'key' => 'wrong_content_key',
                'label' => '页面无内部标识/方案说明/demo 文案',
                'ok' => false,
                'value' => ['home_page' => ['wrong_field' => 'wrong value']],
            ],
            [
                'key' => 'not_in_contract',
                'label' => 'should be removed',
                'ok' => true,
                'value' => 'unknown',
            ],
        ];
        $pageReports = [
            'home_page' => [
                'bad_matches' => ['demo', 'example.com'],
                'shared_blocks' => ['header' => true, 'footer' => true],
                'stage1_hits' => ['sample heading'],
                'theme_hits' => ['#FFD700'],
                'visuals' => ['broken_images' => [], 'has_svg_visual' => true, 'has_image_need' => false],
                'visual_depth_signals' => ['gradient', 'shadow', 'visual'],
            ],
        ];

        /** @var list<array<string,mixed>> $normalized */
        $normalized = $reflection->invoke($service, $items, $pageReports);

        self::assertCount(13, $normalized);
        self::assertSame(
            ['demo', 'example.com'],
            $this->findItem($normalized, 'content_quality')['value']['home_page'] ?? []
        );
        self::assertSame(
            '页面无内部标识/方案说明/demo 文案',
            $this->findItem($normalized, 'content_quality')['label'] ?? ''
        );
        self::assertSame(
            '页面包含已确认方案内容',
            $this->findItem($normalized, 'stage1_content_visible')['label'] ?? ''
        );
        self::assertSame(
            '页面包含已确认视觉 token',
            $this->findItem($normalized, 'theme_visible')['label'] ?? ''
        );
        self::assertSame([], $this->findItem($normalized, 'not_in_contract'));
    }

    public function testInspectScopeTaskCoveragePassesWhenScheduledMatchesExpected(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏</p></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'task_coverage');
        self::assertTrue((bool)($item['ok'] ?? false), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertSame([], $item['value']['missing_blocks'] ?? ['unexpected']);
        self::assertSame([], $item['value']['extra_blocks'] ?? ['unexpected']);
        self::assertTrue((bool)($item['value']['evaluated'] ?? false));
    }

    public function testInspectScopeTaskCoverageBlocksPublishWhenExpectedBlockIsMissingFromBuildTasks(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['execution_blueprint']['pages']['home_page']['blocks'][] = [
            'block_key' => 'trust_proof',
            'field_plan' => [],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section style="color:#FFD700"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'task_coverage');
        self::assertFalse((bool)($item['ok'] ?? true), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertSame(['trust_proof'], $item['value']['missing_blocks']['home_page'] ?? []);
        self::assertFalse($report['passed']);
    }

    public function testInspectScopeTaskCoverageBlocksPublishWhenBuildTaskIsOrphaned(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['build_blueprint']['tasks'][] = [
            'task_key' => 'page:home_page:legacy_orphan',
            'task_type' => 'page_section',
            'page_type' => 'home_page',
            'block_key' => 'legacy_orphan',
            'section_code' => 'content/home-page-legacy-orphan',
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'task_coverage');
        self::assertFalse((bool)($item['ok'] ?? true));
        self::assertSame(['legacy_orphan'], $item['value']['extra_blocks']['home_page'] ?? []);
    }

    public function testInspectScopeTaskCoverageSkipsWhenBlueprintNotYetGenerated(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        unset($scope['execution_blueprint'], $scope['build_blueprint']);

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'task_coverage');
        // 蓝图未生成时 task_coverage 不应阻断，由 build_tasks_done 等门禁兜底。
        self::assertTrue((bool)($item['ok'] ?? false), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertFalse((bool)($item['value']['evaluated'] ?? true));
    }

    public function testInspectScopeSourceTruthCoverageBlocksPublishOnErrorFinding(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['qa_report_contract'] = [
            'payload' => [
                'content_quality' => [
                    'status' => 'fail',
                    'findings' => [
                        [
                            'severity' => 'error',
                            'category' => 'content_quality',
                            'contract_type' => 'source_truth',
                            'message' => 'Missing must-include fact [f01]: APK 安全下载',
                            'path' => 'content_quality.missing_must_include_fact',
                        ],
                    ],
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'source_truth_coverage');
        self::assertFalse((bool)($item['ok'] ?? true), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $item['value']['error_count'] ?? 0);
        self::assertFalse($report['passed']);
    }

    public function testInspectScopeSourceTruthCoverageDoesNotBlockOnWarningOnly(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['qa_report_contract'] = [
            'payload' => [
                'content_quality' => [
                    'status' => 'warn',
                    'findings' => [
                        [
                            'severity' => 'warning',
                            'category' => 'content_quality',
                            'contract_type' => 'source_truth',
                            'message' => 'Missing must-include fact [f02]: 多语言客服',
                            'path' => 'content_quality.missing_must_include_fact',
                        ],
                    ],
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'source_truth_coverage');
        self::assertTrue((bool)($item['ok'] ?? false), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $item['value']['warning_count'] ?? 0);
    }

    public function testInspectScopeRenderDataQualityBlocksPublishOnSeoErrorFinding(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['qa_report_contract'] = [
            'payload' => [
                'content_quality' => [
                    'status' => 'fail',
                    'findings' => [
                        [
                            'severity' => 'error',
                            'category' => 'seo',
                            'rule' => 'seo.missing_title',
                            'message' => 'Page is missing SEO title metadata.',
                            'target_path' => 'payload.materialized_pages_by_type.home_page.seo_title',
                        ],
                    ],
                ],
            ],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'render_data_quality');
        self::assertFalse((bool)($item['ok'] ?? true), \json_encode($item, \JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $item['value']['error_count'] ?? 0);
        self::assertFalse($report['passed']);
    }

    public function testInspectScopeRenderDataQualityBlocksOnPreflightError(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['quality_gate_preflight_error'] = 'Page layout has no rendered sections.';

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'render_data_quality');
        self::assertFalse((bool)($item['ok'] ?? true));
        self::assertStringContainsString('Page layout has no rendered sections', (string)($item['detail'] ?? ''));
        self::assertFalse($report['passed']);
    }

    public function testInspectScopeTaskCoverageFailureIncludesDetailText(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        $scope['execution_blueprint']['pages']['home_page']['blocks'][] = [
            'block_key' => 'trust_proof',
            'field_plan' => [],
        ];

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section><h1>Hero</h1></section></main><footer>Footer</footer>',
        ]);

        $item = $this->findItem($report['items'], 'task_coverage');
        self::assertFalse((bool)($item['ok'] ?? true));
        self::assertStringContainsString('trust_proof', (string)($item['detail'] ?? ''));
    }

    public function testInspectScopeQaReportGatesPassWhenQaReportContractIsAbsent(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();
        // 不设置 qa_report_contract：尚未到 buildRenderDataContract 阶段时不应阻断发布。

        $report = $service->inspectScope($scope, [
            'home_page' => $this->responsiveStyle() . '<header>Header</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        self::assertTrue(
            (bool)($this->findItem($report['items'], 'source_truth_coverage')['ok'] ?? false),
            'source_truth_coverage should not block when qa_report_contract is absent'
        );
        self::assertTrue(
            (bool)($this->findItem($report['items'], 'render_data_quality')['ok'] ?? false),
            'render_data_quality should not block when qa_report_contract is absent'
        );
    }

    private function createService(): AiSiteQualityGateService
    {
        return new AiSiteQualityGateService(
            buildTaskService: new AiSiteBuildTaskService(new AiSitePageBlueprintService()),
            scopeCompatibilityService: new AiSiteScopeCompatibilityService(new LayoutConfigNormalizer()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScope(): array
    {
        return [
            'page_types' => ['home_page'],
            'pagebuilder_pages_by_type' => [
                'home_page' => ['page_id' => 1],
            ],
            'build_blueprint' => [
                'tasks' => [
                    ['task_key' => 'page:home_page:hero_banner', 'task_type' => 'page_section', 'page_type' => 'home_page', 'group_key' => 'home_page', 'section_code' => 'content/home-page-hero-banner'],
                ],
            ],
            'build_tasks' => [
                'page:home_page:hero_banner' => ['task_key' => 'page:home_page:hero_banner', 'status' => 'done'],
            ],
            'execution_blueprint' => [
                'palette' => ['primary' => '#FFD700', 'secondary' => '#8B0000', 'accent' => '#228B22'],
                'pages' => [
                    'home_page' => [
                        'blocks' => [
                            [
                                'block_key' => 'hero_banner',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => '专为印度玩家打造的棋牌娱乐殿堂'],
                                    ['field' => 'description', 'sample' => '体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'virtual_pages_by_type' => [
                'home_page' => [
                    'blocks' => [
                        ['block_id' => 'header-ai-site-header', 'type' => 'ai_generated_shared_header'],
                        ['block_id' => 'home-page-hero-banner', 'type' => 'ai_generated_section'],
                        ['block_id' => 'footer-ai-site-footer', 'type' => 'ai_generated_shared_footer'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function findItem(array $items, string $key): array
    {
        foreach ($items as $item) {
            if (\is_array($item) && (string)($item['key'] ?? '') === $key) {
                return $item;
            }
        }

        return [];
    }

    private function responsiveStyle(): string
    {
        return '<style>@media (max-width:420px){.pb-test-section{grid-template-columns:minmax(0,1fr);min-width:0;max-width:100%;overflow-x:hidden;padding:clamp(20px,6vw,32px)}.pb-test-section img{max-width:100%;height:auto;object-fit:cover}.pb-test-section h1{font-size:clamp(30px,9vw,48px);overflow-wrap:break-word}}</style>';
    }
}
