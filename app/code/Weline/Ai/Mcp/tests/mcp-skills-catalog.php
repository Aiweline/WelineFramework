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
skillCheck(in_array('数据分析', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 数据分析');
skillCheck(in_array('API', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat API');
skillCheck(in_array('支付开发工程师', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 支付开发工程师');
skillCheck(in_array('主题开发工程师', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 主题开发工程师');
skillCheck(!in_array('主题', is_array($teamBundle['core_roster'] ?? null) ? $teamBundle['core_roster'] : [], true), 'engineering team core roster retired 主题 into 主题开发工程师');
skillCheck(in_array('电商顾问', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 电商顾问');
skillCheck(in_array('性能检查工程师', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 性能检查工程师');
skillCheck(in_array('提示词优化工程师', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 提示词优化工程师');
skillCheck(in_array('翻译工程师', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seat 翻译工程师');
skillCheck(!in_array('合规', is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [], true), 'engineering team framework seats retired 合规 into 电商顾问');
skillCheck(in_array('UI', is_array($teamBundle['core_roster'] ?? null) ? $teamBundle['core_roster'] : [], true), 'engineering team core roster includes UI');
skillCheck(in_array('acceptance_substantive_signoff', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle acceptance_substantive_signoff');
skillCheck(in_array('findings_wake_pm', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle findings_wake_pm');
skillCheck(in_array('requirement_issuer_owns_acceptance', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle requirement_issuer_owns_acceptance');
skillCheck(($teamBundle['peer_talk']['result_waiting_acceptance'] ?? '') === 'waiting_acceptance', 'engineering team peer_talk waiting_acceptance');
skillCheck(in_array('requirement_session_dashboard', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle requirement_session_dashboard');
skillCheck(in_array('pm_plan_lifecycle', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle pm_plan_lifecycle');
skillCheck(($teamBundle['session_path'] ?? '') === 'doc/开发/session/{slug}.md', 'engineering team session_path');
skillCheck(str_contains((string) ($teamBundle['session_template'] ?? ''), 'requirement-session.md'), 'engineering team session_template');
skillCheck(in_array('closeout_related_web_urls', is_array($teamBundle['principles'] ?? null) ? $teamBundle['principles'] : [], true), 'engineering team principle closeout_related_web_urls');
skillCheck(in_array('ui_and_prototype_review_pass', is_array($teamBundle['acceptance_gate_order'] ?? null) ? $teamBundle['acceptance_gate_order'] : [], true), 'engineering team acceptance_gate_order includes ui_and_prototype_review_pass');
skillCheck(in_array('tester_execution_pass', is_array($teamBundle['acceptance_gate_order'] ?? null) ? $teamBundle['acceptance_gate_order'] : [], true), 'engineering team acceptance_gate_order includes tester_execution_pass');
skillCheck(in_array('pm_huishen_pass', is_array($teamBundle['acceptance_gate_order'] ?? null) ? $teamBundle['acceptance_gate_order'] : [], true), 'engineering team acceptance_gate_order includes pm_huishen_pass');
skillCheck(in_array('原型', is_array($teamBundle['acceptance_signoff'] ?? null) ? $teamBundle['acceptance_signoff'] : [], true), 'engineering team acceptance_signoff includes 原型');
skillCheck(in_array('meetings/acceptance-ui.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra acceptance-ui');
skillCheck(in_array('surfaces.md', is_array($teamBundle['minutes_extra'] ?? null) ? $teamBundle['minutes_extra'] : [], true), 'engineering team minutes_extra surfaces.md');

$seatMirrors = $teamBundle['seat_skill_mirrors'] ?? [];
skillCheck(($seatMirrors['schema_version'] ?? '') === 'seat-skill-mirrors.v1', 'engineering team seat_skill_mirrors schema');
$seatMap = is_array($seatMirrors['seats'] ?? null) ? $seatMirrors['seats'] : [];
$widgetSeat = $seatMap['部件开发工程师'] ?? [];
$widgetPrompt = (string) ($widgetSeat['prompt_increment'] ?? '');
skillCheck(str_contains($widgetPrompt, '所有 CSS/可执行 JS 禁内联')
    && str_contains($widgetPrompt, 'style=') && str_contains($widgetPrompt, 'on*=')
    && str_contains($widgetPrompt, 'source-postion/source-position'), 'widget prompt mandates external resources and position aliases');
skillCheck(in_array('app/code/Weline/Theme/doc/部件静态资源固化规范.md',
    $widgetSeat['authoritative_docs'] ?? [], true), 'widget seat loads authoritative asset position contract');

$expectedSeats = array_merge(
    is_array($teamBundle['core_roster'] ?? null) ? $teamBundle['core_roster'] : [],
    is_array($teamBundle['framework_seats'] ?? null) ? $teamBundle['framework_seats'] : [],
);
foreach ($expectedSeats as $seatName) {
    skillCheck(isset($seatMap[$seatName]), 'engineering team seat_skill_mirrors covers ' . $seatName);
    if (!isset($seatMap[$seatName]) || !is_array($seatMap[$seatName])) {
        continue;
    }
    skillCheck(trim((string) ($seatMap[$seatName]['prompt_increment'] ?? '')) !== '', 'seat_skill_mirrors prompt_increment for ' . $seatName);
    skillCheck(is_array($seatMap[$seatName]['authoritative_docs'] ?? null) && ($seatMap[$seatName]['authoritative_docs'] ?? []) !== [], 'seat_skill_mirrors docs for ' . $seatName);
}
$front = is_array($seatMap['前端'] ?? null) ? $seatMap['前端'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, is_array($front['mcp_skill_ids'] ?? null) ? $front['mcp_skill_ids'] : [], true), '前端 mirror includes frontend_development');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL, is_array($front['mcp_skill_ids'] ?? null) ? $front['mcp_skill_ids'] : [], true), '前端 mirror includes taglib_ui_control');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N, is_array($front['mcp_skill_ids'] ?? null) ? $front['mcp_skill_ids'] : [], true), '前端 mirror includes template_i18n');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV, is_array($front['mcp_skill_ids'] ?? null) ? $front['mcp_skill_ids'] : [], true), '前端 mirror includes module_i18n_csv');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), 'frontend_ui_requires_zh_en_csv'), '前端 prompt mandates zh/en CSV hard gate');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), '开发语言默认中文'), '前端 prompt mandates Chinese development language');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), '不是 CSS'), '前端 prompt clarifies bilingual via CSV not CSS');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), '紧凑') || str_contains((string) ($front['prompt_increment'] ?? ''), '全宽'), '前端 prompt forbids full-width stacked search');
skillCheck(in_array('app/code/Weline/I18n/doc/模块翻译CSV规范.md', is_array($front['authoritative_docs'] ?? null) ? $front['authoritative_docs'] : [], true), '前端 mirror docs include 模块翻译CSV规范');
$eventSeat = is_array($seatMap['事件'] ?? null) ? $seatMap['事件'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION, is_array($eventSeat['mcp_skill_ids'] ?? null) ? $eventSeat['mcp_skill_ids'] : [], true), '事件 mirror includes event_extension');
skillCheck(str_contains((string) ($eventSeat['prompt_increment'] ?? ''), '数据分析'), '事件 prompt defers Visitor pixel to 数据分析');
$analyticsSeat = is_array($seatMap['数据分析'] ?? null) ? $seatMap['数据分析'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS, is_array($analyticsSeat['mcp_skill_ids'] ?? null) ? $analyticsSeat['mcp_skill_ids'] : [], true), '数据分析 mirror includes visitor_data_analytics');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, is_array($analyticsSeat['mcp_skill_ids'] ?? null) ? $analyticsSeat['mcp_skill_ids'] : [], true), '数据分析 mirror includes frontend_development');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL, is_array($analyticsSeat['mcp_skill_ids'] ?? null) ? $analyticsSeat['mcp_skill_ids'] : [], true), '数据分析 mirror includes taglib_ui_control');
$analyticsSkillRefs = is_array($analyticsSeat['skill_refs'] ?? null) ? $analyticsSeat['skill_refs'] : [];
skillCheck($analyticsSkillRefs !== [], '数据分析 seat exposes skill_refs');
skillCheck(str_contains(implode(' ', $analyticsSkillRefs), 'frontend_development'), '数据分析 skill_refs include frontend_development');
skillCheck(str_contains(implode(' ', $analyticsSkillRefs), 'taglib_ui_control'), '数据分析 skill_refs include taglib_ui_control');
$analyticsDocs = is_array($analyticsSeat['authoritative_docs'] ?? null) ? $analyticsSeat['authoritative_docs'] : [];
skillCheck(in_array('app/code/Weline/Visitor/doc/像素拓展使用指南.md', $analyticsDocs, true), '数据分析 docs include 像素拓展使用指南');
skillCheck(in_array('app/code/Weline/Websites/doc/store-saleschannel-scope.md', $analyticsDocs, true), '数据分析 docs include store-saleschannel-scope');
skillCheck(in_array('app/code/Weline/Theme/doc/开发/Theme开发总指南.md', $analyticsDocs, true), '数据分析 docs include Theme开发总指南');
skillCheck(in_array('app/code/Weline/Taglib/doc/场景映射表.md', $analyticsDocs, true), '数据分析 docs include Taglib 场景映射表');
$analyticsPrompt = (string) ($analyticsSeat['prompt_increment'] ?? '');
skillCheck(str_contains($analyticsPrompt, 'Weline_Visitor') || str_contains($analyticsPrompt, 'Visitor'), '数据分析 prompt owns Weline_Visitor');
skillCheck(str_contains($analyticsPrompt, '技能引用') || str_contains($analyticsPrompt, 'skill'), '数据分析 prompt mentions skill refs');
skillCheck(str_contains($analyticsPrompt, 'frontend_development') && str_contains($analyticsPrompt, 'taglib_ui_control'), '数据分析 prompt requires frontend+taglib get_skill');
skillCheck(str_contains($analyticsPrompt, '事件') && (str_contains($analyticsPrompt, '混岗') || str_contains($analyticsPrompt, '禁止')), '数据分析 prompt distinguishes from framework Event seat');
skillCheck(str_contains($analyticsPrompt, '产品混岗') || str_contains($analyticsPrompt, '非文件类型'), '数据分析 prompt uses product-role split not absolute event.xml ban');
skillCheck(str_contains($analyticsPrompt, 'GtmBridge') || str_contains($analyticsPrompt, '双通道'), '数据分析 prompt covers GTM/GA4 dual-channel');
skillCheck(str_contains($analyticsPrompt, 'event_dictionary') || str_contains($analyticsPrompt, '字典'), '数据分析 prompt covers event dictionary');
skillCheck(str_contains($analyticsPrompt, 'sandbox.emit') || str_contains($analyticsPrompt, '去重'), '数据分析 prompt covers conversion dedupe');
skillCheck(str_contains((string) ($eventSeat['prompt_increment'] ?? ''), '像素桥接') || str_contains((string) ($eventSeat['prompt_increment'] ?? ''), 'Visitor/Observer'), '事件 prompt defers Visitor pixel Observers to 数据分析');
skillCheck(str_contains($analyticsPrompt, 'scope_json') || str_contains($analyticsPrompt, '路径过滤'), '数据分析 prompt distinguishes path filter from Scope');
skillCheck(!in_array(GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION, is_array($analyticsSeat['mcp_skill_ids'] ?? null) ? $analyticsSeat['mcp_skill_ids'] : [], true), '数据分析 mirror does not include event_extension');
$backend = is_array($seatMap['后端'] ?? null) ? $seatMap['后端'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE, is_array($backend['mcp_skill_ids'] ?? null) ? $backend['mcp_skill_ids'] : [], true), '后端 mirror includes module_upgrade_gate');
$apiSeat = is_array($seatMap['API'] ?? null) ? $seatMap['API'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT, is_array($apiSeat['mcp_skill_ids'] ?? null) ? $apiSeat['mcp_skill_ids'] : [], true), 'API mirror includes api_sdk_development');
$apiDocs = is_array($apiSeat['authoritative_docs'] ?? null) ? $apiSeat['authoritative_docs'] : [];
skillCheck(in_array('app/code/Weline/Framework/doc/3-开发/API接口开发规范.md', $apiDocs, true), 'API mirror docs include API接口开发规范');
skillCheck(in_array('app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md', $apiDocs, true), 'API mirror docs include BinQuery Provider开发指南');
skillCheck(in_array('app/code/Weline/Framework/doc/BinQuery/README.md', $apiDocs, true), 'API mirror docs include BinQuery README');
skillCheck(in_array('app/code/Weline/Ai/doc/开发/team/api-seat-charter/meetings/align-freeze.md', $apiDocs, true), 'API mirror docs include align-freeze architecture charter');
$apiPrompt = (string) ($apiSeat['prompt_increment'] ?? '');
skillCheck(str_contains($apiPrompt, '归属') || str_contains($apiPrompt, 'Vendor_Module') || str_contains($apiPrompt, 'align-freeze'), 'API prompt_increment mentions owning module or charter');
skillCheck(str_contains($apiPrompt, 'BinQuery') && str_contains($apiPrompt, 'QueryProvider'), 'API prompt_increment covers BinQuery/QueryProvider');
skillCheck(str_contains($apiPrompt, 'AbstractRestController') || str_contains($apiPrompt, 'REST'), 'API prompt_increment covers REST');
skillCheck(str_contains($apiPrompt, 'framework:compile') || str_contains($apiPrompt, 'query:help') || str_contains($apiPrompt, 'compile'), 'API prompt_increment requires compile/query:help');
skillCheck(str_contains($apiPrompt, 'w_query') || str_contains($apiPrompt, '共用 Query') || str_contains($apiPrompt, 'Query 核'), 'API prompt_increment requires shared Query core / w_query');
skillCheck(str_contains($apiPrompt, '权限') && (str_contains($apiPrompt, 'backend_acl') || str_contains($apiPrompt, 'auth')), 'API prompt_increment requires per-entry permission matrix');
skillCheck(str_contains($apiPrompt, 'align-freeze') || str_contains($apiPrompt, '已冻架构'), 'API prompt_increment points at frozen architecture charter');
skillCheck(str_contains($apiPrompt, '写了 Provider') || str_contains($apiPrompt, '不等于') || str_contains($apiPrompt, '≠'), 'API prompt_increment states Provider≠auto callable');
$querySeat = is_array($seatMap['查询'] ?? null) ? $seatMap['查询'] : [];
skillCheck(str_contains((string) ($querySeat['prompt_increment'] ?? ''), 'API'), '查询 prompt defers Provider implementation to API seat');
skillCheck(str_contains((string) ($querySeat['prompt_increment'] ?? ''), '共用 Query') || str_contains((string) ($querySeat['prompt_increment'] ?? ''), '多入口'), '查询 prompt covers shared Query core multi-entry review');
$paymentSeat = is_array($seatMap['支付开发工程师'] ?? null) ? $seatMap['支付开发工程师'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT, is_array($paymentSeat['mcp_skill_ids'] ?? null) ? $paymentSeat['mcp_skill_ids'] : [], true), '支付开发工程师 mirror includes payment_development');
$themeEngineerSeat = is_array($seatMap['主题开发工程师'] ?? null) ? $seatMap['主题开发工程师'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT, is_array($themeEngineerSeat['mcp_skill_ids'] ?? null) ? $themeEngineerSeat['mcp_skill_ids'] : [], true), '主题开发工程师 mirror includes theme_development');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT, is_array($themeEngineerSeat['mcp_skill_ids'] ?? null) ? $themeEngineerSeat['mcp_skill_ids'] : [], true), '主题开发工程师 mirror includes frontend_development');
skillCheck(in_array('dev/ai-command/ai/主题开发.md', is_array($themeEngineerSeat['authoritative_docs'] ?? null) ? $themeEngineerSeat['authoritative_docs'] : [], true), '主题开发工程师 docs include 主题开发 command');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'Team:主题开发工程师'), '主题开发工程师 prompt states seat identity');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'work_mode'), '主题开发工程师 prompt requires work_mode');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'theme.css'), '主题开发工程师 prompt forbids design theme.css override');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'theme:active'), '主题开发工程师 prompt mentions theme:active lifecycle');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), '必装永远存在')
    && str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'user_deleted@{versionId}'), '主题开发工程师 prompt mandates required defaults always present');
