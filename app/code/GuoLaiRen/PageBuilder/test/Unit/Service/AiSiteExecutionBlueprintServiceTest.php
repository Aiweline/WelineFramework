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
        foreach (['implementation_detail', 'realtime_content', 'reason', 'completion_rule', 'editable_fields'] as $requiredBlockField) {
            self::assertArrayHasKey($requiredBlockField, $sharedTask);
        }
        $pageTask = $artifacts['execution_blueprint']['tasks'][2] ?? [];
        foreach (['implementation_detail', 'realtime_content', 'reason', 'completion_rule', 'editable_fields'] as $requiredBlockField) {
            self::assertArrayHasKey($requiredBlockField, $pageTask['block'] ?? []);
        }
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($pageTask['source_ref']['shared_context_hash'] ?? '')
        );
    }

    public function testStageOneThemePlanningFieldsAreCompleteAcrossArtifacts(): void
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

        $planThemeDesign = $artifacts['plan_json']['theme_design'] ?? null;
        self::assertStageOneThemeDesignSchema($planThemeDesign);
        $expectedThemeDesignCore = self::stageOneThemeDesignCore($planThemeDesign);
        $themeDesignJob = self::findQueueJobByType($artifacts['execution_blueprint']['queue_jobs'] ?? [], 'stage1.shared.theme_design');
        self::assertIsArray($themeDesignJob);
        foreach ([
            'structured.shared_plan.theme_design' => $artifacts['structured']['shared_plan']['theme_design'] ?? null,
            'execution_blueprint.theme_context_snapshot' => $artifacts['execution_blueprint']['theme_context_snapshot'] ?? null,
            'execution_blueprint.shared_prompt_context.theme_design' => $artifacts['execution_blueprint']['shared_prompt_context']['theme_design'] ?? null,
            'plan_workbench.stage1.theme_context_snapshot' => $artifacts['plan_workbench']['stage1']['theme_context_snapshot'] ?? null,
            'execution_blueprint.queue_jobs.stage1.shared.theme_design.theme_context_snapshot' => $themeDesignJob['theme_context_snapshot'] ?? null,
        ] as $path => $themeDesign) {
            self::assertStageOneThemeDesignSchema($themeDesign);
            self::assertSame($expectedThemeDesignCore, self::stageOneThemeDesignCore($themeDesign), $path . ' must mirror plan_json.theme_design core planning fields.');
        }

        $selectionReason = (string)($planThemeDesign['selection_reason'] ?? '');
        self::assertStringContainsString('strong CTA', $selectionReason);

        $sharedPromptContext = $artifacts['execution_blueprint']['shared_prompt_context'] ?? null;
        self::assertIsArray($sharedPromptContext);
        self::assertSame($planThemeDesign, $sharedPromptContext['theme_design'] ?? null);
        self::assertNotSame('', (string)($sharedPromptContext['context_hash'] ?? ''));
        self::assertIsArray($sharedPromptContext['header_plan'] ?? null);
        self::assertIsArray($sharedPromptContext['footer_plan'] ?? null);

        foreach (['home_page', 'about_page'] as $pageType) {
            $summary = (string)($artifacts['plan_json']['pages'][$pageType]['theme_alignment_summary'] ?? '');
            self::assertNotSame('', $summary, $pageType . ' theme_alignment_summary must be present.');
            foreach (['Ocean Slate', 'CTA', 'Header', 'Footer'] as $requiredToken) {
                self::assertStringContainsString($requiredToken, $summary, $pageType . ' theme_alignment_summary must prove shared-theme alignment.');
            }
            self::assertSame(
                $summary,
                (string)($artifacts['structured']['page_plans'][$pageType]['theme_alignment_summary'] ?? ''),
                $pageType . ' structured page plan must mirror the customer-visible theme alignment summary.'
            );
        }
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

    public function testPlanBookStructuredIsGeneratedFromStageOneBlockTree(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        [$scope, $structured, $executionBlueprint, $planJson] = $this->buildPlanBookStructuredFixture();

        $method = new \ReflectionMethod($service, 'buildPlanWorkbenchArtifacts');
        $method->setAccessible(true);
        $planWorkbench = $method->invoke(
            $service,
            $scope,
            $structured,
            $executionBlueprint,
            $planJson,
            '# Plan book fixture',
            'en_US'
        );

        $planBook = $planWorkbench['confirmed']['plan_book']['structured'] ?? null;

        self::assertIsArray($planBook);
        self::assertSame('stage1.block_tree', (string)($planBook['source'] ?? ''));
        self::assertSame(
            (string)($executionBlueprint['signature'] ?? ''),
            (string)($planBook['source_signature'] ?? '')
        );
        self::assertNotSame('', (string)($planBook['context_hash'] ?? ''));
        self::assertSame(2, (int)($planBook['counts']['shared_blocks'] ?? 0));
        self::assertArrayHasKey('home_page', $planBook['pages'] ?? []);

        $sharedHeader = $planBook['shared_blocks'][0] ?? [];
        self::assertSame('shared:header', (string)($sharedHeader['task_key'] ?? ''));
        self::assertSame('shared:header', (string)($sharedHeader['block_key'] ?? ''));
        self::assertNotSame('', (string)($sharedHeader['completion_rule'] ?? ''));
        self::assertNotEmpty($sharedHeader['editable_fields'] ?? []);
        self::assertNotSame('', (string)($sharedHeader['context_hash'] ?? ''));

        $sourceHomeBlocks = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            $structured['page_plans']['home_page']['blocks'] ?? []
        ));
        $bookHomeBlocks = \array_values(\array_map(
            static fn(array $block): string => (string)($block['source_block_key'] ?? ''),
            $planBook['pages']['home_page']['blocks'] ?? []
        ));

        self::assertSame($sourceHomeBlocks, $bookHomeBlocks);
        self::assertNotEmpty($bookHomeBlocks);

        $firstPageBlock = $planBook['pages']['home_page']['blocks'][0] ?? [];
        self::assertSame('page:home_page:' . $bookHomeBlocks[0], (string)($firstPageBlock['task_key'] ?? ''));
        self::assertSame('page:home_page:' . $bookHomeBlocks[0], (string)($firstPageBlock['block_key'] ?? ''));
        self::assertNotSame('', (string)($firstPageBlock['implementation_detail'] ?? ''));
        self::assertIsArray($firstPageBlock['realtime_content'] ?? null);
        self::assertNotSame('', (string)($firstPageBlock['reason'] ?? ''));
        self::assertNotSame('', (string)($firstPageBlock['completion_rule'] ?? ''));
        self::assertNotEmpty($firstPageBlock['editable_fields'] ?? []);
        self::assertNotSame('', (string)($firstPageBlock['context_hash'] ?? ''));
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

    public function testRefineDraftPlanPageOnlyReplacesCurrentPageTree(): void
    {
        $baselineService = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $baseline = $baselineService->buildPlanArtifacts([
            'site_title' => 'Page Only Refine Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
        ], [
            'site_title' => 'Page Only Refine Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);
        $scope = [
            'site_title' => 'Page Only Refine Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'plan_json' => $baseline['plan_json'],
            'plan_markdown' => $baseline['markdown'],
            'plan_structured' => $baseline['structured'],
            'plan_workbench' => $baseline['plan_workbench'],
            'execution_blueprint_draft' => $baseline['execution_blueprint'],
        ];
        $originalAboutPage = $baseline['plan_json']['pages'][Page::TYPE_ABOUT] ?? [];
        $originalSharedBlocks = $baseline['plan_json']['shared_blocks'] ?? [];

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildPageOnlyRefineAiPlanResponse())
        );

        $refined = $service->refineDraftPlanPage($scope, [
            'site_title' => 'Page Only Refine Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ], Page::TYPE_HOME, [
            'instruction' => 'Tighten the current home page around a launch offer.',
            'round' => 2,
        ]);

        self::assertSame('refine_page', (string)($refined['page_refine_summary']['mode'] ?? ''));
        self::assertSame(Page::TYPE_HOME, (string)($refined['page_refine_summary']['page_type'] ?? ''));
        self::assertContains(Page::TYPE_ABOUT, $refined['page_refine_summary']['preserved_page_types'] ?? []);
        self::assertStringContainsString(
            'Launch offer headline',
            (string)\json_encode($refined['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? [], \JSON_UNESCAPED_UNICODE)
        );
        self::assertStringNotContainsString(
            'SHOULD_NOT_LEAK_TO_ABOUT_PAGE',
            (string)\json_encode($refined['plan_json']['pages'][Page::TYPE_ABOUT] ?? [], \JSON_UNESCAPED_UNICODE)
        );
        self::assertSame($originalAboutPage, $refined['plan_json']['pages'][Page::TYPE_ABOUT] ?? []);
        self::assertSame($originalSharedBlocks, $refined['plan_json']['shared_blocks'] ?? []);

        $homeTaskKeys = \array_values(\array_filter(
            \array_map(
                static fn(array $task): string => (string)($task['task_key'] ?? ''),
                \is_array($refined['execution_blueprint']['tasks'] ?? null) ? $refined['execution_blueprint']['tasks'] : []
            ),
            static fn(string $taskKey): bool => \str_starts_with($taskKey, 'page:' . Page::TYPE_HOME . ':')
        ));
        self::assertContains('page:' . Page::TYPE_HOME . ':launch_offer', $homeTaskKeys);
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

    public function testPlanBlockMutationCreateRebuildDeleteUpdatesStageOneAssembly(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Mutation Plan Test',
            'brief_description' => 'Need editable stage-one blocks that can be locally mutated.',
            'page_types' => [Page::TYPE_HOME],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
        ], [
            'site_title' => 'Mutation Plan Test',
            'brief_description' => 'Need editable stage-one blocks that can be locally mutated.',
        ]);

        $pageType = Page::TYPE_HOME;
        $originalBlocks = \array_values(\is_array($artifacts['structured']['pages'][$pageType]['blocks'] ?? null)
            ? $artifacts['structured']['pages'][$pageType]['blocks']
            : []);
        self::assertNotEmpty($originalBlocks);
        $firstBlockKey = (string)($originalBlocks[0]['block_key'] ?? '');
        self::assertNotSame('', $firstBlockKey);

        $scope = [
            'plan_locale' => 'en_US',
            'plan_json' => $artifacts['plan_json'],
            'plan_markdown' => $artifacts['markdown'],
            'plan_structured' => $artifacts['structured'],
            'plan_workbench' => $artifacts['plan_workbench'],
            'execution_blueprint_draft' => $artifacts['execution_blueprint'],
        ];

        $created = $service->mutateDraftPlanBlock($scope, $pageType, 'create', '', [
            'title' => 'Guarantee Block',
            'goal' => 'Show a concrete guarantee before the final CTA.',
            'after_block_key' => $firstBlockKey,
        ]);

        $createdBlocks = \array_values(\is_array($created['structured']['pages'][$pageType]['blocks'] ?? null)
            ? $created['structured']['pages'][$pageType]['blocks']
            : []);
        self::assertCount(\count($originalBlocks) + 1, $createdBlocks);
        self::assertSame('guarantee_block', (string)($created['mutation_summary']['block_key'] ?? ''));
        self::assertArrayHasKey(
            'page:' . $pageType . ':guarantee_block',
            \is_array($created['structured']['block_index']['flat'] ?? null) ? $created['structured']['block_index']['flat'] : []
        );
        self::assertStringContainsString('#### guarantee_block', (string)$created['markdown']);
        self::assertSame(
            $created['structured']['block_index'],
            $created['plan_workbench']['confirmed']['block_index'] ?? []
        );

        $createdScope = [
            'plan_locale' => 'en_US',
            'plan_json' => $created['plan_json'],
            'plan_markdown' => $created['markdown'],
            'plan_structured' => $created['structured'],
            'plan_workbench' => $created['plan_workbench'],
            'execution_blueprint_draft' => $created['execution_blueprint'],
        ];
        $rebuilt = $service->mutateDraftPlanBlock($createdScope, $pageType, 'rebuild', 'guarantee_block', [
            'goal' => 'Updated guarantee block goal.',
            'field_plan' => [
                ['field' => 'headline', 'sample' => 'Guaranteed launch clarity', 'reason' => 'Specific guarantee headline'],
            ],
        ]);
        $rebuiltBlock = \array_values(\array_filter(
            \is_array($rebuilt['structured']['pages'][$pageType]['blocks'] ?? null) ? $rebuilt['structured']['pages'][$pageType]['blocks'] : [],
            static fn(array $block): bool => (string)($block['block_key'] ?? '') === 'guarantee_block'
        ))[0] ?? [];
        self::assertSame('Updated guarantee block goal.', (string)($rebuiltBlock['goal'] ?? ''));
        self::assertGreaterThanOrEqual(2, (int)($rebuiltBlock['version'] ?? 0));
        self::assertGreaterThanOrEqual(2, (int)($rebuilt['mutation_summary']['assembly_version'] ?? 0));

        $rebuiltScope = [
            'plan_locale' => 'en_US',
            'plan_json' => $rebuilt['plan_json'],
            'plan_markdown' => $rebuilt['markdown'],
            'plan_structured' => $rebuilt['structured'],
            'plan_workbench' => $rebuilt['plan_workbench'],
            'execution_blueprint_draft' => $rebuilt['execution_blueprint'],
        ];
        $deleted = $service->mutateDraftPlanBlock($rebuiltScope, $pageType, 'delete', 'guarantee_block');
        $deletedBlocks = \array_values(\is_array($deleted['structured']['pages'][$pageType]['blocks'] ?? null)
            ? $deleted['structured']['pages'][$pageType]['blocks']
            : []);
        self::assertCount(\count($originalBlocks), $deletedBlocks);
        self::assertArrayNotHasKey(
            'page:' . $pageType . ':guarantee_block',
            \is_array($deleted['structured']['block_index']['flat'] ?? null) ? $deleted['structured']['block_index']['flat'] : []
        );
        self::assertStringNotContainsString('#### guarantee_block', (string)$deleted['markdown']);
    }

    public function testPageBlockReorderWritesStructuredSortOrderMarkdownAndStageTwoSplitOrder(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $pageType = Page::TYPE_HOME;
        $originalKeys = ['hero', 'proof', 'cta'];
        $orderedKeys = ['cta', 'proof', 'hero'];
        $pagePlan = [
            'page_label' => 'Home',
            'page_goal' => 'Explain value and convert visitors.',
            'primary_keywords' => ['value'],
            'secondary_keywords' => ['proof'],
            'blocks' => [
                [
                    'block_key' => 'hero',
                    'section_code' => 'hero',
                    'order' => 10,
                    'goal' => 'Lead with the core promise.',
                    'content_brief' => ['headline_direction' => 'Hero headline'],
                    'field_plan' => [['field' => 'headline', 'sample' => 'Build faster', 'reason' => 'Primary value']],
                    'result_ref' => [],
                ],
                [
                    'block_key' => 'proof',
                    'section_code' => 'proof',
                    'order' => 20,
                    'goal' => 'Show trust evidence.',
                    'content_brief' => ['headline_direction' => 'Proof headline'],
                    'field_plan' => [['field' => 'proof_point', 'sample' => 'Trusted by teams', 'reason' => 'Trust cue']],
                    'result_ref' => [],
                ],
                [
                    'block_key' => 'cta',
                    'section_code' => 'cta',
                    'order' => 30,
                    'goal' => 'Invite the next action.',
                    'content_brief' => ['headline_direction' => 'CTA headline'],
                    'field_plan' => [['field' => 'button_label', 'sample' => 'Start now', 'reason' => 'Conversion cue']],
                    'result_ref' => [],
                ],
            ],
        ];
        $sharedComponents = [
            'header' => ['task_key' => 'shared:header', 'task_type' => 'shared_component', 'component' => 'header', 'sort_order' => 10, 'goal' => 'Header'],
            'footer' => ['task_key' => 'shared:footer', 'task_type' => 'shared_component', 'component' => 'footer', 'sort_order' => 20, 'goal' => 'Footer'],
        ];
        $sharedPromptContext = ['context_hash' => 'shared-context', 'theme_context_hash' => 'theme-context'];
        $structured = [
            'i18n' => ['locale' => 'en_US'],
            'site_strategy' => ['site_display_name' => 'Page Block Sort Writeback Test', 'summary' => 'Summary'],
            'palette' => ['name' => 'Ocean Slate'],
            'theme_style' => ['name' => 'Plan-Driven Hybrid'],
            'seo_strategy' => ['core_intent' => 'intent'],
            'navigation_plan' => ['header_items' => []],
            'footer_plan' => ['featured' => [], 'policies' => []],
            'page_types' => [$pageType],
            'shared_components' => $sharedComponents,
            'shared_plan' => [
                'theme_design' => ['theme_purpose' => 'Test purpose'],
                'shared_prompt_context' => $sharedPromptContext,
            ],
            'pages' => [$pageType => $pagePlan],
        ];
        $executionBlueprint = [
            'workspace_track' => 'virtual_theme',
            'page_types' => [$pageType],
            'shared_prompt_context' => $sharedPromptContext,
            'shared_components' => $sharedComponents,
            'pages' => [$pageType => $pagePlan],
            'tasks' => \array_merge(
                \array_values($sharedComponents),
                \array_map(
                    static fn(array $block): array => [
                        'task_key' => 'page:' . $pageType . ':' . (string)$block['block_key'],
                        'task_type' => 'page_block',
                        'page_type' => $pageType,
                        'page_label' => 'Home',
                        'sort_order' => (int)($block['order'] ?? 0),
                        'block' => $block,
                        'status' => 'pending',
                    ],
                    $pagePlan['blocks']
                )
            ),
        ];
        $planJson = [
            'i18n' => ['locale' => 'en_US'],
            'site_strategy' => $structured['site_strategy'],
            'palette' => $structured['palette'],
            'theme_style' => $structured['theme_style'],
            'seo_strategy' => $structured['seo_strategy'],
            'navigation_plan' => $structured['navigation_plan'],
            'footer_plan' => $structured['footer_plan'],
            'page_types' => [$pageType],
            'pages' => [$pageType => $pagePlan],
        ];
        $scope = [
            'site_title' => 'Page Block Sort Writeback Test',
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'plan_json' => $planJson,
            'plan_markdown' => '',
            'plan_structured' => $structured,
            'plan_workbench' => [],
            'execution_blueprint_draft' => $executionBlueprint,
        ];

        $reordered = $service->reorderDraftPlanBlocks($scope, $pageType, $orderedKeys);

        $expectedSortOrders = \array_map(
            static fn(int $index): int => ($index + 1) * 10,
            \array_keys($orderedKeys)
        );
        $structuredPageBlocks = \array_values(\is_array($reordered['structured']['pages'][$pageType]['blocks'] ?? null)
            ? $reordered['structured']['pages'][$pageType]['blocks']
            : []);
        self::assertSame($orderedKeys, \array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            $structuredPageBlocks
        ));
        self::assertSame($expectedSortOrders, \array_map(
            static fn(array $block): int => (int)($block['sort_order'] ?? 0),
            $structuredPageBlocks
        ));
        self::assertSame($expectedSortOrders, \array_map(
            static fn(array $block): int => (int)($block['order'] ?? 0),
            $structuredPageBlocks
        ));
        self::assertSame($expectedSortOrders, \array_map(
            static fn(array $block): int => (int)($block['sort_order'] ?? 0),
            \array_values(\is_array($reordered['plan_json']['pages'][$pageType]['blocks'] ?? null)
                ? $reordered['plan_json']['pages'][$pageType]['blocks']
                : [])
        ));

        $blockIndexRows = \array_values(\is_array($reordered['structured']['block_index']['pages'][$pageType] ?? null)
            ? $reordered['structured']['block_index']['pages'][$pageType]
            : []);
        self::assertSame($orderedKeys, \array_map(
            static fn(array $row): string => (string)($row['source_block_key'] ?? ''),
            $blockIndexRows
        ));
        self::assertSame($expectedSortOrders, \array_map(
            static fn(array $row): int => (int)($row['sort_order'] ?? 0),
            $blockIndexRows
        ));

        $firstMarkdownPosition = \strpos((string)$reordered['markdown'], '#### ' . $orderedKeys[0]);
        $secondMarkdownPosition = \strpos((string)$reordered['markdown'], '#### ' . $orderedKeys[1]);
        self::assertIsInt($firstMarkdownPosition);
        self::assertIsInt($secondMarkdownPosition);
        self::assertLessThan($secondMarkdownPosition, $firstMarkdownPosition);

        $pageTasks = \array_values(\array_filter(
            \is_array($reordered['execution_blueprint']['tasks'] ?? null) ? $reordered['execution_blueprint']['tasks'] : [],
            static fn(array $task): bool => (string)($task['page_type'] ?? '') === $pageType
                && \trim((string)($task['block']['block_key'] ?? '')) !== ''
        ));
        self::assertSame($orderedKeys, \array_map(
            static fn(array $task): string => (string)($task['block']['block_key'] ?? ''),
            $pageTasks
        ));
        self::assertSame($expectedSortOrders, \array_map(
            static fn(array $task): int => (int)($task['sort_order'] ?? 0),
            $pageTasks
        ));

        $stageTwoService = new AiSiteVirtualThemePlanService();
        $taskPlan = $stageTwoService->buildTaskPlanArtifacts([
            'fake_mode' => 1,
            'site_title' => 'Page Block Sort Writeback Test',
            'workspace_track' => 'virtual_theme',
            'plan_json' => $reordered['plan_json'],
            'plan_structured' => $reordered['structured'],
            'plan_markdown' => $reordered['markdown'],
            'plan_workbench' => $reordered['plan_workbench'],
            'execution_blueprint' => $reordered['execution_blueprint'],
            'execution_blueprint_confirmed_signature' => (string)($reordered['execution_blueprint']['signature'] ?? ''),
        ], $reordered['execution_blueprint']);
        $stageTwoPageTasks = \array_values(\is_array($taskPlan['structured']['page_tasks'][$pageType] ?? null)
            ? $taskPlan['structured']['page_tasks'][$pageType]
            : []);
        self::assertSame(
            \array_map(static fn(string $blockKey): string => 'page:' . $pageType . ':' . $blockKey, $orderedKeys),
            \array_map(static fn(array $task): string => (string)($task['task_key'] ?? ''), $stageTwoPageTasks)
        );
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
        $pageType = Page::TYPE_HOME;
        $laterBlock = [
            'block_key' => 'later_block',
            'section_code' => 'Later Block',
            'sort_order' => 20,
            'order' => 20,
            'goal' => 'Render after the earlier block.',
            'realtime_content' => [
                'headline' => 'Later headline',
                'supporting_copy' => ['Later copy'],
                'cta' => [['label' => 'Later CTA']],
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => 'Later headline'],
            ],
        ];
        $earlierBlock = [
            'block_key' => 'earlier_block',
            'section_code' => 'Earlier Block',
            'sort_order' => 10,
            'order' => 10,
            'goal' => 'Render before the later block.',
            'realtime_content' => [
                'headline' => 'Earlier headline',
                'supporting_copy' => ['Earlier copy'],
                'cta' => [['label' => 'Earlier CTA']],
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => 'Earlier headline'],
            ],
        ];
        $structured = [
            'i18n' => ['locale' => 'en_US'],
            'site_strategy' => [
                'site_display_name' => 'Sorted Markdown Test',
                'summary' => 'The markdown reader must be assembled from sorted blocks.',
            ],
            'theme_style' => ['name' => 'Sorted Theme'],
            'palette' => ['name' => 'Sorted Palette'],
            'navigation_plan' => ['header_items' => [['label' => 'Home', 'href' => '/']]],
            'footer_plan' => ['featured' => [], 'policies' => []],
            'seo_strategy' => ['core_intent' => 'sorted markdown'],
            'page_types' => [$pageType],
            'pages' => [
                $pageType => [
                    'page_label' => 'Home',
                    'page_goal' => 'Prove sorted markdown assembly.',
                    'theme_alignment_summary' => 'Home follows the shared sorted plan.',
                    'primary_keywords' => ['sorted markdown'],
                    'secondary_keywords' => ['block tree'],
                    'blocks' => [$laterBlock, $earlierBlock],
                ],
            ],
        ];

        $buildPlanJson = new \ReflectionMethod($service, 'buildPlanJson');
        $buildPlanJson->setAccessible(true);
        $planJson = $buildPlanJson->invoke($service, $structured);
        self::assertIsArray($planJson);

        $blockKeys = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            $planJson['pages'][$pageType]['blocks'] ?? []
        ));
        self::assertSame(['earlier_block', 'later_block'], \array_slice($blockKeys, 0, 2));

        $planJson['markdown'] = 'FREE MARKDOWN SENTINEL THAT MUST NOT BE REUSED';
        $buildMarkdownPlan = new \ReflectionMethod($service, 'buildMarkdownPlan');
        $buildMarkdownPlan->setAccessible(true);
        $markdown = (string)$buildMarkdownPlan->invoke($service, $planJson, 'en_US');

        $earlierPosition = \strpos($markdown, '#### earlier_block');
        $laterPosition = \strpos($markdown, '#### later_block');
        self::assertIsInt($earlierPosition);
        self::assertIsInt($laterPosition);
        self::assertLessThan($laterPosition, $earlierPosition);
        self::assertStringContainsString('Earlier headline', $markdown);
        self::assertStringContainsString('Later headline', $markdown);
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
        self::assertSame(240, (int)($capturedParams['timeout'] ?? 0));
        self::assertTrue((bool)($capturedParams['enforce_timeout_in_stream'] ?? false));
        self::assertSame(['type' => 'json_object'], $capturedParams['response_format'] ?? null);
        self::assertSame('ai', (string)($artifacts['generation_source'] ?? 'ai'));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['theme_style']['selection_reason'] ?? ''));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['palette']['selection_reason'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamSupportsStagedThemeAndPageGeneration(): void
    {
        $calls = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$calls): void {
                $calls[] = [
                    'prompt' => $prompt,
                    'scenario' => $scenarioCode,
                    'params' => $params,
                ];
                $callback(match (\count($calls)) {
                    2 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ], [
            'staged_generation' => true,
        ]);

        self::assertSame('ai_staged', (string)($artifacts['generation_source'] ?? ''));
        self::assertSame('pagebuilder_plan_generation', (string)($calls[0]['scenario'] ?? ''));
        self::assertSame('pagebuilder_plan_generation', (string)($calls[1]['scenario'] ?? ''));
        self::assertSame('pagebuilder_plan_generation', (string)($calls[2]['scenario'] ?? ''));
        self::assertStringContainsString('THEME planner', (string)($calls[0]['prompt'] ?? ''));
        self::assertStringContainsString('PAGE planner', (string)($calls[1]['prompt'] ?? ''));
        self::assertStringContainsString('Critical page differentiation rules:', (string)($calls[1]['prompt'] ?? ''));
        self::assertStringContainsString('Page-type architecture guide:', (string)($calls[2]['prompt'] ?? ''));
        self::assertStringContainsString('design_tags', (string)($calls[1]['prompt'] ?? ''));
        self::assertTrue((bool)($calls[0]['params']['enforce_timeout_in_stream'] ?? false));
        self::assertTrue((bool)($calls[1]['params']['enforce_timeout_in_stream'] ?? false));
        self::assertLessThanOrEqual(4096, (int)($calls[1]['params']['max_tokens'] ?? 0));

        $homeBlocks = \array_values(\array_map(static fn(array $block): string => (string)($block['block_key'] ?? ''), $artifacts['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? []));
        $aboutBlocks = \array_values(\array_map(static fn(array $block): string => (string)($block['block_key'] ?? ''), $artifacts['plan_json']['pages'][Page::TYPE_ABOUT]['blocks'] ?? []));
        self::assertNotSame($homeBlocks, $aboutBlocks);
        self::assertSame(['hero', 'featured_games', 'final_cta'], $homeBlocks);
        self::assertSame(['brand_story', 'mission_values', 'community_cta'], $aboutBlocks);
        self::assertContains('5s fade in/out', $artifacts['structured']['page_plans'][Page::TYPE_HOME]['blocks'][0]['design_tags']['motion'] ?? []);
        self::assertContains('rounded image', $artifacts['structured']['page_plans'][Page::TYPE_HOME]['blocks'][0]['design_tags']['visual'] ?? []);
        self::assertContains('timeline reveal', $artifacts['structured']['page_plans'][Page::TYPE_ABOUT]['blocks'][0]['design_tags']['motion'] ?? []);
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

    public function testBuildPlanArtifactsByAiStreamRepairsMissingPageThemeAlignmentSummary(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithoutThemeAlignmentSummary())
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
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

        $summary = (string)($artifacts['plan_json']['pages']['home_page']['theme_alignment_summary'] ?? '');
        self::assertNotSame('', $summary);
        self::assertStringContainsString('shared theme purpose', $summary);
        self::assertStringNotContainsString('string explaining how this page obeys', $summary);
    }

    public function testBuildPlanArtifactsByAiStreamRepairsPromptLikeAboutThemeAlignmentSummary(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithPromptLikeAboutThemeAlignmentSummary())
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
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

        $summary = (string)($artifacts['plan_json']['pages']['about_page']['theme_alignment_summary'] ?? '');
        self::assertNotSame('', $summary);
        self::assertStringContainsString('shared theme purpose', $summary);
        self::assertStringContainsString('Ocean Slate', $summary);
        self::assertStringNotContainsString('string explaining how this page obeys', $summary);
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
        foreach (['theme_purpose', 'tone_of_voice', 'cta_tone', 'selection_reason'] as $field) {
            self::assertNotSame('', \trim((string)$themeDesign[$field]), 'theme_design.' . $field . ' must not be empty.');
        }

        self::assertIsArray($themeDesign['color_scheme']);
        foreach (['name', 'primary', 'secondary', 'accent', 'background', 'body', 'button'] as $field) {
            self::assertArrayHasKey($field, $themeDesign['color_scheme']);
            self::assertNotSame('', \trim((string)$themeDesign['color_scheme'][$field]), 'theme_design.color_scheme.' . $field . ' must not be empty.');
        }

        self::assertIsArray($themeDesign['typography_spacing_radius']);
        foreach (['font_family', 'heading_scale', 'body_scale', 'spacing_scale', 'radius_scale'] as $field) {
            self::assertArrayHasKey($field, $themeDesign['typography_spacing_radius']);
            self::assertNotSame('', \trim((string)$themeDesign['typography_spacing_radius'][$field]), 'theme_design.typography_spacing_radius.' . $field . ' must not be empty.');
        }

        foreach (['visual_keywords', 'forbidden_styles'] as $field) {
            self::assertIsArray($themeDesign[$field]);
            self::assertNotEmpty($themeDesign[$field], 'theme_design.' . $field . ' must contain at least one reusable planning constraint.');
            foreach ($themeDesign[$field] as $index => $value) {
                self::assertNotSame('', \trim((string)$value), 'theme_design.' . $field . '[' . $index . '] must not be empty.');
            }
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

    /**
     * @return array<string, mixed>
     */
    private static function stageOneThemeDesignCore(mixed $themeDesign): array
    {
        self::assertStageOneThemeDesignSchema($themeDesign);

        return [
            'theme_purpose' => $themeDesign['theme_purpose'],
            'color_scheme' => [
                'name' => $themeDesign['color_scheme']['name'],
                'primary' => $themeDesign['color_scheme']['primary'],
                'secondary' => $themeDesign['color_scheme']['secondary'],
                'accent' => $themeDesign['color_scheme']['accent'],
                'background' => $themeDesign['color_scheme']['background'],
                'body' => $themeDesign['color_scheme']['body'],
                'button' => $themeDesign['color_scheme']['button'],
            ],
            'typography_spacing_radius' => [
                'font_family' => $themeDesign['typography_spacing_radius']['font_family'],
                'heading_scale' => $themeDesign['typography_spacing_radius']['heading_scale'],
                'body_scale' => $themeDesign['typography_spacing_radius']['body_scale'],
                'spacing_scale' => $themeDesign['typography_spacing_radius']['spacing_scale'],
                'radius_scale' => $themeDesign['typography_spacing_radius']['radius_scale'],
            ],
            'visual_keywords' => $themeDesign['visual_keywords'],
            'tone_of_voice' => $themeDesign['tone_of_voice'],
            'cta_tone' => $themeDesign['cta_tone'],
            'forbidden_styles' => $themeDesign['forbidden_styles'],
            'selection_reason' => $themeDesign['selection_reason'],
        ];
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

    /**
     * @return array{
     *     0:array<string, mixed>,
     *     1:array<string, mixed>,
     *     2:array<string, mixed>,
     *     3:array<string, mixed>
     * }
     */
    private function buildPlanBookStructuredFixture(): array
    {
        $themeDesign = [
            'theme_purpose' => 'Convert visitors with a trust-first, action-ready site plan.',
            'color_scheme' => [
                'name' => 'Ocean Slate',
                'primary' => '#0f4c81',
                'secondary' => '#f5f7fb',
                'accent' => '#ffb703',
                'background' => '#ffffff',
                'text' => '#0f172a',
                'button' => '#0f4c81',
                'selection_reason' => 'The strong CTA requirement needs a trustworthy high-contrast color system.',
            ],
            'typography_spacing_radius' => [
                'font_family' => 'Inter',
                'heading_scale' => 'Large concise headings.',
                'body_scale' => 'Readable body copy.',
                'spacing_scale' => 'Generous section rhythm.',
                'radius_scale' => 'Rounded cards and CTA buttons.',
            ],
            'visual_keywords' => ['trustworthy', 'conversion-focused'],
            'tone_of_voice' => 'Confident and helpful',
            'cta_tone' => 'Direct',
            'forbidden_styles' => ['generic luxury'],
            'selection_reason' => 'The strong CTA request needs concrete trust signals and direct action language.',
            'context_hash' => 'theme-hash',
        ];
        $sharedPromptContext = [
            'context_hash' => 'shared-hash',
            'theme_context_hash' => 'theme-hash',
            'theme_design' => $themeDesign,
        ];
        $sharedComponents = [
            'header' => [
                'task_key' => 'shared:header',
                'component' => 'header',
                'sort_order' => 10,
                'goal' => 'Make brand navigation and primary CTA immediately usable.',
                'implementation_detail' => 'Desktop horizontal navigation, mobile collapsed menu, persistent CTA.',
                'realtime_content' => [
                    'headline' => 'Structured Plan Book Test',
                    'supporting_copy' => ['Home', 'About', 'Contact'],
                    'cta' => [['label' => 'Book a consult', 'target' => '#contact']],
                ],
                'editable_fields' => ['brand_name', 'navigation_items', 'primary_cta'],
                'completion_rule' => 'Header is complete when brand, nav, CTA, and responsive behavior are defined.',
                'content_source' => ['theme_context_snapshot', 'shared_prompt_context'],
                'responsive_rule' => 'Collapse navigation on mobile while retaining CTA.',
            ],
            'footer' => [
                'task_key' => 'shared:footer',
                'component' => 'footer',
                'sort_order' => 20,
                'goal' => 'Close the site with contact, policy, and support paths.',
                'implementation_detail' => 'Grouped footer columns with contact and policies.',
                'realtime_content' => [
                    'headline' => 'Continue with Structured Plan Book Test',
                    'supporting_copy' => ['Contact', 'Privacy', 'Support'],
                ],
                'editable_fields' => ['footer_links', 'policy_links', 'contact_fields'],
                'completion_rule' => 'Footer is complete when grouped links, policies, and contact fields are defined.',
                'content_source' => ['theme_context_snapshot', 'shared_prompt_context'],
                'responsive_rule' => 'Stack columns on mobile.',
            ],
        ];
        $pages = [
            'home_page' => [
                'page_key' => 'home_page',
                'page_label' => 'Home',
                'page_goal' => 'Explain the offer and move visitors to contact.',
                'page_status' => 'done',
                'shared_context_hash' => 'shared-hash',
                'theme_context_hash' => 'theme-hash',
                'page_context_hash' => 'home-hash',
                'theme_alignment_summary' => 'Home blocks reuse the Ocean Slate palette, direct CTA tone, and header/footer handoff.',
                'blocks' => [
                    [
                        'block_key' => 'hero',
                        'section_code' => 'Hero',
                        'order' => 10,
                        'goal' => 'State the value proposition and primary CTA.',
                        'implementation_detail' => 'Use a two-column hero with proof points and CTA.',
                        'realtime_content' => [
                            'headline' => 'Launch your site with confidence',
                            'supporting_copy' => ['Clear plan', 'Fast review', 'Ready CTA'],
                            'cta' => [['label' => 'Start planning', 'target' => '#contact']],
                        ],
                        'why' => 'The hero turns the strong CTA requirement into visible content and action.',
                        'completion_rule' => 'Hero is complete when headline, proof copy, CTA, media, and responsive behavior are defined.',
                        'editable_fields' => ['headline', 'supporting_copy', 'primary_cta'],
                        'content_source' => ['safe_inference', 'editable_field'],
                        'responsive_rule' => 'Stack copy and media on small screens.',
                    ],
                    [
                        'block_key' => 'proof',
                        'section_code' => 'Proof',
                        'order' => 20,
                        'goal' => 'Show trust signals before the CTA repeats.',
                        'implementation_detail' => 'Use cards for outcomes, testimonials, and process steps.',
                        'realtime_content' => [
                            'headline' => 'Proof visitors can verify',
                            'supporting_copy' => ['Outcome cards', 'Process steps'],
                        ],
                        'why' => 'Proof content reduces hesitation before conversion.',
                        'completion_rule' => 'Proof is complete when trust cards, labels, and supporting copy are defined.',
                        'field_plan' => [
                            ['field' => 'headline', 'sample' => 'Proof visitors can verify'],
                            ['field' => 'card_title', 'sample' => 'Fast planning review'],
                        ],
                        'content_source' => ['safe_inference', 'editable_field'],
                        'responsive_rule' => 'Cards become a single column on mobile.',
                    ],
                ],
            ],
        ];
        $structured = [
            'i18n' => ['locale' => 'en_US'],
            'theme_context_snapshot' => $themeDesign,
            'shared_components' => $sharedComponents,
            'shared_plan' => [
                'theme_design' => $themeDesign,
                'shared_prompt_context' => $sharedPromptContext,
            ],
            'page_types' => ['home_page'],
            'pages' => $pages,
            'page_plans' => $pages,
            'block_index' => ['flat' => []],
            'execution_steps' => [],
        ];
        $executionBlueprint = [
            'signature' => 'fixture-stage1-signature',
            'theme_context_snapshot' => $themeDesign,
            'shared_prompt_context' => $sharedPromptContext,
            'shared_components' => $sharedComponents,
            'page_types' => ['home_page'],
            'pages' => $pages,
            'page_plans' => $pages,
            'tasks' => [],
        ];
        $scope = [
            'plan_locale' => 'en_US',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ];
        $planJson = [
            'page_types' => ['home_page'],
            'pages' => [
                'home_page' => [
                    'page_goal' => 'Explain the offer and move visitors to contact.',
                    'blocks' => [
                        ['block_key' => 'hero'],
                        ['block_key' => 'proof'],
                    ],
                ],
            ],
        ];

        return [$scope, $structured, $executionBlueprint, $planJson];
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

    private function buildAiPlanResponseWithPromptLikeAboutThemeAlignmentSummary(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['pages']['about_page']['theme_alignment_summary'] = 'string explaining how this page obeys theme_design/shared_prompt_context';

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildStagedPageAiResponse(string $pageType): string
    {
        $page = $pageType === Page::TYPE_ABOUT
            ? [
                'page_goal' => 'Build brand trust with story, mission, values, and community proof.',
                'theme_alignment_summary' => 'About page uses the shared trust palette and calmer narrative rhythm while avoiding homepage conversion-only structure.',
                'primary_keywords' => ['about brand', 'mission', 'community trust'],
                'secondary_keywords' => ['values', 'story', 'why trust us'],
                'blocks' => [
                    $this->buildStageOnePageBlockFixture('brand_story', 'Tell the origin story and show why the brand exists.', ['timeline layout', 'editorial portrait', 'soft shadow'], ['timeline reveal', 'slow fade'], ['story anchor links']),
                    $this->buildStageOnePageBlockFixture('mission_values', 'Turn mission and values into proof points.', ['value cards', 'icon grid', 'calm spacing'], ['staggered card reveal'], ['card hover detail']),
                    $this->buildStageOnePageBlockFixture('community_cta', 'Invite visitors to continue into community or support.', ['community strip', 'rounded avatar group'], ['gentle slide up'], ['secondary CTA hover']),
                ],
            ]
            : [
                'page_goal' => 'Convert visitors quickly with hero, game highlights, and a final action.',
                'theme_alignment_summary' => 'Home page uses the shared palette, energetic CTA rhythm, and trust tone for a conversion-first entry.',
                'primary_keywords' => ['home', 'games', 'download'],
                'secondary_keywords' => ['Teen Patti', 'Rummy', 'Carrom'],
                'blocks' => [
                    $this->buildStageOnePageBlockFixture('hero', 'Show the main promise and immediate CTA.', ['premium banner', 'rounded image', 'card shadow'], ['5s fade in/out', 'subtle parallax'], ['primary CTA hover']),
                    $this->buildStageOnePageBlockFixture('featured_games', 'Show game choices as fast scanning cards.', ['game cards', 'icon badges', 'shadowed tiles'], ['hover lift'], ['game card tap state']),
                    $this->buildStageOnePageBlockFixture('final_cta', 'Close with a direct registration CTA.', ['full-width CTA band', 'soft gradient'], ['pulse accent'], ['sticky CTA on mobile']),
                ],
            ];

        return \json_encode(['page' => $page], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param list<string> $visual
     * @param list<string> $motion
     * @param list<string> $interaction
     * @return array<string, mixed>
     */
    private function buildStageOnePageBlockFixture(string $blockKey, string $goal, array $visual, array $motion, array $interaction): array
    {
        return [
            'block_key' => $blockKey,
            'goal' => $goal,
            'keywords' => [$blockKey],
            'content' => $goal . ' Use concrete copy and visible CTA language for Royal Indian Games.',
            'design_tags' => [
                'visual' => $visual,
                'motion' => $motion,
                'interaction' => $interaction,
                'texture' => ['soft gradient', 'theme accent surface'],
                'responsive' => ['desktop two-column', 'mobile stacked cards'],
                'implementation_note' => 'Carry these tags into stage two and stage three implementation.',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => $blockKey . ' title', 'implementation_note' => 'Place this exact title in the block heading using the shared typography scale.'],
                ['field' => 'description', 'sample' => $goal, 'implementation_note' => 'Place this copy below the title as the visible body message for the block.'],
                ['field' => 'button_text', 'sample' => 'Start now', 'implementation_note' => 'Use this as the primary CTA label and connect it to the block action path.'],
            ],
            'execution_script' => [
                'feature_points' => ['Visible title', 'Concrete supporting copy', 'CTA path'],
                'core_copy' => $goal . ' Start now.',
                'typography' => 'Use shared heading and body scale.',
                'style_tone' => 'Use shared theme tone.',
                'background_direction' => 'Use themed visual treatment.',
                'media_assets' => ['brand visual'],
            ],
            'reusable' => 'no',
            'seo_impact' => 'medium',
        ];
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

    private function buildPageOnlyRefineAiPlanResponse(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['pages']['home_page']['page_goal'] = 'Drive launch-offer signups from the home page.';
        $decoded['plan_json']['pages']['home_page']['blocks'] = [
            [
                'block_key' => 'launch_offer',
                'goal' => 'Launch offer headline and CTA',
                'keywords' => ['launch offer'],
                'content' => 'Launch offer headline with a deadline, proof line, and primary CTA for visitors ready to start.',
                'field_plan' => [
                    ['field' => 'title', 'sample' => 'Launch offer headline', 'implementation_note' => 'Use this as the visible launch-offer headline.', 'reason' => 'Anchors the page-level refine to the current page.'],
                    ['field' => 'cta_label', 'sample' => 'Claim the launch offer', 'implementation_note' => 'Use this as the primary CTA label.', 'reason' => 'Makes the refined page actionable.'],
                ],
                'execution_script' => [
                    'feature_points' => ['Launch deadline', 'Primary CTA'],
                    'core_copy' => 'Launch offer headline and CTA copy',
                    'typography' => 'Bold hero heading',
                    'style_tone' => 'Urgent but trustworthy',
                    'background_direction' => 'Clean launch highlight',
                    'media_assets' => ['launch-offer.jpg'],
                ],
                'reusable' => 'no',
                'seo_impact' => 'high',
            ],
        ];
        $decoded['plan_json']['pages']['about_page']['blocks'][0]['content'] = 'SHOULD_NOT_LEAK_TO_ABOUT_PAGE';

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
