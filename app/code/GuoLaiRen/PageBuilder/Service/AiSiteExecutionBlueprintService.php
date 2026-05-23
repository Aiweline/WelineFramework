<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteExecutionBlueprintService
{
    public const VERSION = 1;
    private const STAGE_ONE_LOCAL_REGEN_MAX_ROUNDS = 3;
    private const STAGE_ONE_LOCAL_REGEN_BATCH_BLOCKS = 5;
    private const STAGE_ONE_PAGE_PLAN_MAX_TOKENS = 14000;
    private const STAGE_ONE_JSON_RETRY_MAX_TOKENS = 16000;
    private const STAGE_ONE_SEGMENTED_PAGE_TARGET_THRESHOLD = 7;
    private const STAGE_ONE_PAGE_SKELETON_MAX_TOKENS = 2600;
    private const STAGE_ONE_PAGE_BLOCK_SEGMENT_MAX_TOKENS = 5200;
    private const STAGE_ONE_PAGE_BLOCK_SEGMENT_SIZE = 1;
    private const TEMPLATE_SCAFFOLD_BRAND_TERMS = [
        'LudoEmpire',
        'PokerArena',
        'Poker Arena',
        'Satta King 786',
        'Satta King',
        'BharatPlay',
        'RummyRoyal',
        'Teen Patti Royal',
    ];
    /** @var array<string, array<string, mixed>|null> */
    private array $appendInstructionDecisionCache = [];
    private ?SourceTruthContractBuilder $sourceTruthContractBuilder = null;
    private ?SourceTruthContractValidator $sourceTruthContractValidator = null;
    private ?AiSiteStageOneContractService $stageOneContractService = null;
    private ?AiSiteStageOnePromptContractRenderer $stageOnePromptContractRenderer = null;
    private ?AiSiteStageOneContractValidator $stageOneContractValidator = null;
    private ?AiSitePageRouteContractService $pageRouteContractService = null;

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
        private readonly ?AiService $aiService = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
        private readonly ?AiSiteSkillRegistry $skillRegistry = null,
        private readonly ?AiSiteReferenceImageInsightService $referenceImageInsightService = null,
    ) {
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
    }

    private function getSkillRegistry(): AiSiteSkillRegistry
    {
        return $this->skillRegistry ?? ObjectManager::getInstance(AiSiteSkillRegistry::class);
    }

    private function getDesignDirectionService(): DesignDirectionService
    {
        return ObjectManager::getInstance(DesignDirectionService::class);
    }

    private function getSourceTruthContractBuilder(): SourceTruthContractBuilder
    {
        if ($this->sourceTruthContractBuilder === null) {
            $this->sourceTruthContractBuilder = ObjectManager::getInstance(SourceTruthContractBuilder::class);
        }

        return $this->sourceTruthContractBuilder;
    }

    private function getSourceTruthContractValidator(): SourceTruthContractValidator
    {
        if ($this->sourceTruthContractValidator === null) {
            $this->sourceTruthContractValidator = ObjectManager::getInstance(SourceTruthContractValidator::class);
        }

        return $this->sourceTruthContractValidator;
    }

    private function getStageOneContractService(): AiSiteStageOneContractService
    {
        if ($this->stageOneContractService === null) {
            $this->stageOneContractService = ObjectManager::getInstance(AiSiteStageOneContractService::class);
        }

        return $this->stageOneContractService;
    }

    private function getStageOnePromptContractRenderer(): AiSiteStageOnePromptContractRenderer
    {
        if ($this->stageOnePromptContractRenderer === null) {
            $this->stageOnePromptContractRenderer = ObjectManager::getInstance(AiSiteStageOnePromptContractRenderer::class);
        }

        return $this->stageOnePromptContractRenderer;
    }

    private function getStageOneContractValidator(): AiSiteStageOneContractValidator
    {
        if ($this->stageOneContractValidator === null) {
            $this->stageOneContractValidator = ObjectManager::getInstance(AiSiteStageOneContractValidator::class);
        }

        return $this->stageOneContractValidator;
    }

    private function getPageRouteContractService(): AiSitePageRouteContractService
    {
        if ($this->pageRouteContractService === null) {
            $this->pageRouteContractService = ObjectManager::getInstance(AiSitePageRouteContractService::class);
        }

        return $this->pageRouteContractService;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $conversation
     */
    public function buildSourceSignature(array $scope, array $conversation = []): string
    {
        $userConversation = [];
        foreach ($conversation as $entry) {
            if (!\is_array($entry) || (string)($entry['role'] ?? '') !== 'user') {
                continue;
            }
            $content = \trim((string)($entry['content'] ?? ''));
            if ($content !== '') {
                $userConversation[] = $content;
            }
        }

        return \sha1((string)\json_encode([
            'site_title' => \trim((string)($scope['site_title'] ?? '')),
            'site_tagline' => \trim((string)($scope['site_tagline'] ?? '')),
            'target_domain' => \strtolower(\trim((string)($scope['target_domain'] ?? ''))),
            'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? '')),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
            'reference_images' => $this->buildReferenceImagePromptList($scope),
            'conversation' => $userConversation,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function resolveStageOneContentLocale(array $scope, string $planLocale = ''): string
    {
        foreach ([
            $scope['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['website_profile']['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $planLocale,
        ] as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $locale = \trim((string)$value);
            if ($locale !== '') {
                return $locale;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string
     * }
     */
    public function buildPlanArtifacts(array $scope, array $websiteProfile, array $payload = []): array
    {
        $pageTypes = $this->expandPageTypes($scope);
        $runtimeInstruction = \trim((string)($payload['instruction'] ?? ''));
        $instruction = $this->isStageOneResumeRepairPayload($payload, $scope) ? '' : $runtimeInstruction;
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $planningScope = \array_replace($scope, [
            'page_types' => $pageTypes,
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
            'plan_instruction' => $instruction,
            'plan_runtime_instruction' => $runtimeInstruction,
            'plan_target_scope' => $targetScope,
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
        ]);
        $sourceTruthContract = $this->getSourceTruthContractBuilder()->build(
            $planningScope,
            $websiteProfile,
            \is_array($planningScope['reference_image_insights'] ?? null) ? $planningScope['reference_image_insights'] : [],
            $instruction,
            $pageTypes,
            $contentLocale
        );
        $sourceTruthValidation = $this->getSourceTruthContractValidator()->validate($sourceTruthContract);
        if (!($sourceTruthValidation['valid'] ?? false)) {
            throw new \RuntimeException('Source truth contract invalid: ' . \implode('; ', \array_map('strval', \is_array($sourceTruthValidation['errors'] ?? null) ? $sourceTruthValidation['errors'] : [])));
        }
        $planningScope['source_truth_contract'] = $sourceTruthContract;
        $planningScope['source_truth_contract_hash'] = \sha1((string)\json_encode($sourceTruthContract, \JSON_UNESCAPED_UNICODE));
        $planningScope['asset_manifest_hash'] = \sha1((string)\json_encode(
            \is_array($planningScope['asset_manifest'] ?? null) ? $planningScope['asset_manifest'] : [],
            \JSON_UNESCAPED_UNICODE
        ));
        $planningScope['stage1_contract'] = $this->getStageOneContractService()->build(
            $planningScope,
            $pageTypes,
            $planLocale,
            $contentLocale,
            'local_plan_artifacts'
        );
        $planningScope['page_route_contract'] = \is_array($planningScope['stage1_contract']['page_route_contract'] ?? null)
            ? $planningScope['stage1_contract']['page_route_contract']
            : [];

        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $planningScope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $planningScope);
        $palette = $this->buildPalettePlan($planningScope, $websiteProfile, $instruction);
        $themeStyle = $this->buildThemeStyle($planningScope, $websiteProfile, $palette, $instruction);
        $navigationPlan = $this->buildNavigationPlan($pageTypes, $contentLocale, $planningScope);
        $footerPlan = $this->buildFooterPlan($pageTypes, $contentLocale, $planningScope);
        $seoStrategy = $this->buildSeoStrategy($siteDisplayName, $planningScope, $pageTypes, $instruction);

        $tasks = [
            $this->buildSharedTask('header', $siteDisplayName, $navigationPlan, $palette, $themeStyle, $seoStrategy, $contentLocale),
            $this->buildSharedTask('footer', $siteDisplayName, $footerPlan, $palette, $themeStyle, $seoStrategy, $contentLocale),
        ];

        $pages = [];
        $pageBlueprints = [];
        foreach ($pageTypes as $pageType) {
            $pageBlueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $planningScope, $websiteProfile);
            $pageBlueprints[$pageType] = $pageBlueprint;
            $pagePlan = $this->buildPagePlan(
                $pageType,
                $pageBlueprint,
                $pageTypes,
                $siteDisplayName,
                $siteSummary,
                $palette,
                $themeStyle,
                $instruction,
                $targetScope,
                $contentLocale
            );
            $pages[$pageType] = $pagePlan;
        }

        $sharedComponents = [
            'header' => $tasks[0],
            'footer' => $tasks[1],
        ];
        $sharedComponents = $this->normalizeStageOneSharedComponents($sharedComponents);
        $tasks = \array_values($sharedComponents);
        $themeContextSnapshot = $this->buildStageOneThemeContextSnapshot(
            $planningScope,
            $websiteProfile,
            $siteDisplayName,
            $siteSummary,
            $palette,
            $themeStyle,
            $navigationPlan,
            $footerPlan,
            $seoStrategy,
            $pageTypes,
            $instruction
        );
        $themeDesignQueueJob = $this->buildStageOneThemeDesignQueueJob($planningScope, $websiteProfile, $themeContextSnapshot, $planLocale);
        $stageOneQueueJobs = $this->upsertStageOneQueueJob([], $themeDesignQueueJob);
        $sharedPromptContext = $this->buildStageOneSharedPromptContext(
            $themeContextSnapshot,
            $sharedComponents,
            $pageTypes,
            $planLocale
        );
        $pagePlans = [];
        $stageOneQueue = $this->buildStageOneHeaderFooterQueueEnvelope(
            $planningScope,
            $websiteProfile,
            $themeContextSnapshot,
            $sharedComponents,
            $sharedPromptContext,
            $pagePlans,
            $pageTypes,
            $planLocale
        );
        $pagePlans = $this->buildStageOnePagePlansConcurrently($pages, $sharedPromptContext);
        $stageOneQueue = $this->buildStageOnePageFanoutQueueEnvelope($stageOneQueue, $pagePlans, $sharedPromptContext, $planLocale);
        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $tasks[] = $this->buildPageTask((string)$pageType, $pagePlan, $block);
            }
        }

        $executionBlueprint = [
            'version' => self::VERSION,
            'build_method' => 'stage1_shared_first_block_plan_v2',
            'workspace_track' => (string)($planningScope['workspace_track'] ?? AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME),
            'content_locale' => $contentLocale,
            'page_types' => $pageTypes,
            'page_blueprints' => $pageBlueprints,
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_prompt_context' => $sharedPromptContext,
            'stage1_queue' => $stageOneQueue,
            'shared_components' => $sharedComponents,
            'pages' => $pages,
            'page_plans' => $pagePlans,
            'block_index' => $blockIndex,
            'tasks' => $tasks,
            'queue_jobs' => $stageOneQueueJobs,
        ];
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $structured = [
            'i18n' => $this->ensurePlanI18nSection([], $planLocale, $this->isEnglishLocale($planLocale)),
            'build_method' => 'stage1_shared_first_block_plan_v2',
            'content_locale' => $contentLocale,
            'request_summary' => $this->buildStageOneRequestSummary($planningScope, $websiteProfile, $instruction),
            'site_strategy' => [
                'site_display_name' => $siteDisplayName,
                'summary' => $siteSummary,
                'theme_style' => $themeStyle,
                'palette' => $palette,
                'instruction' => $instruction,
                'target_scope' => $targetScope,
                'plan_locale' => $planLocale,
                'content_locale' => $contentLocale,
            ],
            'seo_strategy' => $seoStrategy,
            'palette' => $palette,
            'theme_style' => $themeStyle,
            'navigation_plan' => $navigationPlan,
            'footer_plan' => $footerPlan,
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_components' => $sharedComponents,
            'shared_plan' => [
                'theme_design' => $themeContextSnapshot,
                'theme_design_job' => $themeDesignQueueJob,
                'header_block' => $sharedComponents['header'],
                'footer_block' => $sharedComponents['footer'],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
                'shared_prompt_context' => $sharedPromptContext,
            ],
            'stage1_queue' => $stageOneQueue,
            'page_types' => $pageTypes,
            'pages' => $pages,
            'page_plans' => $pagePlans,
            'block_index' => $blockIndex,
            'queue_jobs' => $stageOneQueueJobs,
            'execution_steps' => $this->buildExecutionSteps($tasks),
        ];
        $planJson = $this->buildPlanJson($structured);
        $planJson['content_locale'] = $contentLocale;
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts(
            $planningScope,
            $structured,
            $executionBlueprint,
            $planJson,
            $markdown,
            $planLocale
        );

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'derived_scope_patch' => $this->buildDerivedScopePatch($planningScope, $websiteProfile, $structured, $executionBlueprint),
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
        ];
    }

    /**
     * 真实 AI 流式生成阶段一方案；失败时回退到本地规划器。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @param callable(string):void|null $onChunk
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   ai_generated?:int
     * }
     */
    public function buildPlanArtifactsByAiStream(
        array $scope,
        array $websiteProfile,
        array $payload = [],
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array
    {
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            throw new \RuntimeException((string)__('AI 建站阶段一不允许使用 deterministic/fake 回退方案'));
        }

        return $this->buildPlanArtifactsByStagedAiStream($scope, $websiteProfile, $payload, $onChunk, $onProgress);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @param callable(string):void|null $onChunk
     * @param callable(array<string, mixed>):void|null $onProgress
     * @return array<string, mixed>
     */
    private function buildPlanArtifactsByStagedAiStream(
        array $scope,
        array $websiteProfile,
        array $payload = [],
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array
    {
        $pageTypes = $this->expandPageTypes($scope);
        $runtimeInstruction = \trim((string)($payload['instruction'] ?? ''));
        $instruction = $this->isStageOneResumeRepairPayload($payload, $scope) ? '' : $runtimeInstruction;
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $scope = \array_replace($scope, [
            'content_locale' => $contentLocale,
            'plan_instruction' => $instruction,
            'plan_runtime_instruction' => $runtimeInstruction,
        ]);
        $onScopeCheckpoint = \is_callable($payload['on_stage1_scope_checkpoint'] ?? null) ? $payload['on_stage1_scope_checkpoint'] : null;
        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $scope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope);
        $isResumeRepair = $this->isStageOneResumeRepairPayload($payload, $scope);
        $referenceImages = $this->buildReferenceImagePromptList($scope);
        if (
            $referenceImages !== []
            && !(
                $isResumeRepair
                && \is_array($scope['reference_image_insights'] ?? null)
                && ($scope['reference_image_insights'] ?? []) !== []
            )
        ) {
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '检测到参考图片，正在先执行图片理解并提取风格洞察',
                8,
                'reference_image_understanding',
                'start',
                ['image_total' => \count($referenceImages)]
            );
            $referenceImageInsights = $this->analyzeReferenceImagesByAi($scope, $planLocale, $onChunk);
            if ($referenceImageInsights !== []) {
                $scope['reference_image_insights'] = $referenceImageInsights;
                $referenceImageInsightsSignature = $this->getReferenceImageInsightService()->buildSignature($scope);
                if ($referenceImageInsightsSignature !== '') {
                    $scope['reference_image_insights_signature'] = $referenceImageInsightsSignature;
                }
                $this->persistStageOneScopeCheckpoint(
                    $onScopeCheckpoint,
                    [
                        'reference_image_insights' => $referenceImageInsights,
                        'reference_image_insights_signature' => (string)($scope['reference_image_insights_signature'] ?? ''),
                    ],
                    'reference_image_understanding',
                    $pageTypes
                );
            }
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '参考图片理解完成，继续生成阶段一方案',
                10,
                'reference_image_understanding',
                'done',
                ['image_total' => \count($referenceImages)]
            );
        }

        $sourceTruthContract = $this->getSourceTruthContractBuilder()->build(
            $scope,
            $websiteProfile,
            \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [],
            $instruction,
            $pageTypes,
            $contentLocale
        );
        $sourceTruthValidation = $this->getSourceTruthContractValidator()->validate($sourceTruthContract);
        if (!($sourceTruthValidation['valid'] ?? false)) {
            throw new \RuntimeException('Source truth contract invalid: ' . \implode('; ', \array_map('strval', \is_array($sourceTruthValidation['errors'] ?? null) ? $sourceTruthValidation['errors'] : [])));
        }
        $scope['source_truth_contract'] = $sourceTruthContract;
        $scope['source_truth_contract_hash'] = \sha1((string)\json_encode($sourceTruthContract, \JSON_UNESCAPED_UNICODE));
        $scope['asset_manifest_hash'] = \sha1((string)\json_encode(
            \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [],
            \JSON_UNESCAPED_UNICODE
        ));
        $scope['stage1_contract'] = $this->getStageOneContractService()->build(
            $scope,
            $pageTypes,
            $planLocale,
            $contentLocale,
            'source_truth_ready'
        );
        $scope['page_route_contract'] = \is_array($scope['stage1_contract']['page_route_contract'] ?? null)
            ? $scope['stage1_contract']['page_route_contract']
            : [];

        $oneLineRequirement = $this->resolveStageOneRequirementSeed(
            $scope,
            $websiteProfile,
            $pageTypes,
            $siteDisplayName,
            $siteSummary
        );
        $checkpointSignature = $this->buildStageOneCheckpointSignature($scope, $websiteProfile, $pageTypes, $planLocale, $contentLocale, $instruction, $targetScope, $oneLineRequirement);
        $checkpoint = $this->resolveStageOneCheckpoint($payload, $scope, $checkpointSignature);
        if ($checkpoint !== [] && \trim((string)($checkpoint['signature'] ?? '')) !== '') {
            $checkpointSignature = (string)$checkpoint['signature'];
        }
        $onCheckpoint = \is_callable($payload['on_stage1_checkpoint'] ?? null) ? $payload['on_stage1_checkpoint'] : null;
        $planJson = \is_array($checkpoint['plan_json'] ?? null) ? $checkpoint['plan_json'] : [];
        $stageOneGenerationAttempts = [
            'requirement_expand' => 0,
            'theme_design' => 0,
            'page_fanout' => [],
            'recovery_count' => 0,
            'local_repair_rounds' => 0,
            'retry_from_previous_failure' => $this->extractPlanRetryableFailureItems($scope) !== [] ? 1 : 0,
        ];
        $stageOneAdapterRequestParams = $this->buildStageOneAiAdapterRequestParams($scope, $websiteProfile);

        try {
            if ($this->hasStageOneRequirementCheckpoint($planJson)) {
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    '已复用已保存的需求扩写结果，继续生成阶段一方案',
                    24,
                    'requirement_expand',
                    'checkpoint',
                    ['page_total' => \count($pageTypes)]
                );
            } else {
                $stageOneGenerationAttempts['requirement_expand'] = 1;
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    '正在扩展用户需求，生成阶段一总设计输入',
                    12,
                    'requirement_expand',
                    'start',
                    ['page_total' => \count($pageTypes)]
                );
                $requirementDecoded = $this->generateStageOneJsonByAi(
                    $this->buildAiStageOneRequirementExpansionPrompt($scope, $websiteProfile, $pageTypes, $planLocale, $instruction, $siteDisplayName, $siteSummary),
                    'pagebuilder_plan_generation',
                    2048,
                    150,
                    $onChunk,
                    $stageOneAdapterRequestParams
                );
                $planJson = $this->mergeStageOneRequirementExpansionAiPlanJson($planJson, $requirementDecoded, $oneLineRequirement, $pageTypes);
                $this->assertStageOneRequirementExpansionIsGenerated($planJson);
                $this->persistStageOneCheckpoint($onCheckpoint, $checkpointSignature, $planJson, 'requirement_expand', $pageTypes);
            }
            $this->emitStageOnePipelineProgress(
                $onProgress,
                $this->hasStageOneThemeCheckpoint($planJson) ? '已复用已保存的总主题设计，继续生成页面总设计' : '需求扩写已完成，正在生成总主题设计',
                28,
                'theme_design',
                $this->hasStageOneThemeCheckpoint($planJson) ? 'checkpoint' : 'start',
                ['page_total' => \count($pageTypes)]
            );

            if (!$this->hasStageOneThemeCheckpoint($planJson)) {
                $stageOneGenerationAttempts['theme_design'] = 1;
                $themeDecoded = $this->generateStageOneJsonByAi(
                    $this->buildAiStageOneThemePrompt(
                        $scope,
                        $websiteProfile,
                        $pageTypes,
                        $planLocale,
                        $contentLocale,
                        $instruction,
                        $siteDisplayName,
                        $siteSummary,
                        \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : []
                    ),
                    'pagebuilder_plan_generation',
                    3072,
                    150,
                    $onChunk,
                    $stageOneAdapterRequestParams
                );
                $planJson = $this->mergeStageOneThemeAiPlanJson($planJson, $themeDecoded, $planLocale, $pageTypes, $scope);
                $planJson['content_locale'] = $contentLocale;
                $planJson = $this->materializeStageOneThemeContract(
                    $planJson,
                    $pageTypes,
                    $planLocale,
                    $contentLocale,
                    'theme_design',
                    $scope
                );
                $this->assertStageOneThemePlanIsGenerated($planJson);
                $this->persistStageOneCheckpoint($onCheckpoint, $checkpointSignature, $planJson, 'theme_design', $pageTypes);
            }
            $planJson = $this->materializeStageOneThemeContract(
                $planJson,
                $pageTypes,
                $planLocale,
                $contentLocale,
                'theme_design',
                $scope
            );
            $this->assertStageOneThemePlanIsGenerated($planJson);
            $this->persistStageOneScopeCheckpoint(
                $onScopeCheckpoint,
                [
                    'stage1_contract' => \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [],
                    'theme_context_snapshot' => \is_array($planJson['theme_context_snapshot'] ?? null) ? $planJson['theme_context_snapshot'] : [],
                    'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
                    'shared_prompt_context' => \is_array($planJson['shared_prompt_context'] ?? null) ? $planJson['shared_prompt_context'] : [],
                ],
                'theme_design',
                $pageTypes
            );
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '总主题设计已完成，正在整理共享 Header/Footer 规划',
                44,
                'header_footer',
                'start',
                ['page_total' => \count($pageTypes)]
            );
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '共享 Header/Footer 规划已就绪，正在并发生成各页面总设计',
                60,
                'page_fanout',
                'start',
                ['page_total' => \count($pageTypes)]
            );
            $existingPagePlans = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
            $stageOneContract = \is_array($planJson['stage1_contract'] ?? null)
                ? $planJson['stage1_contract']
                : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
            $pendingPageTypes = \array_values(\array_filter(
                $pageTypes,
                fn(string $pageType): bool => !$this->hasStageOnePagePlanCheckpoint($existingPagePlans, $pageType, $stageOneContract, $scope)
                    || !$this->stageOnePagePlanPassesStrictValidation($existingPagePlans, $pageType, $stageOneContract, $scope)
            ));
            if ($isResumeRepair) {
                $resumePageTypes = $this->resolveStageOneResumePageTypes(
                    $scope,
                    $pageTypes,
                    $existingPagePlans,
                    $stageOneContract
                );
                if ($resumePageTypes !== []) {
                    $pendingPageTypes = \array_values(\array_filter(
                        $pendingPageTypes,
                        static fn(string $pageType): bool => \in_array($pageType, $resumePageTypes, true)
                    ));
                }
            }
            if ($pendingPageTypes === []) {
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    '已复用已保存的页面总设计，正在装配阶段一总设计',
                    84,
                    'page_fanout',
                    'checkpoint',
                    ['page_total' => \count($pageTypes)]
                );
            }
            $pageFanoutFailures = [];
            $pagePlans = $pendingPageTypes === [] ? [] : $this->generateStageOnePagePlansByAi(
                $scope,
                $websiteProfile,
                $planJson,
                $pendingPageTypes,
                $planLocale,
                $contentLocale,
                $instruction,
                $targetScope,
                $onChunk,
                $onProgress,
                $pageFanoutFailures,
                $stageOneGenerationAttempts,
                $onCheckpoint,
                $checkpointSignature,
                $pageTypes
            );
            if ($pagePlans !== []) {
                $planJson['pages'] = \array_replace(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [], $pagePlans);
            }
            if ($pageFanoutFailures !== []) {
                $retryPageTypes = \array_values(\array_keys($pageFanoutFailures));
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    'Retrying Stage-1 failed pages with issue-specific contract instructions: ' . \implode(', ', $retryPageTypes),
                    84,
                    'page_fanout',
                    'retry',
                    ['page_total' => \count($retryPageTypes), 'page_types' => $retryPageTypes]
                );
                $retryScope = $scope;
                $retryScope['retryable_ai_failures']['plan']['items'] = $pageFanoutFailures;
                $retryFanoutFailures = [];
                $retryPagePlans = $this->generateStageOnePagePlansByAi(
                    $retryScope,
                    $websiteProfile,
                    $planJson,
                    $retryPageTypes,
                    $planLocale,
                    $contentLocale,
                    $instruction,
                    $targetScope,
                    $onChunk,
                    $onProgress,
                    $retryFanoutFailures,
                    $stageOneGenerationAttempts,
                    $onCheckpoint,
                    $checkpointSignature,
                    $pageTypes
                );
                if ($retryPagePlans !== []) {
                    $planJson['pages'] = \array_replace(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [], $retryPagePlans);
                }
                $pageFanoutFailures = $retryFanoutFailures;
            }
            $this->persistStageOneCheckpoint($onCheckpoint, $checkpointSignature, $planJson, 'page_fanout', $pageTypes, $pageFanoutFailures);

            $this->emitStageOnePipelineProgress(
                $onProgress,
                '各页面总设计已完成，正在装配阶段一总设计',
                88,
                'plan_assemble',
                'start',
                ['page_total' => \count($pageTypes)]
            );
            $validationPageTypes = $pageFanoutFailures === []
                ? $pageTypes
                : \array_values(\array_filter(
                    $pageTypes,
                    fn(string $pageType): bool => $this->hasStageOnePagePlanCheckpoint(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [], $pageType, $stageOneContract, $scope)
                ));
            $artifacts = $this->mapAiPlanToArtifacts(
                $scope,
                $websiteProfile,
                ['plan_json' => $planJson],
                $pageTypes,
                $planLocale,
                $instruction,
                $targetScope,
                $validationPageTypes,
                $pageFanoutFailures !== [],
                $onProgress,
                $stageOneGenerationAttempts
            );
            $artifactRetryableFailures = [];
            foreach (\is_array($artifacts['retryable_ai_failures'] ?? null) ? $artifacts['retryable_ai_failures'] : [] as $failure) {
                if (!\is_array($failure)) {
                    continue;
                }
                $failureKey = \trim((string)($failure['page_type'] ?? $failure['item_key'] ?? ''));
                $artifactRetryableFailures[$failureKey !== '' ? $failureKey : ('failure_' . \count($artifactRetryableFailures))] = $failure;
            }
            $combinedRetryableFailures = \array_replace($artifactRetryableFailures, $pageFanoutFailures);
            $artifacts['ai_generated'] = 1;
            $artifacts['generation_source'] = 'ai_staged';
            $artifacts['retryable_ai_failures'] = \array_values($combinedRetryableFailures);
            $artifacts['partial_retry_required'] = $combinedRetryableFailures !== [] ? 1 : 0;
            $this->persistStageOneCheckpoint($onCheckpoint, $checkpointSignature, $planJson, 'plan_assemble', $pageTypes, $combinedRetryableFailures);
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '阶段一总设计装配完成，正在写入方案草案',
                92,
                'plan_assemble',
                'done',
                ['page_total' => \count($pageTypes)]
            );
            return $artifacts;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                $this->normalizeAiPlanGenerationErrorMessage($throwable->getMessage()),
                (int)$throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * @param list<string> $pageTypes
     */
    private function buildStageOneCheckpointSignature(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        string $oneLineRequirement
    ): string {
        return \sha1((string)\json_encode([
            'site_title' => \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')),
            'brief' => $oneLineRequirement,
            'page_types' => \array_values($pageTypes),
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'reference_image_insights_signature' => (string)($scope['reference_image_insights_signature'] ?? ''),
            'source_truth_contract_hash' => (string)($scope['source_truth_contract_hash'] ?? ''),
            'asset_manifest_hash' => (string)($scope['asset_manifest_hash'] ?? ''),
            'contract_schema_version' => 'source_truth_v1',
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveStageOneCheckpoint(array $payload, array $scope, string $signature): array
    {
        if ($this->isStageOneRebuildPayload($payload, $scope)) {
            return [];
        }

        $checkpoint = \is_array($payload['stage1_checkpoint'] ?? null)
            ? $payload['stage1_checkpoint']
            : (\is_array($scope['_plan_generation_checkpoint'] ?? null) ? $scope['_plan_generation_checkpoint'] : []);
        if ($checkpoint === []) {
            $persistedPlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
            if ($persistedPlanJson !== [] && $this->checkpointHasResumableStageOneProgress(['plan_json' => $persistedPlanJson])) {
                return [
                    'signature' => $signature,
                    'step' => 'persisted_plan_json',
                    'plan_json' => $persistedPlanJson,
                    'page_types' => \is_array($scope['page_types'] ?? null) ? \array_values($scope['page_types']) : [],
                    'updated_at' => (string)($scope['plan_generated_at'] ?? \date('Y-m-d H:i:s')),
                ];
            }

            return [];
        }
        $storedSignature = \trim((string)($checkpoint['signature'] ?? ''));
        if ($storedSignature === $signature) {
            return $checkpoint;
        }
        if ($this->isStageOneResumeRepairPayload($payload, $scope) && $this->checkpointHasResumableStageOneProgress($checkpoint)) {
            return $checkpoint;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $scope
     */
    private function isStageOneRebuildPayload(array $payload, array $scope): bool
    {
        $promptMode = \strtolower(\trim((string)($payload['prompt_mode'] ?? '')));
        if ($promptMode === 'rebuild') {
            return true;
        }
        if ((int)($payload['_force_rebuild'] ?? 0) === 1 || (int)($scope['_force_rebuild'] ?? 0) === 1) {
            return true;
        }
        $planSseRequest = \is_array($scope['_plan_sse_request'] ?? null) ? $scope['_plan_sse_request'] : [];

        return \strtolower(\trim((string)($planSseRequest['prompt_mode'] ?? ''))) === 'rebuild';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $scope
     */
    private function isStageOneResumeRepairPayload(array $payload, array $scope): bool
    {
        $promptMode = \strtolower(\trim((string)($payload['prompt_mode'] ?? '')));
        if ($promptMode === 'resume_plan') {
            return true;
        }
        if ((int)($payload['resume_failed_tasks'] ?? 0) === 1) {
            return true;
        }
        $scopePromptMode = \strtolower(\trim((string)($scope['plan_last_prompt_mode'] ?? '')));
        if ($scopePromptMode === 'resume_plan') {
            return true;
        }
        $planSseRequest = \is_array($scope['_plan_sse_request'] ?? null) ? $scope['_plan_sse_request'] : [];

        return \strtolower(\trim((string)($planSseRequest['prompt_mode'] ?? ''))) === 'resume_plan';
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    private function checkpointHasResumableStageOneProgress(array $checkpoint): bool
    {
        $planJson = \is_array($checkpoint['plan_json'] ?? null) ? $checkpoint['plan_json'] : [];
        if ($planJson === []) {
            return false;
        }
        if ($this->hasStageOneRequirementCheckpoint($planJson) || $this->hasStageOneThemeCheckpoint($planJson)) {
            return true;
        }
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];

        return $pages !== [];
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $existingPagePlans
     * @param array<string, mixed> $stageOneContract
     * @return list<string>
     */
    private function resolveStageOneResumePageTypes(
        array $scope,
        array $pageTypes,
        array $existingPagePlans,
        array $stageOneContract
    ): array {
        $failedPageTypes = [];
        foreach ($this->extractPlanRetryableFailureItems($scope) as $itemKey => $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $retryScope = \trim((string)($failure['retry_scope'] ?? ''));
            if (!\in_array($retryScope, ['stage1_page', 'page_fanout'], true)) {
                continue;
            }
            $pageType = \trim((string)($failure['page_type'] ?? $failure['item_key'] ?? (\is_string($itemKey) ? $itemKey : '')));
            if ($pageType !== '' && !\in_array($pageType, $failedPageTypes, true)) {
                $failedPageTypes[] = $pageType;
            }
        }
        $missing = [];
        foreach ($pageTypes as $pageType) {
            if (
                !$this->hasStageOnePagePlanCheckpoint($existingPagePlans, $pageType, $stageOneContract, $scope)
                || !$this->stageOnePagePlanPassesStrictValidation($existingPagePlans, $pageType, $stageOneContract, $scope)
            ) {
                $missing[] = $pageType;
            }
        }

        return \array_values(\array_unique(\array_merge($failedPageTypes, $missing)));
    }

    private function stageOnePagePlanPassesStrictValidation(array $pagePlans, string $pageType, array $contract, array $scope): bool
    {
        $page = \is_array($pagePlans[$pageType] ?? null) ? $pagePlans[$pageType] : [];
        if ($page === []) {
            return false;
        }

        $report = $this->getStageOneContractValidator()->validatePagePlan(
            $pageType,
            $page,
            $contract,
            [
                'generation_attempts' => ['resume_checkpoint_validation' => 1],
                'recovery_count' => 0,
                'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? '')),
                'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
            ]
        );

        return !empty($report['passed']);
    }

    private function hasStageOneRequirementCheckpoint(array $planJson): bool
    {
        try {
            $this->assertStageOneRequirementExpansionIsGenerated($planJson);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasStageOneThemeCheckpoint(array $planJson): bool
    {
        try {
            $this->assertStageOneThemePlanIsGenerated($planJson);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function materializeStageOneThemeContract(
        array $planJson,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $step,
        array $scope = []
    ): array {
        $themeDesign = $this->extractStageOneThemeDesign(
            \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : []
        );
        if ($themeDesign === []) {
            return $planJson;
        }

        $themeContextSnapshot = [
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
            'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
            'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
            'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
            'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
            'page_type_overviews' => \is_array($planJson['page_type_overviews'] ?? null) ? $planJson['page_type_overviews'] : [],
        ];
        $themeContextSnapshot = $this->mergeStageOneThemeDesignIntoSnapshot($themeContextSnapshot, $themeDesign);

        $sharedComponents = $this->normalizeStageOneSharedComponents(
            \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : []
        );
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $sharedPromptContext = $this->buildStageOneSharedPromptContext(
            $themeContextSnapshot,
            $sharedComponents,
            $pageTypes,
            $planLocale,
            $requirementExpansion
        );

        $ruleContract = $this->getStageOneContractService()->normalize(
            \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []),
            $scope,
            $pageTypes,
            $planLocale,
            $contentLocale,
            $step
        );
        $stageOneContract = \array_replace($ruleContract, [
            'step' => $step,
            'status' => 'theme_archived',
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'page_types' => \array_values($pageTypes),
            'requirement_expansion' => $requirementExpansion,
            'theme_design' => $themeDesign,
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_components' => $sharedComponents,
            'shared_prompt_context' => $sharedPromptContext,
            'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
            'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
            'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
            'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
            'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
            'archived_at' => \date('Y-m-d H:i:s'),
        ]);
        $stageOneContract['rules_contract'] = $ruleContract;
        $stageOneContract['contract_hash'] = (string)($ruleContract['contract_hash'] ?? '');
        $stageOneContract['signature'] = \sha1((string)\json_encode($stageOneContract, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        $planJson['theme_design'] = $themeDesign;
        $planJson['theme_context_snapshot'] = $themeContextSnapshot;
        $planJson['shared_components'] = $sharedComponents;
        $planJson['shared_prompt_context'] = $sharedPromptContext;
        $planJson['stage1_contract'] = $stageOneContract;

        return $planJson;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{min:int, max:int, target:int, required:list<string>, optional:list<string>}
     */
    private function resolveStageOneBlockBudget(string $pageType, array $scope): array
    {
        return $this->getStageOneContractService()->resolveBlockBudget($pageType, $scope);
    }

    /**
     * Mirror the PHP stage-one gate inside prompts so the model optimizes for
     * the same contract that will be enforced after generation.
     *
     * @param list<string> $pageTypes
     * @return list<string>
     */
    private function buildStageOneGatePromptLines(array $pageTypes, array $scope, string $gateScope = 'full', ?string $singlePageType = null): array
    {
        $targetPageTypes = $singlePageType !== null && $singlePageType !== ''
            ? [$singlePageType]
            : \array_values(\array_filter(\array_map('strval', $pageTypes), static fn(string $value): bool => $value !== ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $contract = $this->getStageOneContractService()->normalize(
            \is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : [],
            $scope,
            $targetPageTypes,
            $planLocale,
            $contentLocale,
            $gateScope
        );

        return match ($gateScope) {
            'requirement' => $this->getStageOnePromptContractRenderer()->renderRequirementBriefing($contract),
            'theme' => $this->getStageOnePromptContractRenderer()->renderThemeContract($contract),
            'page' => $this->getStageOnePromptContractRenderer()->renderPageContract($contract, (string)($targetPageTypes[0] ?? $singlePageType ?? '')),
            default => $this->getStageOnePromptContractRenderer()->renderFullContract($contract),
        };
    }

    /**
     * @param array<string, mixed> $pagePlans
     */
    private function hasStageOnePagePlanCheckpoint(array $pagePlans, string $pageType, array $contract = [], array $scope = []): bool
    {
        $page = \is_array($pagePlans[$pageType] ?? null) ? $pagePlans[$pageType] : [];
        if ($page === []) {
            return false;
        }
        $contract = $this->getStageOneContractService()->normalize(
            $contract,
            $scope,
            [$pageType],
            \trim((string)($scope['plan_locale'] ?? '')),
            $this->resolveStageOneContentLocale($scope),
            'page_checkpoint'
        );
        $report = $this->getStageOneContractValidator()->validatePagePlan(
            $pageType,
            $page,
            $contract,
            [
                'generation_attempts' => ['page_checkpoint' => 1],
                'recovery_count' => 0,
                'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? '')),
                'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
            ]
        );

        return !empty($report['passed']);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function hasCompleteStageOneDesignTags(array $block): bool
    {
        $designTags = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
        if ($designTags === []) {
            return false;
        }
        foreach (['visual', 'motion', 'interaction', 'texture', 'responsive'] as $key) {
            if (!\is_array($designTags[$key] ?? null) || ($designTags[$key] ?? []) === []) {
                return false;
            }
        }

        return \trim((string)($designTags['color_layering'] ?? '')) !== ''
            && \trim((string)($designTags['implementation_note'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $pageContract
     */
    private function hasCompleteStageOneVisualSignature(array $block, array $pageContract): bool
    {
        $signature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
        if ($signature === []) {
            return false;
        }
        $requiredKeys = \is_array($pageContract['visual_signature_keys'] ?? null)
            ? $pageContract['visual_signature_keys']
            : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS;
        foreach ($requiredKeys as $key) {
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            if (\trim((string)($signature[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function hasCompleteStageOneImageIntent(array $block): bool
    {
        $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
        if ($intent === [] || !\array_key_exists('needs_image', $intent)) {
            return false;
        }
        if (!\is_bool($intent['needs_image'])) {
            return false;
        }
        $needsImage = (bool)$intent['needs_image'];
        foreach (['image_role', 'placement', 'reuse_policy'] as $key) {
            if (\trim((string)($intent[$key] ?? '')) === '') {
                return false;
            }
        }
        $mediaStrategy = \trim((string)($block['visual_signature']['media_strategy'] ?? ''));
        if ($needsImage) {
            return \trim((string)($intent['image_subject'] ?? '')) !== ''
                && !\str_contains($mediaStrategy, 'CSS-only/no generated image');
        }

        return \trim((string)($intent['css_motif'] ?? '')) !== ''
            && \trim((string)($intent['visual_atmosphere'] ?? '')) !== ''
            && \trim((string)($intent['image_treatment'] ?? '')) !== ''
            && \str_starts_with($mediaStrategy, 'CSS-only/no generated image');
    }

    /**
     * @param callable(array<string, mixed>):void|null $onCheckpoint
     * @param list<string> $pageTypes
     */
    private function persistStageOneCheckpoint(
        ?callable $onCheckpoint,
        string $signature,
        array $planJson,
        string $step,
        array $pageTypes,
        array $retryableFailures = []
    ): void
    {
        if ($onCheckpoint === null || $signature === '' || $planJson === []) {
            return;
        }

        $onCheckpoint([
            'signature' => $signature,
            'step' => $step,
            'plan_json' => $planJson,
            'page_types' => \array_values($pageTypes),
            'retryable_ai_failures' => \array_values($retryableFailures),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param callable(array<string, mixed>):void|null $onCheckpoint
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $pagePlan
     * @param list<string> $pageTypes
     */
    private function persistStageOnePageCheckpoint(
        ?callable $onCheckpoint,
        string $signature,
        array $planJson,
        string $pageType,
        array $pagePlan,
        array $pageTypes
    ): void {
        if ($onCheckpoint === null || $signature === '' || $pageType === '' || $pagePlan === []) {
            return;
        }

        $checkpointPlanJson = $planJson;
        $checkpointPlanJson['pages'] = \array_replace(
            \is_array($checkpointPlanJson['pages'] ?? null) ? $checkpointPlanJson['pages'] : [],
            [$pageType => $pagePlan]
        );

        $onCheckpoint([
            'signature' => $signature,
            'step' => 'page_plan',
            'completed_task' => 'page_plan:' . $pageType,
            'plan_json' => $checkpointPlanJson,
            'page_types' => \array_values($pageTypes),
            'page_checkpoint' => [
                'page_type' => $pageType,
                'status' => 'passed',
                'updated_at' => \date('Y-m-d H:i:s'),
            ],
            'merge_plan_json' => 1,
            'retryable_ai_failures' => [],
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param callable(array<string, mixed>):void|null $onScopeCheckpoint
     * @param array<string, mixed> $scopePatch
     * @param list<string> $pageTypes
     */
    private function persistStageOneScopeCheckpoint(
        ?callable $onScopeCheckpoint,
        array $scopePatch,
        string $step,
        array $pageTypes
    ): void
    {
        if ($onScopeCheckpoint === null || $scopePatch === []) {
            return;
        }

        $scopePatch['plan_generation_progress'] = [
            'step' => $step,
            'page_types' => \array_values($pageTypes),
            'failed_count' => 0,
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $onScopeCheckpoint($scopePatch);
    }

    /**
     * @param callable(array<string, mixed>):void|null $onProgress
     * @param array<string, mixed> $context
     */
    private function emitStageOnePipelineProgress(
        ?callable $onProgress,
        string $message,
        int $progressPercent,
        string $step,
        string $phase,
        array $context = []
    ): void {
        if ($onProgress === null || $message === '') {
            return;
        }

        $payload = \array_replace([
            'message' => $message,
            'progress_percent' => \max(0, \min(99, $progressPercent)),
            'progress_kind' => 'queue_info',
            'stage1_step' => $step,
            'stage1_phase' => $phase,
            'token_usage' => [
                'input_tokens' => null,
                'output_tokens' => null,
                'total_tokens' => null,
            ],
        ], $context);

        $onProgress($payload);
    }

    private function buildStageOnePagePipelineMessage(bool $completed, int $current, int $totalPages, string $pageKey): string
    {
        $prefix = $completed ? '页面总设计已生成' : '正在生成页面总设计';

        return $prefix . '（' . $current . '/' . $totalPages . '）：' . $pageKey;
    }

    /**
     * @param callable(string):void|null $onChunk
     * @param array<string, mixed> $requestParamOverrides
     * @return array<string, mixed>
     */
    private function generateStageOneJsonByAi(
        string $prompt,
        string $scenarioCode,
        int $maxTokens,
        int $timeout,
        ?callable $onChunk = null,
        array $requestParamOverrides = []
    ): array {
        AiSiteWorkflowTrace::prompt('stage1_ai_generation_call', $prompt, [
            'scenario_code' => $scenarioCode,
            'max_tokens' => $maxTokens,
            'timeout' => $timeout,
        ]);
        $attemptPrompt = $this->prependStageOneJsonOnlyGuard($prompt);
        $fullContent = '';
        $requestParams = \array_merge([
            'allow_zero_balance_provider' => true,
            'temperature' => 0.15,
            'max_tokens' => $maxTokens,
            'timeout' => \max(60, $timeout),
            'disable_ai_timeout' => false,
            'disable_cli_timeout' => false,
            'enforce_timeout_in_stream' => true,
            'response_format' => ['type' => 'json_object'],
        ], $requestParamOverrides);
        $requestParams = $this->sanitizeStageOneJsonRequestParams($requestParams);

        $streamThrowable = null;
        try {
            $this->getAiService()->generateStream(
                $attemptPrompt,
                static function (string $chunk) use (&$fullContent, $onChunk): bool {
                    $fullContent .= $chunk;
                    if (\is_callable($onChunk) && $chunk !== '') {
                        $onChunk($chunk);
                    }
                    return true;
                },
                null,
                $scenarioCode,
                null,
                $requestParams
            );
        } catch (\Throwable $throwable) {
            $streamThrowable = $throwable;
            if (!$this->isEmptyAiStreamCompletionFailure($throwable) && $fullContent === '') {
                throw $throwable;
            }
        }

        $decoded = $this->getResponseJsonParser()->extractAndDecode($fullContent);
        if (\is_array($decoded)) {
            return $decoded;
        }

        $invalidPreview = $this->buildSafeAiJsonDiagnosticSnippet($fullContent, 500);
        $retryParams = $this->buildStageOneJsonRetryRequestParams($requestParams);
        $retryContent = '';
        try {
            $this->getAiService()->generateStream(
                $this->buildStageOneJsonRecoveryPrompt(
                    $prompt,
                    $streamThrowable instanceof \Throwable
                        ? 'previous streaming attempt failed before a complete JSON object was available: ' . $streamThrowable->getMessage()
                        : 'previous streaming response was invalid or truncated JSON. Invalid preview: ' . $invalidPreview
                ),
                static function (string $chunk) use (&$retryContent, $onChunk): bool {
                    $retryContent .= $chunk;
                    if (\is_callable($onChunk) && $chunk !== '') {
                        $onChunk($chunk);
                    }
                    return true;
                },
                null,
                $scenarioCode,
                null,
                $retryParams
            );
            $decoded = $this->getResponseJsonParser()->extractAndDecode($retryContent);
            if (\is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $retryThrowable) {
            if ($streamThrowable === null) {
                $streamThrowable = $retryThrowable;
            }
        }

        $recoveryContent = '';
        try {
            $recoveryContent = $this->generateStageOneJsonByAiRecovery(
                $prompt,
                $scenarioCode,
                $retryParams,
                'previous streaming responses were invalid or truncated JSON. Invalid preview: ' . $invalidPreview
            );
            $decoded = $this->getResponseJsonParser()->extractAndDecode($recoveryContent);
            if (\is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $recoveryThrowable) {
            if ($streamThrowable === null) {
                $streamThrowable = $recoveryThrowable;
            }
        }

        throw new \RuntimeException(
            $this->buildInvalidStageOneJsonDiagnostic($fullContent, $retryContent, $recoveryContent),
            0,
            $streamThrowable
        );
    }

    /**
     * @param array<string, mixed> $requestParams
     */
    private function generateStageOneJsonByAiRecovery(
        string $prompt,
        string $scenarioCode,
        array $requestParams,
        string $reason
    ): string {
        return (string)$this->getAiService()->generate(
            $this->buildStageOneJsonRecoveryPrompt($prompt, $reason),
            null,
            $scenarioCode,
            null,
            $requestParams
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildStageOneAiAdapterRequestParams(array $scope, array $websiteProfile): array
    {
        $designDirectionState = $this->getDesignDirectionService()->buildWorkspaceDirectionState($scope);
        $designDirectionSnapshot = \is_array($designDirectionState['snapshot'] ?? null)
            ? $designDirectionState['snapshot']
            : [];
        $selectedCodes = $this->getSkillRegistry()->resolveSelectedSkillCodes(
            $this->normalizeStageOneSelectedSkillCodes($scope[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] ?? [])
        );
        $siteTitle = \trim((string)(
            $scope['site_title']
            ?? $websiteProfile['site_title']
            ?? ($scope['website_profile']['site_title'] ?? '')
        ));
        $briefDescription = \trim((string)(
            $scope['brief_description']
            ?? $scope['user_description']
            ?? $websiteProfile['brief_description']
            ?? ($scope['website_profile']['brief_description'] ?? '')
        ));
        $adminId = \max(0, (int)(
            $scope['admin_user_id']
            ?? $scope['admin_id']
            ?? $websiteProfile['admin_user_id']
            ?? $websiteProfile['admin_id']
            ?? 0
        ));

        return [
            'admin_user_id' => $adminId,
            'site_title' => $siteTitle,
            'brief_description' => $briefDescription,
            'selected_skill_codes' => $selectedCodes,
            'design_direction_mode' => (string)($designDirectionState['mode'] ?? ($scope['design_direction_mode'] ?? 'auto')),
            'design_direction_code' => (string)($designDirectionState['code'] ?? ($scope['design_direction_code'] ?? '')),
            'design_direction_snapshot' => $designDirectionSnapshot,
            'design_direction_version' => (int)($designDirectionState['version'] ?? ($scope['design_direction_version'] ?? 0)),
            'design_direction_hash' => (string)($designDirectionState['hash'] ?? ($scope['design_direction_hash'] ?? '')),
            'design_direction_match_reason' => (string)($designDirectionState['match_reason'] ?? ($scope['design_direction_match_reason'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $requestParams
     * @return array<string, mixed>
     */
    private function buildStageOneJsonRetryRequestParams(array $requestParams): array
    {
        $currentMaxTokens = \max(512, (int)($requestParams['max_tokens'] ?? 0));
        $requestParams['max_tokens'] = \min(
            self::STAGE_ONE_JSON_RETRY_MAX_TOKENS,
            \max($currentMaxTokens * 2, $currentMaxTokens + 1800)
        );
        $requestParams['temperature'] = 0.1;
        $requestParams['timeout'] = \max(90, (int)($requestParams['timeout'] ?? 0));
        $requestParams['disable_ai_timeout'] = false;
        $requestParams['disable_cli_timeout'] = false;
        $requestParams['enforce_timeout_in_stream'] = true;
        $requestParams['response_format'] = ['type' => 'json_object'];

        return $this->sanitizeStageOneJsonRequestParams($requestParams);
    }

    private function buildStageOneJsonRecoveryPrompt(string $prompt, string $reason): string
    {
        return $this->prependStageOneJsonOnlyGuard(
            "RECOVERY MODE: {$reason}. Return one complete final JSON object immediately. "
            . "Prefer compact strings over long prose. Do not stream partial JSON, do not add markdown, and do not explain. "
            . "Preserve the contract shape while repairing JSON: do not delete pages, blocks, required_block_keys, field_plan rows, visual_signature, image_intent, page_design_plan, or execution_script.core_copy to make the JSON shorter. "
            . "Never replace required values with schema placeholders such as string, sentence, TODO, none, or empty strings.\n\n"
            . $prompt
        );
    }

    private function buildInvalidStageOneJsonDiagnostic(string $primaryContent, string $retryContent, string $recoveryContent): string
    {
        $diagnostic = [
            'primary_len' => \strlen($primaryContent),
            'retry_len' => \strlen($retryContent),
            'recovery_len' => \strlen($recoveryContent),
            'primary_tail' => $this->buildSafeAiJsonDiagnosticTail($primaryContent, 220),
            'retry_tail' => $this->buildSafeAiJsonDiagnosticTail($retryContent, 220),
            'recovery_tail' => $this->buildSafeAiJsonDiagnosticTail($recoveryContent, 220),
        ];

        return 'invalid ai json: '
            . $this->buildSafeAiJsonDiagnosticSnippet($primaryContent, 200)
            . ' [debug='
            . (string)\json_encode(
                $diagnostic,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR
            )
            . ']';
    }

    private function buildSafeAiJsonDiagnosticSnippet(string $content, int $maxChars): string
    {
        $content = $this->normalizeUtf8DiagnosticText((string)\preg_replace('/\s+/', ' ', $content));
        if ($content === '' || $maxChars <= 0) {
            return '';
        }

        return (string)\mb_substr($content, 0, $maxChars, 'UTF-8');
    }

    private function buildSafeAiJsonDiagnosticTail(string $content, int $maxChars): string
    {
        $content = $this->normalizeUtf8DiagnosticText($content);
        if ($content === '' || $maxChars <= 0) {
            return '';
        }

        return (string)\mb_substr($content, -$maxChars, null, 'UTF-8');
    }

    private function normalizeUtf8DiagnosticText(string $content): string
    {
        if ($content === '' || \preg_match('//u', $content)) {
            return $content;
        }

        $converted = \function_exists('iconv') ? @\iconv('UTF-8', 'UTF-8//IGNORE', $content) : false;
        if (\is_string($converted) && \preg_match('//u', $converted)) {
            return $converted;
        }
        if (\function_exists('mb_convert_encoding')) {
            $converted = @\mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            if (\is_string($converted) && \preg_match('//u', $converted)) {
                return $converted;
            }
        }

        return (string)\preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $content);
    }

    private function isEmptyAiStreamCompletionFailure(\Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof \Throwable; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (\str_contains($message, '流式生成完成但未返回任何内容')
                || \str_contains($message, '流式生成完成但未返回任何正文')
                || \str_contains($message, 'streaming completed without final content')
            ) {
                return true;
            }
        }

        return false;
    }

    private function prependStageOneJsonOnlyGuard(string $prompt): string
    {
        $guard = [
            'CRITICAL OUTPUT CONTRACT FOR STRUCTURED JSON:',
            '- You may think internally, but final output must contain only one JSON object and nothing else.',
            '- Do not output reasoning_content, analysis, comments, markdown, code fences, or prose outside the final JSON object.',
            '- The first character of the final answer MUST be `{` and the last character MUST be `}`.',
            '- Return exactly one valid JSON object matching the requested schema. No trailing text.',
            '- Output minified JSON: no pretty-print indentation, no decorative line breaks, and no repeated whitespace outside strings.',
            '- Prefer compact strings and short arrays. Do not produce huge narrative paragraphs inside JSON values.',
            '- Escape all quotes inside string values; never use unescaped newlines in JSON strings.',
            '- Keep every string single-line; do not include literal tabs or control characters.',
            '- Prefer Chinese punctuation or parentheses instead of quoted phrases inside values. Keep CSS/unit/style notes terse and plain-text so the JSON parser receives valid JSON.',
        ];

        return \implode("\n", $guard) . "\n\n" . $prompt;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeStageOneJsonRequestParams(array $params): array
    {
        // 阶段一结构化 JSON 任务：强制禁用 thinking，避免只返回 reasoning_content。
        $params['thinking'] = ['type' => 'disabled'];
        $params['thinking_mode'] = 'disabled';
        $params['enable_thinking'] = false;
        $params['enable_reasoning'] = false;

        unset(
            $params['reasoning_effort'],
            $params['thinking_budget'],
            $params['thinking_budget_tokens']
        );

        return $params;
    }

    private function resolveStageOneCooperativeSessionId(array $scope, string $scopeKey): string
    {
        foreach ([$scope['public_id'] ?? null, $scope['session_public_id'] ?? null, $scope['session_id'] ?? null] as $candidate) {
            $sessionId = \trim((string)$candidate);
            if ($sessionId !== '') {
                return $sessionId . ':stage1:' . $scopeKey;
            }
        }

        return '';
    }

    /**
     * @param list<string> $pageTypes
     */
    private function resolveStageOneRequirementSeed(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $siteDisplayName,
        string $siteSummary
    ): string {
        foreach ([
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
            $websiteProfile['brief_description'] ?? null,
            $siteSummary,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $siteName = \trim((string)(
            $siteDisplayName
            ?: ($scope['site_title'] ?? $websiteProfile['site_title'] ?? $scope['website_profile']['site_title'] ?? '')
        ));
        $pageText = \implode(', ', \array_values(\array_filter(\array_map('strval', $pageTypes))));

        if ($siteName !== '' && $pageText !== '') {
            return 'Create a complete website plan for ' . $siteName . ' covering: ' . $pageText . '.';
        }
        if ($siteName !== '') {
            return 'Create a complete website plan for ' . $siteName . '.';
        }
        if ($pageText !== '') {
            return 'Create a complete website plan covering: ' . $pageText . '.';
        }

        return 'Create a complete website plan with clear positioning, navigation, content strategy, and conversion goals.';
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $decoded
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function mergeStageOneRequirementExpansionAiPlanJson(array $planJson, array $decoded, string $oneLineRequirement, array $pageTypes): array
    {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        $source = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : $decoded;
        $candidate = \is_array($source['requirement_expansion'] ?? null)
            ? $source['requirement_expansion']
            : (\is_array($source['expanded_requirement_plan'] ?? null) ? $source['expanded_requirement_plan'] : []);

        $planJson['requirement_expansion'] = $this->normalizeStageOneRequirementExpansion($candidate, $oneLineRequirement, $pageTypes);
        $planJson = $this->syncStageOneOverviewFieldsFromRequirementExpansion($planJson);

        return $planJson;
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $decoded
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function mergeStageOneThemeAiPlanJson(
        array $planJson,
        array $decoded,
        string $planLocale,
        array $pageTypes,
        array $scope
    ): array
    {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        $source = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : $decoded;
        if (!\is_array($source['shared_components'] ?? null) && \is_array($source['shared_blocks'] ?? null)) {
            $sharedComponentsFromBlocks = [];
            foreach ($source['shared_blocks'] as $sharedBlock) {
                if (!\is_array($sharedBlock)) {
                    continue;
                }
                $component = \trim((string)($sharedBlock['component'] ?? ''));
                if ($component === '') {
                    $blockKey = \trim((string)($sharedBlock['block_key'] ?? $sharedBlock['task_key'] ?? ''));
                    if (\str_starts_with($blockKey, 'shared:')) {
                        $component = \substr($blockKey, \strlen('shared:'));
                    }
                }
                $component = \trim($component);
                if (!\in_array($component, ['header', 'footer'], true)) {
                    continue;
                }
                if (!isset($sharedBlock['implementation_detail']) && isset($sharedBlock['implementation_note'])) {
                    $sharedBlock['implementation_detail'] = $sharedBlock['implementation_note'];
                }
                $sharedComponentsFromBlocks[$component] = $sharedBlock;
            }
            if ($sharedComponentsFromBlocks !== []) {
                $source['shared_components'] = $sharedComponentsFromBlocks;
            }
        }
        foreach (['i18n', 'site_strategy', 'theme_style', 'palette', 'theme_design', 'page_type_overviews', 'navigation_plan', 'footer_plan', 'shared_components', 'seo_strategy'] as $key) {
            if (\is_array($source[$key] ?? null)) {
                $planJson[$key] = $source[$key];
            }
        }
        if (!\is_array($planJson['theme_design'] ?? null)) {
            $sharedPlan = \is_array($source['shared_plan'] ?? null) ? $source['shared_plan'] : [];
            $themeDesignFallback = \is_array($sharedPlan['theme_design'] ?? null)
                ? $sharedPlan['theme_design']
                : (\is_array($sharedPlan['theme_context_snapshot'] ?? null) ? $sharedPlan['theme_context_snapshot'] : []);
            if ($themeDesignFallback !== []) {
                $planJson['theme_design'] = $themeDesignFallback;
            }
        }
        if (!\is_array($planJson['shared_components'] ?? null)) {
            $sharedPlan = \is_array($source['shared_plan'] ?? null) ? $source['shared_plan'] : [];
            $sharedComponents = [];
            if (\is_array($sharedPlan['header_block'] ?? null)) {
                $sharedComponents['header'] = $sharedPlan['header_block'];
            }
            if (\is_array($sharedPlan['footer_block'] ?? null)) {
                $sharedComponents['footer'] = $sharedPlan['footer_block'];
            }
            if ($sharedComponents !== []) {
                $planJson['shared_components'] = $sharedComponents;
            }
        }
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $planJson['page_types'] = $pageTypes;
        $planJson['content_locale'] = $contentLocale;
        $planJson['i18n'] = $this->ensurePlanI18nSection(
            \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [],
            $planLocale,
            $this->isEnglishLocale($planLocale)
        );
        $planJson['page_type_overviews'] = $this->buildStageOnePageTypeOverviewContext(
            \is_array($planJson['page_type_overviews'] ?? null) ? $planJson['page_type_overviews'] : [],
            $pageTypes,
            \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
            \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : []
        );
        $planJson = $this->syncStageOneOverviewFieldsFromRequirementExpansion($planJson);
        $planJson = $this->ensureStageOneNavigationAndFooterPlans($planJson, $pageTypes, $contentLocale, $scope);
        $planJson['seo_strategy'] = $this->ensureStageOneThemeSeoStrategy($planJson, $scope, $pageTypes);
        $planJson['shared_components'] = $this->ensureStageOneThemeSharedComponents($planJson, $scope, $planLocale);
        if (\is_array($planJson['theme_design'] ?? null)) {
            $planJson['theme_design'] = $this->repairAiStageOneThemeSelectionReasonBeforeValidation(
                $planJson['theme_design'],
                \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $scope['website_profile']['brief_description'] ?? '')),
                $planLocale
            );
        }
        return $planJson;
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function ensureStageOneNavigationAndFooterPlans(array $planJson, array $pageTypes, string $contentLocale, array $scope = []): array
    {
        $contractPageTypes = $this->resolveStageOneStableContractPageTypes($planJson, $scope, $pageTypes);
        if ($contractPageTypes === []) {
            $contractPageTypes = $pageTypes;
        }
        $routeContract = $this->resolveStageOnePageRouteContract($contractPageTypes, $contentLocale, $scope);
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $navigationPlan['header_items'] = $this->sanitizeStageOneRouteLinkRows(
            $navigationPlan['header_items'] ?? [],
            $routeContract,
            'navigation_plan.header_items',
            $this->buildNavigationPlan($pageTypes, $contentLocale, $scope)['header_items'] ?? []
        );
        $planJson['navigation_plan'] = $navigationPlan;

        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $fallbackFooterPlan = $this->buildFooterPlan($pageTypes, $contentLocale, $scope);
        $footerPlan['featured'] = $this->sanitizeStageOneRouteLinkRows(
            $footerPlan['featured'] ?? [],
            $routeContract,
            'footer_plan.featured',
            $fallbackFooterPlan['featured'] ?? []
        );
        $footerPlan['policies'] = $this->sanitizeStageOneRouteLinkRows(
            $footerPlan['policies'] ?? [],
            $routeContract,
            'footer_plan.policies',
            $fallbackFooterPlan['policies'] ?? []
        );
        $planJson['footer_plan'] = $footerPlan;

        return $planJson;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterStageOneLinkRows(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        return \array_values(\array_filter($items, static function ($item): bool {
            return \is_array($item)
                && \trim((string)($item['label'] ?? '')) !== ''
                && \trim((string)($item['href'] ?? '')) !== '';
        }));
    }

    /**
     * @param array<string, mixed> $routeContract
     * @param mixed $items
     * @param mixed $fallbackItems
     * @return list<array<string, mixed>>
     */
    private function sanitizeStageOneRouteLinkRows(mixed $items, array $routeContract, string $fieldPath, mixed $fallbackItems = []): array
    {
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        $allowedPaths = [];
        foreach ($this->routeTypesForStageOneLinkGroup($routeContract, $fieldPath) as $pageType) {
            $path = \is_array($routesByType[$pageType] ?? null) ? \trim((string)($routesByType[$pageType]['path'] ?? '')) : '';
            if ($path !== '') {
                $allowedPaths[$path] = $path;
            }
        }
        if ($allowedPaths === []) {
            $allowedPaths = $this->getPageRouteContractService()->allowedPathMap($routeContract);
        }

        $normalized = [];
        $seen = [];
        foreach ($this->filterStageOneLinkRows($items) as $item) {
            $label = \trim((string)($item['label'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            $type = \trim((string)($item['type'] ?? ''));
            $path = $type !== '' && \is_array($routesByType[$type] ?? null)
                ? \trim((string)($routesByType[$type]['path'] ?? ''))
                : $this->getPageRouteContractService()->normalizeHrefToContractPath($routeContract, $href);
            if ($path === '' || ($allowedPaths !== [] && !isset($allowedPaths[$path])) || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $row = $item;
            $row['label'] = $label;
            $row['href'] = $path;
            if ($type === '') {
                foreach ($routesByType as $routeType => $route) {
                    if (\is_array($route) && \trim((string)($route['path'] ?? '')) === $path) {
                        $row['type'] = (string)$routeType;
                        break;
                    }
                }
            }
            $normalized[] = $row;
        }

        if ($normalized !== [] && $this->stageOneRouteLinkRowsPassContract($normalized, $routeContract, $fieldPath)) {
            return $normalized;
        }

        $fallback = $this->filterStageOneLinkRows($fallbackItems);
        if ($fallback !== [] && $this->stageOneRouteLinkRowsPassContract($fallback, $routeContract, $fieldPath)) {
            return $fallback;
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $routeContract
     */
    private function stageOneRouteLinkRowsPassContract(array $items, array $routeContract, string $fieldPath): bool
    {
        $allowedPaths = [];
        foreach ($this->routeTypesForStageOneLinkGroup($routeContract, $fieldPath) as $pageType) {
            $path = \trim((string)($this->getPageRouteContractService()->routesByType($routeContract)[$pageType]['path'] ?? ''));
            if ($path !== '') {
                $allowedPaths[$path] = $path;
            }
        }
        if ($allowedPaths === []) {
            $allowedPaths = $this->getPageRouteContractService()->allowedPathMap($routeContract);
        }
        if ($allowedPaths === []) {
            return $items !== [];
        }

        foreach ($items as $item) {
            if (!\is_array($item)) {
                return false;
            }
            $href = \trim((string)($item['href'] ?? ''));
            $path = $this->getPageRouteContractService()->normalizeHrefToContractPath($routeContract, $href);
            if ($path === '') {
                $path = $this->getPageRouteContractService()->normalizeHrefPath($href);
            }
            if ($path === '' || !isset($allowedPaths[$path])) {
                return false;
            }
        }

        return $items !== [];
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function ensureStageOneThemeSharedComponents(array $planJson, array $scope, string $planLocale): array
    {
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $siteDisplayName = \trim((string)($siteStrategy['site_display_name'] ?? $scope['site_title'] ?? $scope['website_profile']['site_title'] ?? ''));
        $palette = \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [];
        $themeStyle = \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [];
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);

        $fallback = [
            'header' => $this->buildSharedTask('header', $siteDisplayName, $navigationPlan, $palette, $themeStyle, $seoStrategy, $contentLocale),
            'footer' => $this->buildSharedTask('footer', $siteDisplayName, $footerPlan, $palette, $themeStyle, $seoStrategy, $contentLocale),
        ];

        $sharedComponents = \is_array($planJson['shared_components'] ?? null)
            ? $this->normalizeStageOneSharedComponents($planJson['shared_components'])
            : [];

        foreach ($fallback as $component => $defaultPlan) {
            $sharedComponents[$component] = \array_replace(
                $defaultPlan,
                \is_array($sharedComponents[$component] ?? null) ? $sharedComponents[$component] : [],
                ['component' => $component]
            );
        }

        return $this->normalizeStageOneSharedComponents($sharedComponents);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function ensureStageOneThemeSeoStrategy(array $planJson, array $scope, array $pageTypes): array
    {
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $siteDisplayName = \trim((string)($siteStrategy['site_display_name'] ?? $scope['site_title'] ?? $scope['website_profile']['site_title'] ?? ''));
        $fallback = $this->buildSeoStrategy($siteDisplayName, $scope, $pageTypes);
        $candidate = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $merged = \array_replace($fallback, $candidate);
        if (!\is_array($merged['primary_keywords'] ?? null) || $merged['primary_keywords'] === []) {
            $merged['primary_keywords'] = \is_array($fallback['primary_keywords'] ?? null) ? $fallback['primary_keywords'] : [];
        }
        if (!\is_array($merged['keyword_page_map'] ?? null) || $merged['keyword_page_map'] === []) {
            $merged['keyword_page_map'] = \is_array($fallback['keyword_page_map'] ?? null)
                ? $fallback['keyword_page_map']
                : $this->buildSeoKeywordPageMap(
                    \is_array($merged['primary_keywords'] ?? null) ? $merged['primary_keywords'] : [],
                    $pageTypes
                );
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function normalizeStageOneRequirementExpansion(array $candidate, string $oneLineRequirement, array $pageTypes): array
    {
        $expandedBrief = \trim((string)($candidate['expanded_brief'] ?? $candidate['expanded_description'] ?? $candidate['planning_summary'] ?? ''));
        $planningSummary = \trim((string)($candidate['planning_summary'] ?? $candidate['site_planning_summary'] ?? $expandedBrief));
        $siteGoal = \trim((string)($candidate['site_goal'] ?? $candidate['core_goal'] ?? ''));
        $conversionStrategy = \trim((string)($candidate['conversion_strategy'] ?? $candidate['conversion_direction'] ?? ''));
        $contentDirection = \trim((string)($candidate['content_direction'] ?? $candidate['content_strategy'] ?? ''));
        $businessGoals = $this->normalizeStringList($candidate['business_goals'] ?? $candidate['goals'] ?? []);
        $primaryCta = \trim((string)($candidate['primary_cta'] ?? $candidate['main_cta'] ?? $candidate['cta'] ?? ''));

        if ($expandedBrief === '' && $oneLineRequirement !== '') {
            $expandedBrief = $oneLineRequirement;
        }
        if ($planningSummary === '') {
            $planningSummary = $expandedBrief;
        }

        $pageStrategy = [];
        foreach (\is_array($candidate['page_strategy'] ?? null) ? $candidate['page_strategy'] : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $pageType = \trim((string)($row['page_type'] ?? $row['page'] ?? ''));
            if ($pageType === '') {
                continue;
            }
            $pageStrategy[] = [
                'page_type' => $pageType,
                'intent' => \trim((string)($row['intent'] ?? $row['page_intent'] ?? '')),
                'content_focus' => \trim((string)($row['content_focus'] ?? $row['focus'] ?? '')),
                'conversion_role' => \trim((string)($row['conversion_role'] ?? '')),
            ];
        }

        if ($pageStrategy === []) {
            foreach ($pageTypes as $pageType) {
                $pageStrategy[] = [
                    'page_type' => (string)$pageType,
                    'intent' => (string)$pageType,
                    'content_focus' => $planningSummary,
                    'conversion_role' => $conversionStrategy,
                ];
            }
        }

        return [
            'original_brief' => $oneLineRequirement,
            'expanded_brief' => $expandedBrief,
            'planning_summary' => $planningSummary,
            'site_goal' => $siteGoal !== '' ? $siteGoal : $planningSummary,
            'business_goals' => $businessGoals !== [] ? $businessGoals : [$siteGoal !== '' ? $siteGoal : $planningSummary],
            'target_users' => $this->normalizeStringList($candidate['target_users'] ?? []),
            'business_context' => \trim((string)($candidate['business_context'] ?? $candidate['market_context'] ?? '')),
            'content_direction' => $contentDirection,
            'conversion_strategy' => $conversionStrategy,
            'primary_cta' => $primaryCta,
            'page_strategy' => $pageStrategy,
            'technical_direction' => $this->normalizeStringList($candidate['technical_direction'] ?? $candidate['technical_notes'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function syncStageOneOverviewFieldsFromRequirementExpansion(array $planJson): array
    {
        $expansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        if ($expansion === []) {
            return $planJson;
        }

        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $expandedBrief = $this->firstNonEmptyString([
            $planJson['overview_expanded_brief'] ?? null,
            $expansion['expanded_brief'] ?? null,
            $expansion['planning_summary'] ?? null,
            $siteStrategy['summary'] ?? null,
            $expansion['original_brief'] ?? null,
        ]);
        if ($expandedBrief !== '') {
            $planJson['overview_expanded_brief'] = $expandedBrief;
        }

        $businessGoals = $this->normalizeStringList($planJson['overview_business_goals'] ?? []);
        if ($businessGoals === []) {
            $businessGoals = $this->normalizeStringList($expansion['business_goals'] ?? []);
        }
        foreach ([$expansion['site_goal'] ?? null, $siteStrategy['core_goal'] ?? null] as $candidate) {
            $goal = \trim((string)$candidate);
            if ($goal !== '' && !\in_array($goal, $businessGoals, true)) {
                $businessGoals[] = $goal;
            }
        }
        if ($businessGoals !== []) {
            $planJson['overview_business_goals'] = \array_values($businessGoals);
        }

        $contentFocus = $this->firstNonEmptyString([
            $planJson['overview_content_focus'] ?? null,
            $expansion['content_direction'] ?? null,
            $siteStrategy['content_strategy'] ?? null,
            $expansion['planning_summary'] ?? null,
        ]);
        if ($contentFocus === '' && \is_array($expansion['page_strategy'] ?? null)) {
            $focusItems = [];
            foreach ($expansion['page_strategy'] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $focus = \trim((string)($row['content_focus'] ?? ''));
                if ($focus !== '') {
                    $focusItems[$focus] = $focus;
                }
            }
            $contentFocus = \implode("\n", \array_values($focusItems));
        }
        if ($contentFocus !== '') {
            $planJson['overview_content_focus'] = $contentFocus;
        }

        $primaryCta = $this->firstNonEmptyString([
            $expansion['primary_cta'] ?? null,
            $siteStrategy['primary_cta'] ?? null,
        ]);
        $domainStrategy = $this->firstNonEmptyString([
            $planJson['overview_domain_strategy'] ?? null,
            $expansion['conversion_strategy'] ?? null,
            $siteStrategy['conversion_path'] ?? null,
        ]);
        if ($primaryCta !== '' && $domainStrategy !== '' && \stripos($domainStrategy, $primaryCta) === false) {
            $domainStrategy .= "\nCTA: " . $primaryCta;
        } elseif ($domainStrategy === '' && $primaryCta !== '') {
            $domainStrategy = 'CTA: ' . $primaryCta;
        }
        if ($domainStrategy !== '') {
            $planJson['overview_domain_strategy'] = $domainStrategy;
        }

        return $planJson;
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function assertStageOneRequirementExpansionIsGenerated(array $planJson): void
    {
        $expansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        foreach (['original_brief', 'expanded_brief', 'planning_summary', 'site_goal'] as $key) {
            if (\trim((string)($expansion[$key] ?? '')) === '') {
                throw new \RuntimeException('第一阶段方案生成失败：生成主题规划前必须先完成需求扩展。');
            }
        }
        if (!\is_array($expansion['page_strategy'] ?? null) || $expansion['page_strategy'] === []) {
            throw new \RuntimeException('第一阶段方案生成失败：生成主题规划前必须先完成需求扩展。');
        }
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function assertStageOneThemePlanIsGenerated(array $planJson): void
    {
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];

        $requiredStrings = [
            $themeDesign['theme_purpose'] ?? null,
            $themeDesign['selection_reason'] ?? null,
            $colorScheme['primary'] ?? null,
            $colorScheme['accent'] ?? null,
            $typography['font_family'] ?? null,
            $typography['spacing_scale'] ?? null,
        ];

        foreach ($requiredStrings as $value) {
            if (!\is_string($value) || \trim($value) === '') {
                throw new \RuntimeException('第一阶段方案生成失败：生成页面方案前必须先完成主题方案。');
            }
        }

        if (!\is_array($themeDesign['visual_keywords'] ?? null) || $themeDesign['visual_keywords'] === []) {
            throw new \RuntimeException('第一阶段方案生成失败：生成页面方案前必须先完成主题方案。');
        }
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $seoRequiredStrings = [
            $seoStrategy['core_intent'] ?? null,
            $seoStrategy['content_strategy'] ?? null,
            $seoStrategy['internal_linking'] ?? null,
            $seoStrategy['url_structure'] ?? null,
        ];
        foreach ($seoRequiredStrings as $value) {
            if (!\is_string($value) || \trim($value) === '') {
                throw new \RuntimeException('第一阶段方案生成失败：生成页面方案前必须先完成 SEO 策略合同。');
            }
        }
        if (!\is_array($seoStrategy['primary_keywords'] ?? null) || $seoStrategy['primary_keywords'] === []) {
            throw new \RuntimeException('第一阶段方案生成失败：生成页面方案前必须先完成 SEO 策略关键词。');
        }
        if (!\is_array($seoStrategy['keyword_page_map'] ?? null) || $seoStrategy['keyword_page_map'] === []) {
            throw new \RuntimeException('第一阶段方案生成失败：生成页面方案前必须先完成 SEO 页面映射。');
        }
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        foreach (['header', 'footer'] as $component) {
            $componentPlan = \is_array($sharedComponents[$component] ?? null) ? $sharedComponents[$component] : [];
            $goal = \trim((string)($componentPlan['goal'] ?? ''));
            $implementationDetail = \trim((string)($componentPlan['implementation_detail'] ?? $componentPlan['implementation_note'] ?? ''));
            if ($goal === '' || $implementationDetail === '') {
                $sharedKeys = \array_values(\array_filter(\array_map(
                    static fn($key): string => \is_string($key) ? \trim($key) : '',
                    \array_keys($sharedComponents)
                ), static fn(string $key): bool => $key !== ''));
                throw new \RuntimeException(
                    '第一阶段方案生成失败：生成页面方案前必须先完成主题/Header/Footer 方案。'
                    . ' component=' . $component
                    . ' shared_keys=' . ($sharedKeys === [] ? '[none]' : \implode(',', $sharedKeys))
                    . ' goal=' . ($goal === '' ? '0' : '1')
                    . ' implementation=' . ($implementationDetail === '' ? '0' : '1')
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     * @param callable(string):void|null $onChunk
     * @param callable(array<string, mixed>):void|null $onProgress
     * @param callable(array<string, mixed>):void|null $onCheckpoint
     * @param list<string> $checkpointPageTypes
     * @return array<string, mixed>
     */
    private function generateStageOnePagePlansByAi(
        array $scope,
        array $websiteProfile,
        array $planJson,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        ?callable $onChunk = null,
        ?callable $onProgress = null,
        ?array &$retryableFailures = null,
        ?array &$generationAttempts = null,
        ?callable $onCheckpoint = null,
        string $checkpointSignature = '',
        array $checkpointPageTypes = []
    ): array {
        if ($retryableFailures === null) {
            $retryableFailures = [];
        }
        if ($generationAttempts === null) {
            $generationAttempts = ['page_fanout' => [], 'recovery_count' => 0];
        }

        $totalPages = \count($pageTypes);
        $checkpointPageTypes = $checkpointPageTypes === []
            ? \array_values($pageTypes)
            : \array_values($checkpointPageTypes);
        $prebuiltResults = [];
        $existingPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $stageOneContract = \is_array($planJson['stage1_contract'] ?? null)
            ? $planJson['stage1_contract']
            : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
        foreach ($pageTypes as $pageType) {
            $pageKey = (string)$pageType;
            if ($this->hasStageOneRetryablePageFailure($scope, $pageKey)) {
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    'Previous page AI failure will be retried in this stage-one run: ' . $pageKey,
                    82,
                    'page_plan',
                    'retry',
                    ['page_type' => $pageKey]
                );
                continue;
            }

            $existingPage = \is_array($existingPages[$pageKey] ?? null) ? $existingPages[$pageKey] : [];
            if ($existingPage === [] || !$this->hasStageOnePagePlanCheckpoint([$pageKey => $existingPage], $pageKey, $stageOneContract, $scope)) {
                continue;
            }

            $prebuiltResults[$pageKey] = $existingPage;
            $generationAttempts['page_fanout'][$pageKey] = [
                'primary' => 0,
                'recovery' => 0,
                'status' => 'checkpoint',
            ];
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '已复用已保存的页面总设计，跳过 AI 生成：' . $pageKey,
                60,
                'page_plan',
                'checkpoint',
                ['page_type' => $pageKey]
            );
        }

        $pageTypes = \array_values(\array_filter(
            $pageTypes,
            static fn(string $pageType): bool => !\array_key_exists($pageType, $prebuiltResults)
        ));
        if ($pageTypes === []) {
            return $prebuiltResults;
        }

        $concurrency = \max(1, \count($pageTypes));
        $ai = $this->getAiService();
        $adapterRequestParams = $this->buildStageOneAiAdapterRequestParams($scope, $websiteProfile);

        /** @var array<string, callable(array<string, mixed>, string|int): array<string, mixed>> $tasks */
        $tasks = [];
        $completedPages = \count($prebuiltResults);
        foreach ($pageTypes as $pageIndex => $pageType) {
            $pageKey = (string)$pageType;
            $pageOrder = (int)$pageIndex + 1;
            $this->emitStageOnePipelineProgress(
                $onProgress,
                $this->buildStageOnePagePipelineMessage(false, $pageOrder, $totalPages, $pageKey),
                60,
                'page_plan',
                'start',
                ['page_type' => $pageKey]
            );
            $generationAttempts['page_fanout'][$pageKey] = [
                'primary' => 1,
                'recovery' => 0,
                'status' => 'pending',
            ];
            $tasks[$pageKey] = function (array $sessionParams) use ($scope, $websiteProfile, $planJson, $pageKey, $planLocale, $contentLocale, $instruction, $targetScope, $onProgress, &$completedPages, $totalPages, &$generationAttempts, $adapterRequestParams, $onCheckpoint, $checkpointSignature, $checkpointPageTypes): array {
                $baselinePage = \is_array($planJson['pages'][$pageKey] ?? null) ? $planJson['pages'][$pageKey] : [];
                $requestParams = \array_merge($adapterRequestParams, $sessionParams);
                $useSegmentedPagePlan = $this->shouldUseSegmentedStageOnePagePlan($pageKey, $scope);
                $priorRetryInstruction = $this->buildStageOneRetryablePageFailureInstruction($scope, $pageKey);
                if ($useSegmentedPagePlan) {
                    $pagePlan = $this->generateStageOneSegmentedPagePlanByAi(
                        $scope,
                        $websiteProfile,
                        $planJson,
                        $pageKey,
                        $planLocale,
                        $contentLocale,
                        $instruction,
                        $targetScope,
                        $requestParams,
                        null,
                        $onProgress,
                        $priorRetryInstruction
                    );
                } else {
                    $decoded = $this->generateStageOneJsonByAi(
                        $this->buildAiStageOnePagePrompt($scope, $websiteProfile, $planJson, $pageKey, $planLocale, $contentLocale, $instruction, $targetScope),
                        'pagebuilder_plan_generation',
                        self::STAGE_ONE_PAGE_PLAN_MAX_TOKENS,
                        240,
                        null,
                        $requestParams
                    );
                    $pagePlan = $this->extractStageOneAiPagePlan($decoded, $pageKey);
                }
                $pagePlan = $this->normalizeStageOneAiPagePlanWithBaseline(
                    $pagePlan,
                    $baselinePage,
                    $pageKey
                );
                $stageOneContract = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
                $pagePlan = $this->finalizeStageOneAiPagePlanForCheckpoint(
                    $pagePlan,
                    $pageKey,
                    $stageOneContract,
                    $scope,
                    $planLocale,
                    $contentLocale
                );
                $pageValidationReport = $this->getStageOneContractValidator()->validatePagePlan(
                    $pageKey,
                    $pagePlan,
                    $stageOneContract,
                    [
                        'generation_attempts' => ['page_primary' => 1],
                        'recovery_count' => 0,
                        'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? '')),
                        'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                        'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
                    ]
                );
                $pageCheckpointPassed = $this->hasStageOnePagePlanCheckpoint([$pageKey => $pagePlan], $pageKey, $stageOneContract, $scope)
                    && !empty($pageValidationReport['passed']);
                if (!$pageCheckpointPassed) {
                    $primaryValidationIssues = \is_array($pageValidationReport['issues'] ?? null) ? $pageValidationReport['issues'] : [];
                    $generationAttempts['page_fanout'][$pageKey]['primary_issues'] = \array_slice(
                        $primaryValidationIssues,
                        0,
                        12
                    );
                    $generationAttempts['page_fanout'][$pageKey]['primary_failure_summary'] = $this->summarizeStageOneValidationReport($pageValidationReport);
                    $generationAttempts['page_fanout'][$pageKey]['recovery'] = 1;
                    $generationAttempts['recovery_count'] = \max(0, (int)($generationAttempts['recovery_count'] ?? 0)) + 1;
                    $this->emitStageOnePipelineProgress(
                        $onProgress,
                        'Page plan contract failed; retrying strict recovery for page: ' . $pageKey . ' issues=' . $generationAttempts['page_fanout'][$pageKey]['primary_failure_summary'],
                        78,
                        'page_plan',
                        'retry',
                        ['page_type' => $pageKey]
                    );
                    if ($useSegmentedPagePlan) {
                        $segmentedRecoveryInstruction = 'Previous segmented page plan failed Stage-1 page validation: '
                            . $generationAttempts['page_fanout'][$pageKey]['primary_failure_summary'];
                        $issueRules = $this->buildStageOneIssueSpecificRecoveryRules($primaryValidationIssues);
                        if ($issueRules !== []) {
                            $segmentedRecoveryInstruction .= ' ' . \implode(' ', $issueRules);
                        }
                        $pagePlan = $this->generateStageOneSegmentedPagePlanByAi(
                            $scope,
                            $websiteProfile,
                            $planJson,
                            $pageKey,
                            $planLocale,
                            $contentLocale,
                            $instruction,
                            $targetScope,
                            $requestParams,
                            null,
                            $onProgress,
                            $this->clipText($segmentedRecoveryInstruction, 1600)
                        );
                    } else {
                        $decoded = $this->generateStageOneJsonByAi(
                            $this->buildAiStageOnePageRecoveryPrompt(
                                $scope,
                                $websiteProfile,
                                $planJson,
                                $pageKey,
                                $planLocale,
                                $contentLocale,
                                $instruction,
                                $targetScope,
                                'Previous response failed Stage-1 page validation: ' . $generationAttempts['page_fanout'][$pageKey]['primary_failure_summary'],
                                $pagePlan
                            ),
                            'pagebuilder_plan_generation',
                            self::STAGE_ONE_PAGE_PLAN_MAX_TOKENS,
                            240,
                            null,
                            $requestParams
                        );
                        $pagePlan = $this->extractStageOneAiPagePlan($decoded, $pageKey);
                    }
                    $pagePlan = $this->normalizeStageOneAiPagePlanWithBaseline(
                        $pagePlan,
                        $baselinePage,
                        $pageKey
                    );
                    $pagePlan = $this->finalizeStageOneAiPagePlanForCheckpoint(
                        $pagePlan,
                        $pageKey,
                        $stageOneContract,
                        $scope,
                        $planLocale,
                        $contentLocale
                    );
                }
                $completedPages++;
                $finalPageValidationReport = $this->getStageOneContractValidator()->validatePagePlan(
                    $pageKey,
                    $pagePlan,
                    $stageOneContract,
                    [
                        'generation_attempts' => ['page_final' => 1],
                        'recovery_count' => (int)($generationAttempts['page_fanout'][$pageKey]['recovery'] ?? 0),
                        'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? '')),
                        'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                        'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
                    ]
                );
                $finalCheckpointPassed = $this->hasStageOnePagePlanCheckpoint([$pageKey => $pagePlan], $pageKey, $stageOneContract, $scope)
                    && !empty($finalPageValidationReport['passed']);
                if (!$finalCheckpointPassed) {
                    $generationAttempts['page_fanout'][$pageKey]['final_issues'] = \array_slice(
                        \is_array($finalPageValidationReport['issues'] ?? null) ? $finalPageValidationReport['issues'] : [],
                        0,
                        12
                    );
                    $generationAttempts['page_fanout'][$pageKey]['final_failure_summary'] = $this->summarizeStageOneValidationReport($finalPageValidationReport);
                    $generationAttempts['page_fanout'][$pageKey]['ai_response_summary'] = $this->summarizeStageOneAiPagePlanForFailureDiagnostics($pagePlan, $pageKey);
                }
                $generationAttempts['page_fanout'][$pageKey]['status'] = $finalCheckpointPassed ? 'passed' : 'failed';
                if ($finalCheckpointPassed) {
                    $this->persistStageOnePageCheckpoint(
                        $onCheckpoint,
                        $checkpointSignature,
                        $planJson,
                        $pageKey,
                        $pagePlan,
                        $checkpointPageTypes
                    );
                }
                $pageProgress = 60 + (int)\floor(($completedPages / \max(1, $totalPages)) * 22);
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    $finalCheckpointPassed
                        ? $this->buildStageOnePagePipelineMessage(true, $completedPages, $totalPages, $pageKey)
                        : 'Page plan generated but failed Stage-1 contract checkpoint and is waiting for retry: ' . $pageKey,
                    $pageProgress,
                    'page_plan',
                    $finalCheckpointPassed ? 'done' : 'failed',
                    ['page_type' => $pageKey]
                );

                return $pagePlan;
            };
        }

        $settled = $ai->runCooperativeSessionTasksSettled($tasks, [
            'concurrency' => $concurrency,
            'session_id' => $this->resolveStageOneCooperativeSessionId($scope, 'page_plan'),
            'disable_conversation_history' => true,
            'disable_conversation_persist' => true,
        ]);

        $results = $prebuiltResults;
        foreach ($pageTypes as $pageType) {
            $pageKey = (string)$pageType;
            $entry = \is_array($settled[$pageKey] ?? null) ? $settled[$pageKey] : [];
            $fanoutAttempt = \is_array($generationAttempts['page_fanout'][$pageKey] ?? null)
                ? $generationAttempts['page_fanout'][$pageKey]
                : [];
            if (($entry['status'] ?? '') === 'fulfilled' && \is_array($entry['result'] ?? null) && $entry['result'] !== []) {
                $result = $entry['result'];
                $stageOneContract = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
                if ($this->hasStageOnePagePlanCheckpoint([$pageKey => $result], $pageKey, $stageOneContract, $scope)) {
                    $results[$pageKey] = $result;
                    $generationAttempts['page_fanout'][$pageKey]['status'] = 'passed';
                    continue;
                }

                if (!isset($fanoutAttempt['ai_response_summary']) || \trim((string)$fanoutAttempt['ai_response_summary']) === '') {
                    $generationAttempts['page_fanout'][$pageKey]['ai_response_summary'] = $this->summarizeStageOneAiPagePlanForFailureDiagnostics($result, $pageKey);
                    $fanoutAttempt = $generationAttempts['page_fanout'][$pageKey];
                }
                $message = 'Stage-one page fanout returned a page plan without usable blocks.';
                $retryableFailures[$pageKey] = $this->buildStageOneRetryablePageFailureFromFanoutAttempt($pageKey, $message, $fanoutAttempt);
                $generationAttempts['page_fanout'][$pageKey]['status'] = 'retryable_failure';
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    'Page plan generation returned no usable blocks and is waiting for retry: ' . $pageKey,
                    82,
                    'page_plan',
                    'failed',
                    ['page_type' => $pageKey, 'error_message' => $retryableFailures[$pageKey]['message']]
                );
                continue;
            }

            $throwable = ($entry['error'] ?? null) instanceof \Throwable
                ? $entry['error']
                : new \RuntimeException('Stage-one page fanout task did not return a usable page plan.');
            $message = \trim($throwable->getMessage());
            $retryableFailures[$pageKey] = $this->buildStageOneRetryablePageFailureFromFanoutAttempt(
                $pageKey,
                $message !== '' ? $message : $throwable::class,
                $fanoutAttempt
            );
            $generationAttempts['page_fanout'][$pageKey]['status'] = 'error';
            $this->emitStageOnePipelineProgress(
                $onProgress,
                'Page plan generation failed and is waiting for retry: ' . $pageKey,
                82,
                'page_plan',
                'failed',
                ['page_type' => $pageKey, 'error_message' => $retryableFailures[$pageKey]['message']]
            );
        }

        return $results;
    }

    private function shouldUseSegmentedStageOnePagePlan(string $pageType, array $scope): bool
    {
        $budget = $this->resolveStageOneBlockBudget($pageType, $scope);

        return (int)($budget['target'] ?? 0) >= self::STAGE_ONE_SEGMENTED_PAGE_TARGET_THRESHOLD;
    }

    /**
     * Large pages can exceed a provider output cap when a full Stage-1 page is
     * produced in one JSON object. Keep the same contract, but split generation
     * into an AI page skeleton followed by AI block segments.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $requestParams
     * @return array<string, mixed>
     */
    private function generateStageOneSegmentedPagePlanByAi(
        array $scope,
        array $websiteProfile,
        array $planJson,
        string $pageType,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        array $requestParams,
        ?callable $onChunk = null,
        ?callable $onProgress = null,
        string $recoveryInstruction = ''
    ): array {
        $blockBudget = $this->resolveStageOneBlockBudget($pageType, $scope);
        $this->emitStageOnePipelineProgress(
            $onProgress,
            'Large page detected; generating segmented Stage-1 page skeleton: ' . $pageType,
            64,
            'page_plan',
            'segment_skeleton',
            ['page_type' => $pageType]
        );
        $skeletonDecoded = $this->generateStageOneJsonByAi(
            $this->buildAiStageOnePageSkeletonPrompt(
                $scope,
                $websiteProfile,
                $planJson,
                $pageType,
                $planLocale,
                $contentLocale,
                $instruction,
                $targetScope,
                $blockBudget,
                $recoveryInstruction
            ),
            'pagebuilder_plan_generation',
            self::STAGE_ONE_PAGE_SKELETON_MAX_TOKENS,
            180,
            $onChunk,
            $requestParams
        );
        $skeleton = $this->extractStageOneAiPageSkeleton($skeletonDecoded, $pageType);
        if ($skeleton === []) {
            throw new \RuntimeException('Segmented Stage-1 page skeleton AI failed for ' . $pageType . ': empty page skeleton.');
        }

        $orderedBlockKeys = $this->resolveStageOneSegmentedPageBlockKeys($skeleton, $blockBudget);
        if ($orderedBlockKeys === []) {
            throw new \RuntimeException('Segmented Stage-1 page skeleton AI failed for ' . $pageType . ': no block keys.');
        }
        $blueprintsByKey = $this->buildStageOneSegmentedBlueprintMap($skeleton);

        $pagePlan = [
            'page_goal' => \trim((string)($skeleton['page_goal'] ?? '')),
            'theme_alignment_summary' => \trim((string)($skeleton['theme_alignment_summary'] ?? '')),
            'page_design_plan' => \is_array($skeleton['page_design_plan'] ?? null) ? $skeleton['page_design_plan'] : [],
            'primary_keywords' => \is_array($skeleton['primary_keywords'] ?? null) ? $this->normalizeStringList($skeleton['primary_keywords']) : [],
            'secondary_keywords' => \is_array($skeleton['secondary_keywords'] ?? null) ? $this->normalizeStringList($skeleton['secondary_keywords']) : [],
            'blocks' => [],
        ];

        $blocksByKey = [];
        $segments = \array_chunk($orderedBlockKeys, self::STAGE_ONE_PAGE_BLOCK_SEGMENT_SIZE);
        foreach ($segments as $segmentIndex => $segmentKeys) {
            $segmentKeys = \array_values(\array_filter(\array_map('strval', $segmentKeys), static fn(string $value): bool => \trim($value) !== ''));
            if ($segmentKeys === []) {
                continue;
            }
            $this->emitStageOnePipelineProgress(
                $onProgress,
                'Generating Stage-1 block segment ' . ((int)$segmentIndex + 1) . '/' . \count($segments) . ' for ' . $pageType . ': ' . \implode(', ', $segmentKeys),
                66 + (int)\floor(((int)$segmentIndex / \max(1, \count($segments))) * 10),
                'page_plan',
                'segment_blocks',
                ['page_type' => $pageType, 'block_keys' => $segmentKeys]
            );
            $segmentBlocksByKey = [];
            $segmentRecoveryInstruction = $recoveryInstruction;
            $missingSegmentKeys = $segmentKeys;
            $segmentValidationIssues = [];
            for ($segmentAttempt = 0; $segmentAttempt < 3; $segmentAttempt++) {
                $segmentDecoded = $this->generateStageOneJsonByAi(
                    $this->buildAiStageOnePageBlockSegmentPrompt(
                        $scope,
                        $websiteProfile,
                        $planJson,
                        $pageType,
                        $planLocale,
                        $contentLocale,
                        $instruction,
                        $targetScope,
                        $blockBudget,
                        $skeleton,
                        $segmentKeys,
                        $this->buildStageOneSegmentedBlueprintSubset($blueprintsByKey, $segmentKeys),
                        $segmentRecoveryInstruction
                    ),
                    'pagebuilder_plan_generation',
                    self::STAGE_ONE_PAGE_BLOCK_SEGMENT_MAX_TOKENS,
                    210,
                    $onChunk,
                    $requestParams
                );
                $segmentBlocksByKey = [];
                foreach ($this->extractStageOneAiBlockSegment($segmentDecoded) as $block) {
                    if (!\is_array($block)) {
                        continue;
                    }
                    $blockKey = \trim((string)($block['block_key'] ?? ''));
                    if ($blockKey === '') {
                        continue;
                    }
                    $segmentBlocksByKey[$blockKey] = $block;
                }
                $missingSegmentKeys = \array_values(\array_filter(
                    $segmentKeys,
                    static fn(string $requiredSegmentKey): bool => !\is_array($segmentBlocksByKey[$requiredSegmentKey] ?? null)
                ));
                if ($missingSegmentKeys === []) {
                    $stageOneContract = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
                    $segmentValidationIssues = $this->validateStageOneBlockSegmentForPromptRetry(
                        $segmentBlocksByKey,
                        $segmentKeys,
                        $pageType,
                        $stageOneContract,
                        $scope,
                        $planLocale,
                        $contentLocale,
                        \trim((string)($skeleton['generated_image_target_block_key'] ?? ''))
                    );
                    if ($segmentValidationIssues === []) {
                        break;
                    }
                }
                $segmentRecoveryInstruction = $missingSegmentKeys !== []
                    ? ('Previous segment attempt missed required block keys: '
                        . \implode(', ', $missingSegmentKeys)
                        . '. Return exactly the requested block keys and do not rename them.')
                    : ('Previous segment attempt returned the requested block key but failed the Stage-1 block contract: '
                        . $this->summarizeStageOneValidationReport(['issues' => $segmentValidationIssues])
                        . '. ' . \implode(' ', $this->buildStageOneIssueSpecificRecoveryRules($segmentValidationIssues))
                        . ' Return a complete block object for exactly the requested key; shorten copy instead of omitting visual_signature, image_intent, field_plan, or execution_script.core_copy.');
            }
            if ($missingSegmentKeys !== []) {
                throw new \RuntimeException('Segmented Stage-1 page block AI failed for ' . $pageType . ': missing block ' . \implode(', ', $missingSegmentKeys) . '.');
            }
            foreach ($segmentBlocksByKey as $blockKey => $block) {
                $blocksByKey[$blockKey] = $block;
            }
        }

        foreach ($orderedBlockKeys as $blockKey) {
            if (\is_array($blocksByKey[$blockKey] ?? null)) {
                $pagePlan['blocks'][] = $blocksByKey[$blockKey];
            }
        }

        return $pagePlan;
    }

    /**
     * Segment generation is allowed to be creative, but each returned block must
     * already be structurally complete before it is stitched into the page.
     *
     * @param array<string, array<string, mixed>> $segmentBlocksByKey
     * @param list<string> $segmentKeys
     * @return list<array<string, mixed>>
     */
    private function validateStageOneBlockSegmentForPromptRetry(
        array $segmentBlocksByKey,
        array $segmentKeys,
        string $pageType,
        array $contract,
        array $scope,
        string $planLocale,
        string $contentLocale,
        string $generatedImageTargetKey
    ): array {
        $contract = $this->getStageOneContractService()->normalize(
            $contract,
            $scope,
            [$pageType],
            $planLocale,
            $contentLocale,
            'segment_prompt_retry'
        );
        $pageContract = $this->getStageOneContractService()->pageContract($contract, $pageType);
        $requiredVisualKeys = \is_array($pageContract['visual_signature_keys'] ?? null)
            ? $pageContract['visual_signature_keys']
            : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS;
        $issues = [];

        foreach (\array_values($segmentKeys) as $index => $segmentKey) {
            $segmentKey = \trim((string)$segmentKey);
            if ($segmentKey === '') {
                continue;
            }
            $path = 'pages.' . $pageType . '.blocks.' . $index;
            $block = \is_array($segmentBlocksByKey[$segmentKey] ?? null) ? $segmentBlocksByKey[$segmentKey] : [];
            if ($block === []) {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_block_key', $path . '.block_key', $pageType, $segmentKey);
                continue;
            }
            $actualBlockKey = \trim((string)($block['block_key'] ?? ''));
            if ($actualBlockKey !== $segmentKey) {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_block_key', $path . '.block_key', $pageType, $segmentKey, [
                    'expected' => $segmentKey,
                    'actual' => $actualBlockKey,
                ]);
            }
            if (\trim((string)($block['page_flow_role'] ?? '')) === '') {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_page_flow_role', $path . '.page_flow_role', $pageType, $segmentKey);
            }
            if (\trim((string)($block['content'] ?? '')) === '') {
                $issues[] = $this->stageOneSegmentRetryIssue('instruction_like_or_empty', $path . '.content', $pageType, $segmentKey);
            }
            if (!$this->hasCompleteStageOneDesignTags($block)) {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_design_tag', $path . '.design_tags', $pageType, $segmentKey);
            }

            $signature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
            if ($signature === []) {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_visual_signature', $path . '.visual_signature', $pageType, $segmentKey);
            }
            foreach ($requiredVisualKeys as $visualKey) {
                $visualKey = \trim((string)$visualKey);
                if ($visualKey === '') {
                    continue;
                }
                $value = \trim((string)($signature[$visualKey] ?? ''));
                if ($value === '' || \in_array(\mb_strtolower($value), ['none', 'same as above', 'string'], true)) {
                    $issues[] = $this->stageOneSegmentRetryIssue('invalid_visual_signature', $path . '.visual_signature.' . $visualKey, $pageType, $segmentKey, [
                        'expected' => 'non-empty concrete visual_signature.' . $visualKey,
                        'actual' => $value,
                    ]);
                }
            }

            $intent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
            if ($intent === []) {
                $issues[] = $this->stageOneSegmentRetryIssue('missing_image_intent', $path . '.image_intent', $pageType, $segmentKey);
                continue;
            }
            if (!\array_key_exists('needs_image', $intent) || !\is_bool($intent['needs_image'])) {
                $issues[] = $this->stageOneSegmentRetryIssue('invalid_image_intent_needs_image', $path . '.image_intent.needs_image', $pageType, $segmentKey, [
                    'expected' => 'JSON boolean true or false',
                    'actual' => \is_scalar($intent['needs_image'] ?? null) ? (string)($intent['needs_image'] ?? '') : \get_debug_type($intent['needs_image'] ?? null),
                ]);
                continue;
            }

            $needsImage = (bool)$intent['needs_image'];
            if ($generatedImageTargetKey !== '' && $segmentKey === $generatedImageTargetKey && !$needsImage) {
                $issues[] = $this->stageOneSegmentRetryIssue('page_missing_generated_image_intent', 'pages.' . $pageType . '.blocks.image_intent', $pageType, $segmentKey, [
                    'expected' => 'generated image target block must set image_intent.needs_image=true with concrete image fields',
                ]);
            }
            if (!$this->hasCompleteStageOneImageIntent($block)) {
                $issues[] = $this->stageOneSegmentRetryIssue(
                    $needsImage ? 'invalid_image_intent_field' : 'missing_css_motif_for_no_image_block',
                    $path . '.image_intent',
                    $pageType,
                    $segmentKey
                );
            }
            if (!$needsImage && $this->stageOneSegmentHasMediaAssets($block)) {
                $issues[] = $this->stageOneSegmentRetryIssue('image_intent_conflicts_with_block_plan', $path . '.execution_script.media_assets', $pageType, $segmentKey, [
                    'expected' => 'CSS-only blocks must use execution_script.media_assets=[]',
                ]);
            }
        }

        return \array_slice($issues, 0, 16);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stageOneSegmentHasMediaAssets(array $block): bool
    {
        $assets = [];
        foreach ([
            $block['execution_script']['media_assets'] ?? null,
            $block['media_assets'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate)) {
                foreach ($candidate as $asset) {
                    if (\is_scalar($asset) && \trim((string)$asset) !== '') {
                        $assets[] = \trim((string)$asset);
                    }
                }
            } elseif (\is_scalar($candidate) && \trim((string)$candidate) !== '') {
                $assets[] = \trim((string)$candidate);
            }
        }

        return $assets !== [];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function stageOneSegmentRetryIssue(string $code, string $path, string $pageType, string $blockKey, array $extra = []): array
    {
        return \array_replace([
            'code' => $code,
            'reason_code' => $code,
            'severity' => 'high',
            'path' => $path,
            'field_path' => $path,
            'retry_scope' => 'stage1_contract',
            'prompt_hint' => 'Regenerate this Stage-1 block segment from the contract instead of dropping nested objects.',
            'page_type' => $pageType,
            'block_key' => $blockKey,
        ], $extra);
    }

    private function stageOneBlockReturnExamplesJson(): string
    {
        $examples = [
            'generated_image_block' => [
                'block_key' => 'hero',
                'page_flow_role' => 'opening',
                'goal' => 'Drive APK downloads with a concrete product/game visual.',
                'keywords' => ['APK download', 'card game'],
                'content' => 'Download the APK and start playing trusted card games today.',
                'design_tags' => [
                    'visual' => ['split hero', 'phone mockup'],
                    'motion' => ['soft reveal', 'CTA glow'],
                    'interaction' => ['primary CTA hover'],
                    'texture' => ['felt gradient', 'gold rim'],
                    'responsive' => ['mobile stacked', 'desktop split'],
                    'color_layering' => 'dark felt base with gold highlight',
                    'implementation_note' => 'Keep copy left and media right.',
                ],
                'visual_signature' => [
                    'composition_pattern' => 'split hero with phone mockup',
                    'spatial_rhythm' => 'copy left, generated product visual right',
                    'media_strategy' => 'Generated hero image sits beside the CTA as a rounded phone mockup',
                    'surface_treatment' => 'dark felt gradient with gold rim light',
                    'interaction_pattern' => 'CTA hover glow and subtle image parallax',
                ],
                'image_intent' => [
                    'needs_image' => true,
                    'image_role' => 'hero_image',
                    'image_subject' => 'phone APK install screen with playing-card table behind it',
                    'placement' => 'media_panel',
                    'visual_atmosphere' => 'premium trusted game-lobby mood with warm gold lighting',
                    'image_treatment' => 'rounded phone mockup with shallow shadow and gold overlay',
                    'reuse_policy' => 'reuse_when_intent_matches',
                    'css_motif' => '',
                ],
                'field_plan' => [
                    ['field' => 'headline', 'sample' => 'Play Today', 'implementation_note' => 'Render as the main H1.'],
                    ['field' => 'supporting_copy', 'sample' => 'Fast APK download with trusted gameplay.', 'implementation_note' => 'Render below headline.'],
                    ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Use for the primary button.'],
                ],
                'execution_script' => [
                    'feature_points' => ['Fast download', 'Secure setup'],
                    'core_copy' => 'Download the APK and start playing trusted card games today.',
                    'typography' => 'Bold display headline with readable body text',
                    'style_tone' => 'Confident and conversion-focused',
                    'background_direction' => 'Dark felt gradient with gold highlights',
                    'media_assets' => ['hero-phone-apk-install.png'],
                ],
                'reusable' => 'no',
                'seo_impact' => 'high',
            ],
            'css_only_block' => [
                'block_key' => 'player_reviews',
                'page_flow_role' => 'proof',
                'goal' => 'Build trust with player feedback without generating image assets.',
                'keywords' => ['reviews', 'secure app'],
                'content' => 'Real players trust the app for quick, secure gameplay.',
                'design_tags' => [
                    'visual' => ['review cards', 'star badges'],
                    'motion' => ['hover lift', 'badge shimmer'],
                    'interaction' => ['card hover'],
                    'texture' => ['glass cards', 'border glow'],
                    'responsive' => ['mobile stack', 'desktop rail'],
                    'color_layering' => 'dark glass cards with warm accent badges',
                    'implementation_note' => 'Use CSS initials and rating chips.',
                ],
                'visual_signature' => [
                    'composition_pattern' => 'staggered testimonial cards',
                    'spatial_rhythm' => 'three review cards with rating badges',
                    'media_strategy' => 'CSS-only/no generated image; cards use initials, star badges, and gradient borders',
                    'surface_treatment' => 'glass cards with accent border glow',
                    'interaction_pattern' => 'card hover lift and rating shimmer',
                ],
                'image_intent' => [
                    'needs_image' => false,
                    'image_role' => 'css_motif',
                    'image_subject' => 'none',
                    'placement' => 'background_layer',
                    'visual_atmosphere' => 'secure premium review wall with social proof energy',
                    'image_treatment' => 'CSS gradients, initials, star badges, and border glow replace generated imagery',
                    'reuse_policy' => 'no_generated_image',
                    'css_motif' => 'glass testimonial cards with accent side borders and gold star badges',
                ],
                'field_plan' => [
                    ['field' => 'headline', 'sample' => 'Trusted by Players', 'implementation_note' => 'Render above review cards.'],
                    ['field' => 'supporting_copy', 'sample' => 'Secure gameplay with quick support.', 'implementation_note' => 'Render as proof copy.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 star average rating', 'implementation_note' => 'Render as a badge.'],
                ],
                'execution_script' => [
                    'feature_points' => ['Verified reviews', 'Quick support'],
                    'core_copy' => 'Real players trust the app for quick, secure gameplay.',
                    'typography' => 'Compact headline with badge text',
                    'style_tone' => 'Reassuring and factual',
                    'background_direction' => 'Layered cards on a soft gradient surface',
                    'media_assets' => [],
                ],
                'reusable' => 'no',
                'seo_impact' => 'medium',
            ],
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneFieldPlanIntentExamplesJson(): string
    {
        $examples = [
            'cta_block' => [
                ['field' => 'headline', 'sample' => 'Start Playing Today', 'implementation_note' => 'Main CTA heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Download the APK and follow the quick setup steps.', 'implementation_note' => 'Body sentence before button.'],
                ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Primary action button.'],
            ],
            'proof_block' => [
                ['field' => 'headline', 'sample' => 'Trusted by Players', 'implementation_note' => 'Proof section heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Verified reviews highlight fast setup and secure play.', 'implementation_note' => 'Trust body copy.'],
                ['field' => 'proof_detail', 'sample' => '4.8 average rating', 'implementation_note' => 'Badge or stat chip.'],
            ],
            'media_or_feature_block' => [
                ['field' => 'headline', 'sample' => 'See the Game Flow', 'implementation_note' => 'Feature heading.'],
                ['field' => 'supporting_copy', 'sample' => 'A guided screen shows setup, play, and rewards.', 'implementation_note' => 'Feature body copy.'],
                ['field' => 'image_brief', 'sample' => 'Phone screen with card table behind it', 'implementation_note' => 'Asset brief if needs_image=true.'],
            ],
            'support_or_form_block' => [
                ['field' => 'headline', 'sample' => 'Need Help?', 'implementation_note' => 'Support heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Send your question and the support team will guide you.', 'implementation_note' => 'Support body copy.'],
                ['field' => 'form_label', 'sample' => 'Describe your issue', 'implementation_note' => 'Visitor-facing form label.'],
            ],
            'policy_block' => [
                ['field' => 'headline', 'sample' => 'Privacy Overview', 'implementation_note' => 'Policy heading.'],
                ['field' => 'supporting_copy', 'sample' => 'This page explains how account and app data is handled.', 'implementation_note' => 'Neutral policy body.'],
                ['field' => 'policy_summary', 'sample' => 'Data use, rights, and contact options', 'implementation_note' => 'Policy summary chip.'],
            ],
            'multi_item_blocks_still_use_three_rows' => [
                'player_reviews' => [
                    ['field' => 'headline', 'sample' => 'Players Trust the App', 'implementation_note' => 'Review section heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Ravi likes quick setup; Anika values secure play; Dev trusts the lobby.', 'implementation_note' => 'Intro plus three review snippets.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 average rating from active players', 'implementation_note' => 'Single rating badge, not extra rows.'],
                ],
                'support_faq' => [
                    ['field' => 'headline', 'sample' => 'Support Questions', 'implementation_note' => 'FAQ heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Get help with install steps, account access, and game basics.', 'implementation_note' => 'One FAQ intro sentence.'],
                    ['field' => 'context_detail', 'sample' => 'Install help | Account help | Game rules', 'implementation_note' => 'Multiple FAQ topics inside one row.'],
                ],
            ],
            'by_common_block_key' => [
                'hero_download' => [
                    ['field' => 'headline', 'sample' => 'Download in Three Clear Steps', 'implementation_note' => 'Heading for download guidance.'],
                    ['field' => 'supporting_copy', 'sample' => 'Follow the install prompts and open the game lobby safely.', 'implementation_note' => 'Body copy before action.'],
                    ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Primary download action.'],
                ],
                'player_reviews' => [
                    ['field' => 'headline', 'sample' => 'Players Trust the App', 'implementation_note' => 'Review section heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Short reviews highlight smooth setup and secure play.', 'implementation_note' => 'Review intro sentence.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 average rating', 'implementation_note' => 'Rating badge detail.'],
                ],
                'faq_or_rules' => [
                    ['field' => 'headline', 'sample' => 'Rules Before You Play', 'implementation_note' => 'FAQ/rules heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Review the basics before starting your first table.', 'implementation_note' => 'Intro sentence.'],
                    ['field' => 'context_detail', 'sample' => 'Teen Patti, Rummy, and Poker basics', 'implementation_note' => 'Topic chip or summary.'],
                ],
                'article_collection' => [
                    ['field' => 'headline', 'sample' => 'Latest Strategy Guides', 'implementation_note' => 'Article list heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Browse practical tips for safer and smarter play.', 'implementation_note' => 'Collection intro.'],
                    ['field' => 'article_teaser', 'sample' => 'Beginner Teen Patti table guide', 'implementation_note' => 'First article teaser.'],
                ],
            ],
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneCssOnlyImageIntentExamplesJson(): string
    {
        $examples = [
            'trust_security' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'secure premium install-check mood with warm trust accents',
                'image_treatment' => 'CSS shield chips, check rows, and glow borders replace generated imagery',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'secure-install checklist with amber shields, tick chips, and dark glass panels',
            ],
            'player_reviews' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'inline_visual',
                'visual_atmosphere' => 'social proof wall with compact trustworthy player energy',
                'image_treatment' => 'CSS initials, star badges, and staggered testimonial cards replace player photos',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'initial circles, rating stars, and neon card borders',
            ],
            'faq_or_rules' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'calm rulebook panel with clear scanning and responsible-play tone',
                'image_treatment' => 'CSS accordion rows, card-suit bullets, and gold dividers replace images',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'accordion rule rows with suit icons, numbered chips, and divider lines',
            ],
            'article_collection' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'inline_visual',
                'visual_atmosphere' => 'editorial guide index with practical strategy-card mood',
                'image_treatment' => 'CSS article cards, category tabs, and reading badges replace thumbnails',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'editorial card stack with guide labels, suit marks, and read-time chips',
            ],
            'support_faq' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'support center clarity with low-friction help cues',
                'image_treatment' => 'CSS help chips, accordion arrows, and soft panels replace support photos',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'help-topic chips, FAQ rows, and focus-visible accordion arrows',
            ],
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneGeneratedImageTargetExamplesJson(): string
    {
        $examples = [
            'home_page' => 'hero',
            'about_page' => 'origin_story',
            'contact_page' => 'contact_methods',
            'blog_post' => 'article_hero',
            'blog_category' => 'category_hero',
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * @param list<string> $segmentKeys
     */
    private function stageOneSegmentMediaDecisionExamplesJson(array $segmentKeys, string $generatedImageTargetKey): string
    {
        $cssOnlyExamples = \json_decode($this->stageOneCssOnlyImageIntentExamplesJson(), true);
        $cssOnlyExamples = \is_array($cssOnlyExamples) ? $cssOnlyExamples : [];
        $examples = [];
        foreach ($segmentKeys as $segmentKey) {
            $segmentKey = \trim((string)$segmentKey);
            if ($segmentKey === '') {
                continue;
            }
            if ($generatedImageTargetKey !== '' && $segmentKey === $generatedImageTargetKey) {
                $examples[$segmentKey] = [
                    'needs_image' => true,
                    'image_role' => 'hero_image or section_image',
                    'image_subject' => 'concrete generated scene/product/interface subject tied to this block',
                    'placement' => 'media_panel or inline_visual',
                    'media_strategy' => 'Generated image integrated with this block; do not say CSS-only/no generated image',
                ];
                continue;
            }
            if (\is_array($cssOnlyExamples[$segmentKey] ?? null)) {
                $examples[$segmentKey] = $cssOnlyExamples[$segmentKey];
                continue;
            }
            if (\preg_match('/(?:review|testimonial|faq|rules|trust|security|proof|cta|download|support|policy)/i', $segmentKey) === 1) {
                $examples[$segmentKey] = [
                    'needs_image' => false,
                    'image_role' => 'css_motif',
                    'image_subject' => 'none',
                    'placement' => 'background_layer or inline_visual',
                    'visual_atmosphere' => 'block-specific CSS visual mood, not a generic placeholder',
                    'image_treatment' => 'CSS cards, chips, icons, dividers, or badges replace generated imagery',
                    'reuse_policy' => 'no_generated_image',
                    'css_motif' => 'concrete CSS motif matching this block key',
                    'media_strategy' => 'CSS-only/no generated image; describe the block-specific motif in visual_signature.media_strategy',
                ];
            }
        }

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneVisualSignatureExamplesJson(): string
    {
        $examples = [
            'generated_media_block' => [
                'composition_pattern' => 'split hero with copy rail and phone media panel',
                'spatial_rhythm' => 'large headline column balanced by one generated visual',
                'media_strategy' => 'Generated phone APK install image anchors the right panel beside CTA copy',
                'surface_treatment' => 'dark felt gradient, gold rim light, and soft glass card depth',
                'interaction_pattern' => 'CTA glow on hover; generated media panel uses reduced-motion parallax',
            ],
            'css_only_static_block' => [
                'composition_pattern' => 'staggered proof cards with compact badge row',
                'spatial_rhythm' => 'three short cards, tight copy, and one accent stat strip',
                'media_strategy' => 'CSS-only/no generated image; initials, chips, dividers, and card-suit motifs create the visual',
                'surface_treatment' => 'glass panels with amber border glow and subtle patterned texture',
                'interaction_pattern' => 'card hover lift, focus-visible links, no ambient motion',
            ],
            'faq_or_rules_block' => [
                'composition_pattern' => 'accordion-style rule rows inside a framed help panel',
                'spatial_rhythm' => 'left intro copy, right stacked questions, compact row gaps',
                'media_strategy' => 'CSS-only/no generated image; rule numbers, suit icons, and divider lines guide scanning',
                'surface_treatment' => 'matte dark panel with gold separators and soft inset shadow',
                'interaction_pattern' => 'accordion row expand, keyboard focus ring, no floating animation',
            ],
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneRequirementExpansionExampleJson(): string
    {
        $example = [
            'requirement_expansion' => [
                'original_brief' => 'Teen Patti APK download site for Indian players',
                'expanded_brief' => 'Create a trusted Indian card-game APK site with fast download guidance, safety proof, rules education, and support clarity.',
                'planning_summary' => 'Conversion-first app-download experience with strong trust and beginner education.',
                'site_goal' => 'Help visitors understand, trust, and download the APK.',
                'business_goals' => ['Increase APK downloads', 'Build safety confidence'],
                'target_users' => ['Indian card players', 'New APK users'],
                'business_context' => 'Mobile game discovery site for Teen Patti-style card gameplay.',
                'content_direction' => 'Use direct download copy, rule summaries, trust proof, FAQ, and support guidance.',
                'conversion_strategy' => 'Lead with download CTA, reinforce with safety/reviews, repeat one final CTA.',
                'primary_cta' => 'Download APK',
                'page_strategy' => [
                    [
                        'page_type' => 'home_page',
                        'intent' => 'convert visitors with hero, proof, features, and CTA flow',
                        'content_focus' => 'download benefit, games, safety, reviews',
                        'conversion_role' => 'primary conversion',
                    ],
                ],
                'technical_direction' => ['Mobile-first layout', 'Reusable CTA pattern', 'Verified image slots only'],
            ],
        ];

        return \json_encode($example, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOneThemeExampleJson(): string
    {
        $example = [
            'site_strategy' => [
                'site_display_name' => 'Teenipiya',
                'summary' => 'Trusted APK download and learning hub for Indian card-game players.',
                'website_type' => 'game APK landing and resource site',
                'core_goal' => 'Convert visitors to safe APK downloads.',
                'target_users' => 'Indian mobile card-game players',
                'content_strategy' => 'Pair download CTAs with rules, trust proof, and support.',
                'conversion_path' => 'Hero CTA, game proof, safety proof, FAQ, final CTA.',
                'primary_cta' => 'Download APK',
            ],
            'theme_design' => [
                'style_signature' => 'premium Indian card-room launch style with felt depth and gold CTA accents',
                'art_direction' => [
                    'layout_motif' => 'diagonal hero, proof rail, compact CTA stage',
                    'background_system' => 'dark felt gradients with subtle pattern layers',
                    'surface_treatment' => 'glass cards, gold rims, tactile CTA glow',
                    'visual_detail_rule' => 'use CSS card suits/chips as motifs; real images only for phone/game scenes',
                    'motion_rule' => 'short reveal, hover lift, CTA glow; no generic floating blobs',
                ],
                'visual_keywords' => ['felt depth', 'gold CTA', 'glass proof cards', 'phone mockup'],
                'forbidden_styles' => ['plain SaaS blue gradient', 'generic white card grid', 'purple AI glow'],
            ],
            'page_type_overviews' => [
                'home_page' => [
                    'page_role' => 'primary conversion',
                    'content_focus' => 'download, games, proof, FAQ',
                    'theme_color_application' => 'gold CTAs over dark card-room surfaces',
                    'section_layering_hint' => 'hero then feature/proof rhythm',
                    'interaction_intent' => 'CTA glow and card hover lift',
                    'differentiation_note' => 'marketing landing, not legal text layout',
                ],
            ],
        ];

        return \json_encode($example, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function stageOnePageSkeletonExampleJson(): string
    {
        $example = [
            'page' => [
                'page_goal' => 'Convert visitors to APK downloads while proving safety and game value.',
                'theme_alignment_summary' => 'Uses dark felt surfaces, gold CTAs, and proof rails from the shared card-room theme.',
                'page_design_plan' => [
                    'page_role' => 'conversion home',
                    'content_narrative' => 'download promise, game benefits, trust proof, FAQ, final action',
                    'visual_hierarchy' => 'large opening, dense feature proof, compact final CTA',
                    'visual_signature_application' => 'each block gets a distinct composition, surface, media, and interaction signature',
                    'composition_motif' => 'diagonal launch hero with staggered proof rail',
                    'color_layering' => 'dark base, warm gold accents, lighter support panels',
                    'section_flow' => ['opening download promise', 'features and proof before final CTA'],
                    'interaction_notes' => ['CTA hover glow', 'cards lift on hover'],
                    'polish_details' => ['CSS chip motifs', 'subtle patterned background'],
                    'anti_monotony_rule' => 'alternate media, proof cards, FAQ rows, and CTA stage instead of repeated card grids',
                ],
                'ordered_block_keys' => ['hero', 'hero_download', 'game_showcase_or_features', 'trust_security', 'player_reviews', 'faq_or_rules', 'final_cta'],
                'generated_image_target_block_key' => 'hero',
                'block_blueprints' => [
                    [
                        'block_key' => 'hero',
                        'page_flow_role' => 'opening',
                        'goal' => 'make the APK download promise immediately clear',
                        'content_focus' => 'download CTA plus trusted game intro',
                        'visual_signature_hint' => 'split hero with phone/game scene and CTA panel',
                        'image_intent_hint' => 'generated phone APK install screen with card table behind it',
                        'handoff_rule' => 'block worker must return full field_plan, visual_signature, and image_intent',
                    ],
                    [
                        'block_key' => 'hero_download',
                        'page_flow_role' => 'cta',
                        'goal' => 'make download steps and action obvious',
                        'content_focus' => 'APK install step and CTA label',
                        'visual_signature_hint' => 'compact download rail with CSS chip/step badges',
                        'image_intent_hint' => 'CSS-only download step motif',
                        'handoff_rule' => 'cta block row 2 must be cta_label or action_label',
                    ],
                    [
                        'block_key' => 'game_showcase_or_features',
                        'page_flow_role' => 'details',
                        'goal' => 'show the core card-game experience without repeating the hero',
                        'content_focus' => 'game modes, phone lobby, and quick play value',
                        'visual_signature_hint' => 'feature grid with one phone-lobby media panel',
                        'image_intent_hint' => 'generated phone game lobby or CSS feature cards',
                        'handoff_rule' => 'row 2 should be image_brief when generated, otherwise context_detail',
                    ],
                    [
                        'block_key' => 'trust_security',
                        'page_flow_role' => 'proof',
                        'goal' => 'make safety and APK confidence visible before reviews',
                        'content_focus' => 'secure install, fair play, and app trust cues',
                        'visual_signature_hint' => 'trust checklist band with shield chips and audit badges',
                        'image_intent_hint' => 'CSS-only shield chips and secure-install motif',
                        'handoff_rule' => 'must include full CSS-only image_intent if no generated image',
                    ],
                    [
                        'block_key' => 'player_reviews',
                        'page_flow_role' => 'proof',
                        'goal' => 'add player trust proof without another hero shell',
                        'content_focus' => 'short reviews and rating detail',
                        'visual_signature_hint' => 'staggered testimonial cards with rating badges',
                        'image_intent_hint' => 'CSS-only initials and star badges',
                        'handoff_rule' => 'proof block row 2 should be proof_detail',
                    ],
                    [
                        'block_key' => 'faq_or_rules',
                        'page_flow_role' => 'details',
                        'goal' => 'answer common play and install questions in a scannable format',
                        'content_focus' => 'rules basics, APK setup, and responsible play cues',
                        'visual_signature_hint' => 'accordion-style rule rows inside framed help panel',
                        'image_intent_hint' => 'CSS-only rule numbers, suit icons, and divider lines',
                        'handoff_rule' => 'all five visual_signature fields must be non-empty',
                    ],
                    [
                        'block_key' => 'final_cta',
                        'page_flow_role' => 'cta',
                        'goal' => 'close the page with one confident download action',
                        'content_focus' => 'final reassurance and APK action label',
                        'visual_signature_hint' => 'compact CTA stage with glow button and trust chips',
                        'image_intent_hint' => 'CSS-only CTA glow, chip rail, and card-suit texture',
                        'handoff_rule' => 'row 2 must be cta_label/action_label/button_text',
                    ],
                ],
            ],
        ];

        return \json_encode($example, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * @param list<string> $requiredBlockKeys
     * @param list<string> $optionalBlockKeys
     * @return list<string>
     */
    private function orderStageOneBlockKeysForPrompt(array $requiredBlockKeys, array $optionalBlockKeys, int $targetBlockCount): array
    {
        $unique = static function (array $values): array {
            $result = [];
            $seen = [];
            foreach ($values as $value) {
                $value = \trim((string)$value);
                if ($value === '') {
                    continue;
                }
                $key = \mb_strtolower($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = $value;
            }

            return $result;
        };
        $requiredBlockKeys = $unique($requiredBlockKeys);
        $optionalBlockKeys = $unique($optionalBlockKeys);
        if ($requiredBlockKeys === []) {
            return $targetBlockCount > 0 ? \array_slice($optionalBlockKeys, 0, $targetBlockCount) : $optionalBlockKeys;
        }

        $first = \array_shift($requiredBlockKeys);
        $tail = [];
        $middleRequired = [];
        foreach ($requiredBlockKeys as $blockKey) {
            if (\preg_match('/(?:final|cta|conversion|download_band|action)$/i', $blockKey) === 1) {
                $tail[] = $blockKey;
                continue;
            }
            $middleRequired[] = $blockKey;
        }

        $ordered = [$first, ...$middleRequired];
        $need = $targetBlockCount > 0
            ? \max(0, $targetBlockCount - \count($ordered) - \count($tail))
            : \count($optionalBlockKeys);
        foreach ($optionalBlockKeys as $optionalBlockKey) {
            if ($need <= 0) {
                break;
            }
            if (\in_array($optionalBlockKey, $ordered, true) || \in_array($optionalBlockKey, $tail, true)) {
                continue;
            }
            $ordered[] = $optionalBlockKey;
            $need--;
        }
        $ordered = \array_values(\array_unique(\array_merge($ordered, $tail)));

        return $targetBlockCount > 0 && \count($ordered) > $targetBlockCount
            ? \array_slice($ordered, 0, $targetBlockCount)
            : $ordered;
    }

    private function stageOneAppendInstructionClassifierExamplesJson(): string
    {
        $examples = [
            'append_example' => [
                'instruction' => 'Add a why-choose-us section below the home hero.',
                'return' => [
                    'action' => 'append_block',
                    'append_type' => 'why_choose_us',
                    'target_page_type' => 'home_page',
                    'confidence' => 0.92,
                    'decision_note' => 'User explicitly asks to add a new why-choose-us section under hero.',
                ],
            ],
            'none_example' => [
                'instruction' => 'Make the home page button copy more compelling.',
                'return' => [
                    'action' => 'none',
                    'append_type' => 'none',
                    'target_page_type' => 'home_page',
                    'confidence' => 0.86,
                    'decision_note' => 'User asks to refine existing copy, not add a new block.',
                ],
            ],
        ];

        return \json_encode($examples, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function buildStageOneIdentityGuardPrompt(array $scope, array $websiteProfile, string $siteDisplayName = ''): string
    {
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $siteIdentity = \is_array($sourceTruth['site_identity'] ?? null) ? $sourceTruth['site_identity'] : [];
        $allowed = [];
        foreach ([
            $siteDisplayName,
            $scope['site_title'] ?? null,
            $scope['site_name'] ?? null,
            $websiteProfile['site_title'] ?? null,
            $websiteProfile['site_name'] ?? null,
            $siteIdentity['site_name'] ?? null,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                $allowed[] = $candidate;
            }
        }
        foreach (\is_array($siteIdentity['brand_terms'] ?? null) ? $siteIdentity['brand_terms'] : [] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                $allowed[] = $candidate;
            }
        }
        $allowed = $this->uniqueStageOnePromptStrings($allowed);
        $allowedLookup = \array_fill_keys(\array_map(static fn(string $term): string => \mb_strtolower($term), $allowed), true);
        $forbidden = [];
        foreach (self::TEMPLATE_SCAFFOLD_BRAND_TERMS as $term) {
            if (!isset($allowedLookup[\mb_strtolower($term)])) {
                $forbidden[] = $term;
            }
        }

        return 'SOURCE_TRUTH_IDENTITY_GUARD: approved_brand_terms='
            . (\json_encode($allowed, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]')
            . '; forbidden_template_or_example_brand_terms='
            . (\json_encode($forbidden, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]')
            . '; style templates, default configs, layout JSON, and examples are structure/art-direction references only. Do not copy their brand names, old page copy, SEO text, nav labels, social links, CTA targets, alt/title text, or support/game lists into the Stage-1 plan. If a candidate page/block string contains a forbidden term, rewrite it from the current brief before returning JSON.';
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueStageOnePromptStrings(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $key = \mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $blockBudget
     */
    private function buildAiStageOnePageSkeletonPrompt(
        array $scope,
        array $websiteProfile,
        array $planJson,
        string $pageType,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        array $blockBudget,
        string $recoveryInstruction = ''
    ): string {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $requirementExpansion = \json_encode($planJson['requirement_expansion'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $themeDesign = \json_encode($planJson['theme_design'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $pageTypeOverview = \json_encode($this->resolveStageOnePageTypeOverview($planJson, $pageType), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $sourceTruthLine = $this->buildStageOneSourceTruthPromptLine($scope);
        $targetBlockCount = \max(1, (int)($blockBudget['target'] ?? 0));
        $requiredBlockKeys = \array_values(\array_filter(\array_map('strval', \is_array($blockBudget['required'] ?? null) ? $blockBudget['required'] : []), static fn(string $value): bool => \trim($value) !== ''));
        $optionalBlockKeys = \array_values(\array_filter(\array_map('strval', \is_array($blockBudget['optional'] ?? null) ? $blockBudget['optional'] : []), static fn(string $value): bool => \trim($value) !== ''));
        $orderedKeyExample = $this->orderStageOneBlockKeysForPrompt($requiredBlockKeys, $optionalBlockKeys, $targetBlockCount);
        $isPolicyPage = \in_array($pageType, ['privacy_policy', 'terms_of_service', 'refund_policy', 'shipping_policy', 'cookie_policy'], true);
        $generatedImageTargetRule = !$isPolicyPage
            ? 'Generated-image target rule: choose one ordered_block_keys value as generated_image_target_block_key. Prefer the opening/media/support block that can carry a real generated scene, product interface, phone mockup, support desk, or editorial visual. That target block segment must later set image_intent.needs_image=true; other blocks may use complete CSS-only motifs.'
            : 'Policy media rule: generated_image_target_block_key must be an empty string for dense policy/legal pages unless the page contract explicitly says otherwise.';

        return \implode("\n", \array_values(\array_filter([
            ...$this->buildStageOnePromptRolePrelude(),
            '銆愮郴缁熸彁绀鸿瘝銆?',
            'You are PageBuilder Stage-1 PAGE SKELETON planner for one large page.',
            'Return STRICT JSON only. Create the page-level art direction and ordered block blueprint; do not generate full block field_plan details in this call.',
            'Accepted shape: {"page":{...}}.',
            'Page type: ' . $pageType,
            'Plan locale: ' . ($planLocale !== '' ? $planLocale : 'zh_Hans_CN'),
            'Website content locale: ' . ($contentLocale !== '' ? $contentLocale : ($planLocale !== '' ? $planLocale : 'zh_Hans_CN')),
            'Block budget: target=' . (string)($blockBudget['target'] ?? 0) . ', required=' . \json_encode($blockBudget['required'] ?? [], \JSON_UNESCAPED_UNICODE) . ', recommended_optional=' . \json_encode($blockBudget['optional'] ?? [], \JSON_UNESCAPED_UNICODE) . '. ordered_block_keys must contain exactly target keys, include every required key exactly once, and then optional keys only if target needs them.',
            'This page required block_key checklist is dynamic and binding: ' . \json_encode($requiredBlockKeys, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . '. Suggested natural ordered_block_keys example for this exact page: ' . \json_encode($orderedKeyExample, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . '. Do not copy a generic example if it omits any current required key or puts final CTA before proof/detail sections.',
            'Opening-order contract: the first ordered_block_keys value must be the first required key because it carries the page opening/first visual contract. After that, follow the page skeleton and page_design_plan flow; do not move a final/download/install/CTA block before narrative/detail/proof sections unless that CTA is the explicit page opening.',
            'The ordered block blueprint is a real plan. Choose order from this page identity and page_design_plan.section_flow, not from a generic home template.',
            'Each block_blueprints row must define what that block uniquely contributes, how it differs visually from adjacent blocks, and whether it needs a generated image.',
            $generatedImageTargetRule,
            'Preferred generated-image target examples by page type: ' . $this->stageOneGeneratedImageTargetExamplesJson() . '. These are teaching defaults, not copy; use the closest required/opening/support/article block for this page when available.',
            'Creativity rule: the contract is a frame, not a template. Invent a page-specific rhythm, block sequence, media idea, and surface language inside the required keys; do not produce a generic compliance checklist.',
            'Block blueprint intent rule: page_flow_role should describe the block job (opening/proof/details/cta/support), and image_intent_hint should say either a concrete generated scene/product/interface subject or a CSS-only motif such as badges, chips, dividers, patterns, cards, or icon-led UI.',
            'Output budget: page_goal/theme_alignment_summary <= 120 chars; each page_design_plan value <= 70 chars; arrays max 2 items; each block_blueprints value <= 60 chars.',
            '銆愮敤鎴锋彁绀鸿瘝銆態rief: ' . ($brief !== '' ? $brief : '-'),
            '銆愮敤鎴锋彁绀鸿瘝銆慖nstruction: ' . ($instruction !== '' ? $instruction : '-'),
            '銆愰€氱敤鎻愮ず璇嶃€?',
            'Target scope: ' . ($targetScope !== '' ? $targetScope : '-'),
            'Confirmed requirement expansion: ' . $requirementExpansion,
            'Shared theme_design: ' . $themeDesign,
            'Theme-level page overview: ' . $pageTypeOverview,
            'Reference image insights: ' . $this->buildReferenceImageInsightsPromptText($scope),
            $sourceTruthLine,
            $recoveryInstruction !== '' ? 'Recovery instruction: ' . $this->clipText($recoveryInstruction, 1400) : '',
            'Schema:',
            '{"page":{"page_goal":"string","theme_alignment_summary":"string","page_design_plan":{"page_role":"string","content_narrative":"string","visual_hierarchy":"string","visual_signature_application":"string","composition_motif":"string","color_layering":"string","section_flow":["string"],"interaction_notes":["string"],"polish_details":["string"],"anti_monotony_rule":"string"},"primary_keywords":["string"],"secondary_keywords":["string"],"ordered_block_keys":["string"],"generated_image_target_block_key":"string or empty for policy pages","block_blueprints":[{"block_key":"string","page_flow_role":"opening|proof|details|cta|support","goal":"string","content_focus":"string","visual_signature_hint":"string","image_intent_hint":"generated image subject or CSS-only motif","handoff_rule":"string"}]}}',
            'Page skeleton example (copy the structure, not the content; rewrite for this page): ' . $this->stageOnePageSkeletonExampleJson(),
            'Self-check before return: ordered_block_keys length equals target, every required block key appears exactly once, the first key equals the first required key, non-policy generated_image_target_block_key is one of ordered_block_keys, final CTA stays near the page end unless it is the page opening, and block_blueprints covers every ordered key. If too long, shorten blueprint strings; do not omit keys.',
        ], static fn(string $line): bool => \trim($line) !== '')));
    }

    /**
     * @param array<string, mixed> $blockBudget
     * @param array<string, mixed> $skeleton
     * @param list<string> $segmentKeys
     * @param array<string, array<string, mixed>> $segmentBlueprints
     */
    private function buildAiStageOnePageBlockSegmentPrompt(
        array $scope,
        array $websiteProfile,
        array $planJson,
        string $pageType,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        array $blockBudget,
        array $skeleton,
        array $segmentKeys,
        array $segmentBlueprints,
        string $recoveryInstruction = ''
    ): string {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $themeDesign = \json_encode($planJson['theme_design'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $pageTypeOverview = \json_encode($this->resolveStageOnePageTypeOverview($planJson, $pageType), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $skeletonJson = \json_encode($this->clipStageOneSegmentedSkeletonForPrompt($skeleton), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $segmentBlueprintJson = \json_encode($segmentBlueprints, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $sourceTruthLine = $this->buildStageOneSourceTruthPromptLine($scope);
        $orderedBlockKeys = \is_array($skeleton['ordered_block_keys'] ?? null) ? \array_values(\array_map('strval', $skeleton['ordered_block_keys'])) : [];
        $firstOrderedBlockKey = \trim((string)($orderedBlockKeys[0] ?? ($blockBudget['required'][0] ?? '')));
        $isPolicyPage = \in_array($pageType, ['privacy_policy', 'terms_of_service', 'refund_policy', 'shipping_policy', 'cookie_policy'], true);
        $generatedImageTargetKey = \trim((string)($skeleton['generated_image_target_block_key'] ?? ''));
        if ($generatedImageTargetKey === '' && !$isPolicyPage) {
            foreach ($orderedBlockKeys as $candidateKey) {
                $candidateKey = \trim((string)$candidateKey);
                if ($candidateKey === '') {
                    continue;
                }
                if (\preg_match('/(?:hero|showcase|feature|origin|contact|support|article|category|story|media|game)/i', $candidateKey) === 1) {
                    $generatedImageTargetKey = $candidateKey;
                    break;
                }
            }
            if ($generatedImageTargetKey === '') {
                $generatedImageTargetKey = $firstOrderedBlockKey;
            }
        }
        $firstBlockSegmentRule = (!$isPolicyPage && $firstOrderedBlockKey !== '' && $firstOrderedBlockKey !== $generatedImageTargetKey && \in_array($firstOrderedBlockKey, $segmentKeys, true))
            ? 'First-block media guidance for this segment: block_key=' . $firstOrderedBlockKey . ' may use CSS-only visual planning when that fits the page narrative. Ensure the page still has at least one generated-image block elsewhere when this first block is CSS-only.'
            : '';
        $generatedImageSegmentRule = (!$isPolicyPage && $generatedImageTargetKey !== '' && \in_array($generatedImageTargetKey, $segmentKeys, true))
            ? 'Generated-image assignment for this segment: block_key=' . $generatedImageTargetKey . ' is the page-level generated_image_target_block_key. This block MUST set image_intent.needs_image=true, use a concrete generated scene/product/interface/editorial subject, and visual_signature.media_strategy must describe the real image integration without CSS-only/no generated image wording.'
            : (!$isPolicyPage && $generatedImageTargetKey !== ''
                ? 'Generated-image assignment for this page: block_key=' . $generatedImageTargetKey . ' carries the page-level generated image in another segment. Blocks in this segment may use generated images only when their own blueprint needs one; otherwise use complete CSS-only motifs.'
                : '');
        $blockReturnExamplesJson = $this->stageOneBlockReturnExamplesJson();
        $fieldPlanIntentExamplesJson = $this->stageOneFieldPlanIntentExamplesJson();
        $visualSignatureExamplesJson = $this->stageOneVisualSignatureExamplesJson();
        $cssOnlyImageIntentExamplesJson = $this->stageOneCssOnlyImageIntentExamplesJson();
        $segmentMediaDecisionExamplesJson = $this->stageOneSegmentMediaDecisionExamplesJson($segmentKeys, $generatedImageTargetKey);
        $hasCtaLikeSegment = false;
        foreach ($segmentKeys as $segmentKey) {
            if (\preg_match('/(?:cta|download|install|action|conversion)/i', $segmentKey) === 1) {
                $hasCtaLikeSegment = true;
                break;
            }
        }
        $ctaSegmentRule = $hasCtaLikeSegment
            ? 'CTA field-plan rule for this segment: blocks with page_flow_role=cta must use row 0 field=headline, row 1 field=supporting_copy, and row 2 field=cta_label or action_label or button_text with a non-empty visitor-facing action label. Blocks that are support/details/article/category content may keep row 2 as context_detail, proof_detail, image_brief, form_label, or policy_summary.'
            : '';

        return \implode("\n", \array_values(\array_filter([
            ...$this->buildStageOnePromptRolePrelude(),
            '銆愮郴缁熸彁绀鸿瘝銆?',
            'You are PageBuilder Stage-1 BLOCK SEGMENT planner. Generate full Stage-1 block objects only for the requested keys. The assembler will combine segments without changing your block content.',
            'Return STRICT JSON only. Accepted shape: {"blocks":[...]}',
            'Page type: ' . $pageType,
            'Target block keys for this segment: ' . \json_encode($segmentKeys, \JSON_UNESCAPED_UNICODE) . '. Output exactly these keys, in this order, no extra blocks.',
            'Full page block budget for context only: target=' . (string)($blockBudget['target'] ?? 0) . ', required=' . \json_encode($blockBudget['required'] ?? [], \JSON_UNESCAPED_UNICODE) . ', optional=' . \json_encode($blockBudget['optional'] ?? [], \JSON_UNESCAPED_UNICODE) . '.',
            'Plan locale: ' . ($planLocale !== '' ? $planLocale : 'zh_Hans_CN'),
            'Website content locale: ' . ($contentLocale !== '' ? $contentLocale : ($planLocale !== '' ? $planLocale : 'zh_Hans_CN')),
            'Language rule: content, core_copy, field_plan.sample, CTA labels, alt text, and media descriptions must use Website content locale except brand/product acronyms such as APK/APP/SEO.',
            'Visible-copy rule: content, field_plan.sample, and execution_script.core_copy are visitor-facing copy seeds. Do not put layout recipes, hover notes, CSS values, or why-this-block explanations in those fields.',
            'Output budget: each block.content/core_copy <= 110 chars; feature_points max 2 and <= 12 chars; field_plan.sample <= 56 chars; implementation_note <= 32 chars; visual_signature values <= 56 chars; design_tags arrays max 2 items.',
            'Creativity rule: obey the block schema but invent the block-specific composition, motion, surface, and visitor message from the page goal. Do not copy examples verbatim or make every block a generic card grid.',
            'Image rule: non-policy pages need at least one generated scene/product/interface image somewhere in the page, but it does not have to be the first block. Every image_intent must include all required keys. If needs_image=false, visual_signature.media_strategy must start with "CSS-only/no generated image" and image_intent.css_motif/visual_atmosphere/image_treatment must be filled.',
            $generatedImageSegmentRule,
            'Media decision examples for this exact segment: ' . $segmentMediaDecisionExamplesJson . '. Use this to choose generated-image vs CSS-only path before writing the block JSON; rewrite the wording for the current brand and block.',
            'needs_image type rule: image_intent.needs_image MUST be the JSON boolean true or false. Never return "yes", "no", "maybe", "optional", "CSS-only", an empty string, or explanatory text; put visual planning detail in media_strategy/css_motif/visual_atmosphere/image_treatment.',
            'needs_image GOOD/BAD examples: GOOD {"needs_image":true}; GOOD {"needs_image":false}; BAD {"needs_image":"CSS-only"}; BAD {"needs_image":"no"}; BAD {"needs_image":{"value":false}}.',
            'Image intent location rule: output image_intent only at block top level. Do not output visual.image_intent, nested image_intent copies, rationale, reason, or why fields.',
            'Image intent examples: generated {"needs_image":true,"image_role":"hero_image","image_subject":"phone APK install screen with Teen Patti table behind it","placement":"media_panel","visual_atmosphere":"warm trusted casino lobby mood","image_treatment":"rounded phone mockup with amber overlay","reuse_policy":"reuse_when_intent_matches","css_motif":""}; CSS-only {"needs_image":false,"image_role":"css_motif","image_subject":"none","placement":"background_layer","visual_atmosphere":"secure premium dark-card mood","image_treatment":"CSS gradient cards and border glow only","reuse_policy":"no_generated_image","css_motif":"amber grid badges with neon trust outlines"}',
            'CSS-only image_intent examples by common block_key: ' . $cssOnlyImageIntentExamplesJson,
            'Icon/decorative visual boundary: small icons, badges, arrows, dividers, chips, rating stars, initials, and abstract marks should normally be CSS/SVG/icon-font motifs inside design_tags or css_motif with needs_image=false. Use needs_image=true only for a real generated scene/product/interface/editorial visual, not for an isolated icon.',
            'Image intent consistency rule: never set image_intent.needs_image=true while any media field says CSS-only/no generated image/no image. Choose generated-image path or CSS-only path, not both.',
            'CSS-only media assets rule: when image_intent.needs_image=false, execution_script.media_assets MUST be an empty array []. Do not write avatar, photo, image, screenshot, mockup, scene, or generated asset names for CSS-only review/proof/FAQ/CTA blocks.',
            'Media path BAD examples: BAD {"image_intent":{"needs_image":false},"visual_signature":{"media_strategy":"CSS-only/no generated image; review cards"},"execution_script":{"media_assets":["review avatar photo"]}}; BAD {"image_intent":{"needs_image":true},"visual_signature":{"media_strategy":"CSS-only/no generated image; badge cards"}}.',
            'Block object hard gate: every block must include page_flow_role, design_tags with all required keys, visual_signature with all five keys, image_intent with all eight keys, exactly three field_plan rows, and execution_script.core_copy. Do not omit these objects for review, FAQ, policy, blog, list, or CTA blocks.',
            'Visual signature completeness rule: visual_signature.composition_pattern, spatial_rhythm, media_strategy, surface_treatment, and interaction_pattern must all be non-empty concrete text. Never use empty string, "none", "same as above", or a schema placeholder for any visual_signature field. If the block is static, still describe the static layout, scan rhythm, CSS media strategy, surface, and focus/hover behavior.',
            'Visual signature examples (copy the shape, not the content; adapt to this exact block): ' . $visualSignatureExamplesJson,
            'CTA field rule: if page_flow_role=cta, row 2 field must be exactly cta_label, action_label, or button_text with a visitor-facing action label. Do not force article/category/contact support blocks into CTA shape unless their page_flow_role is cta.',
            'Field plan intent examples (choose fields by block intent, not by block_key text alone): ' . $fieldPlanIntentExamplesJson,
            'Generated image subject rule: generated-image blocks must use a real generated scene/product/interface subject, never an icon, logo, badge, shield, sparkle, glyph, arrow, coin, or abstract mark.',
            $firstBlockSegmentRule,
            $ctaSegmentRule,
            'Policy/legal safety: if this is a policy page, body blocks must be policy/support/rights focused and must not use download/install/play/register/claim/reward/bonus/coins conversion copy.',
            'Visible copy boundary: content fields are final website copy seeds. Do not output prompt instructions, blueprint explanations, validator wording, field names, layout recipes, or internal labels as visitor-facing copy.',
            '銆愮敤鎴锋彁绀鸿瘝銆態rief: ' . ($brief !== '' ? $brief : '-'),
            '銆愮敤鎴锋彁绀鸿瘝銆慖nstruction: ' . ($instruction !== '' ? $instruction : '-'),
            '銆愰€氱敤鎻愮ず璇嶃€?',
            'Target scope: ' . ($targetScope !== '' ? $targetScope : '-'),
            'Shared theme_design: ' . $themeDesign,
            'Theme-level page overview: ' . $pageTypeOverview,
            'Page skeleton from previous AI step: ' . $skeletonJson,
            'Segment block blueprints from previous AI step: ' . $segmentBlueprintJson,
            'Reference image insights: ' . $this->buildReferenceImageInsightsPromptText($scope),
            $sourceTruthLine,
            $recoveryInstruction !== '' ? 'Recovery instruction: ' . $this->clipText($recoveryInstruction, 1200) : '',
            'Schema for every block:',
            '{"blocks":[{"block_key":"string","page_flow_role":"opening|proof|details|cta|support","goal":"string","keywords":["string"],"content":"string","design_tags":{"visual":["string"],"motion":["string"],"interaction":["string"],"texture":["string"],"responsive":["string"],"color_layering":"string","implementation_note":"string"},"visual_signature":{"composition_pattern":"string","spatial_rhythm":"string","media_strategy":"string","surface_treatment":"string","interaction_pattern":"string"},"image_intent":{"needs_image":true,"image_role":"hero_image|section_image|trust_brand_image|css_motif|none","image_subject":"specific scene/product/editorial subject or none for CSS-only","placement":"background_layer|media_panel|inline_visual|none","visual_atmosphere":"specific mood, environment, lighting, and brand feel","image_treatment":"specific crop, style, framing, overlay, and integration treatment","reuse_policy":"reuse_when_intent_matches|no_generated_image","css_motif":"required concrete CSS motif when needs_image=false, empty string when generated image is needed"},"field_plan":[{"field":"headline","sample":"string","implementation_note":"string"},{"field":"supporting_copy","sample":"string","implementation_note":"string"},{"field":"context_detail","sample":"string","implementation_note":"string"}],"execution_script":{"feature_points":["string"],"core_copy":"string","typography":"string","style_tone":"string","background_direction":"string","media_assets":["string"]},"reusable":"yes|no","seo_impact":"high|medium|low"}]}',
            'Complete block examples (copy the shape, not the exact content; rewrite for this page/locale/block): ' . $blockReturnExamplesJson,
            'Field key rule: the schema shows context_detail as the default third row. For cta-role blocks replace row 2 field with exactly one action key such as cta_label, action_label, or button_text. For proof/image/form/policy blocks choose one concrete key such as proof_detail, image_brief, form_label, or policy_summary. Do not output a pipe-separated union field name.',
            'Self-check before return: every requested key appears once; no generic block_key; exactly 3 field_plan rows; cta-role blocks have a cta_label/action_label/button_text field with non-empty sample; execution_script.core_copy is non-empty visitor copy; all five visual_signature values are non-empty; image_intent is complete; adjacent segment blocks must not share the same composition/surface/media pattern.',
        ], static fn(string $line): bool => \trim($line) !== '')));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildStageOneSourceTruthPromptLine(array $scope): string
    {
        $sourceTruthContract = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        if ($sourceTruthContract === []) {
            return '';
        }

        $factsForPrompt = [];
        foreach (\is_array($sourceTruthContract['must_include_facts'] ?? null) ? $sourceTruthContract['must_include_facts'] : [] as $fact) {
            if (\is_array($fact)) {
                $text = \trim((string)($fact['text'] ?? ''));
                if ($text !== '') {
                    $factsForPrompt[] = $text;
                }
            }
        }

        return 'Source/context facts for planning only (not a factuality gate). Reuse them naturally when they help the block, but never paste prompt or blueprint text as visible copy. Facts: '
            . \json_encode($factsForPrompt, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            . '; visual must-honor: '
            . \json_encode($sourceTruthContract['visual_must_honor'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            . '; conversion goals: '
            . \json_encode($sourceTruthContract['conversion_goals'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function extractStageOneAiPageSkeleton(array $decoded, string $pageType): array
    {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        if (\is_array($decoded['page'] ?? null)) {
            return $decoded['page'];
        }
        if (\is_array($decoded['page_skeleton'] ?? null)) {
            return $decoded['page_skeleton'];
        }
        if (\is_array($decoded['plan_json']['pages'][$pageType] ?? null)) {
            return $decoded['plan_json']['pages'][$pageType];
        }
        if (\is_array($decoded['pages'][$pageType] ?? null)) {
            return $decoded['pages'][$pageType];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function extractStageOneAiBlockSegment(array $decoded): array
    {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        foreach ([
            $decoded['blocks'] ?? null,
            $decoded['page']['blocks'] ?? null,
            $decoded['block_segment']['blocks'] ?? null,
            $decoded['segment']['blocks'] ?? null,
        ] as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            return \array_values(\array_filter($candidate, static fn($row): bool => \is_array($row)));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $skeleton
     * @param array<string, mixed> $blockBudget
     * @return list<string>
     */
    private function resolveStageOneSegmentedPageBlockKeys(array $skeleton, array $blockBudget): array
    {
        $target = \max(1, (int)($blockBudget['target'] ?? 0));
        $required = \array_values(\array_filter(\array_map('strval', \is_array($blockBudget['required'] ?? null) ? $blockBudget['required'] : []), static fn(string $value): bool => \trim($value) !== ''));
        $optional = \array_values(\array_filter(\array_map('strval', \is_array($blockBudget['optional'] ?? null) ? $blockBudget['optional'] : []), static fn(string $value): bool => \trim($value) !== ''));
        $allowed = \array_fill_keys(\array_merge($required, $optional), true);
        $candidates = [];

        foreach (\is_array($skeleton['ordered_block_keys'] ?? null) ? $skeleton['ordered_block_keys'] : [] as $key) {
            $candidates[] = (string)$key;
        }
        foreach (\is_array($skeleton['block_blueprints'] ?? null) ? $skeleton['block_blueprints'] : [] as $row) {
            if (\is_array($row)) {
                $candidates[] = (string)($row['block_key'] ?? '');
            }
        }
        foreach (\is_array($skeleton['blocks'] ?? null) ? $skeleton['blocks'] : [] as $row) {
            if (\is_array($row)) {
                $candidates[] = (string)($row['block_key'] ?? '');
            }
        }

        $ordered = [];
        $seen = [];
        $firstRequiredKey = \trim((string)($required[0] ?? ''));
        if ($firstRequiredKey !== '' && isset($allowed[$firstRequiredKey])) {
            $ordered[] = $firstRequiredKey;
            $seen[$firstRequiredKey] = true;
        }
        foreach ($candidates as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '' || !isset($allowed[$candidate]) || isset($seen[$candidate])) {
                continue;
            }
            $ordered[] = $candidate;
            $seen[$candidate] = true;
        }
        foreach ($optional as $optionalKey) {
            if (\count($ordered) >= $target) {
                break;
            }
            if (!isset($seen[$optionalKey])) {
                $ordered[] = $optionalKey;
                $seen[$optionalKey] = true;
            }
        }
        if (\count($ordered) <= $target) {
            return $ordered;
        }

        $requiredSet = \array_fill_keys($required, true);
        $trimmed = [];
        foreach ($ordered as $key) {
            if (isset($requiredSet[$key])) {
                $trimmed[] = $key;
            }
        }
        foreach ($ordered as $key) {
            if (\count($trimmed) >= $target) {
                break;
            }
            if (!isset($requiredSet[$key]) && !\in_array($key, $trimmed, true)) {
                $trimmed[] = $key;
            }
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $skeleton
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOneSegmentedBlueprintMap(array $skeleton): array
    {
        $map = [];
        foreach ([
            \is_array($skeleton['block_blueprints'] ?? null) ? $skeleton['block_blueprints'] : [],
            \is_array($skeleton['blocks'] ?? null) ? $skeleton['blocks'] : [],
        ] as $rows) {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $blockKey = \trim((string)($row['block_key'] ?? ''));
                if ($blockKey === '') {
                    continue;
                }
                $map[$blockKey] = $row;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $blueprintsByKey
     * @param list<string> $segmentKeys
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOneSegmentedBlueprintSubset(array $blueprintsByKey, array $segmentKeys): array
    {
        $subset = [];
        foreach ($segmentKeys as $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '') {
                continue;
            }
            $subset[$blockKey] = \is_array($blueprintsByKey[$blockKey] ?? null)
                ? $blueprintsByKey[$blockKey]
                : ['block_key' => $blockKey];
        }

        return $subset;
    }

    /**
     * @param array<string, mixed> $skeleton
     * @return array<string, mixed>
     */
    private function clipStageOneSegmentedSkeletonForPrompt(array $skeleton): array
    {
        return [
            'page_goal' => $this->clipText(\trim((string)($skeleton['page_goal'] ?? '')), 160),
            'theme_alignment_summary' => $this->clipText(\trim((string)($skeleton['theme_alignment_summary'] ?? '')), 160),
            'page_design_plan' => \is_array($skeleton['page_design_plan'] ?? null) ? $skeleton['page_design_plan'] : [],
            'ordered_block_keys' => \is_array($skeleton['ordered_block_keys'] ?? null) ? $skeleton['ordered_block_keys'] : [],
            'generated_image_target_block_key' => \trim((string)($skeleton['generated_image_target_block_key'] ?? '')),
        ];
    }

    private function hasStageOneRetryablePageFailure(array $scope, string $pageKey): bool
    {
        return \is_array($scope['retryable_ai_failures']['plan']['items'][$pageKey] ?? null);
    }

    private function resolveStageOneRetryablePageFailureMessage(array $scope, string $pageKey): string
    {
        $item = \is_array($scope['retryable_ai_failures']['plan']['items'][$pageKey] ?? null)
            ? $scope['retryable_ai_failures']['plan']['items'][$pageKey]
            : [];

        return \trim((string)($item['message'] ?? ''));
    }

    private function buildStageOneRetryablePageFailureInstruction(array $scope, string $pageKey): string
    {
        $item = \is_array($scope['retryable_ai_failures']['plan']['items'][$pageKey] ?? null)
            ? $scope['retryable_ai_failures']['plan']['items'][$pageKey]
            : [];
        if ($item === []) {
            return '';
        }

        $summary = \trim((string)($item['validation_summary'] ?? ''));
        $message = \trim((string)($item['message'] ?? $item['error'] ?? ''));
        $issues = \is_array($item['validation_issues'] ?? null) ? $item['validation_issues'] : [];
        $parts = [];
        if ($summary !== '') {
            $parts[] = 'Previous retryable page failure validation summary: ' . $summary . '.';
        } elseif ($message !== '') {
            $parts[] = 'Previous retryable page failure: ' . $this->clipText($message, 500);
        }
        foreach ($this->buildStageOneIssueSpecificRecoveryRules($issues) as $rule) {
            $parts[] = $rule;
        }

        return $parts === [] ? '' : $this->clipText(\implode(' ', $parts), 1400);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildStageOneRetryablePageFailure(string $pageType, string $message, array $context = []): array
    {
        $message = \trim($message);
        $failureSource = $this->resolveStageOneRetryableFailureSource($message, $context);
        $validationSummary = \trim((string)($context['validation_summary'] ?? ''));
        $validationIssues = \is_array($context['validation_issues'] ?? null) ? $context['validation_issues'] : [];
        $aiResponseSummary = \trim((string)($context['ai_response_summary'] ?? ''));
        $detailLines = [];
        if ($validationSummary !== '') {
            $detailLines[] = '门禁摘要：' . $validationSummary;
        }
        if ($validationIssues !== []) {
            $detailLines[] = '门禁明细：' . $this->clipText(
                (string)(\json_encode(\array_slice($validationIssues, 0, 6), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: ''),
                900
            );
        }
        if ($aiResponseSummary !== '') {
            $detailLines[] = 'AI 产出摘要：' . $aiResponseSummary;
        }
        $composedMessage = $message;
        if ($detailLines !== []) {
            $composedMessage .= ' | ' . \implode(' | ', $detailLines);
        }

        return [
            'operation' => 'plan',
            'item_key' => $pageType,
            'item_type' => 'page_fanout',
            'retry_scope' => 'stage1_page',
            'page_type' => $pageType,
            'failure_source' => $failureSource,
            'failure_class' => $this->describeStageOneRetryableFailureClass($failureSource),
            'message' => $this->clipText($composedMessage, 1800),
            'error' => $this->clipText($message, 800),
            'validation_summary' => $validationSummary,
            'validation_issues' => \array_slice($validationIssues, 0, 12),
            'ai_response_summary' => $aiResponseSummary,
            'failed_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveStageOneRetryableFailureSource(string $message, array $context = []): string
    {
        $explicit = \trim((string)($context['failure_source'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $normalized = \mb_strtolower($message);
        if (
            \str_contains($normalized, 'undefined constant')
            || \str_contains($normalized, 'undefined method')
            || \str_contains($normalized, 'call to undefined')
            || \str_contains($normalized, 'fatal error')
        ) {
            return 'platform';
        }
        if (
            \str_contains($normalized, 'stage-one page fanout')
            || \str_contains($normalized, 'without usable blocks')
            || ($context['validation_issues'] ?? []) !== []
            || \trim((string)($context['validation_summary'] ?? '')) !== ''
        ) {
            return 'gate_checkpoint';
        }

        return 'ai_generation';
    }

    private function describeStageOneRetryableFailureClass(string $failureSource): string
    {
        return match ($failureSource) {
            'platform' => '平台/代码异常（非 AI 文案问题）',
            'gate_checkpoint' => '阶段一门禁/契约校验未通过',
            'ai_generation' => 'AI 生成或解析失败',
            default => '阶段一页面生成失败',
        };
    }

    /**
     * @param array<string, mixed> $pagePlan
     */
    private function summarizeStageOneAiPagePlanForFailureDiagnostics(array $pagePlan, string $pageType): string
    {
        if ($pagePlan === []) {
            return 'AI 未返回可用 page 对象。';
        }
        $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
        $blockKeys = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? ''));
            if ($blockKey !== '') {
                $blockKeys[] = $blockKey;
            }
        }

        return $this->clipText(
            (string)(\json_encode([
                'page_type' => $pageType,
                'page_goal' => \trim((string)($pagePlan['page_goal'] ?? '')),
                'blocks_count' => \count($blocks),
                'block_keys' => \array_slice(\array_values(\array_unique($blockKeys)), 0, 12),
                'has_page_design_plan' => \is_array($pagePlan['page_design_plan'] ?? null) && ($pagePlan['page_design_plan'] ?? []) !== [],
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: ''),
            700
        );
    }

    /**
     * @param array<string, mixed> $fanoutAttempt
     */
    private function buildStageOneRetryablePageFailureFromFanoutAttempt(string $pageType, string $fallbackMessage, array $fanoutAttempt = []): array
    {
        $validationSummary = \trim((string)($fanoutAttempt['final_failure_summary'] ?? $fanoutAttempt['primary_failure_summary'] ?? ''));
        $validationIssues = \is_array($fanoutAttempt['final_issues'] ?? null) && ($fanoutAttempt['final_issues'] ?? []) !== []
            ? $fanoutAttempt['final_issues']
            : (\is_array($fanoutAttempt['primary_issues'] ?? null) ? $fanoutAttempt['primary_issues'] : []);
        $message = \trim($fallbackMessage);
        if ($message === '' && $validationSummary !== '') {
            $message = '阶段一页面未通过契约校验：' . $validationSummary;
        }

        return $this->buildStageOneRetryablePageFailure($pageType, $message, [
            'validation_summary' => $validationSummary,
            'validation_issues' => $validationIssues,
            'ai_response_summary' => \trim((string)($fanoutAttempt['ai_response_summary'] ?? '')),
        ]);
    }

    /**
     * @param list<string> $pageTypes
     * @param callable(string):void|null $onChunk
     * @param callable(array<string, mixed>):void|null $onProgress
     * @return array<string, mixed>
     */
    private function generateStageOnePagePlansByAiSequential(
        array $scope,
        array $websiteProfile,
        array $planJson,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array {
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $results = [];
        $totalPages = \count($pageTypes);
        $completedPages = 0;
        $adapterRequestParams = $this->buildStageOneAiAdapterRequestParams($scope, $websiteProfile);
        foreach ($pageTypes as $pageIndex => $pageType) {
            $pageKey = (string)$pageType;
            $pageOrder = (int)$pageIndex + 1;
            $this->emitStageOnePipelineProgress(
                $onProgress,
                $this->buildStageOnePagePipelineMessage(false, $pageOrder, $totalPages, $pageKey),
                60,
                'page_plan',
                'start',
                ['page_type' => $pageKey]
            );
            $useSegmentedPagePlan = $this->shouldUseSegmentedStageOnePagePlan($pageKey, $scope);
            if ($useSegmentedPagePlan) {
                $pagePlan = $this->generateStageOneSegmentedPagePlanByAi(
                    $scope,
                    $websiteProfile,
                    $planJson,
                    $pageKey,
                    $planLocale,
                    $contentLocale,
                    $instruction,
                    $targetScope,
                    $adapterRequestParams,
                    $onChunk,
                    $onProgress
                );
            } else {
                $decoded = $this->generateStageOneJsonByAi(
                    $this->buildAiStageOnePagePrompt($scope, $websiteProfile, $planJson, $pageKey, $planLocale, $contentLocale, $instruction, $targetScope),
                    'pagebuilder_plan_generation',
                    self::STAGE_ONE_PAGE_PLAN_MAX_TOKENS,
                    240,
                    $onChunk,
                    $adapterRequestParams
                );
                $pagePlan = $this->extractStageOneAiPagePlan($decoded, $pageKey);
            }
            $pagePlan = $this->normalizeStageOneAiPagePlanWithBaseline(
                $pagePlan,
                \is_array($pages[$pageKey] ?? null) ? $pages[$pageKey] : [],
                $pageKey
            );
            $stageOneContract = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []);
            if (!$this->hasStageOnePagePlanCheckpoint([$pageKey => $pagePlan], $pageKey, $stageOneContract, $scope)) {
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    'Page plan contract failed; retrying strict recovery for page: ' . $pageKey,
                    78,
                    'page_plan',
                    'retry',
                    ['page_type' => $pageKey]
                );
                if ($useSegmentedPagePlan) {
                    $pagePlan = $this->generateStageOneSegmentedPagePlanByAi(
                        $scope,
                        $websiteProfile,
                        $planJson,
                        $pageKey,
                        $planLocale,
                        $contentLocale,
                        $instruction,
                        $targetScope,
                        $adapterRequestParams,
                        $onChunk,
                        $onProgress,
                        'Previous segmented response did not contain usable blocks with valid block copy, field_plan rows, and execution_script.core_copy.'
                    );
                } else {
                    $decoded = $this->generateStageOneJsonByAi(
                        $this->buildAiStageOnePageRecoveryPrompt(
                            $scope,
                            $websiteProfile,
                            $planJson,
                            $pageKey,
                            $planLocale,
                            $contentLocale,
                            $instruction,
                            $targetScope,
                            'Previous response did not contain a usable non-empty blocks array with valid block copy, field_plan rows, and execution_script.core_copy.',
                            $pagePlan
                        ),
                        'pagebuilder_plan_generation',
                        self::STAGE_ONE_PAGE_PLAN_MAX_TOKENS,
                        240,
                        $onChunk,
                        $adapterRequestParams
                    );
                    $pagePlan = $this->extractStageOneAiPagePlan($decoded, $pageKey);
                }
                $pagePlan = $this->normalizeStageOneAiPagePlanWithBaseline(
                    $pagePlan,
                    \is_array($pages[$pageKey] ?? null) ? $pages[$pageKey] : [],
                    $pageKey
                );
            }
            if ($this->hasStageOnePagePlanCheckpoint([$pageKey => $pagePlan], $pageKey, $stageOneContract, $scope)) {
                $results[$pageKey] = $pagePlan;
            } else {
                throw new \RuntimeException('Stage-one page plan AI failed for ' . $pageKey . ': returned no usable blocks.');
            }
            $completedPages++;
            $pageProgress = 60 + (int)\floor(($completedPages / \max(1, $totalPages)) * 22);
            $this->emitStageOnePipelineProgress(
                $onProgress,
                $this->buildStageOnePagePipelineMessage(true, $completedPages, $totalPages, $pageKey),
                $pageProgress,
                'page_plan',
                'done',
                ['page_type' => $pageKey]
            );
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function extractStageOneAiPagePlan(array $decoded, string $pageType): array
    {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        if (\is_array($decoded['plan_json']['pages'][$pageType] ?? null)) {
            return $decoded['plan_json']['pages'][$pageType];
        }
        if (\is_array($decoded['pages'][$pageType] ?? null)) {
            return $decoded['pages'][$pageType];
        }
        if (\is_array($decoded['page'] ?? null)) {
            return $decoded['page'];
        }
        if (\is_array($decoded['page_plan'] ?? null)) {
            return $decoded['page_plan'];
        }
        if (\is_array($decoded['blocks'] ?? null) && \is_array($decoded['field_plan'] ?? null) === false) {
            return $decoded;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $baselinePage
     * @return array<string, mixed>
     */
    private function normalizeStageOneAiPagePlanWithBaseline(array $pagePlan, array $baselinePage, string $pageType): array
    {
        if ($pagePlan === []) {
            return [];
        }

        $normalized = $pagePlan;
        foreach (['page_goal', 'page_label', 'page_title', 'nav_label', 'meta_title', 'meta_description'] as $key) {
            $candidate = \trim((string)($normalized[$key] ?? ''));
            $isWeakPageGoal = $key === 'page_goal' && $this->isWeakStageOnePageGoal($candidate, $pageType);
            if ($candidate === '' || $isWeakPageGoal || $this->isPromptLikeStageOneText($candidate, $key, '', '', $pageType)) {
                unset($normalized[$key]);
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function finalizeStageOneAiPagePlanForCheckpoint(
        array $pagePlan,
        string $pageType,
        array $contract,
        array $scope,
        string $planLocale,
        string $contentLocale
    ): array {
        $contract = $this->getStageOneContractService()->normalize(
            $contract,
            $scope,
            [$pageType],
            $planLocale,
            $contentLocale,
            'page_fanout_finalize'
        );
        if ($pagePlan === []) {
            return [];
        }

        $pageGoal = \trim((string)($pagePlan['page_goal'] ?? ''));
        if ($pageGoal === '' || $this->isWeakStageOnePageGoal($pageGoal, $pageType)) {
            $pagePlan['page_goal'] = $this->buildStageOneConcretePageGoal(
                $pageType,
                (string)($pagePlan['page_label'] ?? $pagePlan['page_title'] ?? ''),
                $planLocale
            );
        }
        $themeAlignmentSummary = \trim((string)($pagePlan['theme_alignment_summary'] ?? ''));
        if ($themeAlignmentSummary === '' || $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', $pageType)) {
            $pagePlan['theme_alignment_summary'] = $this->buildStageOneThemeAlignmentSummaryFromPlanJson(
                $pageType,
                $pagePlan,
                ['theme_design' => \is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : []],
                $planLocale
            );
        }
        if (!\is_array($pagePlan['page_design_plan'] ?? null) || ($pagePlan['page_design_plan'] ?? []) === []) {
            $pagePlan['page_design_plan'] = $this->normalizeStageOnePageDesignPlan($pageType, $pagePlan, [
                'page_type_overviews' => [
                    $pageType => [
                        'page_role' => $pageType,
                        'content_focus' => (string)($pagePlan['page_goal'] ?? ''),
                    ],
                ],
                'theme_design' => \is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : [],
            ]);
        }

        $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
        $pagePlan['blocks'] = $this->repairAiStageOneBlocksBeforeValidation(
            $blocks,
            $pageType,
            $planLocale,
            ['theme_design' => \is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : []],
            \is_array($pagePlan['page_design_plan'] ?? null) ? $pagePlan['page_design_plan'] : []
        );
        $pagePlan['blocks'] = $this->ensureStageOneVisualSignatureDiversityForValidation(
            \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []
        );

        return $pagePlan;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function ensureStageOneVisualSignatureDiversityForValidation(array $blocks): array
    {
        $previousFingerprint = '';
        $compositionCounts = [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $signature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
            if ($signature === []) {
                continue;
            }
            $fingerprint = $this->stageOneVisualSignatureDiversityFingerprint($signature);
            $composition = \mb_strtolower(\trim((string)($signature['composition_pattern'] ?? '')));
            $compositionSeen = $composition !== '' ? (int)($compositionCounts[$composition] ?? 0) : 0;
            $needsDistinctTag = ($fingerprint !== '' && $fingerprint === $previousFingerprint)
                || ($composition !== '' && $compositionSeen >= 2);
            if ($needsDistinctTag) {
                $variationToken = $this->buildStageOneVisualSignatureVariationToken($block, (int)$index);
                foreach (['composition_pattern', 'surface_treatment', 'spatial_rhythm'] as $field) {
                    $value = \trim((string)($signature[$field] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    if (!\str_contains(\mb_strtolower($value), \mb_strtolower($variationToken))) {
                        $signature[$field] = $this->clipText($value . ' - ' . $variationToken, 90);
                    }
                }
                $blocks[$index]['visual_signature'] = $signature;
                $fingerprint = $this->stageOneVisualSignatureDiversityFingerprint($signature);
                $composition = \mb_strtolower(\trim((string)($signature['composition_pattern'] ?? '')));
            }

            if ($composition !== '') {
                $compositionCounts[$composition] = (int)($compositionCounts[$composition] ?? 0) + 1;
            }
            $previousFingerprint = $fingerprint;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildStageOneVisualSignatureVariationToken(array $block, int $index): string
    {
        $blockKey = \preg_replace('/[^a-z0-9_]+/i', '_', \trim((string)($block['block_key'] ?? ''))) ?? '';
        $blockKey = \trim($blockKey, '_');
        if ($blockKey === '') {
            $blockKey = 'block_' . ((int)$index + 1);
        }

        return 'variant_' . $blockKey;
    }

    /**
     * @param list<string> $pageTypes
     */
    private function buildStageOnePromptRolePrelude(): array
    {
        $lines = [
            '【提示词角色与优先级】',
            '- 【用户提示词】= 用户原始需求、用户后续指令、当前聚焦页面或区块目标；这是页面身份、内容重点、转化目标和视觉偏好的最高优先级。',
            '- 【系统提示词】= 输出 JSON/schema、阶段强契约、语言、本地化、可执行边界和提示词泄漏防护；这些约束保证规划能被后续构建消费。',
            '- 【通用提示词】= PageBuilder 底座规划规则、审美质量、视觉多样性和默认建议；它只能补足用户没有说明的部分。',
            '- 优先级规则：当【用户提示词】与【通用提示词】或系统中的设计/内容建议冲突时，以【用户提示词】为准；JSON/schema、语言、安全、可执行边界和提示词泄漏防护仍必须保持有效。',
        ];
        $lines[] = 'BuildPlan no-reason field rule: do not add extra explanatory keys named reason, why, rationale, thinking, analysis, explanation, chain_of_thought, design_reason, or reasoning anywhere unless the active schema explicitly lists that exact key. Theme selection_reason is allowed only in theme_style, palette, and theme_design when the schema lists it; do not invent selection_reason on pages, blocks, image_intent, visual_signature, field_plan, or execution_script.';
        $lines[] = 'Template scaffold translation rule: style templates, default configs, layout JSON, and examples are structural references only. Treat #download, #contact, #faq, href="#", placeholder URLs, fake media names, old brands, old CTA targets, and sample social/support links as stale scaffold values. Do not copy them into Stage-1 output; use exact route-contract paths when provided, otherwise omit the link or describe a button/event action.';
        return $lines;
    }

    /**
     * @param list<string> $pageTypes
     */
    private function buildAiStageOneRequirementExpansionPrompt(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $siteDisplayName,
        string $siteSummary
    ): string {
        $brief = $this->resolveStageOneRequirementSeed($scope, $websiteProfile, $pageTypes, $siteDisplayName, $siteSummary);
        return \implode("\n", [
            ...$this->buildStageOnePromptRolePrelude(),
            '【系统提示词】',
            'You are PageBuilder Stage-1 REQUIREMENT EXPANSION planner.',
            'Step 1 only: expand the user one-line requirement into a concrete website planning brief. Do not generate theme, Header/Footer, or page blocks.',
            'Decision order: first rewrite the one-line requirement into concrete business intent, then map intent to page-by-page roles, then derive technical direction.',
            'Creativity rule: expand the brief into concrete customer, business, site, conversion, and content intent. Use named users, offers, page roles, and technical direction from the brief; do not return generic labels or rule summaries.',
            'Return STRICT JSON only. Start with `{` and end with `}`. No markdown, no explanation, no reasoning text.',
            'JSON size rule: keep arrays short and values concise; use 1-2 sentence strings, not long essays.',
            'Output budget: target 5-8 target_users entries max 4 words each; page_strategy one row per selected page type; technical_direction 3-6 actionable bullets.',
            'Locale: ' . ($planLocale !== '' ? $planLocale : 'zh_Hans_CN'),
            'Site: ' . ($siteDisplayName !== '' ? $siteDisplayName : '-'),
            $this->buildStageOneIdentityGuardPrompt($scope, $websiteProfile, $siteDisplayName),
            '【用户提示词】User one-line requirement: ' . ($brief !== '' ? $brief : '-'),
            '【用户提示词】Instruction: ' . ($instruction !== '' ? $instruction : '-'),
            '【通用提示词】',
            'Selected page types: ' . \implode(', ', $pageTypes),
            'Site summary: ' . ($siteSummary !== '' ? $siteSummary : '-'),
            'Reference image insights (if any): ' . $this->buildReferenceImageInsightsPromptText($scope),
            'Visual contract rules (non-negotiable when present): '
                . \json_encode(
                    \is_array($scope['reference_image_insights']['visual_contract'] ?? null)
                        ? $scope['reference_image_insights']['visual_contract']
                        : [],
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                ),
            'You MUST convert visual_contract into requirement_expansion signals that theme_design and later page plans will implement.',
            'Every page_strategy row must cite which visual_contract elements matter for that page_type.',
            'Do not output visual patterns listed in forbidden_visuals.',
            'Policy-page conversion boundary: if selected page types include privacy_policy, terms_of_service, refund_policy, shipping_policy, or cookie_policy, page_strategy/conversion_strategy must not say every page body uses app download/APK/install/play/reward CTA. Keep policy page body goals focused on rights, rules, compliance, and support; global header/footer CTA can remain separate.',
            ...$this->buildStageOneGatePromptLines($pageTypes, $scope, 'requirement'),
            'Schema:',
            '{"requirement_expansion":{"original_brief":"string","expanded_brief":"string","planning_summary":"string","site_goal":"string","business_goals":["string"],"target_users":["string"],"business_context":"string","content_direction":"string","conversion_strategy":"string","primary_cta":"string","page_strategy":[{"page_type":"string","intent":"string","content_focus":"string","conversion_role":"string"}],"technical_direction":["string"]}}',
            'Example return shape (copy the structure, not the content; rewrite for the current brief): ' . $this->stageOneRequirementExpansionExampleJson(),
            'Hard rules: expanded_brief must be a larger concrete version of the user requirement; business_goals, content_direction, conversion_strategy, and primary_cta are required because they feed the editable plan overview; page_strategy must cover every selected page type; all values must be customer-visible planning content, not prompt instructions.',
            'Self-check before return: remove any sentence that still reads like "围绕/突出/说明/优化"; replace with named offers, nouns, and visible outcomes.',
        ]);
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $requirementExpansion
     */
    private function buildAiStageOneThemePrompt(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $siteDisplayName,
        string $siteSummary,
        array $requirementExpansion
    ): string {
        $brief = $this->resolveStageOneRequirementSeed($scope, $websiteProfile, $pageTypes, $siteDisplayName, $siteSummary);
        $requirementExpansionJson = \json_encode($requirementExpansion, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        return \implode("\n", [
            ...$this->buildStageOnePromptRolePrelude(),
            '【系统提示词】',
            'You are PageBuilder single-stage THEME planner.',
            'Single-stage shared pass: use the confirmed requirement expansion to generate the shared theme, Header, Footer, and compact page-type design overviews. Do not include page-specific block plans in this shared pass.',
            'Decision order: lock theme_design first, then shared Header/Footer content structure, then page_type_overviews. Do not reverse this order.',
            'Return STRICT JSON only. Start with `{` and end with `}`. No markdown, no explanation, no reasoning text.',
            'JSON size rule: compact object only; keep arrays short and avoid long narrative paragraphs.',
            'Theme compactness hard gate: total JSON should stay under 4500 characters. Every string must be one short sentence <= 80 chars; labels <= 20 chars; no paragraphs.',
            'Output budget: visual_keywords 4-6 items, forbidden_styles 3-5 items, navigation header_items max 6, footer links max 8, each page_type_overviews field <= 32 chars.',
            'i18n.labels are UI headings only, not plan content. Example labels: {"title":"Site theme","site":"Site","summary":"Summary","site_structure":"Structure","shared_global_plan":"Shared plan","page_details":"Pages"}.',
            'Do not output page blocks, block lists, legal prose, SEO essays, long Thai paragraphs, or detailed page copy in this theme pass. Page workers will generate those later.',
            'Confirmed requirement expansion from step 1: ' . $requirementExpansionJson,
            'Goal: produce the shared part of the one confirmed plan. Page-specific block plans may be generated by parallel fanout workers, but the final user-visible plan has only one confirmation stage.',
            'Plan locale: ' . ($planLocale !== '' ? $planLocale : 'zh_Hans_CN'),
            'Website content locale: ' . ($contentLocale !== '' ? $contentLocale : ($planLocale !== '' ? $planLocale : 'zh_Hans_CN')),
            'Language rule: Header/Footer labels, CTA labels, link labels, media text, and other customer-visible website copy MUST use Website content locale. Do not use Plan locale for visible website copy unless both locales are identical.',
            'Site: ' . ($siteDisplayName !== '' ? $siteDisplayName : '-'),
            '【用户提示词】Brief: ' . ($brief !== '' ? $brief : '-'),
            'Reference image insights (must be honored before theme decisions): ' . $this->buildReferenceImageInsightsPromptText($scope),
            'Visual contract rules (non-negotiable when present): '
                . \json_encode(
                    \is_array($scope['reference_image_insights']['visual_contract'] ?? null)
                        ? $scope['reference_image_insights']['visual_contract']
                        : [],
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                ),
            'You MUST convert visual_contract into theme_design.art_direction and the page design plans.',
            'Every page_design_plan must cite which visual_contract items it implements.',
            'Every block must map at least one visual_contract item or explicitly state not_applicable.',
            'Do not output visual patterns listed in forbidden_visuals.',
            '【用户提示词】Instruction: ' . ($instruction !== '' ? $instruction : '-'),
            '【通用提示词】',
            'Selected page types: ' . \implode(', ', $pageTypes),
            ...$this->buildStageOneGatePromptLines($pageTypes, $scope, 'theme'),
            'Schema:',
            '{"i18n":{"locale":"string","labels":{"title":"string","site":"string","summary":"string","site_structure":"string","shared_global_plan":"string","page_details":"string"}},"site_strategy":{"site_display_name":"string","summary":"string","website_type":"string","core_goal":"string","target_users":"string","content_strategy":"string","conversion_path":"string","primary_cta":"string"},"theme_style":{"name":"string","visual_tone":"string","font_family":"string","selection_reason":"internal fit summary, not shown as a plan reason"},"palette":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","surface":"#hex","text":"#hex","selection_reason":"internal palette fit summary, not shown as a plan reason"},"theme_design":{"theme_purpose":"string","style_signature":"brief-derived visual identity, not a generic theme name","reference_style_context":{"summary":"string","style_keywords":["string"],"color_palette":["#hex"],"layout_cues":["string"],"component_cues":["string"],"typography_cues":["string"],"do_not_use":["string"],"implementation_rule":"string"},"art_direction":{"layout_motif":"string","background_system":"string","surface_treatment":"string","visual_detail_rule":"string","motion_rule":"string"},"color_scheme":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","background":"#hex","body":"#hex","button":"#hex"},"typography_spacing_radius":{"font_family":"string","heading_scale":"string","body_scale":"string","spacing_scale":"string","radius_scale":"string"},"visual_keywords":["string"],"tone_of_voice":"string","cta_tone":"string","forbidden_styles":["string"],"selection_reason":"internal brief-alignment summary; must copy at least one exact noun/action phrase from Brief or Instruction"},"page_type_overviews":{"home_page":{"page_role":"string","content_focus":"string","theme_color_application":"string","section_layering_hint":"string","interaction_intent":"string","differentiation_note":"string"}},"navigation_plan":{"header_items":[{"label":"string","href":"string"}]},"footer_plan":{"featured":[{"label":"string","href":"string"}],"policies":[{"label":"string","href":"string"}]},"shared_components":{"header":{"component":"header","title":"string","goal":"string","implementation_detail":"string","realtime_content":{"headline":"string","supporting_copy":["string"],"cta":[{"label":"string","target":"string"}],"editable_slots":["string"]},"editable_fields":["string"],"responsive_rule":"string"},"footer":{"component":"footer","title":"string","goal":"string","implementation_detail":"string","realtime_content":{"headline":"string","supporting_copy":["string"],"cta":[{"label":"string","target":"string"}],"editable_slots":["string"]},"editable_fields":["string"],"responsive_rule":"string"}},"seo_strategy":{"core_intent":"string","primary_keywords":["string"],"keyword_page_map":[{"keyword":"string","page_type":"string"}],"content_strategy":"string","internal_linking":"string","url_structure":"string"}}',
            'Compact example style: {"i18n":{"locale":"th_TH","labels":{"title":"Site theme","site":"Teenipiya","summary":"Summary","site_structure":"Structure","shared_global_plan":"Shared plan","page_details":"Pages"}},"page_type_overviews":{"home_page":{"page_role":"conversion home","content_focus":"download trust proof","theme_color_application":"amber CTA on dark cards","section_layering_hint":"hero then proof zones","interaction_intent":"hover lift only","differentiation_note":"not legal layout"}}}',
            'Theme teaching example (copy the structure, not the content; invent the visual system from the current brief): ' . $this->stageOneThemeExampleJson(),
            'SEO strategy contract: seo_strategy is a REQUIRED top-level contract section in this shared pass. It is planning metadata, not visible website copy. It must include core_intent, 3-6 primary_keywords, keyword_page_map covering every selected page type at least once, content_strategy, internal_linking, and url_structure. Do not omit seo_strategy when compacting JSON.',
            'Hard rules: theme_design and shared_components.header/footer must be concrete implementation decisions derived from the expanded requirement; navigation_plan.header_items must include exact labels and route-contract hrefs for selected page types plus the primary CTA; footer_plan must include featured links, and must include policy/help links when policy pages are selected or trust/safety/support links to existing selected pages only when policy pages are not selected; shared_components.header/footer must include visible content, editable slots, responsive behavior, and implementation_detail; page_type_overviews must cover every selected page type with page role, content focus, theme color application, section layering hint, interaction intent, and differentiation note; these overviews are conceptual page planning only, not block lists; keep output compact and shorten strings before adding detail.',
            'Shape rule: output shared Header/Footer only as shared_components.header and shared_components.footer. Do not output shared_blocks in the theme response; shared_blocks are derived internally after the strict contract is accepted.',
            'Reference-image carryover rule: if Reference image insights are not "-", theme_design.reference_style_context MUST copy/adapt those insights and theme_design.style_signature, art_direction, color_scheme, typography_spacing_radius, visual_keywords, and forbidden_styles MUST visibly use them. Do not merely mention reference images as inspiration.',
            'Visual quality bar: theme_design.style_signature and art_direction are mandatory. They must describe a polished, customer-fit visual identity that a frontend generator can execute, including composition motif, background/texture system, surface treatment, detail language, and motion restraint.',
            'Customer-fit rule: do not default to a blue SaaS gradient, plain white cards, generic Inter/Roboto/system-font hierarchy, or centered hero plus three-card grid unless the user brief specifically asks for that look.',
            'Beauty rule: make the final website feel designed for a paying client. Select deliberate typography, layered backgrounds, tactile CTA states, inline SVG/CSS visual motifs, spacing/radius rhythm, and mobile composition that match the brief.',
            'Anti-monotony rule: page_type_overviews.theme_color_application and section_layering_hint must prevent an entire page from becoming one flat color; describe alternating surfaces/cards/gradients/contrast zones using the approved palette.',
            'Customer-fit evidence rule: style_signature must include at least two concrete nouns/actions from Brief or Instruction and turn them into visible design language; never choose a style that could fit any unrelated website.',
            'Interaction/effects rule: art_direction.motion_rule must name exact hover, focus, reveal, or ambient effects that are reduced-motion-safe and suitable for the customer scenario; do not write vague "smooth animation".',
            'Style-diversity rule: forbidden_styles must include the generic look most likely to be overused for this site category, and page_type_overviews must explain how each page avoids repeating the same hero/card composition.',
            'Theme completeness self-check: before returning, verify theme_design has theme_purpose, style_signature, color_scheme.primary/accent/background/body/button, typography_spacing_radius.font_family/spacing_scale, visual_keywords, tone_of_voice, cta_tone, forbidden_styles, and selection_reason. If any field is missing, shorten other strings instead of omitting required fields.',
            'Self-check before return: verify the top-level JSON contains site_strategy, theme_style, palette, theme_design, page_type_overviews, navigation_plan, footer_plan, shared_components, seo_strategy, and i18n. If header/footer labels, CTA labels, links, or seo_strategy fields are missing or abstract placeholders, rewrite before returning.',
        ]);
    }

    private function buildAiStageOnePagePrompt(
        array $scope,
        array $websiteProfile,
        array $planJson,
        string $pageType,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope
    ): string {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $requirementExpansion = \json_encode($planJson['requirement_expansion'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $themeDesign = \json_encode($planJson['theme_design'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $sharedComponents = \json_encode($planJson['shared_components'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $archivedStageOneContract = '{}';
        $pageTypeOverview = \json_encode($this->resolveStageOnePageTypeOverview($planJson, $pageType), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $normalizedTargetScope = \mb_strtolower(\trim($targetScope));
        $baselinePageForPrompt = [];
        if ($normalizedTargetScope !== '' && !\in_array($normalizedTargetScope, ['plan', 'full_plan'], true)) {
            $baselinePageForPrompt = \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [];
        }
        $baselinePage = \json_encode($baselinePageForPrompt, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $blockBudget = $this->resolveStageOneBlockBudget($pageType, $scope);
        $archivedStageOneContract = \json_encode([
            'page_type' => $pageType,
            'block_budget' => $blockBudget,
            'field_plan_count' => AiSiteStageOneContractService::FIELD_PLAN_COUNT,
            'visual_signature_keys' => AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
        $isPolicyPage = \in_array($pageType, [
            'privacy_policy',
            'terms_of_service',
            'refund_policy',
            'shipping_policy',
            'cookie_policy',
        ], true);
        $firstBlockImageGate = $isPolicyPage
            ? 'First-block generated media gate for this page: not required because this is a policy/legal page.'
            : 'First-block generated media gate for this page: not required. Place the page generated-image slot on the block where it best supports the narrative; blocks[0] may be CSS-only when its full CSS motif, visual_atmosphere, and image_treatment are declared.';
        $pageArchitectureGuide = $this->buildStageOnePageArchitectureGuide($pageType);
        $sourceTruthContract = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        $factsForPrompt = [];
        foreach (\is_array($sourceTruthContract['must_include_facts'] ?? null) ? $sourceTruthContract['must_include_facts'] : [] as $f) {
            if (\is_array($f)) {
                $factsForPrompt[] = (string)($f['text'] ?? '');
            }
        }
        $sourceTruthPromptLine = $sourceTruthContract !== []
            ? 'Source/context facts for planning only, not a hard factuality gate. Reuse naturally where useful and rewrite into visitor copy; never paste blueprint/prompt text. Facts: '
                . \json_encode($factsForPrompt, \JSON_UNESCAPED_UNICODE)
                . ' Visual must-honor: '
                . \json_encode($sourceTruthContract['visual_must_honor'] ?? [], \JSON_UNESCAPED_UNICODE)
                . ' Conversion goals: '
                . \json_encode($sourceTruthContract['conversion_goals'] ?? [], \JSON_UNESCAPED_UNICODE)
            : '';
        $blockReturnExamplesJson = $this->stageOneBlockReturnExamplesJson();
        $fieldPlanIntentExamplesJson = $this->stageOneFieldPlanIntentExamplesJson();
        $visualSignatureExamplesJson = $this->stageOneVisualSignatureExamplesJson();

        return \implode("\n", [
            ...$this->buildStageOnePromptRolePrelude(),
            '【系统提示词】',
            'You are PageBuilder single-stage PAGE planner.',
            'Single-stage page fanout: generate exactly this page type by carrying the confirmed requirement expansion, theme, Header, and Footer. Other page types may be generated in parallel, but the final plan is confirmed once.',
            'Decision order: first page_design_plan, then blocks, then field_plan + execution_script; never draft block copy before page_design_plan is complete.',
            'Creativity rule: the contract is a frame, not a template. Use the required fields to create a distinctive page plan: page-specific block rhythm, visual signature, media strategy, motion idea, texture, and conversion/support flow. Do not merely comply with a checklist.',
            'Return STRICT JSON only for exactly one page. Start with `{` and end with `}`. Do not return other pages, markdown, explanation, or reasoning text.',
            'Accepted output shape: either {"page":{...}} or the page object itself. In both cases the page object MUST include a non-empty blocks array. Do not return only page_design_plan, field_plan, a summary, or a contract checklist without blocks.',
            ...$this->buildStageOneGatePromptLines([$pageType], $scope, 'page', $pageType),
            'First-pass acceptance gate: this response must pass without a recovery retry. Before returning, verify page.blocks exists, contains exactly target blocks, includes every required block key exactly once, and every block has field_plan plus execution_script.core_copy. If not, rewrite the JSON before sending.',
            'Hard reject patterns: design-only responses, page_design_plan without blocks, empty blocks, generic block_key values such as details/content/info, field_plan outside blocks, or blocks without core_copy.',
            'Block budget: min=' . $blockBudget['min'] . ', max=' . $blockBudget['max'] . ', target=' . ($blockBudget['target'] ?? $blockBudget['min']) . ', required=' . \json_encode($blockBudget['required'], \JSON_UNESCAPED_UNICODE) . ', recommended_optional=' . \json_encode($blockBudget['optional'] ?? [], \JSON_UNESCAPED_UNICODE) . '; output exactly the target count, never fewer than target and never more than max. If required count is smaller than target, add recommended optional blocks in order until target is reached.',
            $firstBlockImageGate,
            'Required block key coverage: include each required block key exactly once. The required list is not a forced visual sequence; choose a natural order based on page_design_plan.section_flow, and place CTA/support blocks where the page narrative requires.',
            'Output budget: page_goal/theme_alignment_summary <= 140 chars each; each page_design_plan string <= 90 chars; section_flow/interactions/polish arrays max 2 items; each block.content/core_copy <= 120 chars; execution_script.feature_points max 2 items with <= 12 chars each; execution_script.typography/style_tone/background_direction <= 36 chars each; field_plan.sample <= 60 chars; field_plan.implementation_note <= 36 chars; each design_tags array max 2 items with <= 10 chars each; each visual_signature field <= 60 chars. Shorten strings instead of dropping required blocks or fields.',
            'theme_alignment_summary contract: write one concrete page-specific planning sentence using the actual shared theme purpose, palette/color use, type/spacing/radius rule, voice, CTA rhythm, one avoided/forbidden style, and Header/Footer handoff. Do not output schema placeholders, instructions, or phrases such as "string", "how this page obeys", or "explaining how".',
            'Plan locale: ' . ($planLocale !== '' ? $planLocale : 'zh_Hans_CN'),
            'Website content locale: ' . ($contentLocale !== '' ? $contentLocale : ($planLocale !== '' ? $planLocale : 'zh_Hans_CN')),
            'Language rule: blocks[].content, field_plan[].sample, CTA labels, link labels, alt text, and media descriptions are customer-visible website content and MUST use Website content locale. Do not use Plan locale for website copy unless it is identical to Website content locale.',
            $this->buildStageOneIdentityGuardPrompt($scope, $websiteProfile, ''),
            'Visible-copy contract: blocks[].content, execution_script.core_copy, and every field_plan[].sample are final visitor copy seeds. Do NOT put layout instructions, card/grid recipes, hover notes, background directions, or explanations of why a block exists in these fields. Put design/layout/effect details only in page_design_plan, design_tags, implementation_note, execution_script.typography/style_tone/background_direction/media_assets.',
            'Locale contract: except the site title/brand name and unavoidable product acronyms such as APK/APP/SEO, visible-copy fields must not contain large phrases from another language. If the brief contains foreign game/product names, rewrite them into the Website content locale unless the name is the brand itself.',
            'Page type: ' . $pageType,
            $pageType === 'home_page'
                ? 'Home compact mode: this page has many required blocks, so every block must be terse. Do not include rem/px/CSS value recipes, long typography strings, long background descriptions, or more than two feature_points. Minified JSON is mandatory.'
                : 'Page compact mode: keep every field short; do not use rem/px/CSS recipes when a short visual token is enough.',
            'Page-type architecture guide: ' . $pageArchitectureGuide,
            '【用户提示词】Brief: ' . ($brief !== '' ? $brief : '-'),
            '【用户提示词】Instruction: ' . ($instruction !== '' ? $instruction : '-'),
            '【通用提示词】',
            'Target scope: ' . ($targetScope !== '' ? $targetScope : '-'),
            'Confirmed requirement expansion (non-negotiable): ' . $requirementExpansion,
            'Archived Stage-1 page contract summary (non-negotiable source of truth generated and stored before page fanout): ' . $archivedStageOneContract,
            'Shared theme_design (non-negotiable): ' . $themeDesign,
            'Reference image insights (non-negotiable when present): ' . $this->buildReferenceImageInsightsPromptText($scope),
            'Theme-level page overview for this page (use before choosing blocks): ' . $pageTypeOverview,
            'Confirmed shared Header/Footer blocks (must frame this page when displayed): ' . $sharedComponents,
            'Existing page baseline for focused refinement only; full-plan/rebuild runs receive an empty object and must generate from confirmed requirement/theme only: ' . $baselinePage,
            'Page design planning rules:',
            '- First create page_design_plan, then derive blocks from it. Blocks must not be chosen directly from theme_design alone.',
            '- page_design_plan must translate theme_design.style_signature and art_direction into this page: composition motif, background system, surface treatment, detail language, and motion restraint.',
            '- If theme_design.reference_style_context exists, page_design_plan must carry those reference-image cues into this page as adapted layout, palette, component, typography, and forbidden-style decisions.',
            '- page_design_plan.color_layering must name which theme colors are used for page background, alternating section surfaces, cards/panels, text, and CTA/accent states.',
            '- Prevent monotone pages: never make the entire page one flat background color unless the page_design_plan explicitly adds layered surfaces, cards, dividers, gradients, illustrations, or contrast bands.',
            '- page_design_plan.section_flow must describe the visual rhythm across the block sequence: opening impact, middle information/proof layer, and closing action or reassurance layer.',
            '- page_design_plan.interaction_notes must describe hover/focus/mobile behavior that matches the page role, not just generic CTA hover.',
            '- Visual polish is mandatory: every page must include at least one specific motif/detail (inline SVG idea, CSS texture, framed media treatment, asymmetric composition, editorial type contrast, or tactile button state) that fits the brief.',
            '- Customer-intent lock: page_design_plan and every block must preserve the user brief as an experience, not just as copy. If the user asks for gaming, APK, booking, consulting, education, or local service, the layout, affordances, effects, and CTAs must visibly fit that scenario.',
            '- Interaction/effects plan: every page_design_plan.polish_details item must say where the effect appears, which CSS/SVG technique implements it, and how mobile/reduced-motion behavior stays friendly.',
            'Critical page differentiation rules:',
            '- Design this page from its page_type intent, not by copying the home page and changing nouns.',
            '- home_page and about_page MUST have clearly different block_key sets, block order, content purpose, and design_tags.',
            '- home_page must follow the page contract from Block budget: include every required block key exactly once, then use recommended_optional from Block budget until the target block count is reached. Do not replace required page-contract keys with generic brand_promise/content/details blocks.',
            '- Card-game APK style home_page information architecture: when required keys include player_reviews or faq_or_rules, keep them as independent blocks. Do not merge reviews, FAQ, game showcase, or install/download guidance into brand_promise or final_cta.',
            '- about_page usually needs story/mission/team/values/trust narrative blocks, not another homepage conversion sequence.',
            '- contact_page usually needs the required block keys contact_methods, support_form_guidance, support_faq, contact_cta; use optional map/service_area details inside those blocks when relevant.',
            '- policy/legal pages usually need summary, key_rules, refund_or_support_steps, help_cta; keep rules concise and avoid full legal prose in Stage-1.',
            '- Policy/legal page body contract: privacy, terms, refund, shipping, and cookie pages must not put conversion CTA copy such as free download, install now, play-game, registration, claim, reward/bonus/coins, or app-download inside page body blocks. Neutral legal applicability wording may mention that the policy applies when visitors download or use the APK/app; neutral data-use or rights wording may mention account benefits only as policy facts, never as an offer. Global header/footer may keep a site CTA when appropriate, but body blocks must stay policy/support/rights focused.',
            '- Policy/legal block role rule: terms_contact, privacy_contact, cookie_contact, refund_contact, and similar policy-help blocks should normally use page_flow_role=support or details, not cta. Use page_flow_role=cta only when field_plan includes a policy-safe action label such as 查看条款, 了解隐私权利, 查看争议流程, or 提交政策问题.',
            '- Policy first-block lock: on privacy_policy and terms_of_service, block index 0 content, field_plan samples, and execution_script.core_copy must be policy-summary copy only. They must not inherit site primary_cta or conversion wording such as free download, install now, play, register, claim, reward, bonus, coins, or app-download.',
            '- Policy/legal CTA safety: terms/privacy body CTAs must use policy-safe actions only, such as read terms, view rules, review privacy rights, understand dispute steps, or contact policy help. Never use free download, install now, play, register, claim bonus, reward, coins, or app-download CTA wording inside policy page body blocks. Neutral legal applicability wording may mention download/use of the APK/app.',
            '- Policy/legal help_cta/contact blocks must use policy-safe actions such as privacy rights review, terms summary, data protection help, dispute/support contact, or policy assistance. Do not reuse the site primary_cta even if the overall website goal is app download.',
            '- Visible copy boundary: page content, field_plan samples, and core_copy are final website copy seeds derived from the brief, style direction, page role, and block identity. They must not contain prompt instructions, blueprint explanations, validator wording, field names, layout recipes, or internal labels.',
            '- Contact/support copy is allowed when it follows the page and block plan. The gate only rejects internal prompt/blueprint leakage or placeholder copy, not the factuality of a support/service claim.',
            '- Within one page, every block_key must be unique. Never output two blocks both named "details"; use purpose-specific keys such as coverage, rights, faq, process, trust, steps, timeline, or proof.',
            '- If a policy/support page needs multiple information sections, each section must use a different block_key that matches its job.',
            '- Every block MUST include design_tags with visual, motion, interaction, texture, responsive arrays and an implementation_note.',
            '- design_tags must contain all required keys inside the design_tags object: visual, motion, interaction, texture, responsive, color_layering, implementation_note. Never place color_layering or implementation_note only at block root.',
            '- design_tags.responsive MUST describe desktop, tablet, and phone behavior. If the block uses image + form/card/CTA panels, explicitly state panel order, stack breakpoint, min-width:0 / max-width:100% containment, and that decorative layers cannot cover content.',
            '- Block object hard gate: every block must include page_flow_role, design_tags with all required keys, visual_signature with all five keys, image_intent with all eight keys, exactly three field_plan rows, and execution_script.core_copy. Do not omit these objects for review, FAQ, policy, blog, list, or CTA blocks.',
            '- Visual signature completeness rule: visual_signature.composition_pattern, spatial_rhythm, media_strategy, surface_treatment, and interaction_pattern must all be non-empty concrete text. Never use empty string, "none", "same as above", or a schema placeholder for any visual_signature field. If the block is static, still describe the static layout, scan rhythm, CSS media strategy, surface, and focus/hover behavior.',
            '- Visual signature examples (copy the shape, not the content; adapt to this exact page/block): ' . $visualSignatureExamplesJson,
            '- Every field_plan row MUST include field, sample, and a concrete implementation_note explaining where/how that exact sample is rendered on the page.',
            '- field_plan must use exactly three stable rows: row 0 field=headline with an actual heading sample; row 1 field=supporting_copy with one actual visitor-facing sentence; row 2 field=context_detail unless cta_label/proof_detail/image_brief/form_label/policy_summary is more accurate for that block.',
            '- BuildPlan visible body handoff: every block must include at least one real visitor-facing body sentence in execution_script.core_copy, field_plan row 1 supporting_copy, realtime_content.supporting_copy, feature_points, or content. A CTA label, button text, layout note, or design instruction alone is invalid.',
            '- CTA body/action split: contact_cta/final_cta/download_cta blocks need a supporting_copy/body sentence plus a separate cta_label/action label. The body sentence must be final visitor copy, not a layout note or blueprint explanation.',
            '- CTA field rule: if page_flow_role=cta, row 2 field must be exactly cta_label, action_label, or button_text with a visitor-facing action label. Do not force article/category/contact/support blocks into CTA shape unless their page_flow_role is cta.',
            '- field_plan.field must be a non-empty short snake_case semantic key. Never leave field empty and never invent vague keys such as text, copy, content, details, or item.',
            '- field_plan.sample and field_plan.implementation_note must not start with validator-rejected prompt words: write, rewrite, describe the/this block, describe the/this field, use this field, do not output, 围绕, 突出, 说明, 完善, 优化. Visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            '- design_tags examples: visual=["premium","card shadow","rounded image","large banner"], motion=["5s fade in/out","subtle parallax","hover lift"], interaction=["primary CTA hover","tabs","accordion"], texture=["soft gradient","glass surface","Indian pattern accent"], responsive=["mobile stacked cards","desktop two-column"].',
            '- These design_tags are source-of-truth for virtual-theme build and publish migration; make them specific enough to recreate effects, spacing, shadows, radius, image treatment, and interaction behavior.',
            'Field plan intent rules:',
            '- Exactly three field_plan rows per block. Row 0 is usually headline; row 1 is supporting_copy/body copy; row 2 should match the block intent, such as cta_label/action_label/button_text for CTA, proof_detail for proof, image_brief for generated media, form_label for support/form, policy_summary for legal text, or context_detail for neutral details.',
            '- Field plan intent examples (choose fields by block intent, not by block_key text alone): ' . $fieldPlanIntentExamplesJson,
            'Image intent rules:',
            '- Every image_intent must include all eight contract keys: needs_image, image_role, image_subject, placement, visual_atmosphere, image_treatment, reuse_policy, css_motif.',
            '- needs_image type rule: image_intent.needs_image MUST be the JSON boolean true or false. Never return "yes", "no", "maybe", "optional", "CSS-only", an empty string, or explanatory text; put visual planning detail in media_strategy/css_motif/visual_atmosphere/image_treatment.',
            '- needs_image GOOD/BAD examples: GOOD {"needs_image":true}; GOOD {"needs_image":false}; BAD {"needs_image":"CSS-only"}; BAD {"needs_image":"no"}; BAD {"needs_image":{"value":false}}.',
            '- Image intent location rule: output image_intent only at block top level. Do not output visual.image_intent, nested image_intent copies, rationale, reason, or why fields.',
            '- Image intent examples: generated {"needs_image":true,"image_role":"hero_image","image_subject":"phone APK install screen with Teen Patti table behind it","placement":"media_panel","visual_atmosphere":"warm trusted casino lobby mood","image_treatment":"rounded phone mockup with amber overlay","reuse_policy":"reuse_when_intent_matches","css_motif":""}; CSS-only {"needs_image":false,"image_role":"css_motif","image_subject":"none","placement":"background_layer","visual_atmosphere":"secure premium dark-card mood","image_treatment":"CSS gradient cards and border glow only","reuse_policy":"no_generated_image","css_motif":"amber grid badges with neon trust outlines"}.',
            '- CSS-only image_intent examples by common block_key: ' . $cssOnlyImageIntentExamplesJson,
            '- Complete block examples (copy the shape, not the exact content; rewrite for this page/locale/block): ' . $blockReturnExamplesJson,
            '- When image_intent.needs_image=true, image_subject must be a concrete block-level generated visual: a scene, product/editorial photograph, interface/product mockup, environment, people moment, or premium illustration tied to the block goal. When needs_image=false, image_subject must be "none" and css_motif carries the visual plan.',
            '- Non-policy page visual asset rule: every non-policy page must include at least one block with image_intent.needs_image=true and a concrete generated image subject. Do not make an entire home/about/contact/custom page CSS-only. Contact/support pages should use a real generated support-desk, app-help, product-interface, or customer-service scene where it best fits the page narrative.',
            '- Preferred generated-image target examples by page type: ' . $this->stageOneGeneratedImageTargetExamplesJson() . '. Prefer these required/opening/support/article blocks when present, but keep narrative fit and still return all required blocks.',
            '- Non-policy opening image direction: for home_page, about_page, contact_page, and custom marketing pages, prefer a concrete generated scene/product/interface subject early in the page when it supports the narrative, but do not force block index 0 to be the generated-image block.',
            '- Do not use icon-only image subjects for page blocks. Invalid page-block subjects include app icon, shield badge, logo mark, sparkle glyph, line icon, avatar badge, chevron, symbol, SVG icon, download arrow, coin mark, or any subject that is only a decorative mark.',
            '- Icon/decorative visual boundary: when the visual need is small icons, badges, arrows, dividers, chips, rating stars, initials, or abstract marks, keep needs_image=false and describe the motif in css_motif/design_tags. Use needs_image=true only when the block needs a real generated scene/product/interface/editorial visual.',
            '- Abstract trust/reward/security/payment/download marks are not generated image subjects by themselves. Convert them into a real scene or product visual: players at a Teen Patti table, a phone APK install screen, a support desk, a product interface, or an editorial brand moment.',
            '- If execution_script.media_assets or visual_signature.media_strategy mentions a photo, image, screenshot, mockup, scene, hero image, banner image, background image, or avatar, set image_intent.needs_image=true and provide a concrete generated asset brief.',
            '- Real media contract: when a block plans an image, screenshot, phone screen, mockup, scene, background image, or media asset, describe the actual generated asset and integration. Never say placeholder, dummy, fake image, temporary image, blank box, 占位, 占位图, 假图, 临时图片, or 占位视觉 in design_tags, visual_signature, image_intent, field_plan, or execution_script.',
            '- If the block uses only CSS cards, gradients, patterns, lines, badges, or small icons, set needs_image=false and provide a full CSS-only image_intent: image_role css_motif or none, placement none/background_layer/inline_visual, non-empty css_motif, visual_atmosphere, image_treatment, and visual_signature.media_strategy that starts with the exact ASCII marker "CSS-only/no generated image" before any localized explanation.',
            '- Opening/proof block rule: page_flow_role opening, hero, or proof must either set needs_image=true with a concrete generated subject, or set needs_image=false with the full CSS-only image_intent above. Trust/security proof blocks are allowed to be CSS-only only when css_motif is non-empty and visual_signature.media_strategy starts with "CSS-only/no generated image". Do not leave proof blocks undecided.',
            'Schema:',
            '{"page":{"page_goal":"string","theme_alignment_summary":"string","page_design_plan":{"page_role":"string","content_narrative":"string","visual_hierarchy":"string","visual_signature_application":"string","composition_motif":"string","color_layering":"string","section_flow":["string"],"interaction_notes":["string"],"polish_details":["string"],"anti_monotony_rule":"string"},"primary_keywords":["string"],"secondary_keywords":["string"],"blocks":[{"block_key":"string","page_flow_role":"opening|proof|details|cta|support","goal":"string","keywords":["string"],"content":"string","design_tags":{"visual":["string"],"motion":["string"],"interaction":["string"],"texture":["string"],"responsive":["string"],"color_layering":"string","implementation_note":"string"},"visual_signature":{"composition_pattern":"string","spatial_rhythm":"string","media_strategy":"string","surface_treatment":"string","interaction_pattern":"string"},"image_intent":{"needs_image":true,"image_role":"hero_image|section_image|trust_brand_image|css_motif|none","image_subject":"specific scene/product/editorial subject or none for CSS-only","placement":"background_layer|media_panel|inline_visual|none","visual_atmosphere":"specific mood, environment, lighting, and brand feel","image_treatment":"specific crop, style, framing, overlay, and integration treatment","reuse_policy":"reuse_when_intent_matches|no_generated_image","css_motif":"required concrete CSS motif when needs_image=false, empty string when generated image is needed"},"field_plan":[{"field":"headline","sample":"visitor-facing heading","implementation_note":"where this heading renders"},{"field":"supporting_copy","sample":"visitor-facing support sentence","implementation_note":"where this body copy renders"},{"field":"context_detail","sample":"detail, proof, CTA label, image brief, form label, or policy summary","implementation_note":"where this detail renders"}],"execution_script":{"feature_points":["string"],"core_copy":"string","typography":"string","style_tone":"string","background_direction":"string","media_assets":["string"]},"reusable":"yes|no","seo_impact":"high|medium|low"}]}}',
            'Schema placeholders are type markers only. Returning literal placeholder values such as "string", "sentence", "how this page obeys", "explaining how", or generic blueprint wording is a contract failure.',
            'Block budget: min=' . $blockBudget['min'] . ', max=' . $blockBudget['max'] . ', target=' . ($blockBudget['target'] ?? $blockBudget['min']) . ', required=' . \json_encode($blockBudget['required'], \JSON_UNESCAPED_UNICODE) . ', recommended_optional=' . \json_encode($blockBudget['optional'] ?? [], \JSON_UNESCAPED_UNICODE) . '. Output exactly target blocks.',
            'You MUST include every required_block_key unless the normalized page contract marks it irrelevant.',
            'Required block key contract: if required is not empty, blocks[].block_key MUST include each required value exactly once. The required list is a coverage contract, not a forced visual sequence. Never omit blocks, never return an empty blocks array, and never replace required block keys with generic names such as details, content, section, or info.',
            'Hard rules: output exactly target blocks according to budget; each block exactly 3 field_plan rows using headline/supporting_copy/context_detail-style slots with non-empty semantic field keys; every block MUST include execution_script.core_copy as one compact final visitor-facing sentence in Website content locale; execution_script.feature_points max 2 and must be concrete customer-visible deliverables for this block, not writing/layout instructions like "section title"; content and core_copy must be final customer-visible implementation content in Website content locale, compact and not instruction-like; every block.content must describe the concrete visitor message and proof/action, never a reason for the block and never a UI construction recipe; every block must have complete design_tags including visual/motion/interaction/texture/responsive/color_layering/implementation_note; every block must include visual_signature with composition_pattern/spatial_rhythm/media_strategy/surface_treatment/interaction_pattern and image_intent with needs_image/image_role/image_subject/placement/visual_atmosphere/image_treatment/reuse_policy/css_motif; if needs_image=true then image_subject must be a concrete scene/product/editorial/interface/environment/people visual, never an icon/logo/badge/glyph/symbol/download arrow/coin mark, never a placeholder/dummy/fake/temporary image plan, and visual_signature.media_strategy must not say CSS-only/no generated image; if needs_image=false then visual_signature.media_strategy must start with "CSS-only/no generated image" and css_motif/visual_atmosphere/image_treatment must be non-empty; every block must state how it follows page_design_plan.color_layering and page_design_plan.section_flow; return a complete JSON object within the token budget.',
            'Self-check before return: verify blocks is non-empty, blocks count equals target, every required block_key appears exactly once, every block has content, all five visual_signature fields non-empty, image_intent complete, exactly 3 field_plan rows with sample and implementation_note, and execution_script.core_copy is non-empty final copy. If any field is missing, rewrite shorter content instead of omitting that block or field.',
            'Self-check before return: verify every block has explicit page_flow_role rhythm (opening/proof/details/cta/support) and does not duplicate another page type block purpose.',
            $sourceTruthPromptLine,
        ]);
    }

    /**
     * @param array<string, mixed> $invalidPagePlan
     */
    private function buildAiStageOnePageRecoveryPrompt(
        array $scope,
        array $websiteProfile,
        array $planJson,
        string $pageType,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        string $failureMessage,
        array $invalidPagePlan
    ): string {
        $blockBudget = $this->resolveStageOneBlockBudget($pageType, $scope);
        $invalidSummary = $this->summarizeInvalidStageOnePagePlanForPrompt($invalidPagePlan);
        $visualSignatureExamplesJson = $this->stageOneVisualSignatureExamplesJson();
        $stageOneContract = $this->getStageOneContractService()->normalize(
            \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []),
            $scope,
            [$pageType],
            $planLocale,
            $contentLocale,
            'page_recovery'
        );
        $validationReport = $this->getStageOneContractValidator()->validatePagePlan(
            $pageType,
            $invalidPagePlan,
            $stageOneContract,
            [
                'generation_attempts' => ['recovery_prompt' => 1],
                'recovery_count' => 1,
                'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? '')),
                'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
            ]
        );

        return $this->buildAiStageOnePagePrompt(
            $scope,
            $websiteProfile,
            $planJson,
            $pageType,
            $planLocale,
            $contentLocale,
            $instruction,
            $targetScope
        ) . "\n" . \implode("\n", [
            ...$this->getStageOnePromptContractRenderer()->renderRepairContract($stageOneContract, $validationReport),
            ...$this->buildStageOneIssueSpecificRecoveryRules(
                \is_array($validationReport['issues'] ?? null) ? $validationReport['issues'] : []
            ),
            'RECOVERY MODE FOR STAGE-ONE PAGE FANOUT.',
            'The previous response failed the non-negotiable page contract: ' . $this->clipText($failureMessage, 500),
            'This retry replaces the previous response. Return one complete page JSON only; do not explain the failure.',
            'Recovery acceptance gate: page.blocks must be a non-empty array, contain exactly target=' . (string)($blockBudget['target'] ?? $blockBudget['min']) . ' blocks, and required block keys must appear exactly once: ' . \json_encode($blockBudget['required'], \JSON_UNESCAPED_UNICODE) . '.',
            'Recovery completeness gate: rebuild and return the entire page object, not a partial patch and not only failed blocks. Every block in the returned page must include complete design_tags, visual_signature, image_intent, field_plan, and execution_script.core_copy; never leave the last block skeletal or missing visual_signature.',
            'Every returned block must include block_key, page_flow_role, content, complete design_tags, complete visual_signature, complete image_intent, exactly 3 field_plan rows with field/sample/implementation_note, and execution_script.core_copy.',
            'Recovery creativity rule: fix the failed contract fields while preserving or improving the page-specific visual idea. Do not collapse the page into generic compliance cards, and do not copy examples verbatim.',
            'Field plan intent examples for recovery: ' . $this->stageOneFieldPlanIntentExamplesJson(),
            'Visual signature recovery examples: ' . $visualSignatureExamplesJson,
            'Complete block examples for recovery shape: ' . $this->stageOneBlockReturnExamplesJson(),
            'needs_image recovery rule: image_intent.needs_image must be the JSON boolean true or false only; never return a string, empty value, or explanatory phrase.',
            'visual_signature recovery rule: all five visual_signature fields must be non-empty. For static FAQ, review, support, policy, or text blocks, describe accordion/card/list layout, scan rhythm, CSS-only media strategy, surface treatment, and focus/hover behavior instead of leaving any field blank.',
            'Field plan recovery rule: every field_plan.sample must be final visitor-facing copy or a concrete asset brief, not an instruction. Do not start samples with write, rewrite, describe the/this block, describe the/this field, use this field, explain, create, show, include, highlight, mention, 围绕, 突出, 说明, 完善, or 优化; visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            'Visible body recovery rule: every repaired block must include visitor-facing body copy that BuildPlan can consume. contact_cta/final_cta/download_cta blocks must include supporting_copy/body copy separately from button or action labels.',
            'If the token budget is tight, shorten visitor copy and field samples. Never omit blocks, required block keys, field_plan, or core_copy.',
            'Compact recovery budget: each block.content/core_copy <= 180 chars, each field_plan.sample <= 90 chars, each design_tags array max 2 items, and visual_signature fields <= 90 chars. A complete short page beats a verbose truncated page.',
            'Policy/legal recovery role rule: terms_contact/privacy_contact/cookie_contact/refund_contact blocks should use page_flow_role=support or details unless they include a policy-safe cta_label. Never use a marketing download/register/reward CTA to satisfy a policy contact block.',
            'Hard reject patterns: returning only page_design_plan; returning only field_plan; returning an empty blocks array; returning generic keys such as details/content/info instead of required block keys.',
            'Invalid previous page plan summary for diagnostics only: ' . ($invalidSummary !== '' ? $invalidSummary : '{}'),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return list<string>
     */
    private function buildStageOneIssueSpecificRecoveryRules(array $issues): array
    {
        $codes = [];
        $imageTargets = [];
        $visualTargets = [];
        $cssOnlyTargets = [];
        $missingIntentTargets = [];
        foreach ($issues as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $code = \trim((string)($issue['reason_code'] ?? $issue['code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
            $pageType = \trim((string)($issue['page_type'] ?? ''));
            $blockKey = \trim((string)($issue['block_key'] ?? ''));
            $fieldPath = \trim((string)($issue['field_path'] ?? $issue['path'] ?? ''));
            $targetLabel = $pageType !== '' && $blockKey !== '' && $blockKey !== '__page__'
                ? $pageType . '/' . $blockKey . ($fieldPath !== '' ? '/' . $fieldPath : '')
                : '';
            if ($code === 'page_missing_generated_image_intent') {
                if ($pageType !== '' && $blockKey !== '' && $blockKey !== '__page__') {
                    $imageTargets[] = $pageType . '/' . $blockKey;
                }
            }
            if (($code === 'invalid_visual_signature' || $code === 'missing_visual_signature') && $targetLabel !== '') {
                $visualTargets[] = $targetLabel;
            }
            if (($code === 'missing_css_motif_for_no_image_block' || $code === 'image_intent_conflicts_with_block_plan') && $targetLabel !== '') {
                $cssOnlyTargets[] = $targetLabel;
            }
            if ($code === 'missing_image_intent' && $targetLabel !== '') {
                $missingIntentTargets[] = $targetLabel;
            }
        }

        $rules = [];
        if ($this->hasAnyStageOneIssueCode($codes, [
            'missing_page_design_plan',
            'malformed_block',
            'missing_block_key',
            'missing_page_flow_role',
            'missing_design_tag',
            'missing_visual_signature',
            'missing_image_intent',
            'missing_field_plan',
            'invalid_field_plan_count',
            'malformed_field_plan_row',
        ])) {
            $rules[] = 'Issue-specific rule for missing/malformed structure: rebuild the complete page object, not only the failed field. Every block must include block_key, page_flow_role, content, complete design_tags, complete visual_signature, complete image_intent, exactly three field_plan rows, and execution_script.core_copy.';
        }
        if (isset($codes['missing_theme_field'])) {
            $rules[] = 'Issue-specific rule for missing_theme_field: rebuild the complete shared theme artifact with required theme_design fields, color_scheme, typography_spacing_radius, navigation_plan, footer_plan, shared_components, and seo_strategy. Do not return page blocks in a theme repair.';
        }
        if (isset($codes['required_block_order_mismatch'])) {
            $rules[] = 'Issue-specific rule for required_block_order_mismatch: keep each required block_key unchanged and place it at the expected index from the issue or contract. Do not satisfy order by renaming a different block.';
        }
        if ($this->hasAnyStageOneIssueCode($codes, [
            'missing_link_list',
            'invalid_link_row',
            'link_href_not_exact_route_path',
            'link_href_not_in_route_contract',
        ])) {
            $rules[] = 'Issue-specific rule for header/footer links: rebuild link rows as objects with real label and href, and copy href exactly from page_route_contract.allowed_internal_paths or the exact link_groups path. Do not invent anchors, query strings, domains, translated slugs, singular/plural variants, or campaign paths.';
        }
        if ($this->hasAnyStageOneIssueCode($codes, [
            'invalid_visual_signature',
            'adjacent_visual_signature_duplicate',
            'overused_composition_pattern',
            'duplicate_block_message',
        ])) {
            $targetText = $visualTargets !== []
                ? (' Exact visual_signature target(s): ' . \implode(', ', \array_values(\array_unique($visualTargets))) . '.')
                : '';
            $rules[] = 'Issue-specific rule for visual/message uniqueness:' . $targetText . ' rewrite each affected block with a concrete block-specific composition_pattern, spatial_rhythm, media_strategy, surface_treatment, interaction_pattern, headline, and core_copy. No visual_signature field may be empty, "none", "same as above", or a schema placeholder. Keep required block keys unchanged; do not add unrelated blocks just to create variety.';
        }
        if (isset($codes['page_missing_generated_image_intent'])) {
            $targetText = $imageTargets !== []
                ? (' Target block(s): ' . \implode(', ', \array_values(\array_unique($imageTargets))) . '.')
                : '';
            $rules[] = 'Issue-specific rule for page_missing_generated_image_intent:' . $targetText
                . ' the returned page must contain at least one block with image_intent.needs_image=true. Prefer the named block when it is a natural media target, otherwise place the generated-image slot on the block that best fits the page narrative. Use image_role hero_image or section_image, placement background_layer/media_panel/inline_visual, reuse_policy reuse_when_intent_matches, and a concrete image_subject tied to that block. Do not force block index 0 to be the generated-image block solely to satisfy this issue.';
        }
        if (isset($codes['invalid_image_intent_needs_image'])) {
            $rules[] = 'Issue-specific rule for invalid_image_intent_needs_image: rewrite image_intent.needs_image as the JSON boolean true or false only. Never use "yes", "no", "maybe", "optional", "CSS-only", an empty string, or explanatory text. Put visual planning detail in media_strategy/css_motif/visual_atmosphere/image_treatment and keep the rest of the block creative and page-specific.';
        }
        if (isset($codes['missing_image_intent'])) {
            $targetText = $missingIntentTargets !== []
                ? (' Exact missing image_intent target(s): ' . \implode(', ', \array_values(\array_unique($missingIntentTargets))) . '.')
                : '';
            $rules[] = 'Issue-specific rule for missing_image_intent:' . $targetText . ' add one top-level image_intent object to every affected block with all eight keys: needs_image, image_role, image_subject, placement, visual_atmosphere, image_treatment, reuse_policy, and css_motif. Do not put image_intent under visual, and do not add rationale/reason/why fields.';
        }
        if (isset($codes['missing_css_motif_for_no_image_block'])) {
            $targetText = $cssOnlyTargets !== []
                ? (' Exact CSS-only target(s): ' . \implode(', ', \array_values(\array_unique($cssOnlyTargets))) . '.')
                : '';
            $rules[] = 'Issue-specific rule for missing_css_motif_for_no_image_block:' . $targetText . ' when needs_image=false, keep needs_image as JSON false and fill css_motif, visual_atmosphere, image_treatment, image_role=css_motif or none, image_subject=none, placement=none/background_layer/inline_visual, reuse_policy=no_generated_image, and visual_signature.media_strategy starting with "CSS-only/no generated image". If that is not the right visual path, switch the block to needs_image=true with a concrete generated subject.';
        }
        if (isset($codes['invalid_image_intent_field'])) {
            $rules[] = 'Issue-specific rule for invalid_image_intent_field: keep image_intent and replace the weak field with concrete planning text. Generated-image blocks need image_role, image_subject, placement, visual_atmosphere, image_treatment, and reuse_policy; CSS-only blocks need css_motif, visual_atmosphere, image_treatment, and media_strategy starting with CSS-only/no generated image. Do not add rationale, reason, or why fields.';
        }
        if (isset($codes['missing_visible_body_copy'])) {
            $rules[] = 'Issue-specific rule for missing_visible_body_copy: rewrite the affected block with a real visitor-facing body sentence in execution_script.core_copy and field_plan row 1 field=supporting_copy. If the block is a CTA, keep the action label in a separate cta_label/action field and do not use the button label as the only body copy.';
        }
        if (isset($codes['instruction_like_or_empty'])) {
            $rules[] = 'Issue-specific rule for instruction_like_or_empty: replace empty or instruction-like field_plan fields/samples/core_copy with concrete final values. Use row 0 field=headline, row 1 field=supporting_copy, and a purpose-specific row 2 key; every execution_script.core_copy must be one non-empty visitor-facing sentence.';
        }
        if (isset($codes['cta_role_missing_block_action'])) {
            $rules[] = 'Issue-specific rule for cta_role_missing_block_action: if this is a policy/legal contact block, prefer changing page_flow_role to support/details. If it is truly a CTA block, add a separate cta_label/action_label/button_text field with a final visitor action, and keep a real supporting_copy body sentence.';
        }
        if (isset($codes['image_intent_conflicts_with_block_plan'])) {
            $targetText = $cssOnlyTargets !== []
                ? (' Exact media conflict target(s): ' . \implode(', ', \array_values(\array_unique($cssOnlyTargets))) . '.')
                : '';
            $rules[] = 'Issue-specific rule for image_intent_conflicts_with_block_plan:' . $targetText . ' align media planning and image_intent on the same block. Choose exactly one path. Generated-image path: needs_image=true, concrete image_subject, placement, reuse_policy, and visual_signature.media_strategy describing the generated asset with no CSS-only/no generated image markers. CSS-only path: needs_image=false, image_role css_motif or none, placement none/background_layer/inline_visual, non-empty css_motif/visual_atmosphere/image_treatment, and media_strategy starting with CSS-only/no generated image. Never mix the two paths in one block.';
        }

        return $rules;
    }

    /**
     * @param array<string, bool> $codes
     * @param list<string> $targets
     */
    private function hasAnyStageOneIssueCode(array $codes, array $targets): bool
    {
        foreach ($targets as $target) {
            if (isset($codes[$target])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $invalidPagePlan
     */
    private function summarizeInvalidStageOnePagePlanForPrompt(array $invalidPagePlan): string
    {
        if ($invalidPagePlan === []) {
            return '{}';
        }

        $blocks = \is_array($invalidPagePlan['blocks'] ?? null) ? $invalidPagePlan['blocks'] : [];
        $blockKeys = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
            if ($blockKey !== '') {
                $blockKeys[] = $blockKey;
            }
        }
        $summary = [
            'top_level_keys' => \array_slice(\array_values(\array_map('strval', \array_keys($invalidPagePlan))), 0, 20),
            'blocks_count' => \count($blocks),
            'block_keys' => \array_slice(\array_values(\array_unique($blockKeys)), 0, 20),
        ];

        return $this->clipText(
            (string)(\json_encode($summary, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: ''),
            1000
        );
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function resolveStageOnePageTypeOverview(array $planJson, string $pageType): array
    {
        $overviews = \is_array($planJson['page_type_overviews'] ?? null) ? $planJson['page_type_overviews'] : [];
        if (\is_array($overviews[$pageType] ?? null)) {
            return $overviews[$pageType];
        }
        foreach ($overviews as $overview) {
            if (!\is_array($overview)) {
                continue;
            }
            if (\trim((string)($overview['page_type'] ?? $overview['page_key'] ?? '')) === $pageType) {
                return $overview;
            }
        }

        return [];
    }

    private function buildStageOnePageArchitectureGuide(string $pageType): string
    {
        return match ($pageType) {
            Page::TYPE_HOME => 'Home page: conversion-first entry page. Use a distinctive opening/banner, value/game highlights, trust or final CTA. Avoid brand-history blocks as the primary structure.',
            Page::TYPE_ABOUT => 'About page: brand story and trust narrative. Use origin/story, mission/values/team/proof, community promise or about CTA. Do not reuse homepage hero/highlights/cta as the same block sequence.',
            Page::TYPE_CONTACT => 'Contact page: support and lead-capture path. Use contact methods, form guidance, service/support details, FAQ or map/location when relevant.',
            Page::TYPE_BLOG_LIST => 'Blog/resource list page: use the required block keys resource_hero, article_grid, learning_path, newsletter_cta. If the brief asks for an academy or learning center, turn this into a learning hub with topic intro, article/resource cards, learning path, expert tips, and newsletter or consultation CTA.',
            Page::TYPE_CUSTOM => 'Custom page: map the brief-specific page request here. If the brief asks for product series, create product collection/category blocks, product comparison, use-case guidance, craftsmanship/details, and purchase or consultation CTA.',
            default => $pageType . ': infer the standard information architecture for this page type and create blocks that differ from home_page in purpose, order, and content pattern.',
        };
    }

    /**
     * 与方案生成门禁对齐：确认前对 scope 内 plan_json 执行同款 repair，供 BuildPlan v2 与 SourceTruth 校验使用。
     *
     * @param array<string, mixed> $scope
     * @return array{scope: array<string, mixed>, stage1_validation: array<string, mixed>}
     */
    public function prepareStageOnePlanScopeForConfirmation(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        if ($planJson === [] && $planStructured !== []) {
            $planJson = $planStructured;
        }
        if ($planJson === []) {
            return [
                'scope' => $scope,
                'stage1_validation' => ['passed' => false, 'issues' => []],
            ];
        }

        $pageTypes = $this->resolveStageOnePlanPageTypesFromScope($scope, $planJson);
        $planLocale = $this->firstNonEmptyString([
            $scope['plan_locale'] ?? null,
            $scope['default_language'] ?? null,
            $scope['default_locale'] ?? null,
            'zh_Hans_CN',
        ]);
        $briefDescription = $this->firstNonEmptyString([
            $scope['brief_description'] ?? null,
            $scope['site_summary'] ?? null,
            \is_array($scope['website_profile'] ?? null) ? ($scope['website_profile']['brief_description'] ?? null) : null,
            '',
        ]);
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $repairedPlanJson = $this->repairAiStageOnePlanJsonBeforeValidation(
            $planJson,
            $pageTypes,
            $planLocale,
            $briefDescription,
            $scope,
            $contentLocale
        );
        $scope['plan_json'] = $repairedPlanJson;
        $scope['plan_structured'] = $repairedPlanJson;
        $stageOneGenerationAttempts = \is_array($scope['stage1_generation_attempts'] ?? null)
            ? $scope['stage1_generation_attempts']
            : [];
        $stage1Validation = $this->buildStageOneValidationReport(
            $repairedPlanJson,
            $pageTypes,
            $briefDescription,
            $planLocale,
            $scope,
            $stageOneGenerationAttempts
        );

        return [
            'scope' => $scope,
            'stage1_validation' => $stage1Validation,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return list<string>
     */
    private function resolveStageOnePlanPageTypesFromScope(array $scope, array $planJson): array
    {
        $pageTypes = [];
        foreach (\is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [] as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '') {
                $pageTypes[] = $pageType;
            }
        }
        if ($pageTypes === []) {
            foreach (\array_keys(\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : []) as $pageType) {
                $pageType = \trim((string)$pageType);
                if ($pageType !== '') {
                    $pageTypes[] = $pageType;
                }
            }
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   change_scope_report:array<string, mixed>
     * }
     */
    public function refineDraftPlan(
        array $scope,
        array $websiteProfile,
        array $payload,
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk, $onProgress);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $artifacts = $this->applyRefineAccumulativePolicy($scope, $artifacts, $instruction);
        $report = [
            'mode' => 'refine',
            'round' => $round,
            'target_scope' => $targetScope,
            'instruction' => $instruction,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'plan',
                    'reason' => $instruction !== '' ? $instruction : '局部优化当前方案',
                ],
            ],
        ];
        $artifacts['structured']['change_scope_report'] = $report;
        $artifacts['plan_json']['change_scope_report'] = $report;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['change_scope_report'] = $report;

        return $artifacts;
    }


    /**
     * Refine a single stage-1 page plan while preserving every other page tree.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   markdown:string,
     *   plan_workbench:array<string, mixed>,
     *   page_refine_summary:array<string, mixed>
     * }
     */
    public function refineDraftPlanPage(
        array $scope,
        array $websiteProfile,
        string $pageType,
        array $payload,
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            throw new \RuntimeException('Page type is required for stage-1 page refine.');
        }

        $structured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = $this->resolveStageOneExecutionBlueprint($scope);
        $existingPlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null)
                ? $structured['pages']
                : (\is_array($existingPlanJson['pages'] ?? null) ? $existingPlanJson['pages'] : []));

        if (!\is_array($pages[$pageType] ?? null)) {
            throw new \RuntimeException('Stage-1 page plan not found for page type: ' . $pageType);
        }

        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $aiPayload = \array_replace($payload, [
            'instruction' => $instruction !== ''
                ? ('Refine only page "' . $pageType . '". Preserve every other page and shared plan unchanged. ' . $instruction)
                : ('Refine only page "' . $pageType . '". Preserve every other page and shared plan unchanged.'),
            'target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
            'prompt_mode' => 'refine_page',
            'round' => $round,
        ]);

        $candidateArtifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $aiPayload, $onChunk, $onProgress);
        $candidateStructured = \is_array($candidateArtifacts['structured'] ?? null) ? $candidateArtifacts['structured'] : [];
        $candidatePlanJson = \is_array($candidateArtifacts['plan_json'] ?? null) ? $candidateArtifacts['plan_json'] : [];
        $candidatePages = \is_array($candidateStructured['pages'] ?? null)
            ? $candidateStructured['pages']
            : (\is_array($candidatePlanJson['pages'] ?? null) ? $candidatePlanJson['pages'] : []);
        $refinedPage = \is_array($candidatePages[$pageType] ?? null) ? $candidatePages[$pageType] : [];
        if ($refinedPage === []) {
            throw new \RuntimeException('AI page refine result did not include page type: ' . $pageType);
        }

        $originalPageKeys = \array_values(\array_map('strval', \array_keys($pages)));
        $pages[$pageType] = $refinedPage;
        $structured['pages'] = $pages;
        $executionBlueprint['pages'] = $pages;

        if (!\is_array($structured['page_types'] ?? null) || $structured['page_types'] === []) {
            $structured['page_types'] = \is_array($executionBlueprint['page_types'] ?? null)
                ? $executionBlueprint['page_types']
                : \array_values(\array_map('strval', \array_keys($pages)));
        }
        if (!\is_array($executionBlueprint['page_types'] ?? null) || $executionBlueprint['page_types'] === []) {
            $executionBlueprint['page_types'] = \is_array($structured['page_types'] ?? null)
                ? $structured['page_types']
                : \array_values(\array_map('strval', \array_keys($pages)));
        }

        $sharedPromptContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null)
            ? $executionBlueprint['shared_prompt_context']
            : (\is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : []);
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        $structured['page_plans'] = $pagePlans;
        $executionBlueprint['page_plans'] = $pagePlans;

        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $structured['shared_components'] = $sharedComponents;
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'header_block' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
                'footer_block' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
                'shared_prompt_context' => $sharedPromptContext,
            ]
        );

        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $structured['block_index'] = $blockIndex;
        $executionBlueprint['block_index'] = $blockIndex;

        $existingTasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
        $targetPagePlan = \is_array($pagePlans[$pageType] ?? null) ? $pagePlans[$pageType] : [];
        $targetTasks = $this->buildStageOnePageBlockTasks($pageType, $targetPagePlan);
        $executionBlueprint['tasks'] = $this->replaceStageOnePageTasks($existingTasks, $pageType, $targetTasks);
        $structured['execution_steps'] = $this->buildExecutionSteps($executionBlueprint['tasks']);
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? $structured['i18n']['locale'] ?? ''));
        $planJson = $this->buildPlanJson($structured);
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);
        $summary = [
            'mode' => 'refine_page',
            'page_type' => $pageType,
            'target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
            'instruction' => $instruction,
            'round' => $round,
            'preserved_page_types' => \array_values(\array_filter(
                $originalPageKeys,
                static fn(string $candidate): bool => $candidate !== $pageType
            )),
            'changed_block_count' => \count(\is_array($pages[$pageType]['blocks'] ?? null) ? $pages[$pageType]['blocks'] : []),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $planJson['change_scope_report'] = $summary;
        $structured['change_scope_report'] = $summary;

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
            'page_refine_summary' => $summary,
        ];
    }

    /**
     * 微调策略：默认累加（保留历史追加区块），仅当用户明确“删除/移除”时执行删除。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>
     */
    private function applyRefineAccumulativePolicy(array $scope, array $artifacts, string $instruction): array
    {
        $currentPlanJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        if (!\is_array($currentPlanJson['pages'] ?? null)) {
            return $artifacts;
        }
        $existingPlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!\is_array($existingPlanJson['pages'] ?? null)) {
            return $artifacts;
        }

        $isDeleteIntent = $this->isDeleteInstruction($instruction);
        $deleteKeyword = $isDeleteIntent ? $this->extractDeleteKeyword($instruction) : '';
        $pages = $currentPlanJson['pages'];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan) || !\is_array($pagePlan['blocks'] ?? null)) {
                continue;
            }
            $currentBlocks = $pagePlan['blocks'];
            $existingBlocks = \is_array($existingPlanJson['pages'][$pageType]['blocks'] ?? null)
                ? $existingPlanJson['pages'][$pageType]['blocks']
                : [];
            if ($existingBlocks === []) {
                continue;
            }
            if ($isDeleteIntent) {
                $currentBlocks = $this->filterBlocksByDeleteInstruction($currentBlocks, $deleteKeyword);
            } else {
                $currentBlocks = $this->mergeAccumulativeCustomBlocks($existingBlocks, $currentBlocks);
            }
            $pages[$pageType]['blocks'] = \array_values($currentBlocks);
        }
        $currentPlanJson['pages'] = $pages;
        $artifacts['plan_json'] = $currentPlanJson;
        if (\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured']['pages'] = $pages;
        }

        return $this->refreshStageOnePlanArtifacts($artifacts, $scope);
    }

    /**
     * fake_mode 需要让块级微调/新增/删除/重建在前端产生可观察差异。
     * 否则多次操作会始终落到同一份确定性草稿，E2E 无法判断阶段内编辑是否生效。
     *
     * @param array<string, mixed> $artifacts
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyFakeModePreviewMutation(array $artifacts, array $scope, array $payload): array
    {
        $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        if ($pages === []) {
            return $artifacts;
        }

        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $promptMode = \strtolower(\trim((string)($payload['prompt_mode'] ?? '')));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $pageType = $this->resolveFakeModePlanPageType($pages, $targetScope);
        $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        $blocks = \array_values(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []);
        $signatureSeed = (string)\json_encode([
            'page_type' => $pageType,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'prompt_mode' => $promptMode,
            'round' => $round,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $operationId = \substr(\sha1($signatureSeed), 0, 8);

        if ($this->looksLikeAddBlockInstruction($instruction)) {
            $blocks[] = $this->buildFakeModePlanBlock($pageType, $instruction, $round, $operationId);
        } elseif ($this->isDeleteInstruction($instruction)) {
            $blocks = $this->removeFakeModePlanBlock($blocks, $targetScope, $instruction);
        } else {
            $blocks = $this->annotateFakeModePlanBlock($blocks, $instruction, $targetScope, $round, $operationId, $promptMode);
        }

        if ($blocks === []) {
            $blocks[] = $this->buildFakeModePlanBlock($pageType, 'restore block after delete', $round, $operationId);
        }

        $pagePlan['blocks'] = \array_values($blocks);
        $pagePlan['page_goal'] = \trim((string)($pagePlan['page_goal'] ?? ''));
        if ($promptMode === 'rebuild') {
            $pagePlan['page_goal'] = \trim($pagePlan['page_goal'] . '（重建轮次 #' . $round . '）');
        }
        $pages[$pageType] = $pagePlan;
        $planJson['pages'] = $pages;
        $planJson['change_scope_report'] = [
            'mode' => $promptMode !== '' ? $promptMode : 'refine',
            'round' => $round,
            'target_scope' => $targetScope,
            'instruction' => $instruction,
            'operation_id' => $operationId,
        ];

        $artifacts['plan_json'] = $planJson;
        if (\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured']['change_scope_report'] = $planJson['change_scope_report'];
        }
        $artifacts = $this->refreshStageOnePlanArtifacts($artifacts, $scope);
        if (\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured']['change_scope_report'] = $planJson['change_scope_report'];
        }

        return $artifacts;
    }

    /**
     * @param array<string, mixed> $artifacts
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function refreshStageOnePlanArtifacts(array $artifacts, array $scope): array
    {
        $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        if ($planJson === []) {
            return $artifacts;
        }

        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $planBlocks = $this->buildReadablePlanBlocksFromPlanJson($planJson, $planLocale);
        $planJson['plan_blocks'] = $planBlocks;
        $artifacts['plan_json'] = $planJson;

        if (\is_array($artifacts['structured'] ?? null)) {
            $artifacts['structured'] = \array_replace(
                $artifacts['structured'],
                [
                    'site_strategy' => \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [],
                    'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
                    'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
                    'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
                    'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
                    'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
                    'pages' => \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
                    'plan_blocks' => $planBlocks,
                ]
            );
        }

        $siteName = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $changeScopeReport = \is_array($planJson['change_scope_report'] ?? null) ? $planJson['change_scope_report'] : [];
        $artifacts['markdown'] = $this->renderReadableMarkdownFromPlanBlocks($planBlocks, $planLocale, $siteName, $changeScopeReport);
        return $artifacts;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return list<array<string, mixed>>
     */
    private function buildReadablePlanBlocksFromPlanJson(array $planJson, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $headerItems = \is_array($planJson['navigation_plan']['header_items'] ?? null) ? $planJson['navigation_plan']['header_items'] : [];
        $footerPolicies = \is_array($planJson['footer_plan']['policies'] ?? null) ? $planJson['footer_plan']['policies'] : [];
        $contentItems = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $contentItems[] = [
                'title' => (string)$pageType,
                'goal' => (string)($pagePlan['page_goal'] ?? ''),
                'theme_alignment_summary' => (string)($pagePlan['theme_alignment_summary'] ?? ''),
                'blocks' => \array_values(\array_filter(\array_map(function ($block) use ($locale) {
                    if (!\is_array($block)) {
                        return null;
                    }

                    return [
                        'block_key' => (string)($block['block_key'] ?? ''),
                        'goal' => (string)($block['goal'] ?? ''),
                        'content' => $this->buildBlockContentSummary($block),
                        'implementation_note' => $this->buildBlockImplementationFocus($block, $locale),
                        'design_tags' => \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [],
                        'field_plan' => $this->normalizeStageOneFieldPlanForCustomerView(\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : []),
                    ];
                }, \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []))),
            ];
        }

        return [
            [
                'block_id' => 'plan_header_001',
                'region' => 'header',
                'type' => 'title',
                'title' => $isEn ? 'Site Blueprint Header' : '方案头部',
                'content' => ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点')) . ' - ' . ($summary !== '' ? $summary : ($isEn ? 'Blueprint overview' : '方案概览')),
                'items' => [],
            ],
            [
                'block_id' => 'plan_background_001',
                'region' => 'body',
                'type' => 'background',
                'title' => $isEn ? 'Planning Background' : '规划背景',
                'content' => $summary !== '' ? $summary : ($isEn ? 'Use selected page types and conversion goals as baseline.' : '以当前页面类型与转化目标作为规划基线。'),
                'items' => [
                    'header_navigation' => $headerItems,
                    'footer_policies' => $footerPolicies,
                ],
            ],
            [
                'block_id' => 'plan_catalog_001',
                'region' => 'catalog',
                'type' => 'content_catalog',
                'title' => $isEn ? 'Page Block Catalog' : '页面区块目录',
                'content' => $isEn ? 'Implementation-ready block catalog for the virtual-theme build.' : '用于虚拟主题构建的区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '方案尾部',
                'content' => $isEn ? 'This blueprint is ready for virtual-theme build execution.' : '该方案已可直接进入虚拟主题构建执行。',
                'items' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $planBlocks
     */
    private function renderReadableMarkdownFromPlanBlocks(array $planBlocks, string $locale = '', string $siteName = '', array $changeScopeReport = []): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $lines = [];
        $lines[] = $isEn ? '# Site Blueprint' : '# 站点方案蓝图';
        $lines[] = '';
        $lines[] = '- ' . ($isEn ? 'Site: ' : '站点：') . ($siteName !== '' ? $siteName : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = '';
        foreach ($planBlocks as $block) {
            $title = \trim((string)($block['title'] ?? ''));
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $type = \trim((string)($block['type'] ?? 'section'));
            $region = \trim((string)($block['region'] ?? 'body'));
            $content = \trim((string)($block['content'] ?? ''));
            $lines[] = '## ' . ($title !== '' ? $title : ($isEn ? 'Block' : '区块'));
            $lines[] = '- ' . ($isEn ? 'Block ID: ' : '区块 ID：') . ($blockId !== '' ? $blockId : '-');
            $lines[] = '- ' . ($isEn ? 'Region: ' : '区域：') . ($region !== '' ? $region : 'body');
            $lines[] = '- ' . ($isEn ? 'Type: ' : '类型：') . ($type !== '' ? $type : 'section');
            if ($content !== '') {
                $lines[] = '- ' . ($isEn ? 'Content: ' : '内容：') . $content;
            }
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            if ($items !== []) {
                $lines[] = $isEn ? '### Items' : '### 条目';
                foreach ($items as $key => $item) {
                    if (\is_string($key) && $key !== '') {
                        $lines[] = '- ' . $key . ': ' . (\is_scalar($item) ? (string)$item : (\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                    } else {
                        $lines[] = '- ' . (\is_scalar($item) ? (string)$item : (\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                    }
                }
            }
            $lines[] = '';
        }

        if ($changeScopeReport !== []) {
            $mode = \trim((string)($changeScopeReport['mode'] ?? 'refine'));
            $targetScope = \trim((string)($changeScopeReport['target_scope'] ?? 'plan'));
            $instruction = \trim((string)($changeScopeReport['instruction'] ?? ''));
            $operationId = \trim((string)($changeScopeReport['operation_id'] ?? ''));
            $round = (int)($changeScopeReport['round'] ?? 0);
            $lines[] = $isEn ? '## Latest Change Summary' : '## 本轮变更摘要';
            $lines[] = '- ' . ($isEn ? 'Mode: ' : '模式：') . ($mode !== '' ? $mode : 'refine');
            $lines[] = '- ' . ($isEn ? 'Target Scope: ' : '作用范围：') . ($targetScope !== '' ? $targetScope : 'plan');
            if ($round > 0) {
                $lines[] = '- ' . ($isEn ? 'Round: ' : '轮次：') . (string)$round;
            }
            if ($operationId !== '') {
                $lines[] = '- ' . ($isEn ? 'Operation ID: ' : '操作 ID：') . $operationId;
            }
            if ($instruction !== '') {
                $lines[] = '- ' . ($isEn ? 'Instruction: ' : '指令：') . $instruction;
            }
            $lines[] = '';
        }

        return \str_replace(
            ['Block Direction (Stage 1 Blueprint)', '区块方向（阶段一蓝图）'],
            ['Block Content Plan', '区块内容方案'],
            \implode("\n", $lines)
        );
    }

    /**
     * @param array<string, mixed> $pages
     */
    private function resolveFakeModePlanPageType(array $pages, string $targetScope): string
    {
        if (\preg_match('/pages\.([a-z0-9_]+)/i', $targetScope, $matches) === 1 && !empty($pages[$matches[1]])) {
            return (string)$matches[1];
        }

        $pageTypes = \array_keys($pages);
        return (string)($pageTypes[0] ?? 'home_page');
    }

    private function looksLikeAddBlockInstruction(string $instruction): bool
    {
        $text = \mb_strtolower(\trim($instruction));
        if ($text === '') {
            return false;
        }

        return \str_contains($text, 'add block')
            || \str_contains($text, 'add a')
            || \str_contains($text, '新增')
            || \str_contains($text, '补足')
            || \str_contains($text, '添加');
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function annotateFakeModePlanBlock(array $blocks, string $instruction, string $targetScope, int $round, string $operationId, string $promptMode): array
    {
        if ($blocks === []) {
            return [
                $this->buildFakeModePlanBlock('home_page', $instruction, $round, $operationId),
            ];
        }

        $targetKey = '';
        if (\preg_match('/blocks\.([a-z0-9_]+)/i', $targetScope, $matches) === 1) {
            $targetKey = (string)$matches[1];
        }

        foreach ($blocks as $idx => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
            if ($targetKey !== '' && $blockKey !== $targetKey) {
                continue;
            }
            $label = $instruction !== '' ? $instruction : ($promptMode === 'rebuild' ? '重建当前方案块' : '微调当前方案块');
            $content = \trim((string)($block['content'] ?? ''));
            $goal = \trim((string)($block['goal'] ?? ''));
            $blocks[$idx]['content'] = $content . ($content !== '' ? "\n" : '') . '[fake-mode:' . $operationId . '] ' . $label;
            $blocks[$idx]['goal'] = $goal !== '' ? $goal . '（' . $label . '）' : $label;
            return $blocks;
        }

        $blocks[0]['content'] = \trim((string)($blocks[0]['content'] ?? '')) . "\n[fake-mode:" . $operationId . '] ' . ($instruction !== '' ? $instruction : '更新当前区块');
        return $blocks;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function removeFakeModePlanBlock(array $blocks, string $targetScope, string $instruction): array
    {
        if ($blocks === []) {
            return [];
        }

        $targetKey = '';
        if (\preg_match('/blocks\.([a-z0-9_]+)/i', $targetScope, $matches) === 1) {
            $targetKey = (string)$matches[1];
        }
        if ($targetKey === '' && $instruction !== '') {
            $targetKey = $this->extractDeleteKeyword($instruction);
        }

        if ($targetKey !== '') {
            $filtered = \array_values(\array_filter($blocks, static function (array $block) use ($targetKey): bool {
                $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? $block['section_code'] ?? '')));
                return $blockKey !== \mb_strtolower($targetKey);
            }));
            if ($filtered !== []) {
                return $filtered;
            }
        }

        \array_pop($blocks);
        return \array_values($blocks);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFakeModePlanBlock(string $pageType, string $instruction, int $round, string $operationId): array
    {
        $blockKey = 'custom_' . \strtolower($operationId);
        $brief = $instruction !== '' ? $instruction : '补足当前页面的关键信息区块';

        return [
            'block_key' => $blockKey,
            'goal' => '补充 ' . $pageType . ' 页面在第 ' . $round . ' 轮操作中的缺失信息。',
            'keywords' => ['fake-mode', $pageType, $blockKey],
            'content' => '[fake-mode:' . $operationId . '] ' . $brief,
            'field_plan' => [
                [
                    'field' => 'headline',
                    'sample' => '新增区块 ' . $round,
                    'reason' => '保证新增区块在预览和 Markdown 中可被识别。',
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $existingBlocks
     * @param list<array<string, mixed>> $currentBlocks
     * @return list<array<string, mixed>>
     */
    private function mergeAccumulativeCustomBlocks(array $existingBlocks, array $currentBlocks): array
    {
        $knownFingerprints = [];
        foreach ($currentBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $knownFingerprints[$this->buildBlockFingerprint($block)] = true;
        }
        $merged = $currentBlocks;
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (\mb_strtolower(\trim((string)($block['block_key'] ?? ''))) !== 'custom') {
                continue;
            }
            $fp = $this->buildBlockFingerprint($block);
            if (isset($knownFingerprints[$fp])) {
                continue;
            }
            $merged[] = $block;
            $knownFingerprints[$fp] = true;
        }
        \usort($merged, static fn(array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0)));
        return \array_values($merged);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function filterBlocksByDeleteInstruction(array $blocks, string $deleteKeyword): array
    {
        if ($deleteKeyword === '') {
            return $blocks;
        }
        $keyword = \mb_strtolower($deleteKeyword);
        return \array_values(\array_filter($blocks, static function ($block) use ($keyword): bool {
            if (!\is_array($block)) {
                return true;
            }
            $serialized = \mb_strtolower((string)(\json_encode($block, \JSON_UNESCAPED_UNICODE) ?: ''));
            return !\str_contains($serialized, $keyword);
        }));
    }

    private function isDeleteInstruction(string $instruction): bool
    {
        $text = \mb_strtolower(\trim($instruction));
        if ($text === '') {
            return false;
        }
        return $this->containsAny($text, ['删除', '移除', '去掉', '删掉', 'remove', 'delete']);
    }

    private function extractDeleteKeyword(string $instruction): string
    {
        $text = \trim($instruction);
        if ($text === '') {
            return '';
        }
        if (\preg_match(
            '/(?:删除|移除|去掉|删掉)(?:一段|一块|一节|一条)?\s*([^，。、\s]{1,20})/u',
            $text,
            $matches
        )) {
            return \trim((string)($matches[1] ?? ''));
        }
        if (\preg_match('/(?:remove|delete)\s+([a-zA-Z0-9_\-\s]{1,30})/i', $text, $matches)) {
            return \trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildBlockFingerprint(array $block): string
    {
        $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
        $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
        $titleSample = '';
        if (\is_array($block['field_plan'] ?? null) && isset($block['field_plan'][0]) && \is_array($block['field_plan'][0])) {
            $titleSample = \mb_strtolower(\trim((string)($block['field_plan'][0]['sample'] ?? '')));
        }
        return \md5($blockKey . '|' . $sectionCode . '|' . $titleSample);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $payload
     * @return array{
     *   plan_json:array<string, mixed>,
     *   structured:array<string, mixed>,
     *   execution_blueprint:array<string, mixed>,
     *   derived_scope_patch:array<string, mixed>,
     *   markdown:string,
     *   rebuild_summary:array<string, mixed>
     * }
     */
    public function rebuildDraftPlan(
        array $scope,
        array $websiteProfile,
        array $payload,
        ?callable $onChunk = null,
        ?callable $onProgress = null
    ): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk, $onProgress);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $summary = [
            'mode' => 'rebuild',
            'round' => $round,
            'instruction' => $instruction,
            'task_count' => \count(\is_array($artifacts['execution_blueprint']['tasks'] ?? null) ? $artifacts['execution_blueprint']['tasks'] : []),
            'page_type_count' => \count(\is_array($artifacts['execution_blueprint']['page_types'] ?? null) ? $artifacts['execution_blueprint']['page_types'] : []),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $artifacts['structured']['rebuild_summary'] = $summary;
        $artifacts['plan_json']['rebuild_summary'] = $summary;
        $artifacts['execution_blueprint']['signature'] = $this->buildExecutionBlueprintSignature($artifacts['execution_blueprint']);
        $artifacts['rebuild_summary'] = $summary;

        return $artifacts;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function buildAiPlanPrompt(
        array $scope,
        array $websiteProfile,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $targetScope,
        string $siteDisplayName,
        string $siteSummary
    ): string {
        $baseline = $this->buildPlanArtifacts($scope, $websiteProfile, [
            'instruction' => $instruction,
            'target_scope' => $targetScope,
        ]);
        $baselinePlanJson = \is_array($baseline['plan_json'] ?? null) ? $baseline['plan_json'] : [];
        $baselineText = $baselinePlanJson === []
            ? '{}'
            : (\json_encode($baselinePlanJson, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}');
        $baselineExecutionBlueprint = \is_array($baseline['execution_blueprint'] ?? null) ? $baseline['execution_blueprint'] : [];
        $baselineExecutionBlueprintText = $baselineExecutionBlueprint === []
            ? '{}'
            : (\json_encode($baselineExecutionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}');
        $selectedPageCoverageHints = $this->buildSelectedPageCoverageHints($pageTypes);
        $promptInputProfile = $this->buildPromptInputProfile($scope, $websiteProfile, $instruction, $targetScope);

        $outputLanguage = $planLocale !== '' ? $planLocale : 'zh_Hans_CN';
        $pageTypeText = $pageTypes === [] ? '-' : \implode(', ', $pageTypes);
        $userBrief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $oneLineRequirement = $userBrief !== '' ? $userBrief : ($instruction !== '' ? $instruction : '-');
        $cleanPrompt = [
            // === 角色与意图：放到最前，先“锁定要做什么”，再给 schema ===
            'You are Structured Web Blueprint Engine for a real website builder.',
            'PRIMARY GOAL: Take the user one-line website requirement and EXPAND it into a CONCRETE, READY-TO-BUILD plan. This is NOT a writing tutorial; it is the actual blueprint that Stage-2 will execute.',
            'STAGE ORDER CONTRACT: first decide theme_design (visual concept, palette, typography, spacing/radius, CTA tone, motion/interaction direction), then shared Header/Footer, then page plans and page blocks. Do not design page blocks before the shared theme contract exists.',
            'PRODUCTION SITE CONTRACT: the result must be suitable for an operating website, not a demo, not a proposal page, and not a page that explains the plan to visitors.',
            'VISUAL RICHNESS CONTRACT: unless the user explicitly asks for minimalism, choose a rich but coherent visual system with layered backgrounds, varied accent colors, and role-specific compositions (hero stage, proof rail, FAQ rows, form split, CTA band, channel hub, etc.). Do not make every page block the same three-card grid with different copy. Use deliberate typography, surface depth, button hover/focus states, transition timing, and smooth scroll/reveal motion guidance; avoid icon/SVG decoration as the primary visual language.',
            '中文要求：根据下面的【用户一句话需求】拓写出**真实可落地**的建站方案——给出具体导航、栏目、标题、正文、CTA、字段示例与落地说明，禁止通篇“围绕…/突出…/说明…”这类方向性描述。',
            '【用户一句话需求】(authoritative, expand from this): ' . $oneLineRequirement,
            '【站点名】: ' . ($siteDisplayName !== '' ? $siteDisplayName : '-'),
            '【站点摘要】: ' . ($siteSummary !== '' ? $siteSummary : '-'),
            '【选定页面类型】: ' . $pageTypeText,
            '【输出语言】: ' . $outputLanguage,
            'Reference images for visual style (if any): ' . (($referenceImageJson = \json_encode($this->buildReferenceImagePromptList($scope), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)) && $referenceImageJson !== '[]' ? $referenceImageJson : '-'),
            '',
            'CONCRETENESS CONTRACT (must satisfy ALL):',
            '1) Every block has REAL on-page strings: nav labels, page titles, headings, subtitles, body sentences, CTA labels, link targets, form fields, trust points.',
            '2) Replace any sentence that would still be true for an unrelated business with words derived from the user one-line requirement, site name, and instruction.',
            '3) Use proper nouns / numbers / offers / brand voice; avoid generic "突出价值/说明亮点/完善导航" sentences.',
            '4) When facts are uncertain, use prefix "[假设]" and STILL output a concrete sample value (not a placeholder).',
            '5) navigation_plan.header_items, footer_plan.featured, footer_plan.policies must be non-empty arrays of {label, href} with real labels and exact route-contract paths. If no policy pages are selected, footer_plan.policies must contain trust, safety, support, or compliance links to existing selected pages only; do not invent anchors.',
            ...$this->getSkillRegistry()->buildPromptGuideLinesForScope('stage1', $scope),
            ...$this->getDesignDirectionService()->buildStageOnePromptLines($scope),
            ...$this->buildStageOneGatePromptLines($pageTypes, $scope, 'full'),
            'STAGE-1 SHARED THEME PLAN CONTRACT (theme_design must satisfy ALL):',
            '- theme_design is the concrete shared plan for Header/Footer and later page prompts; never output it as abstract direction, brand adjectives, or design-method notes.',
            '- theme_design.theme_purpose must name the site mission, target visitor, first-screen emotion, and conversion promise derived from the user one-line requirement.',
            '- theme_design.color_scheme must provide ready-to-apply hex colors for primary/secondary/accent/background/body/button; values must be concrete implementation decisions, not labels like "modern palette".',
            '- theme_design.typography_spacing_radius must give usable font family, heading/body scale, spacing scale, and radius scale decisions that can be implemented without another interpretation step.',
            '- theme_design.visual_keywords, tone_of_voice, cta_tone, and forbidden_styles must be specific reusable constraints for page prompts, not vague words like "premium", "clean", or "professional" alone.',
            '- theme_design.style_signature is required: a brief-derived visual identity that makes this site feel intentionally designed, not a reusable default template.',
            '- theme_design.art_direction is required: layout_motif, background_system, surface_treatment, visual_detail_rule, and motion_rule must be concrete enough for frontend component generation.',
            '- Avoid default AI website aesthetics unless explicitly requested: no generic blue SaaS gradient, no purple-on-white default, no plain centered hero plus three cards, no default Inter/Roboto/Arial/system-font look as the main design.',
            '- Header/Footer planning must reuse this theme_design directly so shared_prompt_context can carry concrete colors, voice, CTA tone, and forbidden styles to every page.',
            '',
            'STAGE-1 PAGE THEME ALIGNMENT CONTRACT (pages must satisfy ALL):',
            '- Every page prompt MUST treat theme_design + shared_prompt_context as non-negotiable constraints, not optional inspiration.',
            '- Every page object MUST include theme_alignment_summary explaining how that page reuses theme_purpose, color_scheme, typography_spacing_radius, tone_of_voice, cta_tone, and forbidden_styles.',
            '- theme_alignment_summary must be a concrete page-specific sentence, not a schema phrase. It must name actual theme words/colors/rules from theme_design and the specific page/block rhythm.',
            '- The literal values "string", "how this page obeys", "string explaining how this page obeys", and "how this page and every block obey theme_design" are invalid output, not examples to copy.',
            '- Repeat the shared theme decisions inside each page plan: page_goal, blocks, field_plan samples, execution_script, CTA wording, and media assets must visibly obey the same palette, voice, spacing/radius, and forbidden styles.',
            '- If a page idea conflicts with shared_prompt_context, rewrite the page idea. Never invent a per-page palette, voice, CTA style, or visual direction that diverges from theme_design.',
            '- Every page object MUST include the canonical page_design_plan keys: page_role, content_narrative, visual_hierarchy, visual_signature_application, composition_motif, color_layering, section_flow, interaction_notes, polish_details, and anti_monotony_rule.',
            '- Every block object MUST include visual_signature. It must describe the block-specific composition, focal element, image role, CTA role, and how it avoids repeating sibling blocks.',
            '- Page banners/opening blocks are not a fixed template. They must be composed from page_design_plan.page_role, page_design_plan.composition_motif, and current block visual_signature.',
            '',
            'GOOD vs BAD examples (do NOT copy verbatim, learn the structure only):',
            'BAD field_plan.sample : "Write a title around the main value"',
            'GOOD field_plan.sample: "Trusted APK Download for Indian Card Players"',
            'BAD blocks[].content   : "Highlight brand value and guide action"',
            'GOOD blocks[].content  : "Download the APK, review the game lobby, and start from a clear first step."',
            'BAD execution_script.core_copy : "Briefly explain product benefits"',
            'GOOD execution_script.core_copy: "Players can understand the install step, game lobby, and safety proof before downloading."',
            'BAD navigation_plan.header_items : []',
            'GOOD navigation_plan.header_items: [{"label":"Home","href":"/"},{"label":"About","href":"/about"},{"label":"Blog","href":"/blog"},{"label":"Contact","href":"/contact"}]',
            '',
            'Return STRICT JSON only. No markdown fence. No prose outside JSON.',
            'The first non-whitespace character must be { and the last non-whitespace character must be }.',
            'Do not echo the schema, prompt rules, GOOD/BAD examples, or writing instructions back into the plan.',
            'Do not add extra explanatory fields named reason, why, rationale, thinking, analysis, explanation, chain_of_thought, design_reason, or reasoning. Use only the schema keys; selection_reason is allowed only in the three theme objects shown below.',
            'JSON schema (return this structured plan object directly as the top-level JSON object):',
            '{',
            '    "i18n":{"locale":"string","labels":{"title":"string","site":"string","summary":"string","site_structure":"string","shared_global_plan":"string","page_details":"string"}},',
            '    "site_strategy":{"site_display_name":"string","summary":"string","website_type":"string","core_goal":"string","target_users":"string","conversion_path":"string"},',
            '    "theme_style":{"name":"string","visual_tone":"string","font_family":"string","selection_reason":"internal fit summary, not shown as a plan reason"},',
            '    "palette":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","surface":"#hex","text":"#hex","selection_reason":"internal palette fit summary, not shown as a plan reason"},',
            '    "theme_design":{"theme_purpose":"string","style_signature":"brief-derived visual identity, not a generic theme name","art_direction":{"layout_motif":"string","background_system":"string","surface_treatment":"string","visual_detail_rule":"string","motion_rule":"string"},"color_scheme":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","background":"#hex","body":"#hex","button":"#hex"},"typography_spacing_radius":{"font_family":"string","heading_scale":"string","body_scale":"string","spacing_scale":"string","radius_scale":"string"},"visual_keywords":["string"],"tone_of_voice":"string","cta_tone":"string","forbidden_styles":["string"],"selection_reason":"internal brief-alignment summary; must copy at least one exact noun/action phrase from the requirement"},',
            '    "navigation_plan":{"header_items":[{"label":"string","href":"string"}]},',
            '    "footer_plan":{"featured":[],"policies":[]},',
            '    "seo_strategy":{"core_intent":"string","primary_keywords":["string"],"keyword_page_map":[{"keyword":"string","page_type":"string"}],"content_strategy":"string","internal_linking":"string","url_structure":"string"},',
            '    "page_types":["home_page"],',
            '    "pages":{"home_page":{"page_goal":"string","theme_alignment_summary":"string","page_design_plan":{"page_role":"string","content_narrative":"string","visual_hierarchy":"string","visual_signature_application":"string","composition_motif":"string","color_layering":"string","section_flow":["string"],"interaction_notes":["string"],"polish_details":["string"],"anti_monotony_rule":"string"},"primary_keywords":["string"],"secondary_keywords":["string"],"blocks":[{"block_key":"string","page_flow_role":"opening|proof|details|cta|support","goal":"string","keywords":["string"],"content":"string","design_tags":{"visual":["string"],"motion":["string"],"interaction":["string"],"texture":["string"],"responsive":["string"],"color_layering":"string","implementation_note":"string"},"visual_signature":{"composition_pattern":"string","spatial_rhythm":"string","media_strategy":"string","surface_treatment":"string","interaction_pattern":"string"},"image_intent":{"needs_image":true,"image_role":"hero_image|section_image|trust_brand_image|css_motif|none","image_subject":"specific scene/product/editorial subject or none for CSS-only","placement":"background_layer|media_panel|inline_visual|none","visual_atmosphere":"specific mood, environment, lighting, and brand feel","image_treatment":"specific crop, style, framing, overlay, and integration treatment","reuse_policy":"reuse_when_intent_matches|no_generated_image","css_motif":"required concrete CSS motif when needs_image=false, empty string when generated image is needed"},"field_plan":[{"field":"headline","sample":"visitor-facing heading","implementation_note":"where this heading renders"},{"field":"supporting_copy","sample":"visitor-facing support sentence","implementation_note":"where this body copy renders"},{"field":"context_detail","sample":"detail, proof, CTA label, image brief, form label, or policy summary","implementation_note":"where this detail renders"}],"execution_script":{"feature_points":["string"],"core_copy":"string","typography":"string","style_tone":"string","background_direction":"string","media_assets":["string"]},"reusable":"yes|no","seo_impact":"high|medium|low"}]}},',
            '    "execution_steps":[{"step":1,"task_key":"string","task_type":"string","status":"pending"}],',
            '    "build_plan_task_hints":[{"page":"string","block":"string","task_types":["copywriting","ui_design","frontend_dev"]}]',
            '}',
            'Hard rules:',
            '- theme_alignment_summary content rule: it must mention actual theme_design color_scheme, tone_of_voice, cta_tone, trust expression, one avoided forbidden style, and Header/Footer handoff in the page locale.',
            '- seo_strategy is required planning metadata. It MUST include core_intent, 3-6 primary_keywords, keyword_page_map with at least one row for every selected page type, content_strategy, internal_linking, and url_structure. Never omit seo_strategy to save tokens.',
            '- Output budget is mandatory: home_page MUST contain 5-7 blocks, other pages 3-5 blocks; each block MUST contain exactly 3 field_plan rows; execution_script.feature_points MUST contain at most 3 short items; content/core_copy MUST be concise final copy, not long article text.',
            '- Prefer dense implementation-ready summaries over exhaustive copy. BuildPlan will expand executable tasks later, so Stage-1 must stay compact enough to return complete valid JSON.',
            '- All text fields must use locale: ' . $outputLanguage,
            '- Strict visible-copy field boundary: pages.*.blocks[].content, execution_script.core_copy, and field_plan[].sample become BuildPlan content_manifest seeds. They MUST be final visitor-facing copy in the Website content locale, not UI recipes, not layout instructions, not hover/background/card/grid descriptions, and not explanations of why the block exists.',
            '- Put layout/effect/media directions only in implementation_note, design_tags, execution_script.typography, execution_script.style_tone, execution_script.background_direction, and execution_script.media_assets. Do not put those directions in content or field_plan.sample.',
            '- Language consistency gate: except the site title/brand and unavoidable acronyms like APK/APP/SEO, visible-copy seeds must not contain large phrases from another language. Rewrite foreign game/product names into the Website content locale unless the name is the exact brand.',
            '- Do not return markdown.',
            '- Do not return a separate markdown field.',
            '- Output only the structured plan object shown in the schema.',
            '- The plan must contain final-ready content samples, not writing instructions.',
            '- theme_design MUST be a concrete shared theme plan. Reject and rewrite it if it reads like directions about what to design instead of decisions to implement.',
            '- Every pages.*.theme_alignment_summary MUST explicitly name the shared theme purpose, palette/color use, type/spacing/radius rule, tone/CTA rule, and at least one forbidden style that the page avoids.',
            '- Every page block must be checked against shared_prompt_context before output; any page-specific color, voice, CTA, layout, or media direction that drifts from theme_design must be rewritten.',
            '- theme_style.selection_reason, palette.selection_reason, and theme_design.selection_reason are internal brief-alignment summaries only; do not present them as user-visible plan reasons.',
            '- theme_design.selection_reason must explicitly mention the user one-line requirement and copy at least one exact noun/action phrase from it so validation can prove the theme is tied to the brief.',
            '- selection_reason must connect the color/font/tone choices to the user one-line requirement without generic claims like "modern/professional/simple" as the whole value.',
            '- theme_design.selection_reason must never be empty. If uncertain, still output one concrete internal fit summary that quotes the requirement phrase and states how palette/font/tone support it.',
            '- Never write process wording such as "标题围绕核心价值展开", "正文说明主要亮点", "CTA 保持单一动作", or "字体与排版指定".',
            '- Never write blueprint guidance such as "围绕...说明", "首页先讲清...", "阶段一仅给方向", "List 2-4 points", or "Specify heading font".',
            '- For each block, content must read like real website copy that can be shown to a client immediately.',
            '- Example for hero: write concrete title, subtitle, description, CTA label, trust points, and support text. Do not describe what should be written.',
            '- field_plan.sample must be direct content, for example "欢迎来到示例品牌服务中心" or "立即开始", not "标题围绕核心价值展开".',
            '- field_plan.implementation_note must be a customer-readable implementation note such as layout handling, editable constraint, delivery requirement, or asset direction; never write abstract design rationale or prompt guidance.',
            '- For media/image fields, field_plan.sample must be a concrete asset brief the client can review, not a generic instruction like "使用一张主视觉图".',
            '- Do not output fake image URLs such as hero.jpg, about.jpg, example.com, placeholder services, or unverified .jpg/.png/.webp paths. When an image is needed, describe a concrete generated asset brief; the build must use a verified generated/uploaded asset and fail rather than substituting placeholders.',
            '- execution_script.feature_points must be concrete deliverables for this block, not meta-writing advice.',
            '- execution_script.core_copy must summarize the actual content message already written for the block.',
            '- Treat the output as a customer-visible implementation plan: every visible sentence must answer the user brief directly.',
            '- The hero title/subtitle/description MUST reuse the most concrete nouns from the one-line requirement (market, product type, offer, download/service words) instead of abstract labels like "核心价值" or "下一步动作".',
            '- If the brief mentions app/APK download, booking, consultation, pricing, trial, or signup, at least one CTA label must reflect that exact action directly.',
            '- page_types can only use selected_page_types.',
            '- If information is missing, you may make reasonable assumptions, but mark them with the prefix "[假设]".',
            '- Even in refine/rebuild/translation mode, you must still output the full plan, not fragments.',
            '- Stage 1 only outputs plan content. Do not include build/executing/log/progress language.',
            '- Every selected page must be covered and ready for virtual-theme build task execution.',
            '- Each pages.<page>.theme_alignment_summary is REQUIRED and must explain how that page and its blocks obey theme_design color_scheme, tone_of_voice, cta_tone, trust expression, and Header/Footer handoff.',
            '- Header and footer must be described as concrete shared-site content and navigation.',
            '- The structured plan must be readable by product and implementation teams immediately.',
            '- Minimum concreteness: navigation_plan.header_items MUST be non-empty; each item MUST include concrete label + exact route-contract href tied to selected_page_types; forbid generic placeholders like "Link1" or "Nav item".',
            '- footer_plan.featured and footer_plan.policies MUST list real link titles and destinations users can click, not phrases like "补充政策链接". If no policy pages are selected, policies MUST become trust/safety/support/compliance links to selected pages only, never anchors.',
            '- Each non-trivial page block: field_plan MUST have exactly 3 entries; every sample is final copy or starts with "[假设]" and still contains concrete wording.',
            '- Every field_plan row should pair the final sample with a short implementation_note that explains how the sample lands on the page, not why the AI chose it.',
            '- blocks[].content MUST be multi-sentence client-ready copy or bullet lines of real sentences; a single meta line such as "突出品牌价值" is invalid.',
            '- Self-check: if any paragraph stays true for an unrelated business, rewrite it using nouns, offers, and proof points from site_display_name + instruction + prompt_input_profile.',
            '- When classifying uncertainty, use inline "[已知]"/"[建议]"/"[假设]" sparingly; never leave a section as pure methodology without named UI strings, colors, or labels.',
            '- Final audit (silently before output): for every block check (a) does it cite at least one concrete noun/number/brand from the user one-line requirement? (b) does field_plan have >=3 entries with real samples? (c) does it avoid verbs like "围绕/突出/说明/突出/完善/优化" used as the only description? If any check fails, REWRITE that block before returning.',
            'Selected page coverage hints (must all be represented in the final plan):',
            $selectedPageCoverageHints,
            'Input context:',
            'site_display_name: ' . $siteDisplayName,
            'site_summary: ' . ($siteSummary !== '' ? $siteSummary : '-'),
            'selected_page_types: ' . $pageTypeText,
            'target_scope: ' . ($targetScope !== '' ? $targetScope : 'full_plan'),
            'instruction: ' . ($instruction !== '' ? $instruction : '-'),
            'prompt_input_profile:',
            $promptInputProfile,
            'baseline_plan_json:',
            $baselineText,
            'baseline_execution_blueprint:',
            $baselineExecutionBlueprintText,
        ];
        if (\function_exists('w_log')) {
            \call_user_func('w_log', 'info', \implode("\n", $cleanPrompt), [], 'buildAiPlanPrompt');
        }

        return \implode("\n", $cleanPrompt);
    }

    /**
     * @param list<string> $pageTypes
     */
    private function buildSelectedPageCoverageHints(array $pageTypes): string
    {
        $lines = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $lines[] = '- ' . $pageType . ': must include page goal, theme_alignment_summary, conversion rhythm, block implementation detail, field plan, execution script, SEO structure, CTA usage, responsive guidance.';
        }

        return $lines === [] ? '-' : \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     */
    private function buildPromptInputProfile(
        array $scope,
        array $websiteProfile,
        string $instruction,
        string $targetScope
    ): string {
        $profile = [
            'site_title' => \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')),
            'site_tagline' => \trim((string)($scope['site_tagline'] ?? $websiteProfile['site_tagline'] ?? '')),
            'brief_description' => \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? '')),
            'target_domain' => \trim((string)($scope['target_domain'] ?? $websiteProfile['target_domain'] ?? '')),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? '')),
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'reference_images' => $this->buildReferenceImagePromptList($scope),
        ];

        return (string)(\json_encode($profile, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array{name:string,url:string,path:string,mime_type:string}>
     */
    private function buildReferenceImagePromptList(array $scope): array
    {
        return $this->getReferenceImageInsightService()->buildReferenceImagePromptList($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @param callable(string):void|null $onChunk
     * @return array<string, mixed>
     */
    private function analyzeReferenceImagesByAi(array $scope, string $planLocale, ?callable $onChunk = null): array
    {
        unset($onChunk);
        return $this->getReferenceImageInsightService()->analyze($scope, $planLocale);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildReferenceImageInsightsPromptText(array $scope): string
    {
        $insights = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        if ($insights === []) {
            return '-';
        }

        $encoded = \json_encode($insights, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        return \is_string($encoded) && $encoded !== '' ? $encoded : '-';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildReferenceStyleContext(array $scope): array
    {
        $insights = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        if ($insights === []) {
            return [];
        }

        return [
            'summary' => \trim((string)($insights['summary'] ?? '')),
            'style_keywords' => $this->normalizeStringList($insights['style_keywords'] ?? []),
            'color_palette' => $this->normalizeStringList($insights['color_palette'] ?? []),
            'layout_cues' => $this->normalizeStringList($insights['layout_cues'] ?? []),
            'component_cues' => $this->normalizeStringList($insights['component_cues'] ?? []),
            'typography_cues' => $this->normalizeStringList($insights['typography_cues'] ?? []),
            'do_not_use' => $this->normalizeStringList($insights['do_not_use'] ?? []),
            'implementation_rule' => 'Carry these reference-image cues into theme_design, page_type_overviews, shared_prompt_context, and later page/block style plans; adapt them to the brief instead of copying the image pixel-for-pixel.',
            'signature' => \trim((string)($scope['reference_image_insights_signature'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $themeDesign
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function applyReferenceImageInsightsToThemeDesign(array $themeDesign, array $scope): array
    {
        $referenceStyleContext = $this->buildReferenceStyleContext($scope);
        if ($referenceStyleContext === []) {
            return $themeDesign;
        }

        $existingReferenceContext = \is_array($themeDesign['reference_style_context'] ?? null)
            ? $themeDesign['reference_style_context']
            : [];
        $themeDesign['reference_style_context'] = $this->mergeReferenceStyleContext(
            $referenceStyleContext,
            $existingReferenceContext
        );
        $themeDesign['visual_keywords'] = \array_values(\array_unique(\array_merge(
            $this->normalizeStringList($themeDesign['visual_keywords'] ?? []),
            $this->normalizeStringList($referenceStyleContext['style_keywords'] ?? []),
            $this->normalizeStringList($referenceStyleContext['layout_cues'] ?? [])
        )));
        $themeDesign['forbidden_styles'] = \array_values(\array_unique(\array_merge(
            $this->normalizeStringList($themeDesign['forbidden_styles'] ?? []),
            $this->normalizeStringList($referenceStyleContext['do_not_use'] ?? [])
        )));

        $artDirection = \is_array($themeDesign['art_direction'] ?? null) ? $themeDesign['art_direction'] : [];
        $summary = \trim((string)($referenceStyleContext['summary'] ?? ''));
        $layoutCue = $this->firstNonEmptyString($this->normalizeStringList($referenceStyleContext['layout_cues'] ?? []));
        $componentCue = $this->firstNonEmptyString($this->normalizeStringList($referenceStyleContext['component_cues'] ?? []));
        $typographyCue = $this->firstNonEmptyString($this->normalizeStringList($referenceStyleContext['typography_cues'] ?? []));
        if ($summary !== '' && !\str_contains((string)($themeDesign['selection_reason'] ?? ''), $summary)) {
            $baseReason = \trim((string)($themeDesign['selection_reason'] ?? ''));
            $themeDesign['selection_reason'] = ($baseReason !== '' ? $baseReason . ' ' : '')
                . 'Reference image cue: ' . $summary;
        }
        if ($layoutCue !== '') {
            $artDirection['layout_motif'] = $this->appendReferenceCue((string)($artDirection['layout_motif'] ?? ''), $layoutCue);
        }
        if ($componentCue !== '') {
            $artDirection['surface_treatment'] = $this->appendReferenceCue((string)($artDirection['surface_treatment'] ?? ''), $componentCue);
            $artDirection['visual_detail_rule'] = $this->appendReferenceCue((string)($artDirection['visual_detail_rule'] ?? ''), $componentCue);
        }
        if ($typographyCue !== '') {
            $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
            $typography['font_family'] = $this->appendReferenceCue((string)($typography['font_family'] ?? ''), $typographyCue);
            $themeDesign['typography_spacing_radius'] = $typography;
        }
        $themeDesign['art_direction'] = $artDirection;

        return $themeDesign;
    }

    /**
     * @param array<string, mixed> $referenceStyleContext
     * @param array<string, mixed> $existingReferenceContext
     * @return array<string, mixed>
     */
    private function mergeReferenceStyleContext(array $referenceStyleContext, array $existingReferenceContext): array
    {
        $merged = $referenceStyleContext;
        foreach (['summary', 'implementation_rule', 'signature'] as $field) {
            $existing = \trim((string)($existingReferenceContext[$field] ?? ''));
            if ($existing !== '') {
                $merged[$field] = $existing;
            }
        }
        foreach (['style_keywords', 'color_palette', 'layout_cues', 'component_cues', 'typography_cues', 'do_not_use'] as $field) {
            $merged[$field] = \array_values(\array_unique(\array_merge(
                $this->normalizeStringList($referenceStyleContext[$field] ?? []),
                $this->normalizeStringList($existingReferenceContext[$field] ?? [])
            )));
        }

        return $merged;
    }

    private function appendReferenceCue(string $base, string $cue): string
    {
        $base = \trim($base);
        $cue = \trim($cue);
        if ($cue === '' || \mb_stripos($base, $cue) !== false) {
            return $base;
        }
        if ($base === '') {
            return $cue;
        }

        return $base . '; reference cue: ' . $cue;
    }

    /**
     * 根据选择的页面类型动态生成 Markdown 模板
     *
     * @param list<string> $pageTypes
     * @return string
     */
    private function buildPageMarkdownTemplate(array $pageTypes): string
    {
        $lines = [
            '# Site Blueprint',
            '## Global',
            '### Route Structure',
            '### Header',
            '### Footer',
            '### Shared Typography',
            '### Shared Palette',
            '### CTA System',
            '### SEO Rules',
            '',
            'Use concrete content under every heading.',
            'For each block, write the real copy, real CTA, and real trust/support text.',
            'Do not write guidance such as "围绕核心价值说明" or "标题请突出卖点".',
        ];

        $pageDefinitions = [
            Page::TYPE_HOME => ['label' => 'Home', 'blocks' => ['Hero', 'Feature', 'Process', 'CTA']],
            Page::TYPE_ABOUT => ['label' => 'About', 'blocks' => ['Hero', 'Brand Story', 'Trust', 'CTA']],
            Page::TYPE_CONTACT => ['label' => 'Contact', 'blocks' => ['Hero', 'Contact Methods', 'FAQ', 'CTA']],
            'faq_page' => ['label' => 'FAQ', 'blocks' => ['Hero', 'Question List', 'Support CTA']],
            Page::TYPE_BLOG_LIST => ['label' => 'Blog', 'blocks' => ['Hero', 'Article Grid', 'Content CTA']],
            'services_page' => ['label' => 'Services', 'blocks' => ['Hero', 'Service List', 'Process', 'FAQ', 'CTA']],
            'download_page' => ['label' => 'Download', 'blocks' => ['Hero', 'Steps', 'FAQ', 'Support']],
        ];

        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }

            $definition = $pageDefinitions[$pageType] ?? [
                'label' => \ucwords(\str_replace('_', ' ', $pageType)),
                'blocks' => ['Hero', 'Feature', 'CTA'],
            ];

            $lines[] = '';
            $lines[] = '## Page: ' . $definition['label'];
            $lines[] = '### Page Goal';
            $lines[] = '### Conversion Rhythm';

            foreach ($definition['blocks'] as $blockName) {
                $lines[] = '### Block: ' . $blockName;
                $lines[] = '#### Block ID';
                $lines[] = '#### Function';
                $lines[] = '#### Content';
                $lines[] = '#### Content Fields';
                $lines[] = '#### SEO Keywords';
                $lines[] = '#### SEO Structure';
                $lines[] = '#### CTA Usage';
                $lines[] = '#### Typography';
                $lines[] = '#### Colors';
                $lines[] = '#### Background';
                $lines[] = '#### Layout';
                $lines[] = '#### Motion';
                $lines[] = '#### Interaction';
                $lines[] = '#### Responsive';
                $lines[] = '#### Assets';
                $lines[] = '#### Image Rules';
                $lines[] = '#### Data Points';
                $lines[] = '#### Reusability';
            }
        }

        return \implode("\n", $lines);
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizeAiPlanResponseShape(array $decoded): array
    {
        if (\is_array($decoded['plan_json'] ?? null)) {
            return $decoded;
        }

        if (!$this->looksLikeAiStageOnePlanJsonPayload($decoded)) {
            return $decoded;
        }

        $planJson = $decoded;
        unset($planJson['markdown']);

        return [
            'markdown' => \trim((string)($decoded['markdown'] ?? '')),
            'plan_json' => $planJson,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function looksLikeAiStageOnePlanJsonPayload(array $candidate): bool
    {
        foreach (['site_strategy', 'theme_style', 'palette', 'navigation_plan', 'footer_plan', 'seo_strategy', 'pages'] as $sectionKey) {
            if (!\is_array($candidate[$sectionKey] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function describeAiPlanResponseKeys(array $decoded): string
    {
        $keys = \array_values(\array_filter(\array_map(
            static fn($key): string => \is_string($key) ? \trim($key) : '',
            \array_keys($decoded)
        )));

        return $keys === [] ? '[none]' : \implode(', ', $keys);
    }

    private function normalizeAiPlanGenerationErrorMessage(string $message): string
    {
        $normalized = \trim($message);
        if ($normalized === '') {
            $normalized = '未知错误。';
        }

        $normalized = (string)(\preg_replace('/^(?:AI plan generation failed:|第一阶段方案生成失败：)\s*/iu', '', $normalized) ?? $normalized);

        return '第一阶段方案生成失败：' . $normalized;
    }

    private function mapAiPlanToArtifacts(
        array $scope,
        array $websiteProfile,
        array $decoded,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $targetScope,
        ?array $validationPageTypes = null,
        bool $skipLocalRepair = false,
        ?callable $onProgress = null,
        array $stageOneGenerationAttempts = []
    ): array {
        $decoded = $this->normalizeAiPlanResponseShape($decoded);
        $baseline = $this->buildPlanArtifacts($scope, $websiteProfile, [
            'instruction' => $instruction,
            'target_scope' => $targetScope,
        ]);
        $planJson = \is_array($decoded['plan_json'] ?? null) ? $decoded['plan_json'] : [];
        if ($planJson === []) {
            throw new \RuntimeException(
                'missing plan_json payload. received top-level keys: ' . $this->describeAiPlanResponseKeys($decoded)
            );
        }

        $this->emitStageOnePipelineProgress(
            $onProgress,
            '正在整理阶段一原始方案与基线蓝图',
            89,
            'plan_assemble',
            'normalize_input',
            ['page_total' => \count($pageTypes)]
        );
        $planJson['page_types'] = $pageTypes;
        $validationPageTypes = \array_values(\array_filter(
            $validationPageTypes ?? $pageTypes,
            static fn($pageType): bool => \is_string($pageType) && $pageType !== ''
        ));
        $planJson['i18n'] = $this->ensurePlanI18nSection(
            \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [],
            $planLocale,
            $this->isEnglishLocale($planLocale)
        );
        $briefDescription = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $localRegenReport = [];
        if (!$skipLocalRepair) {
            $this->emitStageOnePipelineProgress(
                $onProgress,
                '正在检查阶段一方案是否需要断点局部修复',
                89,
                'plan_assemble',
                'local_repair_scan',
                ['page_total' => \count($validationPageTypes)]
            );
            [$planJson, $localRegenReport] = $this->repairAiStageOneProblemBlocksByAi(
                $scope,
                $websiteProfile,
                $planJson,
                $validationPageTypes,
                $planLocale,
                $contentLocale,
                $instruction,
                $targetScope,
                $briefDescription,
                $onProgress
            );
        }
        if ($localRegenReport !== []) {
            $planJson['_stage1_local_regen_report'] = $localRegenReport;
            $stageOneGenerationAttempts['local_repair_rounds'] = \count(\is_array($localRegenReport['rounds'] ?? null) ? $localRegenReport['rounds'] : []);
        }
        $this->emitStageOnePipelineProgress(
            $onProgress,
            '阶段一页面内容已校验，正在生成共享区块与队列索引',
            90,
            'plan_assemble',
            'build_shared_index',
            ['page_total' => \count($validationPageTypes)]
        );
        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);
        if ($planBlocks === []) {
            $planBlocks = $this->buildPlanBlocksFromPlanJson($planJson, $planLocale);
        }
        $planJson['plan_blocks'] = $planBlocks;
        $planJson = $this->repairAiStageOnePlanJsonBeforeValidation(
            $planJson,
            $validationPageTypes,
            $planLocale,
            $briefDescription,
            $scope,
            $contentLocale
        );

        $this->emitStageOnePipelineProgress(
            $onProgress,
            '正在执行阶段一强契约校验',
            90,
            'plan_assemble',
            'validate_contract',
            ['page_total' => \count($validationPageTypes)]
        );
        $stageOneValidationReport = $this->buildStageOneValidationReport(
            $planJson,
            $validationPageTypes,
            $briefDescription,
            $planLocale,
            $scope,
            $stageOneGenerationAttempts
        );
        $validationPassed = !empty($stageOneValidationReport['passed']);
        $partialRetryMode = $skipLocalRepair;
        $validationRetryableFailures = [];
        if (!$validationPassed) {
            $validationRetryableFailures = $this->buildStageOneRetryablePageFailuresFromValidationReport($stageOneValidationReport);
            $deferToPartialRetry = $validationRetryableFailures !== []
                || (
                    $partialRetryMode
                    && (
                        $validationPageTypes === []
                        || $this->stageOneValidationFailuresAreCoveredByPartialRetry($stageOneValidationReport, $pageTypes, $validationPageTypes)
                    )
                );
            if (!$deferToPartialRetry) {
                throw new \RuntimeException('AI stage-1 plan invalid: ' . $this->summarizeStageOneValidationReport($stageOneValidationReport));
            }
        }
        $planJson['stage1_validation_report'] = $stageOneValidationReport;
        $planJson['stage1_first_pass'] = $validationPassed && !empty($stageOneValidationReport['first_pass']) ? 1 : 0;
        $planJson['stage1_generation_attempts'] = $stageOneGenerationAttempts;
        if ($validationPassed) {
            $this->assertAiStageOnePlanJsonIsStrict($planJson, $validationPageTypes, $briefDescription, $planLocale, $scope);
        }

        $executionBlueprint = \is_array($baseline['execution_blueprint'] ?? null) ? $baseline['execution_blueprint'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $planJson = $this->syncStageOneOverviewFieldsFromRequirementExpansion($planJson);
        $structured = \array_replace(
            \is_array($baseline['structured'] ?? null) ? $baseline['structured'] : [],
            [
                'overview_expanded_brief' => (string)($planJson['overview_expanded_brief'] ?? ''),
                'overview_business_goals' => \is_array($planJson['overview_business_goals'] ?? null) ? $planJson['overview_business_goals'] : [],
                'overview_content_focus' => (string)($planJson['overview_content_focus'] ?? ''),
                'overview_domain_strategy' => (string)($planJson['overview_domain_strategy'] ?? ''),
                'requirement_expansion' => $requirementExpansion,
                'site_strategy' => \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [],
                'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
                'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
                'page_type_overviews' => \is_array($planJson['page_type_overviews'] ?? null) ? $planJson['page_type_overviews'] : [],
                'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
                'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
                'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
                'page_types' => $pageTypes,
                'pages' => \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
                'plan_blocks' => $planBlocks,
            ]
        );
        $structured['content_locale'] = $this->resolveStageOneContentLocale($scope, $planLocale);
        $structured['site_strategy'] = \array_replace(
            \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
            ['content_locale' => $structured['content_locale']]
        );

        $themeDesign = \is_array($planJson['theme_design'] ?? null)
            ? $this->extractStageOneThemeDesign($planJson['theme_design'])
            : $this->extractStageOneThemeDesign(\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $themeDesign = $this->applyReferenceImageInsightsToThemeDesign($themeDesign, $scope);
        $themeContextSnapshot = $this->mergeStageOneThemeDesignIntoSnapshot(
            \is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : [],
            $themeDesign
        );
        if (\is_array($planJson['page_type_overviews'] ?? null) && $planJson['page_type_overviews'] !== []) {
            $themeContextSnapshot['page_type_overviews'] = $planJson['page_type_overviews'];
        }
        $themeDesignQueueJob = $this->buildStageOneThemeDesignQueueJob($scope, $websiteProfile, $themeContextSnapshot, $planLocale);
        $stageOneQueueJobs = $this->upsertStageOneQueueJob(
            \is_array($executionBlueprint['queue_jobs'] ?? null)
                ? $executionBlueprint['queue_jobs']
                : (\is_array($structured['queue_jobs'] ?? null) ? $structured['queue_jobs'] : []),
            $themeDesignQueueJob
        );
        $sharedComponentSource = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        if ($sharedComponentSource === [] && \is_array($planJson['shared_plan'] ?? null)) {
            if (\is_array($planJson['shared_plan']['header_block'] ?? null)) {
                $sharedComponentSource['header'] = $planJson['shared_plan']['header_block'];
            }
            if (\is_array($planJson['shared_plan']['footer_block'] ?? null)) {
                $sharedComponentSource['footer'] = $planJson['shared_plan']['footer_block'];
            }
        }
        if ($sharedComponentSource === []) {
            $sharedComponentSource = \is_array($executionBlueprint['shared_components'] ?? null) ? $executionBlueprint['shared_components'] : [];
        }
        $sharedComponents = $this->normalizeStageOneSharedComponents($sharedComponentSource);
        $sharedPromptContext = $this->buildStageOneSharedPromptContext($themeContextSnapshot, $sharedComponents, $pageTypes, $planLocale, $requirementExpansion);
        $themeDesignQueueJob = $this->buildStageOneThemeDesignQueueJob($scope, $websiteProfile, $themeContextSnapshot, $planLocale);
        $stageOneQueueJobs = $this->upsertStageOneQueueJob(
            \is_array($executionBlueprint['queue_jobs'] ?? null) ? $executionBlueprint['queue_jobs'] : [],
            $themeDesignQueueJob
        );
        $structured['theme_context_snapshot'] = $themeContextSnapshot;
        $structured['shared_components'] = $sharedComponents;
        $structured['stage1_contract'] = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [];
        $structured['stage1_validation_report'] = \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [];
        $structured['stage1_first_pass'] = (int)($planJson['stage1_first_pass'] ?? 0);
        $structured['stage1_generation_attempts'] = \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [];
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'theme_design' => $themeContextSnapshot,
                'theme_design_job' => $themeDesignQueueJob,
                'requirement_expansion' => $requirementExpansion,
                'shared_prompt_context' => $sharedPromptContext,
            ]
        );
        $structured['queue_jobs'] = $stageOneQueueJobs;
        $executionBlueprint['requirement_expansion'] = $requirementExpansion;
        $planJson['theme_design'] = $themeDesign;
        $planJson['requirement_expansion'] = $requirementExpansion;
        $planJson['shared_components'] = $sharedComponents;
        $this->assertStageOneRequirementExpansionIsGenerated($planJson);
        $this->assertStageOneThemePlanIsGenerated($planJson);
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : $themeDesign;
        $executionBlueprint['theme_context_snapshot'] = $themeContextSnapshot;
        $executionBlueprint['shared_prompt_context'] = $sharedPromptContext;
        $executionBlueprint['shared_components'] = $sharedComponents;
        $executionBlueprint['content_locale'] = (string)($structured['content_locale'] ?? '');
        $stageOneQueue = \is_array($structured['stage1_queue'] ?? null)
            ? $structured['stage1_queue']
            : (\is_array($executionBlueprint['stage1_queue'] ?? null) ? $executionBlueprint['stage1_queue'] : [
                'version' => 1,
                'stage' => 'stage1',
                'status' => 'done',
                'sequence' => [],
                'jobs' => [],
            ]);
        $structured['stage1_queue'] = $stageOneQueue;
        $executionBlueprint['stage1_queue'] = $stageOneQueue;
        $pagePlans = $this->buildStageOnePagePlansConcurrently(
            \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
            $sharedPromptContext
        );
        $stageOneQueue = $this->buildStageOnePageFanoutQueueEnvelope($stageOneQueue, $pagePlans, $sharedPromptContext, $planLocale);
        $structured['stage1_queue'] = $stageOneQueue;
        $executionBlueprint['stage1_queue'] = $stageOneQueue;
        $this->emitStageOnePipelineProgress(
            $onProgress,
            '正在生成页面任务、区块索引与断点队列',
            91,
            'plan_assemble',
            'build_queue_envelope',
            ['page_total' => \count($validationPageTypes)]
        );
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan) || !\is_array($planJson['pages'][$pageType] ?? null)) {
                continue;
            }
            if (\trim((string)($planJson['pages'][$pageType]['theme_alignment_summary'] ?? '')) === '') {
                $planJson['pages'][$pageType]['theme_alignment_summary'] = (string)($pagePlan['theme_alignment_summary'] ?? '');
            }
            if (!\is_array($planJson['pages'][$pageType]['page_design_plan'] ?? null)) {
                $planJson['pages'][$pageType]['page_design_plan'] = \is_array($pagePlan['page_design_plan'] ?? null) ? $pagePlan['page_design_plan'] : [];
            }
        }
        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $sharedBlockRows = $this->buildStageOneSharedBlocksPlanJson($sharedComponents);
        $planJson['shared_blocks'] = $sharedBlockRows;
        foreach (\is_array($planJson['pages'] ?? null) ? \array_keys($planJson['pages']) : [] as $pageType) {
            if (!\is_array($planJson['pages'][$pageType] ?? null)) {
                continue;
            }
            $planJson['pages'][$pageType]['display_blocks'] = $this->buildStageOnePageDisplayBlocks(
                $sharedBlockRows,
                \array_values(\is_array($planJson['pages'][$pageType]['blocks'] ?? null) ? $planJson['pages'][$pageType]['blocks'] : [])
            );
        }

        $tasks = [];
        foreach (['header', 'footer'] as $sharedKey) {
            if (\is_array($sharedComponents[$sharedKey] ?? null)) {
                $tasks[] = $sharedComponents[$sharedKey];
            }
        }
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $tasks[] = $this->buildPageTask((string)$pageType, $pagePlan, $block);
            }
        }

        $structured['page_plans'] = $pagePlans;
        $structured['block_index'] = $blockIndex;
        $structured['execution_steps'] = $this->buildExecutionSteps($tasks);

        $executionBlueprint['pages'] = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $executionBlueprint['page_plans'] = $pagePlans;
        $executionBlueprint['block_index'] = $blockIndex;
        $executionBlueprint['tasks'] = $tasks;
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $this->emitStageOnePipelineProgress(
            $onProgress,
            '正在生成工作台预览数据与方案文档',
            91,
            'plan_assemble',
            'build_workbench',
            ['page_total' => \count($validationPageTypes)]
        );
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);
        $derivedScopePatch = $this->buildDerivedScopePatch($scope, $websiteProfile, $structured, $executionBlueprint);
        $derivedScopePatch['stage1_contract'] = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [];
        $derivedScopePatch['stage1_validation_report'] = \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [];
        $derivedScopePatch['stage1_first_pass'] = (int)($planJson['stage1_first_pass'] ?? 0);
        $derivedScopePatch['stage1_generation_attempts'] = \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [];

        return \array_replace($baseline, [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'derived_scope_patch' => $derivedScopePatch,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
            'retryable_ai_failures' => \array_values($validationRetryableFailures),
            'partial_retry_required' => $validationRetryableFailures !== [] ? 1 : 0,
        ]);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     */
    private function buildStageOneValidationReport(
        array $planJson,
        array $pageTypes,
        string $briefDescription,
        string $planLocale,
        array $scope,
        array $stageOneGenerationAttempts = []
    ): array {
        $contentLocale = $this->resolveStageOneContentLocale($scope, $planLocale);
        $validationTargets = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !\in_array($pageType, $validationTargets, true)) {
                $validationTargets[] = $pageType;
            }
        }
        // Partial assemble passes only pages that cleared fanout; an empty list must not
        // fall back to every selected page_type or missing_page will block the whole plan.
        $contractPageTypes = $validationTargets !== []
            ? $this->resolveStageOneStableContractPageTypes($planJson, $scope, $validationTargets)
            : [];
        $contract = $this->getStageOneContractService()->normalize(
            \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []),
            $scope,
            $contractPageTypes,
            $planLocale,
            $contentLocale,
            'validation'
        );
        $planJson['stage1_contract'] = \array_replace(\is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [], [
            'contract_version' => (string)($contract['contract_version'] ?? ''),
            'contract_hash' => (string)($contract['contract_hash'] ?? ''),
            'page_contracts' => \is_array($contract['page_contracts'] ?? null) ? $contract['page_contracts'] : [],
            'copy_rules' => \is_array($contract['copy_rules'] ?? null) ? $contract['copy_rules'] : [],
            'image_planning_rules' => \is_array($contract['image_planning_rules'] ?? null) ? $contract['image_planning_rules'] : [],
            'visual_quality_rules' => \is_array($contract['visual_quality_rules'] ?? null) ? $contract['visual_quality_rules'] : [],
        ]);

        $localRepairRounds = \max(0, (int)($stageOneGenerationAttempts['local_repair_rounds'] ?? 0));
        $recoveryCount = \max(0, (int)($stageOneGenerationAttempts['recovery_count'] ?? 0));
        $retryableFailures = $this->extractPlanRetryableFailureItems($scope);
        $context = [
            'brief_description' => $briefDescription,
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'generation_attempts' => $stageOneGenerationAttempts,
            'recovery_count' => $recoveryCount,
            'retryable_failure_count' => \count($retryableFailures),
            'local_repair_rounds' => $localRepairRounds,
            'retry_from_previous_failure' => !empty($stageOneGenerationAttempts['retry_from_previous_failure']),
            'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
            'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
        ];

        return $this->getStageOneContractValidator()->validateFullPlan($planJson, $contract, $context);
    }

    /**
     * @param array<string, mixed> $report
     * @param list<string> $pageTypes
     * @param list<string> $validationPageTypes
     */
    private function stageOneValidationFailuresAreCoveredByPartialRetry(
        array $report,
        array $pageTypes,
        array $validationPageTypes
    ): bool {
        $issues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
        if ($issues === []) {
            return false;
        }
        $failedPageTypes = \array_fill_keys(\array_values(\array_diff($pageTypes, $validationPageTypes)), true);
        if ($failedPageTypes === []) {
            return false;
        }
        foreach ($issues as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $pageType = \trim((string)($issue['page_type'] ?? ($issue['extra']['page_type'] ?? '')));
            if ($pageType === '') {
                $path = (string)($issue['path'] ?? $issue['field_path'] ?? '');
                if (\preg_match('#^pages\.([a-z0-9_]+)#i', $path, $matches) === 1) {
                    $pageType = (string)$matches[1];
                }
            }
            if ($pageType === '' || !isset($failedPageTypes[$pageType])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOneRetryablePageFailuresFromValidationReport(array $report): array
    {
        $issues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
        if ($issues === []) {
            return [];
        }

        $issuesByPage = [];
        foreach ($issues as $issue) {
            if (!\is_array($issue)) {
                return [];
            }
            $pageType = $this->extractStageOneValidationIssuePageType($issue);
            if ($pageType === '') {
                return [];
            }
            $issuesByPage[$pageType][] = $issue;
        }

        $failures = [];
        foreach ($issuesByPage as $pageType => $pageIssues) {
            $pageReport = ['issues' => $pageIssues];
            $failures[$pageType] = $this->buildStageOneRetryablePageFailure(
                $pageType,
                'Stage-one final contract failed for page: ' . $pageType,
                [
                    'validation_summary' => $this->summarizeStageOneValidationReport($pageReport),
                    'validation_issues' => $pageIssues,
                    'failure_source' => 'gate_checkpoint',
                ]
            );
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function extractStageOneValidationIssuePageType(array $issue): string
    {
        $pageType = \trim((string)($issue['page_type'] ?? ($issue['extra']['page_type'] ?? '')));
        if ($pageType !== '') {
            return $pageType;
        }

        $path = (string)($issue['path'] ?? $issue['field_path'] ?? '');
        if (\preg_match('#^pages\.([a-z0-9_]+)#i', $path, $matches) === 1) {
            return (string)$matches[1];
        }

        return '';
    }

    /**
     * Route contracts are selected-page contracts, not generated-artifact contracts.
     * If page fanout drops a page, validation must report missing_page while keeping
     * the original slug/link contract intact.
     *
     * @param list<string> $fallbackPageTypes
     * @return list<string>
     */
    private function resolveStageOneStableContractPageTypes(array $planJson, array $scope, array $fallbackPageTypes): array
    {
        foreach ([
            \is_array($planJson['stage1_contract']['page_types'] ?? null) ? $planJson['stage1_contract']['page_types'] : [],
            \is_array($scope['stage1_contract']['page_types'] ?? null) ? $scope['stage1_contract']['page_types'] : [],
            \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [],
            $fallbackPageTypes,
        ] as $source) {
            $normalized = [];
            foreach ($source as $pageType) {
                $pageType = \trim((string)$pageType);
                if ($pageType !== '' && !\in_array($pageType, $normalized, true)) {
                    $normalized[] = $pageType;
                }
            }
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function summarizeStageOneValidationReport(array $report): string
    {
        $issues = \is_array($report['issues'] ?? null) ? $report['issues'] : [];
        if ($issues === []) {
            return 'unknown contract failure';
        }
        $parts = [];
        foreach (\array_slice($issues, 0, 8) as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $code = \trim((string)($issue['code'] ?? $issue['reason_code'] ?? 'invalid'));
            $path = \trim((string)($issue['path'] ?? $issue['field_path'] ?? 'stage1'));
            $parts[] = ($path !== '' ? $path : 'stage1') . '=' . ($code !== '' ? $code : 'invalid');
        }

        return $parts !== [] ? \implode('; ', $parts) : 'unknown contract failure';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function extractPlanRetryableFailureItems(array $scope): array
    {
        return \is_array($scope[AiSiteBuildTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items'] ?? null)
            ? $scope[AiSiteBuildTaskService::RETRYABLE_AI_FAILURES_SCOPE_KEY]['plan']['items']
            : [];
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     */
    private function assertAiStageOnePlanJsonIsStrict(array $planJson, array $pageTypes, string $briefDescription, string $planLocale, array $scope = []): void
    {
        $pageTypes = $this->resolveStageOneStableContractPageTypes($planJson, $scope, $pageTypes);
        foreach (['site_strategy', 'theme_style', 'palette', 'theme_design', 'navigation_plan', 'footer_plan', 'seo_strategy', 'pages'] as $sectionKey) {
            if (!\is_array($planJson[$sectionKey] ?? null)) {
                throw new \RuntimeException('AI stage-1 plan invalid: missing section "' . $sectionKey . '".');
            }
        }
        $this->assertAiStageOneThemeDesignSchema($planJson['theme_design']);

        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        if ($themeDesign !== []) {
            $this->assertAiStageOneThemeDesignColorScheme($themeDesign);
        }

        $this->assertAiStageOneLinkList(
            \is_array($planJson['navigation_plan']['header_items'] ?? null) ? $planJson['navigation_plan']['header_items'] : [],
            'navigation_plan.header_items'
        );
        $this->assertAiStageOneLinkList(
            \is_array($planJson['footer_plan']['featured'] ?? null) ? $planJson['footer_plan']['featured'] : [],
            'footer_plan.featured'
        );
        $this->assertAiStageOneLinkList(
            \is_array($planJson['footer_plan']['policies'] ?? null) ? $planJson['footer_plan']['policies'] : [],
            'footer_plan.policies'
        );

        $briefSignals = $this->extractStageOneBriefSignalTokens($briefDescription);
        $themeDesign = \is_array($planJson['theme_design'] ?? null)
            ? $planJson['theme_design']
            : (\is_array($planJson['shared_plan']['theme_design'] ?? null) ? $planJson['shared_plan']['theme_design'] : []);
        if ($themeDesign !== []) {
            $this->assertAiStageOneThemeSelectionReason($themeDesign, $briefDescription);
        }

        foreach ($pageTypes as $pageType) {
            $page = \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : null;
            if ($page === null) {
                throw new \RuntimeException('AI stage-1 plan invalid: missing page "' . $pageType . '".');
            }

            $pageGoal = \trim((string)($page['page_goal'] ?? ''));
            if ($this->isWeakStageOnePageGoal($pageGoal, $pageType)) {
                throw new \RuntimeException('AI stage-1 plan invalid: page_goal for "' . $pageType . '" is empty or still instruction-like.');
            }

            $themeAlignmentSummary = \trim((string)($page['theme_alignment_summary'] ?? ''));
            if ($themeAlignmentSummary === '' || $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', $pageType)) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_alignment_summary for "' . $pageType . '" is empty or still instruction-like.');
            }

            if (!\is_array($page['page_design_plan'] ?? null) || ($page['page_design_plan'] ?? []) === []) {
                throw new \RuntimeException('AI stage-1 plan invalid: page_design_plan for "' . $pageType . '" is missing.');
            }

            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($blocks === []) {
                throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" has no blocks.');
            }
            $contract = $this->getStageOneContractService()->normalize(
                \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []),
                $scope,
                $pageTypes,
                $planLocale,
                $this->resolveStageOneContentLocale($scope, $planLocale),
                'strict_assert'
            );
            $pageContract = $this->getStageOneContractService()->pageContract($contract, $pageType);
            $blockBudget = [
                'min' => (int)($pageContract['min_blocks'] ?? 0),
                'max' => (int)($pageContract['max_blocks'] ?? 0),
                'required' => \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [],
            ];
            $forbiddenBlockKeys = \array_fill_keys(\array_map('strval', \is_array($pageContract['forbidden_block_keys'] ?? null) ? $pageContract['forbidden_block_keys'] : AiSiteStageOneContractService::GENERIC_BLOCK_KEYS), true);
            $fieldPlanCount = \max(1, (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT));
            if (\count($blocks) < $blockBudget['min'] || \count($blocks) > $blockBudget['max']) {
                throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" block count must be between ' . $blockBudget['min'] . ' and ' . $blockBudget['max'] . '.');
            }

            $seenBlockKeys = [];
            $requiredBlockKeys = \array_fill_keys($blockBudget['required'], false);
            foreach ($blocks as $index => $block) {
                if (!\is_array($block)) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block #' . $index . ' on "' . $pageType . '" is malformed.');
                }
                $blockKey = \trim((string)($block['block_key'] ?? ''));
                if ($blockKey === '') {
                    throw new \RuntimeException('AI stage-1 plan invalid: block #' . $index . ' on "' . $pageType . '" is missing block_key.');
                }
                $normalizedBlockKey = \mb_strtolower($blockKey);
                if (isset($seenBlockKeys[$normalizedBlockKey])) {
                    throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" contains duplicate block_key "' . $blockKey . '".');
                }
                $seenBlockKeys[$normalizedBlockKey] = true;
                if (isset($forbiddenBlockKeys[$normalizedBlockKey])) {
                    throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" contains generic block_key "' . $blockKey . '".');
                }
                if (\array_key_exists($blockKey, $requiredBlockKeys)) {
                    $requiredBlockKeys[$blockKey] = true;
                }

                $content = \trim((string)($block['content'] ?? ''));
                if ($content === '' || $this->isPromptLikeStageOneText($content, 'content', (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" on "' . $pageType . '" still contains instruction-like content.');
                }

                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                if (\count($fieldPlan) !== $fieldPlanCount) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" on "' . $pageType . '" must contain exactly ' . $fieldPlanCount . ' field_plan rows.');
                }
                foreach ($fieldPlan as $fieldIndex => $row) {
                    if (!\is_array($row)) {
                        throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" field_plan row #' . $fieldIndex . ' is malformed.');
                    }
                    $field = \trim((string)($row['field'] ?? ''));
                    $sample = \trim((string)($row['sample'] ?? ''));
                    $implementationNote = $this->resolveStageOneFieldImplementationNote($row);
                    if ($field === '' || $sample === '') {
                        throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" has empty field/sample rows.');
                    }
                    if ($this->isPromptLikeStageOneText($sample, $field, (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                        throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" field "' . $field . '" is still instruction-like.');
                    }
                    if ($implementationNote === '' || $this->isPromptLikeStageOneText($implementationNote, $field, (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                        throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" field "' . $field . '" is missing a concrete implementation_note.');
                    }
                }

                $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
                $coreCopy = \trim((string)($executionScript['core_copy'] ?? ''));
                if ($coreCopy === '' || $this->isPromptLikeStageOneText($coreCopy, 'core_copy', (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" execution_script.core_copy is empty or instruction-like.');
                }
                foreach (\is_array($executionScript['feature_points'] ?? null) ? $executionScript['feature_points'] : [] as $point) {
                    $text = \is_scalar($point) ? \trim((string)$point) : '';
                    if ($text !== '' && $this->isPromptLikeStageOneText($text, 'feature_points', (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                        throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" feature_points still contain instruction-like text.');
                    }
                }
            }
            foreach ($requiredBlockKeys as $requiredBlockKey => $seen) {
                if (!$seen) {
                    throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" is missing required block_key "' . $requiredBlockKey . '".');
                }
            }

            if ($pageType === Page::TYPE_HOME && $briefSignals !== []) {
                $heroBlock = $this->findStageOneHeroBlock($blocks);
                if ($heroBlock !== null) {
                    if (!$this->stageOneBlockContainsBriefSignal($heroBlock, $briefSignals)) {
                        throw new \RuntimeException('AI stage-1 plan invalid: homepage hero does not reuse concrete nouns from the brief.');
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $themeDesign
     */
    private function assertAiStageOneThemeDesignColorScheme(array $themeDesign): void
    {
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        // 历史阶段一产物可能仅输出 body（正文色）或仅输出 text，校验前先互补，避免因字段别名差异误判失败。
        $textColor = \trim((string)($colorScheme['text'] ?? ''));
        $bodyColor = \trim((string)($colorScheme['body'] ?? ''));
        if ($textColor === '' && $bodyColor !== '') {
            $colorScheme['text'] = $bodyColor;
            $textColor = $bodyColor;
        }
        if ($bodyColor === '' && $textColor !== '') {
            $colorScheme['body'] = $textColor;
        }
        foreach (['primary', 'secondary', 'accent', 'background', 'text', 'button'] as $colorKey) {
            $value = \trim((string)($colorScheme[$colorKey] ?? ''));
            if ($value === '') {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.color_scheme.' . $colorKey . ' must not be empty.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $links
     */
    private function assertAiStageOneLinkList(array $links, string $path): void
    {
        if ($links === []) {
            throw new \RuntimeException('AI stage-1 plan invalid: "' . $path . '" must not be empty.');
        }

        foreach ($links as $index => $link) {
            if (!\is_array($link)) {
                throw new \RuntimeException('AI stage-1 plan invalid: "' . $path . '" row #' . $index . ' is malformed.');
            }
            $label = \trim((string)($link['label'] ?? ''));
            $href = \trim((string)($link['href'] ?? ''));
            if ($label === '' || $href === '') {
                throw new \RuntimeException('AI stage-1 plan invalid: "' . $path . '" row #' . $index . ' is missing label or href.');
            }
        }
    }

    /**
     * @param array<string, mixed> $themeDesign
     */
    private function assertAiStageOneThemeDesignSchema(array $themeDesign): void
    {
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
            if (!\array_key_exists($field, $themeDesign)) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design missing "' . $field . '".');
            }
        }

        foreach (['color_scheme', 'typography_spacing_radius'] as $field) {
            if (!\is_array($themeDesign[$field])) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.' . $field . ' must be an object.');
            }
        }

        foreach (['visual_keywords', 'forbidden_styles'] as $field) {
            if (!\is_array($themeDesign[$field])) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.' . $field . ' must be an array.');
            }
        }

        foreach (['name', 'primary', 'secondary', 'accent', 'background', 'body', 'button'] as $field) {
            if (!\array_key_exists($field, $themeDesign['color_scheme'])) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.color_scheme missing "' . $field . '".');
            }
        }

        foreach (['font_family', 'heading_scale', 'body_scale', 'spacing_scale', 'radius_scale'] as $field) {
            if (!\array_key_exists($field, $themeDesign['typography_spacing_radius'])) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.typography_spacing_radius missing "' . $field . '".');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function findStageOneHeroBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($this->stageOneBlockHasExplicitHeroRole($block)) {
                return $block;
            }
        }

        return isset($blocks[0]) && \is_array($blocks[0]) ? $blocks[0] : null;
    }

    /**
     * @param list<array<string, mixed>|mixed> $blocks
     */
    private function findStageOneHeroBlockIndex(array $blocks): ?int
    {
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($this->stageOneBlockHasExplicitHeroRole($block)) {
                return (int)$index;
            }
        }

        foreach ($blocks as $index => $block) {
            if (\is_array($block)) {
                return (int)$index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stageOneBlockHasExplicitHeroRole(array $block): bool
    {
        foreach ([
            $block['block_type'] ?? null,
            $block['page_flow_role'] ?? null,
            $block['component_kind'] ?? null,
            $block['template'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate) && !(\is_object($candidate) && \method_exists($candidate, '__toString'))) {
                continue;
            }
            $token = $this->normalizeStageOneRoleToken((string)$candidate);
            if (\in_array($token, ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold', 'opening'], true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeStageOneRoleToken(string $value): string
    {
        $value = \mb_strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        $value = \str_replace([' ', '-', '/', '\\'], '_', $value);
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_');
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $briefSignals
     */
    private function stageOneBlockContainsBriefSignal(array $block, array $briefSignals): bool
    {
        return $this->stageOneTextContainsBriefSignal(
            (string)\json_encode($block, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
            $briefSignals
        );
    }

    /**
     * @param list<string> $briefSignals
     */
    private function stageOneTextContainsBriefSignal(string $text, array $briefSignals): bool
    {
        $text = \trim($text);
        if ($text === '' || $briefSignals === []) {
            return false;
        }

        foreach ($briefSignals as $signal) {
            $signal = \trim((string)$signal);
            if ($signal !== '' && \mb_stripos($text, $signal) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $themeDesign
     */
    private function assertAiStageOneThemeSelectionReason(array $themeDesign, string $briefDescription): void
    {
        $selectionReason = \trim((string)($themeDesign['selection_reason'] ?? ''));
        if ($selectionReason === '' || $this->isPromptLikeStageOneText($selectionReason, 'selection_reason')) {
            throw new \RuntimeException('AI stage-1 plan invalid: theme_design.selection_reason is empty or still instruction-like.');
        }

        if (!$this->stageOneSelectionReasonReferencesRequirement($selectionReason, $briefDescription)) {
            throw new \RuntimeException('AI stage-1 plan invalid: theme_design.selection_reason must reference the user one-line requirement.');
        }
    }

    private function stageOneSelectionReasonReferencesRequirement(string $selectionReason, string $briefDescription): bool
    {
        $selectionReason = \trim($selectionReason);
        if ($selectionReason === '') {
            return false;
        }

        $requirementSignals = $this->extractStageOneSelectionReasonRequirementTokens($briefDescription);
        if ($requirementSignals === []) {
            return !$this->isGenericThemeSelectionReason($selectionReason);
        }

        foreach ($requirementSignals as $signal) {
            if ($signal !== '' && \mb_stripos($selectionReason, $signal) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractStageOneBriefSignalTokens(string $briefDescription): array
    {
        $brief = \trim($briefDescription);
        if ($brief === '') {
            return [];
        }

        $signals = [];
        foreach ([
            '印度', '棋牌', '下载', 'APK', 'apk', 'teen patti', 'rummy',
            '预约', '咨询', '批发', '代理', '课程', '下载站',
            'download', 'booking', 'consulting', 'pricing', 'trial',
        ] as $candidate) {
            if ($candidate !== '' && \mb_stripos($brief, $candidate) !== false) {
                $signals[] = $candidate;
            }
        }

        return \array_values(\array_unique($signals));
    }

    /**
     * @return list<string>
     */
    private function extractStageOneSelectionReasonRequirementTokens(string $briefDescription): array
    {
        $brief = \trim($briefDescription);
        if ($brief === '') {
            return [];
        }

        $signals = $this->extractStageOneBriefSignalTokens($brief);
        if (\preg_match_all('/[a-z0-9][a-z0-9+#.\'-]{2,}/iu', $brief, $matches) > 0) {
            $stopWords = [
                'and' => true,
                'are' => true,
                'build' => true,
                'for' => true,
                'need' => true,
                'page' => true,
                'pages' => true,
                'site' => true,
                'the' => true,
                'this' => true,
                'with' => true,
                'website' => true,
            ];
            foreach ($matches[0] as $candidate) {
                $candidate = \mb_strtolower(\trim((string)$candidate));
                if ($candidate !== '' && !isset($stopWords[$candidate])) {
                    $signals[] = $candidate;
                }
            }
        }

        if (\preg_match_all('/[\p{Han}]{2,}/u', $brief, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                $candidate = \trim((string)$candidate);
                if ($candidate !== '') {
                    $signals[] = $candidate;
                }
            }
        }

        return \array_values(\array_unique($signals));
    }

    private function isGenericThemeSelectionReason(string $selectionReason): bool
    {
        $normalized = \mb_strtolower(\trim($selectionReason));
        if ($normalized === '') {
            return true;
        }

        $genericMarkers = [
            'modern',
            'premium',
            'high-end',
            'high end',
            'simple',
            'clean',
            'professional',
            'elegant',
            'minimal',
            'trustworthy',
            'contemporary',
        ];
        $withoutGeneric = $normalized;
        foreach ($genericMarkers as $marker) {
            if ($marker !== '') {
                $withoutGeneric = \str_replace($marker, ' ', $withoutGeneric);
            }
        }
        $withoutGeneric = (string)\preg_replace('/[\s,.;:\-]+/u', '', $withoutGeneric);

        return $withoutGeneric === '' || \mb_strlen($withoutGeneric) < 4;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $fallbackPlanJson
     * @return array<string, mixed>
     */
    private function enforcePlanJsonBaseline(array $planJson, array $fallbackPlanJson, array $pageTypes, bool $isEn): array
    {
        $normalized = $planJson;
        $normalized['page_types'] = $pageTypes;
        if (!\is_array($normalized['pages'] ?? null)) {
            $normalized['pages'] = [];
        }
        $fallbackPages = \is_array($fallbackPlanJson['pages'] ?? null) ? $fallbackPlanJson['pages'] : [];
        foreach ($pageTypes as $pageType) {
            if (!\is_array($normalized['pages'][$pageType] ?? null)) {
                $normalized['pages'][$pageType] = \is_array($fallbackPages[$pageType] ?? null) ? $fallbackPages[$pageType] : [];
                continue;
            }
            if (!\is_array($fallbackPages[$pageType] ?? null)) {
                continue;
            }
            // 非英文方案下，强制使用本地页面块文案，确保遵守计划语言与结构约束。
            if (!$isEn) {
                $normalized['pages'][$pageType] = $fallbackPages[$pageType];
            }
        }

        return $normalized;
    }

    private function isPromptLikeStageOneText(
        string $text,
        string $field = '',
        string $template = '',
        string $sectionName = '',
        string $pageLabel = ''
    ): bool {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return false;
        }
        if (\in_array($normalized, [
            'string',
            'sentence',
            'concrete page-specific sentence',
            'how this page obeys',
            'string explaining how this page obeys',
            'how this page and every block obey theme_design',
            'page-specific theme alignment sentence',
        ], true)) {
            return true;
        }

        foreach ([
            '围绕', '说明核心价值', '首页先讲清', '阶段一仅给方向', '蓝图方向', '标题围绕', '指定标题字体',
            '列出 2-4', '字体与排版', '风格语气', '背景方向', '素材建议', 'cta 保持单一动作',
            '待补充', '待撰写', '详见后文', '完善导航', '优化体验', '补充政策链接', '突出品牌价值',
            '先用一句话讲清', '再把用户带到', '让访客在第一屏', '承接核心关键词', '不能遮挡标题和主 cta',
            'block direction', 'section title', 'supporting subtitle text', 'direction only', 'blueprint direction',
            'list 2-4', 'specify heading font', 'describe the overall visual tone', 'use concise readable paragraphs',
            'first-screen promise', 'lead visitors to the next step',
            'string explaining how this page obeys', 'how this page and every block obey theme_design',
            'write the title around', 'explain the core value', 'do not describe what should be written',
        ] as $marker) {
            if ($marker !== '' && $normalized === \mb_strtolower($marker)) {
                return true;
            }
        }

        if (\preg_match('/^(?:write|rewrite|describe\s+(?:the|this)\s+(?:block|section|field|content|layout|purpose)|use this field|do not output)\b/iu', $normalized) === 1) {
            return true;
        }

        return $this->looksLikeBlueprintInstruction($text, $field, $template, $sectionName, $pageLabel);
    }

    private function isStageOneContractPromptLikeText(string $text): bool
    {
        $text = \trim($text);
        if ($text === '') {
            return true;
        }
        if (\mb_strlen($text) <= 2) {
            return true;
        }
        if (\in_array(\mb_strtolower($text), ['string', 'sentence', 'text', 'copy', 'placeholder', 'todo', 'n/a'], true)) {
            return true;
        }

        if (\preg_match('/^(?:how this page obeys|explaining how|schema|placeholder|prompt|instruction|return only|final visitor copy|website content locale)$/iu', $text) === 1) {
            return true;
        }

        return \preg_match('/^(?:write|rewrite|describe\s+(?:the|this)\s+(?:block|section|field|content|layout|purpose)|use this field|do not output)\b/iu', $text) === 1;
    }

    private function isWeakStageOnePageGoal(string $text, string $pageType = ''): bool
    {
        $normalized = \mb_strtolower(\trim($text, " \t\n\r\0\x0B.,;:!?。，；：！？"));
        if ($normalized === '') {
            return true;
        }

        if ($this->isPromptLikeStageOneText($text, 'page_goal', '', '', $pageType)) {
            return true;
        }

        foreach ([
            'deliver clear and actionable page content for visitors',
            'present the refund_policy content clearly',
            'present the privacy_policy content clearly',
            'present the terms_of_service content clearly',
            'present the shipping_policy content clearly',
            'present the cookie_policy content clearly',
            'should clearly serve page intent and lead users to next actions',
            '为访客提供清晰且可执行的页面内容',
            '页面需要围绕页面意图输出清晰信息并承接下一步动作',
            '页面围绕页面意图输出清晰信息并承接下一步动作',
        ] as $marker) {
            if ($normalized === \mb_strtolower($marker)) {
                return true;
            }
        }

        return false;
    }

    private function shouldRepairStageOnePageGoal(string $pageGoal, string $originalPageGoal, string $pageType): bool
    {
        $pageGoal = \trim($pageGoal);
        $originalPageGoal = \trim($originalPageGoal);
        if ($pageGoal === '' || $originalPageGoal === '') {
            return true;
        }

        foreach ([$pageGoal, $originalPageGoal] as $candidate) {
            $normalizedCandidate = \trim($candidate, " \t\n\r\0\x0B.,;:!?。，；：！？");
            if (
                $this->isWeakStageOnePageGoal($normalizedCandidate, $pageType)
                || $this->isPromptLikeStageOneText($candidate, 'page_goal', '', '', $pageType)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function repairAiStageOnePlanJsonBeforeValidation(
        array $planJson,
        array $pageTypes,
        string $planLocale,
        string $briefDescription,
        array $scope = [],
        string $contentLocale = ''
    ): array {
        $contentLocale = $contentLocale !== ''
            ? $contentLocale
            : $this->resolveStageOneContentLocale($scope, $planLocale);
        $normalized = $planJson;
        $normalized = $this->repairStageOneThemeDesignBeforeValidation($normalized, $scope, $pageTypes);
        $normalized = $this->ensureStageOneNavigationAndFooterPlans($normalized, $pageTypes, $contentLocale, $scope);
        if (!\is_array($normalized['pages'] ?? null)) {
            $normalized['pages'] = [];
        }

        if (\is_array($normalized['theme_design'] ?? null)) {
            $normalized['theme_design'] = $this->repairAiStageOneThemeSelectionReasonBeforeValidation(
                $normalized['theme_design'],
                $briefDescription,
                $planLocale
            );
        }

        foreach ($pageTypes as $pageType) {
            $originalPage = \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [];
            if (!\is_array($normalized['pages'][$pageType] ?? null)) {
                continue;
            }

            $page = $normalized['pages'][$pageType];
            $pageGoal = \trim((string)($page['page_goal'] ?? ''));
            $originalPageGoal = \trim((string)($originalPage['page_goal'] ?? ''));
            if ($this->shouldRepairStageOnePageGoal($pageGoal, $originalPageGoal, (string)$pageType)) {
                $page['page_goal'] = $this->buildStageOneConcretePageGoal(
                    (string)$pageType,
                    (string)($page['page_label'] ?? $page['page_title'] ?? ''),
                    $planLocale
                );
            }
            $themeAlignmentSummary = \trim((string)($page['theme_alignment_summary'] ?? ''));
            $originalThemeAlignmentSummary = \trim((string)($originalPage['theme_alignment_summary'] ?? ''));
            if (
                $themeAlignmentSummary === ''
                || $originalThemeAlignmentSummary === ''
                || $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', (string)$pageType)
                || $this->isPromptLikeStageOneText($originalThemeAlignmentSummary, 'theme_alignment_summary', '', '', (string)$pageType)
            ) {
                $page['theme_alignment_summary'] = $this->buildStageOneThemeAlignmentSummaryFromPlanJson(
                    (string)$pageType,
                    $page,
                    $normalized,
                    $planLocale
                );
            }
            $page['blocks'] = $this->repairAiStageOneBlocksBeforeValidation(
                \is_array($page['blocks'] ?? null) ? $page['blocks'] : [],
                (string)$pageType,
                $planLocale,
                [
                    'theme_design' => \is_array($normalized['theme_design'] ?? null)
                        ? $normalized['theme_design']
                        : (\is_array($scope['theme_design'] ?? null) ? $scope['theme_design'] : []),
                ],
                \is_array($page['page_design_plan'] ?? null) ? $page['page_design_plan'] : []
            );
            if ((string)$pageType === Page::TYPE_HOME) {
                $page = $this->repairStageOneHomeHeroBriefSignals(
                    $page,
                    $this->extractStageOneBriefSignalTokens($briefDescription),
                    $planLocale
                );
            }

            $normalized['pages'][$pageType] = $page;
        }

        return $this->repairAiStageOneFieldImplementationNotesBeforeValidation($normalized, $pageTypes, $planLocale);
    }

    /**
     * @param array<string, mixed> $page
     * @param list<string> $briefSignals
     * @return array<string, mixed>
     */
    private function repairStageOneHomeHeroBriefSignals(array $page, array $briefSignals, string $planLocale): array
    {
        if ($briefSignals === [] || !\is_array($page['blocks'] ?? null)) {
            return $page;
        }

        $blocks = $page['blocks'];
        $heroIndex = $this->findStageOneHeroBlockIndex($blocks);
        if ($heroIndex === null || !\is_array($blocks[$heroIndex] ?? null)) {
            return $page;
        }

        $heroBlock = $blocks[$heroIndex];
        if ($this->stageOneBlockContainsBriefSignal($heroBlock, $briefSignals)) {
            return $page;
        }

        $signalPhrase = $this->buildStageOneBriefSignalPhrase($briefSignals, $planLocale);
        if ($signalPhrase === '') {
            return $page;
        }

        $blocks[$heroIndex] = $this->injectStageOneBriefSignalsIntoHeroBlock($heroBlock, $signalPhrase, $planLocale);
        $page['blocks'] = \array_values($blocks);

        return $page;
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function repairAiStageOneFieldImplementationNotesBeforeValidation(array $planJson, array $pageTypes, string $planLocale): array
    {
        foreach ($pageTypes as $pageType) {
            if (!\is_array($planJson['pages'][$pageType]['blocks'] ?? null)) {
                continue;
            }
            foreach ($planJson['pages'][$pageType]['blocks'] as $blockIndex => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
                $template = \trim((string)($block['component_kind'] ?? $block['template'] ?? ''));
                $sectionName = \trim((string)($block['section_code'] ?? $blockKey));
                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                foreach ($fieldPlan as $fieldIndex => $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $field = \trim((string)($row['field'] ?? ''));
                    $sample = \trim((string)($row['sample'] ?? ''));
                    if ($field === '' || $sample === '') {
                        continue;
                    }
                    $implementationNote = $this->resolveStageOneFieldImplementationNote($row);
                if (
                    $implementationNote !== ''
                    && !$this->isStageOneContractPromptLikeText($implementationNote)
                    && !$this->isPromptLikeStageOneText($implementationNote, $field, $template, $sectionName, (string)$pageType)
                ) {
                    continue;
                }
                    $fieldPlan[$fieldIndex] = $this->syncStageOneFieldImplementationNote(
                        $row,
                        $this->buildStageOneFieldImplementationNoteFromSample($field, $sample, $blockKey, (string)$pageType, $planLocale)
                    );
                }
                $planJson['pages'][$pageType]['blocks'][$blockIndex]['field_plan'] = $fieldPlan;
            }
        }

        return $planJson;
    }

    /**
     * @param list<string> $briefSignals
     */
    private function buildStageOneBriefSignalPhrase(array $briefSignals, string $planLocale): string
    {
        $signals = [];
        foreach ($briefSignals as $signal) {
            $signal = \trim((string)$signal);
            if ($signal === '' || \in_array($signal, $signals, true)) {
                continue;
            }
            $signals[] = $signal;
            if (\count($signals) >= 4) {
                break;
            }
        }

        if ($signals === []) {
            return '';
        }

        return \implode($this->isEnglishLocale($planLocale) ? ', ' : ' / ', $signals);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function injectStageOneBriefSignalsIntoHeroBlock(array $block, string $signalPhrase, string $planLocale): array
    {
        $content = \trim((string)($block['content'] ?? ''));
        if ($content !== '') {
            $block['content'] = $signalPhrase . ': ' . $content;
        }

        $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? 'home_hero'));
        $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
        $updatedFirstTextField = false;
        foreach ($fieldPlan as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            $sample = \trim((string)($row['sample'] ?? ''));
            if ($sample === '' || $this->stageOneTextContainsBriefSignal($sample, [$signalPhrase])) {
                continue;
            }
            $fieldLower = \mb_strtolower($field);
            if (
                !$updatedFirstTextField
                || \str_contains($fieldLower, 'title')
                || \str_contains($fieldLower, 'headline')
                || \str_contains($fieldLower, 'subtitle')
                || \str_contains($fieldLower, 'description')
            ) {
                $sample = $signalPhrase . ' - ' . $sample;
                $row['sample'] = $sample;
                $row = $this->syncStageOneFieldImplementationNote(
                    $row,
                    $this->buildStageOneFieldImplementationNoteFromSample($field, $sample, $blockKey, Page::TYPE_HOME, $planLocale)
                );
                $fieldPlan[$index] = $row;
                $updatedFirstTextField = true;
            }
        }
        if ($fieldPlan !== []) {
            $block['field_plan'] = $fieldPlan;
        }

        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $coreCopy = \trim((string)($executionScript['core_copy'] ?? ''));
        if ($coreCopy !== '' && !$this->stageOneTextContainsBriefSignal($coreCopy, [$signalPhrase])) {
            $executionScript['core_copy'] = $signalPhrase . ': ' . $coreCopy;
        }
        $featurePoints = \is_array($executionScript['feature_points'] ?? null) ? $executionScript['feature_points'] : [];
        if ($featurePoints !== []) {
            $firstPoint = \is_scalar($featurePoints[0] ?? null) ? \trim((string)$featurePoints[0]) : '';
            if (!$this->stageOneTextContainsBriefSignal($firstPoint, [$signalPhrase])) {
                $featurePoints[0] = $signalPhrase . ' - ' . ($firstPoint !== '' ? $firstPoint : 'hero promise');
                $executionScript['feature_points'] = \array_values($featurePoints);
            }
        }
        if ($executionScript !== []) {
            $block['execution_script'] = $executionScript;
        }

        return $block;
    }

    /**
     * @param list<array<string, mixed>|mixed> $blocks
     * @param array<string, mixed> $sharedPromptContext
     * @param array<string, mixed> $pageDesignPlan
     * @return list<array<string, mixed>|mixed>
     */
    private function repairAiStageOneBlocksBeforeValidation(
        array $blocks,
        string $pageType,
        string $planLocale,
        array $sharedPromptContext = [],
        array $pageDesignPlan = []
    ): array {
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
            $template = \trim((string)($block['component_kind'] ?? $block['template'] ?? ''));
            $sectionName = \trim((string)($block['section_code'] ?? $blockKey));
            $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
            foreach ($fieldPlan as $fieldIndex => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $field = \trim((string)($row['field'] ?? ''));
                $sample = \trim((string)($row['sample'] ?? ''));
                if ($field === '' || $sample === '') {
                    continue;
                }
                $implementationNote = $this->resolveStageOneFieldImplementationNote($row);
                if (
                    $implementationNote === ''
                    || $this->isStageOneContractPromptLikeText($implementationNote)
                    || $this->isPromptLikeStageOneText($implementationNote, $field, $template, $sectionName, $pageType)
                ) {
                    $implementationNote = $this->buildStageOneFieldImplementationNoteFromSample(
                        $field,
                        $sample,
                        $blockKey,
                        $pageType,
                        $planLocale
                    );
                    $fieldPlan[$fieldIndex] = $this->syncStageOneFieldImplementationNote($row, $implementationNote);
                }
            }
            $block['field_plan'] = $fieldPlan;
            $block['execution_script'] = $this->repairAiStageOneExecutionScriptBeforeValidation(
                \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [],
                $blockKey,
                $template,
                $sectionName,
                (string)$pageType,
                $planLocale
            );
            $block['design_tags'] = $this->normalizeStageOneBlockDesignTags(
                $block,
                $sharedPromptContext,
                $pageDesignPlan
            );
            $blocks[$index] = $block;
        }

        return \array_values($blocks);
    }

    /**
     * @param array<string, mixed> $executionScript
     * @return array<string, mixed>
     */
    private function repairAiStageOneExecutionScriptBeforeValidation(
        array $executionScript,
        string $blockKey,
        string $template,
        string $sectionName,
        string $pageType,
        string $planLocale
    ): array {
        $featurePoints = \is_array($executionScript['feature_points'] ?? null) ? $executionScript['feature_points'] : [];
        $repairedFeaturePoints = [];
        foreach ($featurePoints as $index => $point) {
            $text = \is_scalar($point) ? \trim((string)$point) : '';
            if ($text === '') {
                continue;
            }
            if ($this->isPromptLikeStageOneText($text, 'feature_points', $template, $sectionName !== '' ? $sectionName : $blockKey, $pageType)) {
                $text = $this->buildStageOneFeaturePointFromBlockContext($blockKey, $pageType, $index, $planLocale);
            }
            $repairedFeaturePoints[] = $text;
        }
        if ($featurePoints !== []) {
            $executionScript['feature_points'] = $repairedFeaturePoints !== []
                ? \array_slice($repairedFeaturePoints, 0, 3)
                : [$this->buildStageOneFeaturePointFromBlockContext($blockKey, $pageType, 0, $planLocale)];
        }

        return $executionScript;
    }

    /**
     * @param list<string> $pageTypes
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function repairAiStageOneProblemBlocksByAi(
        array $scope,
        array $websiteProfile,
        array $planJson,
        array $pageTypes,
        string $planLocale,
        string $contentLocale,
        string $instruction,
        string $targetScope,
        string $briefDescription,
        ?callable $onProgress = null
    ): array {
        $report = [
            'rounds' => [],
            'final_issue_count' => 0,
        ];
        $working = $planJson;

        for ($round = 1; $round <= self::STAGE_ONE_LOCAL_REGEN_MAX_ROUNDS; $round++) {
            $working = $this->repairAiStageOnePlanJsonBeforeValidation(
                $working,
                $pageTypes,
                $planLocale,
                $briefDescription,
                $scope,
                $contentLocale
            );
            $issues = $this->collectAiStageOneProblemIssues($working, $pageTypes, $briefDescription, $planLocale, $scope);
            if ($issues === []) {
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    '阶段一局部修复检查通过，未发现需重生成的问题块',
                    90,
                    'plan_assemble',
                    'local_repair_done',
                    [
                        'page_total' => \count($pageTypes),
                        'repair_round' => $round,
                        'issue_count' => 0,
                    ]
                );
                $report['final_issue_count'] = 0;
                return [$working, $report];
            }

            $this->emitStageOnePipelineProgress(
                $onProgress,
                '发现阶段一问题块，正在准备断点局部修复（第' . $round . '轮，' . \count($issues) . '项）',
                90,
                'plan_assemble',
                'local_repair_prepare',
                [
                    'page_total' => \count($pageTypes),
                    'repair_round' => $round,
                    'issue_count' => \count($issues),
                ]
            );
            $issueBlockMap = [];
            foreach ($issues as $issue) {
                $pageType = \trim((string)($issue['page_type'] ?? ''));
                $blockKey = \trim((string)($issue['block_key'] ?? ''));
                if ($pageType === '' || $blockKey === '' || $blockKey === '__page__') {
                    continue;
                }
                $issueBlockMap[$pageType . '::' . $blockKey] = [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                ];
            }
            $selectedIssueBlocks = \array_slice(\array_values($issueBlockMap), 0, self::STAGE_ONE_LOCAL_REGEN_BATCH_BLOCKS);
            if ($selectedIssueBlocks === []) {
                break;
            }

            $targetPageTypes = \array_values(\array_unique(\array_map(
                static fn(array $item): string => (string)($item['page_type'] ?? ''),
                $selectedIssueBlocks
            )));
            if ($targetPageTypes === []) {
                break;
            }

            $this->emitStageOnePipelineProgress(
                $onProgress,
                '正在局部重生成异常页面内容（第' . $round . '轮，' . \count($targetPageTypes) . '个页面）',
                90,
                'plan_assemble',
                'local_repair_generate',
                [
                    'page_total' => \count($pageTypes),
                    'repair_round' => $round,
                    'repair_page_total' => \count($targetPageTypes),
                    'issue_count' => \count($issues),
                ]
            );
            $issueSummary = [];
            foreach ($issues as $issue) {
                $pageType = (string)($issue['page_type'] ?? '');
                if (!\in_array($pageType, $targetPageTypes, true)) {
                    continue;
                }
                $issueSummary[] = '[' . $pageType . '][' . (string)($issue['block_key'] ?? '') . '] '
                    . (string)($issue['field_path'] ?? '')
                    . ': '
                    . (string)($issue['reason_code'] ?? 'invalid');
                if (\count($issueSummary) >= 20) {
                    break;
                }
            }
            $issueSpecificRules = $this->buildStageOneIssueSpecificRecoveryRules($issues);
            $regenInstruction = \trim($instruction . "\n"
                . 'Fix only listed invalid blocks. Keep all unaffected blocks unchanged. '
                . 'Return final customer-visible copy, not writing guidance. '
                . 'Issue list: ' . \implode('; ', $issueSummary)
                . ($issueSpecificRules !== [] ? ("\n" . \implode("\n", $issueSpecificRules)) : ''));

            try {
                $regeneratedPages = $this->generateStageOnePagePlansByAiSequential(
                    $scope,
                    $websiteProfile,
                    $working,
                    $targetPageTypes,
                    $planLocale,
                    $contentLocale,
                    $regenInstruction,
                    $targetScope,
                    null,
                    $onProgress
                );
            } catch (\Throwable $throwable) {
                $report['rounds'][] = [
                    'round' => $round,
                    'target_pages' => $targetPageTypes,
                    'target_blocks' => $selectedIssueBlocks,
                    'issue_count_before' => \count($issues),
                    'merged' => 0,
                    'repair_error' => $this->clipText($throwable->getMessage(), 800),
                ];
                $this->emitStageOnePipelineProgress(
                    $onProgress,
                    '阶段一局部重生成失败，已记录错误；本次不做本地兜底，等待下次从头重试',
                    90,
                    'plan_assemble',
                    'local_repair_error',
                    [
                        'page_total' => \count($pageTypes),
                        'repair_round' => $round,
                        'issue_count' => \count($issues),
                        'error_message' => $this->clipText($throwable->getMessage(), 800),
                    ]
                );
                break;
            }

            $merged = false;
            foreach ($targetPageTypes as $pageType) {
                $existingPage = \is_array($working['pages'][$pageType] ?? null) ? $working['pages'][$pageType] : [];
                $regeneratedPage = \is_array($regeneratedPages[$pageType] ?? null) ? $regeneratedPages[$pageType] : [];
                if ($existingPage === [] || $regeneratedPage === []) {
                    continue;
                }
                $targetBlockKeys = [];
                foreach ($selectedIssueBlocks as $selectedIssueBlock) {
                    if ((string)($selectedIssueBlock['page_type'] ?? '') !== $pageType) {
                        continue;
                    }
                    $targetBlockKeys[] = (string)($selectedIssueBlock['block_key'] ?? '');
                }
                $targetBlockKeys = \array_values(\array_unique(\array_filter($targetBlockKeys, static fn(string $value): bool => $value !== '')));
                if ($targetBlockKeys === []) {
                    continue;
                }
                $working['pages'][$pageType] = $this->mergeStageOneRegeneratedPageByProblemBlocks(
                    $existingPage,
                    $regeneratedPage,
                    $targetBlockKeys
                );
                $merged = true;
            }

            $report['rounds'][] = [
                'round' => $round,
                'target_pages' => $targetPageTypes,
                'target_blocks' => $selectedIssueBlocks,
                'issue_count_before' => \count($issues),
                'merged' => $merged ? 1 : 0,
            ];

            $this->emitStageOnePipelineProgress(
                $onProgress,
                '阶段一局部修复第' . $round . '轮已合并，正在继续校验',
                90,
                'plan_assemble',
                'local_repair_merge',
                [
                    'page_total' => \count($pageTypes),
                    'repair_round' => $round,
                    'issue_count' => \count($issues),
                    'merged' => $merged ? 1 : 0,
                ]
            );
            if (!$merged) {
                break;
            }
        }

        $finalIssues = $this->collectAiStageOneProblemIssues($working, $pageTypes, $briefDescription, $planLocale, $scope);
        if ($finalIssues !== []) {
            $this->emitStageOnePipelineProgress(
                $onProgress,
                'Stage-one contract validation still has unresolved page/block issues; local fallback is forbidden and the plan must retry.',
                90,
                'plan_assemble',
                'contract_failed',
                [
                    'page_total' => \count($pageTypes),
                    'issue_count' => \count($finalIssues),
                ]
            );
            throw new \RuntimeException(
                'Stage-one contract validation failed after AI regeneration; local fallback is forbidden. Issues: '
                . $this->summarizeStageOneProblemIssues($finalIssues)
            );
        }
        $report['final_issue_count'] = \count($finalIssues);
        $report['final_issues'] = \array_slice($finalIssues, 0, 30);

        return [$working, $report];
    }

    /**
     * @param list<string> $targetBlockKeys
     * @return array<string,mixed>
     */
    private function mergeStageOneRegeneratedPageByProblemBlocks(array $existingPage, array $regeneratedPage, array $targetBlockKeys): array
    {
        $mergedPage = $existingPage;
        $targetLookup = \array_fill_keys($targetBlockKeys, true);
        $existingBlocks = \is_array($existingPage['blocks'] ?? null) ? $existingPage['blocks'] : [];
        $incomingBlocks = \is_array($regeneratedPage['blocks'] ?? null) ? $regeneratedPage['blocks'] : [];
        $incomingByKey = [];
        foreach ($incomingBlocks as $incomingBlock) {
            if (!\is_array($incomingBlock)) {
                continue;
            }
            $blockKey = \trim((string)($incomingBlock['block_key'] ?? ''));
            if ($blockKey === '') {
                continue;
            }
            $incomingByKey[$blockKey] = $incomingBlock;
        }
        foreach ($existingBlocks as $index => $existingBlock) {
            if (!\is_array($existingBlock)) {
                continue;
            }
            $blockKey = \trim((string)($existingBlock['block_key'] ?? ''));
            if ($blockKey === '' || !isset($targetLookup[$blockKey])) {
                continue;
            }
            if (\is_array($incomingByKey[$blockKey] ?? null)) {
                $existingBlocks[$index] = \array_replace_recursive($existingBlock, $incomingByKey[$blockKey]);
            }
        }
        $mergedPage['blocks'] = $existingBlocks;
        if (\trim((string)($mergedPage['page_goal'] ?? '')) === '') {
            $mergedPage['page_goal'] = (string)($regeneratedPage['page_goal'] ?? $mergedPage['page_goal'] ?? '');
        }
        if (\trim((string)($mergedPage['theme_alignment_summary'] ?? '')) === '') {
            $mergedPage['theme_alignment_summary'] = (string)($regeneratedPage['theme_alignment_summary'] ?? $mergedPage['theme_alignment_summary'] ?? '');
        }

        return $mergedPage;
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return array<string,mixed>
     */
    private function applyStageOneIssueFallbacks(array $planJson, array $issues, string $planLocale): array
    {
        foreach ($issues as $issue) {
            $pageType = \trim((string)($issue['page_type'] ?? ''));
            $blockKey = \trim((string)($issue['block_key'] ?? ''));
            $fieldPath = \trim((string)($issue['field_path'] ?? ''));
            if ($pageType === '' || !\is_array($planJson['pages'][$pageType] ?? null)) {
                continue;
            }

            if ($blockKey === '__page__') {
                if ($fieldPath === 'page_goal' && \trim((string)($planJson['pages'][$pageType]['page_goal'] ?? '')) === '') {
                    $planJson['pages'][$pageType]['page_goal'] = $this->buildStageOneConcretePageGoal(
                        $pageType,
                        (string)($planJson['pages'][$pageType]['page_label'] ?? $planJson['pages'][$pageType]['page_title'] ?? ''),
                        $planLocale
                    );
                }
                if ($fieldPath === 'theme_alignment_summary' && \trim((string)($planJson['pages'][$pageType]['theme_alignment_summary'] ?? '')) === '') {
                    $planJson['pages'][$pageType]['theme_alignment_summary'] = $this->buildStageOneThemeAlignmentSummaryFromPlanJson(
                        $pageType,
                        \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [],
                        $planJson,
                        $planLocale
                    );
                }
                $currentThemeAlignmentSummary = \trim((string)($planJson['pages'][$pageType]['theme_alignment_summary'] ?? ''));
                if (
                    $fieldPath === 'theme_alignment_summary'
                    && $this->isPromptLikeStageOneText($currentThemeAlignmentSummary, 'theme_alignment_summary', '', '', $pageType)
                ) {
                    $planJson['pages'][$pageType]['theme_alignment_summary'] = $this->buildStageOneThemeAlignmentSummaryFromPlanJson(
                        $pageType,
                        \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [],
                        $planJson,
                        $planLocale
                    );
                }
                $currentPageGoal = \trim((string)($planJson['pages'][$pageType]['page_goal'] ?? ''));
                if (
                    $fieldPath === 'page_goal'
                    && $this->isWeakStageOnePageGoal($currentPageGoal, $pageType)
                ) {
                    $planJson['pages'][$pageType]['page_goal'] = $this->buildStageOneConcretePageGoal(
                        $pageType,
                        (string)($planJson['pages'][$pageType]['page_label'] ?? $planJson['pages'][$pageType]['page_title'] ?? ''),
                        $planLocale
                    );
                }
                continue;
            }

            $blocks = \is_array($planJson['pages'][$pageType]['blocks'] ?? null) ? $planJson['pages'][$pageType]['blocks'] : [];
            foreach ($blocks as $index => $block) {
                if (!\is_array($block) || \trim((string)($block['block_key'] ?? '')) !== $blockKey) {
                    continue;
                }
                if ($fieldPath === 'content') {
                    $blocks[$index]['content'] = $this->isEnglishLocale($planLocale)
                        ? ('Visible ' . $blockKey . ' content rendered for users.')
                        : ('在页面中呈现 ' . $blockKey . ' 的可见内容。');
                } elseif ($fieldPath === 'execution_script.core_copy') {
                    $blocks[$index]['execution_script']['core_copy'] = $this->isEnglishLocale($planLocale)
                        ? ('Core copy for ' . $blockKey . ' stays visible and actionable.')
                        : ($blockKey . ' 的核心文案保持可见且可执行。');
                } elseif ($fieldPath === 'execution_script.feature_points') {
                    $blocks[$index]['execution_script']['feature_points'] = [
                        $this->buildStageOneFeaturePointFromBlockContext($blockKey, $pageType, 0, $planLocale),
                        $this->buildStageOneFeaturePointFromBlockContext($blockKey, $pageType, 1, $planLocale),
                    ];
                } elseif (\str_starts_with($fieldPath, 'field_plan.')) {
                    $fieldPlan = \is_array($blocks[$index]['field_plan'] ?? null) ? $blocks[$index]['field_plan'] : [];
                    $fieldIndex = (int)($issue['field_index'] ?? -1);
                    if ($fieldIndex >= 0 && \is_array($fieldPlan[$fieldIndex] ?? null)) {
                        $field = \trim((string)($fieldPlan[$fieldIndex]['field'] ?? ''));
                        $sample = \trim((string)($fieldPlan[$fieldIndex]['sample'] ?? ''));
                        if ($sample === '') {
                            $sample = $this->isEnglishLocale($planLocale)
                                ? ('Visible ' . ($field !== '' ? $field : 'content') . ' content for ' . $blockKey)
                                : ($blockKey . ' 的' . ($field !== '' ? $field : '内容') . '可见文案');
                            $fieldPlan[$fieldIndex]['sample'] = $sample;
                        }
                        $fieldPlan[$fieldIndex] = $this->syncStageOneFieldImplementationNote(
                            $fieldPlan[$fieldIndex],
                            $this->buildStageOneFieldImplementationNoteFromSample($field, $sample, $blockKey, $pageType, $planLocale)
                        );
                        $blocks[$index]['field_plan'] = $fieldPlan;
                    }
                }
                $content = \trim((string)($blocks[$index]['content'] ?? ''));
                if ($this->isWeakStageOneBlockContent($content, $blockKey, $pageType)) {
                    $blocks[$index]['content'] = $this->buildStageOneConcreteBlockContent($blockKey, $pageType, $planLocale);
                }
                $coreCopy = \trim((string)($blocks[$index]['execution_script']['core_copy'] ?? ''));
                if ($this->isWeakStageOneCoreCopy($coreCopy, $blockKey, $pageType)) {
                    $blocks[$index]['execution_script']['core_copy'] = $this->buildStageOneConcreteCoreCopy($blockKey, $pageType, $planLocale);
                }
                foreach (\is_array($blocks[$index]['field_plan'] ?? null) ? $blocks[$index]['field_plan'] : [] as $fpIndex => $fieldRow) {
                    if (!\is_array($fieldRow)) {
                        continue;
                    }
                    $field = \trim((string)($fieldRow['field'] ?? ''));
                    $sample = \trim((string)($fieldRow['sample'] ?? ''));
                    if ($this->isWeakStageOneFieldSample($sample, $field, $blockKey, $pageType)) {
                        $blocks[$index]['field_plan'][$fpIndex]['sample'] = $this->buildStageOneConcreteFieldSample($field, $blockKey, $pageType, $planLocale);
                    }
                }
                break;
            }
            $planJson['pages'][$pageType]['blocks'] = $blocks;
        }

        return $planJson;
    }

    /**
     * @param list<array<string,mixed>> $issues
     */
    private function summarizeStageOneProblemIssues(array $issues): string
    {
        $parts = [];
        foreach (\array_slice($issues, 0, 12) as $issue) {
            $pageType = \trim((string)($issue['page_type'] ?? ''));
            $blockKey = \trim((string)($issue['block_key'] ?? ''));
            $fieldPath = \trim((string)($issue['field_path'] ?? ''));
            $reason = \trim((string)($issue['reason_code'] ?? 'invalid'));
            $label = \implode('/', \array_values(\array_filter([$pageType, $blockKey, $fieldPath], static fn(string $value): bool => $value !== '')));
            $parts[] = ($label !== '' ? $label : 'stage1') . '=' . ($reason !== '' ? $reason : 'invalid');
        }

        return $parts !== [] ? \implode('; ', $parts) : 'unknown issue';
    }

    /**
     * @param list<string> $pageTypes
     * @return list<array<string,mixed>>
     */
    private function collectAiStageOneProblemIssues(array $planJson, array $pageTypes, string $briefDescription, string $planLocale, array $scope = []): array
    {
        $issues = [];
        $seenProblemIssueKeys = [];
        foreach ($pageTypes as $pageType) {
            $page = \is_array($planJson['pages'][$pageType] ?? null) ? $planJson['pages'][$pageType] : [];
            if ($page === []) {
                $issues[] = [
                    'stage' => 'stage1',
                    'page_type' => (string)$pageType,
                    'block_key' => '__page__',
                    'field_path' => 'page',
                    'reason_code' => 'missing_page',
                    'matched_marker' => '',
                    'snippet' => '',
                    'severity' => 'high',
                ];
                continue;
            }
            $pageGoal = \trim((string)($page['page_goal'] ?? ''));
            if ($this->isWeakStageOnePageGoal($pageGoal, (string)$pageType)) {
                $issues[] = [
                    'stage' => 'stage1',
                    'page_type' => (string)$pageType,
                    'block_key' => '__page__',
                    'field_path' => 'page_goal',
                    'reason_code' => 'instruction_like_or_empty',
                    'matched_marker' => '',
                    'snippet' => $this->clipText($pageGoal, 120),
                    'severity' => 'medium',
                ];
            }
            $themeAlignmentSummary = \trim((string)($page['theme_alignment_summary'] ?? ''));
            if ($themeAlignmentSummary === '' || $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', (string)$pageType)) {
                $issues[] = [
                    'stage' => 'stage1',
                    'page_type' => (string)$pageType,
                    'block_key' => '__page__',
                    'field_path' => 'theme_alignment_summary',
                    'reason_code' => 'instruction_like_or_empty',
                    'matched_marker' => '',
                    'snippet' => $this->clipText($themeAlignmentSummary, 120),
                    'severity' => 'medium',
                ];
            }
            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            $contract = $this->getStageOneContractService()->normalize(
                \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : (\is_array($scope['stage1_contract'] ?? null) ? $scope['stage1_contract'] : []),
                $scope,
                $pageTypes,
                $planLocale,
                $this->resolveStageOneContentLocale($scope, $planLocale),
                'problem_scan'
            );
            $pageContract = $this->getStageOneContractService()->pageContract($contract, (string)$pageType);
            $blockBudget = [
                'min' => (int)($pageContract['min_blocks'] ?? 0),
                'max' => (int)($pageContract['max_blocks'] ?? 0),
                'required' => \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [],
            ];
            $forbiddenBlockKeys = \array_fill_keys(\array_map('strval', \is_array($pageContract['forbidden_block_keys'] ?? null) ? $pageContract['forbidden_block_keys'] : AiSiteStageOneContractService::GENERIC_BLOCK_KEYS), true);
            $fieldPlanCount = \max(1, (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT));
            if (\count($blocks) < $blockBudget['min'] || \count($blocks) > $blockBudget['max']) {
                $issues[] = [
                    'stage' => 'stage1',
                    'page_type' => (string)$pageType,
                    'block_key' => '__page__',
                    'field_path' => 'blocks',
                    'reason_code' => 'invalid_block_count',
                    'matched_marker' => '',
                    'snippet' => 'count=' . \count($blocks) . ', min=' . $blockBudget['min'] . ', max=' . $blockBudget['max'],
                    'severity' => 'high',
                ];
            }
            $seenBlockKeys = [];
            $requiredBlockKeys = \array_fill_keys($blockBudget['required'], false);
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? ''));
                if ($blockKey === '') {
                    continue;
                }
                $normalizedBlockKey = \mb_strtolower($blockKey);
                if (isset($seenBlockKeys[$normalizedBlockKey])) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'blocks.block_key',
                        'reason_code' => 'duplicate_block_key',
                        'matched_marker' => '',
                        'snippet' => $blockKey,
                        'severity' => 'high',
                    ];
                    continue;
                }
                $seenBlockKeys[$normalizedBlockKey] = true;
                if (isset($forbiddenBlockKeys[$normalizedBlockKey])) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'blocks.block_key',
                        'reason_code' => 'generic_block_key',
                        'matched_marker' => '',
                        'snippet' => $blockKey,
                        'severity' => 'high',
                    ];
                    continue;
                }
                if (\array_key_exists($blockKey, $requiredBlockKeys)) {
                    $requiredBlockKeys[$blockKey] = true;
                }
                $template = (string)($block['component_kind'] ?? $block['template'] ?? '');
                $sectionName = (string)($block['section_code'] ?? $blockKey);
                $content = \trim((string)($block['content'] ?? ''));
                if ($content === '' || $this->isPromptLikeStageOneText($content, 'content', $template, $sectionName, (string)$pageType)) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'content',
                        'reason_code' => 'instruction_like_or_empty',
                        'matched_marker' => '',
                        'snippet' => $this->clipText($content, 120),
                        'severity' => 'high',
                    ];
                }
                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                if ($fieldPlan === [] && $fieldPlanCount > 0) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'field_plan',
                        'reason_code' => 'missing_field_plan',
                        'matched_marker' => '',
                        'snippet' => 'count=0',
                        'severity' => 'high',
                    ];
                } elseif (\count($fieldPlan) !== $fieldPlanCount) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'field_plan',
                        'reason_code' => 'invalid_field_plan_count',
                        'matched_marker' => '',
                        'snippet' => 'count=' . \count($fieldPlan),
                        'severity' => 'medium',
                    ];
                }
                foreach ($fieldPlan as $fieldIndex => $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $field = \trim((string)($row['field'] ?? ''));
                    $sample = \trim((string)($row['sample'] ?? ''));
                    $implementationNote = $this->resolveStageOneFieldImplementationNote($row);
                    if ($sample === '' || $this->isPromptLikeStageOneText($sample, $field, $template, $sectionName, (string)$pageType)) {
                        $issues[] = [
                            'stage' => 'stage1',
                            'page_type' => (string)$pageType,
                            'block_key' => $blockKey,
                            'field_path' => 'field_plan.sample',
                            'field_index' => (int)$fieldIndex,
                            'reason_code' => 'instruction_like_or_empty',
                            'matched_marker' => '',
                            'snippet' => $this->clipText($sample, 120),
                            'severity' => 'high',
                        ];
                    }
                    if ($implementationNote === '' || $this->isPromptLikeStageOneText($implementationNote, $field, $template, $sectionName, (string)$pageType)) {
                        $issues[] = [
                            'stage' => 'stage1',
                            'page_type' => (string)$pageType,
                            'block_key' => $blockKey,
                            'field_path' => 'field_plan.implementation_note',
                            'field_index' => (int)$fieldIndex,
                            'reason_code' => 'instruction_like_or_empty',
                            'matched_marker' => '',
                            'snippet' => $this->clipText($implementationNote, 120),
                            'severity' => 'medium',
                        ];
                    }
                }
                $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
                $coreCopy = \trim((string)($executionScript['core_copy'] ?? ''));
                if ($coreCopy === '' || $this->isPromptLikeStageOneText($coreCopy, 'core_copy', $template, $sectionName, (string)$pageType)) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => $blockKey,
                        'field_path' => 'execution_script.core_copy',
                        'reason_code' => 'instruction_like_or_empty',
                        'matched_marker' => '',
                        'snippet' => $this->clipText($coreCopy, 120),
                        'severity' => 'high',
                    ];
                }
                foreach (\is_array($executionScript['feature_points'] ?? null) ? $executionScript['feature_points'] : [] as $point) {
                    $text = \is_scalar($point) ? \trim((string)$point) : '';
                    if ($text === '' || $this->isPromptLikeStageOneText($text, 'feature_points', $template, $sectionName, (string)$pageType)) {
                        $issues[] = [
                            'stage' => 'stage1',
                            'page_type' => (string)$pageType,
                            'block_key' => $blockKey,
                            'field_path' => 'execution_script.feature_points',
                            'reason_code' => 'instruction_like_or_empty',
                            'matched_marker' => '',
                            'snippet' => $this->clipText($text, 120),
                            'severity' => 'medium',
                        ];
                    }
                }
            }
            foreach ($requiredBlockKeys as $requiredBlockKey => $seen) {
                if (!$seen) {
                    $issues[] = [
                        'stage' => 'stage1',
                        'page_type' => (string)$pageType,
                        'block_key' => (string)$requiredBlockKey,
                        'field_path' => 'blocks.block_key',
                        'reason_code' => 'missing_required_block_key',
                        'matched_marker' => '',
                        'snippet' => (string)$requiredBlockKey,
                        'severity' => 'high',
                    ];
                }
            }
            $pageValidationReport = $this->getStageOneContractValidator()->validatePagePlan(
                (string)$pageType,
                $page,
                $contract,
                [
                    'generation_attempts' => ['problem_scan' => 1],
                    'recovery_count' => 0,
                    'brief_description' => $briefDescription,
                    'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
                    'user_requirements' => \is_array($scope['user_requirements'] ?? null) ? $scope['user_requirements'] : [],
                    'plan_locale' => $planLocale,
                    'content_locale' => $this->resolveStageOneContentLocale($scope, $planLocale),
                ]
            );
            foreach (\is_array($pageValidationReport['issues'] ?? null) ? $pageValidationReport['issues'] : [] as $contractIssue) {
                if (!\is_array($contractIssue)) {
                    continue;
                }
                $reasonCode = \trim((string)($contractIssue['reason_code'] ?? $contractIssue['code'] ?? 'invalid'));
                if ($reasonCode === '' || $reasonCode === 'missing_page') {
                    continue;
                }
                $severity = \trim((string)($contractIssue['severity'] ?? 'high'));
                if (!\in_array($severity, ['high', 'blocking'], true)) {
                    continue;
                }
                $fieldPath = \trim((string)($contractIssue['field_path'] ?? $contractIssue['path'] ?? ''));
                $blockKey = $this->resolveStageOneProblemIssueBlockKey($contractIssue, $blocks, $fieldPath);
                if ($blockKey === '') {
                    continue;
                }
                $dedupeKey = (string)$pageType . '::' . $blockKey . '::' . $fieldPath . '::' . $reasonCode;
                if (isset($seenProblemIssueKeys[$dedupeKey])) {
                    continue;
                }
                $seenProblemIssueKeys[$dedupeKey] = true;
                $issues[] = [
                    'stage' => 'stage1',
                    'page_type' => (string)$pageType,
                    'block_key' => $blockKey,
                    'field_path' => $fieldPath !== '' ? $fieldPath : 'blocks',
                    'reason_code' => $reasonCode,
                    'matched_marker' => '',
                    'snippet' => $this->clipText((string)($contractIssue['snippet'] ?? ($contractIssue['extra']['snippet'] ?? '')), 120),
                    'severity' => $severity,
                ];
            }
        }

        return \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => \in_array((string)($issue['severity'] ?? ''), ['high', 'blocking'], true)
        ));
    }

    /**
     * @param array<string, mixed> $issue
     * @param list<array<string, mixed>> $blocks
     */
    private function resolveStageOneProblemIssueBlockKey(array $issue, array $blocks, string $fieldPath): string
    {
        $blockKey = \trim((string)($issue['block_key'] ?? ($issue['extra']['block_key'] ?? '')));
        if ($blockKey !== '' && $blockKey !== '__page__') {
            return $blockKey;
        }

        if (\preg_match('/(?:^|\\.)blocks\\.(\\d+)(?:\\.|$)/', $fieldPath, $matches) === 1) {
            $index = (int)$matches[1];
            if (\is_array($blocks[$index] ?? null)) {
                $candidate = \trim((string)($blocks[$index]['block_key'] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $reasonCode = \trim((string)($issue['reason_code'] ?? $issue['code'] ?? ''));
        if ($reasonCode === 'page_missing_generated_image_intent' && \is_array($blocks[0] ?? null)) {
            return \trim((string)($blocks[0]['block_key'] ?? ''));
        }

        return '';
    }

    private function buildStageOneFeaturePointFromBlockContext(
        string $blockKey,
        string $pageType,
        int $index,
        string $planLocale
    ): string {
        $isEn = $this->isEnglishLocale($planLocale);
        $blockLabel = $blockKey !== '' ? $blockKey : $pageType;
        $fallbacksEn = [
            'Visible ' . $blockLabel . ' content rendered on the page',
            'Shared theme typography and spacing applied to ' . $blockLabel,
            'Responsive ' . $blockLabel . ' layout ready for implementation',
        ];
        $fallbacksZh = [
            '在页面中渲染 ' . $blockLabel . ' 的可见内容',
            '为 ' . $blockLabel . ' 应用共享主题字号与间距',
            '完成 ' . $blockLabel . ' 的响应式布局说明',
        ];
        $fallbacks = $isEn ? $fallbacksEn : $fallbacksZh;

        return $fallbacks[$index % \count($fallbacks)];
    }

    private function buildStageOneFieldImplementationNoteFromSample(
        string $field,
        string $sample,
        string $blockKey,
        string $pageType,
        string $planLocale
    ): string {
        $isEn = $this->isEnglishLocale($planLocale) || $this->looksMostlyAscii($sample);
        $fieldLabel = $field !== '' ? $field : 'content';
        $blockLabel = $blockKey !== '' ? $blockKey : $pageType;
        $sampleSnippet = $this->clipText($sample, 90);
        $normalizedField = \mb_strtolower($fieldLabel);

        if ($isEn) {
            if (\str_contains($normalizedField, 'title') || \str_contains($normalizedField, 'headline')) {
                return \sprintf('Render "%s" as the visible heading in the "%s" block using the shared typography scale.', $sampleSnippet, $blockLabel);
            }
            if (\str_contains($normalizedField, 'subtitle') || \str_contains($normalizedField, 'summary') || \str_contains($normalizedField, 'description')) {
                return \sprintf('Place "%s" directly below the "%s" block heading as supporting copy with readable line height.', $sampleSnippet, $blockLabel);
            }
            if (\str_contains($normalizedField, 'image') || \str_contains($normalizedField, 'media') || \str_contains($normalizedField, 'asset')) {
                return \sprintf('Use "%s" as the concrete generated-media brief for the "%s" block; the build must render or generate this asset and fail if no verified media is available.', $sampleSnippet, $blockLabel);
            }
            if (\str_contains($normalizedField, 'cta') || \str_contains($normalizedField, 'button')) {
                return \sprintf('Use "%s" as the clickable CTA content in the "%s" block and connect it to the block action target.', $sampleSnippet, $blockLabel);
            }

            return \sprintf('Bind "%s" to editable field "%s" in the "%s" block and render it as visible page content.', $sampleSnippet, $fieldLabel, $blockLabel);
        }

        if (\str_contains($normalizedField, 'title') || \str_contains($normalizedField, 'headline')) {
            return \sprintf('将“%s”渲染为“%s”区块的可见标题，并沿用共享标题字号层级。', $sampleSnippet, $blockLabel);
        }
        if (\str_contains($normalizedField, 'subtitle') || \str_contains($normalizedField, 'summary') || \str_contains($normalizedField, 'description')) {
            return \sprintf('将“%s”放在“%s”区块标题下方作为辅助文案，保持可读行高。', $sampleSnippet, $blockLabel);
        }
        if (\str_contains($normalizedField, 'image') || \str_contains($normalizedField, 'media') || \str_contains($normalizedField, 'asset')) {
            return \sprintf('Use "%s" as the concrete generated-media brief for the "%s" block; the build must render or generate this asset and fail if no verified media is available.', $sampleSnippet, $blockLabel);
        }
        if (\str_contains($normalizedField, 'cta') || \str_contains($normalizedField, 'button')) {
            return \sprintf('将“%s”作为“%s”区块的可点击 CTA 内容，并连接到该区块动作目标。', $sampleSnippet, $blockLabel);
        }

        return \sprintf('将“%s”绑定到“%s”区块的可编辑字段“%s”，并作为页面可见内容渲染。', $sampleSnippet, $blockLabel, $fieldLabel);
    }

    private function looksMostlyAscii(string $text): bool
    {
        $text = \trim($text);
        if ($text === '') {
            return false;
        }
        $asciiLength = \strlen((string)\preg_replace('/[^\x00-\x7F]/', '', $text));

        return $asciiLength >= (int)\ceil(\strlen($text) * 0.7);
    }

    /**
     * Repair an incomplete theme_design before the strict assertion runs.
     *
     * The AI sometimes returns a structurally valid JSON but with missing sub-fields
     * inside theme_design (e.g., no `color_scheme.primary`, empty `visual_keywords`).
     * Instead of failing the entire stage-1 pipeline with a generic error, fill in
     * reasonable defaults from the palette/style data that IS available.
     *
     * @param array<string,mixed> $planJson
     * @param array<string,mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string,mixed>
     */
    private function repairStageOneThemeDesignBeforeValidation(array $planJson, array $scope, array $pageTypes): array
    {
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $palette = \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [];
        $themeStyle = \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [];
        $planLocale = \trim((string)($planJson['content_locale'] ?? $planJson['plan_locale'] ?? ''));
        $isEn = $this->isEnglishLocale($planLocale);

        if (\trim((string)($themeDesign['theme_purpose'] ?? '')) === '') {
            $siteTitle = \trim((string)($scope['site_title'] ?? $scope['website_profile']['site_title'] ?? ''));
            $themePurpose = \trim((string)($themeStyle['description'] ?? $themeStyle['theme_purpose'] ?? ''));
            if ($themePurpose === '') {
                $themePurpose = $isEn
                    ? ('Build a trustworthy online presence' . ($siteTitle !== '' ? ' for "' . $siteTitle . '"' : '') . '.')
                    : ('为 "' . ($siteTitle !== '' ? $siteTitle : '该站点') . '" 建立清晰可信的线上品牌形象。');
            }
            $themeDesign['theme_purpose'] = $themePurpose;
        }
        if (\trim((string)($themeDesign['style_signature'] ?? '')) === '') {
            $themeDesign['style_signature'] = \trim((string)($themeStyle['visual_tone'] ?? $themeStyle['name'] ?? ''));
            if ($themeDesign['style_signature'] === '') {
                $themeDesign['style_signature'] = $isEn ? 'Brief-aligned visual identity' : '紧扣需求的视觉识别';
            }
        }
        $artDirectionValue = $themeDesign['art_direction'] ?? '';
        if (!\is_array($artDirectionValue) && \trim((string)$artDirectionValue) === '') {
            $themeDesign['art_direction'] = $isEn ? 'Calm layout with brand-aligned accent moments.' : '保持克制的布局，并在品牌主色处做强调。';
        }

        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $colorDefaults = [
            'name' => $isEn ? 'Site palette' : '站点主色',
            'primary' => $this->pickString($palette['primary'] ?? null, $themeStyle['primary_color'] ?? null, '#1e293b'),
            'secondary' => $this->pickString($palette['secondary'] ?? null, $themeStyle['secondary_color'] ?? null, '#334155'),
            'accent' => $this->pickString($palette['accent'] ?? null, $themeStyle['accent_color'] ?? null, '#3b82f6'),
            'background' => $this->pickString($palette['surface'] ?? null, $palette['background'] ?? null, '#ffffff'),
            'body' => $this->pickString($palette['text'] ?? null, $palette['body'] ?? null, '#1f2937'),
            'button' => $this->pickString($palette['button'] ?? null, $palette['accent'] ?? null, '#2563eb'),
        ];
        foreach ($colorDefaults as $key => $default) {
            if (\trim((string)($colorScheme[$key] ?? '')) === '') {
                $colorScheme[$key] = $default;
            }
        }
        $themeDesign['color_scheme'] = $colorScheme;

        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $typographyDefaults = [
            'font_family' => $this->pickString($themeStyle['font_family'] ?? null, $palette['font_family'] ?? null, 'system-ui, -apple-system, sans-serif'),
            'heading_scale' => $isEn ? '1.25x modular scale' : '1.25 倍递进字号',
            'body_scale' => $isEn ? '16px base line-height 1.55' : '正文 16px 行高 1.55',
            'spacing_scale' => $isEn ? 'comfortable 8px grid' : '宽松 8px 栅格',
            'radius_scale' => $isEn ? 'soft 8px radius' : '柔和 8px 圆角',
        ];
        foreach ($typographyDefaults as $key => $default) {
            if (\trim((string)($typography[$key] ?? '')) === '') {
                $typography[$key] = $default;
            }
        }
        $themeDesign['typography_spacing_radius'] = $typography;

        $visualKeywords = $this->normalizeStringList($themeDesign['visual_keywords'] ?? []);
        if ($visualKeywords === []) {
            $themeStyleKeywords = $this->normalizeStringList($themeStyle['visual_keywords'] ?? []);
            $paletteKeywords = $this->normalizeStringList($palette['visual_keywords'] ?? []);
            $visualKeywords = $themeStyleKeywords !== []
                ? $themeStyleKeywords
                : ($paletteKeywords !== [] ? $paletteKeywords : ($isEn
                    ? ['confident typography', 'layered surfaces', 'restrained motion']
                    : ['有质感的字体', '层次分明的版面', '克制的动效']));
        }
        $themeDesign['visual_keywords'] = \array_values(\array_unique($visualKeywords));

        if (\trim((string)($themeDesign['tone_of_voice'] ?? '')) === '') {
            $themeDesign['tone_of_voice'] = $isEn ? 'Clear, helpful, and trustworthy.' : '清晰、可靠、有帮助感。';
        }
        if (\trim((string)($themeDesign['cta_tone'] ?? '')) === '') {
            $themeDesign['cta_tone'] = $isEn ? 'Direct and action-leading.' : '直接、引导下一步行动。';
        }

        $forbiddenStyles = $this->normalizeStringList($themeDesign['forbidden_styles'] ?? []);
        if ($forbiddenStyles === []) {
            $forbiddenStyles = $isEn
                ? ['flashy gradients without purpose', 'stock-photo collages', 'cluttered hero compositions']
                : ['毫无意义的炫光渐变', '拼贴风格的素材照片', '塞满元素的首屏组合'];
        }
        $themeDesign['forbidden_styles'] = \array_values(\array_unique($forbiddenStyles));

        $themeDesign = $this->repairAiStageOneThemeSelectionReasonBeforeValidation(
            $themeDesign,
            \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $scope['website_profile']['brief_description'] ?? '')),
            $planLocale
        );

        $planJson['theme_design'] = $themeDesign;
        return $planJson;
    }

    /**
     * @param array<string, mixed> $themeDesign
     * @return array<string, mixed>
     */
    private function repairAiStageOneThemeSelectionReasonBeforeValidation(
        array $themeDesign,
        string $briefDescription,
        string $planLocale
    ): array {
        $selectionReason = \trim((string)($themeDesign['selection_reason'] ?? ''));
        $needsRepair = $selectionReason === ''
            || $this->isPromptLikeStageOneText($selectionReason, 'selection_reason')
            || !$this->stageOneSelectionReasonReferencesRequirement($selectionReason, $briefDescription)
            || $this->isGenericThemeSelectionReason($selectionReason);
        if (!$needsRepair) {
            return $themeDesign;
        }

        $requirementReference = \trim($briefDescription);
        if ($requirementReference === '') {
            $requirementReference = \trim((string)($themeDesign['theme_purpose'] ?? ''));
        }
        if ($requirementReference === '') {
            return $themeDesign;
        }

        $themeDesign['selection_reason'] = $this->buildStageOneThemeSelectionReasonFromThemeDesign(
            $themeDesign,
            $requirementReference,
            $planLocale
        );

        return $themeDesign;
    }

    /**
     * @param array<string, mixed> $themeDesign
     */
    private function buildStageOneThemeSelectionReasonFromThemeDesign(
        array $themeDesign,
        string $requirementReference,
        string $planLocale
    ): string {
        $isEn = $this->isEnglishLocale($planLocale);
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $themePurpose = \trim((string)($themeDesign['theme_purpose'] ?? ''));
        $palette = \trim((string)($colorScheme['name'] ?? $colorScheme['primary'] ?? $colorScheme['accent'] ?? ''));
        $font = \trim((string)($typography['font_family'] ?? $typography['heading_scale'] ?? ''));
        $tone = \trim((string)($themeDesign['tone_of_voice'] ?? $themeDesign['cta_tone'] ?? ''));

        $requirementReference = $this->clipText($requirementReference, 140);
        $themePurpose = $themePurpose !== '' ? $this->clipText($themePurpose, 90) : ($isEn ? 'the conversion promise' : '转化承诺');
        $palette = $palette !== '' ? $palette : ($isEn ? 'the shared color system' : '共享色彩系统');
        $font = $font !== '' ? $font : ($isEn ? 'the shared typography system' : '共享字体系统');
        $tone = $tone !== '' ? $tone : ($isEn ? 'the shared voice and CTA tone' : '共享内容语气与 CTA 语气');

        if ($isEn) {
            return \sprintf(
                'The user one-line requirement "%s" needs %s, so this theme uses %s, %s, and %s to keep the first-screen promise, trust cues, and CTA directly tied to that brief.',
                $requirementReference,
                $themePurpose,
                $palette,
                $font,
                $tone
            );
        }

        return \sprintf(
            '用户一句话需求“%s”需要%s；该主题将它落到%s、%s和%s，确保首屏承诺、信任表达与 CTA 都直接服务这条需求。',
            $requirementReference,
            $themePurpose,
            $palette,
            $font,
            $tone
        );
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $planJson
     */
    private function buildStageOneThemeAlignmentSummaryFromPlanJson(
        string $pageType,
        array $page,
        array $planJson,
        string $planLocale
    ): string {
        $isEn = $this->isEnglishLocale($planLocale);
        $themeDesign = \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
        $blockKeys = \array_values(\array_filter(\array_map(
            static fn($block): string => \is_array($block) ? \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')) : '',
            $blocks
        ), static fn(string $value): bool => $value !== ''));

        $pageLabel = \trim((string)($page['page_label'] ?? $page['page_title'] ?? (Page::getPageTypes()[$pageType] ?? $pageType)));
        $pageGoal = \trim((string)($page['page_goal'] ?? ''));
        $themePurpose = \trim((string)($themeDesign['theme_purpose'] ?? $planJson['site_strategy']['core_goal'] ?? ''));
        $paletteName = \trim((string)($colorScheme['name'] ?? $planJson['palette']['name'] ?? ''));
        $primary = \trim((string)($colorScheme['primary'] ?? $planJson['palette']['primary'] ?? ''));
        $accent = \trim((string)($colorScheme['accent'] ?? $planJson['palette']['accent'] ?? ''));
        $typeRule = \trim((string)($typography['font_family'] ?? $typography['heading_scale'] ?? ''));
        $spacingRule = \trim((string)($typography['spacing_scale'] ?? $typography['radius_scale'] ?? ''));
        $tone = \trim((string)($themeDesign['tone_of_voice'] ?? $planJson['theme_style']['visual_tone'] ?? ''));
        $ctaTone = \trim((string)($themeDesign['cta_tone'] ?? ''));
        $forbiddenStyles = \is_array($themeDesign['forbidden_styles'] ?? null) ? $themeDesign['forbidden_styles'] : [];
        $forbidden = \trim((string)($forbiddenStyles[0] ?? ''));

        $pageLabel = $pageLabel !== '' ? $pageLabel : $pageType;
        $pageGoal = $pageGoal !== '' ? $pageGoal : ($isEn ? 'the selected page goal' : '当前页面目标');
        $themePurpose = $themePurpose !== '' ? $themePurpose : ($isEn ? 'the shared conversion promise' : '共享转化目标');
        $colorUse = $paletteName !== '' ? $paletteName : \trim($primary . ($accent !== '' ? ' / ' . $accent : ''));
        $colorUse = $colorUse !== '' ? $colorUse : ($isEn ? 'the shared color scheme' : '共享色彩体系');
        $typeSpacing = \trim($typeRule . ($spacingRule !== '' ? ' / ' . $spacingRule : ''));
        $typeSpacing = $typeSpacing !== '' ? $typeSpacing : ($isEn ? 'the shared type, spacing, and radius rules' : '共享字体、间距和圆角规则');
        $tone = $tone !== '' ? $tone : ($isEn ? 'the shared voice' : '共享语气');
        $ctaTone = $ctaTone !== '' ? $ctaTone : ($isEn ? 'the shared CTA tone' : '共享 CTA 语气');
        $forbidden = $forbidden !== '' ? $forbidden : ($isEn ? 'off-theme visual styles' : '偏离主题的视觉风格');
        $blockSummary = $blockKeys !== []
            ? \implode($isEn ? ', ' : '、', \array_slice($blockKeys, 0, 4))
            : ($isEn ? 'all page blocks' : '全部页面区块');

        if ($isEn) {
            return \sprintf(
                '%s keeps the shared theme purpose "%s" while %s support "%s"; it applies %s, follows %s, keeps the %s voice and %s CTA rhythm, avoids %s, and preserves the Header/Footer handoff.',
                $pageLabel,
                $themePurpose,
                $blockSummary,
                $pageGoal,
                $colorUse,
                $typeSpacing,
                $tone,
                $ctaTone,
                $forbidden
            );
        }

        return \sprintf(
            '%s 继承共享主题目标“%s”；%s 服务页面目标“%s”。页面沿用 %s，遵守 %s，保持 %s 与 %s，并避免 %s，同时延续 Header/Footer 的承接关系。',
            $pageLabel,
            $themePurpose,
            $blockSummary,
            $pageGoal,
            $colorUse,
            $typeSpacing,
            $tone,
            $ctaTone,
            $forbidden
        );
    }

    /**
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $fallbackPlanJson
     * @return array<string, mixed>
     */
    private function sanitizePromptLikePlanJson(array $planJson, array $fallbackPlanJson): array
    {
        $normalized = $planJson;
        $normalized['site_strategy'] = $this->sanitizeStageOneTextSection(
            \is_array($normalized['site_strategy'] ?? null) ? $normalized['site_strategy'] : [],
            \is_array($fallbackPlanJson['site_strategy'] ?? null) ? $fallbackPlanJson['site_strategy'] : [],
            ['summary', 'core_goal', 'target_users', 'conversion_path']
        );
        $normalized['seo_strategy'] = $this->sanitizeStageOneTextSection(
            \is_array($normalized['seo_strategy'] ?? null) ? $normalized['seo_strategy'] : [],
            \is_array($fallbackPlanJson['seo_strategy'] ?? null) ? $fallbackPlanJson['seo_strategy'] : [],
            ['content_strategy', 'internal_linking', 'url_structure']
        );
        $normalized['pages'] = $this->sanitizeStageOnePages(
            \is_array($normalized['pages'] ?? null) ? $normalized['pages'] : [],
            \is_array($fallbackPlanJson['pages'] ?? null) ? $fallbackPlanJson['pages'] : []
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $fallbackSection
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function sanitizeStageOneTextSection(array $section, array $fallbackSection, array $keys): array
    {
        foreach ($keys as $key) {
            $candidate = \trim((string)($section[$key] ?? ''));
            if ($candidate === '' || !$this->isPromptLikeStageOneText($candidate)) {
                continue;
            }
            $fallback = \trim((string)($fallbackSection[$key] ?? ''));
            if ($fallback !== '') {
                $section[$key] = $fallback;
            }
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $pages
     * @param array<string, mixed> $fallbackPages
     * @return array<string, mixed>
     */
    private function sanitizeStageOnePages(array $pages, array $fallbackPages): array
    {
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $fallbackPage = \is_array($fallbackPages[$pageType] ?? null) ? $fallbackPages[$pageType] : [];
            $pageGoal = \trim((string)($page['page_goal'] ?? ''));
            if ($this->isWeakStageOnePageGoal($pageGoal, (string)$pageType)) {
                $fallbackGoal = \trim((string)($fallbackPage['page_goal'] ?? ''));
                $page['page_goal'] = !$this->isWeakStageOnePageGoal($fallbackGoal, (string)$pageType)
                    ? $fallbackGoal
                    : $this->buildStageOneConcretePageGoal(
                        (string)$pageType,
                        (string)($page['page_label'] ?? $fallbackPage['page_label'] ?? $page['page_title'] ?? $fallbackPage['page_title'] ?? ''),
                        ''
                    );
            }
            $themeAlignmentSummary = \trim((string)($page['theme_alignment_summary'] ?? ''));
            if ($themeAlignmentSummary !== '' && $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', (string)$pageType)) {
                $page['theme_alignment_summary'] = (string)($fallbackPage['theme_alignment_summary'] ?? $themeAlignmentSummary);
            }
            $page['blocks'] = $this->sanitizeStageOneBlocks(
                \is_array($page['blocks'] ?? null) ? $page['blocks'] : [],
                \is_array($fallbackPage['blocks'] ?? null) ? $fallbackPage['blocks'] : [],
                (string)$pageType
            );
            $pages[$pageType] = $page;
        }

        return $pages;
    }

    private function buildStageOneConcretePageGoal(string $pageType, string $pageLabel = '', string $locale = ''): string
    {
        $policyGoal = $this->resolveStageOnePolicyPageGoal($pageType, $locale);
        if ($policyGoal !== '') {
            return $policyGoal;
        }

        $resolvedLabel = \trim($pageLabel);
        if ($resolvedLabel === '') {
            $resolvedLabel = $this->resolveStageOnePageTypeLabel($pageType, $locale);
        }

        return $this->resolvePageGoal($pageType, $resolvedLabel, $locale);
    }

    private function resolveStageOnePolicyPageGoal(string $pageType, string $locale): string
    {
        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_REFUND_POLICY => 'Explain refund eligibility, timing, and request steps so customers can act with confidence.',
                Page::TYPE_PRIVACY_POLICY => 'Explain what data is collected, how it is used, and what control visitors keep.',
                Page::TYPE_TERMS_OF_SERVICE => 'Clarify usage rules, responsibilities, and account expectations before purchase or signup.',
                Page::TYPE_SHIPPING_POLICY => 'Set delivery timing, shipping regions, and exception handling expectations clearly.',
                Page::TYPE_COOKIE_POLICY => 'Explain what cookies are used, why they exist, and how visitors can manage consent.',
                default => '',
            };
        }

        return match ($pageType) {
            Page::TYPE_REFUND_POLICY => '退款政策清楚呈现适用条件、处理时效和申请路径，帮助客户判断并提交请求。',
            Page::TYPE_PRIVACY_POLICY => '隐私政策清楚呈现数据收集范围、使用方式和访客可保留的控制权。',
            Page::TYPE_TERMS_OF_SERVICE => '服务条款清楚呈现使用规则、责任边界和购买或注册前需要确认的事项。',
            Page::TYPE_SHIPPING_POLICY => '配送政策清楚呈现送达时效、覆盖区域和异常处理方式。',
            Page::TYPE_COOKIE_POLICY => 'Cookie 政策清楚呈现使用类型、用途和访客管理同意的方式。',
            default => '',
        };
    }

    private function isWeakStageOneBlockContent(string $text, string $blockKey = '', string $pageType = ''): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return true;
        }

        return \mb_stripos($normalized, 'content rendered for users') !== false;
    }

    private function isWeakStageOneCoreCopy(string $text, string $blockKey = '', string $pageType = ''): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return true;
        }

        return \mb_stripos($normalized, 'stays visible and actionable') !== false;
    }

    private function isWeakStageOneFieldSample(string $text, string $field = '', string $blockKey = '', string $pageType = ''): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return true;
        }

        return \mb_stripos($normalized, 'visible ') !== false && \mb_stripos($normalized, ' content for ') !== false;
    }

    private function buildStageOneConcreteBlockContent(string $blockKey, string $pageType, string $locale): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return $blockKey !== ''
                ? ($blockKey . ' 区块直接展示客户可读内容、关键信息和下一步动作。')
                : '该区块直接展示客户可读内容、关键信息和下一步动作。';
        }

        return $blockKey !== ''
            ? ('The ' . $blockKey . ' block shows concrete customer-facing details, trust signals, and the next action on the page.')
            : 'This block shows concrete customer-facing details, trust signals, and the next action on the page.';
    }

    private function buildStageOneConcreteCoreCopy(string $blockKey, string $pageType, string $locale): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return $blockKey !== ''
                ? ($blockKey . ' 区块用清晰文案说明用户会看到什么、为什么可信，以及下一步如何继续。')
                : '该区块用清晰文案说明用户会看到什么、为什么可信，以及下一步如何继续。';
        }

        return $blockKey !== ''
            ? ('The ' . $blockKey . ' block explains what customers get, why they can trust it, and which next step they can take now.')
            : 'This block explains what customers get, why they can trust it, and which next step they can take now.';
    }

    private function buildStageOneConcreteFieldSample(string $field, string $blockKey, string $pageType, string $locale): string
    {
        $field = \trim($field);
        if (!$this->isEnglishLocale($locale)) {
            return match (\mb_strtolower($field)) {
                'title', 'headline' => '清晰说明本区块的核心信息',
                'subtitle', 'summary', 'description' => '补充客户需要理解的条件、步骤或结果',
                'button_text', 'cta', 'cta_text' => '立即查看详情',
                default => $blockKey !== '' ? ($blockKey . ' 的可编辑客户文案') : '可编辑客户文案',
            };
        }

        return match (\mb_strtolower($field)) {
            'title', 'headline' => 'Clear headline for this customer-facing section',
            'subtitle', 'summary', 'description' => 'Supporting copy that explains the condition, step, or outcome customers need to understand',
            'button_text', 'cta', 'cta_text' => 'Review the next step',
            default => $blockKey !== '' ? ('Editable customer-facing copy for the ' . $blockKey . ' block') : 'Editable customer-facing copy',
        };
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $fallbackBlocks
     * @return list<array<string, mixed>>
     */
    private function sanitizeStageOneBlocks(array $blocks, array $fallbackBlocks, string $pageType): array
    {
        $fallbackByKey = [];
        foreach ($fallbackBlocks as $index => $fallbackBlock) {
            if (!\is_array($fallbackBlock)) {
                continue;
            }
            $fallbackKey = \trim((string)($fallbackBlock['block_key'] ?? ''));
            if ($fallbackKey !== '') {
                $fallbackByKey[$fallbackKey] = $fallbackBlock;
            }
            $fallbackByKey['#' . $index] ??= $fallbackBlock;
        }

        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? ''));
            $fallbackBlock = \is_array($fallbackByKey[$blockKey] ?? null)
                ? $fallbackByKey[$blockKey]
                : (\is_array($fallbackByKey['#' . $index] ?? null) ? $fallbackByKey['#' . $index] : []);
            $blocks[$index] = $this->sanitizeStageOneBlock($block, $fallbackBlock, $pageType);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $fallbackBlock
     * @return array<string, mixed>
     */
    private function sanitizeStageOneBlock(array $block, array $fallbackBlock, string $pageType): array
    {
        $template = \trim((string)($block['template'] ?? ''));
        $sectionName = \trim((string)($block['section_name'] ?? $block['section_code'] ?? $block['label'] ?? $block['block_key'] ?? ''));

        foreach (['goal', 'content', 'why'] as $key) {
            $candidate = \trim((string)($block[$key] ?? ''));
            if ($candidate === '' || !$this->isPromptLikeStageOneText($candidate, '', $template, $sectionName, $pageType)) {
                continue;
            }
            $fallback = \trim((string)($fallbackBlock[$key] ?? ''));
            if ($fallback !== '') {
                $block[$key] = $fallback;
            }
        }

        $block['field_plan'] = $this->sanitizeStageOneFieldPlan(
            \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
            \is_array($fallbackBlock['field_plan'] ?? null) ? $fallbackBlock['field_plan'] : [],
            $template,
            $sectionName,
            $pageType
        );
        $block['execution_script'] = $this->sanitizeStageOneExecutionScript(
            \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [],
            \is_array($fallbackBlock['execution_script'] ?? null) ? $fallbackBlock['execution_script'] : [],
            $template,
            $sectionName,
            $pageType
        );

        return $block;
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     * @param list<array<string, mixed>> $fallbackFieldPlan
     * @return list<array<string, mixed>>
     */
    private function sanitizeStageOneFieldPlan(
        array $fieldPlan,
        array $fallbackFieldPlan,
        string $template,
        string $sectionName,
        string $pageType
    ): array {
        $fallbackByField = [];
        foreach ($fallbackFieldPlan as $index => $fallbackRow) {
            if (!\is_array($fallbackRow)) {
                continue;
            }
            $field = \trim((string)($fallbackRow['field'] ?? ''));
            if ($field !== '') {
                $fallbackByField[$field] = $fallbackRow;
            }
            $fallbackByField['#' . $index] ??= $fallbackRow;
        }

        foreach ($fieldPlan as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            $fallbackRow = \is_array($fallbackByField[$field] ?? null)
                ? $fallbackByField[$field]
                : (\is_array($fallbackByField['#' . $index] ?? null) ? $fallbackByField['#' . $index] : []);

            $sample = \trim((string)($row['sample'] ?? ''));
            if ($sample === '' || $this->isPromptLikeStageOneText($sample, $field, $template, $sectionName, $pageType)) {
                $fallbackSample = \trim((string)($fallbackRow['sample'] ?? ''));
                if ($fallbackSample !== '') {
                    $row['sample'] = $fallbackSample;
                }
            }

            $implementationNote = $this->resolveStageOneFieldImplementationNote($row);
            if ($implementationNote === '' || $this->isPromptLikeStageOneText($implementationNote, $field, $template, $sectionName, $pageType)) {
                $fallbackImplementationNote = $this->resolveStageOneFieldImplementationNote($fallbackRow);
                if ($fallbackImplementationNote !== '') {
                    $implementationNote = $fallbackImplementationNote;
                }
            }
            $row = $this->syncStageOneFieldImplementationNote($row, $implementationNote);

            $fieldPlan[$index] = $row;
        }

        return $fieldPlan;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveStageOneFieldImplementationNote(array $row): string
    {
        foreach (['implementation_note', 'delivery_note'] as $key) {
            $value = \trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function syncStageOneFieldImplementationNote(array $row, string $implementationNote): array
    {
        $implementationNote = \trim($implementationNote);
        if ($implementationNote === '') {
            unset($row['implementation_note']);
            return $row;
        }

        $row['implementation_note'] = $implementationNote;
        unset($row['reason']);
        return $row;
    }

    /**
     * @param array<string, mixed> $executionScript
     * @param array<string, mixed> $fallbackExecutionScript
     * @return array<string, mixed>
     */
    private function sanitizeStageOneExecutionScript(
        array $executionScript,
        array $fallbackExecutionScript,
        string $template,
        string $sectionName,
        string $pageType
    ): array {
        $featurePoints = \is_array($executionScript['feature_points'] ?? null) ? $executionScript['feature_points'] : [];
        $fallbackFeaturePoints = \is_array($fallbackExecutionScript['feature_points'] ?? null) ? $fallbackExecutionScript['feature_points'] : [];
        $sanitizedFeaturePoints = [];
        foreach ($featurePoints as $point) {
            $text = \is_scalar($point) ? \trim((string)$point) : '';
            if ($text === '' || $this->isPromptLikeStageOneText($text, 'feature_points', $template, $sectionName, $pageType)) {
                continue;
            }
            $sanitizedFeaturePoints[] = $text;
        }
        $executionScript['feature_points'] = $sanitizedFeaturePoints !== [] ? $sanitizedFeaturePoints : $fallbackFeaturePoints;

        foreach (['core_copy', 'typography', 'style_tone', 'background_direction'] as $key) {
            $candidate = \trim((string)($executionScript[$key] ?? ''));
            if ($candidate === '' || !$this->isPromptLikeStageOneText($candidate, $key, $template, $sectionName, $pageType)) {
                continue;
            }
            $fallback = \trim((string)($fallbackExecutionScript[$key] ?? ''));
            if ($fallback !== '') {
                $executionScript[$key] = $fallback;
            }
        }

        $mediaAssets = \is_array($executionScript['media_assets'] ?? null) ? $executionScript['media_assets'] : [];
        $fallbackMediaAssets = \is_array($fallbackExecutionScript['media_assets'] ?? null) ? $fallbackExecutionScript['media_assets'] : [];
        $sanitizedMediaAssets = [];
        foreach ($mediaAssets as $asset) {
            $text = \is_scalar($asset) ? \trim((string)$asset) : '';
            if ($text === '' || $this->isPromptLikeStageOneText($text, 'media_assets', $template, $sectionName, $pageType)) {
                continue;
            }
            $sanitizedMediaAssets[] = $text;
        }
        $executionScript['media_assets'] = $sanitizedMediaAssets !== [] ? $sanitizedMediaAssets : $fallbackMediaAssets;

        return $executionScript;
    }

    /**
     * @param array<string, mixed> $i18n
     * @return array<string, mixed>
     */
    private function ensurePlanI18nSection(array $i18n, string $planLocale, bool $isEn): array
    {
        if (!$isEn) {
            $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
            $defaultLabels = [
                'title' => '阶段一执行方案（完整内容版）',
                'site' => '站点',
                'summary' => '摘要',
                'site_structure' => '全站结构',
                'shared_global_plan' => '全站共享规划',
                'page_details' => '页面与区块内容细化',
            ];
            foreach ($defaultLabels as $key => $value) {
                $candidate = \trim((string)($labels[$key] ?? ''));
                $labels[$key] = $candidate !== '' ? $candidate : $value;
            }

            return [
                'locale' => \trim((string)($i18n['locale'] ?? '')) !== '' ? (string)$i18n['locale'] : $planLocale,
                'labels' => $labels,
            ];
        }

        $defaultLabels = $isEn
            ? [
                'title' => 'Stage 1 Execution Plan (Full Blueprint)',
                'site' => 'Site',
                'summary' => 'Summary',
                'site_structure' => 'Site Structure',
                'shared_global_plan' => 'Shared Global Plan',
                'page_details' => 'Page And Block Execution Details',
            ]
            : [
                'title' => '阶段一执行蓝图（完整规划）',
                'site' => '站点',
                'summary' => '摘要',
                'site_structure' => '全站结构',
                'shared_global_plan' => '全站共享规划',
                'page_details' => '页面与区块执行细化',
            ];

        $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
        foreach ($defaultLabels as $key => $value) {
            $candidate = \trim((string)($labels[$key] ?? ''));
            $labels[$key] = $candidate !== '' ? $candidate : $value;
        }

        return [
            'locale' => \trim((string)($i18n['locale'] ?? '')) !== '' ? (string)$i18n['locale'] : $planLocale,
            'labels' => $labels,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return list<string>
     */
    private function expandPageTypes(array $scope): array
    {
        $rawPageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
        $pageTypes = [];
        foreach ($rawPageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && isset(Page::getPageTypes()[$pageType])) {
                $pageTypes[] = $pageType;
            }
        }
        if ($pageTypes === []) {
            $pageTypes = [Page::TYPE_HOME];
        }

        return \array_values(\array_unique($pageTypes));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildPalettePlan(array $scope, array $websiteProfile, string $instruction = ''): array
    {
        $brief = \mb_strtolower(\trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? '')));
        $instructionLower = \mb_strtolower($instruction);

        if ($this->containsAny($instructionLower, ['midnight ember', '深色', '暗色', '夜间', '高对比'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '当前指令明确倾向深色高对比方案，便于突出主 CTA、首屏价值和转化入口。',
            ];
        }

        if ($this->containsAny($brief, ['棋牌', 'casino', 'rummy', 'ludo', 'poker', 'aviator', 'satta', 'game', 'gaming'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '棋牌与下载转化场景更适合高对比深色底，便于突出按钮、福利信息和信任提示。',
            ];
        }

        if ($this->containsAny($brief, ['saas', 'finance', 'fintech', 'payment', 'data', 'dashboard', '软件', '金融', '支付'])) {
            return [
                'name' => 'Signal Blue',
                'primary' => '#0f172a',
                'accent' => '#0ea5e9',
                'secondary' => '#14b8a6',
                'surface' => '#e0f2fe',
                'text' => '#0f172a',
                'reason' => '信息型与专业型网站更适合蓝青配色，便于表达可靠、清晰与数据感。',
            ];
        }

        if ($this->containsAny($brief, ['fitness', 'health', 'wellness', 'sport', '健身', '健康', '运动'])) {
            return [
                'name' => 'Active Green',
                'primary' => '#052e16',
                'accent' => '#22c55e',
                'secondary' => '#84cc16',
                'surface' => '#f0fdf4',
                'text' => '#14532d',
                'reason' => '健康与活力类内容适合绿色体系，便于表达行动感与持续使用。',
            ];
        }

        if ($this->containsAny($brief . ' ' . $instructionLower, ['ai', 'plugin', 'plugins', 'extension', 'download', 'automation', 'developer tool', '插件', '下载', '工具'])) {
            return [
                'name' => 'Electric Circuit',
                'primary' => '#07111f',
                'accent' => '#38bdf8',
                'secondary' => '#a3e635',
                'surface' => '#ecfeff',
                'text' => '#0f172a',
                'reason' => 'AI/product-download briefs need a sharper utility-tech palette with luminous accents, dark depth, and clear action contrast.',
            ];
        }

        if ($this->containsAny($brief . ' ' . $instructionLower, ['ceramic', 'pottery', 'handmade', 'craft', 'aroma', 'candle', 'home fragrance', '陶瓷', '手作', '香薰', '礼物'])) {
            return [
                'name' => 'Warm Atelier',
                'primary' => '#5f2d1f',
                'accent' => '#d97706',
                'secondary' => '#0f766e',
                'surface' => '#fff7ed',
                'text' => '#3f1f16',
                'reason' => 'Craft and gift-oriented briefs benefit from warm tactile color, natural surfaces, and editorial contrast.',
            ];
        }

        if ($this->containsAny($brief . ' ' . $instructionLower, ['restaurant', 'coffee', 'bakery', 'food', 'tea', '餐厅', '咖啡', '烘焙', '茶'])) {
            return [
                'name' => 'Culinary Ink',
                'primary' => '#1f1308',
                'accent' => '#c2410c',
                'secondary' => '#facc15',
                'surface' => '#fffbeb',
                'text' => '#241509',
                'reason' => 'Food and hospitality briefs need appetite-driven warmth, high-contrast menu hierarchy, and tactile editorial surfaces.',
            ];
        }

        if ($this->containsAny($brief . ' ' . $instructionLower, ['fashion', 'beauty', 'luxury', 'jewelry', 'skincare', '美妆', '服装', '珠宝', '护肤', '奢侈'])) {
            return [
                'name' => 'Editorial Rose',
                'primary' => '#3b0a2a',
                'accent' => '#f472b6',
                'secondary' => '#f59e0b',
                'surface' => '#fff1f2',
                'text' => '#3b0a2a',
                'reason' => 'Fashion and beauty briefs need a more editorial palette with premium contrast, soft surfaces, and expressive accents.',
            ];
        }

        return [
            'name' => 'Ocean Slate',
            'primary' => '#0f172a',
            'accent' => '#2563eb',
            'secondary' => '#14b8a6',
            'surface' => '#f8fafc',
            'text' => '#0f172a',
            'reason' => '默认采用稳健的蓝灰体系，兼顾信息结构、内容承载与多行业适配性。',
        ];

    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $palette
     * @return array<string, mixed>
     */
    private function buildThemeStyle(array $scope, array $websiteProfile, array $palette, string $instruction = ''): array
    {
        $brief = \trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $paletteName = (string)($palette['name'] ?? 'Ocean Slate');
        $preset = match (\strtolower($paletteName)) {
            'electric circuit' => [
                'name' => 'Neon Utility Lab',
                'visual_tone' => 'sharp, luminous, product-led, download-focused',
                'font_family' => 'Sora, Manrope, Avenir Next, sans-serif',
                'style_signature' => 'dark utility dashboard with luminous circuit accents',
                'layout_direction' => 'high-contrast hero, floating plugin cards, proof band, and direct download CTA path',
                'component_density' => 'medium-high density with compact product facts, badges, and action states',
                'layout_motif' => 'circuit-grid overlays and modular plugin panels',
                'background_system' => 'deep ink gradients with cyan/lime glows and pale utility surfaces',
                'surface_treatment' => 'glass panels, bordered cards, and terminal-like detail strips',
                'visual_detail_rule' => 'use inline SVG plugin nodes, circuit traces, and status chips instead of generic illustrations',
                'motion_rule' => 'short reveal transitions, restrained hover lift, and clear focus states',
                'reason' => 'The AI/plugin/download requirement needs stronger utility-tech styling, visible product action, and a memorable non-generic visual system.',
            ],
            'warm atelier' => [
                'name' => 'Warm Atelier Editorial',
                'visual_tone' => 'warm, tactile, natural, gift-ready',
                'font_family' => 'Fraunces, Cormorant Garamond, Georgia, serif',
                'style_signature' => 'handmade atelier warmth with tactile editorial framing',
                'layout_direction' => 'large editorial product moments, warm paper surfaces, story panels, and soft CTA flow',
                'component_density' => 'airy density with larger imagery slots and slower reading rhythm',
                'layout_motif' => 'handmade labels, asymmetric still-life panels, and craft note cards',
                'background_system' => 'cream paper base, terracotta accents, subtle grain, and natural shadow bands',
                'surface_treatment' => 'soft cards, rounded clay-like corners, and framed image wells',
                'visual_detail_rule' => 'use line-drawn vessels, scent notes, stamps, and textured borders',
                'motion_rule' => 'gentle fade/slide motion only; avoid techy animations',
                'reason' => 'Craft and gift briefs need warmth, material texture, and a quieter premium rhythm.',
            ],
            'culinary ink' => [
                'name' => 'Culinary Ink House',
                'visual_tone' => 'warm, appetite-led, editorial, lively',
                'font_family' => 'Recoleta, Fraunces, Georgia, serif',
                'style_signature' => 'menu-editorial warmth with bold appetite accents',
                'layout_direction' => 'hero dish/story impact, menu cards, reservation/contact path, and local trust details',
                'component_density' => 'medium density with readable menus and strong CTA contrast',
                'layout_motif' => 'menu boards, ingredient chips, and framed tasting notes',
                'background_system' => 'warm parchment surfaces, toasted accents, and dark ink contrast sections',
                'surface_treatment' => 'paper cards, stamped labels, and tactile dividers',
                'visual_detail_rule' => 'use ingredient icons, menu rules, and CSS texture instead of stock food placeholders',
                'motion_rule' => 'small hover warmth and reservation CTA emphasis',
                'reason' => 'Food and hospitality sites need appetite, clarity, and tactile local atmosphere.',
            ],
            'editorial rose' => [
                'name' => 'Editorial Rose Studio',
                'visual_tone' => 'premium, expressive, editorial, polished',
                'font_family' => 'Canela, Playfair Display, Georgia, serif',
                'style_signature' => 'luxury editorial composition with rose-glow accents',
                'layout_direction' => 'bold editorial hero, curated collection cards, trust/service blocks, and elegant CTA moments',
                'component_density' => 'selective density with strong white space and premium detail',
                'layout_motif' => 'magazine crops, soft frames, badges, and asymmetric content rhythm',
                'background_system' => 'rose-tinted surfaces, deep plum contrast, and glossy accent strips',
                'surface_treatment' => 'elevated cards, fine borders, soft glows, and image-frame treatment',
                'visual_detail_rule' => 'use editorial labels, product swatches, and refined inline SVG details',
                'motion_rule' => 'subtle reveal, no busy effects; preserve premium restraint',
                'reason' => 'Fashion/beauty/luxury briefs need editorial polish and expressive but controlled visual hierarchy.',
            ],
            default => [
                'name' => 'Plan-Driven Hybrid',
                'visual_tone' => $brief !== '' ? 'credible, clear, conversion-ready, polished' : 'professional, stable, executable, polished',
                'font_family' => 'Sora, Manrope, Avenir Next, sans-serif',
                'style_signature' => 'brief-led polished editorial-product hybrid',
                'layout_direction' => 'first-screen value, proof points, and primary CTA form a clear top-to-bottom conversion path',
                'component_density' => 'medium density with enough visual detail to avoid a template feel',
                'layout_motif' => 'layered panels, clear proof cards, and action-focused content bands',
                'background_system' => 'alternating surfaces, subtle gradients, and contrast bands inside the approved palette',
                'surface_treatment' => 'scoped cards, soft depth, visible hover/focus states, and consistent radius rhythm',
                'visual_detail_rule' => 'use CSS shapes or inline SVG motifs tied to the brief; never rely on blank stock placeholders',
                'motion_rule' => 'restrained reveal and hover motion with accessible focus states',
                'reason' => 'This style keeps the plan readable while adding enough art direction for a polished generated website.',
            ],
        };
        if ($instruction !== '') {
            $preset['reason'] = 'This round adapts the visual strategy to the user instruction: ' . $this->clipText($instruction, 120);
        }
        $preset['palette_name'] = $paletteName;

        return $preset;
    }

    private function buildNavigationPlan(array $pageTypes, string $contentLocale = '', array $scope = []): array
    {
        $routeContract = $this->resolveStageOnePageRouteContract($pageTypes, $contentLocale, $scope);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        $allItems = $this->buildStageOneRouteItemsForTypes($pageTypes, $routesByType, $contentLocale);
        $headerRouteTypes = $this->routeTypesForStageOneLinkGroup($routeContract, 'navigation_plan.header_items');
        $headerItems = $this->buildStageOneRouteItemsForTypes($headerRouteTypes, $routesByType, $contentLocale);
        if ($headerItems === []) {
            $headerItems = \array_slice($allItems, 0, 5);
        }

        return [
            'header_items' => $headerItems,
            'all_items' => $allItems,
            'why' => 'Header uses the page route contract so every generated link maps to an actual selected page.',
        ];

        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $allItems[] = [
                'type' => $pageType,
                'label' => $this->resolveStageOnePageTypeLabel((string)$pageType, $contentLocale),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
        }

        $headerItems = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            foreach ($allItems as $item) {
                if ((string)($item['type'] ?? '') === $type) {
                    $headerItems[] = $item;
                    break;
                }
            }
        }
        foreach ($allItems as $item) {
            if (\count($headerItems) >= 5) {
                break;
            }
            $exists = false;
            foreach ($headerItems as $headerItem) {
                if ((string)($headerItem['type'] ?? '') === (string)($item['type'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $headerItems[] = $item;
            }
        }

        return [
            'header_items' => $headerItems,
            'all_items' => $allItems,
            'why' => 'Header 需要在首屏建立路径感，让访客能在首页、品牌说明和咨询入口之间快速切换。',
        ];

        $labels = Page::getPageTypes();
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $allItems[] = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
        }

        $headerItems = [];
        foreach ([Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_BLOG_LIST, Page::TYPE_CONTACT] as $type) {
            foreach ($allItems as $item) {
                if ((string)($item['type'] ?? '') === $type) {
                    $headerItems[] = $item;
                    break;
                }
            }
        }
        foreach ($allItems as $item) {
            if (\count($headerItems) >= 5) {
                break;
            }
            $exists = false;
            foreach ($headerItems as $headerItem) {
                if ((string)($headerItem['type'] ?? '') === (string)($item['type'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $headerItems[] = $item;
            }
        }

        return [
            'header_items' => $headerItems,
            'all_items' => $allItems,
            'why' => 'Header ?????????????????????????????',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildFooterPlan(array $pageTypes, string $contentLocale = '', array $scope = []): array
    {
        $routeContract = $this->resolveStageOnePageRouteContract($pageTypes, $contentLocale, $scope);
        $routesByType = $this->getPageRouteContractService()->routesByType($routeContract);
        $allItems = $this->buildStageOneRouteItemsForTypes($pageTypes, $routesByType, $contentLocale);
        $featured = $this->buildStageOneRouteItemsForTypes(
            $this->routeTypesForStageOneLinkGroup($routeContract, 'footer_plan.featured'),
            $routesByType,
            $contentLocale
        );
        $policies = $this->buildStageOneRouteItemsForTypes(
            $this->routeTypesForStageOneLinkGroup($routeContract, 'footer_plan.policies'),
            $routesByType,
            $contentLocale
        );
        if ($policies === []) {
            $policies = $this->buildStageOneRouteItemsForTypes(
                \array_values(\array_filter([Page::TYPE_CONTACT, Page::TYPE_ABOUT, Page::TYPE_HOME], static fn(string $type): bool => isset($routesByType[$type]))),
                $routesByType,
                $contentLocale
            );
        }

        return [
            'featured' => $featured !== [] ? $featured : \array_slice($allItems, 0, 4),
            'policies' => $policies,
            'all_items' => $allItems,
            'why' => 'Footer uses the page route contract so policy and featured links only target selected pages.',
        ];

        $featured = [];
        $policies = [];
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $item = [
                'type' => $pageType,
                'label' => $this->resolveStageOnePageTypeLabel((string)$pageType, $contentLocale),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
            $allItems[] = $item;
            if (\in_array($pageType, [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST], true)) {
                $featured[] = $item;
            }
            if (\in_array($pageType, [Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $policies[] = $item;
            }
        }

        return [
            'featured' => $featured !== [] ? $featured : \array_slice($allItems, 0, 4),
            'policies' => $policies,
            'all_items' => $allItems,
            'why' => 'Footer 负责补齐政策链接、联系入口和二级导航，也承担 SEO 内链承接作用。',
        ];

        $labels = Page::getPageTypes();
        $featured = [];
        $policies = [];
        $allItems = [];
        foreach ($pageTypes as $pageType) {
            $item = [
                'type' => $pageType,
                'label' => (string)($labels[$pageType] ?? $pageType),
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            ];
            $allItems[] = $item;
            if (\in_array($pageType, [Page::TYPE_HOME, Page::TYPE_ABOUT, Page::TYPE_CONTACT, Page::TYPE_BLOG_LIST], true)) {
                $featured[] = $item;
            }
            if (\in_array($pageType, [Page::TYPE_PRIVACY_POLICY, Page::TYPE_TERMS_OF_SERVICE, Page::TYPE_COOKIE_POLICY, Page::TYPE_REFUND_POLICY, Page::TYPE_SHIPPING_POLICY], true)) {
                $policies[] = $item;
            }
        }

        return [
            'featured' => $featured !== [] ? $featured : \array_slice($allItems, 0, 4),
            'policies' => $policies,
            'all_items' => $allItems,
            'why' => 'Footer ???????????????????????? SEO ??????',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @return list<array{type:string,label:string,href:string}>
     */
    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveStageOnePageRouteContract(array $pageTypes, string $contentLocale = '', array $scope = []): array
    {
        $raw = \is_array($scope['page_route_contract'] ?? null)
            ? $scope['page_route_contract']
            : (\is_array($scope['stage1_contract']['page_route_contract'] ?? null) ? $scope['stage1_contract']['page_route_contract'] : []);

        return $this->getPageRouteContractService()->normalize($raw, $pageTypes, $scope, $contentLocale);
    }

    /**
     * @param list<string>|array<int|string, mixed> $pageTypes
     * @param array<string, array<string, string>> $routesByType
     * @return list<array{type:string,label:string,href:string}>
     */
    private function buildStageOneRouteItemsForTypes(array $pageTypes, array $routesByType, string $contentLocale = ''): array
    {
        $items = [];
        $seen = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '' || isset($seen[$pageType]) || !\is_array($routesByType[$pageType] ?? null)) {
                continue;
            }
            $route = $routesByType[$pageType];
            $label = $this->resolveStageOnePageTypeLabel($pageType, $contentLocale);
            if ($label === '') {
                $label = \trim((string)($route['label'] ?? $pageType));
            }
            $href = \trim((string)($route['path'] ?? ''));
            if ($href === '') {
                continue;
            }
            $seen[$pageType] = true;
            $items[] = [
                'type' => $pageType,
                'label' => $label,
                'href' => $href,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $routeContract
     * @return list<string>
     */
    private function routeTypesForStageOneLinkGroup(array $routeContract, string $fieldPath): array
    {
        $linkGroup = \is_array($routeContract['link_groups'][$fieldPath] ?? null) ? $routeContract['link_groups'][$fieldPath] : [];
        $routeTypes = \is_array($linkGroup['route_types'] ?? null) ? $linkGroup['route_types'] : [];
        if ($routeTypes === []) {
            $fallbackKey = match ($fieldPath) {
                'navigation_plan.header_items' => 'header_route_types',
                'footer_plan.featured' => 'footer_featured_route_types',
                'footer_plan.policies' => 'footer_policy_route_types',
                default => '',
            };
            $routeTypes = $fallbackKey !== '' && \is_array($routeContract[$fallbackKey] ?? null) ? $routeContract[$fallbackKey] : [];
        }

        $normalized = [];
        foreach ($routeTypes as $routeType) {
            $routeType = \trim((string)$routeType);
            if ($routeType !== '' && !\in_array($routeType, $normalized, true)) {
                $normalized[] = $routeType;
            }
        }

        return $normalized;
    }

    private function disabledFooterPolicyLinkDefaults(array $pageTypes, string $contentLocale = ''): array
    {
        return [];

        $isEnglish = $this->isEnglishLocale($contentLocale);
        $links = [];
        if (\in_array(Page::TYPE_CONTACT, $pageTypes, true)) {
            $links[] = [
                'type' => 'support',
                'label' => $isEnglish ? 'Support and Safety' : '客服与安全说明',
                'href' => '/' . Page::getDefaultHandleForType(Page::TYPE_CONTACT),
            ];
        }
        if (\in_array(Page::TYPE_ABOUT, $pageTypes, true)) {
            $links[] = [
                'type' => 'trust',
                'label' => $isEnglish ? 'Trust and Compliance' : '信任与合规',
                'href' => '/' . Page::getDefaultHandleForType(Page::TYPE_ABOUT) . '#trust',
            ];
        }
        $links[] = [
            'type' => 'security',
            'label' => $isEnglish ? 'Download Safety' : '下载安全说明',
            'href' => '/',
        ];

        return \array_slice($links, 0, 3);
    }

    private function resolveStageOnePageTypeLabel(string $pageType, string $contentLocale = ''): string
    {
        if ($this->isEnglishLocale($contentLocale)) {
            $englishLabels = [
                Page::TYPE_HOME => 'Home',
                Page::TYPE_ABOUT => 'About',
                Page::TYPE_CONTACT => 'Contact',
                Page::TYPE_PRIVACY_POLICY => 'Privacy Policy',
                Page::TYPE_TERMS_OF_SERVICE => 'Terms of Service',
                Page::TYPE_REFUND_POLICY => 'Refund Policy',
                Page::TYPE_SHIPPING_POLICY => 'Shipping Policy',
                Page::TYPE_COOKIE_POLICY => 'Cookie Policy',
                Page::TYPE_BLOG => 'Blog Post',
                Page::TYPE_BLOG_CATEGORY => 'Blog Category',
                Page::TYPE_BLOG_LIST => 'Blog',
                Page::TYPE_CUSTOM => 'Custom Page',
            ];

            return $englishLabels[$pageType] ?? \ucwords(\str_replace('_', ' ', $pageType));
        }

        return (string)(Page::getPageTypes()[$pageType] ?? $pageType);
    }

    private function normalizeStageOneVisibleTextLocale(string $value, string $fallback, string $contentLocale = ''): string
    {
        $value = \trim($value);
        if ($this->isEnglishLocale($contentLocale) && \preg_match('/\p{Han}/u', $value) === 1) {
            return \trim($fallback);
        }

        return $value !== '' ? $value : \trim($fallback);
    }

    /**
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildSeoStrategy(string $siteDisplayName, array $scope, array $pageTypes, string $instruction = ''): array
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $baseKeywords = \array_values(\array_filter([
            $siteDisplayName,
            $brief !== '' ? $this->clipText($brief, 20) : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 官网') : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' 首页') : '',
        ], static fn(string $value): bool => \trim($value) !== ''));

        return [
            'core_intent' => '围绕站点核心价值建立首页承接、页面分发与咨询转化的搜索结构。',
            'primary_keywords' => \array_values(\array_unique($baseKeywords)),
            'keyword_page_map' => $this->buildSeoKeywordPageMap($baseKeywords, $pageTypes),
            'page_type_count' => \count($pageTypes),
            'meta_rule' => '标题优先承接主词与页面价值，description 用自然短句说明利益点与下一步动作。',
            'content_strategy' => $instruction !== '' ? ('本轮 SEO 内容重点同步用户补充说明：' . $this->clipText($instruction, 80)) : '每个页面都要围绕页面目标给出可读内容、关键词承接和清晰内链。',
            'internal_linking' => '首页串联 About、FAQ、Contact 等页面，区块内 CTA 与锚点同步承担内链分发。',
            'url_structure' => '保持简洁的英文 slug，首页为 /，其他页面采用稳定的语义化路径。',
        ];

        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $baseKeywords = \array_values(\array_filter([
            $siteDisplayName,
            $brief !== '' ? $this->clipText($brief, 20) : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' ??') : '',
            $siteDisplayName !== '' ? ($siteDisplayName . ' ??') : '',
        ], static fn(string $value): bool => \trim($value) !== ''));

        return [
            'core_intent' => '?????????????????????????????????????',
            'primary_keywords' => \array_values(\array_unique($baseKeywords)),
            'keyword_page_map' => $this->buildSeoKeywordPageMap($baseKeywords, $pageTypes),
            'page_type_count' => \count($pageTypes),
            'meta_rule' => '???????????????????????????description ????????????????',
            'content_strategy' => $instruction !== '' ? '?? SEO ????????????' . $this->clipText($instruction, 80) : '????????????????????????????',
            'internal_linking' => '????????????????????FAQ?????????????',
            'url_structure' => '???????????????? URL slug?',
        ];
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $pageTypes
     * @return list<array{keyword:string,page_type:string,reason:string}>
     */
    private function buildSeoKeywordPageMap(array $keywords, array $pageTypes): array
    {
        $pageTypes = \array_values(\array_filter(
            \array_map('strval', $pageTypes),
            static fn(string $pageType): bool => \trim($pageType) !== ''
        ));
        if ($pageTypes === []) {
            $pageTypes = [Page::TYPE_HOME];
        }

        $keywords = \array_values(\array_filter(
            \array_map('strval', $keywords),
            static fn(string $keyword): bool => \trim($keyword) !== ''
        ));
        if ($keywords === []) {
            $keywords = ['website'];
        }

        $map = [];
        foreach ($keywords as $index => $keyword) {
            $pageType = $pageTypes[$index % \count($pageTypes)];
            $map[] = [
                'keyword' => $keyword,
                'page_type' => $pageType,
                'reason' => 'Map this keyword to the page that can satisfy its search intent with visible content and internal links.',
            ];
        }

        foreach ($pageTypes as $pageType) {
            $hasPage = false;
            foreach ($map as $row) {
                if ((string)($row['page_type'] ?? '') === $pageType) {
                    $hasPage = true;
                    break;
                }
            }
            if ($hasPage) {
                continue;
            }
            $map[] = [
                'keyword' => $this->resolveStageOnePageTypeLabel($pageType, ''),
                'page_type' => $pageType,
                'reason' => 'Ensure every generated page has at least one SEO intent anchor.',
            ];
        }

        return $map;
    }

    private function buildPagePlan(
        string $pageType,
        array $pageBlueprint,
        array $pageTypes,
        string $siteDisplayName,
        string $siteSummary,
        array $palette,
        array $themeStyle,
        string $instruction = '',
        string $targetScope = '',
        string $locale = ''
    ): array {
        $pageLabel = $this->isEnglishLocale($locale)
            ? $this->resolveStageOnePageTypeLabel($pageType, $locale)
            : (string)($pageBlueprint['page_label'] ?? (Page::getPageTypes()[$pageType] ?? $pageType));
        $pageTitle = (string)($pageBlueprint['page_title'] ?? $pageLabel);
        if ($this->isEnglishLocale($locale) && \preg_match('/\p{Han}/u', $pageTitle) === 1) {
            $pageTitle = $pageLabel;
        }
        $pageGoal = $this->resolvePageGoal($pageType, $pageLabel, $locale);
        $blocks = [];
        foreach (\is_array($pageBlueprint['sections'] ?? null) ? $pageBlueprint['sections'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $blocks[] = $this->buildBlockPlan(
                $pageType,
                $pageLabel,
                $pageGoal,
                $section,
                $palette,
                $themeStyle,
                $siteDisplayName,
                $siteSummary,
                $locale
            );
        }

        $appendInstruction = $this->resolveAppendBlockInstruction($instruction, $targetScope, $pageType, $blocks);
        if ($appendInstruction !== null) {
            $appendBlock = $this->buildAppendedBlockPlan($appendInstruction, $pageType, $pageLabel, $pageGoal, $palette, $themeStyle, $locale);
            $insertAfter = \trim((string)($appendInstruction['insert_after'] ?? ''));
            if ($insertAfter !== '') {
                $inserted = false;
                foreach ($blocks as $idx => $block) {
                    if (!\is_array($block)) {
                        continue;
                    }
                    $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
                    $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
                    $componentKind = \mb_strtolower(\trim((string)($block['component_kind'] ?? '')));
                    if ($blockKey === $insertAfter || $sectionCode === $insertAfter || $componentKind === $insertAfter) {
                        \array_splice($blocks, $idx + 1, 0, [$appendBlock]);
                        $inserted = true;
                        break;
                    }
                }
                if (!$inserted) {
                    $blocks[] = $appendBlock;
                }
            } else {
                $blocks[] = $appendBlock;
            }
            \usort($blocks, static fn(array $left, array $right): int => ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0)));
        }

        $internalLinks = [];
        foreach ($pageTypes as $candidateType) {
            if ($candidateType === $pageType) {
                continue;
            }
            $internalLinks[] = [
                'type' => $candidateType,
                'label' => $this->resolveStageOnePageTypeLabel((string)$candidateType, $locale),
                'href' => $candidateType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($candidateType),
            ];
            if (\count($internalLinks) >= 4) {
                break;
            }
        }

        return [
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_title' => $pageTitle,
            'page_goal' => $pageGoal,
            'theme_alignment_summary' => $this->buildPageThemeAlignmentSummary($pageLabel, $pageGoal, $blocks, $palette, $themeStyle, $locale),
            'why' => $this->resolvePageWhy($pageType, $pageLabel, $locale),
            'decision_reason' => $instruction !== ''
                ? '页面策略按本轮补充说明对齐：' . $this->clipText($instruction, 80)
                : '页面策略基于已选页面类型与站点目标自动生成。',
            'slug' => $pageType === Page::TYPE_HOME ? '/' : '/' . Page::getDefaultHandleForType($pageType),
            'nav_label' => $pageLabel,
            'meta_title' => $this->normalizeStageOneVisibleTextLocale((string)($pageBlueprint['meta_title'] ?? $pageTitle), $pageTitle, $locale),
            'meta_description' => $this->normalizeStageOneVisibleTextLocale((string)($pageBlueprint['meta_description'] ?? ''), $siteSummary, $locale),
            'primary_keywords' => $this->buildPageKeywords($pageTitle, $pageLabel, $siteDisplayName),
            'secondary_keywords' => $this->buildSecondaryKeywords($pageType, $pageLabel),
            'internal_links' => $internalLinks,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     */
    private function buildPageThemeAlignmentSummary(
        string $pageLabel,
        string $pageGoal,
        array $blocks,
        array $palette,
        array $themeStyle,
        string $locale = ''
    ): string {
        $isEn = $this->isEnglishLocale($locale);
        $paletteName = \trim((string)($palette['name'] ?? ''));
        $visualTone = \trim((string)($themeStyle['visual_tone'] ?? ''));
        $blockKeys = \array_values(\array_filter(\array_map(
            static fn(array $block): string => \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')),
            $blocks
        ), static fn(string $value): bool => $value !== ''));
        $blockSummary = $blockKeys === []
            ? ($isEn ? 'the page blocks' : '页面区块')
            : \implode($isEn ? ', ' : '、', \array_slice($blockKeys, 0, 4));
        $paletteLabel = $paletteName !== '' ? $paletteName : ($isEn ? 'the shared color system' : '共享色系');
        $toneLabel = $visualTone !== '' ? $visualTone : ($isEn ? 'the shared voice' : '共享语气');

        if ($isEn) {
            return \sprintf(
                '%s follows shared_prompt_context (%s and %s): %s support the page goal "%s", reuse the shared CTA/trust rhythm, and hand off cleanly from Header navigation to Footer reassurance.',
                $pageLabel !== '' ? $pageLabel : 'This page',
                $paletteLabel,
                $toneLabel,
                $blockSummary,
                $pageGoal
            );
        }

        return \sprintf(
            '%s 遵守 shared_prompt_context（%s 与 %s）：%s 服务“%s”这一页面目标，延续共享 CTA 与信任表达，并从 Header 导航自然承接到 Footer 的补充背书。',
            $pageLabel !== '' ? $pageLabel : '本页面',
            $paletteLabel,
            $toneLabel,
            $blockSummary,
            $pageGoal
        );
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function resolveAppendBlockInstruction(string $instruction, string $targetScope, string $pageType, array $blocks): ?array
    {
        $appendType = null;
        $aiDecision = $this->resolveAppendInstructionByAi($instruction, $targetScope);
        if ($this->isAiAppendDecisionMatchPage($aiDecision, $pageType)) {
            $appendType = \trim((string)($aiDecision['append_type'] ?? ''));
            if (!\in_array($appendType, ['partner', 'about_intro', 'why_choose_us', 'custom'], true)) {
                $appendType = 'custom';
            }
        } elseif ($this->shouldAppendPartnerBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'partner';
        } elseif ($this->shouldAppendAboutBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'about_intro';
        } elseif ($this->shouldAppendWhyChooseUsBlock($instruction, $targetScope, $pageType)) {
            $appendType = 'why_choose_us';
        }
        if ($appendType !== null) {
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
                $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
                $componentKind = \mb_strtolower(\trim((string)($block['component_kind'] ?? '')));
                if (
                    ($appendType === 'partner' && ($blockKey === 'partner' || $sectionCode === 'partner' || $componentKind === 'partner'))
                    || ($appendType === 'about_intro' && (
                        \str_contains($blockKey, 'about')
                        || \str_contains($sectionCode, 'about')
                        || \str_contains($componentKind, 'about')
                    ))
                    || ($appendType === 'why_choose_us' && (
                        \str_contains($blockKey, 'why_choose')
                        || \str_contains($sectionCode, 'why_choose')
                        || \str_contains($componentKind, 'why_choose')
                        || \str_contains($blockKey, 'advantage')
                        || \str_contains($sectionCode, 'advantage')
                        || \str_contains($componentKind, 'advantage')
                    ))
                ) {
                    return null;
                }
            }

            return [
                'type' => $appendType,
                'instruction' => $instruction,
                'insert_after' => $this->containsAny(\mb_strtolower($instruction), ['hero下面', 'hero 后', 'hero后', 'after hero']) ? 'hero' : '',
            ];
        }

        return null;
    }

    /**
     * 由 AI 判定微调指令的真实意图，避免把“新增模块”逻辑硬编码在关键词表里。
     *
     * @return array<string, mixed>|null
     */
    private function resolveAppendInstructionByAi(string $instruction, string $targetScope): ?array
    {
        $instruction = \trim($instruction);
        if ($instruction === '') {
            return null;
        }
        $cacheKey = \md5($instruction . '|' . \trim($targetScope));
        if (\array_key_exists($cacheKey, $this->appendInstructionDecisionCache)) {
            return $this->appendInstructionDecisionCache[$cacheKey];
        }

        $prompt = \implode("\n", [
            'You are an intent classifier for PageBuilder refine instructions.',
            'Return STRICT JSON only. Do not output markdown.',
            'Schema:',
            '{',
            '  "action": "append_block|none",',
            '  "append_type": "about_intro|why_choose_us|partner|custom|none",',
            '  "target_page_type": "home_page|about_page|contact_page|all|auto",',
            '  "confidence": 0.0,',
            '  "decision_note": "classifier-only note; never copy this key into page/block/build contracts"',
            '}',
            'Rules:',
            '- If user asks to add/insert/join a section/module/block, action must be append_block.',
            '- Infer append_type by semantics, not fixed keywords only.',
            '- If target page is not explicit, use auto.',
            '- confidence in [0,1].',
            'Examples (copy the decision pattern, not the text): ' . $this->stageOneAppendInstructionClassifierExamplesJson(),
            'Instruction: ' . $instruction,
            'Target scope: ' . (\trim($targetScope) !== '' ? $targetScope : '-'),
        ]);
        $prompt = $this->getSkillRegistry()->prependPromptGuide($prompt, 'stage1');

        try {
            $raw = (string)$this->getAiService()->generate(
                $prompt,
                null,
                'pagebuilder_plan_generation',
                null,
                [
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.0,
                    'max_tokens' => 500,
                    'timeout' => 60,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                $this->appendInstructionDecisionCache[$cacheKey] = null;
                return null;
            }
            $action = \trim((string)($decoded['action'] ?? ''));
            if ($action !== 'append_block') {
                $this->appendInstructionDecisionCache[$cacheKey] = null;
                return null;
            }
            $this->appendInstructionDecisionCache[$cacheKey] = $decoded;
            return $decoded;
        } catch (\Throwable) {
            $this->appendInstructionDecisionCache[$cacheKey] = null;
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $decision
     */
    private function isAiAppendDecisionMatchPage(?array $decision, string $pageType): bool
    {
        if (!$decision) {
            return false;
        }
        $targetPageType = \trim((string)($decision['target_page_type'] ?? 'auto'));
        if ($targetPageType === '' || $targetPageType === 'auto' || $targetPageType === 'all') {
            return $pageType === Page::TYPE_HOME;
        }
        return $targetPageType === $pageType;
    }

    /**
     * @param array<string, mixed> $appendInstruction
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildAppendedBlockPlan(
        array $appendInstruction,
        string $pageType,
        string $pageLabel,
        string $pageGoal,
        array $themeStyle,
        array $palette,
        string $locale
    ): array {
        $instructionText = \trim((string)($appendInstruction['instruction'] ?? ''));
        $blockType = \trim((string)($appendInstruction['type'] ?? 'custom'));
        $blockKey = $blockType !== '' ? $blockType : 'custom';

        return [
            'block_key' => $blockKey,
            'section_code' => $blockKey,
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 980,
            'goal' => $pageGoal,
            'planning_note' => $this->isEnglishLocale($locale)
                ? ('Append a focused content block for ' . $pageLabel . ' based on the latest instruction.')
                : ('????????? ' . $pageLabel . ' ???????????????'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule('content', $locale),
                'responsive_rule' => $this->resolveResponsiveRule('content', $locale),
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'implementation_note' => $this->isEnglishLocale($locale)
                    ? 'Use the current palette to keep the appended block visually consistent.'
                    : '???????????????????????',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => [],
                'anchors' => [],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $pageGoal,
                'implementation_note' => $this->isEnglishLocale($locale)
                    ? 'Keep the appended block concise, useful, and conversion-oriented.'
                    : '????????????????????????',
                'headline_direction' => $this->resolveCustomAppendHeadlineDirection($appendInstruction, $locale),
                'body_direction' => $this->resolveCustomAppendBodyDirection($appendInstruction, $locale),
                'cta_direction' => $this->resolveCtaDirection([], 'content', 'custom', $pageGoal, $pageLabel, '', '', $locale),
            ],
            'field_plan' => [
                [
                    'field' => 'title',
                    'sample' => $this->resolveCustomAppendTitleSample($appendInstruction),
                    'implementation_note' => $this->isEnglishLocale($locale)
                        ? 'Use the title as the visible section label so the client can confirm the appended block purpose immediately.'
                        : '标题直接作为新增区块的可见识别名，方便客户确认新增内容的用途。',
                ],
                [
                    'field' => 'description',
                    'sample' => $instructionText !== '' ? $instructionText : ($this->isEnglishLocale($locale) ? 'Add supporting details for this section.' : '???????????????'),
                    'implementation_note' => $this->isEnglishLocale($locale)
                        ? 'Fill this area with the actual supporting details that will appear in the block, not with writing guidance.'
                        : '这里直接写会上屏的补充内容，不写写作提示或方向说明。',
                ],
            ],
            'result_ref' => [],
        ];
    }

    private function resolveCustomAppendTitleSample(array $appendInstruction): string
    {
        $instruction = \trim((string)($appendInstruction['instruction'] ?? ''));
        if ($instruction === '') {
            return '';
        }
        // 从「新增/添加 … 区块|模块|板块|区域」类指令抽取标题样例（正则须完整闭合）。
        if (\preg_match(
            '/(?:新增|添加)(?:一段|一块|一节|一条)?\s*([^，。、\s]{2,20}(?:区块|模块|板块|区域))/u',
            $instruction,
            $matches
        )) {
            return \trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    /**
     * @param array<string, mixed> $appendInstruction
     */
    private function resolveCustomAppendHeadlineDirection(array $appendInstruction, string $locale): string
    {
        $titleSample = $this->resolveCustomAppendTitleSample($appendInstruction);
        if ($titleSample !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Use "' . $titleSample . '" as section headline.')
                : ('新增区块标题使用“' . $titleSample . '”。');
        }
        return $this->isEnglishLocale($locale) ? 'Add one clear value headline.' : '补充一个明确价值标题。';
    }

    /**
     * @param array<string, mixed> $appendInstruction
     */
    private function resolveCustomAppendBodyDirection(array $appendInstruction, string $locale): string
    {
        $instruction = \trim((string)($appendInstruction['instruction'] ?? ''));
        if ($instruction !== '') {
            return $this->isEnglishLocale($locale)
                ? ('Follow this refine instruction strictly: ' . $this->clipText($instruction, 100))
                : ('严格按用户微调说明生成该区块：' . $this->clipText($instruction, 100));
        }
        return $this->isEnglishLocale($locale) ? 'Explain added value in short paragraph.' : '用短段落解释新增价值。';
    }

    private function shouldAppendPartnerBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, ['合作伙伴', '合作品牌', '合作方', 'partner', 'brand logo', 'logo wall'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }

        return $pageType === Page::TYPE_HOME && \str_contains($scope, 'page');
    }

    private function shouldAppendAboutBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, ['关于我们', 'about us', 'about', 'company intro', '品牌介绍'])) {
            return false;
        }
        if (!$this->containsAny($instructionLower, ['添加', '新增', '加入', 'append', 'add', 'insert'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }

        return $pageType === Page::TYPE_HOME && (\str_contains($scope, 'home') || \str_contains($scope, '首页') || \str_contains($scope, 'page'));
    }

    private function shouldAppendWhyChooseUsBlock(string $instruction, string $targetScope, string $pageType): bool
    {
        $instructionLower = \mb_strtolower(\trim($instruction));
        if (!$this->containsAny($instructionLower, [
            '为什么选择我们',
            '为什么选我们',
            '选择我们',
            '我们的优势',
            '核心优势',
            'why choose us',
            'why us',
            'our advantages',
            'key advantages',
        ])) {
            return false;
        }
        if (!$this->containsAny($instructionLower, ['添加', '新增', '加入', '加一个', 'append', 'add', 'insert'])) {
            return false;
        }
        if ($targetScope === '') {
            return $pageType === Page::TYPE_HOME;
        }
        $scope = \mb_strtolower($targetScope);
        if (\str_contains($scope, $pageType)) {
            return true;
        }
        return $pageType === Page::TYPE_HOME && (\str_contains($scope, 'home') || \str_contains($scope, '首页') || \str_contains($scope, 'page'));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function buildPartnerBlockPlan(string $pageType, string $pageLabel, array $palette): array
    {
        return [
            'block_key' => 'partner',
            'section_code' => 'partner',
            'region' => 'content',
            'component_kind' => 'partner',
            'order' => 990,
            'goal' => '通过合作品牌或信任标识增强站点可信度。',
            'why' => $pageLabel . ' 需要补充品牌背书，让用户在继续浏览前先建立基础信任。',
            'style_brief' => [
                'visual_tone' => '可信、整洁、偏展示型',
                'layout_rule' => '桌面端 4-6 列 Logo 墙，移动端 2 列排列。',
                'responsive_rule' => '移动端保留 Logo 清晰度与留白，不挤压标题说明。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '合作品牌区块应以浅底承载 Logo，避免干扰主导航与主 CTA。',
            ],
            'seo_brief' => [
                'intent' => '展示合作品牌与信任背书。',
                'keywords' => ['合作品牌', '品牌背书', '信任标识'],
                'anchors' => ['#partner'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '补充可见的品牌背书内容。',
                'why' => '让访客快速感知合作可信度。',
                'headline_direction' => '合作品牌与信任背书',
                'body_direction' => '展示合作品牌 Logo、合作方向或平台认证，让用户快速建立基本信任。',
                'cta_direction' => '',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '合作品牌与信任背书', 'reason' => '让用户快速知道本区块用途。'],
                ['field' => 'description', 'sample' => '以下展示的是当前重点合作品牌、合作平台或可信任标识，可作为后续品牌物料的替换位。', 'reason' => '给出客户可直接确认的说明文案。'],
            ],
            'result_ref' => [],
        ];

        return [
            'block_key' => 'partner',
            'section_code' => 'partner',
            'region' => 'content',
            'component_kind' => 'partner',
            'order' => 990,
            'goal' => '?????????????????????????',
            'why' => $pageLabel . ' ????????????????? Logo ??????',
            'style_brief' => [
                'visual_tone' => '??????????????????? CTA?',
                'layout_rule' => '????? 4-6 ? Logo ????????? 2 ??',
                'responsive_rule' => '???? Logo ???????????????',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '?????????????? Logo????????',
            ],
            'seo_brief' => [
                'intent' => '??????????????',
                'keywords' => ['????', '????', '????'],
                'anchors' => ['#partner'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '??????????????????',
                'why' => '??????????????',
                'headline_direction' => '?????????',
                'body_direction' => '?????? Logo????????????????????????????',
                'cta_direction' => '??????',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '?????????', 'reason' => '???????????'],
                ['field' => 'description', 'sample' => '????????????????????????????', 'reason' => '?????????'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAboutIntroBlockPlan(string $pageType, string $pageLabel, array $palette, string $locale = ''): array
    {
        return [
            'block_key' => 'about_intro',
            'section_code' => 'about_intro',
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 985,
            'goal' => '补充品牌介绍入口，帮助用户更快理解站点背景。',
            'why' => $this->isEnglishLocale($locale)
                ? ('Add an about-intro block to help users understand ' . $pageLabel . ' faster.')
                : ('为 ' . $pageLabel . ' 补充一段品牌介绍入口，帮助用户更快理解站点背景。'),
            'style_brief' => [
                'visual_tone' => '可信、简洁、偏信息型',
                'layout_rule' => '标题与正文上下布局，桌面端可增加一张辅助图片。',
                'responsive_rule' => '移动端优先保留标题、正文与主按钮，辅助图像放到文字下方。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '介绍型内容更适合浅底与清晰文字层级，保证阅读效率。',
            ],
            'seo_brief' => [
                'intent' => '解释品牌背景与价值定位。',
                'keywords' => ['品牌介绍', '关于我们', '团队背景'],
                'anchors' => ['#about-intro'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '给出品牌介绍的直接文案样例。',
                'why' => '让访客更快理解品牌与服务边界。',
                'headline_direction' => '认识我们正在做的事情',
                'body_direction' => '用一段简洁说明介绍品牌定位、服务对象和希望用户继续了解的理由。',
                'cta_direction' => '按钮可引导到关于我们或咨询入口。',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '认识我们正在做的事情', 'reason' => '明确区块主题。'],
                ['field' => 'description', 'sample' => '我们希望用更清晰的内容结构、可信的说明方式和明确的下一步动作，帮助用户更快理解品牌并继续深入浏览。', 'reason' => '给出可直接上屏的品牌介绍文案。'],
            ],
            'result_ref' => [],
        ];

        return [
            'block_key' => 'about_intro',
            'section_code' => 'about_intro',
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 985,
            'goal' => '???????????????????',
            'why' => $this->isEnglishLocale($locale)
                ? ('Add an about-intro block to help users understand ' . $pageLabel . ' faster.')
                : ('????????????????? ' . $pageLabel . ' ???????'),
            'style_brief' => [
                'visual_tone' => '???????????',
                'layout_rule' => '??????????????????????',
                'responsive_rule' => '???????????? CTA ???',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '????????????????????',
            ],
            'seo_brief' => [
                'intent' => '???????????????',
                'keywords' => ['????', '????', '????'],
                'anchors' => ['#about-intro'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => '????????????????????????',
                'why' => '?????????????????',
                'headline_direction' => '????????????',
                'body_direction' => '?????????????????????????????',
                'cta_direction' => '????',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '????????????', 'reason' => '???????????'],
                ['field' => 'description', 'sample' => '?????????????????????????????????', 'reason' => '??????????????'],
            ],
            'result_ref' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWhyChooseUsBlockPlan(string $pageType, string $pageLabel, array $palette, string $locale = ''): array
    {
        return [
            'block_key' => 'why_choose_us',
            'section_code' => 'why_choose_us',
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 987,
            'goal' => '用具体理由说明为什么用户应继续选择我们。',
            'why' => $this->isEnglishLocale($locale)
                ? ('Explain why users should choose ' . $pageLabel . ' over alternatives.')
                : ('解释为什么用户在比较后仍然应该继续选择 ' . $pageLabel . '。'),
            'style_brief' => [
                'visual_tone' => '可信、明确、偏对比型',
                'layout_rule' => '使用 3-4 个理由卡片或列表并列呈现。',
                'responsive_rule' => '移动端按单列堆叠，每条理由保留独立标题。',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '对比型区块需要清晰分组和重点强调色，但不能喧宾夺主。',
            ],
            'seo_brief' => [
                'intent' => '解释核心优势与选择理由。',
                'keywords' => ['为什么选择我们', '核心优势', '服务亮点'],
                'anchors' => ['#why-choose-us'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/contact' : '/'],
            ],
            'content_brief' => [
                'goal' => '列出 3-4 条可以直接展示的选择理由。',
                'why' => '帮助用户在关键决策前完成判断。',
                'headline_direction' => '为什么用户会继续选择我们',
                'body_direction' => '用可感知的结果、服务体验或支持能力来解释差异点。',
                'cta_direction' => '结尾可承接咨询或了解更多。',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '为什么用户会继续选择我们', 'reason' => '让区块目的明确可见。'],
                ['field' => 'description', 'sample' => '我们更强调上手清晰、过程透明、支持及时和结果可确认，让用户在浏览时就能理解继续了解的价值。', 'reason' => '给出能直接展示的价值说明。'],
            ],
            'result_ref' => [],
        ];

        return [
            'block_key' => 'why_choose_us',
            'section_code' => 'why_choose_us',
            'region' => 'content',
            'component_kind' => 'content',
            'order' => 987,
            'goal' => '????????????????????',
            'why' => $this->isEnglishLocale($locale)
                ? ('Explain why users should choose ' . $pageLabel . ' over alternatives.')
                : ('??????????????????? ' . $pageLabel . '?'),
            'style_brief' => [
                'visual_tone' => '??????????',
                'layout_rule' => '?? 3-4 ????????????',
                'responsive_rule' => '??????????????????',
            ],
            'palette_usage' => [
                'background' => (string)($palette['surface'] ?? '#ffffff'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'text' => (string)($palette['text'] ?? '#0f172a'),
                'reason' => '????????????????????? CTA?',
            ],
            'seo_brief' => [
                'intent' => '???????????????',
                'keywords' => ['???????', '????', '????'],
                'anchors' => ['#why-choose-us'],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/contact' : '/'],
            ],
            'content_brief' => [
                'goal' => '??????? 3-4 ??????',
                'why' => '???????????????????????',
                'headline_direction' => '???????????',
                'body_direction' => '?????????????????????????????',
                'cta_direction' => '????',
            ],
            'field_plan' => [
                ['field' => 'title', 'sample' => '???????????', 'reason' => '???????????'],
                ['field' => 'description', 'sample' => '????????????????????????????????????', 'reason' => '????????????'],
            ],
            'result_ref' => [],
        ];
    }

    private function buildBlockPlan(
        string $pageType,
        string $pageLabel,
        string $pageGoal,
        array $section,
        array $palette,
        array $themeStyle,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): array {
        $sectionKey = \trim((string)($section['key'] ?? 'block'));
        $sectionCode = \trim((string)($section['code'] ?? $sectionKey));
        $template = \trim((string)($section['template'] ?? 'content'));
        $sectionName = \trim((string)($section['name'] ?? $sectionCode));
        $config = \is_array($section['config'] ?? null) ? $section['config'] : [];
        $fieldPlan = $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName, $siteSummary, $pageLabel);

        return [
            'block_key' => $sectionKey,
            'section_code' => $sectionCode,
            'region' => 'content',
            'component_kind' => $template,
            'order' => (int)($section['sort_order'] ?? 0),
            'goal' => $this->resolveBlockGoal($template, $pageGoal, $locale),
            'implementation_detail' => $this->resolveLayoutRule($template, $locale),
            'why' => $this->isEnglishLocale($locale)
                ? ($sectionName . ' breaks the page goal of "' . $pageLabel . '" into actionable, scannable, and linkable content.')
                : ($sectionName . ' 用来把“' . $pageLabel . '”页面目标拆成可浏览、可转化、可内链的实际内容块。'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule($template, $locale),
                'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            ],
            'palette_usage' => [
                'background' => $template === 'cta' ? (string)($palette['primary'] ?? '') : (string)($palette['surface'] ?? ''),
                'accent' => (string)($palette['accent'] ?? ''),
                'text' => $template === 'cta' ? (string)($palette['text'] ?? '#ffffff') : '#0f172a',
                'reason' => $template === 'hero'
                    ? '首屏需要更高对比度来承接核心关键词与主 CTA。'
                    : '内容区块使用更轻的底色，便于承载可读文本与信任信息。',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
                'anchors' => ['#' . $this->slugify($sectionKey !== '' ? $sectionKey : $sectionCode)],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $sectionName . ' 要同时服务信息理解和下一步动作。',
                'headline_direction' => $this->resolveHeadlineDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale),
                'body_direction' => $this->resolveBodyDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale),
                'cta_direction' => $this->resolveCtaDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale),
            ],
            'field_plan' => $fieldPlan,
            'realtime_content' => $this->buildBlockRealtimeContent($config, $sectionName, $pageGoal, $template, $pageLabel, $siteDisplayName, $siteSummary, $locale),
            'editable_fields' => $this->extractEditableFieldsFromFieldPlan($fieldPlan),
            'content_source' => ['safe_inference', 'editable_field', 'media_manager'],
            'style_direction' => (string)($themeStyle['visual_tone'] ?? ''),
            'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            'completion_rule' => $this->isEnglishLocale($locale)
                ? 'Block is complete when content fields, CTA, media slot, and responsive behavior are all defined for implementation.'
                : '当内容字段、CTA、素材位和响应式行为都明确后，该区块才算完整。',
            'execution_script' => $this->buildBlockExecutionScript($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale),
            'result_ref' => [],
        ];

        return [
            'block_key' => $sectionKey,
            'section_code' => $sectionCode,
            'region' => 'content',
            'component_kind' => $template,
            'order' => (int)($section['sort_order'] ?? 0),
            'goal' => $this->resolveBlockGoal($template, $pageGoal, $locale),
            'implementation_detail' => $this->resolveLayoutRule($template, $locale),
            'why' => $this->isEnglishLocale($locale)
                ? ($sectionName . ' breaks the page goal of "' . $pageLabel . '" into actionable, scannable, and linkable content.')
                : ($sectionName . ' 用来把“' . $pageLabel . '”页面目标拆成可浏览、可转化、可内链的实际内容块。'),
            'style_brief' => [
                'visual_tone' => (string)($themeStyle['visual_tone'] ?? ''),
                'layout_rule' => $this->resolveLayoutRule($template, $locale),
                'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            ],
            'palette_usage' => [
                'background' => $template === 'cta' ? (string)($palette['primary'] ?? '') : (string)($palette['surface'] ?? ''),
                'accent' => (string)($palette['accent'] ?? ''),
                'text' => $template === 'cta' ? (string)($palette['text'] ?? '#ffffff') : '#0f172a',
                'reason' => $template === 'hero'
                    ? '首屏需要更高对比度来承接核心关键词和主 CTA。'
                    : '内容区块使用更轻的底色，便于承载 SEO 文案和可读信息层级。',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
                'anchors' => ['#' . $this->slugify($sectionKey !== '' ? $sectionKey : $sectionCode)],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'keywords' => $this->buildBlockKeywords($siteDisplayName, $pageLabel, $template, $sectionName),
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $sectionName . ' 要同时服务信息理解和下一步动作。',
                'headline_direction' => $this->resolveHeadlineDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $locale),
                'body_direction' => $this->resolveBodyDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $locale),
                'cta_direction' => $this->resolveCtaDirection($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $locale),
            ],
            'field_plan' => $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName, $pageLabel),
            'realtime_content' => $this->buildBlockRealtimeContent($config, $sectionName, $pageGoal, $template, $pageLabel, $siteDisplayName, $locale),
            'editable_fields' => $this->extractEditableFieldsFromFieldPlan($this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName, $pageLabel)),
            'content_source' => ['safe_inference', 'editable_field', 'media_manager'],
            'style_direction' => (string)($themeStyle['visual_tone'] ?? ''),
            'responsive_rule' => $this->resolveResponsiveRule($template, $locale),
            'completion_rule' => $this->isEnglishLocale($locale) ? 'Block is complete when content fields, CTA, media slot, and responsive behavior are all defined for implementation.' : '当内容字段、CTA、素材位和响应式行为都明确后，该区块才算完整。',
            'execution_script' => $this->buildBlockExecutionScript($config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $locale),
            'result_ref' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildSharedTask(
        string $component,
        string $siteDisplayName,
        array $payload,
        array $palette,
        array $themeStyle,
        array $seoStrategy,
        string $contentLocale = ''
    ): array {
        $isHeader = $component === 'header';
        $isEn = $this->isEnglishLocale($contentLocale);
        $brandName = $siteDisplayName !== '' ? $siteDisplayName : ($isEn ? 'Brand name' : '品牌名称');
        $headerCopy = $isEn
            ? [
                'goal' => 'Output ready-to-render site header content and carry the primary navigation plus main CTA.',
                'implementation_detail' => 'Use horizontal navigation and one primary button on desktop; collapse navigation on mobile while keeping the brand name and primary CTA visible.',
                'supporting_copy' => ['Home', 'About', 'Download Now'],
                'cta_label' => 'Download Now',
                'media_rule' => 'Use a replaceable brand logo slot with clear recognition.',
                'responsive_rule' => 'On mobile, keep the brand name and primary CTA visible while navigation collapses into a menu.',
                'completion_rule' => 'Header is complete when brand identity, navigation, primary CTA, and mobile behavior are defined.',
            ]
            : [
                'goal' => '输出可直接上屏的站点头部内容，并承接主导航与主 CTA。',
                'implementation_detail' => '桌面端使用横向导航与单一主按钮，移动端折叠菜单但保留品牌名和主 CTA。',
                'supporting_copy' => ['首页', '关于我们', '立即咨询'],
                'cta_label' => '立即咨询',
                'media_rule' => '使用可替换的品牌 Logo 位，保持清晰识别度。',
                'responsive_rule' => '移动端优先保留品牌名与主 CTA，导航折叠为菜单。',
                'completion_rule' => 'Header 完整条件是品牌识别、导航、主 CTA 与移动端规则都已明确。',
            ];
        $footerCopy = $isEn
            ? [
                'goal' => 'Output ready-to-render footer content with contact paths, policy links, and secondary navigation.',
                'implementation_detail' => 'Group footer information with priority for contact paths, common pages, and policy links.',
                'headline' => 'Continue with ' . $brandName,
                'supporting_copy' => ['Quick links', 'Policies', 'Contact', 'Support'],
                'media_rule' => 'Information groups may use lightweight icons without overpowering text.',
                'responsive_rule' => 'On mobile, stack footer groups in one column while keeping policy and contact links easy to tap.',
                'completion_rule' => 'Footer is complete when information groups, policy links, contact fields, and responsive behavior are defined.',
            ]
            : [
                'goal' => '输出可直接上屏的页脚内容，补齐联系入口、政策链接与次级导航。',
                'implementation_detail' => '页脚按信息分组呈现，优先展示联系入口、常用页面和政策链接。',
                'headline' => '继续了解 ' . $brandName,
                'supporting_copy' => ['快速入口', '政策说明', '联系渠道', '客服支持'],
                'media_rule' => '信息分组可搭配轻量图标，但不要压过文字内容。',
                'responsive_rule' => '移动端按分组单列堆叠，确保政策与联系入口仍然清晰可点。',
                'completion_rule' => 'Footer 完整条件是信息分组、政策链接、联系字段与响应式规则都已明确。',
            ];

        return [
            'task_key' => 'shared:' . $component,
            'task_type' => 'shared_component',
            'component' => $component,
            'sort_order' => $isHeader ? 10 : 20,
            'goal' => $isHeader ? $headerCopy['goal'] : $footerCopy['goal'],
            'implementation_detail' => $isHeader
                ? $headerCopy['implementation_detail']
                : $footerCopy['implementation_detail'],
            'realtime_content' => $isHeader
                ? [
                    'headline' => $brandName,
                    'supporting_copy' => $headerCopy['supporting_copy'],
                    'cta' => [['label' => $headerCopy['cta_label'], 'target' => '']],
                    'media' => [['kind' => 'logo', 'rule' => $headerCopy['media_rule']]],
                    'data_slots' => ['brand_name', 'navigation_items', 'primary_cta'],
                    'editable_slots' => ['brand_name', 'logo', 'navigation_items', 'primary_cta'],
                ]
                : [
                    'headline' => $footerCopy['headline'],
                    'supporting_copy' => $footerCopy['supporting_copy'],
                    'cta' => [],
                    'media' => [['kind' => 'icon', 'rule' => $footerCopy['media_rule']]],
                    'data_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                    'editable_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                ],
            'editable_fields' => $isHeader
                ? ['brand_name', 'logo', 'navigation_items', 'primary_cta', 'mobile_menu_rule']
                : ['footer_links', 'policy_links', 'contact_fields', 'social_links'],
            'content_source' => ['theme_context_snapshot', 'shared_prompt_context', 'editable_field'],
            'style_direction' => (string)($themeStyle['visual_tone'] ?? ''),
            'responsive_rule' => $isHeader
                ? $headerCopy['responsive_rule']
                : $footerCopy['responsive_rule'],
            'completion_rule' => $isHeader
                ? $headerCopy['completion_rule']
                : $footerCopy['completion_rule'],
            'content_locale' => $contentLocale,
            'site_display_name' => $siteDisplayName,
            'style_brief' => [
                'palette' => $palette,
                'theme_style' => $themeStyle,
            ],
            'seo_brief' => $seoStrategy,
            'payload' => $payload,
            'status' => 'pending',
        ];

        return [
            'task_key' => 'shared:' . $component,
            'task_type' => 'shared_component',
            'component' => $component,
            'goal' => $isHeader ? '??????????????? CTA?' : '???????????????????',
            'implementation_detail' => $isHeader
                ? '????????????? CTA?????????????????'
                : '?????????????????????????????????',
            'realtime_content' => $isHeader
                ? [
                    'headline' => '??? / Logo / ????',
                    'supporting_copy' => ['???', '? CTA', '???????'],
                    'cta' => [['label' => '????', 'target' => '']],
                    'media' => [['kind' => 'logo', 'rule' => '?????? logo ????']],
                    'data_slots' => ['brand_name', 'navigation_items', 'primary_cta'],
                    'editable_slots' => ['brand_name', 'logo', 'navigation_items', 'primary_cta'],
                ]
                : [
                    'headline' => '?????',
                    'supporting_copy' => ['????', '????', '????', '????'],
                    'cta' => [],
                    'media' => [['kind' => 'icon', 'rule' => '????? icon ??']],
                    'data_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                    'editable_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                ],
            'editable_fields' => $isHeader
                ? ['brand_name', 'logo', 'navigation_items', 'primary_cta', 'mobile_menu_rule']
                : ['footer_links', 'policy_links', 'contact_fields', 'social_links'],
            'content_source' => ['theme_context_snapshot', 'shared_prompt_context', 'editable_field'],
            'style_direction' => (string)($themeStyle['visual_tone'] ?? ''),
            'responsive_rule' => $isHeader
                ? '?????????????????? CTA?'
                : '???????????????????????????',
            'completion_rule' => $isHeader
                ? 'Header ?????????????????CTA???????????'
                : 'Footer ?????????????????????????????',
            'site_display_name' => $siteDisplayName,
            'style_brief' => [
                'palette' => $palette,
                'theme_style' => $themeStyle,
            ],
            'seo_brief' => $seoStrategy,
            'payload' => $payload,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildPageTask(string $pageType, array $pagePlan, array $block): array
    {
        $blockKey = \trim((string)($block['block_key'] ?? 'block'));
        $pagePromptContext = \is_array($pagePlan['page_prompt_context'] ?? null) ? $pagePlan['page_prompt_context'] : [];
        $sharedContextHash = \trim((string)($pagePlan['shared_context_hash'] ?? ''));
        if ($sharedContextHash === '') {
            throw new \RuntimeException('Page task "' . $pageType . ':' . $blockKey . '" missing shared_context_hash.');
        }

        return [
            'task_key' => 'page:' . $pageType . ':' . $blockKey,
            'task_type' => 'page_block',
            'page_type' => $pageType,
            'page_label' => (string)($pagePlan['page_label'] ?? $pageType),
            'slug' => (string)($pagePlan['slug'] ?? '/'),
            'source_ref' => [
                'page_key' => $pageType,
                'block_key' => $blockKey,
                'shared_context_hash' => $sharedContextHash,
                'theme_context_hash' => (string)($pagePlan['theme_context_hash'] ?? ''),
            ],
            'sort_order' => (int)($block['sort_order'] ?? $block['order'] ?? 0),
            'prompt_context' => $pagePromptContext,
            'implementation_detail' => (string)($block['implementation_detail'] ?? $block['style_brief']['layout_rule'] ?? ''),
            'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
            'reason' => (string)($block['why'] ?? ''),
            'completion_rule' => (string)($block['completion_rule'] ?? ''),
            'block' => $block,
            'status' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> $executionBlueprint
     */
    private function buildExecutionBlueprintSignature(array $executionBlueprint): string
    {
        return \sha1((string)\json_encode($executionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function buildExecutionSteps(array $tasks): array
    {
        $steps = [];
        foreach ($tasks as $index => $task) {
            $steps[] = [
                'step' => $index + 1,
                'task_key' => (string)($task['task_key'] ?? 'task:' . ($index + 1)),
                'task_type' => (string)($task['task_type'] ?? 'unknown'),
                'status' => 'pending',
            ];
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildDerivedScopePatch(
        array $scope,
        array $websiteProfile,
        array $structured,
        array $executionBlueprint
    ): array {
        $themeDesign = [
            'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? $websiteProfile['site_tagline'] ?? ''),
            'theme_style' => $structured['theme_style'] ?? [],
            'palette' => $structured['palette'] ?? [],
            'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
            'source_truth_contract_hash' => (string)($scope['source_truth_contract_hash'] ?? ''),
            'asset_manifest_hash' => (string)($scope['asset_manifest_hash'] ?? ''),
            'reference_image_insights' => \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [],
            'reference_image_insights_signature' => (string)($scope['reference_image_insights_signature'] ?? ''),
            'theme_design' => $this->extractStageOneThemeDesign(
                \is_array($structured['shared_plan']['theme_design'] ?? null)
                    ? $structured['shared_plan']['theme_design']
                    : (\is_array($structured['theme_context_snapshot'] ?? null) ? $structured['theme_context_snapshot'] : [])
            ),
            'plan_workbench' => $this->buildPlanWorkbenchArtifacts(
                $scope,
                $structured,
                $executionBlueprint,
                $this->buildPlanJson($structured),
                $this->buildMarkdownPlan($this->buildPlanJson($structured), (string)($structured['i18n']['locale'] ?? $scope['plan_locale'] ?? '')),
                (string)($structured['i18n']['locale'] ?? $scope['plan_locale'] ?? '')
            ),
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
        ];

        $themeDesign['theme_design'] = $this->applyReferenceImageInsightsToThemeDesign(
            \is_array($themeDesign['theme_design'] ?? null) ? $themeDesign['theme_design'] : [],
            $scope
        );
        $planJson = $this->buildPlanJson($structured);
        $stageOneContract = \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [];
        if ($stageOneContract === []) {
            $stageOneContract = \array_replace($this->getStageOneContractService()->build(
                $scope,
                \is_array($structured['page_types'] ?? null) ? \array_values($structured['page_types']) : [],
                (string)($structured['i18n']['locale'] ?? $scope['plan_locale'] ?? ''),
                (string)($structured['content_locale'] ?? ''),
                'final'
            ), [
                'status' => 'theme_archived',
                'theme_design' => \is_array($themeDesign['theme_design'] ?? null) ? $themeDesign['theme_design'] : [],
                'theme_context_snapshot' => \is_array($structured['theme_context_snapshot'] ?? null) ? $structured['theme_context_snapshot'] : [],
                'shared_components' => \is_array($structured['shared_components'] ?? null) ? $structured['shared_components'] : [],
                'shared_prompt_context' => \is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : [],
                'signature' => (string)($executionBlueprint['signature'] ?? ''),
            ]);
        }
        $themeDesign['stage1_contract'] = $stageOneContract;
        $themeDesign['stage1_validation_report'] = \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [];
        $themeDesign['stage1_first_pass'] = (int)($planJson['stage1_first_pass'] ?? 0);
        $themeDesign['stage1_generation_attempts'] = \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [];

        return $themeDesign;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param list<string> $pageTypes
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @param array<string, mixed> $navigationPlan
     * @param array<string, mixed> $footerPlan
     * @param array<string, mixed> $seoStrategy
     * @return array<string, mixed>
     */
    private function buildStageOneThemeContextSnapshot(
        array $scope,
        array $websiteProfile,
        string $siteDisplayName,
        string $siteSummary,
        array $palette,
        array $themeStyle,
        array $navigationPlan,
        array $footerPlan,
        array $seoStrategy,
        array $pageTypes,
        string $instruction
    ): array {
        $themeDesign = $this->buildStageOneThemeDesign(
            $scope,
            $websiteProfile,
            $siteDisplayName,
            $siteSummary,
            $palette,
            $themeStyle,
            $instruction
        );
        $contentLocale = $this->resolveStageOneContentLocale($scope, (string)($scope['plan_locale'] ?? ''));
        $isContentEnglish = $this->isEnglishLocale($contentLocale);
        $snapshot = [
            'theme_context_id' => 'stage1-theme-' . \substr(\sha1($siteDisplayName . '|' . \implode(',', $pageTypes)), 0, 12),
            'content_locale' => $contentLocale,
            'site_positioning' => $siteSummary,
            'site_display_name' => $siteDisplayName,
            'visual_direction' => $themeStyle,
            'content_tone' => (string)($themeStyle['visual_tone'] ?? ''),
            'palette' => $palette,
            'shared_navigation_strategy' => $navigationPlan,
            'shared_footer_strategy' => $footerPlan,
            'shared_cta_strategy' => [
                'primary_action' => $isContentEnglish ? 'Contact / Start now' : '立即咨询 / 立即开始',
                'reason' => '共享 CTA 必须在 Header、Footer 与页面核心区块中保持同一转化方向。',
            ],
            'seo_strategy' => $seoStrategy,
            'page_types' => $pageTypes,
            'anti_hardcode_rules' => [
                'brand_name' => '品牌名必须来自用户输入、站点标题或冻结方案；不要显示 brand_name/site_title 等字段名。',
                'contact' => '联系方式文案必须是面向访客的最终表达；禁止显示占位邮箱、断裂邮箱、phone/email/address 字段名或方案说明。',
                'cases' => '案例、资质、价格和客户名可以按方案语义写成营销文案；禁止展示“真实案例/资质/价格待补充”等蓝图式提示。',
            ],
            'source_instruction' => $instruction,
        ];

        return $this->mergeStageOneThemeDesignIntoSnapshot($snapshot, $themeDesign);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $palette
     * @param array<string, mixed> $themeStyle
     * @return array<string, mixed>
     */
    private function buildStageOneThemeDesign(
        array $scope,
        array $websiteProfile,
        string $siteDisplayName,
        string $siteSummary,
        array $palette,
        array $themeStyle,
        string $instruction
    ): array {
        $rawRequirement = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $requirementReference = $rawRequirement !== ''
            ? $rawRequirement
            : ($instruction !== '' ? $instruction : ($siteSummary !== '' ? $siteSummary : $siteDisplayName));
        $themePurpose = $siteSummary !== ''
            ? $siteSummary
            : ($requirementReference !== '' ? $requirementReference : '建立清晰可信、可转化的站点视觉与内容骨架。');
        $visualTone = \trim((string)($themeStyle['visual_tone'] ?? '专业、清晰、可转化'));

        $themeDesign = [
            'theme_purpose' => $themePurpose,
            'style_signature' => (string)($themeStyle['style_signature'] ?? (($themeStyle['name'] ?? 'Theme') . ' visual identity')),
            'art_direction' => [
                'layout_motif' => (string)($themeStyle['layout_motif'] ?? 'layered content panels and action-focused visual rhythm'),
                'background_system' => (string)($themeStyle['background_system'] ?? 'alternating surfaces, subtle gradients, and contrast bands inside the approved palette'),
                'surface_treatment' => (string)($themeStyle['surface_treatment'] ?? 'cards, panels, soft depth, and consistent radius rhythm'),
                'visual_detail_rule' => (string)($themeStyle['visual_detail_rule'] ?? 'use inline SVG/CSS motifs tied to the customer brief, not generic stock placeholders'),
                'motion_rule' => (string)($themeStyle['motion_rule'] ?? 'restrained reveal, hover, and focus states with accessible motion'),
            ],
            'color_scheme' => [
                'name' => (string)($palette['name'] ?? 'Ocean Slate'),
                'primary' => (string)($palette['primary'] ?? '#0f172a'),
                'secondary' => (string)($palette['secondary'] ?? $palette['accent'] ?? '#14b8a6'),
                'accent' => (string)($palette['accent'] ?? '#2563eb'),
                'background' => (string)($palette['surface'] ?? '#f8fafc'),
                'body' => (string)($palette['text'] ?? '#0f172a'),
                'button' => (string)($palette['primary'] ?? $palette['accent'] ?? '#2563eb'),
            ],
            'typography_spacing_radius' => [
                'font_family' => (string)($themeStyle['font_family'] ?? 'Poppins, Inter, sans-serif'),
                'heading_scale' => '首屏标题 40-56px，二级标题 28-36px，移动端按 0.78 倍收敛。',
                'body_scale' => '正文 16-18px，行高 1.6，适合长段客户方案说明。',
                'spacing_scale' => '以 8px 为基础栅格，区块上下留白 48-96px。',
                'radius_scale' => '卡片 16-24px，按钮 999px 胶囊或 12px 圆角，保持统一。',
            ],
            'visual_keywords' => \array_values(\array_filter([
                $visualTone,
                (string)($themeStyle['name'] ?? ''),
                (string)($palette['name'] ?? ''),
                '结构清晰',
                'CTA 突出',
            ], static fn(string $value): bool => \trim($value) !== '')),
            'tone_of_voice' => $visualTone !== '' ? $visualTone : '可信、清晰、可转化',
            'cta_tone' => '动作明确、低犹豫成本，优先承接用户需求中的下一步行为。',
            'forbidden_styles' => [
                '禁止只写“现代/高级/简洁”等空泛风格标签。',
                '禁止伪造未提供的品牌事实、资质、客户名、价格或联系方式。',
                '禁止输出与用户一句话需求无关的通用模板感方案。',
            ],
            'selection_reason' => $requirementReference !== ''
                ? '围绕用户需求“' . $this->clipText($requirementReference, 120) . '”，选择该主题以优先保证可读信息结构、明确 CTA 和可执行内容落地。'
                : '选择该主题以优先保证可读信息结构、明确 CTA 和可执行内容落地。',
        ];

        return $this->applyReferenceImageInsightsToThemeDesign($themeDesign, $scope);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $themeDesign
     * @return array<string, mixed>
     */
    private function mergeStageOneThemeDesignIntoSnapshot(array $snapshot, array $themeDesign): array
    {
        $themeDesign = $this->extractStageOneThemeDesign($themeDesign);
        foreach ($themeDesign as $key => $value) {
            $snapshot[$key] = $value;
        }
        $snapshot['theme_design'] = $themeDesign;
        unset($snapshot['context_hash']);
        $snapshot['context_hash'] = \sha1((string)\json_encode($snapshot, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function extractStageOneThemeDesign(array $candidate): array
    {
        $themeDesign = \is_array($candidate['theme_design'] ?? null) ? $candidate['theme_design'] : $candidate;
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $textColor = \trim((string)($colorScheme['text'] ?? ''));
        $bodyColor = \trim((string)($colorScheme['body'] ?? ''));
        if ($textColor === '' && $bodyColor !== '') {
            $colorScheme['text'] = $bodyColor;
        }
        if ($bodyColor === '' && $textColor !== '') {
            $colorScheme['body'] = $textColor;
        }
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $visualKeywords = \is_array($themeDesign['visual_keywords'] ?? null) ? $themeDesign['visual_keywords'] : [];
        $forbiddenStyles = \is_array($themeDesign['forbidden_styles'] ?? null) ? $themeDesign['forbidden_styles'] : [];
        $referenceStyleContext = \is_array($themeDesign['reference_style_context'] ?? null) ? $themeDesign['reference_style_context'] : [];
        if ($referenceStyleContext !== []) {
            $referenceStyleContext = [
                'summary' => \trim((string)($referenceStyleContext['summary'] ?? '')),
                'style_keywords' => $this->normalizeStringList($referenceStyleContext['style_keywords'] ?? []),
                'color_palette' => $this->normalizeStringList($referenceStyleContext['color_palette'] ?? []),
                'layout_cues' => $this->normalizeStringList($referenceStyleContext['layout_cues'] ?? []),
                'component_cues' => $this->normalizeStringList($referenceStyleContext['component_cues'] ?? []),
                'typography_cues' => $this->normalizeStringList($referenceStyleContext['typography_cues'] ?? []),
                'do_not_use' => $this->normalizeStringList($referenceStyleContext['do_not_use'] ?? []),
                'implementation_rule' => \trim((string)($referenceStyleContext['implementation_rule'] ?? '')),
                'signature' => \trim((string)($referenceStyleContext['signature'] ?? '')),
            ];
        }
        $styleSignature = \trim((string)($themeDesign['style_signature'] ?? $themeDesign['visual_identity'] ?? $themeDesign['design_signature'] ?? ''));
        if ($styleSignature === '') {
            $styleSignature = \trim((string)($themeDesign['theme_purpose'] ?? $themeDesign['site_positioning'] ?? 'brief-led polished visual identity'));
        }
        $artDirection = \array_replace([
            'layout_motif' => 'brief-led composition motif',
            'background_system' => 'layered backgrounds and contrast surfaces inside the approved palette',
            'surface_treatment' => 'polished cards, panels, depth, and radius rhythm',
            'visual_detail_rule' => 'inline SVG/CSS motifs tied to the brief, never generic placeholders',
            'motion_rule' => 'restrained reveal, hover, and focus states',
        ], \is_array($themeDesign['art_direction'] ?? null) ? $themeDesign['art_direction'] : []);

        return [
            'theme_purpose' => (string)($themeDesign['theme_purpose'] ?? $themeDesign['site_positioning'] ?? ''),
            'style_signature' => $styleSignature,
            'reference_style_context' => $referenceStyleContext,
            'art_direction' => $artDirection,
            'color_scheme' => $colorScheme,
            'typography_spacing_radius' => $typography,
            'visual_keywords' => \array_values($visualKeywords),
            'tone_of_voice' => (string)($themeDesign['tone_of_voice'] ?? $themeDesign['content_tone'] ?? ''),
            'cta_tone' => (string)($themeDesign['cta_tone'] ?? ''),
            'forbidden_styles' => \array_values($forbiddenStyles),
            'selection_reason' => (string)($themeDesign['selection_reason'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $themeContextSnapshot
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildStageOneSharedPromptContext(array $themeContextSnapshot, array $sharedComponents, array $pageTypes, string $planLocale, array $requirementExpansion = []): array
    {
        $contentLocale = \trim((string)($themeContextSnapshot['content_locale'] ?? ''));
        $context = [
            'prompt_context_id' => 'stage1-shared-' . \substr((string)($themeContextSnapshot['context_hash'] ?? \sha1('shared')), 0, 12),
            'theme_context_hash' => (string)($themeContextSnapshot['context_hash'] ?? ''),
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'page_types' => $pageTypes,
            'requirement_expansion' => $requirementExpansion,
            'theme_design' => [
                ...$this->extractStageOneThemeDesign($themeContextSnapshot),
            ],
            'page_type_overviews' => $this->buildStageOnePageTypeOverviewContext(
                \is_array($themeContextSnapshot['page_type_overviews'] ?? null) ? $themeContextSnapshot['page_type_overviews'] : [],
                $pageTypes,
                $themeContextSnapshot,
                $requirementExpansion
            ),
            'header_plan' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
            'footer_plan' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
            'generation_rule' => $this->isEnglishLocale($contentLocale)
                ? 'Page type plans must carry this shared context and preserve Header, Footer, and theme continuity.'
                : '页面类型方案必须携带该共享上下文，并保持 Header、Footer 与主题连续性。',
        ];
        $context['context_hash'] = \sha1((string)\json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        return $context;
    }

    /**
     * @param array<string, mixed> $rawOverviews
     * @param list<string> $pageTypes
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOnePageTypeOverviewContext(array $rawOverviews, array $pageTypes, array $themeContextSnapshot, array $requirementExpansion): array
    {
        $mapped = [];
        foreach ($rawOverviews as $key => $overview) {
            if (!\is_array($overview)) {
                continue;
            }
            $pageType = \trim((string)(\is_string($key) ? $key : ($overview['page_type'] ?? $overview['page_key'] ?? '')));
            if ($pageType === '') {
                continue;
            }
            $mapped[$pageType] = $overview;
        }

        $themeDesign = $this->extractStageOneThemeDesign($themeContextSnapshot);
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $visualKeywords = \is_array($themeDesign['visual_keywords'] ?? null) ? $this->normalizeStringList($themeDesign['visual_keywords']) : [];
        $expandedBrief = \trim((string)($requirementExpansion['expanded_brief'] ?? $requirementExpansion['core_user_intent'] ?? ''));
        $primary = \trim((string)($colorScheme['primary'] ?? 'primary'));
        $surface = \trim((string)($colorScheme['background'] ?? $colorScheme['surface'] ?? 'surface'));
        $accent = \trim((string)($colorScheme['accent'] ?? $colorScheme['button'] ?? 'accent'));

        foreach ($pageTypes as $pageType) {
            if (\is_array($mapped[$pageType] ?? null) && $mapped[$pageType] !== []) {
                continue;
            }
            $role = $pageType === Page::TYPE_HOME
                ? 'conversion entry page'
                : ($pageType === Page::TYPE_ABOUT ? 'trust and story page' : 'page-specific support page');
            $mapped[$pageType] = [
                'page_type' => $pageType,
                'page_role' => $role,
                'content_focus' => $expandedBrief !== '' ? $expandedBrief : 'Use this page to support the selected website goal with concrete visitor-facing content.',
                'theme_color_application' => 'Use ' . $surface . ' as base, ' . $primary . ' for strong page identity, and ' . $accent . ' for CTA/accent states.',
                'section_layering_hint' => 'Alternate background, surface panels, cards, dividers, or illustration bands so this page is not one flat color.',
                'interaction_intent' => $pageType === Page::TYPE_HOME ? 'Make the primary conversion path obvious with visible hover/focus feedback.' : 'Use page-appropriate interactive affordances without copying the home page rhythm.',
                'differentiation_note' => $visualKeywords !== [] ? 'Keep ' . \implode(', ', \array_slice($visualKeywords, 0, 3)) . ' but vary block sequence and color layering for ' . $pageType . '.' : 'Keep the shared theme but vary block sequence and color layering for ' . $pageType . '.',
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $themeContextSnapshot
     * @param array<string, mixed> $sharedComponents
     * @param array<string, mixed> $sharedPromptContext
     * @param array<string, mixed> $pagePlans
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildStageOneHeaderFooterQueueEnvelope(
        array $scope,
        array $websiteProfile,
        array $themeContextSnapshot,
        array $sharedComponents,
        array $sharedPromptContext,
        array $pagePlans,
        array $pageTypes,
        string $planLocale
    ): array {
        $headerBlock = \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [];
        $footerBlock = \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [];
        $themeContextHash = \trim((string)($themeContextSnapshot['context_hash'] ?? ''));
        $sharedContextHash = \trim((string)($sharedPromptContext['context_hash'] ?? ''));
        $job = [
            'job_key' => 'stage1.shared.header_footer',
            'job_type' => 'stage1.shared.header_footer',
            'stage' => 'stage1_shared',
            'status' => 'done',
            'depends_on' => ['stage1.shared.theme_design'],
            'token' => \sha1((string)\json_encode([
                'job_key' => 'stage1.shared.header_footer',
                'theme_context_hash' => $themeContextHash,
                'shared_context_hash' => $sharedContextHash,
                'page_types' => $pageTypes,
            ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
            'inputs' => [
                'theme_context_hash' => $themeContextHash,
                'theme_context_snapshot' => $themeContextSnapshot,
                'page_types' => $pageTypes,
                'plan_locale' => $planLocale,
                'content_locale' => (string)($sharedPromptContext['content_locale'] ?? ''),
            ],
            'outputs' => [
                'header_block' => $headerBlock,
                'footer_block' => $footerBlock,
                'shared_prompt_context' => $sharedPromptContext,
            ],
            'output_refs' => [
                'shared:header',
                'shared:footer',
                'shared_prompt_context',
            ],
        ];
        $pageFanoutJobs = $this->buildStageOnePageFanoutQueueJobs(
            $scope,
            $websiteProfile,
            $pagePlans,
            $sharedPromptContext,
            $planLocale
        );
        $jobs = [
            $job['job_key'] => $job,
        ];
        $sequence = [$job['job_key']];
        foreach ($pageFanoutJobs as $pageFanoutJob) {
            $pageJobKey = (string)($pageFanoutJob['job_key'] ?? '');
            if ($pageJobKey === '') {
                continue;
            }
            $jobs[$pageJobKey] = $pageFanoutJob;
            $sequence[] = $pageJobKey;
        }

        return [
            'version' => 1,
            'stage' => 'stage1',
            'status' => 'done',
            'sequence' => $sequence,
            'jobs' => $jobs,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $pagePlans
     * @param array<string, mixed> $sharedPromptContext
     * @return list<array<string, mixed>>
     */
    private function buildStageOnePageFanoutQueueJobs(
        array $scope,
        array $websiteProfile,
        array $pagePlans,
        array $sharedPromptContext,
        string $planLocale
    ): array {
        $jobs = [];
        $sessionPublicId = \trim((string)($scope['session_public_id'] ?? $scope['public_id'] ?? ''));
        $websitePublicId = \trim((string)($scope['website_public_id'] ?? $websiteProfile['public_id'] ?? $websiteProfile['website_public_id'] ?? ''));
        $sharedContextHash = \trim((string)($sharedPromptContext['context_hash'] ?? ''));
        $themeContextHash = \trim((string)($sharedPromptContext['theme_context_hash'] ?? ''));
        $sortOrder = 100;

        foreach ($pagePlans as $pageKey => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $pageKey = \trim((string)$pageKey);
            if ($pageKey === '') {
                continue;
            }

            $pageContextHash = \trim((string)($pagePlan['page_context_hash'] ?? ''));
            $token = \sha1((string)\json_encode([
                'job_type' => 'stage1.page_plan',
                'page_key' => $pageKey,
                'shared_context_hash' => $sharedContextHash,
                'page_context_hash' => $pageContextHash,
            ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

            $jobs[] = [
                'job_key' => 'stage1.page_plan:' . $pageKey,
                'job_type' => 'stage1.page_plan',
                'stage' => 'stage1_page_fanout',
                'sort_order' => $sortOrder++,
                'session_public_id' => $sessionPublicId,
                'website_public_id' => $websitePublicId,
                'page_key' => $pageKey,
                'block_key' => 'page:' . $pageKey,
                'depends_on' => ['stage1.shared.header_footer'],
                'status' => (string)($pagePlan['page_status'] ?? 'done'),
                'progress_percent' => (string)($pagePlan['page_status'] ?? 'done') === 'done' ? 100 : 0,
                'prompt_version' => 'stage1.page_plan.v1',
                'plan_locale' => $planLocale,
                'content_locale' => (string)($sharedPromptContext['content_locale'] ?? ''),
                'context_hash' => $pageContextHash,
                'shared_context_hash' => $sharedContextHash,
                'theme_context_hash' => $themeContextHash,
                'token' => $token,
                'dispatch_trigger' => 'stage1.shared.header_footer.done',
                'dispatch_mode' => 'automatic_after_dependency',
                'requires_user_tab' => false,
                'fanout_group' => 'stage1.page_fanout',
                'concurrency' => [
                    'mode' => 'fiber_coroutine',
                    'group' => 'stage1.page_fanout',
                    'task_granularity' => 'one_page_one_task',
                ],
                'inputs' => [
                    'page_key' => $pageKey,
                    'shared_context_hash' => $sharedContextHash,
                    'theme_context_hash' => $themeContextHash,
                    'plan_locale' => $planLocale,
                    'content_locale' => (string)($sharedPromptContext['content_locale'] ?? ''),
                ],
                'outputs' => [
                    'page_plan' => $pagePlan,
                    'page_context_hash' => $pageContextHash,
                ],
                'result_ref' => [
                    'kind' => 'scope_path',
                    'scope_path' => 'plan_workbench.stage1.page_plans.' . $pageKey,
                    'structured_path' => 'plan_structured.page_plans.' . $pageKey,
                    'execution_blueprint_path' => 'execution_blueprint_draft.page_plans.' . $pageKey,
                    'context_hash' => $pageContextHash,
                ],
                'retry_count' => 0,
                'last_error' => '',
                'updated_at' => '',
            ];
        }

        return $jobs;
    }

    /**
     * @param array<string, mixed> $stageOneQueue
     * @param array<string, mixed> $pagePlans
     * @param array<string, mixed> $sharedPromptContext
     * @return array<string, mixed>
     */
    private function buildStageOnePageFanoutQueueEnvelope(
        array $stageOneQueue,
        array $pagePlans,
        array $sharedPromptContext,
        string $planLocale
    ): array {
        $jobs = \is_array($stageOneQueue['jobs'] ?? null) ? $stageOneQueue['jobs'] : [];
        $sequence = \array_values(\array_map('strval', \is_array($stageOneQueue['sequence'] ?? null) ? $stageOneQueue['sequence'] : []));
        $pageJobKeys = [];
        $sortOrder = 40;

        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }

            $pageKey = (string)$pageType;
            $jobKey = 'stage1.page_plan:' . $pageKey;
            $pageJobKeys[] = $jobKey;
            $jobs[$jobKey] = [
                'job_key' => $jobKey,
                'job_type' => 'stage1.page_plan',
                'stage' => 'stage1_page_fanout',
                'sort_order' => $sortOrder++,
                'status' => 'done',
                'depends_on' => ['stage1.shared.header_footer'],
                'progress_percent' => 100,
                'prompt_version' => 'stage1.page_plan.v1',
                'plan_locale' => $planLocale,
                'context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                'shared_context_hash' => (string)($pagePlan['shared_context_hash'] ?? $sharedPromptContext['context_hash'] ?? ''),
                'theme_context_hash' => (string)($pagePlan['theme_context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
                'dispatch_trigger' => 'stage1.shared.header_footer.done',
                'dispatch_mode' => 'automatic_after_dependency',
                'requires_user_tab' => false,
                'fanout_group' => 'stage1.page_fanout',
                'token' => \sha1((string)\json_encode([
                    'job_key' => $jobKey,
                    'shared_context_hash' => (string)($pagePlan['shared_context_hash'] ?? $sharedPromptContext['context_hash'] ?? ''),
                    'page_context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
                'concurrency' => [
                    'mode' => 'fiber_coroutine',
                    'fanout_group' => 'stage1.page_plan.fanout',
                    'task_granularity' => 'one_page_one_task',
                    'trigger_after' => 'stage1.shared.header_footer',
                ],
                'inputs' => [
                    'page_key' => $pageKey,
                    'shared_context_hash' => (string)($pagePlan['shared_context_hash'] ?? $sharedPromptContext['context_hash'] ?? ''),
                    'theme_context_hash' => (string)($pagePlan['theme_context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
                    'plan_locale' => $planLocale,
                ],
                'outputs' => [
                    'page_plan' => $pagePlan,
                    'page_context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                ],
                'output_refs' => [
                    'plan_workbench.stage1.page_plans.' . $pageKey,
                    'plan_structured.page_plans.' . $pageKey,
                ],
            ];
        }

        foreach ($pageJobKeys as $jobKey) {
            if (!\in_array($jobKey, $sequence, true)) {
                $sequence[] = $jobKey;
            }
        }

        $stageOneQueue['sequence'] = $sequence;
        $stageOneQueue['jobs'] = $jobs;
        $stageOneQueue['fanout'] = [
            'trigger_after' => 'stage1.shared.header_footer',
            'mode' => 'fiber_coroutine',
            'task_granularity' => 'one_page_one_task',
            'page_job_count' => \count($pageJobKeys),
            'page_job_keys' => $pageJobKeys,
        ];

        return $stageOneQueue;
    }

    /**
     * @param array<string, mixed> $pages
     * @return array<string, mixed>
     */
    private function buildStageOnePagePlans(array $pages, array $sharedPromptContext): array
    {
        $pagePlans = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $pagePlans[(string)$pageType] = $this->buildStageOnePagePlan((string)$pageType, $pagePlan, $sharedPromptContext);
        }

        return $pagePlans;
    }

    /**
     * @param array<string, mixed> $pages
     * @param array<string, mixed> $sharedPromptContext
     * @return array<string, mixed>
     */
    private function buildStageOnePagePlansConcurrently(array $pages, array $sharedPromptContext): array
    {
        if (\count($pages) <= 1 || !\class_exists(\Fiber::class)) {
            return $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        }

        /** @var array<string, \Fiber> $fibers */
        $fibers = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $pageKey = (string)$pageType;
            $fibers[$pageKey] = new \Fiber(function () use ($pageKey, $pagePlan, $sharedPromptContext): array {
                return $this->buildStageOnePagePlan($pageKey, $pagePlan, $sharedPromptContext);
            });
        }

        if ($fibers === []) {
            return [];
        }

        $results = [];
        $errors = [];
        foreach ($fibers as $pageKey => $fiber) {
            try {
                $fiber->start();
            } catch (\Throwable $throwable) {
                $errors[$pageKey] = $throwable;
            }
        }

        while (\count($results) + \count($errors) < \count($fibers)) {
            $madeProgress = false;
            foreach ($fibers as $pageKey => $fiber) {
                if (isset($results[$pageKey]) || isset($errors[$pageKey])) {
                    continue;
                }

                try {
                    if ($fiber->isTerminated()) {
                        $results[$pageKey] = $fiber->getReturn();
                        $madeProgress = true;
                        continue;
                    }

                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                        $madeProgress = true;
                    }
                } catch (\Throwable $throwable) {
                    $errors[$pageKey] = $throwable;
                    $madeProgress = true;
                }
            }

            if (!$madeProgress && \count($results) + \count($errors) < \count($fibers)) {
                \usleep(1000);
            }
        }

        if ($errors !== []) {
            $firstError = \reset($errors);
            if ($firstError instanceof \Throwable) {
                throw $firstError;
            }
        }

        $pagePlans = [];
        foreach ($pages as $pageType => $_) {
            $pageKey = (string)$pageType;
            if (isset($results[$pageKey])) {
                $pagePlans[$pageKey] = $results[$pageKey];
            }
        }

        return $pagePlans;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $sharedPromptContext
     * @return array<string, mixed>
     */
    private function buildStageOnePagePlan(string $pageType, array $pagePlan, array $sharedPromptContext): array
    {
        $sharedContextHash = (string)($sharedPromptContext['context_hash'] ?? '');
        $themeContextHash = (string)($sharedPromptContext['theme_context_hash'] ?? '');
        $assembledPagePlan = \array_replace($pagePlan, [
            'page_key' => $pageType,
            'page_status' => 'done',
            'content_locale' => (string)($sharedPromptContext['content_locale'] ?? ''),
            'shared_context_hash' => $sharedContextHash,
            'theme_context_hash' => $themeContextHash,
            'assembly_version' => 1,
            'generation_method' => 'stage1.page_plan.generate',
        ]);
        $assembledPagePlan['page_design_plan'] = $this->normalizeStageOnePageDesignPlan(
            $pageType,
            $assembledPagePlan,
            $sharedPromptContext
        );
        $blocks = [];
        $usedVisualFingerprints = [];
        foreach (\is_array($assembledPagePlan['blocks'] ?? null) ? $assembledPagePlan['blocks'] : [] as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $normalizedBlock = $this->normalizeStageOnePageBlock($pageType, $assembledPagePlan, $block, (int)$index, $sharedPromptContext);
            $normalizedBlock['visual_signature'] = $this->normalizeStageOneBlockVisualSignature(
                $normalizedBlock,
                (int)$index,
                $usedVisualFingerprints
            );
            $blocks[] = $normalizedBlock;
        }
        $assembledPagePlan['blocks'] = $blocks;
        if (\trim((string)($assembledPagePlan['theme_alignment_summary'] ?? '')) === '') {
            $assembledPagePlan['theme_alignment_summary'] = $this->buildPageThemeAlignmentSummaryFromSharedContext(
                (string)($assembledPagePlan['page_label'] ?? $pageType),
                (string)($assembledPagePlan['page_goal'] ?? ''),
                $blocks,
                $sharedPromptContext
            );
        }
        $assembledPagePlan['page_context_hash'] = $this->buildStageOnePageContextHash($pageType, $assembledPagePlan);

        return $assembledPagePlan;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $block
     * @param array<string, mixed> $sharedPromptContext
     * @return array<string, mixed>
     */
    private function normalizeStageOnePageBlock(
        string $pageType,
        array $pagePlan,
        array $block,
        int $index,
        array $sharedPromptContext
    ): array {
        $rawBlockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ('block_' . ($index + 1))));
        $blockKey = $rawBlockKey !== '' ? $rawBlockKey : ('block_' . ($index + 1));
        $title = \trim((string)($block['title'] ?? $block['label'] ?? \str_replace(['_', '-'], ' ', $blockKey)));
        $goal = \trim((string)($block['goal'] ?? $block['block_goal'] ?? $block['content'] ?? ''));
        $implementationDetail = \trim((string)($block['implementation_detail'] ?? $block['implementation_note'] ?? ''));
        if ($implementationDetail === '') {
            $implementationDetail = $this->buildBlockImplementationFocus($block, (string)($pagePlan['plan_locale'] ?? ''));
        }
        if ($implementationDetail === '') {
            $implementationDetail = $goal !== '' ? $goal : 'Render this page block with concrete copy, editable fields, and responsive behavior.';
        }
        $completionRule = \trim((string)($block['completion_rule'] ?? ''));
        if ($completionRule === '') {
            $completionRule = 'Block is complete when copy, editable fields, responsive layout, and CTA/media slots are ready for preview.';
        }
        $realtimeContent = \is_array($block['realtime_content'] ?? null)
            ? $block['realtime_content']
            : $this->buildStageOneRealtimeContentFromPageBlock($block);
        $editableFields = \is_array($block['editable_fields'] ?? null)
            ? $this->normalizeStringList($block['editable_fields'])
            : $this->deriveStageOneEditableFields($block, $realtimeContent);
        $contentSource = \is_array($block['content_source'] ?? null)
            ? $this->normalizeStringList($block['content_source'])
            : ['shared_prompt_context', 'page_plan', 'editable_field'];
        $dependencies = \is_array($block['dependencies'] ?? null)
            ? $this->normalizeStringList($block['dependencies'])
            : ['shared:header', 'shared:footer'];

        $normalized = \array_replace($block, [
            'block_key' => $blockKey,
            'block_type' => (string)($block['block_type'] ?? $block['component_kind'] ?? 'page:content'),
            'page_key' => $pageType,
            'title' => $title,
            'goal' => $goal,
            'implementation_detail' => $implementationDetail,
            'realtime_content' => $realtimeContent,
            'editable_fields' => $editableFields,
            'content_source' => $contentSource,
            'page_flow_role' => (string)($block['page_flow_role'] ?? $this->inferStageOnePageFlowRole($index, $blockKey, $block)),
            'design_tags' => $this->normalizeStageOneBlockDesignTags(
                $block,
                $sharedPromptContext,
                \is_array($pagePlan['page_design_plan'] ?? null) ? $pagePlan['page_design_plan'] : []
            ),
            'style_direction' => (string)($block['style_direction'] ?? $block['execution_script']['style_tone'] ?? ''),
            'responsive_rule' => (string)($block['responsive_rule'] ?? $block['execution_script']['responsive_rule'] ?? ''),
            'seo_role' => (string)($block['seo_role'] ?? $block['seo_impact'] ?? ''),
            'completion_rule' => $completionRule,
            'dependencies' => $dependencies,
            'prompt_context_hash' => (string)($sharedPromptContext['context_hash'] ?? ''),
            'version' => (int)($block['version'] ?? 1),
            'sort_order' => (int)($block['sort_order'] ?? (($index + 1) * 10)),
        ]);
        unset($normalized['reason'], $normalized['why']);
        $normalized['context_hash'] = $this->buildStageOneBlockContextHash($pageType, $normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $usedFingerprints
     * @return array<string, string>
     */
    private function normalizeStageOneBlockVisualSignature(array $block, int $index, array &$usedFingerprints): array
    {
        $signature = [];
        $raw = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
        foreach (AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS as $key) {
            $value = \trim((string)($raw[$key] ?? ''));
            if ($value !== '') {
                $signature[$key] = $value;
            }
        }

        $fingerprint = $this->stageOneVisualSignatureDiversityFingerprint($signature);
        if ($fingerprint !== '' && !\in_array($fingerprint, $usedFingerprints, true)) {
            $usedFingerprints[] = $fingerprint;

            return $signature;
        }

        $repaired = $this->buildDistinctStageOneVisualSignature($block, $index, $usedFingerprints);
        $repairedFingerprint = $this->stageOneVisualSignatureDiversityFingerprint($repaired);
        if ($repairedFingerprint !== '') {
            $usedFingerprints[] = $repairedFingerprint;
        }

        return $repaired;
    }

    /**
     * @param array<string, string> $signature
     */
    private function stageOneVisualSignatureDiversityFingerprint(array $signature): string
    {
        $parts = [];
        foreach (['composition_pattern', 'surface_treatment', 'media_strategy'] as $key) {
            $value = \mb_strtolower(\trim((string)($signature[$key] ?? '')));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? '' : \implode('|', $parts);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveStageOneExplicitRoleKind(array $block, int $index): string
    {
        $tokens = [];
        foreach ([
            $block['block_type'] ?? null,
            $block['page_flow_role'] ?? null,
            $block['component_kind'] ?? null,
            $block['template'] ?? null,
            $block['block_key'] ?? null,
            $block['section_code'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate) && !(\is_object($candidate) && \method_exists($candidate, '__toString'))) {
                continue;
            }
            $token = $this->normalizeStageOneRoleToken((string)$candidate);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        $tokens = \array_values(\array_unique($tokens));
        $groups = [
            'opening' => ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold', 'opening'],
            'faq' => ['faq', 'support_faq', 'faq_rows', 'faq_list'],
            'support' => ['support', 'support_guidance', 'help_desk'],
            'form' => ['form', 'contact_form', 'message_form', 'support_form', 'support_form_guidance', 'form_guidance'],
            'contact' => ['contact', 'contact_methods', 'contact_channels', 'support_channels', 'channel_hub'],
            'cta' => ['cta', 'contact_cta', 'final_cta', 'page_cta', 'download_cta', 'conversion_cta', 'download_band', 'conversion_band'],
            'proof' => ['proof', 'trust', 'trust_proof', 'trust_security', 'testimonial', 'metric_strip', 'badge_wall'],
            'feature' => ['feature', 'features', 'feature_grid', 'feature_rail', 'showcase', 'game_showcase', 'game_showcase_or_features', 'featured_games', 'product', 'service', 'suite'],
            'story' => ['story', 'origin_story', 'brand_story', 'mission', 'mission_values', 'about', 'timeline', 'journey'],
        ];
        foreach ($groups as $roleKind => $acceptedTokens) {
            foreach ($tokens as $token) {
                if (\in_array($token, $acceptedTokens, true)) {
                    return $roleKind;
                }
            }
        }

        return $index === 0 ? 'opening' : 'details';
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string> $usedFingerprints
     * @return array<string, string>
     */
    private function buildDistinctStageOneVisualSignature(array $block, int $index, array $usedFingerprints): array
    {
        $roleKind = $this->resolveStageOneExplicitRoleKind($block, $index);

        $rolePreferred = match ($roleKind) {
            'opening' => ['full_bleed_hero_stage', 'split_editorial_hero', 'cinematic_opening_band'],
            'faq', 'support' => ['faq_accordion_rows', 'support_guidance_split', 'help_desk_channel_strip'],
            'form', 'contact' => ['form_guidance_split', 'channel_hub_console', 'lead_capture_panel'],
            'cta' => ['cta_conversion_stage', 'cinematic_action_band', 'download_focus_strip'],
            'proof' => ['staggered_proof_rail', 'metric_proof_strip', 'credential_badge_wall'],
            'feature' => ['feature_matrix_band', 'asymmetric_media_feature', 'comparison_editorial_split'],
            'story' => ['timeline_process_rail', 'stacked_editorial_band', 'founder_mission_split'],
            default => ['stacked_editorial_band', 'split_editorial_panel', 'editorial_detail_rail', 'proof_metric_band', 'feature_matrix_band'],
        };

        $rotation = [
            'split_editorial_panel',
            'stacked_editorial_band',
            'staggered_proof_rail',
            'metric_proof_strip',
            'feature_matrix_band',
            'timeline_process_rail',
            'asymmetric_media_feature',
            'faq_accordion_rows',
            'form_guidance_split',
            'channel_hub_console',
            'cta_conversion_stage',
            'comparison_editorial_split',
            'credential_badge_wall',
        ];

        $composition = '';
        foreach (\array_merge($rolePreferred, $rotation) as $candidate) {
            $probe = $this->composeStageOneVisualSignaturePreset($candidate, $block, $index);
            $fingerprint = $this->stageOneVisualSignatureDiversityFingerprint($probe);
            if ($fingerprint === '' || \in_array($fingerprint, $usedFingerprints, true)) {
                continue;
            }
            $composition = $candidate;
            break;
        }
        if ($composition === '') {
            $composition = $rotation[$index % \count($rotation)] ?? 'stacked_editorial_band';
        }

        return $this->composeStageOneVisualSignaturePreset($composition, $block, $index);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, string>
     */
    private function composeStageOneVisualSignaturePreset(string $compositionPattern, array $block, int $index): array
    {
        $compositionPattern = \trim($compositionPattern);
        $roleKind = $this->resolveStageOneExplicitRoleKind($block, $index);
        $surfaceAlternation = ['elevated_soft_cards', 'inset_glass_panels', 'outlined_editorial_slabs', 'layered_contrast_bands'];
        $mediaAlternation = ['verified_feature_image', 'css_motif_backdrop', 'inline_media_rail', 'background_texture_panel'];
        $interactionAlternation = ['subtle_hover_lift', 'focus_ring_emphasis', 'scroll_reveal_cadence', 'cta_micro_motion'];

        return [
            'composition_pattern' => $compositionPattern,
            'spatial_rhythm' => match ($compositionPattern) {
                'full_bleed_hero_stage', 'cinematic_opening_band', 'cinematic_action_band' => 'full-bleed opening cadence with overlay copy panel',
                'faq_accordion_rows', 'support_guidance_split' => 'tight stacked Q/A rhythm with asymmetric help rail',
                'form_guidance_split', 'channel_hub_console', 'lead_capture_panel' => 'form-first vertical rhythm with grouped field spacing',
                'staggered_proof_rail', 'metric_proof_strip', 'credential_badge_wall' => 'offset proof cadence with alternating metric emphasis',
                'timeline_process_rail', 'founder_mission_split' => 'stepped narrative rhythm with milestone spacing',
                default => $index % 2 === 0 ? 'airy editorial spacing with alternating surface bands' : 'compact proof cadence with deliberate whitespace breaks',
            },
            'media_strategy' => match (true) {
                $roleKind === 'opening' => 'hero cover image with text-safe scrim',
                \in_array($roleKind, ['faq', 'form', 'contact', 'cta'], true) => $mediaAlternation[($index + 1) % \count($mediaAlternation)],
                default => $mediaAlternation[$index % \count($mediaAlternation)],
            },
            'surface_treatment' => $surfaceAlternation[$index % \count($surfaceAlternation)],
            'interaction_pattern' => $interactionAlternation[$index % \count($interactionAlternation)],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $sharedPromptContext
     * @return array{visual:list<string>,motion:list<string>,interaction:list<string>,texture:list<string>,responsive:list<string>,color_layering:string,implementation_note:string}
     */
    private function normalizeStageOneBlockDesignTags(array $block, array $sharedPromptContext, array $pageDesignPlan = []): array
    {
        $raw = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];
        $themeDesign = \is_array($sharedPromptContext['theme_design'] ?? null) ? $sharedPromptContext['theme_design'] : [];
        $visualKeywords = \is_array($themeDesign['visual_keywords'] ?? null) ? $themeDesign['visual_keywords'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? 'block'));
        $pageColorLayering = \trim((string)($pageDesignPlan['color_layering'] ?? ''));

        $normalizeList = static function (mixed $value): array {
            if (!\is_array($value)) {
                return [];
            }

            return \array_values(\array_filter(\array_map(
                static fn($item): string => \is_scalar($item) ? \trim((string)$item) : '',
                $value
            ), static fn(string $item): bool => $item !== ''));
        };

        $visual = $normalizeList($raw['visual'] ?? []);
        if ($visual === []) {
            $visual = \array_values(\array_filter(\array_map(
                static fn($item): string => \is_scalar($item) ? \trim((string)$item) : '',
                \array_slice($visualKeywords, 0, 3)
            ), static fn(string $item): bool => $item !== ''));
        }
        if ($visual === []) {
            $visual = ['theme-consistent layout', 'clear visual hierarchy'];
        }

        $motion = $normalizeList($raw['motion'] ?? []);
        if ($motion === []) {
            $motion = $this->stageOneBlockHasExplicitHeroRole($block)
                ? ['5s fade in/out', 'subtle entrance animation']
                : ['hover lift', '150ms ease transition'];
        }

        $interaction = $normalizeList($raw['interaction'] ?? []);
        if ($interaction === []) {
            $interaction = ['CTA hover feedback', 'keyboard-focus visible state'];
        }

        $texture = $normalizeList($raw['texture'] ?? []);
        if ($texture === []) {
            $paletteName = \trim((string)($colorScheme['name'] ?? ''));
            $texture = [$paletteName !== '' ? ($paletteName . ' surface') : 'soft themed surface'];
            $backgroundDirection = \trim((string)($executionScript['background_direction'] ?? ''));
            if ($backgroundDirection !== '') {
                $texture[] = $backgroundDirection;
            }
        }
        if ($pageColorLayering !== '') {
            $texture[] = $pageColorLayering;
        }

        $responsive = $normalizeList($raw['responsive'] ?? []);
        if ($responsive === []) {
            $spacingScale = \trim((string)($typography['spacing_scale'] ?? ''));
            $responsive = [
                'desktop preserves intended composition',
                'mobile stacks content without hiding CTA',
            ];
            if ($spacingScale !== '') {
                $responsive[] = 'spacing follows ' . $spacingScale;
            }
        }

        $implementationNote = \trim((string)($raw['implementation_note'] ?? ''));
        if ($implementationNote === '') {
            $implementationNote = 'Carry these design tags into virtual-theme build and publish migration so animation, texture, spacing, radius, and interaction behavior are implemented consistently.';
        }
        if ($pageColorLayering !== '' && !\str_contains($implementationNote, $pageColorLayering)) {
            $implementationNote .= ' Follow page color layering: ' . $pageColorLayering;
        }

        $colorLayering = \trim((string)($raw['color_layering'] ?? $pageColorLayering));
        if ($colorLayering === '') {
            $primary = \trim((string)($colorScheme['primary'] ?? 'primary'));
            $background = \trim((string)($colorScheme['background'] ?? $colorScheme['surface'] ?? 'background'));
            $accent = \trim((string)($colorScheme['accent'] ?? $colorScheme['button'] ?? 'accent'));
            $colorLayering = \sprintf(
                'Use %s surfaces with %s accents and readable body contrast on %s backgrounds.',
                $background,
                $accent,
                $primary
            );
        }

        return [
            'visual' => \array_values(\array_unique($visual)),
            'motion' => \array_values(\array_unique($motion)),
            'interaction' => \array_values(\array_unique($interaction)),
            'texture' => \array_values(\array_unique($texture)),
            'responsive' => \array_values(\array_unique($responsive)),
            'color_layering' => $colorLayering,
            'implementation_note' => $implementationNote,
        ];
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $sharedPromptContext
     * @return array<string, mixed>
     */
    private function normalizeStageOnePageDesignPlan(string $pageType, array $pagePlan, array $sharedPromptContext): array
    {
        $raw = \is_array($pagePlan['page_design_plan'] ?? null)
            ? $pagePlan['page_design_plan']
            : (\is_array($pagePlan['visual_design_plan'] ?? null) ? $pagePlan['visual_design_plan'] : []);
        $overviews = \is_array($sharedPromptContext['page_type_overviews'] ?? null) ? $sharedPromptContext['page_type_overviews'] : [];
        $overview = \is_array($overviews[$pageType] ?? null) ? $overviews[$pageType] : [];
        $themeDesign = \is_array($sharedPromptContext['theme_design'] ?? null) ? $sharedPromptContext['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $primary = \trim((string)($colorScheme['primary'] ?? 'primary'));
        $background = \trim((string)($colorScheme['background'] ?? $colorScheme['surface'] ?? 'background'));
        $accent = \trim((string)($colorScheme['accent'] ?? $colorScheme['button'] ?? 'accent'));

        $sectionFlow = $this->normalizeStringList($raw['section_flow'] ?? []);
        if ($sectionFlow === []) {
            $sectionFlow = $this->normalizeStringList($raw['content_flow'] ?? []);
        }
        if ($sectionFlow === []) {
            $sectionFlow = [
                'Opening block establishes the page-specific promise with a distinctive visual layer.',
                'Middle block changes surface treatment for proof, detail, or reassurance.',
                'Closing block uses CTA/accent treatment without repeating the opening composition.',
            ];
        }

        $interactionNotes = $this->normalizeStringList($raw['interaction_notes'] ?? []);
        if ($interactionNotes === []) {
            $interactionNotes = $this->normalizeStringList($overview['interaction_intent'] ?? []);
        }
        if ($interactionNotes === []) {
            $interactionNotes = ['Use hover, focus, and mobile states that reinforce this page role without generic CTA-only behavior.'];
        }

        $colorLayering = \trim((string)($raw['color_layering'] ?? $raw['theme_color_application'] ?? $overview['theme_color_application'] ?? ''));
        if ($colorLayering === '') {
            $colorLayering = 'Use ' . $background . ' as base, ' . $primary . ' for strong identity zones, and ' . $accent . ' for CTA/accent states with alternating section surfaces.';
        }

        return [
            'page_role' => $this->firstNonEmptyString([
                $raw['page_role'] ?? null,
                $overview['page_role'] ?? null,
                $pageType,
            ]),
            'content_narrative' => $this->firstNonEmptyString([
                $raw['content_narrative'] ?? null,
                $raw['content_focus'] ?? null,
                $overview['content_focus'] ?? null,
                $pagePlan['page_goal'] ?? null,
            ]),
            'visual_hierarchy' => $this->firstNonEmptyString([
                $raw['visual_hierarchy'] ?? null,
                $raw['section_layering_hint'] ?? null,
                $overview['section_layering_hint'] ?? null,
                'Build a clear opening, supporting middle layer, and conversion or reassurance close.',
            ]),
            'color_layering' => $colorLayering,
            'section_flow' => $sectionFlow,
            'interaction_notes' => $interactionNotes,
            'anti_monotony_rule' => $this->firstNonEmptyString([
                $raw['anti_monotony_rule'] ?? null,
                'Do not render the whole page as one flat color; every block needs a distinct surface, card, divider, texture, illustration, or contrast band while staying inside the approved palette.',
            ]),
        ];
    }

    private function inferStageOnePageFlowRole(int $index, string $blockKey, array $block = []): string
    {
        foreach ([
            $block['block_type'] ?? null,
            $block['component_kind'] ?? null,
            $block['template'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate) && !(\is_object($candidate) && \method_exists($candidate, '__toString'))) {
                continue;
            }
            $token = $this->normalizeStageOneRoleToken((string)$candidate);
            if (\in_array($token, ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold', 'opening'], true)) {
                return 'opening';
            }
            if (\in_array($token, ['cta', 'final_cta', 'download_cta', 'conversion_cta'], true)) {
                return 'cta';
            }
            if (\in_array($token, ['proof', 'trust', 'trust_proof', 'testimonial', 'metric_strip', 'badge_wall'], true)) {
                return 'proof';
            }
        }

        $normalized = $this->normalizeStageOneRoleToken($blockKey);
        if ($index === 0) {
            return 'opening';
        }
        if (\in_array($normalized, ['cta', 'final_cta', 'download_cta', 'conversion_cta'], true)) {
            return 'cta';
        }
        if (\in_array($normalized, ['proof', 'trust', 'trust_proof', 'testimonial'], true)) {
            return 'proof';
        }

        return $index >= 2 ? 'support' : 'details';
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageOneRealtimeContentFromPageBlock(array $block): array
    {
        $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
        $headline = '';
        $supportingCopy = [];
        foreach ($fieldPlan as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \mb_strtolower(\trim((string)($row['field'] ?? '')));
            $sample = \trim((string)($row['sample'] ?? ''));
            if ($sample === '') {
                continue;
            }
            if ($headline === '' && (\str_contains($field, 'title') || \str_contains($field, 'headline') || \str_contains($field, 'heading'))) {
                $headline = $sample;
                continue;
            }
            $supportingCopy[] = $sample;
        }

        if ($headline === '') {
            $headline = \trim((string)($block['title'] ?? $block['content'] ?? $block['goal'] ?? ''));
        }
        if ($supportingCopy === []) {
            $content = \trim((string)($block['content'] ?? ''));
            if ($content !== '' && $content !== $headline) {
                $supportingCopy[] = $content;
            }
        }
        $executionScript = \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [];

        return [
            'headline' => $headline,
            'supporting_copy' => \array_values(\array_slice($supportingCopy, 0, 6)),
            'cta' => \is_array($block['cta'] ?? null) ? $block['cta'] : [],
            'media' => \is_array($executionScript['media_assets'] ?? null) ? $executionScript['media_assets'] : [],
            'editable_slots' => $this->deriveStageOneEditableFields($block, []),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $realtimeContent
     * @return list<string>
     */
    private function deriveStageOneEditableFields(array $block, array $realtimeContent): array
    {
        $fields = [];
        $addField = static function (string $field) use (&$fields): void {
            $field = \trim($field);
            if ($field !== '') {
                $fields[] = $field;
            }
        };

        if (\is_array($block['field_plan'] ?? null)) {
            foreach ($this->extractEditableFieldsFromFieldPlan($block['field_plan']) as $field) {
                $addField($field);
            }
        }

        $slots = \is_array($realtimeContent['editable_slots'] ?? null)
            ? $realtimeContent['editable_slots']
            : (\is_array($block['editable_slots'] ?? null) ? $block['editable_slots'] : []);
        foreach ($slots as $slotKey => $slotValue) {
            if (\is_string($slotValue) || \is_numeric($slotValue)) {
                $addField((string)$slotValue);
                continue;
            }
            if (\is_string($slotKey)) {
                $addField($slotKey);
            }
        }

        $content = $realtimeContent;
        if ($content === []) {
            $content = [
                'headline' => \trim((string)($block['title'] ?? $block['content'] ?? $block['goal'] ?? '')),
                'supporting_copy' => \trim((string)($block['content'] ?? '')),
                'cta' => $block['cta'] ?? null,
                'media' => \is_array($block['execution_script']['media_assets'] ?? null) ? $block['execution_script']['media_assets'] : null,
            ];
        }
        foreach (['headline', 'supporting_copy', 'cta', 'media'] as $contentKey) {
            $value = $content[$contentKey] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $addField($contentKey === 'supporting_copy' ? 'body_copy' : $contentKey);
        }

        $contentBrief = \is_array($block['content_brief'] ?? null) ? $block['content_brief'] : [];
        foreach ([
            'headline_direction' => 'headline',
            'body_direction' => 'body_copy',
            'cta_direction' => 'primary_cta',
        ] as $briefKey => $fieldName) {
            if (\trim((string)($contentBrief[$briefKey] ?? '')) !== '') {
                $addField($fieldName);
            }
        }

        $fields = \array_values(\array_unique($fields));
        return $fields !== [] ? $fields : ['headline', 'body_copy'];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $sharedPromptContext
     */
    private function buildPageThemeAlignmentSummaryFromSharedContext(
        string $pageLabel,
        string $pageGoal,
        array $blocks,
        array $sharedPromptContext
    ): string {
        $themeDesign = \is_array($sharedPromptContext['theme_design'] ?? null) ? $sharedPromptContext['theme_design'] : [];
        $colorScheme = \is_array($themeDesign['color_scheme'] ?? null) ? $themeDesign['color_scheme'] : [];
        $palette = [
            'name' => (string)($colorScheme['name'] ?? $sharedPromptContext['palette_name'] ?? ''),
        ];
        $themeStyle = [
            'visual_tone' => (string)($themeDesign['tone_of_voice'] ?? $sharedPromptContext['content_tone'] ?? ''),
        ];

        return 'shared_prompt_context: ' . $this->buildPageThemeAlignmentSummary($pageLabel, $pageGoal, $blocks, $palette, $themeStyle);
    }

    /**
     * @param array<string, mixed> $pagePlan
     */
    private function buildStageOnePageContextHash(string $pageType, array $pagePlan): string
    {
        $hashSource = $pagePlan;
        unset($hashSource['page_context_hash']);

        return \sha1((string)\json_encode([
            'page_key' => $pageType,
            'page_plan' => $hashSource,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildStageOneBlockContextHash(string $pageType, array $block): string
    {
        $hashSource = $block;
        unset($hashSource['context_hash']);

        return \sha1((string)\json_encode([
            'page_key' => $pageType !== '' ? $pageType : 'shared',
            'block_key' => (string)($block['block_key'] ?? $block['section_code'] ?? $block['task_key'] ?? ''),
            'block' => $hashSource,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function sortStageOneBlocksForPlanBookMarkdown(array $blocks): array
    {
        $wrapped = [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $wrapped[] = [
                'index' => (int)$index,
                'sort_order' => $this->resolveStageOnePlanBookBlockSortOrder($block, (int)$index),
                'block' => $block,
            ];
        }

        \usort($wrapped, static function (array $left, array $right): int {
            $sortCompare = ((int)$left['sort_order']) <=> ((int)$right['sort_order']);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return ((int)$left['index']) <=> ((int)$right['index']);
        });

        return \array_values(\array_map(static fn(array $row): array => $row['block'], $wrapped));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveStageOnePlanBookBlockSortOrder(array $block, int $fallbackIndex): int
    {
        foreach (['sort_order', 'order'] as $field) {
            if (!\array_key_exists($field, $block)) {
                continue;
            }
            $value = $block[$field];
            if (\is_int($value)) {
                return $value;
            }
            if (\is_float($value)) {
                return (int)$value;
            }
            if (\is_string($value) && \trim($value) !== '' && \is_numeric($value)) {
                return (int)$value;
            }
        }

        return ($fallbackIndex + 1) * 10;
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param array<string, mixed> $pagePlans
     * @return array<string, mixed>
     */
    private function buildStageOneBlockIndex(array $sharedComponents, array $pagePlans): array
    {
        $index = [
            'shared' => [],
            'pages' => [],
            'flat' => [],
        ];
        foreach ($this->normalizeStageOneSharedComponents($sharedComponents) as $region => $componentPlan) {
            if (!\is_array($componentPlan)) {
                continue;
            }
            $blockKey = 'shared:' . (string)$region;
            $row = [
                'block_key' => $blockKey,
                'block_type' => $blockKey,
                'page_key' => '',
                'title' => (string)($componentPlan['component'] ?? $region),
                'goal' => (string)($componentPlan['goal'] ?? ''),
                'sort_order' => (int)($componentPlan['sort_order'] ?? 0),
                'status' => 'done',
            ];
            $index['shared'][$blockKey] = $row;
            $index['flat'][$blockKey] = $row;
        }
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $index['pages'][$pageType] ??= [];
            foreach ($this->normalizeStageOnePageBlocksForBlockIndex(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []) as $offset => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $rawBlockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
                if ($rawBlockKey === '') {
                    $rawBlockKey = 'block_' . ((int)$offset + 1);
                }
                $blockKey = 'page:' . (string)$pageType . ':' . $rawBlockKey;
                $row = [
                    'block_key' => $blockKey,
                    'block_type' => 'page:content',
                    'page_key' => (string)$pageType,
                    'source_block_key' => $rawBlockKey,
                    'title' => (string)($block['section_code'] ?? $rawBlockKey),
                    'goal' => (string)($block['goal'] ?? ''),
                    'sort_order' => (int)($block['sort_order'] ?? $block['order'] ?? 0),
                    'status' => 'done',
                    'implementation_detail' => (string)($block['implementation_detail'] ?? $block['style_brief']['layout_rule'] ?? ''),
                ];
                $index['pages'][$pageType][$blockKey] = $row;
                $index['flat'][$blockKey] = $row;
            }
        }
        $index['counts'] = [
            'shared' => \count($index['shared']),
            'pages' => \count($index['pages']),
            'blocks' => \count($index['flat']),
        ];

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function normalizeStageOnePageBlocksForBlockIndex(array $blocks): array
    {
        $wrapped = [];
        foreach ($blocks as $offset => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $wrapped[] = [
                'offset' => (int)$offset,
                'sort_order' => $this->resolveStageOnePageBlockSortOrder($block, (int)$offset),
                'block_key' => \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')),
                'block' => $block,
            ];
        }

        \usort($wrapped, static function (array $left, array $right): int {
            $sortCompare = ((int)$left['sort_order']) <=> ((int)$right['sort_order']);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            $offsetCompare = ((int)$left['offset']) <=> ((int)$right['offset']);
            if ($offsetCompare !== 0) {
                return $offsetCompare;
            }

            return \strcmp((string)$left['block_key'], (string)$right['block_key']);
        });

        return \array_values(\array_map(static fn(array $row): array => $row['block'], $wrapped));
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveStageOnePageBlockSortOrder(array $block, int $offset): int
    {
        if (\array_key_exists('sort_order', $block)) {
            return (int)$block['sort_order'];
        }
        if (\array_key_exists('order', $block)) {
            return (int)$block['order'];
        }

        return ($offset + 1) * 10;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $themeContextSnapshot
     * @return array<string, mixed>
     */
    private function buildStageOneThemeDesignQueueJob(
        array $scope,
        array $websiteProfile,
        array $themeContextSnapshot,
        string $planLocale
    ): array {
        $contextHash = \trim((string)($themeContextSnapshot['context_hash'] ?? ''));
        $stableHash = $contextHash !== '' ? $contextHash : \sha1((string)\json_encode($themeContextSnapshot, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        $sessionPublicId = \trim((string)($scope['session_public_id'] ?? $scope['public_id'] ?? ''));
        $websitePublicId = \trim((string)($scope['website_public_id'] ?? $websiteProfile['public_id'] ?? $websiteProfile['website_public_id'] ?? ''));
        $jobHash = \substr(\sha1('stage1.shared.theme_design|' . $sessionPublicId . '|' . $stableHash), 0, 12);

        return [
            'job_key' => 'stage1.shared.theme_design:' . $jobHash,
            'job_type' => 'stage1.shared.theme_design',
            'stage' => 'stage1',
            'sort_order' => 20,
            'session_public_id' => $sessionPublicId,
            'website_public_id' => $websitePublicId,
            'page_key' => '',
            'block_key' => 'shared:theme_design',
            'depends_on' => ['stage1.requirement_expand'],
            'status' => 'done',
            'progress_percent' => 100,
            'prompt_version' => 'stage1.shared.theme_design.v1',
            'plan_locale' => $planLocale,
            'context_hash' => $stableHash,
            'result_ref' => [
                'kind' => 'scope_path',
                'scope_path' => 'plan_workbench.stage1.theme_context_snapshot',
                'structured_path' => 'plan_structured.theme_context_snapshot',
                'execution_blueprint_path' => 'execution_blueprint_draft.theme_context_snapshot',
                'context_hash' => $stableHash,
            ],
            'theme_context_snapshot' => $themeContextSnapshot,
            'retry_count' => 0,
            'last_error' => '',
            'updated_at' => '',
        ];
    }

    /**
     * @param array<int|string, mixed> $jobs
     * @param array<string, mixed> $job
     * @return list<array<string, mixed>>
     */
    private function upsertStageOneQueueJob(array $jobs, array $job): array
    {
        $normalized = [];
        $incomingJobKey = \trim((string)($job['job_key'] ?? ''));
        $incomingJobType = \trim((string)($job['job_type'] ?? ''));

        foreach ($jobs as $existing) {
            if (!\is_array($existing)) {
                continue;
            }
            $existingJobKey = \trim((string)($existing['job_key'] ?? ''));
            $existingJobType = \trim((string)($existing['job_type'] ?? ''));
            if (($incomingJobKey !== '' && $existingJobKey === $incomingJobKey)
                || ($incomingJobType !== '' && $existingJobType === $incomingJobType)) {
                continue;
            }
            $normalized[] = $existing;
        }
        $normalized[] = $job;

        return $this->normalizeStageOneQueueJobs($normalized);
    }

    /**
     * @param array<int|string, mixed> $jobs
     * @return list<array<string, mixed>>
     */
    private function normalizeStageOneQueueJobs(array $jobs): array
    {
        $normalized = [];
        foreach ($jobs as $job) {
            if (!\is_array($job)) {
                continue;
            }
            $jobKey = \trim((string)($job['job_key'] ?? ''));
            $jobType = \trim((string)($job['job_type'] ?? ''));
            if ($jobKey === '' || $jobType === '') {
                continue;
            }
            $normalized[] = $job;
        }

        \usort($normalized, static function (array $left, array $right): int {
            $leftOrder = (int)($left['sort_order'] ?? 1000);
            $rightOrder = (int)($right['sort_order'] ?? 1000);
            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            return \strcmp((string)($left['job_key'] ?? ''), (string)($right['job_key'] ?? ''));
        });

        return \array_values($normalized);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function isStageOnePageFanoutJob(array $job): bool
    {
        $jobType = \trim((string)($job['job_type'] ?? ''));
        if ($jobType === 'stage1.page_plan') {
            return true;
        }

        return \trim((string)($job['fanout_group'] ?? '')) === 'stage1.page_fanout'
            || \trim((string)($job['stage'] ?? '')) === 'stage1_page_fanout';
    }

    private function isStageOneWorkTerminalStatus(string $status): bool
    {
        return \in_array(\trim($status), ['done', 'failed', 'cancelled', 'stale'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function markStageOneRowStaleForSharedContextChange(array $row, string $currentSharedContextHash): array
    {
        $previousSharedContextHash = \trim((string)(
            $row['shared_context_hash']
            ?? $row['inputs']['shared_context_hash']
            ?? $row['source_ref']['shared_context_hash']
            ?? ''
        ));
        if (
            $currentSharedContextHash === ''
            || $previousSharedContextHash === ''
            || $previousSharedContextHash === $currentSharedContextHash
            || $this->isStageOneWorkTerminalStatus((string)($row['status'] ?? ''))
        ) {
            return $row;
        }

        $row['status'] = 'stale';
        $row['progress_percent'] = 0;
        $row['stale_reason'] = 'shared_context_hash_changed';
        $row['previous_shared_context_hash'] = $previousSharedContextHash;
        $row['current_shared_context_hash'] = $currentSharedContextHash;
        $row['last_error'] = 'shared_context_hash changed; rebuild required';

        return $row;
    }

    /**
     * @param array<int|string, mixed> $jobs
     * @return array<int|string, mixed>
     */
    private function markStageOnePageFanoutJobsStaleForSharedContextChange(array $jobs, string $currentSharedContextHash): array
    {
        foreach ($jobs as $jobKey => $job) {
            if (!\is_array($job) || !$this->isStageOnePageFanoutJob($job)) {
                continue;
            }
            $jobs[$jobKey] = $this->markStageOneRowStaleForSharedContextChange($job, $currentSharedContextHash);
        }

        return $jobs;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function markStageOnePageTasksStaleForSharedContextChange(array $tasks, string $currentSharedContextHash): array
    {
        foreach ($tasks as $index => $task) {
            if (!\is_array($task) || (string)($task['task_type'] ?? '') !== 'page_block') {
                continue;
            }
            $tasks[$index] = $this->markStageOneRowStaleForSharedContextChange($task, $currentSharedContextHash);
        }

        return \array_values($tasks);
    }

    /**
     * @return array{0:array<string, mixed>,1:array<string, mixed>}
     */
    private function markStageOnePageWorkStaleForSharedContextChange(
        array $structured,
        array $executionBlueprint,
        string $currentSharedContextHash
    ): array {
        if ($currentSharedContextHash === '') {
            return [$structured, $executionBlueprint];
        }

        if (\is_array($structured['stage1_queue']['jobs'] ?? null)) {
            $structured['stage1_queue']['jobs'] = $this->markStageOnePageFanoutJobsStaleForSharedContextChange(
                $structured['stage1_queue']['jobs'],
                $currentSharedContextHash
            );
        }
        if (\is_array($executionBlueprint['stage1_queue']['jobs'] ?? null)) {
            $executionBlueprint['stage1_queue']['jobs'] = $this->markStageOnePageFanoutJobsStaleForSharedContextChange(
                $executionBlueprint['stage1_queue']['jobs'],
                $currentSharedContextHash
            );
        }
        if (\is_array($executionBlueprint['tasks'] ?? null)) {
            $executionBlueprint['tasks'] = $this->markStageOnePageTasksStaleForSharedContextChange(
                $executionBlueprint['tasks'],
                $currentSharedContextHash
            );
        }
        if (\is_array($structured['execution_steps'] ?? null)) {
            $structured['execution_steps'] = $this->buildExecutionSteps(
                \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : []
            );
        }

        return [$structured, $executionBlueprint];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    private function buildStageOneRequestSummary(array $scope, array $websiteProfile, string $instruction): array
    {
        $rawRequirement = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        return [
            'raw_requirement' => $rawRequirement,
            'explicit_facts' => \array_values(\array_filter([
                \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')) !== '' ? ('site_title=' . (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')) : '',
                $rawRequirement !== '' ? ('brief=' . $rawRequirement) : '',
            ])),
            'safe_inferences' => [
                '根据已选页面类型生成页面结构与内容区块方案。',
                '先生成共享主题、Header、Footer，再组织每个页面的具体内容块。',
            ],
            'pending_variables' => [
                '如果方案需要后续编辑的联系方式、案例、资质、价格或地址，请写成自然访客文案；不要把字段名、待补充说明或蓝图句子展示出来。',
            ],
            'latest_instruction' => $instruction,
        ];

        return [
            'raw_requirement' => $rawRequirement,
            'explicit_facts' => \array_values(\array_filter([
                \trim((string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')) !== '' ? ('site_title=' . (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? '')) : '',
                $rawRequirement !== '' ? ('brief=' . $rawRequirement) : '',
            ])),
            'safe_inferences' => [
                '根据已选页面类型生成页面区块方案。',
                '先生成共享主题、Header、Footer，再并发生成页面类型方案。',
            ],
            'pending_variables' => [
                '如果方案需要后续编辑的联系方式、案例、资质、价格或地址，请写成自然访客文案；不要把字段名、待补充说明或蓝图句子展示出来。',
            ],
            'latest_instruction' => $instruction,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function buildPlanWorkbenchArtifacts(
        array $scope,
        array $structured,
        array $executionBlueprint,
        array $planJson,
        string $markdown,
        string $planLocale
    ): array {
        $pagePlans = \is_array($structured['page_plans'] ?? null) ? $structured['page_plans'] : [];
        $blockIndex = \is_array($structured['block_index'] ?? null) ? $structured['block_index'] : [];
        $stageOneQueueJobs = \is_array($structured['stage1_queue']['jobs'] ?? null)
            ? $structured['stage1_queue']['jobs']
            : $this->normalizeStageOneQueueJobs(
                \is_array($executionBlueprint['queue_jobs'] ?? null)
                    ? $executionBlueprint['queue_jobs']
                    : (\is_array($structured['queue_jobs'] ?? null) ? $structured['queue_jobs'] : [])
            );
        $stage1 = [
            'request_summary' => \is_array($structured['request_summary'] ?? null) ? $structured['request_summary'] : $this->buildStageOneRequestSummary($scope, [], ''),
            'requirement_expansion' => \is_array($structured['requirement_expansion'] ?? null) ? $structured['requirement_expansion'] : [],
            'theme_context_snapshot' => \is_array($structured['theme_context_snapshot'] ?? null) ? $structured['theme_context_snapshot'] : [],
            'shared_plan' => \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            'page_plans' => $pagePlans,
            'page_tabs_state' => $this->buildStageOnePageTabsState($pagePlans),
            'interaction_state' => $this->buildStageOneInteractionState($pagePlans, $blockIndex),
            'progress' => $this->buildStageOneProgressSummary(
                \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
                $pagePlans,
                $stageOneQueueJobs
            ),
            'queue_jobs' => $stageOneQueueJobs,
            'block_index' => $blockIndex,
        ];
        $planBookStructured = $this->buildStageOnePlanBookStructured($structured, $executionBlueprint, $planLocale);
        $contractContext = $this->buildStageOneContractContext($scope, $executionBlueprint, $planLocale);
        $contracts = $this->buildStageOneContracts(
            $scope,
            $structured,
            $executionBlueprint,
            $planJson,
            $planBookStructured,
            $planLocale,
            $contractContext
        );

        return [
            'version' => 2,
            'plan_locale' => $planLocale,
            'contract_context' => $contractContext,
            'contracts' => $contracts,
            'stage1' => $stage1,
            'confirmed' => [
                'plan_book_markdown' => $markdown,
                'plan_book' => [
                    'structured' => $planBookStructured,
                ],
                'structured_plan' => $structured,
                'plan_json' => $planJson,
                'execution_blueprint' => $executionBlueprint,
                'block_index' => \is_array($structured['block_index'] ?? null) ? $structured['block_index'] : [],
                'shared_prompt_context' => \is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : [],
                'confirmed_signature' => (string)($executionBlueprint['signature'] ?? ''),
                'contract_context' => $contractContext,
                'contracts' => $contracts,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildStageOneContractContext(array $scope, array $executionBlueprint, string $planLocale): array
    {
        $registry = $this->getSkillRegistry();
        $selectedCodes = $registry->resolveSelectedSkillCodes($this->normalizeStageOneSelectedSkillCodes(
            $scope[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] ?? []
        ));
        $skillSnapshots = $registry->buildSkillSnapshots($selectedCodes);
        $snapshotHashSource = [];
        foreach ($skillSnapshots as $snapshot) {
            $snapshotHashSource[] = [
                'code' => (string)($snapshot['code'] ?? ''),
                'source' => (string)($snapshot['source'] ?? ''),
                'body_hash' => (string)($snapshot['body_hash'] ?? ''),
            ];
        }
        $designDirectionState = $this->getDesignDirectionService()->buildWorkspaceDirectionState($scope);
        $designDirectionSnapshot = \is_array($designDirectionState['snapshot'] ?? null) ? $designDirectionState['snapshot'] : [];

        return [
            'version' => 1,
            'stage' => ContractType::STAGE_STAGE1,
            'plan_locale' => $planLocale,
            'source_signature' => (string)($executionBlueprint['signature'] ?? ''),
            'adapter_type' => 'json_strict',
            'requires_human_review' => true,
            'selected_skill_codes' => $selectedCodes,
            'skill_snapshots' => $skillSnapshots,
            'skill_snapshot_hash' => \sha1((string)\json_encode($snapshotHashSource, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)),
            'design_direction_mode' => (string)($designDirectionState['mode'] ?? 'auto'),
            'design_direction_code' => (string)($designDirectionState['code'] ?? ''),
            'design_direction_snapshot' => $designDirectionSnapshot,
            'design_direction_version' => (int)($designDirectionState['version'] ?? 0),
            'design_direction_hash' => (string)($designDirectionState['hash'] ?? ''),
            'design_direction_match_reason' => (string)($designDirectionState['match_reason'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @param array<string, mixed> $planJson
     * @param array<string, mixed> $planBookStructured
     * @param array<string, mixed> $contractContext
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOneContracts(
        array $scope,
        array $structured,
        array $executionBlueprint,
        array $planJson,
        array $planBookStructured,
        string $planLocale,
        array $contractContext
    ): array {
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $requestSummary = \is_array($structured['request_summary'] ?? null)
            ? $structured['request_summary']
            : $this->buildStageOneRequestSummary($scope, [], '');
        $pageTypes = $this->normalizeStageOneContractStringList(
            \is_array($planJson['page_types'] ?? null)
                ? $planJson['page_types']
                : (\is_array($structured['page_types'] ?? null) ? $structured['page_types'] : ($scope['page_types'] ?? []))
        );
        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $blockIndex = \is_array($structured['block_index'] ?? null)
            ? $structured['block_index']
            : (\is_array($executionBlueprint['block_index'] ?? null) ? $executionBlueprint['block_index'] : []);
        $queueJobs = \is_array($structured['stage1_queue']['jobs'] ?? null)
            ? $structured['stage1_queue']['jobs']
            : (\is_array($executionBlueprint['stage1_queue']['jobs'] ?? null)
                ? $executionBlueprint['stage1_queue']['jobs']
                : (\is_array($executionBlueprint['queue_jobs'] ?? null) ? $executionBlueprint['queue_jobs'] : []));
        $briefDescription = \trim((string)(
            $scope['brief_description']
            ?? $scope['user_description']
            ?? $requestSummary['raw_requirement']
            ?? ''
        ));
        $siteTitle = \trim((string)(
            $siteStrategy['site_display_name']
            ?? $scope['site_title']
            ?? $scope['website_profile']['site_title']
            ?? ''
        ));

        $siteBrief = $this->buildStageOneContract(
            ContractType::TYPE_SITE_BRIEF,
            [
                'site_title' => $siteTitle,
                'brief_description' => $briefDescription,
                'request_summary' => $requestSummary,
                'requirement_expansion' => \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [],
                'site_strategy' => $siteStrategy,
                'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
                'content_locale' => \trim((string)($planJson['content_locale'] ?? $structured['content_locale'] ?? '')),
            ],
            $contractContext,
            [],
            [
                'payload.site_title',
                'payload.brief_description',
                'payload.requirement_expansion',
                'payload.site_strategy',
                'payload.seo_strategy',
            ],
            [
                'payload.assumptions',
                'payload.human_notes',
            ]
        );

        $designManifest = $this->buildStageOneContract(
            ContractType::TYPE_DESIGN_MANIFEST,
            [
                'theme_design' => \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
                'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
                'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
                'shared_components' => $sharedComponents,
                'shared_prompt_context' => \is_array($structured['shared_plan']['shared_prompt_context'] ?? null)
                    ? $structured['shared_plan']['shared_prompt_context']
                    : (\is_array($executionBlueprint['shared_prompt_context'] ?? null) ? $executionBlueprint['shared_prompt_context'] : []),
                'theme_context_snapshot' => \is_array($structured['theme_context_snapshot'] ?? null)
                    ? $structured['theme_context_snapshot']
                    : (\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []),
            ],
            $contractContext,
            [$this->buildStageOneSourceContractRef($siteBrief)],
            [
                'payload.theme_design',
                'payload.theme_style',
                'payload.palette',
                'payload.shared_components',
            ],
            [
                'payload.human_notes',
            ]
        );

        $pageContract = $this->buildStageOneContract(
            ContractType::TYPE_PAGE_CONTRACT,
            [
                'page_types' => $pageTypes,
                'pages' => \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
                'page_type_overviews' => \is_array($planBookStructured['page_type_overviews'] ?? null) ? $planBookStructured['page_type_overviews'] : [],
                'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
                'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
                'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
            ],
            $contractContext,
            [
                $this->buildStageOneSourceContractRef($siteBrief),
                $this->buildStageOneSourceContractRef($designManifest),
            ],
            [
                'payload.page_types',
                'payload.pages',
                'payload.navigation_plan',
                'payload.footer_plan',
                'payload.seo_strategy',
            ],
            [
                'payload.pages.*.human_notes',
            ]
        );

        $blockPlan = $this->buildStageOneContract(
            ContractType::TYPE_BLOCK_PLAN,
            [
                'shared_blocks' => \is_array($planBookStructured['shared_blocks'] ?? null) ? \array_values($planBookStructured['shared_blocks']) : [],
                'pages' => $this->buildStageOneBlockPlanContractPages($planBookStructured),
                'block_index' => $blockIndex,
                'queue_jobs' => $queueJobs,
                'counts' => \is_array($planBookStructured['counts'] ?? null) ? $planBookStructured['counts'] : [],
            ],
            $contractContext,
            [
                $this->buildStageOneSourceContractRef($siteBrief),
                $this->buildStageOneSourceContractRef($designManifest),
                $this->buildStageOneSourceContractRef($pageContract),
            ],
            [
                'payload.shared_blocks',
                'payload.pages',
                'payload.block_index',
                'payload.queue_jobs',
            ],
            [
                'payload.pages.*.blocks.*.human_notes',
                'payload.pages.*.blocks.*.editable_fields',
            ]
        );

        return [
            ContractType::TYPE_SITE_BRIEF => $siteBrief,
            ContractType::TYPE_DESIGN_MANIFEST => $designManifest,
            ContractType::TYPE_PAGE_CONTRACT => $pageContract,
            ContractType::TYPE_BLOCK_PLAN => $blockPlan,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $contractContext
     * @param list<array{id:string,type:string,version:string,status:string}> $sourceContracts
     * @param list<string> $frozenFields
     * @param list<string> $mutableFields
     * @return array<string, mixed>
     */
    private function buildStageOneContract(
        string $type,
        array $payload,
        array $contractContext,
        array $sourceContracts,
        array $frozenFields,
        array $mutableFields
    ): array {
        $meta = (new ContractMetaBuilder())->build(
            $type,
            ContractType::STAGE_STAGE1,
            ContractType::STATUS_DRAFT,
            $this->stageOneContractCreator($type),
            'json_strict',
            [
                'payload_hash' => $this->buildStageOneContractHash($payload),
                'source_signature' => (string)($contractContext['source_signature'] ?? ''),
                'skill_snapshot_hash' => (string)($contractContext['skill_snapshot_hash'] ?? ''),
            ]
        );
        $meta['requires_human_review'] = true;
        $meta['frozen_fields'] = $frozenFields;
        $meta['mutable_fields'] = $mutableFields;
        $meta['source_contracts'] = $sourceContracts;

        $qa = new QaGateHelper();

        return [
            'contract_meta' => $meta,
            'permission_matrix' => $this->stageOnePermissionMatrixForContract($type),
            'frozen_fields' => $frozenFields,
            'mutable_fields' => $mutableFields,
            'source_contracts' => $sourceContracts,
            'qa_gates' => [
                'schema_shape' => $qa->gate(
                    'schema_shape',
                    QaGateHelper::STATUS_PASS,
                    'Generated from normalized Stage1 plan artifacts.'
                ),
                'human_review' => $qa->gate(
                    'human_review',
                    QaGateHelper::STATUS_PENDING,
                    'Requires human confirmation before downstream stages treat frozen fields as authoritative.'
                ),
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{id:string,type:string,version:string,status:string}
     */
    private function buildStageOneSourceContractRef(array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];

        return [
            'id' => (string)($meta['id'] ?? $meta['contract_id'] ?? ''),
            'type' => (string)($meta['type'] ?? ''),
            'version' => (string)($meta['version'] ?? ContractType::VERSION_V1),
            'status' => (string)($meta['status'] ?? ''),
        ];
    }

    private function stageOneContractCreator(string $type): string
    {
        return match ($type) {
            ContractType::TYPE_SITE_BRIEF => 'site_strategist',
            ContractType::TYPE_DESIGN_MANIFEST => 'design_director',
            ContractType::TYPE_PAGE_CONTRACT => 'page_architect',
            ContractType::TYPE_BLOCK_PLAN => 'block_planner',
            default => 'stage1_contract_binder',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stageOnePermissionMatrixForContract(string $type): array
    {
        $matrix = (new PermissionMatrix())->forStage(ContractType::STAGE_STAGE1);
        $booleans = [
            'can_create_new_pages' => $type === ContractType::TYPE_PAGE_CONTRACT,
            'can_delete_pages' => $type === ContractType::TYPE_PAGE_CONTRACT,
            'can_change_page_type' => $type === ContractType::TYPE_PAGE_CONTRACT,
            'can_create_new_blocks' => $type === ContractType::TYPE_BLOCK_PLAN,
            'can_delete_blocks' => $type === ContractType::TYPE_BLOCK_PLAN,
            'can_reorder_blocks' => $type === ContractType::TYPE_BLOCK_PLAN,
            'can_change_component_variant' => $type === ContractType::TYPE_BLOCK_PLAN,
            'can_create_new_design_tokens' => $type === ContractType::TYPE_DESIGN_MANIFEST,
            'can_select_existing_design_tokens' => true,
            'can_write_copy' => true,
            'can_change_cta_text' => $type !== ContractType::TYPE_DESIGN_MANIFEST,
            'can_change_seo_keywords' => $type === ContractType::TYPE_SITE_BRIEF || $type === ContractType::TYPE_PAGE_CONTRACT,
            'can_create_image_prompts' => false,
            'can_generate_assets' => false,
            'can_patch_render_data' => false,
        ];

        return \array_replace($matrix, [
            'contract_type' => $type,
            'permissions' => $booleans,
        ]);
    }

    /**
     * @param array<string, mixed> $planBookStructured
     * @return array<string, array<string, mixed>>
     */
    private function buildStageOneBlockPlanContractPages(array $planBookStructured): array
    {
        $pages = [];
        foreach (\is_array($planBookStructured['pages'] ?? null) ? $planBookStructured['pages'] : [] as $pageKey => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $blocks = [];
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blocks[] = [
                    'task_key' => (string)($block['task_key'] ?? ''),
                    'block_key' => (string)($block['block_key'] ?? ''),
                    'source_block_key' => (string)($block['source_block_key'] ?? ''),
                    'component_kind' => (string)($block['component_kind'] ?? ''),
                    'sort_order' => (int)($block['sort_order'] ?? 0),
                    'title' => (string)($block['title'] ?? ''),
                    'goal' => (string)($block['goal'] ?? ''),
                    'context_hash' => (string)($block['context_hash'] ?? ''),
                    'editable_fields' => \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [],
                ];
            }
            $pages[(string)$pageKey] = [
                'page_key' => (string)($page['page_key'] ?? $pageKey),
                'page_label' => (string)($page['page_label'] ?? $pageKey),
                'page_goal' => (string)($page['page_goal'] ?? ''),
                'page_context_hash' => (string)($page['page_context_hash'] ?? ''),
                'theme_context_hash' => (string)($page['theme_context_hash'] ?? ''),
                'shared_context_hash' => (string)($page['shared_context_hash'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        return $pages;
    }

    private function buildStageOneContractHash(array $payload): string
    {
        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private function normalizeStageOneSelectedSkillCodes(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
            if (!\is_array($items)) {
                $items = [];
            }
        } elseif (\is_scalar($raw)) {
            $items = [(string)$raw];
        } else {
            $items = [];
        }

        return $this->normalizeStageOneContractStringList($items);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeStageOneContractStringList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value === '' || \in_array($value, $values, true)) {
                continue;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveStageOneExecutionBlueprint(array $scope): array
    {
        $draft = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        if ($draft !== []) {
            return $draft;
        }

        return \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, mixed>
     */
    private function buildStageOnePlanBookStructured(array $structured, array $executionBlueprint, string $planLocale): array
    {
        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $pagePlans = \is_array($structured['page_plans'] ?? null)
            ? $structured['page_plans']
            : (\is_array($executionBlueprint['page_plans'] ?? null) ? $executionBlueprint['page_plans'] : []);
        $sharedPromptContext = \is_array($structured['shared_plan']['shared_prompt_context'] ?? null)
            ? $structured['shared_plan']['shared_prompt_context']
            : (\is_array($executionBlueprint['shared_prompt_context'] ?? null) ? $executionBlueprint['shared_prompt_context'] : []);
        $themeContextSnapshot = \is_array($structured['theme_context_snapshot'] ?? null)
            ? $structured['theme_context_snapshot']
            : (\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $contentLocale = \trim((string)($structured['content_locale'] ?? $structured['site_strategy']['content_locale'] ?? $executionBlueprint['content_locale'] ?? ''));
        $requirementExpansion = \is_array($structured['requirement_expansion'] ?? null)
            ? $structured['requirement_expansion']
            : (\is_array($executionBlueprint['requirement_expansion'] ?? null) ? $executionBlueprint['requirement_expansion'] : []);

        $sharedBlocks = [];
        foreach ($this->normalizeStageOneSharedComponents($sharedComponents) as $component => $componentPlan) {
            $sharedBlocks[] = $this->buildStageOnePlanBookSharedBlock((string)$component, $componentPlan);
        }

        $pages = [];
        $pageBlockCount = 0;
        foreach ($pagePlans as $pageKey => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $blocks = [];
            $blockSortOrder = 10;
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blocks[] = $this->buildStageOnePlanBookPageBlock((string)$pageKey, $block, $blockSortOrder);
                $blockSortOrder += 10;
            }
            $pageBlockCount += \count($blocks);
            $pages[(string)$pageKey] = [
                'page_key' => (string)$pageKey,
                'page_label' => (string)($pagePlan['page_label'] ?? $pageKey),
                'page_goal' => \trim((string)($pagePlan['page_goal'] ?? '')),
                'content_locale' => $contentLocale,
                'page_status' => (string)($pagePlan['page_status'] ?? 'done'),
                'theme_alignment_summary' => \trim((string)($pagePlan['theme_alignment_summary'] ?? '')),
                'page_design_plan' => \is_array($pagePlan['page_design_plan'] ?? null) ? $pagePlan['page_design_plan'] : [],
                'shared_context_hash' => (string)($pagePlan['shared_context_hash'] ?? $sharedPromptContext['context_hash'] ?? ''),
                'theme_context_hash' => (string)($pagePlan['theme_context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
                'page_context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                'blocks' => $blocks,
                'display_blocks' => $this->buildStageOnePageDisplayBlocks($sharedBlocks, $blocks),
            ];
        }

        $planBook = [
            'version' => 1,
            'source' => 'stage1.block_tree',
            'source_signature' => (string)($executionBlueprint['signature'] ?? ''),
            'plan_locale' => $planLocale,
            'content_locale' => $contentLocale,
            'theme_context_hash' => (string)($themeContextSnapshot['context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
            'shared_context_hash' => (string)($sharedPromptContext['context_hash'] ?? ''),
            'requirement_expansion' => $requirementExpansion,
            'theme_context_snapshot' => $themeContextSnapshot,
            'shared_prompt_context' => $sharedPromptContext,
            'page_type_overviews' => \is_array($sharedPromptContext['page_type_overviews'] ?? null) ? $sharedPromptContext['page_type_overviews'] : [],
            'theme_design' => $this->extractStageOneThemeDesign(
                \is_array($structured['shared_plan']['theme_design'] ?? null)
                    ? $structured['shared_plan']['theme_design']
                    : $themeContextSnapshot
            ),
            'shared_blocks' => $sharedBlocks,
            'pages' => $pages,
            'counts' => [
                'shared_blocks' => \count($sharedBlocks),
                'pages' => \count($pages),
                'page_blocks' => $pageBlockCount,
                'total_blocks' => \count($sharedBlocks) + $pageBlockCount,
            ],
        ];
        $planBook['context_hash'] = $this->buildStageOnePlanBookContextHash($planBook);

        return $planBook;
    }

    /**
     * @param array<string, mixed> $componentPlan
     * @return array<string, mixed>
     */
    private function buildStageOnePlanBookSharedBlock(string $component, array $componentPlan): array
    {
        $taskKey = \trim((string)($componentPlan['task_key'] ?? ''));
        if ($taskKey === '') {
            $taskKey = 'shared:' . $component;
        }

        return [
            'task_key' => $taskKey,
            'block_key' => $taskKey,
            'block_scope' => 'shared',
            'component' => $component,
            'sort_order' => (int)($componentPlan['sort_order'] ?? $this->defaultStageOneSharedSortOrder($component, 0)),
            'title' => (string)($componentPlan['component'] ?? $component),
            'goal' => \trim((string)($componentPlan['goal'] ?? '')),
            'implementation_detail' => $this->buildBlockImplementationFocus($componentPlan, ''),
            'realtime_content' => \is_array($componentPlan['realtime_content'] ?? null) ? $componentPlan['realtime_content'] : [],
            'completion_rule' => \trim((string)($componentPlan['completion_rule'] ?? '')),
            'editable_fields' => $this->normalizeStageOnePlanBookEditableFields($componentPlan),
            'content_source' => \is_array($componentPlan['content_source'] ?? null) ? \array_values($componentPlan['content_source']) : [],
            'style_direction' => \trim((string)($componentPlan['style_direction'] ?? '')),
            'responsive_rule' => \trim((string)($componentPlan['responsive_rule'] ?? '')),
            'context_hash' => $this->buildStageOnePlanBookBlockContextHash($taskKey, $componentPlan),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function buildStageOnePlanBookPageBlock(string $pageKey, array $block, int $fallbackSortOrder): array
    {
        $sourceBlockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? 'block'));
        if ($sourceBlockKey === '') {
            $sourceBlockKey = 'block';
        }
        $taskKey = 'page:' . $pageKey . ':' . $sourceBlockKey;

        return [
            'task_key' => $taskKey,
            'block_key' => $taskKey,
            'source_block_key' => $sourceBlockKey,
            'block_scope' => 'page',
            'page_key' => $pageKey,
            'component_kind' => \trim((string)($block['component_kind'] ?? $block['section_code'] ?? 'section')),
            'sort_order' => (int)($block['sort_order'] ?? $block['order'] ?? $fallbackSortOrder),
            'title' => \trim((string)($block['section_code'] ?? $sourceBlockKey)),
            'goal' => \trim((string)($block['goal'] ?? '')),
            'implementation_detail' => $this->buildBlockImplementationFocus($block, ''),
            'realtime_content' => \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [],
            'completion_rule' => \trim((string)($block['completion_rule'] ?? '')),
            'editable_fields' => $this->normalizeStageOnePlanBookEditableFields($block),
            'content_source' => \is_array($block['content_source'] ?? null) ? \array_values($block['content_source']) : [],
            'style_direction' => \trim((string)($block['style_direction'] ?? '')),
            'design_tags' => \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [],
            'responsive_rule' => \trim((string)($block['responsive_rule'] ?? $block['style_brief']['responsive_rule'] ?? '')),
            'seo_brief' => \is_array($block['seo_brief'] ?? null) ? $block['seo_brief'] : [],
            'context_hash' => $this->buildStageOnePlanBookBlockContextHash($taskKey, $block),
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function normalizeStageOnePlanBookEditableFields(array $block): array
    {
        $editableFields = \is_array($block['editable_fields'] ?? null) ? $block['editable_fields'] : [];
        if ($editableFields === []) {
            $editableFields = $this->extractEditableFieldsFromFieldPlan(\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : []);
        }

        return \array_values(\array_unique(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $editableFields
        ), static fn(string $value): bool => $value !== '')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildStageOnePlanBookBlockContextHash(string $blockKey, array $payload): string
    {
        $hashPayload = $payload;
        unset($hashPayload['context_hash']);

        return \sha1((string)\json_encode([
            'block_key' => $blockKey,
            'payload' => $hashPayload,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $planBook
     */
    private function buildStageOnePlanBookContextHash(array $planBook): string
    {
        unset($planBook['context_hash']);

        return \sha1((string)\json_encode($planBook, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $pagePlans
     * @return list<array<string, mixed>>
     */
    private function buildStageOnePageTabsState(array $pagePlans): array
    {
        $tabs = [];
        foreach ($pagePlans as $pageKey => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $tabs[] = [
                'page_key' => (string)$pageKey,
                'label' => (string)($pagePlan['page_label'] ?? $pageKey),
                'status' => (string)($pagePlan['page_status'] ?? 'done'),
                'block_count' => \count(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []),
                'page_context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                'assembly_version' => (int)($pagePlan['assembly_version'] ?? 1),
            ];
        }

        return $tabs;
    }

    /**
     * @param array<string, mixed> $pagePlans
     * @param array<string, mixed> $blockIndex
     * @return array<string, mixed>
     */
    private function buildStageOneInteractionState(array $pagePlans, array $blockIndex): array
    {
        $flatBlocks = \is_array($blockIndex['flat'] ?? null) ? $blockIndex['flat'] : [];
        $firstPageKey = '';
        foreach ($pagePlans as $pageKey => $pagePlan) {
            if (\is_array($pagePlan)) {
                $firstPageKey = (string)$pageKey;
                break;
            }
        }

        return [
            'active_page_key' => $firstPageKey,
            'selected_block_key' => '',
            'draft_inputs' => [],
            'block_actions' => $this->buildStageOneBlockActions($flatBlocks),
            'page_actions' => $this->buildStageOnePageActions($pagePlans),
        ];
    }

    /**
     * @param array<string, mixed> $flatBlocks
     * @return array<string, list<string>>
     */
    private function buildStageOneBlockActions(array $flatBlocks): array
    {
        $actions = [];
        foreach ($flatBlocks as $blockKey => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $key = (string)$blockKey;
            if ($key === '') {
                continue;
            }
            $actions[$key] = ['refine', 'rebuild', 'delete'];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $pagePlans
     * @return array<string, list<string>>
     */
    private function buildStageOnePageActions(array $pagePlans): array
    {
        $actions = [];
        foreach ($pagePlans as $pageKey => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $key = (string)$pageKey;
            if ($key === '') {
                continue;
            }
            $actions[$key] = ['refine_page', 'rebuild_page', 'create_block'];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $sharedPlan
     * @param array<string, mixed> $pagePlans
     * @param array<int|string, mixed> $queueJobs
     * @return array<string, mixed>
     */
    private function buildStageOneProgressSummary(array $sharedPlan, array $pagePlans, array $queueJobs = []): array
    {
        $sharedDone = (int)(\is_array($sharedPlan['theme_design'] ?? null) || $sharedPlan !== []);
        $pageTotal = \count($pagePlans);
        $pageDone = 0;
        foreach ($pagePlans as $pagePlan) {
            if (\is_array($pagePlan) && (string)($pagePlan['page_status'] ?? 'done') === 'done') {
                $pageDone++;
            }
        }
        $normalizedQueueJobs = $this->normalizeStageOneQueueJobs($queueJobs);
        $queueJobDone = 0;
        foreach ($normalizedQueueJobs as $queueJob) {
            if ((string)($queueJob['status'] ?? '') === 'done') {
                $queueJobDone++;
            }
        }
        $totalUnits = \max(1, 1 + $pageTotal);
        return [
            'shared_done' => $sharedDone,
            'page_total' => $pageTotal,
            'page_done' => $pageDone,
            'queue_job_total' => \count($normalizedQueueJobs),
            'queue_job_done' => $queueJobDone,
            'overall_percent' => (int)\round((($sharedDone + $pageDone) / $totalUnits) * 100),
            'queue_model' => 'shared-first-page-fanout',
        ];
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function buildPlanJson(array $structured): array
    {
        $pages = \is_array($structured['pages'] ?? null) ? $structured['pages'] : [];
        $stageOnePagePlans = \is_array($structured['page_plans'] ?? null) ? $structured['page_plans'] : [];
        $sharedComponents = $this->resolveStageOneSharedComponents($structured, []);
        $sharedBlocks = $this->buildStageOneSharedBlocksPlanJson($sharedComponents);
        $pageBlocks = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $stageOnePagePlan = \is_array($stageOnePagePlans[$pageType] ?? null) ? $stageOnePagePlans[$pageType] : [];
            $blockRows = [];
            foreach ($this->sortStageOneBlocksForPlanBookMarkdown(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []) as $offset => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockRows[] = [
                    'block_key' => (string)($block['block_key'] ?? $block['section_code'] ?? 'block'),
                    'sort_order' => $this->resolveStageOnePlanBookBlockSortOrder($block, (int)$offset),
                    'content' => $this->buildBlockContentSummary($block),
                    'implementation_note' => $this->buildBlockImplementationFocus($block, (string)($structured['i18n']['locale'] ?? '')),
                    'keywords' => \array_values(\array_filter(\array_map(
                        static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                        \is_array($block['keywords'] ?? null)
                            ? $block['keywords']
                            : (\is_array($block['seo_brief']['keywords'] ?? null) ? $block['seo_brief']['keywords'] : [])
                    ), static fn(string $value): bool => $value !== '')),
                    'field_plan' => $this->normalizeStageOneFieldPlanForCustomerView(\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : []),
                    'execution_script' => \is_array($block['execution_script'] ?? null) ? $block['execution_script'] : [],
                ];
            }
            $pageBlocks[(string)$pageType] = [
                'page_goal' => \trim((string)($pagePlan['page_goal'] ?? '')),
                'theme_alignment_summary' => \trim((string)(
                    $stageOnePagePlan['theme_alignment_summary']
                    ?? $pagePlan['theme_alignment_summary']
                    ?? ''
                )),
                'primary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'secondary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'blocks' => $blockRows,
                'display_blocks' => $this->buildStageOnePageDisplayBlocks($sharedBlocks, $blockRows),
            ];
        }

        return [
            'i18n' => \is_array($structured['i18n'] ?? null) ? $structured['i18n'] : [],
            'content_locale' => \trim((string)($structured['content_locale'] ?? $structured['site_strategy']['content_locale'] ?? '')),
            'requirement_expansion' => \is_array($structured['requirement_expansion'] ?? null) ? $structured['requirement_expansion'] : [],
            'site_strategy' => \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
            'theme_style' => \is_array($structured['theme_style'] ?? null) ? $structured['theme_style'] : [],
            'palette' => \is_array($structured['palette'] ?? null) ? $structured['palette'] : [],
            'theme_design' => $this->extractStageOneThemeDesign(
                \is_array($structured['shared_plan']['theme_design'] ?? null)
                    ? $structured['shared_plan']['theme_design']
                    : (\is_array($structured['theme_context_snapshot'] ?? null) ? $structured['theme_context_snapshot'] : [])
            ),
            'navigation_plan' => \is_array($structured['navigation_plan'] ?? null) ? $structured['navigation_plan'] : [],
            'footer_plan' => \is_array($structured['footer_plan'] ?? null) ? $structured['footer_plan'] : [],
            'theme_context_snapshot' => \is_array($structured['theme_context_snapshot'] ?? null) ? $structured['theme_context_snapshot'] : [],
            'shared_components' => $sharedComponents,
            'shared_prompt_context' => \is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : [],
            'stage1_contract' => \is_array($structured['stage1_contract'] ?? null) ? $structured['stage1_contract'] : [],
            'stage1_validation_report' => \is_array($structured['stage1_validation_report'] ?? null) ? $structured['stage1_validation_report'] : [],
            'stage1_first_pass' => (int)($structured['stage1_first_pass'] ?? 0),
            'stage1_generation_attempts' => \is_array($structured['stage1_generation_attempts'] ?? null) ? $structured['stage1_generation_attempts'] : [],
            'shared_blocks' => $sharedBlocks,
            'seo_strategy' => \is_array($structured['seo_strategy'] ?? null) ? $structured['seo_strategy'] : [],
            'page_types' => \is_array($structured['page_types'] ?? null) ? $structured['page_types'] : [],
            'pages' => $pageBlocks,
            'plan_blocks' => $this->buildPlanBlocksFromPlanJson([
                'site_strategy' => \is_array($structured['site_strategy'] ?? null) ? $structured['site_strategy'] : [],
                'theme_style' => \is_array($structured['theme_style'] ?? null) ? $structured['theme_style'] : [],
                'palette' => \is_array($structured['palette'] ?? null) ? $structured['palette'] : [],
                'navigation_plan' => \is_array($structured['navigation_plan'] ?? null) ? $structured['navigation_plan'] : [],
                'footer_plan' => \is_array($structured['footer_plan'] ?? null) ? $structured['footer_plan'] : [],
                'seo_strategy' => \is_array($structured['seo_strategy'] ?? null) ? $structured['seo_strategy'] : [],
                'page_types' => \is_array($structured['page_types'] ?? null) ? $structured['page_types'] : [],
                'pages' => $pageBlocks,
            ], (string)($structured['i18n']['locale'] ?? '')),
            'execution_steps' => \is_array($structured['execution_steps'] ?? null) ? $structured['execution_steps'] : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $sharedBlocks
     * @param list<array<string, mixed>> $pageBlocks
     * @return list<array<string, mixed>>
     */
    private function buildStageOnePageDisplayBlocks(array $sharedBlocks, array $pageBlocks): array
    {
        $headerBlocks = [];
        $globalSharedBlocks = [];
        $footerBlocks = [];
        foreach ($sharedBlocks as $sharedBlock) {
            if (!\is_array($sharedBlock)) {
                continue;
            }
            $component = \trim((string)($sharedBlock['component'] ?? ''));
            if ($component === 'header') {
                $headerBlocks[] = \array_replace($sharedBlock, ['display_role' => 'shared_header']);
            } elseif ($component === 'footer') {
                $footerBlocks[] = \array_replace($sharedBlock, ['display_role' => 'shared_footer']);
            } else {
                $globalSharedBlocks[] = \array_replace($sharedBlock, ['display_role' => 'shared_global']);
            }
        }

        $pageRows = [];
        foreach ($pageBlocks as $pageBlock) {
            if (\is_array($pageBlock)) {
                $pageRows[] = \array_replace($pageBlock, ['display_role' => 'page_block']);
            }
        }

        return \array_values(\array_merge($headerBlocks, $globalSharedBlocks, $pageRows, $footerBlocks));
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     * @return list<array<string, mixed>>
     */
    private function normalizeStageOneFieldPlanForCustomerView(array $fieldPlan): array
    {
        foreach ($fieldPlan as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fieldPlan[$index] = $this->syncStageOneFieldImplementationNote($row, $this->resolveStageOneFieldImplementationNote($row));
        }

        return $fieldPlan;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function buildBlockImplementationFocus(array $block, string $locale = ''): string
    {
        $implementationDetail = \trim((string)($block['implementation_note'] ?? $block['implementation_detail'] ?? ''));
        if ($implementationDetail !== '') {
            return $implementationDetail;
        }

        $layoutRule = \trim((string)($block['style_brief']['layout_rule'] ?? $block['responsive_rule'] ?? ''));
        if ($layoutRule !== '') {
            return $layoutRule;
        }

        $completionRule = \trim((string)($block['completion_rule'] ?? ''));
        if ($completionRule !== '') {
            return $completionRule;
        }

        $featurePoints = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            \is_array($block['execution_script']['feature_points'] ?? null) ? $block['execution_script']['feature_points'] : []
        ), static fn(string $value): bool => $value !== ''));
        if ($featurePoints !== []) {
            return \implode($this->isEnglishLocale($locale) ? '; ' : '；', \array_slice($featurePoints, 0, 3));
        }

        return \trim((string)($block['implementation_detail'] ?? $block['implementation_note'] ?? $block['goal'] ?? ''));
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function buildMarkdownPlan(array $planJson, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $i18n = \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [];
        $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
        $requirementExpansion = \is_array($planJson['requirement_expansion'] ?? null) ? $planJson['requirement_expansion'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $site = \trim((string)($siteStrategy['site_display_name'] ?? ''));
        $summary = \trim((string)($siteStrategy['summary'] ?? ''));
        $themeName = \trim((string)($planJson['theme_style']['name'] ?? ''));
        $paletteName = \trim((string)($planJson['palette']['name'] ?? ''));
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $sharedBlocks = \is_array($planJson['shared_blocks'] ?? null) ? $this->normalizeStageOneSharedBlockRows($planJson['shared_blocks']) : [];
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $pageTypes = \is_array($planJson['page_types'] ?? null) ? $planJson['page_types'] : \array_keys($pages);
        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);

        if ($pages === [] && $planBlocks !== []) {
            return $this->renderMarkdownFromPlanBlocks($planBlocks, $locale, $site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点'));
        }

        $lines = [];
        $lines[] = '# ' . (string)($labels['title'] ?? ($isEn ? 'Stage 1 Content Plan' : '阶段一内容方案'));
        $lines[] = '';
        $lines[] = '- ' . (string)($labels['site'] ?? ($isEn ? 'Site' : '站点')) . ': ' . ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = '- ' . (string)($labels['summary'] ?? ($isEn ? 'Summary' : '摘要')) . ': ' . ($summary !== '' ? $summary : ($isEn ? 'Pending details' : '待补充站点说明'));
        $lines[] = '- ' . ($isEn ? 'Theme Style' : '主题风格') . ': ' . ($themeName !== '' ? $themeName : 'Plan-Driven Hybrid');
        $lines[] = '- ' . ($isEn ? 'Palette' : '色盘') . ': ' . ($paletteName !== '' ? $paletteName : 'Ocean Slate');
        $lines[] = '- ' . ($isEn ? 'Page Count' : '页面数量') . ': ' . (string)\count($pageTypes);
        $lines[] = '';
        if ($requirementExpansion !== []) {
            $expandedBrief = \trim((string)($requirementExpansion['expanded_brief'] ?? ''));
            $planningSummary = \trim((string)($requirementExpansion['planning_summary'] ?? ''));
            $siteGoal = \trim((string)($requirementExpansion['site_goal'] ?? ''));
            $lines[] = '## ' . ($isEn ? 'Expanded Requirement Plan' : '需求拓展规划');
            if ($expandedBrief !== '') {
                $lines[] = '- ' . ($isEn ? 'Expanded Brief' : '拓展说明') . ': ' . $expandedBrief;
            }
            if ($planningSummary !== '') {
                $lines[] = '- ' . ($isEn ? 'Planning Summary' : '规划说明') . ': ' . $planningSummary;
            }
            if ($siteGoal !== '') {
                $lines[] = '- ' . ($isEn ? 'Site Goal' : '站点目标') . ': ' . $siteGoal;
            }
            $lines[] = '';
        }
        $lines[] = '## ' . (string)($labels['site_structure'] ?? ($isEn ? 'Site Structure' : '全站结构'));
        foreach ($pageTypes as $pageType) {
            $lines[] = '- ' . (string)$pageType;
        }
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['shared_global_plan'] ?? ($isEn ? 'Shared Global Plan' : '全站共享规划'));
        $lines[] = '- ' . ($isEn ? 'Header Navigation' : 'Header 导航') . ': ' . $this->buildLinkSummary(\is_array($navigationPlan['header_items'] ?? null) ? $navigationPlan['header_items'] : [], $locale);
        $lines[] = '- ' . ($isEn ? 'Footer Sections' : 'Footer 分组') . ': ' . $this->buildLinkSummary(\is_array($footerPlan['featured'] ?? null) ? $footerPlan['featured'] : [], $locale);
        $lines[] = '- ' . ($isEn ? 'Footer Policies' : 'Footer 政策') . ': ' . $this->buildLinkSummary(\is_array($footerPlan['policies'] ?? null) ? $footerPlan['policies'] : [], $locale);
        foreach ($sharedBlocks as $sharedBlock) {
            if (!\is_array($sharedBlock)) {
                continue;
            }
            $sharedLabel = \trim((string)($sharedBlock['label'] ?? $sharedBlock['component'] ?? $sharedBlock['block_key'] ?? ''));
            $sharedGoal = \trim((string)($sharedBlock['goal'] ?? ''));
            $sharedOrder = (int)($sharedBlock['sort_order'] ?? 0);
            $lines[] = '- ' . ($isEn ? 'Shared Block' : '共享块') . ' #' . (string)$sharedOrder . ': ' . ($sharedLabel !== '' ? $sharedLabel : 'shared') . ($sharedGoal !== '' ? (' - ' . $sharedGoal) : '');
        }
        $lines[] = '- ' . ($isEn ? 'SEO Core Strategy' : 'SEO 核心策略') . ': ' . \trim((string)($seoStrategy['core_intent'] ?? ($isEn ? 'not set' : '待补充')));
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['page_details'] ?? ($isEn ? 'Page And Block Content Details' : '页面与区块内容细化'));

        foreach ($pageTypes as $pageType) {
            $pageType = (string)$pageType;
            $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            if ($pagePlan === []) {
                $lines[] = '### ' . $pageType;
                $lines[] = $isEn ? '- Missing page plan.' : '- 暂无页面方案。';
                $lines[] = '';
                continue;
            }

            $lines[] = '### ' . ((string)($pagePlan['page_label'] ?? $pageType));
            $lines[] = '- ' . ($isEn ? 'Page Goal' : '页面目标') . ': ' . \trim((string)($pagePlan['page_goal'] ?? ''));
            $themeAlignmentSummary = \trim((string)($pagePlan['theme_alignment_summary'] ?? ''));
            if ($themeAlignmentSummary !== '') {
                $lines[] = '- ' . ($isEn ? 'Theme Alignment' : '主题遵守说明') . ': ' . $themeAlignmentSummary;
            }
            $lines[] = '- ' . ($isEn ? 'Primary Keywords' : '主关键词') . ': ' . $this->buildKeywordSummary(\is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : [], $locale);
            $lines[] = '- ' . ($isEn ? 'Secondary Keywords' : '次关键词') . ': ' . $this->buildKeywordSummary(\is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : [], $locale);
            $lines[] = '';

            foreach ($this->sortStageOneBlocksForPlanBookMarkdown(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []) as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockTitle = \trim((string)($block['section_code'] ?? $block['block_key'] ?? 'block'));
                $lines[] = '#### ' . $blockTitle;
                $lines[] = '- ' . ($isEn ? 'Goal' : '区块目标') . ': ' . \trim((string)($block['goal'] ?? ''));
                $blockImplementationFocus = $this->buildBlockImplementationFocus($block, $locale);
                if ($blockImplementationFocus !== '') {
                    $lines[] = '- ' . ($isEn ? 'Implementation Focus' : '落地重点') . ': ' . $blockImplementationFocus;
                }
                $lines[] = '- ' . ($isEn ? 'Content' : '区块内容') . ': ' . $this->buildBlockContentSummary($block);

                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                foreach ($fieldPlan as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }
                    $fieldName = \trim((string)($field['field'] ?? 'field'));
                    $fieldSample = \trim((string)($field['sample'] ?? ''));
                    if ($fieldSample === '') {
                        continue;
                    }
                    $lines[] = '  - ' . $fieldName . ': ' . $fieldSample;
                }
                $lines[] = '';
            }
        }

        return \implode("\n", $lines);

        $isEn = $this->isEnglishLocale($locale);
        $i18n = \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [];
        $labels = \is_array($i18n['labels'] ?? null) ? $i18n['labels'] : [];
        $siteStrategy = \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [];
        $site = \trim((string)($siteStrategy['site_display_name'] ?? ''));
        $summary = \trim((string)($siteStrategy['summary'] ?? ''));
        $themeName = \trim((string)($planJson['theme_style']['name'] ?? ''));
        $paletteName = \trim((string)($planJson['palette']['name'] ?? ''));
        $navigationPlan = \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [];
        $footerPlan = \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [];
        $seoStrategy = \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $pageTypes = \is_array($planJson['page_types'] ?? null) ? $planJson['page_types'] : \array_keys($pages);
        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);

        if ($pages === [] && $planBlocks !== []) {
            return $this->renderMarkdownFromPlanBlocks($planBlocks, $locale, $site !== '' ? $site : ($isEn ? 'Untitled site' : '?????'));
        }

        $lines = [];
        $lines[] = '# ' . (string)($labels['title'] ?? ($isEn ? 'Stage 1 Content Plan' : '???????'));
        $lines[] = '';
        $lines[] = '- ' . (string)($labels['site'] ?? ($isEn ? 'Site' : '??')) . ': ' . ($site !== '' ? $site : ($isEn ? 'Untitled site' : '?????'));
        $lines[] = '- ' . (string)($labels['summary'] ?? ($isEn ? 'Summary' : '??')) . ': ' . ($summary !== '' ? $summary : ($isEn ? 'Pending details' : '???'));
        $lines[] = '- ' . ($isEn ? 'Theme Style' : '????') . ': ' . ($themeName !== '' ? $themeName : 'Plan-Driven Hybrid');
        $lines[] = '- ' . ($isEn ? 'Palette' : '??') . ': ' . ($paletteName !== '' ? $paletteName : 'Ocean Slate');
        $lines[] = '- ' . ($isEn ? 'Page Count' : '????') . ': ' . (string)\count($pageTypes);
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['site_structure'] ?? ($isEn ? 'Site Structure' : '????'));
        foreach ($pageTypes as $pageType) {
            $lines[] = '- ' . (string)$pageType;
        }
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['shared_global_plan'] ?? ($isEn ? 'Shared Global Plan' : '??????'));
        $lines[] = '- ' . ($isEn ? 'Header Navigation' : 'Header ??') . ': ' . $this->buildLinkSummary(\is_array($navigationPlan['header_items'] ?? null) ? $navigationPlan['header_items'] : [], $locale);
        $lines[] = '- ' . ($isEn ? 'Footer Sections' : 'Footer ??') . ': ' . $this->buildLinkSummary(\is_array($footerPlan['featured'] ?? null) ? $footerPlan['featured'] : [], $locale);
        $lines[] = '- ' . ($isEn ? 'Footer Policies' : 'Footer ??') . ': ' . $this->buildLinkSummary(\is_array($footerPlan['policies'] ?? null) ? $footerPlan['policies'] : [], $locale);
        $lines[] = '- ' . ($isEn ? 'SEO Core Strategy' : 'SEO ????') . ': ' . \trim((string)($seoStrategy['core_intent'] ?? ($isEn ? 'not set' : '???')));
        $lines[] = '';
        $lines[] = '## ' . (string)($labels['page_details'] ?? ($isEn ? 'Page And Block Content Details' : '?????????'));

        foreach ($pageTypes as $pageType) {
            $pageType = (string)$pageType;
            $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            if ($pagePlan === []) {
                $lines[] = '### ' . $pageType;
                $lines[] = $isEn ? '- Missing page plan.' : '- ???????';
                $lines[] = '';
                continue;
            }

            $lines[] = '### ' . ((string)($pagePlan['page_label'] ?? $pageType));
            $lines[] = '- ' . ($isEn ? 'Page Goal' : '????') . ': ' . \trim((string)($pagePlan['page_goal'] ?? ''));
            $lines[] = '- ' . ($isEn ? 'Primary Keywords' : '????') . ': ' . $this->buildKeywordSummary(\is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : [], $locale);
            $lines[] = '- ' . ($isEn ? 'Secondary Keywords' : '?????') . ': ' . $this->buildKeywordSummary(\is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : [], $locale);
            $lines[] = '';

            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockTitle = \trim((string)($block['section_code'] ?? $block['block_key'] ?? 'block'));
                $lines[] = '#### ' . $blockTitle;
                $lines[] = '- ' . ($isEn ? 'Goal' : '??') . ': ' . \trim((string)($block['goal'] ?? ''));
                $blockImplementationFocus = $this->buildBlockImplementationFocus($block, $locale);
                if ($blockImplementationFocus !== '') {
                    $lines[] = '- ' . ($isEn ? 'Implementation Focus' : '??') . ': ' . $blockImplementationFocus;
                }
                $lines[] = '- ' . ($isEn ? 'Content' : '??') . ': ' . $this->buildBlockContentSummary($block);

                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                foreach ($fieldPlan as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }
                    $fieldName = \trim((string)($field['field'] ?? 'field'));
                    $fieldSample = \trim((string)($field['sample'] ?? ''));
                    if ($fieldSample === '') {
                        continue;
                    }
                    $lines[] = '  - ' . $fieldName . ': ' . $fieldSample;
                }
                $lines[] = '';
            }
        }

        return \str_replace(['Block Direction (Stage 1 Blueprint)', '???????????'], ['Block Content Plan', '??????'], \implode("\n", $lines));
    }

    private function normalizePlanBlocks(array $rawBlocks): array
    {
        $blocks = [];
        foreach ($rawBlocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $region = \trim((string)($block['region'] ?? 'body'));
            $type = \trim((string)($block['type'] ?? 'section'));
            $title = \trim((string)($block['title'] ?? ''));
            $content = \trim((string)($block['content'] ?? ''));
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            if ($blockId === '') {
                $blockId = 'plan_block_' . ($index + 1);
            }
            if ($title === '' && $content === '' && $items === []) {
                continue;
            }
            $blocks[] = [
                'block_id' => $blockId,
                'region' => $region !== '' ? $region : 'body',
                'type' => $type !== '' ? $type : 'section',
                'title' => $title !== '' ? $title : ('Block ' . ($index + 1)),
                'content' => $content,
                'items' => $items,
            ];
        }
        return $blocks;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return list<array<string, mixed>>
     */
    private function buildPlanBlocksFromPlanJson(array $planJson, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $headerItems = \is_array($planJson['navigation_plan']['header_items'] ?? null) ? $planJson['navigation_plan']['header_items'] : [];
        $footerPolicies = \is_array($planJson['footer_plan']['policies'] ?? null) ? $planJson['footer_plan']['policies'] : [];
        $contentItems = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $contentItems[] = [
                'title' => (string)$pageType,
                'goal' => (string)($pagePlan['page_goal'] ?? ''),
                'theme_alignment_summary' => (string)($pagePlan['theme_alignment_summary'] ?? ''),
                'blocks' => $this->sortStageOneBlocksForPlanBookMarkdown(\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : []),
            ];
        }

        return [
            [
                'block_id' => 'plan_header_001',
                'region' => 'header',
                'type' => 'title',
                'title' => $isEn ? 'Site Blueprint Header' : '站点方案头部',
                'content' => ($site !== '' ? $site : ($isEn ? 'Untitled site' : '未命名站点')) . ' - ' . ($summary !== '' ? $summary : ($isEn ? 'Blueprint overview' : '方案总览')),
                'items' => [],
            ],
            [
                'block_id' => 'plan_background_001',
                'region' => 'body',
                'type' => 'background',
                'title' => $isEn ? 'Planning Background' : '方案背景',
                'content' => $summary !== '' ? $summary : ($isEn ? 'Use selected page types and conversion goals as baseline.' : '以已选页面类型与转化目标作为方案基线。'),
                'items' => [
                    'header_navigation' => $headerItems,
                    'footer_policies' => $footerPolicies,
                ],
            ],
            [
                'block_id' => 'plan_catalog_001',
                'region' => 'catalog',
                'type' => 'content_catalog',
                'title' => $isEn ? 'Page Block Catalog' : '页面区块目录',
                'content' => $isEn ? 'Implementation-ready block catalog for the virtual-theme build.' : '供虚拟主题构建使用的页面区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '方案结尾',
                'content' => $isEn ? 'This blueprint is ready for virtual-theme build execution.' : '当前方案已经可以进入虚拟主题构建执行。',
                'items' => [],
            ],
        ];

        $isEn = $this->isEnglishLocale($locale);
        $site = \trim((string)($planJson['site_strategy']['site_display_name'] ?? ''));
        $summary = \trim((string)($planJson['site_strategy']['summary'] ?? ''));
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $headerItems = \is_array($planJson['navigation_plan']['header_items'] ?? null) ? $planJson['navigation_plan']['header_items'] : [];
        $footerPolicies = \is_array($planJson['footer_plan']['policies'] ?? null) ? $planJson['footer_plan']['policies'] : [];
        $contentItems = [];
        foreach ($pages as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $contentItems[] = [
                'title' => (string)$pageType,
                'goal' => (string)($pagePlan['page_goal'] ?? ''),
                'blocks' => \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [],
            ];
        }

        return [
            [
                'block_id' => 'plan_header_001',
                'region' => 'header',
                'type' => 'title',
                'title' => $isEn ? 'Site Blueprint Header' : '????',
                'content' => ($site !== '' ? $site : ($isEn ? 'Untitled site' : '?????')) . ' - ' . ($summary !== '' ? $summary : ($isEn ? 'Blueprint overview' : '????')),
                'items' => [],
            ],
            [
                'block_id' => 'plan_background_001',
                'region' => 'body',
                'type' => 'background',
                'title' => $isEn ? 'Planning Background' : '????',
                'content' => $summary !== '' ? $summary : ($isEn ? 'Use selected page types and conversion goals as baseline.' : '???????????????????'),
                'items' => [
                    'header_navigation' => $headerItems,
                    'footer_policies' => $footerPolicies,
                ],
            ],
            [
                'block_id' => 'plan_catalog_001',
                'region' => 'catalog',
                'type' => 'content_catalog',
                'title' => $isEn ? 'Page Block Catalog' : '??????',
                'content' => $isEn ? 'Implementation-ready block catalog for the virtual-theme build.' : '用于虚拟主题构建的区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '????',
                'content' => $isEn ? 'This blueprint is ready for virtual-theme build execution.' : '该方案已可直接进入虚拟主题构建执行。',
                'items' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $planBlocks
     */
    private function renderMarkdownFromPlanBlocks(array $planBlocks, string $locale = '', string $siteName = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $lines = [];
        $lines[] = '# ' . ($isEn ? 'Single-Stage Content Plan' : '单阶段内容方案');
        $lines[] = '';
        $lines[] = '- ' . ($isEn ? 'Site' : '站点') . ': ' . ($siteName !== '' ? $siteName : ($isEn ? 'Untitled site' : '未命名站点'));
        $lines[] = '';

        foreach ($planBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $title = \trim((string)($block['title'] ?? ''));
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $type = \trim((string)($block['type'] ?? 'section'));
            $region = \trim((string)($block['region'] ?? 'body'));
            $content = \trim((string)($block['content'] ?? ''));
            $lines[] = '## ' . ($title !== '' ? $title : ($isEn ? 'Block' : '区块'));
            $lines[] = '- ' . ($isEn ? 'Block ID' : '区块 ID') . ': ' . ($blockId !== '' ? $blockId : '-');
            $lines[] = '- ' . ($isEn ? 'Region' : '区域') . ': ' . $region;
            $lines[] = '- ' . ($isEn ? 'Type' : '类型') . ': ' . $type;
            if ($content !== '') {
                $lines[] = '- ' . ($isEn ? 'Content' : '内容') . ': ' . $content;
            }
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            foreach ($items as $key => $item) {
                $label = \is_string($key) && $key !== '' ? $key : ($isEn ? 'Item' : '项目');
                $value = \is_scalar($item) ? (string)$item : ((string)(\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                $lines[] = '- ' . $label . ': ' . $value;
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);

        $isEn = $this->isEnglishLocale($locale);
        $lines = [];
        $lines[] = '# ' . ($isEn ? 'Stage 1 Content Plan' : '???????');
        $lines[] = '';
        $lines[] = '- ' . ($isEn ? 'Site' : '??') . ': ' . ($siteName !== '' ? $siteName : ($isEn ? 'Untitled site' : '?????'));
        $lines[] = '';

        foreach ($planBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $title = \trim((string)($block['title'] ?? ''));
            $blockId = \trim((string)($block['block_id'] ?? ''));
            $type = \trim((string)($block['type'] ?? 'section'));
            $region = \trim((string)($block['region'] ?? 'body'));
            $content = \trim((string)($block['content'] ?? ''));
            $lines[] = '## ' . ($title !== '' ? $title : ($isEn ? 'Block' : '??'));
            $lines[] = '- ' . ($isEn ? 'Block ID' : '?? ID') . ': ' . ($blockId !== '' ? $blockId : '-');
            $lines[] = '- ' . ($isEn ? 'Region' : '??') . ': ' . $region;
            $lines[] = '- ' . ($isEn ? 'Type' : '??') . ': ' . $type;
            if ($content !== '') {
                $lines[] = '- ' . ($isEn ? 'Content' : '??') . ': ' . $content;
            }
            $items = \is_array($block['items'] ?? null) ? $block['items'] : [];
            foreach ($items as $key => $item) {
                $label = \is_string($key) && $key !== '' ? $key : ($isEn ? 'Item' : '??');
                $value = \is_scalar($item) ? (string)$item : ((string)(\json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''));
                $lines[] = '- ' . $label . ': ' . $value;
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);
    }

    private function buildLinkSummary(array $items, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        if ($items === []) {
            return $isEn ? 'none' : '无';
        }
        $parts = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? $item['type'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            $parts[] = $href !== '' ? ($label . '(' . $href . ')') : $label;
            if (\count($parts) >= 8) {
                break;
            }
        }

        return $parts !== [] ? \implode($isEn ? ', ' : '、', $parts) : ($isEn ? 'none' : '无');

        $isEn = $this->isEnglishLocale($locale);
        if ($items === []) {
            return $isEn ? 'none' : '无';
        }
        $parts = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? $item['type'] ?? ''));
            $href = \trim((string)($item['href'] ?? ''));
            $parts[] = $href !== '' ? ($label . '(' . $href . ')') : $label;
            if (\count($parts) >= 8) {
                break;
            }
        }

        return $parts !== [] ? \implode($isEn ? ', ' : '、', $parts) : ($isEn ? 'none' : '无');
    }

    /**
     * @param list<string> $keywords
     */
    private function buildKeywordSummary(array $keywords, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $normalized = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $keywords
        ), static fn(string $value): bool => $value !== ''));
        if ($normalized === []) {
            return $isEn ? 'none' : '无';
        }

        return \implode($isEn ? ', ' : '、', \array_slice($normalized, 0, 8));

        $isEn = $this->isEnglishLocale($locale);
        $normalized = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $keywords
        ), static fn(string $value): bool => $value !== ''));
        if ($normalized === []) {
            return $isEn ? 'none' : '无';
        }
        return \implode($isEn ? ', ' : '、', \array_slice($normalized, 0, 8));
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     */
    private function buildFieldSampleSummary(array $fieldPlan, string $locale = ''): string
    {
        $isEn = $this->isEnglishLocale($locale);
        $parts = [];
        foreach ($fieldPlan as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = \trim((string)($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $sample = \trim((string)($item['sample'] ?? ''));
            if ($sample === '') {
                continue;
            }
            $parts[] = $field . '=>' . $sample;
            if (\count($parts) >= 5) {
                break;
            }
        }

        return $parts !== [] ? \implode($isEn ? '; ' : '；', $parts) : ($isEn ? 'pending field samples' : '待补充字段示例');

        $isEn = $this->isEnglishLocale($locale);
        $parts = [];
        foreach ($fieldPlan as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $field = \trim((string)($item['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $sample = \trim((string)($item['sample'] ?? ''));
            if ($sample === '') {
                continue;
            }
            $parts[] = $field . '=>' . $sample;
            if (\count($parts) >= 5) {
                break;
            }
        }
        return $parts !== [] ? \implode($isEn ? '; ' : '；', $parts) : ($isEn ? 'pending field samples' : '待补充字段示例');
    }

    private function resolvePageGoal(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => '承接主关键词、说明核心价值，并把用户带到下一步动作。',
                Page::TYPE_ABOUT => '解释品牌背景、能力边界和可信依据，提升继续了解意愿。',
                Page::TYPE_CONTACT => '降低咨询门槛，快速收集有效线索。',
                default => $pageLabel . ' 清楚呈现访客最关心的信息、可信依据和继续操作路径。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Capture core intent, explain value, and surface primary conversion actions.',
                Page::TYPE_ABOUT => 'Build trust by explaining brand background and delivery capability.',
                Page::TYPE_CONTACT => 'Reduce friction and collect qualified leads quickly.',
                Page::TYPE_REFUND_POLICY => 'Explain refund eligibility, timing, and request steps so customers can act with confidence.',
                Page::TYPE_PRIVACY_POLICY => 'Explain what data is collected, how it is used, and what control visitors keep.',
                Page::TYPE_TERMS_OF_SERVICE => 'Clarify usage rules, responsibilities, and account expectations before purchase or signup.',
                Page::TYPE_SHIPPING_POLICY => 'Set delivery timing, shipping regions, and exception handling expectations clearly.',
                Page::TYPE_COOKIE_POLICY => 'Explain what cookies are used, why they exist, and how visitors can manage consent.',
                default => $pageLabel . ' gives visitors specific context, useful proof, and a clear route to continue.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '承接主关键词、首屏价值和关键转化入口。',
            Page::TYPE_ABOUT => '解释品牌背景与能力边界，建立信任。',
            Page::TYPE_CONTACT => '降低咨询门槛，收集有效线索。',
            default => $pageLabel . ' 清楚呈现访客最关心的信息、可信依据和继续操作路径。',
        };
    }

    private function resolvePageWhy(string $pageType, string $pageLabel, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => '首页作为全站主要入口，需要统一价值叙事、导航分发和转化动作。',
                Page::TYPE_ABOUT => '关于页用来补充品牌说明与信任依据，帮助用户继续判断。',
                Page::TYPE_CONTACT => '联系页用来承接咨询并缩短转化路径。',
                default => $pageLabel . ' 用于补足站点结构完整性与长尾关键词覆盖。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Home is the core traffic entry and must unify value narrative and navigation.',
                Page::TYPE_ABOUT => 'About builds trust and improves conversion decisions.',
                Page::TYPE_CONTACT => 'Contact captures inquiries and shortens the conversion path.',
                default => $pageLabel . ' completes site structure and supports long-tail search coverage.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '首页作为全站流量主入口，需要统一价值叙事与导航分发。',
            Page::TYPE_ABOUT => '关于页用于增强可信度并提升转化决策效率。',
            Page::TYPE_CONTACT => '联系页用于承接咨询和售前沟通，缩短转化路径。',
            default => $pageLabel . ' 用于补足站点结构完整性与长尾关键词覆盖。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildPageKeywords(string $pageTitle, string $pageLabel, string $siteDisplayName): array
    {
        return \array_values(\array_unique(\array_filter([
            \trim($pageTitle),
            \trim($pageLabel),
            $siteDisplayName !== '' ? ($siteDisplayName . ' ' . $pageLabel) : '',
        ], static fn(string $keyword): bool => $keyword !== '')));
    }

    /**
     * @return list<string>
     */
    private function buildSecondaryKeywords(string $pageType, string $pageLabel): array
    {
        $keywords = [$pageLabel . ' 指南', $pageLabel . ' 常见问题'];
        if ($pageType === Page::TYPE_HOME) {
            $keywords[] = '品牌介绍';
            $keywords[] = '核心卖点';
        }

        return \array_values(\array_unique($keywords));

        $keywords = [$pageLabel . ' 指南', $pageLabel . ' 常见问题'];
        if ($pageType === Page::TYPE_HOME) {
            $keywords[] = '品牌介绍';
            $keywords[] = '核心优势';
        }

        return \array_values(\array_unique($keywords));
    }

    private function resolveBlockGoal(string $template, string $pageGoal, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => '在首屏快速解释价值并引导主动作。',
                'cta' => '聚焦单一动作，降低用户决策成本。',
                'features' => '把能力亮点拆成可快速浏览的结构化内容。',
                default => $pageGoal,
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Explain value quickly above the fold and drive a primary action.',
                'cta' => 'Focus on one action to reduce decision friction.',
                'features' => 'Present capabilities in a structured and scannable format.',
                default => $pageGoal,
            };
        }
        return match ($template) {
            'hero' => '在首屏快速解释价值并引导关键动作。',
            'cta' => '聚焦单一动作，降低用户决策成本。',
            'features' => '结构化说明能力点，增强对比与理解。',
            default => $pageGoal,
        };
    }

    private function resolveLayoutRule(string $template, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => '首屏优先双栏或居中布局，让标题与主 CTA 同屏出现。',
                'features' => '采用卡片栅格布局，便于快速扫读。',
                default => '移动端单列优先，桌面端按内容密度扩展。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero' => 'Prefer two-column or centered above-the-fold layout with headline and CTA visible together.',
                'features' => 'Use card grid layout for fast scanning.',
                default => 'Mobile-first single column, then expand by content density on desktop.',
            };
        }
        return match ($template) {
            'hero' => '首屏优先双栏或居中布局，让标题与 CTA 同屏出现。',
            'features' => '卡片栅格布局，保证扫读效率。',
            default => '移动端单列优先，桌面端按内容密度扩展。',
        };
    }

    private function resolveResponsiveRule(string $template, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero', 'cta' => '移动端优先保留标题与主按钮，次级说明折叠到后续区域。',
                default => '断点下按单列堆叠，保证阅读顺序与触达效率。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($template) {
                'hero', 'cta' => 'On mobile, keep headline and primary CTA visible first; collapse secondary details.',
                default => 'Stack to single column on small screens to preserve reading order.',
            };
        }
        return match ($template) {
            'hero', 'cta' => '移动端优先保留标题与主按钮，次级说明折叠到后续区域。',
            default => '断点下按单列堆叠，保证阅读顺序与触达效率。',
        };
    }

    /**
     * @return list<string>
     */
    private function buildBlockKeywords(string $siteDisplayName, string $pageLabel, string $template, string $sectionName): array
    {
        $keywords = [
            $pageLabel . ' ' . $sectionName,
            $template . ' ' . $sectionName,
        ];
        if ($siteDisplayName !== '') {
            $keywords[] = $siteDisplayName . ' ' . $sectionName;
        }

        return \array_values(\array_unique(\array_filter($keywords, static fn(string $keyword): bool => \trim($keyword) !== '')));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveHeadlineDirection(
        array $config,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): string
    {
        return $this->resolveConcreteFieldValue('title', $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveBodyDirection(
        array $config,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): string
    {
        $subtitle = $this->resolveConcreteFieldValue('subtitle', $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale);
        $description = $this->resolveConcreteFieldValue('description', $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale);
        $parts = \array_values(\array_filter([$subtitle, $description], static fn(string $value): bool => \trim($value) !== ''));

        return $parts !== [] ? \implode(' ', $parts) : $description;
    }

    private function resolveCtaDirection(
        array $config,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): string
    {
        $preset = $this->inferBlockPreset($template, $sectionName, $pageLabel);
        if (!\array_key_exists('button_text', $config) && !\in_array($preset, ['hero', 'cta', 'contact_form'], true)) {
            return '';
        }

        $label = $this->resolveConcreteFieldValue('button_text', $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale);
        if ($label === '') {
            return '';
        }

        $target = $this->resolveConcreteFieldValue('button_link', $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale);

        return 'Primary CTA: ' . $label . ($target !== '' ? ' (target ' . $target . ')' : ' (button event, no placeholder href)');
    }

    private function buildFieldPlan(
        array $config,
        string $sectionName,
        string $pageGoal,
        string $template,
        string $locale = '',
        string $siteDisplayName = '',
        string $siteSummary = '',
        string $pageLabel = ''
    ): array
    {
        $fields = [];
        $requiredFields = $this->resolveRequiredFieldNames($template, $sectionName, $pageLabel);
        foreach (['title', 'subtitle', 'description', 'button_text', 'button_link', 'image'] as $field) {
            if (!\in_array($field, $requiredFields, true) && !\array_key_exists($field, $config)) {
                continue;
            }
            $implementationNote = $this->resolveFieldImplementationNote($field, $template, $sectionName, $pageGoal, $pageLabel, $locale);
            $fields[] = [
                'field' => $field,
                'sample' => $this->resolveConcreteFieldValue($field, $config, $template, $sectionName, $pageGoal, $pageLabel, $siteDisplayName, $siteSummary, $locale),
                'implementation_note' => $implementationNote,
            ];
        }

        return $fields;
    }

    private function resolveFieldImplementationNote(
        string $field,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $locale = ''
    ): string {
        $preset = $this->inferBlockPreset($template, $sectionName, $pageLabel);
        if ($this->isEnglishLocale($locale)) {
            return match ($field) {
                'title' => 'Use this as the visible heading for the block; keep it immediately understandable on first read.',
                'subtitle' => 'Place this below the title to continue the message in one concise supporting line.',
                'description' => 'Use 1-2 sentences to clarify scenario, benefit, or trust proof without drifting into generic copy.',
                'button_text' => 'Keep the CTA verb-led and aligned with the next action the page should drive.',
                'button_link' => 'Pair this target directly with the CTA label so it can be implemented without guessing.',
                'image' => $preset === 'hero'
                    ? 'Describe the hero visual concretely so design can produce it while keeping the headline and primary CTA visible together.'
                    : 'Describe the exact visual content or replacement slot the design team should prepare for this field.',
                default => $sectionName . ' needs this field to support goal "' . $pageGoal . '".',
            };
        }

        return match ($field) {
            'title' => '作为区块主标题直接上屏，优先让客户一眼看懂这块要传达什么。',
            'subtitle' => '放在标题下方补充关键信息，建议保持 1 行到 2 行的可读长度。',
            'description' => '用 1 到 2 句补充场景、收益或信任信息，避免空泛口号。',
            'button_text' => '按钮文案用动作词开头，并与本区块承接的下一步行为保持一致。',
            'button_link' => '链接目标与按钮文案一一对应，可直接作为锚点或跳转地址实施。',
            'image' => $preset === 'hero'
                ? '主视觉需要描述清楚画面内容，方便设计出图时同时保留标题和主 CTA 的可见区域。'
                : '这里直接写要呈现的画面内容或替换位要求，方便后续设计与素材执行。',
            default => $sectionName . ' 需要该字段来承接“' . $pageGoal . '”这个页面目标。',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBlockExecutionScript(
        array $config,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): array
    {
        $fieldPlan = $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName, $siteSummary, $pageLabel);
        $fieldLookup = $this->buildFieldPlanLookup($fieldPlan);
        $preset = $this->inferBlockPreset($template, $sectionName, $pageLabel);
        $title = \trim((string)($fieldLookup['title'] ?? ''));
        $subtitle = \trim((string)($fieldLookup['subtitle'] ?? ''));
        $description = \trim((string)($fieldLookup['description'] ?? ''));
        $ctaLabel = \trim((string)($fieldLookup['button_text'] ?? ''));

        return [
            'feature_points' => $this->buildConcreteExecutionFeaturePoints($preset, $title, $description, $ctaLabel, $locale),
            'core_copy' => $this->buildExecutionCoreCopy($title, $subtitle, $description, $ctaLabel, $locale),
            'typography' => $this->resolveExecutionTypography($preset, $locale),
            'style_tone' => $this->resolveExecutionStyleTone($preset, $locale),
            'background_direction' => $this->resolveExecutionBackgroundDirection($preset, $locale),
            'media_assets' => $this->buildExecutionMediaAssets($preset, $locale),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildBlockRealtimeContent(
        array $config,
        string $sectionName,
        string $pageGoal,
        string $template,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): array
    {
        $fieldPlan = $this->buildFieldPlan($config, $sectionName, $pageGoal, $template, $locale, $siteDisplayName, $siteSummary, $pageLabel);
        $fieldLookup = $this->buildFieldPlanLookup($fieldPlan);
        $headline = \trim((string)($fieldLookup['title'] ?? ''));
        $subtitle = \trim((string)($fieldLookup['subtitle'] ?? ''));
        $description = \trim((string)($fieldLookup['description'] ?? ''));
        $ctaLabel = \trim((string)($fieldLookup['button_text'] ?? ''));
        $ctaTarget = \trim((string)($fieldLookup['button_link'] ?? ''));
        $imageRule = \trim((string)($fieldLookup['image'] ?? ''));
        $supportingCopy = \array_values(\array_filter([$subtitle, $description], static fn(string $value): bool => \trim($value) !== ''));
        $cta = [];
        if ($ctaLabel !== '') {
            $cta[] = [
                'label' => $ctaLabel,
                'target' => $ctaTarget,
            ];
        }
        $media = [];
        if ($imageRule !== '') {
            $media[] = [
                'kind' => 'image',
                'rule' => $imageRule,
            ];
        }

        return [
            'headline' => $headline,
            'supporting_copy' => $supportingCopy,
            'cta' => $cta,
            'media' => $media,
            'data_slots' => $this->extractEditableFieldsFromFieldPlan($fieldPlan),
            'editable_slots' => $this->extractEditableFieldsFromFieldPlan($fieldPlan),
        ];
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     * @return list<string>
     */
    private function extractEditableFieldsFromFieldPlan(array $fieldPlan): array
    {
        $fields = [];
        foreach ($fieldPlan as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $fields[] = $field;
        }
        return \array_values(\array_unique($fields));
    }

    private function resolveFieldSample(
        string $field,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $siteDisplayName,
        string $siteSummary = '',
        string $locale = '',
        string $pageLabel = ''
    ): string
    {
        $siteName = $siteDisplayName !== '' ? $siteDisplayName : ($this->isEnglishLocale($locale) ? 'your brand' : '??');
        $preset = $this->inferBlockPreset($template, $sectionName, $pageLabel);

        return match ($field) {
            'title' => $this->resolveDefaultTitleSample($preset, $sectionName, $pageGoal, $pageLabel, $siteName, $siteSummary, $locale),
            'subtitle' => $this->resolveDefaultSubtitleSample($preset, $sectionName, $pageGoal, $pageLabel, $siteName, $siteSummary, $locale),
            'description' => $this->resolveDefaultDescriptionSample($preset, $sectionName, $pageGoal, $pageLabel, $siteName, $siteSummary, $locale),
            'button_text' => $this->resolveDefaultButtonText($preset, $pageGoal, $siteSummary, $locale),
            'button_link' => '',
            'image' => $this->resolveDefaultImageRule($preset, $pageLabel, $siteName, $siteSummary, $locale),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    /**
     * @param array<string, mixed> $config
     */
    private function resolveConcreteFieldValue(
        string $field,
        array $config,
        string $template,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteDisplayName,
        string $siteSummary,
        string $locale = ''
    ): string {
        $raw = \trim((string)($config[$field] ?? ''));
        if ($raw !== '' && !$this->looksLikeBlueprintInstruction($raw, $field, $template, $sectionName, $pageLabel)) {
            return $raw;
        }

        return $this->resolveFieldSample($field, $template, $sectionName, $pageGoal, $siteDisplayName, $siteSummary, $locale, $pageLabel);
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredFieldNames(string $template, string $sectionName, string $pageLabel = ''): array
    {
        return match ($this->inferBlockPreset($template, $sectionName, $pageLabel)) {
            'hero' => ['title', 'subtitle', 'description', 'button_text', 'button_link', 'image'],
            'cta', 'contact_form' => ['title', 'description', 'button_text', 'button_link'],
            default => ['title', 'description'],
        };
    }

    /**
     * @param list<array<string, mixed>> $fieldPlan
     * @return array<string, string>
     */
    private function buildFieldPlanLookup(array $fieldPlan): array
    {
        $lookup = [];
        foreach ($fieldPlan as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $field = \trim((string)($row['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $lookup[$field] = \trim((string)($row['sample'] ?? ''));
        }

        return $lookup;
    }

    private function inferBlockPreset(string $template, string $sectionName, string $pageLabel = ''): string
    {
        $templateText = \mb_strtolower(\trim($template . ' ' . $sectionName . ' ' . $pageLabel));
        if (\str_contains($templateText, 'hero')) {
            return 'hero';
        }
        if (\str_contains($templateText, 'feature')) {
            return 'features';
        }
        if (\str_contains($templateText, 'process') || \str_contains($templateText, 'step')) {
            return 'process';
        }
        if (\str_contains($templateText, 'cta')) {
            return 'cta';
        }
        if (\str_contains($templateText, 'brand')) {
            return 'brand';
        }
        if (\str_contains($templateText, 'trust')) {
            return 'trust';
        }
        if (\str_contains($templateText, 'contact')) {
            return 'contact_form';
        }
        if (\str_contains($templateText, 'privacy') || \str_contains($templateText, 'refund') || \str_contains($templateText, 'terms')) {
            return 'legal';
        }

        return 'content';
    }

    private function looksLikeBlueprintInstruction(string $text, string $field = '', string $template = '', string $sectionName = '', string $pageLabel = ''): bool
    {
        $normalized = \mb_strtolower(\trim($text));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            '围绕', '说明核心价值', '首页先讲清', '阶段一仅给方向', '列出 2-4', '标题围绕', '指定标题字体',
            'block direction', 'section title', 'supporting subtitle text',
            'list 2-4', 'specify heading font', 'describe the overall visual tone', 'use concise readable paragraphs',
            'first-screen promise', 'lead visitors to the next step',
            'string explaining how this page obeys', 'how this page and every block obey theme_design',
            'keep cta', 'home hero', 'page hero',
        ] as $marker) {
            if ($marker !== '' && $normalized === \mb_strtolower($marker)) {
                return true;
            }
        }

        if ($field === 'title') {
            $sectionNormalized = \mb_strtolower(\trim($sectionName));
            $pageNormalized = \mb_strtolower(\trim($pageLabel));
            if ($normalized === $sectionNormalized || $normalized === $pageNormalized) {
                return true;
            }
            if (\preg_match('/^(hero|feature|features|process|cta|contact|content|section|block|home|about|contact page|首页|关于我们|联系我们)$/iu', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function resolveDefaultTitleSample(
        string $preset,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteName,
        string $siteSummary = '',
        string $locale = ''
    ): string
    {
        $summaryCue = $this->buildBriefDrivenSnippet($siteSummary, $siteName, $locale, 18, 42);
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryCue !== '' ? ($siteName . '：' . $summaryCue) : ('欢迎来到 ' . $siteName),
                'features' => $summaryCue !== '' ? ('热门内容：' . $summaryCue) : ('为什么用户会继续选择 ' . $siteName),
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === '立即下载' ? '三步完成下载' : '三步快速开始',
                'cta' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === '立即下载' ? '准备开始下载了吗？' : '准备好进入下一步了吗？',
                'brand' => '认识一下 ' . $siteName,
                'trust' => '为什么可以放心了解 ' . $siteName,
                'contact_form' => '告诉我们你的需求',
                'legal' => $pageLabel !== '' ? $pageLabel : '政策说明',
                default => $pageLabel !== '' ? ($pageLabel . ' 重点内容') : ($sectionName !== '' ? $sectionName : ($siteName . ' 重点内容')),
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryCue !== '' ? ($siteName . ': ' . $summaryCue) : ('Welcome to ' . $siteName),
                'features' => $summaryCue !== '' ? ('Featured: ' . $summaryCue) : ('Why visitors choose ' . $siteName),
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === 'Download Now' ? 'Start in three download steps' : 'Start in three simple steps',
                'cta' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === 'Download Now' ? 'Ready to download now?' : 'Ready to take the next step?',
                'brand' => 'Get to know ' . $siteName,
                'trust' => 'Reasons to trust ' . $siteName,
                'contact_form' => 'Tell us what you need',
                'legal' => $pageLabel !== '' ? $pageLabel : 'Policy details',
                default => $pageLabel !== '' ? ($pageLabel . ' highlights') : ($sectionName !== '' ? $sectionName : ($siteName . ' highlights')),
            };
        }

        return match ($preset) {
            'hero' => '????' . $siteName,
            'features' => '?????' . $siteName,
            'process' => '??????',
            'cta' => '???????????',
            'brand' => '??' . $siteName,
            'trust' => $siteName . ' ???????',
            'contact_form' => '????????',
            'legal' => $pageLabel !== '' ? $pageLabel : '????',
            default => $pageLabel !== '' ? ($pageLabel . '????') : ($sectionName !== '' ? $sectionName : ($siteName . ' ????')),
        };
    }

    private function resolveDefaultSubtitleSample(
        string $preset,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteName,
        string $siteSummary = '',
        string $locale = ''
    ): string
    {
        $summaryLine = $this->buildBriefDrivenSnippet($siteSummary, $siteName, $locale, 42, 96);
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryLine !== '' ? $summaryLine : ('欢迎来到 ' . $siteName),
                'features' => $summaryLine !== '' ? ('重点包括：' . $summaryLine) : ('这里整理最值得先看的核心亮点与入口。'),
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === '立即下载'
                    ? '选择游戏、查看说明、完成下载，流程一页看清。'
                    : '浏览重点内容、确认入口、完成下一步动作，流程一页看清。',
                default => '',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryLine !== '' ? $summaryLine : ('Welcome to ' . $siteName),
                'features' => $summaryLine !== '' ? ('Key focus: ' . $summaryLine) : ('See the main highlights and entry points first.'),
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === 'Download Now'
                    ? 'Choose the title, review the notes, and complete the download in one clear flow.'
                    : 'Review the highlights, pick the right entry, and move to the next action in one clear flow.',
                default => '',
            };
        }

        return match ($preset) {
            'hero' => '????????????????????',
            'features' => '????????????????????',
            'process' => '???????????????????????',
            default => '',
        };
    }

    private function resolveDefaultDescriptionSample(
        string $preset,
        string $sectionName,
        string $pageGoal,
        string $pageLabel,
        string $siteName,
        string $siteSummary = '',
        string $locale = ''
    ): string
    {
        $summaryLine = $this->buildBriefDrivenSnippet($siteSummary, $siteName, $locale, 64, 140);
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryLine !== ''
                    ? ($siteName . ' 聚焦 ' . $summaryLine . '，让访客一进首页就能看到重点内容与可执行入口。')
                    : ('欢迎来到 ' . $siteName . '，这里会先展示最值得了解的内容和清晰的下一步入口。'),
                'features' => $summaryLine !== ''
                    ? ('围绕 ' . $summaryLine . '，这里会把热门内容、核心亮点和常见关心点整理成易读卡片。')
                    : '这里会把热门内容、核心亮点和常见关心点整理成易读卡片，方便快速比较。',
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === '立即下载'
                    ? '先看推荐内容，再确认版本与说明，最后直接进入下载入口，减少首次操作的犹豫。'
                    : '先看重点内容，再确认适合自己的入口，最后进入咨询、联系或继续了解的下一步。',
                'cta' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === '立即下载'
                    ? '点击后可直接进入下载入口，同时保留必要说明，方便用户马上开始。'
                    : '点击后直接进入当前页面最重要的下一步，避免用户在多个入口之间反复选择。',
                'brand', 'trust' => $summaryLine !== ''
                    ? ($siteName . ' 围绕 ' . $summaryLine . ' 持续组织内容与服务，让首次访问也能更快建立信任。')
                    : ('通过品牌背景、支持方式、服务说明和信任细节，帮助访客更安心地继续了解。'),
                'contact_form' => '只收集必要联系信息与需求说明，让用户可以更快发起沟通。',
                'legal' => '把关键政策信息拆成可读的小节，方便用户快速找到相关规则。',
                default => '把页面目标转成访客可以直接看到的实际内容：' . $pageGoal,
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $summaryLine !== ''
                    ? ($siteName . ' focuses on ' . $summaryLine . ', so visitors can see the main offer and the next action as soon as the page opens.')
                    : ('Welcome to ' . $siteName . ', where visitors can see the main offer and the next action right away.'),
                'features' => $summaryLine !== ''
                    ? ('This section turns ' . $summaryLine . ' into scannable highlights, core entry points, and quick-read benefit cards.')
                    : 'This section turns the main offer into scannable highlights, core entry points, and quick-read benefit cards.',
                'process' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === 'Download Now'
                    ? 'Review the recommendation, confirm the notes, and move straight into the download flow with less hesitation.'
                    : 'Review the highlights, choose the right entry, and move into the next action with less hesitation.',
                'cta' => $this->inferPrimaryActionFromSummary($siteSummary, $locale) === 'Download Now'
                    ? 'After clicking, visitors move straight to the download entry with the key supporting notes still visible.'
                    : 'After clicking, visitors move straight into the main next step without guessing which action matters most.',
                'brand', 'trust' => $summaryLine !== ''
                    ? ($siteName . ' keeps the focus on ' . $summaryLine . ' so first-time visitors can build trust more quickly.')
                    : 'Build trust with brand background, support approach, and reassuring details that make visitors comfortable continuing.',
                'contact_form' => 'Collect only the key contact details and request notes so visitors can start a conversation quickly.',
                'legal' => 'Present the key policy information in readable sections so visitors can find the rule they need without guessing.',
                default => 'Turn the page goal into clear on-screen content that visitors can read and act on right away: ' . $pageGoal,
            };
        }

        return match ($preset) {
            'hero' => '???????????????????????????????????????',
            'features' => '??????????????????????????????????',
            'process' => '????????????????????????????????',
            'cta' => '????????????????????????????',
            'brand', 'trust' => '??????????????????????????????????',
            'contact_form' => '????????????????????????????',
            'legal' => '????????????????????????????',
            default => '???????????????????????????????????' . $pageGoal,
        };
    }

    private function resolveDefaultButtonText(string $preset, string $pageGoal, string $siteSummary = '', string $locale = ''): string
    {
        $primaryAction = $this->inferPrimaryActionFromSummary($siteSummary, $locale);
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $primaryAction !== '' ? $primaryAction : '立即开始',
                'cta', 'contact_form' => $primaryAction !== '' ? $primaryAction : '立即咨询',
                default => '了解更多',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => $primaryAction !== '' ? $primaryAction : 'Start Now',
                'cta', 'contact_form' => $primaryAction !== '' ? $primaryAction : 'Contact Us',
                default => 'Learn More',
            };
        }

        return match ($preset) {
            'hero' => '????',
            'cta', 'contact_form' => '????',
            default => '????',
        };
    }

    private function resolveDefaultImageRule(string $preset, string $pageLabel, string $siteName, string $siteSummary = '', string $locale = ''): string
    {
        $summaryCue = $this->buildBriefDrivenSnippet($siteSummary, $siteName, $locale, 28, 72);
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => '首屏主视觉建议：展示“' . ($summaryCue !== '' ? $summaryCue : $siteName) . '”相关场景、界面或核心内容，并确保标题与主 CTA 同屏可见。',
                'brand', 'trust' => '信任素材建议：展示“' . ($summaryCue !== '' ? $summaryCue : $siteName) . '”相关团队、产品界面、合作标识或服务现场，并预留可替换物料位。',
                default => '',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => 'Hero visual brief: show "' . ($summaryCue !== '' ? $summaryCue : $siteName) . '" through a scene, interface, or offer-focused visual while keeping the headline and primary CTA visible together.',
                'brand', 'trust' => 'Trust visual brief: show team, product evidence, partner marks, or service proof tied to "' . ($summaryCue !== '' ? $summaryCue : $siteName) . '" with replaceable asset slots.',
                default => '',
            };
        }

        return match ($preset) {
            'hero' => '???????????????????????? CTA?',
            'brand', 'trust' => '??????????????????????',
            default => '',
        };
    }

    private function buildBriefDrivenSnippet(string $siteSummary, string $siteName, string $locale = '', int $zhLimit = 24, int $enLimit = 72): string
    {
        $summary = \trim(\preg_replace('/\s+/u', ' ', $siteSummary) ?? '');
        if ($summary === '' || $this->looksLikeBlueprintInstruction($summary)) {
            return '';
        }
        if ($this->isEnglishLocale($locale) && \preg_match('/\p{Han}/u', $summary) === 1) {
            return '';
        }

        $summary = \trim($summary, " \t\n\r\0\x0B,.;:!?，。；：！？");
        if ($summary === '') {
            return '';
        }

        if ($siteName !== '' && \mb_stripos($summary, $siteName) !== false) {
            $summary = \trim(\str_replace($siteName, '', $summary));
            $summary = \trim($summary, " \t\n\r\0\x0B,.;:!?，。；：！？");
        }

        if ($summary === '') {
            return '';
        }

        return $this->clipText($summary, $this->isEnglishLocale($locale) ? $enLimit : $zhLimit);
    }

    private function inferPrimaryActionFromSummary(string $siteSummary, string $locale = ''): string
    {
        $summary = \mb_strtolower(\trim($siteSummary));
        if ($summary === '') {
            return '';
        }

        if ($this->containsAny($summary, ['apk', '下载', 'download', 'install', '安装'])) {
            return $this->isEnglishLocale($locale) ? 'Download Now' : '立即下载';
        }
        if ($this->containsAny($summary, ['预约', '预订', 'booking', 'reserve'])) {
            return $this->isEnglishLocale($locale) ? 'Book Now' : '立即预约';
        }
        if ($this->containsAny($summary, ['咨询', '联系', '合作', '代理', '服务', 'solution', 'service', 'contact'])) {
            return $this->isEnglishLocale($locale) ? 'Contact Us' : '立即咨询';
        }

        return '';
    }

    private function buildExecutionFeaturePoints(string $preset, string $locale = ''): array
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => ['首屏直接说清价值', '保留一个主 CTA', '补充信任或支持提示'],
                'features' => ['拆出核心亮点', '保持内容可扫读', '支持用户快速比较'],
                'process' => ['步骤表达清晰', '降低首次使用门槛', '引导继续推进'],
                'cta' => ['只保留一个主动作', '说明点击后的收益'],
                default => ['内容清晰可读', '服务页面目标', '让下一步动作可见'],
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => ['Show core value immediately', 'Expose one primary CTA', 'Add trust or support hints'],
                'features' => ['Summarize key strengths', 'Keep cards scannable', 'Support comparison reading'],
                'process' => ['Explain steps clearly', 'Reduce first-use friction', 'Guide progress'],
                'cta' => ['Keep one action only', 'Explain next-step benefit'],
                default => ['Make the block readable', 'Support page goal', 'Keep next action visible'],
            };
        }

        return match ($preset) {
            'hero' => ['?????????', '?????? CTA', '?????????'],
            'features' => ['??????', '???????', '????????'],
            'process' => ['??????', '????????', '??????'],
            'cta' => ['???????', '????????'],
            default => ['??????', '??????', '????????'],
        };
    }

    /**
     * @return list<string>
     */
    private function buildConcreteExecutionFeaturePoints(string $preset, string $title, string $description, string $ctaLabel, string $locale = ''): array
    {
        $points = [];
        if ($title !== '') {
            $points[] = $this->isEnglishLocale($locale)
                ? ('Visible headline: ' . $this->clipText($title, 80))
                : ('上屏标题：' . $this->clipText($title, 28));
        }
        if ($description !== '') {
            $points[] = $this->isEnglishLocale($locale)
                ? ('Support copy: ' . $this->clipText($description, 120))
                : ('补充文案：' . $this->clipText($description, 44));
        }
        if ($ctaLabel !== '') {
            $points[] = $this->isEnglishLocale($locale)
                ? ('Primary CTA label: ' . $ctaLabel)
                : ('主 CTA 文案：' . $ctaLabel);
        }

        return $points !== [] ? $points : $this->buildExecutionFeaturePoints($preset, $locale);
    }

    private function buildExecutionCoreCopy(string $title, string $subtitle, string $description, string $ctaLabel, string $locale = ''): string
    {
        $parts = \array_values(\array_filter([$title, $subtitle, $description], static fn(string $value): bool => \trim($value) !== ''));
        $copy = \implode(' ', $parts);
        if ($ctaLabel !== '') {
            $copy .= ($copy !== '' ? ' ' : '') . ($this->isEnglishLocale($locale) ? ('CTA: ' . $ctaLabel . '.') : ('CTA：' . $ctaLabel . '。'));
        }

        return $copy;
    }

    private function resolveExecutionTypography(string $preset, string $locale = ''): string
    {
        return $this->isEnglishLocale($locale)
            ? 'Use a bold headline style with clean body text to keep hierarchy clear and conversion-focused.'
            : '标题使用更强层级，正文保持清晰易读，让用户先看懂再行动。';
    }

    private function resolveExecutionStyleTone(string $preset, string $locale = ''): string
    {
        return $this->isEnglishLocale($locale)
            ? 'Professional, readable, and conversion-oriented; keep the palette aligned with the site-wide visual direction.'
            : '整体保持专业、可信、可转化，并与全站视觉方向一致。';
    }

    private function resolveExecutionBackgroundDirection(string $preset, string $locale = ''): string
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => '使用更强的背景层次或主视觉来承接首屏价值，但不能压过文字内容。',
                'cta' => 'CTA 区域可使用强调色或深底形成明显分区。',
                default => '使用安静背景承托内容，不要和正文争抢注意力。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => 'Use a stronger background or visual layer to frame the first-screen value without overpowering the text.',
                'cta' => 'Use accent or contrast background to isolate the action area clearly.',
                default => 'Use a calm background that supports readability and does not compete with the content.',
            };
        }

        return match ($preset) {
            'hero' => '???????????????????????????????',
            'cta' => 'CTA ??????????????????????',
            default => '?????????????????????',
        };
    }

    private function buildExecutionMediaAssets(string $preset, string $locale = ''): array
    {
        if (!$this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => ['一张能承接首屏承诺的主视觉或产品图'],
                'brand', 'trust' => ['团队、产品、场景图或信任标识素材'],
                default => [],
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($preset) {
                'hero' => ['Main visual or product shot to support the first-screen promise'],
                'brand', 'trust' => ['Team, office, product, or trust-badge media'],
                default => [],
            };
        }

        return match ($preset) {
            'hero' => ['?????????????????????'],
            'brand', 'trust' => ['???????????????'],
            default => [],
        };
    }

    private function buildBlockContentSummary(array $block): string
    {
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $headline = \trim((string)($realtime['headline'] ?? ''));
        $supportingCopy = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            \is_array($realtime['supporting_copy'] ?? null) ? $realtime['supporting_copy'] : []
        ), static fn(string $value): bool => $value !== ''));
        $ctaLabels = [];
        foreach (\is_array($realtime['cta'] ?? null) ? $realtime['cta'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $label = \trim((string)($item['label'] ?? ''));
            if ($label !== '') {
                $ctaLabels[] = $label;
            }
        }

        $parts = \array_values(\array_filter([
            $headline,
            \implode(' ', \array_slice($supportingCopy, 0, 2)),
            $ctaLabels !== [] ? ('CTA: ' . \implode(' / ', $ctaLabels)) : '',
        ], static fn(string $value): bool => \trim($value) !== ''));
        if ($parts !== []) {
            return \implode(' | ', $parts);
        }

        $fallback = \array_values(\array_filter([
            \trim((string)($block['content_brief']['headline_direction'] ?? '')),
            \trim((string)($block['content_brief']['body_direction'] ?? '')),
            \trim((string)($block['content_brief']['cta_direction'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));

        return $fallback !== [] ? \implode(' | ', $fallback) : \trim((string)($block['goal'] ?? ''));
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        if (\trim($haystack) === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && \mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        $items = \is_array($raw) ? $raw : [$raw];
        $normalized = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return \array_values($normalized);
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function pickString(mixed ...$values): string
    {
        return $this->firstNonEmptyString($values);
    }

    private function clipText(string $text, int $maxLength): string
    {
        $text = \trim($text);
        if ($text === '' || $maxLength <= 0) {
            return '';
        }
        if (\mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return \mb_substr($text, 0, $maxLength) . '…';
    }

    private function slugify(string $text): string
    {
        $text = \mb_strtolower(\trim($text));
        if ($text === '') {
            return 'section';
        }
        $text = (string)\preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        $text = \trim($text, '-');

        return $text !== '' ? $text : 'section';
    }

    /**
     * @param array<string, mixed> $sharedComponents
     * @return array<string, array<string, mixed>>
     */
    private function normalizeStageOneSharedComponents(array $sharedComponents): array
    {
        $normalized = [];
        foreach ($sharedComponents as $region => $componentPlan) {
            if (!\is_array($componentPlan)) {
                continue;
            }
            $component = \trim((string)($componentPlan['component'] ?? $region));
            if ($component === '') {
                $taskKey = \trim((string)($componentPlan['task_key'] ?? ''));
                $component = \str_starts_with($taskKey, 'shared:') ? \substr($taskKey, \strlen('shared:')) : '';
            }
            $component = \trim($component);
            if ($component === '') {
                $component = 'shared_' . (string)(\count($normalized) + 1);
            }

            $componentPlan['component'] = $component;
            $componentPlan['task_key'] = \trim((string)($componentPlan['task_key'] ?? '')) !== ''
                ? \trim((string)$componentPlan['task_key'])
                : ('shared:' . $component);
            $componentPlan['task_type'] = \trim((string)($componentPlan['task_type'] ?? '')) !== ''
                ? \trim((string)$componentPlan['task_type'])
                : 'shared_component';
            $componentPlan['sort_order'] = (int)($componentPlan['sort_order'] ?? $this->defaultStageOneSharedSortOrder($component, \count($normalized)));
            $componentPlan = $this->normalizeStageOneSharedBlock($component, $componentPlan);
            $normalized[$component] = $componentPlan;
        }

        \uasort($normalized, static function (array $left, array $right): int {
            $sortCompare = ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0));
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return \strcmp((string)($left['component'] ?? ''), (string)($right['component'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param array<string, mixed> $componentPlan
     * @return array<string, mixed>
     */
    private function normalizeStageOneSharedBlock(string $component, array $componentPlan): array
    {
        $taskKey = \trim((string)($componentPlan['task_key'] ?? ('shared:' . $component)));
        if ($taskKey === '') {
            $taskKey = 'shared:' . $component;
        }
        $goal = \trim((string)($componentPlan['goal'] ?? ''));
        if ($goal === '') {
            $goal = $component === 'header'
                ? 'Build the shared site header with brand recognition, navigation, and a primary CTA.'
                : 'Build the shared site footer with contact, policy, and support links.';
        }
        $implementationDetail = \trim((string)($componentPlan['implementation_detail'] ?? $componentPlan['implementation_note'] ?? ''));
        if ($implementationDetail === '') {
            $implementationDetail = $goal !== '' ? $goal : 'Build the shared ' . $component . ' block with editable content and responsive behavior.';
        }
        $completionRule = \trim((string)($componentPlan['completion_rule'] ?? ''));
        if ($completionRule === '') {
            $completionRule = 'Shared block is complete when copy, links, editable fields, and responsive behavior are ready for all pages.';
        }
        $realtimeContent = \is_array($componentPlan['realtime_content'] ?? null)
            ? $componentPlan['realtime_content']
            : [
                'headline' => \trim((string)($componentPlan['site_display_name'] ?? \ucfirst($component))),
                'supporting_copy' => [],
                'cta' => [],
                'media' => [],
                'editable_slots' => [],
            ];
        $editableFields = \is_array($componentPlan['editable_fields'] ?? null)
            ? $this->normalizeStringList($componentPlan['editable_fields'])
            : $this->deriveStageOneEditableFields($componentPlan, $realtimeContent);
        $contentSource = \is_array($componentPlan['content_source'] ?? null)
            ? $this->normalizeStringList($componentPlan['content_source'])
            : ['theme_context_snapshot', 'shared_prompt_context', 'editable_field'];
        $dependencies = \is_array($componentPlan['dependencies'] ?? null)
            ? $this->normalizeStringList($componentPlan['dependencies'])
            : [];

        $normalized = \array_replace($componentPlan, [
            'block_key' => $taskKey,
            'block_type' => 'shared:' . $component,
            'page_key' => '',
            'title' => (string)($componentPlan['title'] ?? \ucfirst($component)),
            'goal' => $goal,
            'implementation_detail' => $implementationDetail,
            'realtime_content' => $realtimeContent,
            'editable_fields' => $editableFields,
            'content_source' => $contentSource,
            'style_direction' => (string)($componentPlan['style_direction'] ?? ''),
            'responsive_rule' => (string)($componentPlan['responsive_rule'] ?? ''),
            'seo_role' => (string)($componentPlan['seo_role'] ?? 'shared_site_chrome'),
            'completion_rule' => $completionRule,
            'dependencies' => $dependencies,
            'prompt_context_hash' => (string)($componentPlan['prompt_context_hash'] ?? ''),
            'version' => (int)($componentPlan['version'] ?? 1),
        ]);
        unset($normalized['reason'], $normalized['why']);
        $normalized['context_hash'] = $this->buildStageOneBlockContextHash('', $normalized);

        return $normalized;
    }

    private function defaultStageOneSharedSortOrder(string $component, int $fallbackIndex): int
    {
        return match ($component) {
            'header' => 10,
            'footer' => 20,
            default => ($fallbackIndex + 1) * 10,
        };
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array<string, array<string, mixed>>
     */
    private function resolveStageOneSharedComponents(array $structured, array $executionBlueprint): array
    {
        if (\is_array($executionBlueprint['shared_components'] ?? null) && $executionBlueprint['shared_components'] !== []) {
            return $this->normalizeStageOneSharedComponents($executionBlueprint['shared_components']);
        }
        if (\is_array($structured['shared_components'] ?? null) && $structured['shared_components'] !== []) {
            return $this->normalizeStageOneSharedComponents($structured['shared_components']);
        }

        $sharedPlan = \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [];
        $sharedComponents = [];
        if (\is_array($sharedPlan['header_block'] ?? null) && $sharedPlan['header_block'] !== []) {
            $sharedComponents['header'] = $sharedPlan['header_block'];
        }
        if (\is_array($sharedPlan['footer_block'] ?? null) && $sharedPlan['footer_block'] !== []) {
            $sharedComponents['footer'] = $sharedPlan['footer_block'];
        }

        return $this->normalizeStageOneSharedComponents($sharedComponents);
    }

    /**
     * @param array<string, mixed> $sharedComponents
     * @return list<array<string, mixed>>
     */
    private function buildStageOneSharedBlocksPlanJson(array $sharedComponents): array
    {
        $rows = [];
        foreach ($this->normalizeStageOneSharedComponents($sharedComponents) as $component => $componentPlan) {
            $blockKey = \trim((string)($componentPlan['task_key'] ?? ('shared:' . $component)));
            $rows[] = [
                'block_key' => $blockKey !== '' ? $blockKey : ('shared:' . $component),
                'component' => (string)$component,
                'label' => \ucfirst((string)$component),
                'sort_order' => (int)($componentPlan['sort_order'] ?? 0),
                'goal' => \trim((string)($componentPlan['goal'] ?? '')),
                'content' => $this->buildBlockContentSummary($componentPlan),
                'implementation_note' => $this->buildBlockImplementationFocus($componentPlan, ''),
                'editable_fields' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($componentPlan['editable_fields'] ?? null) ? $componentPlan['editable_fields'] : []
                ), static fn(string $value): bool => $value !== '')),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $sharedBlocks
     * @return list<array<string, mixed>>
     */
    private function normalizeStageOneSharedBlockRows(array $sharedBlocks): array
    {
        $rows = \array_values(\array_filter($sharedBlocks, static fn($row): bool => \is_array($row)));
        \usort($rows, static function (array $left, array $right): int {
            $sortCompare = ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0));
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return \strcmp((string)($left['block_key'] ?? ''), (string)($right['block_key'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $orderedBlockKeys
     * @return array{
     *     plan_json:array<string, mixed>,
     *     structured:array<string, mixed>,
     *     execution_blueprint:array<string, mixed>,
     *     markdown:string,
     *     plan_workbench:array<string, mixed>,
     *     reorder_summary:array<string, mixed>
     * }
     */
    public function reorderDraftPlanBlocks(array $scope, string $pageType, array $orderedBlockKeys): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            throw new \RuntimeException('Page type is required for stage-1 reorder.');
        }
        if ($pageType === 'shared') {
            return $this->reorderDraftSharedPlanBlocks($scope, $orderedBlockKeys);
        }

        $structured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $executionBlueprint = $this->resolveStageOneExecutionBlueprint($scope);
        $pagePlans = \is_array($executionBlueprint['page_plans'] ?? null)
            ? $executionBlueprint['page_plans']
            : (\is_array($structured['page_plans'] ?? null) ? $structured['page_plans'] : []);
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
        if ($pages === [] && $pagePlans !== []) {
            $pages = $pagePlans;
        }
        if ($pages === []) {
            $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        }
        $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($pagePlan === []) {
            throw new \RuntimeException('Stage-1 page plan not found for page type: ' . $pageType);
        }

        $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
        if ($blocks === []) {
            throw new \RuntimeException('Stage-1 block list is empty for page type: ' . $pageType);
        }

        $originalOrder = \array_values(\array_filter(\array_map(
            static fn(array $block): string => \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')),
            $blocks
        ), static fn(string $blockKey): bool => $blockKey !== ''));
        $reorderedBlocks = $this->reorderStageOneBlockList($blocks, $orderedBlockKeys);
        $normalizedOrder = \array_values(\array_filter(\array_map(
            static fn(array $block): string => \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')),
            $reorderedBlocks
        ), static fn(string $blockKey): bool => $blockKey !== ''));

        $pages[$pageType] = \array_replace($pagePlan, [
            'blocks' => $reorderedBlocks,
        ]);

        $structured['pages'] = $pages;
        $executionBlueprint['pages'] = $pages;
        if (!\is_array($structured['page_types'] ?? null) || $structured['page_types'] === []) {
            $structured['page_types'] = \is_array($executionBlueprint['page_types'] ?? null)
                ? $executionBlueprint['page_types']
                : \array_values(\array_map('strval', \array_keys($pages)));
        }

        $sharedPromptContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null)
            ? $executionBlueprint['shared_prompt_context']
            : (\is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : []);
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        $structured['page_plans'] = $pagePlans;
        $executionBlueprint['page_plans'] = $pagePlans;

        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $structured['shared_components'] = $sharedComponents;
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'header_block' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
                'footer_block' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
            ]
        );
        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $structured['block_index'] = $blockIndex;
        $executionBlueprint['block_index'] = $blockIndex;

        $tasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
        $tasks = $this->rebuildStageOneTaskList($tasks, $pageType, $normalizedOrder);
        $executionBlueprint['tasks'] = $tasks;
        $structured['execution_steps'] = $this->buildExecutionSteps($tasks);
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? $structured['i18n']['locale'] ?? ''));
        $planJson = $this->buildPlanJson($structured);
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
            'reorder_summary' => [
                'page_type' => $pageType,
                'original_order' => $originalOrder,
                'ordered_block_keys' => $normalizedOrder,
                'block_count' => \count($normalizedOrder),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $blockPatch
     * @return array{
     *     plan_json:array<string, mixed>,
     *     structured:array<string, mixed>,
     *     execution_blueprint:array<string, mixed>,
     *     markdown:string,
     *     plan_workbench:array<string, mixed>,
     *     mutation_summary:array<string, mixed>,
     *     block:array<string, mixed>|null
     * }
     */
    public function mutateDraftPlanBlock(
        array $scope,
        string $pageType,
        string $action,
        string $blockKey = '',
        array $blockPatch = []
    ): array {
        $pageType = \trim($pageType);
        $action = \strtolower(\trim($action));
        $blockKey = \trim($blockKey);
        if ($pageType === '' || !\in_array($action, ['create', 'delete', 'refine', 'rebuild'], true)) {
            throw new \RuntimeException('Stage-1 block mutation requires page_type and action=create|delete|refine|rebuild.');
        }
        if ($action !== 'create' && $blockKey === '') {
            throw new \RuntimeException('Stage-1 block mutation requires block_key for refine/rebuild/delete.');
        }
        if ($pageType === 'shared') {
            return $this->mutateDraftSharedPlanBlock($scope, $action, $blockKey, $blockPatch);
        }

        $structured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = $this->resolveStageOneExecutionBlueprint($scope);
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
        $pagePlan = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($pagePlan === []) {
            throw new \RuntimeException('Stage-1 page plan not found for page type: ' . $pageType);
        }

        $blocks = \array_values(\array_filter(
            \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [],
            static fn($block): bool => \is_array($block)
        ));
        $mutatedBlock = null;
        $removedBlock = null;

        if ($action === 'delete') {
            foreach ($blocks as $index => $block) {
                if ($this->stageOneBlockKeyMatches($block, $blockKey)) {
                    $removedBlock = $block;
                    unset($blocks[$index]);
                    break;
                }
            }
            if ($removedBlock === null) {
                throw new \RuntimeException('Stage-1 block not found for delete: ' . $blockKey);
            }
        } elseif ($action === 'create') {
            $existingKeys = \array_values(\array_filter(\array_map(
                static fn(array $block): string => \trim((string)($block['block_key'] ?? $block['section_code'] ?? '')),
                $blocks
            ), static fn(string $key): bool => $key !== ''));
            $mutatedBlock = $this->buildStageOneCreatedBlock($pageType, $pagePlan, $blockPatch, $existingKeys);
            $inserted = false;
            $afterBlockKey = \trim((string)($blockPatch['after_block_key'] ?? ''));
            if ($afterBlockKey !== '') {
                foreach ($blocks as $index => $block) {
                    if (!$this->stageOneBlockKeyMatches($block, $afterBlockKey)) {
                        continue;
                    }
                    \array_splice($blocks, $index + 1, 0, [$mutatedBlock]);
                    $inserted = true;
                    break;
                }
            }
            if (!$inserted) {
                $blocks[] = $mutatedBlock;
            }
        } else {
            foreach ($blocks as $index => $block) {
                if (!$this->stageOneBlockKeyMatches($block, $blockKey)) {
                    continue;
                }
                unset($blockPatch['block_key'], $blockPatch['section_code'], $blockPatch['after_block_key']);
                $mutatedBlock = \array_replace_recursive($block, $blockPatch);
                $mutatedBlock['block_key'] = \trim((string)($block['block_key'] ?? $blockKey));
                $mutatedBlock['section_code'] = \trim((string)($block['section_code'] ?? $mutatedBlock['block_key']));
                $mutatedBlock['version'] = (int)($block['version'] ?? 1) + 1;
                $mutatedBlock['mutation_source'] = 'stage1_block_mutation_api';
                $mutatedBlock['mutated_at'] = \date('c');
                if (\trim((string)($mutatedBlock['implementation_detail'] ?? $mutatedBlock['implementation_note'] ?? '')) === '') {
                    $mutatedBlock['implementation_detail'] = 'Render this updated block as a concrete page section with visitor-ready copy, editable fields, and responsive layout.';
                }
                unset($mutatedBlock['reason'], $mutatedBlock['why']);
                $blocks[$index] = $mutatedBlock;
                break;
            }
            if ($mutatedBlock === null) {
                throw new \RuntimeException('Stage-1 block not found for mutation: ' . $blockKey);
            }
        }

        $sharedPromptContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null)
            ? $executionBlueprint['shared_prompt_context']
            : (\is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : []);
        $blocks = \array_values($blocks);
        foreach ($blocks as $index => $block) {
            $block['sort_order'] = ($index + 1) * 10;
            $block['order'] = ($index + 1) * 10;
            $blocks[$index] = $this->normalizeStageOnePageBlock($pageType, $pagePlan, $block, $index, $sharedPromptContext);
        }

        $pagePlan['blocks'] = $blocks;
        $pagePlan['block_tree_version'] = (int)($pagePlan['block_tree_version'] ?? 1) + 1;
        $pagePlan['assembly_version'] = (int)($pagePlan['assembly_version'] ?? 1) + 1;
        $pagePlan['last_block_mutation'] = [
            'action' => $action,
            'block_key' => $action === 'create'
                ? (string)($mutatedBlock['block_key'] ?? '')
                : ($action === 'delete' ? (string)($removedBlock['block_key'] ?? $blockKey) : (string)($mutatedBlock['block_key'] ?? $blockKey)),
            'mutated_at' => \date('c'),
        ];
        $pages[$pageType] = $pagePlan;
        $structured['pages'] = $pages;
        $executionBlueprint['pages'] = $pages;

        $assembled = $this->assembleStageOneDraftArtifacts($scope, $structured, $executionBlueprint);
        $summary = [
            'action' => $action,
            'page_type' => $pageType,
            'block_key' => (string)($pagePlan['last_block_mutation']['block_key'] ?? $blockKey),
            'block_count' => \count($blocks),
            'block_tree_version' => (int)$pagePlan['block_tree_version'],
            'assembly_version' => (int)$pagePlan['assembly_version'],
        ];
        $assembled['mutation_summary'] = $summary;
        $assembled['block'] = $action === 'delete' ? null : $this->findStageOnePageBlock(
            \is_array($assembled['structured']['pages'][$pageType]['blocks'] ?? null) ? $assembled['structured']['pages'][$pageType]['blocks'] : [],
            (string)$summary['block_key']
        );

        return $assembled;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $executionBlueprint
     * @return array{
     *     plan_json:array<string, mixed>,
     *     structured:array<string, mixed>,
     *     execution_blueprint:array<string, mixed>,
     *     markdown:string,
     *     plan_workbench:array<string, mixed>
     * }
     */
    private function assembleStageOneDraftArtifacts(array $scope, array $structured, array $executionBlueprint): array
    {
        $sharedPromptContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null)
            ? $executionBlueprint['shared_prompt_context']
            : (\is_array($structured['shared_plan']['shared_prompt_context'] ?? null) ? $structured['shared_plan']['shared_prompt_context'] : []);
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        $structured['pages'] = $pages;
        $structured['page_plans'] = $pagePlans;
        $executionBlueprint['pages'] = $pages;
        $executionBlueprint['page_plans'] = $pagePlans;

        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $structured['shared_components'] = $sharedComponents;
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'header_block' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
                'footer_block' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
            ]
        );
        $executionBlueprint['shared_components'] = $sharedComponents;

        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $structured['block_index'] = $blockIndex;
        $executionBlueprint['block_index'] = $blockIndex;
        $tasks = $this->buildStageOneTasksFromBlocks($sharedComponents, $pagePlans);
        $structured['execution_steps'] = $this->buildExecutionSteps($tasks);
        $executionBlueprint['tasks'] = $tasks;
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? $structured['i18n']['locale'] ?? ''));
        $planJson = $this->buildPlanJson($structured);
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param array<string, mixed> $pagePlans
     * @return list<array<string, mixed>>
     */
    private function buildStageOneTasksFromBlocks(array $sharedComponents, array $pagePlans): array
    {
        $tasks = [];
        foreach ($this->normalizeStageOneSharedComponents($sharedComponents) as $sharedComponent) {
            if (\is_array($sharedComponent)) {
                $tasks[] = $sharedComponent;
            }
        }
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $tasks[] = $this->buildPageTask((string)$pageType, $pagePlan, $block);
            }
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stageOneBlockKeyMatches(array $block, string $blockKey): bool
    {
        $blockKey = \trim($blockKey);
        if ($blockKey === '') {
            return false;
        }

        return \in_array($blockKey, [
            \trim((string)($block['block_key'] ?? '')),
            \trim((string)($block['section_code'] ?? '')),
            \trim((string)($block['task_key'] ?? '')),
        ], true);
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $blockPatch
     * @param list<string> $existingKeys
     * @return array<string, mixed>
     */
    private function buildStageOneCreatedBlock(array|string $pageType, array $pagePlan, array $blockPatch, array $existingKeys): array
    {
        $pageType = (string)$pageType;
        $rawKey = \trim((string)($blockPatch['block_key'] ?? $blockPatch['section_code'] ?? $blockPatch['title'] ?? $blockPatch['label'] ?? 'custom_block'));
        $blockKey = $this->uniqueStageOneBlockKey($this->sanitizeStageOneBlockKey($rawKey), $existingKeys);
        $title = \trim((string)($blockPatch['title'] ?? $blockPatch['label'] ?? \str_replace(['_', '-'], ' ', $blockKey)));
        $goal = \trim((string)($blockPatch['goal'] ?? $blockPatch['instruction'] ?? 'Add a focused page section that supports the current page goal.'));
        $content = \trim((string)($blockPatch['content'] ?? $blockPatch['content_brief']['summary'] ?? $goal));
        $fieldPlan = \is_array($blockPatch['field_plan'] ?? null) ? $blockPatch['field_plan'] : [
            ['field' => 'headline', 'sample' => $title, 'implementation_note' => 'Use as the visible headline for the new page block.'],
            ['field' => 'body_copy', 'sample' => $content, 'implementation_note' => 'Use as visitor-facing body copy in the new page block.'],
        ];

        $createdBlock = \array_replace_recursive([
            'block_key' => $blockKey,
            'section_code' => $blockKey,
            'title' => $title,
            'goal' => $goal,
            'content_brief' => ['summary' => $content],
            'realtime_content' => [
                'headline' => $title,
                'supporting_copy' => [$content],
            ],
            'field_plan' => $fieldPlan,
            'implementation_detail' => 'Render the new section as a concrete page block with editable copy, CTA/media slots when relevant, and responsive layout.',
            'completion_rule' => 'Block is complete when headline, copy, editable fields, responsive layout, and any CTA/media slots are ready.',
            'editable_fields' => ['headline', 'body_copy'],
            'content_source' => ['user_instruction', 'editable_field'],
            'responsive_rule' => 'Stack content vertically on small screens and keep CTA/media visible.',
            'status' => 'done',
            'mutation_source' => 'stage1_block_mutation_api',
            'created_for_page' => $pageType,
            'page_label' => (string)($pagePlan['page_label'] ?? $pageType),
            'version' => 1,
        ], $blockPatch, [
            'block_key' => $blockKey,
            'section_code' => (string)($blockPatch['section_code'] ?? $blockKey),
        ]);
        unset($createdBlock['reason'], $createdBlock['why']);

        return $createdBlock;
    }

    private function sanitizeStageOneBlockKey(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9_-]+/i', '_', $value);
        $value = \trim($value, '_-');

        return $value !== '' ? $value : 'custom_block';
    }

    /**
     * @param list<string> $existingKeys
     */
    private function uniqueStageOneBlockKey(string $baseKey, array $existingKeys): string
    {
        $existing = \array_fill_keys(\array_map('strval', $existingKeys), true);
        $candidate = $baseKey !== '' ? $baseKey : 'custom_block';
        $suffix = 2;
        while (isset($existing[$candidate])) {
            $candidate = $baseKey . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function findStageOnePageBlock(array $blocks, string $blockKey): ?array
    {
        foreach ($blocks as $block) {
            if (\is_array($block) && $this->stageOneBlockKeyMatches($block, $blockKey)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $blockPatch
     * @return array{
     *     plan_json:array<string, mixed>,
     *     structured:array<string, mixed>,
     *     execution_blueprint:array<string, mixed>,
     *     markdown:string,
     *     plan_workbench:array<string, mixed>,
     *     mutation_summary:array<string, mixed>,
     *     block:array<string, mixed>|null
     * }
     */
    private function mutateDraftSharedPlanBlock(
        array $scope,
        string $action,
        string $blockKey = '',
        array $blockPatch = []
    ): array {
        $structured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = $this->resolveStageOneExecutionBlueprint($scope);
        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        $mutatedComponent = '';
        $mutatedBlock = null;
        $removedBlock = null;

        if ($action === 'delete') {
            $component = $this->findStageOneSharedComponentKey($sharedComponents, $blockKey);
            if ($component === '') {
                throw new \RuntimeException('Stage-1 shared block not found for delete: ' . $blockKey);
            }
            $removedBlock = $sharedComponents[$component] ?? null;
            unset($sharedComponents[$component]);
            $mutatedComponent = $component;
        } elseif ($action === 'create') {
            $mutatedBlock = $this->buildStageOneCreatedSharedBlock($blockPatch, \array_keys($sharedComponents));
            $mutatedComponent = (string)($mutatedBlock['component'] ?? '');
            $afterComponent = $this->findStageOneSharedComponentKey($sharedComponents, \trim((string)($blockPatch['after_block_key'] ?? '')));
            $sharedComponents = $this->insertStageOneSharedComponent($sharedComponents, $mutatedComponent, $mutatedBlock, $afterComponent);
        } else {
            $component = $this->findStageOneSharedComponentKey($sharedComponents, $blockKey);
            if ($component === '') {
                throw new \RuntimeException('Stage-1 shared block not found for mutation: ' . $blockKey);
            }
            $currentBlock = \is_array($sharedComponents[$component] ?? null) ? $sharedComponents[$component] : [];
            unset(
                $blockPatch['block_key'],
                $blockPatch['block_type'],
                $blockPatch['task_key'],
                $blockPatch['component'],
                $blockPatch['after_block_key']
            );
            $mutatedBlock = \array_replace_recursive($currentBlock, $blockPatch);
            $mutatedBlock['component'] = $component;
            $mutatedBlock['task_key'] = 'shared:' . $component;
            $mutatedBlock['block_key'] = 'shared:' . $component;
            $mutatedBlock['block_type'] = 'shared:' . $component;
            $mutatedBlock['version'] = (int)($currentBlock['version'] ?? 1) + 1;
            $mutatedBlock['mutation_source'] = 'stage1_shared_block_mutation_api';
            $mutatedBlock['mutated_at'] = \date('c');
            if (\trim((string)($mutatedBlock['implementation_detail'] ?? $mutatedBlock['implementation_note'] ?? '')) === '') {
                $mutatedBlock['implementation_detail'] = 'Render this updated shared block with concrete links, editable copy, and responsive site-wide behavior.';
            }
            unset($mutatedBlock['reason'], $mutatedBlock['why']);
            $sharedComponents[$component] = $mutatedBlock;
            $mutatedComponent = $component;
        }

        $sharedComponents = $this->normalizeStageOneSharedComponentOrder($sharedComponents);
        $mutatedBlockKey = $action === 'create'
            ? 'shared:' . $mutatedComponent
            : ($action === 'delete'
                ? (string)($removedBlock['task_key'] ?? ('shared:' . $mutatedComponent))
                : (string)($mutatedBlock['task_key'] ?? ('shared:' . $mutatedComponent)));
        $sharedTreeVersion = \max(
            (int)($structured['shared_plan']['block_tree_version'] ?? 1),
            (int)($executionBlueprint['shared_block_tree_version'] ?? 1)
        ) + 1;
        $assemblyVersion = \max(
            (int)($structured['shared_plan']['assembly_version'] ?? 1),
            (int)($executionBlueprint['assembly_version'] ?? 1)
        ) + 1;

        $structured['shared_components'] = $sharedComponents;
        $themeContextSnapshot = \is_array($structured['theme_context_snapshot'] ?? null)
            ? $structured['theme_context_snapshot']
            : (\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $pageTypes = \is_array($structured['page_types'] ?? null)
            ? \array_values(\array_map('strval', $structured['page_types']))
            : (\is_array($executionBlueprint['page_types'] ?? null) ? \array_values(\array_map('strval', $executionBlueprint['page_types'])) : []);
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? $structured['i18n']['locale'] ?? ''));
        $sharedPromptContext = $this->buildStageOneSharedPromptContext($themeContextSnapshot, $sharedComponents, $pageTypes, $planLocale);
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'theme_design' => \is_array($structured['shared_plan']['theme_design'] ?? null)
                    ? $structured['shared_plan']['theme_design']
                    : $themeContextSnapshot,
                'header_block' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
                'footer_block' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
                'shared_prompt_context' => $sharedPromptContext,
                'block_tree_version' => $sharedTreeVersion,
                'assembly_version' => $assemblyVersion,
                'last_block_mutation' => [
                    'action' => $action,
                    'block_key' => $mutatedBlockKey,
                    'mutated_at' => \date('c'),
                ],
            ]
        );
        $executionBlueprint['shared_components'] = $sharedComponents;
        $executionBlueprint['shared_prompt_context'] = $sharedPromptContext;
        $executionBlueprint['theme_context_snapshot'] = $themeContextSnapshot;
        $executionBlueprint['shared_block_tree_version'] = $sharedTreeVersion;
        $executionBlueprint['assembly_version'] = $assemblyVersion;
        [$structured, $executionBlueprint] = $this->markStageOnePageWorkStaleForSharedContextChange(
            $structured,
            $executionBlueprint,
            \trim((string)($sharedPromptContext['context_hash'] ?? ''))
        );
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        $structured['page_plans'] = $pagePlans;
        $executionBlueprint['pages'] = $pages;
        $executionBlueprint['page_plans'] = $pagePlans;
        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $structured['block_index'] = $blockIndex;
        $executionBlueprint['block_index'] = $blockIndex;
        $tasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
        $executionBlueprint['tasks'] = $this->rebuildStageOneSharedTaskList($tasks, $sharedComponents);
        $structured['execution_steps'] = $this->buildExecutionSteps($executionBlueprint['tasks']);
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);
        $planJson = $this->buildPlanJson($structured);
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);
        $assembled = [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
        ];
        $summary = [
            'action' => $action,
            'page_type' => 'shared',
            'block_key' => $mutatedBlockKey,
            'block_count' => \count($sharedComponents),
            'block_tree_version' => $sharedTreeVersion,
            'assembly_version' => $assemblyVersion,
        ];
        $assembled['mutation_summary'] = $summary;
        $assembled['block'] = $action === 'delete'
            ? null
            : $this->findStageOneSharedComponent($assembled['structured']['shared_components'] ?? [], $mutatedBlockKey);

        return $assembled;
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     */
    private function findStageOneSharedComponentKey(array $sharedComponents, string $blockKey): string
    {
        $blockKey = \trim($blockKey);
        if ($blockKey === '') {
            return '';
        }
        $componentKey = \str_starts_with($blockKey, 'shared:') ? \substr($blockKey, \strlen('shared:')) : $blockKey;
        foreach ($sharedComponents as $component => $componentPlan) {
            if (!\is_array($componentPlan)) {
                continue;
            }
            $component = \trim((string)$component);
            $candidates = [
                $component,
                'shared:' . $component,
                \trim((string)($componentPlan['component'] ?? '')),
                \trim((string)($componentPlan['task_key'] ?? '')),
                \trim((string)($componentPlan['block_key'] ?? '')),
                \trim((string)($componentPlan['block_type'] ?? '')),
            ];
            if (\in_array($blockKey, $candidates, true) || \in_array($componentKey, $candidates, true)) {
                return $component;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $sharedComponents
     * @return array<string, mixed>|null
     */
    private function findStageOneSharedComponent(array $sharedComponents, string $blockKey): ?array
    {
        $component = $this->findStageOneSharedComponentKey(
            \array_filter($sharedComponents, static fn($componentPlan): bool => \is_array($componentPlan)),
            $blockKey
        );

        return $component !== '' && \is_array($sharedComponents[$component] ?? null)
            ? $sharedComponents[$component]
            : null;
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param array<string, mixed> $componentPlan
     * @return array<string, array<string, mixed>>
     */
    private function insertStageOneSharedComponent(
        array $sharedComponents,
        string $component,
        array $componentPlan,
        string $afterComponent = ''
    ): array {
        $result = [];
        $inserted = false;
        foreach ($sharedComponents as $currentComponent => $currentPlan) {
            $result[$currentComponent] = $currentPlan;
            if ($afterComponent !== '' && (string)$currentComponent === $afterComponent) {
                $result[$component] = $componentPlan;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $result[$component] = $componentPlan;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     * @return array<string, array<string, mixed>>
     */
    private function normalizeStageOneSharedComponentOrder(array $sharedComponents): array
    {
        $normalized = [];
        foreach (\array_values($sharedComponents) as $index => $componentPlan) {
            if (!\is_array($componentPlan)) {
                continue;
            }
            $component = \trim((string)($componentPlan['component'] ?? ''));
            if ($component === '') {
                $taskKey = \trim((string)($componentPlan['task_key'] ?? $componentPlan['block_key'] ?? ''));
                $component = \str_starts_with($taskKey, 'shared:') ? \substr($taskKey, \strlen('shared:')) : $taskKey;
            }
            $component = $this->sanitizeStageOneBlockKey($component);
            $componentPlan['component'] = $component;
            $componentPlan['task_key'] = 'shared:' . $component;
            $componentPlan['block_key'] = 'shared:' . $component;
            $componentPlan['block_type'] = 'shared:' . $component;
            $componentPlan['sort_order'] = ($index + 1) * 10;
            $normalized[$component] = $this->normalizeStageOneSharedBlock($component, $componentPlan);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $blockPatch
     * @param list<string> $existingComponents
     * @return array<string, mixed>
     */
    private function buildStageOneCreatedSharedBlock(array $blockPatch, array $existingComponents): array
    {
        $rawKey = \trim((string)($blockPatch['component'] ?? $blockPatch['block_key'] ?? $blockPatch['task_key'] ?? $blockPatch['title'] ?? $blockPatch['label'] ?? 'shared_block'));
        if (\str_starts_with($rawKey, 'shared:')) {
            $rawKey = \substr($rawKey, \strlen('shared:'));
        }
        $component = $this->uniqueStageOneBlockKey($this->sanitizeStageOneBlockKey($rawKey), $existingComponents);
        $taskKey = 'shared:' . $component;
        $title = \trim((string)($blockPatch['title'] ?? $blockPatch['label'] ?? \str_replace(['_', '-'], ' ', $component)));
        $goal = \trim((string)($blockPatch['goal'] ?? $blockPatch['instruction'] ?? 'Add a reusable shared block that supports the site-wide experience.'));
        $content = \trim((string)($blockPatch['content'] ?? $blockPatch['content_brief']['summary'] ?? $goal));
        $fieldPlan = \is_array($blockPatch['field_plan'] ?? null) ? $blockPatch['field_plan'] : [
            ['field' => 'headline', 'sample' => $title, 'implementation_note' => 'Use as the visible headline for this shared block.'],
            ['field' => 'supporting_copy', 'sample' => $content, 'implementation_note' => 'Use as reusable shared copy that can be edited before build.'],
        ];

        $createdBlock = \array_replace_recursive([
            'component' => $component,
            'task_key' => $taskKey,
            'block_key' => $taskKey,
            'block_type' => $taskKey,
            'task_type' => 'shared_component',
            'title' => $title,
            'goal' => $goal,
            'content_brief' => ['summary' => $content],
            'realtime_content' => [
                'headline' => $title,
                'supporting_copy' => [$content],
                'cta' => [],
                'media' => [],
                'editable_slots' => [],
            ],
            'field_plan' => $fieldPlan,
            'implementation_detail' => 'Render the new shared block as reusable site chrome with editable copy, links, responsive behavior, and page-safe defaults.',
            'completion_rule' => 'Shared block is complete when copy, links, editable fields, and responsive behavior are ready for all relevant pages.',
            'editable_fields' => ['headline', 'supporting_copy'],
            'content_source' => ['user_instruction', 'theme_context_snapshot', 'shared_prompt_context', 'editable_field'],
            'responsive_rule' => 'Keep the shared block readable on mobile and avoid obscuring page content.',
            'status' => 'done',
            'mutation_source' => 'stage1_shared_block_mutation_api',
            'version' => 1,
        ], $blockPatch, [
            'component' => $component,
            'task_key' => $taskKey,
            'block_key' => $taskKey,
            'block_type' => $taskKey,
            'task_type' => 'shared_component',
        ]);
        unset($createdBlock['reason'], $createdBlock['why']);

        return $createdBlock;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $orderedBlockKeys
     * @return array{
     *     plan_json:array<string, mixed>,
     *     structured:array<string, mixed>,
     *     execution_blueprint:array<string, mixed>,
     *     markdown:string,
     *     plan_workbench:array<string, mixed>,
     *     reorder_summary:array<string, mixed>
     * }
     */
    private function reorderDraftSharedPlanBlocks(array $scope, array $orderedBlockKeys): array
    {
        $structured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        $executionBlueprint = $this->resolveStageOneExecutionBlueprint($scope);
        $sharedComponents = $this->resolveStageOneSharedComponents($structured, $executionBlueprint);
        if (\count($sharedComponents) < 2) {
            throw new \RuntimeException('Stage-1 shared block list needs at least two blocks to reorder.');
        }

        $originalOrder = \array_values(\array_map(
            static fn($component): string => 'shared:' . (string)$component,
            \array_keys($sharedComponents)
        ));
        $sharedComponents = $this->reorderStageOneSharedComponents($sharedComponents, $orderedBlockKeys);
        $normalizedOrder = \array_values(\array_map(
            static fn($component): string => 'shared:' . (string)$component,
            \array_keys($sharedComponents)
        ));

        $structured['shared_components'] = $sharedComponents;
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'header_block' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
                'footer_block' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
                'shared_blocks' => $this->buildStageOneSharedBlocksPlanJson($sharedComponents),
            ]
        );

        $pageTypes = \is_array($structured['page_types'] ?? null)
            ? \array_values(\array_map('strval', $structured['page_types']))
            : (\is_array($executionBlueprint['page_types'] ?? null) ? \array_values(\array_map('strval', $executionBlueprint['page_types'])) : []);
        $themeContextSnapshot = \is_array($structured['theme_context_snapshot'] ?? null)
            ? $structured['theme_context_snapshot']
            : (\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? $structured['i18n']['locale'] ?? ''));
        $sharedPromptContext = $this->buildStageOneSharedPromptContext($themeContextSnapshot, $sharedComponents, $pageTypes, $planLocale);
        $structured['shared_plan']['theme_design'] = \is_array($structured['shared_plan']['theme_design'] ?? null)
            ? $structured['shared_plan']['theme_design']
            : $themeContextSnapshot;
        $structured['shared_plan']['shared_prompt_context'] = $sharedPromptContext;
        [$structured, $executionBlueprint] = $this->markStageOnePageWorkStaleForSharedContextChange(
            $structured,
            $executionBlueprint,
            \trim((string)($sharedPromptContext['context_hash'] ?? ''))
        );

        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
        $structured['page_plans'] = $pagePlans;
        $executionBlueprint['pages'] = $pages;
        $executionBlueprint['page_plans'] = $pagePlans;
        $executionBlueprint['shared_components'] = $sharedComponents;
        $executionBlueprint['shared_prompt_context'] = $sharedPromptContext;
        $executionBlueprint['theme_context_snapshot'] = $themeContextSnapshot;

        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);
        $structured['block_index'] = $blockIndex;
        $executionBlueprint['block_index'] = $blockIndex;
        $tasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
        $executionBlueprint['tasks'] = $this->rebuildStageOneSharedTaskList($tasks, $sharedComponents);
        $structured['execution_steps'] = $this->buildExecutionSteps($executionBlueprint['tasks']);
        $executionBlueprint['signature'] = $this->buildExecutionBlueprintSignature($executionBlueprint);

        $planJson = $this->buildPlanJson($structured);
        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);

        return [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
            'reorder_summary' => [
                'page_type' => 'shared',
                'original_order' => $originalOrder,
                'ordered_block_keys' => $normalizedOrder,
                'block_count' => \count($normalizedOrder),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param list<string> $orderedBlockKeys
     * @return array<string, array<string, mixed>>
     */
    private function reorderStageOneSharedComponents(array $sharedComponents, array $orderedBlockKeys): array
    {
        $orderMap = [];
        foreach ($orderedBlockKeys as $position => $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if (\str_starts_with($blockKey, 'shared:')) {
                $blockKey = \substr($blockKey, \strlen('shared:'));
            }
            if ($blockKey === '' || isset($orderMap[$blockKey])) {
                continue;
            }
            $orderMap[$blockKey] = $position;
        }

        $wrapped = [];
        foreach ($this->normalizeStageOneSharedComponents($sharedComponents) as $index => $componentPlan) {
            if (!\is_array($componentPlan)) {
                continue;
            }
            $component = \trim((string)($componentPlan['component'] ?? $index));
            $wrapped[] = [
                'component' => $component,
                'position' => $orderMap[$component] ?? \PHP_INT_MAX,
                'previous_sort_order' => (int)($componentPlan['sort_order'] ?? 0),
                'component_plan' => $componentPlan,
            ];
        }

        \usort($wrapped, static function (array $left, array $right): int {
            $positionCompare = ((int)$left['position']) <=> ((int)$right['position']);
            if ($positionCompare !== 0) {
                return $positionCompare;
            }
            $sortCompare = ((int)$left['previous_sort_order']) <=> ((int)$right['previous_sort_order']);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return \strcmp((string)$left['component'], (string)$right['component']);
        });

        $result = [];
        foreach ($wrapped as $offset => $row) {
            $componentPlan = \is_array($row['component_plan']) ? $row['component_plan'] : [];
            $component = \trim((string)($row['component'] ?? $componentPlan['component'] ?? ''));
            if ($component === '') {
                continue;
            }
            $componentPlan['component'] = $component;
            $componentPlan['task_key'] = 'shared:' . $component;
            $componentPlan['sort_order'] = ($offset + 1) * 10;
            $result[$component] = $componentPlan;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, array<string, mixed>> $sharedComponents
     * @return list<array<string, mixed>>
     */
    private function rebuildStageOneSharedTaskList(array $tasks, array $sharedComponents): array
    {
        $sharedTasks = \array_values($this->normalizeStageOneSharedComponents($sharedComponents));
        $pageTasks = [];
        foreach ($tasks as $task) {
            if (!\is_array($task) || (string)($task['task_type'] ?? '') === 'shared_component') {
                continue;
            }
            $pageTasks[] = $task;
        }

        return \array_values(\array_merge($sharedTasks, $pageTasks));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $orderedBlockKeys
     * @return list<array<string, mixed>>
     */
    private function reorderStageOneBlockList(array $blocks, array $orderedBlockKeys): array
    {
        $orderMap = [];
        foreach ($orderedBlockKeys as $position => $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '' || isset($orderMap[$blockKey])) {
                continue;
            }
            $orderMap[$blockKey] = $position;
        }

        $wrapped = [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? ''));
            $wrapped[] = [
                'index' => $index,
                'position' => $orderMap[$blockKey] ?? \PHP_INT_MAX,
                'block' => $block,
            ];
        }

        \usort($wrapped, static function (array $left, array $right): int {
            $positionCompare = ((int)$left['position']) <=> ((int)$right['position']);
            if ($positionCompare !== 0) {
                return $positionCompare;
            }
            return ((int)$left['index']) <=> ((int)$right['index']);
        });

        $result = [];
        foreach ($wrapped as $offset => $row) {
            $block = \is_array($row['block'] ?? null) ? $row['block'] : [];
            $sortOrder = ($offset + 1) * 10;
            $block['sort_order'] = $sortOrder;
            $block['order'] = $sortOrder;
            $result[] = $block;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStageOnePageBlockTasks(string $pageType, array $pagePlan): array
    {
        $tasks = [];
        foreach (\is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $tasks[] = $this->buildPageTask($pageType, $pagePlan, $block);
        }

        return $tasks;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $replacementTasks
     * @return list<array<string, mixed>>
     */
    private function replaceStageOnePageTasks(array $tasks, string $pageType, array $replacementTasks): array
    {
        $result = [];
        $inserted = false;
        foreach ($tasks as $task) {
            if (!\is_array($task) || !$this->isStageOnePageTaskForPage($task, $pageType)) {
                $result[] = $task;
                continue;
            }

            if (!$inserted) {
                foreach ($replacementTasks as $replacementTask) {
                    $result[] = $replacementTask;
                }
                $inserted = true;
            }
        }

        if (!$inserted) {
            foreach ($replacementTasks as $replacementTask) {
                $result[] = $replacementTask;
            }
        }

        return \array_values($result);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isStageOnePageTaskForPage(array $task, string $pageType): bool
    {
        if ((string)($task['page_type'] ?? '') !== $pageType) {
            return false;
        }

        return (string)($task['task_type'] ?? '') === 'page_block'
            || \trim((string)($task['block']['block_key'] ?? $task['block_key'] ?? $task['section_code'] ?? '')) !== '';
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param list<string> $orderedBlockKeys
     * @return list<array<string, mixed>>
     */
    private function rebuildStageOneTaskList(array $tasks, string $pageType, array $orderedBlockKeys): array
    {
        $orderMap = [];
        foreach ($orderedBlockKeys as $position => $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '' || isset($orderMap[$blockKey])) {
                continue;
            }
            $orderMap[$blockKey] = $position;
        }

        $targetTasks = [];
        foreach ($tasks as $index => $task) {
            if (!\is_array($task) || (string)($task['page_type'] ?? '') !== $pageType) {
                continue;
            }
            $blockKey = \trim((string)($task['block']['block_key'] ?? $task['block_key'] ?? $task['section_code'] ?? ''));
            if ($blockKey === '') {
                continue;
            }
            $targetTasks[] = [
                'index' => $index,
                'position' => $orderMap[$blockKey] ?? \PHP_INT_MAX,
                'task' => $task,
            ];
        }

        \usort($targetTasks, static function (array $left, array $right): int {
            $positionCompare = ((int)$left['position']) <=> ((int)$right['position']);
            if ($positionCompare !== 0) {
                return $positionCompare;
            }
            return ((int)$left['index']) <=> ((int)$right['index']);
        });

        $reorderedTargetTasks = \array_values(\array_map(static fn(array $row): array => $row['task'], $targetTasks));
        foreach ($reorderedTargetTasks as $offset => $task) {
            $sortOrder = ($offset + 1) * 10;
            $task['sort_order'] = $sortOrder;
            if (\is_array($task['block'] ?? null)) {
                $task['block']['sort_order'] = $sortOrder;
                $task['block']['order'] = $sortOrder;
            }
            $reorderedTargetTasks[$offset] = $task;
        }
        $cursor = 0;
        $result = [];
        foreach ($tasks as $task) {
            if (
                \is_array($task)
                && (string)($task['page_type'] ?? '') === $pageType
                && \trim((string)($task['block']['block_key'] ?? $task['block_key'] ?? $task['section_code'] ?? '')) !== ''
            ) {
                $result[] = $reorderedTargetTasks[$cursor] ?? $task;
                $cursor++;
                continue;
            }
            $result[] = $task;
        }

        return \array_values($result);
    }

    private function getAiService(): AiService
    {
        return $this->aiService ?? ObjectManager::getInstance(AiService::class);
    }

    private function getReferenceImageInsightService(): AiSiteReferenceImageInsightService
    {
        return $this->referenceImageInsightService ?? ObjectManager::getInstance(AiSiteReferenceImageInsightService::class);
    }

    private function isEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '') {
            return false;
        }

        return \str_starts_with($locale, 'en');
    }

    /**
     * T5: 方案 Markdown 章节完整性校验器
     * 验证生成的方案 Markdown 是否包含所有必需章节
     *
     * @param string $markdown 方案 Markdown 文本
     * @param string $locale 方案语言
     * @return array{valid: bool, missing_sections: list<string>, warnings: list<string>}
     */
    public function validateMarkdownSections(string $markdown, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdown = \trim($markdown);
        $missingSections = [];
        $warnings = [];

        // 必须包含的章节（双语对照）。
        $requiredSections = [
            'style_overview' => [
                'zh' => ['风格总览', '主题风格', '风格概览'],
                'en' => ['Style Overview', 'Theme Style', 'style overview'],
            ],
            'palette' => [
                'zh' => ['颜色色系', '色系', '调色板', '调色盘', '色彩'],
                'en' => ['Color Palette', 'Palette', 'color palette', 'color system'],
            ],
            'header' => [
                'zh' => ['Header 设计', '头部设计', '页头设计'],
                'en' => ['Header Design', 'header design'],
            ],
            'footer' => [
                'zh' => ['Footer 设计', '底部设计', '页脚设计'],
                'en' => ['Footer Design', 'footer design'],
            ],
            'page_types_overview' => [
                'zh' => ['页面类型设计', '页面设计总览', '全站结构'],
                'en' => ['Page Type Design', 'Page Design Overview', 'Site Structure'],
            ],
            'page_block_details' => [
                'zh' => ['页面与区块执行细化', '分页面块级设计', '区块设计'],
                'en' => ['Page And Block Execution Details', 'Block Details'],
            ],
            'execution_order' => [
                'zh' => ['执行顺序', '任务蓝图摘要', '执行顺序与任务蓝图'],
                'en' => ['Execution Order', 'task blueprint'],
            ],
        ];

        // 检查每一个必需章节。
        foreach ($requiredSections as $sectionKey => $sectionLabels) {
            $labels = $isEn ? $sectionLabels['en'] : $sectionLabels['zh'];
            $found = false;
            foreach ($labels as $label) {
                if (\mb_stripos($markdown, $label) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingSections[] = $sectionKey;
            }
        }

        // 检查是否包含页面覆盖清单。
        if (\mb_stripos($markdown, '页面覆盖清单') === false
            && \mb_stripos($markdown, 'Page Coverage') === false
            && \mb_stripos($markdown, 'Selected Pages') === false
        ) {
            $warnings[] = $isEn
                ? 'Warning: Page coverage checklist not found.'
                : '警告：未找到页面覆盖清单。';
        }

        return [
            'valid' => $missingSections === [],
            'missing_sections' => $missingSections,
            'warnings' => $warnings,
        ];
    }

    /**
     * 检查方案 Markdown 是否包含指定页面类型的规划。
     *
     * @param string $markdown 方案 Markdown
     * @param list<string> $selectedPageTypes 用户选择的页面类型
     * @param string $locale 方案语言
     * @return array{valid: bool, missing_pages: list<string>, extra_pages: list<string>}
     */
    public function validatePageCoverage(string $markdown, array $selectedPageTypes, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdownLower = \mb_strtolower($markdown);
        $missingPages = [];
        $extraPages = [];

        // 常见页面类型关键词映射。
        $pageTypeKeywords = [
            'home_page' => ['首页', 'home page', 'homepage'],
            'about_page' => ['关于页面', 'about', '关于我们'],
            'contact_page' => ['联系页面', 'contact', '联系我们'],
            'product_page' => ['产品页面', 'product', '产品列表'],
            'blog_page' => ['博客页面', 'blog', '博客'],
            'service_page' => ['服务页面', 'service', '服务'],
            'privacy_page' => ['隐私政策', 'privacy'],
            'terms_page' => ['服务条款', 'terms'],
        ];

        // 检查每一个已选页面是否有规划。
        foreach ($selectedPageTypes as $pageType) {
            $keywords = $pageTypeKeywords[$pageType] ?? [$pageType, \str_replace('_', ' ', $pageType)];
            $found = false;
            foreach ($keywords as $keyword) {
                if (\mb_stripos($markdownLower, \mb_strtolower($keyword)) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingPages[] = $pageType;
            }
        }

        return [
            'valid' => $missingPages === [],
            'missing_pages' => $missingPages,
            'extra_pages' => $extraPages,
        ];
    }
}
