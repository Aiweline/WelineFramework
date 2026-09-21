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
                'HARD: At requirement start classify work_kind + fe_be_scope (requirement_fe_be_scope_analysis), then run Spec Kit/Kiro-style clarify + use-case when needed (requirement_clarify_use_case_spec). Enable host Plan Mode (host_plan_mode_for_planning) UNLESS simple plan_skip with rationale≥24; plan body ONLY 背景+方案+细节 (plan_content_focus_only). Non-simple/complex requirements: the parent itself chooses team mode (engineering_team_for_new_requirements) and seats; every user-facing line is Team:{席位}: e.g. Team:架构师:. Simple plan_skip uses 监工: and must not use Team:. Complex team MUST obey framework_first + dual_track_all specialty seats + component_reuse_or_negotiate + team_flow_on_contracts (对齐冻结会钉 UC+contracts+deps；依赖唤醒；禁止开发完才补主路径用例) + acceptance UI+原型 substantive signoff. Content-ops exempt. EVERY ask MUST have real acceptance (requirement_acceptance_always)—Web touches need local Browser WB-OP visual+logic even without Playwright e2e. Layout/humanization/吐槽/审图 force prototype+frontend-design adjustments (ui_skill_surface_signal_gate). Then analyze implicit requirements; decide ui_skill_decision. When participate: prototype+frontend-design+weline-theme-development + type=shentu. Closeout MUST write huishen_notes 汇审 (closeout_requires_huishen).',
                'HARD: Any user message with an image/screenshot attachment (admin/CMS/error/storefront—not only retail/B2B) MUST run MCP command 审图 (dev/ai-command/theme/审图.md) immediately; do not wait for the word 审图. Classify error_shot vs ui_shot: non-error (ui_shot) defaults to UI modification required. Same-turn joint pipeline: extract wireframe/line sketch → prototype adjustments (prototype) → frontend-design humanization + aesthetic standards → weline-theme-development CSS/tokens; fix fails (do not critique-only). Shot-only/silent screenshot: UI+prototype audit—NOT confirming prior chat. If host skills frontend-design or prototype are missing: prompt visibly and self-install into Cursor Agent Store before E/F pass (image_attachment_shentu_bundle.missing_host_skills_gate).',
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
                'minutes_extra' => [
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
                    'framework_first',
                    'dual_track_all',
                    'component_first',
                    'acceptance_substantive_signoff',
                    'team_flow_on_contracts',
                ],
                'dual_track' => true,
                'review_lanes' => 'per_triggered_seat',
                'core_roster' => [
                    '项目经理', '需求分析', '领域探查', '架构师', '后端', '前端', '主题', 'UI', '原型', '测试', '安全', '文档',
                ],
                'framework_seats' => [
                    '扩展点', '事件', '查询', 'Taglib', 'Hook', 'Provider', 'i18n', 'ACL', 'Setup', '合规',
                ],
                'acceptance_signoff' => ['UI', '原型'],
                'component_negotiate' => ['原型', 'UI', '主题'],
                'utterance' => [
                    'simple' => '监工:',
                    'team' => 'Team:{席位}:',
                    'example' => 'Team:架构师:',
                ],
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
