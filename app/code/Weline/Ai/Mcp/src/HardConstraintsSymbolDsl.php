<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * HC-SL: hard-constraints symbol delivery (formula ∧ gloss hot path; Catalog summary cold).
 * Expand only via resolve_task_context.hard_constraint_ids — never get_indexed_document.
 */
final class HardConstraintsSymbolDsl
{
    public const EXPAND_VIA = 'resolve_task_context.hard_constraint_ids';

    public const FORMULA_MAX = 80;

    public const GLOSS_MAX = 64;

    /** Raised 8100→8200 with request_context_logic_diagram_must_read_and_sync hot entry (2026-10-19). */
    public const PACKAGE_TOKEN_BUDGET = 8200;

    public const EXPAND_IDS_MAX = 20;

    public const LANGUAGE_SCHEMA = 'hc-sl.language.v1';

    public const LANGUAGE_SCHEMA_PATH = 'app/code/Weline/Ai/Mcp/schemas/hc-sl.language.v1.json';

    /**
     * Compact language structure table for Agent rule CRUD (pointer + operators + lifecycle).
     *
     * @return array<string, mixed>
     */
    public static function languageTable(): array
    {
        $path = dirname(__DIR__) . '/schemas/hc-sl.language.v1.json';
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'schema' => self::LANGUAGE_SCHEMA,
            'path' => self::LANGUAGE_SCHEMA_PATH,
            'expand_via' => self::EXPAND_VIA,
            'error' => 'language schema file missing',
        ];
    }

    /**
     * Consistency gate for add/update/delete — no orphan overrides/legend; caps held.
     *
     * @return array{ok: bool, findings: list<string>}
     */
    public static function validateCatalogConsistency(): array
    {
        $findings = [];
        $index = self::fullRuleIndex();
        $overrides = self::overrides();
        foreach (array_keys($overrides) as $id) {
            if (!isset($index[$id])) {
                $findings[] = 'orphan_override:' . $id;
            }
        }
        foreach ($index as $id => $meta) {
            $hot = self::compileEntryHot($id, $meta['summary']);
            if ($hot['formula'] === '' || $hot['gloss_zh'] === '') {
                $findings[] = 'empty_hot:' . $id;
            }
            if (mb_strlen($hot['formula'], 'UTF-8') > self::FORMULA_MAX) {
                $findings[] = 'formula_over_cap:' . $id;
            }
            if (mb_strlen($hot['gloss_zh'], 'UTF-8') > self::GLOSS_MAX) {
                $findings[] = 'gloss_over_cap:' . $id;
            }
            foreach (self::atomsUsedInFormula($hot['formula']) as $atom) {
                if (!isset(self::legend()[$atom])) {
                    // Derived templates may use Obey/Violate/Staff/Trigger — allow builtins.
                    if (!in_array($atom, self::builtinAtoms(), true)) {
                        $findings[] = 'unregistered_atom:' . $atom . '@' . $id;
                    }
                }
            }
        }
        foreach (self::legend() as $sym => $row) {
            $ruleIds = is_array($row['rule_ids'] ?? null) ? $row['rule_ids'] : [];
            $alive = [];
            foreach ($ruleIds as $rid) {
                $rid = trim((string) $rid);
                if ($rid !== '' && isset($index[$rid])) {
                    $alive[] = $rid;
                }
            }
            if ($ruleIds !== [] && $alive === []) {
                $findings[] = 'orphan_legend_atom:' . $sym;
            }
        }
        $est = self::estimateTokens([
            'legend' => self::legend(),
            'rules' => self::symbolRules(),
            'mcp_operational' => self::symbolOperational(),
        ]);
        if ($est > self::PACKAGE_TOKEN_BUDGET) {
            $findings[] = 'token_budget_exceeded:' . $est;
        }

        return ['ok' => $findings === [], 'findings' => $findings];
    }

    /**
     * @return list<string>
     */
    public static function atomsUsedInFormula(string $formula): array
    {
        $atoms = [];
        if (preg_match_all('/\b([A-Z][A-Za-z0-9]*)\b/u', $formula, $m) > 0) {
            foreach ($m[1] as $tok) {
                $atoms[$tok] = true;
            }
        }
        // Also match legend keys that may be non-Pascal in future.
        foreach (array_keys(self::legend()) as $sym) {
            if ($sym !== '' && str_contains($formula, $sym)) {
                $atoms[$sym] = true;
            }
        }

        return array_keys($atoms);
    }

    /**
     * @return list<string>
     */
    public static function builtinAtoms(): array
    {
        // Formula-local predicates allowed without legend rows (legend is for shared hot-path atoms).
        return [
            'Obey', 'Violate', 'Staff', 'Trigger', 'Misgrade', 'EngScan', 'SmellReport', 'SilentExpand',
            'Inherit', 'MutateUpstream', 'PinInk', 'BadSeed', 'Token', 'SurfaceExc', 'DefaultTheme', 'UaExc',
            'CssImp', 'SqlBiz', 'OrmOnly', 'RealUC', 'UT', 'Deliver', 'Pass', 'ReportDone', 'WebTouch', 'WBOP',
            'Accept', 'PlanRev', 'LensE', 'LensA', 'LensI', 'Closeout', 'Brief', 'Decisive', 'VerdictLast',
            'Ask', 'Lane', 'Grade', 'Eng', 'COps', 'Write', 'Arch', 'Prep', 'Aoci', 'Chat', 'PM',
            'OneSeatOneAgent', 'Impl', 'HostPlanMode', 'ImplementabilityBullets', 'PromptWork', 'PayWork',
            'WidgetWork', 'ThemeWork', 'CodexOptIn', 'Cli', 'Codex', 'RuleCRUD', 'Skill', 'LangTable',
            'SyncMap', 'DirtySym', 'Silent', 'Nested', 'InverseRaised', 'Frontend', 'NeedPlan', 'PlanDone',
            'Complex', 'Simple', 'API', 'DesignTheme', 'PlanBody', 'Scheme', 'SiteSeed', 'SiteCopy', 'ProjectRules',
            'McpGen', 'HandEdit', 'GlobalRuleEdit', 'MCP', 'HostMdc', 'DemoPage', 'Git', 'CacheOff',
            'SeverityUi', 'SemTone', 'AllDefault',
            'CompVar', 'PaletteLeaf', 'DanglingVar',
            'ReqCtxAsk', 'ReadDiag', 'LogicChg', 'SyncDiag',
        ];
    }

    /**
     * Atom legend for prepare_project hot path.
     *
     * @return array<string, array{gloss_zh: string, rule_ids: list<string>}>
     */
    public static function legend(): array
    {
        return [
            'Ask' => ['gloss_zh' => '用户一条需求', 'rule_ids' => ['intent_lane_router']],
            'Lane' => ['gloss_zh' => '意图车道路由', 'rule_ids' => ['intent_lane_router']],
            'Grade' => ['gloss_zh' => '需求工程分级', 'rule_ids' => ['requirement_engineering_grade_gate']],
            'Eng' => ['gloss_zh' => '工程任务(改码/文档/验收)', 'rule_ids' => ['mcp_call_scope']],
            'COps' => ['gloss_zh' => '内容运营技能任务', 'rule_ids' => ['content_ops_skills_skip_mcp']],
            'Write' => ['gloss_zh' => '即将写仓', 'rule_ids' => ['architecture_grade_change_only']],
            'Arch' => ['gloss_zh' => 'mechanism∧owning∧¬do≥1', 'rule_ids' => ['architecture_grade_change_only']],
            'Prep' => ['gloss_zh' => 'prepare_project+遵守hard_constraints', 'rule_ids' => ['mcp_call_scope']],
            'Aoci' => ['gloss_zh' => 'aoci_rules(+overview若不可靠)', 'rule_ids' => ['aoci_complements_weline_mcp']],
            'WebTouch' => ['gloss_zh' => '触.phtml/CSS/主题/店面页', 'rule_ids' => ['browser_operator_self_test']],
            'WBOP' => ['gloss_zh' => 'Browser真机禁缓存+抹自动+点选+截图Read', 'rule_ids' => ['browser_operator_self_test']],
            'Curl5' => ['gloss_zh' => '打开前curl --max-time 5探活', 'rule_ids' => ['browser_operator_fail_closed_release']],
            'Hang' => ['gloss_zh' => 'Browser超时/无tab/锁死', 'rule_ids' => ['browser_operator_fail_closed_release']],
            'HangRelease' => ['gloss_zh' => 'Browser卡住立即unlock+关验收签', 'rule_ids' => ['browser_operator_fail_closed_release']],
            'Accept' => [
                'gloss_zh' => '本机验收证据已齐',
                'rule_ids' => [
                    'requirement_acceptance_always',
                    'user_facing_brief_plain_report',
                    'plan_complete_self_review_then_implement',
                ],
            ],
            'ReportDone' => [
                'gloss_zh' => '对用户宣称完成/PASS',
                'rule_ids' => [
                    'requirement_acceptance_always',
                    'user_facing_brief_plain_report',
                    'plan_complete_self_review_then_implement',
                ],
            ],
            'PlanRev' => ['gloss_zh' => '审查/自审/汇审计划', 'rule_ids' => ['plan_review_three_lenses']],
            'LensE' => ['gloss_zh' => '读计划∧探磁盘码', 'rule_ids' => ['plan_review_requires_code_evidence']],
            'LensA' => ['gloss_zh' => '架构审mech+own+¬do', 'rule_ids' => ['scheme_requires_architecture_review']],
            'LensI' => ['gloss_zh' => '影响面先AOCI', 'rule_ids' => ['plan_review_requires_impact_surface']],
            'Closeout' => ['gloss_zh' => '面向用户收口/进度', 'rule_ids' => ['user_facing_brief_plain_report']],
            'Brief' => ['gloss_zh' => '简述问题→改动→结论末', 'rule_ids' => ['user_facing_brief_plain_report']],
            'Decisive' => ['gloss_zh' => '用户要明确结论', 'rule_ids' => ['user_facing_verdict_last']],
            'VerdictLast' => ['gloss_zh' => '决定性结论置全文末', 'rule_ids' => ['user_facing_verdict_last']],
            'SqlBiz' => ['gloss_zh' => '业务层手写SQL方言', 'rule_ids' => ['business_forbids_sql_dialect']],
            'OrmOnly' => ['gloss_zh' => 'SQL仅Adapter/Dialect', 'rule_ids' => ['business_forbids_sql_dialect']],
            'HandGen' => ['gloss_zh' => 'AI手改generated/或view/tpl', 'rule_ids' => ['no_generated_no_routes_xml']],
            'RoutesXml' => ['gloss_zh' => '手写routes.xml', 'rule_ids' => ['no_generated_no_routes_xml']],
            'FetchEnd' => ['gloss_zh' => 'ORM链以fetch/fetchArray收尾', 'rule_ids' => ['no_generated_no_routes_xml']],
            'SysWriteGen' => ['gloss_zh' => '系统升级/收集命令或Publisher写generated', 'rule_ids' => ['no_generated_no_routes_xml']],
            'CssImp' => ['gloss_zh' => '默认主题视觉!important', 'rule_ids' => ['theme_default_forbids_css_important']],
            'Staff' => ['gloss_zh' => '专席真实子智能体', 'rule_ids' => ['engineering_team_for_new_requirements']],
            'Pass' => ['gloss_zh' => '可宣称通过/完成', 'rule_ids' => ['requirement_acceptance_always']],
            'ProdProbe' => ['gloss_zh' => '生产/公网店面HTTP探活', 'rule_ids' => ['production_http_probe_forbids_agent_local_curl']],
            'AgentLocalCurl' => ['gloss_zh' => 'Agent宿主本机curl公网Host', 'rule_ids' => ['production_http_probe_forbids_agent_local_curl']],
            'SshLocalhost' => ['gloss_zh' => 'SSH站柜主Host后本机127.0.0.1+Host头', 'rule_ids' => ['production_http_probe_forbids_agent_local_curl']],
            'PierceStamp' => [
                'gloss_zh' => '安装级w_fpc_pierce打穿章query',
                'rule_ids' => ['feature_delivery_urls', 'browser_cache_disabled_on_open'],
            ],
            'DelivUrl' => [
                'gloss_zh' => '交付地址/规划汇报http(s)链接',
                'rule_ids' => ['feature_delivery_urls'],
            ],
            'UiPage' => [
                'gloss_zh' => '界面页渲染请求(HTML/壳)',
                'rule_ids' => ['ui_page_controllers_mutations_via_async_binquery'],
            ],
            'Ctrl' => [
                'gloss_zh' => 'HTTP页面控制器',
                'rule_ids' => ['ui_page_controllers_mutations_via_async_binquery'],
            ],
            'Mutate' => [
                'gloss_zh' => '非界面资料增删改/更新',
                'rule_ids' => ['ui_page_controllers_mutations_via_async_binquery'],
            ],
            'AsyncBQ' => [
                'gloss_zh' => '异步BinQuery(Weline.Api→query-bin)',
                'rule_ids' => [
                    'ui_page_controllers_mutations_via_async_binquery',
                    'admin_simple_create_edit_via_modal_binquery',
                ],
            ],
            'MultiCtrlCRUD' => [
                'gloss_zh' => '多控制器CRUD管同一信息域',
                'rule_ids' => ['ui_page_controllers_mutations_via_async_binquery'],
            ],
            'AdminManage' => [
                'gloss_zh' => '后台/管理界面资源管理面',
                'rule_ids' => [
                    'admin_manage_ui_requires_humanized_full_ops',
                    'admin_simple_create_edit_via_modal_binquery',
                ],
            ],
            'Crud' => [
                'gloss_zh' => '增删改查齐备',
                'rule_ids' => ['admin_manage_ui_requires_humanized_full_ops'],
            ],
            'HumanOps' => [
                'gloss_zh' => '批量/筛选/确认等人性化操作',
                'rule_ids' => ['admin_manage_ui_requires_humanized_full_ops'],
            ],
            'CreateOnlyShip' => [
                'gloss_zh' => '只做新增或残缺管理就宣称完成',
                'rule_ids' => ['admin_manage_ui_requires_humanized_full_ops'],
            ],
            'SimpleForm' => [
                'gloss_zh' => '不复杂的管理新增/编辑表单',
                'rule_ids' => ['admin_simple_create_edit_via_modal_binquery'],
            ],
            'Dlg' => [
                'gloss_zh' => 'Weline.UI弹层dialog',
                'rule_ids' => ['admin_simple_create_edit_via_modal_binquery'],
            ],
            'InlineExpand' => [
                'gloss_zh' => '列表页内常驻展开表单占版面',
                'rule_ids' => ['admin_simple_create_edit_via_modal_binquery'],
            ],
            'SeverityUi' => [
                'gloss_zh' => '表达风险/结果/操作等级的UI',
                'rule_ids' => ['theme_semantic_severity_tone_required'],
            ],
            'SemTone' => [
                'gloss_zh' => '按矩阵绑data-tone语义色',
                'rule_ids' => ['theme_semantic_severity_tone_required'],
            ],
            'AllDefault' => [
                'gloss_zh' => '严重度不同却全默认/quiet/neutral',
                'rule_ids' => ['theme_semantic_severity_tone_required'],
            ],
            'CompVar' => [
                'gloss_zh' => '组件/foundation引用主题CSS变量',
                'rule_ids' => ['theme_referenced_tokens_must_resolve'],
            ],
            'PaletteLeaf' => [
                'gloss_zh' => '主题variables/colors盘可解析叶子(含暗色)',
                'rule_ids' => ['theme_referenced_tokens_must_resolve'],
            ],
            'DanglingVar' => [
                'gloss_zh' => '写了var/data-tone但主题叶子缺失',
                'rule_ids' => ['theme_referenced_tokens_must_resolve'],
            ],
        ];
    }

    /**
     * Spine + patterned overrides (formula ≤80, gloss_zh ≤64).
     *
     * @return array<string, array{formula: string, gloss_zh: string, cluster?: string}>
     */
    public static function overrides(): array
    {
        return [
            'intent_lane_router' => [
                'formula' => '∀t.Ask(t)→□Lane(t)',
                'gloss_zh' => '每条需求先意图车道；未路由禁进队',
                'cluster' => '§L',
            ],
            'requirement_engineering_grade_gate' => [
                'formula' => 'Ask→□(Lane∧Grade)∧Misgrade→⊥',
                'gloss_zh' => '先分级engineering|content_ops|chat；改码误判必重判',
                'cluster' => '§G',
            ],
            'requirement_scan_architecture_smell_gate' => [
                'formula' => 'EngScan→□(Aoci∧SmellReport)∧¬SilentExpand',
                'gloss_zh' => '扫码顺带架构隐患汇报；扩修须用户裁决',
                'cluster' => '§G',
            ],
            'architecture_grade_change_only' => [
                'formula' => 'Eng∧Write→□Arch|(¬Arch→⊥Write)',
                'gloss_zh' => 'Write前mech+own+¬do；禁编排层硬编码业务',
                'cluster' => '§A',
            ],
            'mcp_call_scope' => [
                'formula' => 'Eng→□Prep∧COps→¬Prep',
                'gloss_zh' => '工程须prepare；内容运营跳过MCP',
                'cluster' => '§P',
            ],
            'content_ops_skills_skip_mcp' => [
                'formula' => 'COps→¬Prep',
                'gloss_zh' => '产品/文章/主图等跳过prepare与技能索引',
                'cluster' => '§P',
            ],
            'aoci_complements_weline_mcp' => [
                'formula' => 'Eng→□Aoci∧¬(Aoci≢Prep)',
                'gloss_zh' => 'prepare后须aoci_rules；AOCI不可替Weline门禁',
                'cluster' => '§Ω',
            ],
            'requirement_acceptance_always' => [
                'formula' => 'Eng→(ReportDone→□Accept)∧(¬Accept→¬ReportDone)',
                'gloss_zh' => '未测禁报完成；Accept≻Brief；Web须真机WB-OP',
                'cluster' => '§✓',
            ],
            'browser_operator_self_test' => [
                'formula' => 'WebTouch→□WBOP∧(¬WBOP→¬Pass)',
                'gloss_zh' => 'Web改须禁缓存+抹自动+点选+截图Read',
                'cluster' => '§✓',
            ],
            'browser_operator_fail_closed_release' => [
                'formula' => 'WBOP→□Curl5∧(Hang→□HangRelease)',
                'gloss_zh' => '开Browser前curl≤5s；卡住立即unlock关签',
                'cluster' => '§✓',
            ],
            'browser_cache_disabled_on_open' => [
                'formula' => 'WBOP→□(CacheOff∧PierceStamp)',
                'gloss_zh' => '验收导航禁缓存且须FPC打穿章',
                'cluster' => '§✓',
            ],
            'feature_delivery_urls' => [
                'formula' => 'DelivUrl→□PierceStamp',
                'gloss_zh' => '交付/规划URL须w_fpc_pierce；visitor次链可免',
                'cluster' => '§✓',
            ],
            'real_machine_usecases_not_unit_tests' => [
                'formula' => 'Deliver→□RealUC∧(UT↛Pass)',
                'gloss_zh' => '真机UC=交付门；UT/契约≠交付PASS',
                'cluster' => '§✓',
            ],
            'plan_review_three_lenses' => [
                'formula' => 'PlanRev→□(LensE∧LensA∧LensI)|缺一→¬Pass',
                'gloss_zh' => '计划审查三视角齐；缺一禁通过',
                'cluster' => '§Π',
            ],
            'plan_review_requires_code_evidence' => [
                'formula' => 'PlanRev→□LensE∧VerdictLast',
                'gloss_zh' => '读计划+探磁盘码；是否通过置尾',
                'cluster' => '§Π',
            ],
            'plan_review_requires_impact_surface' => [
                'formula' => 'PlanRev→□LensI',
                'gloss_zh' => '影响面先调AOCI再MCP/原生',
                'cluster' => '§Π',
            ],
            'scheme_requires_architecture_review' => [
                'formula' => 'Scheme→□LensA',
                'gloss_zh' => '方案须mech+own+¬do；禁补丁级过审',
                'cluster' => '§Π',
            ],
            'plan_complete_self_review_then_implement' => [
                'formula' => 'PlanDone→□(三视角)∧Pass→□Impl|(ReportDone→□Accept)',
                'gloss_zh' => '自审通过即开发；宣称完成前须真机验收',
                'cluster' => '§Π',
            ],
            'user_facing_brief_plain_report' => [
                'formula' => 'Closeout→□Brief∧(¬Accept→¬ReportDone)',
                'gloss_zh' => '收口简述；未测禁已完成；禁用简述跳过真机',
                'cluster' => '§U',
            ],
            'user_facing_verdict_last' => [
                'formula' => 'Decisive→□VerdictLast',
                'gloss_zh' => '决定性结论必须放全文末尾',
                'cluster' => '§U',
            ],
            'business_forbids_sql_dialect' => [
                'formula' => 'SqlBiz→⊥∧(合法SQL→OrmOnly)',
                'gloss_zh' => '业务禁方言SQL；仅ORM Adapter层',
                'cluster' => '§D',
            ],
            'no_generated_no_routes_xml' => [
                'formula' => 'HandGen∨RoutesXml∨¬FetchEnd→⊥|□SysWriteGen',
                'gloss_zh' => '禁手改generated/tpl；禁routes.xml；ORM须fetch',
                'cluster' => '§P',
            ],
            'theme_default_forbids_css_important' => [
                'formula' => '(DefaultTheme∧CssImp∧¬UaExc)→⊥',
                'gloss_zh' => '默认主题禁!important；仅UA窄例外',
                'cluster' => '§Θ',
            ],
            'theme_design_must_inherit_not_mutate_source_widgets' => [
                'formula' => 'DesignTheme→□Inherit∧¬MutateUpstream',
                'gloss_zh' => 'design只继承；禁改app/code上游主题',
                'cluster' => '§Θ',
            ],
            'theme_inverse_raised_paper_ink_pin' => [
                'formula' => 'InverseRaised→□PinInk∧¬BadSeed',
                'gloss_zh' => 'inverse下raised先钉#0f1111再绑纸面Token',
                'cluster' => '§Θ',
            ],
            'theme_frontend_must_obey_tokens_and_surface_roles' => [
                'formula' => 'Frontend→□(Token∨SurfaceExc)',
                'gloss_zh' => '前端守变量+表面角色；纸面钉勿当硬编码扫掉',
                'cluster' => '§Θ',
            ],
            'theme_referenced_tokens_must_resolve' => [
                'formula' => 'CompVar→□PaletteLeaf∧¬DanglingVar',
                'gloss_zh' => '组件var须主题盘叶子；禁空壳；暗色同验',
                'cluster' => '§Θ',
            ],
            'theme_semantic_severity_tone_required' => [
                'formula' => 'SeverityUi→□SemTone∧¬AllDefault',
                'gloss_zh' => '风险结果操作须data-tone；禁全默认quiet',
                'cluster' => '§Θ',
            ],
            'preserve_dirty_workspace' => [
                'formula' => '¬(checkout∨restore∨clean∨stash_wipe)',
                'gloss_zh' => '禁擦脏工作区；须dirty-load再改',
                'cluster' => '§P',
            ],
            'engineering_team_for_new_requirements' => [
                'formula' => 'Complex→□(PM∧OneSeatOneAgent)∧Simple→监工',
                'gloss_zh' => '复杂一席一真子智能体；简单仅监工',
                'cluster' => '§E',
            ],
            'host_plan_mode_for_planning' => [
                'formula' => 'NeedPlan→□HostPlanMode',
                'gloss_zh' => '须计划时自行切Plan Mode',
                'cluster' => '§Π',
            ],
            'plan_content_focus_only' => [
                'formula' => 'PlanBody→□(背景∧方案∧细节)',
                'gloss_zh' => '计划只写背景/方案/细节三节',
                'cluster' => '§Π',
            ],
            'plan_implementability_checklist' => [
                'formula' => 'Plan细节→□ImplementabilityBullets',
                'gloss_zh' => '细节须含可实施清单子弹；缺则禁开工',
                'cluster' => '§Π',
            ],
            'request_context_logic_diagram_must_read_and_sync' => [
                'formula' => 'ReqCtxAsk→□ReadDiag∧(LogicChg→□SyncDiag)',
                'gloss_zh' => '请求上下文先读逻辑图；改管线同波更新',
                'cluster' => '§A',
            ],
            'website_concept_seed_not_in_modules' => [
                'formula' => 'SiteSeed∨SiteCopy→□(design∨站柜)∧¬app/code',
                'gloss_zh' => '站种子与专属文案·i18n禁写入app/code',
                'cluster' => '§A',
            ],
            'framework_demos_live_in_weline_demo' => [
                'formula' => 'DemoPage→□Weline_Demo∧¬Git',
                'gloss_zh' => '演示页只落本机Weline_Demo；禁入库',
                'cluster' => '§A',
            ],
            'production_http_probe_forbids_agent_local_curl' => [
                'formula' => 'ProdProbe→□SshLocalhost∧¬AgentLocalCurl',
                'gloss_zh' => '生产公网探活禁本机curl；须SSH本机探',
                'cluster' => '§P',
            ],
            'wls_managed_nginx_supports_fpm_origin' => [
                'formula' => '□Obey∧(Violate→⊥)',
                'gloss_zh' => 'WLS支持fpm；须origin:set',
                'cluster' => '§P',
            ],
            'global_rules_dual_write_mcp_and_host' => [
                'formula' => 'GlobalRuleEdit→□(MCP∧HostMdc)',
                'gloss_zh' => '全局规则须MCP与~/.cursor/rules双写',
                'cluster' => '§P',
            ],
            'host_editor_rules_mcp_generated_only' => [
                'formula' => 'ProjectRules→□McpGen∧¬HandEdit',
                'gloss_zh' => '项目.cursor/rules仅MCP生成；禁手改权威',
                'cluster' => '§P',
            ],
            'prompt_engineer_for_skill_prompt_work' => [
                'formula' => 'PromptWork→□Staff(提示词优化)∧仅重复才压',
                'gloss_zh' => '提示词/席位镜须提示词席；禁丢义乱加',
                'cluster' => '§S',
            ],
            'payment_engineer_for_payment_work' => [
                'formula' => 'PayWork→□Staff(支付开发工程师)',
                'gloss_zh' => '支付域须支付开发工程师席+payment技能',
                'cluster' => '§S',
            ],
            'widget_engineer_for_widget_work' => [
                'formula' => 'WidgetWork→□Staff(部件开发工程师)',
                'gloss_zh' => '部件/注入须部件开发工程师席',
                'cluster' => '§S',
            ],
            'theme_engineer_for_theme_work' => [
                'formula' => 'ThemeWork→□Staff(主题开发工程师)∧work_mode',
                'gloss_zh' => 'Theme工作须主题席并声明work_mode',
                'cluster' => '§S',
            ],
            'host_delegate_explore_plan_review_to_codex_cli' => [
                'formula' => 'CodexOptIn∧Cli→◇Codex∧¬Silent∧¬Nested',
                'gloss_zh' => '仅用户提Codex才委派；禁静默/嵌套',
                'cluster' => '§E',
            ],
            'hc_sl_rule_lifecycle_gate' => [
                'formula' => 'RuleCRUD→□(Skill∧LangTable∧SyncMap)|DirtySym→⊥',
                'gloss_zh' => '硬规则增删改须技能+语言表；禁散文脏留符号',
                'cluster' => '§P',
            ],
            'ui_page_controllers_mutations_via_async_binquery' => [
                'formula' => 'UiPage→◇Ctrl∧Mutate→□AsyncBQ∧¬MultiCtrlCRUD',
                'gloss_zh' => '界面页可用控制器；资料变更须异步BinQuery；禁多控制器CRUD',
                'cluster' => '§A',
            ],
            'admin_manage_ui_requires_humanized_full_ops' => [
                'formula' => 'AdminManage→□(Crud∧HumanOps)∧¬CreateOnlyShip',
                'gloss_zh' => '管理界面须增删改查+批量等人性化；禁只做新增就收工',
                'cluster' => '§A',
            ],
            'admin_simple_create_edit_via_modal_binquery' => [
                'formula' => 'AdminManage∧SimpleForm→□(Dlg∧AsyncBQ)∧¬InlineExpand',
                'gloss_zh' => '简单管理新增/编辑须弹层+BinQuery；禁页内常驻展开',
                'cluster' => '§A',
            ],
        ];
    }

    /**
     * Hot-path rows omit per-rule doc (package-level authoritative_doc + expand).
     *
     * @return list<array{id: string, formula: string, gloss_zh: string, cluster: string}>
     */
    public static function symbolRules(): array
    {
        $out = [];
        foreach (self::fullRuleIndex() as $id => $meta) {
            $out[] = self::compileEntryHot($id, $meta['summary']);
        }

        return $out;
    }

    /**
     * @return list<array{id: string, formula: string, gloss_zh: string, cluster: string}>
     */
    public static function symbolOperational(): array
    {
        $out = [];
        foreach (HardConstraintsCatalog::mcpOperationalRules() as $rule) {
            $id = trim((string) ($rule['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = self::compileEntryHot($id, (string) ($rule['summary'] ?? ''));
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return list<array{id: string, formula: string, gloss_zh: string, summary: string, doc: string, cluster: string}>
     */
    public static function expandBodies(array $ids): array
    {
        $index = self::fullRuleIndex();
        $out = [];
        foreach (array_slice($ids, 0, self::EXPAND_IDS_MAX) as $raw) {
            $id = trim((string) $raw);
            if ($id === '' || !isset($index[$id])) {
                continue;
            }
            $compiled = self::compileEntryHot($id, $index[$id]['summary']);
            $out[] = [
                'id' => $id,
                'formula' => $compiled['formula'],
                'gloss_zh' => $compiled['gloss_zh'],
                'summary' => $index[$id]['summary'],
                'doc' => $index[$id]['doc'],
                'cluster' => $compiled['cluster'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lookup(string $symOrId): ?array
    {
        $key = trim($symOrId);
        if ($key === '') {
            return null;
        }
        $legend = self::legend();
        if (isset($legend[$key])) {
            return [
                'sym' => $key,
                'gloss_zh' => $legend[$key]['gloss_zh'],
                'rule_ids' => $legend[$key]['rule_ids'],
                'expand_via' => self::EXPAND_VIA,
            ];
        }
        $index = self::fullRuleIndex();
        if (!isset($index[$key])) {
            return null;
        }
        $compiled = self::compileEntryHot($key, $index[$key]['summary']);

        return [
            'id' => $key,
            'formula' => $compiled['formula'],
            'gloss_zh' => $compiled['gloss_zh'],
            'summary' => $index[$key]['summary'],
            'doc' => $index[$key]['doc'],
            'cluster' => $compiled['cluster'],
            'expand_via' => self::EXPAND_VIA,
        ];
    }

    /**
     * @param array<string, mixed> $package
     */
    public static function estimateTokens(array $package): int
    {
        return (int) ceil(mb_strlen(Json::encode($package), 'UTF-8') / 4);
    }

    /**
     * @return array{id: string, formula: string, gloss_zh: string, cluster: string}
     */
    private static function compileEntryHot(string $id, string $summary): array
    {
        $overrides = self::overrides();
        if (isset($overrides[$id])) {
            $formula = self::cap((string) $overrides[$id]['formula'], self::FORMULA_MAX);
            $gloss = self::cap((string) $overrides[$id]['gloss_zh'], self::GLOSS_MAX);
            $cluster = (string) ($overrides[$id]['cluster'] ?? self::clusterFor($id));
        } else {
            [$formula, $gloss, $cluster] = self::derive($id, $summary);
        }

        return [
            'id' => $id,
            'formula' => $formula,
            'gloss_zh' => $gloss,
            'cluster' => $cluster,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function derive(string $id, string $summary): array
    {
        $cluster = self::clusterFor($id);
        $seat = self::seatHint($id, $summary);
        if ($seat !== null) {
            $formula = self::cap('Trigger→□Staff(' . $seat . ')|¬Staff→⊥', self::FORMULA_MAX);
            $gloss = self::cap('触及本域须席「' . $seat . '」；细则expand', self::GLOSS_MAX);

            return [$formula, $gloss, $cluster];
        }
        $forbid = (bool) preg_match('/\bFORBID|禁止|SERIOUS\b/i', $summary);
        $must = (bool) preg_match('/\bMANDATORY|MUST|强制\b/i', $summary);
        $abbr = self::abbr($id);
        if ($forbid && $must) {
            $formula = self::cap('□Obey(' . $abbr . ')∧(Violate→⊥)', self::FORMULA_MAX);
            $gloss = self::cap('强制遵守+禁止违反「' . $abbr . '」；细则expand', self::GLOSS_MAX);
        } elseif ($forbid) {
            $formula = self::cap('Violate(' . $abbr . ')→⊥', self::FORMULA_MAX);
            $gloss = self::cap('禁止违反「' . $abbr . '」；细则expand', self::GLOSS_MAX);
        } else {
            $formula = self::cap('□Obey(' . $abbr . ')|(¬Obey→⊥)', self::FORMULA_MAX);
            $gloss = self::cap('必须遵守「' . $abbr . '」；细则expand', self::GLOSS_MAX);
        }

        return [$formula, $gloss, $cluster];
    }

    private static function seatHint(string $id, string $summary): ?string
    {
        $map = [
            'payment_engineer' => '支付开发工程师',
            'widget_engineer' => '部件开发工程师',
            'theme_engineer' => '主题开发工程师',
            'analytics_engineer' => '数据分析',
            'ecommerce_advisor' => '电商顾问',
            'performance_engineer' => '性能检查工程师',
            'prompt_engineer' => '提示词优化工程师',
            'translation_engineer' => '翻译工程师',
            'documentation_engineer' => '文档工程师',
            'code_review' => '代码复审',
            'api_rest' => 'API',
        ];
        foreach ($map as $needle => $seat) {
            if (str_contains($id, $needle)) {
                return $seat;
            }
        }
        if (preg_match('/Team:([^:\\s]+):/', $summary, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function clusterFor(string $id): string
    {
        return match (true) {
            str_starts_with($id, 'theme_') || str_contains($id, 'ui_') || str_contains($id, 'frontend_') || str_contains($id, 'css_') || str_contains($id, 'widget_') => '§Θ',
            str_contains($id, 'plan_') || str_contains($id, 'scheme_') || str_contains($id, 'host_plan') => '§Π',
            str_contains($id, 'accept') || str_contains($id, 'browser_') || str_contains($id, 'e2e_') || str_contains($id, 'usecase') || str_contains($id, 'shentu') || str_contains($id, 'huishen') => '§✓',
            str_contains($id, 'user_facing') => '§U',
            str_contains($id, 'i18n') || str_contains($id, 'lang') || str_contains($id, 'translation') || str_contains($id, 'locale') => '§I',
            str_contains($id, 'sql') || str_contains($id, 'database') || str_contains($id, 'orm') => '§D',
            str_contains($id, 'aoci') => '§Ω',
            str_contains($id, 'engineer') || str_contains($id, 'advisor') || str_contains($id, 'seat') || str_contains($id, 'team') => '§S',
            str_contains($id, 'architecture') || str_contains($id, 'website_concept') || str_contains($id, 'demo') => '§A',
            str_contains($id, 'requirement') || str_contains($id, 'intent_lane') || str_contains($id, 'grade') => '§G',
            default => '§P',
        };
    }

    private static function abbr(string $id): string
    {
        $parts = explode('_', $id);
        if (count($parts) <= 3) {
            return self::cap($id, 28);
        }

        return self::cap(implode('_', array_slice($parts, 0, 3)), 28);
    }

    private static function cap(string $value, int $max): string
    {
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max, 'UTF-8');
    }

    /**
     * @return array<string, array{summary: string, doc: string}>
     */
    private static function fullRuleIndex(): array
    {
        $index = [];
        foreach (HardConstraintsCatalog::rules() as $rule) {
            $id = trim((string) ($rule['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $index[$id] = [
                'summary' => (string) ($rule['summary'] ?? ''),
                'doc' => (string) ($rule['doc'] ?? HardConstraintsCatalog::AUTHORITATIVE_DOC),
            ];
        }
        foreach (HardConstraintsCatalog::mcpOperationalRules() as $rule) {
            $id = trim((string) ($rule['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $index[$id] = [
                'summary' => (string) ($rule['summary'] ?? ''),
                'doc' => (string) ($rule['doc'] ?? HardConstraintsCatalog::AUTHORITATIVE_DOC),
            ];
        }

        return $index;
    }
}
