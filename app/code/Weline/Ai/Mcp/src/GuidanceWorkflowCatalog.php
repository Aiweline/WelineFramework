<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Pinned workflow guidance merged into resolve_task_context (read-only guidance).
 */
final class GuidanceWorkflowCatalog
{
    public const SCHEMA = 'workflow-contract.v1';

    /** Frontend Theme/UI development surface id (not a single attribute name). */
    public const SURFACE_FRONTEND_DEVELOPMENT = 'frontend_development';

    public const SURFACE_TAGLIB_UI_CONTROL = 'taglib_ui_control';

    public const SURFACE_HOOK_EXTENSION = 'hook_extension';

    public const SURFACE_EVENT_EXTENSION = 'event_extension';

    public const SURFACE_TEMPLATE_I18N = 'template_i18n';

    public const SURFACE_MODULE_UPGRADE = 'module_upgrade_gate';

    public const SURFACE_MODULE_I18N_CSV = 'module_i18n_csv';

    public const SURFACE_WEBUI_BROWSER_CLOSEOUT = 'webui_browser_closeout';

    public const SURFACE_REQUIREMENT_CLARIFY_USE_CASE = 'requirement_clarify_use_case';

    public const SURFACE_ENGINEERING_TEAM = 'engineering_team';

    public const SURFACE_WIDGET_DEVELOPMENT = 'widget_development';

    public const SURFACE_THEME_DEVELOPMENT = 'theme_development';

    public const SURFACE_API_SDK_DEVELOPMENT = 'api_sdk_development';

    public const SURFACE_PAYMENT_DEVELOPMENT = 'payment_development';

    public const SURFACE_VISITOR_DATA_ANALYTICS = 'visitor_data_analytics';

    public const SURFACE_ECOMMERCE_ADVISOR = 'ecommerce_advisor';

    public const SURFACE_PERFORMANCE_CHECK = 'performance_check';

    public const SURFACE_PROMPT_OPTIMIZATION = 'prompt_optimization';

    public const SURFACE_TRANSLATION_ENGINEER = 'translation_engineer';

    /** @return list<string> Repository-relative pinned doc paths. */
    public static function pinnedDocumentPaths(): array
    {
        return [
            'app/code/Weline/Ai/doc/AI硬规则索引.md',
            'app/code/Weline/Ai/doc/AI工程交付流程.md',
            'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            'app/code/Weline/Ai/doc/文档索引.md',
            'app/code/Weline/Taglib/doc/场景映射表.md',
            'app/code/Weline/Taglib/doc/标签全量索引.md',
            'app/code/Weline/Framework/doc/4-内置标签/README.md',
            'app/code/Weline/Hook/doc/Hook创建规范.md',
            'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
            'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
            'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
            'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            'app/code/Weline/Framework/doc/统一缓存范围与性能优化.md',
            'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
            'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'app/code/Weline/Theme/doc/部件开发指南.md',
            'app/code/Weline/Payment/doc/payment-shell.md',
            'app/code/Weline/Payment/doc/provider-development.md',
            'dev/ai-command/ai/需求澄清与用例规格.md',
            'dev/ai-command/ai/工程团队.md',
            'dev/ai-command/ai/支付开发.md',
            'dev/ai-command/ai/主题开发.md',
            'dev/ai-command/ai/电商顾问.md',
            'dev/ai-command/ai/性能检查.md',
            'dev/ai-command/ai/提示词优化.md',
            'dev/ai-command/ai/翻译工程师.md',
        ];
    }

    /**
     * Pointer-only bootstrap notices. Framework rule bodies live in HardConstraintsCatalog
     * (prepare_project.agent_guidance.hard_constraints / workflow_contract.hard_rules id index).
     *
     * @return list<string>
     */
    public static function sessionStartupNotices(): array
    {
        return [
            '【引导·只指路】工程任务在 MCP 已挂载/可挂载时必须先 prepare_project，并遵守 agent_guidance.hard_constraints（hard-constraints.v1）。框架硬约束不在本列表展开。权威正文 app/code/Weline/Ai/doc/AI硬规则索引.md；任务细则可由 resolve_task_context → workflow_contract.v1 surfaces 下发；工程技能由 agent_guidance.mcp_skills + resolve_skill/get_skill 按需取。编码用宿主原生编辑。冷启动门禁由 MCP 生成 `.cursor/rules/weline-mcp-coldstart.mdc`（ensure 写出）。',
            '[Bootstrap · pointers only] For engineering when MCP is attached/attachable, MUST prepare_project first and obey agent_guidance.hard_constraints (hard-constraints.v1). Framework hard rules are not expanded here. Authority app/code/Weline/Ai/doc/AI硬规则索引.md; task detail via resolve_task_context → workflow_contract.v1 surfaces; engineering skills via agent_guidance.mcp_skills + resolve_skill/get_skill. Coding uses host-native editors. Cold-start gate is MCP-generated `.cursor/rules/weline-mcp-coldstart.mdc` (via ensure).',
            '交付地址机器契约见 agent_guidance.feature_delivery_urls 与 closeout_delivery_reminder；本机默认 Host 为 `{project_hash}.test.weline.com`，禁止主验收使用 `*.weline.test`。任何 feature 必须 Agent 自跑 Playwright e2e PASS（禁止请用户测试；默认无头 e2e_playwright_headless_default，勿加 --headed 除非用户要求观看；仅正式 runner：`php bin/w e2e:run` / `npx playwright test`，禁止 `node -e`/`chromium.launch` 探活，见 e2e_playwright_formal_runner_only）；Browser 自测、**默认非抢占后台**、每次打开禁用缓存、交付地址、汇报后关闭标签见 hard_constraints（ui_feature_requires_e2e / forbid_user_manual_test_handoff / e2e_playwright_headless_default / e2e_playwright_formal_runner_only / browser_operator_self_test / browser_operator_non_preemptive / browser_cache_disabled_on_open / feature_delivery_urls / browser_release_after_delivery）与 WebUI浏览器验收与交付地址门禁.md。',
            'Delivery URL machine contract: agent_guidance.feature_delivery_urls and closeout_delivery_reminder; default local Host is {project_hash}.test.weline.com (never primary *.weline.test). Every feature MUST Agent-run Playwright e2e to PASS (never ask the user to test; default headless via e2e_playwright_headless_default—do not pass --headed unless the user asks to watch; formal runner only: php bin/w e2e:run / npx playwright test—forbid node -e / chromium.launch probes per e2e_playwright_formal_runner_only). Browser self-test, **non-preemptive background WB-OP**, cache-disabled-on-open, delivery URLs, and close-after-report live in hard_constraints (ui_feature_requires_e2e / forbid_user_manual_test_handoff / e2e_playwright_headless_default / e2e_playwright_formal_runner_only / browser_operator_self_test / browser_operator_non_preemptive / browser_cache_disabled_on_open / feature_delivery_urls / browser_release_after_delivery) and WebUI browser closeout gate doc.',
            '【调用范围】闲聊可跳过 MCP。工程/编码任务：MCP 已挂载或可挂载时必须 ensure→prepare_project→遵守 hard_constraints，再原生编辑；检索工具按需。挂不上则宿主 Read AI硬规则索引.md。例外：打招呼 hi/你好 或「提取技能」可 list MCP 技能+指令。运行/翻译状态查询默认本机（runtime_status_query_local_first）；仅明示线上/生产才 SSH。细则见 mcp_call_scope / greeting_lists_mcp_skills_and_commands。',
            '[Call scope] Skip MCP for pure chat AND content-ops skills (content_ops_skills_skip_mcp: 产品优化/详情优化/翻译优化/新建文章/规格修复—host Read doc/ai/skills + ai-command only). For engineering/coding when MCP is attached/attachable: MUST ensure→prepare_project→obey hard_constraints before host-native edits; retrieval tools as needed. If MCP cannot attach, host-Read AI硬规则索引.md. Exception: greeting hi/你好 or command 提取技能 may list MCP skills+commands. Status/translation queries default LOCAL (runtime_status_query_local_first); production SSH only when user explicitly asks. See mcp_call_scope / content_ops_skills_skip_mcp / greeting_lists_mcp_skills_and_commands.',
            '【脏改】preserve_dirty_workspace：禁止 git checkout/restore/clean/stash 擦脏；编辑前 dirty-load 当前磁盘脏改；禁止用其它会话/对话旧版本写回导致相互覆盖。',
            '[Dirty workspace] preserve_dirty_workspace: never wipe dirty with git checkout/restore/clean/stash; dirty-load current disk before edit; forbid other-session/old-baseline overwrite of live dirty files.',
            '【每条编码需求】提出后须：① 分析前后端是否要做（fe_be_scope）；② 澄清/用例（简单可轻量）；③ 非简单则宿主 Plan Mode（简单可 plan_skip+理由）；③b **简单用监工（每句监工:），复杂由父会话进 team（父会话仅 Team:项目经理:；每席真实子智能体；席间 channel+resume 互聊；禁扮演）**；④ **一定要验收**—即使不做 Playwright e2e，凡触及 Web 须本机 Browser 真机验视觉+逻辑（WB-OP）；⑤ 布局调整/不够人性化/被吐槽/审图时 **原型+UI 必须参与并调整**；⑥ 隐形需求、work_kind、ui_skill_decision；再 TDD→跑测→汇审→交付地址。',
            '[Every coding requirement] Analyze FE/BE scope; clarify/use-case (light when simple); host Plan Mode unless simple skip; non-simple requirements staff the engineering team (parent=项目经理 switchboard only, one real subagent per seat, peer talk via channel+resume, 停工 and wait on architecture contradictions; simple skip and content-ops exempt); ALWAYS acceptance—Web touches need local Browser WB-OP visual+logic even without Playwright e2e; layout/humanization/complaint/审图 force prototype+UI adjustments; then TDD→verify→汇审→delivery URLs.',
        ];
    }

    /** @return array<string, mixed> Short machine-readable reminder for every feature closeout report. */
    public static function closeoutDeliveryReminder(): array
    {
        return [
            'schema' => 'closeout-delivery-reminder.v1',
            'required_in_every_feature_report' => true,
            'section_title' => '交付地址',
            'section_title_en' => 'Delivery URLs',
            'format' => '[{label}]({probe_verified_http_or_https_url})',
            'surfaces' => ['frontend', 'backend', 'api', 'cli'],
            'when_no_ui' => 'N/A',
            'contract_ref' => 'workflow_contract.v1.feature_delivery_urls',
            'forbidden' => [
                'Reporting feature completion without a Delivery URLs section',
                'Host-private pseudo-protocol (e.g. command:simpleBrowser.api.open) as the primary acceptance link',
                'Styled plain 打开 text without Markdown [label](url) syntax',
                'Invented or probe-failed URLs presented as acceptance links',
                'Primary acceptance Host *.weline.test when *.test.weline.com is available',
                'Forcing 127.0.0.1 when *.test.weline.com Host exists',
                'Leaving acceptance Browser tabs/webviews open after the Delivery URLs section (idle Glass/Simple Browser/ide-browser)',
            ],
            'summary_zh' => '每次向用户汇报功能完成或阶段性交付时：① 须已自行按验收层级验证（agent_self_verify_before_done：UT/RT/WB）；任何 feature 须 Agent 自跑 Playwright e2e PASS（ui_feature_requires_e2e），禁止请用户测试/刷新自验（forbid_user_manual_test_handoff）；功能/Web/UI 须含验收阶段审图（acceptance_phase_requires_shentu）；Web/UI 须用当前宿主可用的真实 Browser 按用例自测，且每次打开/导航前禁用 HTTP 缓存（未测或宿主无 Browser 只能报验收未完成）；② 回复须含「需求纠偏」小节（requirement_framework_scrutiny：无调整写「无调整/合理」，有纠偏则逐条列出原问题与更合理做法）；③ 回复须含「耦合提示」小节（framework_decoupled_only：无耦合写「无耦合」，有发现则逐条列出，禁止静默交付耦合写法）；④ 回复须含「汇审」小节（closeout_requires_huishen：对照需求/验收/(功能时)原型·UI·审图·e2e）；⑤ 回复末尾必须包含「交付地址」小节，列出探活过的前台/后台/API 可点击 http(s) Markdown 链接；本机默认 Host 为 `{project_hash}.test.weline.com`（例 http://p05113ef3.test.weline.com:9555/...），禁止把 `*.weline.test` 当主验收 Host；纯逻辑无 UI 写 N/A。禁止省略该小节。⑥ 写完「交付地址」后立即关闭本回合打开的验收 Browser 标签/webview（Cursor：unlock 后 browser_tabs close；用户明确要求保留除外）。',
            'summary_en' => 'On every feature completion or stage handoff: (1) Agent must have self-verified by acceptance tier (agent_self_verify_before_done: UT/RT/WB) including Playwright e2e PASS for every feature (ui_feature_requires_e2e)—never ask the user to test (forbid_user_manual_test_handoff); include acceptance-phase 审图 when feature/UI; for Web/UI, run a host-available real Browser on agreed use cases with HTTP cache disabled on every open/navigate—otherwise only report WebUI incomplete; (2) include a 「需求纠偏」/Requirement correction section; (3) include a 「耦合提示」/Coupling tips section; (4) include a 「汇审」/Joint review section (closeout_requires_huishen); (5) end with a Delivery URLs section of probe-verified clickable http(s) Markdown links using default local Host {project_hash}.test.weline.com (never primary *.weline.test), or N/A when no UI. Never omit this section. (6) Immediately after that section, close every acceptance Browser tab/webview opened this turn, unless the user explicitly asks to keep them.',
            'browser_self_test_required_for_web' => true,
            'agent_self_verify_required' => true,
            'agent_self_verify_rule' => 'agent_self_verify_before_done',
            'acceptance_phase_requires_shentu' => true,
            'acceptance_shentu_rule' => 'acceptance_phase_requires_shentu',
            'closeout_requires_huishen' => true,
            'huishen_section_title' => '汇审',
            'huishen_section_title_en' => 'Joint review',
            'huishen_hard_constraint' => 'closeout_requires_huishen',
            'requirement_feature_kind_gate' => true,
            'requirement_implicit_analysis_skill_decision' => true,
            'requirement_cross_layer_impact_gate' => true,
            'prefer_tdd' => true,
            'requirement_framework_scrutiny' => true,
            'requirement_scrutiny_report_required' => true,
            'requirement_scrutiny_section_title' => '需求纠偏',
            'requirement_scrutiny_section_title_en' => 'Requirement corrections',
            'requirement_scrutiny_when_none' => '无调整',
            'requirement_scrutiny_hard_constraint' => 'requirement_framework_scrutiny',
            'framework_decoupled_only' => true,
            'coupling_report_required' => true,
            'coupling_section_title' => '耦合提示',
            'coupling_section_title_en' => 'Coupling tips',
            'coupling_when_none' => '无耦合',
            'coupling_hard_constraint' => 'framework_decoupled_only',
            'browser_tooling' => 'host_available_real_browser',
            'browser_cache_disabled_on_open_required' => true,
            'browser_operator_non_preemptive_required' => true,
            'browser_open_order' => [
                'prefer_background_non_preemptive_navigate',
                'disable_http_cache_for_session',
                'strip_automation_detection_flags',
                'navigate_or_reload_ignore_cache',
                'run_wb_op_and_optional_wb_vis',
            ],
            'browser_release_after_delivery_required' => true,
            'browser_release_order' => [
                'complete_wb_op_and_optional_wb_vis',
                'write_delivery_urls_section',
                'unlock_if_locked',
                'close_acceptance_browser_tabs',
            ],
            'forbidden_completion_claims_without_browser' => [
                'Claiming Web/UI feature done after unit tests or curl only',
                'Asking the user to open pages instead of AI Browser self-test when a host Browser is available',
                'Hard-coding Cursor-only Browser as the sole allowed tool',
                'Leaving idle acceptance Browser tabs after delivery',
                'Verifying this turn UI/static assets against default browser disk cache without disable/ignoreCache',
                'Treating reCAPTCHA/human-verification blocks caused by automation flags as WB-OP pass',
                'Preemptive foreground Browser navigate (position:active) that steals IDE/chat focus without user request to watch',
            ],
        ];
    }

    /** @return array<string, mixed> Machine-readable closeout URL delivery contract. */
    public static function featureDeliveryUrls(): array
    {
        return [
            'schema' => 'feature-delivery-urls.v1',
            'required_on_feature_closeout' => true,
            'surfaces' => ['frontend', 'backend', 'api', 'cli'],
            'entry_shape' => [
                'label' => 'Human-readable page or route name',
                'path' => 'Route path (e.g. /wishlist, /admin/catalog/category)',
                'url' => 'Full probe-verified http(s) URL on the active WLS instance',
                'surface' => 'frontend|backend|api|cli',
                'role' => 'primary_acceptance|secondary|api_only',
                'notes' => 'Optional: auth scope, website scope, or N/A reason',
            ],
            'link_format' => [
                'primary_acceptance' => '[{label}]({url})',
                'secondary_http' => '[{label}]({url})',
                'optional_host_opener' => 'Only as a secondary line on hosts that support it (e.g. Cursor Simple Browser); never replace the primary Markdown http(s) link.',
                'copy_fallback' => 'Optional second line: bare `{url}` in backticks for copy/paste; must match the clickable link target exactly.',
                'url_rules' => [
                    'Use a probe-verified literal http(s) URL as the Markdown link target (include ?query=&key=value as literal characters). WLS local default Host is http://{project_hash}.test.weline.com:{port}—do not force https when the instance serves http.',
                    'Do not encodeURIComponent the whole URL; do not double-encode ? / = &.',
                    'Default delivery Host MUST be {project_hash}.test.weline.com (e.g. p05113ef3.test.weline.com). Do not use *.weline.test (e.g. p05113ef3.weline.test) as the primary acceptance Host—even if /etc/hosts also lists it.',
                    'Prefer instance Host (*.test.weline.com) when WLS serves it; use 127.0.0.1 only when no *.test.weline.com Host exists.',
                    'Primary delivery link must be standard Markdown [label](http(s)://…); host-private schemes like command:simpleBrowser.api.open are optional secondary openers only (Cursor), never the sole/primary acceptance link.',
                ],
            ],
            'examples' => [
                'correct' => '[愿望清单](https://p05113ef3.test.weline.com:9555/wishlist)',
                'correct_http' => '[后台配置](http://p05113ef3.test.weline.com:9555/admin/system/config)',
                'forbidden_host' => 'http://p05113ef3.weline.test:9555/...（禁止作主验收 Host）',
                'forbidden' => '**打开**（仅变色文字、无 Markdown 链接语法）',
            ],
            'forbidden_delivery_patterns' => [
                'Styled or bold plain text “打开” without Markdown [text](url) link syntax',
                'Link text that looks clickable but has no href / url target',
                'command:simpleBrowser.api.open as primary/sole acceptance link (non-clickable or Cursor-only in many clients)',
                'command:simpleBrowser.api.open with encodeURIComponent on the entire URL',
                'Probe-failed or invented URLs presented as acceptance links',
                'Using open_resource or Simple Browser for non-http paths (source files, doc paths, commands)',
                'Forcing 127.0.0.1 when a working *.test.weline.com Host exists',
                'Using *.weline.test (e.g. p05113ef3.weline.test) as the primary acceptance Host when *.test.weline.com is available',
            ],
            'rules' => [
                'List every user-facing page and admin page created or modified by the feature.',
                'Include API/Query routes when the feature exposes programmatic entry points.',
                'Probe URLs before delivery; do not invent routes or hosts.',
                'Default local WLS Host for primary acceptance is {project_hash}.test.weline.com (e.g. http://p05113ef3.test.weline.com:9555/path). Never use *.weline.test as the primary Host when *.test.weline.com exists—even if /etc/hosts lists both.',
                'Every primary acceptance URL must be a real Markdown link `[label](http(s)://…)` with a direct http(s) target per link_format.primary_acceptance.',
                'Link label should name the page (e.g. 愿望清单, 后台分类管理); avoid orphan “打开” text outside link syntax.',
                'When a surface does not apply, state N/A for that surface instead of omitting the section.',
                'Record the same URLs in module doc/开发日志.md under the feature entry (plain http(s) URLs OK in docs).',
                'After the Delivery URLs section is written for the user, immediately close every acceptance Browser tab/webview opened this turn (hard rule browser_release_after_delivery).',
            ],
            'default_local_host' => '{project_hash}.test.weline.com',
            'forbidden_primary_hosts' => ['*.weline.test'],
            'fallback_hosts' => ['127.0.0.1', 'localhost'],
            'authoritative_skill' => 'local-browser-urls',
            'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            'authoritative_index' => 'app/code/Weline/Ai/doc/AI硬规则索引.md',
        ];
    }

