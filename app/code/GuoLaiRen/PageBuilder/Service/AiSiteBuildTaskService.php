<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use Weline\Framework\Manager\ObjectManager;

class AiSiteBuildTaskService
{
    private const GENERATED_ARTIFACT_PROMPT_TRACE_MARKERS = [
        'Fill the block fields',
        'confirmed stage-1 plan',
        'confirmed stage-1 theme',
        'stage-2 task detail',
        'frontend component skill',
        'Generate the frontend block',
        'content_fill_rule',
        'field_content_requirements',
        'stage3_directive',
        'task_script',
        'block_task.content_plan',
        'block_task.style_plan',
        '&lt;2 class=',
        '<2 class=',
        '</pa>',
        '</pdiv>',
        '</divsection>',
        'Required by block task schema',
        'Built from plan',
        'generated from plan',
        'source intent',
        'customer brief',
        'planning_reason',
        'implementation_contract',
        'data_contract',
        'visitor-visible copy',
        'Return ONLY',
        'Do not use the',
        'component prompt',
        '$category',
        'slug ===',
        '提示词',
        '输出必须',
        '优先沿用',
        '字段样例',
        '直接产出可上屏',
        '生成页面方案',
        '内容填充规则',
    ];

    /**
     * 页级 rollup / checkpoint：按 page_type 统计块级任务完成情况；可与 skip_remaining_blocks 联动跳过后续 section。
     *
     * @see self::rollupBuildPageProgressForPageType()
     */
    public const BUILD_PAGE_PROGRESS_SCOPE_KEY = '_build_page_progress';

    public const TASK_STATUS_PENDING = 'pending';
    public const TASK_STATUS_RUNNING = 'running';
    public const TASK_STATUS_DONE = 'done';
    public const TASK_STATUS_FAILED = 'failed';
    public const TASK_STATUS_CANCELLED = 'cancelled';
    public const RETRYABLE_AI_FAILURES_SCOPE_KEY = 'retryable_ai_failures';
    private const BUILD_LOCKED_PLAN_SCOPE_KEYS = [
        'page_types',
        'page_types_user_customized',
        'plan_confirmed',
        'plan_confirmed_at',
        'plan_json',
        'plan_markdown',
        'plan_structured',
        'plan_workbench',
        'plan_generated_at',
        'plan_generated_locale',
        'plan_generated_page_types',
        'plan_generated_source_signature',
        'plan_ai_generated',
        'plan_last_prompt_mode',
        'plan_last_target_scope',
        'plan_last_round',
        'plan_rebuild_summary',
        'plan_change_scope_report',
        'build_plan_v2',
        'plan_projection',
        'content_manifest',
        'build_plan_confirmed',
        'build_plan_confirmed_at',
        'build_plan_v2_validation',
        'has_build_plan_v2',
    ];
    /**
     * Execution rows are stored on build_plan_v2; duplicate definition fields
     * are removed before persisting block execution state.
     *
     * @var array<string, true>
     */
    private const BUILD_TASK_STATE_DUPLICATE_KEYS = [
        'task_type' => true,
        'group_key' => true,
        'page_type' => true,
        'section_code' => true,
        'dependencies' => true,
        'can_parallel' => true,
        'progress_weight' => true,
        'runtime_context' => true,
        'plan_context' => true,
        'task_script' => true,
        'block_task' => true,
        'implementation_contract' => true,
    ];
    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function ensureTaskScope(array $scope, array $websiteProfile, string $workspaceTrack): array
    {
        unset($websiteProfile, $workspaceTrack);
        $scope = $this->normalizeConfirmedBuildPlanFlag($scope);
        $validation = $this->validateConfirmedBuildPlanV2ForBuild($scope);
        if (!($validation['valid'] ?? false)) {
            return $this->markBuildPlanExecutionBlocked($scope, $validation);
        }

        return $this->ensureBuildPlanBlockExecutionState($scope);
    }

    /**
     * Reset build_plan_v2 execution rows to pending for a forced rebuild.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetBuildTasksToPendingForRebuild(array $scope, bool $reuseAvailableArtifacts = true): array
    {
        $scope = $this->ensureBuildPlanBlockExecutionState($scope);
        $tasks = $this->extractBlueprintTasks($scope);
        if ($tasks === []) {
            return $scope;
        }
        $existingTasks = $this->extractTaskState($scope);
        $now = \date('Y-m-d H:i:s');
        foreach ($tasks as $definition) {
            $taskKey = \trim((string)($definition['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $existing = \is_array($existingTasks[$taskKey] ?? null) ? $existingTasks[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($existing['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_CANCELLED) {
                $scope = $this->setTaskState($scope, $taskKey, [
                    'status' => self::TASK_STATUS_CANCELLED,
                ], false);
                continue;
            }

            if ($reuseAvailableArtifacts && $this->isGeneratedArtifactAvailableForTask($scope, $definition)) {
                $resultRef = \is_array($existing['result_ref'] ?? null) && $existing['result_ref'] !== []
                    ? $existing['result_ref']
                    : $this->buildTaskResultRefFromDefinition($definition);
                $scope = $this->setTaskState($scope, $taskKey, [
                    'status' => self::TASK_STATUS_DONE,
                    'message' => '',
                    'result_ref' => $resultRef,
                    'updated_at' => \trim((string)($existing['updated_at'] ?? '')) !== ''
                        ? (string)$existing['updated_at']
                        : $now,
                    'finished_at' => \trim((string)($existing['finished_at'] ?? '')) !== ''
                        ? (string)$existing['finished_at']
                        : $now,
                ], false);
                continue;
            }

            $scope = $this->setTaskState($scope, $taskKey, [
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => $now,
                'started_at' => '',
                'finished_at' => '',
            ], false);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearBuildArtifactsForRegeneration(array $scope): array
    {
        foreach ([
            'virtual_pages_by_type',
            'pagebuilder_pages_by_type',
            'materialized_pages_by_type',
            'page_type_layouts',
            'pending_generation_page_types',
            self::BUILD_PAGE_PROGRESS_SCOPE_KEY,
            'build_summary',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'qa_report_contract',
            'asset_image_generation_failures',
            'publish_verification',
            'pre_publish_visual_urls',
        ] as $key) {
            $scope[$key] = [];
        }

        foreach ([
            'can_publish',
            'site_ready',
            'latest_build_failed',
            'publish_blocked_by_latest_ai_failure',
        ] as $key) {
            $scope[$key] = 0;
        }

        foreach ([
            'publish_blocked_reason',
            'preview_full_url',
            'visual_preview_url',
            'visual_edit_url',
        ] as $key) {
            $scope[$key] = '';
        }
        $scope['latest_build_failure'] = [];
        $scope = $this->resetBuildPlanExecutionRows($scope);

        $scope = $this->clearRetryableAiFailures($scope, 'build');
        $scope['_build_regeneration'] = [
            'active' => 1,
            'started_at' => \date('Y-m-d H:i:s'),
        ];

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasConfirmedBuildPlanForBuild(array $scope): bool
    {
        return $this->hasConfirmedBuildPlanV2ForBuild($scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasConfirmedBuildPlanV2ForBuild(array $scope): bool
    {
        return (bool)($this->validateConfirmedBuildPlanV2ForBuild($scope)['valid'] ?? false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{valid:bool,errors:list<string>}
     */
    private function validateConfirmedBuildPlanV2ForBuild(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return [
                'valid' => false,
                'errors' => ['BUILD_PLAN_CONTRACT_INVALID: confirmed build_plan_v2 is required before build'],
            ];
        }

        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $status = \strtolower(\trim((string)($meta['status'] ?? '')));
        if ((int)($scope['build_plan_confirmed'] ?? 0) !== 1 && $status !== 'confirmed') {
            return [
                'valid' => false,
                'errors' => ['BUILD_PLAN_CONTRACT_INVALID: build_plan_v2 must be confirmed before build'],
            ];
        }
        if (\trim((string)($meta['signature'] ?? '')) === '') {
            return [
                'valid' => false,
                'errors' => ['BUILD_PLAN_CONTRACT_INVALID: confirmed build_plan_v2 is missing contract_meta.signature'],
            ];
        }

