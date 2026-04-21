<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
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
        self::assertStageOneThemeDesignSchema($artifacts['plan_json']['theme_design'] ?? null);
        self::assertStageOneThemeDesignSchema($artifacts['structured']['shared_plan']['theme_design'] ?? null);
        self::assertStageOneThemeDesignSchema($artifacts['execution_blueprint']['shared_prompt_context']['theme_design'] ?? null);
        self::assertSame(
            (string)($artifacts['plan_json']['theme_design']['selection_reason'] ?? ''),
            (string)($artifacts['structured']['shared_plan']['theme_design']['selection_reason'] ?? '')
        );
        self::assertStringContainsString(
            'shared_prompt_context',
            (string)($artifacts['plan_json']['pages']['home_page']['theme_alignment_summary'] ?? '')
        );
        self::assertStringContainsString(
            'shared_prompt_context',
            (string)($artifacts['structured']['page_plans']['home_page']['theme_alignment_summary'] ?? '')
        );
        self::assertNotEmpty($artifacts['plan_json']['pages']['home_page']['blocks'] ?? []);
        self::assertNotSame('', (string)($artifacts['plan_json']['pages']['home_page']['theme_alignment_summary'] ?? ''));
        self::assertSame(
            (string)($artifacts['plan_json']['pages']['home_page']['theme_alignment_summary'] ?? ''),
            (string)($artifacts['structured']['page_plans']['home_page']['theme_alignment_summary'] ?? '')
        );
        self::assertStringContainsString('主题遵守说明', (string)($artifacts['markdown'] ?? ''));
        $firstBlock = $artifacts['plan_json']['pages']['home_page']['blocks'][0] ?? [];
        self::assertArrayHasKey('content', $firstBlock);
        self::assertArrayHasKey('why', $firstBlock);
        self::assertArrayHasKey('implementation_note', $firstBlock);
        self::assertStringNotContainsString('围绕', (string)($firstBlock['content'] ?? ''));
        self::assertStringNotContainsString('阶段一仅给方向', (string)($firstBlock['content'] ?? ''));
        self::assertNotEmpty($firstBlock['field_plan'][0]['sample'] ?? '');
        self::assertNotEmpty($firstBlock['field_plan'][0]['implementation_note'] ?? '');
        self::assertStringNotContainsString('标题围绕', (string)($firstBlock['field_plan'][0]['sample'] ?? ''));
        self::assertStringNotContainsString('Why:', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['structured'] ?? null);
        self::assertIsArray($artifacts['execution_blueprint'] ?? null);
        self::assertNotEmpty($artifacts['execution_blueprint']['tasks'] ?? []);
        self::assertSame('stage1_shared_first_block_plan_v2', (string)($artifacts['execution_blueprint']['build_method'] ?? ''));
        self::assertIsArray($artifacts['execution_blueprint']['theme_context_snapshot'] ?? null);
        self::assertNotSame('', (string)($artifacts['execution_blueprint']['theme_context_snapshot']['context_hash'] ?? ''));
        $themeDesignJob = self::findQueueJobByType($artifacts['execution_blueprint']['queue_jobs'] ?? [], 'stage1.shared.theme_design');
        $themeContextHash = (string)($artifacts['execution_blueprint']['theme_context_snapshot']['context_hash'] ?? '');
        self::assertNotNull($themeDesignJob);
        self::assertSame('stage1.shared.theme_design', (string)($themeDesignJob['job_type'] ?? ''));
        self::assertSame('stage1', (string)($themeDesignJob['stage'] ?? ''));
        self::assertSame('shared:theme_design', (string)($themeDesignJob['block_key'] ?? ''));
        self::assertSame(['stage1.requirement_expand'], $themeDesignJob['depends_on'] ?? []);
        self::assertSame('done', (string)($themeDesignJob['status'] ?? ''));
        self::assertSame(100, (int)($themeDesignJob['progress_percent'] ?? 0));
        self::assertSame($themeContextHash, (string)($themeDesignJob['context_hash'] ?? ''));
        self::assertSame($themeContextHash, (string)($themeDesignJob['theme_context_snapshot']['context_hash'] ?? ''));
        self::assertSame('plan_workbench.stage1.theme_context_snapshot', (string)($themeDesignJob['result_ref']['scope_path'] ?? ''));
        self::assertIsArray($artifacts['execution_blueprint']['shared_prompt_context'] ?? null);
        self::assertIsArray($artifacts['structured']['shared_plan']['shared_prompt_context'] ?? null);
        $headerFooterJob = $artifacts['execution_blueprint']['stage1_queue']['jobs']['stage1.shared.header_footer'] ?? null;
        self::assertIsArray($headerFooterJob);
        self::assertSame('stage1.shared.header_footer', (string)($headerFooterJob['job_key'] ?? ''));
        self::assertSame('stage1.shared.header_footer', (string)($headerFooterJob['job_type'] ?? ''));
        self::assertSame('done', (string)($headerFooterJob['status'] ?? ''));
        self::assertNotSame('', (string)($headerFooterJob['token'] ?? ''));
        self::assertSame(['stage1.shared.theme_design'], $headerFooterJob['depends_on'] ?? []);
        self::assertSame(
            (string)($artifacts['execution_blueprint']['theme_context_snapshot']['context_hash'] ?? ''),
            (string)($headerFooterJob['inputs']['theme_context_hash'] ?? '')
        );
        self::assertSame('shared:header', (string)($headerFooterJob['outputs']['header_block']['task_key'] ?? ''));
        self::assertSame('shared:footer', (string)($headerFooterJob['outputs']['footer_block']['task_key'] ?? ''));
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($headerFooterJob['outputs']['shared_prompt_context']['context_hash'] ?? '')
        );
        self::assertSame(
            $artifacts['execution_blueprint']['stage1_queue']['jobs']['stage1.shared.header_footer'] ?? null,
            $artifacts['structured']['stage1_queue']['jobs']['stage1.shared.header_footer'] ?? null
        );
        self::assertSame(
            $artifacts['execution_blueprint']['stage1_queue']['jobs']['stage1.shared.header_footer'] ?? null,
            $artifacts['plan_workbench']['stage1']['queue_jobs']['stage1.shared.header_footer'] ?? null
        );
        $stageOneQueue = $artifacts['execution_blueprint']['stage1_queue'] ?? [];
        self::assertSame('fiber_coroutine', (string)($stageOneQueue['fanout']['mode'] ?? ''));
        self::assertSame('one_page_one_task', (string)($stageOneQueue['fanout']['task_granularity'] ?? ''));
        self::assertSame('stage1.shared.header_footer', (string)($stageOneQueue['fanout']['trigger_after'] ?? ''));
        self::assertSame(2, (int)($stageOneQueue['fanout']['page_job_count'] ?? 0));
        self::assertSame(
            ['stage1.page_plan:home_page', 'stage1.page_plan:about_page'],
            $stageOneQueue['fanout']['page_job_keys'] ?? []
        );
        $homePageJob = $stageOneQueue['jobs']['stage1.page_plan:home_page'] ?? null;
        self::assertIsArray($homePageJob);
        self::assertSame('stage1.page_plan', (string)($homePageJob['job_type'] ?? ''));
        self::assertSame(['stage1.shared.header_footer'], $homePageJob['depends_on'] ?? []);
        self::assertSame('fiber_coroutine', (string)($homePageJob['concurrency']['mode'] ?? ''));
        self::assertSame('home_page', (string)($homePageJob['inputs']['page_key'] ?? ''));
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($homePageJob['inputs']['shared_context_hash'] ?? '')
        );
        self::assertSame(
            (string)($artifacts['structured']['page_plans']['home_page']['page_context_hash'] ?? ''),
            (string)($homePageJob['outputs']['page_context_hash'] ?? '')
        );
        self::assertSame(
            $homePageJob,
            $artifacts['plan_workbench']['stage1']['queue_jobs']['stage1.page_plan:home_page'] ?? null
        );
        self::assertIsArray($artifacts['structured']['page_plans']['home_page'] ?? null);
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($artifacts['structured']['page_plans']['home_page']['shared_context_hash'] ?? '')
        );
        $stageOneJobs = $artifacts['execution_blueprint']['stage1_queue']['jobs'] ?? [];
        self::assertIsArray($stageOneJobs);
        self::assertArrayHasKey('stage1.page_plan:home_page', $stageOneJobs);
        self::assertArrayHasKey('stage1.page_plan:about_page', $stageOneJobs);
        $homePageJob = $stageOneJobs['stage1.page_plan:home_page'] ?? [];
        self::assertSame('stage1.page_plan', (string)($homePageJob['job_type'] ?? ''));
        self::assertSame('stage1_page_fanout', (string)($homePageJob['stage'] ?? ''));
        self::assertSame(['stage1.shared.header_footer'], $homePageJob['depends_on'] ?? []);
        self::assertSame('automatic_after_dependency', (string)($homePageJob['dispatch_mode'] ?? ''));
        self::assertSame('stage1.shared.header_footer.done', (string)($homePageJob['dispatch_trigger'] ?? ''));
        self::assertFalse((bool)($homePageJob['requires_user_tab'] ?? true));
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($homePageJob['shared_context_hash'] ?? '')
        );
        self::assertSame(
            (string)($artifacts['structured']['page_plans']['home_page']['page_context_hash'] ?? ''),
            (string)($homePageJob['context_hash'] ?? '')
        );
        self::assertSame(
            $artifacts['structured']['page_plans']['home_page'] ?? null,
            $homePageJob['outputs']['page_plan'] ?? null
        );
        self::assertContains('stage1.page_plan:home_page', $artifacts['execution_blueprint']['stage1_queue']['sequence'] ?? []);
        self::assertContains('stage1.page_plan:about_page', $artifacts['execution_blueprint']['stage1_queue']['sequence'] ?? []);
        self::assertSame(
            $homePageJob,
            $artifacts['plan_workbench']['stage1']['queue_jobs']['stage1.page_plan:home_page'] ?? null
        );
        self::assertSame(3, (int)($artifacts['plan_workbench']['stage1']['progress']['queue_job_total'] ?? 0));
        self::assertSame(3, (int)($artifacts['plan_workbench']['stage1']['progress']['queue_job_done'] ?? 0));
        self::assertIsArray($artifacts['structured']['block_index']['flat'] ?? null);
        self::assertArrayHasKey('shared:header', $artifacts['structured']['block_index']['flat']);
        self::assertIsArray($artifacts['plan_workbench']['stage1']['page_tabs_state'] ?? null);
        self::assertNotSame('', (string)($artifacts['structured']['page_plans']['home_page']['page_context_hash'] ?? ''));
        self::assertSame(
            (string)($artifacts['structured']['page_plans']['home_page']['page_context_hash'] ?? ''),
            (string)($artifacts['plan_workbench']['stage1']['page_tabs_state'][0]['page_context_hash'] ?? '')
        );
        self::assertIsArray($artifacts['plan_workbench']['stage1']['interaction_state'] ?? null);
        self::assertSame('home_page', (string)($artifacts['plan_workbench']['stage1']['interaction_state']['active_page_key'] ?? ''));
        self::assertSame(
            ['refine', 'rebuild', 'delete'],
            $artifacts['plan_workbench']['stage1']['interaction_state']['block_actions']['shared:header'] ?? []
        );
        self::assertSame(
            ['refine_page', 'rebuild_page', 'create_block'],
            $artifacts['plan_workbench']['stage1']['interaction_state']['page_actions']['home_page'] ?? []
        );
        self::assertIsArray($artifacts['plan_workbench']['confirmed']['block_index'] ?? null);
        $sharedTask = $artifacts['execution_blueprint']['tasks'][0] ?? [];
        self::assertArrayHasKey('implementation_detail', $sharedTask);
        self::assertArrayHasKey('realtime_content', $sharedTask);
        $pageTask = $artifacts['execution_blueprint']['tasks'][2] ?? [];
        self::assertArrayHasKey('implementation_detail', $pageTask['block'] ?? []);
        self::assertArrayHasKey('realtime_content', $pageTask['block'] ?? []);
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($pageTask['source_ref']['shared_context_hash'] ?? '')
        );
    }

    public function testPageTaskMissingSharedContextHashIsRejected(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildPageTask');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing shared_context_hash');

        $method->invoke($service, 'home_page', [
            'page_label' => 'Home',
            'slug' => '/',
            'blocks' => [],
        ], [
            'block_key' => 'hero',
            'implementation_detail' => 'Hero implementation',
        ]);
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

    public function testBuildPlanArtifactsByAiStreamFallsBackToDeterministicInFakeMode(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Fake Mode Plan',
            'brief_description' => 'Use deterministic plan generation for fake mode.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'fake_mode' => 1,
        ], [
            'site_title' => 'Fake Mode Plan',
            'brief_description' => 'Use deterministic plan generation for fake mode.',
        ], [
            'instruction' => 'Refine only the homepage hero.',
            'target_scope' => 'page:home_page:block:hero',
        ]);

        self::assertSame(0, (int)($artifacts['ai_generated'] ?? -1));
        self::assertSame(1, (int)($artifacts['ai_fallback'] ?? 0));
        self::assertSame('deterministic', (string)($artifacts['generation_source'] ?? ''));
        self::assertNotSame('', (string)($artifacts['markdown'] ?? ''));
        self::assertIsArray($artifacts['plan_json'] ?? null);
        self::assertNotEmpty($artifacts['plan_json']['pages']['home_page']['blocks'] ?? []);
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
            $this->createStreamingAiServiceStub($this->buildPartnerAiPlanResponse())
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

    public function testReorderDraftPlanBlocksUpdatesPlanJsonAndExecutionBlueprintOrder(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Sortable Plan Test',
            'brief_description' => 'Need multiple sections that can be reordered.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Sortable Plan Test',
            'brief_description' => 'Need multiple sections that can be reordered.',
        ]);

        $pageType = '';
        $originalKeys = [];
        foreach (($artifacts['plan_json']['pages'] ?? []) as $candidatePageType => $page) {
            $candidateBlocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            $candidateKeys = \array_values(\array_filter(\array_map(
                static fn(array $block): string => \trim((string)($block['block_key'] ?? '')),
                $candidateBlocks
            )));
            if (\count($candidateKeys) >= 2) {
                $pageType = (string)$candidatePageType;
                $originalKeys = $candidateKeys;
                break;
            }
        }

        self::assertNotSame('', $pageType);
        self::assertGreaterThanOrEqual(2, \count($originalKeys));

        $orderedKeys = \array_values(\array_reverse($originalKeys));
        $scope = [
            'plan_json' => $artifacts['plan_json'],
            'plan_markdown' => $artifacts['markdown'],
            'plan_structured' => $artifacts['structured'],
            'plan_workbench' => $artifacts['plan_workbench'],
            'execution_blueprint_draft' => $artifacts['execution_blueprint'],
        ];

        $reordered = $service->reorderDraftPlanBlocks($scope, $pageType, $orderedKeys);

        self::assertSame(
            $orderedKeys,
            \array_values(\array_map(
                static fn(array $block): string => (string)($block['block_key'] ?? ''),
                $reordered['plan_json']['pages'][$pageType]['blocks'] ?? []
            ))
        );
        self::assertSame(
            $orderedKeys,
            \array_values(\array_map(
                static fn(array $block): string => (string)($block['block_key'] ?? ''),
                $reordered['structured']['page_plans'][$pageType]['blocks'] ?? []
            ))
        );
        self::assertSame(
            [10, 20],
            \array_values(\array_slice(\array_map(
                static fn(array $block): int => (int)($block['sort_order'] ?? 0),
                $reordered['structured']['page_plans'][$pageType]['blocks'] ?? []
            ), 0, 2))
        );

        $pageIndex = \is_array($reordered['structured']['block_index']['pages'][$pageType] ?? null)
            ? $reordered['structured']['block_index']['pages'][$pageType]
            : [];
        $expectedIndexKeys = \array_values(\array_map(
            static fn(string $blockKey): string => 'page:' . $pageType . ':' . $blockKey,
            $orderedKeys
        ));
        self::assertSame($expectedIndexKeys, \array_keys($pageIndex));
        self::assertSame(10, (int)($pageIndex[$expectedIndexKeys[0]]['sort_order'] ?? 0));
        self::assertSame(20, (int)($pageIndex[$expectedIndexKeys[1]]['sort_order'] ?? 0));
        self::assertSame(
            $pageIndex,
            $reordered['plan_workbench']['confirmed']['block_index']['pages'][$pageType] ?? []
        );

        $pageTaskBlockKeys = \array_values(\array_map(
            static fn(array $task): string => (string)($task['block']['block_key'] ?? ''),
            \array_values(\array_filter(
                \is_array($reordered['execution_blueprint']['tasks'] ?? null) ? $reordered['execution_blueprint']['tasks'] : [],
                static fn(array $task): bool => (string)($task['page_type'] ?? '') === $pageType
                    && \trim((string)($task['block']['block_key'] ?? '')) !== ''
            ))
        ));
        self::assertSame($orderedKeys, $pageTaskBlockKeys);
    }

    public function testReorderDraftPlanBlocksSupportsSharedBlocksSortOrder(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Shared Sort Test',
            'brief_description' => 'Need global header and footer blocks that can be reordered.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
        ], [
            'site_title' => 'Shared Sort Test',
            'brief_description' => 'Need global header and footer blocks that can be reordered.',
        ]);

        $scope = [
            'plan_json' => $artifacts['plan_json'],
            'plan_markdown' => $artifacts['markdown'],
            'plan_structured' => $artifacts['structured'],
            'plan_workbench' => $artifacts['plan_workbench'],
            'execution_blueprint_draft' => $artifacts['execution_blueprint'],
        ];

        $reordered = $service->reorderDraftPlanBlocks($scope, 'shared', ['shared:footer', 'shared:header']);

        self::assertSame(
            ['shared:footer', 'shared:header'],
            \array_values(\array_map(
                static fn(array $block): string => (string)($block['block_key'] ?? ''),
                $reordered['plan_json']['shared_blocks'] ?? []
            ))
        );
        self::assertSame(10, (int)($reordered['structured']['shared_components']['footer']['sort_order'] ?? 0));
        self::assertSame(20, (int)($reordered['structured']['shared_components']['header']['sort_order'] ?? 0));

        $sharedIndexKeys = \array_keys(\is_array($reordered['structured']['block_index']['shared'] ?? null) ? $reordered['structured']['block_index']['shared'] : []);
        self::assertSame(['shared:footer', 'shared:header'], $sharedIndexKeys);
        $footerMarkdownPosition = \strpos((string)$reordered['markdown'], 'Shared Block #10: Footer');
        $headerMarkdownPosition = \strpos((string)$reordered['markdown'], 'Shared Block #20: Header');
        self::assertIsInt($footerMarkdownPosition);
        self::assertIsInt($headerMarkdownPosition);
        self::assertLessThan($headerMarkdownPosition, $footerMarkdownPosition);
    }

    public function testPlanBookMarkdownIsGeneratedFromSortedBlockTree(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Sorted Markdown Test',
            'brief_description' => 'Need a plan book whose markdown follows block tree sorting, not a free markdown string.',
            'page_types' => [Page::TYPE_HOME],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
        ], [
            'site_title' => 'Sorted Markdown Test',
            'brief_description' => 'Need a plan book whose markdown follows block tree sorting, not a free markdown string.',
        ]);

        $pageType = Page::TYPE_HOME;
        $blocks = \is_array($artifacts['structured']['pages'][$pageType]['blocks'] ?? null)
            ? $artifacts['structured']['pages'][$pageType]['blocks']
            : [];
        self::assertGreaterThanOrEqual(2, \count($blocks));

        $laterBlock = $blocks[0];
        $earlierBlock = $blocks[1];
        $laterBlock['sort_order'] = 20;
        $laterBlock['order'] = 20;
        $earlierBlock['sort_order'] = 10;
        $earlierBlock['order'] = 10;
        $artifacts['structured']['pages'][$pageType]['blocks'] = [$laterBlock, $earlierBlock];
        $artifacts['structured']['page_types'] = [$pageType];

        $buildPlanJson = new \ReflectionMethod($service, 'buildPlanJson');
        $buildPlanJson->setAccessible(true);
        $planJson = $buildPlanJson->invoke($service, $artifacts['structured']);
        self::assertIsArray($planJson);

        $blockKeys = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            $planJson['pages'][$pageType]['blocks'] ?? []
        ));
        self::assertSame([
            (string)($earlierBlock['block_key'] ?? ''),
            (string)($laterBlock['block_key'] ?? ''),
        ], \array_slice($blockKeys, 0, 2));

        $planJson['markdown'] = 'FREE MARKDOWN SENTINEL THAT MUST NOT BE REUSED';
        $buildMarkdownPlan = new \ReflectionMethod($service, 'buildMarkdownPlan');
        $buildMarkdownPlan->setAccessible(true);
        $markdown = (string)$buildMarkdownPlan->invoke($service, $planJson, 'en_US');

        $earlierTitle = (string)($earlierBlock['section_code'] ?? $earlierBlock['block_key'] ?? 'block');
        $laterTitle = (string)($laterBlock['section_code'] ?? $laterBlock['block_key'] ?? 'block');
        $earlierPosition = \strpos($markdown, '#### ' . $earlierTitle);
        $laterPosition = \strpos($markdown, '#### ' . $laterTitle);
        self::assertIsInt($earlierPosition);
        self::assertIsInt($laterPosition);
        self::assertLessThan($laterPosition, $earlierPosition);
        self::assertStringNotContainsString('FREE MARKDOWN SENTINEL', $markdown);
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
        self::assertStringContainsString('The plan must contain final-ready content samples, not writing instructions.', $capturedPrompt);
        self::assertStringContainsString('STAGE-1 SHARED THEME PLAN CONTRACT (theme_design must satisfy ALL):', $capturedPrompt);
        self::assertStringContainsString('theme_design is the concrete shared plan for Header/Footer and later page prompts', $capturedPrompt);
        self::assertStringContainsString('never output it as abstract direction', $capturedPrompt);
        self::assertStringContainsString('theme_design.color_scheme must provide ready-to-apply hex colors', $capturedPrompt);
        self::assertStringContainsString('theme_design MUST be a concrete shared theme plan', $capturedPrompt);
        self::assertStringContainsString('STAGE-1 PAGE THEME ALIGNMENT CONTRACT (pages must satisfy ALL):', $capturedPrompt);
        self::assertStringContainsString('Every page prompt MUST treat theme_design + shared_prompt_context as non-negotiable constraints', $capturedPrompt);
        self::assertStringContainsString('"theme_alignment_summary":"string explaining how this page obeys theme_design/shared_prompt_context"', $capturedPrompt);
        self::assertStringContainsString('Every pages.*.theme_alignment_summary MUST explicitly name the shared theme purpose', $capturedPrompt);
        self::assertStringContainsString('Do not return markdown.', $capturedPrompt);
        self::assertStringContainsString('Do not return a separate markdown field.', $capturedPrompt);
        self::assertStringContainsString('Output only the structured plan object shown in the schema.', $capturedPrompt);
        self::assertStringContainsString('"selection_reason":"why this font family and voice/tone fit the user requirement"', $capturedPrompt);
        self::assertStringContainsString('"selection_reason":"why this color system fits the user requirement"', $capturedPrompt);
        self::assertStringContainsString('"theme_alignment_summary":"how this page and every block obey theme_design color_scheme, tone_of_voice, cta_tone, trust expression, and Header/Footer handoff"', $capturedPrompt);
        self::assertStringContainsString('theme_style.selection_reason and palette.selection_reason are REQUIRED', $capturedPrompt);
        self::assertStringContainsString('why the color system, font family, and voice/tone were selected', $capturedPrompt);
        self::assertStringContainsString('selection_reason must connect the color/font/tone choices to the user one-line requirement', $capturedPrompt);
        self::assertStringContainsString('Never write blueprint guidance such as "围绕...说明"', $capturedPrompt);
        self::assertStringContainsString('Never write process wording such as "标题围绕核心价值展开"', $capturedPrompt);
        self::assertStringContainsString('field_plan.sample must be direct content', $capturedPrompt);
        self::assertStringContainsString('field_plan.implementation_note must be a customer-readable implementation note', $capturedPrompt);
        self::assertStringContainsString('Each pages.<page>.theme_alignment_summary is REQUIRED', $capturedPrompt);
        self::assertStringContainsString('Selected page coverage hints (must all be represented in the final plan):', $capturedPrompt);
        self::assertStringContainsString('- home_page: must include page goal, theme_alignment_summary, conversion rhythm, block implementation detail, field plan, execution script, SEO structure, CTA usage, responsive guidance.', $capturedPrompt);
        self::assertStringContainsString('baseline_execution_blueprint:', $capturedPrompt);
        self::assertStringContainsString('"default_locale": "en_US"', $capturedPrompt);
        self::assertStringNotContainsString('"markdown":"string"', $capturedPrompt);
        self::assertStringNotContainsString('Markdown template (fill with concrete content, never with direction text):', $capturedPrompt);
        self::assertStringNotContainsString('"reason":"string"', $capturedPrompt);
        self::assertStringNotContainsString('????????', $capturedPrompt);
    }

    public function testBuildPlanArtifactsByAiStreamCapsMaxTokensBelowProviderLimit(): void
    {
        $capturedParams = null;
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedParams): void {
                $capturedParams = $params;
                $callback($this->buildValidAiPlanResponse());
            });

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Token Limit Test',
            'brief_description' => 'Keep stream token budget below the provider cap.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Token Limit Test',
            'brief_description' => 'Keep stream token budget below the provider cap.',
        ]);

        self::assertIsArray($capturedParams);
        self::assertLessThanOrEqual(8192, (int)($capturedParams['max_tokens'] ?? 0));
        self::assertLessThan(8192, (int)($capturedParams['max_tokens'] ?? 0));
        self::assertSame(['type' => 'json_object'], $capturedParams['response_format'] ?? null);
        self::assertSame('ai', (string)($artifacts['generation_source'] ?? 'ai'));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['theme_style']['selection_reason'] ?? ''));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['palette']['selection_reason'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamAcceptsTopLevelPlanObjectShape(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildTopLevelAiPlanResponse())
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);

        self::assertSame(1, (int)($artifacts['ai_generated'] ?? 0));
        self::assertSame(0, (int)($artifacts['ai_fallback'] ?? -1));
        self::assertIsArray($artifacts['plan_json'] ?? null);
        self::assertNotEmpty($artifacts['plan_json']['pages']['home_page']['blocks'] ?? []);
    }

    public function testBuildPlanArtifactsByAiStreamAcceptsThemeSelectionReasonThatReferencesRequirement(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithThemeSelectionReason(
                'The strong CTA requirement calls for a clear conversion-focused visual system that keeps Home and About visitors moving toward contact.'
            ))
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);

        self::assertSame(1, (int)($artifacts['ai_generated'] ?? 0));
        self::assertSame(
            'The strong CTA requirement calls for a clear conversion-focused visual system that keeps Home and About visitors moving toward contact.',
            (string)($artifacts['plan_json']['theme_design']['selection_reason'] ?? '')
        );
    }

    public function testBuildPlanArtifactsByAiStreamRejectsThemeSelectionReasonThatIgnoresRequirement(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithThemeSelectionReason(
                'Modern, premium, clean, and simple.'
            ))
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_design.selection_reason must reference the user one-line requirement');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Teenipiya',
            'brief_description' => 'Build an APK download landing page for Teenipiya rummy players in India.',
            'page_types' => ['home_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => 'Build an APK download landing page for Teenipiya rummy players in India.',
        ]);
    }

    public function testBuildPlanArtifactsByAiStreamReportsMissingPlanJsonOnlyOnce(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub(\json_encode(['markdown' => '# Invalid'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI plan generation failed: missing plan_json payload. received top-level keys: markdown');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);
    }

    public function testBuildPlanArtifactsByAiStreamRejectsPromptLikePlanCopy(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildPromptLikeAiPlanResponse())
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instruction-like');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);
    }

    public function testBuildPlanArtifactsByAiStreamRequiresPageThemeAlignmentSummary(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithoutThemeAlignmentSummary())
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_alignment_summary for "home_page"');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);
    }

    public function testBuildPlanArtifactsByAiStreamRejectsPromptLikeHeroInsteadOfSilentFallback(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildPromptLikeAiPlanResponse())
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instruction-like');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Teenipiya websiteProfile',
            'brief_description' => '面向印度市场的棋牌游戏网站，推广热门棋牌游戏 APK 下载与玩法内容。',
            'page_types' => ['home_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
            'default_locale' => 'zh_Hans_CN',
        ], [
            'site_title' => 'Teenipiya websiteProfile',
            'brief_description' => '面向印度市场的棋牌游戏网站，推广热门棋牌游戏 APK 下载与玩法内容。',
        ]);
    }

    public function testBuildPlanArtifactsByAiStreamRejectsHomepageHeroThatIgnoresBriefSignals(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildGenericAiPlanResponse())
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('homepage hero does not reuse concrete nouns from the brief');

        $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Teenipiya websiteProfile',
            'brief_description' => '面向印度市场的棋牌游戏网站，推广热门棋牌游戏 APK 下载与玩法内容。',
            'page_types' => ['home_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
            'default_locale' => 'zh_Hans_CN',
        ], [
            'site_title' => 'Teenipiya websiteProfile',
            'brief_description' => '面向印度市场的棋牌游戏网站，推广热门棋牌游戏 APK 下载与玩法内容。',
        ]);
    }

    private static function assertStageOneThemeDesignSchema(mixed $themeDesign): void
    {
        self::assertIsArray($themeDesign);
        foreach ([
            'theme_purpose',
            'color_scheme',
            'typography_spacing_radius',
            'visual_keywords',
            'tone_of_voice',
            'cta_tone',
            'forbidden_styles',
            'selection_reason',
        ] as $field) {
            self::assertArrayHasKey($field, $themeDesign);
        }

        self::assertIsArray($themeDesign['color_scheme']);
        foreach (['name', 'primary', 'secondary', 'accent', 'background', 'body', 'button'] as $field) {
            self::assertArrayHasKey($field, $themeDesign['color_scheme']);
        }

        self::assertIsArray($themeDesign['typography_spacing_radius']);
        foreach (['font_family', 'heading_scale', 'body_scale', 'spacing_scale', 'radius_scale'] as $field) {
            self::assertArrayHasKey($field, $themeDesign['typography_spacing_radius']);
        }
    }

    private static function findQueueJobByType(mixed $jobs, string $jobType): ?array
    {
        if (!\is_array($jobs)) {
            return null;
        }
        foreach ($jobs as $job) {
            if (\is_array($job) && (string)($job['job_type'] ?? '') === $jobType) {
                return $job;
            }
        }

        return null;
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
                    'selection_reason' => 'Sans Serif and a structured tone keep the strong CTA brief readable and trustworthy for home and about visitors.',
                ],
                'palette' => [
                    'name' => 'Ocean Slate',
                    'primary' => '#0f172a',
                    'secondary' => '#14b8a6',
                    'accent' => '#2563eb',
                    'surface' => '#f8fafc',
                    'text' => '#0f172a',
                    'selection_reason' => 'Ocean Slate balances trust and action so the strong CTA stands out without overwhelming the home and about content.',
                ],
                'theme_design' => [
                    'theme_purpose' => 'Build trust quickly and guide visitors toward one clear CTA.',
                    'color_scheme' => [
                        'name' => 'Ocean Slate',
                        'primary' => '#0f172a',
                        'secondary' => '#475569',
                        'accent' => '#2563eb',
                        'background' => '#f8fafc',
                        'body' => '#0f172a',
                        'text' => '#0f172a',
                        'button' => '#2563eb',
                    ],
                    'typography_spacing_radius' => [
                        'font_family' => 'Sans Serif',
                        'heading_scale' => 'Hero 40-56px, section headings 28-36px.',
                        'body_scale' => 'Body copy 16-18px with readable line height.',
                        'spacing_scale' => 'Use 8px spacing units with generous section rhythm.',
                        'radius_scale' => 'Cards use 16px radius and CTA buttons use pill radius.',
                    ],
                    'visual_keywords' => ['clear trust', 'conversion focus'],
                    'tone_of_voice' => 'Trustworthy and action-oriented',
                    'cta_tone' => 'Direct CTA labels that move strong CTA visitors forward.',
                    'forbidden_styles' => ['Do not use vague premium-only descriptions.'],
                    'selection_reason' => 'The strong CTA, trust, and conversion requirement needs a concrete trust-first visual system for home and about visitors.',
                ],
                'navigation_plan' => [
                    'header_items' => [
                        ['label' => 'Home', 'href' => '/'],
                        ['label' => 'About', 'href' => '/about'],
                    ],
                ],
                'footer_plan' => [
                    'featured' => [
                        ['label' => 'Contact', 'href' => '/contact'],
                    ],
                    'policies' => [
                        ['label' => 'Privacy Policy', 'href' => '/privacy-policy'],
                    ],
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
                        'theme_alignment_summary' => 'Home page blocks use the Ocean Slate palette, trustworthy action tone, direct CTA rhythm, and Header-to-Footer trust handoff from the shared theme plan.',
                        'primary_keywords' => ['home keyword'],
                        'secondary_keywords' => ['cta keyword'],
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Explain value',
                                'keywords' => ['hero keyword'],
                                'content' => 'Welcome to Plan Service Test. Start with a clear value statement, a short proof line, and one CTA that leads visitors forward.',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Welcome to Plan Service Test', 'implementation_note' => 'Use this as the visible hero headline so the client can approve the promise immediately.', 'reason' => 'Use this as the visible hero headline so the client can approve the promise immediately.'],
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
                        'theme_alignment_summary' => 'About page blocks keep the Ocean Slate trust palette, brand-proof voice, shared CTA language, and Footer reassurance aligned with the theme plan.',
                        'primary_keywords' => ['about keyword'],
                        'secondary_keywords' => ['trust keyword'],
                        'blocks' => [
                            [
                                'block_key' => 'hero',
                                'goal' => 'Introduce brand',
                                'keywords' => ['brand keyword'],
                                'content' => 'Learn how Plan Service Test works, why the team is credible, and what visitors can expect after getting in touch.',
                                'field_plan' => [
                                    ['field' => 'title', 'sample' => 'Why visitors trust Plan Service Test', 'implementation_note' => 'Use this heading to frame the trust section in visible client-facing language.', 'reason' => 'Use this heading to frame the trust section in visible client-facing language.'],
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

    private function buildTopLevelAiPlanResponse(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $planJson = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : [];
        if ($planJson === []) {
            return '{}';
        }

        return \json_encode(
            \array_merge(
                ['markdown' => (string)($decoded['markdown'] ?? '# Site Blueprint')],
                $planJson
            ),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }

    private function buildAiPlanResponseWithThemeSelectionReason(string $selectionReason): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        if (!\is_array($decoded['plan_json']['theme_design'] ?? null)) {
            $decoded['plan_json']['theme_design'] = [];
        }
        $decoded['plan_json']['theme_design']['selection_reason'] = $selectionReason;

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildAiPlanResponseWithoutThemeAlignmentSummary(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        unset($decoded['plan_json']['pages']['home_page']['theme_alignment_summary']);

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildPromptLikeAiPlanResponse(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['site_strategy']['summary'] = 'Explain the core value with concise readable paragraphs.';
        $decoded['plan_json']['theme_design']['selection_reason'] = 'The strong CTA and APK download requirement needs a concrete trust-first visual system before this prompt-like payload is rejected.';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['content'] = 'Write the title around the core value, then explain the main highlights and next CTA.';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['field_plan'][0]['sample'] = '标题围绕核心价值展开';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['field_plan'][0]['implementation_note'] = 'Keep the first-screen promise concise and lead visitors to the next step.';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['feature_points'] = ['List 2-4 value points'];
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['core_copy'] = 'Do not describe what should be written.';

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildPartnerAiPlanResponse(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['pages']['home_page']['blocks'][] = [
            'block_key' => 'partner',
            'goal' => 'Show partner proof',
            'keywords' => ['partner logos'],
            'content' => 'Trusted by partner brands that appear across the onboarding and trust journey.',
            'field_plan' => [
                ['field' => 'title', 'sample' => '合作品牌', 'implementation_note' => 'Use this as the visible section heading.'],
                ['field' => 'description', 'sample' => '展示合作品牌、合作方向与可信背书。', 'implementation_note' => 'Use this as the supporting copy for the logo wall.'],
            ],
            'execution_script' => [
                'feature_points' => ['Visible headline: 合作品牌', 'Support copy: 展示合作品牌、合作方向与可信背书。'],
                'core_copy' => '合作品牌与信任背书在同一区块展示，方便快速建立可信度。',
                'typography' => 'Readable',
                'style_tone' => 'Trustworthy',
                'background_direction' => 'Neutral',
                'media_assets' => ['partner-logos.png'],
            ],
            'reusable' => 'yes',
            'seo_impact' => 'medium',
        ];

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildGenericAiPlanResponse(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['theme_design']['selection_reason'] = 'The Teenipiya APK download requirement needs a concrete trust-first visual system for rummy players.';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['content'] = '欢迎来到 Teenipiya websiteProfile。这里会展示重点内容，并提供一个清晰的下一步入口。';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['field_plan'] = [
            ['field' => 'title', 'sample' => '欢迎来到 Teenipiya websiteProfile', 'implementation_note' => '作为区块主标题直接上屏。'],
            ['field' => 'subtitle', 'sample' => '这里会展示重点内容', 'implementation_note' => '放在标题下方补充信息。'],
            ['field' => 'description', 'sample' => '浏览重点内容后即可进入下一步入口。', 'implementation_note' => '补充简短说明。'],
            ['field' => 'button_text', 'sample' => '立即开始', 'implementation_note' => '按钮文本。'],
        ];
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['core_copy'] = '欢迎来到 Teenipiya websiteProfile，这里会展示重点内容并提供下一步入口。';
        $decoded['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['feature_points'] = ['Visible headline', 'Primary CTA'];

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
