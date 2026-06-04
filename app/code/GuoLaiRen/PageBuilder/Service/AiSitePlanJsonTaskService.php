<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use Weline\Framework\Manager\ObjectManager;

class AiSitePlanJsonTaskService
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
    ];

    /**
     * 婵犵數濮烽弫鍛婃叏閻戝鈧倿顢欓悙顒夋綗闂佸搫娲㈤崹鍦婵犳碍鐓欓弶鍫濆⒔閻ｈ京鐥幑鎰垫綈濞ｅ洤锕俊鍫曞川椤斿吋顏犻梻浣告惈椤戝嫮娆㈠璺鸿摕婵炴垶菤濡插牊鎱ㄥΔ鈧Λ娑㈠矗閺囩偐鏀?rollup闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍏煎€绘俊顖濆吹閸欏棝姊洪崫鍕靛剰闁绘绻橀崺鈧い鎺嗗亾缂佺姴绉瑰畷鏇㈠础閻忕粯妞介幃鈺冩嫚閼碱剨绱?page_type 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻锝夊箣閿濆憛鎾绘煕閵堝懎顏柡灞剧洴椤㈡洟鏁愰崱娆樻К缂傚倷鐒﹂崝鏍€冩繝鍥ц摕闁跨喓濮撮悙濠囨煏婢跺牆鍔ら柛鏃€鎸冲鐑樻姜閹殿噮妲┑鐐叉▕閸欏啫顕ｆ繝姘亜闁稿繐鐨烽幏濠氭煟鎼淬劍娑у鐟帮工鍗辨い鏇楀亾婵﹦绮幏鍛村棘閵堝宕梻浣侯焾閿曘儵鎮уΔ鍐煔閺夊牄鍔庣弧鈧梺鎼炲労閻忔稖顦归柡灞剧☉閳藉宕￠悙鑼啈婵犵數鍋涢悧鍡涒€﹀畡閭︽綎闁惧繐婀辩壕鍏间繆椤栨氨姣炲┑顔兼喘濮婃椽鎮烽悧鍫濇殘闂佽鍠栭崐鎼佹晝閵忥紕鐟归柍褜鍓欓～蹇涙惞閸︻厾锛滃┑鈽嗗灠缁绘宕戦幇鏉跨闁告劦鍠楅弲婵嬫煕鐏炴崘澹橀柟顖滃仜閳规垿鎮欓崣澶樻缂備浇顕уΛ婵嗩嚕閵娾晜鍤嶉柕澶涚导缁ㄥ姊洪崫鍕殜闁稿鎹囬弻娑欐償閵忕姭鏋欓悗娈垮枟閹倸顕ｉ鈧畷濂告偄閸濆嫬绗氶梺鑽ゅ枑缁秶鍒掗幘宕囨殾婵犲﹤鍟犲Σ鍫ユ煏韫囨洖啸闁汇倕娲铏规喆閸曨偆顦ㄥ銈嗘肠閸涱亜浜炬慨姗嗗墻濡插綊鏌曢崶褍顏鐐村浮楠炲鈹戦幇顏呭亝闂傚倷鐒﹂幃鍫曞礉瀹€鍕９鐟滅増甯掔粻鐐烘煏婵炲灝鍓婚柣鏃傚帶缁犱即骞栨潏鍓хシ闁逞屽墯閸旀妲愰幘瀛樺闁告繂瀚呴敐鍥ｅ亾閸忓浜鹃梺褰掓？缁€渚€鎷戦悢鍝ョ闁瑰鍊戝顑╋綁宕奸妷锔惧幈濠德板€曢崯顐ｇ濠婂懐纾奸柣妯垮皺缁夌儤鎱ㄦ繝鍐┿仢鐎规洦鍋婂畷鐔兼濞戞ê顥嶉梻鍌欑劍濡炲潡宕㈡總绋跨９闁割煈鍣崵鏇炩攽閻樺疇澹橀幆鐔兼⒑闂堟侗妲堕柛銊︽そ閿濈偛顓奸崨顏呮杸闂佺粯鍔曞鍫曀夐悙鐑樼厱闁靛ě鍐╃€婚柛妤呬憾閺屾盯顢曢悩鎻掑闂佺顑傞崜婵堟崲濠靛洨绡€闁稿本鍑规禒鍓х磽娴ｇ懓鍔堕悘蹇旂懇閸┾偓妞ゆ帊绶￠崯蹇涙煕閻樺磭澧柡鍛板煐閹棃鏁愰崨顓犱喊?skip_remaining_blocks 闂傚倸鍊搁崐鎼佸磹閹间礁纾圭€瑰嫭鍣磋ぐ鎺戠倞闁靛ě鍛獎闂備礁澹婇崑鍡涘窗閸℃顩烽柛顐犲劜閻撴瑩姊婚崒姘煎殶妞わ讣绠撻弻锕傚礃椤忓嫭鐏堥梺鍝勬湰濞叉鎹㈠┑濠勭杸婵炴垶鐟埀顒€绉瑰娲川婵犲嫭鍣х紓浣虹帛閿曘垹顕ｇ拠宸悑濠㈣泛锕ｇ槐鍫曟⒑閸涘﹥澶勯柛瀣噹閳绘捇寮婚妷锕€鈧敻鎮峰▎蹇擃仾缂佲偓閳ь剙顪冮妶蹇擃洭闁轰礁顭烽悰顕€宕橀妸搴㈡瀹曘劑顢橀悢椋庛偠濠碉紕鍋戦崐鏍箰妤ｅ啫纾规い鎰剁畱鍞悷婊冪箳婢规洘绺介崨濠勫幗濠碘槅鍨靛▍锝夋晬瀹ュ拋鐔嗙憸蹇涘极婵犳艾钃熼柨娑樺濞岊亪鏌涢幘妞诲亾婵℃彃鐗嗛—鍐Χ鎼粹€茬凹缂備浇顕ч悧鎾诲Υ娴ｈ倽鏃€鎷呴悷閭︹偓鎾绘⒑閼姐倕鏋嶉柛妤€鍟胯灋闁绘垼濮ら埛?section闂?     *
     * @see self::rollupBuildPageProgressForPageType()
     */
    public const BUILD_PAGE_PROGRESS_SCOPE_KEY = '_build_page_progress';

    public const TASK_STATUS_PENDING = 'pending';
    public const TASK_STATUS_RUNNING = 'running';
    public const TASK_STATUS_DONE = 'done';
    public const TASK_STATUS_FAILED = 'failed';
    public const TASK_STATUS_CANCELLED = 'cancelled';
    private const PLAN_BLOCK_STATUS_PENDING = 0;
    private const PLAN_BLOCK_STATUS_RUNNING = 2;
    private const PLAN_BLOCK_STATUS_DONE = 1;
    private const PLAN_BLOCK_STATUS_FAILED = -1;
    private const PLAN_JSON_TASK_MAX_AUTOMATIC_ATTEMPTS = 3;
    public const RETRYABLE_AI_FAILURES_SCOPE_KEY = 'retryable_ai_failures';
    private const BUILD_LOCKED_PLAN_SCOPE_KEYS = [
        'page_types',
        'page_types_user_customized',
        'plan_json',
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
        'content_manifest',
    ];
    /**
     * Duplicate task definition fields are removed before persisting block
     * execution state back to plan_json.pages.{page_type}.{block_key}.
     *
     * @var array<string, true>
     */
    private const PLAN_JSON_TASK_STATE_DUPLICATE_KEYS = [
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

    /** @var array<string, true> */
    private const PLAN_JSON_PAGE_META_KEYS = [
        'page_key' => true,
        'page_type' => true,
        'type' => true,
        'status' => true,
        'message' => true,
        'error' => true,
        'error_message' => true,
        'updated_at' => true,
        'started_at' => true,
        'finished_at' => true,
        'attempt_no' => true,
        'result_ref' => true,
        'title' => true,
        'label' => true,
        'page_label' => true,
        'page_title' => true,
        'page_goal' => true,
        'page_status' => true,
        'content_locale' => true,
        'shared_context_hash' => true,
        'theme_context_hash' => true,
        'assembly_version' => true,
        'generation_method' => true,
        'page_design_plan' => true,
        'theme_alignment_summary' => true,
        'page_context_hash' => true,
        'block_nodes' => true,
        'ordered_block_keys' => true,
        'seo' => true,
        'meta_title' => true,
        'meta_description' => true,
        'meta_keywords' => true,
        'route' => true,
        'slug' => true,
        'path' => true,
        'layout' => true,
        'sections' => true,
        'section_refinements' => true,
        'content' => true,
        'description' => true,
        'summary' => true,
        'html' => true,
        'html_content' => true,
        'fields' => true,
    ];

    private readonly AiSitePlanJsonStateService $planJsonStateService;

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
        ?AiSitePlanJsonStateService $planJsonStateService = null,
    ) {
        $this->planJsonStateService = $planJsonStateService ?? new AiSitePlanJsonStateService();
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function ensureTaskScope(array $scope, array $websiteProfile, string $workspaceTrack): array
    {
        unset($websiteProfile, $workspaceTrack);
        $scope = $this->normalizePlanJsonConfirmedState($scope);
        $validation = $this->validatePlanJsonPagesForBuild($scope);
        if (!($validation['valid'] ?? false)) {
            return $this->markPlanJsonExecutionBlocked($scope, $validation);
        }

        return $this->ensurePlanJsonBlockExecutionState($scope);
    }

    /**
     * Reset plan_json.pages block status nodes to pending for a forced rebuild.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetPlanJsonTasksToPendingForRebuild(array $scope, bool $reuseAvailableArtifacts = true): array
    {
        $scope = $this->ensurePlanJsonBlockExecutionState($scope);
        $tasks = $this->extractPlanJsonTasks($scope);
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
                    : $this->planJsonTaskResultRefFromDefinition($definition);
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
        $scope = $this->resetPlanJsonExecutionRows($scope);

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
    public function hasConfirmedPlanJsonForBuild(array $scope): bool
    {
        return (bool)($this->validatePlanJsonPagesForBuild($scope)['valid'] ?? false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{valid:bool,errors:list<string>}
     */
    private function validatePlanJsonPagesForBuild(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!$this->planJsonStateService->isConfirmed($planJson)) {
            return [
                'valid' => false,
                'errors' => ['PLAN_JSON_NOT_CONFIRMED: plan_json.confirmed is required before build'],
            ];
        }
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        if ($pages === []) {
            return [
                'valid' => false,
                'errors' => ['PLAN_JSON_PAGES_INVALID: plan_json.pages is required before build'],
            ];
        }
        $coverage = $this->inspectConfirmedPlanJsonPageTypeCoverage($scope);
        $missingPages = \is_array($coverage['missing_page_types'] ?? null) ? $coverage['missing_page_types'] : [];
        if ($missingPages !== []) {
            $errors = [];
            $errors[] = 'PLAN_JSON_PAGES_INVALID: plan_json.pages missing selected page_types: ' . \implode(', ', $missingPages);

            return ['valid' => false, 'errors' => $errors];
        }
        $emptyPages = [];
        foreach ($pages as $pageKey => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($pageKey) ? $pageKey : '')));
            if ($pageType === '') {
                continue;
            }
            if ($this->extractPlanJsonPageBlockNodes($page) === []) {
                $emptyPages[] = $pageType;
            }
        }
        if ($emptyPages !== []) {
            return [
                'valid' => false,
                'errors' => ['PLAN_JSON_PAGES_INVALID: plan_json.pages has no block nodes for page_types: ' . \implode(', ', \array_values(\array_unique($emptyPages)))],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function collectMissingSelectedPlanPageTypes(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $expected = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
        if ($expected === []) {
            return [];
        }

        $actual = [];
        foreach ($this->stageOnePlanPageTypeSourceCandidates($scope, $planJson) as $pageSource) {
            $this->collectStageOnePlanPageTypesFromSource($pageSource, $actual);
        }

        return $this->missingStringSet($expected, \array_values(\array_keys($actual)));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return list<mixed>
     */
    private function stageOnePlanPageTypeSourceCandidates(array $scope, array $planJson): array
    {
        unset($scope);

        return [
            $planJson['pages'] ?? null,
        ];
    }

    /**
     * @param array<string, true> $actual
     */
    private function collectStageOnePlanPageTypesFromSource(mixed $pageSource, array &$actual, int $depth = 0): void
    {
        if (!\is_array($pageSource) || $depth > 4) {
            return;
        }

        $directPageType = \trim((string)($pageSource['page_type'] ?? $pageSource['type'] ?? ''));
        if ($directPageType !== '') {
            $actual[$directPageType] = true;
            $this->collectNestedStageOnePlanPageTypeBuckets($pageSource, $actual, $depth);
            return;
        }

        foreach ($pageSource as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
            if ($pageType === '' && \is_string($key) && !\ctype_digit($key)) {
                $pageType = \trim($key);
            }
            if ($pageType !== '') {
                $actual[$pageType] = true;
            }
            $this->collectNestedStageOnePlanPageTypeBuckets($page, $actual, $depth);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, true> $actual
     */
    private function collectNestedStageOnePlanPageTypeBuckets(array $page, array &$actual, int $depth): void
    {
        foreach (['page', 'plan_json_page'] as $wrapperKey) {
            if (\is_array($page[$wrapperKey] ?? null)) {
                $this->collectStageOnePlanPageTypesFromSource($page[$wrapperKey], $actual, $depth + 1);
            }
        }
        foreach (['pages'] as $bucketKey) {
            if (\is_array($page[$bucketKey] ?? null)) {
                $this->collectStageOnePlanPageTypesFromSource($page[$bucketKey], $actual, $depth + 1);
            }
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   expected_page_types:list<string>,
     *   actual_page_types:list<string>,
     *   missing_page_types:list<string>,
     * }
     */
    public function inspectConfirmedPlanJsonPageTypeCoverage(array $scope): array
    {
        $expected = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $actual = [];
        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $key => $page) {
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
    private function markPlanJsonExecutionBlocked(array $scope, array $validation): array
    {
        $scope['plan_json_pages_validation'] = $validation;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizePlanJsonConfirmedState(array $scope): array
    {
        if (!\is_array($scope['plan_json'] ?? null)) {
            return $scope;
        }

        $scope['plan_json'] = $this->planJsonStateService->setConfirmed(
            $scope['plan_json'],
            $this->planJsonStateService->isConfirmed($scope['plan_json'])
        );

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function shouldLockPlanJsonContract(array $scope): bool
    {
        return $this->hasConfirmedPlanJsonForBuild($scope);
    }

    /**
     * Build consumes the confirmed plan_json.pages contract. Request or queue
     * scope_patch must never confirm or rewrite plan/build definitions.
     *
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $currentScope
     * @return array<string, mixed>
     */
    public function stripPlanJsonMutationScopePatch(array $scopePatch, array $currentScope): array
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
     * @return list<string>
     */
    public function planJsonDerivedScopeKeys(): array
    {
        return [
            'plan_json',
            'plan_json_pages_validation',
            'plan_json_task_summary',
            'workspace_track',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function extractPlanJsonDerivedScopePatch(array $scope): array
    {
        $patch = [];
        foreach ($this->planJsonDerivedScopeKeys() as $key) {
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
    public function restorePlanJsonContract(array $scope, array $confirmedScope): array
    {
        if (!$this->shouldLockPlanJsonContract($confirmedScope)) {
            return $scope;
        }

        $lockedKeys = [
            'page_types',
            'page_types_user_customized',
            'plan_json',
            'plan_generated_at',
            'plan_generated_locale',
            'plan_generated_page_types',
            'plan_ai_generated',
            'plan_json_pages_validation',
            'plan_json_task_summary',
            'workspace_track',
        ];
        foreach ($lockedKeys as $key) {
            if (\array_key_exists($key, $confirmedScope)) {
                $scope[$key] = $confirmedScope[$key];
            } else {
                unset($scope[$key]);
            }
        }
        return $this->normalizePlanJsonConfirmedState($scope);
    }

    /**
     * Keep only block-level plan context that prompt assembly actually reads.
     * The full plan_json block node and its execution context are already represented
     * by the executable block fields; duplicating them across every block makes
     * session artifacts large enough to destabilize queue workers.
     *
     * @param array<string, mixed> $planContext
     * @return array<string, mixed>
     */
    private function compactPlanJsonTaskPlanContext(array $planContext): array
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
     * Block prompt context is frozen while plan_json execution rows are built.
     * Later prompt assembly must read these block-level references instead of falling back to
     * broad mutable scope state.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function resolvePlanJsonStage2RuntimeContext(array $scope, array $contract): array
    {
        $contractContext = \is_array($scope['contract_context'] ?? null) ? $scope['contract_context'] : [];

        $themeContext = $this->buildThemeContextFromPlanJsonContract($scope, $contract);
        $sharedPromptContext = $this->buildSharedContextFromPlanJsonContract($scope, $contract);

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
                'selected_skill_codes' => $this->normalizePlanJsonStringList(
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
            'asset_context' => $this->summarizePlanJsonAssetContext($scope),
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
    private function summarizePlanJsonAssetContext(array $scope): array
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
    private function buildThemeContextFromPlanJsonContract(array $scope, array $contract): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $designManifest = \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];

        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        return [
            'site_display_name' => $this->firstNonEmptyPlanJsonText([
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
    private function buildSharedContextFromPlanJsonContract(array $scope, array $contract): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $siteBrief = \is_array($contract['site_brief'] ?? null) ? $contract['site_brief'] : [];
        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $requirements = \is_array($source['user_requirements'] ?? null) ? $source['user_requirements'] : [];
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $contentItems = \is_array($contentManifest['items'] ?? null) ? $contentManifest['items'] : [];
        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $siteDisplayName = $this->firstNonEmptyPlanJsonText([
            $scope['site_title'] ?? null,
            $profile['site_title'] ?? null,
            $siteBrief['site_name'] ?? null,
            $requirements['site_name'] ?? null,
        ]);
        $primaryCta = $this->normalizePlanJsonPrimaryCta((string)($requirements['primary_cta'] ?? ''));
        $navigationItems = $this->buildSharedNavigationItemsFromPlanJsonContract($contract, $contentItems);
        $sitePositioning = $this->firstNonEmptyPlanJsonText([
            $requirements['expanded_brief'] ?? null,
            $requirements['site_goal'] ?? null,
            $requirements['content_direction'] ?? null,
            $siteBrief['summary'] ?? null,
        ]);
        if ($sitePositioning === '' && \is_array($planJson['site_strategy'] ?? null)) {
            $sitePositioning = $this->firstNonEmptyPlanJsonText([
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
                'primary_target' => $this->resolvePlanJsonPrimaryCtaTarget($navigationItems),
            ], static fn(string $value): bool => $value !== ''),
            'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, string> $contentItems
     * @return list<array{label:string,href:string,type:string}>
     */
    private function buildSharedNavigationItemsFromPlanJsonContract(array $contract, array $contentItems): array
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
            $label = $this->firstNonEmptyPlanJsonText([
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
    private function resolvePlanJsonPrimaryCtaTarget(array $navigationItems): string
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

    private function normalizePlanJsonPrimaryCta(string $value): string
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
    private function firstNonEmptyPlanJsonText(array $values): string
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
            'plan_text_rule' => 'plan_json text is intent only; translate or rewrite it before rendering visible copy.',
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
    private function normalizePlanJsonRecordSet(mixed $items, array $idFields): array
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
    private function normalizePlanJsonStringList(mixed $values): array
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

    private function normalizePlanJsonRoleToken(string $value): string
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

    private function resolvePlanJsonSectionCode(string $pageType, string $sectionKey, string $blockId): string
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
     * @param array<string, mixed> $contentItems
     * @param list<string> $keys
     */
    private function firstPlanJsonContentValue(array $contentItems, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->contentValueForPlanJsonKey($contentItems, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contentItems
     */
    private function contentValueForPlanJsonKey(array $contentItems, string $key): string
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
    private function slicePlanJsonContentItems(array $contentItems, array $keys): array
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
        $planJsonTasks = $this->extractPlanJsonTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $pending = [];
        foreach ($planJsonTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            $attemptNo = \max(0, (int)($state['attempt_no'] ?? 0));
            if ($attemptNo >= self::PLAN_JSON_TASK_MAX_AUTOMATIC_ATTEMPTS) {
                continue;
            }
            $staleRunningRetry = $status === self::TASK_STATUS_RUNNING
                && $attemptNo > 0;
            if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_FAILED], true) && !$staleRunningRetry) {
                continue;
            }
            $pending[] = \array_replace($task, $state);
        }
        \usort($pending, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $pending;
    }

    /**
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗霉閿濆浜ら柤鏉挎健濮婃椽顢楅埀顒傜矓椤曗偓閸┾偓妞ゆ帒锕﹂悾鐢碘偓瑙勬礀閵堝憡淇婃搴樺亾閿濆簼绨奸柛鐘成戦妵鍕閿涘嫭鍣伴梺璇″枟閻熲晠銆佸Δ浣哥窞濠电姳鑳剁槐锕€鈹戦悩娈挎殰缂佽鲸娲熷畷鎴濃槈閵忊晜鏅為梺鍛婁緱閸亪宕戦幘鏂ユ闁圭儤鎸婚悵鏍ㄧ箾鐎涙鐭婇柟璇х節楠炲棝寮崼婢晠鏌ㄩ弮鈧崕鎶界嵁閹扮増鐓熼幖杈剧磿閻ｎ參鏌涙惔銈勫惈闁瑰箍鍨介獮鍥嚋椤戣棄浜鹃柛娑欐儗閺佸棝鏌涢弴銊ュ闁告ü绮欏铏圭磼濡儵鎷瑰┑鐐插悑閻熲晠骞冨鈧崺锟犲磼濡湱鐩庢俊鐐€曠换鎰偓姘煎墴瀵娊鏁愰崨顏呮杸闂佺偨鍎辩壕顓㈠春閿濆洠鍋撶憴鍕闁绘牕鍚嬫穱濠囧箹娴ｈ娅嗛梺浼欑到閺堫剟锝炲鍕瘈闁汇垽娼у暩闂佽桨鐒﹂幃鍌氱暦閹达箑围闁告稑鍊归惄顖氱暦缁嬭鏃堝焵椤掑倹鍏滈柍褜鍓熷娲川婵犲倸顫戦柣蹇撶箲閻熲晛顕ｉ幎鑺ユ櫆闁兼亽鍎卞鍨攽閳藉棗鐏￠悗绗涘懏鍏滈柣鎰靛墻濞堜粙鏌ｉ幇鍏哥盎闁诲浚浜滆彁闁搞儜宥堝惈婵犵鈧磭鍩ｇ€规洏鍔戦、姗€鎮㈡潪鏉款棜濠电姷鏁搁崑娑㈩敋椤撱垹鍌ㄧ憸鏃堝箚瀹€鍕＜婵ê鍚嬬紞搴♀攽閻愬弶鈻曞ù婊勭箞瀹曟垿鏁撻悩宕囧幗濠德板€愰崑鎾绘煟濡も偓濡繂顕ｉ幎钘夐唶闁靛鑵归幏娲⒑绾懎浜归柛瀣⊕娣囧﹪宕楅懖鈺冾啎缂佺虎鍙冮ˉ鎾跺姬閳ь剟鎮楃憴鍕婵＄偘绮欏畷娲焵椤掍降浜滈柟鍝勭Ч濡惧嘲霉濠婂嫮鐭掗柡宀€鍠栧畷顐﹀礋椤掑顥ｅ┑鐐茬摠缁秹宕曢幎瑙ｂ偓鏃堝礃椤斿槈褔鐓崶銊﹀暗婵¤缍佸娲传閵夈儛锝嗘叏濡濮傜€规洘宀搁獮鎺懳旈埀顒勬偂濞戙垺鐓曟繛鎴濆船楠炴ɑ銇勯弮鈧敮鎺椻€旈崘顔嘉ч柛鈩冿供濮婂潡姊虹粙娆惧剱闁告梹鐟╅妴浣肝熼懡銈夋闂佸憡绋戣墝闁归攱妞藉娲偂鎼搭喗缍楅梺绋匡攻濞茬喎顕ｉ幖浣哥闁绘劗鏁搁惁鍫ユ⒑闂堟稓绠氭俊鎻掓嚇閹偞绂掔€ｎ偆鍘甸悗鐟板閸嬪﹪宕曢弮鍌楀亾鐟欏嫭绌跨紒鍙夊劤椤曘儵宕熼瀣枎鐓ら悹鍥у级濞呮牠姊婚崒姘偓鐑芥嚄閸撲礁鍨濇い鏍仜缁€澶嬫叏濡炶浜鹃悗瑙勬礃濡炶棄顕ｆ禒瀣垫晝闁挎繂鎳庨獮鎴︽⒒娴ｅ憡鍟為柟绋挎瀹曠喖顢曢敐鍥ｅ亾妤ｅ啯鈷掑ù锝呮啞閹牊銇勯敂璇茬仸闁诡喗锚閳规垹鈧綆浜為崝锕€顪冮妶鍡楀潑闁稿鎸婚妵鍕敇閻樻彃骞嬮梺缁樹緱閸犳稓绮诲☉妯锋閺夊牄鍔嶅▍鍥⒒娴ｇ懓顕滄繛鎻掔Ч瀹曟垿骞橀崜浣猴紲闂侀€炲苯澧寸€规洘锕㈤、娆撴偩鐏炶棄濡囨繝鐢靛Х閺佹悂宕戝☉銏″€舵繝闈涱儏缁€澶愭煙缂併垹鏋熼柣鎾存礋閺岋綁骞囬鍌涙喖闂侀潧娲︾换鍐箞閵婏妇绡€闁稿本绋掗崕鎾绘煛娴ｅ摜澧﹂柡灞剧洴婵＄兘骞嬪┑鍡樼亾闂佽瀛╂繛濠傤潖閾忚瀚氶柟缁樺俯閸斿姊洪崨濠傜伇妞ゎ偄顦辩划瀣吋婢舵ɑ鏅滈梺鍓插亖閸ㄥ湱绮婇敃鍌涒拺缁绢厼鎳忚ぐ褏绱掗悩鍐茬伌闁挎繄鍋ゅ畷銊р偓娑欘焽閸橆亪姊洪崜鎻掍簼缂佽鍟村畷鎶芥嚍閵夛絼绨婚梺鎸庢椤曆冣枍瀹ュ棙鍙忓┑鐘叉噺椤忕娀鏌嶈閸撴瑥锕㈡潏銊﹀弿闁汇垺娼屾径瀣窞闁归偊鍘鹃崢鐢告⒑閹勭闁稿瀚幈銊﹀緞瀹€鈧壕?
     * - shared 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴銏℃櫆闁芥ê顦純鏇熺節閻㈤潧孝闁挎洏鍊楅埀顒佸嚬閸ｏ綁濡撮崨鏉戠煑濠㈣泛鐬奸惁鍫熺節閻㈤潧孝闁稿﹦绮弲璺衡槈閵忥紕鍘遍柣搴€ラ崟顒傚絾闂備線娼уú銈団偓姘嵆閻涱噣骞掑Δ鈧粻锝嗙節閸偄濮冮柟顕嗙悼缁辨捇宕掑▎鎺戝帯婵犳鍨伴顓犳閻愬鐟归柍褜鍓欓锝嗙節濮橆厼浜滈梺绋跨箺閸嬫劙宕濋悜鑺モ拺闁圭瀛╃壕鐢告煕鐎ｎ偅灏い顓″劵椤т線鏌涢悩鎰佹疁濠碉紕鏁诲畷鐔碱敍濮ｄ匠鍥ㄧ厱婵炴垵宕弸娑欑箾閸噥娈滄慨濠冩そ瀹曨偊宕熼鍛晧闂備礁鎲￠弻銊╂儗閸岀偛鏄ラ柕澶涚畱缁剁偛鈹戦悙顏勭伄闁哥姵鍔楃划顓㈡偄绾拌鲸鏅┑鐐村灥瀹曨剟寮畷鍥╃＝闁稿本鑹鹃埀顒佹倐瀹曟劖顦版惔銏╁仺闂佽法鍠撴慨瀵哥玻濡ゅ懏鐓涚€广儱娴锋禍鍦喐閻楀牆绗氶柛濠傤煼閺岋箑螣娓氼垱楔濡炪倖鏌ㄥΛ妤呪€旈崘顔嘉ч柛鈩冾殔琛肩紓鍌欒兌婵敻宕归崷顓炲灊闁割偁鍎辩粈鍐┿亜閺冨倹娅曢柛姗嗕簼缁绘繈鎮介棃娑楃捕闂佽绻戠换鍫濈暦濠靛绠绘い鏃傛櫕閸?shared 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾剧粯绻涢幋娆忕労闁轰礁顑嗛妵鍕箻鐠虹儤鐎鹃梺鍛婄懃缁绘劘鐏冮梺鎸庣箓閹冲酣寮搁妶澶嬬厸濞达絽澹婇崕鎴︽煙閹绘帗鍟為柟顖涙婵℃悂濡疯閺?
     * - shared 闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊椤掑鏅悷婊冪Ч濠€渚€姊虹紒妯虹伇婵☆偄瀚划濠氭偐缂佹鍘甸梺纭咁潐閸旓箓宕靛▎鎾村€垫慨姗嗗墻濡插綊鏌曢崶褍顏鐐村浮楠炲鈹戦幇顏呭亝闂傚倷鐒﹂幃鍫曞礉瀹€鍕９鐟滅増甯掔粻鐐烘煏婵炲灝鍓婚柣鏃傚帶缁犱即骞栨潏鍓хシ闁逞屽墯閸旀瑩寮婚敐澶嬪亜闁告縿鍎查崵鍌滅磽娴ｅ搫校闁圭懓娲幃浼搭敋閳ь剙顕ｆ禒瀣垫晣闁绘劖顔栭崯鍥ㄤ繆閻愵亜鈧牠骞愭ィ鍐ㄧ；闁绘柨鎽滈々閿嬨亜閺嶃劎鐭岀痪鎹愭闇夐柨婵嗘缁茶霉濠婂懎浜剧紒缁樼箞婵偓闁挎繂妫涢妴鎰版⒑閹颁礁鐏℃繛鍙夌箞婵＄敻骞囬弶璺唺闂佺懓顕刊顓炍ｉ娑氱瘈闁汇垽娼ф禒锔界箾閸忚偐鎳呴柍褜鍓欓悘姘辨暜濡ゅ啰鐭夌€广儱顦介弫鍌炴煕閺囥劌骞楁繛鍫ョ畺濮婃椽妫冨☉姘鳖唺婵犳鍣崢鐓庡祫闂佸壊鍋侀崕鏌ユ偂韫囨稓鍙撻柛銉ｅ妽缁€鈧柛鐔侯焾椤?page_type 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙缂佺姷濞€閺岀喖宕滆鐢盯鏌涙繝鍛厫闁逛究鍔岃灒闁圭娴烽妴鎰磽娴ｅ搫校婵犮垺锕㈤崺鐐哄箣閿旇棄浜归柣搴℃贡婵挳藟濠靛棌鏀芥い鏃€顑欏鎰版煟閹垮嫮绡€闁绘侗鍣ｅ浠嬧€栭妷銉╁弰妞ゃ垺顨婇崺鈧い鎺嶆缁诲棗霉閻樺樊鍎愰柣鎾寸洴閺屾稑顭ㄩ埀顒傜矆娴ｈ娅犻柟缁㈠枟閻撴盯鎮橀悙鎻掆挃婵炴彃鐡ㄩ妵鍕閳╁啰顦版繝纰樷偓宕囧煟鐎规洏鍔戦、娆撳矗閵壯勫瘻濠电姷鏁告慨鐑姐€傛禒瀣劦妞ゆ巻鍋撶痪缁㈠幖閿曘垽骞橀鐣屽幈闂佸搫鍊藉▔鏇㈡倿閹间焦鐓冮柕澶涢檮椤ュ牏鈧娲橀敃銏ゃ€佸▎鎾冲簥濠㈣鍨伴崰姘舵偄閸℃稒鍋ｉ弶鐐村椤掔喖鏌涙惔銏犲婵﹤鎼埢搴ㄥ箚瑜嶇猾宥呪攽椤旂》鏀绘俊鐐舵閻ｇ兘濡搁敂鍓ь啎濠殿喗锕╅崢濂告倶閹绢喗鐓欐い鏍ㄨ壘椤忣厽銇勯姀锛勨槈妞ゎ偅绻冨蹇涘Ω閿旇鏅?1 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾惧鏌ｉ幇顒佹儓闁搞劌鍊块弻娑㈩敃閿濆棛顦ョ紓浣哄Т缂嶅﹪寮诲澶婁紶闁告洦鍋€閸嬫挻绻濆銉㈠亾閸涙潙绠甸柟鐑樼箖鐎靛矂鏌ｆ惔顖滅У濞存粍绮撻、妤呭鎺虫禍婊勩亜閹板墎绋荤紒鈧崘顔界厵濞撴艾鐏濇俊濂告懚閿濆鐓曟い顓熷灥閺嬨倝鏌涘鍡椾喊婵﹥妞藉畷顐﹀礋椤掆偓椤庢盯姊洪崨濠冨暗闁哥姵鐗犻悰顕€宕橀…鎴炲缓闂侀€炲苯澧存鐐插暙閳诲酣骞橀弶鎴炵杺婵犵數鍋涢悧濠勨偓绗涘泚澶嬪緞閹邦厸鎷绘繛杈剧到閹诧繝骞嗛崼銉︾厵闁告劘灏欑粻濠氭煙椤旀儳鍘撮柛鈺嬬節瀹曘劑顢橀悩鍨瘒闂備浇宕垫繛鈧紓鍌涘哺婵℃挳鍩€椤掍椒绻嗛柟缁樺笧婢э箓鏌＄仦绯曞亾瀹曞洦娈煎銈嗘⒒閸樠囧汲濞嗘垶鍋栨繝闈涚墢绾句粙鏌涚仦鎹愬闁逞屽墯閹倸鐣烽幇鏉夸紶闁靛／鍛帬闂備礁婀遍搹搴ㄥ窗濡ゅ懎纾婚悗锝庡枤閸欐捇鏌涢妷锝呭缂佲偓閳ь剟姊洪幖鐐插缂佽鐗撳濠氬Ω閵夈垺鏂€闂佺硶鍓濋敋闁哄懐鏁诲娲传閸曨偅娈梺缁橆殔濡繈骞冮悙鍝勫瀭妞ゆ劗濮崇花濠氭⒑閸︻厼鍔嬮柛銊ф暬瀵娊寮Λ?
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
        $planJsonTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $this->extractPlanJsonTasks($scope)
        ))), true);
        $hasSharedHeader = isset($planJsonTaskKeys['shared:header']);
        $hasSharedFooter = isset($planJsonTaskKeys['shared:footer']);
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

        // 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮撮悙娴嬪亾閸洖鐒垫い鎺嗗亾闁哥喎纾划璇测槈濡攱顫嶅┑鈽嗗灣閳峰牆危椤栨稓绡€闁汇垽娼ф禒锕傛煕閵娿劍纭炬い顐ｇ箞婵℃悂鍩℃担渚敤婵犳鍠楅…鍫ュ春閺嶎厼纾归柛顭戝亞缁犻箖鏌熺€电鍓卞ù鐓庢閺岀喓鈧數顭堟禒锕傛煕濞嗗繒绠茬紒缁樼箖缁绘繈宕掑闂存樊濠电偛鐡ㄧ划宥囧垝閹捐钃熼柨鐔哄Т閻愬﹪鏌嶆潪鐗堫樂婵炲矈浜滈—鍐Χ閸愩劌濮㈡繝娈垮櫍椤ユ挸危閹版澘绠虫俊銈傚亾缂佺姵绋掗妵鍕箻濡も偓鐎氼噣寮抽敃鍌涒拻濞撴埃鍋撻柍褜鍓氱粙鎾诲煘閹烘鐓曢柡鍐ｅ亾闁搞劌鐏濋锝夘敃閿曗偓缁犳盯鏌℃径濠勪虎缂佹劖绋戦—鍐Χ閸℃瑥顫х紓渚囧枤婵炩偓鐎规洏鍎靛畷銊р偓娑櫱氶幏?page_type 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙５闁逞屽墾缁犳挸鐣锋總绋款潊闁炽儱鍟跨花銉╂⒒娴ｇ瓔娼愬鐟版閺呰泛螖閸涱厾锛涢柣搴秵閸犳鎮￠弴銏＄厓闁宠桨绀侀弳娆撴煙閼测晩鐒鹃棁?1 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾惧鏌ｉ幇顒佹儓闁搞劌鍊块弻娑㈩敃閿濆棛顦ョ紓浣哄Т缂嶅﹪寮诲澶婁紶闁告洦鍋€閸嬫挻绻濆銉㈠亾閸涙潙绠甸柟鐑樼箖鐎靛矂鏌ｆ惔顖滅У濞存粍绮撻、妤呭鎺虫禍婊勩亜閹板墎绋荤紒鈧崘顏嗙＜缂備焦顭囩粻鐐翠繆椤愩垹鏆欓柍钘夘槸椤繈顢楁径瀣槕闂傚倸鍊烽懗鍓佸垝椤栨粍鏆滈柟鐑橆殕閺呮繈鏌曢崼婵愭Ч缂佺姵甯″缁樻媴閾忕懓绗￠梺鎸庣娣囧﹪顢涘鎹愬惈閻庤娲樺ú婵堢不濞戙垹绫嶉柛灞剧矤閸熷酣姊绘担鍛婂暈濞撴碍顨婂畷鎴﹀礋椤栨氨鍔﹀銈嗗笂閼宠埖鏅堕悽鍛婄厪闁糕剝顨呴弳锝呪攽閿涘嫬鍘撮柛鈺嬬節瀹曟帒顫濋敐鍛闂佺粯鍨兼慨銈夋偂閻樼粯鐓曟繝闈涘閸旀粓鏌￠崱蹇旀珚闁哄本娲熷畷鍗炍熼崫鍕垫綒闂備浇顕栭崰鏍床閺屻儮鈧箓濡搁埡浣侯槹濡炪倖甯掗崐鎼佸吹閹烘鈷掑ù锝勮閻掗箖鏌ㄩ弴妯衡偓婵嬪箖濡　鏀介悗锝庡亜娴犲ジ鎮楅悷鏉款伃闁稿锕ら…鍥煛閸涱喖浠梺鍛婄箓鐎氼參骞嗛崼銉︾厾闁哄娉曟禒銏ゆ煃鐟欏嫬鐏︽鐐诧躬閺屾稒绻濋崘鈺冾槹閻庤娲樺姗€锝炲┑瀣垫晣闁绘垵妫楀▓濂告煟鎼粹€冲辅闁稿鎹囬弻娑㈠即閵娿儱骞嬮梺褰掓敱濡炶棄顫忓ú顏勫窛濠电姴瀚уΣ鍫ユ⒑閹稿孩纾搁柛濠冩礋濠€浣割渻閵堝棙鐓ユい顐ｆ礃缁傚秴顭ㄩ崼鐔哄弳濠电娀娼уΛ娑氱不閻楀牄浜滈柍鍝勶工婢ф壆绱掓潏銊ユ诞妞ゃ垺宀稿畷銊╊敇閻愭鍟堥梺璇查閻忔艾顭垮Ο灏栧亾濮橆偄宓嗛柣娑卞櫍瀹曞爼顢楁径瀣珜闂備礁鎲￠崝鏇㈠疮椤栨娲偄閻撳海鐣哄┑掳鍊曢幊搴ｇ矆閸屾凹鐔嗛悹铏瑰皑濮婃顭跨憴鍕婵﹦绮幏鍛村川婵犲倹娈樻繝鐢靛仦瑜板啰绮旈悷閭﹀殨妞ゆ帊鑳堕悷褰掓煃瑜滈崜娆撴偩閻戣棄绠ｉ柨鏇楀亾缂佺姴顭烽弻鈩冨緞鐎ｎ亞浠肩紓浣瑰姉閸嬨倕顫忔ウ瑁や汗闁圭儤鍨抽崰濠囨⒑閸涘﹦鎳冨Δ鐘崇摃閻忓姊洪崨濠傚Е闁绘挸鐗嗛妴鎺撶節濮橆厾鍘梺鍓插亝缁诲牓顢撳Δ鈧湁婵犲ň鍋撶紒顔界懇瀵鈽夊鍛澑闂佸搫鍟幑渚€鍩€椤掑啯纭堕柍?
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
        // 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮撮悙娴嬪亾閸洖鐒垫い鎺嗗亾闁哥喎纾划璇测槈濡攱顫嶅┑鈽嗗灣閳峰牆危椤栨稓绡€闁汇垽娼ф禒锕傛煕閵娿劌鐓愮紒宀勪憾閹粌螣鐠囨彃浼庨梻浣筋潐閸庡吋鎱ㄩ妶澶嬪亗闁告劦鍠楅悡鏇熺節闂堟稒顥滄い蹇婃櫊閺屽秷顧侀柛鎾寸箞閿濈偞寰勯幇顒傜杽闂侀潧顭堥崕娲偂閵夆晜鐓曢柡鍥殕濞呭啰绱掗妸銉吋婵﹥妞藉畷顐﹀礋椤掆偓缁愭盯姊虹粙娆惧剳闁稿鍊涘Λ銏ゆ⒑缂佹﹩鐒介柡浣告憸婢规洘绺介崨濠勫幍闂備緡鍙忕粻鎴濐嚕閻愵剛绠鹃柛顐ゅ枔閻帡鏌″畝鈧崰鏍€佸▎鎾崇閹艰揪绲婚埀顒佸姍濮婅櫣鈧湱濮甸ˉ澶嬨亜閿曞倹娑фい鏇秮瀹曟劙鎮ゆ担鍓愨晛鈹戦悩鎰佸晱闁革綆鍨辨穱濠囧炊閳哄偆娼熼梺瑙勫礃椤曆呭閸忓吋鍙忔俊顖濆吹濡倿鏌曡箛瀣偓鏍偂閻旈晲绻嗛柕鍫濆閸斿秶鈧娲栭惌鍌炲蓟閻旂⒈鏁婇悹鍥ㄥ絻缁侇喖顪冮妶鍐ㄧ仾闁荤啿鏅犻獮鍐ㄢ枎閹垮啯鏅㈤梺閫炲苯澧板瑙勬礋椤㈡盯鎮欑划瑙勫闂備礁鎲＄粙鎴︽晝閿斿墽涓嶉柟鍓х帛閸婂灚鎱ㄥΟ鐓庡付闁哄鐩弻锝夋晲閸℃瑧鐣甸梺瀹犳椤︻垶锝炲┑鍥ㄧ秶闁冲搫顑囬梻顖涚節閻㈤潧浠╅柟娲讳簽瀵板﹪宕稿Δ鈧粻鐘绘煙閹呮憼闁告瑥绻橀弻娑㈩敃閵堝懏鐎繛瀛樼矋缁捇寮婚弴鐔虹闁割煈鍠栨慨鏇㈡⒑閹肩偛鈧劙宕戦幘缁樷拻濞撴埃鍋撴繛浣冲厾娲晝閸屾氨顦梺鍝勬储閸ㄥ綊鎮￠垾鎰佺唵闁兼悂娼ф慨鍥ㄣ亜椤愩垺鍤囬柡?
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
        foreach ($this->extractPlanJsonTasks($scope, true) as $task) {
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listTaskDefinitions(array $scope): array
    {
        return $this->extractPlanJsonTasks($scope, true);
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
     * 闂?scope 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾惧鏌ｉ幇顒佹儓闁搞劌鍊块弻娑㈩敃閿濆棛顦ョ紓浣哄Т缂嶅﹪寮诲澶婁紶闁告洦鍓欏▍锝夋⒑缁嬭儻顫﹂柛鏂跨焸閸╃偤骞嬮敃鈧壕鍏兼叏濮楀棗骞栭柡鍡楃墦濮婅櫣绮欏▎鎯у壄闂佺锕ョ换鍫濐嚕婵犳艾鍗抽柣鏃囨椤旀洟姊虹紒妯哄Е闁告挻宀搁幃鐢稿籍閸啿鎷?`_build_page_progress[<page_type>][skip_remaining_blocks]=true`闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍛婄秶濡わ絽鍟宥夋⒑閹肩偛鈧牠宕濋弽顓炍﹂柛鏇ㄥ灠閸愨偓濡炪倖鍔﹀鈧繛宀婁邯濮婅櫣绮欓崸妤娾偓妤冪磼婢跺﹦绉虹€殿喖顭峰鎾晬閸曨厽婢戦梻渚€娼ч敍蹇涘椽閸愵亜鎯炴繝纰夌磿閸嬫垿宕愰幇鏉跨柧闁绘ê鍤㈡径鎰閻犲洩灏欓崝锕€顪冮妶鍡楀潑闁稿鎸剧槐鎺楁偐閼碱儷褏鈧娲樺ú鐔煎蓟閸℃鍚嬮柛娑卞灱閸炵敻姊虹拠鎻掑毐缂傚秴妫濋崺鈧?pending/running 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弴鐐测偓褰掑磿閹寸姵鍠愰柣妤€鐗嗙粭鎺旂磼閳ь剚寰勭仦绋夸壕闁稿繐顦禍楣冩⒑闁偛鑻晶鎾煕閳规儳浜炬俊鐐€栫敮濠囨嚄閸洘鍋傛い鏍仦閻撴洘淇婇妶鍛仾闁绘繍浜滆彁闁搞儜宥呭闂侀€炲苯澧紒瀣浮閺佸绱撴担绋款暢闁稿鍊濆濠氭偄閸忚偐鍔烽梺鎸庢磵閸嬫挸顭胯婢ф濡?section 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗霉閿濆牊顏犵紒鈧繝鍌楁斀闁绘ɑ褰冩禍鐐烘煟閹烘梹娅曢柟鍙夌摃缁犳盯寮撮悤浣圭稐闂備胶绮崝鏇㈩敋椤撶姴濮柍褜鍓熷娲箹閻愭彃濡ч梺鎼炲労閻撳妲愰鈧埞鎴︽偐閸偅姣勯梺绋款儐缁嬫垼鐏掓繝鐢靛Т閸熶即銆呴崣澶岀瘈濠电姴鍊绘晶鏇犵磼閳ь剟宕橀鐣屽帗閻熸粍绮撳畷婊堟偄婵傚缍庡┑鐐叉▕娴滄粎绮昏ぐ鎺撶厽闁归偊鍘肩徊璇测攽椤曗偓椤ユ挾妲愰幘瀛樺闁告繂瀚呴敐澶嬪仺妞ゆ牗绮屾禒褔鏌?done闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍛婄秶濡わ絽鍟宥夋⒑缁嬫鍎忔い鎴濐樀瀵鈽夊Ο閿嬵潔闂佸憡顨堥崑鐔哥椤撱垺鍊甸悷娆忓缁€鈧悗娈垮枛閻栧ジ鐛崼銉ノ╅柕澶婃捣閸犳牕鐣疯ぐ鎺濇晩闁诡垎鍐窗闂傚倸鍊烽懗鍫曗€﹂崼銉晞闁稿瞼鍋涢悿鐐節婵犲倹鍣虹€规洖寮剁换娑㈠箣濞嗗繒浠煎Δ鐘靛亼閸ㄧ儤绌辨繝鍥舵晬婵﹩鍘介崕鎾绘偠濮橆厾绠栫紒缁樼箓閳绘捇宕归鐣屼邯闂備焦瀵уú锔界閻愰潧鍨濆┑鐘宠壘閸愨偓濡炪倖鎸鹃崑鐘诲箺閺囥垺鈷戦柟绋挎捣閳藉鎮楀闂寸盎闁宠绮欓、鏃堝醇閻旇渹鍖栭梻浣规偠閸庮垶宕濆畝鈧濠勬嫚濞村鏂€濡炪倖鏌ㄩ幖顐︽倶閸欏鍙忓┑鐘叉噺椤忕姷绱掗鐣屾噧闁宠閰ｉ獮鍡氼槻濠碘姍鍛＝闁稿本鐟ч崝宥囨喐閺夊灝鏆欐い顓炴喘閺佹捇鎮╅棃娑氥偊闂傚鍋勫ú锔剧矙閹烘纾婚柟閭﹀幘缁犻箖鏌ょ喊鍗炲閻㈩垱鐩弻锟犲椽閸愵亜鍩岄梺瀹狀潐閸ㄥ潡骞冮埡鍐＜婵☆垰顭烽弫顏堟⒒娴ｈ櫣甯涙い銊ユ噽閹广垹螣娓氼垰娈ㄩ梺褰掓？缁€渚€鎮為崹顐犱簻闁圭儤鍨甸顏堟煃闁垮绗掗棁澶愭煥濠靛棙澶勯柛銈傚亾婵＄偑鍊栧ú妯煎垝鎼达絾顫曢柟鐑樻煛閸嬫捇鏁愭惔鈥茬盎闂佽绻戦幐鎶藉蓟閻旂⒈鏁嶆慨妯夸含閸旑垶鎮楃憴鍕閻㈩垱甯￠崺銉﹀緞婵犲孩寤洪梺绯曞墲椤ㄥ棝顢欓幘缁樷拻闁稿本鐟чˇ锕傛煕閻旈攱鍋ユ鐐寸墵椤㈡洟鏁冮埀顒傜不閻樿绠归弶鍫濆⒔閹ジ鏌ｉ鐐搭棦闁哄本绋撴禒锕傚箚瑜滃Λ婊堟⒑缁嬫鍎愰柟鐟版喘閹即顢氶埀顒€鐣疯ぐ鎺濇晩闁绘挸瀵掑娑㈡⒒閸屾瑨鍏岄柟铏崌瀹曨垶宕稿Δ浣哄帎闂佹寧绻傞ˇ浼村磻濡眹浜滈柡鍥殔娴滅偓绻濆▓鍨灀闁稿鎹囧铏圭磼濡浚浜滆灋婵°倕鍟扮粈濠傗攽閻樺弶鎼愰柡瀣╃窔閺岀喖鎮ч崼鐔哄嚒閻庣懓鎲＄换鍐Φ閸曨垰鍐€闁靛ě鍛幘闂備礁鎽滈崑娑氱礊婵犲偆娼栫紓浣诡焽閻熷綊鏌嶈閸撶喖宕洪埀顒併亜閹烘垵鈧憡绂掑鍫熺厾婵炶尪顕ч悘锟犳煛閸涱厾鍩ｆい銏＄☉閳藉螖閸愵亞鏆伴梻鍌欑閹诧繝鎮烽妷鈹у洭顢涘鍛暥閻熸粍妫冨濠氭偄閼测晛绁﹂梺鍓茬厛閸犳碍绂掓總鍛婄叄濞村吋鐟ч幃鑲╃磼鏉堛劍灏伴柟宄版噺閹便劑骞嬮婵嬪仐閻庤娲樼换鍫濈暦閵娧€鍋撳☉娅辨岸骞忓ú顏呪拺闁革富鍙庨悞楣冩倵濞戞帗娅婇挊鐔兼煕閳╁啰鈯曢柣鎾存礋閹鏁愭惔鈥茬盎婵犳鍠栭ˇ杈╂閹烘鏁婇柤娴嬫櫅閳敻鎮楃憴鍕鐎规洦鍓熼崺銉﹀緞婵炵偓鐎婚梺鐟扮摠缁诲倹淇?
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

            foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
                    $this->planJsonTaskResultRefFromDefinition($task),
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
            'message' => $this->sanitizePlanJsonTaskFailureMessageForView($message),
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
            'message' => $this->sanitizePlanJsonTaskFailureMessageForView($message, 'Retrying generation in a fresh queue.'),
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
        $planJsonTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => \trim((string)($task['task_key'] ?? '')),
            $this->extractPlanJsonTasks($scope)
        ))), true);
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
            if ($taskKey === '' || !isset($planJsonTaskKeys[$taskKey])) {
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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

            $scope = $this->markTaskDone($scope, $taskKey, $this->planJsonTaskResultRefFromDefinition($task));
            $taskState = $this->extractTaskState($scope);
        }

        return $scope;
    }

    /**
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾圭€瑰嫭鍣磋ぐ鎺戠倞妞ゎ剦鍓氶惄顖氱暦閻旂⒈鏁嶆慨妯块哺閻掔偓淇婇悙顏勨偓鏍偋濡ゅ啫鍨濈€广儱鎳愰弳锕傛煛鐏炶鍔滈柣鎾存礋閹﹢鎮欐担鍐╊€楅梺鎼炲€栧Λ鍐蓟閿濆鍋勭紒瀣硶瑜板牓姊虹紒妯荤叆闁告艾顑夊畷鐢稿礃椤旂晫鍘撻梺鍛婄箓鐎氼剟寮搁敂鍓х＜閺夊牄鍔庨崣鈧┑顔硷功缁垶骞忛崨鏉戝窛濠电姴鍟崜鍨繆閻愵亜鈧呯磽濮樿泛纭€闁告劘灏欓弳锔炬喐閻楀牆绗氶柛瀣ㄥ姂閺屾盯骞橀崘鑼獓闂佸搫鎳夐弲婊呮崲濠靛鍋ㄩ梻鍫熷垁閵忥紕绠鹃悹鍥囧懐鏆ら梺璇″晸閵堝洨鏉稿┑鐐村灦閻熝囧储闁秵鈷戦柡鍌樺劜濞呭懘鏌涢悩瀹犲闁崇粯鎹囧顕€鍩€椤掑嫬桅闁告洦鍨版儫闂佹寧姊婚崑鎾诲闯椤斿墽纾藉ù锝勭矙閸濇椽鎮介銈囩瘈闁靛棔绀侀埢搴ㄥ箻閺夋垳鎮ｉ梺璇茬箳閸嬬偤宕曢幎钘夊瀭闂侇剙绉甸悡鐔煎箹濞ｎ剙鐒洪柛鐔风箻閺屾盯鎮╁畷鍥р拰闂佽鍠楅敋妞ゎ偅绻堥、妤佸緞婵犲喚鍞梻鍌欑閹测€趁洪敃鍌氱婵炴垶鑹炬慨顒勬煃瑜滈崜姘辨崲濞戞瑦缍囬柛鎾楀憛姘攽閻愬弶瀚呯紓宥勭窔楠炲啴鏁撻悩鑼吅濠电娀娼ч崯顖炲棘閳ь剟姊绘担铏瑰笡闁告梹锕㈠畷娲冀椤戝彞姹楅悷婊冪箳濡叉劙骞掑Δ鈧粻鐢告煙閻戞ê鐏嶉柟绋垮暣濮婃椽宕ㄦ繝鍌滅懖闁汇埄鍨辩敮锟犮€佸Ο鑽ら檮缂佸瀵ч妵婵嬫⒑閸涘﹤濮﹂柛妯绘倐瀹曟垿骞樼拠鑼唶闁圭厧鐡ㄧ粙鎰姳婵犳碍鈷戦悷娆忓缁€鍐╃箾婢跺顬奸柍顏呮尦濮婄粯鎷呮笟顖滃姼濡炪倖鍨靛Λ婵嬬嵁閹达箑鐐婃い鎺嗗亾缂佺姵鐗犻弻锝夊箣閿濆憛鎾绘煕鐎ｎ亶鍎旈柡灞剧洴椤㈡洟濡堕崨顔锯偓楣冩煟鎼淬垻顣插┑鐐诧工椤繒绱掑Ο璇差€撻柣鐔哥懃鐎氼剚绂掗埡鍛拺闁告稑锕ラ悡銉х磼婢跺灏﹂柟顔藉劤閳规垹鈧綆浜滅粣娑欑節閻㈤潧小闁煎啿澧庣划璇差潩閼哥鎷洪梺鍛婄箓鐎氬嘲危瑜版帗鐓曢柍杞拌兌婢э妇鈧娲忛崕鎶藉焵椤掑﹦绉甸柛鐘冲哺瀹曪綁骞樼紒妯煎幈闂侀潧顧€缁茶姤淇婇悾宀€纾奸柍褜鍓熷畷濂稿Ψ閿旀儳骞楁繝鐢靛仦閸ㄥ爼鎮ч弴銏犵闁挎棁濮ら崣?pending/running闂?
     *
     * 闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊瑜忛弳锕傛煕椤垵浜濋柛娆忕箻閺屸剝寰勭€ｎ亝顔呭┑鐐叉▕娴滄粌娲垮┑鐘灱濞夋盯顢栭崨顔绢浄闂侇剙绉甸埛鎴︽⒒閸喍绶遍柣鎺楃畺閺屾稒鎯旈姀銏″櫚闂佽桨鐒﹂崝鏍ь嚗閸曨剛绡€闁告洟娼ч幃鍫ユ⒒閸屾瑧鍔嶉悗绗涘吘娑欐媴鐟欏嫬寮块梺闈涚墕閹冲寮稿澶嬬厸鐎规搩鍠掗崑鎾绘煛閳ь剟鎳為妷锝勭盎闂佸搫鍟崐鐟扳枍閺囩姷纾奸柣妯虹－婢ь剟鏌曢崶褍顏鐐村笒閳规垿宕堕埡瀣崪stPendingTasks()` / `hasPendingTasks()` 婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柟闂寸绾剧粯绻涢幋娆忕労闁轰礁顑嗛妵鍕箻鐠虹儤鐎鹃梺鍛婄懃缁绘﹢寮婚悢铏圭＜闁靛繒濮甸悘鍫㈢磽娴ｅ搫啸濠电偐鍋撻梺缁樻惄閸嬪﹤鐣烽崼鏇炍╃憸澶嬫叏閸ヮ剚鈷戠紒瀣皡瀹搞儵鏌ｉ弽褋鍋㈢€殿喛顕ч埥澶愬閻樻牓鍔戦弻銊モ攽閸℃ê娅ｅ銈庡墮椤戝顫?pending闂?
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕閻庤娲忛崕鎶藉焵椤掑﹦绉甸柛鐘愁殜瀹曟洟骞嬮悩鍐叉瀾闂佺粯顨呴悧鍡欑箔閹烘梻妫柟顖嗗嫬浠撮梺鍝勭灱閸犲酣鍩㈤幘璇插瀭妞ゆ梻鏅禍鎰版⒒娴ｄ警鐒炬い鎴濇楠炴垿宕堕‖顒佺洴瀹曟﹢濡搁姀鈽嗘綌婵犳鍠楅敋鐟滄澘顦卞Σ鎰潨閳ь剙顫忕紒妯诲闁绘垶锚濞堝苯顪冮妶鍐ㄥ闂佸府绲介悾鐑藉箣閻愮數鐦堥梺绋挎湰缁秴鈻撻幆褉鏀芥い鏂款潟娴犳粓鏌涚€ｎ偅灏柍瑙勫灴閸╁嫰宕橀埡浣插亾閹邦兘鏀介柨娑樺閸樺瓨銇勯姀锛勬噰鐎规洘顨婂畷妤呭礂閼测晜袙闂傚倸鍊搁崐宄懊归崶顒夋晪鐟滃秹婀侀梺缁樺灱濡嫰寮告担绯曟斀闁绘ê鐤囨竟妯肩棯閹规劦鍤欓柍瑙勫灴閹晠骞撻幒鎾搭啀婵＄偑鍊愰弲婊堟偂閿熺姴钃熼柨婵嗩槸缁犳稒銇勯弽銊ょ繁濞寸姭鏅犻幃妤冩喆閸曨剙鐭紓浣藉煐瀹€绋款嚕鐠囨祴妲堥柕蹇曞Х閸旀挳姊洪崨濠傚Е濞存粍鐗曞嵄闁割偁鍎查埛鎴犵磽娴ｅ顏呮叏閸ヮ剚鐓ラ柡鍥ュ妺闁垳鈧鍠栭…鐑藉极閹版澘宸濋柛灞剧矊閺嬫棃鏌熸搴♀枅闁瑰磭濞€椤㈡宕掑Ο杞扮敾闂傚倷娴囬褏鈧稈鏅犻、娆撳冀椤撶偟鐛ラ梺鍝勮癁瀹€鈧崝鐑芥⒑閻愯棄鍔滈柛鎾磋壘椤洭寮介妸褏顔曢悗鐟板閸犳洟骞夋ィ鍐╃厸濞达綁娼婚煬顒勬煛鐏炲墽鈽夐柍瑙勫灴瀹曞崬螖婵犱焦鍋呭┑锛勫亼閸娿倝宕滃▎寰稑鈹戠€ｎ亣鎽?running闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢鍛婄秶濡わ絽鍟宥夋⒑缁嬫鍎忔い鎴濇嚇閸╃偤骞嬮敂钘変汗闂傚鍋掗崣鈧柟?pending闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢灏佹瀻闂勫洭顢氳鐓ゆい鎾卞灮瀹撲線鐓崶銊︾；婵炲矈浜幃瑙勬媴閼恒儳褰ч梺娲诲亜缁绘劙鍩為幋锔藉€烽悗娑櫭棄宥夋⒑缁洘娅呴柛鐔告綑閻ｇ兘骞嬮敃鈧粻濠氭煕閵婏妇鈽夊ù婊堢畺閹嘲鈻庤箛鎿冧痪缂備讲鍋撻柛鎰典簽绾惧吋銇勯弮鍥т汗闁绘帒鎲￠妵鍕閳藉懓鈧法鈧娲橀〃濠囧箖閳╁啯鍎熼柨婵嗘川瀹撲線姊婚崒娆掑厡缁绢厼鐖煎畷婊冣攽鐎ｎ€箓鏌ｉ幇顒夊殶闁绘繂鐖奸弻锟犲炊閵夈儳浠鹃梺鎶芥敱閸ㄥ灝顫忓ú顏嶆晝闁靛牆鎳嶇划鍫曟⒑閸忓吋銇熼柛銊╀憾瀵煡宕滄担鎻掍壕闁汇垻鏁搁妴濠囨煕鐎ｎ偅灏甸柟鍙夋尦瀹曠喖顢楅崒銈喰為梻鍌欐祰瀹曠敻宕抽敂鍓т笉闁硅揪鑵归埀顒婄畵瀹曞爼顢楁径瀣珕闂備礁澹婇崑鍛崲閸曨垼鏁囬柣鎾冲瘨濞撳鏌曢崼婵囶棡闁抽攱甯￠弻娑氣偓锝庡亝瀹曞瞼鈧娲樻繛濠囩嵁閺嶃劍濯撮柛蹇擃槹鐎氳棄鈹戦悙鑸靛涧缂佽弓绮欓獮澶愭晸閻樿尙鐣鹃梺鍓插亖閸庢煡鎮￠悢闀愮箚闁靛牆瀚崗宀勬煟椤撶儐鍎戠紒杈ㄥ浮瀹曟帒顫濆В娅诲洦鐓涘ù锝囨嚀婵牏鈧灚婢樼€氼厾鎹㈠┑瀣妞ゆ挾鍠愰惁鐐烘⒒閸屾艾鈧娆㈠顒夌劷鐟滄棃骞冭瀹曞崬霉閺夋寧澶勯悗闈涖偢瀵爼骞嬮悪鍛惞?done闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸婂潡鏌ㄩ弮鍫熸殰闁稿鎸剧划顓炩槈濡娅ч梺娲诲幗閻熲晠寮婚悢琛″亾閻㈡鐒惧ù鐘欏洦鈷掗柛鏇ㄥ亜椤忣參鏌″畝瀣瘈鐎规洘锕㈡俊鎼佸Ψ閵忕姳澹曢悷婊呭鐢亞绱為弽顓熺厸闁搞儮鏅欑槐宕囨喐閻楀牆绗掔紒鈧崒鐐寸厱闊洦鑹炬禍鍦磼鐎ｎ亝宸濈紒杈ㄦ尰閹峰懘骞撻幒宥咁棜闂傚倷绀侀幉鈥趁洪敃鍌氬瀭闂侇剙绉甸崑鍌炴煥濠靛棭妲洪柛娆愭崌閺屾盯濡烽敐鍛瀴缂備讲鍋撻柛鎰靛枟閻撴洟鏌曢崼婵嗏偓鍛婄閸撗呯＝?
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻鐔兼⒒鐎靛壊妲梺姹囧€ら崰鏍箒闂佺绻愰崥瀣礊閹寸姷纾奸柟閭﹀弾濞堟粍顨ラ悙瀵稿ⅹ閼挎劖銇勯幒鍡椾壕婵犵鈧偨鍋㈤柡灞剧洴婵℃悂濡烽妷銏犱壕鐟滅増甯掓闂佸憡娲﹂崰姘舵偪閳ь剟姊洪崷顓炰壕闁告挻纰嶇€电厧鐣濋崟顑芥嫼闂佸憡绺块崕閬嶅几鎼淬劍鐓欓柧蹇ｅ亾閼拌法鈧鍠涢褔鍩ユ径濞㈢喖鏌ㄧ€ｎ兘鍋撴繝姘棅妞ゆ劑鍨虹粊顐ょ磼閼碱剙浜炬い銊︾懇濮婂宕掑▎鎴濆闂佽鍠栭悥鐓庣暦濠靛棛鏆嗛柛鏇ㄥ€犺閺岀喖姊荤€靛壊妲紓浣哄У閻楃娀骞冨畡鎵虫瀻闊洦鎼╂禒鐐節濞堝灝鐏￠柟鍛婂▕楠炲啫鐣￠幍铏€婚棅顐㈡处閹尖晜瀵奸埀顒勬⒒娴ｅ憡鍟為柛鈺侊功閹广垹鈹戦崱鈺傜稁闂佺粯鍨堕敋妞ゆ洝椴哥换娑㈠幢濡櫣浠奸柡宥忕節濮婄粯鎷呴崨濠傛殘闂佽鎮傜粻鏍х暦娴兼潙鍐€妞ゆ挾鍠庢禒鎺戭渻閵堝棙顥堥柡渚囧櫍瀹曟垿骞樼紒妯绘珳闁硅偐琛ラ崜婵嬫倶閸垻纾藉ù锝呮惈鏍＄紓浣割儐鐢剝淇婇悽绋跨妞ゆ柨澧介弶鎼佹⒑閸︻厼浜鹃柟顖氳嫰铻為柕鍫濐槹閻撱垽鏌涢幇鈺佸闁肩缍婇弻宥囨喆閸曨偆浼岄梺璇″枟閻熴儵顢欒箛娑辨晩闁稿繒鈷堥崯鈧┑鐘垫暩婵敻顢欓弽顓為棷闁挎繂娲ㄦ稉宥夋煛瀹ュ骸骞戦柍褜鍏涚粈渚€锝炲┑瀣疀濞达絽澧ｉ鍫熲拻濞撴埃鍋撻柍褜鍓氱粙鎾诲煘閹烘鐓曢柡鍐ｅ亾闁搞劌娼￠悰顕€宕橀纰辨綂闂侀潧鐗嗛幊鎰八囪缁辨帡鎮欓鈧婊冾渻鐎涙ɑ鍊愰挊鐔兼煕椤愮姴鍔滈柍閿嬪笒閵嗘帒顫濋敐鍛婵犵數鍋橀崠鐘诲川椤旂厧绨ラ梻浣虹《閸撴繄绮欓幋鐘电焼闁割偆鍠撶弧鈧梻鍌氱墛缁嬫挻鏅跺☉娆嶄簻闁归偊浜為惌娆撴煛瀹€鈧崰鎾舵閹烘嚦鐔兼惞鐠団剝鏁ら梺鑽ゅ枑缁孩鏅跺Δ鍐╂殰婵°倕鎳庣壕濠氭煙閹殿喖顣奸柣鎾跺Х閻ヮ亪寮堕崹顔垮煘闂佸憡妫忛崳锝夊蓟閺囷紕鐤€濠电姴鍟▍姘舵⒑缁嬫鍎愰柟鎼佺畺楠炲骞橀鑲╊槹濡炪倖甯掗崑鍡椢ｉ悷鎵虫斀闁绘劘灏欓幗鐘电磼椤旇偐绠伴柍缁樻煥閳藉濮€閳ュ厖鎮ｉ梻浣虹帛閸ㄥ吋鎱ㄩ妶澶婄９濠电姵纰嶉悡銏′繆椤栨粌鐨戠紒杈ㄥ哺閺屽秹宕崟顒€娅ら梺缁樻尪閸庤尙鎹㈠┑瀣棃婵炴垶鐟Λ鐐烘⒑闁偛鑻晶顕€鏌熺拠褏纾跨紒顔碱儏椤撳吋寰勬繝鍕垫Ф闁荤喐绮岄ˇ闈涚暦閹达箑绠婚悹鍥皺椤ρ勭節閵忥絾纭鹃柨鏇稻缁旂喖寮撮姀鈾€鎷绘繛杈剧到閹诧繝宕悙鐑樼厱闁哄啯鎹囧顔剧磼閸屾氨效闁诡喗鐟︾粭鐔碱敍濞戞瑦鐝﹂梻鍌欑濠€閬嶅磿閵堝鈧啴宕卞☉娆忎簵闂佺粯鏌ㄩ崥瀣偂閻斿吋鐓欓梺顓ㄧ畱婢у鏌涢妶鍥ф灈闁哄苯绉归幐濠冨緞濡亶锕傛⒑鐎圭媭娼愰柛銊ョ仢閻ｇ兘骞掗幋鏃€顫嶅┑鐐叉钃辨い銉ョ墦濮婄粯鎷呮笟顖涙暞濠电偛鎳忓ú鐔煎箖閻戣棄鐓涢柛娑卞灠缁侊箓鏌ｆ惔顖滅У闁哥姵顨婂鎻掆攽鐎ｎ偆鍘撻悷婊勭矒瀹曟粌鈹戦崼鐔峰簥濠殿喗顭堥崺鏍偂閻旂厧绠归弶鍫濆⒔绾惧潡鏌ｉ敐搴″籍闁哄本绋掗幆鏃堝Χ閸曨偅鍎撻梻浣烘嚀缁犲秹宕规禒瀣祦闁圭儤鍤﹂弮鍫濈闁靛ě浣镐喊闂傚倸鍊风欢姘焽瑜忛幑銏ゅ醇閵夈儳锛欓梺鍝勭▉閸樹粙宕戦崒鐐寸厸闁搞儮鏅涢弸鏃傜磼閻樿崵鐣洪柡灞剧洴閸╁嫰宕楅悪鈧禍婵嬪箞閵娾晛鐐婃い鎺嶈兌閸樹粙妫呴銏℃悙妞ゆ垵鎳樺畷婵嬪Χ閸モ晝锛滈柡澶婄墑閸斿苯霉椤旈敮鍋撳▓鍨珮闁革綇绲介悾鐑藉箳閹搭厽鍍靛銈嗗坊閸嬫挾绱掗悪鍛ɑ缂佺粯绻傞埢鎾诲垂椤旂晫浜梻浣瑰濞插繘宕愬┑瀣伋闁挎洖鍊归悡銉╂倵閿濆倹娅囩紒鐘冲哺濮婇缚銇愰幒鎿勭吹闂佺粯甯粻鎾愁嚕閹绘帩鐓ラ柛娑卞灣閿涙繃绻涙潏鍓хК闁稿鍊块獮瀣偐閻㈢數鍔告俊鐐€栭弻銊╂儍閻戣棄缁╁ù鐘差儐閻撱儲绻涢幋鐏活亪顢旈埡浼辩懓顭ㄩ崘锕€浠梺鍝勬湰缁嬫帞鎹㈠┑瀣闁绘劦鍓涢弳顓㈡⒒娴ｅ憡鍟為悽顖涱殘閺侇噣鏁撻悩顔瑰亾娴ｇ硶妲堟俊顖滃仦鐢繝骞婇弽顓炵厸濞达絽婀遍埀顒勭畺閹宕归锝囧嚒闁诲孩鍑归崢楣冨箲閵忕姭鏀介柛銉ｅ妼閸斿懘姊洪弬銉︽珔闁哥姵宀稿畷銉モ枎閹剧补鎷婚梺绋挎湰閸戝綊鎮￠鍕厱閻庯綆浜滈鈺呮偂閵堝鐓ラ柡鍥╁仜閳ь剙鎽滅划鍫ュ礋椤撶喎鏋戦梺缁橆殔閻楀棛绮幒妤佸仭婵炲棙鐟ч悾鐢告煛鐏炵晫啸妞ぱ傜窔閺屾盯骞樼捄鐑樼€诲?running闂?
     *
     * @param array<string, mixed> $scope
     */
    public function hasUnfinishedBlueprintTasks(array $scope): bool
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴銏℃櫆缂備焦蓱濞呭牓姊绘担鍛婂暈濞撴碍顨婂畷褰掝敂閸繄锛涢梺鍦亾閸撴艾顭囬埡鍛厽闁圭偓濞婇妤併亜椤愵偄浜炵紒杈ㄦ尰閹峰懏绂掔€ｎ亝鎳欓梻浣告贡閹虫挸煤椤撱垺鍋樻い鏂挎閻旇桨鐒婇柡宓倸顥氶梻浣圭湽閸ㄥ寮幖浣肝ュ┑鐘叉处閻撴盯鎮楅敐搴′簽濠⒀呮暬閺屸€崇暆鐎ｎ剛袦闂佽鍠掗弲鐘茬暦閿濆棗绶為悗锝庡亜閳ь剛鍏橀弻锝嗘償閵忊晛鏅遍梺鍝ュУ閻楃娀骞冮妷鈺傚亗閹艰揪绲惧▓楣冩⒑閸濆嫭鍌ㄩ柛銊ョ秺瀹曪繝骞庨懞銉у帾闂婎偄娲㈤崕宕囧閸ф鐓曟俊顖涘椤ュ鏌嶇憴鍕伌闁诡喒鏅濋幏鐘侯槻濞村吋鍔栨穱濠囧Χ閸ヮ灝锝夋煙椤旂厧鈧潡鐛崘顭戞建闁逞屽墴瀵偊宕橀鑲╋紲濠电偞鍨堕懝鍓ф暜濡ゅ懏鐓熼柣鏂挎憸閻绱掗鑺ュ碍闁伙絽鍢茶灃闁逞屽墴閿濈偛顭ㄩ崼婵堝姦濡炪倖宸婚崑鎾绘煟閿濆洤鍘存鐐差儔閺佸啴鍩€椤掑倻涓嶉柤濮愬€楃壕钘壝归敐鍫殐闁绘帊绮欓弻娑橆潨閳ь剚绂嶇捄渚綎婵炲樊浜濋崑锟犳煙濞堝灝鏋熼柟鑼跺亹缁辨挻鎷呯粵瀣闂佸摜鍠愰幐鎶芥偘椤曗偓瀹曞崬鈽夊鈧崬鍫曟⒑闂堟侗妾у┑鈥虫喘瀹曘垽妫冨☉杈ㄥ瘜闂侀潧鐗嗗Λ娆撳煕閹烘鐓熼柍鈺佸暞閻撱儵鏌嶇紒妯诲碍妞ゎ厹鍔戝畷銊╊敂閸曨亜顥氭繝鐢靛仜閻楀棝鎮樺┑瀣嚑闁哄啫鐗婇悡鏇㈡煛閸愶絽浜鹃梺鎼炲妼濞硷繝鎮伴鐣岀瘈闁稿被鍊楅崣鍡涙⒑閸撴彃浜濈紒璇插€块幃妤咁敇閻戝棙瀵岄梺闈涚墕濡鎱ㄨ缁辨帡骞撻幒鎾充淮閻庢鍠楁繛濠囧箖閵忣澀鐒婂ù锝堫潐閺夋悂姊绘担铏瑰笡闁告梹娲栬灒濠电姴娲ら崥褰掓煟閺傝法娈遍柡鈧懞銉ｄ簻闁哄洦顨呮禍鍓х磽娴ｅ搫校闁绘濞€婵″瓨鎷呴崜鍙夊兊闂佸綊顣﹂悞锔界搹闂傚倸鍊风欢姘跺焵椤掍胶銆掗柍瑙勫浮閺屾盯寮埀顒勫垂閸ф宓侀柛鎰靛枛椤懘鏌曢崼婵囶棞濞存粍顨婇弻鐔兼嚃閳哄媻澶愭煃瑜滈崜婵嗏枍閺囥垹姹查煫鍥ㄧ⊕閳锋帡鏌涚仦鍓ф噮闁告柨绉归弻鐔碱敊閼测晛鐓熼悗瑙勬礃濞茬喖骞冨鍏剧喓鍠婃潏銊╂暅闂傚倷绀佹竟濠囧磻閸涱劶娲冀椤撶喎娈濋悷婊呭鐢鎮￠弴鐔翠簻闁规澘澧庣粙鑽ょ磼閳ь剟宕橀鐣屽幗闂佽鍎冲畷顒勫礉濠婂懐纾肩紓浣诡焽濞插瓨顨ラ悙宸剶闁诡喗鐟╁畷褰掝敃閵忥紕褰插┑鐘垫暩婵即宕规總闈╃稏濠㈣埖鍔栭弲婵嬫煏韫囧鈧洟鎷戦悢鍝ョ闁瑰鍎愰悞鎼佹煟閺傚灝鎮戦柛銈嗗浮閺屾洟宕煎┑鍥舵闂佹悶鍔戞禍鍫曞蓟閿濆棙鍎熼柕鍫濆缂嶅牆鈹戦悙鎻掔骇闁挎洏鍨归悾鐤亹閹烘挸浠洪梻鍌氱墐閺呮繄绮欒箛鎾斀闁绘绮☉褎銇勯幋鐐插鐎殿噮鍋婂畷姗€顢欓悾灞藉汲闂備礁鎼崯鐘诲磻閹剧粯鐓曢幖杈剧磿閿涘秶绱掗鑲╁ⅵ鐎规洖銈告俊鐑芥晜鐟欏嫬顏烘繝鐢靛仩閹活亞绱為埀顒佺箾閸滃啰鎮奸柡渚囧枛閳藉濮€閿涘嫬甯鹃梻浣规偠閸庮垶宕濇惔鈭惰櫣鈧數纭堕崑鎾斥枔閸喗鐏嶉悷婊勬緲閸熸挳宕洪妷锕€绶為柟閭﹀墻濞煎﹪姊洪崘鍙夋儓闁稿﹦鎳撻埢宥夊籍閳ь剚绌辨繝鍥ㄥ€锋い蹇撳閸嬫捇寮借濞兼牕鈹戦悩宕囶暡闁稿鍊濋弻锟犲礃閵娧冾杸闂佺锕﹂弫濠氬蓟閿涘嫪娌悹鍥ㄥ絻婵洟姊虹紒妯诲鞍闁荤啿鏅犲濠氬焺閸愩劎绐炴繝鐢靛Т鐎氼亪鎼规惔鈽嗘富闁靛牆绻掗崚鎵棯缂併垹骞樻俊鍙夊姍楠炴帒螖閳ь剟鎮為崹顐犱簻闁瑰搫绉堕ˇ锔剧磼閸撲礁浠遍柡宀€鍠栭弻鍥晝閳ь剟鐛鈧弻娑橆潨閳ь剚绂嶇捄渚綎濡わ箒锟ユ禍褰掓煙閻戞ê鐏ｉ柛鐘诧躬濮婃椽宕ㄦ繝鍐ㄩ瀺缂備礁顑嗛崹鍧楀箖濞差亶鏁囬柣鏃囨閻﹀牓姊哄Ч鍥х伈婵炰匠鍡忓彺闂傚倷鑳堕…鍫ヮ敄閸℃稑绠板┑鐘宠壘妗呴梺鍛婃处閸犳岸鎮块埀顒勬⒑閸︻厼浜炬繛鍏肩懃閳诲秷顦叉い顏勫暣婵″爼宕掑☉娆戝綃婵＄偑鍊戦崕鏌ュ箲閸ヮ剙鏄ラ柍褜鍓氶妵鍕箳閸℃ぞ澹曠紓鍌欒兌婵绮旈悷鎵殾闁哄洢鍨圭涵鈧梺缁樺姇缁夌數绮欓幋锕€鐓濋幖娣妼缁狅綁鏌ｅΟ澶稿惈闁?stuck running 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴銏℃櫇闁逞屽墴閹潡顢氶埀顒勫蓟濞戙垹绠涙い鎾跺О閸嬬偤姊洪崫鍕靛剱闁绘顨堥幑銏犫槈閵忕姷顓哄┑鐐叉缁绘帗绂掓ィ鍐┾拺?running 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柣鎴ｅГ閸ゅ嫰鏌涢锝嗙缂佺姷濞€閺岀喖骞戦幇闈涙闁瑰吋娼欓敃顏堝蓟閿涘嫪娌悹鍥ㄥ絻婢规劙姊洪幖鐐插姶闁告搫绠撳顐㈩吋閸℃ê寮?pending闂?
     * 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮撮悙娴嬪亾閸洖鐒垫い鎺嗗亾闁哥喎纾划璇测槈濡攱顫嶅┑鈽嗗灣閳峰牆危椤栨稓绡€闁汇垽娼ф禒锕傛煕閵娿劌鐓愮紒宀勪憾閹粌螣鐠囨彃浼庨梻浣筋潐閸庡吋鎱ㄩ妶澶嬪亗闁告劦鍠楅悡鏇熺節闂堟稒顥滄い蹇婃櫊閺屽秷顧侀柛鎾寸箞閿濈偞寰勯幇顒傜杽闂侀潧顭堥崕娲偂閵夆晜鐓曢柡鍥殕濞呭啰绱掗妸銉吋婵﹥妞藉畷顐﹀礋椤愶絾顔勯梻浣侯焾閿曪箓寮繝姘卞祦闊洦绋掗弲鎼佹煟濡櫣锛嶉柛姗€浜跺娲濞戞艾顣哄┑鐐茬湴閸婃洟鎮洪鐔剁箚闁绘劦浜滈埀顑惧€濆畷鎴﹀川濞ｎ兘鍋撻崘顔奸唶闁靛繆妲呭鐔兼⒑閸︻厼鍔嬮柛銈忕畵閹垽鎮℃惔婵堢倞闂備礁鎲″ú鏍倶濮樿京绀婇悘鐐插⒔缁♀偓闂佹眹鍨藉褍鏆╂俊鐐€х徊鑲╁垝濞嗗繒鏆︽繝濠傚暊閺€浠嬫倵閿濆簼绨介柣锝嗘そ濮婅櫣绮欓幐搴㈡嫳缂備礁顑嗛崝妤呭箲閵忋倕骞㈡繛鎴炵懃閳ь剛鏁婚弻锝夊閻樺啿鏆堝┑鐐叉噺缁秶鎹㈠☉妯兼殕濠电姳绶氶崑妤呮⒑閸濆嫮鐒跨紒鐘冲灱閻忔帗绻涢幘鏉戝毈闁搞劏浜悷褍鈹戦悩鍨毄闁稿鐩幃褔宕熼姘憋紵闂傚倸鐗婃笟妤€顭囬弽銊х鐎瑰壊鍠曠花璇裁归懖鈺佲枅闁哄本鐩鎾Ω閵夈儳顔愭俊鐐€х徊浠嬪箹椤愶腹鈧棃宕橀鍢壯囩叓閸ャ劍灏垫俊璇х秮濮婃椽宕妷銉愶絾銇勯妸銉含濠碘剝鎸冲畷姗€顢欓崲澶堝姂閺屽秵娼幏灞藉帯闂佺锕﹂崑娑⑩€旈崘顔嘉ч柛鈩冾殘閻熸劗绱撴担鎻掍壕婵炶揪绲藉﹢閬嶃€呴弻銉︹拺妞ゆ巻鍋撶紒澶屾暬閸╂盯骞嬮敂钘夆偓鐢告煕閿旇骞栫悮銊╂⒑闁偛鑻崢鎾煕鐎ｎ偅宕屾慨濠呮缁瑥鈻庨幆褍澹夐梻浣告贡椤牊鏅堕挊澹╋綁骞囬弶璺唺濠德板€愰崑鎾绘煢閸愵亜鏋涢柡灞炬礃缁旂喖顢涘顓炴濠殿噯绲藉ú顓㈠蓟閿濆棙鍎熼柕蹇婃櫅閺呴亶姊洪崫銉バｇ€光偓缁嬫鍤曟い鎰剁悼缁♀偓濠殿喗菧閸庮噣宕戦幘瀵哥懝闁逞屽墮椤曪綁顢氶埀顒€鐣烽悡搴樻斀闁割偅绺鹃崑鎾绘倷閻戞ǚ鎷洪梻鍌氱墛娓氭鎮炴ィ鍐╃厱閹兼番鍊濋崫娲煙?done闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣椤愯姤鎱ㄥ鍡楀⒒闁绘帞鏅幉绋款吋閸澀缃曞┑鐘茬棄閺夊簱鍋撻弴銏犵柈濞寸厧鐡ㄩ崕妤呮煙閸撗呭笡闁稿绲借灃闁挎洑绶ゆ蹇涙煃瑜滈崗姗€宕戦幘缁樷拺闁告繂瀚烽崕鎰繆椤愩垹鏆ｇ€殿喖顭烽幃銏㈡偘閳ュ厖澹曢梺姹囧灪椤旀牠鎮為幆顬″綊鎮╁▎蹇擃仴濞存粍绮撻弻宥夊传閸曨偅娈梺鍛娚戦幐鎶藉蓟閻旂⒈鏁婇柤娴嬫櫇妤旈柣搴ゎ潐濞叉牠鎮ラ崗闂寸箚闁归棿绀佸敮閻熸粌绻樻俊鍫曟煥鐎ｂ晝绠氶梺缁樺姦娴滄粓鍩€椤戞儳鈧繂鐣烽幋鐐电懝闁逞屽墮閻ｇ兘骞囬弶鍨祮闂佺偨鍎辩壕顓㈠磹閹烘鈷掗柛灞剧懅閸斿秹鏌熼鑲╁煟鐎规洘娲熷鍫曞箣閺冣偓閻忓啫鈹戦悙鏉戠仧闁搞劌缍婂畷娆撴偐缂佹鍘介梺鍝勫暊閸嬫捇鏌熼鍨厫缂佸倸绉撮…銊╁醇閻斿搫骞嶉梻浣虹帛閸ㄦ儼鎽柣蹇撶箳閺佸寮婚敐澶樻晣闁绘垵妫楅崜宕囩磽?done闂?
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function finalizePlanJsonTaskStatesAfterRunLoop(array $scope): array
    {
        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope, true);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $summary = $this->summarize($scope);
        if (!$this->shouldAttachBuildRenderDataContract($scope, $summary)) {
            if ((int)($summary['running'] ?? 0) > 0) {
                return $this->resetRunningTasksForInterruptedBuild(
                    $scope,
                    (string)__('Interrupted build reset running tasks to pending.')
                );
            }

            return $scope;
        }
        if ((int)($summary['running'] ?? 0) <= 0) {
            return $this->attachBuildRenderDataContract($scope);
        }
        $scope = $this->resetRunningTasksForInterruptedBuild(
            $scope,
            (string)__('Interrupted build reset running tasks to pending.')
        );
        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope, true);
        $scope = $this->clearResolvedRetryableAiFailures($scope);

        return $this->attachBuildRenderDataContract($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $summary
     */
    private function shouldAttachBuildRenderDataContract(array $scope, array $summary): bool
    {
        if ((int)($summary['total'] ?? 0) <= 0) {
            return false;
        }
        if ((int)($summary['pending'] ?? 0) > 0
            || (int)($summary['running'] ?? 0) > 0
            || (int)($summary['failed'] ?? 0) > 0
            || (int)($summary['cancelled'] ?? 0) > 0
            || (int)($summary['done'] ?? 0) < (int)($summary['total'] ?? 0)
        ) {
            return false;
        }

        $gate = $this->inspectBuildCompletionGate($scope);

        return !empty($gate['passed']);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function attachBuildRenderDataContract(array $scope): array
    {
        $scope = $this->syncPageTypeLayoutsWithSharedComponents($scope);
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $PlanJson = [
            'pages' => \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [],
            'contract_meta' => [
                'contract_id' => 'plan_json',
                'signature' => \trim((string)($planJson['signature'] ?? $planJson['source_signature'] ?? 'plan_json')),
            ],
            'workspace_track' => \trim((string)($scope['workspace_track'] ?? '')),
        ];
        $executionTasks = $this->extractPlanJsonTasks($scope);
        if ($PlanJson['pages'] === [] || $executionTasks === []) {
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

        $sourceContracts = $this->resolveBuildRenderSourceContracts($PlanJson);
        $payload = $this->buildRenderDataContractPayload($scope, $PlanJson, $summary);
        $meta = \is_array($PlanJson['contract_meta'] ?? null) ? $PlanJson['contract_meta'] : [];
        $contractContext = [
            'version' => 1,
            'stage' => ContractType::STAGE_BUILD,
            'plan_json_contract_id' => \trim((string)($meta['contract_id'] ?? $meta['id'] ?? '')),
            'plan_json_signature' => \trim((string)($meta['signature'] ?? $meta['source_signature'] ?? '')),
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
                    'source_signature' => (string)($contractContext['plan_json_signature'] ?? ''),
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
                    'plan_json',
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $PlanJson
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildRenderDataContractPayload(array $scope, array $PlanJson, array $summary): array
    {
        $pageTypes = [];
        foreach (\is_array($PlanJson['pages'] ?? null) ? $PlanJson['pages'] : [] as $key => $page) {
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

        $meta = \is_array($PlanJson['contract_meta'] ?? null) ? $PlanJson['contract_meta'] : [];

        return [
            'plan_json_contract_id' => \trim((string)($meta['contract_id'] ?? $meta['id'] ?? '')),
            'plan_json_signature' => \trim((string)($meta['signature'] ?? $meta['source_signature'] ?? '')),
            'workspace_track' => \trim((string)($PlanJson['workspace_track'] ?? $scope['workspace_track'] ?? '')),
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
     * @param array<string, mixed> $PlanJson
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function resolveBuildRenderSourceContracts(array $PlanJson): array
    {
        $refs = [];
        $meta = \is_array($PlanJson['contract_meta'] ?? null) ? $PlanJson['contract_meta'] : [];
        $PlanJsonContractId = \trim((string)($meta['contract_id'] ?? $meta['id'] ?? ''));
        if ($PlanJsonContractId !== '') {
            $refs[] = [
                'id' => $PlanJsonContractId,
                'type' => 'plan_json',
                'version' => '1',
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
        $planJsonTasks = $this->extractPlanJsonTasks($scope);
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

        foreach ($planJsonTasks as $task) {
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
                'message' => $this->sanitizePlanJsonTaskFailureMessageForView((string)($taskState[$taskKey]['message'] ?? ''), ''),
                'updated_at' => (string)($taskState[$taskKey]['updated_at'] ?? ''),
                'finished_at' => (string)($taskState[$taskKey]['finished_at'] ?? ''),
            ];
        }

        return $summary;
    }

    /**
     * Build completion gate is sourced from plan_json.pages block status nodes.
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
        $pageBlockProgress = $this->inspectBuildCompletionPageBlockProgress($scope);
        $pageBlockShortfalls = \is_array($pageBlockProgress['shortfalls'] ?? null) ? $pageBlockProgress['shortfalls'] : [];
        $PlanJsonMissingStageOneBlocks = \is_array($pageBlockProgress['missing_stage1_plan_block_nodes'] ?? null)
            ? $pageBlockProgress['missing_stage1_plan_block_nodes']
            : [];
        $defaultTemplatePageLayouts = \is_array($pageBlockProgress['default_template_page_layouts'] ?? null)
            ? $pageBlockProgress['default_template_page_layouts']
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
            || $PlanJsonMissingStageOneBlocks !== []
            || $defaultTemplatePageLayouts !== []
            || $pageBlockShortfalls !== []
            || $done < $total;

        $reason = '';
        if ($total <= 0) {
            $reason = 'missing_plan_json_block_nodes';
        } elseif ($failed > 0) {
            $reason = 'failed_plan_json_block_nodes';
        } elseif ($cancelled > 0) {
            $reason = 'cancelled_plan_json_block_nodes';
        } elseif ($invalidArtifacts > 0) {
            $reason = 'invalid_generated_artifacts';
        } elseif ($duplicateArtifacts > 0) {
            $reason = 'duplicate_generated_artifacts';
        } elseif ($missingBuildPageTypes !== []) {
            $reason = 'missing_plan_json_page_types';
        } elseif ($missingPageTypeLayouts !== []) {
            $reason = 'missing_page_type_layouts';
        } elseif ($emptyPageTypeLayouts !== []) {
            $reason = 'empty_page_type_layouts';
        } elseif ($missingPersistedVirtualThemeLayouts !== []) {
            $reason = 'missing_persisted_virtual_theme_layouts';
        } elseif ($PlanJsonMissingStageOneBlocks !== []) {
            $reason = 'plan_json_missing_stage1_block_nodes';
        } elseif ($defaultTemplatePageLayouts !== []) {
            $reason = 'default_template_page_layouts';
        } elseif ($pageBlockShortfalls !== []) {
            $reason = 'incomplete_page_block_counts';
        } elseif ($unfinished > 0) {
            $reason = 'unfinished_plan_json_block_nodes';
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
            'page_block_progress' => $pageBlockProgress,
            'page_block_shortfalls' => $pageBlockShortfalls,
            'plan_json_missing_stage1_block_nodes' => $PlanJsonMissingStageOneBlocks,
            'default_template_page_layouts' => $defaultTemplatePageLayouts,
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
     * @return array{expected_page_types:list<string>,rows:list<array<string,mixed>>,shortfalls:list<array<string,mixed>>}
     */
    public function inspectBuildCompletionPageBlockProgress(array $scope): array
    {
        $expectedPageTypes = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
        $rows = [];
        foreach ($expectedPageTypes as $pageType) {
            $rows[$pageType] = $this->emptyPageBlockProgressRow($pageType);
        }

        $expectedBlocks = $this->collectExpectedPlanJsonPageBlockNodes($scope);
        foreach ($expectedBlocks as $pageType => $blocks) {
            $rows[$pageType] ??= $this->emptyPageBlockProgressRow((string)$pageType);
            $rows[$pageType]['expected_block_nodes'] = \count($blocks);
            $rows[$pageType]['expected_block_codes'] = $this->extractExpectedPageBlockCodes($blocks, 'section_code');
            $rows[$pageType]['expected_block_ids'] = $this->extractExpectedPageBlockCodes($blocks, 'block_id');
            $rows[$pageType]['expected_block_keys'] = $this->extractExpectedPageBlockCodes($blocks, 'block_key');
        }

        $stageOneBlocks = $this->collectExpectedStageOnePlanPageBlockNodes($scope);
        foreach ($stageOneBlocks as $pageType => $blocks) {
            $rows[$pageType] ??= $this->emptyPageBlockProgressRow((string)$pageType);
            $rows[$pageType]['stage1_expected_block_nodes'] = \count($blocks);
            $rows[$pageType]['stage1_expected_block_codes'] = $this->extractExpectedPageBlockCodes($blocks, 'section_code');
            $rows[$pageType]['missing_stage1_plan_block_codes'] = $this->missingStringSet(
                $rows[$pageType]['stage1_expected_block_codes'],
                $rows[$pageType]['expected_block_codes']
            );
        }

        $taskState = $this->extractTaskState($scope);
        $completedByPage = [];
        $executableByPage = [];
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
            if ((string)($task['task_type'] ?? '') !== 'page_section') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType === '') {
                continue;
            }
            $rows[$pageType] ??= $this->emptyPageBlockProgressRow($pageType);
            $rows[$pageType]['executable_block_nodes'] = (int)$rows[$pageType]['executable_block_nodes'] + 1;
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            if ($sectionCode !== '') {
                $executableByPage[$pageType][$sectionCode] = true;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_DONE && $this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                $rows[$pageType]['completed_block_nodes'] = (int)$rows[$pageType]['completed_block_nodes'] + 1;
                if ($sectionCode !== '') {
                    $completedByPage[$pageType][$sectionCode] = true;
                }
            }
        }

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        foreach ($rows as $pageType => $row) {
            $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
            $rows[$pageType]['layout_block_nodes'] = $this->countBuiltLayoutContentBlocks($layout);
            $rows[$pageType]['layout_block_codes'] = $this->collectLayoutSectionCodes($layout);
            $rows[$pageType]['missing_layout_block_codes'] = $this->missingSectionIdentitySet(
                $rows[$pageType]['expected_block_codes'],
                $rows[$pageType]['layout_block_codes']
            );
            $rows[$pageType]['missing_executable_block_codes'] = $this->missingStringSet(
                $rows[$pageType]['expected_block_codes'],
                \array_values(\array_keys($executableByPage[$pageType] ?? []))
            );
            $rows[$pageType]['missing_completed_block_codes'] = $this->missingStringSet(
                $rows[$pageType]['expected_block_codes'],
                \array_values(\array_keys($completedByPage[$pageType] ?? []))
            );
            $rows[$pageType]['has_default_template_markers'] = $this->arrayContainsDefaultTemplateMarkers($layout);
            $rows[$pageType]['persisted_layout_block_nodes'] = 0;
            $rows[$pageType]['persisted_layout_block_codes'] = [];
            $rows[$pageType]['missing_persisted_layout_block_codes'] = [];
            $rows[$pageType]['persisted_layout_has_default_template_markers'] = false;
            if ($this->requiresPersistedVirtualThemeLayoutCheck($scope)) {
                $persistedLayout = $this->loadPersistedVirtualThemeLayoutConfig($scope, (string)$pageType);
                $rows[$pageType]['persisted_layout_block_nodes'] = $this->countBuiltLayoutContentBlocks($persistedLayout);
                $rows[$pageType]['persisted_layout_block_codes'] = $this->collectLayoutSectionCodes($persistedLayout);
                $rows[$pageType]['missing_persisted_layout_block_codes'] = $this->missingSectionIdentitySet(
                    $rows[$pageType]['expected_block_codes'],
                    $rows[$pageType]['persisted_layout_block_codes']
                );
                $rows[$pageType]['persisted_layout_has_default_template_markers'] = $this->arrayContainsDefaultTemplateMarkers($persistedLayout);
            }
        }

        $shortfalls = [];
        $missingStageOneBlocks = [];
        $defaultTemplatePageLayouts = [];
        foreach ($rows as $pageType => $row) {
            $expected = (int)($row['expected_block_nodes'] ?? 0);
            $stageOneExpected = (int)($row['stage1_expected_block_nodes'] ?? 0);
            $executable = (int)($row['executable_block_nodes'] ?? 0);
            $completed = (int)($row['completed_block_nodes'] ?? 0);
            $layout = (int)($row['layout_block_nodes'] ?? 0);
            $missingStageOneBlockCodes = \is_array($row['missing_stage1_plan_block_codes'] ?? null) ? $row['missing_stage1_plan_block_codes'] : [];
            $missingExecutableBlockCodes = \is_array($row['missing_executable_block_codes'] ?? null) ? $row['missing_executable_block_codes'] : [];
            $missingCompletedBlockCodes = \is_array($row['missing_completed_block_codes'] ?? null) ? $row['missing_completed_block_codes'] : [];
            $missingLayoutBlockCodes = \is_array($row['missing_layout_block_codes'] ?? null) ? $row['missing_layout_block_codes'] : [];
            $missingPersistedLayoutBlockCodes = \is_array($row['missing_persisted_layout_block_codes'] ?? null)
                ? $row['missing_persisted_layout_block_codes']
                : [];
            $hasDefaultTemplateMarkers = !empty($row['has_default_template_markers'])
                || !empty($row['persisted_layout_has_default_template_markers']);
            $complete = $expected > 0
                && ($stageOneExpected <= 0 || $expected >= $stageOneExpected)
                && $executable >= $expected
                && $completed >= $expected
                && $layout >= $expected
                && $missingStageOneBlockCodes === []
                && $missingExecutableBlockCodes === []
                && $missingCompletedBlockCodes === []
                && $missingLayoutBlockCodes === []
                && $missingPersistedLayoutBlockCodes === []
                && !$hasDefaultTemplateMarkers;
            $rows[$pageType]['complete'] = $complete;
            if ($missingStageOneBlockCodes !== [] || ($stageOneExpected > 0 && $expected < $stageOneExpected)) {
                $missingStageOneBlocks[] = [
                    'page_type' => (string)$pageType,
                    'missing_block_codes' => $missingStageOneBlockCodes,
                    'stage1_expected_block_nodes' => $stageOneExpected,
                    'plan_json_expected_block_nodes' => $expected,
                ];
            }
            if ($hasDefaultTemplateMarkers) {
                $defaultTemplatePageLayouts[] = (string)$pageType;
            }
            if (!$complete) {
                $shortfalls[] = [
                    'page_type' => (string)$pageType,
                    'expected_block_nodes' => $expected,
                    'executable_block_nodes' => $executable,
                    'completed_block_nodes' => $completed,
                    'layout_block_nodes' => $layout,
                    'persisted_layout_block_nodes' => (int)($row['persisted_layout_block_nodes'] ?? 0),
                    'missing_stage1_plan_block_codes' => $missingStageOneBlockCodes,
                    'missing_executable_block_codes' => $missingExecutableBlockCodes,
                    'missing_completed_block_codes' => $missingCompletedBlockCodes,
                    'missing_layout_block_codes' => $missingLayoutBlockCodes,
                    'missing_persisted_layout_block_codes' => $missingPersistedLayoutBlockCodes,
                    'has_default_template_markers' => $hasDefaultTemplateMarkers,
                ];
            }
        }

        return [
            'expected_page_types' => $expectedPageTypes,
            'rows' => \array_values($rows),
            'shortfalls' => $shortfalls,
            'missing_stage1_plan_block_nodes' => $missingStageOneBlocks,
            'default_template_page_layouts' => \array_values(\array_unique($defaultTemplatePageLayouts)),
        ];
    }

    /**
     * @return array{page_type:string,expected_block_nodes:int,executable_block_nodes:int,completed_block_nodes:int,layout_block_nodes:int,complete:bool}
     */
    private function emptyPageBlockProgressRow(string $pageType): array
    {
        return [
            'page_type' => $pageType,
            'expected_block_nodes' => 0,
            'stage1_expected_block_nodes' => 0,
            'executable_block_nodes' => 0,
            'completed_block_nodes' => 0,
            'layout_block_nodes' => 0,
            'persisted_layout_block_nodes' => 0,
            'expected_block_codes' => [],
            'expected_block_ids' => [],
            'expected_block_keys' => [],
            'stage1_expected_block_codes' => [],
            'layout_block_codes' => [],
            'persisted_layout_block_codes' => [],
            'missing_stage1_plan_block_codes' => [],
            'missing_executable_block_codes' => [],
            'missing_completed_block_codes' => [],
            'missing_layout_block_codes' => [],
            'missing_persisted_layout_block_codes' => [],
            'has_default_template_markers' => false,
            'persisted_layout_has_default_template_markers' => false,
            'complete' => false,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, list<array{page_type:string,block_id:string,block_key:string,section_code:string,task_key:string}>>
     */
    private function collectExpectedPlanJsonPageBlockNodes(array $scope): array
    {
        $selected = $this->buildStringSet($this->normalizePlanJsonStringList($scope['page_types'] ?? []));
        $blocksByPage = [];
        foreach ($this->extractPlanJsonPages($scope) as $pageType => $page) {
            if ($pageType === '' || ($selected !== [] && !isset($selected[$pageType]))) {
                continue;
            }
            foreach ($this->extractPlanJsonPageBlockNodes($page) as $blockKey => $block) {
                $sectionKey = \trim((string)($block['section_key'] ?? $block['block_key'] ?? $blockKey));
                if ($sectionKey === '') {
                    continue;
                }
                $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? ''));
                if ($blockId === '') {
                    $blockId = $pageType . '.' . $sectionKey;
                }
                $sectionCode = \trim((string)($block['section_code'] ?? $block['component_code'] ?? ''));
                if ($sectionCode === '') {
                    $sectionCode = $this->resolvePlanJsonSectionCode($pageType, $sectionKey, $blockId);
                }
                $blocksByPage[$pageType][] = [
                    'page_type' => $pageType,
                    'block_id' => $blockId,
                    'block_key' => $sectionKey,
                    'section_code' => $sectionCode,
                    'task_key' => 'page:' . $pageType . ':' . $sectionCode,
                ];
            }
        }

        return $blocksByPage;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, list<array{page_type:string,block_id:string,block_key:string,section_code:string,task_key:string}>>
     */
    private function collectExpectedStageOnePlanPageBlockNodes(array $scope): array
    {
        $selected = $this->buildStringSet($this->normalizePlanJsonStringList($scope['page_types'] ?? []));
        $blocksByPage = [];
        foreach ($this->extractPlanJsonPages($scope) as $pageType => $page) {
            if ($pageType === '' || ($selected !== [] && !isset($selected[$pageType]))) {
                continue;
            }
            foreach ($this->extractPlanJsonPageBlockNodes($page) as $blockKey => $block) {
                $sectionKey = \trim((string)(
                    $block['section_key']
                    ?? $block['block_key']
                    ?? $block['key']
                    ?? $block['id']
                    ?? (\is_string($blockKey) ? $blockKey : '')
                ));
                if ($sectionKey === '') {
                    continue;
                }
                $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? ''));
                if ($blockId === '') {
                    $blockId = $pageType . '.' . $sectionKey;
                }
                $sectionCode = \trim((string)($block['section_code'] ?? $block['component_code'] ?? ''));
                if ($sectionCode === '') {
                    $sectionCode = $this->resolvePlanJsonSectionCode($pageType, $sectionKey, $blockId);
                }
                $blocksByPage[$pageType][] = [
                    'page_type' => $pageType,
                    'block_id' => $blockId,
                    'block_key' => $sectionKey,
                    'section_code' => $sectionCode,
                    'task_key' => 'page:' . $pageType . ':' . $sectionCode,
                ];
            }
        }

        return $blocksByPage;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<string>
     */
    private function extractExpectedPageBlockCodes(array $blocks, string $field): array
    {
        $values = [];
        foreach ($blocks as $block) {
            $value = \trim((string)($block[$field] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function collectLayoutSectionCodes(array $layout): array
    {
        $codes = [];
        foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $sectionCode = $this->resolveLayoutSectionCode($section);
            if ($sectionCode !== '') {
                $codes[] = $sectionCode;
            }
        }

        return \array_values(\array_unique($codes));
    }

    /**
     * @param list<string> $values
     * @return array<string, true>
     */
    private function buildStringSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value !== '') {
                $set[$value] = true;
            }
        }

        return $set;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     * @return list<string>
     */
    private function missingSectionIdentitySet(array $expected, array $actual): array
    {
        $missing = [];
        foreach ($expected as $expectedCode) {
            $expectedCode = \trim((string)$expectedCode);
            if ($expectedCode === '') {
                continue;
            }
            $found = false;
            foreach ($actual as $actualCode) {
                if ($this->sectionIdentityMatches((string)$actualCode, $expectedCode)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $expectedCode;
            }
        }

        return \array_values(\array_unique($missing));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function loadPersistedVirtualThemeLayoutConfig(array $scope, string $pageType): array
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0 || $pageType === '') {
            return [];
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
            return (int)$layout->getId() > 0 && \is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function arrayContainsDefaultTemplateMarkers(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }
        $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!\is_string($encoded) || $encoded === '') {
            return false;
        }

        foreach ([
            'Default Page Template',
            'This is the default page',
        ] as $marker) {
            if (\stripos($encoded, $marker) !== false) {
                return true;
            }
        }

        return false;
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
        $expected = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
     * @param array<string, mixed> $gate inspectBuildCompletionGate() 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓﹂弫鍐煥閺囨浜鹃梺姹囧€楅崑鎾舵崲濠靛洨绡€闁稿本绮岄。娲⒑閽樺鏆熼柛鐘崇墵瀵寮撮悢铏诡啎闂佸壊鐓堥崰鏍ㄧ珶閸曨偀鏀介柣鎰级閳绘洖霉濠婂嫮鐭掔€规洘锕㈤崺鈧い鎺嗗亾妞ゎ亜鍟存俊鍫曞幢濡儤娈梻浣侯焾椤戝洭宕伴弽顓炴瀬?
     */
    public function formatBuildCompletionGateFailureDetail(array $gate): string
    {
        $reason = \trim((string)($gate['reason'] ?? ''));
        if ($reason === '') {
            return 'Plan JSON build completion gate failed.';
        }

        return 'Plan JSON build completion gate failed: ' . $reason . '.';
    }

    private function formatFailedPlanJsonTaskLines(array $summary): string
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

        return (string)__('婵犵數濮烽弫鍛婃叏閻戣棄鏋侀柛娑橈攻閸欏繐霉閸忓吋缍戦柛銊ュ€圭换娑橆啅椤旇崵鐩庣紒鐐劤濞硷繝寮婚妶鍚ゅ湱鈧綆鍋呴悵鏃堟⒑閹肩偛濡界紒璇茬墦瀵鈽夐姀鐘殿啋濠德板€愰崑鎾绘倵濮樼厧澧寸€殿喗鎮傚畷鎺楁倷缁瀚奸梻浣告贡椤牏鈧稈鏅犻崺娑㈠箳濡や胶鍘搁梺鍛婄矆缁€浣圭娴煎瓨鐓忛柛鈩冾殕閸ゅ洭鏌熼鐣岀煉闁瑰磭鍋ゆ俊鐑藉Ψ閵堝懎顕ч梻鍌氬€烽悞锕傚箖閸洖纾挎い鏍仜缁€澶屸偓鍏夊亾闁逞屽墮椤曘儲绻濋崟顓狅紲濠碘槅鍨堕弲鑼姳鐠囧樊娓婚柕鍫濇婵鈹戦鑺ュ唉闁糕斁鍋撳銈嗗笂閼冲墎绮婚悙纰樺亾?{tasks}', [
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴銏℃櫆缂備焦蓱濞呭牓姊绘担鍛婂暈濞撴碍顨婂畷褰掝敂閸繄锛涢梺鍦亾閸撴艾顭囬埡鍛厽闁圭偓濞婇妤併亜椤愵偄浜炵紒杈ㄦ尰閹峰懏绂掔€ｎ亝鎳欓梻浣告贡閹虫挸煤椤撱垺鍋樻い鏂挎閻旂厧绀傞柣鎾虫捣瑜版挳姊绘担鍛婂暈闁荤喆鍎佃棟闁芥ê锛夊☉銏″亜闁稿繐鐨烽幏娲⒑閻撳寒娼熼柛濠冩礋瀵悂骞嬮敂鐣屽幗闂佺粯姊瑰娆撳礉閵堝鐓冪憸婊堝礈濮橆剦娼╅柕濞炬櫅缁€鍌涗繆椤栨瑨顒熼柛銈嗘礋閺屾洘绻涢悙顒佺彅缂備胶濯崳锝夊蓟閵堝绠掗柟鐑樺灥婵垽姊洪崨濠忚€垮ù婊嗘硾椤繐煤椤忓懎浠梺瑙勵問閸犳骞夐懖鈺冪＝濞达絿鐡旈崵娆撴煕閻斾警妫庢俊顐犲灩閳规垿鎮╃拠褍浼愰柣搴㈠搸閸斿秶绮嬪鍜佺叆闁割偆鍠撻崣鍡涙⒑閸濆嫬鏆欐繛鏉戝€垮畷闈涒枎韫囷絿鍞甸悷婊冪焸瀹曪繝骞庨挊澶庢憰閻庡箍鍎遍悧婊冾瀶閵娾晜鈷戦柛娑橈攻鐏忎即鏌ｉ埡濠傜仸闁绘侗鍠楃换婵嬪炊瑜忛鎺楁煟閻樺弶澶勭憸鏉垮暣閹潧螣娓氼垱瀵岄梺闈涚墕濡绮幒妤佸€垫慨妯煎帶婢у鈧娲栫紞濠傜暦閹烘垟妲堟繛鍡楃箰娴煎孩绻濈喊妯活潑闁搞劏浜埀顒傜懗閸涱喖鍘规俊銈忕到閸燁垶宕戦埄鍐闁糕剝顭囬幊鍛存煟閿濆懐浠涙い銊ｅ劦閹瑧鈧數顭堥埛灞轿旈悩闈涗沪闁绘濞€閵嗕礁顫滈埀顒勫箖濞嗘挸绀傜紒瀣仢椤曆呯磽閸屾艾鈧悂宕愭搴㈠闁哄被鍎辩壕濠氭煙閻愵剙澧柣鏂挎閹娼幏宀婂妳闂佺瀛╅崹鍦閹烘鍋愰柤濮愬€楅弳顐︽⒑閸濆嫮鐏遍柛鐘崇墵閻涱噣骞嬮敃鈧粻娑欍亜閹捐泛孝婵炴嚪鍥ㄢ拻濞撴埃鍋撴繛鑹板吹瀵板﹪鎳栭埡浣哥亰濠电偛妫欓幐鎼佹嫅閻斿吋鐓熼柡鍐ㄥ€甸幏锟犳煛娴ｉ潻鍔熼柣銉邯椤㈡﹢鎮╁畷鍥уЫ闂備礁鎲￠崙褰掑磻婵犲洤绠栨俊銈傚亾闁崇粯鎹囧畷褰掝敊閻ｅ苯钂嬮梻鍌欑閹芥粍鎱ㄩ悽鍛婂剮妞ゆ牗绻冮ˉ銈夋⒒娴ｇ瓔娼愰柛搴″悑閹便劑濡舵径瀣簵闂佺粯姊婚崢褏绮昏ぐ鎺撶厵缁炬澘宕獮鏍煛鐎ｎ偆娲撮柡宀嬬秬缁犳盯寮撮悙鎰剁秮閺屾洟宕惰椤忣厾鈧鍠楅幐鎶藉箖閵忋倖鍊绘俊顖滃劋閻濅即姊虹拠鍙夊攭妞ゎ偄顦叅闁挎洖鍊哥壕璇测攽閻樻彃鈧寮抽敃鍌涚厽闁哄啫鍊甸幏锟犳煛娴ｇ鏆為柕鍥у楠炲洭宕滄担鐟颁还闂備線鈧偛鑻晶濠氭煕鐎ｎ亝顥犳俊鍙夊姍楠炴鎷犻懠顒婄床婵犳鍠楅敃鈺呭礂濮椻偓瀹曟垿骞樼拠鑼啇婵炶揪绲介幗婊堟偘閵夈儮鏀芥い鏃傜摂閻掔偓绻涙径瀣创婵﹤顭烽、娑㈡倷鐎电寮虫繝鐢靛█濞佳兾涢鐐嶏綁骞栨担鍦幐闁诲函绲洪弲婵嬎囬敃鍌涚厓閻熸瑥瀚悘鎾煙椤旇娅婃鐐存崌楠炴帒鈹戦崨顖涳紡闂傚倷娴囬褏鈧稈鏅濋崰濠傤吋閸パ勭€抽悗骞垮劚椤︻垱顢婇梻浣告啞濞诧箓宕规导鏉戠闁逞屽墴濮婃椽妫冨☉鍐蹭紣濠电偠顕滅粻鎴︽偩濠靛牏鐭欓悹鎭掑妽濞?
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
    public function syncPlanJsonTaskFailuresToRetryableLedger(array $scope): array
    {
        $scope = $this->normalizePlanJsonConfirmedState($scope);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $taskSummary = $this->summarize($scope);
        $completionGate = $this->inspectBuildCompletionGate($scope);
        $completionGatePassed = !empty($completionGate['passed']);
        $allPlanJsonTasksComplete = $completionGatePassed
            && $this->isPlanJsonTaskSummaryFullyComplete($taskSummary)
            && !$this->hasUnfinishedBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $existingBuildLedger = $this->getRetryableAiFailures($scope, 'build');
        $existingBuildFailures = \is_array($existingBuildLedger['build']['items'] ?? null)
            ? $existingBuildLedger['build']['items']
            : [];
        if ($allPlanJsonTasksComplete) {
            $existingBuildFailures = [];
            $scope = $this->clearLatestBuildFailureState($scope);
        }
        $failures = [];
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
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
                'item_type' => (string)($task['task_type'] ?? 'plan_json_task'),
                'retry_scope' => 'plan_json_task',
                'page_type' => (string)($task['page_type'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'message' => $this->sanitizePlanJsonTaskFailureMessageForView($message),
                'failed_at' => (string)($state['finished_at'] ?? $state['updated_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        if (!$allPlanJsonTasksComplete && $failures === [] && $existingBuildFailures !== []) {
            $failures = $existingBuildFailures;
        }
        if (
            !$allPlanJsonTasksComplete
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
                'item_type' => (string)($latestBuildFailure['item_type'] ?? 'plan_json_task'),
                'retry_scope' => (string)($latestBuildFailure['retry_scope'] ?? 'plan_json_task'),
                'page_type' => (string)($latestBuildFailure['page_type'] ?? ''),
                'section_code' => (string)($latestBuildFailure['section_code'] ?? ''),
                'message' => $this->sanitizePlanJsonTaskFailureMessageForView((string)(
                    $latestBuildFailure['message']
                    ?? $scope['publish_blocked_reason']
                    ?? ''
                )),
                'failed_at' => (string)($latestBuildFailure['failed_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        $scope = $this->replaceRetryableAiFailures($scope, 'build', $failures);
        if ($failures === [] && $allPlanJsonTasksComplete) {
            $scope = $this->clearLatestBuildFailureState($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function isPlanJsonTaskSummaryFullyComplete(array $summary): bool
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
    private function extractPlanJsonTasks(array $scope, bool $inflate = false): array
    {
        unset($inflate);

        return $this->buildExecutionTasksFromPlanJson($scope);
    }

    /**
     * Build execution units directly from plan_json.pages.{page_type}.{block_key}.
     * The task context only carries the current page/block plus root site/theme
     * context, so no second build-state source is hydrated.
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildExecutionTasksFromPlanJson(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = $this->extractPlanJsonPages($scope);
        if ($planJson === [] || $pages === []) {
            return [];
        }

        $selectedPageTypes = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
        if ($selectedPageTypes !== []) {
            $pages = \array_intersect_key($pages, \array_fill_keys($selectedPageTypes, true));
        }
        if ($pages === []) {
            return [];
        }

        $contentLocale = $this->firstNonEmptyPlanJsonText([
            $scope['ai_content_locale'] ?? null,
            $scope['selected_content_locale'] ?? null,
            $scope['selected_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
            $scope['content_locale'] ?? null,
        ]);
        $languageContract = $this->buildLanguageRuntimeContract($contentLocale);
        $runtimeRoot = $this->planJsonRuntimeContext($scope, $planJson, $contentLocale);
        $sitePlanContext = $this->compactPlanJsonRootForTaskContext($planJson);
        $tasks = [];
        $pageIndex = 0;

        foreach ($pages as $pageType => $page) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $blocks = $this->extractPlanJsonPageBlockNodes($page);
            if ($blocks === []) {
                continue;
            }
            $blockRows = [];
            foreach ($blocks as $blockKey => $block) {
                $blockRows[] = [$blockKey, $block];
            }
            \usort($blockRows, static function (array $left, array $right): int {
                $leftBlock = \is_array($left[1] ?? null) ? $left[1] : [];
                $rightBlock = \is_array($right[1] ?? null) ? $right[1] : [];

                return ((int)($leftBlock['sort_order'] ?? $leftBlock['order'] ?? $leftBlock['position'] ?? 0))
                    <=> ((int)($rightBlock['sort_order'] ?? $rightBlock['order'] ?? $rightBlock['position'] ?? 0));
            });

            foreach ($blockRows as $blockIndex => [$blockKey, $block]) {
                $blockKey = \trim((string)$blockKey);
                if ($blockKey === '' || !\is_array($block)) {
                    continue;
                }
                $blockId = $this->firstNonEmptyPlanJsonText([
                    $block['block_id'] ?? null,
                    $block['id'] ?? null,
                    $pageType . '.' . $blockKey,
                ]);
                $sectionKey = $this->firstNonEmptyPlanJsonText([
                    $block['section_key'] ?? null,
                    $block['block_key'] ?? null,
                    $blockKey,
                ]);
                $sectionCode = $this->firstNonEmptyPlanJsonText([
                    $block['section_code'] ?? null,
                    $block['component_code'] ?? null,
                    $block['code'] ?? null,
                ]);
                if ($sectionCode === '') {
                    $sectionCode = $this->resolvePlanJsonSectionCode($pageType, $sectionKey, $blockId);
                }
                $taskId = 'page:' . $pageType . ':' . $sectionCode;
                $blockType = $this->normalizePlanJsonRoleToken($this->firstNonEmptyPlanJsonText([
                    $block['block_type'] ?? null,
                    $block['type'] ?? null,
                    $block['template'] ?? null,
                    $block['component_type'] ?? null,
                    'section',
                ]));
                $blockType = $blockType !== '' ? $blockType : 'section';
                $pageFlowRole = $this->normalizePlanJsonRoleToken($this->firstNonEmptyPlanJsonText([
                    $block['page_flow_role'] ?? null,
                    $block['flow_role'] ?? null,
                    $block['role'] ?? null,
                ]));
                $contentKeys = $this->normalizePlanJsonStringList($block['content_keys'] ?? []);
                $label = $this->firstNonEmptyPlanJsonText([
                    $block['title'] ?? null,
                    $block['section_title'] ?? null,
                    $block['label'] ?? null,
                    $block['headline'] ?? null,
                    \ucfirst(\str_replace(['_', '-'], ' ', $blockKey)),
                ]);
                $blockGoal = $this->firstNonEmptyPlanJsonText([
                    $block['block_goal'] ?? null,
                    $block['task_goal'] ?? null,
                    $block['why_this_block'] ?? null,
                    $block['goal'] ?? null,
                    $block['description'] ?? null,
                ]);
                $contentPlan = $this->firstNonEmptyPlanJsonBlockArray($block, [
                    'content_plan',
                    'content',
                    'copy',
                    'core_copy',
                    'content_copy',
                    'field_content',
                ]);
                $stylePlan = \array_replace(
                    $this->firstNonEmptyPlanJsonBlockArray($sitePlanContext, ['theme_design', 'theme_style', 'palette', 'design_manifest']),
                    $this->firstNonEmptyPlanJsonBlockArray($block, ['style_plan', 'visual_contract', 'visual_signature', 'image_intent', 'design_tags'])
                );
                $fieldPlan = $this->firstNonEmptyPlanJsonBlockArray($block, [
                    'field_plan',
                    'fields',
                    'field_schema',
                    'default_config',
                    'extra_fields',
                    'meta_fields',
                ]);
                $visualSignature = \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [];
                $imageIntent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
                $outputContract = $this->planJsonExecutionOutputContract($blockType, $contentKeys);
                $acceptance = $this->planJsonExecutionAcceptanceContract($blockType);
                $runtimeContext = \array_replace_recursive($runtimeRoot, [
                    'content_locale' => $contentLocale,
                    'language_contract' => $languageContract,
                    'context_refs' => [
                        'site_context_ref' => 'plan_json',
                        'page_context_ref' => 'plan_json.pages.' . $pageType,
                        'block_context_ref' => 'plan_json.pages.' . $pageType . '.' . $blockKey,
                    ],
                ]);
                $planContext = [
                    'source' => 'plan_json.pages',
                    'site_context' => $sitePlanContext,
                    'page_type' => $pageType,
                    'page' => $this->compactPlanJsonPageForTaskContext($page),
                    'page_goal' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                    'block_key' => $blockKey,
                    'section_key' => $sectionKey,
                    'section_code' => $sectionCode,
                    'block_type' => $blockType,
                    'page_flow_role' => $pageFlowRole,
                    'block_goal' => $blockGoal,
                    'block' => $block,
                    'stage1_block_content' => $contentPlan,
                    'content_plan' => $contentPlan,
                    'style_plan' => $stylePlan,
                    'field_plan' => $fieldPlan,
                ];
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
                    'block_key' => $blockKey,
                    'block_id' => $blockId,
                    'block_type' => $blockType,
                    'page_flow_role' => $pageFlowRole,
                    'visual_signature' => $visualSignature,
                    'image_intent' => $imageIntent,
                    'label' => $label,
                    'sort_order' => 100 + ($pageIndex * 1000) + ((int)$blockIndex * 10),
                    'dependencies' => [],
                    'can_parallel' => true,
                    'materialize_after_done' => true,
                    'materialize_policy' => 'page',
                    'prompt_template_key' => 'plan_json_block_execute',
                    'progress_weight' => 2.0,
                    'result_ref' => [
                        'page_type' => $pageType,
                        'section_code' => $sectionCode,
                        'block_key' => $blockKey,
                    ],
                    'runtime_context' => $runtimeContext,
                    'plan_context' => $planContext,
                    'task_script' => [
                        'component_type' => 'section',
                        'story_goal' => $blockGoal,
                        'field_content_requirements' => $fieldPlan,
                        'output_contract' => $outputContract,
                        'acceptance' => $acceptance,
                        'content_keys' => $contentKeys,
                        'policy_slices' => ['layout.4_8_spacing', 'typography.refined_font_stack', 'image.integrated_not_pasted', 'responsive.no_horizontal_scroll'],
                        'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'],
                    ],
                    'block_task' => [
                        'block_type' => $blockType,
                        'page_flow_role' => $pageFlowRole,
                        'task_goal' => $blockGoal,
                        'content_plan' => $contentPlan,
                        'style_plan' => $stylePlan,
                        'visual_signature' => $visualSignature,
                        'image_intent' => $imageIntent,
                        'meta_fields' => $fieldPlan,
                        'output_contract' => $outputContract,
                        'acceptance' => $acceptance,
                    ],
                    'implementation_contract' => [
                        'source' => 'plan_json.pages.' . $pageType . '.' . $blockKey,
                        'block_id' => $blockId,
                        'block_key' => $blockKey,
                        'page_type' => $pageType,
                        'data_contract' => \is_array($outputContract['render_data'] ?? null) ? $outputContract['render_data'] : [],
                        'output_contract' => $outputContract,
                        'acceptance' => $acceptance,
                    ],
                ];
            }
            ++$pageIndex;
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
            $message = $this->sanitizePlanJsonTaskFailureMessageForView((string)($failure['message'] ?? $failure['error'] ?? ''));
            $failureForView = $failure;
            foreach (['message', 'error', 'error_message', 'failure_reason', 'reason'] as $messageKey) {
                if (!isset($failureForView[$messageKey]) || !\is_scalar($failureForView[$messageKey])) {
                    continue;
                }
                $failureForView[$messageKey] = $this->sanitizePlanJsonTaskFailureMessageForView((string)$failureForView[$messageKey], $message);
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

    private function sanitizePlanJsonTaskFailureMessageForView(string $message, string $fallback = 'Build task failed.'): string
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
        foreach ($this->extractPlanJsonTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
            $block = $this->resolvePlanJsonBlockForTask($scope, $pageType, $blockKey, (string)($task['section_code'] ?? ''));
            $row = [
                'task_key' => $taskKey,
                'status' => $this->planBlockStatusToTaskStatus($this->normalizePlanBlockStatus($block['status'] ?? self::PLAN_BLOCK_STATUS_PENDING)),
                'attempt_no' => (int)($block['attempt_no'] ?? 0),
                'message' => (string)($block['message'] ?? $block['error'] ?? $block['error_message'] ?? ''),
                'result_ref' => \is_array($block['result_ref'] ?? null) ? $block['result_ref'] : $this->planJsonTaskResultRefFromDefinition($task),
                'updated_at' => (string)($block['updated_at'] ?? ''),
                'started_at' => (string)($block['started_at'] ?? ''),
                'finished_at' => (string)($block['finished_at'] ?? ''),
            ];
            $sanitized[$taskKey] = $this->sanitizePlanJsonTaskStateRow($row, $taskKey);
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
        $resultRef = \is_array($patch['result_ref'] ?? null) ? $patch['result_ref'] : [];
        if (\is_array($patch['result_ref'] ?? null)) {
            foreach (['component', 'section_component', 'section_block', 'generated_section_block'] as $heavyKey) {
                if (isset($patch['result_ref'][$heavyKey])) {
                    unset($patch['result_ref'][$heavyKey]);
                }
            }
        }
        $next = $this->sanitizePlanJsonTaskStateRow(\array_replace($existing, $patch), $taskKey);

        $definition = $this->getTaskDefinition($scope, $taskKey);
        if ($definition === null || (string)($definition['task_type'] ?? '') !== 'page_section') {
            return $this->attachPlanJsonExecutionSummary($scope);
        }
        $pageType = \trim((string)($definition['page_type'] ?? ''));
        $blockKey = \trim((string)($definition['block_key'] ?? $definition['section_key'] ?? ''));
        if ($pageType === '' || $blockKey === '') {
            return $this->attachPlanJsonExecutionSummary($scope);
        }
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        $block = \is_array($page[$blockKey] ?? null) ? $page[$blockKey] : [];
        if ($block === []) {
            $sectionCode = \trim((string)($definition['section_code'] ?? ''));
            foreach ($this->extractPlanJsonPageBlockNodes($page) as $candidateKey => $candidateBlock) {
                $candidateSectionCode = \trim((string)($candidateBlock['section_code'] ?? $candidateBlock['component_code'] ?? ''));
                if ($candidateKey === $blockKey
                    || ($sectionCode !== '' && ($candidateSectionCode === $sectionCode || $this->sectionIdentityMatches($candidateSectionCode, $sectionCode)))
                ) {
                    $blockKey = $candidateKey;
                    $block = $candidateBlock;
                    break;
                }
            }
        }
        if ($block === []) {
            return $this->attachPlanJsonExecutionSummary($scope);
        }

        $taskStatus = $this->normalizeTaskStatus((string)($next['status'] ?? self::TASK_STATUS_PENDING));
        $block['status'] = $this->taskStatusToPlanBlockStatus($taskStatus);
        $block['attempt_no'] = (int)($next['attempt_no'] ?? 0);
        $block['message'] = (string)($next['message'] ?? '');
        $block['result_ref'] = \is_array($next['result_ref'] ?? null) ? $next['result_ref'] : [];
        $block['updated_at'] = (string)($next['updated_at'] ?? \date('Y-m-d H:i:s'));
        $block['started_at'] = (string)($next['started_at'] ?? '');
        $block['finished_at'] = (string)($next['finished_at'] ?? '');
        if ($taskStatus === self::TASK_STATUS_FAILED) {
            $block['error'] = $block['message'] !== '' ? $block['message'] : 'AI generation failed.';
        } else {
            unset($block['error'], $block['error_message']);
        }
        if ($taskStatus === self::TASK_STATUS_DONE) {
            $block = $this->syncPlanJsonBlockGeneratedPayload($block, $resultRef, $definition, $scope);
        }
        $scope['plan_json'] = $this->planJsonStateService->applyBlockPatch(
            $planJson,
            $pageType,
            $blockKey,
            $block
        );

        return $this->attachPlanJsonExecutionSummary($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function ensurePlanJsonBlockExecutionState(array $scope): array
    {
        if (!\is_array($scope['plan_json'] ?? null)) {
            return $scope;
        }
        $scope['plan_json'] = $this->planJsonStateService->normalizeExecutionState($scope['plan_json']);

        return $this->attachPlanJsonExecutionSummary($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function attachPlanJsonExecutionSummary(array $scope): array
    {
        $summary = $this->summarize($scope);
        $scope['plan_json_task_summary'] = [
            'total' => (int)($summary['total'] ?? 0),
            'done' => (int)($summary['done'] ?? 0),
            'pending' => (int)($summary['pending'] ?? 0),
            'running' => (int)($summary['running'] ?? 0),
            'failed' => (int)($summary['failed'] ?? 0),
            'cancelled' => (int)($summary['cancelled'] ?? 0),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resetPlanJsonExecutionRows(array $scope): array
    {
        if (!\is_array($scope['plan_json'] ?? null)) {
            return $scope;
        }
        $scope['plan_json'] = $this->planJsonStateService->resetBlockExecutionState($scope['plan_json']);
        unset($scope['plan_json_task_summary']);

        return $scope;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $contract
     */
    private function planJsonTaskKeyForPlanBlock(array $block, string $blockId, array $contract): string
    {
        $pageId = \trim((string)($block['page_id'] ?? ''));
        $pagesById = $this->normalizePlanJsonRecordSet($contract['pages'] ?? [], ['page_id', 'id']);
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
        $sectionCode = $this->resolvePlanJsonSectionCode($pageType, $sectionKey, $blockId);
        if ($sectionCode === '') {
            return '';
        }

        return 'page:' . $pageType . ':' . $sectionCode;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonPages(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $normalized = [];
        foreach ($pages as $pageKey => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($pageKey) ? $pageKey : '')));
            if ($pageType === '') {
                continue;
            }
            $page['page_type'] = $pageType;
            $normalized[$pageType] = $page;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonPageBlockNodes(array $page): array
    {
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!$this->isPlanJsonDynamicBlockNode($key, $value)) {
                continue;
            }
            $blockKey = \trim((string)($value['block_key'] ?? $value['section_key'] ?? (\is_string($key) ? $key : '')));
            if ($blockKey === '') {
                continue;
            }
            $value['block_key'] = $blockKey;
            $blocks[$blockKey] = $value;
        }

        return $blocks;
    }

    private function isPlanJsonDynamicBlockNode(int|string $key, mixed $value): bool
    {
        if (!\is_array($value) || !\is_string($key)) {
            return false;
        }
        $key = \trim($key);
        if ($key === '' || isset(self::PLAN_JSON_PAGE_META_KEYS[$key])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function compactPlanJsonPageForTaskContext(array $page): array
    {
        $copy = $page;
        foreach ($this->extractPlanJsonPageBlockNodes($page) as $blockKey => $_) {
            unset($copy[$blockKey]);
        }

        return $copy;
    }

    /**
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function compactPlanJsonRootForTaskContext(array $planJson): array
    {
        $copy = $planJson;
        foreach ([
            'pages',
            'plan_projection',
            'content_manifest',
        ] as $key) {
            unset($copy[$key]);
        }

        return $copy;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function firstNonEmptyPlanJsonBlockArray(array $source, array $keys): array
    {
        foreach ($keys as $key) {
            $candidate = $source[$key] ?? null;
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $planJson
     * @return array<string, mixed>
     */
    private function planJsonRuntimeContext(array $scope, array $planJson, string $contentLocale): array
    {
        $profile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $site = \is_array($planJson['site'] ?? null) ? $planJson['site'] : [];
        $siteBrief = \array_filter([
            'site_name' => $this->firstNonEmptyPlanJsonText([
                $scope['site_title'] ?? null,
                $profile['site_title'] ?? null,
                $site['name'] ?? null,
                $site['site_name'] ?? null,
            ]),
            'summary' => $this->firstNonEmptyPlanJsonText([
                $scope['brief_description'] ?? null,
                $profile['brief_description'] ?? null,
                $site['summary'] ?? null,
                $site['description'] ?? null,
                $planJson['summary'] ?? null,
            ]),
            'primary_locale' => $contentLocale,
        ], static fn(mixed $value): bool => $value !== '' && $value !== null);

        $themeContext = [
            'source' => 'plan_json',
            'theme_design' => \is_array($planJson['theme_design'] ?? null) ? $planJson['theme_design'] : [],
            'theme_style' => \is_array($planJson['theme_style'] ?? null) ? $planJson['theme_style'] : [],
            'palette' => \is_array($planJson['palette'] ?? null) ? $planJson['palette'] : [],
            'design_manifest' => \is_array($planJson['design_manifest'] ?? null) ? $planJson['design_manifest'] : [],
        ];
        $pageSummaries = [];
        foreach ($this->extractPlanJsonPages(['plan_json' => $planJson]) as $pageType => $page) {
            $pageSummaries[$pageType] = $this->compactPlanJsonPageForTaskContext($page);
        }

        return [
            'site_context' => [
                'site_brief' => $siteBrief,
                'source_of_truth' => [
                    'source' => 'plan_json',
                    'pages_ref' => 'plan_json.pages',
                ],
                'website_profile' => $profile,
            ],
            'theme_context_snapshot' => $themeContext,
            'shared_prompt_context' => [
                'source' => 'plan_json',
                'navigation_plan' => \is_array($planJson['navigation_plan'] ?? null) ? $planJson['navigation_plan'] : [],
                'footer_plan' => \is_array($planJson['footer_plan'] ?? null) ? $planJson['footer_plan'] : [],
                'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
                'pages' => $pageSummaries,
            ],
            'policy_context' => [
                'design_manifest' => \is_array($planJson['design_manifest'] ?? null) ? $planJson['design_manifest'] : [],
                'policy_projection' => \is_array($planJson['policy_projection'] ?? null) ? $planJson['policy_projection'] : [],
            ],
            'skill_context' => [
                'selected_skill_codes' => $this->normalizePlanJsonStringList($scope['selected_skill_codes'] ?? []),
            ],
            'reference_context' => [
                'source_truth_contract' => \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [],
            ],
            'asset_context' => $this->summarizePlanJsonAssetContext($scope),
        ];
    }

    private function normalizePlanBlockStatus(mixed $status): int
    {
        if (\is_int($status)) {
            return \in_array($status, [
                self::PLAN_BLOCK_STATUS_PENDING,
                self::PLAN_BLOCK_STATUS_RUNNING,
                self::PLAN_BLOCK_STATUS_DONE,
                self::PLAN_BLOCK_STATUS_FAILED,
            ], true) ? $status : self::PLAN_BLOCK_STATUS_PENDING;
        }
        $status = \strtolower(\trim((string)$status));

        return match ($status) {
            '1', 'done', 'complete', 'completed', 'success', 'succeeded', 'ready', 'finished', 'passed', 'persisted', 'skipped', 'skip', 'ignored' => self::PLAN_BLOCK_STATUS_DONE,
            '2', 'running', 'processing', 'generating', 'started', 'in_progress', 'queued', 'retrying' => self::PLAN_BLOCK_STATUS_RUNNING,
            '-1', 'failed', 'error', 'fail', 'failure', 'retryable_failure', 'cancelled', 'canceled' => self::PLAN_BLOCK_STATUS_FAILED,
            default => self::PLAN_BLOCK_STATUS_PENDING,
        };
    }

    private function planBlockStatusToTaskStatus(int $status): string
    {
        return match ($status) {
            self::PLAN_BLOCK_STATUS_DONE => self::TASK_STATUS_DONE,
            self::PLAN_BLOCK_STATUS_RUNNING => self::TASK_STATUS_RUNNING,
            self::PLAN_BLOCK_STATUS_FAILED => self::TASK_STATUS_FAILED,
            default => self::TASK_STATUS_PENDING,
        };
    }

    private function taskStatusToPlanBlockStatus(string $status): int
    {
        return match ($this->normalizeTaskStatus($status)) {
            self::TASK_STATUS_DONE => self::PLAN_BLOCK_STATUS_DONE,
            self::TASK_STATUS_RUNNING => self::PLAN_BLOCK_STATUS_RUNNING,
            self::TASK_STATUS_FAILED, self::TASK_STATUS_CANCELLED => self::PLAN_BLOCK_STATUS_FAILED,
            default => self::PLAN_BLOCK_STATUS_PENDING,
        };
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolvePlanJsonBlockForTask(array $scope, string $pageType, string $blockKey, string $sectionCode = ''): array
    {
        $pages = $this->extractPlanJsonPages($scope);
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($page === []) {
            return [];
        }
        if ($blockKey !== '' && \is_array($page[$blockKey] ?? null)) {
            return $page[$blockKey];
        }
        foreach ($this->extractPlanJsonPageBlockNodes($page) as $candidateKey => $block) {
            if ($blockKey !== '' && $candidateKey === $blockKey) {
                return $block;
            }
            $candidateSectionCode = \trim((string)($block['section_code'] ?? $block['component_code'] ?? ''));
            if ($sectionCode !== '' && ($candidateSectionCode === $sectionCode || $this->sectionIdentityMatches($candidateSectionCode, $sectionCode))) {
                return $block;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $page
     */
    private function rollupPlanJsonPageStatus(array $page): int
    {
        $hasRunning = false;
        $hasPending = false;
        $hasFailed = false;
        $hasDone = false;
        foreach ($this->extractPlanJsonPageBlockNodes($page) as $block) {
            $status = $this->normalizePlanBlockStatus($block['status'] ?? self::PLAN_BLOCK_STATUS_PENDING);
            $hasRunning = $hasRunning || $status === self::PLAN_BLOCK_STATUS_RUNNING;
            $hasPending = $hasPending || $status === self::PLAN_BLOCK_STATUS_PENDING;
            $hasFailed = $hasFailed || $status === self::PLAN_BLOCK_STATUS_FAILED;
            $hasDone = $hasDone || $status === self::PLAN_BLOCK_STATUS_DONE;
        }
        if ($hasRunning) {
            return self::PLAN_BLOCK_STATUS_RUNNING;
        }
        if ($hasFailed) {
            return self::PLAN_BLOCK_STATUS_FAILED;
        }
        if ($hasPending) {
            return $hasDone ? self::PLAN_BLOCK_STATUS_RUNNING : self::PLAN_BLOCK_STATUS_PENDING;
        }

        return $hasDone ? self::PLAN_BLOCK_STATUS_DONE : self::PLAN_BLOCK_STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $resultRef
     * @param array<string, mixed> $task
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function syncPlanJsonBlockGeneratedPayload(array $block, array $resultRef, array $task, array $scope): array
    {
        $sectionBlock = \is_array($resultRef['section_block'] ?? null)
            ? $resultRef['section_block']
            : (\is_array($resultRef['generated_section_block'] ?? null) ? $resultRef['generated_section_block'] : []);
        $component = \is_array($resultRef['component'] ?? null)
            ? $resultRef['component']
            : (\is_array($resultRef['section_component'] ?? null) ? $resultRef['section_component'] : []);
        if ($sectionBlock === [] && $component === []) {
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
            $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
            $sectionBlock = $this->findLayoutSectionByCode($layout, $sectionCode) ?? [];
        }

        $html = $this->firstNonEmptyPlanJsonText([
            $sectionBlock['html'] ?? null,
            $sectionBlock['html_content'] ?? null,
            $component['html'] ?? null,
            $component['html_content'] ?? null,
        ]);
        if ($html !== '') {
            $block['html'] = $html;
        }
        $phtml = $this->firstNonEmptyPlanJsonText([
            $sectionBlock['phtml'] ?? null,
            $sectionBlock['template_phtml'] ?? null,
            $component['phtml'] ?? null,
        ]);
        if ($phtml !== '') {
            $block['phtml'] = $phtml;
        }
        foreach ([
            'fields' => [$sectionBlock['config'] ?? null, $component['default_config'] ?? null, $component['config'] ?? null],
            'field_schema' => [$sectionBlock['field_schema'] ?? null],
            'default_config' => [$component['default_config'] ?? null, $sectionBlock['config'] ?? null],
            'ai_data' => [$component['ai_data'] ?? null],
        ] as $targetKey => $candidates) {
            foreach ($candidates as $candidate) {
                if (\is_array($candidate) && $candidate !== []) {
                    $block[$targetKey] = $candidate;
                    break;
                }
            }
        }

        return $block;
    }

    /**
     * @param list<string> $contentKeys
     * @return array<string, mixed>
     */
    private function planJsonExecutionOutputContract(string $componentType, array $contentKeys): array
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
    private function planJsonExecutionAcceptanceContract(string $componentType): array
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
        $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
        $planJsonBlock = $this->resolvePlanJsonBlockForTask($scope, $pageType, $blockKey, $sectionCode);
        if ($this->planJsonBlockHasGeneratedArtifact($planJsonBlock)) {
            return true;
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

            if ($this->isBuiltPageSectionArtifact($layoutSection)) {
                return true;
            }

            $componentCode = \trim((string)($layoutSection['code'] ?? $layoutSection['component'] ?? $sectionCode));
            if ($componentCode !== '' && $this->virtualThemeComponentArtifactAvailable($scope, $componentCode)) {
                return true;
            }

            if ($this->requiresPersistedVirtualThemeLayoutCheck($scope)) {
                return $this->persistedVirtualThemeLayoutContainsSectionCode($scope, $pageType, $sectionCode);
            }

            return false;
        }
        if ($activeRegeneration) {
            return false;
        }
        if ($this->persistedVirtualThemeLayoutContainsSectionCode($scope, $pageType, $sectionCode)) {
            return !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
        }

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];

        return $this->virtualPageContainsBuiltSectionArtifact($virtualPage, $sectionCode)
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
     * During a forced rebuild, persisted virtual-theme rows belong to the prior
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
     * @param array<string, mixed> $section
     */
    private function isBuiltPageSectionArtifact(array $section): bool
    {
        if ($section === []) {
            return false;
        }

        $html = \trim((string)($section['html'] ?? $section['html_content'] ?? ''));
        $phtml = \trim((string)($section['phtml'] ?? $section['template_phtml'] ?? ''));
        if ($html === '' && $phtml === '') {
            return false;
        }

        $code = \trim((string)($section['code'] ?? $section['component'] ?? $section['section_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $rendered = $html !== '' ? $html : $phtml;

        return !$this->containsGeneratedArtifactPromptTrace($rendered);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function planJsonBlockHasGeneratedArtifact(array $block): bool
    {
        if ($block === []) {
            return false;
        }
        if ($this->normalizePlanBlockStatus($block['status'] ?? self::PLAN_BLOCK_STATUS_PENDING) !== self::PLAN_BLOCK_STATUS_DONE) {
            return false;
        }
        $html = \trim((string)($block['html'] ?? $block['html_content'] ?? $block['phtml'] ?? ''));
        if ($html === '') {
            return false;
        }

        return !$this->containsGeneratedArtifactPromptTrace($html);
    }

    /**
     * @param array<string, mixed> $virtualPage
     */
    private function virtualPageContainsBuiltSectionArtifact(array $virtualPage, string $sectionCode): bool
    {
        $blocks = \is_array($virtualPage['block_nodes'] ?? null) ? $virtualPage['block_nodes'] : [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (['section_code', 'code', 'block_code', 'component', 'component_code'] as $key) {
                if (!$this->sectionIdentityMatches((string)($block[$key] ?? ''), $sectionCode)) {
                    continue;
                }

                return $this->isBuiltPageSectionArtifact($block);
            }
        }

        return false;
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
    private function planJsonTaskResultRefFromDefinition(array $task): array
    {
        $taskType = \trim((string)($task['task_type'] ?? ''));
        if ($taskType === 'shared_component') {
            return ['region' => \trim((string)($task['region'] ?? ''))];
        }

        return [
            'page_type' => \trim((string)($task['page_type'] ?? '')),
            'section_code' => \trim((string)($task['section_code'] ?? '')),
            'block_key' => \trim((string)($task['block_key'] ?? $task['section_key'] ?? '')),
        ];
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
        return $this->countBuiltLayoutContentBlocks($layout) > 0;
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function countBuiltLayoutContentBlocks(array $layout): int
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        $count = 0;
        foreach ($content as $section) {
            if (!\is_array($section)) {
                continue;
            }
            foreach (['code', 'component', 'section_code'] as $key) {
                if (\trim((string)($section[$key] ?? '')) !== '') {
                    ++$count;
                    continue 2;
                }
            }
            if ($this->isBuiltPageSectionArtifact($section)) {
                ++$count;
            }
        }

        return $count;
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
            if ($layout->getId() <= 0 || $this->arrayContainsGeneratedArtifactPromptTrace($config)) {
                return false;
            }
            $section = $this->findLayoutSectionByCode($config, $sectionCode);

            return $section !== null && $this->isBuiltPageSectionArtifact($section);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $summary summarize() 闂傚倸鍊搁崐鎼佸磹妞嬪海鐭嗗〒姘ｅ亾妤犵偞鐗犻、鏇氱秴闁搞儺鍓﹂弫鍐煥閺囨浜鹃梺姹囧€楅崑鎾舵崲濠靛洨绡€闁稿本绮岄。娲⒑閽樺鏆熼柛鐘崇墵瀵寮撮悢铏诡啎闂佸壊鐓堥崰鏍ㄧ珶閸曨偀鏀介柣鎰级閳绘洖霉濠婂嫮鐭掔€规洘锕㈤崺鈧い鎺嗗亾妞ゎ亜鍟存俊鍫曞幢濡儤娈梻浣侯焾椤戝洭宕伴弽顓炴瀬?
     * @return list<array{page_type:string,done:int,total:int,complete:bool}>
     */
    public function summarizePageBlockProgress(array $summary): array
    {
        $groups = \is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $rows = [];
        foreach ($groups as $groupKey => $group) {
            if ($groupKey === 'shared' || !\is_array($group)) {
                continue;
            }
            $pageType = \trim((string)($group['page_type'] ?? $groupKey));
            if ($pageType === '') {
                continue;
            }
            $done = (int)($group['done'] ?? 0);
            $total = (int)($group['total'] ?? 0);
            $rows[] = [
                'page_type' => $pageType,
                'done' => $done,
                'total' => $total,
                'complete' => $total > 0 && $done >= $total,
            ];
        }

        return $rows;
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
        $status = \strtolower(\trim($status));
        $status = match ($status) {
            '0' => self::TASK_STATUS_PENDING,
            '2' => self::TASK_STATUS_RUNNING,
            '1' => self::TASK_STATUS_DONE,
            '-1' => self::TASK_STATUS_FAILED,
            default => $status,
        };

        return \in_array($status, [
            self::TASK_STATUS_PENDING,
            self::TASK_STATUS_RUNNING,
            self::TASK_STATUS_DONE,
            self::TASK_STATUS_FAILED,
            self::TASK_STATUS_CANCELLED,
        ], true) ? $status : self::TASK_STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizePlanJsonTaskStateRow(array $row, string $taskKey): array
    {
        foreach (self::PLAN_JSON_TASK_STATE_DUPLICATE_KEYS as $key => $_) {
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
