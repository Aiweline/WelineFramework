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
