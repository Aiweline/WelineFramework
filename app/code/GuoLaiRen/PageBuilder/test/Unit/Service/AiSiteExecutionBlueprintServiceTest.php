<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Skill\BuiltinSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSelectionResolver;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSnapshotBuilder;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteReferenceImageInsightService;
use GuoLaiRen\PageBuilder\Service\AiSiteStageOneContractService;
use GuoLaiRen\PageBuilder\Service\AiSiteStageOneContractValidator;
use GuoLaiRen\PageBuilder\Service\AiSiteStageOnePromptContractRenderer;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

final class AiSiteExecutionBlueprintServiceTest extends TestCase
{
    public function testStageOneContentLocalePrefersWebsiteLocaleOverPlanLocale(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'resolveStageOneContentLocale');
        $method->setAccessible(true);

        self::assertSame(
            'pt_BR',
            (string)$method->invoke($service, [
                'plan_locale' => 'zh_Hans_CN',
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
                'website_profile' => [
                    'default_locale' => 'pt_BR',
                    'content_locale' => 'zh_Hans_CN',
                ],
            ], 'zh_Hans_CN')
        );

        self::assertSame(
            'en_US',
            (string)$method->invoke($service, [
                'ai_content_locale' => 'en_US',
                'content_locale' => 'zh_Hans_CN',
                'default_locale' => 'pt_BR',
                'website_profile' => [
                    'default_locale' => 'pt_BR',
                ],
            ], 'zh_Hans_CN')
        );
    }

    public function testStageOneJsonRetryParamsPreserveStrictBlockSegmentSchema(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $formatMethod = new \ReflectionMethod($service, 'buildStageOneBlockSegmentResponseFormat');
        $formatMethod->setAccessible(true);
        $retryMethod = new \ReflectionMethod($service, 'buildStageOneJsonRetryRequestParams');
        $retryMethod->setAccessible(true);

        $strictFormat = $formatMethod->invokeArgs($service, ['support_form_guidance', [], false]);
        $params = $retryMethod->invoke($service, [
            'max_tokens' => 512,
            'response_format' => $strictFormat,
        ]);

        self::assertSame('json_schema', $params['response_format']['type'] ?? null);
        self::assertSame(
            ['support_form_guidance'],
            $params['response_format']['json_schema']['schema']['properties']['blocks']['items']['properties']['block_key']['enum'] ?? null
        );
        self::assertSame(
            ['support'],
            $params['response_format']['json_schema']['schema']['properties']['blocks']['items']['properties']['page_flow_role']['enum'] ?? null
        );
        self::assertContains(
            'page_flow_role',
            $params['response_format']['json_schema']['schema']['properties']['blocks']['items']['required'] ?? []
        );
    }

    public function testStageOneThemeResponseFormatLocksPageOverviewAndSharedComponents(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildStageOneThemeResponseFormat');
        $method->setAccessible(true);

        $format = $method->invoke($service, [Page::TYPE_HOME, Page::TYPE_CONTACT]);
        $schema = $format['json_schema']['schema'] ?? [];

        self::assertSame('json_schema', $format['type'] ?? null);
        self::assertTrue((bool)($format['json_schema']['strict'] ?? false));
        self::assertContains('page_type_overviews', $schema['required'] ?? []);
        self::assertSame(
            [Page::TYPE_HOME, Page::TYPE_CONTACT],
            $schema['properties']['page_type_overviews']['required'] ?? null
        );
        self::assertSame(
            ['label', 'target'],
            $schema['properties']['shared_components']['properties']['header']['properties']['realtime_content']['properties']['cta']['items']['required'] ?? null
        );
    }

    public function testStageOneJsonSchemaResponseFormatRequiresResolvedModelCapability(): void
    {
        $aiService = $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveModel'])
            ->getMock();
        $aiService->method('resolveModel')
            ->with(null, 'pagebuilder_plan_generation')
            ->willReturn([
                'model_code' => 'deepseek-v4-flash',
                'capabilities' => ['chat', 'reasoning', 'code', 'function_calling'],
            ]);

        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);
        $formatMethod = new \ReflectionMethod($service, 'buildStageOneBlockSegmentResponseFormat');
        $formatMethod->setAccessible(true);
        $normalizeMethod = new \ReflectionMethod($service, 'normalizeStageOneJsonResponseFormatForScenario');
        $normalizeMethod->setAccessible(true);

        $params = $normalizeMethod->invoke($service, [
            'response_format' => $formatMethod->invokeArgs($service, ['support_form_guidance', [], false]),
        ], 'pagebuilder_plan_generation');

        self::assertSame(['type' => 'json_object'], $params['response_format'] ?? null);
    }

    public function testStageOnePromptContractsAvoidCopyablePlaceholderVocabulary(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $preludeMethod = new \ReflectionMethod($service, 'buildStageOnePromptRolePrelude');
        $preludeMethod->setAccessible(true);

        $contract = (new AiSiteStageOneContractService())->build(
            ['page_types' => [Page::TYPE_CONTACT]],
            [Page::TYPE_CONTACT],
            'zh_Hans_CN',
            'pt_BR'
        );
        $pageLines = (new AiSiteStageOnePromptContractRenderer())->renderPageContract($contract, Page::TYPE_CONTACT);
        $promptText = \mb_strtolower(\implode("\n", [
            ...$preludeMethod->invoke($service),
            ...$pageLines,
        ]));

        self::assertStringNotContainsString('placeholder', $promptText);
        self::assertStringNotContainsString('占位', $promptText);
        self::assertStringContainsString('html input hint attribute', $promptText);
    }

    public function testStageOnePagePromptProvidesExactBlockKeyFramework(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildAiStageOnePagePrompt');
        $method->setAccessible(true);
        $scope = [
            'page_types' => [Page::TYPE_COOKIE_POLICY],
            'brief_description' => 'Teenipiya APK site needs clear policy support content.',
        ];
        $contract = (new AiSiteStageOneContractService())->build(
            $scope,
            [Page::TYPE_COOKIE_POLICY],
            'zh_Hans_CN',
            'pt_BR'
        );

        $prompt = (string)$method->invoke(
            $service,
            \array_replace($scope, ['stage1_contract' => $contract]),
            ['brief_description' => $scope['brief_description']],
            [
                'stage1_contract' => $contract,
                'requirement_expansion' => [],
                'theme_design' => [],
                'shared_components' => [],
            ],
            Page::TYPE_COOKIE_POLICY,
            'zh_Hans_CN',
            'pt_BR',
            '',
            ''
        );

        self::assertStringContainsString('Exact block-key framework', $prompt);
        self::assertStringContainsString(
            '["cookie_overview","cookie_types","preference_controls","cookie_contact"]',
            $prompt
        );
        self::assertStringContainsString('final CSS treatment itself', $prompt);
        self::assertStringNotContainsString('CSS replaces the image', $prompt);
    }

    public function testStageOneBlockRepairNormalizesExecutionScriptTypo(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'repairAiStageOneBlocksBeforeValidation');
        $method->setAccessible(true);

        $blocks = $method->invoke($service, [[
            'block_key' => 'hero',
            'content' => 'Baixe o APK da Teenipiya com segurança.',
            'field_plan' => [],
            'execution_script' => [],
            'ution_script' => [
                'core_copy' => 'Baixe o APK e entre em mesas confiáveis de Teen Patti.',
                'feature_points' => ['Download rápido'],
            ],
        ]], Page::TYPE_HOME, 'pt_BR', [], []);

        self::assertIsArray($blocks);
        self::assertSame(
            'Baixe o APK e entre em mesas confiáveis de Teen Patti.',
            (string)($blocks[0]['execution_script']['core_copy'] ?? '')
        );
        self::assertArrayNotHasKey('ution_script', $blocks[0]);
    }

    public function testStageOneRecoveryRulesHandleIconOnlyImageSubject(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildStageOneIssueSpecificRecoveryRules');
        $method->setAccessible(true);

        $rules = $method->invoke($service, [[
            'reason_code' => 'icon_only_image_subject',
            'page_type' => 'about_page',
            'block_key' => 'origin_story',
            'field_path' => 'blocks.0.image_intent.image_subject',
        ]]);

        self::assertIsArray($rules);
        $text = \implode("\n", \array_map('strval', $rules));
        self::assertStringContainsString('icon_only_image_subject', $text);
        self::assertStringContainsString('real scene, product interface, editorial environment, or human-in-context visual', $text);
        self::assertStringContainsString('about_page/origin_story', $text);
    }

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
        self::assertArrayHasKey('implementation_note', $firstBlock);
        self::assertArrayNotHasKey('why', $firstBlock);
        self::assertArrayNotHasKey('reason', $firstBlock);
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
        $contractContext = $artifacts['plan_workbench']['contract_context'] ?? [];
        self::assertSame('stage1', (string)($contractContext['stage'] ?? ''));
        self::assertSame(['claude-design'], $contractContext['selected_skill_codes'] ?? []);
        self::assertSame('claude-design', (string)($contractContext['skill_snapshots'][0]['code'] ?? ''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string)($contractContext['skill_snapshot_hash'] ?? ''));
        $contracts = $artifacts['plan_workbench']['contracts'] ?? [];
        self::assertArrayHasKey(ContractType::TYPE_SITE_BRIEF, $contracts);
        self::assertArrayHasKey(ContractType::TYPE_DESIGN_MANIFEST, $contracts);
        self::assertArrayHasKey(ContractType::TYPE_PAGE_CONTRACT, $contracts);
        self::assertArrayHasKey(ContractType::TYPE_BLOCK_PLAN, $contracts);
        self::assertSame(
            ContractType::STATUS_DRAFT,
            (string)($contracts[ContractType::TYPE_SITE_BRIEF]['contract_meta']['status'] ?? '')
        );
        self::assertSame(
            (string)($contracts[ContractType::TYPE_SITE_BRIEF]['contract_meta']['id'] ?? ''),
            (string)($contracts[ContractType::TYPE_SITE_BRIEF]['contract_meta']['contract_id'] ?? '')
        );
        self::assertSame('Plan Service Test', (string)($contracts[ContractType::TYPE_SITE_BRIEF]['payload']['site_title'] ?? ''));
        self::assertSame(['home_page', 'about_page'], $contracts[ContractType::TYPE_PAGE_CONTRACT]['payload']['page_types'] ?? []);
        self::assertSame(
            (string)($contracts[ContractType::TYPE_PAGE_CONTRACT]['contract_meta']['id'] ?? ''),
            (string)($contracts[ContractType::TYPE_BLOCK_PLAN]['source_contracts'][2]['id'] ?? '')
        );
        self::assertSame('pass', (string)($contracts[ContractType::TYPE_BLOCK_PLAN]['qa_gates']['schema_shape']['status'] ?? ''));
        self::assertStringStartsWith(
            'page:home_page:',
            (string)($contracts[ContractType::TYPE_BLOCK_PLAN]['payload']['pages']['home_page']['blocks'][0]['task_key'] ?? '')
        );
        self::assertSame($contracts, $artifacts['plan_workbench']['confirmed']['contracts'] ?? []);
        $sharedTask = $artifacts['execution_blueprint']['tasks'][0] ?? [];
        foreach (['implementation_detail', 'realtime_content', 'completion_rule', 'editable_fields'] as $requiredBlockField) {
            self::assertArrayHasKey($requiredBlockField, $sharedTask);
        }
        self::assertArrayNotHasKey('reason', $sharedTask);
        $pageTask = $artifacts['execution_blueprint']['tasks'][2] ?? [];
        foreach (['implementation_detail', 'realtime_content', 'completion_rule', 'editable_fields'] as $requiredBlockField) {
            self::assertArrayHasKey($requiredBlockField, $pageTask['block'] ?? []);
        }
        self::assertArrayNotHasKey('reason', $pageTask['block'] ?? []);
        self::assertSame(
            (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
            (string)($pageTask['source_ref']['shared_context_hash'] ?? '')
        );
    }

    public function testStageOneThemePromptRequiresPolishedCustomerFitVisualIdentity(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $method = new \ReflectionMethod($service, 'buildAiStageOneThemePrompt');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs($service, [
            [
                'site_title' => 'AI Plugin Lab',
                'brief_description' => 'Build an AI plugin download website for developers.',
                'page_types' => ['home_page', 'about_page'],
            ],
            [
                'site_title' => 'AI Plugin Lab',
                'brief_description' => 'Build an AI plugin download website for developers.',
            ],
            ['home_page', 'about_page'],
            'en_US',
            'en_US',
            '',
            'AI Plugin Lab',
            'A focused AI plugin download website.',
            [
                'expanded_brief' => 'Developers need a memorable AI plugin download site with trust and direct CTA.',
            ],
        ]);

        self::assertIsString($prompt);
        self::assertStringContainsString('theme_design.style_signature', $prompt);
        self::assertStringContainsString('art_direction', $prompt);
        self::assertStringContainsString('Visual quality bar', $prompt);
        self::assertStringContainsString('Customer-fit rule', $prompt);
        self::assertStringContainsString('Beauty rule', $prompt);
        self::assertStringContainsString('Customer-anchor rule', $prompt);
        self::assertStringContainsString('Interaction/effects rule', $prompt);
        self::assertStringContainsString('Style-diversity rule', $prompt);
        self::assertStringContainsString('generic Inter/Roboto/system-font hierarchy', $prompt);
    }

    public function testAssertAiStageOnePlanJsonRejectsDuplicateBlockKeysOnSamePage(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Teenipiya',
            'brief_description' => 'Explain refund rules clearly for visitors before they download the app.',
            'page_types' => [Page::TYPE_REFUND_POLICY],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => 'Explain refund rules clearly for visitors before they download the app.',
        ]);

        $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        $refundBlocks = \is_array($planJson['pages'][Page::TYPE_REFUND_POLICY]['blocks'] ?? null)
            ? $planJson['pages'][Page::TYPE_REFUND_POLICY]['blocks']
            : [];
        self::assertSame(
            ['hero', 'coverage', 'rights', 'cta'],
            \array_values(\array_map(
                static fn(array $block): string => (string)($block['block_key'] ?? ''),
                $refundBlocks
            ))
        );

        $planJson['theme_design']['selection_reason'] = 'This theme fits the refund rules brief because it keeps policy content readable while preserving a clear download CTA.';
        $planJson['pages'][Page::TYPE_REFUND_POLICY]['blocks'][2]['block_key'] = 'coverage';

        $method = new \ReflectionMethod($service, 'assertAiStageOnePlanJsonIsStrict');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate block_key "coverage"');
        $method->invokeArgs($service, [
            $planJson,
            [Page::TYPE_REFUND_POLICY],
            'Explain refund rules clearly for visitors before they download the app.',
            'en_US',
        ]);
    }

    public function testStageOneAiPlanPromptIncludesSelectedCustomSkillBody(): void
    {
        $customProvider = new CustomSkillProvider(null, [
            'conversion-copy' => [
                'code' => 'conversion-copy',
                'name' => 'Conversion Copy',
                'description' => 'Selected skill for Stage1 prompt verification.',
                'body' => 'Stage1 must use concrete conversion copy from this selected skill.',
                'status' => 'active',
                'source' => 'custom_db',
                'exists' => true,
            ],
        ]);
        $resolver = new SkillSelectionResolver(new BuiltinSkillProvider(), $customProvider);
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            null,
            null,
            new AiSiteSkillRegistry(
                null,
                null,
                $customProvider,
                $resolver,
                new SkillSnapshotBuilder($resolver)
            )
        );
        $method = new \ReflectionMethod($service, 'buildAiPlanPrompt');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs($service, [
            [
                'site_title' => 'Skill Prompt Site',
                'brief_description' => 'Build a conversion-focused analytics landing page.',
                'selected_skill_codes' => ['conversion-copy'],
                'workspace_track' => 'virtual_theme',
            ],
            [
                'site_title' => 'Skill Prompt Site',
                'brief_description' => 'Build a conversion-focused analytics landing page.',
            ],
            ['home_page'],
            'en_US',
            '',
            '',
            'Skill Prompt Site',
            'Analytics landing page.',
        ]);

        self::assertIsString($prompt);
        self::assertStringContainsString('Skill code: conversion-copy', $prompt);
        self::assertStringContainsString('Stage1 must use concrete conversion copy from this selected skill.', $prompt);
    }

    public function testAiPluginDownloadBriefUsesDistinctiveFallbackVisualSystem(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());

        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'AI Plugin Lab',
            'brief_description' => 'Build an AI plugin download website for developers.',
            'page_types' => ['home_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'AI Plugin Lab',
            'brief_description' => 'Build an AI plugin download website for developers.',
        ]);

        self::assertSame('Electric Circuit', (string)($artifacts['plan_json']['palette']['name'] ?? ''));
        self::assertSame('Neon Utility Lab', (string)($artifacts['plan_json']['theme_style']['name'] ?? ''));
        self::assertStringContainsString(
            'luminous circuit accents',
            (string)($artifacts['plan_json']['theme_design']['style_signature'] ?? '')
        );
        self::assertIsArray($artifacts['plan_json']['theme_design']['art_direction'] ?? null);
    }

    public function testBuildPlanArtifactsUsesDefaultLocaleForWebsiteContentWhenPlanLocaleDiffers(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());

        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Teenipiya',
            'brief_description' => 'Indian card game APK download SEO site for Teen Patti and Rummy players.',
            'page_types' => [Page::TYPE_HOME],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Teenipiya',
            'brief_description' => 'Indian card game APK download SEO site for Teen Patti and Rummy players.',
        ]);

        $planBook = $artifacts['plan_workbench']['confirmed']['plan_book']['structured'] ?? [];
        $homeBlocks = \is_array($planBook['pages']['home_page']['blocks'] ?? null) ? $planBook['pages']['home_page']['blocks'] : [];
        $heroBlock = $homeBlocks[0] ?? [];
        $displayVisibleBlocks = \array_map(
            static fn(array $block): array => [
                'block_scope' => (string)($block['block_scope'] ?? ''),
                'component' => (string)($block['component'] ?? ''),
                'page_key' => (string)($block['page_key'] ?? ''),
                'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
                'field_plan' => \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                'editable_fields' => \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [],
            ],
            \array_values(\array_filter(
                \is_array($planBook['pages']['home_page']['display_blocks'] ?? null) ? $planBook['pages']['home_page']['display_blocks'] : [],
                static fn($block): bool => \is_array($block)
            ))
        );
        $sharedVisibleBlocks = \array_map(
            static fn(array $block): array => [
                'component' => (string)($block['component'] ?? ''),
                'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
                'editable_fields' => \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [],
            ],
            \array_values(\array_filter(
                \is_array($planBook['shared_blocks'] ?? null) ? $planBook['shared_blocks'] : [],
                static fn($block): bool => \is_array($block)
            ))
        );
        $visiblePayload = \json_encode([
            'page_label' => $planBook['pages']['home_page']['page_label'] ?? '',
            'content_locale' => $planBook['pages']['home_page']['content_locale'] ?? '',
            'realtime_content' => $heroBlock['realtime_content'] ?? [],
            'field_plan' => $heroBlock['field_plan'] ?? [],
            'display_blocks' => $displayVisibleBlocks,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';
        $sharedVisiblePayload = \json_encode($sharedVisibleBlocks, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';

        self::assertSame('en_US', (string)($artifacts['plan_json']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($artifacts['structured']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($artifacts['execution_blueprint']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($artifacts['execution_blueprint']['theme_context_snapshot']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($artifacts['execution_blueprint']['shared_prompt_context']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($planBook['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($planBook['theme_context_snapshot']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($planBook['shared_prompt_context']['content_locale'] ?? ''));
        self::assertSame('en_US', (string)($planBook['pages']['home_page']['content_locale'] ?? ''));
        self::assertSame('Home', (string)($planBook['pages']['home_page']['page_label'] ?? ''));
        self::assertStringContainsString('Download Now', $visiblePayload);
        self::assertStringContainsString('Download Now', $sharedVisiblePayload);
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $visiblePayload);
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $sharedVisiblePayload);
        self::assertStringNotContainsString('content/home-page', $visiblePayload);
    }

    public function testStageOneHeaderFooterCompletionFansOutPageTasksWithoutTabDependency(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());

        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Fanout Service Test',
            'brief_description' => 'Need home and about pages generated after shared header and footer planning.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
        ], [
            'site_title' => 'Fanout Service Test',
            'brief_description' => 'Need home and about pages generated after shared header and footer planning.',
        ]);

        $stageOneQueue = $artifacts['execution_blueprint']['stage1_queue'] ?? [];
        self::assertIsArray($stageOneQueue);
        self::assertSame('stage1.shared.header_footer', (string)($stageOneQueue['fanout']['trigger_after'] ?? ''));
        self::assertSame('fiber_coroutine', (string)($stageOneQueue['fanout']['mode'] ?? ''));
        self::assertSame('one_page_one_task', (string)($stageOneQueue['fanout']['task_granularity'] ?? ''));
        self::assertSame(2, (int)($stageOneQueue['fanout']['page_job_count'] ?? 0));
        self::assertSame(
            ['stage1.page_plan:home_page', 'stage1.page_plan:about_page'],
            $stageOneQueue['fanout']['page_job_keys'] ?? []
        );

        $queueSequence = $stageOneQueue['sequence'] ?? [];
        self::assertIsArray($queueSequence);
        self::assertContains('stage1.shared.header_footer', $queueSequence);
        self::assertContains('stage1.page_plan:home_page', $queueSequence);
        self::assertContains('stage1.page_plan:about_page', $queueSequence);
        self::assertLessThan(
            \array_search('stage1.page_plan:home_page', $queueSequence, true),
            \array_search('stage1.shared.header_footer', $queueSequence, true)
        );
        self::assertLessThan(
            \array_search('stage1.page_plan:about_page', $queueSequence, true),
            \array_search('stage1.shared.header_footer', $queueSequence, true)
        );

        foreach (['home_page', 'about_page'] as $pageType) {
            $pageJobKey = 'stage1.page_plan:' . $pageType;
            $pageJob = $stageOneQueue['jobs'][$pageJobKey] ?? null;
            self::assertIsArray($pageJob);
            self::assertSame(['stage1.shared.header_footer'], $pageJob['depends_on'] ?? []);
            self::assertSame('stage1.shared.header_footer.done', (string)($pageJob['dispatch_trigger'] ?? ''));
            self::assertSame('automatic_after_dependency', (string)($pageJob['dispatch_mode'] ?? ''));
            self::assertFalse((bool)($pageJob['requires_user_tab'] ?? true));
            self::assertSame('one_page_one_task', (string)($pageJob['concurrency']['task_granularity'] ?? ''));
            self::assertSame(
                (string)($artifacts['execution_blueprint']['shared_prompt_context']['context_hash'] ?? ''),
                (string)($pageJob['inputs']['shared_context_hash'] ?? '')
            );
        }
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
        self::assertArrayNotHasKey('reason', $firstPageBlock);
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

    public function testBuildPlanArtifactsByAiStreamRejectsFakeMode(): void
    {
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deterministic');

        $service->buildPlanArtifactsByAiStream([
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
        self::assertArrayNotHasKey(
            'page_types',
            \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [],
            'Generated plan artifacts must not overwrite the user-selected page type scope.'
        );
        self::assertIsArray($artifacts['derived_scope_patch']['source_truth_contract'] ?? null);
        self::assertNotSame('', (string)($artifacts['derived_scope_patch']['source_truth_contract_hash'] ?? ''));
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

    public function testPlanBlockMutationSupportsSharedBlocks(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $artifacts = $service->buildPlanArtifacts([
            'site_title' => 'Shared Mutation Test',
            'brief_description' => 'Need shared chrome blocks that can be edited from the stage-one workbench.',
            'page_types' => [Page::TYPE_HOME, Page::TYPE_ABOUT],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
        ], [
            'site_title' => 'Shared Mutation Test',
            'brief_description' => 'Need shared chrome blocks that can be edited from the stage-one workbench.',
        ]);
        $scopeFrom = static fn(array $payload): array => [
            'plan_locale' => 'en_US',
            'plan_json' => $payload['plan_json'],
            'plan_markdown' => $payload['markdown'],
            'plan_structured' => $payload['structured'],
            'plan_workbench' => $payload['plan_workbench'],
            'execution_blueprint_draft' => $payload['execution_blueprint'],
        ];

        $rebuilt = $service->mutateDraftPlanBlock($scopeFrom($artifacts), 'shared', 'rebuild', 'shared:header', [
            'goal' => 'Updated shared header goal.',
            'field_plan' => [
                ['field' => 'nav_label', 'sample' => 'Updated navigation', 'reason' => 'Header copy changed'],
            ],
        ]);

        self::assertSame('shared', (string)($rebuilt['mutation_summary']['page_type'] ?? ''));
        self::assertSame('shared:header', (string)($rebuilt['mutation_summary']['block_key'] ?? ''));
        self::assertSame('Updated shared header goal.', (string)($rebuilt['structured']['shared_components']['header']['goal'] ?? ''));
        self::assertSame('Updated shared header goal.', (string)($rebuilt['block']['goal'] ?? ''));
        self::assertArrayHasKey(
            'shared:header',
            \is_array($rebuilt['structured']['block_index']['flat'] ?? null) ? $rebuilt['structured']['block_index']['flat'] : []
        );
        $sharedContextHash = (string)($rebuilt['execution_blueprint']['shared_prompt_context']['context_hash'] ?? '');
        self::assertNotSame('', $sharedContextHash);
        self::assertSame(
            $sharedContextHash,
            (string)($rebuilt['structured']['shared_plan']['shared_prompt_context']['context_hash'] ?? '')
        );

        $created = $service->mutateDraftPlanBlock($scopeFrom($rebuilt), 'shared', 'create', '', [
            'title' => 'Announcement Bar',
            'goal' => 'Show a site-wide launch notice above the page content.',
            'after_block_key' => 'shared:header',
        ]);

        self::assertSame('shared:announcement_bar', (string)($created['mutation_summary']['block_key'] ?? ''));
        self::assertArrayHasKey('announcement_bar', $created['structured']['shared_components'] ?? []);
        self::assertArrayHasKey(
            'shared:announcement_bar',
            \is_array($created['structured']['block_index']['flat'] ?? null) ? $created['structured']['block_index']['flat'] : []
        );
        $sharedBlockKeys = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            \is_array($created['plan_json']['shared_blocks'] ?? null) ? $created['plan_json']['shared_blocks'] : []
        ));
        self::assertContains('shared:announcement_bar', $sharedBlockKeys);
        $sharedTaskKeys = \array_values(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            \array_values(\array_filter(
                \is_array($created['execution_blueprint']['tasks'] ?? null) ? $created['execution_blueprint']['tasks'] : [],
                static fn(array $task): bool => (string)($task['task_type'] ?? '') === 'shared_component'
            ))
        ));
        self::assertContains('shared:announcement_bar', $sharedTaskKeys);
        $homeDisplayBlockKeys = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            \is_array($created['plan_json']['pages'][Page::TYPE_HOME]['display_blocks'] ?? null)
                ? $created['plan_json']['pages'][Page::TYPE_HOME]['display_blocks']
                : []
        ));
        self::assertContains('shared:announcement_bar', $homeDisplayBlockKeys);

        $deleted = $service->mutateDraftPlanBlock($scopeFrom($created), 'shared', 'delete', 'shared:announcement_bar');
        self::assertArrayNotHasKey('announcement_bar', $deleted['structured']['shared_components'] ?? []);
        self::assertArrayNotHasKey(
            'shared:announcement_bar',
            \is_array($deleted['structured']['block_index']['flat'] ?? null) ? $deleted['structured']['block_index']['flat'] : []
        );
        $deletedSharedBlockKeys = \array_values(\array_map(
            static fn(array $block): string => (string)($block['block_key'] ?? ''),
            \is_array($deleted['plan_json']['shared_blocks'] ?? null) ? $deleted['plan_json']['shared_blocks'] : []
        ));
        self::assertNotContains('shared:announcement_bar', $deletedSharedBlockKeys);
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

    public function testBuildAiPlanPromptsContainStageOneThemeFirstConstraints(): void
    {
        $capturedPrompts = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback) use (&$capturedPrompts): void {
                $capturedPrompts[] = $prompt;
                $callback(match (\count($capturedPrompts)) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
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

        self::assertCount(4, $capturedPrompts);
        self::assertStringContainsString('Stage-1 REQUIREMENT EXPANSION planner', $capturedPrompts[0]);
        self::assertStringContainsString('expand the user one-line requirement', $capturedPrompts[0]);
        self::assertStringContainsString('Do not generate theme, Header/Footer, or page blocks.', $capturedPrompts[0]);
        self::assertStringContainsString('single-stage THEME planner', $capturedPrompts[1]);
        self::assertStringContainsString('Confirmed requirement expansion from step 1', $capturedPrompts[1]);
        self::assertStringContainsString('shared Header/Footer', $capturedPrompts[1]);
        self::assertStringContainsString('Plan locale: zh_Hans_CN', $capturedPrompts[1]);
        self::assertStringContainsString('Website content locale: en_US', $capturedPrompts[1]);
        self::assertStringContainsString('Header/Footer labels, CTA labels, link labels, media text, and other customer-visible website copy MUST use Website content locale', $capturedPrompts[1]);
        self::assertStringContainsString('theme_design and shared_components.header/footer must be concrete implementation decisions', $capturedPrompts[1]);
        self::assertStringContainsString('page_type_overviews', $capturedPrompts[1]);
        self::assertStringContainsString('Anti-monotony rule', $capturedPrompts[1]);
        self::assertStringContainsString('single-stage PAGE planner', $capturedPrompts[2]);
        self::assertStringContainsString('Plan locale: zh_Hans_CN', $capturedPrompts[2]);
        self::assertStringContainsString('Website content locale: en_US', $capturedPrompts[2]);
        self::assertStringContainsString('Do not use Plan locale for website copy unless it is identical to Website content locale.', $capturedPrompts[2]);
        self::assertStringContainsString('Confirmed requirement expansion (non-negotiable):', $capturedPrompts[2]);
        self::assertStringContainsString('Shared theme_design (non-negotiable):', $capturedPrompts[2]);
        self::assertStringContainsString('Theme-level page overview for this page', $capturedPrompts[2]);
        self::assertStringContainsString('page_design_plan', $capturedPrompts[2]);
        self::assertStringContainsString('color_layering', $capturedPrompts[2]);
        self::assertStringContainsString('Confirmed shared Header/Footer blocks (must frame this page when displayed):', $capturedPrompts[2]);
        self::assertStringContainsString('Baseline page shape to improve, keep compatible keys:', $capturedPrompts[2]);
        self::assertStringContainsString('Critical page differentiation rules:', $capturedPrompts[2]);
        self::assertStringContainsString('design_tags', $capturedPrompts[2]);
        self::assertStringContainsString('never make the entire page one flat background color', $capturedPrompts[2]);
        self::assertStringContainsString('Block budget: min=5, max=7', $capturedPrompts[2]);
        self::assertStringContainsString('You MUST include every required_block_key', $capturedPrompts[2]);
        $joinedPrompts = \implode("\n", $capturedPrompts);
        self::assertStringNotContainsString('"markdown":"string"', $joinedPrompts);
        self::assertStringNotContainsString('Markdown template (fill with concrete content, never with direction text):', $joinedPrompts);
        self::assertStringNotContainsString('????????', $joinedPrompts);
    }

    public function testBuildPlanArtifactsByAiStreamBackfillsMissingStageOneNavigationPlan(): void
    {
        $response = \json_decode($this->buildValidAiPlanResponse(), true);
        unset($response['plan_json']['navigation_plan']);

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub(\json_encode($response, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
            'default_locale' => 'en_US',
        ], [
            'site_title' => 'Plan Service Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ]);

        self::assertNotEmpty($artifacts['plan_json']['navigation_plan']['header_items'] ?? []);
        self::assertNotEmpty($artifacts['structured']['navigation_plan']['header_items'] ?? []);
        self::assertSame('/', (string)($artifacts['plan_json']['navigation_plan']['header_items'][0]['href'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamReportsDetailedStageOnePipelineProgress(): void
    {
        $progressEvents = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback): void {
                static $streamCall = 0;
                $streamCall++;
                $callback(match ($streamCall) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $artifacts = $service->buildPlanArtifactsByAiStream(
            [
                'site_title' => 'Progress Pipeline Test',
                'brief_description' => 'Need home and about pages with strong CTA.',
                'page_types' => ['home_page', 'about_page'],
                'workspace_track' => 'virtual_theme',
                'plan_locale' => 'zh_Hans_CN',
                'default_locale' => 'en_US',
            ],
            [
                'site_title' => 'Progress Pipeline Test',
                'brief_description' => 'Need home and about pages with strong CTA.',
            ],
            [],
            null,
            static function (array $progress) use (&$progressEvents): void {
                $progressEvents[] = $progress;
            }
        );

        self::assertNotEmpty($artifacts['plan_json']['pages'] ?? []);
        self::assertNotEmpty($progressEvents);
        $messages = \array_values(\array_map(static fn(array $row): string => (string)($row['message'] ?? ''), $progressEvents));
        foreach ($messages as $message) {
            self::assertNotSame('', \trim($message));
        }

        $stageMarkers = \array_values(\array_map(
            static fn(array $row): string => (string)($row['stage1_step'] ?? '') . '|' . (string)($row['stage1_phase'] ?? ''),
            $progressEvents
        ));
        $expectedMarkers = [
            'requirement_expand|start',
            'theme_design|start',
            'header_footer|start',
            'page_fanout|start',
            'plan_assemble|start',
            'plan_assemble|normalize_input',
            'plan_assemble|local_repair_scan',
            'plan_assemble|local_repair_done',
            'plan_assemble|build_shared_index',
            'plan_assemble|validate_contract',
            'plan_assemble|build_queue_envelope',
            'plan_assemble|build_workbench',
            'plan_assemble|done',
        ];
        $positions = [];
        foreach ($expectedMarkers as $expectedMarker) {
            $position = \array_search($expectedMarker, $stageMarkers, true);
            self::assertNotFalse($position, $expectedMarker);
            $positions[] = (int)$position;
        }
        $sortedPositions = $positions;
        \sort($sortedPositions);
        self::assertSame($sortedPositions, $positions);

        $pageMarkers = \array_values(\array_map(
            static fn(array $row): string => (string)($row['page_type'] ?? '') . '|' . (string)($row['stage1_phase'] ?? ''),
            \array_values(\array_filter($progressEvents, static fn(array $row): bool => (string)($row['stage1_step'] ?? '') === 'page_plan'))
        ));
        self::assertContains('home_page|start', $pageMarkers);
        self::assertContains('about_page|start', $pageMarkers);
        self::assertContains('home_page|done', $pageMarkers);
        self::assertContains('about_page|done', $pageMarkers);
        self::assertContains('queue_info', \array_map(static fn(array $row): string => (string)($row['progress_kind'] ?? ''), $progressEvents));
    }

    public function testBuildPlanArtifactsByAiStreamCapsMaxTokensBelowProviderLimit(): void
    {
        $capturedCalls = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$capturedCalls): void {
                $capturedCalls[] = [
                    'prompt' => $prompt,
                    'scenario' => $scenarioCode,
                    'params' => $params,
                ];
                $callback(match (\count($capturedCalls)) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
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

        self::assertCount(4, $capturedCalls);
        self::assertStringContainsString('REQUIREMENT EXPANSION planner', (string)($capturedCalls[0]['prompt'] ?? ''));
        self::assertStringContainsString('THEME planner', (string)($capturedCalls[1]['prompt'] ?? ''));
        self::assertStringContainsString('PAGE planner', (string)($capturedCalls[2]['prompt'] ?? ''));
        self::assertLessThanOrEqual(8192, (int)($capturedCalls[0]['params']['max_tokens'] ?? 0));
        self::assertLessThan(8192, (int)($capturedCalls[0]['params']['max_tokens'] ?? 0));
        self::assertSame(0, (int)($capturedCalls[0]['params']['timeout'] ?? -1));
        self::assertTrue((bool)($capturedCalls[0]['params']['disable_ai_timeout'] ?? false));
        self::assertTrue((bool)($capturedCalls[0]['params']['disable_cli_timeout'] ?? false));
        self::assertFalse((bool)($capturedCalls[0]['params']['enforce_timeout_in_stream'] ?? true));
        self::assertSame(['type' => 'json_object'], $capturedCalls[0]['params']['response_format'] ?? null);
        self::assertSame('ai_staged', (string)($artifacts['generation_source'] ?? ''));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['theme_style']['selection_reason'] ?? ''));
        self::assertStringContainsString('strong CTA', (string)($artifacts['plan_json']['palette']['selection_reason'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamSupportsStagedThemeAndPageGeneration(): void
    {
        $calls = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
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
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
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
        self::assertSame('pagebuilder_plan_generation', (string)($calls[3]['scenario'] ?? ''));
        self::assertStringContainsString('REQUIREMENT EXPANSION planner', (string)($calls[0]['prompt'] ?? ''));
        self::assertStringContainsString('THEME planner', (string)($calls[1]['prompt'] ?? ''));
        self::assertStringContainsString('PAGE planner', (string)($calls[2]['prompt'] ?? ''));
        self::assertStringContainsString('Critical page differentiation rules:', (string)($calls[2]['prompt'] ?? ''));
        self::assertStringContainsString('Page-type architecture guide:', (string)($calls[3]['prompt'] ?? ''));
        self::assertStringContainsString('design_tags', (string)($calls[2]['prompt'] ?? ''));
        self::assertStringContainsString('Confirmed requirement expansion', (string)($calls[1]['prompt'] ?? ''));
        self::assertStringContainsString('Confirmed shared Header/Footer blocks', (string)($calls[2]['prompt'] ?? ''));
        self::assertFalse((bool)($calls[0]['params']['enforce_timeout_in_stream'] ?? true));
        self::assertFalse((bool)($calls[1]['params']['enforce_timeout_in_stream'] ?? true));
        self::assertSame(0, (int)($calls[0]['params']['timeout'] ?? -1));
        self::assertTrue((bool)($calls[0]['params']['disable_ai_timeout'] ?? false));
        self::assertTrue((bool)($calls[0]['params']['disable_cli_timeout'] ?? false));
        self::assertLessThanOrEqual(6144, (int)($calls[2]['params']['max_tokens'] ?? 0));
        self::assertGreaterThan(4096, (int)($calls[2]['params']['max_tokens'] ?? 0));
        self::assertNotEmpty($artifacts['plan_json']['requirement_expansion']['expanded_brief'] ?? '');
        self::assertNotEmpty($artifacts['plan_json']['overview_expanded_brief'] ?? '');
        self::assertNotEmpty($artifacts['plan_json']['overview_business_goals'] ?? []);
        self::assertNotEmpty($artifacts['plan_json']['overview_content_focus'] ?? '');
        self::assertNotEmpty($artifacts['plan_json']['overview_domain_strategy'] ?? '');
        self::assertSame($artifacts['plan_json']['overview_business_goals'], $artifacts['structured']['overview_business_goals'] ?? []);
        self::assertNotEmpty($artifacts['plan_json']['pages'][Page::TYPE_HOME]['display_blocks'] ?? []);

        $homeBlocks = \array_values(\array_map(static fn(array $block): string => (string)($block['block_key'] ?? ''), $artifacts['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? []));
        $aboutBlocks = \array_values(\array_map(static fn(array $block): string => (string)($block['block_key'] ?? ''), $artifacts['plan_json']['pages'][Page::TYPE_ABOUT]['blocks'] ?? []));
        self::assertNotSame($homeBlocks, $aboutBlocks);
        self::assertSame(['hero', 'featured_games', 'final_cta'], $homeBlocks);
        self::assertSame(['brand_story', 'mission_values', 'community_cta'], $aboutBlocks);
        self::assertContains('5s fade in/out', $artifacts['structured']['page_plans'][Page::TYPE_HOME]['blocks'][0]['design_tags']['motion'] ?? []);
        self::assertContains('rounded image', $artifacts['structured']['page_plans'][Page::TYPE_HOME]['blocks'][0]['design_tags']['visual'] ?? []);
        self::assertIsArray($artifacts['structured']['page_plans'][Page::TYPE_HOME]['page_design_plan'] ?? null);
        self::assertNotSame('', (string)($artifacts['structured']['page_plans'][Page::TYPE_HOME]['page_design_plan']['color_layering'] ?? ''));
        self::assertNotSame('', (string)($artifacts['structured']['page_plans'][Page::TYPE_HOME]['blocks'][0]['design_tags']['color_layering'] ?? ''));
        self::assertContains('timeline reveal', $artifacts['structured']['page_plans'][Page::TYPE_ABOUT]['blocks'][0]['design_tags']['motion'] ?? []);
    }

    public function testBuildPlanArtifactsByAiStreamUsesReferenceImageInsightsBeforeThemePlanning(): void
    {
        $calls = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::never())->method('generate');
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode = null,
                string $scenarioCode = '',
                $locale = null,
                array $params = []
            ) use (&$calls): void {
                $calls[] = [
                    'prompt' => $prompt,
                    'scenario' => $scenarioCode,
                    'params' => $params,
                ];
                static $streamCall = 0;
                $streamCall++;
                $callback(match ($streamCall) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $referenceInsightService = new class extends AiSiteReferenceImageInsightService {
            public int $calls = 0;

            public function analyze(array $scope, string $locale = '', string $scenarioCode = self::DEFAULT_SCENARIO_CODE): array
            {
                $this->calls++;
                return [
                    'summary' => 'Magazine-like layouts with bold imagery.',
                    'style_keywords' => ['editorial', 'layered composition'],
                    'color_palette' => ['#112233', '#F0E0D0'],
                    'layout_cues' => ['Asymmetric grid with focal hero crop'],
                    'component_cues' => ['Floating cards with overlap'],
                    'typography_cues' => ['Bold condensed display headings'],
                    'do_not_use' => ['flat generic SaaS stock look'],
                ];
            }

            public function buildSignature(array $scope): string
            {
                return 'reference-sig';
            }
        };

        $scope = [
            'site_title' => 'Reference Guided Stage One',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
            'reference_images' => [[
                'url' => '/pub/media/page-build/reference/moodboard.png',
                'name' => 'Moodboard',
                'mime_type' => 'image/png',
            ]],
        ];
        $websiteProfile = [
            'site_title' => 'Reference Guided Stage One',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ];

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService,
            null,
            null,
            $referenceInsightService
        );
        $artifacts = $service->buildPlanArtifactsByAiStream($scope, $websiteProfile, [
            'staged_generation' => true,
        ]);

        self::assertSame(1, $referenceInsightService->calls);
        self::assertSame(
            'Magazine-like layouts with bold imagery.',
            (string)($artifacts['derived_scope_patch']['reference_image_insights']['summary'] ?? '')
        );
        self::assertSame(
            'reference-sig',
            (string)($artifacts['derived_scope_patch']['reference_image_insights_signature'] ?? '')
        );
        self::assertStringContainsString(
            'Magazine-like layouts with bold imagery.',
            \implode("\n\n", \array_map(static fn(array $call): string => (string)($call['prompt'] ?? ''), $calls))
        );
        self::assertReferenceStyleContextCarried($artifacts['plan_json']['theme_design'] ?? null);
        self::assertReferenceStyleContextCarried($artifacts['structured']['shared_plan']['theme_design'] ?? null);
        self::assertReferenceStyleContextCarried($artifacts['execution_blueprint']['theme_context_snapshot'] ?? null);
        self::assertReferenceStyleContextCarried($artifacts['execution_blueprint']['shared_prompt_context']['theme_design'] ?? null);
        self::assertReferenceStyleContextCarried($artifacts['plan_workbench']['stage1']['theme_context_snapshot'] ?? null);
    }

    public function testBuildPlanArtifactsByAiStreamReusesStageOneCheckpointWithoutRepeatingAiCalls(): void
    {
        $checkpoints = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback): void {
                static $streamCall = 0;
                $streamCall++;
                $callback(match ($streamCall) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $scope = [
            'site_title' => 'Checkpoint Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ];
        $websiteProfile = [
            'site_title' => 'Checkpoint Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ];
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);
        $firstArtifacts = $service->buildPlanArtifactsByAiStream($scope, $websiteProfile, [
            'on_stage1_checkpoint' => static function (array $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        ]);

        self::assertNotEmpty($firstArtifacts['plan_json']['pages'][Page::TYPE_HOME] ?? []);
        self::assertGreaterThanOrEqual(3, \count($checkpoints));
        self::assertSame('requirement_expand', (string)($checkpoints[0]['step'] ?? ''));
        self::assertSame('theme_design', (string)($checkpoints[1]['step'] ?? ''));
        self::assertSame('page_fanout', (string)($checkpoints[2]['step'] ?? ''));

        $noRepeatAiService = $this->createAiServiceStreamMock();
        $noRepeatAiService->expects(self::never())->method('generateStream');
        $resumeService = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $noRepeatAiService);
        $resumedArtifacts = $resumeService->buildPlanArtifactsByAiStream($scope, $websiteProfile, [
            'stage1_checkpoint' => \end($checkpoints),
        ]);

        self::assertSame($firstArtifacts['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? [], $resumedArtifacts['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? []);
        self::assertSame($firstArtifacts['plan_json']['theme_design']['theme_purpose'] ?? '', $resumedArtifacts['plan_json']['theme_design']['theme_purpose'] ?? '');
        self::assertSame('ai_staged', (string)($resumedArtifacts['generation_source'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamRegeneratesCheckpointPageWithEmptyBlocks(): void
    {
        $checkpoints = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(4))
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback): void {
                static $streamCall = 0;
                $streamCall++;
                $callback(match ($streamCall) {
                    1 => $this->buildStageOneRequirementExpansionAiResponse(),
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    4 => $this->buildStagedPageAiResponse(Page::TYPE_ABOUT),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $scope = [
            'site_title' => 'Checkpoint Repair Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
            'page_types' => ['home_page', 'about_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ];
        $websiteProfile = [
            'site_title' => 'Checkpoint Repair Test',
            'brief_description' => 'Need home and about pages with strong CTA.',
        ];
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);
        $firstArtifacts = $service->buildPlanArtifactsByAiStream($scope, $websiteProfile, [
            'on_stage1_checkpoint' => static function (array $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        ]);
        $checkpoint = \end($checkpoints);
        self::assertIsArray($checkpoint);
        $checkpoint['plan_json']['pages'][Page::TYPE_HOME]['blocks'] = [];

        $resumeCalls = [];
        $repairAiService = $this->createAiServiceStreamMock();
        $repairAiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback) use (&$resumeCalls): void {
                $resumeCalls[] = $prompt;
                $callback($this->buildStagedPageAiResponse(Page::TYPE_HOME));
            });

        $resumeService = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $repairAiService);
        $resumedArtifacts = $resumeService->buildPlanArtifactsByAiStream($scope, $websiteProfile, [
            'stage1_checkpoint' => $checkpoint,
        ]);

        self::assertNotEmpty($resumedArtifacts['plan_json']['pages'][Page::TYPE_HOME]['blocks'] ?? []);
        self::assertSame(
            $firstArtifacts['plan_json']['pages'][Page::TYPE_ABOUT]['blocks'] ?? [],
            $resumedArtifacts['plan_json']['pages'][Page::TYPE_ABOUT]['blocks'] ?? []
        );
        self::assertNotEmpty($resumeCalls, 'only the invalid checkpoint page should be regenerated');
    }

    public function testResolveStageOneCheckpointAcceptsStoredCheckpointOnResumePlanDespiteSignatureDrift(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $resolveCheckpoint = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'resolveStageOneCheckpoint');
        $resolveCheckpoint->setAccessible(true);

        $storedSignature = 'stored-checkpoint-signature';
        $checkpoint = [
            'signature' => $storedSignature,
            'step' => 'page_fanout',
            'plan_json' => [
                'requirement_expansion' => ['expanded_brief' => 'seed'],
                'theme_design' => ['theme_purpose' => 'resume checkpoint theme'],
                'pages' => [
                    Page::TYPE_HOME => ['blocks' => [['block_key' => 'hero']]],
                    Page::TYPE_ABOUT => ['blocks' => [['block_key' => 'intro']]],
                ],
            ],
        ];
        $scope = [
            'plan_last_prompt_mode' => 'resume_plan',
            '_plan_sse_request' => ['prompt_mode' => 'resume_plan'],
            '_plan_generation_checkpoint' => $checkpoint,
        ];
        $payload = [
            'prompt_mode' => 'resume_plan',
            'resume_failed_tasks' => 1,
            'target_scope' => 'resume_generation',
            'instruction' => 'resume only failed pages',
            'stage1_checkpoint' => $checkpoint,
        ];

        $resolved = $resolveCheckpoint->invoke($service, $payload, $scope, 'different-signature');
        self::assertSame($storedSignature, (string)($resolved['signature'] ?? ''));
        self::assertSame(
            'resume checkpoint theme',
            (string)($resolved['plan_json']['theme_design']['theme_purpose'] ?? '')
        );
    }

    public function testResolveStageOneResumePageTypesLimitsFanoutToRetryableFailures(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $resolveResumePages = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'resolveStageOneResumePageTypes');
        $resolveResumePages->setAccessible(true);

        $scope = [
            AiSiteBuildTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY => [
                'plan' => [
                    'items' => [
                        Page::TYPE_HOME => [
                            'operation' => 'plan',
                            'item_key' => Page::TYPE_HOME,
                            'page_type' => Page::TYPE_HOME,
                            'retry_scope' => 'stage1_page',
                            'message' => 'home failed',
                        ],
                        'stage1_plan' => [
                            'operation' => 'plan',
                            'item_key' => 'stage1_plan',
                            'retry_scope' => 'plan',
                            'message' => 'aggregate failed',
                        ],
                    ],
                ],
            ],
        ];
        $existingPagePlans = [
            Page::TYPE_HOME => ['blocks' => []],
            Page::TYPE_ABOUT => ['blocks' => [['block_key' => 'intro']]],
        ];

        $resumePageTypes = $resolveResumePages->invoke(
            $service,
            $scope,
            [Page::TYPE_HOME, Page::TYPE_ABOUT],
            $existingPagePlans,
            []
        );

        self::assertSame([Page::TYPE_HOME], $resumePageTypes);
    }

    public function testBuildPlanArtifactsByAiStreamFallsBackToNonStreamWhenProviderReturnsEmptyStream(): void
    {
        $aiService = $this->createAiServiceStreamMock();
        $streamCalls = [];
        $aiService->expects(self::exactly(2))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$streamCalls): void {
                $streamCalls[] = ['prompt' => $prompt, 'params' => $params];
                if (\count($streamCalls) === 1) {
                    throw new \Exception('AI流式生成失败: AI 流式生成完成但未返回任何内容，请检查模型配置');
                }
                $callback('not a complete json response');
            });
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturn($this->buildStageOneRequirementExpansionAiResponse());

        $scope = [
            'site_title' => 'Stream Recovery Test',
            'brief_description' => 'Need a conversion-focused home page.',
            'page_types' => ['home_page'],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'en_US',
            'default_locale' => 'en_US',
        ];
        $websiteProfile = [
            'site_title' => 'Stream Recovery Test',
            'brief_description' => 'Need a conversion-focused home page.',
        ];
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);

        $method = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'generateStageOneJsonByAi');
        $method->setAccessible(true);
        $decoded = $method->invoke(
            $service,
            'Return requirement expansion JSON.',
            'pagebuilder_plan_generation',
            2048,
            150,
            null,
            []
        );

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded['requirement_expansion']['site_goal'] ?? '');
        self::assertCount(2, $streamCalls);
        self::assertGreaterThan(
            (int)($streamCalls[0]['params']['max_tokens'] ?? 0),
            (int)($streamCalls[1]['params']['max_tokens'] ?? 0)
        );
    }

    public function testBuildPlanArtifactsByAiStreamFallsBackToNonStreamWhenStreamReturnsTruncatedJson(): void
    {
        $aiService = $this->createAiServiceStreamMock();
        $streamCalls = [];
        $aiService->expects(self::exactly(2))
            ->method('generateStream')
            ->willReturnCallback(function (
                string $prompt,
                callable $callback,
                $modelCode,
                string $scenarioCode,
                $locale,
                array $params
            ) use (&$streamCalls): void {
                $streamCalls[] = ['prompt' => $prompt, 'params' => $params];
                $callback('not a json response');
            });
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturn($this->buildStagedPageAiResponse(Page::TYPE_HOME));

        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);

        $method = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'generateStageOneJsonByAi');
        $method->setAccessible(true);
        $decoded = $method->invoke(
            $service,
            'Return home_page page plan JSON.',
            'pagebuilder_plan_generation',
            4096,
            150,
            null,
            []
        );

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded['page']['blocks'] ?? []);
        self::assertCount(2, $streamCalls);
        self::assertGreaterThan(
            (int)($streamCalls[0]['params']['max_tokens'] ?? 0),
            (int)($streamCalls[1]['params']['max_tokens'] ?? 0)
        );
    }

    public function testStageOneLocalRepairInvalidAiJsonFallsBackWithoutFailingWholePlan(): void
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        self::assertIsArray($decoded);
        $planJson = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : [];
        $planJson['pages'][Page::TYPE_HOME]['blocks'][0]['content'] = 'section title';

        $progressEvents = [];
        $aiService = $this->createAiServiceStreamMock();
        $aiService->expects(self::exactly(2))
            ->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback): void {
                $callback('{ "page": { "page_goal": "Clearly communicate the refund policy", "theme_alignm');
            });
        $aiService->expects(self::once())
            ->method('generate')
            ->willReturn('{ "page": { "page_goal": "Clearly communicate the refund policy", "theme_alignm');

        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService(), $aiService);
        $method = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'repairAiStageOneProblemBlocksByAi');
        $method->setAccessible(true);
        $result = $method->invoke(
            $service,
            [
                'site_title' => 'Local Repair Test',
                'brief_description' => 'Need home and about pages with strong CTA.',
                'page_types' => [Page::TYPE_HOME],
                'plan_locale' => 'en_US',
            ],
            [
                'site_title' => 'Local Repair Test',
                'brief_description' => 'Need home and about pages with strong CTA.',
            ],
            $planJson,
            [Page::TYPE_HOME],
            'en_US',
            'en_US',
            'Fix invalid blocks.',
            'stage1_local_repair',
            'Need home and about pages with strong CTA.',
            static function (array $progress) use (&$progressEvents): void {
                $progressEvents[] = $progress;
            }
        );

        self::assertIsArray($result);
        self::assertIsArray($result[0] ?? null);
        self::assertIsArray($result[1] ?? null);
        self::assertNotSame('section title', (string)($result[0]['pages'][Page::TYPE_HOME]['blocks'][0]['content'] ?? ''));
        self::assertSame(1, (int)($result[1]['final_issue_count'] ?? 0));
        self::assertContains(
            'local_repair_error',
            \array_map(static fn(array $row): string => (string)($row['stage1_phase'] ?? ''), $progressEvents)
        );
    }

    public function testBuildPlanArtifactsByAiStreamUsesFallbackRequirementWhenBriefIsEmpty(): void
    {
        $calls = [];
        $aiService = $this->createAiServiceStreamMock();
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
                    1 => \json_encode(['requirement_expansion' => []], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}',
                    3 => $this->buildStagedPageAiResponse(Page::TYPE_HOME),
                    default => $this->buildValidAiPlanResponse(),
                });
            });

        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $aiService
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
            'site_title' => '',
            'brief_description' => '',
            'user_description' => '',
            'page_types' => [Page::TYPE_HOME],
            'workspace_track' => 'virtual_theme',
            'plan_locale' => 'zh_Hans_CN',
        ], [
            'site_title' => '',
            'brief_description' => '',
        ]);

        self::assertStringNotContainsString('User one-line requirement: -', (string)($calls[0]['prompt'] ?? ''));
        self::assertStringNotContainsString('Brief: -', (string)($calls[1]['prompt'] ?? ''));
        $originalBrief = (string)($artifacts['plan_json']['requirement_expansion']['original_brief'] ?? '');
        self::assertNotSame('', $originalBrief);
        self::assertNotSame('', (string)($artifacts['plan_json']['requirement_expansion']['expanded_brief'] ?? ''));
        self::assertNotEmpty($artifacts['plan_json']['requirement_expansion']['page_strategy'] ?? []);
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
        self::assertArrayNotHasKey('ai_fallback', $artifacts);
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

    public function testBuildPlanArtifactsByAiStreamRepairsThemeSelectionReasonThatIgnoresRequirement(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithThemeSelectionReason(
                'Modern, premium, clean, and simple.'
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

        $selectionReason = (string)($artifacts['plan_json']['theme_design']['selection_reason'] ?? '');

        self::assertSame(1, (int)($artifacts['ai_generated'] ?? 0));
        self::assertStringContainsString('Need home and about pages with strong CTA.', $selectionReason);
        self::assertNotSame('Modern, premium, clean, and simple.', $selectionReason);
    }

    public function testBuildPlanArtifactsByAiStreamRepairsEmptyThemeSelectionReason(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithThemeSelectionReason(''))
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

        $selectionReason = (string)($artifacts['plan_json']['theme_design']['selection_reason'] ?? '');

        self::assertSame(1, (int)($artifacts['ai_generated'] ?? 0));
        self::assertNotSame('', \trim($selectionReason));
        self::assertStringContainsString('Need home and about pages with strong CTA.', $selectionReason);
    }

    public function testBuildPlanArtifactsByAiStreamRepairsInstructionLikeThemeSelectionReason(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithThemeSelectionReason(
                'Explain why this theme fits the requirement.'
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

        $selectionReason = (string)($artifacts['plan_json']['theme_design']['selection_reason'] ?? '');

        self::assertSame(1, (int)($artifacts['ai_generated'] ?? 0));
        self::assertStringContainsString('Need home and about pages with strong CTA.', $selectionReason);
        self::assertNotSame('Explain why this theme fits the requirement.', $selectionReason);
    }

    public function testBuildPlanArtifactsByAiStreamRepairsMissingFieldImplementationNote(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithMissingFieldImplementationNote())
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

        $fieldPlan = $artifacts['plan_json']['pages']['home_page']['blocks'][0]['field_plan'] ?? [];
        self::assertIsArray($fieldPlan);
        self::assertNotSame('', \trim((string)($fieldPlan[0]['implementation_note'] ?? '')));
        self::assertStringContainsString('visible heading', (string)($fieldPlan[0]['implementation_note'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamMarksPageRetryableWhenFanoutReturnsNoBlocks(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithEmptyHomeBlocks())
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

        $retryableFailures = $artifacts['retryable_ai_failures'] ?? [];

        self::assertSame(1, (int)($artifacts['partial_retry_required'] ?? 0));
        self::assertIsArray($retryableFailures);
        self::assertSame('home_page', (string)($retryableFailures[0]['page_type'] ?? ''));
        self::assertStringContainsString('without usable blocks', (string)($retryableFailures[0]['message'] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamRepairsPromptLikeFeaturePoints(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithPromptLikeFeaturePoint())
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

        $featurePoints = $artifacts['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['feature_points'] ?? [];
        self::assertIsArray($featurePoints);
        self::assertNotEmpty($featurePoints);
        self::assertStringNotContainsString('List 2-4', (string)($featurePoints[0] ?? ''));
    }

    public function testBuildPlanArtifactsByAiStreamRepairsDetailedTermsFeaturePointsFromQueueOutput(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithDetailedTermsInstructionLikeFeaturePoint())
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

        $block = $artifacts['plan_json']['pages']['home_page']['blocks'][0] ?? [];
        self::assertIsArray($block);
        self::assertSame('detailed_terms_content', (string)($block['block_key'] ?? ''));

        $featurePoints = $block['execution_script']['feature_points'] ?? [];
        self::assertIsArray($featurePoints);
        self::assertNotEmpty($featurePoints);
        self::assertStringNotContainsString('section titles', \implode(' ', \array_map('strval', $featurePoints)));
    }

    public function testBuildPlanArtifactsByAiStreamRecordsLocalRegenReportForProblemBlocks(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithDetailedTermsInstructionLikeFeaturePoint())
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

        $report = $artifacts['plan_json']['_stage1_local_regen_report'] ?? [];
        self::assertIsArray($report);
        self::assertArrayHasKey('final_issue_count', $report);
    }

    public function testBuildPlanArtifactsByAiStreamReportsMissingPlanJsonOnlyOnce(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub(\json_encode(['markdown' => '# Invalid'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('第一阶段方案生成失败：生成页面方案前必须先完成主题方案。');

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

    public function testBuildPlanArtifactsByAiStreamAcceptsThemeDesignFromSharedPlanShape(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildAiPlanResponseWithSharedPlanThemeDesignOnly())
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

        $themeDesign = $artifacts['plan_json']['theme_design'] ?? [];
        self::assertIsArray($themeDesign);
        self::assertNotSame('', \trim((string)($themeDesign['theme_purpose'] ?? '')));
        self::assertNotSame('', \trim((string)($themeDesign['selection_reason'] ?? '')));
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

    public function testBuildPlanArtifactsByAiStreamRepairsHomepageHeroThatIgnoresBriefSignals(): void
    {
        $service = new AiSiteExecutionBlueprintService(
            new AiSitePageBlueprintService(),
            $this->createStreamingAiServiceStub($this->buildGenericAiPlanResponse())
        );

        $artifacts = $service->buildPlanArtifactsByAiStream([
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

        self::assertIsArray($artifacts['plan_json'] ?? null);
        $heroBlock = $artifacts['plan_json']['pages']['home_page']['blocks'][0] ?? [];
        self::assertIsArray($heroBlock);
        $heroText = (string)\json_encode($heroBlock, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('APK', $heroText);
    }

    private static function assertStageOneThemeDesignSchema(mixed $themeDesign): void
    {
        self::assertIsArray($themeDesign);
        foreach ([
            'theme_purpose',
            'style_signature',
            'art_direction',
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
        foreach (['theme_purpose', 'style_signature', 'tone_of_voice', 'cta_tone', 'selection_reason'] as $field) {
            self::assertNotSame('', \trim((string)$themeDesign[$field]), 'theme_design.' . $field . ' must not be empty.');
        }
        self::assertIsArray($themeDesign['art_direction']);
        foreach (['layout_motif', 'background_system', 'surface_treatment', 'visual_detail_rule', 'motion_rule'] as $field) {
            self::assertArrayHasKey($field, $themeDesign['art_direction']);
            self::assertNotSame('', \trim((string)$themeDesign['art_direction'][$field]), 'theme_design.art_direction.' . $field . ' must not be empty.');
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
        if (isset($themeDesign['reference_style_context']) && $themeDesign['reference_style_context'] !== []) {
            self::assertIsArray($themeDesign['reference_style_context']);
            foreach (['summary', 'style_keywords', 'color_palette', 'layout_cues', 'component_cues', 'typography_cues', 'do_not_use', 'implementation_rule'] as $field) {
                self::assertArrayHasKey($field, $themeDesign['reference_style_context']);
            }
        }
    }

    private static function assertReferenceStyleContextCarried(mixed $themeDesign): void
    {
        self::assertStageOneThemeDesignSchema($themeDesign);
        $referenceStyleContext = $themeDesign['reference_style_context'] ?? null;
        self::assertIsArray($referenceStyleContext);
        self::assertSame('Magazine-like layouts with bold imagery.', (string)($referenceStyleContext['summary'] ?? ''));
        self::assertContains('editorial', $referenceStyleContext['style_keywords'] ?? []);
        self::assertContains('#112233', $referenceStyleContext['color_palette'] ?? []);
        self::assertContains('Asymmetric grid with focal hero crop', $referenceStyleContext['layout_cues'] ?? []);
        self::assertContains('Floating cards with overlap', $referenceStyleContext['component_cues'] ?? []);
        self::assertContains('Bold condensed display headings', $referenceStyleContext['typography_cues'] ?? []);
        self::assertContains('flat generic SaaS stock look', $referenceStyleContext['do_not_use'] ?? []);
        self::assertContains('Asymmetric grid with focal hero crop', $themeDesign['visual_keywords'] ?? []);
        self::assertContains('flat generic SaaS stock look', $themeDesign['forbidden_styles'] ?? []);
        self::assertStringContainsString('Asymmetric grid with focal hero crop', (string)($themeDesign['art_direction']['layout_motif'] ?? ''));
        self::assertStringContainsString('Floating cards with overlap', (string)($themeDesign['art_direction']['surface_treatment'] ?? ''));
        self::assertStringContainsString('Bold condensed display headings', (string)($themeDesign['typography_spacing_radius']['font_family'] ?? ''));
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
            'style_signature' => $themeDesign['style_signature'],
            'art_direction' => $themeDesign['art_direction'],
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
        $aiService = $this->createAiServiceStreamMock();
        $aiService->method('generateStream')
            ->willReturnCallback(function (string $prompt, callable $callback) use ($response): void {
                if (\str_contains($prompt, 'Stage-1 REQUIREMENT EXPANSION planner')) {
                    $callback($this->buildStageOneRequirementExpansionAiResponse());
                    return;
                }
                $callback($response);
            });

        return $aiService;
    }

    private function createAiServiceStreamMock(): AiService
    {
        return $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateStream', 'generate'])
            ->getMock();
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

    private function buildStageOneRequirementExpansionAiResponse(): string
    {
        return \json_encode([
            'requirement_expansion' => [
                'original_brief' => 'Need home and about pages with strong CTA.',
                'expanded_brief' => 'Plan Service Test needs a trust-first website that explains the offer, proves credibility, and moves visitors toward one strong CTA across Home and About.',
                'planning_summary' => 'Create a concise conversion path with clear positioning, trust proof, and page-specific content blocks that stay ready for frontend implementation.',
                'site_goal' => 'Capture qualified leads through a clear CTA while keeping the brand credible.',
                'target_users' => ['Prospects', 'Decision makers'],
                'business_context' => 'Brand site with direct lead-capture intent.',
                'content_direction' => 'Use concrete headings, proof points, CTA copy, and reusable trust language.',
                'conversion_strategy' => 'Lead visitors from value proof to contact action.',
                'page_strategy' => [
                    [
                        'page_type' => Page::TYPE_HOME,
                        'intent' => 'Introduce value and drive the primary CTA.',
                        'content_focus' => 'Hero promise, service proof, and final action.',
                        'conversion_role' => 'Primary conversion entry.',
                    ],
                    [
                        'page_type' => Page::TYPE_ABOUT,
                        'intent' => 'Build trust with story and credibility.',
                        'content_focus' => 'Origin, mission, values, and reassurance.',
                        'conversion_role' => 'Trust support before contact.',
                    ],
                ],
                'technical_direction' => ['Use responsive block plans', 'Keep shared Header/Footer reusable'],
            ],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
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
                'shared_components' => [
                    'header' => [
                        'component' => 'header',
                        'title' => 'Header',
                        'goal' => 'Make brand navigation and the primary CTA visible before page content.',
                        'implementation_detail' => 'Responsive header with brand name, Home/About links, and one CTA using the shared theme.',
                        'realtime_content' => [
                            'headline' => 'Plan Service Test',
                            'supporting_copy' => ['Home', 'About'],
                            'cta' => [['label' => 'Contact us', 'target' => '/contact']],
                            'editable_slots' => ['brand_name', 'nav_items', 'primary_cta'],
                        ],
                        'editable_fields' => ['brand_name', 'nav_items', 'primary_cta'],
                        'responsive_rule' => 'Collapse navigation into a compact mobile menu.',
                    ],
                    'footer' => [
                        'component' => 'footer',
                        'title' => 'Footer',
                        'goal' => 'Close each page with trust, contact, and policy paths.',
                        'implementation_detail' => 'Shared footer with contact link, policy links, and concise brand reassurance.',
                        'realtime_content' => [
                            'headline' => 'Continue with Plan Service Test',
                            'supporting_copy' => ['Contact', 'Privacy Policy'],
                            'cta' => [['label' => 'Contact us', 'target' => '/contact']],
                            'editable_slots' => ['footer_links', 'policy_links', 'support_copy'],
                        ],
                        'editable_fields' => ['footer_links', 'policy_links', 'support_copy'],
                        'responsive_rule' => 'Stack footer groups on mobile.',
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
                'build_plan_task_hints' => [
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

    private function buildAiPlanResponseWithSharedPlanThemeDesignOnly(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $planJson = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : [];
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        if ($planJson === [] || $themeDesign === []) {
            return '{}';
        }

        unset($planJson['theme_design']);
        $planJson['shared_plan'] = [
            'theme_design' => $themeDesign,
        ];
        $decoded['plan_json'] = $planJson;

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildAiPlanResponseWithMissingFieldImplementationNote(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        unset(
            $decoded['plan_json']['pages']['home_page']['blocks'][0]['field_plan'][0]['implementation_note'],
            $decoded['plan_json']['pages']['home_page']['blocks'][0]['field_plan'][0]['reason']
        );

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildAiPlanResponseWithEmptyHomeBlocks(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['pages']['home_page']['page_goal'] = 'Drive visitors to understand the offer and take the primary CTA.';
        $decoded['plan_json']['pages']['home_page']['theme_alignment_summary'] = 'Home page uses the selected trust-first theme with direct conversion rhythm.';
        $decoded['plan_json']['pages']['home_page']['blocks'] = [];

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildAiPlanResponseWithPromptLikeFeaturePoint(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $decoded['plan_json']['pages']['home_page']['blocks'][0]['execution_script']['feature_points'] = ['List 2-4 value points'];

        return \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function buildAiPlanResponseWithDetailedTermsInstructionLikeFeaturePoint(): string
    {
        $decoded = \json_decode($this->buildValidAiPlanResponse(), true);
        if (!\is_array($decoded)) {
            return '{}';
        }

        $block = &$decoded['plan_json']['pages']['home_page']['blocks'][0];
        if (!\is_array($block)) {
            return '{}';
        }

        $block['block_key'] = 'detailed_terms_content';
        $block['section_code'] = 'detailed_terms_content';
        $block['component_kind'] = 'legal';
        $block['goal'] = 'Explain terms with clear acceptance, eligibility, APK use, privacy, disclaimer, updates, and contact sections.';
        $block['content'] = 'Acceptance, eligibility, APK use, privacy, disclaimer, update, and contact terms are presented as readable sections for visitors before they continue.';
        $block['field_plan'] = [
            ['field' => 'terms_title', 'sample' => 'Terms of Service', 'implementation_note' => 'Render this as the visible legal content heading above the terms sections.'],
            ['field' => 'terms_summary', 'sample' => 'Review acceptance, eligibility, APK use, privacy, disclaimer, updates, and contact paths before continuing.', 'implementation_note' => 'Render this summary directly below the legal heading as customer-visible copy.'],
            ['field' => 'contact_note', 'sample' => 'Contact support@example.com with questions about these terms.', 'implementation_note' => 'Render this as the final support note in the terms content block.'],
        ];
        $block['execution_script']['feature_points'] = [
            'Numbered sections for clarity',
            'Sticky sidebar navigation on desktop',
            'Hover effects on section titles',
        ];
        $block['execution_script']['core_copy'] = 'Acceptance, eligibility, APK use, privacy, disclaimer, updates, and contact terms are ready as visible legal copy.';

        unset($block);

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

    public function testRepairAiStageOneBlocksBeforeValidationBackfillsMissingDesignTags(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $repairMethod = new \ReflectionMethod(AiSiteExecutionBlueprintService::class, 'repairAiStageOneBlocksBeforeValidation');
        $repairMethod->setAccessible(true);

        $blocks = [[
            'block_key' => 'article_body',
            'content' => '文章正文展示完整阅读体验与下一步引导。',
            'design_tags' => [],
            'field_plan' => [
                ['field' => 'headline', 'sample' => '文章标题', 'implementation_note' => '主标题直接上屏。'],
                ['field' => 'supporting_copy', 'sample' => '文章摘要', 'implementation_note' => '摘要区文案。'],
                ['field' => 'context_detail', 'sample' => '阅读提示', 'implementation_note' => '补充说明。'],
            ],
            'execution_script' => [
                'core_copy' => '文章正文帮助访客快速理解主题并继续阅读。',
                'feature_points' => ['清晰标题', '分段正文', '继续阅读入口'],
            ],
        ]];

        $repaired = $repairMethod->invoke(
            $service,
            $blocks,
            Page::TYPE_BLOG,
            'zh_Hans_CN',
            ['theme_design' => ['color_scheme' => ['primary' => '#123456', 'accent' => '#abcdef', 'background' => '#ffffff']]],
            ['color_layering' => '正文区使用浅色底，标题区使用主色强调。']
        );
        self::assertIsArray($repaired[0]['design_tags'] ?? null);
        foreach (AiSiteStageOneContractService::DESIGN_TAG_KEYS as $tagKey) {
            $value = $repaired[0]['design_tags'][$tagKey] ?? null;
            if (\in_array($tagKey, ['visual', 'motion', 'interaction', 'texture', 'responsive'], true)) {
                self::assertIsArray($value);
                self::assertNotEmpty($value, $tagKey);
                continue;
            }
            self::assertIsString($value);
            self::assertNotSame('', \trim($value), $tagKey);
        }

        $contract = (new AiSiteStageOneContractService())->build(
            ['theme_design' => ['color_scheme' => ['primary' => '#123456']]],
            [Page::TYPE_BLOG],
            'zh_Hans_CN',
            'zh_Hans_CN'
        );
        $pagePlan = [
            'page_goal' => '帮助访客阅读博客文章并继续浏览相关内容。',
            'theme_alignment_summary' => '延续站点主题色与排版节奏，突出文章可读性。',
            'page_design_plan' => [
                'color_layering' => '正文区使用浅色底，标题区使用主色强调。',
                'section_flow' => ['标题区', '正文区', '相关推荐'],
            ],
            'blocks' => $repaired,
        ];
        $report = (new AiSiteStageOneContractValidator())->validatePagePlan(Page::TYPE_BLOG, $pagePlan, $contract);
        $missingDesignTagIssues = \array_values(\array_filter(
            \is_array($report['issues'] ?? null) ? $report['issues'] : [],
            static fn(array $issue): bool => (string)($issue['code'] ?? '') === 'missing_design_tag'
        ));
        self::assertSame([], $missingDesignTagIssues, \json_encode($report['issues'] ?? [], \JSON_UNESCAPED_UNICODE));
    }

    public function testPrepareStageOnePlanScopeForConfirmationRepairsMissingDesignTags(): void
    {
        $service = new AiSiteExecutionBlueprintService(new AiSitePageBlueprintService());
        $scope = [
            'page_types' => [Page::TYPE_BLOG],
            'plan_locale' => 'zh_Hans_CN',
            'brief_description' => '面向年轻读者的博客站点。',
            'plan_json' => [
                'theme_design' => [
                    'color_scheme' => [
                        'primary' => '#123456',
                        'accent' => '#abcdef',
                        'background' => '#ffffff',
                    ],
                ],
                'pages' => [
                    Page::TYPE_BLOG => [
                        'page_goal' => '帮助访客阅读博客文章并继续浏览相关内容。',
                        'theme_alignment_summary' => '延续站点主题色与排版节奏，突出文章可读性。',
                        'page_design_plan' => [
                            'color_layering' => '正文区使用浅色底，标题区使用主色强调。',
                            'section_flow' => ['标题区', '正文区', '相关推荐'],
                        ],
                        'blocks' => [[
                            'block_key' => 'article_body',
                            'content' => '文章正文展示完整阅读体验与下一步引导。',
                            'design_tags' => [],
                            'field_plan' => [
                                ['field' => 'headline', 'sample' => '文章标题', 'implementation_note' => '主标题直接上屏。'],
                                ['field' => 'supporting_copy', 'sample' => '文章摘要', 'implementation_note' => '摘要区文案。'],
                                ['field' => 'context_detail', 'sample' => '阅读提示', 'implementation_note' => '补充说明。'],
                            ],
                            'execution_script' => [
                                'core_copy' => '文章正文帮助访客快速理解主题并继续阅读。',
                                'feature_points' => ['清晰标题', '分段正文', '继续阅读入口'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $prepared = $service->prepareStageOnePlanScopeForConfirmation($scope);
        $repairedScope = \is_array($prepared['scope'] ?? null) ? $prepared['scope'] : [];
        $repairedPlanJson = \is_array($repairedScope['plan_json'] ?? null) ? $repairedScope['plan_json'] : [];
        $blocks = \is_array($repairedPlanJson['pages'][Page::TYPE_BLOG]['blocks'] ?? null)
            ? $repairedPlanJson['pages'][Page::TYPE_BLOG]['blocks']
            : [];
        self::assertNotSame([], $blocks);
        foreach (AiSiteStageOneContractService::DESIGN_TAG_KEYS as $tagKey) {
            $value = $blocks[0]['design_tags'][$tagKey] ?? null;
            if (\in_array($tagKey, ['visual', 'motion', 'interaction', 'texture', 'responsive'], true)) {
                self::assertIsArray($value);
                self::assertNotEmpty($value, $tagKey);
                continue;
            }
            self::assertIsString($value);
            self::assertNotSame('', \trim($value), $tagKey);
        }

        $missingDesignTagIssues = \array_values(\array_filter(
            \is_array($prepared['stage1_validation']['issues'] ?? null) ? $prepared['stage1_validation']['issues'] : [],
            static fn(array $issue): bool => (string)($issue['code'] ?? '') === 'missing_design_tag'
        ));
        self::assertSame([], $missingDesignTagIssues, \json_encode($prepared['stage1_validation'] ?? [], \JSON_UNESCAPED_UNICODE));
    }
}
