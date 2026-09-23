<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * MCP-native skill catalog served by resolve_skill / get_skill.
 *
 * Skills are compiled from GuidanceWorkflowCatalog surfaces (+ delivery contracts).
 * They are not repository SKILL.md files and must not revive auto_generate_skills.
 */
final class McpSkillCatalog
{
    public const SCHEMA = 'mcp-skills.v1';

    public const PROVIDER = 'mcp';

    /**
     * Host-shell aliases: optional Cursor/Codex local SKILL.md may mirror these ids,
     * but MCP get_skill is authoritative for engineering skill bodies.
     *
     * @var array<string, string> alias => canonical skill_id
     */
    private const HOST_SHELL_ALIASES = [
        'weline-theme-development' => GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT,
        'local-browser-urls' => GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT,
        'weline-taglib-first' => GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL,
        'weline-req-clarify' => GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
        'weline-engineering-team' => GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM,
        'weline-api-sdk' => GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT,
        'weline-widget-development' => GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT,
        'weline-payment-development' => GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT,
        'weline-ecommerce-advisor' => GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR,
        'weline-visitor-analytics' => GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS,
        'weline-performance-check' => GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK,
        'weline-prompt-optimization' => GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION,
        'weline-translation-engineer' => GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER,
    ];

    /**
     * Policy block for prepare_project.agent_guidance.mcp_skills.
     *
     * @return array<string, mixed>
     */
    public static function policy(string $repository = ''): array
    {
        // Index-only for prepare_project: descriptions/paths stay in definitions + get_skill.
        $workflowSummary = self::summary($repository, 'workflow');
        $fullSummary = self::summary($repository);
        $greeting = $repository !== ''
            ? DocSkillCatalog::greetingCatalog($repository, $workflowSummary)
            : [
                'schema_version' => 'mcp-greeting-catalog.v1',
                'when' => ['hi', '你好', 'hello', '提取技能', 'list skills'],
                'list_from' => 'agent_guidance.mcp_skills.catalog',
                'workflow_preview' => $workflowSummary,
                'module_doc_count' => 0,
                'commands' => [],
                'how_to_load' => [
                    'discover' => 'resolve_skill',
                    'load' => 'get_skill',
                    'list_all' => 'resolve_skill(list_all=true) or task=提取技能',
                ],
                'note' => 'On greeting: print workflow_preview + commands; module docs via resolve_skill(list_all=true). Bodies via get_skill.',
            ];

        return [
            'schema_version' => self::SCHEMA,
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
            'authority' => 'mcp',
            'host_shell_role' => 'optional_thin_mirror',
            'catalog_mode' => 'index_only',
            'catalog_index_fields' => ['skill_id', 'name', 'aliases', 'kind', 'surface_id'],
            'fetch' => [
                'discover' => 'resolve_skill',
                'load' => 'get_skill',
                'list_all' => 'resolve_skill(list_all=true) or task=提取技能',
                'required_fields' => ['skill_id'],
                'body_via' => 'get_skill',
            ],
            'instructions' => [
                'Engineering/product skills for this repository are served by MCP.',
                'When a task needs a skill body, call resolve_skill(task) then get_skill(skill_id).',
                'To list every skill (workflow + module doc indexes), call resolve_skill(list_all=true) or say 提取技能.',
                'On greeting hi/你好/hello with no coding ask: list MCP skills + commands from agent_guidance.mcp_skills.greeting.workflow_preview + commands (+ module_doc_count), or resolve_skill list_all; do not expect full descriptions in prepare_project catalog.',
                'Do not treat host editor SKILL.md as authoritative over MCP skill bodies.',
                'Host shells (e.g. Cursor Agent Skills) may exist only as thin reminders to call MCP.',
                'Full task docs still come from resolve_task_context; skills are procedural checklists.',
                'Whenever the task mentions CSS or 主题/theme: load UI skill frontend-design, prototype skill prototype, and theme skill weline-theme-development (get_skill) before styling.',
                'HARD: At requirement start classify work_kind + fe_be_scope (requirement_fe_be_scope_analysis), then run Spec Kit/Kiro-style clarify + use-case when needed (requirement_clarify_use_case_spec). Enable host Plan Mode (host_plan_mode_for_planning) UNLESS simple plan_skip with rationale≥24; plan body ONLY 背景+方案+细节 (plan_content_focus_only). Non-simple/complex requirements: the parent itself chooses team mode (engineering_team_for_new_requirements) and seats; parent utters ONLY Team:项目经理:; ONE_SEAT_ONE_AGENT (real subagent per seat; forbid parent roleplay); PEER_TALK_VIA_CHANNEL (channel/{thread}.md + resume peers); relay Team:架构师: only from real subagent reports. Simple plan_skip uses 监工: and must not use Team:. Complex team MUST obey framework_first + dual_track_all specialty seats + component_reuse_or_negotiate + team_flow_on_contracts (对齐冻结会钉 UC+contracts+deps；依赖唤醒；禁止开发完才补主路径用例) + ui_prototype_gate_before_test (UI+原型先审可打回→过签才测→PM汇审才汇报). Content-ops exempt. EVERY ask MUST have real acceptance (requirement_acceptance_always)—Web touches need local Browser WB-OP visual+logic even without Playwright e2e. Layout/humanization/吐槽/审图 force prototype+frontend-design adjustments (ui_skill_surface_signal_gate). Then analyze implicit requirements; decide ui_skill_decision. When participate: prototype+frontend-design+weline-theme-development + type=shentu. Closeout MUST write huishen_notes 汇审 (closeout_requires_huishen).',
                'HARD: Any user message with an image/screenshot attachment (admin/CMS/error/storefront—not only retail/B2B) MUST run MCP command 审图 (dev/ai-command/theme/审图.md) immediately; do not wait for the word 审图. Classify error_shot vs ui_shot: non-error (ui_shot) defaults to UI modification required. HARD SEATING: web_ui/confirmed frontend_candidate image → FORCE ui_skill_decision=participate; visual UI + prototype MUST come online (skills always; complex team MUST staff seats 原型+UI+前端+主题开发工程师; simple 监工 still loads those skills). Same-turn joint pipeline: extract wireframe/line sketch → prototype adjustments (prototype) → frontend-design humanization + aesthetic standards → weline-theme-development CSS/tokens; fix fails (do not critique-only). Shot-only/silent screenshot: UI+prototype audit—NOT confirming prior chat. If host skills frontend-design or prototype are missing: prompt visibly and self-install into Cursor Agent Store before E/F pass (image_attachment_shentu_bundle.missing_host_skills_gate).',
                'HARD: 产品优化 (parent) MUST launch THREE parallel subagents—① image (ecommerce-product-image+weline-image-pipeline) ② 详情优化 (ecommerce-detail-suite) ③ 翻译优化 (ecommerce-product-i18n). Not synonyms with any single child. Bundle product_optimize_detail_suite_bundle. Bare /product/ share-link alone does not trigger.',
                'HARD: Prototype/feature Web UI page groupings MUST default to TOP tabs (feature_ui_keep_simple_top_tabs): one job per pane; secondary blocks use click-to-expand cards (default collapsed); redesign non-compliant dense stacked pages to tabs in the same feature.',
                'Module doc skills are extracted from doc/ai/INDEX.json + SKILL.md (+ AI-INDEX locators) into MCP memory only; never revive knowledge.auto_generate_skills.',
            ],
            'feature_skill_bundle' => [
                'rule_id' => 'requirement_implicit_analysis_skill_decision',
                'also_rule_id' => 'requirement_feature_kind_gate',
                'clarify_rule_id' => 'requirement_clarify_use_case_spec',
                'clarify_skill_id' => GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
                'clarify_command_path' => 'dev/ai-command/ai/需求澄清与用例规格.md',
                'triggers' => ['work_kind=feature', '功能', 'feature', 'ui_skill_decision=participate', '隐形需求', '需求澄清', '用例规格'],
                'required_when' => 'ui_skill_decision=participate',
                'required' => [
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                ],
                'also_require_acceptance_type' => 'shentu',
                'acceptance_rule_id' => 'acceptance_phase_requires_shentu',
                'closeout_rule_id' => 'closeout_requires_huishen',
            ],
            'engineering_team_bundle' => [
                'rule_id' => 'engineering_team_for_new_requirements',
                'skill_id' => GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM,
                'host_alias' => 'weline-engineering-team',
                'command_path' => 'dev/ai-command/ai/工程团队.md',
                'parent_role' => '项目经理',
                'exempt' => ['plan_complexity=simple', 'content_ops_skills_skip_mcp'],
                'minutes_dir' => 'doc/开发/team/{slug}/',
                'session_path' => 'doc/开发/session/{slug}.md',
                'session_template' => 'dev/ai-command/ai/templates/requirement-session.md',
                'minutes_extra' => [
                    'roster.md',
                    'channel/{thread}.md',
                    'surfaces.md',
                    'components.md',
                    'contracts.md',
                    'deps.md',
                    'meetings/align-freeze.md',
                    'meetings/component-negotiate.md',
                    'meetings/{seat}-review.md',
                    'meetings/acceptance-ui.md',
                    'meetings/acceptance-prototype.md',
                ],
                'stop_work' => '停工汇报',
                'principles' => [
                    'requirement_session_dashboard',
                    'pm_plan_lifecycle',
                    'framework_first',
                    'dual_track_all',
                    'component_first',
                    'acceptance_substantive_signoff',
                    'ui_prototype_gate_before_test',
                    'team_flow_on_contracts',
                    'one_seat_one_agent',
                    'peer_talk_via_channel',
                    'seat_skill_mirrors',
                    'findings_wake_pm',
                    'requirement_issuer_owns_acceptance',
                    'closeout_related_web_urls',
                ],
                'dual_track' => true,
                'review_lanes' => 'per_triggered_seat',
                'core_roster' => [
                    '项目经理', '需求分析', '领域探查', '架构师', '后端', '前端', 'UI', '原型', '测试', '安全', '文档',
                ],
                'framework_seats' => [
                    '扩展点', '事件', '数据分析', '查询', 'Taglib', 'Hook', 'Provider', '翻译工程师', 'ACL', 'Setup', '电商顾问', 'API', '部件开发工程师', '主题开发工程师', '支付开发工程师', '性能检查工程师', '提示词优化工程师',
                ],
                'acceptance_signoff' => ['UI', '原型'],
                'acceptance_gate_order' => [
                    'specialty_reviews_pass',
                    'ui_and_prototype_review_pass',
                    'tester_execution_pass',
                    'pm_huishen_pass',
                    'user_report_allowed',
                ],
                'component_negotiate' => ['原型', 'UI', '主题开发工程师'],
                'one_seat_one_agent' => true,
                'peer_talk' => [
                    'channel_dir' => 'doc/开发/team/{slug}/channel/',
                    'roster_path' => 'doc/开发/team/{slug}/roster.md',
                    'pm_role' => 'switchboard',
                    'result_waiting_peer' => 'waiting_peer',
                    'result_waiting_acceptance' => 'waiting_acceptance',
                ],
                'utterance' => [
                    'simple' => '监工:',
                    'team_parent' => 'Team:项目经理:',
                    'team_relay' => 'Team:{席位}:',
                    'example' => 'Team:项目经理:',
                    'relay_example' => 'Team:架构师:',
                ],
                'seat_skill_mirrors' => self::engineeringTeamSeatSkillMirrors(),
            ],
            'css_or_theme_skill_bundle' => [
                'rule_id' => 'css_or_theme_requires_ui_prototype_theme_skills',
                'triggers' => ['css', 'CSS', '主题', 'theme'],
                'required' => [
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    [
                        'role' => 'theme',
                        'host_skill' => 'weline-theme-development',
                        'mcp_skill_id' => GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT,
                        'fetch' => 'get_skill',
                    ],
                ],
            ],
            'image_attachment_shentu_bundle' => [
                'rule_id' => 'user_image_attachment_triggers_shentu',
                'hard_trigger' => 'any_user_message_image_or_screenshot_attachment',
                'triggers' => [
                    'image_attachment',
                    'screenshot_attachment',
                    '用户附图',
                    '粘贴图',
                    '截图附件',
                    '附图',
                    '发图即审',
                    '审图',
                    '审查图',
                    'UI 审图',
                    '截图审查',
                    '线稿',
                    '抽取线图',
                    '原型调整',
                    '改 UI',
                    '调样式',
                    'CSS',
                ],
                'command_path' => 'dev/ai-command/theme/审图.md',
                'host_skill' => 'weline-ui-shentu',
                'scope_note' => 'Includes admin/CMS/error/product UI screenshots; not limited to storefront retail/B2B. Non-error ui_shot defaults to UI modification. Shot-only messages default to UI+prototype audit + wireframe + joint skills.',
                'shot_intent' => [
                    'error_shot' => 'prioritize_exception_root_cause_fix_keep_error_ui_readable',
                    'ui_shot' => 'default_require_ui_modification_fix_checklist_fails',
                ],
                'silent_shot_default' => [
                    'when' => 'image_only_or_arrows_only_user_message',
                    'intent' => 'ui_and_prototype_audit_of_visible_surfaces',
                    'forbid' => [
                        'treat_as_prior_chat_confirmation',
                        'treat_as_chat_illustration',
                        'skip_ef_because_looks_like_context_proof',
                        'critique_only_without_ui_fix',
                    ],
                ],
                'joint_pipeline' => [
                    'extract_structural_wireframe',
                    'prototype_adjustments',
                    'frontend_design_aesthetics',
                    'theme_css_tokens_via_weline_theme_development',
                ],
                'required_actions' => [
                    'read_every_attached_image',
                    'classify_web_ui_or_non_frontend',
                    'classify_error_shot_or_ui_shot',
                    'ui_shot_defaults_to_ui_modification',
                    'extract_structural_wireframe_line_sketch',
                    'provide_prototype_adjustments',
                    'for_web_ui_run_checklist_and_fix_fails',
                    'judge_humanization_and_aesthetics_with_frontend_design_and_prototype',
                    'judge_theme_fit_with_weline_theme_development',
                    'joint_frontend_design_prototype_theme_same_turn',
                    'silent_shot_defaults_to_ui_prototype_not_confirmation',
                ],
                'review_dimensions' => [
                    'humanization',
                    'aesthetic_standards',
                    'theme_fit',
                    'wireframe_structure',
                    'prototype_adjustment',
                ],
                'required_skills_for_web_ui' => [
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    [
                        'role' => 'theme',
                        'host_skill' => 'weline-theme-development',
                        'fetch' => 'get_skill',
                    ],
                ],
                'force_ui_skill_participate' => true,
                'required_engineering_seats_when_web_ui' => [
                    'when' => 'complex_engineering_team',
                    'seats' => ['原型', 'UI', '前端', '主题开发工程师'],
                    'simple_supervisor' => 'load_prototype_frontend_design_theme_skills_same_turn_no_Team_prefix',
                    'forbid' => [
                        'ui_skill_decision_skip_when_web_ui_image',
                        'treat_image_as_chat_illustration',
                        'staff_frontend_without_prototype',
                    ],
                ],
                'missing_host_skills_gate' => [
                    'check' => 'available_skills_or_agent_store',
                    'required_host_skills' => ['frontend-design', 'prototype'],
                    'on_missing' => [
                        'prompt_user_visible_warning',
                        'self_install_to_cursor_agent_store',
                        'read_skill_bodies_before_ef_pass',
                    ],
                    'forbid' => [
                        'silent_skip',
                        'pass_ef_without_skills',
                        'ask_user_to_install_in_settings_only',
                    ],
                    'prompt_template' => '⚠ 审图依赖技能缺失：缺少「UI 技能 frontend-design」和/或「原型技能 prototype」。正在自行安装/挂载到本机 Cursor Agent Store；补齐并 Read 正文前，不得对 E（人性化）/ F（规范美观）写 pass。',
                    'install_targets' => [
                        'cursor_agent_store_skills_dir',
                        '~/.cursor/skills/{frontend-design,prototype}/',
                    ],
                ],
            ],
            'product_optimize_detail_suite_bundle' => [
                'rule_id' => 'product_optimize_triggers_detail_suite',
                'hard_trigger' => 'product_pdp_url_with_optimize_intent',
                'mcp' => 'skip',
                'authority' => 'host_repo_doc',
                'hierarchy' => 'parent_contains_three_parallel_children',
                'parent' => [
                    'triggers' => ['产品优化', '商品优化', 'product optimize', '优化主图', '修主图', '整品优化'],
                    'command_path' => 'dev/ai-command/product/产品优化.md',
                    'host_skill' => 'ecommerce-product-optimize',
                    'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-optimize/SKILL.md',
                    'must_launch_three_subagents' => true,
                    'subagent_slots' => ['image', 'detail', 'i18n'],
                ],
                'children' => [
                    'image' => [
                        'slot' => 1,
                        'triggers' => ['主图优化', '规格图优化', '修主图', '优化主图'],
                        'host_skill' => 'ecommerce-product-image',
                        'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-image/SKILL.md',
                        'image_pipeline' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/companions/weline-image-pipeline.md',
                    ],
                    'detail' => [
                        'slot' => 2,
                        'triggers' => ['详情优化', '商详优化', '详情页优化', '商品详情优化', 'PDP优化', 'detail optimize', '修商详'],
                        'command_path' => 'dev/ai-command/product/详情优化.md',
                        'host_skill' => 'ecommerce-detail-suite',
                        'host_skill_alias' => 'ecommerce-detail-processing',
                        'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/SKILL.md',
                    ],
                    'i18n' => [
                        'slot' => 3,
                        'triggers' => ['翻译优化', '商品翻译', '多语补全', 'locale leak', 'product i18n', '翻译没做'],
                        'command_path' => 'dev/ai-command/product/翻译优化.md',
                        'host_skill' => 'ecommerce-product-i18n',
                        'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-i18n/SKILL.md',
                    ],
                ],
                'child' => [
                    'triggers' => ['详情优化', '商详优化', '详情页优化', '商品详情优化', 'PDP优化', 'detail optimize', '修商详'],
                    'command_path' => 'dev/ai-command/product/详情优化.md',
                    'host_skill' => 'ecommerce-detail-suite',
                    'host_skill_alias' => 'ecommerce-detail-processing',
                    'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/SKILL.md',
                ],
                'triggers' => [
                    '详情优化',
                    '商详优化',
                    '产品优化',
                    '商品优化',
                    '详情页优化',
                    '商品详情优化',
                    'PDP优化',
                    'product optimize',
                    'detail optimize',
                    '/product/',
                    'product_pdp_url',
                    '优化主图',
                    '修商详',
                    '修主图',
                    '翻译优化',
                    '商品翻译',
                    '多语补全',
                    '主图优化',
                    '规格图优化',
                ],
                'command_path' => 'dev/ai-command/product/产品优化.md',
                'child_command_path' => 'dev/ai-command/product/详情优化.md',
                'i18n_command_path' => 'dev/ai-command/product/翻译优化.md',
                'host_skill' => 'ecommerce-product-optimize',
                'child_host_skill' => 'ecommerce-detail-suite',
                'image_host_skill' => 'ecommerce-product-image',
                'i18n_host_skill' => 'ecommerce-product-i18n',
                'host_skill_alias' => 'ecommerce-detail-processing',
                'repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-optimize/SKILL.md',
                'child_repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/SKILL.md',
                'image_repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-image/SKILL.md',
                'i18n_repo_skill_path' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-i18n/SKILL.md',
                'image_pipeline' => 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/companions/weline-image-pipeline.md',
                'scope_note' => 'PARENT 产品优化 MUST launch 3 parallel subagents + TWO parent review passes (审查#1/#2) against child gates; FAIL→named rework. Skip data-weds=xq for detail unless force; locale leak / non-1:1 main still force ①/③. SOP not in weline-product-knowledge.',
                'skip_marker' => [
                    'attr' => 'data-weds="xq"',
                    'comment' => '<!--weds:xq-->',
                    'compat' => ['data-weline-detail-suite='],
                    'force_triggers' => ['强制重做', '重跑优化', '忽略已优化标记', '--force', '翻译没做', '糊图'],
                ],
                'required_actions' => [
                    'check_skip_marker_data_weds_xq_before_work',
                    'route_parent_or_child_by_trigger',
                    'parent_must_launch_three_parallel_subagents',
                    'pass_skill_and_command_paths_into_each_subagent_prompt',
                    'parent_must_invoke_child_detail_optimize',
                    'parent_must_invoke_child_i18n_optimize',
                    'parent_must_invoke_child_image_pipeline',
                    'parent_review_pass_1_against_child_skill_gates',
                    'named_rework_failing_slots_with_defect_list',
                    'parent_review_pass_2_against_child_skill_gates',
                    'claim_done_only_after_review_pass_2_all_pass',
                    'read_ecommerce_detail_suite_and_image_pipeline',
                    'resolve_product_from_url_or_context',
                    'lock_target_ar_from_catalog_canvas_or_square_card',
                    'peel_defects_then_ai_outpaint_to_target_ar',
                    'class_audit_main_gallery_variant',
                    'remediate_blur_fill_pads_denoise_no_empty_upscale_no_cover_skinny',
                    'detail_layout_and_selling_points_unless_narrowed',
                    'detect_default_website_locales_and_field_complete_translate',
                    'write_skip_marker_data_weds_xq_on_detail_root',
                    'browser_cache_off_acceptance_and_close',
                ],
                'forbid' => [
                    'treat_parent_product_optimize_as_same_entry_as_child_detail',
                    'parent_finish_without_three_subagents',
                    'parent_finish_images_without_invoking_child',
                    'parent_finish_detail_without_i18n_subagent',
                    'parent_claim_done_without_dual_review_passes',
                    'parent_deliver_on_subagent_report_without_gate_check',
                    'vague_rework_without_named_defects',
                    'copy_review1_as_review2_without_rescan',
                    'child_claim_main_gallery_variant_full_set_without_parent',
                    'cover_crop_to_skinny_as_ar_restore',
                    'critique_only',
                    'rembg_cutout',
                    'solid_pads',
                    'scene_color_pad_as_outpaint',
                    'black_studio_pad_as_outpaint',
                    'blur_fill',
                    'empty_upscale',
                    'fake_edge_smear_outpaint',
                    'bare_product_url_without_optimize_intent',
                    'reoptimize_when_data_weds_xq_present_without_force',
                    'buyer_visible_marketing_copy_as_skip_marker',
                    'use_1688_or_source_attr_as_skip_marker',
                    'skip_images_while_claiming_parent_product_optimize_done',
                ],
            ],
            'blog_article_methodology_bundle' => [
                'rule_id' => 'blog_article_methodology_gate',
                'hard_trigger' => 'blog_article_create_or_review_intent',
                'mcp' => 'skip',
                'authority' => 'host_repo_doc',
                'modes' => ['create', 'review', 'remediate'],
                'triggers' => [
                    '新建文章',
                    '写文章',
                    '写博客',
                    '博客文章',
                    '精写文章',
                    '审查文章',
                    '文章可行性',
                    '审博客',
                    '文章审查',
                    '修文章',
                    '补全文章语种',
                    '文章导向',
                    'blog article',
                    'new blog post',
                    'review blog article',
                    '/blog/',
                ],
                'command_path' => 'dev/ai-command/blog/新建文章.md',
                'host_skill' => 'weline-blog-article',
                'repo_skill_path' => 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/SKILL.md',
                'methodology_path' => 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/methodology.md',
                'review_checklist_path' => 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/review-checklist.md',
                'scope_note' => 'HARD for Blog long-form create/review/remediate. Research before claims; Blog not CMS body; provenance images; BlogPostAdminService only; all default-website locales; no Ollama unless user asks; entry links /blog/{slug} not search; clear ThemeRuntimeCacheCleaner after Catalog/template link edits. Not product 详情优化.',
                'required_actions' => [
                    'read_skill_and_command_before_article_work',
                    'route_create_review_or_remediate',
                    'web_research_sources_before_factual_claims',
                    'blog_not_cms_for_long_form_body',
                    'open_license_provenance_images_no_ai_fakes_as_evidence',
                    'write_via_blog_post_admin_service_only',
                    'translate_all_default_website_locales',
                    'forbid_ollama_unless_user_explicit_this_turn',
                    'entry_links_to_blog_slug_not_search_q',
                    'clear_theme_runtime_cache_after_link_edits',
                    'review_checklist_pass_fail_with_feasibility_verdict',
                    'remediate_when_user_asks_fix_no_critique_only',
                    'browser_cache_off_acceptance_and_close',
                ],
                'forbid' => [
                    'invent_craft_facts_without_sources',
                    'ai_generated_textile_as_museum_evidence',
                    'translate_only_en_us_claiming_done',
                    'cms_page_as_blog_long_form_body',
                    'search_q_entry_claiming_article_delivery',
                    'skip_theme_cache_clear_after_catalog_link_change',
                    'critique_only_when_user_asked_to_fix',
                    'use_product_detail_optimize_for_blog_prose',
                ],
            ],
            'catalog' => $fullSummary,
            'catalog_counts' => [
                'total' => count($fullSummary),
                'workflow' => count($workflowSummary),
                'module_doc' => max(0, count($fullSummary) - count($workflowSummary)),
            ],
            'commands' => $repository !== '' ? DocSkillCatalog::extractCommands($repository) : [],
            'greeting' => $greeting,
            'extract_skills_command' => [
                'triggers' => ['提取技能', 'list skills', 'mcp skills'],
                'path' => 'dev/ai-command/ai/提取技能.md',
                'action' => 'Scan module doc skill indexes into MCP catalog and print the full skill + command list.',
            ],
        ];
    }

