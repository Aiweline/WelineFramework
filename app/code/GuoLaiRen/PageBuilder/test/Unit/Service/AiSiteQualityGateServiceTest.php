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
            'home_page' => '<header>Royal Indian Games</header><main><section style="color:#FFD700;background:linear-gradient(135deg,#111827,#8B0000);display:grid;box-shadow:0 20px 60px rgba(0,0,0,.2);border-radius:24px;transition:transform .2s ease"><svg viewBox="0 0 10 10"></svg><h1>专为印度玩家打造的棋牌娱乐殿堂</h1><p>体验Teen Patti、Rummy等经典游戏，享受安全公平的现代化社区</p></section></main><footer>Footer</footer>',
        ]);

        self::assertTrue($report['passed'], \json_encode($report, \JSON_UNESCAPED_UNICODE));
    }

    public function testInspectScopeFailsForInternalCopyAndBrokenImage(): void
    {
        $service = $this->createService();
        $scope = $this->buildScope();

        $report = $service->inspectScope($scope, [
            'home_page' => '<header>Header</header><section><h2>核心卖点</h2><img src="https://example.com/hero.jpg" alt="hero"></section><footer>Footer</footer>',
        ]);

        self::assertFalse($report['passed']);
        self::assertFalse((bool)($this->findItem($report['items'], 'content_quality')['ok'] ?? true));
        self::assertFalse((bool)($this->findItem($report['items'], 'visual_assets_safe')['ok'] ?? true));
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

        self::assertCount(8, $normalized);
        self::assertSame(
            ['demo', 'example.com'],
            $this->findItem($normalized, 'content_quality')['value']['home_page'] ?? []
        );
        self::assertSame(
            '页面无内部标识/方案说明/demo 文案',
            $this->findItem($normalized, 'content_quality')['label'] ?? ''
        );
        self::assertSame([], $this->findItem($normalized, 'not_in_contract'));
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
}
