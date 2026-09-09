<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Pinned workflow guidance merged into resolve_task_context and get_edit_bundle.
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
            'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
            'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'app/code/Weline/Theme/doc/部件开发指南.md',
        ];
    }

    /**
     * Pointer-only bootstrap notices. Framework rule bodies live in HardConstraintsCatalog
     * (prepare_project.agent_guidance.hard_constraints / workflow_contract.hard_rules).
     *
     * @return list<string>
     */
    public static function sessionStartupNotices(): array
    {
        return [
            '【引导·只指路】框架硬约束不在本列表展开。请立即阅读 prepare_project.agent_guidance.hard_constraints（hard-constraints.v1）；权威正文 app/code/Weline/Ai/doc/AI硬规则索引.md；任务细则由 resolve_task_context → workflow_contract.v1 surfaces 下发；工程技能由 agent_guidance.mcp_skills + resolve_skill/get_skill 按需取。',
            '[Bootstrap · pointers only] Framework hard rules are not expanded here. Read agent_guidance.hard_constraints (hard-constraints.v1); authority app/code/Weline/Ai/doc/AI硬规则索引.md; task detail via resolve_task_context → workflow_contract.v1 surfaces; engineering skills via agent_guidance.mcp_skills + resolve_skill/get_skill.',
            '交付地址机器契约见 agent_guidance.feature_delivery_urls 与 closeout_delivery_reminder；本机默认 Host 为 `{project_hash}.test.weline.com`，禁止主验收使用 `*.weline.test`。Browser 自测、每次打开禁用缓存、交付地址、汇报后关闭标签见 hard_constraints（browser_operator_self_test / browser_cache_disabled_on_open / feature_delivery_urls / browser_release_after_delivery）与 WebUI浏览器验收与交付地址门禁.md。',
            'Delivery URL machine contract: agent_guidance.feature_delivery_urls and closeout_delivery_reminder; default local Host is {project_hash}.test.weline.com (never primary *.weline.test). Browser self-test, cache-disabled-on-open, delivery URLs, and close-after-report live in hard_constraints (browser_operator_self_test / browser_cache_disabled_on_open / feature_delivery_urls / browser_release_after_delivery) and WebUI browser closeout gate doc.',
            '【宿主工具目录】密封编辑前确认本会话可见 submit_task_plan / get_task_plan。ensure 的 mcp_stdio 已含而本会话 GetDynamicTools 缺失时，记 HOST_MCP_SESSION_CATALOG_STALE 并新开 Agent 回合；禁止调用 mcp_auth。',
            '[Host tool catalog] Before sealed edits, confirm this chat exposes submit_task_plan / get_task_plan. If ensure mcp_stdio lists them but GetDynamicTools does not, record HOST_MCP_SESSION_CATALOG_STALE and start a new Agent turn; never call mcp_auth.',
            '【调用范围】非编码（闲聊/概念问答）禁止 MCP；仅编码/工程走 ensure → prepare_project。例外：打招呼 hi/你好 或指令「提取技能」可 list MCP 技能+指令（禁止密封编辑）。细则见 hard_constraints.mcp_operational.mcp_call_scope / greeting_lists_mcp_skills_and_commands。',
            '[Call scope] Skip MCP for non-coding; coding/engineering only uses ensure → prepare_project. Exception: greeting hi/你好 or command 提取技能 may list MCP skills+commands (no sealed edits). See mcp_call_scope / greeting_lists_mcp_skills_and_commands.',
            '【每条编码需求】可执行编码/工程需求提出后，立即 submit_task_plan（强制规划，含 ≥1 unit）；再 TDD 红→绿并实际跑测；未跑通不得宣称完成（plan_then_tdd_required）。非编码勿 submit_task_plan。',
            '[Every coding requirement] Immediately submit_task_plan (≥1 unit acceptance), then TDD red→green with real test runs; never claim done without PASS evidence (plan_then_tdd_required). Non-coding must not submit_task_plan.',
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
            'summary_zh' => '每次向用户汇报功能完成或阶段性交付时：① 须已自行按验收层级验证（agent_self_verify_before_done：UT/RT/WB）；Web/UI 须用当前宿主可用的真实 Browser 按用例自测，且每次打开/导航前禁用 HTTP 缓存（未测或宿主无 Browser 只能报验收未完成）；② 回复末尾必须包含「交付地址」小节，列出探活过的前台/后台/API 可点击 http(s) Markdown 链接；本机默认 Host 为 `{project_hash}.test.weline.com`（例 http://p05113ef3.test.weline.com:9555/...），禁止把 `*.weline.test` 当主验收 Host；纯逻辑无 UI 写 N/A。禁止省略该小节。③ 写完「交付地址」后立即关闭本回合打开的验收 Browser 标签/webview（Cursor：unlock 后 browser_tabs close；用户明确要求保留除外）。',
            'summary_en' => 'On every feature completion or stage handoff: (1) Agent must have self-verified by acceptance tier (agent_self_verify_before_done: UT/RT/WB); for Web/UI, run a host-available real Browser on agreed use cases with HTTP cache disabled on every open/navigate—otherwise only report WebUI incomplete; (2) end with a Delivery URLs section of probe-verified clickable http(s) Markdown links using default local Host {project_hash}.test.weline.com (never primary *.weline.test), or N/A when no UI. Never omit this section. (3) Immediately after that section, close every acceptance Browser tab/webview opened this turn (Cursor: unlock then browser_tabs close), unless the user explicitly asks to keep them. Do not require a Cursor-only browser.',
            'browser_self_test_required_for_web' => true,
            'agent_self_verify_required' => true,
            'agent_self_verify_rule' => 'agent_self_verify_before_done',
            'plan_then_tdd_required' => true,
            'plan_then_tdd_rule' => 'plan_then_tdd_required',
            'browser_tooling' => 'host_available_real_browser',
            'browser_cache_disabled_on_open_required' => true,
            'browser_open_order' => [
                'disable_http_cache_for_session',
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
                '分章计划：上一章 doc/开发日志.md 四段门禁全 pass 后才允许下一章编码。',
                '含 Web 的分章 Done：WB 须 WB-OP；有视觉面且宿主可截图时再加 WB-VIS（存归属模块 doc/evidence/ch{N}/，有 doc/原型设计.md 则对照）。',
                '每章收口须在交付汇报与 doc/开发日志.md 列出本章涉及的前台、后台与 API 地址清单。',
            ],
            'mandatory_before_code' => [
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
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
                'prepare_project_ready',
                'requirements_confirmed_or_scoped',
                'requirement_analysis_in_task_plan',
                'extension_point_selected',
                'task_contract_or_plan',
                'submit_task_plan_accepted',
                'tdd_unit_acceptance_planned',
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
            ],
            'mandatory_before_closeout' => [
                'agent_self_verify_with_acceptance_evidence',
                'tdd_unit_tests_executed_and_passed',
                'module_docs_reconciled_with_behavior',
                'responsive_breakpoints_considered_for_web_ui',
                'webui_browser_operator_self_test_pass_or_na',
                'feature_delivery_urls_provided',
                'webui_browser_released_after_delivery_or_na',
                'module_i18n_csv_collected_when_strings_changed',
            ],
            'hard_constraints' => HardConstraintsCatalog::package(),
            'phases' => [
                ['id' => 'bootstrap', 'label' => '引导与 ready', 'tools' => ['ensure-project-guidance', 'prepare_project'], 'read' => ['agent_guidance.hard_constraints', 'session_startup_notices']],
                ['id' => 'locate', 'label' => '定位与需求确认', 'tools' => ['resolve_task_context', 'search_project_knowledge', 'submit_task_plan'], 'notes' => [
                    'Coding/engineering executable requirements must be understood here and immediately become submit_task_plan.requirements + acceptance — do not defer planning until implement. Non-coding asks skip this phase and all MCP tools.',
                ]],
                ['id' => 'extension_point', 'label' => '扩展点选型', 'docs' => [
                    'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                    'app/code/Weline/Framework/doc/event/README.md',
                ]],
                ['id' => 'plan', 'label' => '计划拆解', 'tools' => ['submit_task_plan', 'get_task_plan', 'update_task_plan_progress', 'review_task_plan'], 'docs' => ['doc/开发/plan.md', 'doc/开发/task.md', 'task_contract'], 'notes' => [
                    'On every coding/engineering user requirement: compose requirements + architecture + dev_tasks + acceptance and call submit_task_plan immediately (user_requirement_full_workflow). Non-coding skips MCP.',
                    'PLAN_REQUIRED is not a dead-end: follow plan_workflow steps 1–8 and submit_task_plan now.',
                    'Track dev_tasks and acceptance status via update_task_plan_progress during implement/verify.',
                    'Call review_task_plan before closeout; closeout_allowed=true required to claim done.',
                    'Web/UI tasks must list tablet and PC responsive acceptance in the plan.',
                ]],
                ['id' => 'implement', 'label' => '实现（TDD 绿）', 'tools' => ['get_edit_bundle', 'apply_compact_edit', 'update_task_plan_progress'], 'notes' => [
                    'Only after accepted plan. TDD: failing test first (red), then minimal production change to green (plan_then_tdd_required). Do not claim done here.',
                ]],
                ['id' => 'review', 'label' => '架构/缺陷/安全复审', 'tools' => ['review_task_plan', 'update_task_plan_progress'], 'notes' => [
                    'Review plan omissions; append review_notes via update_task_plan_progress or review_task_plan.',
                ]],
                ['id' => 'verify', 'label' => '实际跑测与分层验收', 'tools' => ['update_task_plan_progress', 'review_task_plan'], 'notes' => [
                    'MANDATORY (plan_then_tdd_required + agent_self_verify_before_done): run the real test command; unit passed evidence must look like phpunit/PASS output.',
                    'Pure logic → focused unit/contract tests actually PASS; command/API/runtime → real command/API result; page/UI → host-available real Browser WB-OP.',
                    'Unfinished TDD/self-verify → report only “代码已改，TDD/测试未跑通”; never claim done.',
                ]],
                ['id' => 'closeout', 'label' => '文档对齐与开发日志收口', 'tools' => ['review_task_plan'], 'docs' => ['doc/README.md', 'doc/需求.md', 'doc/开发日志.md'], 'notes' => [
                    'review_task_plan.closeout_allowed must be true before claiming feature done.',
                    'Reconcile module docs with shipped behavior before claiming done.',
                    'Plan honesty (plan_todo_evidence_closeout): never mark a multi-todo plan complete without per-todo evidence; partial work must report an unfinished checklist and write it into doc/开发日志.md.',
                    'End every user-facing feature report with 「交付地址」: frontend pages, backend admin pages, API/Query routes (probe-verified); each primary URL as direct http(s) Markdown link `[label](url)` per feature_delivery_urls.link_format.',
                    'After the Delivery URLs section: immediately release acceptance Browsers (browser_release_after_delivery)—unlock then close tabs; do not leave idle Glass/Simple Browser/ide-browser webviews.',
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
            'hard_rules' => HardConstraintsCatalog::workflowHardRules(),
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
            'mandatory_before_code' => ['prepare_project_ready', 'extension_point_selected', 'submit_task_plan_accepted'],
            'mandatory_before_closeout' => ['module_docs_reconciled_with_behavior', 'real_runtime_acceptance_evidence', 'review_task_plan'],
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
            'description' => 'Theme / 布局 / 部件 / partial / 前台模板开发的统一规范表面。【高压线】必须使用 Weline 自研主题 UI（Weline UI 2.0）与主题 CSS 变量 Token；凡任务提到 CSS 或主题/theme，必须先加载 UI 技能 frontend-design、原型技能 prototype、主题技能 weline-theme-development（MCP get_skill），禁止自造色板与间距。禁止第三方 UI 与硬编码视觉字面量。section 身份属性（weline-code）只是其中一条硬约束，不是独立技能名。',
            'triggers' => [
                '部件', 'widget', '主题', 'theme', '布局', 'layout', 'partial',
                'phtml', '前端', 'frontend', '模板', 'section', 'slot',
                'UI', 'ui', 'frontend-design', '样式', '颜色', '间距',
                'css', 'CSS', 'stylesheet', 'prototype', '原型',
            ],
            'authoritative_skill' => 'weline-theme-development',
            'required_companion_skills' => [
                'frontend-design',
                'prototype',
                'weline-theme-development',
            ],
            'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'authoritative_docs' => [
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Theme/doc/部件开发指南.md',
                'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                'app/code/Weline/Theme/doc/theme-layout-content-width.md',
            ],
            'norms' => [
                [
                    'id' => 'weline_ui_theme_first',
                    'summary' => '【高压线】前端必须使用 Weline 自研主题 UI（Weline UI 2.0）与主题 CSS 变量 Token（w-field/w-input/w-button… + --color-*/--weline-theme-*/spacing Token）；禁止 Bootstrap/Element/Ant 等第三方 UI、硬编码色值/间距，以及手写国家/省/市 input 替代 <w:theme:address>',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
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
                    'summary' => '【高压线】凡使用宿主 UI / frontend-design / 审美类技能写前台或后台界面，必须先用 MCP get_skill 加载 weline-theme-development（或 surface frontend_development）并服从 Theme开发总指南 / theme-css-variables-only；主题 Token 与 Weline UI 2.0 类名优先于通用 UI 技能的自造色板。禁止发明私有 #hex/rgb、px 间距阶梯、圆角阴影套件或平行 design token；UI 技能仅可指导构图/层次/文案，不得另造视觉字面量；宿主 SKILL.md 仅可选薄壳',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    'authoritative_skill' => 'weline-theme-development',
                    'mcp_skill_id' => self::SURFACE_FRONTEND_DEVELOPMENT,
                ],
                [
                    'id' => 'theme_address_for_region_pickers',
                    'summary' => '【高压线】前台/后台国家·省·市·区·地区选择（表单、列表筛选、多选 chips）必须用 <w:theme:address>（single/multi）；禁止手写国家/地区 <select>、自造筛选芯片行或绕开 Theme Address 的级联 input；chips/菜单由标签与浮层内核提供',
                    'detail_doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
                ],
                [
                    'id' => 'weline_ui_floating_primitives',
                    'summary' => '【高压线】菜单/Popover/Tooltip/Combobox/地址多选下拉等浮层必须用 Weline.UI（menu/popover/tooltip/combobox/anchored-float 或 UI.floating.attach）；禁止手写 left/top、自研 flip/边界检测或私有 portal',
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
                    'summary' => '部件禁止带 <?= 的内联 script；模块级 JS 须 weline.modules.js + data-weline-load/declare（禁止 @static/<js> 直引）；改登记后必须 php bin/w resource:compile welineModules',
                    'detail_doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                    'verify' => 'php bin/w resource:compile welineModules',
                ],
                [
                    'id' => 'theme_js_module_declare_only',
                    'summary' => '【高压线】前台主题/部件/布局 JS 只能 Weline.declare / data-weline-load / data-weline-declare；禁止 script src=@static 或裸 <js> 拉模块；改 weline.modules.js 后必须 resource:compile welineModules 收集',
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
                    'summary' => 'Theme layouts/partials 仅允许内嵌 Weline_Theme 部件；其他模块用 default_injections + 空 slot',
                    'detail_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'verify' => 'php bin/w frontend:check-theme-layout-widgets',
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
                    'id' => 'browser_api',
                    'summary' => '浏览器业务请求走 Weline.Api.*，禁止 raw ajax/fetch fallback',
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
                    'Non-Weline_Theme <w:widget> or fetch(.../widgets/...) inside Theme layouts/partials (use default_injections)',
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
                ],
                'required' => [
                    'Use first-party Weline Theme / Weline UI 2.0 component classes and theme CSS variable tokens for all visual UI',
                    'Address and region cascade/filters via <w:theme:address> (single or multi, including official chips); never hand-roll region inputs or chip rows',
                    'Floating surfaces (menu/popover/tooltip/combobox/address multi) via menu/popover/tooltip/combobox/anchored-float or UI.floating.attach',
                    'Images via <w:file:image> (or equivalent) with UI width+height or aspect_ratio, plus Theme CSS max-width:100%;height:auto / .w-file-image',
                    'Choose layout / partial / component / widget layer before editing',
                    'Widget JS scoped by data-js-ns + data-uid; register in weline.modules.js and load via Weline.declare / data-weline-load / data-weline-declare; after any modules registry change run php bin/w resource:compile welineModules before closeout',
                    'Dynamic values in attributes: set on HTML elements in body, not on Taglib tag attributes',
                    'Widget root uses WidgetUiScope and a stable type-level section identity attribute',
                    'Layout/partial literal <section> and w:slot wrapper="section" carry stable semantic section identity; verify with php bin/w frontend:check-section-code before setup:upgrade',
                    'Theme layouts/partials inline widgets only when owned by Weline_Theme; other modules use default_injections; verify with php bin/w frontend:check-theme-layout-widgets',
                    'Design and accept Web UI across tablet (≈768) and PC (≥1024), plus mobile 375 when relevant',
                    'After each feature, reconcile module README/需求/开发日志/topic docs with shipped behavior',
                    'After each feature closeout, list probe-verified frontend/backend/API URLs; primary acceptance must use direct http(s) Markdown links `[label](url)`, not host-private pseudo-protocols (e.g. command:simpleBrowser) as the sole/primary link or styled plain “open” text',
                    'Before writing any page/module content shell, choose Theme shell A or B per theme-layout-content-width.md — never invent a third container; inside .w-container use width:100% and padding-inline:0; standalone shells use --weline-layout-content-max-width and --weline-layout-content-padding-inline (or .w-theme-content-width) without pixel fallbacks',
                ],
                'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'authoritative_docs' => [
                    'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'app/code/Weline/Theme/doc/部件开发指南.md',
                    'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                    'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                    'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    'app/code/Weline/Theme/doc/theme-layout-content-width.md',
                ],
                'verification_commands' => [
                    'php bin/w frontend:check-section-code',
                    'php bin/w frontend:check-theme-layout-widgets',
                    'php bin/w resource:compile welineModules',
                ],
            ],
            'verification_commands' => [
                'php bin/w frontend:check-section-code',
                'php bin/w frontend:check-theme-layout-widgets',
                'php bin/w resource:compile welineModules',
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
                ['id' => 'csv_bilingual_aligned', 'summary' => 'zh_Hans_CN.csv 与 en_US.csv source 键对齐，en 列为英文译文'],
                ['id' => 'collect_after_csv', 'summary' => '改 CSV 或新增源串后必须 php bin/w i18n:collect，否则运行时词典不更新'],
            ],
            'verification_commands' => [
                'php bin/w i18n:collect Weline_Module',
                'php bin/w i18n:collect',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    '<?= __(\'...\') ?> or <?= __("...") ?> in .phtml HTML body or attributes',
                    '__() for user-visible strings directly echoed in templates',
                    'Unquoted commas inside @lang()/@lang{} source text (e.g. @lang{支持 .ico, .png} → ParseError)',
                    'Editing i18n/*.csv without running i18n:collect before claiming translation done',
                    'Shipping modules with missing en_US.csv or untranslated en_US rows for new strings',
                ],
                'required' => [
                    'Use <lang>text</lang> or @lang(text) for frontend template copy',
                    'Source text with commas: use <lang>a, b</lang> or quoted @lang(\'a, b\') / @lang{"a, b"}',
                    '__() only in PHP logic layer, not HTML output',
                    'Maintain i18n/zh_Hans_CN.csv and i18n/en_US.csv with aligned keys for every new phrase',
                    'Run php bin/w i18n:collect {Module} after CSV edits or new translatable strings',
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
            'description' => '模块须维护齐全 zh_Hans_CN/en_US CSV；改词或改 CSV 后必须 i18n:collect 才生效。',
            'triggers' => [
                'csv', 'i18n:collect', 'en_us', 'zh_hans_cn', '翻译文件', '词典', 'collect',
                '国际化', 'locale', '语言包',
            ],
            'authoritative_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            'authoritative_docs' => [
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
            ],
            'norms' => [
                ['id' => 'bilingual_csv_required', 'summary' => '每模块至少 zh_Hans_CN.csv + en_US.csv，source 键一致'],
                ['id' => 'frontend_backend_csv_sync', 'summary' => '前台加词须同步补后台 CSV 与 en 译文'],
                ['id' => 'collect_mandatory', 'summary' => 'CSV 处理后必须 i18n:collect，禁止只改文件不收集'],
            ],
            'verification_commands' => [
                'php bin/w i18n:collect Weline_Module',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Claiming i18n done after CSV edit without i18n:collect',
                    'Adding frontend <lang> strings without en_US.csv translation rows',
                    'Replacing i18n:collect with cache:clear only',
                ],
                'required' => [
                    'Align zh_Hans_CN.csv and en_US.csv source keys for every new phrase',
                    'Run php bin/w i18n:collect after any CSV or translatable string change',
                    'Record collect command in module doc/开发日志.md',
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
                ['id' => 'sealed_edit_module_version_gate', 'summary' => 'MCP EditService prepare rejects plans that touch Model/Controller/event.xml/hook.php/register.php without same-plan etc/module.php version increase (EDIT_MODULE_VERSION_REQUIRED)'],
            ],
            'verification_commands' => [
                'php bin/w setup:upgrade -m Weline_Module',
                'php bin/w setup:upgrade --route --module=Weline_Module',
                'php bin/w setup:schema:check',
                'php app/code/Weline/Ai/Mcp/tests/module-version-bump-gate.php',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'Changing Model schema attributes without bumping etc/module.php version',
                    'Adding Controller actions without route refresh (setup:upgrade --route)',
                    'Expecting upgrade() to run when module version unchanged',
                    'Submitting sealed edit-plan.v1 with Model/Controller/event/hook/register changes but omitting etc/module.php bump',
                ],
                'required' => [
                    'Bump etc/module.php version (at least patch) in the same edit-plan as registration-affecting files',
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
                ['id' => 'browser_cache_disabled_on_open', 'summary' => '每次打开/导航验收 Browser 前禁用 HTTP 缓存（Cursor：Network.setCacheDisabled；失败则 Page.reload ignoreCache）；禁止用默认磁盘缓存验本回合静态资源'],
                ['id' => 'wb_vis_screenshots', 'summary' => '有视觉面且宿主可截图时：多断点截图存 doc/evidence/；有原型文档则对照'],
                ['id' => 'delivery_urls_section', 'summary' => '每次功能完成汇报末尾必须有「交付地址」小节（主链 http(s) Markdown）'],
                ['id' => 'delivery_default_host_test_weline_com', 'summary' => '本机主验收 Host 默认 {project_hash}.test.weline.com；禁止主链 *.weline.test；仅无前者时才用 127.0.0.1'],
                ['id' => 'browser_release_after_delivery', 'summary' => '写完「交付地址」后立即关闭本回合打开的验收 Browser（unlock + close tabs）；禁止留下空转 webview；用户明确要求保留除外'],
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
                ],
                'required' => [
                    'Define operator use cases (URL, steps, expected) before claiming Web done',
                    'On every acceptance Browser open/navigate: disable HTTP cache (or ignoreCache reload fallback) before trusting the page',
                    'Run host-available real Browser on those use cases (WB-OP); collect WB-VIS when visual and screenshot-capable',
                    'End every feature/stage report with probe-verified http(s) Markdown Delivery URLs on {project_hash}.test.weline.com by default',
                    'Immediately after the Delivery URLs section, close every acceptance Browser tab opened this turn (Cursor: unlock then browser_tabs close)',
                    'If Browser not run or host has no Browser: report only “代码已改，WebUI 验收未完成”',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
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
        if (str_contains($path, '/Theme/doc/')) {
            return self::SURFACE_FRONTEND_DEVELOPMENT;
        }
        if (str_contains($path, '01-lang')) {
            return self::SURFACE_TEMPLATE_I18N;
        }

        return 'workflow';
    }
}