skillCheck(str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), '席位底线')
    && str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'theme_seat_integrity_over_peer_requests')
    && str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), 'escalate')
    && str_contains((string) ($themeEngineerSeat['prompt_increment'] ?? ''), '驳回'), '主题开发工程师 prompt mandates seat integrity over peer requests with veto');
skillCheck(in_array('app/code/Weline/Theme/doc/开发/spec/required-default-always-present.md', is_array($themeEngineerSeat['authoritative_docs'] ?? null) ? $themeEngineerSeat['authoritative_docs'] : [], true), '主题开发工程师 docs include required-default-always-present spec');
skillCheck(in_array('app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/席位底线补钉.md', is_array($themeEngineerSeat['authoritative_docs'] ?? null) ? $themeEngineerSeat['authoritative_docs'] : [], true), '主题开发工程师 docs include 席位底线补钉 meeting');
$themePrompt = (string) ($themeEngineerSeat['prompt_increment'] ?? '');
skillCheck(
    (str_contains($themePrompt, 'frontend') && str_contains($themePrompt, 'backend'))
    || str_contains($themePrompt, 'area'),
    '主题开发工程师 prompt covers area frontend|backend'
);
skillCheck(str_contains($themePrompt, 'components'), '主题开发工程师 prompt covers components library');
skillCheck(
    str_contains($themePrompt, 'w-backend-page') || str_contains($themePrompt, 'Weline UI'),
    '主题开发工程师 prompt covers Weline UI / w-backend-page'
);
skillCheck(str_contains($themePrompt, 'widget'), '主题开发工程师 prompt covers widget layer');
skillCheck(in_array('app/code/Weline/Theme/view/theme/README.md', is_array($themeEngineerSeat['authoritative_docs'] ?? null) ? $themeEngineerSeat['authoritative_docs'] : [], true), '主题开发工程师 docs include view/theme README');
skillCheck(in_array('app/code/Weline/Theme/doc/部件开发指南.md', is_array($themeEngineerSeat['authoritative_docs'] ?? null) ? $themeEngineerSeat['authoritative_docs'] : [], true), '主题开发工程师 docs include 部件开发指南');
skillCheck(!isset($seatMap['主题']), 'seat_skill_mirrors no longer has standalone 主题 seat');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), '主题开发工程师') && str_contains((string) ($front['prompt_increment'] ?? ''), 'variables/_'), '前端 prompt defers Token/shell to 主题开发工程师');
skillCheck(str_contains((string) ($front['prompt_increment'] ?? ''), 'design 皮肤勿改默认'), '前端 prompt: design skin must not edit default Theme/view/theme');
$paymentDocs = is_array($paymentSeat['authoritative_docs'] ?? null) ? $paymentSeat['authoritative_docs'] : [];
skillCheck(in_array('app/code/Weline/Payment/doc/payment-shell.md', $paymentDocs, true), '支付开发工程师 docs include payment-shell');
skillCheck(in_array('app/code/Weline/Payment/doc/provider-development.md', $paymentDocs, true), '支付开发工程师 docs include provider-development');
skillCheck(in_array('dev/ai-command/ai/支付开发.md', $paymentDocs, true), '支付开发工程师 docs include 支付开发 command');
$paymentPrompt = (string) ($paymentSeat['prompt_increment'] ?? '');
skillCheck(str_contains($paymentPrompt, 'shell_provider') || str_contains($paymentPrompt, 'Extends Provider'), '支付开发工程师 prompt mentions Provider isomorphism');
skillCheck(str_contains($paymentPrompt, 'refund') || str_contains($paymentPrompt, '退款'), '支付开发工程师 prompt covers refund');
skillCheck(str_contains($paymentPrompt, 'cspDirectives') || str_contains($paymentPrompt, 'CSP'), '支付开发工程师 prompt covers CSP');
skillCheck(str_contains($paymentPrompt, 'Team:测试:') && (str_contains($paymentPrompt, 'Browser') || str_contains($paymentPrompt, '浏览器')), '支付开发工程师 prompt wakes 测试席 for Browser');
skillCheck(str_contains($paymentPrompt, 'payment_browser_e2e_closed_loop') || str_contains($paymentPrompt, '验收闭环'), '支付开发工程师 prompt names closed-loop acceptance');
$testSeat = is_array($seatMap['测试'] ?? null) ? $seatMap['测试'] : [];
$testPrompt = (string) ($testSeat['prompt_increment'] ?? '');
skillCheck(str_contains($testPrompt, '支付开发工程师') && str_contains($testPrompt, 'Browser'), '测试 prompt covers payment Browser when woken by 支付');
skillCheck(str_contains($testPrompt, 'tester_tests_must_be_real') || str_contains($testPrompt, '测试必须真'), '测试 prompt mandates tester_tests_must_be_real');
skillCheck(
    str_contains($testPrompt, 'browser_strip_automation_flags')
    || str_contains($testPrompt, '抹掉自动化标志')
    || str_contains($testPrompt, 'navigator.webdriver'),
    '测试 prompt mandates browser_strip_automation_flags'
);
skillCheck(
    str_contains($testPrompt, 'browser_operator_non_preemptive')
    || str_contains($testPrompt, '非抢占'),
    '测试 prompt mandates browser_operator_non_preemptive'
);
skillCheck(
    (str_contains($testPrompt, '自造') || str_contains($testPrompt, '假数据') || str_contains($testPrompt, '假响应'))
    && (str_contains($testPrompt, '禁止') || str_contains($testPrompt, '自欺')),
    '测试 prompt forbids self-fabricated data circular pass'
);
$providerSeat = is_array($seatMap['Provider'] ?? null) ? $seatMap['Provider'] : [];
skillCheck(str_contains((string) ($providerSeat['prompt_increment'] ?? ''), '支付开发工程师'), 'Provider prompt defers Payment implementation to 支付开发工程师');
$ecommerceSeat = is_array($seatMap['电商顾问'] ?? null) ? $seatMap['电商顾问'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR, is_array($ecommerceSeat['mcp_skill_ids'] ?? null) ? $ecommerceSeat['mcp_skill_ids'] : [], true), '电商顾问 mirror includes ecommerce_advisor');
$ecommerceDocs = is_array($ecommerceSeat['authoritative_docs'] ?? null) ? $ecommerceSeat['authoritative_docs'] : [];
skillCheck(in_array('dev/ai-command/ai/电商顾问.md', $ecommerceDocs, true), '电商顾问 docs include 电商顾问 command');
$ecommercePrompt = (string) ($ecommerceSeat['prompt_increment'] ?? '');
skillCheck(str_contains($ecommercePrompt, '禁止写码') || str_contains($ecommercePrompt, '禁写码'), '电商顾问 prompt forbids coding');
skillCheck(str_contains($ecommercePrompt, '运营策划') || str_contains($ecommercePrompt, '领域决策'), '电商顾问 prompt is ops planner / domain decision');
skillCheck(str_contains($ecommercePrompt, '要开发什么') || str_contains($ecommercePrompt, 'dev_ask'), '电商顾问 prompt covers「要开发什么」wake PM');
skillCheck(str_contains($ecommercePrompt, 'WebSearch') || str_contains($ecommercePrompt, '联网'), '电商顾问 prompt requires web policy research');
skillCheck(str_contains($ecommercePrompt, '支持国家') || str_contains($ecommercePrompt, 'getCountries'), '电商顾问 prompt resolves supported countries first');
skillCheck(str_contains($ecommercePrompt, '每一次') || str_contains($ecommercePrompt, '每次'), '电商顾问 prompt requires research before each discussion');
skillCheck(str_contains($ecommercePrompt, '合规'), '电商顾问 prompt merges former 合规 duties');
skillCheck(!isset($seatMap['合规']), 'seat_skill_mirrors no longer has standalone 合规 seat');
$perfSeat = is_array($seatMap['性能检查工程师'] ?? null) ? $seatMap['性能检查工程师'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK, is_array($perfSeat['mcp_skill_ids'] ?? null) ? $perfSeat['mcp_skill_ids'] : [], true), '性能检查工程师 mirror includes performance_check');
$perfDocs = is_array($perfSeat['authoritative_docs'] ?? null) ? $perfSeat['authoritative_docs'] : [];
skillCheck(in_array('dev/ai-command/ai/性能检查.md', $perfDocs, true), '性能检查工程师 docs include 性能检查 command');
skillCheck(in_array('app/code/Weline/Framework/doc/统一缓存范围与性能优化.md', $perfDocs, true), '性能检查工程师 docs include 统一缓存');
$perfPrompt = (string) ($perfSeat['prompt_increment'] ?? '');
skillCheck(str_contains($perfPrompt, 'HotCache') || str_contains($perfPrompt, 'CachePolicy'), '性能检查工程师 prompt mentions HotCache/CachePolicy');
skillCheck(str_contains($perfPrompt, '框架') || str_contains($perfPrompt, 'WLS'), '性能检查工程师 prompt covers framework constraints');
skillCheck(str_contains($perfPrompt, '检查性能') || str_contains($perfPrompt, '必须检查'), '性能检查工程师 prompt mandates checking performance');
skillCheck(str_contains($perfPrompt, '业务特性'), '性能检查工程师 prompt requires business characteristics');
skillCheck(str_contains($perfPrompt, '架构师'), '性能检查工程师 prompt jointly works with 架构师');
skillCheck(str_contains($perfPrompt, '合规'), '性能检查工程师 prompt requires cache compliance check');
skillCheck(str_contains($perfPrompt, '项目经理') && (str_contains($perfPrompt, '请立刻组队') || str_contains($perfPrompt, '请安排') || str_contains($perfPrompt, '拉起') || str_contains($perfPrompt, 'escalate')), '性能检查工程师 prompt wakes PM to arrange after findings');
skillCheck(str_contains($perfPrompt, '禁拆壳')
    && str_contains($perfPrompt, 'theme_seat_integrity_over_peer_requests')
    && (str_contains($perfPrompt, 'header') || str_contains($perfPrompt, 'default_injections')), '性能检查工程师 prompt forbids strip-shell prescriptions');