    /** @return array<string, mixed> */
    public static function chapterDelivery(): array
    {
        return [
            'schema' => 'chapter-delivery.v1',
            'session_startup_notices_addon' => [
                '分章计划：上一章 doc/开发日志.md 四段门禁全 pass 后才允许下一章编码；每章=可完整验收的 e2e 闭环，须在开发日志与汇报标进度后再开下一章。',
                '含 Web 的分章 Done：WB 须 WB-OP；有视觉面且宿主可截图时再加 WB-VIS（存归属模块 doc/evidence/ch{N}/，有 doc/原型设计.md 则对照）。',
                '每章收口须在交付汇报与 doc/开发日志.md 列出本章涉及的前台、后台与 API 地址清单。',
                '工程计划自检：架构/解耦/电商合规/原型设计/e2e完整性/体量/逻辑闭环。',
            ],
            'mandatory_before_code' => [
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
                'plan_compliance_dimensions_reviewed',
            ],
            'mandatory_before_chapter_closeout' => [
                'unit_tests_pass_for_current_chapter',
                'runtime_checks_pass_for_current_chapter',
                'webui_cases_pass_for_current_chapter_or_na',
                'visual_acceptance_pass_for_webui_cases_or_na',
                'dev_log_chapter_gate_recorded',
            ],
            'mandatory_before_next_chapter' => [
                'previous_chapter_gate_passed_in_dev_log',
            ],
            'acceptance_segments' => ['unit_test', 'runtime', 'webui', 'dev_log'],
            'webui_subsegments' => ['operator_path', 'visual_acceptance'],
            'webui_defaults' => [
                'environment' => 'real_wls',
                'browser' => 'host_available_real_browser',
                'breakpoints' => [375, 768, 1024],
                'visual_acceptance_required' => true,
                'visual_acceptance_when' => 'visual_ui_and_host_can_capture_screenshots',
                'visual_evidence' => [
                    'screenshot_per_breakpoint_per_webui_case',
                    'checklist_against_module_prototype_doc_when_present',
                    'paths_recorded_in_dev_log',
                ],
                'prototype_doc' => 'app/code/Weline/{Module}/doc/原型设计.md',
                'evidence_dir_pattern' => 'app/code/Weline/{Module}/doc/evidence/ch{N}/',
                'forbidden_substitutes' => [
                    'curl_only',
                    'mock_dom',
                    'skipped_with_code_done_claim',
                    'text_only_visual_claim_without_screenshot',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function contract(): array
    {
        $frontendDevelopment = self::frontendDevelopmentSurface();
        $surfaces = self::allSurfaces();

        return [
            'schema_version' => self::SCHEMA,
            'session_startup_notices' => self::sessionStartupNotices(),
            'mandatory_before_code' => [
                'requirements_confirmed_or_scoped',
                'work_kind_feature_or_non_feature_classified',
                'requirement_fe_be_scope_analyzed',
                'requirement_clarify_use_case_spec',
                'host_plan_mode_enabled_or_simple_skip',
                'engineering_team_staffed_or_exempt',
                'feature_prototype_and_ui_participation_when_feature',
                'requirement_framework_scrutiny',
                'requirement_cross_layer_impact_gate',
                'architecture_mapped_to_requirements',
                'architecture_design_structured',
                'framework_decoupled_design',
                'extension_point_selected',
                'prepare_project_hard_constraints_when_mcp_attached',
                'optional_resolve_task_context_or_get_skill',
                'acceptance_items_planned',
                'tdd_unit_acceptance_planned',
                'shentu_acceptance_planned_when_feature',
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
            ],
            'mandatory_before_closeout' => [
                'agent_self_verify_with_acceptance_evidence',
                'requirement_acceptance_always_satisfied',
                'tdd_unit_tests_executed_and_passed',
                'acceptance_shentu_passed_or_na',
                'huishen_notes_recorded',
                'requirement_scrutiny_reported',
                'coupling_findings_reported',
                'module_docs_reconciled_with_behavior',
                'responsive_breakpoints_considered_for_web_ui',
                'webui_browser_operator_self_test_pass_or_na',
                'feature_delivery_urls_provided',
                'webui_browser_released_after_delivery_or_na',
                'module_i18n_csv_collected_when_strings_changed',
                'default_website_locales_translated_when_user_asks_translation',
            ],
            // Pointer only: full hard-constraints.v1 already shipped in prepare_project.agent_guidance.
            'hard_constraints' => [
                'schema' => HardConstraintsCatalog::SCHEMA,
                'must_obey' => true,
                'authoritative_doc' => HardConstraintsCatalog::AUTHORITATIVE_DOC,
                'source' => 'prepare_project.agent_guidance.hard_constraints',
                'detail_via' => HardConstraintsCatalog::AUTHORITATIVE_DOC,
            ],
            'phases' => [
                ['id' => 'bootstrap', 'label' => '会话引导（工程必做）', 'tools' => ['ensure-project-guidance', 'prepare_project'], 'read' => ['agent_guidance.hard_constraints', 'session_startup_notices'], 'notes' => [
                    'For engineering when MCP is attached/attachable: MUST ensure → prepare_project and obey hard_constraints before edits. Coding still uses host-native editors; MCP has no write tools. Cold-start alwaysApply gate is MCP-generated weline-mcp-coldstart.mdc.',
                ]],
                ['id' => 'locate', 'label' => '定位与需求确认', 'tools' => ['resolve_task_context', 'search_project_knowledge', 'resolve_skill', 'get_skill'], 'notes' => [
                    'After prepare_project, retrieve docs/skills/code-map via read-only MCP tools as needed. Classify work_kind=feature|non_feature. Coding uses host-native editors.',
                ]],
                ['id' => 'clarify_spec', 'label' => '需求澄清与用例规格', 'tools' => ['get_skill', 'resolve_skill'], 'docs' => [
                    'dev/ai-command/ai/需求澄清与用例规格.md',
                ], 'notes' => [
                    'MANDATORY (requirement_clarify_use_case_spec): Spec Kit/Kiro-style clarify + EARS + use cases into module doc/开发/spec/{slug}.md before architecture/code.',
                    'feature → status ready-for-plan; non_feature may skip with rationale≥24. get_skill(requirement_clarify_use_case|weline-req-clarify).',
                ]],
                ['id' => 'host_plan_mode', 'label' => '宿主计划模式', 'tools' => [], 'notes' => [
                    'DEFAULT (host_plan_mode_for_planning): enable host Plan Mode before architecture/plan—Cursor SwitchMode target_mode_id=plan.',
                    'SIMPLE SKIP: plan_complexity=simple + rationale≥24 (single module, ≤~2h, no new extension invention)—still MUST accept (requirement_acceptance_always); Web touch → Browser WB-OP visual+logic even without e2e.',
                    'Stay in Plan Mode through architecture_design + chapter plan until user approves implement; then SwitchMode to agent.',
                    'If host has no Plan Mode: plan read-only in chat; record host_plan_mode=unavailable + rationale≥24; do not edit business code yet (unless simple skip).',
                    'Plan body (plan_content_focus_only): ONLY 背景 + 方案 + 细节; forbid unrelated narrative that drifts the topic.',
                ]],
                ['id' => 'engineering_team', 'label' => '工程团队编制与停工门禁', 'tools' => ['get_skill'], 'docs' => [
                    'dev/ai-command/ai/工程团队.md',
                ], 'notes' => [
                    'MANDATORY (engineering_team_for_new_requirements): parent itself picks the mode. Simple → 监工, user-facing lines start with 监工:. Complex → parent chooses team mode and seats; parent utters ONLY Team:项目经理:; ONE_SEAT_ONE_AGENT—each seat is a real host subagent (forbid parent roleplay); PEER_TALK_VIA_CHANNEL—seats talk via channel/{thread}.md + resume peers (forbid forged multi-seat dialogue); relay Team:架构师: only from real subagent reports. Content-ops exempt.',
                    'Parent is 项目经理: SESSION bookkeeper + plan-lifecycle owner + switchboard (DoD check; NOT code-review substitute). Framework first + dual_track_all: each triggered specialty seat has 施工 + 合规复审 (事件/扩展点/Taglib/UI/i18n…). Fail → rework before acceptance.',
                    'SESSION (requirement_session_dashboard + pm_plan_lifecycle): create doc/开发/session/{slug}.md (template requirement-session.md) at kickoff; ONLY PM edits; seat deliveries require notify_pm→PM DoD check→update SESSION; deps-satisfied seats may parallel; findings open plan_ids until test+PM recheck; gaps non-empty forbid claiming done.',
                    'FLOW (team_flow_on_contracts): after 立项会, 对齐冻结会 (测试主持) freezes executable UC + contracts.md + deps.md before tech finalization/build. Wake-on-deps concurrency. Forbid designing main-path use cases after development. Acceptance EXECUTES frozen UC only.',
                    'UI in_scope HARD ORDER (ui_prototype_gate_before_test): staff 原型+前端+主题+UI; components.md; insufficient → 原型∥UI real peer talk → component-negotiate.md. After specialty review: UI+原型 review development (acceptance-ui/acceptance-prototype) with reject→PM resume development subagent; BOTH pass BEFORE Tester e2e/WB execution; Tester pass → PM 汇审 → only then user report—e2e green does not waive.',
                    'Parallel subagents only when files/extension points do not overlap and contracts+UC are frozen; start only seats whose deps are satisfied.',
                    'Cross-track findings escalate to a meeting via channel + resume (同意/异议/否决). If nobody can decide, or a major architecture contradiction: 停工汇报 and wait for user confirm—forbid PHP/phtml/CSS.',
                    'FINDINGS WAKE PM (findings_wake_pm): specialty seats that FIND problems MUST immediately escalate to 项目经理 (@项目经理：请立刻组队解决 + notify_pm)—NO Issue task list. 项目经理 MUST same-turn open channel, resume/staff seats, open SESSION plan_id, meet and resolve. FORBID backlog lists or user-only essays without escalate.',
                    'ISSUER OWNS ACCEPTANCE (requirement_issuer_owns_acceptance): after escalate/dev_ask issuer MUST stay waiting_acceptance (FORBID hands-off closed); PM MUST resume issuer on progress milestones with progress report + @发起席：请验收进度; issuer MUST Read SESSION/PM report and write issuer_acceptance; FORBID 汇审 without issuer_acceptance=pass.',
                    'ISSUER OWNS ACCEPTANCE (requirement_issuer_owns_acceptance): after escalate/dev_ask issuer MUST stay waiting_acceptance (FORBID hands-off closed); PM MUST resume issuer on progress milestones with progress report + @发起席：请验收进度; issuer MUST Read SESSION/PM report and write issuer_acceptance; FORBID 汇审 without issuer_acceptance=pass.',
                    'Minutes: owning-module doc/开发/session/{slug}.md + doc/开发/team/{slug}/ (roster.md, channel/, surfaces.md, components.md, contracts.md, deps.md, align-freeze, {seat}-review). Subagent closed is not delivery. get_skill(engineering_team|weline-engineering-team).',
                    'SEAT_SKILL_MIRRORS (hard): every staffed seat gets base skeleton + engineering_team_bundle.seat_skill_mirrors.{seat} increment; seat MUST get_skill/Read its mcp_skill_ids and authoritative_docs (e.g. 前端→frontend_development+Taglib; 事件→event_extension; 后端→module guide+upgrade gate) before coding—forbid generic-skeleton-only.',
                ]],
                ['id' => 'extension_point', 'label' => '扩展点选型', 'docs' => [
                    'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                    'app/code/Weline/Framework/doc/event/README.md',
                ]],
                ['id' => 'design', 'label' => '架构与解耦设计', 'tools' => ['resolve_task_context'], 'notes' => [
                    'Record fe_be_scope (requirement_fe_be_scope_analysis). Scrutinize requirements; record architecture_design + coupling_findings; decide ui_skill_decision. Remain in host Plan Mode unless simple skip. Coding is not gated on MCP write tools.',
                ]],
                ['id' => 'implement', 'label' => '实现（宿主原生编辑 + TDD）', 'tools' => [], 'notes' => [
                    'Edit with host-native tools. Prefer TDD: failing test first (red), then minimal production change to green. Do not claim done here.',
                ]],
                ['id' => 'verify', 'label' => '实际跑测、分层验收与审图', 'tools' => [], 'notes' => [
                    'MANDATORY (requirement_acceptance_always + agent_self_verify_before_done): run real tests; unit evidence must look like phpunit/PASS output.',
                    'HARD (tester_tests_must_be_real): forbid inventing fake fixtures/JSON/Model arrays or stubbing the SUT then asserting that invented data to claim PASS—do not deceive yourself; evidence must come from the real pathway and be independently lookup-able.',
                    'HARD (browser_strip_automation_flags): before captcha/login/submit WB-OP or Playwright, strip navigator.webdriver (CDP addScriptToEvaluateOnNewDocument / addInitScript) and launch Chromium without AutomationControlled/--enable-automation; forbid treating reCAPTCHA automation-block as WB-OP pass.',
                    'Any Web/UI touch → local Browser WB-OP visual + operator logic (even if Playwright e2e skipped as simple).',
                    'Non-simple feature → Playwright e2e chapter + suite PASS; participate → 审图 with prototype+UI adjustments.',
                    'Unfinished self-verify/审图 → report only “代码已改，验收未完成”; never claim done.',
                ]],
                ['id' => 'closeout', 'label' => '文档对齐与开发日志收口', 'docs' => ['doc/README.md', 'doc/需求.md', 'doc/开发日志.md'], 'notes' => [
                    'Write huishen_notes 汇审 before claiming feature done.',
                    'Reconcile module docs with shipped behavior before claiming done.',
                    'Plan honesty (plan_todo_evidence_closeout): never mark multi-todo work complete without per-todo evidence.',
                    'End every user-facing feature report with 「交付地址」 per feature_delivery_urls.link_format.',
                    'After Delivery URLs: immediately release acceptance Browsers (browser_release_after_delivery).',
                ]],
            ],
            'extension_point_matrix' => [
                ['intent' => 'notification_side_effect', 'prefer' => 'event_observer', 'index' => 'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md'],
                ['intent' => 'read_data', 'prefer' => 'interface_query_provider', 'index' => 'app/code/Weline/Framework/doc/BinQuery/README.md'],
                ['intent' => 'write_command', 'prefer' => 'interface_hook_queue', 'index' => 'module doc/'],
                ['intent' => 'ui_control', 'prefer' => 'taglib_hook', 'index' => 'app/code/Weline/Taglib/doc/场景映射表.md'],
                ['intent' => 'view_slot', 'prefer' => 'hook_widget', 'index' => 'app/code/Weline/Hook/doc/Hook创建规范.md'],
                ['intent' => 'cross_module_concrete_service', 'prefer' => 'forbidden', 'index' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md'],
                ['intent' => 'user_visible_copy', 'prefer' => 'lang_or_php_i18n_csv', 'index' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md'],
            ],
            'acceptance_tiers' => [
                ['surface' => 'pure_logic', 'minimum' => 'focused_unit_test'],
                ['surface' => 'api_runtime', 'minimum' => 'real_command_or_api_plus_tests'],
                ['surface' => 'page_interaction', 'minimum' => 'wls_browser_operator_path_multi_breakpoint'],
            ],
            'surfaces' => $surfaces,
            // Index only: full summaries already in prepare_project.agent_guidance.hard_constraints.
            'hard_rules' => HardConstraintsCatalog::workflowHardRulesRef(),
            // Compatibility alias used by older agents; prefer surfaces.frontend_development.
            'template_surface_rules' => $frontendDevelopment['template_surface_rules'],
            'frontend_development' => $frontendDevelopment,
            'authoritative_workflow_doc' => HardConstraintsCatalog::AUTHORITATIVE_WORKFLOW_DOC,
            'authoritative_hard_rules_index' => HardConstraintsCatalog::AUTHORITATIVE_DOC,
            'feature_delivery_urls' => self::featureDeliveryUrls(),
            'closeout_delivery_reminder' => self::closeoutDeliveryReminder(),
            'chapter_delivery' => self::chapterDelivery(),
        ];
    }

    /** Task response: full bootstrap constraints remain in prepare_project, once per session. */
    public static function forTask(string $task): array
    {
        $surfaces = [];
        foreach (self::resolveActiveSurfaces($task) as $surface) {
            $entry = [
                'label' => $surface['label'],
                'authoritative_doc' => $surface['authoritative_doc'],
                'norms' => $surface['norms'] ?? [],
            ];
            if (is_string($surface['authoritative_skill'] ?? null) && ($surface['authoritative_skill'] ?? '') !== '') {
                $entry['authoritative_skill'] = $surface['authoritative_skill'];
                $entry['mcp_skill_id'] = (string) $surface['id'];
                $entry['mcp_skill_fetch'] = [
                    'discover' => 'resolve_skill',
                    'load' => 'get_skill',
                    'skill_id' => (string) $surface['id'],
                    'alias' => $surface['authoritative_skill'],
                ];
            } else {
                $entry['mcp_skill_id'] = (string) $surface['id'];
                $entry['mcp_skill_fetch'] = [
                    'discover' => 'resolve_skill',
                    'load' => 'get_skill',
                    'skill_id' => (string) $surface['id'],
                ];
            }
            if (is_array($surface['required_companion_skills'] ?? null) && $surface['required_companion_skills'] !== []) {
                $entry['required_companion_skills'] = $surface['required_companion_skills'];
            }
            $surfaces[(string) $surface['id']] = $entry;
        }
        return [
            'schema_version' => self::SCHEMA,
            'authoritative_hard_rules_index' => HardConstraintsCatalog::AUTHORITATIVE_DOC,
            'authoritative_workflow_doc' => HardConstraintsCatalog::AUTHORITATIVE_WORKFLOW_DOC,
            'hard_constraints' => [
                'schema' => HardConstraintsCatalog::SCHEMA,
                'must_obey' => true,
                'source' => 'prepare_project.agent_guidance.hard_constraints',
            ],
            'mandatory_before_code' => [
                'requirement_fe_be_scope_analyzed',
                'requirement_clarify_use_case_spec',
                'host_plan_mode_enabled_or_simple_skip',
                'engineering_team_staffed_or_exempt',
                'extension_point_selected',
                'requirement_framework_scrutiny',
                'requirement_cross_layer_impact_gate',
                'architecture_mapped_to_requirements',
                'architecture_design_structured',
                'framework_decoupled_design',
                'optional_resolve_task_context_or_get_skill',
            ],
            'mandatory_before_closeout' => [
                'module_docs_reconciled_with_behavior',
                'real_runtime_acceptance_evidence',
                'requirement_scrutiny_reported',
                'coupling_findings_reported',
                'huishen_notes_recorded',
            ],
            'active_surface_ids' => array_keys($surfaces),
            'surfaces' => $surfaces,
        ];
    }

    /** @return array<string, mixed> */
    public static function frontendDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_FRONTEND_DEVELOPMENT,
            'label' => '前端开发规范',
            'description' => 'Theme / 布局 / 部件 / partial / 前台模板开发的统一规范表面。【高压线·开发语言】前端开发语言默认简体中文：模板/菜单/ACL/`__()`/`@lang`/`<lang>` 用户可见源串必须写中文，禁止英文当默认源串。【高压线·中英 CSV】双语靠模块 i18n/zh_Hans_CN.csv + en_US.csv 翻译（不是 CSS）；zh 第二列中文身份译、en 第二列真实英文；改文案同回合写齐并 php bin/w i18n:collect——前端/UI/主题开发成员与 Agent 均必须遵守（frontend_ui_requires_zh_en_csv + module_i18n_chinese_source_default）。【高压线】必须使用 Weline 自研主题 UI（Weline UI 2.0）与主题 CSS 变量 Token；凡任务提到 CSS 或主题/theme，必须先加载 UI 技能 frontend-design、原型技能 prototype、主题技能 weline-theme-development（MCP get_skill），禁止自造色板与间距。禁止第三方 UI 与硬编码视觉字面量。【高压线·传输】浏览器业务数据默认且只能走 BinQuery：`Weline.Api.* → worker/query-bin`；禁止原生 fetch/XHR/axios/$.ajax，禁止把 BinQuery 写成 HTTP 失败后的回退。section 身份属性（weline-code）只是其中一条硬约束，不是独立技能名。',
            'triggers' => [
                '部件', 'widget', '主题', 'theme', '布局', 'layout', 'partial',
                'phtml', '前端', 'frontend', '模板', 'section', 'slot',
                'UI', 'ui', 'frontend-design', '样式', '颜色', '间距',
                'css', 'CSS', 'stylesheet', 'prototype', '原型',
                'BinQuery', 'query-bin', 'Weline.Api', 'fetch', 'axios',
                '文案', '翻译', 'i18n', 'csv', '中文', '双语', 'zh_Hans', 'en_US',
            ],
            'authoritative_skill' => 'weline-theme-development',
            'required_companion_skills' => [
                'frontend-design',
                'prototype',
                'weline-theme-development',
                'template_i18n',
                'module_i18n_csv',
            ],
            'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'authoritative_docs' => [
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
                'app/code/Weline/Theme/doc/部件开发指南.md',
                'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                'app/code/Weline/Theme/doc/theme-layout-content-width.md',
                'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
                'app/code/Weline/Framework/doc/BinQuery/README.md',
            ],
            'norms' => [
                [
                    'id' => 'weline_ui_theme_first',
                    'summary' => '【高压线】前端必须使用 Weline 自研主题 UI（Weline UI 2.0）与主题 CSS 变量 Token（w-field/w-input/w-button… + --color-*/--weline-theme-*/spacing Token）；禁止 Bootstrap/Element/Ant 等第三方 UI、硬编码色值/间距，以及手写国家/省/市 input 替代 <w:theme:address>',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                ],
                [
                    'id' => 'theme_preview_runtime_three_modes',
                    'summary' => '【高压线】主题身份权威分三态：（1）可视化编辑预览：真实店面 path + query + typed editor_context 参数为主（禁止 start-preview；前台 theme-preview/content HTTP 壳已完整删除，禁止再造/302 兼容）（2）版本真实预览：预览 Token 反解析为准，URL theme/scope 不得覆盖 Token；（3）正式店面：RequestContext/Scope/路径解析为准（仅 r{published_release_id}）。业务逻辑三态同构（preview_storefront_delivery_parity）；只允许在身份装配层分支',
                    'detail_doc' => 'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
                ],
                [
                    'id' => 'css_or_theme_requires_ui_prototype_theme_skills',
                    'summary' => '【高压线】凡任务/需求提到 CSS 或主题/theme（含主题样式、Token、前台/后台视觉 CSS），写样式或改主题前必须先加载并服从三技能：（1）UI 技能 frontend-design；（2）原型技能 prototype；（3）主题技能 weline-theme-development（MCP get_skill / surface frontend_development）。主题 Token 与 Weline UI 2.0 仍优先；UI/原型不得自造色板或绕开 Theme。纯无视觉且无 CSS/主题意图可 N/A。避免主题开发跑偏',
                    'detail_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'required_companion_skills' => [
                        'frontend-design',
                        'prototype',
                        'weline-theme-development',
                    ],
                    'mcp_skill_id' => self::SURFACE_FRONTEND_DEVELOPMENT,
                ],
                [
                    'id' => 'ui_skill_requires_theme_skill',
                    'summary' => '【高压线】凡使用宿主 UI / frontend-design / prototype / 审美类技能写前台或后台界面，必须先用 MCP get_skill 加载 weline-theme-development（或 surface frontend_development）并服从 Theme开发总指南 / theme-css-variables-only；即便用户未说 CSS/主题，只要改可视化 .phtml/.css 即触发。主题 Token 与 Weline UI 2.0（含后台 w-backend-page/w-card/w-field…）优先于通用 UI 自造色板。禁止发明私有 #hex/rgb、px 间距阶梯、圆角阴影套件、平行 design token 或裸 HTML 表单堆；UI/原型仅可指导构图/层次/文案',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    'authoritative_skill' => 'weline-theme-development',
                    'mcp_skill_id' => self::SURFACE_FRONTEND_DEVELOPMENT,
                ],
                [
                    'id' => 'backend_admin_ui_requires_frontend_theme_skills',
                    'summary' => '【高压线】任何后台/admin 可视化页（view/templates/backend|Backend、backend Controller→phtml、后台菜单打开的配置/列表页）：前端/UI/原型/主题席开工前必须 get_skill(frontend_development|weline-theme-development) + Read Theme开发总指南，并加载 frontend-design+prototype 做布局；交付须对齐 Marketing/Smtp 等既有后台 chrome（w-backend-page / w-card / w-field / w-input / w-button / w-table）。搜索/筛选工具条须紧凑横排（输入+按钮同行、限制最大宽度），禁止全宽竖叠「一条就占满屏」的丑搜索条。禁止原始 h1+裸 form/table、内联 margin/width 装饰、硬编码色。纯 Model/Service 无 phtml 可 N/A',
                    'detail_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'authoritative_skill' => 'weline-theme-development',
                    'mcp_skill_id' => self::SURFACE_FRONTEND_DEVELOPMENT,
                    'required_companion_skills' => [
                        'frontend-design',
                        'prototype',
                        'weline-theme-development',
                    ],
                ],
                [
                    'id' => 'frontend_dev_language_chinese_default',
                    'summary' => '【高压线·开发语言】前端开发约定：用户可见源串默认简体中文（模板 <lang>/@lang、菜单、ACL、__()）。中文写在代码里是正确且强制的；禁止英文当默认源串。多语言展示靠中英 CSV 翻译，不靠改模板语言、也不靠 CSS',
                    'detail_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                    'mcp_rule_ids' => [
                        'module_i18n_chinese_source_default',
                        'frontend_ui_requires_zh_en_csv',
                    ],
                    'required_companion_skills' => [
                        'template_i18n',
                        'module_i18n_csv',
                    ],
                ],
                [
                    'id' => 'frontend_ui_requires_zh_en_csv',
                    'summary' => '【高压线·中英 CSV】凡前端/UI/主题改动用户可见文案：源串简中；同回合写齐模块 i18n/zh_Hans_CN.csv（第二列中文身份）与 en_US.csv（第二列真实英文）；禁止 zh 列填英文、en 列留中文占位、漏写 CSV 就交页。改后必须 php bin/w i18n:collect 并按当前后台/前台 locale 抽检。未完成双语不得宣称 UI 交付完成——开发成员与 Agent 同等约束',
                    'detail_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                    'mcp_rule_ids' => [
                        'frontend_ui_requires_zh_en_csv',
                        'module_i18n_csv_collect',
                        'module_i18n_chinese_source_default',
                    ],
                    'required_companion_skills' => [
                        'template_i18n',
                        'module_i18n_csv',
                    ],
                ],
                [
                    'id' => 'theme_address_for_region_pickers',
                    'summary' => '【高压线】前台/后台国家·省·市·区·地区选择（表单、列表筛选、多选 chips）必须用 <w:theme:address>（single/multi）；禁止手写国家/地区 <select>、自造筛选芯片行或绕开 Theme Address 的级联 input；chips/菜单由标签与浮层内核提供',
                    'detail_doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
                ],
                [
                    'id' => 'taglib_before_hand_rolled_controls',
                    'summary' => '【高压线】写任何选择性/领域控件前必须先读 Taglib 场景映射表与标签全量索引；架构上选择性选项优先官方标签（国家→theme:address、范围→w:scope、语言→i18n:switcher 等）；禁止未查库就手写 select/ISO text/自造 chips；无现成标签则在拥有模块新增 Taglib',
                    'detail_doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
                ],
                [
                    'id' => 'storefront_internal_url_via_url_helper',
                    'summary' => '【高压线】站内跳转 href/action/data-*-url 必须用 @url/<url>/@backend-url 或 Url::getUrl/getFrontendUrl/getBackendUrl；禁止 \'/\'.$path 或手写 /module/action；外链 http(s) 可原样',
                    'detail_doc' => 'app/code/Weline/Framework/doc/4-内置标签/06-url标签使用指南.md',
                ],
                [
                    'id' => 'weline_ui_floating_primitives',
                    'summary' => '【高压线】菜单/Popover/Tooltip/Combobox/Dialog/产品·媒体·图标选择器/MCP 前端弹出等浮层必须用 Weline.UI（Weline.UI.dialog、menu/popover/tooltip/combobox/anchored-float 或 UI.floating.attach）；禁止私造 modal、手写 left/top、自研 flip 或私有 portal',
                    'detail_doc' => 'app/code/Weline/Theme/doc/widgets/anchored-float.md',
                ],
                [
                    'id' => 'layer_choice',
                    'summary' => '先判定改动层：layout / partial / component / widget，再落文件',
                ],
                [
                    'id' => 'no_generated_edit',
                    'summary' => '禁止直接改 generated/ 与 view/tpl；改源模板后走编译/扫描链路',
                ],
                [
                    'id' => 'taglib_attr_no_php',
                    'summary' => 'w:* / Taglib 标签属性禁止 <?= / <?php',
                ],
                [
                    'id' => 'no_php_tags_in_comments',
                    'summary' => '注释（// # /* */ /** */ <!-- -->）禁止出现 <?= / <?php 开标签；非禁止普通注释掉语句；文件头勿留生成器短回显',
                    'detail_doc' => 'app/code/Weline/Ai/doc/AI硬规则索引.md',
                ],
                [
                    'id' => 'chinese_comments_friendly_style',
                    'summary' => '新增/修改的说明性注释与 PHPDoc 摘要默认简体中文；代码风格友好清晰、贴合周围、避免过度巧妙；仍遵守 no_php_tags_in_comments',
                    'detail_doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                ],
                [
                    'id' => 'widget_external_js',
                    'summary' => '部件禁止所有内联 CSS/可执行 JS（含 style= 与 on*=）；静态文件见 widget_static_assets_bake_to_head；模块级 JS 须 weline.modules.js + data-weline-load/declare（禁止 @static/<js> 直引）；改登记后必须 php bin/w resource:compile welineModules',
                    'detail_doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                    'verify' => 'php bin/w resource:compile welineModules',
                ],
                [
                    'id' => 'theme_js_module_declare_only',
                    'summary' => '【高压线】前台主题/部件/布局业务模块 JS 只能 Weline.declare / data-weline-load / data-weline-declare；部件静态资产按 widget_static_assets_bake_to_head 统一外置发射；禁止 script src=@static 或裸 <js> 拉模块；改 weline.modules.js 后必须 resource:compile welineModules 收集',
                    'detail_doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                    'verify' => 'php bin/w resource:compile welineModules',
                ],
                [
                    'id' => 'weline_js_loader_framework_only',
                    'summary' => '【高压线】weline.js 只做 ModuleLoader + 维护时懒加载维护模块 JS；禁止 cart/account 等业务名与业务逻辑/内嵌维护 UI',
                    'detail_doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                ],
                [
                    'id' => 'slot_fallback_no_demo',
                    'summary' => '布局 slot <else/> 禁止业务/demo 占位；用 default_injections',
                ],
                [
                    'id' => 'section_identity',
                    'summary' => '前台字面 <section> 与 w:slot wrapper="section" 必须有非空语义 section 身份（属性名 weline-code）',
                    'detail_doc' => 'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                    'verify' => 'php bin/w frontend:check-section-code',
                ],
                [
                    'id' => 'theme_layout_widget_owner',
                    'summary' => '同模块：仅本模块可 <w:widget> 内嵌且禁同部件 JSON 双路径；跨模块：外国部件只能空槽+拥有模块 JSON 默认注入；Theme 仅可内嵌 Weline_Theme；部件施工派部件开发工程师；无 user_deleted@{versionId} 时所选路径必装永远存在',
                    'detail_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'verify' => 'php bin/w frontend:check-theme-layout-widgets',
                ],
                [
                    'id' => 'required_default_always_present_without_user_deleted',
                    'summary' => '【高压线·系统真做法·必须记住】无 user_deleted@{versionId} 时 required JSON 默认注入经布局固化写入模板（有槽则固化；与主题/版本无关；无模板→激活主题运行期动态固化；插件注入→全主题重固化涉及布局；遗漏=固化方案问题）；布局内嵌必装同保证；唯一省略=本版本卸载',
                    'detail_doc' => 'app/code/Weline/Theme/doc/布局固化与默认注入.md',
                ],
                [
                    'id' => 'css_variables_only',
                    'summary' => '【高压线】视觉值必须走主题 CSS 变量 Token（见 theme-css-variables-only.md）；禁止 #hex/rgb/随意 px 字面量；新 Token 须落盘 variables/_*.css',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                ],
                [
                    'id' => 'layout_content_width_tokens',
                    'summary' => '【高压线·统一版心】禁止自写一套页面容器；内容区宽度/gutter 必须走 theme-layout-content-width.md 的壳层 A（已在 .w-container 内：width:100% + padding-inline:0）或壳层 B（独立壳：--weline-layout-content-* / .w-theme-content-width）；禁止 1440px/1200px 等私有 fallback 与双重 gutter；特质色仅局部 scope 可自定义',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-layout-content-width.md',
                ],
                [
                    'id' => 'browser_api_binquery_default',
                    'summary' => '【高压线·传输】浏览器业务请求默认且只能走 BinQuery：`theme.js → Weline.Api.resource|graph|stream → worker/query-bin`。禁止原生 fetch/XMLHttpRequest/$.ajax/axios、手写 /api/framework/query-bin 或业务 REST URL；禁止先打 HTTP 控制器再把 BinQuery 当回退。无 Weline.Api 时返回空/降级本地，不得发明第二套原生请求通道',
                    'detail_doc' => 'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
                ],
                [
                    'id' => 'responsive_tablet_pc',
                    'summary' => '设计阶段纳入平板(≈768)与 PC(≥1024) 响应式；验收收集多断点证据，禁止只做桌面再补丁',
                    'breakpoints' => ['375', '768', '1024', '1440'],
                ],
                [
                    'id' => 'docs_reconcile_per_feature',
                    'summary' => '每完成一个功能对照归属模块 doc/ 与实现，有差异则改文档或代码使二者对齐',
                ],
                [
                    'id' => 'feature_delivery_urls',
                    'summary' => '功能交付时列出前台/后台/API 地址；主验收须直接 http(s) Markdown 链接 [名称](url)，禁止宿主私有伪协议作主链与仅变色「打开」伪链接',
                    'detail_doc' => 'app/code/Weline/Ai/doc/AI工程交付流程.md',
                ],
            ],
            'template_surface_rules' => [
                'surface' => self::SURFACE_FRONTEND_DEVELOPMENT,
                'label' => '前端开发规范',
                'forbidden' => [
                    'PHP tags inside HTML attribute values (e.g. attr="<?= ... ?>") on w:* / Taglib tags',
                    'PHP open tags (<?= / <?php) inside comments (// # /* */ <!-- -->); not ordinary commented-out statements',
                    'Inline <script> containing <?= in Theme widgets/partials that render through slot injection',
                    'Business UI or demo copy inside layout slot fallbacks (use widgets + default_injections)',
                    'Non-Weline_Theme <w:widget> or fetch(.../widgets/...) inside Theme layouts/partials (cross-module must use owning-module default_injections + empty slot)',
                    'Same-module widget both layout-inlined and listed in default_injections JSON (XOR: delete one path)',
                    'Dropping required JSON default_injections or layout-tag inlines when no user_deleted@{versionId} exists (published shell / skip_fill is NOT a license to omit)',
                    'Frontend literal <section> or w:slot wrapper="section" without non-empty semantic section identity (weline-code attribute)',
                    'Editing generated/ or view/tpl as if they were source templates',
                    'Desktop-only Web UI implementation without tablet/PC responsive consideration',
                    'Shipping a feature without reconciling owning module doc/ with behavior',
                    'Inventing a private page/module content container instead of Theme shell A (.w-container fill) or shell B (--weline-layout-content-* / .w-theme-content-width)',
                    'Hard-coded content max-width (1440px/1280px/1180px/90rem) or private padding-inline instead of --weline-layout-content-* / .w-container',
                    'Double horizontal gutters: .w-container parent plus page/widget max-width + padding-inline',
                    'var(--weline-layout-content-max-width, 1440px) or any pixel fallback on layout content tokens',
                    'Third-party UI kits (Bootstrap/Element/Ant/…) or ad-hoc visual CSS literals instead of Weline UI 2.0 + theme CSS variable tokens',
                    'Naked country/province/city/district inputs when <w:theme:address> or official Theme/Taglib address controls exist',
                    'Hand-rolled country/region <select>, custom filter chip rows, or cascade inputs that replace <w:theme:address> in admin/storefront filters and forms',
                    'Hand-computed left/top, custom flip/boundary scripts, or private portal stacks for menus/popovers/tooltips/combobox/address multi dropdowns — use Weline.UI floating primitives instead',
                    'Images without explicit HTML width+height (or aspect_ratio / layout dims) — CLS; do not rely on responsive CSS alone',
                    'Native browser business I/O: fetch / XMLHttpRequest / $.ajax / axios, hand-written /api/framework/query-bin, or business REST URLs for first-party admin/storefront data',
                    'HTTP controller as primary region/data path with BinQuery/Weline.Api as catch fallback — BinQuery is the default and only business transport',
                    'English (or non-Chinese) as the default user-visible source string in templates/menus/ACL/__()/@lang/<lang> — frontend development language defaults to Simplified Chinese',
                    'Shipping or claiming UI done without aligned module i18n/zh_Hans_CN.csv + en_US.csv (zh translate must be Chinese identity; en translate must be real English, never Chinese placeholder)',
                    'Leaving newly added UI copy untranslated in module CSV, or rewriting template sources to English to “fix” locale display',
                ],
                'required' => [
                    'Use first-party Weline Theme / Weline UI 2.0 component classes and theme CSS variable tokens for all visual UI',
                    'Browser business data via Weline.Api.* → worker/query-bin (BinQuery) only; never raw fetch/ajax as primary or as BinQuery fallback',
                    'Address and region cascade/filters via <w:theme:address> (single or multi, including official chips); never hand-roll region inputs or chip rows',
                    'Floating surfaces (menu/popover/tooltip/combobox/address multi) via menu/popover/tooltip/combobox/anchored-float or UI.floating.attach',
                    'Images via <w:file:image> (or equivalent) with UI width+height or aspect_ratio, plus Theme CSS max-width:100%;height:auto / .w-file-image',
                    'Choose layout / partial / component / widget layer before editing',
                    'Widget JS scoped by data-js-ns + data-uid; register in weline.modules.js and load via Weline.declare / data-weline-load / data-weline-declare; after any modules registry change run php bin/w resource:compile welineModules before closeout',
                    'Dynamic values in attributes: set on HTML elements in body, not on Taglib tag attributes',
                    'Widget root uses WidgetUiScope and a stable type-level section identity attribute',
                    'Layout/partial literal <section> and w:slot wrapper="section" carry stable semantic section identity; verify with php bin/w frontend:check-section-code before setup:upgrade',
                    'Same-module: layout-inlined widget forbids same-code default_injections JSON; cross-module: no layout mutual widget calls — foreign widgets only via owning-module default_injections + empty slot; Theme layouts inline only Weline_Theme; verify with php bin/w frontend:check-theme-layout-widgets',
                    'Without user_deleted@{versionId}, required default JSON injections and layout-tag inlines must always exist (required_default_always_present_without_user_deleted)',
                    'Design and accept Web UI across tablet (≈768) and PC (≥1024), plus mobile 375 when relevant',
                    'After each feature, reconcile module README/需求/开发日志/topic docs with shipped behavior',
                    'After each feature closeout, list probe-verified frontend/backend/API URLs; primary acceptance must use direct http(s) Markdown links `[label](url)`, not host-private pseudo-protocols (e.g. command:simpleBrowser) as the sole/primary link or styled plain “open” text',
                    'Before writing any page/module content shell, choose Theme shell A or B per theme-layout-content-width.md — never invent a third container; inside .w-container use width:100% and padding-inline:0; standalone shells use --weline-layout-content-max-width and --weline-layout-content-padding-inline (or .w-theme-content-width) without pixel fallbacks',
                    'Frontend development language defaults to Simplified Chinese for user-visible sources; bilingual display uses module i18n/zh_Hans_CN.csv + en_US.csv (not CSS); after string/CSV changes run php bin/w i18n:collect and locale spot-check before claiming UI done',
                ],
                'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'authoritative_docs' => [
                    'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                    'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
                    'app/code/Weline/Theme/doc/部件开发指南.md',
                    'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                    'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                    'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    'app/code/Weline/Theme/doc/theme-layout-content-width.md',
                    'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
                    'app/code/Weline/Framework/doc/BinQuery/README.md',
                ],
                'verification_commands' => [
                    'php bin/w frontend:check-section-code',
                    'php bin/w frontend:check-theme-layout-widgets',
                    'php bin/w resource:compile welineModules',
                    'php bin/w i18n:collect Weline_Module',
                ],
            ],
            'verification_commands' => [
                'php bin/w frontend:check-section-code',
                'php bin/w frontend:check-theme-layout-widgets',
                'php bin/w resource:compile welineModules',
                'php bin/w i18n:collect Weline_Module',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function allSurfaces(): array
    {
        return [
            self::SURFACE_FRONTEND_DEVELOPMENT => self::frontendDevelopmentSurface(),
            self::SURFACE_TAGLIB_UI_CONTROL => self::taglibUiControlSurface(),
            self::SURFACE_HOOK_EXTENSION => self::hookExtensionSurface(),
            self::SURFACE_EVENT_EXTENSION => self::eventExtensionSurface(),
            self::SURFACE_TEMPLATE_I18N => self::templateI18nSurface(),
            self::SURFACE_MODULE_I18N_CSV => self::moduleI18nCsvSurface(),
            self::SURFACE_MODULE_UPGRADE => self::moduleUpgradeGateSurface(),
            self::SURFACE_WEBUI_BROWSER_CLOSEOUT => self::webuiBrowserCloseoutSurface(),
            self::SURFACE_REQUIREMENT_CLARIFY_USE_CASE => self::requirementClarifyUseCaseSurface(),
            self::SURFACE_ENGINEERING_TEAM => self::engineeringTeamSurface(),
            self::SURFACE_API_SDK_DEVELOPMENT => self::apiSdkDevelopmentSurface(),
            self::SURFACE_WIDGET_DEVELOPMENT => self::widgetDevelopmentSurface(),
            self::SURFACE_THEME_DEVELOPMENT => self::themeDevelopmentSurface(),
            self::SURFACE_PAYMENT_DEVELOPMENT => self::paymentDevelopmentSurface(),
            self::SURFACE_VISITOR_DATA_ANALYTICS => self::visitorDataAnalyticsSurface(),
            self::SURFACE_ECOMMERCE_ADVISOR => self::ecommerceAdvisorSurface(),
            self::SURFACE_PERFORMANCE_CHECK => self::performanceCheckSurface(),
            self::SURFACE_PROMPT_OPTIMIZATION => self::promptOptimizationSurface(),
            self::SURFACE_TRANSLATION_ENGINEER => self::translationEngineerSurface(),
        ];
    }

    /**
     * @return list<string> Surface ids matched by task text (case-insensitive substring).
     */
    public static function resolveActiveSurfaceIds(string $task): array
    {
        $taskLower = mb_strtolower(trim($task), 'UTF-8');
        if ($taskLower === '') {
            return [];
        }

        $active = [];
        foreach (self::allSurfaces() as $id => $surface) {
            foreach (is_array($surface['triggers'] ?? null) ? $surface['triggers'] : [] as $trigger) {
                $trigger = mb_strtolower(trim((string) $trigger), 'UTF-8');
                if ($trigger !== '' && str_contains($taskLower, $trigger)) {
                    $active[] = $id;
                    break;
                }
            }
        }

        return array_values(array_unique($active));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resolveActiveSurfaces(string $task): array
    {
        $surfaces = [];
        foreach (self::resolveActiveSurfaceIds($task) as $id) {
            $surface = self::allSurfaces()[$id] ?? null;
            if (is_array($surface)) {
                $surfaces[] = $surface;
            }
        }

        return $surfaces;
    }

    /** @return array<string, mixed> */
    public static function taglibUiControlSurface(): array
    {
        return [
            'id' => self::SURFACE_TAGLIB_UI_CONTROL,
            'label' => 'Taglib 控件规范',
            'description' => '模板领域控件必须使用官方 Taglib/Hook；写 HTML 前先查场景映射。',
            'triggers' => [
                'taglib', 'w:', '<w:', 'select', 'switcher', 'picker', '手写', '原生',
                'language:select', 'website:select', 'd-table', 'd-form',
            ],
            'authoritative_skill' => 'weline-taglib-first',
            'authoritative_doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
            'authoritative_docs' => [
                'app/code/Weline/Taglib/doc/场景映射表.md',
                'app/code/Weline/Taglib/doc/标签全量索引.md',
                'app/code/Weline/Taglib/doc/README.md',
                'app/code/Weline/Framework/doc/4-内置标签/README.md',
            ],
            'norms' => [
                ['id' => 'scenario_mapping_first', 'summary' => '写 HTML/控件前先读场景映射表并选合适 Taglib（含地址/国家选择）'],
                ['id' => 'no_hand_rolled_select', 'summary' => '禁止手写 language/website/currency/ACL 等 domain select；禁止手写 ISO 国家码 input 代替 theme:address'],
                ['id' => 'taglib_attr_no_php', 'summary' => 'w:* 属性禁止 <?= / <?php'],
                [
                    'id' => 'no_php_tags_in_comments',
                    'summary' => '注释内禁止 <?= / <?php 开标签（非禁止普通注释掉语句）',
                    'detail_doc' => 'app/code/Weline/Ai/doc/AI硬规则索引.md',
                ],
                [
                    'id' => 'chinese_comments_friendly_style',
                    'summary' => '新增/修改说明性注释与 PHPDoc 默认简体中文；风格友好可读、贴合周围代码',
                    'detail_doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                ],
                [
                    'id' => 'taglib_callback_static_url',
                    'summary' => 'Taglib callback 返回 HTML 禁止裸 @static(...)；须 fetchTagSource 解析静态 URL',
                    'detail_doc' => 'app/code/Weline/Taglib/doc/如何自定义Tag.md',
                ],
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Hand-rolled <select>/<input> for language, website, currency, ACL, file, DataTable domains',
                    'PHP tags inside w:* / Taglib tag attribute values',
                    'PHP open tags (<?= / <?php) inside comments (// # /* */ <!-- -->); not ordinary commented-out statements',
                    'Using raw HTML when an official Taglib or Hook exists in scenario mapping',
                    'Literal @static(...) inside Taglib callback()/runtime_callback() HTML return strings',
                ],
                'required' => [
                    'Consult app/code/Weline/Taglib/doc/场景映射表.md before adding HTML controls',
                    'Use app/code/Weline/Taglib/doc/标签全量索引.md for full tag catalog',
                    'When a Taglib callback emits <link>/<script>, resolve static URLs via Template::fetchTagSource(DataInterface::dir_type_STATICS, Module::path) before returning HTML',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function hookExtensionSurface(): array
    {
        return [
            'id' => self::SURFACE_HOOK_EXTENSION,
            'label' => 'Hook 扩展规范',
            'description' => 'Hook 三件套：hook.php + doc/hook/*.md + view/hooks/*.phtml。',
            'triggers' => [
                'hook', 'view/hooks', 'hook.php', 'w:hook', '<w:hook',
            ],
            'authoritative_doc' => 'app/code/Weline/Hook/doc/Hook创建规范.md',
            'authoritative_docs' => [
                'app/code/Weline/Hook/doc/Hook创建规范.md',
                'app/code/Weline/Theme/doc/Hook使用指南.md',
                'app/code/Weline/Theme/doc/Hook点位索引.md',
            ],
            'norms' => [
                ['id' => 'hook_triple', 'summary' => 'Owner hook.php + doc/hook/*.md + impl view/hooks/*.phtml'],
                ['id' => 'hook_name_five_segment', 'summary' => '{Module}::{frontend|backend}::{partials|layouts}::{component}::{position} — type MUST be partials or layouts only; put page/feature in component or position'],
            ],
            'verification_commands' => [
                'php bin/w setup:upgrade --route',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'view/hooks/*.phtml without owner hook.php entry and doc/hook/*.md',
                    'doc path mismatch with hook.php doc field',
                    'Inventing type segment (theme-editor, checkout, product, account, …) — type is ONLY partials or layouts',
                    'Hook names like ::backend::theme-editor:: or ::frontend::checkout:: in the type slot',
                ],
                'required' => [
                    'Declare hook in owner module hook.php before implementation',
                    'Add doc/hook/*.md spec before setup:upgrade',
                    'Use partials or layouts as the third segment; page/feature names belong in component or position',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function eventExtensionSurface(): array
    {
        return [
            'id' => self::SURFACE_EVENT_EXTENSION,
            'label' => 'Event 扩展规范',
            'description' => 'Observer + event.xml + doc/event；禁止发明未文档化事件名。',
            'triggers' => [
                'event', 'observer', 'event.xml', 'dispatch', 'EventsManager',
            ],
            'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
            'authoritative_docs' => [
                'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
                'app/code/Weline/Framework/doc/event/README.md',
                'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            ],
            'norms' => [
                ['id' => 'documented_event_name', 'summary' => '事件名必须在 doc/event 或 Framework event 索引中文档化'],
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Dispatching undocumented event names',
                    'Cross-module new Service instead of Event for side effects',
                ],
                'required' => [
                    'Search event/README.md before creating new events',
                    'Add Observer + etc/event.xml + doc/event/*.md',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function templateI18nSurface(): array
    {
        return [
            'id' => self::SURFACE_TEMPLATE_I18N,
            'label' => '模板 i18n 规范',
            'description' => '前台 .phtml 用户可见文案用 <lang> / @lang()，禁止 HTML 内 __()；@lang()/{} 源文含逗号须加引号或改用 <lang>。',
            'triggers' => [
                '__', 'lang', '翻译', 'i18n', 'phtml', '文案', 'csv', 'en_us', 'zh_hans_cn', '@lang', 'ParseError',
            ],
            'authoritative_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            'authoritative_docs' => [
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
                'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
            ],
            'norms' => [
                ['id' => 'lang_tag_not_php', 'summary' => 'HTML 正文/属性用 <lang>/@lang，不用 <?= __() ?>'],
                ['id' => 'at_lang_no_unquoted_comma', 'summary' => '@lang()/{} 源文含逗号必须加引号或改用 <lang>，禁止 @lang{a, b} 导致编译 ParseError'],
                ['id' => 'chinese_source_default', 'summary' => '模板/菜单/__() 源串默认简体中文（正确）；禁止英文当默认源串'],
                ['id' => 'active_locale_must_show_target_language', 'summary' => '活跃/默认 locale 非中文时必须显示该语种译文；禁止因源串是中文就在英文站露出中文；禁止把 en_US 第二列留成中文占位'],
                ['id' => 'csv_bilingual_aligned', 'summary' => 'zh_Hans_CN.csv 与 en_US.csv source 键对齐；en_US 第二列必须是英文译文，禁止留空或把中文 source 原样当作英文'],
                ['id' => 'collect_after_csv', 'summary' => '改 CSV 或新增源串后必须 php bin/w i18n:collect，否则运行时词典不更新'],
                ['id' => 'en_us_no_chinese_placeholder', 'summary' => '交付前抽检 en_US：用户可见词条第二列不得仍为纯中文（与 source 相同）'],
                ['id' => 'user_mentions_translation_all_default_website_locales', 'summary' => '用户提到「翻译」作为任务时：按默认网站已选语言（Website::ID_DEFAULT）全语种译写；模块 CSV 仅 zh_Hans_CN+en_US，其它语种进系统词典，禁止只译 en_US'],
            ],
            'verification_commands' => [
                'php bin/w i18n:collect Weline_Module',
                'php bin/w i18n:collect',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    '<?= __(\'...\') ?> or <?= __("...") ?> in .phtml HTML body or attributes',
                    '__() for user-visible strings directly echoed in templates',
                    'English (or non-Chinese) default source phrases in templates/menus/ACL for user-visible copy',
                    'Showing Chinese source under en_US / non-Chinese active or website-default locale because en_US translate is still Chinese placeholder',
                    'Rewriting template sources to English to “fix” English-locale display (keep Chinese sources; translate CSV/dictionary instead)',
                    'Unquoted commas inside @lang()/@lang{} source text (e.g. @lang{支持 .ico, .png} → ParseError)',
                    'Editing i18n/*.csv without running i18n:collect before claiming translation done',
                    'Shipping modules with missing en_US.csv or untranslated en_US rows for new strings',
                    'Creating or writing module i18n/{locale}.csv for locales other than zh_Hans_CN / en_US',
                ],
                'required' => [
                    'Use Simplified Chinese as the default source string in <lang>/@lang/menu/ACL/__()',
                    'When active/default locale is not Chinese, render that locale’s translation (never Chinese placeholder)',
                    'Use <lang>text</lang> or @lang(text) for frontend template copy',
                    'Source text with commas: use <lang>a, b</lang> or quoted @lang(\'a, b\') / @lang{"a, b"}',
                    '__() only in PHP logic layer, not HTML output',
                    'Maintain i18n/zh_Hans_CN.csv and i18n/en_US.csv with aligned Chinese source keys for every new phrase',
                    'Non-baseline locales: write system dictionary only (never extra module CSV files)',
                    'Run php bin/w i18n:collect {Module} after zh/en CSV edits or new translatable strings',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function moduleI18nCsvSurface(): array
    {
        return [
            'id' => self::SURFACE_MODULE_I18N_CSV,
            'label' => '模块翻译 CSV 门禁',
            'description' => '源串默认简体中文（正确）；活跃/默认 locale 非中文时须显示目标语译文；模块须维护齐全 zh_Hans_CN/en_US CSV（禁止其它 locale CSV）；用户提到翻译时须覆盖默认网站已选全部语言（非中英语种进系统词典）；en_US 第二列须为英文；改中英 CSV 后必须 i18n:collect。',
            'triggers' => [
                'csv', 'i18n:collect', 'en_us', 'zh_hans_cn', '翻译文件', '词典', 'collect',
                '国际化', 'locale', '语言包', '翻译', 'translate', '默认语言', '英文环境',
            ],
            'authoritative_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            'authoritative_docs' => [
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
            ],
            'norms' => [
                ['id' => 'chinese_source_default', 'summary' => '模板/菜单/ACL/__() 用户可见源串默认简体中文（正确）；禁止英文源串导致中英 CSV 串列'],
                ['id' => 'active_locale_must_show_target_language', 'summary' => '网站默认语或请求 locale 非中文时，界面必须显示该语种译文；禁止因源串是中文就在英文站露出中文；禁止 en_US 第二列中文占位'],
                ['id' => 'bilingual_csv_required', 'summary' => '每模块至少 zh_Hans_CN.csv + en_US.csv，source 键一致；禁止其它 locale CSV'],
                ['id' => 'en_us_real_english', 'summary' => 'en_US 第二列必须是英文译文，禁止留空或把中文 source 原样当作英文'],
                ['id' => 'user_mentions_translation_all_default_website_locales', 'summary' => '用户提到翻译任务时：解析默认网站 language_codes（本地 DB，website_id=0）并对每一语种写真实译文；模块 CSV 仅中英，其它进系统词典；禁止只译 en_US'],
                ['id' => 'frontend_backend_csv_sync', 'summary' => '前台加词须同步补后台 CSV 与 en 译文'],
                ['id' => 'collect_mandatory', 'summary' => 'CSV 处理后必须 i18n:collect，禁止只改文件不收集'],
            ],
            'verification_commands' => [
                'php bin/w i18n:collect Weline_Module',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Using English as default i18n source in templates/menus while expecting Chinese UI',
                    'Showing Chinese under en_US / non-Chinese website-default or request locale because translate column is still Chinese source',
                    'Rewriting template sources to English to fix English-locale display',
                    'Claiming i18n done after CSV edit without i18n:collect',
                    'Adding frontend <lang> strings without en_US.csv translation rows',
                    'Leaving en_US translate column as Chinese source (untranslated placeholder) for user-visible strings',
                    'Stopping at en_US when the user asked to 翻译 without narrowing locales',
                    'Creating or writing module i18n CSV for locales other than zh_Hans_CN / en_US',
                    'Replacing i18n:collect with cache:clear only',
                ],
                'required' => [
                    'Write Simplified Chinese source phrases in code; zh_Hans_CN identity + en_US English translate',
                    'When active/default locale is not Chinese, show that locale’s translation (never Chinese placeholder)',
                    'Align zh_Hans_CN.csv and en_US.csv source keys for every new phrase',
                    'Fill en_US second column with real English before claiming translation done',
                    'When the user mentions 翻译 as a task, cover every default-website selected language: zh/en in module CSV, others in system dictionary only',
                    'Run php bin/w i18n:collect after any zh/en CSV or translatable string change',
                    'Record collect/dictionary import in module doc/开发日志.md',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function moduleUpgradeGateSurface(): array
    {
        return [
            'id' => self::SURFACE_MODULE_UPGRADE,
            'label' => '模块版本与升级门禁',
            'description' => 'Model/Controller/注册表变更后必须 bump etc/module.php version 并跑 setup:upgrade。',
            'triggers' => [
                'model', '#[col]', '#[Col]', 'controller', 'setup:upgrade', 'module.php',
                'version', 'schema', '新建模型', '字段', '路由',
            ],
            'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
            'authoritative_docs' => [
                'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
                'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                'app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md',
            ],
            'norms' => [
                ['id' => 'bump_version_on_schema', 'summary' => 'Model #[Col]/#[Index]/新 Model → patch+ version + setup:upgrade'],
                ['id' => 'bump_version_on_controller', 'summary' => '新 Controller 方法 → version + setup:upgrade --route'],
                ['id' => 'module_version_bump_gate', 'summary' => 'Changes that touch Model/Controller/event.xml/hook.php/register.php MUST include etc/module.php version increase in the same change set, then run setup:upgrade'],
            ],
            'verification_commands' => [
                'php bin/w setup:upgrade -m Weline_Module',
                'php bin/w setup:upgrade --route --module=Weline_Module',
                'php bin/w setup:schema:check',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Changing Model schema attributes without bumping etc/module.php version',
                    'Adding Controller actions without route refresh (setup:upgrade --route)',
                    'Expecting upgrade() to run when module version unchanged',
                    'Changing Model/Controller/event/hook/register without bumping etc/module.php version in the same change set',
                ],
                'required' => [
                    'Bump etc/module.php version (at least patch) in the same change set as registration-affecting files',
                    'Run setup:upgrade or scoped -m / --route after registration-affecting changes',
                    'Record bumped version and command in module doc/开发日志.md',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function webuiBrowserCloseoutSurface(): array
    {
        return [
            'id' => self::SURFACE_WEBUI_BROWSER_CLOSEOUT,
            'label' => 'WebUI 浏览器验收与交付地址',
            'description' => '页面/UI 任务收口前必须用当前宿主可用的真实 Browser 按用例自测；每次打开/导航前禁用 HTTP 缓存；交付汇报末尾必须列「交付地址」；写完交付地址后立即关闭本回合验收 Browser；本机默认 Host 为 {project_hash}.test.weline.com，禁止主链 *.weline.test。',
            'triggers' => [
                'phtml', '页面', '后台', '前台', '验收', '交付', '完成', 'browser', 'webui',
                '交付地址', '自测', '用例', '截图', 'wls', 'ui', 'test.weline.com', 'weline.test',
                '关闭浏览器', 'close browser', 'webview', '缓存', 'cache', 'ignoreCache',
            ],
            'authoritative_skill' => 'local-browser-urls',
            'authoritative_doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            'authoritative_docs' => [
                'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'app/code/Weline/Ai/doc/AI硬规则索引.md',
            ],
            'norms' => [
                ['id' => 'wb_op_browser_self_test', 'summary' => 'AI 必须用当前宿主可用的真实 Browser 跑完约定用例；单测/curl 不能替代；不绑定 Cursor'],
                ['id' => 'browser_operator_non_preemptive', 'summary' => 'WB-OP 默认非抢占后台：Cursor browser_navigate 省略 position；禁止默认 position:active 抢 IDE 焦点；后台≠免测；仅用户要求观看时前台'],
                ['id' => 'browser_cache_disabled_on_open', 'summary' => '每次打开/导航验收 Browser 前禁用 HTTP 缓存（Cursor：Network.setCacheDisabled；失败则 Page.reload ignoreCache）；禁止用默认磁盘缓存验本回合静态资源'],
                ['id' => 'wb_vis_screenshots', 'summary' => '有视觉面且宿主可截图时：多断点截图存 doc/evidence/；有原型文档则对照'],
                ['id' => 'delivery_urls_section', 'summary' => '每次功能完成汇报末尾必须有「交付地址」小节（主链 http(s) Markdown）'],
                ['id' => 'delivery_default_host_test_weline_com', 'summary' => '本机主验收 Host 默认 {project_hash}.test.weline.com；禁止主链 *.weline.test；仅无前者时才用 127.0.0.1'],
                ['id' => 'browser_release_after_delivery', 'summary' => '写完「交付地址」后立即关闭本回合打开的验收 Browser（unlock + close tabs）；禁止留下空转 webview；用户明确要求保留除外'],
                ['id' => 'e2e_playwright_formal_runner_only', 'summary' => 'Playwright 仅 `php bin/w e2e:run` / `npx playwright test`；禁止 node -e / chromium.launch 探活残留 chrome-headless-shell'],
            ],
            'verification_commands' => [
                'curl -I <probe_verified_acceptance_url>',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Claiming Web/UI done without host-available real Browser operator self-test',
                    'Substituting unit tests or curl for Browser use-case execution',
                    'Omitting 「交付地址」 / Delivery URLs section from feature completion reports',
                    'Hard-coding Cursor-only Browser as the sole allowed acceptance tool',
                    'Using command:simpleBrowser (or other host-private schemes) as the sole/primary acceptance link',
                    'Asking the user to open pages instead of AI Browser self-test when a host Browser is available',
                    'Using *.weline.test as primary acceptance Host when *.test.weline.com is available',
                    'Forcing 127.0.0.1 when *.test.weline.com Host exists',
                    'Leaving acceptance Browser tabs/webviews open after Delivery URLs are reported',
                    'Opening acceptance Browser with default HTTP cache enabled when verifying this turn UI/static changes',
                    'Preemptive foreground Browser navigate (position:active) stealing IDE/chat focus without user request to watch',
                    'Ad-hoc node -e / chromium.launch Playwright probes instead of php bin/w e2e:run or npx playwright test',
                ],
                'required' => [
                    'Define operator use cases (URL, steps, expected) before claiming Web done',
                    'On every acceptance Browser open/navigate: prefer background non-preemptive navigate (omit position); disable HTTP cache (or ignoreCache reload fallback) before trusting the page',
                    'Run host-available real Browser on those use cases (WB-OP); collect WB-VIS when visual and screenshot-capable',
                    'End every feature/stage report with probe-verified http(s) Markdown Delivery URLs on {project_hash}.test.weline.com by default',
                    'Immediately after the Delivery URLs section, close every acceptance Browser tab opened this turn (Cursor: unlock then browser_tabs close)',
                    'If Browser not run or host has no Browser: report only “代码已改，WebUI 验收未完成”',
                    'When Playwright is needed: launch only via formal runner (php bin/w e2e:run or npx playwright test)',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function requirementClarifyUseCaseSurface(): array
    {
        return [
            'id' => self::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
            'label' => '需求澄清与用例规格',
            'description' => '编码前强制 Spec Kit/Kiro 式澄清：定向追问、落盘 doc/开发/spec/{slug}.md、用户故事+EARS 验收标准、用例（主成功/异常）映射 type=e2e 与 Browser 操作员路径；feature 须 status=ready-for-plan 再架构/写码。',
            'triggers' => [
                '需求澄清', '澄清需求', '用例规格', '用例设计', '写用例', 'EARS',
                'clarify', 'use case', 'use-case', 'speckit', 'kiro', '规格澄清',
                '用户故事', '验收标准', '非目标', '主成功路径',
            ],
            'authoritative_skill' => 'weline-req-clarify',
            'authoritative_doc' => 'dev/ai-command/ai/需求澄清与用例规格.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/需求澄清与用例规格.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'app/code/Weline/Ai/doc/AI硬规则索引.md',
            ],
            'norms' => [
                ['id' => 'clarify_before_code', 'summary' => '写码/架构落笔前完成澄清；一次最多 5 题；答案写入规格「澄清记录」'],
                ['id' => 'persist_spec_md', 'summary' => 'feature 必须落盘 owning-module doc/开发/spec/{feature-slug}.md'],
                ['id' => 'ears_acceptance', 'summary' => '每用户故事 ≥2 条 EARS（WHEN/IF…SHALL）可观察验收句'],
                ['id' => 'use_cases_feed_e2e', 'summary' => '≥1 用例含主成功步骤，可直接改写为 Playwright/Browser WB-OP 与 type=e2e'],
                ['id' => 'ready_for_plan_gate', 'summary' => 'status 升到 ready-for-plan 后才允许 architecture_design / 业务码'],
                ['id' => 'host_plan_mode_next', 'summary' => 'ready-for-plan 后立即启用宿主 Plan Mode（Cursor SwitchMode→plan）做架构与计划，批准实现后再切 agent'],
                ['id' => 'non_feature_skip_rationale', 'summary' => 'non_feature 可 clarify_status=skipped 且理由≥24 字'],
            ],
            'verification_commands' => [
                'test -f app/code/<Vendor>/<Module>/doc/开发/spec/<slug>.md',
                'rg -n "WHEN |IF |SHALL|UC-" app/code/<Vendor>/<Module>/doc/开发/spec/<slug>.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Jumping from a one-line user ask straight to PHP/phtml patches',
                    'Claiming requirements clear without a persisted doc/开发/spec/{slug}.md for feature work',
                    'Prose-only acceptance without EARS WHEN/IF…SHALL lines',
                    'Use cases that cannot become Playwright or Browser operator steps',
                    'Skipping clarify on feature without ready-for-plan status',
                ],
                'required' => [
                    'Read and follow dev/ai-command/ai/需求澄清与用例规格.md (or get_skill requirement_clarify_use_case)',
                    'For feature: write clarified/ready-for-plan spec with user stories, EARS, and UC mapped to e2e intent',
                    'Ask ≤5 targeted clarification questions per round; encode answers into the spec',
                    'After ready-for-plan: enable host Plan Mode (Cursor SwitchMode plan) before architecture_design / chapter plan',
                    'Only then proceed to requirement_framework_scrutiny and architecture_design (still in Plan Mode)',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function engineeringTeamSurface(): array
    {
        return [
            'id' => self::SURFACE_ENGINEERING_TEAM,
            'label' => '工程团队',
            'description' => '父会话自己选模式：简单用监工（监工:）；复杂进入 team——父会话仅 Team:项目经理:，每席必须真实子智能体（one_seat_one_agent），席间经 channel/{thread}.md + resume 互聊（peer_talk_via_channel）。禁止父会话扮演多席。框架优先、全专席双轨、组件协商、ui_prototype_gate_before_test（UI+原型先审过签才测→测试→PM汇审才汇报）。内容运营两种前缀都不用。',
            'triggers' => [
                '工程团队', '大型团队', '子智能体', '停工汇报', '技术方案会', '问题上报',
                '拉起项目经理', 'findings_wake_pm', '请立刻组队解决',
                'requirement_issuer_owns_acceptance', 'waiting_acceptance', 'issuer_acceptance', '甩手掌柜',
                'requirement_issuer_owns_acceptance', 'waiting_acceptance', 'issuer_acceptance', '甩手掌柜',
                '框架专席', '合规复审', '组件协商', '验收签收', '席间互聊', '一席一智能体',
                'engineering team', 'stop-work', 'dual track', 'peer talk', 'one seat one agent',
                'API席', 'API SDK', 'REST API 席',
                '部件开发工程师', '部件开发',
                '主题开发工程师', '主题开发',
                '支付开发工程师', '支付开发', '万能支付',
                '数据分析', '像素事件', 'Visitor像素', '访客事件',
                '电商顾问', '电商开发顾问', '运营策划', '电商合规', 'ecommerce advisor',
                '性能检查工程师', '性能检查', '性能优化', 'HotCache', 'N+1',
                '提示词优化工程师', '提示词优化', '技能压缩', '技能引用优化', '优化题词',
                '翻译工程师', '漏译', 'locale leak', '界面文案翻译',
            ],
            'authoritative_skill' => 'weline-engineering-team',
            'authoritative_doc' => 'dev/ai-command/ai/工程团队.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/工程团队.md',
                'dev/ai-command/ai/支付开发.md',
                'dev/ai-command/ai/主题开发.md',
                'dev/ai-command/ai/电商顾问.md',
                'dev/ai-command/ai/性能检查.md',
                'dev/ai-command/ai/提示词优化.md',
                'dev/ai-command/ai/翻译工程师.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'app/code/Weline/Ai/doc/AI硬规则索引.md',
                'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
                'app/code/Weline/Framework/doc/统一缓存范围与性能优化.md',
                'app/code/Weline/Theme/doc/部件开发指南.md',
                'app/code/Weline/Payment/doc/payment-shell.md',
                'app/code/Weline/Payment/doc/provider-development.md',
                'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
            ],
            'norms' => [
                ['id' => 'staff_before_code', 'summary' => '简单→监工（前缀监工:）；复杂→父会话选 team；父会话仅 Team:项目经理:'],
                ['id' => 'one_seat_one_agent', 'summary' => '每席真实宿主子智能体；禁止父会话换前缀扮演多席'],
                ['id' => 'peer_talk_via_channel', 'summary' => '席间经 channel/{thread}.md + resume 对端子智能体互聊；项目经理只做交换机'],
                ['id' => 'parent_is_pm', 'summary' => '父会话是项目经理；席位按波次上场，不全员同时开工；roster 登记 agent_id'],
                ['id' => 'framework_first', 'summary' => '需求/设计/施工/复审先映射框架机制与组件，再谈业务补丁'],
                ['id' => 'dual_track_all_specialty_seats', 'summary' => '凡触发专席一律施工轨+合规复审轨（电商顾问=顾问轨运营策划/领域决策+政策合规复审，禁止写码；性能检查=设计检查+实现复审；提示词优化=压缩施工+语义复审；翻译工程师=巡检译修+CSV/collect复审）；fail→返工，禁止带病进验收'],
                ['id' => 'framework_seats_by_trigger', 'summary' => '扩展点/事件/数据分析/查询/Taglib/Hook/Provider/翻译工程师(别名i18n)/ACL/Setup/电商顾问/API/部件开发工程师/主题开发工程师/支付开发工程师/性能检查工程师/提示词优化工程师按触发矩阵上场'],
                ['id' => 'ecommerce_advisor_required_for_commerce', 'summary' => '触及商品/目录/购物车/结账/订单/支付政策面/运费/站店渠/首页落地页运营设计/获客投放活动策划时必须 Team:电商顾问: 参与需求讨论与对齐冻结/技术方案会；运营策划禁写码；领域决策定稿后 escalate 通知 PM；须联网检索现行政策与运营要点'],
                ['id' => 'performance_engineer_required_for_hot_path', 'summary' => '触及热路径/缓存/列表目录搜索/N+1/慢请求时必须 Team:性能检查工程师: 参与需求讨论与对齐冻结/技术方案会，并在开发后复审；建议须落在框架缓存与批量约束内'],
                ['id' => 'prompt_engineer_required_for_skill_prompt', 'summary' => '触及技能引用/提示词骨架/席位镜/MCP skill·surface 文案或用户明示提示词优化时必须 Team:提示词优化工程师:；引用指针化+重复压缩+语义复审；禁削弱硬规则'],
                ['id' => 'translation_engineer_required_for_i18n', 'summary' => '新文案/改用户可见文案/i18n in_scope/漏译/界面流程翻译时必须 Team:翻译工程师:（别名 i18n）；模块 CSV 仅中英格式边界；其它已选 locale→词典/实体；禁「其它语种默认不做」'],
                ['id' => 'api_rest_in_owning_module', 'summary' => 'REST 必须落在资源归属 Vendor_Module（Website API→Weline_Websites；词典/翻译 API→Weline_I18n）；禁止跨模块代写 Rest'],
                ['id' => 'widget_work_assigns_widget_engineer', 'summary' => '凡部件/default_injections/布局内嵌vs注入/placement 相关施工必须分配部件开发工程师（双轨）；禁止前端/主题开发工程师席代写外国部件注入'],
                ['id' => 'theme_work_assigns_theme_engineer', 'summary' => '凡 Theme Token/layout壳/预览三态/app/design/Weline_Theme 自有相关施工必须分配主题开发工程师（双轨）；须声明 work_mode∈{default_theme,design_theme,theme_module_runtime}；禁止只派前端/UI 代写'],
                ['id' => 'payment_work_assigns_payment_engineer', 'summary' => '凡万能支付壳/新支付对接/退款退货资金面/Webhook/对账相关施工必须分配支付开发工程师（双轨）；禁止只派后端/通用 Provider 代写'],
                ['id' => 'visitor_work_assigns_data_analytics', 'summary' => '凡 Weline_Visitor/像素访客事件/Visitor 报表相关施工必须分配数据分析席（双轨）；禁止只派前端/后端代写；禁止与框架事件席混岗'],
                ['id' => 'seat_skill_mirrors_required', 'summary' => '每席必须粘贴 seat_skill_mirrors 专项提示并 get_skill/Read 本席技能与权威文档；禁止只靠通用骨架'],
                ['id' => 'component_reuse_or_negotiate', 'summary' => 'UI优先复用组件；不足须原型∥UI（±主题开发工程师）真实互聊写入 component-negotiate.md'],
                ['id' => 'surfaces_md_required', 'summary' => '复杂 team 强制 surfaces.md；UI in_scope 强制 components.md'],
                ['id' => 'acceptance_ui_and_prototype_signoff', 'summary' => '涉UI验收（ui_prototype_gate_before_test）：UI写acceptance-ui.md、原型写acceptance-prototype.md实质审查（可打回）；双 pass 前禁止测试执行；测试 pass→PM汇审才汇报；e2e绿不能代替'],
                ['id' => 'ui_prototype_gate_before_test', 'summary' => 'UI in_scope 时硬顺序：specialty_reviews_pass → ui_and_prototype_review_pass → tester_execution_pass → pm_huishen_pass → user_report_allowed；禁止测试与 UI/原型并行抢跑'],
                ['id' => 'parallel_only_when_disjoint', 'summary' => '仅文件/扩展点不重叠且接口已冻结时并行子智能体'],
                ['id' => 'escalate_then_meet', 'summary' => '跨轨或硬规则问题 result=escalate，专题会经通道互聊表态同意/异议/否决'],
                ['id' => 'findings_wake_pm', 'summary' => '发现问题立刻 escalate 拉起项目经理当场组队解决并落 SESSION 计划项；不用 Issue 任务列表积压'],
                ['id' => 'requirement_issuer_owns_acceptance', 'summary' => '发起方 escalate 后须 waiting_acceptance 盯验收；读 PM 进度写 issuer_acceptance；禁甩手；缺签收禁汇审'],
                ['id' => 'requirement_session_dashboard', 'summary' => '立项建 doc/开发/session/{slug}.md 总控；仅 PM 维护；无 SESSION 禁施工；未完成清单非空禁宣称完成'],
                ['id' => 'pm_plan_lifecycle', 'summary' => 'PM 计划生命周期：审缺口→派人→监控→notify_pm DoD 检查→测后复检→关计划项；deps 可并行；返工记 SESSION 循环'],
                ['id' => 'seat_closed_reports_related_web_urls', 'summary' => '席位 result=closed 回报必须填 related_web_urls；PM 汇审后向用户汇报须写「交付地址」汇总各席+测试探活地址（见 feature_delivery_urls / closeout_delivery_reminder）；纯逻辑写 N/A'],
                ['id' => 'stop_on_architecture_conflict', 'summary' => '无人能拍板或重大架构矛盾：停工汇报，确认前禁止 PHP/phtml/CSS'],
                ['id' => 'persist_team_minutes', 'summary' => '总控落盘 session/{slug}.md；明细落盘 team/{slug}/（含 roster+channel）；子智能体 closed 不是交付'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/工程团队.md',
                'test -f dev/ai-command/ai/templates/requirement-session.md',
                'test -f dev/ai-command/ai/支付开发.md',
                'test -f dev/ai-command/ai/主题开发.md',
                'test -f dev/ai-command/ai/电商顾问.md',
                'test -f dev/ai-command/ai/性能检查.md',
                'test -f dev/ai-command/ai/提示词优化.md',
                'test -f dev/ai-command/ai/翻译工程师.md',
                'rg -n "engineering_team_for_new_requirements" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "requirement_session_dashboard|pm_plan_lifecycle" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "findings_wake_pm" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "requirement_issuer_owns_acceptance" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "api_rest_in_owning_module" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "ecommerce_advisor_for_commerce|电商顾问" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "performance_engineer_for_design_and_review|性能检查工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "prompt_engineer_for_skill_prompt_work|提示词优化工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "payment_engineer_for_payment_work|支付开发工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "theme_engineer_for_theme_work|主题开发工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "analytics_engineer_for_visitor_work|数据分析" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "widget_work_assigns_widget_engineer|部件开发工程师" app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'rg -n "theme_work_assigns_theme_engineer|主题开发工程师" app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'rg -n "visitor_work_assigns_data_analytics|数据分析" app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'rg -n "one_seat_one_agent|peer_talk_via_channel|dual_track_all|acceptance-ui|ui_prototype_gate_before_test|component-negotiate|framework_first|seat_skill_mirrors|findings_wake_pm|requirement_issuer_owns_acceptance|waiting_acceptance|requirement_session_dashboard|pm_plan_lifecycle|notify_pm|API|部件开发工程师|主题开发工程师|支付开发工程师|电商顾问|数据分析|性能检查工程师|提示词优化工程师" dev/ai-command/ai/工程团队.md',
                'rg -n "ui_prototype_gate_before_test" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Staffing the large team on plan_complexity=simple or on content-ops (产品优化/新建文章)',
                    'Parent role-playing other seats by emitting Team:{席位}: without a real subagent',
                    'Inventing multi-seat dialogue instead of channel/{thread}.md + resume peers',
                    'Parallel edits to the same file or extension point before the contract is frozen',
                    'Treating a subagent closed/done report as delivery',
                    'Writing PHP/phtml/CSS after 停工 before the user confirms',
                    'Letting a subagent ask the user directly instead of a 停工汇报 or peer channel ask',
                    'Entering acceptance without per-triggered-seat 合规复审 pass',
                    'Handing off UI work with e2e green but without UI+原型 acceptance signoff',
                    'Tester e2e/WB execution before acceptance-ui.md + acceptance-prototype.md dual pass (ui_prototype_gate_before_test)',
                    'Reporting completion to user before PM 汇审 after Tester pass',
                    'Seat closed report omitting related_web_urls (or PM completion report omitting 交付地址)',
                    'Inventing parallel UI components without 原型∥UI component-negotiate.md',
                    'Launching a seat with only the generic prompt skeleton (missing seat_skill_mirrors increment / scoped skill docs)',
                    'Implementing Website/Store REST controllers under Weline_I18n (or any non-owning module)',
                    'Implementing I18n dictionary/translation REST under Weline_Websites (or any non-owning module)',
                    'Assigning widget/default_injections/placement work only to 前端/主题开发工程师 without staffing 部件开发工程师',
                    'Assigning Theme Token/layout壳/预览三态/app/design work only to 前端/UI without staffing 主题开发工程师',
                    'Assigning Payment shell/Provider/refund/webhook work only to 后端/Provider without staffing 支付开发工程师',
                    'Assigning Weline_Visitor / pixel / visitor-analytics work only to 前端/后端 without staffing 数据分析',
                    'Treating Visitor pixel/frontend events as framework Event seat (Team:事件:) work',
                    'Ecommerce-related complex team without staffing 电商顾问 for需求讨论/对齐冻结/技术方案会',
                    'Freezing UC/contracts on commerce work without 电商顾问 stance',
                    '电商顾问 writing PHP/phtml/CSS/config',
                    '电商顾问 domain decision「要开发什么」without escalate to 项目经理',
                    '电商顾问 bypassing PM to direct construction seats',
                    'Hot-path/cache/list/N+1 complex team without staffing 性能检查工程师 for design review and post-dev review',
                    'Freezing hot-path/cache UC/contracts without 性能检查工程师 stance',
                    'Skill-ref / prompt-skeleton / seat_skill_mirrors / MCP skill·surface wording work without staffing 提示词优化工程师',
                    'Pasting other skills’ full bodies as “references” or weakening hard-rule semantics while claiming prompt optimize pass',
                    'Recommending business-class parallel process caches outside HotCache/CachePolicy',
                    'Same-module layout <w:widget> plus JSON default_injections for the same widget (double render)',
                    'Cross-module layout inlining of foreign widgets via <w:widget>/fetch instead of owning-module default_injections',
                    'Finding problems (顾问/性能/提示词优化/安全) without escalate to wake 项目经理',
                    '项目经理 receiving escalate but not same-turn staffing seats to resolve',
                    'Using Issue task lists / boards instead of waking 项目经理 immediately',
                    'Issuer hands-off after escalate (closed without waiting_acceptance / ignoring PM progress / no issuer_acceptance)',
                    'PM skipping resume of issuer on progress milestones or claiming 汇审 complete without issuer_acceptance=pass',
                    '项目经理 skipping periodic Issue board check or claiming 汇审 complete with open P0/P1',
                ],
                'required' => [
                    'Read and follow dev/ai-command/ai/工程团队.md (or get_skill engineering_team)',
                    'Parent session acts as 项目经理 only; launch one real subagent per staffed seat; paste prompt skeleton + seat_skill_mirrors increment; register agent_id in roster.md',
                    'Each seat get_skill/Read its own mcp_skill_ids and authoritative_docs before coding or reviewing',
                    'Seat-to-seat talk via channel/{thread}.md + resume; PM switchboard only',
                    'Map requirements/design to framework mechanisms and components first',
                    'Run 施工 + 合规复审 dual track for every triggered specialty seat (电商顾问: 顾问轨运营策划/领域决策+政策合规复审, no code)',
                    'On Product/Catalog/Cart/Checkout/Order/Payment-policy/Shipping/站店渠/首页落地页运营设计/获客投放活动策划: staff Team:电商顾问:; ops research + web-search current policy notes; domain decision → escalate PM; no coding',
                    'On hot-path/cache/list/catalog/search/N+1/slow-request waves: staff Team:性能检查工程师:; get_skill(performance_check); MUST check performance; know framework+business; cache compliance; jointly customize optimization directions with Team:架构师:; design + post-dev review',
                    'On skill-ref / prompt-skeleton / seat_skill_mirrors / MCP skill·surface wording / duplicate compression (or user 提示词优化): staff Team:提示词优化工程师:; get_skill(prompt_optimization); refs=pointers; semantic review must not weaken hard rules',
                    'On REST/SDK change: staff API seat; place Rest controllers only under the owning Vendor_Module',
                    'On widget/default_injections/placement/slot-injection change: staff 部件开发工程师; obey theme_layout_widget_owner (same-module XOR; cross-module JSON only)',
                    'On Theme Token/layout壳/预览三态/app/design/Weline_Theme-owned shell change: staff 主题开发工程师; get_skill(theme_development|frontend_development); defer foreign injections to 部件开发工程师',
                    'On Payment shell/Provider/refund/webhook/reconcile/Payable money-path: staff 支付开发工程师; obey shell_provider_business_isomorph + payment_engineer_for_payment_work',
                    'On Weline_Visitor / pixel / visitor events / Visitor reports: staff Team:数据分析:; get_skill(visitor_data_analytics); distinguish from framework Event seat',
                    'On UI in_scope: components.md; negotiate before extending components; acceptance-ui.md + acceptance-prototype.md dual pass BEFORE Tester execution; Tester pass → PM 汇审 → then user report (ui_prototype_gate_before_test)',
                    'Escalate cross-track findings; hold a meeting before continuing that lane',
                    'On findings or finalized「要开发什么」from 电商顾问/性能检查/提示词优化/安全: escalate immediately with @项目经理：请立刻组队解决; PM same-turn staffs seats (findings_wake_pm)',
                    'After escalate: issuer stays waiting_acceptance; PM resumes issuer on milestones; issuer writes issuer_acceptance; FORBID 汇审 without pass (requirement_issuer_owns_acceptance)',
                    'Seat closed reports include related_web_urls; after PM 汇审 user completion report includes 交付地址 summarizing seat related_web_urls + tester probed URLs (feature_delivery_urls)',
                    'On major architecture contradiction: write doc/开发/team/{slug}/stop-work.md and wait',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function visitorDataAnalyticsSurface(): array
    {
        return [
            'id' => self::SURFACE_VISITOR_DATA_ANALYTICS,
            'label' => 'Visitor 数据分析 / 像素事件规范',
            'description' => '工程团队「数据分析」专席：整模块归属 Weline_Visitor（像素 runtime、事件字典/链、PixelEventVendor、沙盒监视、转化去重、本模块 event.xml/像素桥接 Observer、后台报表/看板）。专职前端访客/像素事件规约与正确运行；产品角色 ≠ 框架 Event 席（非绝对禁碰 event.xml）；配置范围 Website→Store→Channel，路径过滤 scope_json 不是站店渠。',
            'triggers' => [
                '数据分析', '像素事件', 'Visitor像素', '访客事件', 'Team:数据分析',
                'Weline_Visitor', 'WelinePixel', 'weline-pixel', 'pixel.js',
                'data-pixel-event', 'data-visitor-event', 'data-cta-event', 'data-ga-event',
                'PixelEventVendor', '事件链', 'event_chain', '沙盒监视', 'GtmBridge',
                'GTM', 'GA4', 'dataLayer', '转化去重', '访客统计', '像素报表',
            ],
            'authoritative_skill' => 'weline-visitor-analytics',
            'authoritative_doc' => 'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
            'authoritative_docs' => [
                'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
                'app/code/Weline/Visitor/doc/Visitor_Pixel_GTM_GA4_系统设计.md',
                'app/code/Weline/Visitor/doc/像素事件供应商管理-定稿合同.md',
                'app/code/Weline/Visitor/doc/event/事件链注册.md',
                'app/code/Weline/Visitor/doc/event/访客像素标签.md',
                'app/code/Weline/Visitor/doc/开发/spec/conversion-event-dedupe.md',
                'app/code/Weline/Visitor/doc/数据分析功能使用指南.md',
                'app/code/Weline/Websites/doc/store-saleschannel-scope.md',
                'app/code/Weline/Visitor/doc/ai/skills/visitor-data-analytics/SKILL.md',
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Taglib/doc/场景映射表.md',
                'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
                'dev/ai-command/ai/工程团队.md',
            ],
            'norms' => [
                ['id' => 'visitor_module_owns_seat', 'summary' => '整个 Weline_Visitor 模块归数据分析席施工（含报表后端与本模块 event.xml/像素桥接 Observer）；禁止只派前端/后端代写壳与 runtime'],
                ['id' => 'not_framework_event_seat', 'summary' => '产品角色 ≠ Team:事件:（通用 Framework Event）；本席拥有 Visitor 域 Event 契约与像素桥接 Observer；禁止产品混岗（事件席不写 pixel/Vendor/报表，本席不接管无关模块通用 Event）'],
                ['id' => 'frontend_skill_refs_required', 'summary' => '本席是开发席：开工前必须技能引用 get_skill(frontend_development)+get_skill(taglib_ui_control)；仅 Weline.Api.resource|graph|stream；Visitor 后台走主题 Token/Taglib'],
                ['id' => 'pixel_track_only', 'summary' => '采集只走 WelinePixel.track / weline-pixel:: / data-pixel-event|data-visitor-event|data-cta-event|data-ga-event；禁止业务旁路 dataLayer 或私接三方像素脚本；禁止用 Observer 替代采集主路径'],
                ['id' => 'gtm_ga4_mutex_dual_channel', 'summary' => 'GtmBridge 为唯一 GTM dataLayer 出口；开 GTM 必须关 GA4 直连防双计；page_view 不由 Pixel 重复 push'],
                ['id' => 'event_dictionary_and_chain', 'summary' => '改事件须动 event_dictionary.json；事件链 complete_event 须 __event_chain_complete；转化去重：sandbox.emit→占用→扇出；主 track 禁因去重 return null'],
                ['id' => 'scope_vs_path_filter', 'summary' => '配置范围=Website→Store→Channel 继承（<w:scope>）；路径过滤 scope_json 不是站店渠 Scope'],
                ['id' => 'vendor_extends_csp', 'summary' => '第三方对接写 Extends PixelEventVendorInterface（含 cspDirectives）；禁止在 Visitor 壳或 Framework Defaults 硬编码 SDK 域名'],
                ['id' => 'peer_frontend_ok', 'summary' => '本席须掌握前端技能；声明式埋点可 channel 请前端协助；复杂 runtime/Vendor/报表/本模块 UI 必须本席施工'],
                ['id' => 'peer_api_widget_for_reports', 'summary' => '跨模块 QueryProvider 变更 peer API 席；Dashboard/报表部件 peer 部件开发工程师'],
                ['id' => 'analytics_engineer_dual_track', 'summary' => 'Visitor/像素相关施工必须 Team:数据分析: 上场并跑施工+合规复审双轨'],
            ],
            'verification_commands' => [
                'test -f app/code/Weline/Visitor/doc/像素拓展使用指南.md',
                'test -f app/code/Weline/Visitor/doc/像素事件供应商管理-定稿合同.md',
                'test -f app/code/Weline/Visitor/doc/开发/spec/conversion-event-dedupe.md',
                'test -f app/code/Weline/Websites/doc/store-saleschannel-scope.md',
                'rg -n "analytics_engineer_for_visitor_work|数据分析" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "数据分析|visitor_data_analytics|frontend_skill_refs|禁止产品混岗" dev/ai-command/ai/工程团队.md',
                'rg -n "frontend_skill_refs_required|skill_refs|gtm_ga4_mutex" app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'rg -n "GtmBridge|skip_gtm_push|__event_chain_complete" app/code/Weline/Visitor',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Assigning Weline_Visitor / pixel / visitor-analytics work only to 前端/后端 without staffing 数据分析',
                    'Treating Visitor pixel events as framework Event (Team:事件:) work or letting Event seat own Visitor pixel Observers',
                    'Coding Visitor UI/pixel without get_skill(frontend_development) and get_skill(taglib_ui_control) skill refs',
                    'Bypassing WelinePixel with hand-rolled dataLayer.push or third-party pixel scripts in business modules',
                    'Confusing Website/Store/Channel scope with path_include/path_exclude scope_json path filters',
                    'Hardcoding GA/Meta/TikTok SDK domains in Visitor shell or Framework CSP Defaults instead of Vendor.cspDirectives()',
                    'Cross-module direct writes to Visitor Models bypassing published contracts',
                    'Using native fetch/ajax for Visitor admin/storefront business I/O instead of BinQuery',
                ],
                'required' => [
                    'get_skill(visitor_data_analytics|weline-visitor-analytics) + get_skill(frontend_development|weline-theme-development) + get_skill(taglib_ui_control|weline-taglib-first) before Visitor/pixel edits',
                    'Staff Team:数据分析: for Weline_Visitor / pixel / visitor event / Visitor report waves; dual-track 施工+合规复审',
                    'Use WelinePixel.track or declarative weline-pixel:: / data-pixel-event|data-visitor-event|data-cta-event|data-ga-event only; keep GTM/GA4 mutex and conversion dedupe order',
                    'Keep Website→Store→Channel scope distinct from path filters; use <w:scope> for config scope',
                    'Third-party fan-out via PixelEventVendor Extends + cspDirectives()',
                    'Peer-ask 前端 via channel when declarative markup help is needed; do not skip analytics seat',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function themeDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_THEME_DEVELOPMENT,
            'label' => '主题开发专席（薄）',
            'description' => '工程团队「主题开发工程师」专席薄表面：仅专席双轨、路径归属、work_mode 门禁、必装永远存在心智与前后端/部件边界；权威技能正文复用 frontend_development / weline-theme-development，禁止复制 frontend 全文。',
            'triggers' => [
                '主题开发工程师', '主题开发', 'Theme Token', 'CSS变量', 'variables/_',
                'layout壳', '版心', '预览三态', 'theme-preview', 'app/design',
                'Weline_Theme', 'theme_engineer_for_theme_work',
                '新主题', 'theme:create', 'theme:active', 'register.php', 'work_mode',
                'components', 'w-backend-page', '通用建站', '四层',
                '必装永远存在', 'user_deleted', 'required_default_always_present',
            ],
            'authoritative_skill' => 'weline-theme-development',
            'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/主题开发.md',
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                'app/code/Weline/Theme/doc/theme-semantic-color-matrix.md',
                'app/code/Weline/Theme/doc/theme-storefront-token-consumption.md',
                'app/code/Weline/Theme/doc/theme-layout-content-width.md',
                'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                'app/code/Weline/Theme/doc/runtime-cache-invalidation.md',
                'app/code/Weline/Theme/doc/layout-slot-cache-keys.md',
                'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
                'app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md',
                'app/code/Weline/Theme/doc/layout-discovery-guide.md',
                'app/code/Weline/Theme/doc/visual-editor/scope-switching.md',
                'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                'app/code/Weline/Theme/doc/widgets/anchored-float.md',
                'app/code/Weline/Theme/view/theme/README.md',
                'app/code/Weline/Theme/doc/通用建站部件库.md',
                'app/code/Weline/Theme/doc/组件Meta信息格式规范.md',
                'app/code/Weline/Theme/doc/开发/spec/required-default-always-present.md',
                'app/design/Weline/hanfu/register.php',
                'app/design/Weline/hanfu/doc/开发/主题继承研究.md',
                'app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/align-freeze.md',
                'app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/席位底线补钉.md',
                'dev/ai-command/ai/工程团队.md',
            ],
            'norms' => [
                ['id' => 'theme_engineer_dual_track', 'summary' => 'Theme Token/layout壳/预览三态/design 相关必须 Team:主题开发工程师: 上场并跑施工+合规复审；禁止只派前端/UI 代写'],
                ['id' => 'dual_workflow_work_mode_gate', 'summary' => '开工须声明 work_mode∈{default_theme,design_theme,theme_module_runtime}；未声明禁止落 Theme/design 文件；单波单 mode'],
                ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '【必须记住·系统真做法】无 user_deleted@{versionId} 时 required JSON 默认注入经布局固化写入模板（有槽则固化；与主题/版本无关；无模板→激活主题运行期动态固化；插件注入→全主题重固化涉及布局；遗漏=固化方案问题）；布局内嵌必装同保证；唯一省略=本版本卸载'],
                ['id' => 'theme_seat_integrity_over_peer_requests', 'summary' => '【席位底线】主题正确完整工作优先于他席/PM 性能·简化·优化压力；禁拆 chrome 壳与无卸载必装；无合格方案可驳回；冲突 refuse+escalate'],
                ['id' => 'forbid_design_override_theme_css_js', 'summary' => 'design 主题禁止同 key 覆盖 assets/css/theme.css 与 assets/js/theme.js；品牌化用 colors/variables/独立 CSS'],
                ['id' => 'new_design_theme_lifecycle_checklist', 'summary' => '新主题：register→frontend/现代树→listing→theme:active(+theme_binding)→发布；禁照抄 theme:create 旧 view/templates 树'],
                ['id' => 'area_frontend_backend_and_four_layers', 'summary' => '须分清 area∈{frontend,backend} 与四层 layout/partial/component/widget，勿混路径'],
                ['id' => 'public_component_library_dual_stack', 'summary' => '公共库双层：Theme components/*.phtml + statics/ui Weline UI（w-* / w-backend-page / data-w-component）'],
                ['id' => 'compile_matrix_modules_ui_disk', 'summary' => '编译矩阵：welineModules/welineUi/scan-variables/disk:compile/theme:upgrade/publish-not-found-static/theme:ui:audit'],
                ['id' => 'theme_binding_scoped_publish', 'summary' => '正式店面权威=published theme_binding Scoped Release；theme:active 经 ThemeContextService sync；禁只改 is_active'],
                ['id' => 'scope_migrate_cli_unimplemented', 'summary' => 'theme:scope:migrate 当前未实现；禁自写 SQL 猜删 scope 表；只走 Editor 规范 Scope+发布'],
                ['id' => 'runtime_cache_invalidation_ops', 'summary' => '发布后清 Scope 定向缓存；禁 typed Scope 下 clearNonGlobalCaches(null)；blocked 不清缓存；禁手写 @static?v='],
                ['id' => 'weline_api_and_js_declare_only', 'summary' => '站内仅 Weline.Api.*；Theme/部件 JS 仅 modules+data-weline-load/declare；禁 fetch/@static 拉 JS'],
                ['id' => 'editor_dual_preview_parity', 'summary' => '画布禁 start-preview/种Token；仅 #btnFrontendPreview 可 Token；禁 preview early-return（交付同构）'],
                ['id' => 'layout_type_slash_nested', 'summary' => 'layoutType 斜杠嵌套（account/login）；禁 account.login 点号混用'],
                ['id' => 'structure_cache_keys_no_lang', 'summary' => '结构缓存键排除 lang/currency/request_id；呈现 HTML 另键'],
                ['id' => 'semantic_color_matrix_inherit', 'summary' => '语义色：_light→_default→品牌→_dark；品牌禁删 secondary/status；度量用 space/radius/font-size Token；禁裸 px'],
                ['id' => 'frontend_section_weline_code', 'summary' => '前台 section / w:slot wrapper=section 必须非空 weline-code；改后 frontend:check-section-code'],
                ['id' => 'generated_and_factory_reset_guard', 'summary' => '【慎重·清库级】Factory Reset 仅绿场+显式授权+精确 RESET+先备份DB；生产禁；勿当清缓存；禁改 generated/view/tpl；禁自写SQL删scope'],
                ['id' => 'path_ownership_vs_frontend', 'summary' => 'Token/variables/_*.css/版心壳/预览三态/app/design/Weline_Theme 自有归本席；业务 templates/业务JS/Taglib/中英CSV 归前端'],
                ['id' => 'defer_widget_engineer', 'summary' => '外国 default_injections/placement XOR/跨模块空槽 defer Team:部件开发工程师:；theme_layout_widget_owner 不变'],
                ['id' => 'token_only_no_private_palette', 'summary' => '禁止发明私有 hex/rgb 调色盘或平行 spacing/radius；基础组件只消费主题语义 Token'],
                ['id' => 'preview_three_modes', 'summary' => '预览三态与店面同构；禁止为 preview/editor 抽空真实店面逻辑或混淆三态身份权威'],
                ['id' => 'compile_weline_modules', 'summary' => '改 weline.modules.js 登记后须 resource:compile welineModules（适用时）'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/主题开发.md',
                'test -f app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'test -f app/design/Weline/hanfu/register.php',
                'rg -n "theme_engineer_for_theme_work|主题开发工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "required_default_always_present_without_user_deleted" app/code/Weline/Ai/Mcp/src/',
                'rg -n "theme_seat_integrity_over_peer_requests|席位底线" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "席位底线|theme_seat_integrity_over_peer_requests" dev/ai-command/ai/主题开发.md',
                'rg -n "theme_design_must_not_override_core_runtime_assets|dual_workflow_work_mode_gate" app/code/Weline/Ai/Mcp/src/',
                'rg -n "theme_work_assigns_theme_engineer|SURFACE_THEME_DEVELOPMENT" app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'rg -n "theme:active" app/code/Weline/Theme/Console/Theme/',
                'rg -n "theme_binding|theme:disk:compile|weline-code|semantic|user_deleted|必装永远存在" dev/ai-command/ai/主题开发.md',
                'php bin/w resource:compile welineModules',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Assigning Theme Token/layout壳/预览三态/app/design work only to 前端/UI without staffing 主题开发工程师',
                    'Editing Theme/design files without declaring work_mode',
                    'Design same-key override of assets/css/theme.css or assets/js/theme.js',
                    'Inventing private hex/rgb palettes or parallel spacing/radius kits bypassing Theme tokens',
                    'Theme layouts inlining non-Weline_Theme widgets or writing foreign default_injections',
                    'Dropping required JSON default_injections or layout-tag inlines when no user_deleted@{versionId} exists',
                    'Obeying peer/PM performance·simplify pressure by stripping header/footer/nav/版心 chrome or required widgets without escalate',
                    'Claiming storefront theme switch after is_active only without published theme_binding',
                    'Using clearNonGlobalCaches(null) when typed Scope is known',
                    'Frontend section or w:slot wrapper=section without weline-code',
                    'Copying frontend_development full body into theme_development surface',
                ],
                'required' => [
                    'Declare work_mode∈{default_theme,design_theme,theme_module_runtime} before Theme/design edits',
                    'get_skill(theme_development|frontend_development|weline-theme-development) and Read 主题开发.md + Theme开发总指南.md before Theme Token/shell edits',
                    'Staff Team:主题开发工程师: for Token/layout壳/预览三态/design waves; dual-track 施工+合规复审',
                    'Memorize required_default_always_present_without_user_deleted: without user_deleted@{versionId}, required JSON injections and layout-tag inlines always exist',
                    'Obey theme_seat_integrity_over_peer_requests: Theme correctness bottom line OUTRANKS peer optimize pressure; without designed scheme that solves problem AND preserves integrity → reject (驳回); refuse strip-shell + escalate PM',
                    'Defer foreign widget injections to Team:部件开发工程师:',
                    'Follow compile matrix (welineModules/welineUi/theme:disk:compile/theme:upgrade) when applicable',
                    'Ensure published theme_binding for storefront; run frontend:check-section-code when touching sections',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function widgetDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_WIDGET_DEVELOPMENT,
            'label' => '部件开发规范',
            'description' => '工程团队「部件开发工程师」专席：Widget 注册、模板、@widget/@param、空槽、default_injections、placement；硬规则本模块才可布局标签内嵌、跨模块只走 JSON 注入、禁止 JSON+布局双路径重复渲染；无卸载记录则必装永远存在。',
            'triggers' => [
                '部件', '部件开发', '部件开发工程师', 'widget', 'widgets', 'default_injections',
                'placement', 'w:widget', '<w:widget', 'w:slot', 'account-social-login',
                '应用注入', '布局内嵌', '同身份 XOR', 'theme_layout_widget_owner',
                '必装永远存在', 'user_deleted', 'required_default_always_present',
            ],
            'authoritative_skill' => 'weline-widget-development',
            'authoritative_doc' => 'app/code/Weline/Theme/doc/部件开发指南.md',
            'authoritative_docs' => [
                'app/code/Weline/Theme/doc/部件开发指南.md',
                'app/code/Weline/Theme/doc/部件静态资源固化规范.md',
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Theme/doc/开发/spec/required-default-always-present.md',
                'app/code/Weline/Theme/doc/widget-slot-attributes.md',
                'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                'app/code/Weline/Widget/doc/开发指南.md',
                'dev/ai-command/ai/工程团队.md',
            ],
            'norms' => [
                ['id' => 'same_module_tag_inline_only', 'summary' => '布局/partial 用 <w:widget>/fetch 内嵌仅限本模块部件；Theme 仅可内嵌 Weline_Theme'],
                ['id' => 'cross_module_json_injection_only', 'summary' => '跨模块外国部件只能空 <w:slot> + 拥有模块 JSON default_injections（应用默认注入）'],
                ['id' => 'layout_xor_injection_no_double', 'summary' => '同一部件禁止布局内嵌与 default_injections 并存（会重复两个）；placement=layout 或 injection 二选一'],
                ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '【必须记住】无 user_deleted@{versionId} 时 required JSON 默认注入经布局固化写入模板；遗漏=固化方案问题；唯一省略=本版本卸载'],
                ['id' => 'widget_engineer_dual_track', 'summary' => '部件相关施工必须 Team:部件开发工程师: 上场并跑施工+合规复审；禁止前端/主题开发工程师席代写外国注入'],
                ['id' => 'widget_js_weline_modules', 'summary' => '业务模块 JS：weline.modules.js + data-weline-load；改登记后 resource:compile welineModules；禁止所有内联 CSS/可执行 JS（含 style= 与 on*=）'],
                ['id' => 'widget_static_assets_bake_to_head', 'summary' => '所有部件 CSS/可执行 JS 禁止内联（含 style= 与 on*=）；layout-source/source 或 @widget 元数据进入固化闭包；layout-source 固定提前 head；source 按 source-postion（优先）或 source-position 分发，内部 source_position，默认 head；body/end-body 在结束 body 前，footer 在结束 footer 前（无 footer 落 body 末尾）；同 URL 去重且 layout 优先，普通 source 多位置按 head→footer→body 优先。实例数据走 data 属性。'],
                ['id' => 'widget_gates', 'summary' => '改后跑 frontend:check-theme-layout-widgets 与（适用时）frontend:check-required-injection-sibling-fetch'],
            ],
            'verification_commands' => [
                'php bin/w frontend:check-theme-layout-widgets',
                'php bin/w frontend:check-required-injection-sibling-fetch',
                'php bin/w resource:compile welineModules',
                'test -f app/code/Weline/Theme/doc/部件开发指南.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Inlining another module\'s widget via <w:widget> or fetch(.../widgets/...) inside layouts/partials',
                    'Declaring default_injections for a widget already layout-inlined in the same module (double path)',
                    'Sibling fetch of the same required-injection widget beside an empty slot',
                    'Runtime presence/count dedupe patches instead of XOR placement',
                    'Dropping required JSON default_injections when no user_deleted@{versionId} exists',
                    'Theme layouts inlining non-Weline_Theme widgets',
                    'Widget templates with any inline CSS or executable JS, including style= and on*= attributes',
                ],
                'required' => [
                    'get_skill(widget_development|weline-widget-development) or Read 部件开发指南.md before widget edits',
                    'Staff Team:部件开发工程师: for widget/default_injections/placement waves; dual-track 施工+合规复审',
                    'Same-module: choose placement=layout XOR injection; never both',
                    'Cross-module: empty slot + owning-module default_injections only',
                    'Without user_deleted@{versionId}, required default_injections always exist on storefront',
                    'All widget CSS/executable JS external via layout-source/source; obey widget_static_assets_bake_to_head position contract; forbid all inline CSS/executable JS, style= and on*=',
                    'Verify with frontend:check-theme-layout-widgets (+ sibling-fetch gate when applicable)',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function apiSdkDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_API_SDK_DEVELOPMENT,
            'label' => 'API 开发规范（REST + BinQuery）',
            'description' => '工程团队 API 席：遵循已冻架构 align-freeze（Query 核+入口壳+暴露面权限=契约硬要件）。可复用 I/O=QueryProvider；薄 REST=#[Acl]+w_query；写了 Provider≠冰块可调（frontend 必 auth；后台必 backend_acl）。',
            'triggers' => [
                'REST', 'Rest', 'Api/Rest', 'AbstractRestController', 'BackendRestController', 'FrontendRestController',
                'API接口', 'API 开发', 'API开发', 'API SDK', 'API席', 'Team:API',
                'BinQuery', 'QueryProvider', 'query-bin', '/bin/query', 'binquery', 'w_query',
                'openapi', 'SDK使用指南', '第三方 SDK', '远程 API', 'rest/v1',
            ],
            'authoritative_skill' => 'weline-api-sdk',
            'authoritative_doc' => 'app/code/Weline/Ai/doc/开发/team/api-seat-charter/meetings/align-freeze.md',
            'authoritative_docs' => [
                'app/code/Weline/Ai/doc/开发/team/api-seat-charter/meetings/align-freeze.md',
                'app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md',
                'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
                'app/code/Weline/Framework/doc/BinQuery/README.md',
                'app/code/Weline/Framework/doc/BinQuery/协议对接指南.md',
                'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
                'app/code/Weline/Api/doc/framework-api-and-auth-contract.md',
                'dev/ai-command/ai/工程团队.md',
            ],
            'norms' => [
                ['id' => 'api_seat_scope_rest_or_binquery', 'summary' => 'API 席只做 REST 或 BinQuery/QueryProvider；不写 Theme/phtml，不抢后端 Service 内核'],
                ['id' => 'api_shared_query_core', 'summary' => '可复用 I/O 契约只落 QueryProvider；query-bin|/bin/query|薄 REST 为入口壳；薄 REST 默认 #[Acl]+w_query，禁止壳内重写业务'],
                ['id' => 'api_rest_in_owning_module', 'summary' => 'REST 控制器与路由必须在资源归属 Vendor_Module 的 Api/Rest 下；禁止跨模块代写 Rest'],
                ['id' => 'rest_document_acl', 'summary' => '继承 AbstractRestController 系；方法完整 @Document/@example/@param/@return；后台 REST 必须 #[Acl]；setup:upgrade 会拦截不合规接口'],
                ['id' => 'binquery_provider_attribute_compile', 'summary' => 'QueryProvider 用 BinQueryOperation/Param 等 Attribute；descriptor 纯标量；改后 framework:compile；须 query:help 可发现'],
                ['id' => 'binquery_external_flags', 'summary' => '站外 /bin/query 可见须 external=true（frontend 区域另需 frontend=true）；写操作 mode=write 不进 CDN/graph'],
                ['id' => 'api_per_entry_permission_matrix', 'summary' => '每入口独立门：REST→#[Acl]；query-bin→auth+backend_acl；/bin/query→external+API Key scope；CDN 仅 read+external+cdn+public；禁止跨入口旁路'],
                ['id' => 'api_seat_dual_track', 'summary' => '改 Rest/BinQuery/SDK 契约时工程团队必须上场 API 席并跑施工+合规复审双轨'],
                ['id' => 'sdk_vs_binquery_boundary', 'summary' => '站内浏览器业务 I/O 走 BinQuery（Weline.Api→query-bin）；对外 HTTP/第三方优先薄 REST over Query；禁止用 native fetch 冒充站内 SDK'],
                ['id' => 'api_docs_must_update', 'summary' => '新增/变更 REST 或对外 operation 必须同步 owning-module API/REST 文档与 @Document/examples；禁止只改代码不更文档'],
            ],
            'verification_commands' => [
                'rg -n "api_rest_in_owning_module" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "共用 Query 核|w_query|权限矩阵" app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
                'test -f app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
                'test -f app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md',
                'rg -n "API|BinQuery|AbstractRestController|w_query" dev/ai-command/ai/工程团队.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Placing another module\'s REST under a foreign Vendor_Module (e.g. Website list/languages REST inside Weline_I18n)',
                    'REST methods missing @Document / @example / Acl on backend Rest',
                    'Shipping REST without updating owning-module API docs',
                    'Using browser native fetch/ajax for storefront business I/O instead of BinQuery',
                    'Implementing QueryProvider/BinQuery operations without API seat skill mirror / without framework:compile',
                    'Cross-module Model reads inside QueryProvider instead of Interface/owning boundary',
                    'Re-implementing Provider business inside Rest/gateway shells instead of w_query',
                    'Exposing same op via REST with Acl but query-bin without backend_acl (permission bypass)',
                    'Marking write ops external=true without valid API Key scope, or CDN-public on non-public reads',
                ],
                'required' => [
                    'get_skill(api_sdk_development|weline-api-sdk) and Read API接口开发规范.md + BinQuery Provider开发指南 before Rest/BinQuery edits',
                    'Put reusable I/O in QueryProvider; prefer thin REST = Acl + w_query; document per-entry permission matrix',
                    'Choose REST (external HTTP/SDK) vs BinQuery (first-party browser/worker) explicitly',
                    'Put Rest controllers only under the owning module Api/Rest path',
                    'Staff Team:API: for Rest/BinQuery waves; dual-track 施工+合规复审',
                    'After QueryProvider changes: php bin/w framework:compile and query:help smoke',
                    'Update owning-module REST/API documentation in the same change set',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function ecommerceAdvisorSurface(): array
    {
        return [
            'id' => self::SURFACE_ECOMMERCE_ADVISOR,
            'label' => '电商顾问（运营策划，禁止写码）',
            'description' => '工程团队「电商顾问」专席（运营策划；合并原合规席）：领域决策「要开发什么」+活动/增长策划+需求合理性；定稿后 escalate 通知项目经理拉队技术讨论；对照 Product/Catalog/Cart/Checkout/Order/Payment/Shipping 模组能力纠偏；日常联网研究运营；联网检索现行政策/合规做风控。HARD：禁止写码；电商相关复杂 team 必须上场；对齐冻结会/技术方案会未表态不得冻结；禁止绕过 PM 指挥技术席。',
            'triggers' => [
                '电商顾问', '电商开发顾问', '运营策划', '独立站运营', '活动策划',
                '电商合规', '商品合规', '结账合规', '站店渠',
                '跨境电商政策', '消费者权益', '广告法', '虚假宣传',
                'ecommerce advisor', 'Team:电商顾问',
                'Weline_Product', 'Weline_Cart', 'Weline_Checkout', 'Weline_Order', 'Weline_Shipping',
                '购物车', '结账', '退换货政策',
                '首页怎么设计', '获客', '投放', '网红引流', 'KOL',
            ],
            'authoritative_skill' => 'weline-ecommerce-advisor',
            'authoritative_doc' => 'dev/ai-command/ai/电商顾问.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/电商顾问.md',
                'dev/ai-command/ai/工程团队.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'app/code/Weline/Product/doc/AI-INDEX.md',
                'app/code/Weline/Checkout/doc/AI-INDEX.md',
                'app/code/Weline/Cart/doc/README.md',
                'app/code/Weline/Order/doc/AI-INDEX.md',
                'app/code/Weline/Payment/doc/payment-shell.md',
                'app/code/Weline/Shipping/doc/功能现状.md',
                'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
            'norms' => [
                ['id' => 'advisor_no_code', 'summary' => '电商顾问禁止改 PHP/phtml/CSS/JS/XML/JSON 配置；paths_changed 必须为无'],
                ['id' => 'advisor_ops_planner', 'summary' => '人设=运营策划：活动/增长/获客/投放/KOL/产品信息策略与领域决策；日常联网研究运营文章'],
                ['id' => 'advisor_domain_decide_wake_pm', 'summary' => '领域决策「要开发什么」+成功标准定稿后必须 escalate 通知项目经理同回合组队技术讨论；禁止绕过 PM 指挥技术席'],
                ['id' => 'advisor_mandatory_on_commerce', 'summary' => '触及商品/目录/购物车/结账/订单/支付政策面/运费/站店渠/首页落地页运营设计/获客投放活动策划的复杂 team 必须 Team:电商顾问: 上场'],
                ['id' => 'advisor_required_meetings', 'summary' => '立项讨论、对齐冻结会、技术方案会必到；未表态不得冻结 UC/contracts/机制'],
                ['id' => 'advisor_dual_track', 'summary' => '顾问轨（运营策划/领域决策/需求合理性/模组纠偏/设计 brief）+ 政策与合规复审轨；复审产物 meetings/电商顾问-review.md'],
                ['id' => 'advisor_supported_countries_first', 'summary' => '先本机解析已支持国家（Shipping getCountries/支付支持国/需求子集），再分国查政策；禁止固定只查中美'],
                ['id' => 'advisor_web_policy_research', 'summary' => '每次参会表态前必须对相关国家联网检索；纪要写 supported_countries+来源日期；高风险 escalate 或停工'],
                ['id' => 'advisor_storefront_compliance_copy_surfaces', 'summary' => '站内合规巡检须含政策页、顶栏宣称、FAQ Hub/实体、Cookie/隐私 chrome；改可见串 escalate 时 suggested_seats 须含翻译工程师'],
                ['id' => 'advisor_ops_research', 'summary' => '参与讨论或发起要开发什么前须当期 WebSearch 相关运营主题；纪要带来源 URL+日期'],
                ['id' => 'advisor_not_content_ops', 'summary' => '产品优化/详情/主图/翻译等 content_ops 执行不拉本席代跑；本席只给策略与验收标准'],
                ['id' => 'advisor_vs_payment_engineer', 'summary' => '支付实现归支付开发工程师；本席只审政策表述与业务/运营逻辑可上线性'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/电商顾问.md',
                'rg -n "ecommerce_advisor_for_commerce|电商顾问" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "电商顾问|ecommerce_advisor|运营策划" dev/ai-command/ai/工程团队.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    '电商顾问 writing production or config code',
                    'Freezing commerce UC/contracts without 电商顾问 stance',
                    'Claiming no compliance risk without web policy research citations keyed by supported countries',
                    'Using a fixed CN/US-only policy checklist instead of site-supported countries',
                    'Skipping 电商顾问 on Product/Cart/Checkout/Order/Payment-policy/Shipping waves',
                    'Routing content-ops execution (产品优化/详情优化) through Team:电商顾问 as runner',
                    'Domain decision「要开发什么」finalized without escalate to 项目经理',
                    '电商顾问 bypassing PM to direct construction seats',
                    'Compliance remediation of user-visible strings without 翻译工程师 in suggested_seats',
                    'Skipping FAQ Hub / policy / marketing chrome from storefront compliance checklist',
                ],
                'required' => [
                    'get_skill(ecommerce_advisor|weline-ecommerce-advisor) and Read 电商顾问.md before advising',
                    'Staff Team:电商顾问: on commerce-related complex team from 立项波',
                    'Attend align-freeze and tech-scheme; record stance + supported_countries + policy_notes (+ ops_notes/design_brief/dev_ask when applicable)',
                    'Resolve site-supported countries then WebSearch per relevant country before each discussion stance',
                    'WebSearch relevant ops topics before domain decisions; cite sources+date',
                    'Storefront compliance checklist includes policy pages, top-bar claims, FAQ Hub/entity, Cookie/privacy chrome',
                    'On user-visible-string remediations: suggested_seats MUST include 翻译工程师',
                    'On finalized「要开发什么」or findings: escalate @项目经理：请立刻组队解决 with suggested_seats',
                    'Write meetings/电商顾问-review.md with pass/fail; fail blocks acceptance',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function paymentDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_PAYMENT_DEVELOPMENT,
            'label' => '万能支付开发规范',
            'description' => '工程团队「支付开发工程师」专席：万能支付壳（Weline_Payment）编排边界、Extends Provider 对接新支付方式、退款/部分退款/退货资金面、Webhook/浏览器 return、对账、Connect/OAuth、Payable 接入；硬规则业务只进 Provider、统一 URL、幂等、CSP/PCI/密钥安全；HARD 验收闭环：改完必须拉起测试席用真浏览器过触及支付全流程才过手。',
            'triggers' => [
                '支付', '支付开发', '支付开发工程师', '万能支付', 'payment', 'Payment',
                'PaymentProvider', 'method_code', '退款', '部分退款', '退货退款', 'refund',
                'Webhook', 'webhook', '对账', 'reconcile', 'Payable', 'createPayment',
                'resumePayment', 'shell_token', 'payment-shell', 'provider-development',
                'Team:支付开发工程师', '支付方式对接', '对接支付', '支付模块',
            ],
            'authoritative_skill' => 'weline-payment-development',
            'authoritative_doc' => 'app/code/Weline/Payment/doc/payment-shell.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/支付开发.md',
                'app/code/Weline/Payment/doc/payment-shell.md',
                'app/code/Weline/Payment/doc/provider-development.md',
                'app/code/Weline/Payment/doc/webhook.md',
                'app/code/Weline/Payment/doc/payment-state.md',
                'app/code/Weline/Payment/doc/payment-reconcile.md',
                'app/code/Weline/Payment/doc/extends.md',
                'app/code/Weline/Payment/doc/facade-v2.md',
                'app/code/Weline/Framework/doc/3-开发/安全响应头策略.md',
                'dev/ai-command/ai/工程团队.md',
            ],
            'norms' => [
                ['id' => 'shell_orchestrates_provider_implements', 'summary' => '壳只编排（URL/状态机/Inbox/配置托管/幂等）；渠道业务只在 Extends Provider（shell_provider_business_isomorph）'],
                ['id' => 'new_method_minimal_delivery', 'summary' => '对接新支付=Provider + SystemConfig 模板（+ checkout 模板/CustomerGuide）；三 code 一致；禁止按渠道分裂壳 Controller'],
                ['id' => 'unified_callback_urls', 'summary' => '统一 browser return/cancel/webhook URL；运行时须 shell_token 或显式 method_code+transaction_no；禁止手拼或按网关另开 Return URL'],
                ['id' => 'refund_return_money_path', 'summary' => '全额/部分/多次退款与退货资金面经 Provider.refund + 壳 Refund/Ledger；稳定 refund_code/idempotency；禁止绕过 Payment 锁'],
                ['id' => 'webhook_pure_verify_parse', 'summary' => 'verifyCallback/parseCallback 纯函数：不写单、不发队列、不调远端；先 Inbox 再消费 CAS'],
                ['id' => 'payment_security_pci_csp_secrets', 'summary' => '壳不碰卡号；CSP 由 Provider.cspDirectives 自报；密钥走 SystemConfig 加密字段；测连脱敏；禁止硬编码网关域名/密钥'],
                ['id' => 'capabilities_hard_ceiling', 'summary' => 'getCapabilities 为硬上限；金额用 amount_minor；不支持的退款/捕获须明确 unsupported'],
                ['id' => 'payment_engineer_dual_track', 'summary' => '支付相关施工必须 Team:支付开发工程师: 上场并跑施工+合规复审；后端/通用 Provider 席不得代写支付域'],
                ['id' => 'payment_acceptance_real_pathway', 'summary' => '验收须真实支付/取消/失败/退款通路证据（transaction_no/order_uuid）；禁止壳层冒烟冒充实交'],
                ['id' => 'payment_browser_e2e_closed_loop', 'summary' => '代码改完必须拉起 Team:测试: 用宿主真 Browser（WB-OP）过触及支付方式全流程；仅 Browser pass+证据齐全本席才可 review pass；禁止只改代码/单测冒充实浏览器'],
            ],
            'verification_commands' => [
                'test -f app/code/Weline/Payment/doc/payment-shell.md',
                'test -f app/code/Weline/Payment/doc/provider-development.md',
                'test -f dev/ai-command/ai/支付开发.md',
                'rg -n "shell_provider_business_isomorph|payment_engineer_for_payment_work|payment_browser_e2e_closed_loop" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
                'rg -n "支付开发工程师|payment_development|真浏览器|拉起.*测试" dev/ai-command/ai/工程团队.md dev/ai-command/ai/支付开发.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Reimplementing a specific gateway create/refund/callback/capabilities inside Payment shell Controllers/Services',
                    'Splitting per-vendor business Controllers instead of one Extends Provider',
                    'Hand-rolling per-gateway Developer Return URLs or inventing callback routes outside PaymentShellCallbackUrlCatalog',
                    'Touching card PAN/CVV in shell or storefront templates (PCI boundary)',
                    'Hardcoding gateway script/frame/connect domains in Framework SecurityHeaderDefaults instead of Provider.cspDirectives()',
                    'Storing API keys/webhook secrets in plain EAV or committed env samples',
                    'verifyCallback/parseCallback writing Payment/Order/Inventory or calling remote APIs',
                    'Refund/return money movement bypassing Provider.refund + Payment ledger/locks',
                    'Assigning Payment work only to 后端/Provider without staffing 支付开发工程师',
                    'Claiming payment done on shell-only smoke without real pathway evidence',
                    'Marking payment review/pass after code-only changes without waking Team:测试: for real Browser full method pathway',
                    'Substituting PHPUnit/curl/shell-smoke for host real Browser WB-OP payment flow',
                ],
                'required' => [
                    'get_skill(payment_development|weline-payment-development) and Read payment-shell.md + provider-development.md (+ webhook.md when callbacks) before payment edits',
                    'Staff Team:支付开发工程师: for Payment shell/Provider/refund/webhook/reconcile/Payable money-path waves; dual-track 施工+合规复审',
                    'New method docking: Extends Provider + SystemConfig backend/{method_code}.phtml (+ checkout template); codes aligned',
                    'Obey shell_provider_business_isomorph: shell orchestrates, Provider owns gateway lifecycle',
                    'Unified callback URLs with shell_token; Inbox + CAS; amount_minor integers',
                    'CSP via Provider.cspDirectives(); secrets encrypted in SystemConfig; no card data in shell',
                    'After payment code edits: wake Team:测试: for host real Browser WB-OP full pathway of each touched method (select→submit→success/fail/cancel; refund/Webhook when in-scope); review pass only with Browser pass + transaction_no/order_uuid',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function performanceCheckSurface(): array
    {
        return [
            'id' => self::SURFACE_PERFORMANCE_CHECK,
            'label' => '性能检查（设计审查 + 开发后复审）',
            'description' => '工程团队「性能检查工程师」专席：必须检查性能。须深懂 Weline 框架结构与业务特性；审查 HotCache/CachePolicy 缓存设计是否合规；与架构师共同讨论并定制优化方向；开发后用真实证据复审。HARD：未弄清框架+业务特性禁止开药方；建议必须落在框架机制内；禁止把拆 chrome 壳/删必装部件当优化方向（theme_seat_integrity_over_peer_requests）；默认可写只读探针，业务返工交归属席；热路径/缓存相关复杂 team 必须上场。',
            'triggers' => [
                '性能检查', '性能检查工程师', '性能优化', '性能审查', '慢请求', 'TTFB',
                '冷启动', '热路径', 'N+1', 'HotCache', 'CachePool', 'CachePolicy', 'WLS',
                '缓存失效', '批量预取', 'performance', 'performance check', 'performance engineer',
                'Team:性能检查工程师', '店面列表性能', 'FPC',
            ],
            'authoritative_skill' => 'weline-performance-check',
            'authoritative_doc' => 'dev/ai-command/ai/性能检查.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/性能检查.md',
                'dev/ai-command/ai/工程团队.md',
                'app/code/Weline/Framework/doc/统一缓存范围与性能优化.md',
                'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                'app/code/Weline/Framework/doc/性能诊断-20260908.md',
                'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                'docs/版本计划/v3/PHP8.4+框架优化/12-性能基准与目标.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
            ],
            'norms' => [
                ['id' => 'perf_must_check_performance', 'summary' => '本职必须检查性能（设计+开发后）；禁止旁听式签字或不审缓存/timing'],
                ['id' => 'perf_framework_and_business_knowledge', 'summary' => '出场须深懂框架结构（扩展点/WLS/HotCache 分层）并写清本需求业务特性摘要；否则禁止定制优化方向'],
                ['id' => 'perf_cache_design_compliance', 'summary' => '必须审查缓存设计是否合规（CachePolicy/scope/vary/dependencies/失效/禁平行袋）'],
                ['id' => 'perf_joint_with_architect', 'summary' => '必须与架构师共同讨论并定制优化方向；纪要 architect_joint；双方未表态不得冻结含热路径/缓存方案'],
                ['id' => 'perf_framework_constraints_first', 'summary' => '建议落在 HotCache/批量/失效框架约束内；禁通用 Node 套路'],
                ['id' => 'theme_seat_integrity_over_peer_requests', 'summary' => '禁拆壳药方：禁止把移除 header/footer/必装 widget/清空注入列为优化方向；此类 design 否决 / review fail'],
                ['id' => 'perf_mandatory_on_hot_path', 'summary' => '触及热路径/缓存/列表目录搜索/N+1/慢请求的复杂 team 必须 Team:性能检查工程师: 上场'],
                ['id' => 'perf_required_meetings', 'summary' => '立项讨论、对齐冻结会、技术方案会必到（与架构师同会）'],
                ['id' => 'perf_dual_track', 'summary' => '设计检查轨（meetings/性能检查-design.md）+ 实现复审轨（meetings/性能检查-review.md）；fail 阻断验收'],
                ['id' => 'perf_evidence_discipline', 'summary' => '结论须带可复现 DB/WLS/阶段耗时证据；禁止跨样本伪加速比'],
                ['id' => 'perf_rework_to_owners', 'summary' => '默认可写只读探针与纪要；业务实现返工交归属席'],
                ['id' => 'perf_wake_pm_to_arrange', 'summary' => '查出问题后立刻 escalate 拉起项目经理组队：@项目经理：请立刻组队解决；禁止 Issue 列表与本席私自排施工波'],
                ['id' => 'perf_not_content_ops', 'summary' => '产品优化/详情/主图/翻译等 content_ops 不拉本席'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/性能检查.md',
                'rg -n "performance_engineer_for_design_and_review|性能检查工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "性能检查工程师|performance_check|架构师" dev/ai-command/ai/工程团队.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Giving optimization advice without framework structure + business-characteristic summary',
                    'Skipping cache-design compliance check (HotCache/CachePolicy/scope/invalidation)',
                    'Freezing hot-path/cache scheme without joint architect + 性能检查工程师 discussion',
                    'Architect alone deciding cache/perf direction without 性能检查工程师',
                    'Recommending business-class process-local static/parallel caches bypassing HotCache/CachePolicy',
                    'Listing remove header/footer/nav/版心 chrome, drop required widgets, or clear default_injections as an allowed optimization direction',
                    'Caching mutable Model / personalized HTML / drafts in shared pools',
                    'Claiming speedups from incomparable load/Worker samples or CLI-without-WLS as browser TTFB',
                    'Deleting namespace dependencies to fake cache HIT',
                    'Signing design/review pass without actually checking performance',
                    'Finding performance issues without escalate waking 项目经理 (@项目经理：请立刻组队解决)',
                    'Performance engineer privately staffing construction waves instead of PM arrangement',
                    'Skipping 性能检查工程师 on storefront listing/catalog/search/cache/N+1 waves',
                    'Routing content-ops through Team:性能检查工程师',
                ],
                'required' => [
                    'get_skill(performance_check|weline-performance-check) and Read 性能检查.md + 统一缓存范围与性能优化.md + 扩展点选型.md before advising',
                    'Staff Team:性能检查工程师: on hot-path/cache/list/N+1 complex team from 立项波',
                    'Jointly discuss with Team:架构师: to customize optimization directions; record architect_joint + business特性 + cache合规',
                    'Attend align-freeze and tech-scheme; both architect and performance engineer stance required to freeze hot-path/cache UC/contracts',
                    'Write meetings/性能检查-design.md and post-dev meetings/性能检查-review.md with pass/fail + evidence; must check performance both waves',
                    'On findings/fail: result=escalate + @项目经理：请立刻组队解决—PM same-turn staffs construction; forbid Issue backlog and self-arranging construction waves',
                    'Keep suggestions inside Framework HotCache/scope/batch/invalidation rules; rework to owning seats',
                    'FORBID strip-shell prescriptions (theme_seat_integrity_over_peer_requests); only HotCache/batch Query/prefetch/FPC-legal directions',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function promptOptimizationSurface(): array
    {
        return [
            'id' => self::SURFACE_PROMPT_OPTIMIZATION,
            'label' => '提示词优化（技能引用 + 重复压缩 + 题词瘦身）',
            'description' => '工程团队「提示词优化工程师」专席：必须优化提示词。HARD 改写铁律：仅在识别到 ≥2 处同义重复后才可压缩；禁止省略原义；禁止乱加原文没有的规矩；技能引用写成 get_skill/路径指针（禁止整段复制其它技能正文）；重复硬规则/清单只留一处权威；语义复审禁止削弱硬规则。改技能/提示词/席位镜/工程指令文案或用户明示提示词优化时必须上场。',
            'triggers' => [
                '提示词优化', '提示词优化工程师', '优化题词', '优化提示词',
                '技能引用优化', '技能压缩', '重复描述压缩', '技能镜瘦身',
                'prompt optimization', 'prompt engineer', 'prompt_increment',
                'seat_skill_mirrors', 'Team:提示词优化工程师',
            ],
            'authoritative_skill' => 'weline-prompt-optimization',
            'authoritative_doc' => 'dev/ai-command/ai/提示词优化.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/提示词优化.md',
                'dev/ai-command/ai/工程团队.md',
                'app/code/Weline/Ai/doc/AI硬规则索引.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
                'app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
                'app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                'app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
            ],
            'norms' => [
                ['id' => 'prompt_must_optimize', 'summary' => '本职必须优化提示词（引用指针化+重复压缩+题词瘦身）；禁止旁听式签字'],
                ['id' => 'prompt_only_on_duplication', 'summary' => '仅重复才改：须先标 ≥2 处同义展开证据；无证据禁止动刀'],
                ['id' => 'prompt_no_omit_meaning', 'summary' => '禁止丢义：强制上场/禁止项/席位边界/产物路径/硬规则 id 压缩后仍须可执行'],
                ['id' => 'prompt_no_invent_rules', 'summary' => '禁止乱加：不得新增原文没有的规矩/流程/席位义务；指针只指向已有权威'],
                ['id' => 'prompt_refs_are_pointers', 'summary' => '技能引用只写 get_skill id/alias + 文档路径；禁止粘贴被引技能全文'],
                ['id' => 'prompt_one_authority_for_duplicates', 'summary' => '同一硬规则/清单只在一处权威展开，其它处指针；改指针≠删唯一正文'],
                ['id' => 'prompt_no_semantic_regression', 'summary' => '压缩后强制上场/禁止项/席位名/双轨产物路径不得语义回退'],
                ['id' => 'prompt_mandatory_on_skill_prompt_work', 'summary' => '触及技能/提示词/席位镜/MCP skill·surface 文案或用户明示时必须 Team:提示词优化工程师: 上场'],
                ['id' => 'prompt_dual_track', 'summary' => '优化施工轨 meetings/提示词优化-design.md（含重复证据）+ 语义复审轨 meetings/提示词优化-review.md；fail 阻断宣称完成'],
                ['id' => 'prompt_wake_pm_on_conflict', 'summary' => '语义回退/压缩冲突无法本席闭环 → 立刻 escalate 拉起项目经理组队'],
                ['id' => 'prompt_not_content_ops', 'summary' => '产品优化/详情/主图/翻译等 content_ops 不拉本席'],
                ['id' => 'prompt_not_business_code', 'summary' => '默认可改指令/席位镜/surface 文案；业务功能码交归属席'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/提示词优化.md',
                'rg -n "prompt_engineer_for_skill_prompt_work|提示词优化工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "仅重复才改|禁止丢义|禁止乱加" dev/ai-command/ai/提示词优化.md',
                'rg -n "提示词优化工程师|prompt_optimization" dev/ai-command/ai/工程团队.md',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Rewriting prompts without duplication evidence (≥2 same-meaning expansions)',
                    'Omitting/weakening original mandatory staffing, forbiddens, seat boundaries, or hard-rule ids while claiming optimize pass',
                    'Inventing new rules/flows/seat duties not present in the original prompt text',
                    'Pasting other skills’ full bodies into seat mirrors or commands as “references”',
                    'Compressing away mandatory staffing / forbiddens / Team:{seat}: exact names / dual-track artifact paths',
                    'Marking review pass after semantic regression of hard_constraints',
                    'Skipping 提示词优化工程师 on skill-ref / prompt-skeleton / seat_skill_mirrors / MCP surface wording waves',
                    'Routing content-ops through Team:提示词优化工程师',
                    'Rewriting business PHP/phtml/CSS under this seat without escalate',
                ],
                'required' => [
                    'get_skill(prompt_optimization|weline-prompt-optimization) and Read 提示词优化.md + 工程团队.md + AI硬规则索引.md before bulk rewrites',
                    'Staff Team:提示词优化工程师: on skill/prompt/mirror/surface wording work or user 提示词优化',
                    'Record duplication evidence before any compression; only then turn duplicate refs into pointers; keep one authority body intact',
                    'Write meetings/提示词优化-design.md (with evidence) and meetings/提示词优化-review.md with pass/fail + regression checklist; fail on omit-meaning or invent-rules',
                    'On semantic-regression / compression conflicts: escalate + @项目经理：请立刻组队解决; business code to owning seats',
                    'Keep host thin mirrors pointer-only; MCP/docs win',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function translationEngineerSurface(): array
    {
        return [
            'id' => self::SURFACE_TRANSLATION_ENGINEER,
            'label' => '翻译工程师（全站漏译巡检 + 中英 CSV）',
            'description' => '工程团队「翻译工程师」专席（一代别名 i18n）：专职全站界面/流程文案与活跃·默认 locale 匹配巡检，发现问题即译修（品牌/商标/专有名词除外）。标准流程：解析默认站 language_codes → i18n:collect → 源串默认简中 → 对比缺口 → 译修 → 再 collect → 抽检≥1 非中英。模块 CSV 格式边界仅 zh_Hans_CN+en_US（禁止非中英模块 CSV）；其它已选 locale 进系统词典/归属实体——禁止「其它语种默认不做」。伴生技能 template_i18n + module_i18n_csv。不代跑 content_ops 商品翻译优化。',
            'triggers' => [
                '翻译工程师', 'Team:翻译工程师', 'Team:i18n',
                '漏译', 'locale leak', '界面文案翻译', '流程提示翻译',
                '英文环境中文', '非中文环境露中文', 'i18n:collect',
                'translation engineer', 'site i18n audit', '词典收集',
            ],
            'authoritative_skill' => 'weline-translation-engineer',
            'authoritative_doc' => 'dev/ai-command/ai/翻译工程师.md',
            'authoritative_docs' => [
                'dev/ai-command/ai/翻译工程师.md',
                'dev/ai-command/ai/工程团队.md',
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
                'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
                'app/code/Weline/Ai/doc/AI工程交付流程.md',
            ],
            'norms' => [
                ['id' => 'i18n_must_site_audit', 'summary' => '本职必须全站/范围内巡检界面与流程提示是否与活跃·默认 locale 匹配；禁止旁听式签字'],
                ['id' => 'i18n_collect_then_compare_translate', 'summary' => '标准流程：解析默认站 language_codes → i18n:collect → 源串默认简中 → 对比缺口 → 翻译落盘 → 再 collect → 抽检≥1 非中英'],
                ['id' => 'i18n_zh_en_csv_only_this_phase', 'summary' => '模块 CSV 格式边界仅 zh_Hans_CN+en_US；禁止写非中英模块 CSV；禁止把「仅中英」写成「其它语种默认不做」——其它已选 locale 进系统词典/实体'],
                ['id' => 'i18n_default_website_all_locales_on_copy_change', 'summary' => '工程改用户可见文案同波须覆盖默认站每一个已选 locale（zh/en→模块 CSV；其它→词典/实体）；对齐 active_locale_must_show_target_language'],
                ['id' => 'i18n_active_locale_no_chinese_leak', 'summary' => 'active_locale_must_show_target_language：非中文环境禁止继续露中文正文或错误回落英文 catalog（品牌/商标/专有名词除外）；en_US 第二列禁止中文占位'],
                ['id' => 'i18n_chinese_source_default', 'summary' => '源串默认简中；禁止英文源串当默认；禁止为修漏译把模板改成英文'],
                ['id' => 'i18n_mandatory_on_copy_scope', 'summary' => '新文案/改用户可见文案/i18n in_scope/漏译/界面流程翻译复杂 team 必须 Team:翻译工程师:（别名 i18n）上场'],
                ['id' => 'i18n_dual_track', 'summary' => '巡检施工轨 + 合规复审轨（CSV+词典/实体+collect+活跃 locale 抽检证据）；meetings/翻译-review.md'],
                ['id' => 'i18n_not_product_translate_ops', 'summary' => '不代跑 content_ops「翻译优化/商品翻译」；商品多语走 product/翻译优化.md'],
                ['id' => 'i18n_wake_pm_when_blocked', 'summary' => '缺归属/需跨席改源串/无法闭环 → escalate @项目经理：请立刻组队解决'],
                ['id' => 'i18n_companion_skills', 'summary' => '开工前 get_skill(translation_engineer)+get_skill(template_i18n)+get_skill(module_i18n_csv)+Read 翻译工程师.md + 模块翻译CSV规范.md'],
            ],
            'verification_commands' => [
                'test -f dev/ai-command/ai/翻译工程师.md',
                'rg -n "translation_engineer_for_i18n_work|翻译工程师" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                'rg -n "翻译工程师|i18n:collect" dev/ai-command/ai/工程团队.md',
                'php bin/w i18n:collect Weline_Module',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Signing i18n pass without site/region locale audit against active/default locale',
                    'Leaving Chinese UI prose under en_US / non-Chinese locale (except brand/trademark/tech tokens)',
                    'Leaving English catalog fallback under non-en locales after copy remediation (e.g. ru_RU FAQ still English)',
                    'Editing module i18n CSV without i18n:collect',
                    'Creating or writing module i18n CSV for locales other than zh_Hans_CN / en_US',
                    'Treating「模块 CSV 仅中英」as license to skip other default-website locales (dictionary/entity)',
                    'Rewriting template sources to English to fake English-locale display',
                    'Treating content-ops product 翻译优化 as this seat’s default work',
                    'Skipping Team:翻译工程师: (alias i18n) on new/changed user-visible copy / i18n in_scope / leak screenshots',
                ],
                'required' => [
                    'get_skill(translation_engineer|weline-translation-engineer) + get_skill(template_i18n) + get_skill(module_i18n_csv); Read 翻译工程师.md + 模块翻译CSV规范.md',
                    'Staff Team:翻译工程师: (alias i18n) on copy/i18n/leak waves',
                    'Resolve WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT); zh/en→module CSV+collect; other locales→system dictionary/entity; spot-check ≥1 non-zh/en',
                    'Keep module CSV bilingual zh_Hans_CN + en_US only as FORMAT BOUNDARY; forbid non-zh/en module CSV',
                    'Write meetings/翻译-review.md (or equivalent) with collect + locale evidence; escalate when blocked',
                ],
            ],
        ];
    }

    public function pinnedFragments(ProjectRetriever $retriever, int $tokenBudget): array
    {
        $paths = self::pinnedDocumentPaths();
        if ($paths === [] || $tokenBudget < 128) {
            return [];
        }

        $perPath = max(128, (int) floor($tokenBudget / count($paths)));
        $remaining = $tokenBudget;
        $fragments = [];
        $seenPaths = [];

        foreach ($paths as $path) {
            if ($remaining < 128) {
                break;
            }
            if (isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            try {
                $result = $retriever->getDocument([
                    'path' => $path,
                    'limit' => 2,
                    'token_budget' => min($perPath, 900, $remaining),
                ]);
            } catch (\Throwable) {
                continue;
            }
            foreach (is_array($result['documents'] ?? null) ? $result['documents'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $fragments[] = [
                    'kind' => 'workflow',
                    'pinned' => true,
                    'surface' => self::surfaceIdForPinnedPath($path),
                    'path' => $path,
                    'module' => (string) ($item['module'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'start_line' => (int) ($item['start_line'] ?? 1),
                    'end_line' => (int) ($item['end_line'] ?? $item['start_line'] ?? 1),
                    'content' => (string) ($item['snippet'] ?? ''),
                    'file_hash' => (string) ($item['file_hash'] ?? ''),
                    'content_hash' => (string) ($item['content_hash'] ?? ''),
                    'token_estimate' => (int) ($item['token_estimate'] ?? 0),
                ];
                $remaining -= (int) ($item['token_estimate'] ?? 0);
            }
        }

        return $fragments;
    }

    /**
     * @param list<array<string, mixed>> $fragments
     * @param list<array<string, mixed>> $pinned
     * @return list<array<string, mixed>>
     */
    public static function mergeFragments(array $fragments, array $pinned): array
    {
        $merged = [];
        $seen = [];
        foreach (array_merge($pinned, $fragments) as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $path = (string) ($fragment['path'] ?? '');
            $start = (int) ($fragment['start_line'] ?? 0);
            $key = $path . ':' . $start . ':' . (string) ($fragment['content_hash'] ?? '');
            if ($key === '::' || isset($seen[$key])) {
                continue;
            }
            $content = (string) ($fragment['content'] ?? '');
            foreach ($merged as $position => $existing) {
                if (($existing['path'] ?? '') !== $path || $content === '') {
                    continue;
                }
                $existingContent = (string) ($existing['content'] ?? '');
                if ((int) ($existing['start_line'] ?? 0) <= $start
                    && (int) ($existing['end_line'] ?? 0) >= (int) ($fragment['end_line'] ?? 0)
                    && str_contains($existingContent, $content)) {
                    continue 2;
                }
                if ($start <= (int) ($existing['start_line'] ?? 0)
                    && (int) ($fragment['end_line'] ?? 0) >= (int) ($existing['end_line'] ?? 0)
                    && $existingContent !== '' && str_contains($content, $existingContent)) {
                    unset($merged[$position]);
                }
            }
            $seen[$key] = true;
            $merged[] = $fragment;
        }

        return array_values($merged);
    }

    private static function surfaceIdForPinnedPath(string $path): string
    {
        if (str_contains($path, '需求澄清与用例规格')) {
            return self::SURFACE_REQUIREMENT_CLARIFY_USE_CASE;
        }
        if (str_contains($path, '工程团队')) {
            return self::SURFACE_ENGINEERING_TEAM;
        }
        if (str_contains($path, 'WebUI浏览器验收与交付地址门禁')) {
            return self::SURFACE_WEBUI_BROWSER_CLOSEOUT;
        }
        if (str_contains($path, '模块版本与升级门禁')) {
            return self::SURFACE_MODULE_UPGRADE;
        }
        if (str_contains($path, '/Taglib/doc/')) {
            return self::SURFACE_TAGLIB_UI_CONTROL;
        }
        if (str_contains($path, '/Hook/doc/')) {
            return self::SURFACE_HOOK_EXTENSION;
        }
        if (str_contains($path, '事件命名与注册规范')
            || str_contains($path, '/Framework/doc/event/')) {
            return self::SURFACE_EVENT_EXTENSION;
        }
        if (str_contains($path, '部件开发指南') || str_contains($path, 'widget-slot-attributes')) {
            return self::SURFACE_WIDGET_DEVELOPMENT;
        }
        if (str_contains($path, 'API接口开发规范') || str_contains($path, 'SDK使用指南')) {
            return self::SURFACE_API_SDK_DEVELOPMENT;
        }
        if (str_contains($path, 'ai/电商顾问')) {
            return self::SURFACE_ECOMMERCE_ADVISOR;
        }
        if (str_contains($path, 'ai/性能检查')
            || str_contains($path, '统一缓存范围与性能优化')
            || str_contains($path, '性能诊断-')) {
            return self::SURFACE_PERFORMANCE_CHECK;
        }
        if (str_contains($path, 'ai/提示词优化')) {
            return self::SURFACE_PROMPT_OPTIMIZATION;
        }
        if (str_contains($path, 'Payment/doc/payment-shell')
            || str_contains($path, 'Payment/doc/provider-development')
            || str_contains($path, 'Payment/doc/webhook')
            || str_contains($path, 'ai/支付开发')) {
            return self::SURFACE_PAYMENT_DEVELOPMENT;
        }
        if (str_contains($path, 'ai/主题开发')
            || str_contains($path, 'theme-engineer-charter')) {
            return self::SURFACE_THEME_DEVELOPMENT;
        }
        if (str_contains($path, '/Visitor/doc/')
            || str_contains($path, '像素拓展使用指南')
            || str_contains($path, '像素事件供应商')
            || str_contains($path, 'Visitor_Pixel_GTM')
            || str_contains($path, 'visitor-data-analytics')) {
            return self::SURFACE_VISITOR_DATA_ANALYTICS;
        }
        if (str_contains($path, '/Theme/doc/')) {
            return self::SURFACE_FRONTEND_DEVELOPMENT;
        }
        if (str_contains($path, '01-lang')) {
            return self::SURFACE_TEMPLATE_I18N;
        }

        return 'workflow';
    }
}