    /**
     * Per-seat specialized skill/doc mirrors for engineering_team_bundle.
     * Parent MUST paste base skeleton + this seat's prompt_increment; seat MUST load listed skills before work.
     *
     * @return array<string, mixed>
     */
    public static function engineeringTeamSeatSkillMirrors(): array
    {
        $fe = GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT;
        $taglib = GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL;
        $hook = GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION;
        $event = GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION;
        $tplI18n = GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N;
        $csvI18n = GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV;
        $upgrade = GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE;
        $webui = GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT;
        $clarify = GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE;
        $team = GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM;
        $apiSdk = GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT;
        $widget = GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT;
        $themeDev = GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT;
        $payment = GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT;
        $visitor = GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS;
        $ecommerce = GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR;
        $perf = GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK;
        $promptOpt = GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION;
        $translation = GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER;

        $translationSeat = [
            'kind' => 'framework',
            'mcp_skill_ids' => [$translation, $tplI18n, $csvI18n],
            'host_skills' => ['weline-translation-engineer'],
            'authoritative_docs' => [
                'dev/ai-command/ai/翻译工程师.md',
                'dev/ai-command/ai/工程团队.md',
                'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
                'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
            ],
            'must_query_scope' => '全站漏译巡检；collect→对比；模块 CSV 仅 zh+en；其它已选 locale→系统词典/实体；抽检≥1 非中英；不代跑商品翻译优化',
            'prompt_increment' => '你是翻译工程师（Team:翻译工程师:；一代别名键 i18n）——本职是全站界面/流程文案与活跃·默认 locale 匹配巡检，发现问题即译修（品牌/商标/专有名词除外），不是旁听、不是商品翻译小队。\n'
                . 'HARD：开工前 get_skill(translation_engineer|weline-translation-engineer)+get_skill(template_i18n)+get_skill(module_i18n_csv)，并 Read 翻译工程师.md + 模块翻译CSV规范.md。禁止整段粘贴技能正文。\n'
                . '标准流程（硬）：解析 WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT) → php bin/w i18n:collect → 源串默认简中 → 对比缺口 → 翻译落盘 → 再 collect → 抽检≥1 非中英 locale（如 ru_RU）。\n'
                . '模块 CSV 格式边界（硬）：仅 zh_Hans_CN + en_US；禁止写非中英模块 CSV。禁止把「仅中英」写成「其它语种默认不做」——工程改用户可见文案（Theme/政策/FAQ Hub/实体/营销 chrome）同波须覆盖默认站每一个已选 locale：zh/en→模块 CSV+collect；其它→系统词典 upsert+publishLocale（及 FAQ 实体 locale 行）。对齐 active_locale_must_show_target_language；用户提「翻译」见 user_mentions_translation_all_default_website_locales。\n'
                . '巡检修复（硬）：非中文环境下禁止继续露中文正文或错误回落英文 catalog（专有名词除外）；en_US 第二列禁止中文 source 占位；禁止为修漏译把模板源串改成英文。\n'
                . '边界：内容运营「翻译优化/商品翻译」走 ecommerce-product-i18n / product/翻译优化.md，禁止本席代跑；内容运营不拉本席。\n'
                . '双轨：巡检施工 + 复审（CSV+词典/实体+collect+活跃 locale 抽检证据；meetings/翻译-review.md）。无法闭环 → escalate + @项目经理：请立刻组队解决。',
        ];

        return [
            'schema_version' => 'seat-skill-mirrors.v1',
            'authority' => 'dev/ai-command/ai/工程团队.md#席位专项技能镜',
            'rule' => 'PM MUST paste base skeleton + this seat mirror into every subagent prompt; seat MUST get_skill/Read listed mcp_skill_ids and authoritative_docs before coding or reviewing; FORBID coding from generic skeleton alone.',
            'load_order' => [
                'get_skill(engineering_team|weline-engineering-team) or Read 工程团队.md',
                'get_skill each mcp_skill_id (when present)',
                'Read each authoritative_docs path',
                'Read host_skills thin mirrors only as reminders—MCP/docs win',
            ],
            'seats' => [
                '项目经理' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$team],
                    'host_skills' => ['weline-engineering-team'],
                    'authoritative_docs' => [
                        'dev/ai-command/ai/工程团队.md',
                        'dev/ai-command/ai/templates/requirement-session.md',
                        'app/code/Weline/Ai/doc/AI工程交付流程.md',
                    ],
                    'must_query_scope' => 'SESSION 记账/计划生命周期/DoD 检查/编制波次/通道交换机/停工与汇审；不抢施工文件；不替代专席技术复审',
                    'prompt_increment' => '你是项目经理：SESSION 唯一记账人 + 计划生命周期主人 + 交换机。职责：审规划缺口→派人→监控→收交付做 DoD/契约/证据/范围检查→等测试过关→再复检→返工记 SESSION 再拉人→全部计划项 closed + 汇审通过才汇报。禁止扮演其它席位写码/签收；禁止替代架构/专席合规/代码级复审。拉起子智能体时必须粘贴通用骨架 + 该席 seat_skill_mirrors 增量；登记 roster agent_id。\n'
                        . 'HARD（requirement_session_dashboard）：立项即维护 doc/开发/session/{slug}.md（模板 requirement-session.md）；仅本席可改 SESSION；未完成清单非「无」禁止宣称完成。\n'
                        . 'HARD（pm_plan_lifecycle / notify_pm）：席位每次 closed|escalate|waiting_peer|waiting_acceptance 必通知本席；同回合 DoD 检查并更新 SESSION（计划项/交付通知日志）；发现 escalate 必须开 SESSION 子 plan_id（登记 issuer_seat + issuer_acceptance=pending），未测试+复检+发起方签收禁止 closed；deps 已满足席可并行，记账不全球串行。\n'
                        . 'HARD（ui_prototype_gate_before_test · acceptance_gate_order）：UI in_scope 时硬顺序 specialty_reviews_pass → ui_and_prototype_review_pass → tester_execution_pass → pm_huishen_pass → user_report_allowed；禁止测试与 UI/原型并行抢跑；UI/原型 fail 须 resume 开发子智能体再审；测试 pass 后本席汇审通过才可向用户汇报。\n'
                        . 'HARD（findings_wake_pm）：收到专席 escalate（含电商顾问「要开发什么」dev_ask）后同回合立刻开 channel、拉起/resume 相关席位开会并安排施工解决——不用 Issue 任务列表积压；必须落 SESSION 计划项；禁止只转述发现却不组队。'
                        . '若 escalate 来自电商顾问领域决策：同回合组队做技术怎么开发讨论（需求分析∥架构∥已触发施工专席；顾问只审业务/运营不跑偏）。\n'
                        . 'HARD（requirement_issuer_owns_acceptance）：进度节点（施工 closed/测试 pass/汇审前/每回合监控仍 open）必须写可读进度 + @发起席：请验收进度 + resume 发起席；关 plan_id / 汇审前须 issuer_acceptance=pass；禁止甩手场景漏叫醒。\n'
                        . 'HARD（related_web_urls · 交付地址）：汇审通过后向用户汇报完成时必须写「交付地址」小节，汇总 SESSION 相关入口 + 各席 related_web_urls + 测试探活地址；格式服从 feature_delivery_urls / closeout_delivery_reminder；禁止只说已完成不列地址；纯逻辑无 Web 写 N/A。',
                ],
                '需求分析' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$clarify],
                    'host_skills' => ['weline-req-clarify'],
                    'authoritative_docs' => [
                        'dev/ai-command/ai/需求澄清与用例规格.md',
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                    ],
                    'must_query_scope' => '澄清/EARS+UC/框架映射；不改业务码',
                    'prompt_increment' => '开工前 get_skill(requirement_clarify_use_case|weline-req-clarify) 并 Read 需求澄清与用例规格.md。只写规格澄清（含框架机制映射），禁止改 PHP/phtml/CSS。触及电商（商品/目录/购物车/结账/订单/支付政策/运费/站店渠/促销/退换货/跨境/首页落地页运营设计/获客投放活动策划）时：立项波必须与电商顾问并行（真实子智能体）；须经 channel 或同波回报读取顾问 policy_notes/ops_notes/design_brief/逻辑约束并并入规格「顾问约束」；顾问未表态前不得把电商主路径 UC 意图标为可进对齐冻结；冲突时顾问否决阻断冻结，需求分析执笔全文但不得以「规格已写完」跳过顾问。',
                ],
                '领域探查' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$team],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                    ],
                    'must_query_scope' => '只读探查扩展点/冲突/复用面',
                    'prompt_increment' => '只读。优先 resolve_task_context + 扩展点选型.md；产出冲突与候选机制清单，禁止写业务码。',
                ],
                '架构师' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$team],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                        'app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                        'app/code/Weline/Framework/doc/统一缓存范围与性能优化.md',
                        'dev/ai-command/ai/性能检查.md',
                    ],
                    'must_query_scope' => '机制选型/surfaces/解耦；含热路径时与性能检查工程师共同定制优化方向；对接契约否决权',
                    'prompt_increment' => '你是架构师：方案必须映射扩展点选型表；产出/核对 surfaces.md；禁止跨模块直调 Service/Model；重大架构矛盾 escalate 停工，不得私改已冻 UC。\n'
                        . 'HARD（与性能检查工程师协作）：触及热路径/缓存/列表/N+1/慢请求，或 roster 已勾选性能检查工程师时——必须与 Team:性能检查工程师: 经 channel 或同会共同讨论；先共同弄清框架结构落点与本需求业务特性，再审查缓存设计是否合规（HotCache/CachePolicy/scope/vary/dependencies/失效），并共同定制优化方向写入纪要与 surfaces/方案。禁止架构师单方定「加缓存」却跳过性能检查；禁止冻结含热路径/缓存的机制时缺性能检查工程师表态。本席主责机制与解耦；性能是否达标、缓存是否合规以性能检查工程师检查结论为准（可异议 escalate）。',
                ],
                '后端' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$upgrade],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/模块开发完整指南.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                        'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
                    ],
                    'must_query_scope' => 'Service/Model/升版；REST 与 BinQuery API 交给 API 席；支付壳/Provider/退款交给支付开发工程师',
                    'prompt_increment' => '遵守模块开发完整指南 + 开发标准与验收。跨模块读写走 Interface/Query/Event/Hook；改 Model/Controller/注册面必须 get_skill(module_upgrade_gate) 并升版+setup:upgrade。AbstractRestController/BackendRestController/Api/Rest/**、QueryProvider/BinQuery operation 与对外 SDK 契约由 API 席（Team:API:）负责——本席可提供归属 Service/Model，禁止代写 Rest/QueryProvider 或抢 API 席文件。Weline_Payment 壳编排、支付 Extends Provider、退款/Webhook/对账/Payable 资金面由支付开发工程师（Team:支付开发工程师:）负责——本席可提供订单/业务 Resolver 协作，禁止代写支付 Provider 或在壳里重写渠道业务。禁止改前端主题 Token/已冻 UC 意图。',
                ],
                '前端' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$fe, $taglib, $tplI18n, $csvI18n],
                    'host_skills' => ['weline-theme-development', 'weline-taglib-first', 'frontend-design', 'prototype'],
                    'authoritative_docs' => [
                        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                        'app/code/Weline/Taglib/doc/场景映射表.md',
                        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
                    ],
                    'must_query_scope' => '模板/交互/业务 JS（前台+后台）；Taglib 优先；BinQuery；开发语言默认中文；中英 CSV 必译',
                    'prompt_increment' => 'HARD【开发语言默认中文】：前端开发语言是简体中文——模板/菜单/ACL/@lang/<lang>/__() 用户可见源串必须写中文，禁止英文当默认源串。双语靠模块 i18n/zh_Hans_CN.csv + en_US.csv（不是 CSS）：zh 第二列中文身份、en 第二列真实英文；改文案同回合写齐并 php bin/w i18n:collect 抽检 locale（frontend_ui_requires_zh_en_csv + module_i18n_chinese_source_default）。开工前必须 get_skill(frontend_development|weline-theme-development) + get_skill(taglib_ui_control|weline-taglib-first) + get_skill(template_i18n)+get_skill(module_i18n_csv)，并 Read Theme开发总指南.md + theme-css-variables-only.md + 模块翻译CSV规范.md——未读主题/i18n 技能禁止写任何 phtml/CSS。后台/admin 页同样强制（backend_admin_ui_requires_frontend_theme_skills）：用 w-backend-page/w-card/w-field/w-input/w-button/w-table，对照 Marketing 后台页；搜索/工具条须紧凑横排（禁止全宽竖叠丑条）。浏览器业务 I/O 仅 BinQuery。优先嵌已有 w-*/Taglib。新建/改业务 Widget、default_injections、placement、跨模块空槽注入由部件开发工程师（Team:部件开发工程师:）负责——本席可嵌已有槽与消费已注入部件，禁止代写外国部件 JSON 注入或同身份双路径。Theme Token/版心壳/预览三态/Weline_Theme layout 由主题开发工程师负责——本席消费 Token 与 w-*，禁止代写 variables/_*.css 或 Theme 壳层；design 皮肤勿改默认 Theme/view/theme。',
                ],
                '主题开发工程师' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$themeDev, $fe],
                    'host_skills' => ['weline-theme-development', 'frontend-design', 'prototype'],
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
                        'app/code/Weline/Theme/doc/部件开发指南.md',
                        'app/code/Weline/Theme/doc/开发/spec/required-default-always-present.md',
                        'app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/align-freeze.md',
                        'app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/席位底线补钉.md',
                        'dev/ai-command/ai/工程团队.md',
                    ],
                    'must_query_scope' => 'area frontend|backend；四层；必装永远存在(user_deleted)；席位底线>他席优化；Weline.Api禁fetch；JS declare-only；浮层；Editor双入口/预览同构；layoutType斜杠；结构缓存键；scan≠disk:compile；publish-not-found；ui:audit；Factory Reset禁区；theme_binding；语义色+度量Token；weline-code；版心',
                    'prompt_increment' => '你是主题开发工程师（Team:主题开发工程师:）。一代兼容：旧 Team:主题: ≡ 本席。HARD【席位底线·theme_seat_integrity_over_peer_requests】：主题正确完整工作优先于他席/PM「性能/简化/优化」要求；禁拆 header/footer/nav/版心壳；禁丢无 user_deleted@{versionId} 的必装；**无合格方案（能解决问题且保完整性）→ 可且应驳回**；冲突→channel 异议+escalate @项目经理（架构师+性能+部件）；禁止先改烂再汇报。详见 主题开发.md §席位底线。HARD【默认注入经布局固化·必须记住】：系统真做法——无 user_deleted@{versionId} 且槽存在时，required JSON default_injections 必须固化进布局模板（与主题/版本无关）；无模板→激活主题运行期动态固化；插件注入变更→全主题重固化涉及布局；遗漏=固化方案问题；布局内嵌必装同保证；禁止把「壳完整/编辑器不回填/请求期 overlay」当成主路径或省略理由（required_default_always_present_without_user_deleted）。HARD【work_mode+area】：未声明 work_mode∈{default_theme,design_theme,theme_module_runtime} 与 area∈{frontend,backend} 禁止落 Theme/design。HARD【四层+公共库】：layout/partial/component/widget；①components/*.phtml ②statics/ui（Weline UI，含后台 w-backend-page）；widget≠component。HARD【layout.json+layoutType】：布局旁种子归本席；外国注入→部件席；XOR；layoutType 用斜杠嵌套（account/login），禁 account.login。HARD【请求/JS/浮层】：仅 Weline.Api.*；禁 fetch/ajax；业务模块 JS 仅 weline.modules+data-weline-load/declare；部件 CSS/可执行 JS 全部外置，layout-source/source 固化位置与禁内联见 widget_static_assets_bake_to_head + 部件静态资源固化规范.md；浮层仅 anchored-float/data-w-component。HARD【编译矩阵】：modules→welineModules；Editor→welineUi；Meta→scan-variables；店面CSS→disk:compile；静态→theme:upgrade；404→publish-not-found-static；壳→theme:ui:audit。HARD【binding/缓存】：正式店面=published Scoped Release；theme:active sync binding；禁 clearNonGlobalCaches(null)；禁手写 @static?v=；theme:scope:migrate 未实现禁发明。HARD【预览同构】：画布禁 start-preview/种Token；仅 #btnFrontendPreview 可 Token；禁 preview early-return（preview_storefront_delivery_parity）。HARD【语义色+度量】：_light→_default→品牌→_dark；品牌禁删 secondary/status；间距/圆角/字号用 --weline-space/radius/font-size；禁裸 px。HARD【破坏性】：Factory Reset=清库级（scope/layout/injection/weline_theme/Meta/词典）；仅绿场+本回合显式授权+精确 RESET+先备份DB；生产禁；勿当清缓存；禁自写SQL删scope（migrate未实现）；排障先禁缓存Browser/binding/compile再escalate。HARD【weline-code+禁区】：section/slot wrapper=section 必填；禁改 generated/view/tpl/pub当源。HARD【版心/挂盘】：theme-layout-content-width；head colors→variables→foundation→theme.css；design 禁同 key 覆盖 theme.css/js。开工 get_skill(theme_development|frontend_development|weline-theme-development)；Read 主题开发.md 全 P0 节+view/theme/README+required-default-always-present.md。业务 templates→前端；外国注入→部件席。施工+合规复审双轨。',
                ],
                'UI' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$fe, $webui],
                    'host_skills' => ['frontend-design', 'prototype', 'weline-theme-development', 'weline-ui-shentu'],
                    'authoritative_docs' => [
                        'dev/ai-command/theme/审图.md',
                        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    ],
                    'must_query_scope' => '视觉落地/审美复审/成果审查（前台+后台；可打回开发）',
                    'prompt_increment' => 'HARD：施工前 get_skill(weline-theme-development|frontend_development)；frontend-design + prototype + 主题技能同回合。构图可参考 frontend-design，色板/间距/组件类名必须以 Theme Token + Weline UI 2.0 为准（含后台 w-backend-page）。禁止交「功能能用但像未套主题的原始表单」。HARD（ui_prototype_gate_before_test）：审查开发成果有否决权——禁缓存打开活页，写 acceptance-ui.md；fail → escalate 项目经理 resume 开发子智能体；未 pass 禁止测试开跑；禁止代签。',
                ],
                '原型' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$clarify, $fe],
                    'host_skills' => ['prototype', 'frontend-design', 'weline-theme-development'],
                    'authoritative_docs' => [
                        'dev/ai-command/theme/审图.md',
                        'dev/ai-command/ai/需求澄清与用例规格.md',
                        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                    ],
                    'must_query_scope' => '线稿/IA/组件清单/成果意图审查（须映射 Theme/w-*；可打回开发）',
                    'prompt_increment' => 'HARD：原型波也必须 get_skill(frontend_development|weline-theme-development) + Read Theme开发总指南——线稿/投屏方案须点名复用的 w-* / Taglib / 既有后台 chrome，禁止画出脱离主题的「通用 SaaS 表单原型」再逼前端落地。先写 components.md 复用清单；不足必须与 UI（±主题开发工程师）开 component-negotiate。HARD（ui_prototype_gate_before_test）：审查开发成果写 acceptance-prototype.md；fail → escalate 项目经理 resume 开发子智能体；未与 UI 双 pass 禁止测试开跑；禁止代签。',
                ],
                '测试' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [$webui],
                    'host_skills' => ['local-browser-urls'],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                        'tests/e2e/README.md',
                    ],
                    'must_query_scope' => '可执行 UC/红灯骨架/e2e/WB-OP/真实业务证据；禁止自造数据自验通过',
                    'prompt_increment' => '主持对齐冻结会钉可执行 UC。get_skill(webui_browser_closeout|local-browser-urls)。正式 Playwright runner；必须真实业务通路证据（acceptance_real_business_pathway）；禁止壳层冒烟收口；禁止向用户甩测或要账号（见通用骨架本机测试账号 / local_dev_test_accounts）。\n'
                        . 'HARD（ui_prototype_gate_before_test · UI in_scope）：施工期可落红灯骨架；验收执行（UT/RT/WB/e2e）必须等 acceptance-ui.md + acceptance-prototype.md 双 pass；未过签禁止开跑。测试 pass 后交给项目经理汇审，禁止本席直接向用户宣称完成。\n'
                        . 'HARD（测试必须真 · tester_tests_must_be_real）：禁止「自己造假数据/假响应 → 再对着假数据断言 → 宣称 pass」的自欺闭环。禁止手写假 JSON/假 Model 数组冒充业务结果；禁止桩掉被测系统再断言桩结果；禁止只断言自己旁路 INSERT 的行而从未走冻结主 UC。允许：正式 runner / 真 Browser / 真实注册登录 / 经生产 Service 或正式真实通路 fixture 写入且可独立回查的证据（order_uuid/库/后台/展示单号）。证据不得只存在于测试进程内存。\n'
                        . 'HARD（抹掉自动化标志 · browser_strip_automation_flags）：WB-OP / Playwright 必须像真人浏览器，禁止带着自动化检测标志去点登录/提交/人机验证。每次开验收 Browser（与禁缓存同序）：CDP `Page.addScriptToEvaluateOnNewDocument`（或 Playwright `addInitScript`）在页面脚本前把 `navigator.webdriver` 置为 undefined/false；正式 runner 启动 Chromium 须 `--disable-blink-features=AutomationControlled` 且去掉 `--enable-automation`。禁止把「Human-machine verification failed / reCAPTCHA 拦自动化」当成 WB-OP pass 或甩测借口。\n'
                        . 'HARD（非抢占后台 · browser_operator_non_preemptive）：WB-OP 默认后台执行，禁止抢占用户 IDE/对话焦点。Cursor：`browser_navigate` 省略 `position`；禁止默认 `position:"active"`。后台≠免测；仅用户明确要求观看时才可前台。\n'
                        . 'HARD（related_web_urls）：验收 pass 的 closed 回报必须填 related_web_urls 为探活过的前台/后台/API 地址清单（与交付地址同源），交给项目经理汇总；禁止空报 pass/closed。\n'
                        . '被支付开发工程师拉起时（HARD）：必须用宿主真实 Browser（WB-OP、禁缓存、已抹自动化标志）把本波触及的支付方式整条前端流程过一遍（选方式→提交→成功/失败/取消；触及则含退款/Webhook），回报 pass/fail + transaction_no/order_uuid；禁止用单测/curl 代替真浏览器。',
                ],
                '安全' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/安全响应头策略.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                    ],
                    'must_query_scope' => 'ACL/凭据/输入校验/跨站；只审不改架构',
                    'prompt_increment' => '复审波只审：凭据、ACL、输入校验、CSP/响应头、跨站边界。禁止借审查改架构；fail 写 findings+rework_owner。',
                ],
                '文档' => [
                    'kind' => 'core',
                    'mcp_skill_ids' => [],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Ai/doc/AI工程交付流程.md',
                    ],
                    'must_query_scope' => '模块 doc 三文档契约对齐',
                    'prompt_increment' => '只对齐 owning-module doc/（README/需求/功能现状/开发日志）；禁止改生产实现骗绿。',
                ],
                '扩展点' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$team],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                    ],
                    'must_query_scope' => '机制选型表/surfaces；禁跨模块直调',
                    'prompt_increment' => '写码前完成扩展点选型；产出/核对 surfaces.md；机制与实现必须一致；禁止发明未文档化扩展；禁止跨模块 new Service/Model。',
                ],
                '事件' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$event],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
                        'app/code/Weline/Framework/doc/event/README.md',
                    ],
                    'must_query_scope' => '框架 event.xml/Observer/dispatch；Visitor 像素归数据分析席',
                    'prompt_increment' => 'HARD：get_skill(event_extension) + Read 事件命名与注册规范.md。本席仅通用框架 Event（跨模块 event.xml/Observer/dispatch 命名与文档化）。事件名须已在 doc/event 文档化；本该走 Event 禁止直调。Visitor 像素/访客前端事件归数据分析席（Team:数据分析:）：禁止本席代写 pixel.js / Vendor / 报表 / Visitor 像素桥接 Observer；`Weline_Visitor/etc/event.xml` 与 `Visitor/Observer/**` 归数据分析施工，本席仅可 peer 命名/文档四件套合规。',
                ],
                '数据分析' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$visitor, $fe, $taglib],
                    'host_skills' => ['weline-visitor-analytics', 'weline-theme-development', 'weline-taglib-first', 'frontend-design'],
                    'skill_refs' => [
                        'visitor_data_analytics|weline-visitor-analytics',
                        'frontend_development|weline-theme-development',
                        'taglib_ui_control|weline-taglib-first',
                    ],
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
                    ],
                    'must_query_scope' => '整模块 Weline_Visitor（含本模块 event.xml/Observer 像素桥接）：像素 runtime/字典/事件链/Vendor/沙盒/去重/报表；站店渠≠路径过滤；须引用前端技能',
                    'prompt_increment' => '你是数据分析专席（Team:数据分析:）——整个 Weline_Visitor 模块归属本席（含报表后端、本模块 etc/event.xml 与 Observer/**）。专职前端访客/像素事件；同时是开发席。HARD 技能引用（开工前必须 get_skill，禁止只读通用骨架）：① get_skill(visitor_data_analytics|weline-visitor-analytics)；② get_skill(frontend_development|weline-theme-development)；③ get_skill(taglib_ui_control|weline-taglib-first)。必读路径见本席 authoritative_docs（像素拓展使用指南、Visitor_Pixel_GTM_GA4_系统设计含§10热温冷、定稿合同、事件链/访客像素标签、conversion-event-dedupe、数据分析功能使用指南、store-saleschannel-scope、Theme/Taglib/Weline.Api）；禁止把上述文档正文粘进本增量。\n'
                        . '【与事件席分工·非文件类型禁令】本席 ≠ Team:事件:（通用 Framework Event）。禁止产品混岗：事件席不写 pixel.js/Vendor/报表/本模块像素 runtime；本席不接管无关模块 Framework/doc/event。本席拥有 Visitor 内像素桥接 Observer 及 Weline_Visitor::event_chain_collect / taglib_pixel 契约；业务模块 Observer 落归属模块——本席定契约并复审扇出/字典/链；禁止事件席代写 Visitor 像素桥接。\n'
                        . '【术语】框架 Event=event.xml/Observer/dispatch；Visitor「事件」=事件字典/事件链/PixelEventVendor扇出/沙盒——勿把「访客事件」派到 Team:事件:。\n'
                        . '【采集·双通道·字典·去重】只走 WelinePixel.track / weline-pixel:: / data-pixel-event|data-visitor-event|data-cta-event|data-ga-event；禁旁路 dataLayer/私接三方；禁用 Observer 替代店面采集主路径。Weline Pixel 入库为主真源；GtmBridge 为唯一 GTM dataLayer 出口；开 GTM 必须关 GA4 直连；page_view 不由 Pixel 重复 push。改事件名/映射须动 etc/event_dictionary.json。complete_event 须 __event_chain_complete；禁通用 checkout_success 作闭环名。去重：先 sandbox.emit → purchase 族占用 → 再外发；主 track 禁因去重提前 return null。自定义池须 record/事件链发布。沙盒默认；ga4+gtm 禁同时 inject。报表 PixelQueryRouter 热温冷；跨模块 Query peer Team:API:；Dashboard 部件 peer Team:部件开发工程师:。细则见上列权威文档。\n'
                        . '前端底线：仅 Weline.Api→query-bin；禁 native fetch/ajax 与「HTTP主路径+BinQuery回退」。站店渠 `<w:scope>`；路径过滤 scope_json 不是 Scope。声明式埋点可 channel 请前端；复杂 runtime/Vendor/报表/本模块 phtml·JS 必须本席施工。施工+合规复审双轨。',
                ],
                '查询' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/BinQuery/README.md',
                        'app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md',
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
                    ],
                    'must_query_scope' => '跨模块读是否该走 Query；契约合规复审；实现施工交给 API 席',
                    'prompt_increment' => '你是查询专席：负责扩展点选型里「读列表/聚合→QueryProvider」的机制把关与合规复审。Read 扩展点选型 + BinQuery Provider开发指南。禁止跨模块依赖对方 Model；契约字段禁止漂移。共用 Query 核：多入口（query-bin|/bin/query|薄 REST）须同一 Provider 契约，复审时核对入口与 auth/backend_acl/external/mode 声明一致。QueryProvider/BinQuery **实现施工**由 API 席（Team:API:）负责——本席可并行复审或 escalate，禁止本席独自写完 Provider 却跳过 API 席技能镜。新 operation 须可 query:help 发现。',
                ],
                'Taglib' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$taglib],
                    'host_skills' => ['weline-taglib-first'],
                    'authoritative_docs' => [
                        'app/code/Weline/Taglib/doc/场景映射表.md',
                    ],
                    'must_query_scope' => '场景映射/官方标签；禁手写领域控件',
                    'prompt_increment' => 'HARD：get_skill(taglib_ui_control|weline-taglib-first) + Read 场景映射表。先映射再写控件；禁止手写 language/website/currency/ACL 等 select；标签属性内禁止 <?= / <?php。',
                ],
                'Hook' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$hook],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Hook/doc/Hook创建规范.md',
                    ],
                    'must_query_scope' => 'hook.php + doc/hook + view/hooks',
                    'prompt_increment' => 'HARD：get_skill(hook_extension) + Read Hook创建规范.md。必须 hook.php + doc/hook；type 段仅 partials|layouts；禁止只有 phtml 或 type 发明功能名。',
                ],
                'Provider' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$payment],
                    'host_skills' => ['weline-payment-development'],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                        'app/code/Weline/Payment/doc/payment-shell.md',
                        'app/code/Weline/Dropship/doc/dropship-shell.md',
                    ],
                    'must_query_scope' => '壳 SPI 机制/合规复审；Payment Provider 实现施工交给支付开发工程师；Dropship 等同构壳仍本席把关',
                    'prompt_increment' => '你是通用壳+Provider SPI 专席：业务只进 Extends Provider；壳 Controller 只编排（shell_provider_business_isomorph）。Payment 域（万能支付/新支付对接/退款/Webhook）**实现施工**由支付开发工程师（Team:支付开发工程师:）负责——本席可并行合规复审或 escalate，禁止本席独自写完支付 Provider 却跳过支付开发工程师技能镜。Dropship 等非支付壳 SPI 仍由本席施工+复审；对照各模块 shell/provider-development 文档。',
                ],
                '翻译工程师' => $translationSeat,
                // 一代兼容：旧 roster / 触发写 i18n ≡ 翻译工程师
                'i18n' => $translationSeat,
                'ACL' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Acl/doc/README.md',
                        'app/code/Weline/Acl/doc/multi-resource-catalog.md',
                    ],
                    'must_query_scope' => 'menu.xml / ACL 资源与入口门禁',
                    'prompt_increment' => '新后台入口必须 menu.xml + ACL 资源一致；入口无权限门 = fail。对照 Acl 模块文档，禁止只加菜单不加权限。',
                ],
                'Setup' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$upgrade],
                    'host_skills' => [],
                    'authoritative_docs' => [
                        'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
                        'app/code/Weline/Framework/doc/3-开发/模型升级顺序规则.md',
                    ],
                    'must_query_scope' => 'etc/module.php 升版 + setup:upgrade/--route',
                    'prompt_increment' => 'HARD：get_skill(module_upgrade_gate)。改 Model/Controller/event.xml/hook.php/register.php 必须同变更集升版并跑 setup:upgrade（路由面加 --route）。',
                ],
                '电商顾问' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$ecommerce],
                    'host_skills' => ['weline-ecommerce-advisor'],
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
                    'must_query_scope' => '运营策划/领域决策→escalate PM；已支持国家解析→分国政策检索；站内合规文案面（政策/顶栏/FAQ Hub·实体）；改可见串 suggested_seats 须含翻译工程师；日常运营联网研究；禁止写码',
                    'prompt_increment' => '你是电商顾问（Team:电商顾问:；别名运营策划/电商开发顾问）——运营策划+领域顾问，禁止写码（PHP/phtml/CSS/JS/XML/JSON 配置一律不动；paths_changed 必须为「无」）。本席合并原「合规」职责。\n'
                        . 'HARD：开工前 get_skill(ecommerce_advisor|weline-ecommerce-advisor)，并 Read 电商顾问.md + 工程团队.md；按任务 Read Product/Checkout/Cart/Order AI-INDEX、Payment payment-shell、Shipping 功能现状（支付施工归支付开发工程师）。\n'
                        . '工作流（硬）：日常/参会前 WebSearch 运营文章 → 活动与领域决策（要开发什么+成功标准）→ result=escalate + @项目经理：请立刻组队解决（附 suggested_seats、dev_ask）→ PM 同回合拉队技术讨论怎么开发；禁止自排施工、禁止绕过 PM 指挥技术席。\n'
                        . '强制上场：触及商品/目录/购物车/结账/订单/支付政策面/运费/站店渠/促销/退换货/跨境/首页落地页运营设计/获客投放KOL活动策划的复杂 team → **立项波与需求分析并行**拉起；对齐冻结会须 stance+supported_countries+顾问约束节（含 ops/design 要点）；技术方案会必到；未满足不得冻结。施工波不上场写码；仅 escalate 时通道表态。\n'
                        . '双轨：顾问轨（运营策划、领域决策、需求合理性、模组映射、设计 brief、ops_notes/design_brief/dev_ask）+ 政策与合规复审轨（meetings/电商顾问-review.md）。\n'
                        . '政策风控（硬）：先本机解析本站已支持国家（Shipping RegionService::getCountries / 支付支持国 / 需求收窄子集；语种仅辅助），再对相关国家 WebSearch 现行政策做合规检查与合规讨论；禁止固定只查中美、禁止凭记忆宣称合规。'
                        . '每一次参与讨论/对齐冻结/技术方案/复审表态前都必须查（不得复用过期记忆当已查）；纪要写 supported_countries + 分国要点+来源 URL/官方名+检索日期。高风险且无法降险 → escalate 或推动停工。内容运营执行（产品优化等）不拉本席代跑；本席只给策略与验收标准。\n'
                        . '站内合规文案面（硬·指针）：政策页、顶栏/营销宣称、FAQ Hub（FaqHubContent/FaqSeedCopyCatalog/w_weline_faq_item）+ FAQ 实体、Cookie/隐私 chrome——fail escalate 若改用户可见串，suggested_seats **必须含翻译工程师**（另可含主题/前端）；禁止只派主题改中英 CSV 漏词典与 FAQ 实体多语。\n'
                        . 'HARD（findings_wake_pm）：找出合规/政策/运营缺口，或「要开发什么」已定稿后，立刻 result=escalate + @项目经理：请立刻组队解决（附 suggested_seats）；不用 Issue 列表；本席禁写码，由项目经理当场组队解决。\n'
                        . 'HARD（requirement_issuer_owns_acceptance）：escalate/dev_ask 后本席 waiting_acceptance，禁甩手；被 resume 须读 PM 进度写 issuer_acceptance（店面并写 ops_acceptance）；pass 前禁对本 finding closed。',
                ],
                '性能检查工程师' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$perf],
                    'host_skills' => ['weline-performance-check'],
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
                    'must_query_scope' => '必须检查性能；深懂框架结构+业务特性；审查缓存合规；与架构师共同定制优化方向；禁拆壳药方；查出问题后拉起项目经理安排；开发后证据复审',
                    'prompt_increment' => '你是性能检查工程师（Team:性能检查工程师:）——本职是检查性能，不是旁听。HARD：开工前 get_skill(performance_check|weline-performance-check)，并 Read 性能检查.md + 统一缓存范围与性能优化.md + 扩展点选型.md。\n'
                        . '知识门槛：必须先弄清（1）框架结构——模块边界/扩展点、WLS 请求生命周期、HotCache·CachePolicy·CachePool·WLS 分层；（2）本需求业务特性——店面/后台、是否个性化或草稿、热路径段落、可复用 owner/批量入口。未写清业务特性摘要与框架映射前禁止定制优化方向。\n'
                        . '缓存合规检查（设计与复审都要做）：CachePolicy+scope/vary/dependencies 是否正确；失效是否挂 owner；有无可变 Model/个性化 HTML/草稿进共享池；有无业务平行进程内袋；有无把 DB N+1 换成 WLS RPC N+1。不合规 → 异议/否决或 review fail。\n'
                        . 'HARD【禁拆壳药方·theme_seat_integrity_over_peer_requests】：允许方向仅 HotCache/CachePolicy、批量 Query、预取、合法 FPC/编译面等；禁止把移除 header/footer/nav/版心、删无卸载必装 widget、清空 default_injections 列为优化；此类 design=否决；此类 diff=review fail+escalate。详见 性能检查.md §禁拆壳。\n'
                        . '与架构师协作（硬）：立项/对齐冻结/技术方案必须与 Team:架构师: 共同讨论，定制优化方向（目标热路径、允许机制、禁止项、证据口径）；纪要 architect_joint=true。禁止单席私定优化方向。含热路径/缓存的 UC/contracts 双方未表态不得冻结。\n'
                        . '双轨：设计检查轨 meetings/性能检查-design.md（必须检查性能）+ 实现复审轨 meetings/性能检查-review.md（必须再检查，pass/fail+证据）。施工波可写只读探针；业务返工交归属席。内容运营不拉本席。\n'
                        . 'HARD（findings_wake_pm）：设计否决、复审 fail、或无法本席闭环的性能缺口 → 写纪要证据 + result=escalate + @项目经理：请立刻组队解决（附 suggested_seats）。不用 Issue 列表；禁止本席私自排施工波或代项目经理调度。\n'
                        . 'HARD（requirement_issuer_owns_acceptance）：escalate 后 waiting_acceptance；被 resume 须读 PM 进度写 issuer_acceptance；禁甩手；pass 前禁对本 finding closed。',
                ],
                '提示词优化工程师' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$promptOpt],
                    'host_skills' => ['weline-prompt-optimization'],
                    'authoritative_docs' => [
                        'dev/ai-command/ai/提示词优化.md',
                        'dev/ai-command/ai/工程团队.md',
                        'app/code/Weline/Ai/doc/AI硬规则索引.md',
                        'app/code/Weline/Ai/doc/AI工程交付流程.md',
                        'app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
                        'app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
                        'app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
                    ],
                    'must_query_scope' => '必须优化提示词；仅重复才压缩；禁丢义；禁乱加；技能引用指针化；语义复审',
                    'prompt_increment' => '你是提示词优化工程师（Team:提示词优化工程师:）——本职是优化提示词（技能引用指针化、重复描述压缩、题词瘦身），不是旁听、不是改业务功能码。HARD：开工前 get_skill(prompt_optimization|weline-prompt-optimization)，并 Read 提示词优化.md + 工程团队.md（骨架与 seat_skill_mirrors）+ AI硬规则索引.md。\n'
                        . '改写铁律（严重）：①仅重复才改——须先标 ≥2 处同义展开证据，无证据禁止动刀；②禁止丢义——强制上场/禁止项/席位边界/产物路径/硬规则 id 压缩后仍可执行，禁止删成空泛口号；③禁止乱加——不得新增原文没有的规矩/流程/席位义务，指针只指向已有权威；④改指针≠删权威——只删副本，唯一正文必须留全义。\n'
                        . '引用规范（硬）：技能引用只写 get_skill(id|alias) + 必读路径；禁止把其它技能全文粘进席位镜/指令当「引用」。同一硬规则/清单只留一处权威展开，其它处指针。prompt_increment 只写本席独有 HARD+边界；通用骨架已有内容禁止再全文复述。\n'
                        . '压缩合法性：改后抽检强制上场、禁止项、Team 席位名、双轨产物路径未语义回退，且无新增原文义务；削弱硬规则或乱加 → 否决或 review fail。\n'
                        . '双轨：meetings/提示词优化-design.md（须含重复证据）+ meetings/提示词优化-review.md（语义复审 pass/fail）。默认可改指令/席位镜/surface 文案；业务返工交归属席。内容运营不拉本席。\n'
                        . 'HARD（findings_wake_pm）：语义冲突或无法本席闭环的压缩风险 → 立刻 result=escalate + @项目经理：请立刻组队解决（附 suggested_seats）；不用 Issue 列表；禁止只写 review 不唤醒。\n'
                        . 'HARD（requirement_issuer_owns_acceptance）：escalate 后 waiting_acceptance；被 resume 须读 PM 进度写 issuer_acceptance；禁甩手。',
                ],
                'API' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$apiSdk],
                    'host_skills' => ['weline-api-sdk'],
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
                    'must_query_scope' => '仅 REST 与 BinQuery/QueryProvider API 面；必须遵循已冻 Query 核架构与暴露面权限契约；不写业务 Service 内核、不写前端模板',
                    'prompt_increment' => '你是 API 开发工程师（Team:API:）。只开发两类接口面：① REST（AbstractRestController 系）；② BinQuery/QueryProvider（站内 query-bin + 站外 /bin/query）。\n'
                        . '【架构权威】HARD：get_skill(api_sdk_development|weline-api-sdk)；按序 Read 本席 authoritative_docs：1) align-freeze.md（已冻架构：Query 核/入口壳/例外 REST/权限矩阵/暴露面权限=契约硬要件）；2) Provider开发指南.md（权限默认拒绝+冰块真相层）；3) API接口开发规范.md；4) BinQuery/README.md。通道讨论仅背景。禁止凭记忆偏离已冻架构；硬规则 api_rest_in_owning_module + align-freeze 正文不在此复述。\n'
                        . '【核】可复用业务 I/O = 归属模块 QueryProvider；query-bin|/bin/query|可选薄 REST（#[Acl]+@Document 后 w_query）仅为入口壳。领域 Service/Model 由后端席提供。独立 REST 例外仅 multipart/流式、Webhook、登录换票、固定 SDK、框架网关壳。站内禁平行 REST+native fetch。Attribute 默认 external=false、frontend=false，暴露须显式 opt-in。\n'
                        . '【权限=契约】写了 Provider ≠ 冰块可调。frontend=true 须显式 auth；auth=backend 须合格 backend_acl（敏感写 kind=source，禁 self 独挡）。冰块→query-bin 唯一权限真相 = descriptor(auth+backend_acl)；REST #[Acl] 不可替代。每 op 交付入口×门禁行。CDN 仅 external∧read∧cdn∧visibility=public。细则见 Provider开发指南后台写模板。\n'
                        . '【施工】REST 只落归属 Vendor_Module Api/Rest；完整 @Document；后台 #[Acl]。BinQuery：Attribute+descriptor → framework:compile → query:help。禁跨模块代写 Rest/读对方 Model。不改 Theme、不抢后端 Service。施工+合规复审双轨。否决：偏离 align-freeze、缺 auth/backend_acl 却暴露、壳内重写业务、未 compile。',
                ],
                '部件开发工程师' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$widget, $fe],
                    'host_skills' => ['weline-widget-development', 'weline-theme-development'],
                    'authoritative_docs' => [
                        'app/code/Weline/Theme/doc/部件开发指南.md',
                        'app/code/Weline/Theme/doc/部件静态资源固化规范.md',
                        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                        'app/code/Weline/Theme/doc/widget-slot-attributes.md',
                        'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
                        'app/code/Weline/Widget/doc/开发指南.md',
                    ],
                    'must_query_scope' => 'Widget 注册/模板/@param/空槽/default_injections/placement；本模块标签 XOR 跨模块 JSON',
                    'prompt_increment' => 'HARD：开工前 get_skill(widget_development|weline-widget-development) 并 Read 部件开发指南.md + Theme开发总指南.md（部件放置节）+ required-default-always-present.md。HARD【必装永远存在】：无 user_deleted@{versionId} 时 required JSON default_injections 与布局内嵌必装永远存在（发布壳不得省略）。本模块布局才可用 <w:widget>/fetch 内嵌；跨模块外国部件只能空槽 + 拥有模块 JSON default_injections；禁止同一部件 JSON 与布局标签双路径（会重复两个）。placement=layout|injection 二选一。改后跑 frontend:check-theme-layout-widgets 与（适用时）frontend:check-required-injection-sibling-fetch。HARD【部件资源】：所有 CSS/可执行 JS 禁内联（含 style= 与 on*=）；静态文件以 layout-source/source 固化，layout 提前 head，普通 source 按 source-postion/source-position 定位；完整位置/去重与 SystemConfig theme_resource_files 压缩合并契约必读 部件静态资源固化规范.md（widget_static_assets_bake_to_head）；合并按实际部件前序含嵌套/无资源/layout计数，layout独立提前，不做懒加载；JS压缩含反引号保留原文。业务模块仍用 weline.modules.js + data-weline-load。本席承接全部部件相关施工；前端/主题开发工程师不得代写外国部件注入。施工+合规复审双轨。',
                ],
                '支付开发工程师' => [
                    'kind' => 'framework',
                    'mcp_skill_ids' => [$payment],
                    'host_skills' => ['weline-payment-development'],
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
                    ],
                    'must_query_scope' => '万能支付壳编排、新支付 Provider 对接、退款/退货资金面、Webhook/对账/Connect、Payable；PCI/CSP/密钥安全；改完拉测试席真浏览器闭环',
                    'prompt_increment' => '你是支付开发工程师（Team:支付开发工程师:）——支付相关专职席位。HARD：开工前 get_skill(payment_development|weline-payment-development)；Read 支付开发.md + payment-shell.md + provider-development.md（回调/状态/对账见 webhook.md、payment-state.md、payment-reconcile.md）。ProviderInterface 全量方法与参与范围细则见上述文档，禁止在本增量复述全文。\n'
                        . '万能支付：Weline_Payment 唯一壳（URL/编排/状态机/配置/幂等）；渠道差异只在 Extends Provider（shell_provider_business_isomorph）。最小交付=Provider + SystemConfig backend/{method_code}.phtml（+ checkout/CustomerGuide）；getCode/config 文件名/checkout_template_code 三者一致；禁在壳 Controller/Service 重写网关创建/退款/回调，禁按渠道分裂业务控制器。\n'
                        . '资金与安全落地：退款/退货走 Provider.refund + 壳 Refund/Ledger（refund_code+idempotency；不支持→unsupported）；金额 amount_minor。PCI 不碰卡号；CSP 由 Provider.cspDirectives 自报（禁 Framework Defaults 硬编码网关域）；密钥仅 SystemConfig 加密字段；verifyCallback/parseCallback 纯函数。施工+合规复审双轨。\n'
                        . 'HARD（验收闭环 · payment_browser_e2e_closed_loop）：代码改完 ≠ 过手。必须主动拉起真实子智能体 Team:测试:（经项目经理调度或 channel+resume），写清本波 method_code 与必测前端步骤；由测试席用宿主真实 Browser（WB-OP、禁缓存）过该支付方式全流程（选方式→提交→成功/失败/取消；触及则含退款/Webhook return）。仅当测试席 Browser pass + 耐久证据（transaction_no/order_uuid）齐全，本席合规复审才可 pass；否则只能「代码已改，真实浏览器支付通路未过」。禁只改代码不上测试；禁单测/curl/壳层冒烟冒充实浏览器；禁甩测给用户（账号见通用骨架本机测试账号）。后端/通用 Provider 席不得代写本席文件。',
                ],
            ],
        ];
    }

    /**
     * Index-only catalog listing for prepare_project / greeting (no bodies, no descriptions).
     * Full descriptions and paths remain on definitions() / get_skill / resolve_skill(includeContent).
     *
     * @return list<array<string, mixed>>
     */
    public static function summary(string $repository = '', ?string $kindFilter = null): array
    {
        $rows = [];
        foreach (self::definitions($repository) as $skill) {
            $kind = (string) ($skill['kind'] ?? '');
            if ($kind === '') {
                $kind = (($skill['surface_id'] ?? '') !== '') ? 'workflow' : 'module_doc';
            }
            if ($kindFilter !== null && $kind !== $kindFilter) {
                continue;
            }
            $row = [
                'skill_id' => $skill['skill_id'],
                'name' => $skill['name'],
                'aliases' => $skill['aliases'] ?? [],
                'kind' => $kind,
            ];
            $surfaceId = trim((string) ($skill['surface_id'] ?? ''));
            if ($surfaceId !== '') {
                $row['surface_id'] = $surfaceId;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(string $repository = ''): array
    {
        $skills = self::workflowDefinitions();
        if ($repository !== '') {
            foreach (DocSkillCatalog::extractSkills($repository) as $docSkill) {
                $skills[] = $docSkill;
            }
        }

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function workflowDefinitions(): array
    {
        $skills = [];
        foreach (GuidanceWorkflowCatalog::allSurfaces() as $surfaceId => $surface) {
            if (!is_array($surface)) {
                continue;
            }
            $aliases = [];
            foreach (self::HOST_SHELL_ALIASES as $alias => $canonical) {
                if ($canonical === $surfaceId) {
                    $aliases[] = $alias;
                }
            }
            $hostShell = is_string($surface['authoritative_skill'] ?? null)
                ? trim((string) $surface['authoritative_skill'])
                : '';
            if ($hostShell !== '' && !in_array($hostShell, $aliases, true)) {
                $aliases[] = $hostShell;
            }

            $row = self::fromSurface($surface, $aliases);
            $row['kind'] = 'workflow';
            $skills[] = $row;
        }

        foreach (self::HOST_SHELL_ALIASES as $alias => $canonical) {
            $found = false;
            foreach ($skills as $skill) {
                if (($skill['skill_id'] ?? '') === $canonical || in_array($alias, $skill['aliases'] ?? [], true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found && isset(GuidanceWorkflowCatalog::allSurfaces()[$canonical])) {
                $row = self::fromSurface(GuidanceWorkflowCatalog::allSurfaces()[$canonical], [$alias]);
                $row['kind'] = 'workflow';
                $skills[] = $row;
            }
        }

        return $skills;
    }

    /**
     * Match skills for a task query (trigger / name / alias / id substring scoring).
     *
     * @return list<array<string, mixed>>
     */
    public static function resolve(
        string $task,
        int $limit = 5,
        bool $includeContent = false,
        string $repository = '',
        bool $listAll = false,
    ): array {
        $limit = $listAll ? max(1, min(500, $limit > 20 ? $limit : 500)) : max(1, min(20, $limit));
        $taskLower = mb_strtolower(trim($task), 'UTF-8');
        if (in_array($taskLower, ['提取技能', 'list', 'list_all', 'list skills', 'mcp skills', 'all'], true)) {
            $listAll = true;
            $taskLower = '';
        }
        $scored = [];

        foreach (self::definitions($repository) as $skill) {
            $score = 0.0;
            if ($listAll || $taskLower === '') {
                $score = $listAll ? 1.0 : 0.05;
            } else {
                $haystack = mb_strtolower(implode("\n", [
                    (string) $skill['skill_id'],
                    (string) $skill['name'],
                    (string) $skill['description'],
                    implode(' ', $skill['aliases'] ?? []),
                    implode(' ', $skill['triggers'] ?? []),
                    (string) ($skill['module'] ?? ''),
                    (string) ($skill['relative_path'] ?? ''),
                ]), 'UTF-8');
                if (str_contains($haystack, $taskLower)) {
                    $score += 1.0;
                }
                foreach ($skill['triggers'] ?? [] as $trigger) {
                    $trigger = mb_strtolower(trim((string) $trigger), 'UTF-8');
                    if ($trigger !== '' && str_contains($taskLower, $trigger)) {
                        $score += 0.55;
                    }
                }
                foreach ($skill['aliases'] ?? [] as $alias) {
                    $alias = mb_strtolower(trim((string) $alias), 'UTF-8');
                    if ($alias !== '' && (str_contains($taskLower, $alias) || $taskLower === $alias)) {
                        $score += 0.85;
                    }
                }
                $id = mb_strtolower((string) $skill['skill_id'], 'UTF-8');
                if ($id !== '' && (str_contains($taskLower, $id) || $taskLower === $id)) {
                    $score += 0.9;
                }
            }
            if ($score <= 0.0) {
                continue;
            }
            $row = self::publicSkill($skill, $includeContent);
            $row['score'] = round($score, 6);
            $scored[] = $row;
        }

        usort($scored, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($scored, 0, $limit);
    }

    /**
     * Load one skill by skill_id, alias, or name (case-insensitive).
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $selector, bool $includeContent = true, string $repository = ''): ?array
    {
        $key = mb_strtolower(trim($selector), 'UTF-8');
        if ($key === '') {
            return null;
        }

        if (isset(self::HOST_SHELL_ALIASES[$key])) {
            $key = mb_strtolower(self::HOST_SHELL_ALIASES[$key], 'UTF-8');
        }

        foreach (self::definitions($repository) as $skill) {
            $candidates = [
                (string) $skill['skill_id'],
                (string) $skill['name'],
                ...array_map('strval', $skill['aliases'] ?? []),
            ];
            foreach ($candidates as $candidate) {
                if (mb_strtolower(trim($candidate), 'UTF-8') === $key) {
                    return self::publicSkill($skill, $includeContent);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $surface
     * @param list<string> $aliases
     * @return array<string, mixed>
     */
    private static function fromSurface(array $surface, array $aliases): array
    {
        $surfaceId = (string) ($surface['id'] ?? '');
        $docs = [];
        if (is_string($surface['authoritative_doc'] ?? null) && trim((string) $surface['authoritative_doc']) !== '') {
            $docs[] = trim((string) $surface['authoritative_doc']);
        }
        foreach (is_array($surface['authoritative_docs'] ?? null) ? $surface['authoritative_docs'] : [] as $doc) {
            $doc = trim((string) $doc);
            if ($doc !== '' && !in_array($doc, $docs, true)) {
                $docs[] = $doc;
            }
        }

        $norms = [];
        foreach (is_array($surface['norms'] ?? null) ? $surface['norms'] : [] as $norm) {
            if (!is_array($norm)) {
                continue;
            }
            $norms[] = [
                'id' => (string) ($norm['id'] ?? ''),
                'summary' => (string) ($norm['summary'] ?? ''),
                'detail_doc' => (string) ($norm['detail_doc'] ?? ''),
            ];
        }

        $triggers = [];
        foreach (is_array($surface['triggers'] ?? null) ? $surface['triggers'] : [] as $trigger) {
            $trigger = trim((string) $trigger);
            if ($trigger !== '') {
                $triggers[] = $trigger;
            }
        }
        foreach ($aliases as $alias) {
            if ($alias !== '' && !in_array($alias, $triggers, true)) {
                $triggers[] = $alias;
            }
        }

        $label = (string) ($surface['label'] ?? $surfaceId);
        $description = (string) ($surface['description'] ?? $label);

        return [
            'skill_id' => $surfaceId,
            'name' => $label,
            'description' => $description,
            'aliases' => array_values(array_unique(array_filter($aliases, static fn (string $a): bool => $a !== ''))),
            'surface_id' => $surfaceId,
            'triggers' => $triggers,
            'authoritative_docs' => $docs,
            'norms' => $norms,
            'verification_commands' => is_array($surface['verification_commands'] ?? null)
                ? $surface['verification_commands']
                : [],
            'template_surface_rules' => is_array($surface['template_surface_rules'] ?? null)
                ? $surface['template_surface_rules']
                : [],
            'feature_delivery_urls' => $surfaceId === GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT
                ? GuidanceWorkflowCatalog::featureDeliveryUrls()
                : null,
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
        ];
    }

    /**
     * @param array<string, mixed> $skill
     * @return array<string, mixed>
     */
    private static function publicSkill(array $skill, bool $includeContent): array
    {
        $kind = (string) ($skill['kind'] ?? '');
        if ($kind === '') {
            $kind = (($skill['surface_id'] ?? '') !== '') ? 'workflow' : 'module_doc';
        }
        $row = [
            'skill_id' => $skill['skill_id'],
            'name' => $skill['name'],
            'description' => $skill['description'],
            'aliases' => $skill['aliases'],
            'surface_id' => $skill['surface_id'] ?? '',
            'triggers' => $skill['triggers'] ?? [],
            'authoritative_docs' => $skill['authoritative_docs'] ?? [],
            'norms' => $skill['norms'] ?? [],
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
            'kind' => $kind,
            'module' => $skill['module'] ?? '',
            'relative_path' => $skill['relative_path'] ?? '',
            'host_shell_role' => ($skill['aliases'] ?? []) === []
                ? 'none'
                : 'optional_thin_mirror',
            'fetch' => [
                'tool' => 'get_skill',
                'skill_id' => $skill['skill_id'],
            ],
        ];
        if ($includeContent) {
            $source = trim((string) ($skill['content_source'] ?? ''));
            $row['content'] = $source !== '' ? $source : self::renderBody($skill);
            $row['content_format'] = 'markdown';
            $row['verification_commands'] = $skill['verification_commands'] ?? [];
            if (is_array($skill['template_surface_rules'] ?? null) && $skill['template_surface_rules'] !== []) {
                $row['template_surface_rules'] = $skill['template_surface_rules'];
            }
            if (is_array($skill['feature_delivery_urls'] ?? null)) {
                $row['feature_delivery_urls'] = $skill['feature_delivery_urls'];
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $skill
     */
    private static function renderBody(array $skill): string
    {
        $lines = [];
        $lines[] = '# ' . (string) $skill['name'];
        $lines[] = '';
        $lines[] = (string) $skill['description'];
        $lines[] = '';
        $lines[] = '- skill_id: `' . (string) $skill['skill_id'] . '`';
        $lines[] = '- provider: MCP (`' . self::PROVIDER . '`)';
        $lines[] = '- static_skill_files: false（不以仓库/宿主 SKILL.md 为权威）';
        if (($skill['aliases'] ?? []) !== []) {
            $lines[] = '- host shell aliases（可选薄壳）: `' . implode('`, `', $skill['aliases']) . '`';
        }
        $lines[] = '';
        $lines[] = '## 权威文档';
        $lines[] = '';
        foreach ($skill['authoritative_docs'] ?? [] as $doc) {
            $lines[] = '- `' . $doc . '`';
        }
        if (($skill['authoritative_docs'] ?? []) === []) {
            $lines[] = '- （见 AI硬规则索引与对应 workflow surface）';
        }
        $lines[] = '';
        $lines[] = '## 规范要点';
        $lines[] = '';
        foreach ($skill['norms'] ?? [] as $norm) {
            if (!is_array($norm)) {
                continue;
            }
            $id = trim((string) ($norm['id'] ?? ''));
            $summary = trim((string) ($norm['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }
            $lines[] = '- ' . ($id !== '' ? '`' . $id . '` — ' : '') . $summary;
        }
        $rules = is_array($skill['template_surface_rules'] ?? null) ? $skill['template_surface_rules'] : [];
        $required = is_array($rules['required'] ?? null) ? $rules['required'] : [];
        $forbidden = is_array($rules['forbidden'] ?? null) ? $rules['forbidden'] : [];
        if ($required !== []) {
            $lines[] = '';
            $lines[] = '## 必须';
            $lines[] = '';
            foreach ($required as $item) {
                $lines[] = '- ' . $item;
            }
        }
        if ($forbidden !== []) {
            $lines[] = '';
            $lines[] = '## 禁止';
            $lines[] = '';
            foreach ($forbidden as $item) {
                $lines[] = '- ' . $item;
            }
        }
        $commands = is_array($skill['verification_commands'] ?? null) ? $skill['verification_commands'] : [];
        if ($commands !== []) {
            $lines[] = '';
            $lines[] = '## 验证';
            $lines[] = '';
            foreach ($commands as $command) {
                $lines[] = '- `' . $command . '`';
            }
        }
        $lines[] = '';
        $lines[] = '## 取用方式';
        $lines[] = '';
        $lines[] = '1. `resolve_skill(task=…)` 发现匹配技能';
        $lines[] = '2. `get_skill(skill_id=' . (string) $skill['skill_id'] . ')` 加载本正文';
        $lines[] = '3. 任务文档片段仍用 `resolve_task_context`';

        return implode("\n", $lines);
    }
}
