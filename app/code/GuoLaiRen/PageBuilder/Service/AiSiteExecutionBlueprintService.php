<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteExecutionBlueprintService
{
    public const VERSION = 1;
    private const AI_STREAM_MAX_TOKENS = 7168;
    /** @var array<string, array<string, mixed>|null> */
    private array $appendInstructionDecisionCache = [];

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
        private readonly ?AiService $aiService = null,
        private readonly ?AiResponseJsonParser $responseJsonParser = null,
    ) {
    }

    private function getResponseJsonParser(): AiResponseJsonParser
    {
        return $this->responseJsonParser ?? ObjectManager::getInstance(AiResponseJsonParser::class);
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
            'conversation' => $userConversation,
        ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
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
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $planningScope = \array_replace($scope, [
            'page_types' => $pageTypes,
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
            'plan_instruction' => $instruction,
            'plan_target_scope' => $targetScope,
            'plan_locale' => $planLocale,
        ]);

        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $planningScope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $planningScope);
        $palette = $this->buildPalettePlan($planningScope, $websiteProfile, $instruction);
        $themeStyle = $this->buildThemeStyle($planningScope, $websiteProfile, $palette, $instruction);
        $navigationPlan = $this->buildNavigationPlan($pageTypes);
        $footerPlan = $this->buildFooterPlan($pageTypes);
        $seoStrategy = $this->buildSeoStrategy($siteDisplayName, $planningScope, $pageTypes, $instruction);

        $tasks = [
            $this->buildSharedTask('header', $siteDisplayName, $navigationPlan, $palette, $themeStyle, $seoStrategy),
            $this->buildSharedTask('footer', $siteDisplayName, $footerPlan, $palette, $themeStyle, $seoStrategy),
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
                $planLocale
            );
            $pages[$pageType] = $pagePlan;
        }

        $sharedComponents = [
            'header' => $tasks[0],
            'footer' => $tasks[1],
        ];
        $sharedComponents = $this->normalizeStageOneSharedComponents($sharedComponents);
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
        $pagePlans = $this->buildStageOnePagePlans($pages, $sharedPromptContext);
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
            'request_summary' => $this->buildStageOneRequestSummary($planningScope, $websiteProfile, $instruction),
            'site_strategy' => [
                'site_display_name' => $siteDisplayName,
                'summary' => $siteSummary,
                'theme_style' => $themeStyle,
                'palette' => $palette,
                'instruction' => $instruction,
                'target_scope' => $targetScope,
                'plan_locale' => $planLocale,
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
     * 鐪熷疄 AI 娴佸紡鐢熸垚闃舵涓€鏂规锛涘け璐ユ椂鍥為€€鍒版湰鍦拌鍒掑櫒銆?
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
     *   ai_generated?:int,
     *   ai_fallback?:int
     * }
     */
    public function buildPlanArtifactsByAiStream(array $scope, array $websiteProfile, array $payload = [], ?callable $onChunk = null): array
    {
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $artifacts = $this->buildPlanArtifacts($scope, $websiteProfile, $payload);
            $artifacts = $this->applyFakeModePreviewMutation($artifacts, $scope, $payload);
            $artifacts['ai_generated'] = 0;
            $artifacts['ai_fallback'] = 1;
            $artifacts['generation_source'] = 'deterministic';
            return $artifacts;
        }

        $pageTypes = $this->expandPageTypes($scope);
        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $planLocale = \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? ''));
        $siteDisplayName = $this->pageBlueprintService->resolveSiteDisplayName($websiteProfile, $scope);
        $siteSummary = $this->pageBlueprintService->buildSiteMarketingSummary($websiteProfile, $scope);

        $prompt = $this->buildAiPlanPrompt($scope, $websiteProfile, $pageTypes, $planLocale, $instruction, $targetScope, $siteDisplayName, $siteSummary);
        $fullContent = '';

        try {
            $this->getAiService()->generateStream(
                $prompt,
                static function (string $chunk) use (&$fullContent, $onChunk): bool {
                    $fullContent .= $chunk;
                    if (\is_callable($onChunk) && $chunk !== '') {
                        $onChunk($chunk);
                    }
                    return true;
                },
                null,
                'pagebuilder_plan_generation',
                null,
                [
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.35,
                    // Leave headroom below the provider hard cap to avoid model-specific validation errors.
                    'max_tokens' => self::AI_STREAM_MAX_TOKENS,
                    'timeout' => 240,
                    'response_format' => ['type' => 'json_object'],
                ]
            );

            $parser = $this->getResponseJsonParser();
            $decoded = $parser->extractAndDecode($fullContent);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('invalid ai json: ' . \substr(\preg_replace('/\s+/', ' ', $fullContent), 0, 200));
            }

            $artifacts = $this->mapAiPlanToArtifacts($scope, $websiteProfile, $decoded, $pageTypes, $planLocale, $instruction, $targetScope);
            $artifacts['ai_generated'] = 1;
            $artifacts['ai_fallback'] = 0;
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
    public function refineDraftPlan(array $scope, array $websiteProfile, array $payload, ?callable $onChunk = null): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk);
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
     * 寰皟绛栫暐锛氶粯璁ょ疮鍔狅紙淇濈暀鍘嗗彶杩藉姞鍖哄潡锛夛紝浠呭綋鐢ㄦ埛鏄庣‘鈥滃垹闄?绉婚櫎鈥濇椂鎵ц鍒犻櫎銆?
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
     * fake_mode 闇€瑕佽鍧楃骇寰皟/鏂板/鍒犻櫎/閲嶅缓鍦ㄥ墠绔骇鐢熷彲瑙傚療宸紓锛?
     * 鍚﹀垯澶氭鎿嶄綔浼氬缁堣惤鍒板悓涓€浠界‘瀹氭€ц崏绋匡紝E2E 鏃犳硶鍒ゆ柇闃舵鍐呯紪杈戞槸鍚︾敓鏁堛€?
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
                'content' => $isEn ? 'Extractable block catalog for stage-2 task planning.' : '用于第二阶段任务拆解的区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '方案尾部',
                'content' => $isEn ? 'This blueprint is ready for stage-2 task extraction and execution.' : '该方案已可直接进入第二阶段任务拆解与执行。',
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
            || \str_contains($text, '鏂板')
            || \str_contains($text, '琛ヨ冻')
            || \str_contains($text, '娣诲姞');
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
    public function rebuildDraftPlan(array $scope, array $websiteProfile, array $payload, ?callable $onChunk = null): array
    {
        $artifacts = $this->buildPlanArtifactsByAiStream($scope, $websiteProfile, $payload, $onChunk);
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
            '中文要求：根据下面的【用户一句话需求】拓写出**真实可落地**的建站方案——给出具体导航、栏目、标题、正文、CTA、字段示例与落地说明，禁止通篇“围绕…/突出…/说明…”这类方向性描述。',
            '【用户一句话需求】(authoritative, expand from this): ' . $oneLineRequirement,
            '【站点名】: ' . ($siteDisplayName !== '' ? $siteDisplayName : '-'),
            '【站点摘要】: ' . ($siteSummary !== '' ? $siteSummary : '-'),
            '【选定页面类型】: ' . $pageTypeText,
            '【输出语言】: ' . $outputLanguage,
            '',
            'CONCRETENESS CONTRACT (must satisfy ALL):',
            '1) Every block has REAL on-page strings: nav labels, page titles, headings, subtitles, body sentences, CTA labels, link targets, form fields, trust points.',
            '2) Replace any sentence that would still be true for an unrelated business with words derived from the user one-line requirement, site name, and instruction.',
            '3) Use proper nouns / numbers / offers / brand voice; avoid generic "突出价值/说明亮点/完善导航" sentences.',
            '4) When facts are uncertain, use prefix "[假设]" and STILL output a concrete sample value (not a placeholder).',
            '5) navigation_plan.header_items, footer_plan.featured, footer_plan.policies must be non-empty arrays of {label, href} with real labels and routes.',
            '',
            'STAGE-1 SHARED THEME PLAN CONTRACT (theme_design must satisfy ALL):',
            '- theme_design is the concrete shared plan for Header/Footer and later page prompts; never output it as abstract direction, brand adjectives, or design-method notes.',
            '- theme_design.theme_purpose must name the site mission, target visitor, first-screen emotion, and conversion promise derived from the user one-line requirement.',
            '- theme_design.color_scheme must provide ready-to-apply hex colors for primary/secondary/accent/background/body/button; values must be concrete implementation decisions, not labels like "modern palette".',
            '- theme_design.typography_spacing_radius must give usable font family, heading/body scale, spacing scale, and radius scale decisions that can be implemented without another interpretation step.',
            '- theme_design.visual_keywords, tone_of_voice, cta_tone, and forbidden_styles must be specific reusable constraints for page prompts, not vague words like "premium", "clean", or "professional" alone.',
            '- Header/Footer planning must reuse this theme_design directly so shared_prompt_context can carry concrete colors, voice, CTA tone, and forbidden styles to every page.',
            '',
            'STAGE-1 PAGE THEME ALIGNMENT CONTRACT (pages must satisfy ALL):',
            '- Every page prompt MUST treat theme_design + shared_prompt_context as non-negotiable constraints, not optional inspiration.',
            '- Every page object MUST include theme_alignment_summary explaining how that page reuses theme_purpose, color_scheme, typography_spacing_radius, tone_of_voice, cta_tone, and forbidden_styles.',
            '- Required JSON phrase: "theme_alignment_summary":"string explaining how this page obeys theme_design/shared_prompt_context"',
            '- Repeat the shared theme decisions inside each page plan: page_goal, blocks, field_plan samples, execution_script, CTA wording, and media assets must visibly obey the same palette, voice, spacing/radius, and forbidden styles.',
            '- If a page idea conflicts with shared_prompt_context, rewrite the page idea. Never invent a per-page palette, voice, CTA style, or visual direction that diverges from theme_design.',
            '',
            'GOOD vs BAD examples (do NOT copy verbatim, learn the style):',
            'BAD field_plan.sample : "标题围绕核心价值展开"',
            'GOOD field_plan.sample: "30 分钟上手的轻量记账工具，给独立创作者用"',
            'BAD blocks[].content   : "突出品牌价值并引导用户行动"',
            'GOOD blocks[].content  : "首屏一句话讲清价值：’把发票、收入、税务一次理清’；下方两枚 CTA：[免费试用 30 天] 与 [查看演示]，配一行信任：已被 1,200+ 自由职业者使用。"',
            'BAD execution_script.core_copy : "简洁说明产品亮点"',
            'GOOD execution_script.core_copy: "三段式：痛点（账单零散）→ 方案（一处导入自动归类）→ 结果（每月节省 4 小时）。"',
            'BAD navigation_plan.header_items : []',
            'GOOD navigation_plan.header_items: [{"label":"首页","href":"/"},{"label":"功能","href":"/#features"},{"label":"定价","href":"/pricing"},{"label":"博客","href":"/blog"},{"label":"开始使用","href":"/signup"}]',
            '',
            'Return STRICT JSON only. No markdown fence. No prose outside JSON.',
            'The first non-whitespace character must be { and the last non-whitespace character must be }.',
            'Do not echo the schema, prompt rules, GOOD/BAD examples, or writing instructions back into the plan.',
            'JSON schema (return this structured plan object directly as the top-level JSON object):',
            '{',
            '    "i18n":{"locale":"string","labels":{"title":"string","site":"string","summary":"string","site_structure":"string","shared_global_plan":"string","page_details":"string"}},',
            '    "site_strategy":{"site_display_name":"string","summary":"string","website_type":"string","core_goal":"string","target_users":"string","conversion_path":"string"},',
            '    "theme_style":{"name":"string","visual_tone":"string","font_family":"string","selection_reason":"why this font family and voice/tone fit the user requirement"},',
            '    "palette":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","surface":"#hex","text":"#hex","selection_reason":"why this color system fits the user requirement"},',
            '    "theme_design":{"theme_purpose":"string","color_scheme":{"name":"string","primary":"#hex","secondary":"#hex","accent":"#hex","background":"#hex","body":"#hex","button":"#hex"},"typography_spacing_radius":{"font_family":"string","heading_scale":"string","body_scale":"string","spacing_scale":"string","radius_scale":"string"},"visual_keywords":["string"],"tone_of_voice":"string","cta_tone":"string","forbidden_styles":["string"],"selection_reason":"string"},',
            '    "navigation_plan":{"header_items":[{"label":"string","href":"string"}]},',
            '    "footer_plan":{"featured":[],"policies":[]},',
            '    "seo_strategy":{"core_intent":"string","primary_keywords":["string"],"keyword_page_map":[{"keyword":"string","page_type":"string"}],"content_strategy":"string","internal_linking":"string","url_structure":"string"},',
            '    "page_types":["home_page"],',
            '    "pages":{"home_page":{"page_goal":"string","theme_alignment_summary":"string explaining how this page obeys theme_design/shared_prompt_context","primary_keywords":["string"],"secondary_keywords":["string"],"blocks":[{"block_key":"string","goal":"string","keywords":["string"],"content":"string","field_plan":[{"field":"string","sample":"string","implementation_note":"string"}],"execution_script":{"feature_points":["string"],"core_copy":"string","typography":"string","style_tone":"string","background_direction":"string","media_assets":["string"]},"reusable":"yes|no","seo_impact":"high|medium|low"}]}},',
            '    "execution_steps":[{"step":1,"task_key":"string","task_type":"string","status":"pending"}],',
            '    "stage2_task_hints":[{"page":"string","block":"string","task_types":["copywriting","ui_design","frontend_dev"]}]',
            '}',
            'Hard rules:',
            '- theme_alignment_summary schema compatibility phrase: "theme_alignment_summary":"how this page and every block obey theme_design color_scheme, tone_of_voice, cta_tone, trust expression, and Header/Footer handoff"',
            '- All text fields must use locale: ' . $outputLanguage,
            '- Do not return markdown.',
            '- Do not return a separate markdown field.',
            '- Output only the structured plan object shown in the schema.',
            '- The plan must contain final-ready content samples, not writing instructions.',
            '- theme_design MUST be a concrete shared theme plan. Reject and rewrite it if it reads like directions about what to design instead of decisions to implement.',
            '- Every pages.*.theme_alignment_summary MUST explicitly name the shared theme purpose, palette/color use, type/spacing/radius rule, tone/CTA rule, and at least one forbidden style that the page avoids.',
            '- Every page block must be checked against shared_prompt_context before output; any page-specific color, voice, CTA, layout, or media direction that drifts from theme_design must be rewritten.',
            '- theme_style.selection_reason and palette.selection_reason are REQUIRED customer-readable explanations of why the color system, font family, and voice/tone were selected.',
            '- selection_reason must connect the color/font/tone choices to the user one-line requirement; do not use generic claims like "modern/professional/simple" as the whole reason.',
            '- Never write process wording such as "标题围绕核心价值展开", "正文说明主要亮点", "CTA 保持单一动作", or "字体与排版指定".',
            '- Never write blueprint guidance such as "围绕...说明", "首页先讲清...", "阶段一仅给方向", "List 2-4 points", or "Specify heading font".',
            '- For each block, content must read like real website copy that can be shown to a client immediately.',
            '- Example for hero: write concrete title, subtitle, description, CTA label, trust points, and support text. Do not describe what should be written.',
            '- field_plan.sample must be direct content, for example "欢迎来到 Teenipiya 棋牌中心" or "立即开始", not "标题围绕核心价值展开".',
            '- field_plan.implementation_note must be a customer-readable implementation note such as layout handling, editable constraint, delivery requirement, or asset direction; never write abstract design rationale or prompt guidance.',
            '- For media/image fields, field_plan.sample must be a concrete asset brief the client can review, not a generic instruction like "使用一张主视觉图".',
            '- execution_script.feature_points must be concrete deliverables for this block, not meta-writing advice.',
            '- execution_script.core_copy must summarize the actual content message already written for the block.',
            '- Treat the output as a customer-visible implementation plan: every visible sentence must answer the user brief directly.',
            '- The hero title/subtitle/description MUST reuse the most concrete nouns from the one-line requirement (market, product type, offer, download/service words) instead of abstract labels like "核心价值" or "下一步动作".',
            '- If the brief mentions app/APK download, booking, consultation, pricing, trial, or signup, at least one CTA label must reflect that exact action directly.',
            '- page_types can only use selected_page_types.',
            '- If information is missing, you may make reasonable assumptions, but mark them with the prefix "[假设]".',
            '- Even in refine/rebuild/translation mode, you must still output the full plan, not fragments.',
            '- Stage 1 only outputs plan content. Do not include build/executing/log/progress language.',
            '- Every selected page must be covered and ready for stage-2 task extraction.',
            '- Each pages.<page>.theme_alignment_summary is REQUIRED and must explain how that page and its blocks obey theme_design color_scheme, tone_of_voice, cta_tone, trust expression, and Header/Footer handoff.',
            '- Header and footer must be described as concrete shared-site content and navigation.',
            '- The structured plan must be readable by product and implementation teams immediately.',
            '- Minimum concreteness: navigation_plan.header_items MUST be non-empty; each item MUST include concrete label + href (path or /route) tied to selected_page_types; forbid generic placeholders like "Link1" or "Nav item".',
            '- footer_plan.featured and footer_plan.policies MUST list real link titles and destinations users can click, not phrases like "补充政策链接".',
            '- Each non-trivial page block: field_plan SHOULD have at least 3 entries; every sample is final copy or starts with "[假设]" and still contains concrete wording.',
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
        ];

        return (string)(\json_encode($profile, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * 鏍规嵁閫夋嫨鐨勯〉闈㈢被鍨嬪姩鎬佺敓鎴?Markdown 妯℃澘
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
            $normalized = 'unknown error.';
        }

        $normalized = (string)(\preg_replace('/^(?:AI plan generation failed:\s*)+/i', '', $normalized) ?? $normalized);

        return 'AI plan generation failed: ' . $normalized;
    }

    private function mapAiPlanToArtifacts(
        array $scope,
        array $websiteProfile,
        array $decoded,
        array $pageTypes,
        string $planLocale,
        string $instruction,
        string $targetScope
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

        $planJson['page_types'] = $pageTypes;
        $planJson['i18n'] = $this->ensurePlanI18nSection(
            \is_array($planJson['i18n'] ?? null) ? $planJson['i18n'] : [],
            $planLocale,
            $this->isEnglishLocale($planLocale)
        );

        $planBlocks = $this->normalizePlanBlocks(\is_array($planJson['plan_blocks'] ?? null) ? $planJson['plan_blocks'] : []);
        if ($planBlocks === []) {
            $planBlocks = $this->buildPlanBlocksFromPlanJson($planJson, $planLocale);
        }
        $planJson['plan_blocks'] = $planBlocks;

        $briefDescription = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? $websiteProfile['brief_description'] ?? ''));
        $this->assertAiStageOnePlanJsonIsStrict($planJson, $pageTypes, $briefDescription, $planLocale);

        $executionBlueprint = \is_array($baseline['execution_blueprint'] ?? null) ? $baseline['execution_blueprint'] : [];
        $structured = \array_replace(
            \is_array($baseline['structured'] ?? null) ? $baseline['structured'] : [],
            [
                'site_strategy' => \is_array($planJson['site_strategy'] ?? null) ? $planJson['site_strategy'] : [],
                'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
                'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
                'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
                'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
                'seo_strategy' => \is_array($planJson['seo_strategy'] ?? null) ? $planJson['seo_strategy'] : [],
                'page_types' => $pageTypes,
                'pages' => \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
                'plan_blocks' => $planBlocks,
            ]
        );

        $themeDesign = \is_array($planJson['theme_design'] ?? null)
            ? $this->extractStageOneThemeDesign($planJson['theme_design'])
            : $this->extractStageOneThemeDesign(\is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : []);
        $themeContextSnapshot = $this->mergeStageOneThemeDesignIntoSnapshot(
            \is_array($executionBlueprint['theme_context_snapshot'] ?? null) ? $executionBlueprint['theme_context_snapshot'] : [],
            $themeDesign
        );
        $themeDesignQueueJob = $this->buildStageOneThemeDesignQueueJob($scope, $websiteProfile, $themeContextSnapshot, $planLocale);
        $stageOneQueueJobs = $this->upsertStageOneQueueJob(
            \is_array($executionBlueprint['queue_jobs'] ?? null)
                ? $executionBlueprint['queue_jobs']
                : (\is_array($structured['queue_jobs'] ?? null) ? $structured['queue_jobs'] : []),
            $themeDesignQueueJob
        );
        $sharedComponents = $this->normalizeStageOneSharedComponents(
            \is_array($executionBlueprint['shared_components'] ?? null) ? $executionBlueprint['shared_components'] : []
        );
        $sharedPromptContext = $this->buildStageOneSharedPromptContext($themeContextSnapshot, $sharedComponents, $pageTypes, $planLocale);
        $themeDesignQueueJob = $this->buildStageOneThemeDesignQueueJob($scope, $websiteProfile, $themeContextSnapshot, $planLocale);
        $stageOneQueueJobs = $this->upsertStageOneQueueJob(
            \is_array($executionBlueprint['queue_jobs'] ?? null) ? $executionBlueprint['queue_jobs'] : [],
            $themeDesignQueueJob
        );
        $structured['theme_context_snapshot'] = $themeContextSnapshot;
        $structured['shared_components'] = $sharedComponents;
        $structured['shared_plan'] = \array_replace(
            \is_array($structured['shared_plan'] ?? null) ? $structured['shared_plan'] : [],
            [
                'theme_design' => $themeContextSnapshot,
                'theme_design_job' => $themeDesignQueueJob,
                'shared_prompt_context' => $sharedPromptContext,
            ]
        );
        $structured['queue_jobs'] = $stageOneQueueJobs;
        $planJson['theme_design'] = $themeDesign;
        $executionBlueprint['theme_context_snapshot'] = $themeContextSnapshot;
        $executionBlueprint['shared_prompt_context'] = $sharedPromptContext;
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
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan) || !\is_array($planJson['pages'][$pageType] ?? null)) {
                continue;
            }
            if (\trim((string)($planJson['pages'][$pageType]['theme_alignment_summary'] ?? '')) === '') {
                $planJson['pages'][$pageType]['theme_alignment_summary'] = (string)($pagePlan['theme_alignment_summary'] ?? '');
            }
        }
        $blockIndex = $this->buildStageOneBlockIndex($sharedComponents, $pagePlans);

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

        $markdown = $this->buildMarkdownPlan($planJson, $planLocale);
        $planWorkbench = $this->buildPlanWorkbenchArtifacts($scope, $structured, $executionBlueprint, $planJson, $markdown, $planLocale);

        return \array_replace($baseline, [
            'plan_json' => $planJson,
            'structured' => $structured,
            'execution_blueprint' => $executionBlueprint,
            'markdown' => $markdown,
            'plan_workbench' => $planWorkbench,
        ]);
    }

    /**
     * @param array<string, mixed> $planJson
     * @param list<string> $pageTypes
     */
    private function assertAiStageOnePlanJsonIsStrict(array $planJson, array $pageTypes, string $briefDescription, string $planLocale): void
    {
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
            if ($pageGoal === '' || $this->isPromptLikeStageOneText($pageGoal, 'page_goal', '', '', $pageType)) {
                throw new \RuntimeException('AI stage-1 plan invalid: page_goal for "' . $pageType . '" is empty or still instruction-like.');
            }

            $themeAlignmentSummary = \trim((string)($page['theme_alignment_summary'] ?? ''));
            if ($themeAlignmentSummary === '' || $this->isPromptLikeStageOneText($themeAlignmentSummary, 'theme_alignment_summary', '', '', $pageType)) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_alignment_summary for "' . $pageType . '" is empty or still instruction-like.');
            }

            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($blocks === []) {
                throw new \RuntimeException('AI stage-1 plan invalid: page "' . $pageType . '" has no blocks.');
            }

            foreach ($blocks as $index => $block) {
                if (!\is_array($block)) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block #' . $index . ' on "' . $pageType . '" is malformed.');
                }
                $blockKey = \trim((string)($block['block_key'] ?? ''));
                if ($blockKey === '') {
                    throw new \RuntimeException('AI stage-1 plan invalid: block #' . $index . ' on "' . $pageType . '" is missing block_key.');
                }

                $content = \trim((string)($block['content'] ?? ''));
                if ($content === '' || $this->isPromptLikeStageOneText($content, 'content', (string)($block['component_kind'] ?? $block['template'] ?? ''), (string)($block['section_code'] ?? $blockKey), $pageType)) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" on "' . $pageType . '" still contains instruction-like content.');
                }

                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                if ($fieldPlan === []) {
                    throw new \RuntimeException('AI stage-1 plan invalid: block "' . $blockKey . '" on "' . $pageType . '" is missing field_plan.');
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

            if ($pageType === Page::TYPE_HOME && $briefSignals !== []) {
                $heroBlock = $this->findStageOneHeroBlock($blocks);
                if ($heroBlock !== null) {
                    $heroText = (string)\json_encode($heroBlock, \JSON_UNESCAPED_UNICODE);
                    $containsBriefSignal = false;
                    foreach ($briefSignals as $signal) {
                        if ($signal !== '' && \mb_stripos($heroText, $signal) !== false) {
                            $containsBriefSignal = true;
                            break;
                        }
                    }
                    if (!$containsBriefSignal) {
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
            $blockKey = \mb_strtolower(\trim((string)($block['block_key'] ?? '')));
            $sectionCode = \mb_strtolower(\trim((string)($block['section_code'] ?? '')));
            if (\str_contains($blockKey, 'hero') || \str_contains($sectionCode, 'hero')) {
                return $block;
            }
        }

        return isset($blocks[0]) && \is_array($blocks[0]) ? $blocks[0] : null;
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

        $requirementSignals = $this->extractStageOneSelectionReasonRequirementTokens($briefDescription);
        if ($requirementSignals === []) {
            if ($this->isGenericThemeSelectionReason($selectionReason)) {
                throw new \RuntimeException('AI stage-1 plan invalid: theme_design.selection_reason must reference the user one-line requirement.');
            }
            return;
        }

        foreach ($requirementSignals as $signal) {
            if ($signal !== '' && \mb_stripos($selectionReason, $signal) !== false) {
                return;
            }
        }

        throw new \RuntimeException('AI stage-1 plan invalid: theme_design.selection_reason must reference the user one-line requirement.');
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
        if (\preg_match_all('/[a-z0-9][a-z0-9+#.\'-]{2,}/iu', $brief, $matches) === 1) {
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

        if (\preg_match_all('/[\p{Han}]{2,}/u', $brief, $matches) === 1) {
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
            // 闈炶嫳鏂囨柟妗堜笅锛屽己鍒朵娇鐢ㄦ湰鍦伴〉闈㈠潡鏂囨锛岀‘淇濋伒瀹堣鍒掕瑷€涓庣粨鏋勭害鏉熴€?
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

        foreach ([
            '围绕', '说明核心价值', '首页先讲清', '阶段一仅给方向', '蓝图方向', '标题围绕', '指定标题字体',
            '列出 2-4', '字体与排版', '风格语气', '背景方向', '素材建议', 'cta 保持单一动作',
            '待补充', '待撰写', '详见后文', '完善导航', '优化体验', '补充政策链接', '突出品牌价值',
            '先用一句话讲清', '再把用户带到', '让访客在第一屏', '承接核心关键词', '不能遮挡标题和主 cta',
            'block direction', 'section title', 'supporting subtitle text', 'direction only', 'blueprint direction',
            'list 2-4', 'specify heading font', 'describe the overall visual tone', 'use concise readable paragraphs',
            'first-screen promise', 'lead visitors to the next step',
            'write the title around', 'explain the core value', 'do not describe what should be written',
        ] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
                return true;
            }
        }

        return $this->looksLikeBlueprintInstruction($text, $field, $template, $sectionName, $pageLabel);
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
            if ($pageGoal !== '' && $this->isPromptLikeStageOneText($pageGoal)) {
                $page['page_goal'] = (string)($fallbackPage['page_goal'] ?? $pageGoal);
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
        foreach (['implementation_note', 'delivery_note', 'reason'] as $key) {
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
        $row['reason'] = $implementationNote;
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
                'title' => '闃舵涓€鎵ц钃濆浘锛堝畬鏁磋鍒掞級',
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

        return [
            'name' => 'Ocean Slate',
            'primary' => '#0f172a',
            'accent' => '#2563eb',
            'secondary' => '#14b8a6',
            'surface' => '#f8fafc',
            'text' => '#0f172a',
            'reason' => '默认采用稳健的蓝灰体系，兼顾信息结构、内容承载与多行业适配性。',
        ];

        $brief = \mb_strtolower(\trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? '')));
        $instructionLower = \mb_strtolower($instruction);

        if ($this->containsAny($instructionLower, ['midnight ember', '深色', '暗色', '霓虹', '高对比'])) {
            return [
                'name' => 'Midnight Ember',
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'secondary' => '#dc2626',
                'surface' => '#1f2937',
                'text' => '#f9fafb',
                'reason' => '当前指令明确偏向深色高对比方案，适合突出 CTA、核心转化入口和棋牌下载场景。',
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
                'reason' => '棋牌与下载转化场景更适合高对比深色底，方便突出按钮、福利信息和信任提示。',
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
                'reason' => '偏专业与可信的信息型网站更适合蓝青配色，便于表达稳定、清晰和数据感。',
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
                'reason' => '健康与活力类内容适合绿色体系，便于表达成长、行动和持续使用。',
            ];
        }

        return [
            'name' => 'Ocean Slate',
            'primary' => '#0f172a',
            'accent' => '#2563eb',
            'secondary' => '#14b8a6',
            'surface' => '#f8fafc',
            'text' => '#0f172a',
            'reason' => '默认采用稳健的蓝灰体系，兼顾信息结构、SEO 内容承载与多行业适配性。',
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
        $reason = '该风格优先保证信息结构可读、内容可落地，再通过关键区块强化转化动作。';
        if ($instruction !== '') {
            $reason = '本轮按用户补充说明调整视觉策略：' . $this->clipText($instruction, 80);
        }

        return [
            'name' => 'Plan-Driven Hybrid',
            'visual_tone' => $brief !== '' ? '可信、清晰、可转化' : '专业、稳定、可执行',
            'font_family' => 'Poppins, Inter, sans-serif',
            'layout_direction' => '以首屏价值、信任点和单一主 CTA 形成自上而下的转化路径。',
            'component_density' => '中等密度，优先保证首屏理解和后续区块浏览效率。',
            'reason' => $reason,
            'palette_name' => (string)($palette['name'] ?? 'Ocean Slate'),
        ];

        $brief = \trim((string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $reason = '该风格优先保证信息结构可读、内容可落地，再通过关键区块强化转化动作。';
        if ($instruction !== '') {
            $reason = '本轮按用户补充说明调整视觉策略：' . $this->clipText($instruction, 80);
        }

        return [
            'name' => 'Plan-Driven Hybrid',
            'visual_tone' => $brief !== '' ? '可信、清晰、可转化' : '专业、稳定、可执行',
            'font_family' => 'Poppins, Inter, sans-serif',
            'layout_direction' => '以首屏价值、信任点和单一主 CTA 形成自上而下的转化路径。',
            'component_density' => '中等密度，优先保证首屏理解和后续区块浏览效率。',
            'reason' => $reason,
            'palette_name' => (string)($palette['name'] ?? 'Ocean Slate'),
        ];
    }

    private function buildNavigationPlan(array $pageTypes): array
    {
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
    private function buildFooterPlan(array $pageTypes): array
    {
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
            'page_type_count' => \count($pageTypes),
            'meta_rule' => '???????????????????????????description ????????????????',
            'content_strategy' => $instruction !== '' ? '?? SEO ????????????' . $this->clipText($instruction, 80) : '????????????????????????????',
            'internal_linking' => '????????????????????FAQ?????????????',
            'url_structure' => '???????????????? URL slug?',
        ];
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
        $pageLabel = (string)($pageBlueprint['page_label'] ?? (Page::getPageTypes()[$pageType] ?? $pageType));
        $pageTitle = (string)($pageBlueprint['page_title'] ?? $pageLabel);
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
                'label' => (string)(Page::getPageTypes()[$candidateType] ?? $candidateType),
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
            'meta_title' => (string)($pageBlueprint['meta_title'] ?? $pageTitle),
            'meta_description' => (string)($pageBlueprint['meta_description'] ?? ''),
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
            '%s 遵守 shared_prompt_context（%s 与 %s）：%s 围绕“%s”展开，延续共享 CTA 与信任表达，并从 Header 导航自然承接到 Footer 的补充背书。',
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
     * 鐢?AI 鍒ゅ畾寰皟鎸囦护鐨勭湡瀹炴剰鍥撅紝閬垮厤鎶娾€滄柊澧炴ā鍧椻€濋€昏緫纭紪鐮佸湪鍏抽敭璇嶈〃閲屻€?
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
            '  "reason": "string"',
            '}',
            'Rules:',
            '- If user asks to add/insert/join a section/module/block, action must be append_block.',
            '- Infer append_type by semantics, not fixed keywords only.',
            '- If target page is not explicit, use auto.',
            '- confidence in [0,1].',
            'Instruction: ' . $instruction,
            'Target scope: ' . (\trim($targetScope) !== '' ? $targetScope : '-'),
        ]);

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
            'why' => $this->isEnglishLocale($locale)
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
                'reason' => $this->isEnglishLocale($locale)
                    ? 'Use the current palette to keep the appended block visually consistent.'
                    : '???????????????????????',
            ],
            'seo_brief' => [
                'intent' => $pageGoal,
                'keywords' => [],
                'anchors' => ['#' . $blockKey],
                'internal_links' => [$pageType === Page::TYPE_HOME ? '/about' : '/'],
            ],
            'content_brief' => [
                'goal' => $pageGoal,
                'why' => $this->isEnglishLocale($locale)
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
                    'reason' => $this->isEnglishLocale($locale)
                        ? 'Use the title as the visible section label so the client can confirm the appended block purpose immediately.'
                        : '标题直接作为新增区块的可见识别名，方便客户确认新增内容的用途。',
                ],
                [
                    'field' => 'description',
                    'sample' => $instructionText !== '' ? $instructionText : ($this->isEnglishLocale($locale) ? 'Add supporting details for this section.' : '???????????????'),
                    'implementation_note' => $this->isEnglishLocale($locale)
                        ? 'Fill this area with the actual supporting details that will appear in the block, not with writing guidance.'
                        : '这里直接写会上屏的补充内容，不写写作提示或方向说明。',
                    'reason' => $this->isEnglishLocale($locale)
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
        if (!$this->containsAny($instructionLower, ['鍏充簬鎴戜滑', 'about us', 'about', 'company intro', '鍝佺墝浠嬬粛'])) {
            return false;
        }
        if (!$this->containsAny($instructionLower, ['娣诲姞', '鏂板', '鍔犲叆', 'append', 'add', 'insert'])) {
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
        return $pageType === Page::TYPE_HOME && (\str_contains($scope, 'home') || \str_contains($scope, '棣栭〉') || \str_contains($scope, 'page'));
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
        array $seoStrategy
    ): array {
        $isHeader = $component === 'header';
        $brandName = $siteDisplayName !== '' ? $siteDisplayName : '品牌名称';

        return [
            'task_key' => 'shared:' . $component,
            'task_type' => 'shared_component',
            'component' => $component,
            'sort_order' => $isHeader ? 10 : 20,
            'goal' => $isHeader ? '输出可直接上屏的站点头部内容，并承接主导航与主 CTA。' : '输出可直接上屏的页脚内容，补齐联系入口、政策链接与次级导航。',
            'implementation_detail' => $isHeader
                ? '桌面端使用横向导航与单一主按钮，移动端折叠菜单但保留品牌名和主 CTA。'
                : '页脚按信息分组呈现，优先展示联系入口、常用页面和政策链接。',
            'realtime_content' => $isHeader
                ? [
                    'headline' => $brandName,
                    'supporting_copy' => ['首页', '关于我们', '立即咨询'],
                    'cta' => [['label' => '立即咨询', 'target' => '#contact']],
                    'media' => [['kind' => 'logo', 'rule' => '使用可替换的品牌 Logo 位，保持清晰识别度。']],
                    'data_slots' => ['brand_name', 'navigation_items', 'primary_cta'],
                    'editable_slots' => ['brand_name', 'logo', 'navigation_items', 'primary_cta'],
                ]
                : [
                    'headline' => '继续了解 ' . $brandName,
                    'supporting_copy' => ['快速入口', '政策说明', '联系渠道', '客服支持'],
                    'cta' => [],
                    'media' => [['kind' => 'icon', 'rule' => '信息分组可搭配轻量图标，但不要压过文字内容。']],
                    'data_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                    'editable_slots' => ['footer_links', 'policy_links', 'contact_fields'],
                ],
            'editable_fields' => $isHeader
                ? ['brand_name', 'logo', 'navigation_items', 'primary_cta', 'mobile_menu_rule']
                : ['footer_links', 'policy_links', 'contact_fields', 'social_links'],
            'content_source' => ['theme_context_snapshot', 'shared_prompt_context', 'editable_field'],
            'style_direction' => (string)($themeStyle['visual_tone'] ?? ''),
            'responsive_rule' => $isHeader
                ? '移动端优先保留品牌名与主 CTA，导航折叠为菜单。'
                : '移动端按分组单列堆叠，确保政策与联系入口仍然清晰可点。',
            'completion_rule' => $isHeader
                ? 'Header 完整条件是品牌识别、导航、主 CTA 与移动端规则都已明确。'
                : 'Footer 完整条件是信息分组、政策链接、联系字段与响应式规则都已明确。',
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
                    'cta' => [['label' => '????', 'target' => '#contact']],
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
            throw new \RuntimeException('missing shared_context_hash');
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
        return [
            'site_title' => (string)($scope['site_title'] ?? $websiteProfile['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? $websiteProfile['site_tagline'] ?? ''),
            'page_types' => \array_values(\array_map('strval', \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : [])),
            'theme_style' => $structured['theme_style'] ?? [],
            'palette' => $structured['palette'] ?? [],
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
        $snapshot = [
            'theme_context_id' => 'stage1-theme-' . \substr(\sha1($siteDisplayName . '|' . \implode(',', $pageTypes)), 0, 12),
            'site_positioning' => $siteSummary,
            'site_display_name' => $siteDisplayName,
            'visual_direction' => $themeStyle,
            'content_tone' => (string)($themeStyle['visual_tone'] ?? ''),
            'palette' => $palette,
            'shared_navigation_strategy' => $navigationPlan,
            'shared_footer_strategy' => $footerPlan,
            'shared_cta_strategy' => [
                'primary_action' => $this->isEnglishLocale((string)($scope['plan_locale'] ?? '')) ? 'Contact / Start now' : '立即咨询 / 立即开始',
                'reason' => '共享 CTA 必须在 Header、Footer 与页面核心区块中保持同一转化方向。',
            ],
            'seo_strategy' => $seoStrategy,
            'page_types' => $pageTypes,
            'anti_hardcode_rules' => [
                'brand_name' => '用户未提供品牌名时使用可编辑占位字段，不伪造品牌事实。',
                'contact' => '电话、微信、邮箱、地址未知时必须保留待确认字段。',
                'cases' => '真实案例、资质、价格和客户名未知时不得编造。',
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

        return [
            'theme_purpose' => $themePurpose,
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
        $typography = \is_array($themeDesign['typography_spacing_radius'] ?? null) ? $themeDesign['typography_spacing_radius'] : [];
        $visualKeywords = \is_array($themeDesign['visual_keywords'] ?? null) ? $themeDesign['visual_keywords'] : [];
        $forbiddenStyles = \is_array($themeDesign['forbidden_styles'] ?? null) ? $themeDesign['forbidden_styles'] : [];

        return [
            'theme_purpose' => (string)($themeDesign['theme_purpose'] ?? $themeDesign['site_positioning'] ?? ''),
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
    private function buildStageOneSharedPromptContext(array $themeContextSnapshot, array $sharedComponents, array $pageTypes, string $planLocale): array
    {
        $context = [
            'prompt_context_id' => 'stage1-shared-' . \substr((string)($themeContextSnapshot['context_hash'] ?? \sha1('shared')), 0, 12),
            'theme_context_hash' => (string)($themeContextSnapshot['context_hash'] ?? ''),
            'plan_locale' => $planLocale,
            'page_types' => $pageTypes,
            'theme_design' => [
                ...$this->extractStageOneThemeDesign($themeContextSnapshot),
            ],
            'header_plan' => \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [],
            'footer_plan' => \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [],
            'generation_rule' => '页面类型方案必须携带该共享上下文，并保持 Header、Footer 与主题连续性。',
        ];
        $context['context_hash'] = \sha1((string)\json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        return $context;
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
        $sequence = \is_array($stageOneQueue['sequence'] ?? null)
            ? \array_values(\array_map('strval', $stageOneQueue['sequence']))
            : [];

        foreach ($this->buildStageOnePageFanoutQueueJobs([], [], $pagePlans, $sharedPromptContext, $planLocale) as $pageFanoutJob) {
            $pageJobKey = \trim((string)($pageFanoutJob['job_key'] ?? ''));
            if ($pageJobKey === '') {
                continue;
            }
            $jobs[$pageJobKey] = $pageFanoutJob;
            if (!\in_array($pageJobKey, $sequence, true)) {
                $sequence[] = $pageJobKey;
            }
        }

        return \array_replace($stageOneQueue, [
            'version' => (int)($stageOneQueue['version'] ?? 1),
            'stage' => (string)($stageOneQueue['stage'] ?? 'stage1'),
            'status' => (string)($stageOneQueue['status'] ?? 'done'),
            'sequence' => $sequence,
            'jobs' => $jobs,
        ]);
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
        $existingJobs = \is_array($stageOneQueue['jobs'] ?? null) ? $stageOneQueue['jobs'] : [];
        $sessionPublicId = '';
        $websitePublicId = '';
        foreach ($existingJobs as $existingJob) {
            if (!\is_array($existingJob)) {
                continue;
            }

            $pageKey = (string)$pageType;
            $jobKey = 'stage1.page_plan:' . $pageKey;
            $pageJobKeys[] = $jobKey;
            $jobs[$jobKey] = [
                'job_key' => $jobKey,
                'job_type' => 'stage1.page_plan.generate',
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

        $fanoutJobs = $this->buildStageOnePageFanoutQueueJobs(
            ['session_public_id' => $sessionPublicId],
            ['website_public_id' => $websitePublicId],
            $pagePlans,
            $sharedPromptContext,
            $planLocale
        );

        $jobs = [];
        $sequence = [];
        foreach ($existingJobs as $jobKey => $existingJob) {
            if (!\is_array($existingJob) || $this->isStageOnePageFanoutJob($existingJob)) {
                continue;
            }
            $resolvedJobKey = \trim((string)($existingJob['job_key'] ?? $jobKey));
            if ($resolvedJobKey === '') {
                continue;
            }
            $jobs[$resolvedJobKey] = $existingJob;
            $sequence[] = $resolvedJobKey;
        }

        $pageJobKeys = [];
        foreach ($fanoutJobs as $fanoutJob) {
            $jobKey = \trim((string)($fanoutJob['job_key'] ?? ''));
            if ($jobKey === '') {
                continue;
            }
            $jobs[$jobKey] = $fanoutJob;
            $sequence[] = $jobKey;
            $pageJobKeys[] = $jobKey;
        }

        $stageOneQueue['version'] = (int)($stageOneQueue['version'] ?? 1);
        $stageOneQueue['stage'] = 'stage1';
        $stageOneQueue['status'] = (string)($stageOneQueue['status'] ?? 'done');
        $stageOneQueue['sequence'] = \array_values(\array_unique($sequence));
        $stageOneQueue['jobs'] = $jobs;
        $stageOneQueue['fanout'] = [
            'mode' => 'fiber_coroutine',
            'task_granularity' => 'one_page_one_task',
            'trigger_after' => 'stage1.shared.header_footer',
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
            $assembledPagePlan = \array_replace($pagePlan, [
                'page_key' => (string)$pageType,
                'page_status' => 'done',
                'shared_context_hash' => (string)($sharedPromptContext['context_hash'] ?? ''),
                'theme_context_hash' => (string)($sharedPromptContext['theme_context_hash'] ?? ''),
                'assembly_version' => 1,
                'generation_method' => 'stage1.page_plan.generate',
            ]);
            if (
                \trim((string)($assembledPagePlan['theme_alignment_summary'] ?? '')) === ''
                || !\str_contains((string)($assembledPagePlan['theme_alignment_summary'] ?? ''), 'shared_prompt_context')
            ) {
                $assembledPagePlan['theme_alignment_summary'] = $this->buildPageThemeAlignmentSummaryFromSharedContext(
                    (string)($assembledPagePlan['page_label'] ?? $pageType),
                    (string)($assembledPagePlan['page_goal'] ?? ''),
                    \is_array($assembledPagePlan['blocks'] ?? null) ? $assembledPagePlan['blocks'] : [],
                    $sharedPromptContext
                );
            }
            $assembledPagePlan['page_context_hash'] = $this->buildStageOnePageContextHash((string)$pageType, $assembledPagePlan);
            $pagePlans[(string)$pageType] = $assembledPagePlan;
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
        return $this->buildStageOnePagePlans($pages, $sharedPromptContext);
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
                $rawBlockKey = \trim((string)($block['block_key'] ?? $block['section_code'] ?? 'block'));
                if ($rawBlockKey === '') {
                    continue;
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
                    'reason' => (string)($block['why'] ?? ''),
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
                '联系方式、真实案例、资质、价格、地址等未提供事实时，必须保留为可编辑字段。',
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
                '联系方式、真实案例、资质、价格、地址等未提供事实必须保留为可编辑字段。',
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

        return [
            'version' => 2,
            'plan_locale' => $planLocale,
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
            ],
        ];
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
                'page_status' => (string)($pagePlan['page_status'] ?? 'done'),
                'theme_alignment_summary' => \trim((string)($pagePlan['theme_alignment_summary'] ?? '')),
                'shared_context_hash' => (string)($pagePlan['shared_context_hash'] ?? $sharedPromptContext['context_hash'] ?? ''),
                'theme_context_hash' => (string)($pagePlan['theme_context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
                'page_context_hash' => (string)($pagePlan['page_context_hash'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        $planBook = [
            'version' => 1,
            'source' => 'stage1.block_tree',
            'source_signature' => (string)($executionBlueprint['signature'] ?? ''),
            'plan_locale' => $planLocale,
            'theme_context_hash' => (string)($themeContextSnapshot['context_hash'] ?? $sharedPromptContext['theme_context_hash'] ?? ''),
            'shared_context_hash' => (string)($sharedPromptContext['context_hash'] ?? ''),
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
            'reason' => \trim((string)($componentPlan['reason'] ?? $componentPlan['why'] ?? '')),
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
            'reason' => \trim((string)($block['reason'] ?? $block['why'] ?? '')),
            'completion_rule' => \trim((string)($block['completion_rule'] ?? '')),
            'editable_fields' => $this->normalizeStageOnePlanBookEditableFields($block),
            'content_source' => \is_array($block['content_source'] ?? null) ? \array_values($block['content_source']) : [],
            'style_direction' => \trim((string)($block['style_direction'] ?? '')),
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
                    'why' => \trim((string)($block['why'] ?? '')),
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
                'why' => \trim((string)($pagePlan['why'] ?? '')),
                'primary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['primary_keywords'] ?? null) ? $pagePlan['primary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'secondary_keywords' => \array_values(\array_filter(\array_map(
                    static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                    \is_array($pagePlan['secondary_keywords'] ?? null) ? $pagePlan['secondary_keywords'] : []
                ), static fn(string $value): bool => $value !== '')),
                'blocks' => $blockRows,
            ];
        }

        $sharedComponents = $this->resolveStageOneSharedComponents($structured, []);
        $sharedBlocks = $this->buildStageOneSharedBlocksPlanJson($sharedComponents);

        return [
            'i18n' => \is_array($structured['i18n'] ?? null) ? $structured['i18n'] : [],
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

        return \trim((string)($block['why'] ?? ''));
    }

    /**
     * @param array<string, mixed> $planJson
     */
    private function buildMarkdownPlan(array $planJson, string $locale = ''): string
    {
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
                'content' => $isEn ? 'Extractable block catalog for stage-2 task planning.' : '供第二阶段任务拆解使用的页面区块目录。',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '方案结尾',
                'content' => $isEn ? 'This blueprint is ready for stage-2 task extraction and execution.' : '当前方案已经可以进入第二阶段任务拆解与执行。',
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
                'content' => $isEn ? 'Extractable block catalog for stage-2 task planning.' : '????????????????',
                'items' => $contentItems,
            ],
            [
                'block_id' => 'plan_footer_001',
                'region' => 'footer',
                'type' => 'summary',
                'title' => $isEn ? 'Blueprint Tail' : '????',
                'content' => $isEn ? 'This blueprint is ready for stage-2 task extraction and execution.' : '?????????????????????',
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
        $lines[] = '# ' . ($isEn ? 'Stage 1 Content Plan' : '阶段一内容方案');
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
                default => $pageLabel . ' 页面需要围绕页面意图输出清晰信息并承接下一步动作。',
            };
        }

        if ($this->isEnglishLocale($locale)) {
            return match ($pageType) {
                Page::TYPE_HOME => 'Capture core intent, explain value, and surface primary conversion actions.',
                Page::TYPE_ABOUT => 'Build trust by explaining brand background and delivery capability.',
                Page::TYPE_CONTACT => 'Reduce friction and collect qualified leads quickly.',
                default => $pageLabel . ' should clearly serve page intent and lead users to next actions.',
            };
        }
        return match ($pageType) {
            Page::TYPE_HOME => '承接主关键词、首屏价值和关键转化入口。',
            Page::TYPE_ABOUT => '解释品牌背景与能力边界，建立信任。',
            Page::TYPE_CONTACT => '降低咨询门槛，收集有效线索。',
            default => $pageLabel . ' 页面围绕页面意图输出清晰信息并承接下一步动作。',
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

        $keywords = [$pageLabel . ' 鎸囧崡', $pageLabel . ' 甯歌闂'];
        if ($pageType === Page::TYPE_HOME) {
            $keywords[] = '鍝佺墝浠嬬粛';
            $keywords[] = '鏍稿績浼樺娍';
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
        if ($target === '') {
            $target = $preset === 'contact_form' ? '#contact' : '#start';
        }

        return $this->isEnglishLocale($locale)
            ? ('Primary CTA: ' . $label . ' (target ' . $target . ')')
            : ('主 CTA：' . $label . '（跳转 ' . $target . '）');
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
                'reason' => $implementationNote,
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
                'target' => $ctaTarget !== '' ? $ctaTarget : '#contact',
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
            'button_link' => $preset === 'contact_form' ? '#contact' : '#start',
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
            'keep cta', 'home hero', 'page hero',
        ] as $marker) {
            if ($marker !== '' && \mb_stripos($normalized, $marker) !== false) {
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
        $implementationDetail = \trim((string)($componentPlan['implementation_detail'] ?? $componentPlan['implementation_note'] ?? ''));
        if ($implementationDetail === '') {
            $implementationDetail = $goal !== '' ? $goal : 'Build the shared ' . $component . ' block with editable content and responsive behavior.';
        }
        $reason = \trim((string)($componentPlan['reason'] ?? $componentPlan['why'] ?? ''));
        if ($reason === '') {
            $reason = $component === 'header'
                ? 'The header anchors brand recognition, navigation, and the primary conversion action.'
                : 'The footer closes the page with navigation, contact, policy, and support paths.';
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
            'reason' => $reason,
            'completion_rule' => $completionRule,
            'dependencies' => $dependencies,
            'prompt_context_hash' => (string)($componentPlan['prompt_context_hash'] ?? ''),
            'version' => (int)($componentPlan['version'] ?? 1),
        ]);
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
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null)
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);
        $pages = \is_array($executionBlueprint['pages'] ?? null)
            ? $executionBlueprint['pages']
            : (\is_array($structured['pages'] ?? null) ? $structured['pages'] : []);
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
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null)
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);
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

    private function isEnglishLocale(string $locale): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '') {
            return false;
        }

        return \str_starts_with($locale, 'en');
    }

    /**
     * T5: 鏂规 Markdown 绔犺妭瀹屾暣鎬ф牎楠屽櫒
     * 楠岃瘉鐢熸垚鐨勬柟妗?Markdown 鏄惁鍖呭惈鎵€鏈夊繀椤荤珷鑺?
     *
     * @param string $markdown 鏂规 Markdown 鏂囨湰
     * @param string $locale 鏂规璇█
     * @return array{valid: bool, missing_sections: list<string>, warnings: list<string>}
     */
    public function validateMarkdownSections(string $markdown, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdown = \trim($markdown);
        $missingSections = [];
        $warnings = [];

        // 蹇呴』鍖呭惈鐨勭珷鑺傦紙鍙岃瀵圭収锛?
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

        // 妫€鏌ユ瘡涓繀椤荤珷鑺?
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

        // 妫€鏌ユ槸鍚﹀寘鍚〉闈㈣鐩栨竻鍗?
        if (\mb_stripos($markdown, '椤甸潰瑕嗙洊娓呭崟') === false
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
     * 妫€鏌ユ柟妗?Markdown 鏄惁鍖呭惈鎸囧畾椤甸潰绫诲瀷鐨勮鍒?
     *
     * @param string $markdown 鏂规 Markdown
     * @param list<string> $selectedPageTypes 鐢ㄦ埛閫夋嫨鐨勯〉闈㈢被鍨?
     * @param string $locale 鏂规璇█
     * @return array{valid: bool, missing_pages: list<string>, extra_pages: list<string>}
     */
    public function validatePageCoverage(string $markdown, array $selectedPageTypes, string $locale = ''): array
    {
        $isEn = $this->isEnglishLocale($locale);
        $markdownLower = \mb_strtolower($markdown);
        $missingPages = [];
        $extraPages = [];

        // 甯歌椤甸潰绫诲瀷鍏抽敭璇嶆槧灏?
        $pageTypeKeywords = [
            'home_page' => ['棣栭〉', 'home page', 'homepage'],
            'about_page' => ['鍏充簬椤甸潰', 'about', '鍏充簬鎴戜滑'],
            'contact_page' => ['鑱旂郴椤甸潰', 'contact', '鑱旂郴鎴戜滑'],
            'product_page' => ['浜у搧椤甸潰', 'product', '浜у搧鍒楄〃'],
            'blog_page' => ['鍗氬椤甸潰', 'blog', '鍗氬'],
            'service_page' => ['鏈嶅姟椤甸潰', 'service', '鏈嶅姟'],
            'privacy_page' => ['闅愮鏀跨瓥', 'privacy'],
            'terms_page' => ['鏈嶅姟鏉℃', 'terms'],
        ];

        // 妫€鏌ユ瘡涓凡閫夐〉闈㈡槸鍚︽湁瑙勫垝
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