$archSeat = is_array($seatMap['架构师'] ?? null) ? $seatMap['架构师'] : [];
$archPrompt = (string) ($archSeat['prompt_increment'] ?? '');
skillCheck(str_contains($archPrompt, '性能检查'), '架构师 prompt mentions 性能检查工程师 collaboration');
skillCheck(str_contains($archPrompt, '缓存') || str_contains($archPrompt, 'HotCache'), '架构师 prompt covers cache co-design');
skillCheck(in_array('app/code/Weline/Framework/doc/统一缓存范围与性能优化.md', is_array($archSeat['authoritative_docs'] ?? null) ? $archSeat['authoritative_docs'] : [], true), '架构师 docs include 统一缓存');
skillCheck(str_contains($perfPrompt, '设计') && (str_contains($perfPrompt, '复审') || str_contains($perfPrompt, '审查')), '性能检查工程师 prompt covers design+review');
$promptSeat = is_array($seatMap['提示词优化工程师'] ?? null) ? $seatMap['提示词优化工程师'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION, is_array($promptSeat['mcp_skill_ids'] ?? null) ? $promptSeat['mcp_skill_ids'] : [], true), '提示词优化工程师 mirror includes prompt_optimization');
$promptDocs = is_array($promptSeat['authoritative_docs'] ?? null) ? $promptSeat['authoritative_docs'] : [];
skillCheck(in_array('dev/ai-command/ai/提示词优化.md', $promptDocs, true), '提示词优化工程师 docs include 提示词优化 command');
$promptInc = (string) ($promptSeat['prompt_increment'] ?? '');
skillCheck(str_contains($promptInc, '技能引用') || str_contains($promptInc, 'get_skill'), '提示词优化工程师 prompt covers skill refs');
skillCheck(str_contains($promptInc, '压缩') || str_contains($promptInc, '重复'), '提示词优化工程师 prompt covers compression');
skillCheck(str_contains($promptInc, '硬规则') || str_contains($promptInc, '语义'), '提示词优化工程师 prompt forbids semantic regression');
skillCheck(str_contains($promptInc, '仅重复') || str_contains($promptInc, '无证据禁止'), '提示词优化工程师 prompt requires duplication evidence before edit');
skillCheck(str_contains($promptInc, '禁止丢义') || str_contains($promptInc, '丢义'), '提示词优化工程师 prompt forbids omitting meaning');
skillCheck(str_contains($promptInc, '禁止乱加') || str_contains($promptInc, '乱加'), '提示词优化工程师 prompt forbids inventing rules');
$i18nSeat = is_array($seatMap['翻译工程师'] ?? null) ? $seatMap['翻译工程师'] : [];
$i18nAliasSeat = is_array($seatMap['i18n'] ?? null) ? $seatMap['i18n'] : [];
skillCheck($i18nSeat !== [], 'seat_skill_mirrors has 翻译工程师');
skillCheck($i18nAliasSeat !== [], 'seat_skill_mirrors keeps i18n alias key');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER, is_array($i18nSeat['mcp_skill_ids'] ?? null) ? $i18nSeat['mcp_skill_ids'] : [], true), '翻译工程师 mirror includes translation_engineer');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N, is_array($i18nSeat['mcp_skill_ids'] ?? null) ? $i18nSeat['mcp_skill_ids'] : [], true), '翻译工程师 mirror includes template_i18n');
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV, is_array($i18nSeat['mcp_skill_ids'] ?? null) ? $i18nSeat['mcp_skill_ids'] : [], true), '翻译工程师 mirror includes module_i18n_csv');
$i18nDocs = is_array($i18nSeat['authoritative_docs'] ?? null) ? $i18nSeat['authoritative_docs'] : [];
skillCheck(in_array('dev/ai-command/ai/翻译工程师.md', $i18nDocs, true), '翻译工程师 docs include 翻译工程师 command');
$i18nPrompt = (string) ($i18nSeat['prompt_increment'] ?? '');
skillCheck(str_contains($i18nPrompt, 'i18n:collect') || str_contains($i18nPrompt, 'collect'), '翻译工程师 prompt mandates collect-first workflow');
skillCheck(str_contains($i18nPrompt, 'zh_Hans_CN') || str_contains($i18nPrompt, 'en_US') || str_contains($i18nPrompt, '中英'), '翻译工程师 prompt scopes zh+en CSV this phase');
skillCheck(str_contains($i18nPrompt, '系统词典') || str_contains($i18nPrompt, 'WebsiteLanguage') || str_contains($i18nPrompt, '默认站'), '翻译工程师 prompt covers non-zh/en via dictionary/default-website locales');
skillCheck(!str_contains($i18nPrompt, '默认先不做') && !str_contains($i18nPrompt, '未明示则不做'), '翻译工程师 prompt forbids misleading skip-other-locales wording');
skillCheck(str_contains($i18nPrompt, '巡检') || str_contains($i18nPrompt, 'locale'), '翻译工程师 prompt covers site locale audit');
skillCheck(str_contains($i18nPrompt, '商品翻译') || str_contains($i18nPrompt, '翻译优化'), '翻译工程师 prompt boundaries content-ops product i18n');
skillCheck(($i18nAliasSeat['prompt_increment'] ?? null) === ($i18nSeat['prompt_increment'] ?? false), 'i18n alias seat shares 翻译工程师 prompt_increment');
$advisorSeat = is_array($seatMap['电商顾问'] ?? null) ? $seatMap['电商顾问'] : [];
$advisorPrompt = (string) ($advisorSeat['prompt_increment'] ?? '');
skillCheck(str_contains($advisorPrompt, 'FAQ') || str_contains($advisorPrompt, 'FaqHub'), '电商顾问 prompt includes FAQ Hub compliance surface');
skillCheck(str_contains($advisorPrompt, '翻译工程师'), '电商顾问 prompt requires 翻译工程师 in suggested_seats when copy changes');
$hookSeat = is_array($seatMap['Hook'] ?? null) ? $seatMap['Hook'] : [];
skillCheck(in_array(GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION, is_array($hookSeat['mcp_skill_ids'] ?? null) ? $hookSeat['mcp_skill_ids'] : [], true), 'Hook mirror includes hook_extension');

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
skillCheck(str_contains($teamCmd, 'tester_tests_must_be_real') || str_contains($teamCmd, '测试必须真'), 'engineering team command mandates tester_tests_must_be_real');
skillCheck(
    str_contains($teamCmd, 'browser_strip_automation_flags') || str_contains($teamCmd, '抹掉自动化标志'),
    'engineering team command mandates browser_strip_automation_flags'
);
skillCheck(
    str_contains($teamCmd, 'browser_operator_non_preemptive') || str_contains($teamCmd, '非抢占'),
    'engineering team command mandates browser_operator_non_preemptive'
);
skillCheck(str_contains($teamCmd, 'component-negotiate.md'), 'engineering team command mentions component-negotiate.md');
skillCheck(str_contains($teamCmd, '框架优先'), 'engineering team command mentions 框架优先');
skillCheck(str_contains($teamCmd, '事件'), 'engineering team command mentions 事件 seat');
skillCheck(str_contains($teamCmd, '数据分析') && str_contains($teamCmd, 'visitor_data_analytics'), 'engineering team command mirrors 数据分析 seat');
skillCheck(str_contains($teamCmd, '技能引用') && str_contains($teamCmd, 'taglib_ui_control'), 'engineering team command requires 数据分析 frontend skill refs');
skillCheck(str_contains($teamCmd, 'analytics_engineer_for_visitor_work') || str_contains($teamCmd, 'Weline_Visitor'), 'engineering team command mentions visitor analytics hard rule');
skillCheck(str_contains($teamCmd, 'seat_skill_mirrors'), 'engineering team command mentions seat_skill_mirrors');
skillCheck(str_contains($teamCmd, 'findings_wake_pm') || str_contains($teamCmd, '直接拉起项目经理') || str_contains($teamCmd, '请立刻组队解决'), 'engineering team command mentions findings_wake_pm');
skillCheck(str_contains($teamCmd, 'requirement_issuer_owns_acceptance') || str_contains($teamCmd, 'waiting_acceptance') || str_contains($teamCmd, '甩手掌柜'), 'engineering team command mentions requirement_issuer_owns_acceptance');
skillCheck(str_contains($teamCmd, 'issuer_acceptance'), 'engineering team command mentions issuer_acceptance');
skillCheck(str_contains($teamCmd, 'requirement_session_dashboard') || str_contains($teamCmd, 'doc/开发/session/'), 'engineering team command mentions SESSION path');
skillCheck(str_contains($teamCmd, 'pm_plan_lifecycle') || str_contains($teamCmd, '计划生命周期'), 'engineering team command mentions pm_plan_lifecycle');
skillCheck(str_contains($teamCmd, 'notify_pm'), 'engineering team command requires notify_pm');
$sessionTplPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/templates/requirement-session.md';
if (!is_file($sessionTplPath)) {
    $sessionTplPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/templates/requirement-session.md';
}
skillCheck(is_file($sessionTplPath), 'requirement-session template exists');
$sessionTpl = is_file($sessionTplPath) ? (string) file_get_contents($sessionTplPath) : '';
skillCheck(str_contains($sessionTpl, '计划项表') && str_contains($sessionTpl, '交付通知日志'), 'requirement-session template has plan + notify sections');
skillCheck(str_contains($sessionTpl, 'issuer_seat') && str_contains($sessionTpl, 'issuer_acceptance'), 'requirement-session template has issuer columns');
skillCheck(str_contains($teamCmd, 'related_web_urls'), 'engineering team command requires related_web_urls on report card');
skillCheck(
    str_contains($teamCmd, 'related_web_urls')
    && (str_contains($teamCmd, 'HARD（related_web_urls）') || str_contains($teamCmd, '禁止空报完成')),
    'engineering team prompt skeleton HARD requires related_web_urls on closed'
);
skillCheck(!str_contains($teamCmd, 'team_issue_board') && str_contains($teamCmd, '不用任务 Issue'), 'engineering team command retired issue board');
$pmSeat = is_array($seatMap['项目经理'] ?? null) ? $seatMap['项目经理'] : [];
$pmPrompt = (string) ($pmSeat['prompt_increment'] ?? '');
skillCheck(str_contains($pmPrompt, 'findings_wake_pm') || str_contains($pmPrompt, '同回合'), '项目经理 prompt covers same-turn staffing on escalate');
skillCheck(str_contains($pmPrompt, 'requirement_issuer_owns_acceptance') || str_contains($pmPrompt, 'issuer_acceptance') || str_contains($pmPrompt, '发起席'), '项目经理 prompt covers issuer acceptance wake');
skillCheck(str_contains($pmPrompt, 'requirement_session_dashboard') || str_contains($pmPrompt, 'session/'), '项目经理 prompt covers SESSION dashboard');
skillCheck(str_contains($pmPrompt, 'pm_plan_lifecycle') || str_contains($pmPrompt, 'notify_pm') || str_contains($pmPrompt, 'DoD'), '项目经理 prompt covers plan lifecycle / DoD');
skillCheck(
    (str_contains($pmPrompt, 'related_web_urls') || str_contains($pmPrompt, '交付地址'))
    && (str_contains($pmPrompt, 'feature_delivery_urls') || str_contains($pmPrompt, 'closeout_delivery_reminder')),
    '项目经理 prompt mandates 交付地址 from related_web_urls'
);
$testSeatForUrls = is_array($seatMap['测试'] ?? null) ? $seatMap['测试'] : [];
$testPromptForUrls = (string) ($testSeatForUrls['prompt_increment'] ?? '');
skillCheck(
    str_contains($testPromptForUrls, 'related_web_urls')
    && (str_contains($testPromptForUrls, '交付地址') || str_contains($testPromptForUrls, '探活')),
    '测试 prompt mandates related_web_urls on closed pass'
);
skillCheck(str_contains($ecommercePrompt, 'findings_wake_pm') || str_contains($ecommercePrompt, '请立刻组队解决') || str_contains($ecommercePrompt, '@项目经理'), '电商顾问 prompt wakes PM on findings');
skillCheck(str_contains($ecommercePrompt, 'requirement_issuer_owns_acceptance') || str_contains($ecommercePrompt, 'waiting_acceptance'), '电商顾问 prompt owns issuer acceptance');
skillCheck(str_contains($perfPrompt, 'findings_wake_pm') || str_contains($perfPrompt, '请立刻组队解决') || str_contains($perfPrompt, '@项目经理'), '性能检查工程师 prompt wakes PM on findings');
skillCheck(str_contains($perfPrompt, 'requirement_issuer_owns_acceptance') || str_contains($perfPrompt, 'waiting_acceptance'), '性能检查工程师 prompt owns issuer acceptance');
$promptOptSeat = is_array($seatMap['提示词优化工程师'] ?? null) ? $seatMap['提示词优化工程师'] : [];
$promptOptPrompt = (string) ($promptOptSeat['prompt_increment'] ?? '');
skillCheck(str_contains($promptOptPrompt, 'requirement_issuer_owns_acceptance') || str_contains($promptOptPrompt, 'waiting_acceptance'), '提示词优化工程师 prompt owns issuer acceptance');
skillCheck(!is_file(dirname(__DIR__, 2) . '/doc/开发/team/board/issues.md'), 'Ai team board issues.md removed');
skillCheck(str_contains($teamCmd, 'frontend_development') || str_contains($teamCmd, 'Theme开发总指南'), 'engineering team command mirrors frontend skills');
skillCheck(str_contains($teamCmd, 'event_extension') || str_contains($teamCmd, '事件命名与注册规范'), 'engineering team command mirrors event skills');
skillCheck(str_contains($teamCmd, 'api_sdk_development') || str_contains($teamCmd, 'API接口开发规范'), 'engineering team command mirrors API skills');
skillCheck(str_contains($teamCmd, '跨模块代写 Rest') || str_contains($teamCmd, 'api_rest_in_owning_module'), 'engineering team command forbids cross-module Rest');
skillCheck(str_contains($teamCmd, 'w_query') || str_contains($teamCmd, '共用 Query'), 'engineering team command mentions shared Query / w_query');
skillCheck(str_contains($teamCmd, '权限旁路') || str_contains($teamCmd, 'backend_acl'), 'engineering team command forbids permission bypass');
skillCheck(str_contains($teamCmd, '支付开发工程师') && str_contains($teamCmd, 'payment_development'), 'engineering team command mirrors payment engineer');
skillCheck(str_contains($teamCmd, 'payment_engineer_for_payment_work') || str_contains($teamCmd, '万能支付'), 'engineering team command mentions payment hard rule or 万能支付');
skillCheck(str_contains($teamCmd, '电商顾问') && str_contains($teamCmd, 'ecommerce_advisor'), 'engineering team command mirrors ecommerce advisor');
skillCheck(str_contains($teamCmd, '禁止写码') || str_contains($teamCmd, '禁写码'), 'engineering team command says 电商顾问 forbids coding');
skillCheck(str_contains($teamCmd, '运营策划') || str_contains($teamCmd, '要开发什么'), 'engineering team command covers ops planner domain decision');
skillCheck(str_contains($teamCmd, '性能检查工程师') && str_contains($teamCmd, 'performance_check'), 'engineering team command mirrors performance engineer');
skillCheck(str_contains($teamCmd, 'performance_engineer_for_design_and_review') || str_contains($teamCmd, 'HotCache'), 'engineering team command mentions performance hard rule or HotCache');
skillCheck(str_contains($teamCmd, '提示词优化工程师') && str_contains($teamCmd, 'prompt_optimization'), 'engineering team command mirrors prompt engineer');
skillCheck(str_contains($teamCmd, 'prompt_engineer_for_skill_prompt_work') || str_contains($teamCmd, '技能引用'), 'engineering team command mentions prompt hard rule or 技能引用');
skillCheck(str_contains($teamCmd, '翻译工程师') && str_contains($teamCmd, 'translation_engineer'), 'engineering team command mirrors translation engineer');
skillCheck(str_contains($teamCmd, 'translation_engineer_for_i18n_work') || str_contains($teamCmd, 'i18n:collect'), 'engineering team command mentions translation hard rule or collect');
skillCheck(!preg_match('/^\| 合规 \|/m', $teamCmd), 'engineering team command retired standalone 合规 seat row');

$paymentCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/支付开发.md';
if (!is_file($paymentCmdPath)) {
    $paymentCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/支付开发.md';
}
$paymentCmd = is_file($paymentCmdPath) ? (string) file_get_contents($paymentCmdPath) : '';
skillCheck($paymentCmd !== '', 'payment development command file readable');
skillCheck(str_contains($paymentCmd, 'payment_development') || str_contains($paymentCmd, 'weline-payment-development'), 'payment command names skill');
skillCheck(str_contains($paymentCmd, 'payment-shell') && str_contains($paymentCmd, 'provider-development'), 'payment command points at shell docs');
skillCheck(str_contains($paymentCmd, 'PCI') || str_contains($paymentCmd, 'cspDirectives'), 'payment command covers security');
skillCheck(str_contains($paymentCmd, 'Team:测试:') || (str_contains($paymentCmd, '拉起测试') && str_contains($paymentCmd, '浏览器')), 'payment command requires wake 测试 + Browser closed-loop');
skillCheck(str_contains($paymentCmd, '验收闭环') || str_contains($paymentCmd, 'payment_browser_e2e_closed_loop'), 'payment command names 验收闭环');

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
