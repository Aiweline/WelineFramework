<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

final class AiSiteExecutionBlueprintServiceTest extends TestCase
{
    public function testBuildPlanArtifactsProducesMarkdownAndBlueprint(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());

        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);

        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertStringContainsString('# ', (string)($artifacts['markdown'] ?? ''));
        self::assertStringContainsString('home_page', (string)($artifacts['markdown'] ?? ''));
        self::assertStringContainsString('about_page', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['plan_json'] ?? null);
        self::assertNotEmpty($artifacts['plan_json']['pages']['home_page']['blocks'] ?? []);
        $firstBlock = $artifacts['plan_json']['pages']['home_page']['blocks'][0] ?? [];
        self::assertArrayHasKey('content', $firstBlock);
        self::assertArrayHasKey('why', $firstBlock);
        self::assertIsArray($artifacts['structured'] ?? null);
        self::assertIsArray($artifacts['execution_blueprint'] ?? null);
        self::assertNotEmpty($artifacts['execution_blueprint']['tasks'] ?? []);
    }

    public function testRefineDraftPlanAddsChangeScopeReport(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildValidAiPlanResponse())
        );

        $artifacts = $service->refineDraftPlan([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ], [
            'instruction' => 'Only refine the about page trust messaging.',
            'target_scope' => 'about_page',
            'round' => 2,
        ]);

        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['change_scope_report'] ?? null);
        self::assertSame('about_page', (string)($artifacts['change_scope_report']['target_scope'] ?? ''));
        self::assertSame(2, (int)($artifacts['change_scope_report']['round'] ?? 0));
        self::assertStringNotContainsString('## This Round Refine', (string)($artifacts['markdown'] ?? ''));
    }

    public function testRebuildDraftPlanAddsRebuildSummary(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildValidAiPlanResponse())
        );

        $artifacts = $service->rebuildDraftPlan([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ], [
            'instruction' => 'Rebuild the stage one plan around a new brand direction.',
            'round' => 3,
        ]);

        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['rebuild_summary'] ?? null);
        self::assertSame(3, (int)($artifacts['rebuild_summary']['round'] ?? 0));
        self::assertGreaterThan(0, (int)($artifacts['rebuild_summary']['task_count'] ?? 0));
        self::assertStringContainsString('# ', (string)($artifacts['markdown'] ?? ''));
        self::assertStringContainsString('home_page', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['plan_json'] ?? null);
    }

    public function testBuildPlanArtifactsOnlyUsesSelectedPageTypes(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Only Selected Pages',
            'brief_description' => 'No blog required for this website.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Only Selected Pages',
            'brief_description' => 'No blog required for this website.',
        ]);

        $pageTypes = $artifacts['execution_blueprint']['page_types'] ?? [];
        self::assertSame(['home_page', 'about_page'], $pageTypes);
        self::assertNotContains(Page::TYPE_BLOG_LIST, $pageTypes);
    }

    public function testRefineAddsPartnerBlockWhenInstructionRequestsPartners(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildValidAiPlanResponse())
        );

        $artifacts = $service->refineDraftPlan([
            'site_title' => 'Partner Section Site',
            'brief_description' => 'Need trust and conversion.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Partner Section Site',
            'brief_description' => 'Need trust and conversion.',
        ], [
            'instruction' => 'Please add a partner section that shows brand logos.',
            'target_scope' => Page::TYPE_HOME,
            'round' => 2,
        ]);

        $tasks = \is_array($artifacts['execution_blueprint']['tasks'] ?? null) ? $artifacts['execution_blueprint']['tasks'] : [];
        $taskKeys = \array_map(static fn(array $task): string => (string)($task['task_key'] ?? ''), $tasks);
        self::assertContains('page:home_page:partner', $taskKeys);
        self::assertStringContainsString(
            'partner',
            (string)\json_encode($artifacts['plan_json']['pages']['home_page']['blocks'] ?? [], \JSON_UNESCAPED_UNICODE)
        );
    }

    public function testBuildAiPlanPromptContainsStageOneMustConstraints(): void
    {
        $capturedPrompt = null;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback) use (&$capturedPrompt): void {
                $capturedPrompt = $prompt;
                $callback($this->buildValidAiPlanResponse());
            });

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ], [
            'instruction' => 'Focus more on trust messaging.',
            'target_scope' => 'about_page',
        ]);

        self::assertIsString($capturedPrompt);
        self::assertStringContainsString('第一阶段只输出 plan，不允许出现 build started / executing / task running / log stream / percent complete 等执行态措辞。', $capturedPrompt);
        self::assertStringContainsString('必须输出 stage2_task_hints，并显式说明每个页面/区块将如何进入第二阶段任务拆分。', $capturedPrompt);
        self::assertStringContainsString('Selected page coverage hints (must all be represented in the final plan):', $capturedPrompt);
        self::assertStringContainsString('- home_page: must include page goal, conversion rhythm, block why, field plan, execution script, SEO structure, CTA usage, responsive guidance.', $capturedPrompt);
        self::assertStringContainsString('baseline_execution_blueprint:', $capturedPrompt);
        self::assertStringContainsString('"default_locale": "en_US"', $capturedPrompt);
    }

    private function createStreamingAiServiceStub(string $response): AiService
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback) use ($response): void {
                $callback($response);
            });

        return $aiService;
    }

    private function buildValidAiPlanResponse(): string
    {
        return \json_encode([
            'markdown' => '# Site Blueprint',
            'plan_json' => [
                'site_strategy' => [
                    'site_display_name' => 'Plan Service Test',
                    'summary' => 'Need home and about pages with strong CTA.',
                    'website_type' => 'brand site',
                    'core_goal' => 'Capture leads',
                    'target_users' => 'Prospects',
                    'conversion_path' => 'Hero -> proof -> contact',
                ],
                'theme_style' => [
                    'name' => 'Plan-Driven Hybrid',
                    'visual_tone' => 'Structured and clear',
                    'font_family' => 'Sans Serif',
                ],
                'palette' => [
                    'name' => 'Ocean Slate',
                    'primary' => '#0f172a',
                    'accent' => '#2563eb',
                    'surface' => '#f8fafc',
                    'text' => '#0f172a',
                ],
                'navigation_plan' => [
                    'header_items' => [],
                ],
                'footer_plan' => [
                    'featured' => [],
                    'policies' => [],
                ],
                'seo_strategy' => [
                    'core_intent' => 'brand site',
                    'primary_keywords' => ['brand site'],
                    'keyword_page_map' => [],
                    'content_strategy' => 'Answer user intent',
                    'internal_linking' => 'Link core pages',
                    'url_structure' => 'flat',
                ],
                'page_types' => ['home_page', 'about_page'],
                'pages' => [
                    'home_page' => [
                        'page_goal' => 'Explain value and drive conversion.',
                        'primary_keywords' => ['home keyword'],
                        'secondary_keywords' => ['cta keyword'],
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Explain value',
                                'keywords' => ['hero keyword'],
                                'content' => 'Hero content direction',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Hero title', 'reason' => 'Explain value quickly'],
                                ],
                                'execution_script' => [
                                    'feature_points' => ['Primary CTA'],
                                    'core_copy' => 'Hero copy',
                                    'typography' => 'Bold heading',
                                    'style_tone' => 'High trust',
                                    'background_direction' => 'Clean background',
                                    'media_assets' => ['hero.jpg'],
                                ],
                                'reusable' => 'yes',
                                'seo_impact' => 'high',
                            ],
                        ],
                    ],
                    'about_page' => [
                        'page_goal' => 'Build trust.',
                        'primary_keywords' => ['about keyword'],
                        'secondary_keywords' => ['trust keyword'],
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Introduce brand',
                                'keywords' => ['brand keyword'],
                                'content' => 'About content direction',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'About title', 'reason' => 'State the brand clearly'],
                                ],
                                'execution_script' => [
                                    'feature_points' => ['Brand proof'],
                                    'core_copy' => 'About copy',
                                    'typography' => 'Readable',
                                    'style_tone' => 'Trustworthy',
                                    'background_direction' => 'Neutral',
                                    'media_assets' => ['about.jpg'],
                                ],
                                'reusable' => 'no',
                                'seo_impact' => 'medium',
                            ],
                        ],
                    ],
                ],
                'execution_steps' => [
                    ['step' => 1, 'task_key' => 'shared:header', 'task_type' => 'shared', 'status' => 'pending'],
                ],
                'stage2_task_hints' => [
                    ['page' => 'home_page', 'block' => 'hero', 'task_types' => ['copywriting', 'ui_design', 'frontend_dev']],
                ],
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
