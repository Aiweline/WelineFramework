<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\McpSkillCatalog;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$checks = [];

function skillCheck(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks[] = ['label' => $label, 'passed' => $ok];
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

$policy = McpSkillCatalog::policy();
skillCheck(($policy['schema_version'] ?? '') === McpSkillCatalog::SCHEMA, 'policy schema is mcp-skills.v1');
skillCheck(($policy['provider'] ?? '') === 'mcp', 'policy provider is mcp');
skillCheck(($policy['static_skill_files'] ?? true) === false, 'policy forbids static skill files');
skillCheck(($policy['fetch']['discover'] ?? '') === 'resolve_skill', 'policy discover tool is resolve_skill');
skillCheck(($policy['fetch']['load'] ?? '') === 'get_skill', 'policy load tool is get_skill');
skillCheck(is_array($policy['catalog'] ?? null) && count($policy['catalog']) >= 9, 'policy catalog lists surfaces');
skillCheck(($policy['catalog_mode'] ?? '') === 'index_only', 'policy catalog_mode is index_only');
$catalogRow = is_array($policy['catalog'][0] ?? null) ? $policy['catalog'][0] : [];
skillCheck(
    isset($catalogRow['skill_id'], $catalogRow['name'], $catalogRow['kind'])
    && !array_key_exists('description', $catalogRow)
    && !array_key_exists('path', $catalogRow)
    && !array_key_exists('module', $catalogRow),
    'policy catalog rows are index-only (no description/path/module)'
);
$greeting = is_array($policy['greeting'] ?? null) ? $policy['greeting'] : [];
skillCheck(isset($greeting['workflow_preview']) && is_array($greeting['workflow_preview']), 'greeting exposes workflow_preview');
skillCheck(!isset($greeting['skills']), 'greeting does not embed full skills tree');
skillCheck(array_key_exists('module_doc_count', $greeting), 'greeting exposes module_doc_count');

$theme = McpSkillCatalog::get('weline-theme-development', true);
skillCheck(is_array($theme), 'get by host alias weline-theme-development');
skillCheck(($theme['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, 'theme alias maps to frontend_development');
skillCheck(is_string($theme['content'] ?? null) && str_contains((string) $theme['content'], 'get_skill'), 'theme skill body includes fetch instructions');
skillCheck(
    is_string($theme['content'] ?? null)
    && str_contains((string) $theme['content'], 'BinQuery')
    && (str_contains((string) $theme['content'], '回退') || str_contains((string) $theme['content'], 'fallback')),
    'theme skill body mandates BinQuery default not HTTP fallback'
);
skillCheck(($theme['static_skill_files'] ?? true) === false, 'theme skill static_skill_files=false');

$browser = McpSkillCatalog::get('local-browser-urls', true);
skillCheck(is_array($browser), 'get by host alias local-browser-urls');
skillCheck(($browser['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT, 'browser alias maps to webui surface');

$clarify = McpSkillCatalog::get('weline-req-clarify', true);
skillCheck(is_array($clarify), 'get by host alias weline-req-clarify');
skillCheck(($clarify['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE, 'clarify alias maps to requirement_clarify_use_case');
skillCheck(
    is_string($clarify['content'] ?? null)
    && (str_contains((string) $clarify['content'], 'EARS') || str_contains((string) $clarify['content'], '澄清')),
    'clarify skill body mentions EARS or 澄清'
);

$team = McpSkillCatalog::get('weline-engineering-team', true);
skillCheck(is_array($team), 'get by host alias weline-engineering-team');
skillCheck(($team['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM, 'team alias maps to engineering_team');
skillCheck(
    is_string($team['content'] ?? null)
    && str_contains((string) $team['content'], '停工')
    && str_contains((string) $team['content'], '项目经理')
    && str_contains((string) $team['content'], '全专席双轨')
    && str_contains((string) $team['content'], 'acceptance-ui.md'),
    'team skill body mentions 停工, 项目经理, dual track, acceptance-ui'
);

$taglib = McpSkillCatalog::get(GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL, true);
skillCheck(is_array($taglib), 'get by canonical surface id');
skillCheck(in_array('weline-taglib-first', $taglib['aliases'] ?? [], true), 'taglib skill exposes host alias');

$matched = McpSkillCatalog::resolve('Theme 部件 phtml 前端样式', 5, false);
skillCheck($matched !== [], 'resolve matches frontend task');
skillCheck(($matched[0]['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, 'frontend task ranks theme skill first');

$ops = HardConstraintsCatalog::mcpOperationalRules();
$hasMcpSkillsRule = false;
foreach ($ops as $rule) {
    if (is_array($rule) && ($rule['id'] ?? '') === 'mcp_skills_fetch_from_mcp') {
        $hasMcpSkillsRule = true;
        break;
    }
}
skillCheck($hasMcpSkillsRule, 'hard constraints include mcp_skills_fetch_from_mcp');

$bundle = $policy['css_or_theme_skill_bundle'] ?? [];
skillCheck(($bundle['rule_id'] ?? '') === 'css_or_theme_requires_ui_prototype_theme_skills', 'policy exposes css_or_theme skill bundle');
skillCheck(is_array($bundle['required'] ?? null) && count($bundle['required']) === 3, 'css_or_theme bundle lists three skills');

$featureBundle = $policy['feature_skill_bundle'] ?? [];
skillCheck(($featureBundle['rule_id'] ?? '') === 'requirement_implicit_analysis_skill_decision', 'policy exposes feature_skill_bundle');
skillCheck(($featureBundle['clarify_rule_id'] ?? '') === 'requirement_clarify_use_case_spec', 'feature bundle links clarify rule');
skillCheck(($featureBundle['clarify_skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE, 'feature bundle links clarify skill');
skillCheck(($featureBundle['required_when'] ?? '') === 'ui_skill_decision=participate', 'feature bundle gated by ui_skill_decision');
skillCheck(($featureBundle['also_require_acceptance_type'] ?? '') === 'shentu', 'feature bundle requires shentu acceptance');
skillCheck(is_array($featureBundle['required'] ?? null) && count($featureBundle['required']) === 2, 'feature bundle lists prototype+UI');

$teamBundle = $policy['engineering_team_bundle'] ?? [];
skillCheck(($teamBundle['rule_id'] ?? '') === 'engineering_team_for_new_requirements', 'policy exposes engineering_team_bundle');
skillCheck(($teamBundle['skill_id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM, 'engineering team bundle links skill');
skillCheck(($teamBundle['command_path'] ?? '') === 'dev/ai-command/ai/工程团队.md', 'engineering team bundle points at command');
skillCheck(in_array('plan_complexity=simple', is_array($teamBundle['exempt'] ?? null) ? $teamBundle['exempt'] : [], true), 'engineering team bundle exempts simple skip');
skillCheck(in_array('content_ops_skills_skip_mcp', is_array($teamBundle['exempt'] ?? null) ? $teamBundle['exempt'] : [], true), 'engineering team bundle exempts content ops');
skillCheck(($teamBundle['utterance']['example'] ?? '') === 'Team:项目经理:', 'engineering team utterance example is Team:项目经理:');
skillCheck(($teamBundle['utterance']['relay_example'] ?? '') === 'Team:架构师:', 'engineering team relay example is Team:架构师:');
skillCheck(($teamBundle['utterance']['team_parent'] ?? '') === 'Team:项目经理:', 'engineering team parent utterance is Team:项目经理:');
skillCheck(($teamBundle['utterance']['simple'] ?? '') === '监工:', 'engineering team simple utterance is 监工:');
skillCheck(($teamBundle['dual_track'] ?? false) === true, 'engineering team bundle dual_track true');
skillCheck(($teamBundle['one_seat_one_agent'] ?? false) === true, 'engineering team one_seat_one_agent true');
skillCheck(($teamBundle['peer_talk']['pm_role'] ?? '') === 'switchboard', 'engineering team peer_talk pm is switchboard');
skillCheck(($teamBundle['review_lanes'] ?? '') === 'per_triggered_seat', 'engineering team review_lanes per_triggered_seat');
skillCheck(in_array('framework_first', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle framework_first');
skillCheck(in_array('team_flow_on_contracts', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle team_flow_on_contracts');
skillCheck(in_array('one_seat_one_agent', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle one_seat_one_agent');
skillCheck(in_array('peer_talk_via_channel', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle peer_talk_via_channel');
skillCheck(in_array('contracts.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra contracts.md');
skillCheck(in_array('deps.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra deps.md');
skillCheck(in_array('roster.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra roster.md');
skillCheck(in_array('channel/{thread}.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra channel');
skillCheck(in_array('扩展点', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 扩展点');
skillCheck(in_array('事件', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 事件');
skillCheck(in_array('UI', is_array($teamBundle['core_roster'] ?? null) ? $teamBundle['core_roster'] : [], true), 'engineering team core roster includes UI');
skillCheck(in_array('原型', is_array($teamBundle['acceptance_signoff'] ?? null) ? $teamBundle['acceptance_signoff'] : [], true), 'engineering team acceptance_signoff includes 原型');
skillCheck(in_array('meetings/acceptance-ui.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra acceptance-ui');
skillCheck(in_array('surfaces.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra surfaces.md');

$teamCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/工程团队.md';
if (!is_file($teamCmdPath)) {
    $teamCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/工程团队.md';
}
$teamCmd = is_file($teamCmdPath) ? (string) file_get_contents($teamCmdPath) : '';
skillCheck($teamCmd !== '', 'engineering team command file readable');
skillCheck(str_contains($teamCmd, 'dual_track_all') || str_contains($teamCmd, '全专席双轨'), 'engineering team command mentions dual track');
skillCheck(str_contains($teamCmd, 'team_flow_on_contracts') || str_contains($teamCmd, '对齐冻结'), 'engineering team command mentions flow/align-freeze');
skillCheck(str_contains($teamCmd, 'one_seat_one_agent') || str_contains($teamCmd, '一席一智能体'), 'engineering team command mentions one_seat_one_agent');
skillCheck(str_contains($teamCmd, 'peer_talk_via_channel') || str_contains($teamCmd, '席间互聊'), 'engineering team command mentions peer_talk_via_channel');
skillCheck(str_contains($teamCmd, 'channel/{thread}.md') || str_contains($teamCmd, 'channel/'), 'engineering team command mentions channel path');
skillCheck(str_contains($teamCmd, 'contracts.md'), 'engineering team command mentions contracts.md');
skillCheck(str_contains($teamCmd, 'acceptance-ui.md'), 'engineering team command mentions acceptance-ui.md');
skillCheck(str_contains($teamCmd, 'component-negotiate.md'), 'engineering team command mentions component-negotiate.md');
skillCheck(str_contains($teamCmd, '框架优先'), 'engineering team command mentions 框架优先');
skillCheck(str_contains($teamCmd, '事件'), 'engineering team command mentions 事件 seat');

$shentuBundle = $policy['image_attachment_shentu_bundle'] ?? [];
skillCheck(($shentuBundle['rule_id'] ?? '') === 'user_image_attachment_triggers_shentu', 'policy exposes image_attachment_shentu_bundle');
skillCheck(($shentuBundle['hard_trigger'] ?? '') === 'any_user_message_image_or_screenshot_attachment', 'shentu bundle hard_trigger is any image attachment');
skillCheck(($shentuBundle['command_path'] ?? '') === 'dev/ai-command/theme/审图.md', 'shentu bundle points at 审图 command');
skillCheck(($shentuBundle['host_skill'] ?? '') === 'weline-ui-shentu', 'shentu bundle names host thin skill');

$productOptimizeBundle = $policy['product_optimize_detail_suite_bundle'] ?? [];
skillCheck(($productOptimizeBundle['rule_id'] ?? '') === 'product_optimize_triggers_detail_suite', 'policy exposes product_optimize_detail_suite_bundle');
skillCheck(($productOptimizeBundle['mcp'] ?? '') === 'skip', 'product optimize bundle skips MCP');
skillCheck(($productOptimizeBundle['hard_trigger'] ?? '') === 'product_pdp_url_with_optimize_intent', 'product optimize bundle hard_trigger is pdp url with intent');
skillCheck(($productOptimizeBundle['command_path'] ?? '') === 'dev/ai-command/product/产品优化.md', 'product optimize bundle points at 产品优化 parent command');

$blogArticleBundle = $policy['blog_article_methodology_bundle'] ?? [];
skillCheck(($blogArticleBundle['rule_id'] ?? '') === 'blog_article_methodology_gate', 'policy exposes blog_article_methodology_bundle');
skillCheck(($blogArticleBundle['mcp'] ?? '') === 'skip', 'blog article bundle skips MCP');
skillCheck(($blogArticleBundle['hard_trigger'] ?? '') === 'blog_article_create_or_review_intent', 'blog article bundle hard_trigger is create/review intent');
skillCheck(($blogArticleBundle['command_path'] ?? '') === 'dev/ai-command/blog/新建文章.md', 'blog article bundle points at 新建文章 command');
skillCheck(($blogArticleBundle['host_skill'] ?? '') === 'weline-blog-article', 'blog article bundle names host thin skill');
skillCheck(
    ($blogArticleBundle['repo_skill_path'] ?? '') === 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/SKILL.md',
    'blog article repo_skill_path points at weline-blog-article'
);
skillCheck(
    ($blogArticleBundle['methodology_path'] ?? '') === 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/methodology.md'
    && ($blogArticleBundle['review_checklist_path'] ?? '') === 'app/code/Weline/Blog/doc/ai/skills/weline-blog-article/review-checklist.md',
    'blog article bundle exposes methodology and review checklist'
);
skillCheck(($productOptimizeBundle['child_command_path'] ?? '') === 'dev/ai-command/product/详情优化.md', 'product optimize bundle child_command_path is 详情优化');
skillCheck(($productOptimizeBundle['host_skill'] ?? '') === 'ecommerce-product-optimize', 'product optimize bundle names ecommerce-product-optimize parent');
skillCheck(($productOptimizeBundle['child_host_skill'] ?? '') === 'ecommerce-detail-suite', 'product optimize bundle child_host_skill is ecommerce-detail-suite');
skillCheck(
    ($productOptimizeBundle['image_pipeline'] ?? '') === 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/companions/weline-image-pipeline.md',
    'product optimize image_pipeline points at repo Product skill'
);
skillCheck(
    ($productOptimizeBundle['repo_skill_path'] ?? '') === 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-optimize/SKILL.md',
    'product optimize repo_skill_path points at parent ecommerce-product-optimize'
);
skillCheck(
    ($productOptimizeBundle['child_repo_skill_path'] ?? '') === 'app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/SKILL.md',
    'product optimize child_repo_skill_path points at ecommerce-detail-suite'
);
skillCheck(
    ($productOptimizeBundle['hierarchy'] ?? '') === 'parent_contains_three_parallel_children'
    && ($productOptimizeBundle['parent']['must_launch_three_subagents'] ?? false) === true
    && is_array($productOptimizeBundle['parent']['subagent_slots'] ?? null)
    && count($productOptimizeBundle['parent']['subagent_slots']) === 3,
    'product optimize bundle hierarchy three parallel subagents'
);
skillCheck(
    ($productOptimizeBundle['i18n_command_path'] ?? '') === 'dev/ai-command/product/翻译优化.md'
    && ($productOptimizeBundle['i18n_host_skill'] ?? '') === 'ecommerce-product-i18n'
    && ($productOptimizeBundle['i18n_repo_skill_path'] ?? '') === 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-i18n/SKILL.md',
    'product optimize bundle exposes 翻译优化 i18n branch'
);
skillCheck(
    ($productOptimizeBundle['image_host_skill'] ?? '') === 'ecommerce-product-image'
    && ($productOptimizeBundle['image_repo_skill_path'] ?? '') === 'app/code/Weline/Product/doc/ai/skills/ecommerce-product-image/SKILL.md',
    'product optimize bundle exposes ecommerce-product-image branch'
);
skillCheck(
    is_array($productOptimizeBundle['required_actions'] ?? null)
    && in_array('parent_must_launch_three_parallel_subagents', $productOptimizeBundle['required_actions'], true)
    && in_array('detect_default_website_locales_and_field_complete_translate', $productOptimizeBundle['required_actions'], true)
    && in_array('parent_review_pass_1_against_child_skill_gates', $productOptimizeBundle['required_actions'], true)
    && in_array('parent_review_pass_2_against_child_skill_gates', $productOptimizeBundle['required_actions'], true)
    && in_array('named_rework_failing_slots_with_defect_list', $productOptimizeBundle['required_actions'], true)
    && in_array('claim_done_only_after_review_pass_2_all_pass', $productOptimizeBundle['required_actions'], true),
    'product optimize bundle requires three-subagent + dual review + i18n actions'
);
skillCheck(
    is_array($productOptimizeBundle['forbid'] ?? null)
    && in_array('parent_finish_without_three_subagents', $productOptimizeBundle['forbid'], true)
    && in_array('skip_images_while_claiming_parent_product_optimize_done', $productOptimizeBundle['forbid'], true)
    && in_array('parent_claim_done_without_dual_review_passes', $productOptimizeBundle['forbid'], true)
    && in_array('vague_rework_without_named_defects', $productOptimizeBundle['forbid'], true),
    'product optimize bundle forbids finishing without three subagents/dual review or vague rework'
);
skillCheck(
    is_array($productOptimizeBundle['skip_marker'] ?? null)
    && ($productOptimizeBundle['skip_marker']['attr'] ?? '') === 'data-weds="xq"',
    'product optimize bundle skip_marker is data-weds=xq'
);
skillCheck(
    is_array($productOptimizeBundle['required_actions'] ?? null)
    && in_array('check_skip_marker_data_weds_xq_before_work', $productOptimizeBundle['required_actions'], true)
    && in_array('write_skip_marker_data_weds_xq_on_detail_root', $productOptimizeBundle['required_actions'], true),
    'product optimize bundle requires check+write skip marker actions'
);
skillCheck(
    is_array($productOptimizeBundle['forbid'] ?? null)
    && in_array('reoptimize_when_data_weds_xq_present_without_force', $productOptimizeBundle['forbid'], true)
    && in_array('use_1688_or_source_attr_as_skip_marker', $productOptimizeBundle['forbid'], true),
    'product optimize bundle forbids reoptimize without force and 1688-as-marker'
);
skillCheck(
    is_array($productOptimizeBundle['triggers'] ?? null)
    && in_array('详情优化', $productOptimizeBundle['triggers'], true)
    && in_array('商详优化', $productOptimizeBundle['triggers'], true)
    && in_array('产品优化', $productOptimizeBundle['triggers'], true)
    && in_array('商品优化', $productOptimizeBundle['triggers'], true)
    && in_array('/product/', $productOptimizeBundle['triggers'], true),
    'product optimize bundle triggers include 详情/商详/产品/商品优化 and /product/'
);
skillCheck(
    is_array($shentuBundle['required_actions'] ?? null)
    && in_array('judge_humanization_and_aesthetics_with_frontend_design_and_prototype', $shentuBundle['required_actions'], true)
    && in_array('judge_theme_fit_with_weline_theme_development', $shentuBundle['required_actions'], true)
    && in_array('extract_structural_wireframe_line_sketch', $shentuBundle['required_actions'], true)
    && in_array('provide_prototype_adjustments', $shentuBundle['required_actions'], true)
    && in_array('ui_shot_defaults_to_ui_modification', $shentuBundle['required_actions'], true)
    && in_array('joint_frontend_design_prototype_theme_same_turn', $shentuBundle['required_actions'], true),
    'shentu bundle requires UI+prototype+wireframe+joint pipeline actions'
);
skillCheck(
    is_array($shentuBundle['joint_pipeline'] ?? null)
    && in_array('extract_structural_wireframe', $shentuBundle['joint_pipeline'], true)
    && in_array('prototype_adjustments', $shentuBundle['joint_pipeline'], true)
    && in_array('frontend_design_aesthetics', $shentuBundle['joint_pipeline'], true)
    && in_array('theme_css_tokens_via_weline_theme_development', $shentuBundle['joint_pipeline'], true),
    'shentu bundle exposes joint_pipeline wireframe→prototype→UI→theme'
);
skillCheck(
    is_array($shentuBundle['review_dimensions'] ?? null)
    && in_array('humanization', $shentuBundle['review_dimensions'], true)
    && in_array('aesthetic_standards', $shentuBundle['review_dimensions'], true)
    && in_array('theme_fit', $shentuBundle['review_dimensions'], true)
    && in_array('wireframe_structure', $shentuBundle['review_dimensions'], true)
    && in_array('prototype_adjustment', $shentuBundle['review_dimensions'], true),
    'shentu bundle lists humanization/aesthetic/theme_fit/wireframe/prototype dimensions'
);
$missingGate = $shentuBundle['missing_host_skills_gate'] ?? [];
skillCheck(($missingGate['check'] ?? '') === 'available_skills_or_agent_store', 'shentu missing_host_skills_gate check is available_skills_or_agent_store');
skillCheck(
    is_array($missingGate['required_host_skills'] ?? null)
    && in_array('frontend-design', $missingGate['required_host_skills'], true)
    && in_array('prototype', $missingGate['required_host_skills'], true),
    'shentu missing gate requires frontend-design + prototype'
);
skillCheck(
    is_array($missingGate['on_missing'] ?? null)
    && in_array('prompt_user_visible_warning', $missingGate['on_missing'], true)
    && in_array('self_install_to_cursor_agent_store', $missingGate['on_missing'], true),
    'shentu missing gate requires user prompt + self-install'
);
skillCheck(
    is_string($missingGate['prompt_template'] ?? null)
    && str_contains((string) $missingGate['prompt_template'], 'frontend-design')
    && str_contains((string) $missingGate['prompt_template'], 'prototype'),
    'shentu missing gate prompt names UI+prototype skills'
);
skillCheck(
    is_array($policy['instructions'] ?? null)
    && array_reduce(
        $policy['instructions'],
        static fn (bool $ok, mixed $line): bool => $ok || (is_string($line) && str_contains($line, 'image/screenshot attachment')),
        false
    ),
    'policy instructions mention image attachment → 审图'
);
skillCheck(
    is_array($policy['instructions'] ?? null)
    && array_reduce(
        $policy['instructions'],
        static fn (bool $ok, mixed $line): bool => $ok || (
            is_string($line)
            && str_contains($line, 'humanization')
            && str_contains($line, 'frontend-design')
            && str_contains($line, 'prototype')
        ),
        false
    ),
    'policy instructions require humanization via UI+prototype on 审图'
);

$taskContract = GuidanceWorkflowCatalog::forTask('验收 交付地址 Browser');
$webui = $taskContract['surfaces'][GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT] ?? [];
skillCheck(($webui['mcp_skill_fetch']['load'] ?? '') === 'get_skill', 'forTask exposes mcp_skill_fetch');
skillCheck(($webui['authoritative_skill'] ?? '') === 'local-browser-urls', 'webui surface keeps host shell alias');

fwrite(STDOUT, json_encode([
    'schema_version' => 'mcp-skills-catalog-tests.v1',
    'passed' => !$failed,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($failed ? 1 : 0);
