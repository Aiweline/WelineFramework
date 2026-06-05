<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;

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
     * 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴濐潟閳ь剙鍊块、娆撴倷椤掑缍楅梻浣告惈濞层垽宕归崷顓烆棜濠电姵纰嶉悡娆撳级閸繂鈷旈柣锝堜含閻ヮ亪骞戦幇鍨秷婵烇絽娲ら敃顏呬繆閸洖宸濇い鏂垮悑椤忕娀姊绘担鍛婃儓妞ゆ垵瀚▎銏狀潩鐠洪缚鎽曞┑鐐村灦鑿ゆ俊鎻掔墛閹便劌螖閳ь剙螞濞戙垹鐭楅柡鍥╁亹閺€?rollup闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈤崗鐓庘偓缁樹繆椤栨繂鍚归柛娆忔濮婃椽宕崟闈涘壈闂佺粯顨呯换姗€宕洪埀顒併亜閹哄棗浜剧紓浣哄Т缁夌懓鐣烽弴銏犵闁诲繒绮浠嬪箖閳哄啯瀚氶柤纰卞墾缁?page_type 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻鐔兼⒒鐎靛壊妲紒鐐劤缂嶅﹪寮婚敐澶婄闁挎繂鎲涢幘缁樼厱闁靛牆鎳庨顓㈡煛鐏炲墽娲存い銏℃礋閺佹劙宕卞▎妯恍氱紓鍌氬€烽悞锕傚礉閺嶎厹鈧啯绻濋崶褑鎽曢梺璺ㄥ枔婵挳鎮欐繝鍥ㄧ厪濠㈣泛鐗嗛崝銈夋煕閺冣偓閹稿啿顫忛悜妯诲闁规鍣Σ顔尖攽閻愬弶鈻曢柛娆忓暙椤曪絾绻濆顓炰簻闂佺绻愰惃鐑藉箯婵犳碍鐓熼幖娣妽濞懷冾熆閻熷府宸ラ崡杈ㄣ亜閺囨浜惧┑顔硷功缁垶骞忛崨鏉戞闁靛牆顦卞畷顏堟⒒娴ｄ警鐒鹃柨鏇樺劦閹兾旈崘顏嗙厰闁哄鐗勯崝搴ｅ姬閳ь剟姊洪幖鐐插姶闁诲繑绋栭ˇ褰掓煛鐏炲墽鈽夐柍钘夘樀瀹曪繝鎮欓懠顒夊晥濠电姷鏁搁崑娑㈡偋閸℃稈鈧箑鐣￠柇锔界稁闂佹儳绻愬﹢杈╁閸忛棿绻嗘い鏍ㄦ皑濮ｇ偛鈹戦鍏煎枠婵﹥妞介幃鐑芥偋閸繃娈橀梻浣筋嚃閸犳牠宕愰幖浣规櫇闁靛骏绱曢悷褰掓煃瑜滈崜娆擄綖韫囨稒鎯為柛锔诲幘閿涙粌鈹戦埥鍡楃仩缂佺粯顨婂畷鎴﹀箛閺夎法顔嗛梺鍛婂姦閸犳寮插┑瀣厱閻忕偞宕樻竟姗€鏌熼婊冧粶闁宠鍨块幃娆撳矗婢舵ɑ顥ｇ紓鍌欐祰椤曆兾涘┑鍡╁殨闁靛ň鏅滈崵宥夋煏婢舵稓瀵肩紒銊ヮ煼濮婃椽宕崟顐ｆ疁闂佺顑嗛幑鍥蓟濞戞瑦鍎熼柕蹇曞Л閺嬫瑩鎮楀▓鍨灍闁诡喖鍊搁锝夘敋閳ь剙鐣锋總鍛婂亜闁告繂瀚粭姘舵⒑閼姐倕鏋戠紒顔肩Ф閸掓帡骞樺畷鍥ㄦ濠电姴锕ら崯鐘参ｉ崼銉︾厪闊洦娲栧暩闂佹眹鍊曞ú顓烆潖閾忚鍠嗛柛鏇ㄥ亞椤︺劌顪冮妶鍡樿偁闁告侗浜滄禍鐐叏濮楀棗澧绘俊鎻掔秺閺屾洟宕惰椤忣厽顨ラ悙鏉戞诞妤犵偛顑呴埞鎴﹀箛椤忓懎浜濋梻鍌氬€烽悞锕傚箖閸洖绀夌€光偓閸曨偆锛欓悷婊呭鐢帞绮婚悙鐑樼厪濠电偛鐏濋崜濠氭煟閺冨倸甯剁紒鐘卞嵆楠炴牗娼忛崜褏銈烽梺閫炲苯澧柛鏃€顨堝Σ鎰板箻鐎涙ê顎撻梺鍛婄箓鐎氬懘鏁愰崶锝呬壕闁稿繐顦禍楣冩⒑瑜版帗锛熺紒鈧笟鈧幏鎴︽偄閸濄儳顔曢梺鐟邦嚟閸婃垵顫濋鈺嬬秮瀹曞ジ濡烽敂鎯у箞婵犲痉鏉库偓鏇㈠疮椤愶絿顩锋繝濠傛噽绾惧ジ鏌ｅΟ鍨毢缂佸鍎ら幈銊︾節閸愨斂浠㈤悗瑙勬处閸嬪﹤鐣烽悢鍏碱棃婵炴垶锚椤ュ秹姊婚崒娆戝妽婵＄偛娼″畷銏＄附缁嬭法锛欓梺鍓茬厛閸ｎ噣宕甸弴鐐╂斀闁绘ê鐤囨竟姗€骞嗛悢鍏尖拺闂傚牊渚楀Σ鍫曟煕閵婏附銇濋柨婵堝仜椤撳ジ宕ㄩ鍛澑闂備胶绮崝鏇烆嚕閸泙澶愭倷閻戞鍘遍梺闈浤涢崘鈺冣偓濠氭煕濡ゅ懍鎲鹃柡灞剧洴椤㈡洟鎮╅幓鎺戭潥闂備胶顭堥鍌炲礈濠靛牊宕叉繝闈涙川缁♀偓闂佺鏈崙瑙勭閸撗呯＝濞达絿鎳撻崝鍫曟倶韫囨梻鎳囬柛鈹惧亾濡炪倖甯婄欢锟犲疮韫囨稒鐓曢柣妯虹－婢ь剟鏌￠崨鏉跨厫闁诡垱妫冮弫鎰板川椤撶姳鍠?skip_remaining_blocks 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧湱鈧懓瀚崳纾嬨亹閹烘垹鍊為梺闈浤涢崨顓㈢崕闂傚倷绀佹竟濠囧磻閸℃稑绐楅柛鈩冾焽椤╃兘鏌涢鐘插姕闁绘挻鐟╁濠氬磼濮樼厧娈跺銈忚缁犳捇寮婚敃鍌氱妞ゅ繐瀚悘鍫ユ⒑閸濆嫭婀版繛鍙夘焽閹广垹鈹戞繝鍕澑濠电偞鍨堕悷顖炲焵椤掆偓缁夌懓顫忓ú顏勫窛濠电姴瀚崳褏绱撴担铏瑰笡闁挎洏鍨归锝囨嫚瀹割喖鎮戞繝銏ｆ硾閿曪絿妲愰崼鏇熲拺闁告稑锕ユ径鍕煕鐎ｎ亝鍣归柍缁樻崌瀵濡烽敃鈧埀顒傛暬閹嘲鈻庤箛鎿冧痪缂備讲鍋撻柍褜鍓欓—鍐Χ韫囨搩娲梺杞扮椤兘鎮伴鈧畷姗€濡告惔銏☆棃鐎规洏鍔戦、姗€鎮㈡搴涘仩婵犵绱曢崑鎴﹀磹閺嶎厼绠板Δ锝呭暙绾捐銇勯幇鍓佺暠閸烆垶鎮峰鍐濠㈣娲樼缓浠嬪川婵犲嫬骞楁繝纰樻閸ㄩ潧鈻嶉敐澶嬫櫖鐎广儱鎷嬮悢鍡欐喐韫囨稑鏋佸┑鐘宠壘閽冪喖鏌ㄥ☉妯侯仹婵炲矈浜弻娑㈠箻濡炶浜惧┑鈩冨絻閻楀棝鈥旈崘顔嘉ч幖绮光偓鑼嚬缂傚倷娴囬褔鎮ч幘璇参ュù锝堝€介弮鈧幏鍛存偡闁腹鍋撻幘缁樷拺闁煎鍊曢弸宥夋煕濡も偓閸熻儻鐏嬮梺缁樺灱婵倝鍩?section闂?     *
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
    private const PLAN_JSON_TASK_MAX_AUTOMATIC_ATTEMPTS = 2;
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
        'blocks' => true,
        'block_previews' => true,
        'ordered_block_keys' => true,
        'primary_keywords' => true,
        'secondary_keywords' => true,
        'seo' => true,
        'meta_title' => true,
        'meta_description' => true,
        'meta_keywords' => true,
        'route' => true,
        'route_path' => true,
        'slug' => true,
        'path' => true,
        'layout' => true,
        'style_code' => true,
        'style_settings' => true,
        'design_tokens' => true,
        'theme_css_ref' => true,
        'navigation' => true,
        'menus' => true,
        'links' => true,
        'settings' => true,
        'preview_url' => true,
        'preview_full_url' => true,
        'visual_preview_url' => true,
        'visual_edit_url' => true,
        'virtual_preview_url' => true,
        'virtual_edit_url' => true,
        'assets' => true,
        'sections' => true,
        'section_refinements' => true,
        'ai_description' => true,
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
            'materialized_pages_by_type',
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
            if ($this->extractPlanJsonPageBlocks($page) === []) {
                $emptyPages[] = $pageType;
            }
        }
        if ($emptyPages !== []) {
            return [
                'valid' => false,
                'errors' => ['PLAN_JSON_PAGES_INVALID: plan_json.pages has no blocks for page_types: ' . \implode(', ', \array_values(\array_unique($emptyPages)))],
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
     * The full plan_json block and its execution context are already represented
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
            if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_FAILED], true)) {
                continue;
            }
            $pending[] = \array_replace($task, $state);
        }
        \usort($pending, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $pending;
    }

    /**
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴闇夐柨婵嗩槹娴溿倝鏌ら弶鎸庡仴婵﹥妞介、妤呭焵椤掑倻鐭撴い鏇楀亾闁糕斁鍋撳銈嗗笒閿曪箓鎮鹃悽纰樺亾鐟欏嫭绀€闁靛牆鎲℃穱濠冾槹鎼存ê浜鹃柨婵嗙凹缁ㄥジ鏌涢悩鎴愭垿濡甸崟顖氼潊闁挎稑瀚崳浼存⒑鐠団€虫灍闁荤啿鏅犻妴浣肝旀担鍝ョ獮婵犵數濮抽懗鍓佹閿曗偓閳规垿鎮╁▓鎸庢缂備浇椴稿ú鐔风暦閹存績妲堥柕蹇婃櫆閺呯偤姊洪崨濠佺繁闁割煈浜畷鎴﹀箻閺傘儲顫嶉梺鍦劋閹稿鎮甸弽銊х閻庢稒顭囬惌濠囨煙鐠囇呯瘈妤犵偛妫濆顕€宕煎顏佹櫊閺屻劑寮埀顒勫磿閹剁晫宓侀柟鎵閻撶喖骞栨潏鍓х？闁伙綆鍙冮弻娑欐償閵堝嫬鎯堥梺鐟扮畭閸ㄤ粙鐛崶顒佸殝妞ゆ垼妫勬禍楣冩煕濞戞瑦鍎楅柡浣告閺屾盯寮撮妸銉ヮ潾闂佸憡眉缁瑥顫忛搹鍦＜婵☆垳鍎甸幏鐟扳攽閻愭彃鎮戦柣鐔叉櫊楠炲啫顫滈埀顒勫春閿熺姴纾兼俊顖氭贡閻╁孩淇婇悙顏勨偓鏇犳崲閹邦喒鍋撳鐓庡⒋鐎殿喗濞婇弫鎰板川椤忓懏鏉搁梻浣哄仺閸庤京澹曢銏犳槬闁挎繂娲犻崑鎾舵喆閸曨剛顦ラ梺缁樼墪閸氬绌辨繝鍥х濞达綀顫夊▍鍡涙⒑娴兼瑧鍒伴柡鍫墴閿濈偛顭ㄩ崟顓犵槇闂佹眹鍨藉褍鏆╅梻浣芥〃閻掞箓骞冮崒姘辨殾闁硅揪绠戝洿闂佸憡绋戦崐褰掓儎椤栨氨鏆︾紒瀣嚦閺冨牆鐒垫い鎺戝€归崗婊堟煃瑜滈崜鐔奉潖濞差亜宸濆┑鐘插€搁～鎴︽煟韫囨挾绠查柣鐔叉櫅椤曪綁骞庨懞銉︽珕闂佸吋浜介崕鍗烆嚕閸喒鏀介柍钘夋閻忥繝鎮楃粭娑樻噺閸忔粓鏌ｉ幇闈涘⒒婵炲牅绮欓弻锝夊箛閸忓摜鐩庨梺璇叉禋娴滄粏褰侀梺鎼炲劀瀹ュ牆鎯堝┑鐘殿暯閳ь剙纾崺锝団偓瑙勬磸閸旀垿銆佸鈧幃銏℃姜閺夋妫滄繝鐢靛Х閺佹悂宕戝☉銏╂晪妞ゆ挶鍨归崒銊ф喐閺冨牆绠氱€光偓閸曨偆锛滃┑顔矫崥瀣礊鎼粹檧鏀介柣鎰级閳绘洖霉濠婂嫮绠炵€规洘鍨块弫鎾绘偐瀹曞洤骞楁繝寰锋澘鈧劙宕戦幘缁樼厽婵°倐鍋撴俊顐ｇ箓椤曪綁骞庨挊澶愬敹闂侀潧顧€閼靛綊骞忓ú顏呪拺缁绢厼鎳庢禍褰掓煕鐎ｎ偆鈯曞ǎ鍥э躬瀹曟鎳栭埡鍐惧晭缂備胶铏庨崣鍐夐幘璺哄К闁逞屽墴閹鎲撮崟顒傤槰濠碉紕鍋樼划娆忕暦濞差亜鐒垫い鎺嶉檷娴滄粓鏌熼崫鍕ф俊鎯у槻闇夋繝濠傚閻帡鏌″畝鈧崰鏍х暦椤愶箑绀嬫い鎺戭槹椤ワ絽鈹戦悙鑼憼缂侇喖绉瑰畷鏇㈠箮鐟欙絺鍋撻弮鍫濈妞ゆ柨妲堣閻擃偊宕堕妸锕€鏆楀┑陇顕滅紞浣割潖濞差亜浼犻柕澶堝剾閿濆棙鍙忔俊顖滎焾婵倻鈧娲樺畝鎼佺嵁閹烘嚦鏃堝焵椤掑嫭鍋傛繛鎴欏灪閻撴洘绻涢幋婵嗚埞妤犵偞蓱閵囧嫰寮埀顒傛暜閹烘せ鈧棃宕橀鍢壯囨煕閳╁喛渚涙慨濠傛健濮婅櫣绮欏▎鎯у壉闂佸憡姊归悷鈺呭Υ娴ｈ倽鐔兼嚒閵堝顎嶉梻浣告啞缁嬫垼澧濋梺褰掓敱濡炶棄顫忓ú顏呭亗閹兼惌鍠楃紞妤呮⒑缁嬪尅鏀绘繛鑼枎椤曪綁骞栨担鍝ヮ吅闂佺粯鍔楅弫鎼佹儊閸儲鈷戦梻鍫熺〒缁犳碍淇婇幓鎺撳殗闁诡喚鍋炵粋鎺斺偓锝庡亞閸樼敻鎮楅悷鏉款伃闁稿锕畷鏇㈠籍閸屾浜鹃悷娆忓缁岃法绱掗崣澶婂姢妞ゆ洏鍎靛畷鐔碱敃鐎ｎ剙鏋庨悡銈夋偣閸パ冪骇婵炲懏鐗犲濠氬磼濮橆兘鍋撻悜鑺ュ殑闁告挷绀侀崹婵囥亜閺嶎偄浠滅紒鈧径瀣弿婵＄偠顕ф禍楣冩倵鐟欏嫭绀冩俊鐐舵椤曪絾绂掔€ｅ灚鏅濋梺鎸庣箓閹冲酣鐛幋锔解拻濞达絽鎲￠崯鐐烘煙缁嬫寧顥㈢€规洜鍠栭、鏇㈡晲閸ワ絽浜惧Δ锝呭暞閳锋帒霉閿濆懏鍟為柟顖氱墛閵囧嫰鏁傜拠鑼桓闂佽鍠楅敋闁宠鍨归埀顒婄秵娴滅偤宕濋敃鈧—鍐Χ閸℃娼戦梺绋款儐閹稿濡甸崟顖ｆ晣闁绘ɑ褰冮獮瀣⒑缂佹ü绶遍柛鐘崇〒缁鈽夊Ο閿嬵潔闁哄鐗勯崝宥呪枍閸ヮ剚鈷掑ù锝囨嚀椤曟粍绻涢幓鎺斝х€规洘鍨块獮姗€宕滄担鐚寸床闂備線鈧偛鑻晶瀵糕偓瑙勬礃閿曘垽銆佸▎鎾村仼閻忕偠妫勬俊鍥ㄧ節閻㈤潧啸闁轰焦鎮傚畷鎴濃槈閵忊€斥偓鑸电節闂堟侗鍎忕紒鈧径鎰厵缂備降鍨归弸鐔兼煟閹惧瓨绀嬮柡宀嬬秮楠炲洭顢楅崒娑欏枛闂備線娼уú锔炬崲閸愵喖绠為柕濠忓缁♀偓闂佺鏈粙鎺楀磿閹剧粯鐓涘ù锝呮憸婢э箓鏌＄仦鍓ф创濠碉紕鍏橀獮瀣攽閸℃浜鹃梻浣筋嚙鐎涒晜绻涙繝鍌ゆ綎闁惧繗顫夌€氭岸鏌熺紒妯轰刊闁告柨顦靛娲川婵犲倻浼囧銈庡亜椤﹁京鍒掔€ｎ亶鍚嬪鑸瞪戦弲婊堟⒑閸撴彃浜栭柛銊ユ贡缁﹪鏁冮崒娑掓嫼缂佺虎鍘奸幊蹇氥亹瑜忕槐鎺楁偐閸愯尙浼岄梺鎸庣箘閸嬨倕鐣烽妸褉鍋撳☉娆樼劷闁告﹩浜娲礈閹绘帊绨肩紓浣筋嚙閸熸潙鐣烽幎鑺ュ殟闁靛绲肩花濠氭⒑閹稿孩顥嗘い鏇嗗啠鏋嶇€广儱妫欓崣蹇撯攽閻樺弶鍣烘い蹇曞█閺屽秷顧侀柛鎾寸懃閿曘垺娼忛妸锕€寮块梺姹囧灪濞煎本寰勭€ｎ亞绐為梺褰掑亰閸橀箖宕㈤悽鍛娾拺闁诡垎鍕洶闂佺顑呯€氼參骞堥妸锕€绶炵€光偓閳ь剛澹?
     * - shared 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴閵嗘帒顫濋敐鍛婵°倗濮烽崑鐐烘偋閻樻眹鈧線寮撮姀鈩冩珕闂佽姤锚椤︻喚绱旈弴鐔虹瘈闁汇垽娼у瓭闂佹寧娲忛崐妤呭焵椤掍礁鍤柛锝忕秮婵℃挳宕ㄩ弶鎴犵厬婵犮垼娉涢惉濂告儊閸喓绡€闁汇垽娼у瓭闂佺锕︾划顖炲疾鐠鸿　妲堥柕蹇ョ磿閸橀亶鏌ｆ惔顖涒偓銉╁礋椤掑倸绲鹃梻鍌欑窔濞佳兠洪妶鍥ｅ亾濮橆偄宓嗛柣娑卞櫍楠炴帒螖閳ь剛绮婚敐鍡欑瘈闁割煈鍋勬慨鍐煙椤曞棛鎮肩紒杈ㄦ崌瀹曟帒鈻庨幒鎴濆腐濠电姵顔栭崹浼搭敋椤撶姵顫曢柣鎰嚟閻熷綊鏌嶈閸撴瑩顢氶敐鍡欑瘈婵﹩鍘兼禍婊堟⒑缁嬭法绠洪柛瀣姍瀹曟繈鎮滈懞銉㈡嫼闂佸湱顭堢€涒晝澹曢悽鍛婄厱閻庯綆鍋呯亸顓熴亜椤撯€冲姷妞ぱ傜窔閺屾盯鎮╅幇浣圭杹婵犵绱曢弫璇茬暦閻旂⒈鏁嶆慨锝勫尃閸ャ劎鍘卞┑鐐村灥瀹曨剟寮稿☉娆戠闁割偆鍣ュ▓婊勬叏婵犲啯銇濈€规洦鍋婂畷鐔碱敋閸涱喛鏅ч梻鍌欑閹诧繝寮婚妸鈺傚剹闁稿瞼鍋涢弰銉╂煏婢舵稓鐣辩紒鍓佸仜閳规垿鎮欓鍕紕闂佸摜濮甸崝妤冨垝椤撱垺鍋勭痪鎷岄哺閺咁剙鈹戦悙鏉戠仴鐎规洦鍓熷顐㈢暦閸モ晝锛濋梺绋挎湰閼归箖鍩€椤掍焦鍊愮€规洘鍔栭ˇ鐗堟償閵忊晛浠洪梻浣芥硶閸犳挻鎱ㄧ€靛摜鐜绘俊銈呮噺閻撴稓鈧箍鍎卞ù閿嬬閸︻厽鍠愰柣妤€鐗嗙粭姘舵煕婵犲偆鐓奸柡宀嬬畱铻ｅ〒姘煎灡妤旀俊鐐€栭弻銊ノ涘Δ鍛偓鏃堝礃椤斿槈褔鏌涢埄鍐炬當鐞涜偐绱撻崒娆掑厡濠殿喚鏁诲畷褰掑捶椤撶偛鐏婇梺鍓插亖閸庤京绮堥崘鈹夸簻闁哄啫鍊瑰▍鏇㈡煕濮楀棔绨肩紒缁樼箞閹粙妫冨☉妤冩崟闂備浇顕х换鎴犳崲閸繄鏆︽繝闈涱儏缁犵粯銇勯弮鍌涙珪闁?shared 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌熼梻瀵割槮缁惧墽绮换娑㈠箣濞嗗繒鍔撮梺杞扮椤戝棝濡甸崟顖氱閻犺櫣鍎ら悗楣冩⒑閸涘﹦鎳冪紒缁樺姌閻忓啴姊洪幐搴ｇ畵闁瑰啿閰ｅ鎼佸Χ婢跺鍘告繛杈剧到婢瑰﹪宕曢幋锔界厵闁圭粯甯楅崯鐐烘煙椤栨稒顥堝┑鈩冩倐婵＄柉顦撮柡?
     * - shared 闂傚倸鍊搁崐鎼佸磹閹间礁纾圭€瑰嫭鍣磋ぐ鎺戠倞妞ゆ帒顦伴弲顏堟偡濠婂啰效婵犫偓娓氣偓濮婅櫣绱掑Ο铏逛紘濠碘槅鍋勭€氼喚鍒掓繝姘亹缂備焦顭囬崢鐢告⒑绾拋娼愰柛鏃撶畵瀹曢潧鈻庨幘鏉戔偓鍨叏濮楀棗澧绘俊鎻掔秺閺屾洟宕惰椤忣厽顨ラ悙鏉戞诞妤犵偛顑呴埞鎴﹀箛椤忓懎浜濋梻鍌氬€烽悞锕傚箖閸洖绀夌€光偓閸曨偆锛欓悷婊呭鐢帞绮婚悙鐑樼厪濠电偛鐏濋崜濠氭煟閺冨倸甯剁紒鐘卞嵆楠炴牗娼忛崜褏銈烽梺閫炲苯澧柛鏃€鐟╁濠氭晲婢跺浜滈梺鍛婄缚閸庢煡宕甸崒婊呯＝濞达絽鎼牎闂佸湱鎳撳ú顓㈠箖娴兼惌鏁嬮柍褜鍓欓锝嗙鐎ｅ灚鏅ｉ梺缁樺姈椤旀牠宕崶銊ょ箚闁绘劦浜滈埀顒佺墵楠炴劖銈ｉ崘銊э紱闂佺粯鏌ㄩ幗婊堛€呴柨瀣ㄤ簻闁哄秲鍔庨惌宀€鐥幑鎰棄闂囧鏌ㄥ┑鍡橆棞缂佽尪顕ч湁婵犲﹤鎳庢禍鍓х磼缂佹绠炲┑顔瑰亾闂佹寧绻傚Λ娑㈠Υ閹扮増鈷戦柟棰佺閻忊剝绻涢崣澶岀疄濠碉紕鏁婚獮鍥级鐠侯煈鍞洪梻浣烘嚀椤曨厽鍒婇鐐嶏綁顢涘☉姘辩槇闂佹眹鍨藉褎绂掗敂鐣岀闁稿繗鍋愰幊鍛存煃瑜滈崜娆撴倶濮樿鲸鏆滄俊銈呭暟閻鈧箍鍎遍ˇ浠嬪极閸岀偞鐓曢柡鍥ュ妼楠炴绻涢崼銉х暫婵﹥妞藉Λ鍐ㄢ槈濮橀硸鍞哄┑鐘愁問閸ｎ噣宕㈤悡搴＄カ闂備礁澹婇崑渚€宕曢弻銉﹀亗闊洦绋撻崣鎾绘煕閵夛絽濡界紒鈧埀顒勬煕閻斾警鐒炬い?page_type 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛銈呭閺屾盯顢曢敐鍡欘槬缂備胶濮锋繛鈧柡宀€鍠栧畷婊嗩槾閻㈩垱鐩弻娑欑節閸涱厾鍘梺閫涚┒閸斿矁鐏掗梺鍦焾濞寸兘濡撮幇顔剧＝濞达絽鎼牎濠电姰鍨洪敃銏ゅ春閻愬搫绠ｉ柨鏃囨娴滃綊鏌ｆ惔鈩冭础濠殿喗鎸宠棢婵犻潧妫岄弨鑺ャ亜閺冣偓椤戞瑥顭囬幇鐗堢厽闁瑰灝瀚弧鈧梺缁樹緱閸ｏ絽顕ｆ禒瀣р偓鏍Ψ閵夆晛寮板銈冨灪椤ㄥ﹪宕洪埀顒併亜閹哄秵顦风紒璇叉闇夐柣妯烘▕閸庢劙鏌ｉ幘瀵告创闁哄本绋戦…銊╁焵椤掑倻鐭嗗ù锝堫潐濞呯娀鏌熺紒銏犳灍闁绘挻鐩幃姗€鎮欓幓鎺嗘寖濠电偞褰冮悺銊╁Φ閸曨垰顫呴柍鈺佸暟椤︾増绻濈喊妯峰亾瀹曞洤鐓熼悗瑙勬磸閸旀垿銆佸▎鎾崇煑闁靛／鍕樆婵犵數濮烽弫鍛婃叏閻戝鈧倹绂掔€ｎ亞鍔﹀銈嗗坊閸嬫挾鐥紒銏犲箹闁挎洏鍨介獮姗€顢欓悾灞藉箞闂備礁鎼崐钘夆枖閺囥垺鍊块柟闂寸劍閻撳啴鏌曟径娑㈡妞ゃ儱鐗忛埀顒冾潐濞叉﹢鏁冮姀銈冣偓浣糕枎閹惧啿绨ユ繝銏ｎ嚃閸ㄤ即宕板鑸靛亜闁糕剝绋掗崑锝夊级閻愭潙顎滄い鎺斿枛閺屾稒鎯旈姀鐘差潚濠殿喖锕ら幖顐﹀煝鎼淬劌绠氱憸宥囩尵瀹ュ應鏀芥い鏃傘€嬮弨缁樹繆閻愯埖顥夐柣锝囧厴婵℃悂鏁傞崜褜鍟庢繝娈垮枟閿曗晠宕㈡總鍛婂€堕柟缁㈠枟閻撴瑦銇勯弽銊ㄥ妞ゅ浚鍘介妵鍕閿涘嫧妲堝銈庡亝缁诲啫顭囪箛娑樜╅柨鏃囶嚙閺?1 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌熼梻瀵割槮缁炬儳顭烽弻锝夊箛椤掍焦鍎撻梺鎼炲妼閸婂潡寮诲☉銏╂晝闁挎繂妫涢ˇ銉х磽娴ｅ搫孝缂傚秴锕璇差吋婢跺﹣绱堕梺鍛婃处閸嬧偓闁稿鎸荤换婵嗩潩閵夈垹浜鹃柛娑欐綑缁犵敻鏌熼悜妯肩畺閻庨潧鐭傞弻锝嗘償椤栨粎校婵炲瓨绮嶇划鎾汇€佸Δ鍛潊閹鸿櫕绂嶅鍕╀簻闁规澘澧庣粙鑽ょ磼閳ь剟宕橀鐣屽幍婵炴挻鑹鹃悘婵囦繆婵傚憡鎳氶柨婵嗩槹閻撴洘銇勯鐔风仴闁哄鍊濋弻娑橆潨閸℃ぞ鍠婂┑顔硷攻濡炶棄鐣烽锕€绀嬫い鎺嗗亾妞ゅ孩鐩娲川婵犲啫鏆楅梺鍝ュУ閻楃娀鎮伴鈧畷姗€鈥﹂幋鐐茬紦闂備線鈧偛鑻晶瀛橆殽閻愭彃鏆欓柍璇查叄楠炴﹢寮堕幋鐐垫澓濠电姷鏁搁崑娑㈡偋婵犲嫧鍋撶粭娑樻硽婢跺绶為柟閭﹀幐閹风粯绻涙潏鍓у埌闁硅绻濋獮鍡涘醇閵夛妇鍘甸梺鍛婂姌鐏忔瑧绮绘繝姘厵妞ゆ梹鍎抽崢鎾煕閳哄绡€鐎规洏鍔戦、姗€鎮╅崹顐ｇ槖闂傚倷娴囧畷鍨箾閳ь剛绱撻崒娑樺摵濠碘剝鎸抽崺鈧い鎺嶆缁诲棝鏌熺紒妯虹濠⒀嶇畵閺岋紕浠︾化鏇炰壕鐎规洖娲﹀▓鐓庮渻閵堝棙鈷掗柛妯犲洤姹叉繛鍡樺灦閸嬫牗绻濋棃娑氬ⅱ缁惧彞绮欓弻娑氫沪閹规劕顥濋梺閫炲苯澧柟顔煎€搁悾鐑藉箛閺夊じ绱堕梺闈涳紡閸涱垰甯梻鍌欑濠€閬嶆惞鎼淬劌绐楁俊銈呮噹绾惧鎮楅敐搴℃灓闁告瑦鎹囬弻娑㈠Ψ閿濆懎顬夌紓浣插亾闁逞屽墴濮婃椽骞栭悙鎻掝瀴缂備浇顕ч悧鎾愁嚕婵犳艾惟闁靛鍨洪弬鈧梻浣虹《閸撴繈鏁嬮梺鍝勬噽閺佽顫忓ú顏勪紶闁告洦鍋呭▓顓㈡⒑缂佹﹩娈旀俊顐ｇ箞楠炲啴鎮欓崫鍕€銈嗗姉婵磭鑺辨繝姘拺闁革富鍘奸崝瀣煕閵娧勬毈鐎殿喗濞婂顒€螞?
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

        // 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻锝夊箣閿濆憛鎾绘煕婵犲倹鍋ラ柡灞诲姂瀵挳鎮欏ù瀣壕闁割偅娲栭悞鍨亜閹哄棗浜鹃梺鍝ュ枎绾绢厾鍒掔拠娴嬫婵☆垶鏀遍～宥呪攽閳藉棗鐏ｉ柍宄扮墕鍗辨い鏍ㄧ〒缁♀偓闂佹眹鍨藉褎绂掗敃鍌涚厱闁靛鍔嶇涵鐐亜椤愶絿绠炲┑鈩冩倐閸┾剝鎷呮笟顖涙暏濠电姵顔栭崰妤呪€﹂崼銉ユ槬闁哄稁鍘肩壕褰掓煕椤垵浜炵紒鐘荤畺閺岀喓鈧數顭堥崜鍗灻归悡搴㈩棦闁哄瞼鍠撻埀顒傛暩椤牊绂掗敃鍌涚厱婵炲棗绻掔粻鑼磼缂佹绠栫紒缁樼箞瀹曟帒顫濋梻瀛樻▕婵犵數鍋涢悺銊у垝瀹ュ洤鍨濋柟鎹愵嚙閽冪喖鏌ㄩ悢鍝勑㈤柣鎰躬閺屽秵娼悧鍫▊濠电偛鐭堟禍婊堚€旈崘顔嘉ч柛鎰╁妼婵垺绻濆▓鍨珝妞ゃ儲鎸稿嵄闁圭増婢樼粻铏繆閵堝倸浜剧紓浣哄У缁嬫帡濡甸崟顖氱婵°倐鍋撻悗姘煎櫍瀵娊鏁冮崒娑掓嫽婵炴挻鍩冮崑鎾绘煃瑜滈崜姘辩矙閹捐鐓橀柟鐑橆殕閻撴洟鏌￠崘锝呬壕闂佹悶鍔岄悘婵嬵敋閿濆鏁冮柨鏇楀亾缂佺姵鐩弻鈩冨緞婵犲嫪铏庣紓浣瑰姈缁嬫垿鈥旈崘顔嘉ч柛鈩冪懃椤呯磽娓氬洤鏋ゅ┑鐐╁亾閻庤娲忛崕闈涚暦閵娧€鍋撳☉娅辨岸骞?page_type 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛銈呭閺屾盯顢曢敐鍡欙紩闂侀€炲苯澧剧紒鐘虫尭閻ｉ攱绺界粙娆炬綂闂佺偨鍎遍崯璺ㄨ姳閵夆晜鈷掑ù锝囩摂濞兼劕顭块悷鐗堫棡闁哄懓娉涜灃闁告侗鍘鹃敍娑㈡煟鎼搭垳绉甸柛鐘愁殜閹繝寮撮姀锛勫帗闂佸疇妗ㄧ粈渚€寮冲▎鎾寸厵闁兼祴鏅╅悞楣冩?1 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌熼梻瀵割槮缁炬儳顭烽弻锝夊箛椤掍焦鍎撻梺鎼炲妼閸婂潡寮诲☉銏╂晝闁挎繂妫涢ˇ銉х磽娴ｅ搫孝缂傚秴锕璇差吋婢跺﹣绱堕梺鍛婃处閸嬧偓闁稿鎸荤换婵嗩潩閵夈垹浜鹃柛娑欐綑缁犵敻鏌熼悜妯肩畺閻庨潧鐭傞弻锝嗘償椤栨粎校婵炲瓨绮嶇划鎾汇€佸Δ鍛潊閹鸿櫕绂嶅鍕╀簻闁规澘澧庣粙鑽ょ磼閳ь剟宕橀鍡欙紲缂傚倷鐒﹂…鍥╃不閻愮繝绻嗘い鎰╁灩閺嗘瑩鏌嶉挊澶樻Ц妞ゎ偅绻堥、妤佸緞鐎ｎ偆妲曢梻鍌氬€搁崐鐑芥嚄閸撲礁鍨濇い鏍ㄧ矋閺嗘粓鏌熼悜姗嗘畷闁哄懏绻堥弻鏇㈠醇濠垫劖效缂備胶濮电敮鈥愁潖缂佹ɑ濯撮柧蹇曟嚀缁楋繝姊洪幐搴ｎ暡濞ｅ洤锕、娑橆潩閹规劕鎯堥柣搴ゎ潐濞叉ê煤濠靛牏涓嶆繛鎴欏灩缁秹鏌涚仦鍓х煠闁哥喎閰ｅ缁樻媴閸涘﹤鏆堟繛鎾寸椤ㄥ﹤鐣烽幋锕€绀嬫い鏍ㄦ皑閸旓箑顪冮妶鍡楃瑐闁煎疇鍩栭弲鍫曟偨閸涘﹦鍘梺绯曞墲椤ㄥ懘寮抽敐鍛斀闁挎稑瀚崢鎾煕閳哄绡€鐎规洘甯掗～婵嬫晲閸涱剙顥氶梻浣虹帛閸ㄥ吋鎱ㄩ妶澶嬪亗闁绘绮悡鏇熺節闂堟稑顏╅柛鏃€绮撻弻锟犲幢韫囨梹鐝氶梺鍝勬湰濞茬喎鐣烽崡鐐嶇喖宕崟鍨稈闂傚倷娴囬鏍窗閺嶎厼搴婇柡灞诲劗閳ь剨绠撴俊鎼佸煛娴ｄ警妲规俊鐐€栫敮鎺楀磹閹间礁鍚归柟鐑橆殕閳锋帒霉閿濆嫯顒熼柣鎺楃畺閺屻劑寮村Ο琛″亾濠靛绠栨俊顖濄€€閺€浠嬫倵閿濆骸浜滃ù鐘层偢閹鎮烽弶娆句純闂佺顑呴敃銈夆€﹂崶顒€鐓涢柛娑卞枛娴狀垶姊洪崨濠勭畵閻庢凹鍙冮獮鍡涘醇閵夛妇鍘鹃梺鍝勵槼濞夋洘绂掗姀銈嗙厓閻熸瑥瀚悘锔筋殽閻愯韬柡灞剧⊕缁绘繈宕橀埡鍐炬Ч闁诲氦顫夊ú妯侯熆濮椻偓閿濈偛鈹戠€ｅ灚鏅ｉ梺缁樺灥濡鈻撴總鍛婄厽閹肩补鈧啿杈呴梺绋款儐閹瑰洭寮诲☉銏犲嵆闁靛鍎遍獮瀣⒑瑜版帗鏁辨俊鐐舵椤繐煤椤忓嫬绐涙繝鐢靛Т鐎氀兾ｉ崼銉︹拺闁圭瀛╃壕鎼佹煕婵犲啯绀嬫繝鈧担鍓叉富闁靛牆妫欓悡銉︺亜椤愶絾绀冪紒鍌氱Т椤劑宕奸悢鍝勫汲婵犵數濞€濞佳兾涘☉姘变笉闁绘鐗勬禍婊堟煃閸濆嫸宸ュ褎澹嗙槐鎺撴綇閵娿儲璇炲銈冨灪瀹€绋跨暦閵娾晩鏁囬柣鎰問閸熷牓姊虹拠鏌ヮ€楅柣蹇旇壘椤灝螣鐏忔牕浜炬慨姗嗗亜瀹撳棝鏌ｅ☉鍗炴珝鐎规洖鐖奸、妤佸緞鐎ｎ偅鐝滈梻鍌欑閹诧繝宕濋弴銏犵柈妞ゆ牗顕㈠ú顏呭亜闁绘挸娴烽悾鍝勨攽鎺抽崐鏇㈠箠鎼达絿鐭嗛柛灞惧嚬閻斿棝鎮归搹鐟扮殤婵﹥顨呴…璺ㄦ喆閸曨剛顦板┑顔硷功缁垶骞忛崨鏉戝窛濠电姴鍊瑰▓妯荤節閻㈤潧浠︾憸鏉垮暟缁棃鎮烽柇锕€娈ㄥ銈嗗笂閼冲爼鎮疯ぐ鎺撶厓鐟滄粓宕滃▎鎾村仼闁绘垼妫勭粻锝夋煥閺囨浜剧紓浣哄Т椤兘寮婚埄鍐ㄧ窞閻庯綆浜炴禒鑲╃磽娴ｇ懓濮夐柛瀣ㄥ€曢～蹇斻偊鐟併倓姹楅梺鍦劋閸ㄦ娊宕版繝鍥ㄢ拺闁告稑锕﹂幊鍐ㄎ旈悩宕囨憙闁诲繐顑夊娲川婵犲倸袝闂佺粯鎸搁悧鍡涘Υ閹烘挾绡€婵﹩鍘鹃崢顏堟⒑閸撴彃浜濈紒璇茬墦椤㈡挸螖閳ь剟婀佸┑鐘才堥崑鎾剁磼椤旂晫鎳囩€殿喛顕ч埥澶婎煥閸涱垱婢戦梻浣告惈閸燁垶骞戞笟鈧崺鈧い鎺戝暞绾爼鏌?
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
        // 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻锝夊箣閿濆憛鎾绘煕婵犲倹鍋ラ柡灞诲姂瀵挳鎮欏ù瀣壕闁割偅娲栭悞鍨亜閹哄棗浜鹃梺鍝ュ枎绾绢厾鍒掔拠娴嬫婵☆垶鏀遍～宥呪攽閳藉棗鐏ｉ柍宄扮墕鍗辨い鏍ㄧ〒缁♀偓闂佹眹鍨藉褎绂掗敃鍌涚厱闁靛鍔岄悡鎰磼瀹€鍕喚闁诡喗绮岃灒閻犲洦褰冩导搴ㄦ⒒娴ｇ瓔娼愰柛搴″悑閹便劑濡舵径瀣簵闂佸憡鍔﹂崰妤呮偂閺囩喓绡€闂傚牊绋掗ˉ婊勩亜韫囧﹥娅婇柡灞界Х椤т線鏌涢幘瀵哥疄闁挎繄鍋炲鍕箛椤掑倻鏉介梻渚€娼ч…鍫ュ磿濞差亝鍋傞柕澶嗘櫆閻撴洟鏌￠崶顭戞畷婵炲懎鍟扮槐鎺楀Ω閵夘喚鍚嬪┑顔硷攻濡炶棄鐣烽锕€绀嬫い鎺嗗亾缂佹劖鐩铏圭矙濞嗘儳鍓抽梺绋款儍閸婃稑螞閵忋倖鈷戠紓浣癸供閻掍粙鏌℃担鍛婃喐濠㈣娲樼缓浠嬪川婵犲嫬骞嶉梻鍌欑贰閸欏繒绮婚幋婵愬殨闁绘劦鍓涚粻楣冩煕椤愩倕鏋旈柣顓熷浮閺屸€崇暆閳ь剟宕伴弽顓溾偓浣糕枎閹惧磭顔囬柟鑹版彧缁插鍩€椤掍礁濮嶆慨濠呮閳ь剙婀辨慨鐢杆夋径瀣ㄤ簻闁挎洖鍊瑰☉褎銇勯弴顏嗙М鐎规洘鍔欓幃銈嗘媴閸撴劏鏅涢埞鎴︽偐閹颁礁鏅遍梺闈╃秵閸ㄨ鲸绌辨繝鍥х倞闁冲搫鍋嗗鐔兼⒑鐟欏嫬绀冩い鏇嗗懎顥氶柛蹇撳悑閸欏繑淇婇婵嗗惞婵☆垰鍊块弻鏇＄疀鐎ｎ亖鍋撻弽顓熷亗闁绘棃鏅茬换鍡涙煏閸繂顏柛鏂跨Ф閳ь剝顫夊ú鏍儗閸岀偛钃熼柣鏃傗拡閺佸﹪鎮归崶銊ョ祷缂佷緡鍠栭—鍐Χ閸愩劎浠鹃梺鑽ゅ暱閺呯娀鐛崘銊㈡瀻闁瑰灝鍟弲銏ゆ⒑闁偛鑻晶鏉款熆鐟欏嫭绀嬫い銏＄洴閹瑧鍒掔憴鍕伖闂傚倷绀侀幉锛勭矙閹达附鏅濋柨鏂垮⒔娑撳秹鏌熼崜褏甯涢柛濠傜仛閹便劌螣閻撳骸浠橀梺鍝勵儐閻╊垶寮婚敐澶嬫櫜闁糕剝鐟ч悾鐢告⒑鐎圭姵顥夋い锔诲灦閿濈偛鈹戦崶銊хФ闂佸啿鎼鍥⒒椤栨稓绡€闁汇垽娼ф禒鈺呮煙濞茶绨界€垫澘锕畷绋课旈埀顒傜不閻樼粯鐓欓柟顖嗗懏鎲奸梺鍛婄懃缁绘﹢寮诲☉銏╂晝闁靛牆鎳忛悗顓熺箾鐎涙鐭嬬紒顔芥崌瀵寮撮悢铏诡啎闂佸壊鐓堥崰鏍ㄦ叏閺囥垺鈷戦柟鑲╁仜閳ь剚鍔欏畷鎴﹀箻缂佹ǚ鎷绘繛鎾村焹閸嬫挻绻涙担鍐插幘濞差亝鏅濋柛灞炬皑椤︻噣姊洪崫鍕偍闁搞劌缍婇幃锟犲灳閹颁胶鍞甸梺鍏兼倐濞佳勬叏閸ャ劊浜滄い鎰╁灪閸ゅ洭鏌?
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
     * 闂?scope 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌熼梻瀵割槮缁炬儳顭烽弻锝夊箛椤掍焦鍎撻梺鎼炲妼閸婂潡寮诲☉銏╂晝闁挎繂妫涢ˇ銉х磽娴ｅ搫孝缂傚秴锕璇差吋婢跺﹣绱堕梺鍛婃处閸撴瑥鈻嶉敐澶嬧拺缂佸鍎婚～锕傛煕閺傝法鐒搁柛鈺冨仱楠炲鏁冮埀顒傚閸忓吋鍙忔慨妤€妫楅獮鏍煛閸℃澧︽慨濠呮缁瑥鈻庨幆褍澹勯梻浣侯焾閿曘儳鎹㈤崼婵愬殨濠电姵鑹鹃崡鎶芥煟閺冨洦顏犳い鏃€娲熷铏圭磼濡搫袝闂佸憡鎸诲畝鎼佸箖閻㈢绫嶉柛顐ゅ暱閹?`_build_page_progress[<page_type>][skip_remaining_blocks]=true`闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈤崨濠勭Ф婵°倧绲介崯顖烆敁瀹ュ鈷戦柟鑲╁仜閳ь剚鐗犲畷婵嬪冀椤撶倣锕傛煕閺囥劌鐏犻柛鎰ㄥ亾婵＄偑鍊栭崝锕€顭块埀顒佺箾瀹€濠侀偗婵﹨娅ｇ划娆撳锤濡ゅň鍋撳Δ鍐＜濠㈣泛锕︾粔铏光偓娈垮枛椤嘲顕ｉ幘顔芥櫖闁告洦鍘藉鎴︽⒒娓氣偓濞佳囨晬韫囨稑妞介柛鎰典簻閹偞绻濈喊澶岀？闁稿鍨垮畷鎰板箛閺夎法鏌ч梺缁樏崵銏″緞閹邦剛顔掗柣鐘叉穿鐏忔瑩宕濋敃鈧—鍐Χ閸℃娼戦梺绋款儐閹稿墽妲愰幒妤佸亹闁肩⒈鍎疯閳ь剝顫夊ú妯好洪悢鐓庤摕闁糕剝顨忛崥瀣煕濞戝崬鐏遍柛鐐垫暬濮婅櫣鎷犻幓鎺戞瘣缂傚倸绉村Λ婵嬪春閳?pending/running 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮撮悙娴嬪亾瑜版帒纾块柟瀵稿У閸犳劙鏌ｅΔ鈧悧鍡欑箔閹烘梻纾奸柍褜鍓氬鍕沪缁嬪じ澹曢梺绋跨箰椤︻垱绂嶆ィ鍐┾拺闂侇偆鍋涢懟顖涙櫠閹绢喗鐓曢柍瑙勫劤娴滅偓淇婇悙顏勨偓鏍暜婵犲洦鍤勯柛顐ｆ礃閸嬪倹銇勯弽顐沪闁绘挻娲樻穱濠囧Χ閸涱収浠鹃梺缁樼箥娴滄粏褰侀梺鎼炲劀瀹ュ懎顫犻梻渚€鈧偛鑻晶顖滅磼鐎ｎ偄娴柡浣割儑缁辨挻鎷呯粙娆炬殺闂佺顑冮崐婵嗩嚕婵犳碍鍋勯柛蹇氬亹閸旂兘姊洪幐搴㈢５闁稿鎸搁…鑳槾濠⒀勵殜婵?section 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴闇夐柨婵嗙墛椤忕姷绱掗埀顒佺節閸屾鏂€闂佺粯蓱瑜板啯绂嶉悙鐑樼厽闁圭儤姊瑰▍鏇㈡煙閸欏鎽冪紒鐘崇洴瀵挳鎮ゆ担鍦◥闂傚倷鑳剁划顖炲礉閺囥埄鏁嬫い鎾跺Т婵剟鏌嶈閸撶喎顫忓ú顏勭闁绘劖褰冩俊褔姊洪幖鐐插姶闁绘挸顦卞Σ鎰邦敆閳ь剟鍩為幋锔藉亹闁割煈鍋呭В鍕⒑缁嬫鍎愮紒瀣灱閻忔帗绻濋悽闈浶㈤柛鐔跺嵆閵嗗懘宕ｆ径宀€鐦堟繝鐢靛Т閸婄粯鏅堕弴鐘电＜闁逞屽墴瀹曟﹢顢欓悾灞藉笚闁荤喐绮嶇划鎾崇暦濠婂牊鍋勫┑鍌氼槹缂嶅骸鈹戦悙鍙夆枙濞存粍绮庣划鏄忋亹閹烘挾鍘介梺褰掑亰閸樿偐寰婄拠娴嬫斀妞ゆ洍鍋撴い銉︽尵濡叉劙骞樼€涙ê顎撻梺鍛婄箓鐎氬懘鏁愭径瀣缓濡炪倖鐗楃划灞剧瑜旈弻?done闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈤崨濠勭Ф婵°倧绲介崯顖烆敁瀹ュ鈷戠紒瀣儥閸庡繑銇勯幋婵愭█鐎殿喛顕ч埥澶娢熼柨瀣垫綌闂備礁鎲￠〃鍫ュ磻閻斿摜顩锋い鎾卞灪閸婄敻鎮峰▎蹇擃仾缂佲偓閳ь剟鎮楀▓鍨灈闁绘牕銈搁悰顕€宕奸妷銉庘晠鏌曟径濠冩崳闁哥姵鐗曢悾鐤亹閹烘繃鏅╅梺璇″瀻閸愵亞绐楅梻鍌氬€搁崐鐑芥嚄閸洍鈧箓宕奸妷顔芥櫈闂佺鐬奸崑娑㈡偪閻愵剛绡€濠电姴鍊归崳铏光偓瑙勬礀瀵墎鎹㈠☉銏犵婵炲棗绻掓禒鐓幬旈悩闈涗杭闁搞劎鍎ょ粚杈ㄧ節閸ヨ埖鏅┑顔斤供閸樹粙宕曢幘缁樺仩婵﹩鍘剧粻鏍磼缂佹绠撻柍缁樻崌瀹曞綊顢欓悾灞奸偗闂傚倷鐒︾€笛兠洪敂鐣岊洸闁绘劙娼ч崹婵嗏攽閻樺疇澹橀柛鎰ㄥ亾婵＄偑鍊栭幐楣冨磻閻樿绠洪柡鍥ュ灪閳锋垿鏌熺粙鎸庢崳闁宠棄顦甸幃妤€顫濋梻瀵哥泿闂佸疇顔婄划娆撱€侀弮鍫濋唶闁绘棁娓归崠鏍⒒娴ｈ鍋犻柛搴灦瀹曟繂鐣濋埀顒€顕ユ繝鍕珰婵炴潙顑嗛弬鈧俊鐐€栭弻銊╁箹椤愶附鍊堕柛娆忣槹閸欏繐鈹戦悩鍙夊櫤妞ゅ繒濮风槐鎺楊敊閻ｅ本鍣ч梺瀹狀嚙闁帮綁鐛崱姘兼Щ婵犵濮嶉崨顖滐紳闂佺鏈悷褔宕濆鍥ㄥ枑闁哄鐏濋弳娆愩亜椤撶偞鍠橀柡浣规崌閹晠妫冨☉姘ュ亰闂傚倸顭崑鍕洪敂鍓х煓闁圭儤顨呯壕濠氭煙闁箑骞樼紒鐘荤畺閺屻倗鍠婇崡鐐差潾闁汇埄鍨遍惄顖炲蓟閿熺姴妞介柛鎰典簻閸╁矂姊虹€圭媭娼愰柛銊ユ健楠炲啴鍩￠崘顏嗭紲濠碘槅鍨伴…鐑藉极椤忓牊鈷掑ù锝堟鐢稒銇勯妸銉﹀櫧闁瑰箍鍨硅灒濞撴凹鍨板▓銊╂⒑瑜版帗锛熺紒鈧笟鈧幃鐐哄垂椤愮姳绨婚梺鍦劋閸ㄧ敻顢旈鍫熺厓闂佸灝顑呯粭鎺楁婢舵劖鐓ユ繝闈涙婢跺嫰鏌涢妶鍌氫壕濠碉紕鍋戦崐鏍哄Ο鐓庡灊閹艰揪绲鹃～鏇㈡煙閻戞ɑ鐓涢柛瀣崌閺佹劖鎯旈垾鑼泿闂備浇顕х换鎴﹀箰閹惰棄钃熼柣鏃傗拡閺佸秵鎱ㄥΟ澶稿惈闁告棏鍨堕幃妤冩喆閸曨剛顦ㄩ柣銏╁灡鐢繝宕洪妷锕€绶炲┑鐘插瀵ゆ椽姊虹化鏇炲⒉妞ゃ劌妫濋、娆撳箻缂佹ǚ鎷婚梺绋挎湰閻熝囁囬敃鍌涚厱闁绘棃鏀遍崑銉︻殽閻愬澧垫い銏℃礋閺佸啴鍩€椤掑倻涓嶉柣妯款嚙缁犲綊寮堕崼婵嗏挃闁诡喖銈搁弻锝夘敇閻愭惌妫﹂梺鍝勬湰缁嬫挻绂掗敃鍌氱畾鐟滄粌螞濠婂牊鈷戠紒瀣儥閸庢劙鏌熼悷鐗堝枠闁诡喕鍗抽、姘跺焵椤掆偓閻ｇ柉銇愰幒婵囨櫓闂佺粯鎸哥€垫帒顭囧☉銏♀拻闁稿本鐟ㄩ崗宀勬煙閾忣偅宕岀€规洦鍨跺畷绋课旀担鍝勫笌闂備焦瀵х换鍌炈囨导鏉戠；婵☆垱鐪规禍婊堟煛閸ヮ煈娈斿ù婊呭亾缁绘繂鈻撻崹顔界亐闂佺顑嗛幑鍥ь潖閾忓湱纾兼俊顖氭禋娴滄粏鐏嬪┑掳鍊曢崯鎵矆婵犲倵鏀介柣妯哄级閹兼劙鏌＄€ｂ晝绐旈柡宀€鍠栭幃褔宕奸悢鍝勫殥闁诲海鎳撻幉锛勬崲閸愵喖桅闁告洦鍨伴崘鈧梺闈浤涢崨顖氬箻闂傚倷绀侀幗婊堝磻濞戞氨绀婂┑鐘插亞濞兼牜绱撴担璇＄劷闁荤喎缍婇弻宥堫檨闁告挾鍠栧畷娲焵椤掍降浜滈柟鐑樺灥閳ь剙鎲＄粋鎺戭煥閸喓鍘惧┑鐐跺蔼椤曆囨倶閿熺姵鐓涢柛娑卞幘閸╋絾銇勯姀锛勨槈闁宠棄顦灃闁告劦浜為弳浼存⒒閸屾瑧顦﹂柟璇х節閹兘濡烽埞褍娲、娑橆煥閸涱垳鏆ラ柣鐔哥矋濡啫顕ｆ繝姘亜闁兼祴鏅涚粊锕傛⒑閸撹尙鍘涢柛鐘崇缁傛帗绺介崨濠勫弰婵炴潙鍚嬮悷褔骞冮懖鈺冪＜閺夊牄鍔嶇亸浼存煙瀹勭増鍣洪柟渚垮姂楠炲顢樺┑瀣粣闁诲氦顫夊ú妯兼崲閸繄鏆﹂柕濞р偓閸嬫挸鈽夊▍杈ㄥ哺楠炲繐煤椤忓應鎷洪梺闈╁瘜閸欏酣鎮炴ィ鍐╁€垫繛鎴炲笚濞呭﹪鎸婇悢鍏肩厱闁斥晛鍟伴埊鏇㈡煟閹惧瓨绀嬮柟顔筋殜閺佹劖鎯旈垾鑼泿濠电姵顔栭崰鏍囨潏鈺傤潟闁圭儤顨忛弫濠囨煠濞村娅呴柍顏嗘暬閹鎲撮崟顒傤槬閻庤娲﹂崜鐔煎春閵夛箑绶炲┑鐐靛亾閻庡姊洪悷鎵憼缂佽鍊规穱?
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
    public function markTaskPendingForFreshRepair(array $scope, string $taskKey, string $message, bool $resetAttemptNo = true): array
    {
        $patch = [
            'status' => self::TASK_STATUS_PENDING,
            'message' => $this->sanitizePlanJsonTaskFailureMessageForView($message, 'Retrying generation in a fresh queue.'),
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ];
        if ($resetAttemptNo) {
            $patch['attempt_no'] = 0;
        }

        return $this->setTaskState($scope, $taskKey, $patch, false);
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
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message, false);
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

            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message, false);
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧湱鈧懓瀚崳纾嬨亹閹烘垹鍊炲銈庡墻閸撴岸鎯勯姘辨殾闁绘梻鈷堥弫宥嗘叏濡潡鍝洪柣鎺斿亾娣囧﹪鎮欓鍕ㄥ亾閺嶎厽鍋嬫俊銈呭暙閸ㄦ繄鈧箍鍎遍幊鎰板汲閿曞倹鐓涢悘鐐额嚙閸旀粓鏌ｉ幘瀛樼闁诡喗锕㈤幃娆愭媴閸愨晩鈧姊洪幖鐐测偓鏍涢崘顔艰摕闁挎繂顦伴崑鍕磼鐎ｎ厽纭剁憸鏉跨墦濮婅櫣绱掑Ο鑽ゅ弳闂佸憡鑹鹃澶婄暦閻㈢绀冩い鏃傛櫕閸樻捇姊洪崨濠勭畵閻庢凹鍓熷鎼佹晜閸撗咃紲闁哄鐗勯崝搴ㄥ矗閳ь剙鈹戦纭峰姛缂侇噮鍨堕獮蹇涘川閺夋垵绐涙繝鐢靛Т閸燁偊宕滈崹顐＄箚闁绘劦浜滈埀顑懐纾芥慨妯挎硾绾偓闂佸憡鍔樼亸娆撳汲閿旂偓鍠愰柣妤€鐗嗙粭姘舵煕鐎ｃ劌濮傞柡灞剧洴楠炴﹢宕橀懠顒勭崜闂備礁鎼幊澶愬疾濠婂懏宕叉繝闈涱儐閸嬨劑姊婚崼鐔峰瀬闁靛骏绱曠粻楣冩偣閸ュ洤鎳愰弳銈夋⒑鐠団€虫櫢闁靛牆娲ㄩ弶绋库攽閻愭潙鐏﹂柣鐔濆洤鍌ㄩ梺顒€绉甸埛鎴︽煛閸屾ê鍔滄繛鍛嚇閺屾盯鎮╃€圭姴顥濋梺宕囩帛閹瑰洤顕ｉ鈧崺鈧い鎺戝妗呴梺鍛婃处閸ㄧ増鍎梻浣瑰濮婂宕戦幘璇查棷妞ゆ柨澧界壕钘壝归敐鍕煓闁告繃妞介幃浠嬵敍閵堝洨鐦堥梺闈涙缁€渚€鍩㈡惔銊ョ闁哄鍨抽幃锝夋⒑鐠囪尙绠抽柛瀣仱瀹曟洟骞庨挊澶婄€梻渚囧墮缁夌敻鎮￠悢鐓庣婵烇綆鍓欓悞娲煕閻旈绠婚柡灞剧洴閹晛鐣烽崶褉鎷伴梻浣筋嚃閸犳鏁嬪銈庡亝缁诲牓銆佸Δ浣哥窞濠电姴鍠氶崬顐︽⒒閸屾瑧顦﹂柟娴嬧偓瓒佹椽鏁冮崒姘鳖槶濠电偞鍨堕懝鐐叏椤掑嫭鐓冪憸婊堝礈濮樿鲸宕叉繛鎴炵懄缂嶅洭鏌涢幘妤€鎲涘顑芥斀闁绘劕寮剁€氬懐绱撳鍕獢妤犵偛鍟撮弫鎾绘偐閼碱剦鍚呮繝鐢靛█濞佳囧疮椤栫偛妫橀柍褜鍓熷缁樻媴閾忕懓绗￠梺鍛婃⒐閿曘垹鐣峰ú顏勫唨妞ゆ垵褰炲Ч妤呮偡濠婂啰绠虫俊鍙夊姍楠炴帒螖閳ь剛绮婚悽鍛婄厵闁绘垶锚閻忓秹鏌熺粙鍨殻婵﹥妞藉畷銊︾節閸屾粎鎳栭梺姹囧焺閸ㄨ京鏁敓鐘偓浣肝熼懡銈夋缂備礁顑嗙€笛囧Φ濠靛鈷戦柛娑橈工婵箓鏌涘Ο缁樺€愮€规洘鍨块獮妯兼嫚閼碱剦鍞堕梺鍦帶閻°劎绮欓幇顔垮С濠电姵纰嶉埛鎴︽偡濞嗗繐顏╃紒鈧崘鈺冪濠㈣泛顑囬‖濂告煃椤忓懏灏︽慨濠勭帛閹峰懏绗熼婊冨Ъ婵＄偑鍊栭崹闈浳涘┑瀣祦闁硅揪绠戦悙濠冦亜閹哄棗浜剧紓浣哄У閻楃娀寮婚敐澶婄闁挎繂鎲涢幘缁樼厱閻庯綆浜堕崕鏃堟煛鐏炲墽娲存い銏℃礋婵″爼宕ㄩ閿亾妤ｅ啯鐓熼幖娣灮椤ｆ彃鈹戦悙璇у伐妞ゎ偄绻掔槐鎺懳熺拠宸偓鎾绘煟閻斿摜鎳冮悗姘煎墯缁傛帡鍩￠崨顔规嫼闂佸憡绋戦敃銉╂偂閵壯呯＜濠㈣泛顑嗙亸锕傛煙椤旇棄鍔ら柍瑙勫灩閳ь剨缍嗘禍婊呯玻濞戞瑧绡€闁汇垽娼у皬闂佺厧鍟挎晶搴ｅ垝鐠囧樊娼╅柤鍝ヮ暯閹锋椽姊洪崨濠勭畵閻庢艾鍢插嵄鐟滅増甯楅悡鏇㈡煃鏉炴媽鍏屽褝濡囬埀顒冾潐濞插繘宕曢幎钘夌劦妞ゆ帒锕︾粔鐢告煕閻樺啿鍝虹€规洩缍侀獮妯肩磼濡厧骞堥梻渚€娼ч¨鈧紒鑼跺Г娣囧﹪鎮惧畝鈧壕濂告煃瑜滈崜鐔风暦婵傜唯闁挎梹鍎抽獮妤佺節閻㈤潧浠﹂柛銊ョ埣閹囧即閵忕姷顦梺鎸庢婵倝宕?pending/running闂?
     *
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾圭€瑰嫭鍣磋ぐ鎺戠倞鐟滃繘寮抽敃鍌涚厱妞ゎ厽鍨垫禍婵嬫煕濞嗗繒绠婚柡灞稿墲瀵板嫮鈧綆浜濋鍛攽閻愬弶鈻曞ù婊勭矊濞插灝鈹戦悩顔肩伇婵炲鐩、鏍川椤旂虎娴勯梻渚囧墮缁夌敻鍩涢幋锔解拻闁割偆鍠嶇欢閬嶆煟閹烘鐣洪柡灞剧⊕閹棃濮€閵忊€虫珰闂備浇妗ㄩ悞锕傚礉閺嵮屽殫闁告洦鍓涚弧鈧梺鍛婃礋濞佳囧箖閸儲鈷掗柛灞剧懅閸斿秹鎮楃粭娑樺悩濞戞瑦濯撮悷娆忓瀵潡姊洪棃娑氬闁瑰啿顦靛绋款吋婢跺鍘搁悗瑙勬惄閸犳帡宕戦幘缁樼厸闁逞屽墴閹崇偤濡烽敐鍕泿闂備礁鎼崯顐﹀磹閻熸壋鏋嶉柡鍥╁Х绾惧ジ鏌ｅΟ铏癸紞濠⒀屽墴閺屾洟宕惰椤忣厽顨ラ悙鏉戠瑨闁宠鍨垮畷鍫曞煛鐎ｎ喚宕猻tPendingTasks()` / `hasPendingTasks()` 濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌熼梻瀵割槮缁惧墽绮换娑㈠箣濞嗗繒鍔撮梺杞扮椤戝棝濡甸崟顖氱閻犺櫣鍎ら悗楣冩⒑閸涘﹦鎳冪紒缁橈耿瀵鎮㈤搹鍦紲闂侀潧绻掓慨鐢告倶閸垻纾藉ù锝呮惈鍟告繝鐢靛亹閸嬫捇姊虹紒妯绘儎闁稿锕ら悾鐑藉醇閺囩倣鈺冩喐婢跺鍙忛柛銉墯閳锋垹绱掔€ｎ亜鐨＄€规悶鍎甸弻锝夊冀瑜嬮崑銏⑩偓娈垮枦椤曆囧煡婢舵劕顫呴柣妯荤墦閸旀垿寮婚妸銉㈡斀闁糕剝锚濞咃絽顪冮妶搴″妞ゆ垵顦～?pending闂?
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴閵嗘帒顫濋敐鍛闁诲氦顫夊ú蹇涘磿閹惰棄鐒垫い鎺戯功缁夌敻鏌涢悩鎰佹疁鐎规洘娲熼獮瀣偐閸愬弶鐎鹃梻浣虹帛椤ㄥ懘鎮ч崱娆戠當闁圭儤姊诲Λ顖炴煙椤栧棗瀚禒鎾⒑閸濆嫮鐏遍柛鐘查叄閸┿垽骞樼拠鎻掔€銈嗘⒒閺咁偅绂嶉幇鐗堚拻濞达絼璀﹂悞鐐亜閹存繃顥㈡鐐村灴瀹曞爼鈥栭浣烘创鐎规洘锕㈡俊鎼佸閳藉棙缍屽┑鐘愁問閸犳鏁嬮悷婊勬緲椤﹀崬危閹邦剦娼ㄩ柍褜鍓欓～蹇曠磼濡顎撻梺缁樺灦閿氭繛鍫濊嫰椤啴濡堕崘銊ヮ瀳闂備礁搴滅徊浠嬫偩閻戣棄绠ｉ柣鎰暩閻﹀牓姊虹粙鎸庢拱缂侇喖绉撮埢鎾诲箚瑜夐弨鑺ャ亜閺傛娼熷ù鐘崇矒閺屾稓鈧綆鍋呯亸顓㈡煃鐟欏嫬鐏撮柛鈺佸瀹曟﹢鍩℃担鎻掍壕闁归偊鍏橀弨浠嬫煥濞戞ê顏╅柛妯虹摠閵囧嫰濮€閿涘嫭鍣伴悗瑙勬礃椤ㄥ﹤鐣峰Δ鍛闁兼祴鏅滆闂傚倸鍊搁崐鎼佸磹瀹勬噴褰掑炊椤掑鏅悷婊冪Ч濠€渚€姊虹紒妯虹伇婵☆偄瀚板鍛婃媴缁洘鏂€闂佺粯锚閻ゅ洦绔熷Ο鑲╂／闁硅鍔﹂崵娆撴煃鐟欏嫬鐏撮柟顔规櫊楠炴捇骞掗幘鎼晙濠碉紕鍋戦崐鎰板疾濠婂牊鍋傞柨鐔哄Т閽冪喖鏌ㄥ┑鍡╂Ц缂佺姵绋掗妵鍕冀閵娿倗绻佹繛瀵稿Л閺呯娀骞冨Δ鍐╁枂闁告洦鍓欓惌顔剧磽娴ｈ棄鐓愮€光偓缁嬫鍤曢悹鍥ㄧゴ濡插牓鏌曡箛鏇炐ラ柛鏃€鎸冲娲川婵犲倸袝婵炲瓨绮嶉悧鏇炲祫闂佸壊鍋侀崕鏌ュ煕閹寸姷纾藉ù锝咁潠椤忓懏鍙忛柛銉墯閻撱儵鏌￠崶銉ュ闂侇収鍨抽埀顒侇問閸犳牠鈥﹂悜钘夋瀬闁圭増婢樺婵嬫煕鐏炲墽鐭婇柡瀣閺岀喐顦版惔鈾€鏋呴梺鐟扮－婵炩偓妞ゃ垺顨婂畷鎺懳熸潪鎵暰闂傚倸鍊峰ù鍥敋瑜忛埀顒佺▓閺呯娀銆佸▎鎾冲唨妞ゆ挾鍋熼悰銉╂⒑閸濆嫯鐧佺€光偓閳ь剟宕濋悜鑺モ拺闁绘劘妫勯崝婊堟煕閹剧澹樻い顓炴喘瀵粙濡歌椤旀洟鎮楅悷鏉款棌闁哥姵娲熼獮澶嬨偅閸愨晝鍘告繛杈剧秮濞煎鐓鍕厸閻忕偛澧介埥澶愭煃鐟欏嫬鐏寸€规洖宕灃濠电姳鐒﹂崑鍛攽閿涘嫬浜奸柛濞垮€濆畷婊冣枎瀵邦偅绋戦埞鎴犫偓锝庝海閹?running闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈤崨濠勭Ф婵°倧绲介崯顖烆敁瀹ュ鈷戠紒瀣儥閸庡繑銇勯幋婵囧殗闁糕晝鍋ら獮瀣晜閽樺姹楅梻鍌氼煬閸嬫帡宕ｉ埀顒勬煙?pending闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈢亸浣圭€婚梻鍕喘椤㈡俺顦查悡銈嗐亜閹惧崬鐏€规挷绶氶悡顐﹀炊閵婏妇锛涘┑鐐茬焾娴滎亪骞冪憴鍕闁兼亽鍎宠ぐ褔姊哄ú璇蹭簻缂佺粯鍔欓崺鐐哄箣閿旇棄鈧兘鎮楀☉娅亪妫勫澶嬧拺缂侇垱娲樺▍鍛存煕閻斿憡缍戦柣锝囧厴楠炲鏁冮埀顒傜不婵犳碍鐓曢柕濠忓閳藉霉濠婂牏鐣洪柟顔煎槻閳诲氦绠涢幙鍐х棯缂傚倷璁查崑鎾绘煕閹板吀绨界痪鎯у悑閵囧嫰寮崶褌姹楅梺缁樺笒閹诧繝濡甸崟顖氼潊闁宠棄鎳撻埀顒冩硶閳ь剝顫夊ú姗€銆冩繝鍥х畺闁斥晛鍟崕鐔兼煥濠靛棙宸濈€规挷绶氬濠氬磼濞嗘帒鍘＄紒缁㈠幖閻栫厧鐣峰鍐ｆ斀閻庯綆鈧叏绠撻弻锝夊箛椤掑娈堕梺缁樼箓閻栧ジ寮婚敓鐘茬倞闁靛鍎虫禒楣冩⒑閹惰姤鏁遍柛銊ョ仢椤繐煤椤忓秵鏅濋梺闈涚墕閹冲秶鍒掗崼鏇熲拺闁稿繐鍚嬮妵鐔兼煕閵娾晙鎲剧€殿喗鐓″畷婊勬媴閹绘帊澹曢梺姹囧灮閺佹悂濡存繝鍥ㄧ厱閻庯綆鍋呯亸鐢告煙閸欏灏︾€规洜鍠栭、妤呭磼閵堝柊鐐烘⒒閸屾瑦绁扮€规洜鏁诲畷鎶芥晜閸撗傜瑝闂佺鎻懙褰掑焵椤掑﹦鐣电€规洖鐖奸、妤佸緞鐎ｎ偅鐝曢梻鍌欑婢瑰﹪宕戦崨顖涘床闁告洦鍨奸弫鍥煟閹惧啿鐦ㄦ繛鎾愁煼閺屾洟宕煎┑鍥舵！闂佹娊鏀辩敮锟犲蓟濞戞埃鍋撻敐搴′簼鐎规洖鐬奸埀顒冾潐濞叉ɑ绻涙繝鍥╁祦闁哄秲鍔嶆刊鎾煕韫囨搩妲归悗姘虫閳规垿鎮欓懜闈涙锭缂備浇寮撶划娆撶嵁婢舵劖鏅搁柣妯垮皺閻ｉ箖姊洪崜鎻掍簴闁稿孩鐓￠幃锟犳偄闂€鎰畾闂侀潧鐗嗙€氼垶宕楀畝鍕厽妞ゆ挾鍎愰崕鎴犵磼鏉堛劌娴€规洘甯掗～婵喰掑▍璇叉处閻撴稑霉閿濆洦鍤€濠殿喖鐗忛埀顒€鐏氬妯尖偓姘煎幘閹广垹鈹戠€ｎ亞顦板銈嗘尵閸犳劙鎯侀悙鐑樷拻闁稿本鑹鹃埀顒勵棑濞嗐垹顫濋澶屽姺閻熸粍妫冮獮鍐樄鐎规洖宕湁闁哄瀵ф径鍕倵闂堟稏鍋㈢€殿喖鐖奸獮瀣偑閸涱垰鎯?done闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛濠傛健閺屻劑寮崼鐔告闂佺顑嗛幐鍓у垝椤撶偐妲堟俊顖濐嚙濞呇囨⒑濞茶骞楅柣鐔叉櫊瀵鎮㈢悰鈥充壕闁汇垺顔栭悞鎯归悩娆忔处閳锋帡鏌涢弴銊ヤ簻妞ゅ浚鍙冮弻鈥崇暆鐎ｎ剛鐦堥悗瑙勬礃閿曘垺淇婇幖浣肝ㄩ柕蹇曞С婢规洟鎮峰鍛暭閻㈩垼浜炵槐鐐哄冀椤撶喓鍘搁梺鎼炲劗閺呮瑧妲愬畷鍥ㄥ枑闁绘鐗嗙粭鎺旂磼閳ь剟宕掗悙瀵稿幈闂婎偄娲﹂懝鐐閸︻厾纾奸悗锝庝簼瀹告繄绱掓潏銊﹀鞍闁瑰嘲鎳橀獮鎾诲箳瀹ュ拋妫滈梻鍌氬€风粈渚€骞夐垾瓒佹椽鏁冮崒姘€梻渚囧墮缁夌敻宕戦崒鐐寸叆婵犻潧妫Σ娲煕濞嗘劖宕岄柡灞剧洴婵＄兘鏁愰崨顓х€寸紓鍌欒閸嬫捇鏌涢幇闈涙灍闁绘挻娲熼弻鏇㈠醇濠靛棌鍋撻崨濠勵洸闁告挆鍛紳?
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻鐔兼⒒鐎靛壊妲紒鐐劤缂嶅﹪寮婚悢鍏尖拻閻庨潧澹婂Σ顕€姊哄Ч鍥р偓銈夊窗閺嶎厽绠掗梻浣侯焾缁绘劙宕ョ€ｎ剛绀婇柟瀵稿Х绾惧ジ鏌熼柇锕€寮炬繛鍫熺矋椤ㄣ儵鎮欑€电鈪归柤鎸庡姈閵囧嫰骞掗崱妞惧濠电姷顣介埀顒傚仺閸嬨垽鏌＄仦鍓ф创濠碘剝鎮傛俊鐑藉Ψ閵忕姳澹曢悷婊呭鐢帗顢婇梻浣告啞濞诧箓宕板鑸靛仾闁逞屽墴濮婃椽宕烽鐐板闂佸憡鎸荤喊宥団偓鐢靛帶閻ｆ繈宕熼鑺ュ闂備礁鎲＄缓鍧楀磿闁秴鍑犻幖娣妽閻撴瑩鏌ц箛锝呬壕闁兼媽娉曢埀顒侇問閸犳盯顢氳閸┿儲寰勬繛銏㈠枛閺屻劎鈧綆鍏橀崑鎾寸節濮橆収妫呭銈嗗姂閸ㄨ櫣绮婇銈囩＜闁肩⒈鍓欐禍鐐亜閵婏妇鎳囨慨濠傤煼瀹曟帒鈻庨幋婵嗩瀴闂備浇顕栭崰鏍偉閻撳海鏆︽繝闈涙閺嗗棝鏌涢弴銊モ偓鐘侯樄闁哄瞼鍠栧鑽も偓闈涘濡差喚绱撴担鍝勑ｉ柣妤冨█楠炲啫鐣￠幍铏€婚棅顐㈡处閹尖晜绂掗悙顒傜瘈婵炲牆鐏濋悘锟犳煙閸涘﹤鈻曟鐐插暙閻ｏ繝骞嶉搹顐も偓濠氭椤愩垺澶勯柟灏栨櫆鐎靛ジ鍩€椤掑嫭鈷掑ù锝呮啞閸熺偤鏌涢埡渚婂姛闁瑰箍鍨归埞鎴﹀幢閳哄倻绋侀梻浣虹帛閸ㄥ爼鏁嬪銈嗘礉妞村摜鎹㈠☉銏犲耿婵☆垵娅ｆ禒濂告煛瀹ュ繒绡€婵﹦绮幏鍛村川婵犲倹娈橀梻浣筋潐閹倻绮婚弽褏鏆﹀ù鍏兼綑閸愨偓濡炪倖鎸鹃崰搴㈢閹烘埈娓婚柕鍫濇椤ュ牓鏌℃笟鍥ф珝鐎规洘鍨块獮妯肩磼濡粯鐝抽梺纭呭亹鐞涖儵宕滃┑瀣€堕柛顐犲灮绾捐棄霉閿濆懏鎯堥弽锛勭磽娴ｅ壊鍎愰悽顖楀墲娣囧﹪鎮界粙璺槹濡炪倖鏌ㄦ晶浠嬪级閹间焦鈷戦柛锔诲幖娴滈箖鏌熼姘冲閾荤偤鏌曢崼婵愭Ч闁绘挶鍨介弻娑㈠箛閳轰礁顬堥梺鑲╊焾缂嶅﹪寮诲鍥ㄥ枂闁告洦鍋嗘导宀勬⒑鐠団€虫灍闁荤喆鍎甸、娆掔疀濞戣鲸鏅╅梺绋跨箳閳峰牓宕埀顒€鈹戦悩鍨毄濠殿喚鏁婚、娆撳冀椤撶偤妫烽梺鎸庣箓濞层劍绋夊澶嬬厸鐎广儱楠搁獮鎴︽煃瑜滈崗娑氱矆娓氣偓閿濈偛鈹戠€ｎ亞鐤€婵炶揪绲芥晶锝夘敂閸啿鎷绘繛鎾村焹閸嬫捇鏌嶈閸撴氨绮欓幘璇茬厴闁圭儤顨嗛悡鏇㈡煛閸愶絽浜鹃梺鎼炲妼濞硷繝鎮伴鈧畷姗€顢欑喊杈ㄧ秱闂備線娼ч悧鍡涘箠閹板叓鍥槾缂佽鲸甯￠幃娆擃敆閳ь剟顢撳鍐炬富閻庢稒蓱閸婃劙鎸婇悢鍏肩厱妞ゆ劗濮撮崝婊堟煃闁垮绗掗柕鍡樺笒椤繈鏁愰崨顒€顥氬┑鐘垫暩閸嬫﹢宕犻悩璇插窛妞ゆ梻鍘х花銉╂⒒娴ｈ櫣銆婇柛鎾寸箘缁瑩骞嬮悩鐢电劶闂佸壊鍋嗛崰鎾跺姬閳ь剟姊婚崒姘卞缂佸鎸婚弲璺衡槈濞嗗秳绨婚梺褰掑亰娴滅偤鎯屽▎鎾寸厸鐎光偓閳ь剟宕伴幘鑸殿潟闁圭儤鍤﹂悢鍏兼優閻犲洠鍓濋弫銈夋⒑閼姐倕鏋戠紒顔煎閺呰泛螖閸愨晜娈板┑掳鍊曢幊搴ｅ婵犳碍鐓欓柟娈垮枛椤ｅジ鏌ｉ幘璺盒ラ柣銉邯瀵爼宕归鍨厴闂備礁鎲″Λ蹇涘闯閿濆钃熼柡鍥风磿閻も偓婵犵數濮撮崯顐⑩枍濮樿埖鈷戠紒瀣儥閸庢劙鏌熼幖浣虹暫妤犵偛顦甸獮姗€顢欓懖鈺婃Ч婵＄偑鍊栫敮鎺楀磻閸℃あ锝夋偡閹佃櫕鏂€闂佺粯鍔樼亸娆撳箺閻樼數纾兼い鏃囧亹缁犱即鏌嶇紒妯荤叆闁宠棄顦垫慨鈧柍銉ュ帠閹綁姊绘担铏瑰笡闁搞劌鍚嬮幈銊╁Χ婢跺﹦锛欐繝鐢靛У绾板秹鎮￠姀鈥茬箚妞ゆ牗绮岄惃鎴犵磼鏉堛劌鍝洪柡灞界Ч瀹曨偊宕熼鈧▍銈夋⒑缂佹ɑ灏柛搴ゅ皺閹广垹鈹戠€ｎ偒妫冨┑鐐村灦閻燁垰螞閻愮儤鈷戦梺顐ゅ仜閼活垱鏅堕鈧弻鐔烘嫚瑜忕壕璺ㄧ磼椤旂⒈鍎忔い鎾冲悑瀵板嫭绻濋崟鍨ら梺鑽ゅ枑缁矂藝闂堟稓鏆﹂柟杈剧畱缁犲鎮归崶顏勭毢妞は佸嫮绡€闁靛骏绲剧涵楣冩煥閺囶亞绋荤紒鏃傚枛瀵挳濮€閳锯偓閹风粯绻涙潏鍓у埌闁硅绻濆畷顖炴倷閻戞鍘遍梺鍝勫暞閹瑰洤顬婇鍓х＜闁稿本姘ㄦ晥闂佽鍠楅悷锔剧箔閻旂⒈鏁嶆繛鎴炵懄閻濓箓姊婚崒娆戭槮婵犫偓闁秴纾块柕鍫濐槶閳ь剙鍟村畷鍗炩槈濞嗗繋绨甸梻浣虹帛閺屻劑宕ョ€ｎ喗鍋傞柣鏂垮悑閻撴瑩姊洪銊х暠濠⒀冾煼閺屾盯濡堕崶褎鐏堥梺鍝勮嫰缁夊綊骞愭繝鍐ㄧ窞婵☆垱浜堕敃鍌涒拺閻庡湱濯鎰版煕閵娿儳浠㈤柣锝囧厴楠炴帡骞嬮弮鈧～宥呪攽閻愬弶顥為拑杈ㄣ亜閵夈儳澧︽慨濠勭帛閹峰懏绗熼娑欐殲婵犵數鍋涢幊蹇撁洪悢鐓庣畺闁绘垼妫勯悡娑㈡煕濞戝崬鐏犵紒渚婄畵閺岋絾鎯旈婊呅ｉ梺鍝ュУ椤ㄥ﹤顕ｉ幓鎺嗘斀閻庯綆鍋嗛崢鎾绘偡濠婂嫮鐭掔€规洘绮岄埞鎴﹀醇閻斿嘲绨ユ繝娈垮枟椤牓宕洪弽顓熷亗闁绘梻鍘х粻褰掑级閸繂鈷旂痪鎯ф健閺岋綁鏁愭惔鈥崇睄闂佸搫鏈粙鎺楀箚閺冨牆围闁告洦鍋呴崕鎾绘⒒娴ｇ儤鍤€缂佺姴绉瑰畷瑙勭鐎ｎ剙绁﹂梺鍦劋閸わ箓寮崼婵堫槰闂侀潧臎娴ｉ晲鍠婇梻鍌氬€搁崐椋庢濮橆剦鐒界憸蹇涘箲閵忋倕閱囬柕澶堝劤閿涙瑩姊洪崫鍕枆闁告ü绮欏畷鎴﹀磼閻愬鍘搁梺鎼炲劗閺呮盯寮搁弮鍌滅＜闁绘宕甸悾娲煛鐏炲墽娲撮柛鈺佸瀹曟鎮埀顒佺濠靛绠為柕濞炬櫅閻愬﹥銇勯幒宥堝厡闁告ü绮欏Λ鍛搭敃閵忊剝鎮欏銈嗗灥閹虫ê鐣峰┑瀣ч柛銉㈡櫇閿涙粓鏌℃径濠勫闁告柨鑻湁妞ゆ棃鏁崑鎾斥枔閸喗鐝梺闈╃秶缁蹭粙鎮鹃悜钘夌闁规惌鍘介崓闈涱渻閵堝棗鍧婇柛瀣尵缁辨帡鎮崨顖溕戠紓浣虹帛缁诲倿鍩㈤幘璇插瀭妞ゆ梻鏅禍顏堟⒒娴ｇ懓顕滄繛鎻掔箻瀹曟劕鈹戠€ｎ亞浼嬮梺鎸庢礀閸婂綊鎮￠妷鈺傚€甸柨婵嗗€瑰▍鍥╃磼閻樺啿鍝烘慨濠囩細閵囨劙骞掗幙鍕惞闂備胶绮敮顏嗙不閹炬剚鍤曢柟缁樺俯閻撱儵鏌涘☉鍗炵仯闁挎稒绻冪换娑欐綇閸撗呅氶梺绋款儏閸婂潡鐛€ｎ喗鍋愰柣銏㈡暩閸斿憡淇婇悙顏勨偓鏍蓟閵娾晜鍎嶉柣鎴ｆ缂佲晛霉閻樺樊鍎愰柣鎾卞劜缁绘盯骞嬮悘娲讳邯椤㈡棃鍩℃导杈╂嚀椤劑宕橀敃鈧禒顕€姊洪崫鍕拱缂佸甯為幑銏犫攽鐎ｎ亞顦ㄩ梺缁樺姦閸撴盯寮抽銏♀拻濞达絽鎲￠崯鐐烘偨椤栨侗娈橀柡渚囧櫍閺佹捇鎮╅鐟颁壕濞达絿纭跺Σ鍫熶繆椤栨粌浠﹂悽顖ょ節楠炲﹪寮介鐐靛幐婵炶揪绲藉﹢閬嶅焵椤掑嫮鐣洪柟顔筋殜瀹曞綊顢曢敐鍥у殥闂佽瀛╅崙褰掑储妤ｅ啫绠查柕蹇曞Л閺€浠嬫煕閵夛絽濡奸柛鏂挎嚇濮婃椽寮妷锔界彅闂佸摜濮靛畝绋跨暦閵夈儮鏋庨柟鍓цˉ閹峰姊虹粙鎸庢拱闁告垵缍婇幃锟狀敆閸曨剛鍘遍柣搴秵娴滄粓顢旈埡鍛亗闁靛牆顦伴悡銉╂煛閸モ晛浠滈柍褜鍓欓幗婊呭垝閸儱绀嬫い鎾跺枎閺嬫垿姊虹紒姗嗘當闁绘妫涚划顓㈠箳濡や礁浠┑鐐叉閻熝囨偩閻㈠憡鐓涢悘鐐垫櫕鍟稿銇卞倻绐旈柡灞剧洴楠炴鎹勯悜妯尖偓璇差渻?running闂?
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴閵嗘帒顫濋敐鍛婵°倗濮烽崑鐐烘偋閻樻眹鈧線寮撮姀鈩冩珕缂傚倷鐒﹁摫婵炲懎鐗撳缁樻媴閸涘﹤鏆堟繛鎾寸椤ㄥ﹤鐣疯ぐ鎺濇晜闁割偅绻勯敍娑㈡⒑閸︻厼浜鹃柛鎾磋壘椤洭鍩￠崨顔惧幗闂佸湱鍋撴繛濠囶敁濡や降浜滄い鎰靛亜娴滅偟绱掓潏銊﹀鞍闁瑰嘲鎳忕粋鎺斺偓锝庝簼閹虫瑩姊绘担鍛婅础闁硅櫕鎸哥叅妞ゆ挶鍨洪崑妯汇亜閺傛寧顫嶉柣鏃囨〃閻掑﹪鏌″畵顔煎€搁ˉ姘舵⒒娴ｅ湱婀介柛銊ヮ煼瀵偊骞栨担鑲濄儱鈹戦悩鍙夊闁绘挻鐩幃妤呮晲鎼粹€茬敖婵犫拃鍛毈闁哄备鈧磭鏆嗛悗锝庡墰琚﹂梻浣筋嚃閸犳帡寮查悩鑼殾闁挎繂妫楃欢鐐烘倵閿濆骸浜滈柍褜鍓涢崗姗€寮婚敐鍡樺劅闁靛繆鏅涢弲閬嶆⒑閸濄儱校闁绘濞€楠炲啴濡烽埡鍌氫簵闁硅壈鎻徊鎯р枔妤ｅ啯鈷戦柛婵嗗閸屻劑鏌涢妸銉хШ鐎规洩绻濋獮搴ㄦ嚍閵壯冨妇闂傚鍋勫ú銏ゅ磿瀹曞洤顥氶柛褎顨嗛悡鏇熶繆椤栨稑顕滄い銉ヮ樀閺屽秶鎲撮崟顐や紝闂佽鍠掗弲婵嬪箯閻樹警妲绘繛鏉戝悑閸旀牗绌辨繝鍥ч柛銉仢閿濆鐓欐い鏃傚帶閳ь剙娼￠悰顕€宕橀…鎴炲缓闂侀€炲苯澧寸€殿噮鍋婂畷姗€顢欓懖鈺嬬床婵犵數鍋為崹鍫曟嚌閸撗勬殰婵°倕鎳忛悡鐔兼煟閺傛寧鎲搁柣顓烆儑缁辨帡顢欓懞銉ョ闂佷紮绲介崲鑼剁亙闂侀€炲苯澧撮柨婵堝仜椤劑宕煎┑鍫濆Е婵＄偑鍊栧濠氬磻閹剧粯鐓熼柨婵嗘搐閸樺瓨顨ラ悙宸剶闁轰礁鍟撮崺鈧い鎺戝€绘稉宥夋煠婵劕鈧澹曢挊澹濆綊鏁愰崼顐㈡異闂佺粯甯婄划娆撳蓟濞戞﹩娼ㄩ柍褜鍓氱粋宥囨崉娓氼垱缍庡┑鐐叉▕娴滄繈宕戦敓鐘崇厵婵炲牆鐏濋弸鐔兼煙閼艰泛浜圭紒杈ㄦ尰閹峰懐绮电€ｎ亝顔勯梻浣告憸閸犳劙骞愰幎鑺ュ仒妞ゆ洍鍋撶€规洖宕埥澶婎潨閳ь剟宕崼鏇熲拺闂傚牊渚楀褍鈹戦垾铏枠鐎规洏鍨藉Λ鍐ㄢ槈鏉堛劌鐦滈梻渚€娼ч悧鍡椢涘▎鎾崇厱闁圭儤顨嗛悡鐔兼煃閳轰礁鏆為柣鎾卞劦閺屽秶绱掑Ο璇茬濡炪値鍘归崝鎴濈暦閵娾晩鏁傞柛鏇ㄤ簻椤ユ碍绻濋悽闈涗粶闁绘妫濋幃妯衡攽鐎ｎ亜鍤戦梺鍝勫暙閻楀﹪鎮￠弴銏＄厸闁告劧绲芥禍楣冩⒑閹肩偛濡兼繛纭风節閹即顢欓悾宀€鐦堥梺绋胯閸婃宕ｉ崱娑欌拺闁告挻褰冩禍婵堢磼鐠囨彃鈧潡骞冨Δ鍜佹晣闁绘垵妫欑€靛矂姊洪棃娑氬婵☆偅顨嗛幈銊槾缂佽鲸甯￠獮鎾诲箳閹惧厖娣柣搴㈩問閸犳绻涙繝鍥х畺闁靛浚婢€閻掑﹤霉閿濆牜娼愰柡澶嬫倐濮婄粯鎷呴搹鐟扮闂佸憡姊瑰ú鏍亽婵犵數濮村ú銈夊触瑜版帗鐓熼柡鍌濇硶濞堥亶鏌￠埀顒勬嚍閵夛絼绨婚梺鍝勬处椤ㄥ懏绂嶉崜褏纾藉ù锝呮惈鏍￠梺缁橆殘婵炩偓濠碘€崇摠閹峰懘宕滈崣澶婂厞闂備礁缍婇。锕傛倿閿旂晫鎼归梻鍌氬€搁崐椋庢濮樿泛鐒垫い鎺嶈兌閵嗘帡鏌嶇憴鍕诞闁哄本鐩顕€鍩€椤掑嫬鍨傞柛褎顨堝畵渚€鏌涢幇闈涙灈妞ゎ偄鎳橀弻鏇㈠醇濠靛浂妫炴繛瀛樼矋椤ㄥ﹪寮婚悢鍏煎殐闁冲搫濯绘径鎰厓鐟滄粓宕滃┑鍡忔瀺闁哄洢鍨瑰Ч鏌ョ叓閸ャ劎鈯曢柍閿嬪浮閺屾稓浠﹂崜褎鍣梺鍛婃煥缁夊綊寮婚悢纰辨晩闁兼祴鏅涢悡鐔兼倵鐟欏嫭绀冩繛鑼枛楠炲啫顭ㄩ崗鍓у枔閸犲﹥娼忛妸鈺傛殔闂傚倸鍊风粈浣圭珶婵犲洤纾婚柛娑卞姸濞差亜鍐€妞ゆ挾鍠庡▓婵嬫偡濠婂懎顣奸悽顖涱殜閹繝寮撮悢缈犵盎闂佽婢樻晶搴ｇ矙閼姐倗纾奸柍褜鍓熷畷姗€顢欓悾灞藉箺闂備浇顫夐崕鍐茬暦椤掑嫬绀夋繝濠傛噽绾捐偐绱撴担璇＄劷婵炴彃鐡ㄩ〃銉╂倷瀹割喖鍓堕梺璇″枟閻熲晛鐣疯ぐ鎺濇晝闁靛骏绱曡ぐ鎻掆攽閻樺灚鏆╁┑顔诲嵆瀹曡绺介棃鈺冪◤婵犮垼鍩栭崝鏍疾濠靛鐓忛煫鍥ь儏閳ь剚娲熼幏鎴︽偄閸濄儳顔曢梺鐟邦嚟閸庢劙鎮為幖浣圭厽闁哄倸鐏濋幃鎴︽煕閵堝棗娴柡灞炬礋瀹曠厧鈹戦崶鑸殿棧闂備焦鎮堕崝鎴炵閸洖钃熼柨婵嗘閸庣喖鏌曢崼婵嗩劉缂傚秴鐗嗛埞鎴︽倷閹绘帞楠囬梺鎸庢磸閸ㄥ綊鎮鹃悿顖樹汗闁圭儤鎸告禒娲⒒閸屾氨澧愰柡鍛箘缁瑨绠涢幘顖涙杸闂佺粯顭囩划顖氣槈瑜庨妵鍕箣閻愭彃顫掗悗娈垮櫘閸嬪﹤鐣峰鈧、娆撴偩鐏炶棄姹查梻鍌欑閹碱偊宕悩璇茬；闁瑰墽绮悡鏇㈠箹鏉堝墽纾块柨娑樼Ф缁辨帡顢欓懖鈺佲叺閻庤娲栭妶鍛婁繆閻戣姤鏅滈悷娆忓椤忕儤绻濋悽闈涗哗闁规椿浜炵槐鐐哄焵椤掍胶绠鹃柛婊冨暟閹ジ鏌℃笟鍥ф灈闁宠棄顦垫慨鈧柨娑樺鐢箖姊绘担瑙勫仩闁稿寒鍨跺畷婵囨償閳儼娅ｉ埀顒傛暩绾爼宕戦幘鏂ユ灁闁割煈鍠楅悘宥夋偡濠婂嫭绶查柛鐔告尦瀹曟椽濡烽敃鈧欢鐐烘煙闁箑澧绘繛鐓庯躬濮婃椽宕橀崣澶嬪創闂佺锕﹂幊鎾诲煝瀹ュ绫嶉柍褜鍓氱粚杈ㄧ節閸ャ劌鈧攱銇勮箛鎾愁仱闁稿鎹囧鍊燁檨婵炲吋鐗曢埞鎴︽偐瀹曞浂鏆￠梺绋款儍閸婃繈寮婚敓鐘茬闁靛ě鍐炬澑闂備胶顭堥敃锕傚极婵犳艾钃熼柨娑樺濞岊亪鎮归崶銊ョ祷濠殿喗娲熷铏圭磼濡闉嶉梺鑽ゅ暱閺呯姴顕ｆ繝姘労闁告劑鍔庣粣鐐寸節閻㈤潧孝閻庢凹浜幖瑙勬償閳藉棙瀵岄梺闈涚墕缁绘帡宕氶幍顔炬／缂備降鍨归獮妯讳繆閸欏濮嶆鐐村笒铻栭柍褜鍓熼幃鐐哄垂椤愮姳绨婚梺鐟版惈缁夊爼藝閿斿墽纾奸柛鎾茬娴犻亶鏌″畝鈧崰鏍蓟閸ヮ剚鏅濋柍褜鍓熼悰顔碱潨閳ь剟寮诲☉姗嗘建闁逞屽墯缁傚秶鎹勬笟顖涚稁婵°倧绠掗敓銉︾瑜版帗鐓欓柣鎴灻悘锝夋煕閻樿韬慨濠冩そ瀹曘劍绻濋崘銊╃€虹紓鍌欑椤戝棝宕归崸妤€绠栨繛宸憾閺佸洭鏌ｉ弮鍥仩闁伙箑鐗撳鍝勑ч崶褏浼堝┑鐐板尃閸″繐褰洪梻鍌氬€烽懗鍫曗€﹂崼銉晞闁糕剝绋戠粻鏉库攽閻樺疇澹樺鍛存⒑閸涘﹥澶勯柛鐘冲哺閹潡鍩€椤掑嫭鈷戦柛锔诲幖娴滅偓绻涢崗鑲╂噧闁宠绉烽ˇ鍙夈亜椤忓嫬鏆ｅ┑鈥崇埣瀹曟帒鈽夊▎鎴濈秲濠碉紕鍋戦崐鎴﹀磿閺屻儱绠查柛銉墮閺勩儵鏌嶈閸撴岸濡甸崟顖氱闁糕剝銇炴竟鏇犵磽閸屾瑨鍏屽┑顔碱嚟缁棃鎮烽幍顔芥闂佸搫娲㈤崹鍦兜閳ь剟姊虹紒妯哄缂佸鏁哥划娆撳箣閿曗偓閻撴繈骞栧ǎ顒€濡肩紒鐙呯秮閺岋絽螣婢剁鎯堥梺?stuck running 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴閵嗘帒顫濋敐鍛婵°倗濮烽崑鐐烘偋閻樻眹鈧線寮撮姀鈩冩珖闂侀€炲苯澧撮柟顔兼健椤㈡岸鍩€椤掑嫬钃熸繛鎴欏灩缁犳稒銇勯幘璺盒為柛瀣仱濮婃椽宕崟闈涘壉闂佺粯顨嗛〃鍫ュ箲閵忕姭妲堥柕蹇曞Х椤撳搫鈹戦悙鍙夘棞缂佺粯甯楃粋鎺撱偅閸愨斁鎷?running 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌ｉ幋锝呅撻柛銈呭閺屾盯顢曢敐鍡欘槬缂備胶濮锋繛鈧柡宀€鍠栭獮鎴﹀箛闂堟稒顔勯梺鐟板悑濞兼瑩鏁冮鍫濊摕闁挎稑瀚▽顏堟偣閸ャ劌绲诲瑙勫姍濮婃椽骞栭悙鎻掑Ф闂佸憡鎼粻鎾愁嚕椤愩埄鍚嬮柛鈩兠?pending闂?
     * 缂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾惧綊鏌熼梻瀵割槮缁炬儳缍婇弻锝夊箣閿濆憛鎾绘煕婵犲倹鍋ラ柡灞诲姂瀵挳鎮欏ù瀣壕闁割偅娲栭悞鍨亜閹哄棗浜鹃梺鍝ュ枎绾绢厾鍒掔拠娴嬫婵☆垶鏀遍～宥呪攽閳藉棗鐏ｉ柍宄扮墕鍗辨い鏍ㄧ〒缁♀偓闂佹眹鍨藉褎绂掗敃鍌涚厱闁靛鍔岄悡鎰磼瀹€鍕喚闁诡喗绮岃灒閻犲洦褰冩导搴ㄦ⒒娴ｇ瓔娼愰柛搴″悑閹便劑濡舵径瀣簵闂佸憡鍔﹂崰妤呮偂閺囩喓绡€闂傚牊绋掗ˉ婊勩亜韫囧﹥娅婇柡灞界Х椤т線鏌涢幘瀵哥疄闁挎繄鍋炲鍕箛椤掑倻鏉介梻渚€娼ч…鍫ュ磿濞差亝鍋傞柕澶嗘櫆閻撴洟鏌￠崶顭戞畷婵炲懎鍟扮槐鎺楀Ω閵夘喚鍚嬪┑顔硷攻濡炶棄鐣烽锕€绀嬫い鎰剁稻椤斿嫰姊绘担渚劸闁挎洩绠撳顐ｇ節濮樺崬绁﹂棅顐㈡处缁嬫帡寮查幖浣圭厽婵☆垵娅ｉ敍宥夋煕濮椻偓娴滆泛顫忓ú顏咁棃婵炴垶鑹鹃。鍝勨攽閻愯尙婀撮柛濠冩礋閹椽顢橀悢鍓佺畾闂佺粯鍔︽禍婊堝焵椤戞儳鈧繂鐣烽幋锕€宸濇繛锝庡厴閸嬫捇宕橀濂稿敹闂侀潧绻嗗Σ鍛焽閻斿吋鈷戦柛锔诲幖閸斿鏌涢妶蹇曠暤闁诡喓鍨介幃鈩冩償濠靛牏鍊為梻鍌欑閹测€趁洪弽顓熷€舵慨妯夸含缁€濠囨倶閻愭彃鈷旂紒鈾€鍋撻梻浣圭湽閸ㄨ棄顭囪閺嗏晜淇婇悙顏勨偓褏寰婇懖鈺佸灊婵炲棗绻掗弳锔界節婵犲倸鏆婇柡鈧禒瀣€甸柨婵嗙凹缁ㄤ粙鏌ｉ敐鍡樸仢婵﹨娅ｇ划娆撳箰鎼淬垺瀚崇紓鍌欑椤戝棝宕濆Δ鍛闁靛繈鍊曢獮銏＄箾閹寸偟鎳冮柍褜鍓涢弫濠氬蓟閿濆顫呴柣妯哄暱閺嗗牆鈹戦悙鍙夊櫤缂侇喖绉堕幑銏犫槈濡吋娈曟繝鐢靛С缁舵岸宕戝Δ鍛拺闁告繂瀚悞璺ㄧ磼閻樺啿鐏遍柣蹇斿笚缁绘盯骞橀弶鎴濇瘓闂佹悶鍔忔禍顒勬偡瑜嶉埞鎴︽偐閸偅姣勯梺绋款儐閻╊垶骞冭瀹曠喖顢涘鎲嬬吹闂傚倸鍊搁悧濠冪瑹濡も偓椤洭寮介妸褏顔曢悗鐟板閸犳洜鑺辩拠瑁佸綊鎳栭埡浣叉瀰闂佸搫鏈惄顖氼嚕閹绢喖惟闁靛鍎抽鎰繆閻愵亜鈧呭緤娴犲绠规い鎰惰吂閳ь剚妫冨畷姗€顢欓崲澹洨鍙撻柛銉ｅ妽鐏忓灚淇婄拠褏绉慨濠冩そ瀹曨偊濡烽妷鎰剁稻閵囧嫰濡搁妷顖濆惈婵犵鍓濋幐鍐茬暦濮椻偓椤㈡瑩宕叉径鍫濆闁哄苯绉靛顏堝箯鐏炶棄甯梻浣侯焾閿曪箓宕戝☉鈶┾偓鏃堝礃椤斿槈褔鏌涢埄鍐炬畼闁荤喐鍔楃槐鎾存媴閹绘帊澹曞┑鐐舵彧缁茶棄锕㈤柆宥冣偓鍛村蓟閵夛腹鎷哄銈嗗坊閸嬫挾绱掓径灞炬毈闁糕晜鐩獮瀣晜閽樺鍋撻悽鍛婄厱闁挎棁顕ч獮鏍偖閵娾晜鈷戦梺顐ゅ仜閼活垶宕㈤幘顔界厱閻庯綆鍋呭畷灞炬叏婵犲懏顏犵紒顔界懃閳诲酣骞嗚婢瑰姊绘担鍛婅础妞ゎ厼鐗婇弲鍫曟寠婢光晪缍侀獮鍥级鐠侯煈鍞烘繝寰锋澘鈧劙宕戦幘缁樼參闁告劦浜滈弸娑㈡煛鐏炵偓绀冪紒鏃傚枛椤㈡稑顫濋鐐搭仭婵犳鍣徊钘壝洪銏犺摕闁挎繂妫欓崕鐔兼煏韫囧﹥娅呴柡鍛翠憾濮婃椽宕妷銉愶絿鈧厜鍋撶紒瀣儥閸ゆ洘銇勯幇鍓佹偧缂佲檧鍋撴繝娈垮枟鑿ч柛搴櫍瀹曟垿骞樼€靛摜鎳濋梺閫炲苯澧い鏇秮椤㈡岸鍩€椤掆偓閻ｇ兘鎮℃惔妯绘杸闂佸壊鍋呯缓楣冨磻閹剧粯鍊烽柣鎴炃氶幏娲⒒閸屾氨澧涘〒姘殜閹偞銈ｉ崘鈺冨幈闁瑰吋鐣崐婵嬪传濞差亝鐓?done闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亜顒㈡い鎰Г閹便劌顫滈崱妤€鈷掗梺缁樺笧閺咁偊骞夌粙娆惧悑闁割偒婢€缂冩洖鈹戦悩鑼闁哄绨遍崑鎾诲即閵忕姷鏌堟繛瀵稿帶閻°劑宕曞Δ鍛厵闁告挆鍛闂佺顑戠徊鍊熺亙闂佹寧娲戠欢銈嗩殽韫囨稒鐓冪憸婊堝礂濮椻偓瀹曟垿骞樼紒妯锋嫼闂佸憡绻傜€氱兘宕曢幇顓濈箚妞ゆ劑鍨归弳锝団偓娈垮枛椤兘骞冮姀銏″仒闁炽儱鍘栨竟鏇㈡⒑濮瑰洤鐏い鏃€鐗犻幃鐐哄箚椤€崇秺閹晛鈻庤箛鎿冧淮婵炲瓨绮嶇划鎾诲蓟瀹ュ浼犻柛鏇ㄥ亝濞堫參姊洪崨濞氭垿骞愰幎钘夎摕闁绘梻鈷堥弫濠囨煠濞村娅囧Δ鏃堟煟鎼淬値娼愭繛鍙夌墵閹儵宕楅梻瀵哥畾闂佸綊妫跨粈浣告暜闁荤喐绮岀换妯讳繆閸洘鐓ラ悗锝傛櫇缁犳岸姊虹紒妯哄Е濞存粍绮撻崺鈧い鎴炲劤閳ь剚绻傞悾鐑藉箣閻愮數鎳濋梺閫炲苯澧柣锝囧厴楠炲洭寮堕崹顔肩ギ闂備胶鍋ㄩ崕杈╁椤撱垹纾归柟鐑橆殕閳锋帡鏌涚仦鍓ф噮闁告柨绉归弻鐔碱敊閼测晛鐓熼悗瑙勬礃濞茬喎顕ｉ崼鏇炵闁哄啠鍋撻柣蹇撳暙閳规垿鎮欓弶鎴犱户闂佹悶鍔岀紞濠傜暦濞嗘挻鍋愮紓浣诡焽閸樹粙姊洪崫鍕殜闁稿鎹囬弻鐔碱敊閸喚鍘紓浣稿€哥粔鎾€﹂妸鈺侀唶闁绘柨鎼獮宥夋⒒娴ｈ櫣甯涢柛銊﹀劶閹筋偊鏌ｈ箛鎾剁闁轰礁顭峰濠氭晲婢舵ɑ鏅ｉ梺缁樺灥濡宕滃畷鍥╃＝?done闂?
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
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            return false;
        }
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
            'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
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
                    'payload.plan_json.pages',
                    'payload.plan_json.shared_components',
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
        unset($scope['quality_gate_preflight_error']);

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
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        $sharedLayout = [];
        foreach (['header', 'footer'] as $region) {
            $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
            $layoutEntry = $this->buildSharedLayoutEntryFromPlanJsonComponent($region, $component);
            if ($layoutEntry !== []) {
                $sharedLayout[$region] = $layoutEntry;
            }
        }
        if ($sharedLayout === []) {
            return $scope;
        }

        $pageTypes = $this->resolvePlanJsonPageTypesForLayoutSync($planJson);
        if ($pageTypes === []) {
            return $scope;
        }

        $pageTypeLayouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        foreach ($pageTypes as $pageType) {
            $layout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
            foreach ($sharedLayout as $region => $entry) {
                $layout[$region] = $entry;
            }
            $pageTypeLayouts[$pageType] = $layout;
        }
        $scope['page_type_layouts'] = $pageTypeLayouts;

        return $scope;
    }

    /**
     * @param array<string, mixed> $component
     * @return array{component:string,config:array<string,mixed>}|array{}
     */
    private function buildSharedLayoutEntryFromPlanJsonComponent(string $region, array $component): array
    {
        if (!\in_array($region, ['header', 'footer'], true) || !$this->isBuiltSharedComponentArtifact($component)) {
            return [];
        }

        $componentCode = $this->resolveSharedComponentCodeForArtifactCheck($region, [], $component);
        if ($componentCode === '') {
            return [];
        }

        return [
            'component' => $componentCode,
            'config' => \is_array($component['default_config'] ?? null) ? $component['default_config'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $planJson
     * @return list<string>
     */
    private function resolvePlanJsonPageTypesForLayoutSync(array $planJson): array
    {
        $pageTypes = [];
        foreach (\is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [] as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType !== '') {
                $pageTypes[$pageType] = true;
            }
        }

        return \array_values(\array_keys($pageTypes));
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
        $materializedPagesByType = \is_array($scope['materialized_pages_by_type'] ?? null) ? $scope['materialized_pages_by_type'] : [];
        if ($pageTypeSet !== []) {
            $materializedPagesByType = \array_intersect_key($materializedPagesByType, $pageTypeSet);
        }

        $meta = \is_array($PlanJson['contract_meta'] ?? null) ? $PlanJson['contract_meta'] : [];

        return [
            'plan_json_contract_id' => \trim((string)($meta['contract_id'] ?? $meta['id'] ?? '')),
            'plan_json_signature' => \trim((string)($meta['signature'] ?? $meta['source_signature'] ?? '')),
            'plan_json' => [
                'pages' => \is_array($PlanJson['pages'] ?? null) ? $PlanJson['pages'] : [],
                'shared_components' => \is_array($PlanJson['shared_components'] ?? null) ? $PlanJson['shared_components'] : [],
            ],
            'workspace_track' => \trim((string)($PlanJson['workspace_track'] ?? $scope['workspace_track'] ?? '')),
            'page_types' => $pageTypes,
            'materialized_pages_by_type' => $materializedPagesByType,
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
        $summary = $this->summarizePlanJsonPageBlockStatuses($scope);
        $total = (int)$summary['total'];
        $done = (int)$summary['done'];
        $pending = (int)$summary['pending'];
        $running = (int)$summary['running'];
        $failed = (int)$summary['failed'];
        $cancelled = (int)$summary['cancelled'];
        $invalidStatus = (int)$summary['invalid_status'];
        $missingHtml = (int)($summary['missing_html'] ?? 0);
        $unfinished = $pending + $running + $failed + $cancelled + $invalidStatus;

        $reason = match (true) {
            $total <= 0 => 'missing_plan_json_blocks',
            $invalidStatus > 0 => 'invalid_plan_json_block_status',
            $failed > 0 => 'failed_plan_json_blocks',
            $cancelled > 0 => 'cancelled_plan_json_blocks',
            $unfinished > 0 => 'unfinished_plan_json_blocks',
            $missingHtml > 0 => 'missing_plan_json_block_html',
            default => '',
        };

        return [
            'passed' => $total > 0 && $unfinished === 0 && $missingHtml === 0 && $done === $total,
            'reason' => $reason,
            'total' => $total,
            'done' => $done,
            'pending' => $pending,
            'running' => $running,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'invalid_status' => $invalidStatus,
            'missing_html' => $missingHtml,
            'invalid_artifacts' => 0,
            'duplicate_artifacts' => 0,
            'page_block_progress' => $summary['page_block_progress'],
            'invalid_status_rows' => $summary['invalid_status_rows'],
            'missing_html_rows' => $summary['missing_html_rows'] ?? [],
            'unfinished' => $unfinished,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function summarizePlanJsonPageBlockStatuses(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $summary = [
            'total' => 0,
            'done' => 0,
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'invalid_status' => 0,
            'missing_html' => 0,
            'groups' => [],
            'page_block_progress' => [
                'expected_page_types' => [],
                'rows' => [],
                'shortfalls' => [],
            ],
            'invalid_status_rows' => [],
            'missing_html_rows' => [],
        ];

        foreach ($pages as $pageKey => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($pageKey) ? $pageKey : '')));
            if ($pageType === '') {
                continue;
            }
            $summary['page_block_progress']['expected_page_types'][] = $pageType;
            $group = [
                'page_type' => $pageType,
                'total' => 0,
                'done' => 0,
                'pending' => 0,
                'running' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'invalid_status' => 0,
                'missing_html' => 0,
                'tasks' => [],
            ];
            foreach ($page as $blockKey => $block) {
                if (!$this->isPlanJsonPageBlockNode($blockKey, $block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? $block['section_key'] ?? $blockKey));
                if ($blockKey === '') {
                    continue;
                }
                $status = $this->canonicalPlanJsonBlockStatus($block['status'] ?? null);
                $bucket = $status === null ? 'invalid_status' : match ($status) {
                    1 => 'done',
                    2 => 'running',
                    -1 => 'failed',
                    default => 'pending',
                };
                $task = [
                    'task_key' => 'page:' . $pageType . ':' . $blockKey,
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'status' => $bucket,
                    'label' => (string)($block['title'] ?? $block['label'] ?? $blockKey),
                    'updated_at' => (string)($block['updated_at'] ?? ''),
                    'finished_at' => (string)($block['finished_at'] ?? ''),
                    'message' => (string)($block['message'] ?? $block['error_message'] ?? $block['error'] ?? ''),
                ];
                $summary['total']++;
                $summary[$bucket]++;
                $group['total']++;
                $group[$bucket]++;
                if ($status === 1 && !$this->planJsonBlockHasGeneratedHtml($block)) {
                    $summary['missing_html']++;
                    $group['missing_html']++;
                    $summary['missing_html_rows'][] = [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'status' => $block['status'] ?? null,
                    ];
                }
                $group['tasks'][] = $task;
                $summary['page_block_progress']['rows'][] = [
                    'page_type' => $pageType,
                    'block_key' => $blockKey,
                    'status' => $bucket,
                    'message' => $task['message'],
                    'updated_at' => $task['updated_at'],
                ];
                if ($status === null) {
                    $summary['invalid_status_rows'][] = [
                        'page_type' => $pageType,
                        'block_key' => $blockKey,
                        'status' => $block['status'] ?? null,
                    ];
                }
            }
            if ($group['total'] > 0) {
                $summary['groups'][$pageType] = $group;
            }
        }

        $summary['page_block_progress']['expected_page_types'] = \array_values(\array_unique($summary['page_block_progress']['expected_page_types']));

        return $summary;
    }

    private function isPlanJsonPageBlockNode(int|string $key, mixed $value): bool
    {
        return \is_string($key)
            && \trim($key) !== ''
            && !isset(self::PLAN_JSON_PAGE_META_KEYS[\trim($key)])
            && \is_array($value);
    }

    private function canonicalPlanJsonBlockStatus(mixed $status): ?int
    {
        if (\is_int($status)) {
            return \in_array($status, [0, 1, 2, -1], true) ? $status : null;
        }
        if (\is_string($status) && \preg_match('/^-?\d+$/', \trim($status)) === 1) {
            $status = (int)\trim($status);

            return \in_array($status, [0, 1, 2, -1], true) ? $status : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function planJsonBlockHasGeneratedHtml(array $block): bool
    {
        foreach (['html', 'html_content', 'phtml'] as $key) {
            if (\trim((string)($block[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
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

        $expectedBlocks = $this->collectExpectedPlanJsonPageBlocks($scope);
        foreach ($expectedBlocks as $pageType => $blocks) {
            $rows[$pageType] ??= $this->emptyPageBlockProgressRow((string)$pageType);
            $rows[$pageType]['expected_blocks'] = \count($blocks);
            $rows[$pageType]['expected_block_codes'] = $this->extractExpectedPageBlockCodes($blocks, 'section_code');
            $rows[$pageType]['expected_block_ids'] = $this->extractExpectedPageBlockCodes($blocks, 'block_id');
            $rows[$pageType]['expected_block_keys'] = $this->extractExpectedPageBlockCodes($blocks, 'block_key');
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
            $rows[$pageType]['executable_blocks'] = (int)$rows[$pageType]['executable_blocks'] + 1;
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            if ($sectionCode !== '') {
                $executableByPage[$pageType][$sectionCode] = true;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_DONE && $this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                $rows[$pageType]['completed_blocks'] = (int)$rows[$pageType]['completed_blocks'] + 1;
                if ($sectionCode !== '') {
                    $completedByPage[$pageType][$sectionCode] = true;
                }
            }
        }

        foreach ($rows as $pageType => $row) {
            $rows[$pageType]['layout_blocks'] = (int)($rows[$pageType]['completed_blocks'] ?? 0);
            $rows[$pageType]['layout_block_codes'] = \array_values(\array_keys($completedByPage[$pageType] ?? []));
            $rows[$pageType]['missing_layout_block_codes'] = [];
            $rows[$pageType]['missing_executable_block_codes'] = $this->missingStringSet(
                $rows[$pageType]['expected_block_codes'],
                \array_values(\array_keys($executableByPage[$pageType] ?? []))
            );
            $rows[$pageType]['missing_completed_block_codes'] = $this->missingStringSet(
                $rows[$pageType]['expected_block_codes'],
                \array_values(\array_keys($completedByPage[$pageType] ?? []))
            );
            $rows[$pageType]['has_default_template_markers'] = false;
            $rows[$pageType]['persisted_layout_blocks'] = 0;
            $rows[$pageType]['persisted_layout_block_codes'] = [];
            $rows[$pageType]['missing_persisted_layout_block_codes'] = [];
            $rows[$pageType]['persisted_layout_has_default_template_markers'] = false;
        }

        $shortfalls = [];
        foreach ($rows as $pageType => $row) {
            $expected = (int)($row['expected_blocks'] ?? 0);
            $executable = (int)($row['executable_blocks'] ?? 0);
            $completed = (int)($row['completed_blocks'] ?? 0);
            $layout = (int)($row['layout_blocks'] ?? 0);
            $missingExecutableBlockCodes = \is_array($row['missing_executable_block_codes'] ?? null) ? $row['missing_executable_block_codes'] : [];
            $missingCompletedBlockCodes = \is_array($row['missing_completed_block_codes'] ?? null) ? $row['missing_completed_block_codes'] : [];
            $missingLayoutBlockCodes = \is_array($row['missing_layout_block_codes'] ?? null) ? $row['missing_layout_block_codes'] : [];
            $missingPersistedLayoutBlockCodes = \is_array($row['missing_persisted_layout_block_codes'] ?? null)
                ? $row['missing_persisted_layout_block_codes']
                : [];
            $hasDefaultTemplateMarkers = !empty($row['has_default_template_markers'])
                || !empty($row['persisted_layout_has_default_template_markers']);
            $complete = $expected > 0
                && $executable >= $expected
                && $completed >= $expected
                && $layout >= $expected
                && $missingExecutableBlockCodes === []
                && $missingCompletedBlockCodes === []
                && $missingLayoutBlockCodes === []
                && $missingPersistedLayoutBlockCodes === []
                && !$hasDefaultTemplateMarkers;
            $rows[$pageType]['complete'] = $complete;
            if (!$complete) {
                $shortfalls[] = [
                    'page_type' => (string)$pageType,
                    'expected_blocks' => $expected,
                    'executable_blocks' => $executable,
                    'completed_blocks' => $completed,
                    'layout_blocks' => $layout,
                    'persisted_layout_blocks' => (int)($row['persisted_layout_blocks'] ?? 0),
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
        ];
    }

    /**
     * @return array{page_type:string,expected_blocks:int,executable_blocks:int,completed_blocks:int,layout_blocks:int,complete:bool}
     */
    private function emptyPageBlockProgressRow(string $pageType): array
    {
        return [
            'page_type' => $pageType,
            'expected_blocks' => 0,
            'executable_blocks' => 0,
            'completed_blocks' => 0,
            'layout_blocks' => 0,
            'persisted_layout_blocks' => 0,
            'expected_block_codes' => [],
            'expected_block_ids' => [],
            'expected_block_keys' => [],
            'layout_block_codes' => [],
            'persisted_layout_block_codes' => [],
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
    private function collectExpectedPlanJsonPageBlocks(array $scope): array
    {
        $selected = $this->buildStringSet($this->normalizePlanJsonStringList($scope['page_types'] ?? []));
        $blocksByPage = [];
        foreach ($this->extractPlanJsonPages($scope) as $pageType => $page) {
            if ($pageType === '' || ($selected !== [] && !isset($selected[$pageType]))) {
                continue;
            }
            foreach ($this->extractPlanJsonPageBlocks($page) as $blockKey => $block) {
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
    private function collectExpectedStageOnePlanPageBlocks(array $scope): array
    {
        $selected = $this->buildStringSet($this->normalizePlanJsonStringList($scope['page_types'] ?? []));
        $blocksByPage = [];
        foreach ($this->extractPlanJsonPages($scope) as $pageType => $page) {
            if ($pageType === '' || ($selected !== [] && !isset($selected[$pageType]))) {
                continue;
            }
            foreach ($this->extractPlanJsonPageBlocks($page) as $blockKey => $block) {
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
     * @param array<string, mixed> $scope
     * @return array{
     *   expected_page_types:list<string>,
     *   build_page_types:list<string>,
     *   missing_build_page_types:list<string>,
     * }
     */
    public function inspectBuildCompletionPageTypeCoverage(array $scope): array
    {
        $expected = $this->normalizePlanJsonStringList($scope['page_types'] ?? []);
        if ($expected === []) {
            return [
                'expected_page_types' => [],
                'build_page_types' => [],
                'missing_build_page_types' => [],
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

        return [
            'expected_page_types' => $expected,
            'build_page_types' => \array_values(\array_keys($buildPageTypes)),
            'missing_build_page_types' => $this->missingStringSet($expected, \array_values(\array_keys($buildPageTypes))),
        ];
    }

    /**
     * @param array<string, mixed> $gate inspectBuildCompletionGate() 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴姘辩Т闂佹悶鍎洪崜锕傚极閸愵喗鐓ラ柡鍥殔娴滈箖姊哄Ч鍥р偓妤呭磻閹捐埖宕叉繝闈涙川缁♀偓闂佺鏈划宀勩€傚ú顏呪拺闁芥ê顦弳鐔兼煕閻樺磭澧电€殿喖顭峰鎾偄閾忚鍟庨梻浣稿閻撳牓宕伴弽銊х彾闁告洦鍋€閺€浠嬫煟閹邦剙绾ч柍缁樻礀闇夋繝濠傚閻帞鈧娲橀敃銏ゅ春閳ь剚銇勯幒鍡椾壕濡炪値浜滈崯瀛樹繆閸洖骞㈡俊顖滃劋濞堫偊姊绘担渚劸妞ゆ垵娲畷浼村冀椤撶偞鐎?
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

        return (string)__('濠电姷鏁告慨鐑藉极閸涘﹥鍙忛柣鎴ｆ閺嬩線鏌涘☉姗堟敾闁告瑥绻愰湁闁稿繐鍚嬬紞鎴︽煕閵娿儱鈧湱鎹㈠☉姗嗗晠妞ゆ棁宕甸惄搴ｇ磼閻愵剙鍔ゆ繛纭风節瀵濡堕崥銈呮贡閳ь剨缍嗛崑鍛存偟閺冨牊鈷戦柟鑲╁仜婵＄晫绱掔拠鑼ⅵ鐎殿喛顕ч埥澶愬閻樻鍟嬫繝寰锋澘鈧劙宕戦幘缁樺€垫慨妯煎帶婢у鈧鍠楅幃鍌氱暦閹烘鍊风紒顔款潐鐎氬ジ姊绘担鍛婅础妞ゎ厼鐗忛埀顒佺▓閺呯娀宕哄☉銏犵婵°倓鑳堕崢鎼佹⒑閸涘﹦鐭嗙紒鈧担鍦洸濞寸厧鐡ㄩ悡蹇涙煕閳╁喚娈曢柛銈呮喘閺岀喖顢欓悾宀€鐓夐梺鐟扮－閸嬨倖淇婇悜钘壩ㄩ柕鍫濇噹椤曆囨⒒閸屾艾鈧兘鎮為敃鍌氱畺闁割偅娲栫壕鎸庛亜閺嶎偄浠滅紒鈧径灞稿亾閸忓浜鹃梺閫炲苯澧い鏇樺劜缁绘繈宕熼鐙呯床婵犵妲呴崹鍫曞疾閼碱剝濮抽悹鍥ф▕濞撳鏌曢崼婵囶棞濠殿喖顦埞鎴︻敊閼恒儱鍞夐梺绯曟杹閸嬫挸顪冮妶鍡楃瑐闁煎啿澧庣划濠氭倷绾版ê浜?{tasks}', [
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
        foreach ($eligibleSections as $pageType => $sections) {
            if (!\is_array($sections)) {
                continue;
            }
            foreach ($sections as $sectionCode => $sectionMeta) {
                if (!\is_array($sectionMeta)) {
                    continue;
                }
                $section = $this->resolvePlanJsonBlockForTask(
                    $scope,
                    (string)$pageType,
                    (string)($sectionMeta['block_key'] ?? ''),
                    (string)$sectionCode
                );
                if ($sectionCode === '' || $section === []) {
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
     * 闂傚倸鍊搁崐鎼佸磹閹间礁纾归柟闂寸绾剧懓顪冪€ｎ亝鎹ｉ柣顓炴閵嗘帒顫濋敐鍛婵°倗濮烽崑鐐烘偋閻樻眹鈧線寮撮姀鈩冩珕缂傚倷鐒﹁摫婵炲懎鐗撳缁樻媴閸涘﹤鏆堟繛鎾寸椤ㄥ﹤鐣疯ぐ鎺濇晜闁割偅绻勯敍娑㈡⒑閸︻厼浜鹃柛鎾磋壘椤洭鍩￠崨顔惧幗闂佸湱鍋撴繛濠囶敁濡や降浜滄い鎰靛亜娴滅偟绱掓潏銊﹀鞍闁瑰嘲鎳忕粋鎺斺偓锝庝簼閹虫瑩姊绘担鍛婅础闁硅櫕鎸哥叅妞ゆ挶鍨洪崑妯汇亜閺傛寧顫嶉柣鏃傚帶缁€鍌炴煟閹捐櫕鎹ｇ憸鐗堟尦濮婄粯鎷呴崨濠傛殘闂佽崵鍠嗛崕浣冩闂佽姤锚閿涘鈽夐姀鈥充簻闂佺绻愰惃鐑藉箯濞差亝鈷戦柣鎾冲瘨濞肩喖鏌涙繝鍐╃鐎殿喗鎮傞獮瀣晜閻ｅ苯骞楅梻浣虹帛濮婄懓顭囧▎鎾崇闁靛牆顦伴悡鍐喐濠婂牆绀堟慨姗嗗墻濞尖晠鏌曟繛鐐珔缂佲偓閸屾稐绻嗘い鏍ㄧ懆椤掔喖鏌涢妶鍡樼闁哄本娲樼换娑㈡倷椤掍胶褰呯紓鍌欒兌婵參宕抽敐澶婅摕闁靛牆顦粻鎺楁煙閻戞ê鐏ュ┑顔哄灲濮婃椽宕ㄦ繝蹇氣偓鍨瑰鍡樼【妞ゎ偄绻愮叅妞ゅ繐鎳庢禒顓㈡⒑鐟欏嫷鍟忛柛鐘愁殜楠炲鎳栭埡鍐紳婵炶揪绲块悺鏃堝吹濞嗘挻鐓曢柣鏂捐濡孩淇婇鐘茬仼闁宠鍨块幃鈺冩嫚瑜嶆导鎰版煟鎼淬垹鎼搁柛鏂跨Ф缁顓奸崪浣哄弳闂佸壊鍋嗛崰鎾诲矗閸℃稒鈷戦柛婵嗗閺嗘瑦绻涢弶鎴濃偓鍨暦闂堟稈鏋庨煫鍥风悼閸炵敻鎮峰鍐劯鐎规洩绻濋獮搴ㄦ寠婢跺孩鎲伴柣搴＄畭閸庨亶鎮у鍐剧€堕柕濞炬櫆閳锋垿鏌涘☉姗堟敾閻忓繋鍗抽弻锝夊煛婵犲倻浠搁梺缁樹緱閸犳鎹㈠┑瀣倞鐟滃繘顢欓幒妤佺厽闁绘ê寮舵径鍕喐閺夊灝鏆ｉ柟顕€娼ц灒濞撴凹鍨辩€靛矂姊洪棃娑氬婵☆偅顨堢划顓㈠箳濡や礁鈧灚鎱ㄥΟ鐓庡付濠⒀冾嚟閳ь剝顫夊ú鏍礊婵犲倻鏆﹂柟鐑樺灍濡插牊绻涢崱妤冪濞寸厧瀛╃换婵堝枈濡椿娼戦梺鎼炲姀娴滎剟鍩€椤掑倻鎳楅柛娑卞枛閸樿淇婇妶蹇曞埌闁哥噥鍨跺畷鎴﹀焺閸愵亞顔曢梺绯曞墲椤洭骞婇崨瀛樼厽闁挎繂鎳愭禒娑欍亜閵婏絽鍔﹂柟顔界懅閳ь剛鏁搁…鍫ュ煕鐏炶娇鏃堟偐闂堟稐娌梺缁橆殘婵炩偓闁靛棔绀侀～婊堝焵椤掑嫬绠栨繛鍡樻尭缁€鍌滅磼鐎ｎ亞浠㈡い鏇嗗懐纾介柛灞捐壘閳ь剚鎮傚畷鎰槹鎼淬垹顎涢梺鍝勮閸庤京澹曟繝姘厵闁绘劦鍓欐晶顖炴煟閺傛寧顥㈤柟顔款潐濞碱亪骞忓畝濠傚Τ闂備胶顭堢€涒晠宕归崷顓燁潟闁圭儤顨嗛崑鎰版煠婵劕鈧寮抽锔解拺闁告繂瀚悘閬嶆煕閻樺磭澧甸柣娑卞櫍楠炲鏁冮埀顒傜不濞戞瑣浜滈柟鎹愭硾瀛濆┑鐐村毆閸ャ劉鎷绘繛鎾村焹閸嬫挻绻涢懝鏉垮惞鐎垫澘锕幊鏍煛娴ｅ摜浜版繝鐢靛仜濡瑩骞愰幖浣瑰珔闁绘柨鍚嬮悡鐔兼煛閸愩劌鈧敻骞忛敓鐘崇厸濞达綁娼婚崝鐔兼煟閵夘喕閭い銏★耿閹晛鐣烽崶褍蝎闂傚倷绀侀幉锟犲礄瑜版帒纾诲┑鐘叉搐缁犳牗淇婇妶鍌氫壕闂佸磭绮幑鍥х暦瑜版帩鏁婇柣锝呰嫰閽傚姊婚崒娆戭槮闁硅姤绮嶉幈銊╂偨閸涘﹤鍓銈嗙墬缁诲啴藟閵堝鈷掑ù锝囩摂濞兼劙鏌涙惔鈥虫倯闁逛究鍔戞俊鑸靛緞鐎ｎ亙绨甸梻浣虹帛濮婂宕㈣缁槒銇愰幒鎾跺幍缂佺偓婢樺畷顒勭嵁閺嶎厽鐓涢悗锝庡亞濞叉挳鏌″畝瀣К缂佺姵鐩鎾倷閹板墎绉柡灞炬礋瀹曟儼顦叉い蹇ｅ幘閳ь剚顔栭崰妤呭箰閹惰棄绠栭柕蹇嬪€栭崐缁樹繆椤栨粌鍔嬮柣婵呭嵆濮婅櫣鎷犻崣澶婃敪濡炪値鍋勯ˇ顖滃弲闂佹寧娲栭崐鍝ュ鐠囨祴鏀介柣妯诲絻閳ь剙顭峰鎶芥晝閸屾稓鍘介梺鍝勫暙閸婄敻骞忛敓鐘崇厸濞达絿顭堥弳鐐烘煏閸パ冾伃妤犵偛娲畷婊勬媴閻熼杩橀梻鍌欑窔閳ь剛鍋涢懟顖涙櫠婵犳碍鐓曢悗锝庝簼椤ョ姵淇婇崣澶婂妤犵偞顭囬幏鐘绘嚑椤掑﹦搴婂┑鐘愁問閸犳鏁冮埡鍛婵せ鍋撶€规洘鍨块獮妯兼嫚閼碱剦鍟囧┑鐐舵彧缁蹭粙骞楀鍫熷仒闁靛鍎弨鑺ャ亜閺冨倻鎽傞柣鎺斿亾缁绘稒寰勭€ｎ剚鍒涘┑顔硷工椤兘銆佸☉銏″€烽悗鐢殿焾瀵櫕绻濋悽闈涒枅婵炰匠鍏炬盯顢橀悙宥忕秮楠炴牗鎷呴崷顓炲箰闂佽鍑界徊娲疾濠靛瑤鍥晝閸屾稓鍘撻柣鐔哥懃鐎氼剟鎮橀幘顔界厵妞ゆ棁顫夊▍濠冾殽閻愬瓨宕屾鐐村笒閳规垿宕ㄩ娑崇础闂傚倸鍊峰ù鍥敋瑜忛埀顒佺▓閺呮繈宕版繝鍌ゅ悑闁搞儜鍕偓鎶芥倵楠炲灝鍔氭い锔诲灡椤㈠﹪姊绘担鍛婂暈婵炶绠撳畷瑙勫閺夋垹顦ㄩ梺閫炲苯澧存慨濠冩そ濡啫鈽夐崘韫矗婵犵數鍋犻婊呯不閹达附鍋╂繝闈涚墢閻瑩鎮归幁鎺戝婵?
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
                if (($scope['active_operations'][$operation]['queue_status'] ?? '') === self::TASK_STATUS_DONE) {
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
                if (($scope['active_operation']['queue_status'] ?? '') === self::TASK_STATUS_DONE) {
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
        $sharedTaskKeys = [];
        foreach (['header', 'footer'] as $sharedIndex => $region) {
            $taskKey = 'shared:' . $region;
            $sharedTaskKeys[] = $taskKey;
            $componentType = $region === 'header' ? 'shared header' : 'shared footer';
            $tasks[] = [
                'task_key' => $taskKey,
                'task_type' => 'shared_component',
                'scope_key' => 'plan_json.shared_components.' . $region,
                'group_key' => 'shared',
                'region' => $region,
                'component_code' => $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer',
                'section_code' => $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer',
                'label' => $region === 'header' ? 'Shared Header' : 'Shared Footer',
                'sort_order' => 10 + ($sharedIndex * 10),
                'dependencies' => [],
                'can_parallel' => true,
                'materialize_after_done' => true,
                'materialize_policy' => 'shared',
                'prompt_template_key' => 'plan_json_shared_component_execute',
                'progress_weight' => 1.0,
                'result_ref' => [
                    'region' => $region,
                ],
                'runtime_context' => \array_replace_recursive($runtimeRoot, [
                    'content_locale' => $contentLocale,
                    'language_contract' => $languageContract,
                    'context_refs' => [
                        'site_context_ref' => 'plan_json',
                        'shared_component_ref' => 'plan_json.shared_components.' . $region,
                    ],
                ]),
                'plan_context' => [
                    'source' => 'plan_json',
                    'site_context' => $sitePlanContext,
                    'shared_region' => $region,
                    'shared_prompt_context' => \is_array($runtimeRoot['shared_prompt_context'] ?? null) ? $runtimeRoot['shared_prompt_context'] : [],
                ],
                'task_script' => [
                    'component_type' => $componentType,
                    'story_goal' => 'Generate the visitor-facing ' . $componentType . ' from plan_json root navigation, footer, theme, and locale context.',
                    'field_content_requirements' => [],
                    'output_contract' => $this->planJsonExecutionOutputContract($componentType, []),
                    'acceptance' => $this->planJsonExecutionAcceptanceContract($componentType),
                    'content_keys' => [],
                    'policy_slices' => ['navigation.route_contract', 'layout.4_8_spacing', 'responsive.no_horizontal_scroll'],
                    'acceptance_rule_ids' => ['responsive.no_horizontal_scroll', 'a11y.alt_focus_semantic', 'color.readable_contrast'],
                ],
                'block_task' => [
                    'block_type' => $componentType,
                    'task_goal' => 'Generate the shared ' . $region . ' once and reuse it across every selected page.',
                    'content_plan' => [],
                    'style_plan' => $sitePlanContext,
                    'output_contract' => $this->planJsonExecutionOutputContract($componentType, []),
                    'acceptance' => $this->planJsonExecutionAcceptanceContract($componentType),
                ],
                'implementation_contract' => [
                    'source' => 'plan_json.shared_components.' . $region,
                    'region' => $region,
                    'data_contract' => [],
                    'output_contract' => $this->planJsonExecutionOutputContract($componentType, []),
                    'acceptance' => $this->planJsonExecutionAcceptanceContract($componentType),
                ],
            ];
        }
        $pageIndex = 0;

        foreach ($pages as $pageType => $page) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $blocks = $this->extractPlanJsonPageBlocks($page);
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
                    'scope_key' => 'plan_json.pages.' . $pageType . '.' . $blockKey,
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
                    'dependencies' => $sharedTaskKeys,
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
            if ((string)($task['task_type'] ?? '') === 'shared_component') {
                $region = \trim((string)($task['region'] ?? ''));
                $component = $this->resolvePlanJsonSharedComponentArtifact($scope, $region);
                $row = [
                    'task_key' => $taskKey,
                    'status' => $this->planBlockStatusToTaskStatus($this->normalizePlanBlockStatus($component['status'] ?? self::PLAN_BLOCK_STATUS_PENDING)),
                    'attempt_no' => (int)($component['attempt_no'] ?? 0),
                    'message' => (string)($component['message'] ?? $component['error'] ?? $component['error_message'] ?? ''),
                    'result_ref' => \is_array($component['result_ref'] ?? null) ? $component['result_ref'] : $this->planJsonTaskResultRefFromDefinition($task),
                    'updated_at' => (string)($component['updated_at'] ?? ''),
                    'started_at' => (string)($component['started_at'] ?? ''),
                    'finished_at' => (string)($component['finished_at'] ?? ''),
                ];
                $sanitized[$taskKey] = $this->sanitizePlanJsonTaskStateRow($row, $taskKey);
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
        if ($definition !== null && (string)($definition['task_type'] ?? '') === 'shared_component') {
            return $this->setSharedComponentTaskState($scope, $definition, $next, $resultRef);
        }
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
            foreach ($this->extractPlanJsonPageBlocks($page) as $candidateKey => $candidateBlock) {
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
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $row
     * @param array<string, mixed> $resultRef
     * @return array<string, mixed>
     */
    private function setSharedComponentTaskState(array $scope, array $definition, array $row, array $resultRef): array
    {
        $region = \trim((string)($definition['region'] ?? ''));
        if (!\in_array($region, ['header', 'footer'], true)) {
            return $this->attachPlanJsonExecutionSummary($scope);
        }

        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];
        $component = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
        $component['region'] = $region;
        $component['code'] = $this->resolveSharedComponentCodeForArtifactCheck($region, $definition, $component);
        if (\trim((string)$component['code']) === '') {
            $component['code'] = $region === 'header' ? 'header/ai-site-header' : 'footer/ai-site-footer';
        }

        $taskStatus = $this->normalizeTaskStatus((string)($row['status'] ?? self::TASK_STATUS_PENDING));
        $component['status'] = $this->taskStatusToPlanBlockStatus($taskStatus);
        $component['attempt_no'] = (int)($row['attempt_no'] ?? 0);
        $component['message'] = (string)($row['message'] ?? '');
        $component['result_ref'] = \is_array($row['result_ref'] ?? null) ? $row['result_ref'] : $this->planJsonTaskResultRefFromDefinition($definition);
        $component['updated_at'] = (string)($row['updated_at'] ?? \date('Y-m-d H:i:s'));
        $component['started_at'] = (string)($row['started_at'] ?? '');
        $component['finished_at'] = (string)($row['finished_at'] ?? '');

        if ($taskStatus === self::TASK_STATUS_DONE) {
            $component = $this->syncPlanJsonSharedComponentGeneratedPayload($component, $resultRef, $definition);
            unset($component['error'], $component['error_message']);
        } elseif ($taskStatus === self::TASK_STATUS_FAILED) {
            $component['error'] = $component['message'] !== '' ? $component['message'] : 'AI generation failed.';
        } else {
            unset($component['error'], $component['error_message']);
        }

        $sharedComponents[$region] = $component;
        $planJson['shared_components'] = $sharedComponents;
        $scope['plan_json'] = $this->planJsonStateService->normalizePlanJson($planJson);
        if (\is_array($scope['shared_components'] ?? null)) {
            unset($scope['shared_components'][$region]);
            if ($scope['shared_components'] === []) {
                unset($scope['shared_components']);
            }
        }

        return $this->attachPlanJsonExecutionSummary($scope);
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $resultRef
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function syncPlanJsonSharedComponentGeneratedPayload(array $component, array $resultRef, array $task): array
    {
        $generated = \is_array($resultRef['component'] ?? null)
            ? $resultRef['component']
            : (\is_array($resultRef['shared_component'] ?? null) ? $resultRef['shared_component'] : []);
        if ($generated === []) {
            $generated = \is_array($resultRef['section_component'] ?? null) ? $resultRef['section_component'] : [];
        }

        foreach ([
            'code' => [$generated['code'] ?? null, $generated['component_code'] ?? null, $task['component_code'] ?? null, $task['section_code'] ?? null],
            'name' => [$generated['name'] ?? null],
            'region' => [$generated['region'] ?? null, $task['region'] ?? null],
            'html' => [$generated['html'] ?? null, $generated['html_content'] ?? null],
            'phtml' => [$generated['phtml'] ?? null, $generated['template_phtml'] ?? null],
        ] as $targetKey => $candidates) {
            $value = $this->firstNonEmptyPlanJsonText($candidates);
            if ($value !== '') {
                $component[$targetKey] = $value;
            }
        }

        foreach ([
            'default_config' => [$generated['default_config'] ?? null, $generated['config'] ?? null],
            'field_schema' => [$generated['field_schema'] ?? null],
            'ai_data' => [$generated['ai_data'] ?? null],
        ] as $targetKey => $candidates) {
            foreach ($candidates as $candidate) {
                if (\is_array($candidate) && $candidate !== []) {
                    $component[$targetKey] = $candidate;
                    break;
                }
            }
        }

        return $component;
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
    private function extractPlanJsonPageBlocks(array $page): array
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
        foreach ($this->extractPlanJsonPageBlocks($page) as $blockKey => $_) {
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
        foreach ($this->extractPlanJsonPageBlocks($page) as $candidateKey => $block) {
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
        foreach ($this->extractPlanJsonPageBlocks($page) as $block) {
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
        $fields = $this->firstNonEmptyPlanJsonArray([
            $sectionBlock['config'] ?? null,
            $component['default_config'] ?? null,
            $component['config'] ?? null,
        ]);
        $defaultConfig = $this->firstNonEmptyPlanJsonArray([
            $component['default_config'] ?? null,
            $sectionBlock['config'] ?? null,
        ]);
        $aiData = $this->firstNonEmptyPlanJsonArray([
            $component['ai_data'] ?? null,
        ]);
        $contentData = \array_replace($aiData, $defaultConfig, $fields);

        $html = $this->firstNonEmptyPlanJsonText([
            $sectionBlock['html'] ?? null,
            $sectionBlock['html_content'] ?? null,
            $component['html'] ?? null,
            $component['html_content'] ?? null,
        ]);
        if ($html !== '') {
            $block['html'] = $this->repairPlanJsonBlockHtmlFragment(
                $this->hydratePlanJsonBlockHtmlContent($html, $contentData)
            );
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
            'field_schema' => [$sectionBlock['field_schema'] ?? null],
        ] as $targetKey => $candidates) {
            foreach ($candidates as $candidate) {
                if (\is_array($candidate) && $candidate !== []) {
                    $block[$targetKey] = $candidate;
                    break;
                }
            }
        }
        foreach (['fields' => $fields, 'default_config' => $defaultConfig, 'ai_data' => $aiData] as $targetKey => $candidate) {
            if ($candidate !== []) {
                $block[$targetKey] = $candidate;
            }
        }

        return $block;
    }

    /**
     * @param list<mixed> $candidates
     * @return array<string, mixed>
     */
    private function firstNonEmptyPlanJsonArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function hydratePlanJsonBlockHtmlContent(string $html, array $contentData): string
    {
        $title = $this->firstNonEmptyPlanJsonText([
            $contentData['content.headline'] ?? null,
            $contentData['content.heading'] ?? null,
            $contentData['content.section_title'] ?? null,
            $contentData['section_title'] ?? null,
            $contentData['headline'] ?? null,
            $contentData['heading'] ?? null,
            $contentData['title'] ?? null,
        ]);
        $body = $this->firstNonEmptyPlanJsonText([
            $contentData['content.body'] ?? null,
            $contentData['description'] ?? null,
            $contentData['content.description'] ?? null,
            $contentData['section_intro'] ?? null,
            $contentData['body'] ?? null,
            $contentData['subtitle'] ?? null,
        ]);
        $ctaText = $this->firstNonEmptyPlanJsonText([
            $contentData['cta.text'] ?? null,
            $contentData['content.cta_text'] ?? null,
            $contentData['cta_text'] ?? null,
        ]);
        $ctaUrl = $this->firstNonEmptyPlanJsonText([
            $contentData['cta.url'] ?? null,
            $contentData['content.cta_url'] ?? null,
            $contentData['cta_url'] ?? null,
        ]);
        $imageUrl = $this->firstNonEmptyPlanJsonText([
            $contentData['image.url'] ?? null,
            $contentData['media.image_url'] ?? null,
            $contentData['visual.image_url'] ?? null,
        ]);
        $imageAlt = $this->firstNonEmptyPlanJsonText([
            $contentData['image.alt'] ?? null,
            $contentData['media.image_alt'] ?? null,
            $contentData['visual.image_alt'] ?? null,
            $title,
        ]);

        if ($title !== '') {
            $html = $this->fillEmptyHtmlTags($html, 'h[1-6]', $title);
        }
        if ($body !== '') {
            $html = $this->fillEmptyHtmlTags($html, 'p', $body);
        }
        if ($ctaText !== '') {
            $html = $this->fillEmptyHtmlTags($html, 'a', $ctaText);
        }
        if ($ctaUrl !== '') {
            $html = \preg_replace('/(<a\b[^>]*\bhref=)(["\'])\s*\2/iu', '$1$2' . \htmlspecialchars($ctaUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '$2', $html) ?? $html;
        }
        if ($imageUrl !== '') {
            $html = \preg_replace('/(<img\b[^>]*\bsrc=)(["\'])\s*\2/iu', '$1$2' . \htmlspecialchars($imageUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '$2', $html) ?? $html;
        }
        if ($imageAlt !== '') {
            $html = \preg_replace('/(<img\b[^>]*\balt=)(["\'])\s*\2/iu', '$1$2' . \htmlspecialchars($imageAlt, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '$2', $html) ?? $html;
        }

        return $html;
    }

    private function fillEmptyHtmlTags(string $html, string $tagPattern, string $text): string
    {
        $escaped = \htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \preg_replace('/(<(' . $tagPattern . ')\b[^>]*>)\s*(<\/\2>)/iu', '$1' . $escaped . '$3', $html) ?? $html;
    }

    private function repairPlanJsonBlockHtmlFragment(string $html): string
    {
        $html = \trim($html);
        if ($html === '' || !\class_exists(\DOMDocument::class)) {
            return $html;
        }

        $previous = \libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapperId = '__pb_plan_json_block_fragment__';
        $payload = '<!DOCTYPE html><html><body><div id="' . $wrapperId . '">' . $html . '</div></body></html>';
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $payload, \LIBXML_HTML_NODEFDTD | \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $wrapper = $dom->getElementById($wrapperId);
        if (!$wrapper instanceof \DOMElement) {
            return $html;
        }

        $fixed = '';
        foreach (\iterator_to_array($wrapper->childNodes) as $child) {
            $fixed .= (string)$dom->saveHTML($child);
        }
        $fixed = \trim(\html_entity_decode($fixed, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

        return $fixed !== '' ? $fixed : $html;
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
            $sharedComponent = $this->resolvePlanJsonSharedComponentArtifact($scope, $region);
            $componentCode = $this->resolveSharedComponentCodeForArtifactCheck($region, $task, $sharedComponent);
            if ($activeRegeneration) {
                if ($region === '' || !$this->isBuiltSharedComponentArtifact($sharedComponent)) {
                    return false;
                }

                $payload = \json_encode($sharedComponent, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (\is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload)) {
                    return false;
                }

                return true;
            }
            if ($region === '' || !$this->isBuiltSharedComponentArtifact($sharedComponent)) {
                return false;
            }

            $payload = \json_encode($sharedComponent, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (\is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload)) {
                return false;
            }

            return true;
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
        return false;
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
        if (\preg_match('/$isActive\s*=\s*$index\s*===\s*0\s*;/u', $payload) === 1) {
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
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolvePlanJsonSharedComponentArtifact(array $scope, string $region): array
    {
        $region = \trim($region);
        if (!\in_array($region, ['header', 'footer'], true)) {
            return [];
        }

        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sharedComponents = \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [];

        return \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
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
     * @param array<string, mixed> $summary summarize() 闂傚倸鍊搁崐鎼佸磹閹间礁纾瑰瀣捣閻棗銆掑锝呬壕濡ょ姷鍋為悧鐘汇€侀弴姘辩Т闂佹悶鍎洪崜锕傚极閸愵喗鐓ラ柡鍥殔娴滈箖姊哄Ч鍥р偓妤呭磻閹捐埖宕叉繝闈涙川缁♀偓闂佺鏈划宀勩€傚ú顏呪拺闁芥ê顦弳鐔兼煕閻樺磭澧电€殿喖顭峰鎾偄閾忚鍟庨梻浣稿閻撳牓宕伴弽銊х彾闁告洦鍋€閺€浠嬫煟閹邦剙绾ч柍缁樻礀闇夋繝濠傚閻帞鈧娲橀敃銏ゅ春閳ь剚銇勯幒鍡椾壕濡炪値浜滈崯瀛樹繆閸洖骞㈡俊顖滃劋濞堫偊姊绘担渚劸妞ゆ垵娲畷浼村冀椤撶偞鐎?
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