        $validation = (new BuildPlanContractValidator())->validate($contract);
        if (!($validation['valid'] ?? false)) {
            return [
                'valid' => false,
                'errors' => \array_values(\array_map(
                    static fn(string $error): string => 'BUILD_PLAN_CONTRACT_INVALID: ' . $error,
                    \is_array($validation['errors'] ?? null) ? $validation['errors'] : []
                )),
            ];
        }
        $coverage = $this->inspectConfirmedBuildPlanPageTypeCoverage($scope);
        $missingPages = \is_array($coverage['missing_page_types'] ?? null) ? $coverage['missing_page_types'] : [];
        if ($missingPages !== []) {
            $errors = [];
            $errors[] = 'BUILD_PLAN_CONTRACT_INVALID: build_plan_v2.pages missing selected page_types: ' . \implode(', ', $missingPages);

            return ['valid' => false, 'errors' => $errors];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   expected_page_types:list<string>,
     *   actual_page_types:list<string>,
     *   missing_page_types:list<string>,
     * }
     */
    public function inspectConfirmedBuildPlanPageTypeCoverage(array $scope): array
    {
        $expected = $this->normalizeBuildPlanStringList($scope['page_types'] ?? []);
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $actual = [];
        foreach (\is_array($contract['pages'] ?? null) ? $contract['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType !== '') {
                $actual[$pageType] = true;
            }
        }

        return [
            'expected_page_types' => $expected,
            'actual_page_types' => \array_values(\array_keys($actual)),
            'missing_page_types' => $this->missingStringSet($expected, \array_values(\array_keys($actual))),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array{valid:bool,errors:list<string>} $validation
     * @return array<string, mixed>
     */
    private function markBuildPlanExecutionBlocked(array $scope, array $validation): array
    {
        $scope['build_plan_v2_validation'] = $validation;
        $scope['build_plan_confirmed'] = 0;
        $scope['has_build_plan_v2'] = \is_array($scope['build_plan_v2'] ?? null) && $scope['build_plan_v2'] !== [] ? 1 : 0;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeConfirmedBuildPlanFlag(array $scope): array
    {
        if ($this->hasConfirmedBuildPlanV2ForBuild($scope)) {
            $scope['build_plan_confirmed'] = 1;
            $meta = \is_array($scope['build_plan_v2']['contract_meta'] ?? null) ? $scope['build_plan_v2']['contract_meta'] : [];
            if (\trim((string)($scope['build_plan_confirmed_at'] ?? '')) === '') {
                $scope['build_plan_confirmed_at'] = (string)($meta['confirmed_at'] ?? \date('Y-m-d H:i:s'));
            }
            return $this->restoreScopeIdentityFromBuildPlanContract($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function shouldLockBuildPlanContract(array $scope): bool
    {
        return (int)($scope['build_plan_confirmed'] ?? 0) === 1
            || $this->hasConfirmedBuildPlanForBuild($scope);
    }

    /**
     * Build consumes the confirmed BuildPlan v2.2 contract. Request or queue
     * scope_patch must never confirm or rewrite plan/build definitions.
     *
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $currentScope
     * @return array<string, mixed>
     */
    public function stripBuildPlanMutationScopePatch(array $scopePatch, array $currentScope): array
    {
        foreach (self::BUILD_LOCKED_PLAN_SCOPE_KEYS as $key) {
            unset($scopePatch[$key]);
        }
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'user_description', 'default_locale', 'plan_locale'] as $key) {
            if (\array_key_exists($key, $scopePatch) && \is_scalar($scopePatch[$key]) && \trim((string)$scopePatch[$key]) === '') {
                unset($scopePatch[$key]);
            }
        }
        if (\is_array($scopePatch['site_profile_manual'] ?? null)) {
            foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $key) {
                if (!\array_key_exists($key, $scopePatch)) {
                    unset($scopePatch['site_profile_manual'][$key]);
                }
            }
            if ($scopePatch['site_profile_manual'] === []) {
                unset($scopePatch['site_profile_manual']);
            }
        }

        return $scopePatch;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function restoreScopeIdentityFromBuildPlanContract(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return $scope;
        }
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        $identityDefaults = [
            'site_title' => $this->firstNonEmptyBuildPlanText([
                $scope['site_title'] ?? null,
                $profile['site_title'] ?? null,
                $siteBrief['site_name'] ?? null,
                $requirements['site_name'] ?? null,
            ]),
            'site_tagline' => $this->firstNonEmptyBuildPlanText([
                $requirements['site_goal'] ?? null,
                $requirements['content_direction'] ?? null,
                $profile['site_tagline'] ?? null,
            ]),
            'brief_description' => $this->firstNonEmptyBuildPlanText([
                $requirements['expanded_brief'] ?? null,
                $requirements['planning_summary'] ?? null,
                $requirements['site_goal'] ?? null,
                $siteBrief['summary'] ?? null,
                $profile['brief_description'] ?? null,
            ]),
            'target_domain' => $this->firstNonEmptyBuildPlanText([
                $profile['target_domain'] ?? null,
                $scope['selected_domain'] ?? null,
                $this->extractLocalHostFromScopeUrls($scope),
            ]),
        ];

        foreach ($identityDefaults as $key => $value) {
            if ($value !== '' && \trim((string)($scope[$key] ?? '')) === '') {
                $scope[$key] = $value;
            }
        }
        if (\trim((string)($scope['user_description'] ?? '')) === '' && $identityDefaults['brief_description'] !== '') {
            $scope['user_description'] = $identityDefaults['brief_description'];
        }

        return $scope;
    }

    /**
     * @return list<string>
     */
    public function buildPlanDerivedScopeKeys(): array
    {
        return [
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'build_plan_confirmed',
            'build_plan_confirmed_at',
            'build_plan_v2_validation',
            'has_build_plan_v2',
            'workspace_track',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function extractBuildPlanDerivedScopePatch(array $scope): array
    {
        $patch = [];
        foreach ($this->buildPlanDerivedScopeKeys() as $key) {
            if (\array_key_exists($key, $scope)) {
                $patch[$key] = $scope[$key];
            }
        }

        return $patch;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $confirmedScope
     * @return array<string, mixed>
     */
    public function restoreBuildPlanContract(array $scope, array $confirmedScope): array
    {
        if (!$this->shouldLockBuildPlanContract($confirmedScope)) {
            return $scope;
        }

        foreach (self::BUILD_LOCKED_PLAN_SCOPE_KEYS as $key) {
            if (\array_key_exists($key, $confirmedScope)) {
                $scope[$key] = $confirmedScope[$key];
            } else {
                unset($scope[$key]);
            }
        }
        return $this->normalizeConfirmedBuildPlanFlag($scope);
    }

    /**
     * Keep only task-level plan context that prompt assembly actually reads.
     * The full BuildPlan task and its runtime_context are already represented by
     * the executable task fields, and duplicating them across every blueprint
     * task makes session artifacts large enough to destabilize queue workers.
     *
     * @param array<string, mixed> $planContext
     * @return array<string, mixed>
     */
    private function compactBuildPlanTaskPlanContext(array $planContext): array
    {
        unset($planContext['runtime_context']);

        if (\is_array($planContext['task'] ?? null)) {
            $sourceTask = $planContext['task'];
            $taskProjection = [];
            foreach ([
                'task_id',
                'id',
                'input_scope',
                'acceptance_rule_ids',
                'context_budget',
            ] as $key) {
                if (\array_key_exists($key, $sourceTask)) {
                    $taskProjection[$key] = $sourceTask[$key];
                }
            }
            if ($taskProjection === []) {
                unset($planContext['task']);
            } else {
                $planContext['task'] = $taskProjection;
            }
        }

        return $planContext;
    }

    /**
     * Stage2 prompt context is frozen while the task tree is built. Later prompt
     * assembly must read these task-level references instead of falling back to
     * broad mutable scope state.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function resolveBuildPlanStage2RuntimeContext(array $scope, array $contract): array
    {
        $contractContext = \is_array($scope['contract_context'] ?? null) ? $scope['contract_context'] : [];

        $themeContext = $this->buildThemeContextFromBuildPlanContract($scope, $contract);
        $sharedPromptContext = $this->buildSharedContextFromBuildPlanContract($scope, $contract);

        return [
            'site_context' => [
                'site_brief' => \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [],
                'source_of_truth' => \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [],
                'website_profile' => \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            ],
            'theme_context_snapshot' => $themeContext,
            'shared_prompt_context' => $sharedPromptContext,
            'policy_context' => [
                'policy_ref' => \is_array($contract['policy_ref'] ?? null) ? $contract['policy_ref'] : [],
                'policy_projection' => \is_array($contract['policy_projection'] ?? null) ? $contract['policy_projection'] : [],
                'design_manifest' => \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [],
            ],
            'skill_context' => [
                'selected_skill_codes' => $this->normalizeBuildPlanStringList(
                    $scope['selected_skill_codes']
                    ?? $contractContext['selected_skill_codes']
                    ?? []
                ),
                'skill_snapshots' => \is_array($contractContext['skill_snapshots'] ?? null) ? $contractContext['skill_snapshots'] : [],
            ],
            'reference_context' => [
                'source_contracts' => \is_array($contract['source_contracts'] ?? null) ? $contract['source_contracts'] : [],
                'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
            ],
            'asset_context' => $this->summarizeBuildPlanAssetContext($scope),
        ];
    }

    /**
     * Task-level runtime_context is duplicated for every block. Keep the stable
     * session-level asset manifest in scope and store only a small reference
     * summary inside each task; Stage 3 resolves the exact block assets from
     * scope at prompt time.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function summarizeBuildPlanAssetContext(array $scope): array
    {
        $manifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $verifiedAssets = \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [];

        return [
            'asset_context_ref' => 'scope.asset_manifest',
            'asset_manifest_hash' => \trim((string)($scope['asset_manifest_hash'] ?? '')),
            'slot_count' => \count($slots),
            'verified_asset_count' => \count($verifiedAssets),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildThemeContextFromBuildPlanContract(array $scope, array $contract): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $designManifest = \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];

        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        return [
            'site_display_name' => $this->firstNonEmptyBuildPlanText([
                $scope['site_title'] ?? null,
                $profile['site_title'] ?? null,
                $contract['site_brief']['site_name'] ?? null,
                $requirements['site_name'] ?? null,
            ]),
            'theme_design' => \is_array($designManifest['visual_contract'] ?? null)
                ? $designManifest['visual_contract']
                : (\is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : []),
            'theme_style' => \is_array($designManifest['theme_style'] ?? null)
                ? $designManifest['theme_style']
                : (\is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : []),
            'palette' => \is_array($designManifest['palette'] ?? null)
                ? $designManifest['palette']
                : (\is_array($planJson['palette'] ?? null) ? $planJson['palette'] : []),
            'design_manifest' => $designManifest,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function buildSharedContextFromBuildPlanContract(array $scope, array $contract): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $contentItems = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteDisplayName = $this->firstNonEmptyBuildPlanText([
            $scope['site_title'] ?? null,
            $profile['site_title'] ?? null,
            $siteBrief['site_name'] ?? null,
            $requirements['site_name'] ?? null,
        ]);
        $primaryCta = $this->normalizeBuildPlanPrimaryCta((string)($requirements['primary_cta'] ?? ''));
        $navigationItems = $this->buildSharedNavigationItemsFromBuildPlanContract($contract, $contentItems);
        $sitePositioning = $this->firstNonEmptyBuildPlanText([
            $requirements['expanded_brief'] ?? null,
            $requirements['site_goal'] ?? null,
            $requirements['content_direction'] ?? null,
            $siteBrief['summary'] ?? null,
        ]);
        if ($sitePositioning === '' && \is_array($planJson['site_strategy'] ?? null)) {
            $sitePositioning = $this->firstNonEmptyBuildPlanText([
                $planJson['site_strategy']['core_goal'] ?? null,
                $planJson['site_strategy']['content_strategy'] ?? null,
            ]);
        }

        return [
            'site_display_name' => $siteDisplayName,
            'site_positioning' => $sitePositioning,
            'header_items' => $navigationItems,
            'navigation_plan' => $navigationItems !== [] ? ['items' => $navigationItems] : (\is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : []),
            'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
            'footer_featured' => \array_slice($navigationItems, 0, 5),
            'footer_policies' => [],
            'shared_cta_strategy' => \array_filter([
                'primary_action' => $primaryCta,
                'primary_target' => $this->resolveBuildPlanPrimaryCtaTarget($navigationItems),
            ], static fn(string $value): bool => $value !== ''),
            'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, string> $contentItems
     * @return list<array{label:string,href:string,type:string}>
     */
    private function buildSharedNavigationItemsFromBuildPlanContract(array $contract, array $contentItems): array
    {
        $items = [];
        foreach (\is_array($contract['pages'] ?? null) ? $contract['pages'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? ''));
            if ($pageType === '' || $pageType === Page::TYPE_BLOG || $pageType === Page::TYPE_BLOG_CATEGORY) {
                continue;
            }
            $pageId = \trim((string)($page['page_id'] ?? $pageType));
            $titleKey = \trim((string)($page['title_key'] ?? ''));
            $label = $this->firstNonEmptyBuildPlanText([
                $titleKey !== '' ? ($contentItems[$titleKey] ?? null) : null,
                $pageId !== '' ? ($contentItems['page.' . $pageId . '.title'] ?? null) : null,
                Page::getPageTypes()[$pageType] ?? null,
                $pageType,
            ]);
            if ($label === '') {
                continue;
            }
            $handle = Page::getDefaultHandleForType($pageType);
            $items[] = [
                'label' => $label,
                'href' => $pageType === Page::TYPE_HOME ? '/' : '/' . $handle,
                'type' => $pageType,
            ];
            if (\count($items) >= 6) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param list<array{label:string,href:string,type:string}> $navigationItems
     */
    private function resolveBuildPlanPrimaryCtaTarget(array $navigationItems): string
    {
        foreach ([Page::TYPE_CONTACT, Page::TYPE_CUSTOM] as $preferredType) {
            foreach ($navigationItems as $item) {
                if (($item['type'] ?? '') === $preferredType && \trim((string)($item['href'] ?? '')) !== '') {
                    return (string)$item['href'];
                }
            }
        }

        return '';
    }

    private function normalizeBuildPlanPrimaryCta(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        $parts = \preg_split('/\s*(?:\/|\||,|\x{FF0C}|\x{3001})\s*/u', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $part = \trim((string)$part);
            if ($part !== '') {
                return $part;
            }
        }

        return $value;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyBuildPlanText(array $values): string
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

    /**
     * @return array<string, mixed>
     */
    private function buildLanguageRuntimeContract(string $locale): array
    {
        return [
            'source_of_truth_locale' => $locale,
            'visible_copy_rule' => 'All visitor-facing copy for headings, body, buttons, navigation, footer, form labels, alt/title/aria/placeholder text must use source_of_truth_locale.',
            'plan_text_rule' => 'BuildPlan text is intent only; translate or rewrite it before rendering visible copy.',
            'proper_noun_rule' => 'Brand names, product names, domain names, URLs, acronyms, model names, and user-provided proper nouns may retain original spelling when natural.',
            'failure_mode' => 'Visible copy in a different main language is a build contract violation.',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function extractLocalHostFromScopeUrls(array $scope): string
    {
        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'preview_url'] as $key) {
            $url = \trim((string)($scope[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = \parse_url($url, \PHP_URL_HOST);
            $host = \is_string($host) ? \strtolower(\trim($host)) : '';
            if ($host !== '' && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'))) {
                return $host;
            }
        }

        return '';
    }

    /**
     * @param mixed $items
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeBuildPlanRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeBuildPlanStringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (\is_array($value)) {
                $value = $value['task_id'] ?? $value['block_id'] ?? $value['id'] ?? '';
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     * @return list<string>
     */
    private function missingStringSet(array $expected, array $actual): array
    {
        $actualSet = [];
        foreach ($actual as $value) {
            $value = \trim((string)$value);
            if ($value !== '') {
                $actualSet[$value] = true;
            }
        }

        $missing = [];
        foreach ($expected as $value) {
            $value = \trim((string)$value);
            if ($value !== '' && !isset($actualSet[$value])) {
                $missing[] = $value;
            }
        }

        return \array_values(\array_unique($missing));
    }

    private function normalizeBuildPlanRoleToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        $value = \preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? $value;
        $value = \preg_replace('/_+/', '_', $value) ?? $value;

        return \trim($value, '_-');
    }

    private function slugifyForTask(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'section';
    }

    private function resolveBuildPlanSectionCode(string $pageType, string $sectionKey, string $blockId): string
    {
        $section = $sectionKey;
        if ($section === '' && $blockId !== '') {
            $parts = \explode('.', $blockId);
            $section = (string)\end($parts);
        }
        $section = $section !== '' ? $section : 'section';
        $sectionSlug = $this->slugifyForTask($section);

        return 'content/' . $this->slugifyForTask($pageType !== '' ? $pageType : 'page') . '-' . $sectionSlug;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function resolveStageBlockTypeForBuild(array $block): string
    {
        foreach ([
            $block['block_type'] ?? null,
            $block['type'] ?? null,
            $block['template'] ?? null,
            $block['display_role'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $token = $this->normalizeBuildPlanRoleToken((string)$candidate);
            if ($token !== '' && $token !== 'page_block') {
                return $token;
            }
        }

        return 'section';
    }

    /**
     * @param array<string, mixed> $visualSignature
     */
    private function resolveSectionTemplateFromPlannedIdentity(
        string $blockType,
        string $pageFlowRole,
        array $visualSignature = []
    ): string {
        $blockType = $this->normalizeBuildPlanRoleToken($blockType);
        $pageFlowRole = $this->normalizeBuildPlanRoleToken($pageFlowRole);
        $composition = $this->normalizeBuildPlanRoleToken((string)($visualSignature['composition_pattern'] ?? ''));

        if (\in_array($blockType, ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold'], true)) {
            return 'hero';
        }
        if (\in_array($blockType, ['cta', 'final_cta', 'download_cta', 'conversion_cta'], true)
            || \in_array($pageFlowRole, ['conversion', 'final_cta'], true)
            || \in_array($composition, ['cta_band', 'download_band', 'conversion_band'], true)
        ) {
            return 'cta';
        }
        if (\in_array($blockType, ['checklist', 'feature_grid', 'features', 'values', 'proof_grid', 'trust_grid', 'cards'], true)
            || \in_array($composition, ['feature_grid', 'feature_rail', 'proof_band', 'badge_wall', 'metric_strip'], true)
        ) {
            return 'checklist';
        }

        return 'section';
    }

    /**
     * @param array<string, mixed> $contentItems
     * @param list<string> $keys
     */
    private function firstBuildPlanContentValue(array $contentItems, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->contentValueForBuildPlanKey($contentItems, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contentItems
     */
    private function contentValueForBuildPlanKey(array $contentItems, string $key): string
    {
        $key = \trim($key);
        if ($key === '' || !\array_key_exists($key, $contentItems)) {
            return '';
        }

        $value = $contentItems[$key];
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return \trim((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'copy', 'content'] as $field) {
            if (\array_key_exists($field, $value) && (\is_scalar($value[$field]) || (\is_object($value[$field]) && \method_exists($value[$field], '__toString')))) {
                return \trim((string)$value[$field]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contentItems
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function sliceBuildPlanContentItems(array $contentItems, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $contentItems)) {
                $result[$key] = $contentItems[$key];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSignature(array $payload): string
    {
        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listPendingTasks(array $scope): array
    {
        $blueprintTasks = $this->extractBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $pending = [];
        foreach ($blueprintTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            $staleRunningRetry = $status === self::TASK_STATUS_RUNNING
                && (int)($state['attempt_no'] ?? 0) >= 2;
            if ($status !== self::TASK_STATUS_PENDING && !$staleRunningRetry) {
                continue;
            }
            $pending[] = \array_replace($task, $state);
        }
        \usort($pending, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $pending;
    }

    /**
     * 按依赖与页面分布挑选一批可并发调度的任务：
     * - shared 未完成前，仅调度 shared 任务
     * - shared 完成后，优先按 page_type 打散（每页先取 1 个），再补齐窗口
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function pickConcurrentTasks(array $scope, int $maxConcurrent = PHP_INT_MAX): array
    {
        $maxConcurrent = \max(1, $maxConcurrent);
        $pending = $this->listPendingTasks($scope);
        if ($pending === []) {
            return [];
        }
        $pending = \array_values(\array_filter(
            $pending,
            fn(array $task): bool => $this->areTaskDependenciesSatisfied($scope, $task)
        ));
        if ($pending === []) {
            return [];
        }
        $blueprintTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $this->extractBlueprintTasks($scope)
        ))), true);
        $hasSharedHeader = isset($blueprintTaskKeys['shared:header']);
        $hasSharedFooter = isset($blueprintTaskKeys['shared:footer']);
        $sharedDone = (!$hasSharedHeader || $this->isTaskDispatchSatisfied($scope, 'shared:header'))
            && (!$hasSharedFooter || $this->isTaskDispatchSatisfied($scope, 'shared:footer'));
        if (!$sharedDone) {
            $sharedOnly = \array_values(\array_filter($pending, static fn(array $task): bool => (string)($task['task_type'] ?? '') === 'shared_component'));
            return \array_slice($sharedOnly, 0, $maxConcurrent);
        }

        $nonParallelTasks = \array_values(\array_filter(
            $pending,
            static fn(array $task): bool =>
                (string)($task['task_type'] ?? '') === 'page_section'
                && !(bool)($task['can_parallel'] ?? true)
        ));
        if ($nonParallelTasks !== []) {
            return [$nonParallelTasks[0]];
        }

        $pageBuckets = [];
        $selected = [];
        foreach ($pending as $task) {
            $taskType = (string)($task['task_type'] ?? '');
            if ($taskType !== 'page_section') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType === '') {
                continue;
            }
            $pageBuckets[$pageType] ??= [];
            $pageBuckets[$pageType][] = $task;
        }

        // 第一轮：每个 page_type 先取 1 个，尽量并发分布到不同页面。
        foreach ($pageBuckets as $pageType => $tasks) {
            if ($tasks === []) {
                continue;
            }
            $selected[] = $tasks[0];
            \array_shift($pageBuckets[$pageType]);
            if (\count($selected) >= $maxConcurrent) {
                return $selected;
            }
        }
        // 第二轮：补齐并发窗口。
        foreach ($pageBuckets as $tasks) {
            foreach ($tasks as $task) {
                $selected[] = $task;
                if (\count($selected) >= $maxConcurrent) {
                    return $selected;
                }
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    public function getTaskDefinition(array $scope, string $taskKey): ?array
    {
        foreach ($this->extractBlueprintTasks($scope, true) as $task) {
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $resultRef
     * @return array<string, mixed>
     */
    public function markTaskDone(array $scope, string $taskKey, array $resultRef = []): array
    {
        $scope = $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_DONE,
            'message' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
            'finished_at' => \date('Y-m-d H:i:s'),
            'result_ref' => $resultRef,
        ], false);

        return $this->rollupBuildPageProgressForCompletedTaskIfNeeded($scope, $taskKey);
    }

    /**
     * 若 scope 中存在 `_build_page_progress[<page_type>][skip_remaining_blocks]=true`，将仍处 pending/running 的页内 section 批量标为 done（保留检查点语义，避免卡住总进度）。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function applyPagesMarkedSkipRemaining(array $scope): array
    {
        $progress = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
            ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
            : [];
        if ($progress === []) {
            return $scope;
        }

        foreach ($progress as $pageTypeKey => $row) {
            if (!\is_array($row) || !((bool)($row['skip_remaining_blocks'] ?? false))) {
                continue;
            }
            $pageType = \trim((string)$pageTypeKey);
            if ($pageType === '') {
                continue;
            }

            foreach ($this->extractBlueprintTasks($scope) as $task) {
                if ((string)($task['task_type'] ?? '') !== 'page_section') {
                    continue;
                }
                if (\trim((string)($task['page_type'] ?? '')) !== $pageType) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $taskState = $this->extractTaskState($scope);
                $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
                if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                    continue;
                }
                $scope = $this->markTaskDone($scope, $taskKey, \array_merge(
                    $this->buildTaskResultRefFromDefinition($task),
                    ['skipped_remaining_blocks' => true]
                ));
            }

            $progressReload = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
                ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
                : [];
            $slot = \is_array($progressReload[$pageType] ?? null) ? $progressReload[$pageType] : [];
            $progressReload[$pageType] = \array_replace($slot, [
                'skip_remaining_blocks' => false,
                'skipped_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] = $progressReload;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function rollupBuildPageProgressForCompletedTaskIfNeeded(array $scope, string $completedTaskKey): array
    {
        $definition = $this->getTaskDefinition($scope, $completedTaskKey);
        if ($definition === null || (string)($definition['task_type'] ?? '') !== 'page_section') {
            return $scope;
        }
        $pageType = \trim((string)($definition['page_type'] ?? ''));
        if ($pageType === '') {
            return $scope;
        }

        return $this->rollupBuildPageProgressForPageType($scope, $pageType);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function rollupBuildPageProgressForPageType(array $scope, string $pageType): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return $scope;
        }
        $expected = 0;
        $done = 0;
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if ((string)($task['task_type'] ?? '') !== 'page_section') {
                continue;
            }
            if (\trim((string)($task['page_type'] ?? '')) !== $pageType) {
                continue;
            }
            $expected++;
            $tk = \trim((string)($task['task_key'] ?? ''));
            if ($tk === '') {
                continue;
            }
            $st = $this->normalizeTaskStatus((string)($taskState[$tk]['status'] ?? self::TASK_STATUS_PENDING));
            if ($st === self::TASK_STATUS_DONE) {
                $done++;
            }
        }

        $progress = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
            ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
            : [];
        $prior = \is_array($progress[$pageType] ?? null) ? $progress[$pageType] : [];
        $progress[$pageType] = \array_replace($prior, [
            'sections_expected' => $expected,
            'sections_done' => $done,
            'page_rollup_complete' => $expected > 0 && $done >= $expected,
            'rollup_updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] = $progress;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskRunning(array $scope, string $taskKey): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_RUNNING,
            'message' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
            'started_at' => \date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskFailed(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_FAILED,
            'updated_at' => \date('Y-m-d H:i:s'),
            'message' => $this->sanitizeBuildTaskFailureMessageForView($message),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskPendingForRetry(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'message' => 'Retrying generation in the current queue.',
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskPendingForFreshRepair(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'attempt_no' => 0,
            'message' => $this->sanitizeBuildTaskFailureMessageForView($message, 'Retrying generation in a fresh queue.'),
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetFailedTasksForFreshRepair(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        $blueprintTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => \trim((string)($task['task_key'] ?? '')),
            $this->extractBlueprintTasks($scope)
        ))), true);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_FAILED) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        $retryableBuildFailures = $this->summarizeRetryableAiFailures($scope, 'build');
        foreach (\is_array($retryableBuildFailures['items'] ?? null) ? $retryableBuildFailures['items'] : [] as $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $taskKey = \trim((string)($failure['item_key'] ?? ''));
            if ($taskKey === '' || !isset($blueprintTaskKeys[$taskKey])) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
        }

        return $this->clearRetryableAiFailures($scope, 'build');
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetRunningTasksForInterruptedBuild(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_RUNNING) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function getTaskAttemptNo(array $scope, string $taskKey): int
    {
        $taskState = $this->extractTaskState($scope);
        $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];

        return \max(0, (int)($state['attempt_no'] ?? 0));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetTaskForRetry(array $scope, string $taskKey): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'message' => '',
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function listTaskKeysByPageType(array $scope, string $pageType): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return [];
        }

        $taskKeys = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if ((string)($task['page_type'] ?? '') !== $pageType) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey !== '') {
                $taskKeys[] = $taskKey;
            }
        }

        return $taskKeys;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function arePageTasksComplete(array $scope, string $pageType): bool
    {
        $taskKeys = $this->listTaskKeysByPageType($scope, $pageType);
        if ($taskKeys === []) {
            return false;
        }

        $taskState = $this->extractTaskState($scope);
        foreach ($taskKeys as $taskKey) {
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ((string)($state['status'] ?? self::TASK_STATUS_PENDING) !== self::TASK_STATUS_DONE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetPageTasksForRetry(array $scope, string $pageType): array
    {
        foreach ($this->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $scope = $this->resetTaskForRetry($scope, $taskKey);
        }

        return $scope;
    }

    /**
     * Queue-owned retry path: when a scheduler-owned build queue fails the
     * completion gate at the end of its own execute() cycle, put every unfinished
     * task back to pending and let the scheduler retry the same queue row.
     *
     * Cancelled tasks stay cancelled so an explicit operator stop is respected.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetUnfinishedTasksForQueueRetry(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_CANCELLED) {
                continue;
            }
            if ($status === self::TASK_STATUS_DONE && $this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                continue;
            }

            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        return $this->clearRetryableAiFailures($scope, 'build');
    }

    /**
     * Reconcile mutable task state with generated artifacts already persisted by the builder.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function reconcileGeneratedArtifactsWithTaskState(array $scope, bool $allowActiveRegenerationArtifacts = false): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
            if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                continue;
            }
            if (!$this->isGeneratedArtifactAvailableForTask($scope, $task, $allowActiveRegenerationArtifacts)) {
                continue;
            }

            $scope = $this->markTaskDone($scope, $taskKey, $this->buildTaskResultRefFromDefinition($task));
            $taskState = $this->extractTaskState($scope);
        }

        return $scope;
    }

    /**
     * 蓝图维度「仍有工作未完成」：含 pending/running。
     *
     * 说明：`listPendingTasks()` / `hasPendingTasks()` 仅枚举 pending，
     * 若主调度循环因全部为 running（无 pending）提前退出且未落盘 done，会出现
     * 队列已标记完成但任务面板仍卡在「进行中」；发布门槛须显式计入 running。
     *
     * @param array<string, mixed> $scope
     */
    public function hasUnfinishedBlueprintTasks(array $scope): bool
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if (\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构建主循环退出后收敛任务标记：先做产物对齐，再在仍有 stuck running 时将 running 拉回 pending，
     * 第二轮对齐把「已有产物但未落 done」的任务修正为 done。
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function finalizeBuildTaskStatesAfterRunLoop(array $scope): array
    {
        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope, true);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $summary = $this->summarize($scope);
        if ((int)($summary['running'] ?? 0) <= 0) {
            return $this->attachBuildRenderDataContract($scope);
        }
        $scope = $this->resetRunningTasksForInterruptedBuild(
            $scope,
            (string)__(
                '构建主循环已结束，但仍有任务停留在执行中状态；已结合已生成内容与任务状态对齐。'
            )
        );

        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope, true);
        $scope = $this->clearResolvedRetryableAiFailures($scope);

        return $this->attachBuildRenderDataContract($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function attachBuildRenderDataContract(array $scope): array
    {
        $scope = $this->syncPageTypeLayoutsWithSharedComponents($scope);
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $executionTasks = $this->extractBlueprintTasks($scope);
        if ($buildPlan === [] || $executionTasks === []) {
            return $scope;
        }

        $summary = $this->summarize($scope);
        if (
            (int)($summary['total'] ?? 0) <= 0
            || (int)($summary['pending'] ?? 0) > 0
            || (int)($summary['running'] ?? 0) > 0
            || (int)($summary['failed'] ?? 0) > 0
            || (int)($summary['cancelled'] ?? 0) > 0
            || (int)($summary['done'] ?? 0) < (int)($summary['total'] ?? 0)
        ) {
            return $scope;
        }

        $sourceContracts = $this->resolveBuildRenderSourceContracts($buildPlan);
        $payload = $this->buildRenderDataContractPayload($scope, $buildPlan, $summary);
        $meta = \is_array($buildPlan['contract_meta'] ?? null) ? $buildPlan['contract_meta'] : [];
        $contractContext = [
            'version' => 1,
            'stage' => ContractType::STAGE_BUILD,
            'build_plan_contract_id' => \trim((string)($meta['contract_id'] ?? $meta['id'] ?? '')),
            'build_plan_signature' => \trim((string)($meta['signature'] ?? $meta['source_signature'] ?? '')),
            'source_contracts' => $sourceContracts,
        ];
        $qaGateHelper = new QaGateHelper();
        $permissionMatrix = new PermissionMatrix();
        $contract = [
            'contract_meta' => (new ContractMetaBuilder())->build(
                ContractType::TYPE_RENDER_DATA,
                ContractType::STAGE_BUILD,
                ContractType::STATUS_DRAFT,
                'build_renderer',
                'build_render_data',
                [
                    'payload_hash' => $this->buildSignature($payload),
                    'source_signature' => (string)($contractContext['build_plan_signature'] ?? ''),
                ]
            ),
            'permission_matrix' => $permissionMatrix->forStage(ContractType::STAGE_BUILD),
            'frozen_fields' => \array_values(\array_unique(\array_merge(
                $permissionMatrix->defaultFrozenFields(ContractType::STAGE_BUILD),
                [
                    'payload.page_type_layouts',
                    'payload.shared_components',
                    'payload.materialized_pages_by_type',
                    'source_contracts',
                ]
            ))),
            'mutable_fields' => [
                'payload.human_notes',
                'qa_gates.*',
            ],
            'source_contracts' => $sourceContracts,
            'contract_context' => $contractContext,
            'qa_gates' => [
                'schema_shape' => $qaGateHelper->gate('schema_shape', QaGateHelper::STATUS_PASS, 'Build render-data contract payload shape is present.'),
                'source_contracts' => $qaGateHelper->gate(
                    'source_contracts',
                    $sourceContracts !== [] ? QaGateHelper::STATUS_PASS : QaGateHelper::STATUS_WARN,
                    $sourceContracts !== []
                        ? 'Build render-data contract is derived from upstream build and stage contracts.'
                        : 'Build render-data contract has no upstream contract references.'
                ),
                'human_review' => $qaGateHelper->gate('human_review', QaGateHelper::STATUS_PENDING, 'Human review is required before QA and repair contracts consume render data.'),
            ],
            'payload' => $payload,
        ];

        $buildContracts = \is_array($scope['build_contracts'] ?? null) ? $scope['build_contracts'] : [];
        $previousRenderDataContract = \is_array($buildContracts[ContractType::TYPE_RENDER_DATA] ?? null)
            ? $buildContracts[ContractType::TYPE_RENDER_DATA]
            : [];
        $structuralFindings = (new RenderDataQualityLinter())->lint($contract);
        foreach ($structuralFindings as $finding) {
            if (($finding['severity'] ?? '') === 'error') {
                $detail = \trim((string)($finding['message'] ?? ''));
                throw new \RuntimeException(
                    $detail !== ''
                        ? $detail
                        : 'Build render data failed RenderDataQualityLinter structural gate.'
                );
            }
        }

        $qaReportContract = (new ContractQaReportBuilder())->build(
            [ContractType::TYPE_RENDER_DATA => $contract],
            [
                ContractType::TYPE_RENDER_DATA => [
                    'build_plan_v2',
                ],
            ],
            $previousRenderDataContract !== [] ? [ContractType::TYPE_RENDER_DATA => $previousRenderDataContract] : [],
            $structuralFindings
        );
        $buildContracts[ContractType::TYPE_RENDER_DATA] = $contract;
        $buildContracts[ContractType::TYPE_QA_REPORT] = $qaReportContract;
        $scope['build_contracts'] = $buildContracts;
        $scope['render_data_contract'] = $contract;
        $scope['qa_report_contract'] = $qaReportContract;

        $buildWorkbench = \is_array($scope['build_workbench'] ?? null) ? $scope['build_workbench'] : [];
        $workbenchContracts = \is_array($buildWorkbench['contracts'] ?? null) ? $buildWorkbench['contracts'] : [];
        $workbenchContracts[ContractType::TYPE_RENDER_DATA] = $contract;
        $workbenchContracts[ContractType::TYPE_QA_REPORT] = $qaReportContract;
        $scope['build_workbench'] = \array_replace($buildWorkbench, [
            'version' => 1,
            'contract_context' => $contractContext,
            'contracts' => $workbenchContracts,
        ]);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncPageTypeLayoutsWithSharedComponents(array $scope): array
    {
        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        if ($layouts === [] || $sharedComponents === []) {
            return $scope;
        }

        $header = \is_array($sharedComponents['header'] ?? null) ? $sharedComponents['header'] : [];
        $footer = \is_array($sharedComponents['footer'] ?? null) ? $sharedComponents['footer'] : [];
        $headerCode = \trim((string)($header['code'] ?? ''));
        $footerCode = \trim((string)($footer['code'] ?? ''));
        $headerConfig = \is_array($header['default_config'] ?? null) ? $header['default_config'] : [];
        $footerConfig = \is_array($footer['default_config'] ?? null) ? $footer['default_config'] : [];
        if (($headerCode === '' || $headerConfig === []) && ($footerCode === '' || $footerConfig === [])) {
            return $scope;
        }

        $changed = false;
        foreach ($layouts as $pageType => $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            if ($headerCode !== '' && $headerConfig !== []) {
                $layout['header'] = [
                    'component' => $headerCode,
                    'config' => $headerConfig,
                ];
                $changed = true;
            }
            if ($footerCode !== '' && $footerConfig !== []) {
                $layout['footer'] = [
                    'component' => $footerCode,
                    'config' => $footerConfig,
                ];
                $changed = true;
            }
            $layouts[$pageType] = $layout;
        }

        if ($changed) {
            $scope['page_type_layouts'] = $layouts;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $payload buildRenderDataContractPayload
     */
    private function aggregateRenderPayloadHtmlForAssetQa(array $payload): string
    {
        $parts = [];
        $shared = \is_array($payload['shared_components'] ?? null) ? $payload['shared_components'] : [];
        foreach ($shared as $comp) {
            if (\is_array($comp)) {
                $parts[] = (string)($comp['html'] ?? '');
            }
        }
        foreach (\is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [] as $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $parts[] = (string)($block['html'] ?? $block['html_content'] ?? '');
            }
        }

        return \implode("\n", \array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildPlan
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildRenderDataContractPayload(array $scope, array $buildPlan, array $summary): array
    {
        $pageTypes = [];
        foreach (\is_array($buildPlan['pages'] ?? null) ? $buildPlan['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType !== '') {
                $pageTypes[$pageType] = true;
            }
        }
        $pageTypes = \array_values(\array_keys($pageTypes));
        $pageTypeSet = \array_fill_keys($pageTypes, true);
        $pageTypeLayouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $materializedPagesByType = \is_array($scope['materialized_pages_by_type'] ?? null) ? $scope['materialized_pages_by_type'] : [];
        $virtualPagesByType = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $pagebuilderPagesByType = \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [];
        if ($pageTypeSet !== []) {
            $pageTypeLayouts = \array_intersect_key($pageTypeLayouts, $pageTypeSet);
            $materializedPagesByType = \array_intersect_key($materializedPagesByType, $pageTypeSet);
            $virtualPagesByType = \array_intersect_key($virtualPagesByType, $pageTypeSet);
            $pagebuilderPagesByType = \array_intersect_key($pagebuilderPagesByType, $pageTypeSet);
        }

        $meta = \is_array($buildPlan['contract_meta'] ?? null) ? $buildPlan['contract_meta'] : [];

        return [
            'build_plan_contract_id' => \trim((string)($meta['contract_id'] ?? $meta['id'] ?? '')),
            'build_plan_signature' => \trim((string)($meta['signature'] ?? $meta['source_signature'] ?? '')),
            'workspace_track' => \trim((string)($buildPlan['workspace_track'] ?? $scope['workspace_track'] ?? '')),
            'page_types' => $pageTypes,
            'page_type_layouts' => $pageTypeLayouts,
            'shared_components' => \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [],
            'materialized_pages_by_type' => $materializedPagesByType,
            'virtual_pages_by_type' => $virtualPagesByType,
            'pagebuilder_pages_by_type' => $pagebuilderPagesByType,
            'asset_manifest' => \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [],
            'build_summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $buildPlan
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function resolveBuildRenderSourceContracts(array $buildPlan): array
    {
        $refs = [];
        $meta = \is_array($buildPlan['contract_meta'] ?? null) ? $buildPlan['contract_meta'] : [];
        $buildPlanContractId = \trim((string)($meta['contract_id'] ?? $meta['id'] ?? ''));
        if ($buildPlanContractId !== '') {
            $refs[] = [
                'id' => $buildPlanContractId,
                'type' => 'build_plan_v2',
                'version' => '2.2',
                'status' => ContractType::STATUS_CONFIRMED,
            ];
        }

        return $this->dedupeContractRefsForBuild($refs);
    }

    /**
     * @param list<array{id:string,type:string,version:string,status:string}> $refs
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function dedupeContractRefsForBuild(array $refs): array
    {
        $deduped = [];
        $seen = [];
        foreach ((new SourceContractHelper())->normalize($refs) as $ref) {
            $key = $ref['type'] . ':' . $ref['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $ref;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function summarize(array $scope): array
    {
        $blueprintTasks = $this->extractBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);

        $summary = [
            'total' => 0,
            'done' => 0,
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'groups' => [],
        ];

        foreach ($blueprintTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $groupKey = (string)($task['group_key'] ?? 'shared');
            $pageType = (string)($task['page_type'] ?? '');
            $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));

            $summary['total']++;
            $summary[$status]++;
            if (!isset($summary['groups'][$groupKey])) {
                $summary['groups'][$groupKey] = [
                    'page_type' => $pageType,
                    'total' => 0,
                    'done' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                    'tasks' => [],
                ];
            }
            $summary['groups'][$groupKey]['total']++;
            $summary['groups'][$groupKey][$status]++;
            $summary['groups'][$groupKey]['tasks'][] = [
                'task_key' => $taskKey,
                'label' => (string)($task['label'] ?? $taskKey),
                'section_code' => (string)($task['section_code'] ?? ''),
                'component' => (string)($task['component'] ?? ''),
                'task_type' => (string)($task['task_type'] ?? ''),
                'page_type' => $pageType,
                'group_key' => $groupKey,
                'status' => $status,
                'attempt_no' => (int)($taskState[$taskKey]['attempt_no'] ?? 0),
                'message' => $this->sanitizeBuildTaskFailureMessageForView((string)($taskState[$taskKey]['message'] ?? ''), ''),
                'updated_at' => (string)($taskState[$taskKey]['updated_at'] ?? ''),
                'finished_at' => (string)($taskState[$taskKey]['finished_at'] ?? ''),
            ];
        }

        return $summary;
    }

    /**
     * Build completion gate is sourced from build_plan_v2 block execution rows.
     * Derived summaries are display snapshots, not truth.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function inspectBuildCompletionGate(array $scope): array
    {
        $summary = $this->summarize($scope);
        $total = (int)($summary['total'] ?? 0);
        $done = (int)($summary['done'] ?? 0);
        $pending = (int)($summary['pending'] ?? 0);
        $running = (int)($summary['running'] ?? 0);
        $failed = (int)($summary['failed'] ?? 0);
        $cancelled = (int)($summary['cancelled'] ?? 0);
        $invalidArtifacts = $this->countInvalidCompletedTaskArtifacts($scope);
        $duplicateArtifacts = $this->countDuplicateCompletedPageSectionArtifacts($scope);
        $pageTypeCoverage = $this->inspectBuildCompletionPageTypeCoverage($scope);
        $missingBuildPageTypes = \is_array($pageTypeCoverage['missing_build_page_types'] ?? null) ? $pageTypeCoverage['missing_build_page_types'] : [];
        $missingPageTypeLayouts = \is_array($pageTypeCoverage['missing_page_type_layouts'] ?? null) ? $pageTypeCoverage['missing_page_type_layouts'] : [];
        $emptyPageTypeLayouts = \is_array($pageTypeCoverage['empty_page_type_layouts'] ?? null) ? $pageTypeCoverage['empty_page_type_layouts'] : [];
        $missingPersistedVirtualThemeLayouts = \is_array($pageTypeCoverage['missing_persisted_virtual_theme_layouts'] ?? null)
            ? $pageTypeCoverage['missing_persisted_virtual_theme_layouts']
            : [];
        $unfinished = \max(0, $total - $done, $pending + $running + $failed + $cancelled);
        $hasIncompleteTasks = $total <= 0
            || $this->hasUnfinishedBlueprintTasks($scope)
            || $pending > 0
            || $running > 0
            || $failed > 0
            || $cancelled > 0
            || $invalidArtifacts > 0
            || $duplicateArtifacts > 0
            || $missingBuildPageTypes !== []
            || $missingPageTypeLayouts !== []
            || $emptyPageTypeLayouts !== []
            || $missingPersistedVirtualThemeLayouts !== []
            || $done < $total;

        $reason = '';
        if ($total <= 0) {
            $reason = 'missing_build_plan_blocks';
        } elseif ($failed > 0) {
            $reason = 'failed_build_plan_blocks';
        } elseif ($cancelled > 0) {
            $reason = 'cancelled_build_plan_blocks';
        } elseif ($invalidArtifacts > 0) {
            $reason = 'invalid_generated_artifacts';
        } elseif ($duplicateArtifacts > 0) {
            $reason = 'duplicate_generated_artifacts';
        } elseif ($missingBuildPageTypes !== []) {
            $reason = 'missing_build_plan_page_types';
        } elseif ($missingPageTypeLayouts !== []) {
            $reason = 'missing_page_type_layouts';
        } elseif ($emptyPageTypeLayouts !== []) {
            $reason = 'empty_page_type_layouts';
        } elseif ($missingPersistedVirtualThemeLayouts !== []) {
            $reason = 'missing_persisted_virtual_theme_layouts';
        } elseif ($unfinished > 0) {
            $reason = 'unfinished_build_plan_blocks';
        }

        return [
            'passed' => !$hasIncompleteTasks,
            'reason' => $reason,
            'total' => $total,
            'done' => $done,
            'pending' => $pending,
            'running' => $running,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'invalid_artifacts' => $invalidArtifacts,
            'duplicate_artifacts' => $duplicateArtifacts,
            'page_type_coverage' => $pageTypeCoverage,
            'missing_build_page_types' => $missingBuildPageTypes,
            'missing_page_type_layouts' => $missingPageTypeLayouts,
            'empty_page_type_layouts' => $emptyPageTypeLayouts,
            'missing_persisted_virtual_theme_layouts' => $missingPersistedVirtualThemeLayouts,
            'unfinished' => $unfinished,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   expected_page_types:list<string>,
     *   build_page_types:list<string>,
     *   layout_page_types:list<string>,
     *   missing_build_page_types:list<string>,
     *   missing_page_type_layouts:list<string>,
     *   empty_page_type_layouts:list<string>,
     *   missing_persisted_virtual_theme_layouts:list<string>
     * }
     */
    public function inspectBuildCompletionPageTypeCoverage(array $scope): array
    {
        $expected = $this->normalizeBuildPlanStringList($scope['page_types'] ?? []);
        if ($expected === []) {
            return [
                'expected_page_types' => [],
                'build_page_types' => [],
                'layout_page_types' => [],
                'missing_build_page_types' => [],
                'missing_page_type_layouts' => [],
                'empty_page_type_layouts' => [],
                'missing_persisted_virtual_theme_layouts' => [],
            ];
        }

        $buildPageTypes = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if (\trim((string)($task['task_type'] ?? '')) !== 'page_section') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType !== '') {
                $buildPageTypes[$pageType] = true;
            }
        }

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layoutPageTypes = [];
        $missingLayouts = [];
        $emptyLayouts = [];
        $missingPersistedLayouts = [];
        foreach ($expected as $pageType) {
            $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
            if ($layout === []) {
                $missingLayouts[] = $pageType;
            } else {
                $layoutPageTypes[$pageType] = true;
                if (!$this->layoutHasContentBlocks($layout)) {
                    $emptyLayouts[] = $pageType;
                }
            }
            if (
                $this->requiresPersistedVirtualThemeLayoutCheck($scope)
                && !$this->persistedVirtualThemeLayoutHasContent($scope, $pageType)
            ) {
                $missingPersistedLayouts[] = $pageType;
            }
        }

        return [
            'expected_page_types' => $expected,
            'build_page_types' => \array_values(\array_keys($buildPageTypes)),
            'layout_page_types' => \array_values(\array_keys($layoutPageTypes)),
            'missing_build_page_types' => $this->missingStringSet($expected, \array_values(\array_keys($buildPageTypes))),
            'missing_page_type_layouts' => \array_values(\array_unique($missingLayouts)),
            'empty_page_type_layouts' => \array_values(\array_unique($emptyLayouts)),
            'missing_persisted_virtual_theme_layouts' => \array_values(\array_unique($missingPersistedLayouts)),
        ];
    }

    /**
     * @param array<string, mixed> $gate inspectBuildCompletionGate() 返回值
     */
    public function formatBuildCompletionGateFailureDetail(array $gate): string
    {
        $reason = \trim((string)($gate['reason'] ?? ''));
        if ($reason === 'failed_build_plan_blocks') {
            $failedLines = $this->formatFailedBuildTaskLines(
                \is_array($gate['summary'] ?? null) ? $gate['summary'] : []
            );
            if ($failedLines !== '') {
                return $failedLines . ' ' . (string)__('请在工作台点击「重试失败项」后重新调度构建队列。');
            }

            return (string)__('有构建任务失败，请在工作台点击「重试失败项」后重新调度构建队列。');
        }
        if ($reason === 'invalid_generated_artifacts') {
            $count = (int)($gate['invalid_artifacts'] ?? 0);

            return (string)__('有 %{count} 项构建产物无效，请点击「重试失败项」或「重新生成当前阶段」后重试。', ['count' => $count]);
        }
        if ($reason === 'duplicate_generated_artifacts') {
            $count = (int)($gate['duplicate_artifacts'] ?? 0);

            return 'Build produced ' . $count . ' duplicated page-section artifact(s). Regenerate from the Stage-1 visual_signature contract instead of reusing another block.';
        }
        if (\in_array($reason, [
            'missing_build_plan_page_types',
            'missing_page_type_layouts',
            'empty_page_type_layouts',
            'missing_persisted_virtual_theme_layouts',
        ], true)) {
            $coverage = \is_array($gate['page_type_coverage'] ?? null) ? $gate['page_type_coverage'] : [];
            $missing = \array_values(\array_unique(\array_merge(
                \is_array($coverage['missing_build_page_types'] ?? null) ? $coverage['missing_build_page_types'] : [],
                \is_array($coverage['missing_page_type_layouts'] ?? null) ? $coverage['missing_page_type_layouts'] : [],
                \is_array($coverage['empty_page_type_layouts'] ?? null) ? $coverage['empty_page_type_layouts'] : [],
                \is_array($coverage['missing_persisted_virtual_theme_layouts'] ?? null) ? $coverage['missing_persisted_virtual_theme_layouts'] : []
            )));

            return (string)__('构建结果缺少页面类型产物：%{page_types}。请重新生成建站方案并重新调度构建队列。', [
                'page_types' => \implode(', ', \array_slice($missing, 0, 12)),
            ]);
        }
        if ($reason === 'cancelled_build_plan_blocks') {
            return (string)__('有构建任务已取消，请检查工作台任务状态后重试。');
        }
        if ($reason === 'unfinished_build_plan_blocks' || $reason === 'missing_build_plan_blocks') {
            $done = (int)($gate['done'] ?? 0);
            $total = (int)($gate['total'] ?? 0);

            return (string)__('构建任务尚未全部完成（%{done}/%{total}），请等待或点击「重试失败项」。', [
                'done' => $done,
                'total' => $total,
            ]);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $summary summarize() 返回值
     */
    private function formatFailedBuildTaskLines(array $summary): string
    {
        $failedTasks = [];
        foreach (\is_array($summary['groups'] ?? null) ? $summary['groups'] : [] as $group) {
            if (!\is_array($group)) {
                continue;
            }
            foreach (\is_array($group['tasks'] ?? null) ? $group['tasks'] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                if ($this->normalizeTaskStatus((string)($task['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_FAILED) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $label = \trim((string)($task['label'] ?? ''));
                $pageType = \trim((string)($task['page_type'] ?? ''));
                $message = \trim((string)($task['message'] ?? ''));
                $parts = [$taskKey];
                if ($pageType !== '') {
                    $parts[] = $pageType;
                }
                if ($label !== '') {
                    $parts[] = $label;
                }
                $line = \implode(' / ', $parts);
                if ($message !== '') {
                    $line .= ': ' . $message;
                }
                $failedTasks[] = $line;
            }
        }

        if ($failedTasks === []) {
            return '';
        }

        return (string)__('失败任务：%{tasks}', [
            'tasks' => \implode('; ', \array_slice($failedTasks, 0, 5)),
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function countInvalidCompletedTaskArtifacts(array $scope): int
    {
        $count = 0;
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_DONE) {
                continue;
            }
            if (!$this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function countDuplicateCompletedPageSectionArtifacts(array $scope): int
    {
        $taskState = $this->extractTaskState($scope);
        $eligibleSections = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if (!\is_array($task) || \trim((string)($task['task_type'] ?? '')) !== 'page_section') {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            if ($taskKey === '' || $pageType === '' || $sectionCode === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_DONE) {
                continue;
            }
            if (!$this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                continue;
            }
            $eligibleSections[$pageType][$sectionCode] = [
                'task_key' => $taskKey,
                'block_key' => \trim((string)($task['block_key'] ?? $task['section_key'] ?? '')),
            ];
        }
        if ($eligibleSections === []) {
            return 0;
        }

        $duplicates = 0;
        $seenByPage = [];
        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        foreach ($layouts as $pageType => $layout) {
            $pageType = (string)$pageType;
            if (!\is_array($layout) || !\is_array($eligibleSections[$pageType] ?? null)) {
                continue;
            }
            foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = $this->resolveLayoutSectionCode($section);
                if ($sectionCode === '' || !\is_array($eligibleSections[$pageType][$sectionCode] ?? null)) {
                    continue;
                }
                $text = $this->normalizeGeneratedArtifactVisibleText($scope, $section);
                if (\mb_strlen($text) < 80) {
                    continue;
                }
                $fingerprints = ['exact:' . \sha1(\mb_substr($text, 0, 500))];
                $leadFingerprint = $this->buildGeneratedArtifactLeadFingerprint($text);
                if ($leadFingerprint !== '') {
                    $fingerprints[] = 'lead:' . $leadFingerprint;
                }
                $isDuplicate = false;
                foreach ($fingerprints as $fingerprint) {
                    if (isset($seenByPage[$pageType][$fingerprint]) && $seenByPage[$pageType][$fingerprint] !== $sectionCode) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if ($isDuplicate) {
                    ++$duplicates;
                    continue;
                }
                foreach ($fingerprints as $fingerprint) {
                    $seenByPage[$pageType][$fingerprint] = $sectionCode;
                }
            }
        }

        return $duplicates;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function resolveLayoutSectionCode(array $section): string
    {
        foreach (['code', 'component', 'section_code'] as $key) {
            $value = \trim((string)($section[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $section
     */
    private function normalizeGeneratedArtifactVisibleText(array $scope, array $section): string
    {
        $parts = [];
        $sectionCode = $this->resolveLayoutSectionCode($section);
        if ($sectionCode !== '' && $this->requiresPersistedVirtualThemeLayoutCheck($scope)) {
            $persistedTemplate = $this->loadVirtualThemeComponentArtifactPayload($scope, $sectionCode);
            if ($persistedTemplate !== '') {
                $parts[] = $this->extractVisitorTextFromGeneratedTemplate($persistedTemplate);
            }
        }
        foreach (['html', 'html_content', 'template', 'template_content'] as $key) {
            $value = $section[$key] ?? null;
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                $parts[] = $this->extractVisitorTextFromGeneratedTemplate((string)$value);
            }
        }
        if ($parts === [] && \is_array($section['config'] ?? null)) {
            $parts[] = (string)\json_encode($section['config'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if ($parts === []) {
            return '';
        }

        $text = \html_entity_decode(\strip_tags(\implode(' ', $parts)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = (string)\preg_replace('/https?:\/\/\S+|\/pub\/media\/\S+/iu', ' ', $text);
        $text = (string)\preg_replace('/\bcontent\/[a-z0-9_-]+-[a-z0-9_-]+\b/iu', ' ', $text);
        $text = (string)\preg_replace('/\s+/', ' ', $text);

        return \mb_strtolower(\trim($text));
    }

    private function extractVisitorTextFromGeneratedTemplate(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $payload = (string)\preg_replace('/<\?php[\s\S]*?\?>/u', ' ', $payload);
        $payload = (string)\preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/iu', ' ', $payload);
        $payload = (string)\preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/iu', ' ', $payload);

        return $payload;
    }

    private function buildGeneratedArtifactLeadFingerprint(string $text): string
    {
        $text = \trim($text);
        if ($text === '') {
            return '';
        }
        $words = \preg_split('/[^\p{L}\p{N}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($words) || \count($words) < 5) {
            return '';
        }
        $lead = \array_slice($words, 0, 9);
        $leadText = \implode(' ', $lead);
        if (\mb_strlen($leadText) < 24) {
            return '';
        }

        return \sha1($leadText);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array{items:array<string,array<string,mixed>>,updated_at:string}>
     */
    public function getRetryableAiFailures(array $scope, ?string $operation = null): array
    {
        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        if ($operation === null || \trim($operation) === '') {
            return $ledger;
        }

        $operation = \trim($operation);
        return isset($ledger[$operation]) ? [$operation => $ledger[$operation]] : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>>|array<string, array<string, mixed>> $failures
     * @return array<string, mixed>
     */
    public function replaceRetryableAiFailures(array $scope, string $operation, array $failures): array
    {
        $operation = \trim($operation);
        if ($operation === '') {
            return $scope;
        }

        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        $items = $this->normalizeRetryableAiFailureItems($operation, $failures);
        if ($items === []) {
            unset($ledger[$operation]);
        } else {
            $ledger[$operation] = [
                'items' => $items,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
        }

        $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] = $ledger;
        $scope['retryable_ai_failure_count'] = $this->countRetryableAiFailuresFromLedger($ledger);
        $scope['next_stage_blocked_by_ai_failures'] = $scope['retryable_ai_failure_count'] > 0 ? 1 : 0;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearRetryableAiFailures(array $scope, string $operation): array
    {
        return $this->replaceRetryableAiFailures($scope, $operation, []);
    }

    /**
     * 构建方案准备完成后，清理旧构建前置错误。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearBuildPrerequisiteFailureState(array $scope): array
    {
        $scope = $this->clearRetryableAiFailures($scope, 'build');
        return $this->clearLatestBuildFailureState($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearResolvedRetryableAiFailures(array $scope): array
    {
        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        $taskState = $this->extractTaskState($scope);
        foreach (['build'] as $operation) {
            $items = \is_array($ledger[$operation]['items'] ?? null) ? $ledger[$operation]['items'] : [];
            foreach ($items as $itemKey => $item) {
                if (!\is_array($item)) {
                    unset($items[$itemKey]);
                    continue;
                }
                $relatedTaskKeys = \is_array($item['task_keys'] ?? null)
                    ? \array_values(\array_filter(\array_map('strval', $item['task_keys'])))
                    : [];
                $candidateKey = \trim((string)($item['item_key'] ?? $itemKey));
                if ($candidateKey !== '') {
                    $relatedTaskKeys[] = $candidateKey;
                }
                $relatedTaskKeys = \array_values(\array_unique($relatedTaskKeys));
                if ($relatedTaskKeys === []) {
                    continue;
                }

                $resolved = true;
                foreach ($relatedTaskKeys as $taskKey) {
                    $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
                    if ($status !== self::TASK_STATUS_DONE) {
                        $resolved = false;
                        break;
                    }
                }
                if ($resolved) {
                    unset($items[$itemKey]);
                }
            }

            if ($items === []) {
                unset($ledger[$operation]);
            } else {
                $ledger[$operation]['items'] = $items;
                $ledger[$operation]['updated_at'] = \date('Y-m-d H:i:s');
            }
        }

        $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] = $ledger;
        $scope['retryable_ai_failure_count'] = $this->countRetryableAiFailuresFromLedger($ledger);
        $scope['next_stage_blocked_by_ai_failures'] = $scope['retryable_ai_failure_count'] > 0 ? 1 : 0;
        foreach (['build'] as $operation) {
            if (isset($ledger[$operation])) {
                continue;
            }
            if (\is_array($scope['active_operations'][$operation] ?? null)) {
                $scope['active_operations'][$operation]['retryable_ai_failure_count'] = 0;
                $scope['active_operations'][$operation]['failure_mode'] = '';
                $scope['active_operations'][$operation]['queue_waiting_for_scheduler'] = false;
                if (($scope['active_operations'][$operation]['status'] ?? '') === self::TASK_STATUS_DONE) {
                    $scope['active_operations'][$operation]['can_close_stream'] = false;
                    $scope['active_operations'][$operation]['continue_other_operations'] = false;
                }
            }
            if (\is_array($scope['active_operation'] ?? null)
                && \trim((string)($scope['active_operation']['operation'] ?? '')) === $operation
            ) {
                $scope['active_operation']['retryable_ai_failure_count'] = 0;
                $scope['active_operation']['failure_mode'] = '';
                $scope['active_operation']['queue_waiting_for_scheduler'] = false;
                if (($scope['active_operation']['status'] ?? '') === self::TASK_STATUS_DONE) {
                    $scope['active_operation']['can_close_stream'] = false;
                    $scope['active_operation']['continue_other_operations'] = false;
                }
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasRetryableAiFailures(array $scope, ?string $operation = null): bool
    {
        $summary = $this->summarizeRetryableAiFailures($scope, $operation);
        return (int)($summary['count'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{count:int,operations:array<string,int>,items:list<array<string,mixed>>}
     */
    public function summarizeRetryableAiFailures(array $scope, ?string $operation = null): array
    {
        $ledger = $this->getRetryableAiFailures($scope, $operation);
        $items = [];
        $operations = [];
        foreach ($ledger as $operationKey => $bucket) {
            $bucketItems = \is_array($bucket['items'] ?? null) ? $bucket['items'] : [];
            $operations[$operationKey] = \count($bucketItems);
            foreach ($bucketItems as $failure) {
                if (\is_array($failure)) {
                    $items[] = $failure;
                }
            }
        }

        return [
            'count' => \count($items),
            'operations' => $operations,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncBuildTaskFailuresToRetryableLedger(array $scope): array
    {
        $scope = $this->normalizeConfirmedBuildPlanFlag($scope);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $taskSummary = $this->summarize($scope);
        $allBuildTasksComplete = $this->isBuildTaskSummaryFullyComplete($taskSummary)
            && !$this->hasUnfinishedBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $existingBuildLedger = $this->getRetryableAiFailures($scope, 'build');
        $existingBuildFailures = \is_array($existingBuildLedger['build']['items'] ?? null)
            ? $existingBuildLedger['build']['items']
            : [];
        if ($allBuildTasksComplete) {
            $existingBuildFailures = [];
            $scope = $this->clearLatestBuildFailureState($scope);
        }
        $failures = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status !== self::TASK_STATUS_FAILED) {
                continue;
            }
            $message = \trim((string)($state['message'] ?? ''));
            $failures[$taskKey] = [
                'operation' => 'build',
                'item_key' => $taskKey,
                'item_type' => (string)($task['task_type'] ?? 'build_task'),
                'retry_scope' => 'build_task',
                'page_type' => (string)($task['page_type'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'message' => $this->sanitizeBuildTaskFailureMessageForView($message),
                'failed_at' => (string)($state['finished_at'] ?? $state['updated_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        if (!$allBuildTasksComplete && $failures === [] && $existingBuildFailures !== []) {
            $failures = $existingBuildFailures;
        }
        if (
            !$allBuildTasksComplete
            && $failures === []
            && (!empty($scope['latest_build_failed']) || !empty($scope['publish_blocked_by_latest_ai_failure']))
        ) {
            $latestBuildFailure = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
            $fallbackKey = \trim((string)(
                $latestBuildFailure['item_key']
                ?? $latestBuildFailure['task_key']
                ?? $latestBuildFailure['page_type']
                ?? $latestBuildFailure['operation']
                ?? ''
            ));
            if ($fallbackKey === '') {
                $fallbackKey = 'latest_build_failure';
            }
            $failures[$fallbackKey] = [
                'operation' => 'build',
                'item_key' => $fallbackKey,
                'item_type' => (string)($latestBuildFailure['item_type'] ?? 'build_task'),
                'retry_scope' => (string)($latestBuildFailure['retry_scope'] ?? 'build_task'),
                'page_type' => (string)($latestBuildFailure['page_type'] ?? ''),
                'section_code' => (string)($latestBuildFailure['section_code'] ?? ''),
                'message' => $this->sanitizeBuildTaskFailureMessageForView((string)(
                    $latestBuildFailure['message']
                    ?? $scope['publish_blocked_reason']
                    ?? ''
                )),
                'failed_at' => (string)($latestBuildFailure['failed_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        $scope = $this->replaceRetryableAiFailures($scope, 'build', $failures);
        if ($failures === [] && $allBuildTasksComplete) {
            $scope = $this->clearLatestBuildFailureState($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function isBuildTaskSummaryFullyComplete(array $summary): bool
    {
        $total = (int)($summary['total'] ?? 0);
        if ($total <= 0) {
            return false;
        }

        return (int)($summary['done'] ?? 0) >= $total
            && (int)($summary['failed'] ?? 0) === 0
            && (int)($summary['pending'] ?? 0) === 0
            && (int)($summary['running'] ?? 0) === 0
            && (int)($summary['cancelled'] ?? 0) === 0;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearLatestBuildFailureState(array $scope): array
    {
        $scope['latest_build_failed'] = 0;
        $scope['latest_build_failure'] = [];
        $scope['publish_blocked_by_latest_ai_failure'] = 0;
        $scope['publish_blocked_reason'] = '';

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return bool
     */
    public function hasPendingTasks(array $scope): bool
    {
        return $this->listPendingTasks($scope) !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     */
    private function areTaskDependenciesSatisfied(array $scope, array $task): bool
    {
        $dependencies = \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [];
        foreach ($dependencies as $dependency) {
            $dependencyKey = \trim((string)$dependency);
            if ($dependencyKey === '') {
                continue;
            }
            if (!$this->isTaskDispatchSatisfied($scope, $dependencyKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskDone(array $scope, string $taskKey): bool
    {
        $taskState = $this->extractTaskState($scope);
        return (string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING) === self::TASK_STATUS_DONE;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskDispatchSatisfied(array $scope, string $taskKey): bool
    {
        $taskState = $this->extractTaskState($scope);
        $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));

        return \in_array($status, [self::TASK_STATUS_DONE, self::TASK_STATUS_CANCELLED], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function extractBlueprintTasks(array $scope, bool $inflate = false): array
    {
        unset($inflate);

        return $this->buildExecutionTasksFromBuildPlan($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildExecutionTasksFromBuildPlan(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return [];
        }

        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $pagesById = $this->normalizeBuildPlanRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $contentItems = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $runtimeRoot = $this->resolveBuildPlanStage2RuntimeContext($scope, $contract);
        $contentLocale = $this->firstNonEmptyBuildPlanText([
            $contract['i18n']['primary_locale'] ?? null,
            $contentManifest['primary_locale'] ?? null,
            $scope['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
        ]);
        $languageContract = $this->buildLanguageRuntimeContract($contentLocale);
        $tasks = [];

        foreach (['header', 'footer'] as $index => $region) {
            $tasks[] = [
                'task_key' => 'shared:' . $region,
                'task_type' => 'shared_component',
                'scope_key' => 'shared_components.' . $region,
                'group_key' => 'shared',
                'page_type' => '',
                'region' => $region,
                'section_code' => '',
                'section_key' => $region,
                'block_key' => $region,
                'block_type' => $region,
                'page_flow_role' => 'shared_chrome',
                'label' => \ucfirst($region),
                'sort_order' => 10 + ($index * 10),
                'dependencies' => [],
                'can_parallel' => false,
                'materialize_after_done' => false,
                'materialize_policy' => 'none',
                'prompt_template_key' => 'build_plan_v2_block_execute',
                'progress_weight' => 1.0,
                'result_ref' => ['region' => $region],
                'runtime_context' => \array_replace_recursive($runtimeRoot, [
                    'content_locale' => $contentLocale,
                    'language_contract' => $languageContract,
                    'context_refs' => [
                        'site_brief_ref' => 'build_plan_v2.site_brief',
                        'design_manifest_ref' => 'build_plan_v2.design_manifest',
                        'shared_context_ref' => 'build_plan_v2.site_brief',
                    ],
                ]),
                'plan_context' => [
                    'site_brief' => \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [],
                    'design_manifest' => \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [],
                    'shared_region' => $region,
                    'page_flow_role' => 'shared_chrome',
                ],
                'task_script' => [
                    'component_type' => $region,
                    'output_contract' => $this->buildPlanExecutionOutputContract($region, []),
                    'acceptance' => $this->buildPlanExecutionAcceptanceContract($region),
                    'content_keys' => ['site.name', 'site.primary_goal'],
                    'policy_slices' => ['layout.grid_alignment', 'typography.refined_font_stack', 'color.readable_contrast'],
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic'],
                ],
                'block_task' => [
                    'block_type' => $region,
                    'page_flow_role' => 'shared_chrome',
                    'task_goal' => $region === 'header' ? 'Render the site navigation header.' : 'Render the site footer.',
                    'content_plan' => [],
                    'style_plan' => \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [],
                    'output_contract' => $this->buildPlanExecutionOutputContract($region, []),
                    'acceptance' => $this->buildPlanExecutionAcceptanceContract($region),
                ],
                'implementation_contract' => [
                    'source' => 'build_plan_v2.blocks',
                    'contract_id' => (string)($meta['id'] ?? ''),
                    'block_id' => '',
                ],
            ];
        }

        $blocks = \array_values($this->normalizeBuildPlanRecordSet($contract['blocks'] ?? [], ['block_id', 'id']));
        \usort($blocks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $promptContextAssembler = new AiSiteBuildPromptContextAssembler();
        foreach ($blocks as $sortIndex => $block) {
            $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? ''));
            $pageId = \trim((string)($block['page_id'] ?? ''));
            $page = \is_array($pagesById[$pageId] ?? null) ? $pagesById[$pageId] : [];
            $pageType = \trim((string)($block['page_type'] ?? $page['page_type'] ?? ''));
            if ($blockId === '' || $pageType === '') {
                continue;
            }
            $sectionKey = \trim((string)($block['section_key'] ?? ''));
            if ($sectionKey === '') {
                $parts = \explode('.', $blockId);
                $sectionKey = (string)\end($parts);
            }
            $sectionCode = $this->resolveBuildPlanSectionCode($pageType, $sectionKey, $blockId);
            $taskId = 'page:' . $pageType . ':' . $sectionCode;
            $contentKeys = $this->normalizeBuildPlanStringList($block['content_keys'] ?? []);
            $label = $this->firstBuildPlanContentValue($contentItems, $contentKeys);
            if ($label === '') {
                $label = $sectionKey !== '' ? \ucfirst(\str_replace(['_', '-'], ' ', $sectionKey)) : $taskId;
            }
            $blockType = $this->normalizeBuildPlanRoleToken((string)($block['block_type'] ?? $block['type'] ?? $block['template'] ?? 'section'));
            $pageFlowRole = $this->normalizeBuildPlanRoleToken((string)($block['page_flow_role'] ?? ''));
            $visualSignature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
            $imageIntent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
            $stylePlan = \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [];
            foreach (['design_tags', 'visual_signature', 'image_intent'] as $planKey) {
                if (\is_array($block[$planKey] ?? null) && $block[$planKey] !== []) {
                    $stylePlan[$planKey] = $block[$planKey];
                }
            }
            if ($pageFlowRole !== '') {
                $stylePlan['page_flow_role'] = $pageFlowRole;
            }
            $outputContract = $this->buildPlanExecutionOutputContract($blockType, $contentKeys);
            $acceptance = $this->buildPlanExecutionAcceptanceContract($blockType);
            $pseudoTask = [
                'task_id' => $taskId,
                'input_scope' => [
                    'page_id' => $pageId,
                    'page_type' => $pageType,
                    'block_id' => $blockId,
                    'block_type' => $blockType,
                    'section_key' => $sectionKey,
                    'page_flow_role' => $pageFlowRole,
                ],
                'runtime_context' => \array_replace_recursive($runtimeRoot, [
                    'content_locale' => $contentLocale,
                    'language_contract' => $languageContract,
                ]),
                'output_contract' => $outputContract,
                'acceptance' => $acceptance,
            ];
            $planContext = $this->compactBuildPlanTaskPlanContext($promptContextAssembler->assemble($contract, $pseudoTask));
            $planContext['block_type'] = $blockType;
            if ($pageFlowRole !== '') {
                $planContext['page_flow_role'] = $pageFlowRole;
            }
            if ($visualSignature !== []) {
                $planContext['block_visual_signature'] = $visualSignature;
            }
            if ($imageIntent !== []) {
                $planContext['block_image_intent'] = $imageIntent;
            }

            $tasks[] = [
                'task_key' => $taskId,
                'task_type' => 'page_section',
                'scope_key' => 'page_sections.' . $pageType . '.' . $sectionCode,
                'group_key' => $pageType,
                'page_type' => $pageType,
                'region' => 'content',
                'section_code' => $sectionCode,
                'section_key' => $sectionKey,
                'block_key' => $sectionKey,
                'block_id' => $blockId,
                'block_type' => $blockType,
                'page_flow_role' => $pageFlowRole,
                'visual_signature' => $visualSignature,
                'image_intent' => $imageIntent,
                'label' => $label,
                'sort_order' => 100 + ((int)$sortIndex * 10),
                'dependencies' => ['shared:header', 'shared:footer'],
                'can_parallel' => true,
                'materialize_after_done' => true,
                'materialize_policy' => 'page',
                'prompt_template_key' => 'build_plan_v2_block_execute',
                'progress_weight' => 2.0,
                'result_ref' => ['page_type' => $pageType, 'section_code' => $sectionCode],
                'runtime_context' => \array_replace_recursive($runtimeRoot, [
                    'content_locale' => $contentLocale,
                    'language_contract' => $languageContract,
                    'context_refs' => [
                        'site_brief_ref' => 'build_plan_v2.site_brief',
                        'design_manifest_ref' => 'build_plan_v2.design_manifest',
                        'page_context_ref' => $pageId !== '' ? ('build_plan_v2.pages.' . $pageId) : '',
                        'block_context_ref' => 'build_plan_v2.blocks.' . $blockId,
                        'asset_context_ref' => 'build_plan_v2.blocks.' . $blockId . '.image_intent',
                    ],
                ]),
                'plan_context' => $planContext,
                'task_script' => [
                    'component_type' => 'section',
                    'output_contract' => $outputContract,
                    'acceptance' => $acceptance,
                    'content_keys' => $contentKeys,
                    'policy_slices' => ['layout.4_8_spacing', 'typography.refined_font_stack', 'image.integrated_not_pasted', 'responsive.no_horizontal_scroll'],
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'],
                ],
                'block_task' => [
                    'block_type' => $blockType,
                    'page_flow_role' => $pageFlowRole,
                    'task_goal' => $this->contentValueForBuildPlanKey($contentItems, $contentKeys[1] ?? ''),
                    'content_plan' => $this->sliceBuildPlanContentItems($contentItems, $contentKeys),
                    'style_plan' => $stylePlan,
                    'visual_signature' => $visualSignature,
                    'image_intent' => $imageIntent,
                    'output_contract' => $outputContract,
                    'acceptance' => $acceptance,
                ],
                'implementation_contract' => [
                    'source' => 'build_plan_v2.blocks',
                    'contract_id' => (string)($meta['id'] ?? ''),
                    'block_id' => $blockId,
                    'data_contract' => \is_array($outputContract['render_data'] ?? null) ? $outputContract['render_data'] : [],
                    'output_contract' => $outputContract,
                    'acceptance' => $acceptance,
                ],
            ];
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $ledger
     * @return array<string, array{items:array<string,array<string,mixed>>,updated_at:string}>
     */
    private function normalizeRetryableAiFailureLedger(array $ledger): array
    {
        $normalized = [];
        foreach ($ledger as $operation => $bucket) {
            $operation = \trim((string)$operation);
            if ($operation === '' || !\is_array($bucket)) {
                continue;
            }
            $items = $this->normalizeRetryableAiFailureItems(
                $operation,
                \is_array($bucket['items'] ?? null) ? $bucket['items'] : []
            );
            if ($items === []) {
                continue;
            }
            $normalized[$operation] = [
                'items' => $items,
                'updated_at' => (string)($bucket['updated_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>>|array<string, array<string, mixed>> $failures
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRetryableAiFailureItems(string $operation, array $failures): array
    {
        $items = [];
        foreach ($failures as $key => $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $itemKey = \trim((string)($failure['item_key'] ?? $failure['key'] ?? (\is_string($key) ? $key : '')));
            if ($itemKey === '') {
                continue;
            }
            $message = $this->sanitizeBuildTaskFailureMessageForView((string)($failure['message'] ?? $failure['error'] ?? ''));
            $failureForView = $failure;
            foreach (['message', 'error', 'error_message', 'failure_reason', 'reason'] as $messageKey) {
                if (!isset($failureForView[$messageKey]) || !\is_scalar($failureForView[$messageKey])) {
                    continue;
                }
                $failureForView[$messageKey] = $this->sanitizeBuildTaskFailureMessageForView((string)$failureForView[$messageKey], $message);
            }
            $items[$itemKey] = \array_replace([
                'operation' => $operation,
                'item_key' => $itemKey,
                'item_type' => (string)($failure['item_type'] ?? 'ai_item'),
                'retry_scope' => (string)($failure['retry_scope'] ?? $operation),
                'message' => $message !== '' ? $message : 'AI generation failed.',
                'failed_at' => (string)($failure['failed_at'] ?? \date('Y-m-d H:i:s')),
            ], $failureForView, [
                'operation' => \trim((string)($failure['operation'] ?? $operation)),
                'item_key' => $itemKey,
                'message' => $message !== '' ? $message : 'AI generation failed.',
            ]);
        }

        return $items;
    }

    private function sanitizeBuildTaskFailureMessageForView(string $message, string $fallback = 'Build task failed.'): string
    {
        $message = \trim((string)(\preg_replace('/\s+/u', ' ', $message) ?? $message));
        $fallback = \trim($fallback);
        if ($message === '') {
            return $fallback;
        }

        $lower = \mb_strtolower($message, 'UTF-8');
        if (\str_contains($lower, 'required_image_asset_unresolved')
            || \str_contains($lower, 'inline block image generation failed')
            || \str_contains($lower, 'image generation failed')
            || \str_contains($lower, 'vectorengine')
            || \str_contains($lower, 'generatecontent')
            || \str_contains($lower, 'chat pre-consumed quota')
            || \str_contains($lower, 'user quota')
            || \str_contains($lower, 'need quota')
        ) {
            return 'Image generation is temporarily unavailable. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'openssl')
            || \str_contains($lower, 'ssl_read')
            || \str_contains($lower, 'curl')
            || \str_contains($lower, 'operation timed out')
            || \str_contains($lower, 'operation too slow')
            || \str_contains($lower, 'timed out after')
        ) {
            return 'AI generation timed out. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'contract findings')
            || \str_contains($lower, 'hard policy')
            || \str_contains($lower, 'quality gate failed')
            || \str_contains($lower, 'quality gate did not')
            || \str_contains($lower, 'component contract')
        ) {
            return 'AI output did not pass the section quality gate. The section will need another generation attempt.';
        }

        if ((\preg_match('/https?:\\/\\//i', $message) === 1)
            || (\preg_match('/\\brequest\\s*id\\b/i', $message) === 1)
            || (\preg_match('/\\bHTTP\\s*:?\\s*\\d{3}\\b/i', $message) === 1)
            || (\preg_match('/\\b[A-Za-z_]+Exception\\b/', $message) === 1)
        ) {
            return $fallback !== '' ? $fallback : 'AI generation failed. The section will need another generation attempt.';
        }

        return \mb_substr($message, 0, 320, 'UTF-8');
    }

    /**
     * @param array<string, array{items:array<string,array<string,mixed>>,updated_at:string}> $ledger
     */
    private function countRetryableAiFailuresFromLedger(array $ledger): int
    {
        $count = 0;
        foreach ($ledger as $bucket) {
            $count += \count(\is_array($bucket['items'] ?? null) ? $bucket['items'] : []);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function extractTaskState(array $scope): array
    {
        $sanitized = [];
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        foreach (['header', 'footer'] as $region) {
            $row = \is_array($contract['shared_execution'][$region] ?? null) ? $contract['shared_execution'][$region] : [];
            $taskKey = 'shared:' . $region;
            $sanitized[$taskKey] = $this->sanitizeBuildTaskStateRow(\array_replace(['task_key' => $taskKey], $row), $taskKey);
        }
        foreach ($this->normalizeBuildPlanRecordSet($contract['blocks'] ?? [], ['block_id', 'id']) as $blockId => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $taskKey = $this->buildTaskKeyForPlanBlock($block, (string)$blockId, $contract);
            if ($taskKey === '') {
                continue;
            }
            $row = \is_array($block['execution'] ?? null) ? $block['execution'] : [];
            $sanitized[$taskKey] = $this->sanitizeBuildTaskStateRow(\array_replace(['task_key' => $taskKey], $row), $taskKey);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function setTaskState(array $scope, string $taskKey, array $patch, bool $bumpAttempt): array
    {
        $taskKey = \trim($taskKey);
        if ($taskKey === '') {
            return $scope;
        }
        $states = $this->extractTaskState($scope);
        $existing = \is_array($states[$taskKey] ?? null) ? $states[$taskKey] : [
            'task_key' => $taskKey,
            'attempt_no' => 0,
        ];
        if ($bumpAttempt) {
            $patch['attempt_no'] = \max((int)($existing['attempt_no'] ?? 0), 0) + 1;
        }
        $next = $this->sanitizeBuildTaskStateRow(\array_replace($existing, $patch), $taskKey);
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if (\str_starts_with($taskKey, 'shared:')) {
            $region = \trim(\substr($taskKey, 7));
            if ($region !== '') {
                $shared = \is_array($contract['shared_execution'] ?? null) ? $contract['shared_execution'] : [];
                $shared[$region] = $next;
                $contract['shared_execution'] = $shared;
                $scope['build_plan_v2'] = $contract;
            }

            return $this->attachBuildPlanExecutionSummary($scope);
        }

        $blocks = \is_array($contract['blocks'] ?? null) ? $contract['blocks'] : [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? (\is_string($index) ? $index : '')));
            if ($this->buildTaskKeyForPlanBlock($block, $blockId, $contract) !== $taskKey) {
                continue;
            }
            $block['execution'] = $next;
            $blocks[$index] = $block;
            $contract['blocks'] = $blocks;
            $scope['build_plan_v2'] = $contract;
            return $this->attachBuildPlanExecutionSummary($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function ensureBuildPlanBlockExecutionState(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return $scope;
        }

        $now = \date('Y-m-d H:i:s');
        $shared = \is_array($contract['shared_execution'] ?? null) ? $contract['shared_execution'] : [];
        foreach (['header', 'footer'] as $region) {
            $taskKey = 'shared:' . $region;
            $shared[$region] = $this->sanitizeBuildTaskStateRow(\array_replace([
                'task_key' => $taskKey,
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => '',
                'started_at' => '',
                'finished_at' => '',
            ], \is_array($shared[$region] ?? null) ? $shared[$region] : []), $taskKey);
        }
        $contract['shared_execution'] = $shared;

        $blocks = \is_array($contract['blocks'] ?? null) ? $contract['blocks'] : [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? (\is_string($index) ? $index : '')));
            $taskKey = $this->buildTaskKeyForPlanBlock($block, $blockId, $contract);
            if ($taskKey === '') {
                continue;
            }
            $existing = \is_array($block['execution'] ?? null) ? $block['execution'] : [];
            $block['execution'] = $this->sanitizeBuildTaskStateRow(\array_replace([
                'task_key' => $taskKey,
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => $now,
                'started_at' => '',
                'finished_at' => '',
            ], $existing), $taskKey);
            $blocks[$index] = $block;
        }
        $contract['blocks'] = $blocks;
        $scope['build_plan_v2'] = $contract;

        return $this->attachBuildPlanExecutionSummary($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function attachBuildPlanExecutionSummary(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return $scope;
        }
        $summary = $this->summarize($scope);
        $contract['execution_summary'] = [
            'total' => (int)($summary['total'] ?? 0),
            'done' => (int)($summary['done'] ?? 0),
            'pending' => (int)($summary['pending'] ?? 0),
            'running' => (int)($summary['running'] ?? 0),
            'failed' => (int)($summary['failed'] ?? 0),
            'cancelled' => (int)($summary['cancelled'] ?? 0),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        $scope['build_plan_v2'] = $contract;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resetBuildPlanExecutionRows(array $scope): array
    {
        $contract = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($contract === []) {
            return $scope;
        }
        unset($contract['execution_summary'], $contract['shared_execution']);
        if (\is_array($contract['blocks'] ?? null)) {
            foreach ($contract['blocks'] as $index => $block) {
                if (\is_array($block)) {
                    unset($block['execution']);
                    $contract['blocks'][$index] = $block;
                }
            }
        }
        $scope['build_plan_v2'] = $contract;

        return $scope;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $contract
     */
    private function buildTaskKeyForPlanBlock(array $block, string $blockId, array $contract): string
    {
        $pageId = \trim((string)($block['page_id'] ?? ''));
        $pagesById = $this->normalizeBuildPlanRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
        $page = \is_array($pagesById[$pageId] ?? null) ? $pagesById[$pageId] : [];
        $pageType = \trim((string)($block['page_type'] ?? $page['page_type'] ?? ''));
        if ($pageType === '') {
            return '';
        }
        $sectionKey = \trim((string)($block['section_key'] ?? ''));
        if ($sectionKey === '' && $blockId !== '') {
            $parts = \explode('.', $blockId);
            $sectionKey = (string)\end($parts);
        }
        $sectionCode = $this->resolveBuildPlanSectionCode($pageType, $sectionKey, $blockId);
        if ($sectionCode === '') {
            return '';
        }

        return 'page:' . $pageType . ':' . $sectionCode;
    }

    /**
     * @param list<string> $contentKeys
     * @return array<string, mixed>
     */
    private function buildPlanExecutionOutputContract(string $componentType, array $contentKeys): array
    {
        return [
            'format' => 'pagebuilder_php_component',
            'component_type' => $componentType,
            'required_outputs' => ['html', 'css_extra', 'default_config'],
            'render_data' => [
                'content_keys' => $contentKeys,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanExecutionAcceptanceContract(string $componentType): array
    {
        return [
            'definition_of_done' => 'Generate one complete visitor-facing ' . $componentType . ' block from the confirmed plan block.',
            'checks' => ['valid_json', 'visitor_visible_html', 'responsive_layout'],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     */
    private function isGeneratedArtifactAvailableForTask(array $scope, array $task, bool $allowActiveRegenerationArtifacts = false): bool
    {
        $activeRegeneration = $this->isActiveBuildRegeneration($scope);
        if ($activeRegeneration && !$allowActiveRegenerationArtifacts) {
            return false;
        }

        $taskType = \trim((string)($task['task_type'] ?? ''));
        if ($taskType === 'shared_component') {
            $region = \trim((string)($task['region'] ?? ''));
            $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
            $sharedComponent = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
            $componentCode = $this->resolveSharedComponentCodeForArtifactCheck($region, $task, $sharedComponent);
            if ($activeRegeneration) {
                if ($region === '' || !$this->isBuiltSharedComponentArtifact($sharedComponent)) {
                    return false;
                }

                $payload = \json_encode($sharedComponent, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (\is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload)) {
                    return false;
                }

                return $componentCode === '' || !$this->virtualThemeComponentHasPromptTrace($scope, $componentCode);
            }
            if (
                $region !== ''
                && $componentCode !== ''
                && $this->requiresPersistedVirtualThemeLayoutCheck($scope)
                && $this->virtualThemeComponentArtifactAvailable($scope, $componentCode)
            ) {
                return true;
            }
            if ($region === '' || !$this->isBuiltSharedComponentArtifact($sharedComponent)) {
                return false;
            }

            $payload = \json_encode($sharedComponent, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (\is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload)) {
                return false;
            }

            return $componentCode === '' || !$this->virtualThemeComponentHasPromptTrace($scope, $componentCode);
        }

        if ($taskType !== 'page_section') {
            return false;
        }

        $pageType = \trim((string)($task['page_type'] ?? ''));
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($pageType === '' || $sectionCode === '') {
            return false;
        }
        if ($this->materializedAiHtmlPageHasPromptTrace($scope, $pageType)) {
            return false;
        }

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
        $layoutSection = $this->findLayoutSectionByCode($layout, $sectionCode);
        if ($layoutSection !== null) {
            if (
                $this->arrayContainsGeneratedArtifactPromptTrace($layoutSection)
                || $this->virtualThemeComponentHasPromptTrace($scope, $sectionCode)
            ) {
                return false;
            }

            if ($this->requiresPersistedVirtualThemeLayoutCheck($scope)) {
                return $this->persistedVirtualThemeLayoutContainsSectionCode($scope, $pageType, $sectionCode);
            }

            return true;
        }
        if ($activeRegeneration) {
            return false;
        }
        if ($this->persistedVirtualThemeLayoutContainsSectionCode($scope, $pageType, $sectionCode)) {
            return !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
        }

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        return $this->virtualPageContainsSectionCode($virtualPage, $sectionCode)
            && !$this->arrayContainsGeneratedArtifactPromptTrace($virtualPage)
            && !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
    }

    /**
     * In virtual-theme workspaces, in-memory scope is not enough: the preview,
     * publish checklist, and final materialization read the saved theme layout.
     */
    private function requiresPersistedVirtualThemeLayoutCheck(array $scope): bool
    {
        return (int)($scope['virtual_theme_id'] ?? 0) > 0
            && AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME === (string)($scope['workspace_track'] ?? '');
    }

    /**
     * During a forced rebuild, persisted virtual-theme rows belong to the old
     * generation until the current scope records the regenerated artifact.
     *
     * @param array<string, mixed> $scope
     */
    private function isActiveBuildRegeneration(array $scope): bool
    {
        $regeneration = \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [];
        return (int)($regeneration['active'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function virtualThemeComponentArtifactAvailable(array $scope, string $componentCode): bool
    {
        $artifact = $this->loadVirtualThemeComponentArtifact($scope, $componentCode);
        if ($artifact === [] || \trim((string)($artifact['template_content'] ?? '')) === '') {
            return false;
        }

        $payload = \json_encode($artifact, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return !\is_string($payload) || !$this->containsGeneratedArtifactPromptTrace($payload);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function loadVirtualThemeComponentArtifactPayload(array $scope, string $componentCode): string
    {
        $artifact = $this->loadVirtualThemeComponentArtifact($scope, $componentCode);

        return \trim((string)($artifact['template_content'] ?? ''));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{template_content:string, default_config:array<string,mixed>}|array{}
     */
    private function loadVirtualThemeComponentArtifact(array $scope, string $componentCode): array
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        $componentCode = \trim($componentCode);
        if ($virtualThemeId <= 0 || $componentCode === '') {
            return [];
        }

        try {
            /** @var VirtualThemeComponent $component */
            $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
            $component->clearData()->clearQuery()
                ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
                ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
                ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
                ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
                ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            if ((int)$component->getId() <= 0) {
                return [];
            }

            return [
                'template_content' => $component->getTemplateContent(),
                'default_config' => $component->getDefaultConfig(),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function virtualThemeComponentHasPromptTrace(array $scope, string $componentCode): bool
    {
        $artifact = $this->loadVirtualThemeComponentArtifact($scope, $componentCode);
        if ($artifact === []) {
            return false;
        }

        $payload = \json_encode($artifact, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload);
    }

    private function containsGeneratedArtifactPromptTrace(string $payload): bool
    {
        foreach (self::GENERATED_ARTIFACT_PROMPT_TRACE_MARKERS as $marker) {
            if ($marker !== '' && \stripos($payload, $marker) !== false) {
                return true;
            }
        }

        if ($this->containsGeneratedArtifactVisibleHtmlLeak($payload)) {
            return true;
        }

        return false;
    }

    private function containsGeneratedArtifactVisibleHtmlLeak(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        // Valid templates contain raw HTML tags. Only escaped tags or malformed
        // numeric tags are visitor-visible leakage and must invalidate artifacts.
        if (\preg_match('/&lt;\s*\/?\s*[a-z][a-z0-9:-]*[^&\n]{0,160}(?:class\s*=|&gt;)/iu', $payload) === 1) {
            return true;
        }
        if ($this->containsGeneratedArtifactMalformedNumericTag($payload)) {
            return true;
        }
        if ($this->containsGeneratedArtifactMalformedCss($payload)) {
            return true;
        }
        if (\preg_match('/\bbox-sizing\s*:\s*border\s*(?:[;}])/i', $payload) === 1) {
            return true;
        }
        if (\preg_match('/\$isActive\s*=\s*\$index\s*===\s*0\s*;/u', $payload) === 1) {
            return true;
        }
        if (\preg_match('/"brand\.logo"\s*:\s*"[^"]+\/"/iu', $payload) === 1) {
            return true;
        }
        if ($this->containsGeneratedArtifactDuplicateHeroMediaPlaceholder($payload)) {
            return true;
        }

        return false;
    }

    private function containsGeneratedArtifactMalformedNumericTag(string $payload): bool
    {
        if (\preg_match_all('/<\s*\/?\s*[0-9][^>\n]{0,160}>/u', $payload, $matches) < 1) {
            return false;
        }

        foreach ($matches[0] ?? [] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if (\preg_match('/(?:<\?|\?>|[\'"`$,]|ENT_QUOTES|htmlspecialchars)/iu', $candidate) === 1) {
                continue;
            }
            if (\preg_match('/^<\s*\/?\s*[0-9][a-z0-9:-]*\s*\/?\s*>$/iu', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsGeneratedArtifactMalformedCss(string $payload): bool
    {
        $property = '(?:position|inset|overflow|display|width|min-width|max-width|height|min-height|max-height|z-index|opacity|background(?:-image)?|color|border(?:-radius|-color)?|box-shadow|font(?:-size|weight|family)?|line-height|padding|margin|flex(?:-direction|-wrap|-grow|-shrink|-basis)?|grid-template-columns|gap|align-items|justify-content|object-fit|object-position|box-sizing|text-decoration|cursor|outline)';

        return \preg_match('/(?:\d+(?:\.\d+)?(?:px|rem|em|vh|vw|%)|#[0-9a-f]{3,8})(?=\s*' . $property . '\s*:)/i', $payload) === 1
            || \preg_match('/\b' . $property . '\s*:\s*(?:\.{1,3}|[,+-])\s*(?:[;}])/i', $payload) === 1;
    }

    private function containsGeneratedArtifactDuplicateHeroMediaPlaceholder(string $payload): bool
    {
        $payload = \str_replace(['\"', "\\'"], ['"', "'"], $payload);

        return \preg_match(
            '/<img\b(?=[^>]*\bdata-pb-ai-image-role\s*=\s*(["\'])generated-asset\1)[^>]*>[\s\S]{0,800}<div\b(?=[^>]*\bclass\s*=\s*(["\'])[^"\']*media[^"\']*\2)[^>]*>\s*<\/div>/iu',
            $payload
        ) === 1;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function materializedAiHtmlPageHasPromptTrace(array $scope, string $pageType): bool
    {
        $pageId = $this->resolveMaterializedPageIdForArtifactCheck($scope, $pageType);
        if ($pageId <= 0 && (int)($scope['website_id'] ?? $scope['draft_website_id'] ?? 0) <= 0) {
            return false;
        }

        try {
            /** @var Page $page */
            $page = clone ObjectManager::getInstance(Page::class);
            $page->clearData()->clearQuery();
            if ($pageId > 0) {
                $page->load($pageId);
            } else {
                $websiteId = (int)($scope['website_id'] ?? $scope['draft_website_id'] ?? 0);
                $page->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Page::schema_fields_TYPE, $pageType)
                    ->order(Page::schema_fields_ID, 'DESC')
                    ->find()
                    ->fetch();
            }
            if ((int)$page->getId() <= 0) {
                return false;
            }

            $renderMode = \trim((string)$page->getData(Page::schema_fields_RENDER_MODE));
            $aiLayout = (string)$page->getData(Page::schema_fields_AI_LAYOUT);
            if ($renderMode !== Page::RENDER_MODE_AI_HTML && \trim($aiLayout) === '') {
                return false;
            }

            return $this->containsGeneratedArtifactPromptTrace($aiLayout);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveMaterializedPageIdForArtifactCheck(array $scope, string $pageType): int
    {
        $pagesByType = \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [];
        $pageMeta = \is_array($pagesByType[$pageType] ?? null) ? $pagesByType[$pageType] : [];
        $pageId = (int)($pageMeta['page_id'] ?? $pageMeta['materialized_page_id'] ?? 0);
        if ($pageId > 0) {
            return $pageId;
        }

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];

        return (int)($virtualPage['materialized_page_id'] ?? $virtualPage['page_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function arrayContainsGeneratedArtifactPromptTrace(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($encoded) && $this->containsGeneratedArtifactPromptTrace($encoded);
    }

    /**
     * Stage-1 plan_json.shared_components only carries goals/contracts; stage-2 must ship html/phtml.
     *
     * @param array<string, mixed> $sharedComponent
     */
    private function isBuiltSharedComponentArtifact(array $sharedComponent): bool
    {
        if ($sharedComponent === []) {
            return false;
        }

        $html = \trim((string)($sharedComponent['html'] ?? ''));
        $phtml = \trim((string)($sharedComponent['phtml'] ?? ''));
        if ($html === '' && $phtml === '') {
            return false;
        }

        $code = \trim((string)($sharedComponent['code'] ?? $sharedComponent['component_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $rendered = $html !== '' ? $html : $phtml;

        return !$this->containsGeneratedArtifactPromptTrace($rendered);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $sharedComponent
     */
    private function resolveSharedComponentCodeForArtifactCheck(string $region, array $task, array $sharedComponent): string
    {
        foreach ([
            $sharedComponent['code'] ?? null,
            $sharedComponent['component_code'] ?? null,
            $sharedComponent['section_code'] ?? null,
            $task['component_code'] ?? null,
            $task['section_code'] ?? null,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return match ($region) {
            'header' => 'header/ai-site-header',
            'footer' => 'footer/ai-site-footer',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildTaskResultRefFromDefinition(array $task): array
    {
        $taskType = \trim((string)($task['task_type'] ?? ''));
        if ($taskType === 'shared_component') {
            return ['region' => \trim((string)($task['region'] ?? ''))];
        }

        return [
            'page_type' => \trim((string)($task['page_type'] ?? '')),
            'section_code' => \trim((string)($task['section_code'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function layoutContainsSectionCode(array $layout, string $sectionCode): bool
    {
        return $this->findLayoutSectionByCode($layout, $sectionCode) !== null;
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>|null
     */
    private function findLayoutSectionByCode(array $layout, string $sectionCode): ?array
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        foreach ($content as $section) {
            if (!\is_array($section)) {
                continue;
            }
            foreach (['code', 'component', 'section_code'] as $key) {
                if ($this->sectionIdentityMatches((string)($section[$key] ?? ''), $sectionCode)) {
                    return $section;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function layoutHasContentBlocks(array $layout): bool
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        foreach ($content as $section) {
            if (!\is_array($section)) {
                continue;
            }
            foreach (['code', 'component', 'section_code'] as $key) {
                if (\trim((string)($section[$key] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistedVirtualThemeLayoutContainsSectionCode(array $scope, string $pageType, string $sectionCode): bool
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0 || $pageType === '' || $sectionCode === '') {
            return false;
        }

        try {
            /** @var VirtualThemeLayout $layout */
            $layout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
            $layout->clearData()->clearQuery();
            $layout->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
                ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
                ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            $config = $layout->getConfig();
            return $layout->getId() > 0
                && $this->layoutContainsSectionCode($config, $sectionCode)
                && !$this->arrayContainsGeneratedArtifactPromptTrace($config);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistedVirtualThemeLayoutHasContent(array $scope, string $pageType): bool
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0 || $pageType === '') {
            return false;
        }

        try {
            /** @var VirtualThemeLayout $layout */
            $layout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
            $layout->clearData()->clearQuery();
            $layout->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
                ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
                ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            $config = $layout->getConfig();
            return $layout->getId() > 0
                && \is_array($config)
                && $this->layoutHasContentBlocks($config)
                && !$this->arrayContainsGeneratedArtifactPromptTrace($config);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $virtualPage
     */
    private function virtualPageContainsSectionCode(array $virtualPage, string $sectionCode): bool
    {
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (['section_code', 'code', 'block_code', 'component', 'component_code'] as $key) {
                if ($this->sectionIdentityMatches((string)($block[$key] ?? ''), $sectionCode)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sectionIdentityMatches(string $candidate, string $sectionCode): bool
    {
        $candidate = \trim($candidate);
        $sectionCode = \trim($sectionCode);
        if ($candidate === '' || $sectionCode === '') {
            return false;
        }
        if ($candidate === $sectionCode) {
            return true;
        }

        $left = $this->sectionIdentityCandidates($candidate);
        $right = $this->sectionIdentityCandidates($sectionCode);
        foreach (\array_keys($left) as $value) {
            if (isset($right[$value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function sectionIdentityCandidates(string $value): array
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return [];
        }

        $normalized = (string)\preg_replace('/-+/u', '-', \str_replace(['\\', '/', '_'], '-', $value));
        $normalized = \trim($normalized, '-');
        if ($normalized === '') {
            return [];
        }

        $candidates = [$normalized => true];
        if (\str_starts_with($normalized, 'content-')) {
            $withoutPrefix = \trim(\substr($normalized, 8), '-');
            if ($withoutPrefix !== '') {
                $candidates[$withoutPrefix] = true;
            }
        }

        return $candidates;
    }

    private function normalizeTaskStatus(string $status): string
    {
        return \in_array($status, [
            self::TASK_STATUS_PENDING,
            self::TASK_STATUS_RUNNING,
            self::TASK_STATUS_DONE,
            self::TASK_STATUS_FAILED,
            self::TASK_STATUS_CANCELLED,
        ], true) ? $status : self::TASK_STATUS_PENDING;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, array<string, mixed>>
     */
    private function buildTaskLookup(array $tasks): array
    {
        $lookup = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $lookup[$taskKey] = $task;
        }

        return $lookup;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @return array<string, array<string, mixed>>
     */
    private function buildPageTaskLookup(array $pageTasks): array
    {
        $lookup = [];
        foreach ($pageTasks as $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $lookup[$taskKey] = $task;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveSharedRegionFromTask(string $taskKey, array $meta): string
    {
        $region = \trim((string)($meta['region'] ?? ''));
        if ($region !== '') {
            return $region;
        }
        if (\str_starts_with($taskKey, 'shared:')) {
            return \trim(\substr($taskKey, 7));
        }

        return 'shared';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveSectionCodeFromTask(string $taskKey, array $meta): string
    {
        $sectionCode = \trim((string)($meta['section_code'] ?? ''));
        $sectionKey = \trim((string)($meta['section_key'] ?? $meta['block_key'] ?? ''));
        $taskKeyTail = '';
        if (\preg_match('/^[^:]+:[^:]+:(.+)$/', $taskKey, $matches) === 1) {
            $taskKeyTail = \trim((string)($matches[1] ?? ''));
        }

        if ($sectionCode !== '' && !\in_array(\strtolower($sectionCode), ['section', 'content', 'block'], true)) {
            return $sectionCode;
        }
        if ($sectionKey !== '') {
            return $sectionKey;
        }
        if ($taskKeyTail !== '') {
            return $taskKeyTail;
        }

        return \trim((string)($meta['block_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveScopeKeyForTask(string $pageType, string $region, string $sectionCode, array $meta): string
    {
        $scopeKey = \trim((string)($meta['scope_key'] ?? ''));
        if ($scopeKey !== '') {
            return $scopeKey;
        }
        if ($pageType === '') {
            return 'shared_components.' . ($region !== '' ? $region : 'shared');
        }
        if ($sectionCode !== '') {
            return 'page_sections.' . $pageType . '.' . $sectionCode;
        }

        return 'page_sections.' . $pageType;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeBuildTaskStateRow(array $row, string $taskKey): array
    {
        foreach (self::BUILD_TASK_STATE_DUPLICATE_KEYS as $key => $_) {
            unset($row[$key]);
        }

        $row['task_key'] = $taskKey !== '' ? $taskKey : (string)($row['task_key'] ?? '');
        if (isset($row['message']) && !\is_scalar($row['message'])) {
            $row['message'] = '';
        }
        if (isset($row['result_ref']) && !\is_array($row['result_ref'])) {
            $row['result_ref'] = [];
        }

        return $row;
    }
}
