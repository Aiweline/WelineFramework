<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\McpSkillCatalog;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$contract = GuidanceWorkflowCatalog::contract();
$hardConstraintsPackage = HardConstraintsCatalog::package();
$hardRulesFlat = HardConstraintsCatalog::workflowHardRules();
$frontend = is_array($contract['frontend_development'] ?? null)
    ? $contract['frontend_development']
    : [];
$chapterDelivery = is_array($contract['chapter_delivery'] ?? null)
    ? $contract['chapter_delivery']
    : [];
$featureDeliveryUrls = is_array($contract['feature_delivery_urls'] ?? null)
    ? $contract['feature_delivery_urls']
    : [];
$closeoutReminder = is_array($contract['closeout_delivery_reminder'] ?? null)
    ? $contract['closeout_delivery_reminder']
    : [];
$webuiDefaults = is_array($chapterDelivery['webui_defaults'] ?? null)
    ? $chapterDelivery['webui_defaults']
    : [];
$visualEvidence = is_array($webuiDefaults['visual_evidence'] ?? null)
    ? $webuiDefaults['visual_evidence']
    : [];
$templateRules = is_array($contract['template_surface_rules'] ?? null)
    ? $contract['template_surface_rules']
    : [];
$required = is_array($templateRules['required'] ?? null) ? $templateRules['required'] : [];
$forbidden = is_array($templateRules['forbidden'] ?? null) ? $templateRules['forbidden'] : [];
$hardRulesRef = is_array($contract['hard_rules'] ?? null) ? $contract['hard_rules'] : [];
$hardRules = $hardRulesFlat;
$norms = is_array($frontend['norms'] ?? null) ? $frontend['norms'] : [];
$surfaces = is_array($contract['surfaces'] ?? null) ? $contract['surfaces'] : [];
$pinned = GuidanceWorkflowCatalog::pinnedDocumentPaths();

$taglibSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL]
    : [];
$hookSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION]
    : [];
$eventSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION]
    : [];
$i18nSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N]
    : [];
$moduleUpgradeSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE]
    : [];
$moduleI18nCsvSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV]
    : [];
$webuiBrowserCloseoutSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT]
    : [];
$activeHookIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('新建 hook view/hooks');
$activeTaglibIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('taglib select switcher');
$activeModuleUpgradeIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('改了 Model #[Col] 新建 Controller');
$activeI18nCsvIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('i18n csv en_US collect 翻译');
$activeWebuiCloseoutIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('后台页面验收交付 Browser 自测');
$activeClarifyIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('需求澄清 用例规格 EARS clarify');
$clarifySurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE]
    : [];
$activeTeamIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('工程团队 停工汇报 子智能体');
$teamSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM]
    : [];
$apiSdkSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT]
    : [];
$widgetDevSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT]
    : [];
$themeDevSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT]
    : [];
$paymentDevSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT]
    : [];
$ecommerceAdvisorSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR]
    : [];
$performanceCheckSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK]
    : [];
$promptOptimizationSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION]
    : [];
$translationEngineerSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER]
    : [];
$visitorAnalyticsSurface = is_array($surfaces[GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS] ?? null)
    ? $surfaces[GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS]
    : [];
$activeWidgetIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('部件开发工程师 default_injections placement');
$activeThemeIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('主题开发工程师 Theme Token 预览三态 app/design');
$activePaymentIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('支付开发工程师 万能支付 退款 Provider');
$activeEcommerceAdvisorIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('电商顾问 结账合规 站店渠');
$activePerformanceCheckIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('性能检查工程师 HotCache N+1 慢请求');
$activePromptOptimizationIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('提示词优化工程师 技能压缩 seat_skill_mirrors');
$activeTranslationEngineerIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('翻译工程师 漏译 i18n:collect');
$activeVisitorAnalyticsIds = GuidanceWorkflowCatalog::resolveActiveSurfaceIds('数据分析 像素事件 WelinePixel Visitor');
$engineeringTeamBundle = McpSkillCatalog::policy()['engineering_team_bundle'] ?? [];

$hasSectionIdentityNorm = false;
foreach ($norms as $norm) {
    if (is_array($norm) && ($norm['id'] ?? '') === 'section_identity') {
        $hasSectionIdentityNorm = true;
        break;
    }
}

$widgetAssetNorm = '';
foreach ($widgetDevSurface['norms'] ?? [] as $norm) {
    if (($norm['id'] ?? '') === 'widget_static_assets_bake_to_head') {
        $widgetAssetNorm = (string) ($norm['summary'] ?? '');
    }
}
$checks = [
    'widget assets expose both position spellings and body alias' => str_contains($widgetAssetNorm, 'source-postion')
        && str_contains($widgetAssetNorm, 'source-position') && str_contains($widgetAssetNorm, 'end-body')
        && str_contains($widgetAssetNorm, 'source-postion（优先）')
        && str_contains($widgetAssetNorm, 'head→footer→body')
        && str_contains($widgetAssetNorm, '无 footer 落 body 末尾'),
    'widget assets forbid all inline CSS and executable JS' => str_contains($widgetAssetNorm, '所有')
        && str_contains($widgetAssetNorm, 'style=') && str_contains($widgetAssetNorm, 'on*=')
        && !str_contains($widgetAssetNorm, '大段'),

    'surface id is frontend_development' => ($frontend['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT
        && ($frontend['label'] ?? '') === '前端开发规范',
    'session_startup_notices are pointer-only' => is_array($contract['session_startup_notices'] ?? null)
        && count($contract['session_startup_notices']) >= 2
        && count($contract['session_startup_notices']) <= 10
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'hard-constraints.v1')),
            false,
        )
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'AI硬规则索引.md')),
            false,
        )
        && !array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $hit, mixed $notice): bool => $hit || (is_string($notice) && str_contains($notice, 'Weline UI 2.0') && str_contains($notice, 'w-field')),
            false,
        ),
    'hard_constraints package present' => is_array($hardConstraintsPackage)
        && ($hardConstraintsPackage['schema'] ?? '') === HardConstraintsCatalog::SCHEMA
        && ($hardConstraintsPackage['must_obey'] ?? false) === true
        && ($hardConstraintsPackage['authoritative_doc'] ?? '') === HardConstraintsCatalog::AUTHORITATIVE_DOC
        && is_array($hardConstraintsPackage['rules'] ?? null)
        && count($hardConstraintsPackage['rules']) >= 10
        && is_string($hardConstraintsPackage['preamble'] ?? null)
        && str_contains((string) $hardConstraintsPackage['preamble'], 'hard-constraints.v1'),
    'workflow_contract hard_constraints is pointer' => is_array($contract['hard_constraints'] ?? null)
        && ($contract['hard_constraints']['schema'] ?? '') === HardConstraintsCatalog::SCHEMA
        && ($contract['hard_constraints']['must_obey'] ?? false) === true
        && ($contract['hard_constraints']['source'] ?? '') === 'prepare_project.agent_guidance.hard_constraints'
        && !isset($contract['hard_constraints']['rules'])
        && !isset($contract['hard_constraints']['preamble']),
    'workflow_contract hard_rules is id index' => is_array($hardRulesRef)
        && ($hardRulesRef['schema'] ?? '') === 'hard-rules-ref.v1'
        && ($hardRulesRef['source'] ?? '') === 'prepare_project.agent_guidance.hard_constraints'
        && is_array($hardRulesRef['rule_ids'] ?? null)
        && count($hardRulesRef['rule_ids']) >= 10
        && is_array($hardRulesRef['operational_ids'] ?? null)
        && count($hardRulesRef['operational_ids']) >= 1
        && !array_is_list($hardRulesRef),
    'hard_constraints include weline_ui_theme_first' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'weline_ui_theme_first'),
        false,
    ),
    'hard_constraints include preview_storefront_delivery_parity' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'preview_storefront_delivery_parity'
            && str_contains((string) ($rule['summary'] ?? ''), 'FORBIDDEN')
            && str_contains((string) ($rule['summary'] ?? ''), 'SAME business logic')
            && str_contains((string) ($rule['summary'] ?? ''), 'early-return')
            && str_contains((string) ($rule['summary'] ?? ''), 'Hook')),
        false,
    ),
    'hard_constraints include storefront_internal_url_via_url_helper' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'storefront_internal_url_via_url_helper'
            && str_contains((string) ($rule['summary'] ?? ''), '@url')
            && str_contains((string) ($rule['summary'] ?? ''), 'getUrl')
            && str_contains((string) ($rule['doc'] ?? ''), '06-url')),
        false,
    ),
    'hard_constraints include theme_base_components_token_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'theme_base_components_token_only'),
        false,
    ),
    'hard_constraints include ui_skill_requires_theme_skill' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'ui_skill_requires_theme_skill'
            && str_contains((string) ($rule['summary'] ?? ''), 'weline-theme-development')
            && str_contains((string) ($rule['summary'] ?? ''), 'Forbid inventing')),
        false,
    ),
    'hard_constraints include backend_admin_ui_requires_frontend_theme_skills' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'backend_admin_ui_requires_frontend_theme_skills'
            && str_contains((string) ($rule['summary'] ?? ''), 'w-backend-page')
            && str_contains((string) ($rule['summary'] ?? ''), 'weline-theme-development')),
        false,
    ),
    'hard_constraints include css_or_theme_requires_ui_prototype_theme_skills' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'css_or_theme_requires_ui_prototype_theme_skills'
            && str_contains((string) ($rule['summary'] ?? ''), 'frontend-design')
            && str_contains((string) ($rule['summary'] ?? ''), 'prototype')
            && str_contains((string) ($rule['summary'] ?? ''), 'weline-theme-development')),
        false,
    ),
    'hard_constraints include user_image_attachment_triggers_shentu' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'user_image_attachment_triggers_shentu'
            && str_contains((string) ($rule['summary'] ?? ''), '审图')
            && str_contains((string) ($rule['summary'] ?? ''), 'image')
            && str_contains((string) ($rule['summary'] ?? ''), 'human factors')
            && str_contains((string) ($rule['summary'] ?? ''), 'frontend-design')
            && str_contains((string) ($rule['summary'] ?? ''), 'prototype')
            && str_contains((string) ($rule['doc'] ?? ''), '审图.md')),
        false,
    ),
    'hard_constraints include requirement_feature_kind_gate' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_feature_kind_gate'
            && str_contains((string) ($rule['summary'] ?? ''), 'work_kind')
            && str_contains((string) ($rule['summary'] ?? ''), 'ui_skill_decision')),
        false,
    ),
    'hard_constraints include requirement_implicit_analysis_skill_decision' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_implicit_analysis_skill_decision'
            && str_contains((string) ($rule['summary'] ?? ''), 'implicit_requirements')
            && str_contains((string) ($rule['summary'] ?? ''), 'ui_skill_decision')),
        false,
    ),
    'hard_constraints include acceptance_phase_requires_shentu' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'acceptance_phase_requires_shentu'
            && str_contains((string) ($rule['summary'] ?? ''), '审图')
            && str_contains((string) ($rule['summary'] ?? ''), 'shentu')),
        false,
    ),
    'hard_constraints include closeout_requires_huishen' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'closeout_requires_huishen'
            && str_contains((string) ($rule['summary'] ?? ''), '汇审')
            && str_contains((string) ($rule['summary'] ?? ''), 'huishen_notes')),
        false,
    ),
    'hard_constraints include theme_address_for_region_pickers' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'theme_address_for_region_pickers'
            && str_contains((string) ($rule['summary'] ?? ''), '<w:theme:address>')),
        false,
    ),
    'hard_constraints include weline_ui_floating_primitives' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'weline_ui_floating_primitives'),
        false,
    ),
    'hard_constraints include no_native_js_dialogs' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'no_native_js_dialogs'
            && str_contains((string) ($rule['summary'] ?? ''), 'window.alert')
            && str_contains((string) ($rule['summary'] ?? ''), 'Weline.UI.toast')),
        false,
    ),
    'hard_constraints include frontend_unified_content_container' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'frontend_unified_content_container'
            && str_contains((string) ($rule['summary'] ?? ''), 'never invent a private page container')),
        false,
    ),
    'hard_constraints include theme_js_module_declare_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'theme_js_module_declare_only'),
        false,
    ),
    'hard_constraints include weline_js_loader_framework_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'weline_js_loader_framework_only'
            && str_contains((string) ($rule['summary'] ?? ''), 'MANDATORY')
            && str_contains((string) ($rule['summary'] ?? ''), 'ModuleLoader core only')
            && str_contains((string) ($rule['summary'] ?? ''), 'account')
            && str_contains((string) ($rule['summary'] ?? ''), 'cart')
            && str_contains((string) ($rule['summary'] ?? ''), 'maintenance')
            && str_contains((string) ($rule['summary'] ?? ''), 'NOT account')
            && !str_contains((string) ($rule['summary'] ?? ''), 'Core aliases allowed: api / account')),
        false,
    ),
    'hard_constraints include dom_mutation_observe_via_weline_dom' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'dom_mutation_observe_via_weline_dom'
            && str_contains((string) ($rule['summary'] ?? ''), 'MANDATORY architecture')
            && str_contains((string) ($rule['summary'] ?? ''), 'Weline.dom.observe')
            && str_contains((string) ($rule['summary'] ?? ''), 'shared coalesced bus')
            && str_contains((string) ($rule['doc'] ?? ''), 'DOM-Mutation观察总线')),
        false,
    ),
    'hard_constraints include at_lang_no_unquoted_comma' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'at_lang_no_unquoted_comma'),
        false,
    ),
    'hard_constraints include no_php_tags_in_comments' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'no_php_tags_in_comments'
            && str_contains((string) ($rule['summary'] ?? ''), 'inside comments')
            && str_contains((string) ($rule['summary'] ?? ''), 'not ordinary commented-out')),
        false,
    ),
    'hard_constraints omit cancelled cache_lookup_tier_process_shared_db' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'cache_lookup_tier_process_shared_db'),
        false,
    ) === false,
    'hard_constraints include chinese_comments_friendly_style' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'chinese_comments_friendly_style'
            && str_contains((string) ($rule['summary'] ?? ''), 'Simplified Chinese')
            && str_contains((string) ($rule['summary'] ?? ''), 'friendly')),
        false,
    ),
    'hard_constraints include browser_operator_self_test' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'browser_operator_self_test'),
        false,
    ),
    'hard_constraints include browser_operator_non_preemptive' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'browser_operator_non_preemptive'
            && str_contains((string) ($rule['summary'] ?? ''), 'BACKGROUND')
            && str_contains((string) ($rule['summary'] ?? ''), 'position')
            && str_contains((string) ($rule['summary'] ?? ''), 'active')),
        false,
    ),
    'hard_constraints include ui_feature_requires_e2e' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'ui_feature_requires_e2e'
            && str_contains((string) ($rule['summary'] ?? ''), 'work_kind=feature')
            && str_contains((string) ($rule['summary'] ?? ''), 'SIMPLE EXEMPTION')
            && str_contains((string) ($rule['summary'] ?? ''), 'WB-OP')),
        false,
    ),
    'hard_constraints include forbid_user_manual_test_handoff' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'forbid_user_manual_test_handoff'
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT ask the user to test')
            && str_contains((string) ($rule['summary'] ?? ''), 'login credentials')
            && str_contains((string) ($rule['summary'] ?? ''), 'admin/admin')),
        false,
    ),
    'hard_constraints include acceptance_real_business_pathway' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'acceptance_real_business_pathway'
            && str_contains((string) ($rule['summary'] ?? ''), 'REAL business-pathway')
            && str_contains((string) ($rule['summary'] ?? ''), 'order_uuid')
            && str_contains((string) ($rule['summary'] ?? ''), 'shell-only')),
        false,
    ),
    'hard_constraints include tester_tests_must_be_real' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'tester_tests_must_be_real'
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST be real')
            && (str_contains((string) ($rule['summary'] ?? ''), 'fake fixtures')
                || str_contains((string) ($rule['summary'] ?? ''), 'invent'))
            && str_contains((string) ($rule['summary'] ?? ''), 'PASS')),
        false,
    ),
    'hard_constraints include browser_strip_automation_flags' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'browser_strip_automation_flags'
            && str_contains((string) ($rule['summary'] ?? ''), 'navigator.webdriver')
            && (str_contains((string) ($rule['summary'] ?? ''), 'AutomationControlled')
                || str_contains((string) ($rule['summary'] ?? ''), 'enable-automation'))
            && (str_contains((string) ($rule['summary'] ?? ''), 'reCAPTCHA')
                || str_contains((string) ($rule['summary'] ?? ''), 'captcha'))),
        false,
    ),
    'hard_constraints include e2e_playwright_headless_default' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'e2e_playwright_headless_default'
            && str_contains((string) ($rule['summary'] ?? ''), 'headless')
            && str_contains((string) ($rule['summary'] ?? ''), '--headed')),
        false,
    ),
    'hard_constraints include e2e_playwright_formal_runner_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'e2e_playwright_formal_runner_only'
            && str_contains((string) ($rule['summary'] ?? ''), 'formal runner')
            && str_contains((string) ($rule['summary'] ?? ''), 'node -e')
            && str_contains((string) ($rule['summary'] ?? ''), 'chromium.launch')),
        false,
    ),
    'hard_constraints include browser_cache_disabled_on_open' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'browser_cache_disabled_on_open'
            && str_contains((string) ($rule['summary'] ?? ''), 'setCacheDisabled')
            && str_contains((string) ($rule['summary'] ?? ''), 'ignoreCache')),
        false,
    ),
    'hard_constraints include browser_release_after_delivery' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'browser_release_after_delivery'
            && str_contains((string) ($rule['summary'] ?? ''), 'close')
            && str_contains((string) ($rule['summary'] ?? ''), '交付地址')),
        false,
    ),
    'hard_constraints include cursor_debug_csp_developer_tooling' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'cursor_debug_csp_developer_tooling'
            && str_contains((string) ($rule['summary'] ?? ''), 'csp_developer_tooling')
            && str_contains((string) ($rule['summary'] ?? ''), '127.0.0.1:7277')
            && str_contains((string) ($rule['summary'] ?? ''), 'DEV')),
        false,
    ),
    'hard_constraints include image_explicit_width_height_css' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'image_explicit_width_height_css'
            && str_contains((string) ($rule['summary'] ?? ''), 'width')
            && str_contains((string) ($rule['summary'] ?? ''), 'CLS')
            && str_contains((string) ($rule['summary'] ?? ''), 'file:image')),
        false,
    ),
    'hard_constraints feature_delivery_urls requires section' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'feature_delivery_urls'
            && str_contains((string) ($rule['summary'] ?? ''), '交付地址')
            && str_contains((string) ($rule['summary'] ?? ''), 'browser_release_after_delivery')),
        false,
    ),
    'hard_constraints include architecture_first_for_requirements' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'architecture_first_for_requirements'
            && str_contains((string) ($rule['summary'] ?? ''), 'architecture')),
        false,
    ),
    'hard_constraints include requirement_framework_scrutiny' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_framework_scrutiny'
            && str_contains((string) ($rule['summary'] ?? ''), 'requirement_scrutiny')
            && str_contains((string) ($rule['summary'] ?? ''), '需求纠偏')),
        false,
    ),
    'hard_constraints include framework_decoupled_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'framework_decoupled_only'
            && str_contains((string) ($rule['summary'] ?? ''), 'decoupled')
            && str_contains((string) ($rule['summary'] ?? ''), '耦合提示')),
        false,
    ),
    'hard_constraints include shell_provider_business_isomorph' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'shell_provider_business_isomorph'
            && str_contains((string) ($rule['summary'] ?? ''), 'Provider')
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT reimplement')),
        false,
    ),
    'mandatory_before_code includes requirements_confirmed_or_scoped' => in_array(
        'requirements_confirmed_or_scoped',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes requirement_framework_scrutiny' => in_array(
        'requirement_framework_scrutiny',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes architecture_mapped_to_requirements' => in_array(
        'architecture_mapped_to_requirements',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes framework_decoupled_design' => in_array(
        'framework_decoupled_design',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_closeout includes requirement_scrutiny_reported' => in_array(
        'requirement_scrutiny_reported',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mandatory_before_closeout includes coupling_findings_reported' => in_array(
        'coupling_findings_reported',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'closeout reminder requires requirement scrutiny report' => (bool) ($contract['closeout_delivery_reminder']['requirement_scrutiny_report_required'] ?? false)
        && (($contract['closeout_delivery_reminder']['requirement_scrutiny_section_title'] ?? '') === '需求纠偏'),
    'closeout reminder requires coupling report' => (bool) ($contract['closeout_delivery_reminder']['coupling_report_required'] ?? false)
        && (($contract['closeout_delivery_reminder']['coupling_section_title'] ?? '') === '耦合提示'),
    'hard_constraints include plan_todo_evidence_closeout' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'plan_todo_evidence_closeout'
            && str_contains((string) ($rule['summary'] ?? ''), 'evidence')),
        false,
    ),
    'hard_constraints include agent_self_verify_before_done' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'agent_self_verify_before_done'
            && str_contains((string) ($rule['summary'] ?? ''), 'self-verify')
            && str_contains((string) ($rule['summary'] ?? ''), 'evidence')),
        false,
    ),
    'mandatory_before_code includes prepare_project_hard_constraints_when_mcp_attached' => in_array(
        'prepare_project_hard_constraints_when_mcp_attached',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes optional_resolve_task_context_or_get_skill' => in_array(
        'optional_resolve_task_context_or_get_skill',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code is read-only MCP plus engineering gates' => ($contract['mandatory_before_code'] ?? null) === [
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
    'hard_constraints include requirement_clarify_use_case_spec' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_clarify_use_case_spec'
            && str_contains((string) ($rule['summary'] ?? ''), 'EARS')
            && str_contains((string) ($rule['summary'] ?? ''), 'doc/开发/spec')),
        false,
    ),
    'hard_constraints include host_plan_mode_for_planning' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'host_plan_mode_for_planning'
            && str_contains((string) ($rule['summary'] ?? ''), 'Plan Mode')
            && str_contains((string) ($rule['summary'] ?? ''), 'SIMPLE SKIP')),
        false,
    ),
    'hard_constraints include host_delegate_explore_plan_review_to_codex_cli' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'host_delegate_explore_plan_review_to_codex_cli'
            && str_contains((string) ($rule['summary'] ?? ''), 'OPT-IN')
            && str_contains((string) ($rule['summary'] ?? ''), 'did NOT mention Codex')
            && str_contains((string) ($rule['summary'] ?? ''), 'codex exec')
            && str_contains((string) ($rule['summary'] ?? ''), 'codex review')
            && str_contains((string) ($rule['summary'] ?? ''), 'Cursor')
            && str_contains((string) ($rule['summary'] ?? ''), 'content_ops_skills_skip_mcp')
            && str_contains((string) ($rule['summary'] ?? ''), 'knowledge.codex.enabled')
            && (str_contains((string) ($rule['summary'] ?? ''), '-m') || str_contains((string) ($rule['summary'] ?? ''), '--model'))
            && str_contains((string) ($rule['summary'] ?? ''), 'host_plan_mode_for_planning')
            && str_contains((string) ($rule['summary'] ?? ''), 'plan_content_focus_only')
            && (str_contains((string) ($rule['summary'] ?? ''), 'FORBID spawning')
                || str_contains((string) ($rule['summary'] ?? ''), 'nested'))
            && (str_contains((string) ($rule['summary'] ?? ''), 'vague')
                || str_contains((string) ($rule['summary'] ?? ''), 'second'))
            && str_contains((string) ($rule['summary'] ?? ''), '正在工作')
            && str_contains((string) ($rule['summary'] ?? ''), 'silent')),
        false,
    ),
    'hostCodexDelegation schema and plan contract' => (static function () use ($hardConstraintsPackage): bool {
        $delegation = HardConstraintsCatalog::hostCodexDelegation();
        $sections = $delegation['plan_content_contract']['sections_only'] ?? null;
        $planTpl = (string) ($delegation['plan_command_template'] ?? '');
        $reviewTpl = (string) ($delegation['review_command_template'] ?? '');
        $fallbackWhen = $delegation['fallback']['when'] ?? [];
        $pkgDelegation = $hardConstraintsPackage['host_codex_delegation'] ?? null;
        $visible = $delegation['user_visible_status'] ?? null;
        $optIn = $delegation['opt_in'] ?? null;

        return ($delegation['schema_version'] ?? '') === 'host-codex-delegation.v1'
            && ($delegation['policy_id'] ?? '') === 'host_delegate_explore_plan_review_to_codex_cli'
            && ($delegation['enabled_when'] ?? '') === 'user_explicitly_mentions_codex_and_codex_cli_available_and_host_is_not_codex'
            && ($delegation['independent_of_nested_planner'] ?? false) === true
            && ($delegation['native_codex_recursion_guard']['when_host_is_codex'] ?? '') === 'do_not_spawn_nested_codex'
            && ($delegation['model_policy']['model_argument_forbidden'] ?? false) === true
            && is_array($optIn)
            && ($optIn['required'] ?? false) === true
            && ($optIn['forbid_auto_delegate_on_cli_presence_alone'] ?? false) === true
            && ($optIn['gate'] ?? '') === 'user_message_mentions_codex'
            && is_array($optIn['trigger_tokens'] ?? null)
            && in_array('Codex', $optIn['trigger_tokens'], true)
            && is_array($sections)
            && $sections === ['背景', '方案', '细节']
            && str_contains($planTpl, 'read-only')
            && str_contains($planTpl, 'approval_policy="never"')
            && str_contains($planTpl, 'printf')
            && str_contains($planTpl, '$PLAN_PROMPT')
            && str_contains($reviewTpl, '--uncommitted')
            && str_contains($reviewTpl, 'cd "$REPOSITORY"')
            && is_string($delegation['plan_prompt'] ?? null)
            && is_string($delegation['review_prompt'] ?? null)
            && str_contains((string) $delegation['plan_prompt'], '背景')
            && !preg_match('/(^|\\s)-m(\\s|=|$)/', $planTpl)
            && !str_contains($planTpl, '--model')
            && !preg_match('/(^|\\s)-m(\\s|=|$)/', $reviewTpl)
            && !str_contains($reviewTpl, '--model')
            && is_array($fallbackWhen)
            && in_array('cli_missing', $fallbackWhen, true)
            && in_array('not_executable', $fallbackWhen, true)
            && in_array('authentication_failure', $fallbackWhen, true)
            && in_array('timeout', $fallbackWhen, true)
            && in_array('invalid_output', $fallbackWhen, true)
            && is_array($pkgDelegation)
            && ($pkgDelegation['policy_id'] ?? '') === 'host_delegate_explore_plan_review_to_codex_cli'
            && is_array($visible)
            && ($visible['required'] ?? false) === true
            && ($visible['forbid_silent_delegation'] ?? false) === true
            && ($visible['announce_in_chat_before_launch'] ?? false) === true
            && is_array($visible['must_include_tokens'] ?? null)
            && in_array('Codex', $visible['must_include_tokens'], true)
            && in_array('正在工作', $visible['must_include_tokens'], true)
            && str_contains((string) ($visible['phrases']['start_zh'] ?? ''), 'Codex 正在工作');
    })(),
    'hard_constraints include plan_content_focus_only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'plan_content_focus_only'
            && str_contains((string) ($rule['summary'] ?? ''), '背景')
            && str_contains((string) ($rule['summary'] ?? ''), '方案')
            && str_contains((string) ($rule['summary'] ?? ''), '细节')
            && str_contains((string) ($rule['summary'] ?? ''), 'topic drift')),
        false,
    ),
    'hard_constraints include requirement_acceptance_always' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_acceptance_always'
            && str_contains((string) ($rule['summary'] ?? ''), 'WB-OP')
            && str_contains((string) ($rule['summary'] ?? ''), 'visual')),
        false,
    ),
    'hard_constraints include requirement_fe_be_scope_analysis' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_fe_be_scope_analysis'
            && str_contains((string) ($rule['summary'] ?? ''), 'frontend')
            && str_contains((string) ($rule['summary'] ?? ''), 'backend')),
        false,
    ),
    'hard_constraints ui_skill_surface includes humanization complaints' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'ui_skill_surface_signal_gate'
            && str_contains((string) ($rule['summary'] ?? ''), '不够人性化')
            && str_contains((string) ($rule['summary'] ?? ''), '被吐槽')),
        false,
    ),
    'mandatory_before_code includes requirement_clarify_use_case_spec' => in_array(
        'requirement_clarify_use_case_spec',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes host_plan_mode_enabled_or_simple_skip' => in_array(
        'host_plan_mode_enabled_or_simple_skip',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_code includes engineering_team_staffed_or_exempt' => in_array(
        'engineering_team_staffed_or_exempt',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'hard_constraints include engineering_team_for_new_requirements' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'engineering_team_for_new_requirements'
            && str_contains((string) ($rule['summary'] ?? ''), '停工')
            && str_contains((string) ($rule['summary'] ?? ''), 'simple')
            && str_contains((string) ($rule['summary'] ?? ''), '产品优化')
            && str_contains((string) ($rule['summary'] ?? ''), 'dev/team/')
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:架构师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:项目经理:')
            && str_contains((string) ($rule['summary'] ?? ''), 'ONE_SEAT_ONE_AGENT')
            && str_contains((string) ($rule['summary'] ?? ''), 'PEER_TALK_VIA_CHANNEL')
            && str_contains((string) ($rule['summary'] ?? ''), '监工')
            && str_contains((string) ($rule['summary'] ?? ''), 'admin/admin')
            && str_contains((string) ($rule['summary'] ?? ''), 'DUAL TRACK')
            && str_contains((string) ($rule['summary'] ?? ''), 'acceptance-ui.md')
            && str_contains((string) ($rule['summary'] ?? ''), 'FRAMEWORK FIRST')
            && str_contains((string) ($rule['summary'] ?? ''), '扩展点')
            && str_contains((string) ($rule['summary'] ?? ''), 'component-negotiate.md')
            && str_contains((string) ($rule['summary'] ?? ''), 'SEAT_SKILL_MIRRORS')
            && str_contains((string) ($rule['summary'] ?? ''), 'module_doc_forbids_ephemeral_work_artifacts')),
        false,
    ),
    'hard_constraints include findings_wake_pm' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'findings_wake_pm'
            && str_contains((string) ($rule['summary'] ?? ''), '项目经理')
            && str_contains((string) ($rule['summary'] ?? ''), 'escalate')
            && (str_contains((string) ($rule['summary'] ?? ''), 'Issue') || str_contains((string) ($rule['summary'] ?? ''), 'task list'))),
        false,
    ),
    'hard_constraints include requirement_issuer_owns_acceptance' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_issuer_owns_acceptance'
            && str_contains((string) ($rule['summary'] ?? ''), 'waiting_acceptance')
            && str_contains((string) ($rule['summary'] ?? ''), 'issuer_acceptance')
            && (str_contains((string) ($rule['summary'] ?? ''), 'hands-off')
                || str_contains((string) ($rule['summary'] ?? ''), '甩手')
                || str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT'))),
        false,
    ),
    'hard_constraints include requirement_session_dashboard' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'requirement_session_dashboard'
            && str_contains((string) ($rule['summary'] ?? ''), 'session/')
            && str_contains((string) ($rule['summary'] ?? ''), '项目经理')
            && (str_contains((string) ($rule['summary'] ?? ''), '未完成') || str_contains((string) ($rule['summary'] ?? ''), 'gaps'))),
        false,
    ),
    'hard_constraints include pm_plan_lifecycle' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'pm_plan_lifecycle'
            && str_contains((string) ($rule['summary'] ?? ''), 'notify_pm')
            && str_contains((string) ($rule['summary'] ?? ''), 'DoD')
            && (str_contains((string) ($rule['summary'] ?? ''), 'plan_id') || str_contains((string) ($rule['summary'] ?? ''), 'SESSION'))),
        false,
    ),
    'hard_constraints include ui_prototype_gate_before_test' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'ui_prototype_gate_before_test'
            && str_contains((string) ($rule['summary'] ?? ''), 'acceptance-ui.md')
            && str_contains((string) ($rule['summary'] ?? ''), 'resume')
            && (str_contains((string) ($rule['summary'] ?? ''), 'Tester') || str_contains((string) ($rule['summary'] ?? ''), '测试'))
            && str_contains((string) ($rule['summary'] ?? ''), '汇审')),
        false,
    ),
    'engineering team surface norms include dual track and acceptance signoff' => count(array_intersect(
        [
            'framework_first',
            'dual_track_all_specialty_seats',
            'component_reuse_or_negotiate',
            'surfaces_md_required',
            'acceptance_ui_and_prototype_signoff',
            'ui_prototype_gate_before_test',
            'one_seat_one_agent',
            'peer_talk_via_channel',
            'seat_skill_mirrors_required',
            'api_rest_in_owning_module',
            'widget_work_assigns_widget_engineer',
            'theme_work_assigns_theme_engineer',
            'visitor_work_assigns_data_analytics',
            'findings_wake_pm',
            'requirement_issuer_owns_acceptance',
            'requirement_session_dashboard',
            'pm_plan_lifecycle',
        ],
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($teamSurface['norms'] ?? null) ? $teamSurface['norms'] : [],
        ))),
    )) === 17,
    'engineering team surface norms include seat_closed_reports_related_web_urls' => in_array(
        'seat_closed_reports_related_web_urls',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($teamSurface['norms'] ?? null) ? $teamSurface['norms'] : [],
        ))),
        true,
    ),
    'hard_constraints include api_rest_in_owning_module' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'api_rest_in_owning_module'
            && str_contains((string) ($rule['summary'] ?? ''), 'Weline_Websites')
            && str_contains((string) ($rule['summary'] ?? ''), 'Weline_I18n')
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:API:')
            && (str_contains((string) ($rule['summary'] ?? ''), 'SHARED QUERY CORE')
                || str_contains((string) ($rule['summary'] ?? ''), 'w_query'))
            && (str_contains((string) ($rule['summary'] ?? ''), 'PERMISSION MATRIX')
                || str_contains((string) ($rule['summary'] ?? ''), 'backend_acl'))),
        false,
    ),
    'api_sdk_development surface exists' => ($apiSdkSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_API_SDK_DEVELOPMENT
        && str_contains((string) ($apiSdkSurface['authoritative_doc'] ?? ''), 'align-freeze'),
    'api_sdk_development surface includes shared query core norms' => count(array_intersect(
        ['api_shared_query_core', 'api_per_entry_permission_matrix'],
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($apiSdkSurface['norms'] ?? null) ? $apiSdkSurface['norms'] : [],
        ))),
    )) === 2,
    'widget_development surface exists' => ($widgetDevSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT
        && str_contains((string) ($widgetDevSurface['authoritative_doc'] ?? ''), '部件开发指南'),
    'theme_development surface exists' => ($themeDevSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT
        && str_contains((string) ($themeDevSurface['authoritative_doc'] ?? ''), 'Theme开发总指南')
        && ($themeDevSurface['authoritative_skill'] ?? '') === 'weline-theme-development',
    'theme_development norms include dual_workflow_work_mode_gate' => in_array(
        'dual_workflow_work_mode_gate',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
        ))),
        true,
    )
        && in_array(
            'forbid_design_override_theme_css_js',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'new_design_theme_lifecycle_checklist',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'area_frontend_backend_and_four_layers',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'required_default_always_present_without_user_deleted',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'theme_seat_integrity_over_peer_requests',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'public_component_library_dual_stack',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        ),
    'theme_development command doc declares work_mode and theme:active' => (static function (): bool {
        $themeCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/主题开发.md';
        if (!is_file($themeCmdPath)) {
            $themeCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/主题开发.md';
        }
        if (!is_file($themeCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($themeCmdPath);

        return str_contains($body, 'work_mode') && str_contains($body, 'theme:active');
    })(),
    'theme_development command doc covers required_default_always_present' => (static function (): bool {
        $themeCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/主题开发.md';
        if (!is_file($themeCmdPath)) {
            $themeCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/主题开发.md';
        }
        if (!is_file($themeCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($themeCmdPath);

        return str_contains($body, '必装永远存在')
            && str_contains($body, 'user_deleted@{versionId}')
            && str_contains($body, 'required_default_always_present_without_user_deleted');
    })(),
    'theme_development command doc covers seat integrity over peer requests' => (static function (): bool {
        $themeCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/主题开发.md';
        if (!is_file($themeCmdPath)) {
            $themeCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/主题开发.md';
        }
        if (!is_file($themeCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($themeCmdPath);

        return str_contains($body, '席位底线')
            && str_contains($body, 'theme_seat_integrity_over_peer_requests')
            && str_contains($body, 'escalate')
            && str_contains($body, '驳回');
    })(),
    'performance_check command doc forbids strip-shell prescriptions' => (static function (): bool {
        $perfCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/性能检查.md';
        if (!is_file($perfCmdPath)) {
            $perfCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/性能检查.md';
        }
        if (!is_file($perfCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($perfCmdPath);

        return str_contains($body, '禁拆壳')
            && str_contains($body, 'theme_seat_integrity_over_peer_requests')
            && str_contains($body, 'header');
    })(),
    'theme_development command doc covers four layers or public component library' => (static function (): bool {
        $themeCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/主题开发.md';
        if (!is_file($themeCmdPath)) {
            $themeCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/主题开发.md';
        }
        if (!is_file($themeCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($themeCmdPath);

        return str_contains($body, '四层') || str_contains($body, '公共组件库');
    })(),
    'theme_development command doc covers binding cache semantic weline-code' => (static function (): bool {
        $themeCmdPath = dirname(__DIR__, 6) . '/dev/ai-command/ai/主题开发.md';
        if (!is_file($themeCmdPath)) {
            $themeCmdPath = dirname(__DIR__, 5) . '/dev/ai-command/ai/主题开发.md';
        }
        if (!is_file($themeCmdPath)) {
            return false;
        }
        $body = (string) file_get_contents($themeCmdPath);

        return str_contains($body, 'theme_binding')
            && str_contains($body, 'theme:disk:compile')
            && str_contains($body, 'weline-code')
            && str_contains($body, '语义色')
            && str_contains($body, 'theme:scope:migrate')
            && str_contains($body, 'Weline.Api')
            && str_contains($body, 'preview_storefront_delivery_parity')
            && str_contains($body, 'theme:scan-variables')
            && str_contains($body, 'Factory Reset')
            && str_contains($body, '破坏性操作高压线');
    })(),
    'theme_development surface norms include binding and compile matrix' => in_array(
        'theme_binding_scoped_publish',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
        ))),
        true,
    )
        && in_array(
            'compile_matrix_modules_ui_disk',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'frontend_section_weline_code',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'scope_migrate_cli_unimplemented',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        )
        && in_array(
            'editor_dual_preview_parity',
            array_values(array_filter(array_map(
                static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
                is_array($themeDevSurface['norms'] ?? null) ? $themeDevSurface['norms'] : [],
            ))),
            true,
        ),
    'payment_development surface exists' => ($paymentDevSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT
        && str_contains((string) ($paymentDevSurface['authoritative_doc'] ?? ''), 'payment-shell'),
    'ecommerce_advisor surface exists' => ($ecommerceAdvisorSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR
        && str_contains((string) ($ecommerceAdvisorSurface['authoritative_doc'] ?? ''), '电商顾问')
        && str_contains((string) ($ecommerceAdvisorSurface['label'] ?? ''), '运营策划'),
    'ecommerce_advisor has domain_decide_wake_pm norm' => in_array(
        'advisor_domain_decide_wake_pm',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($ecommerceAdvisorSurface['norms'] ?? null) ? $ecommerceAdvisorSurface['norms'] : [],
        ))),
        true,
    ),
    'visitor_data_analytics surface exists' => ($visitorAnalyticsSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS
        && str_contains((string) ($visitorAnalyticsSurface['authoritative_doc'] ?? ''), '像素拓展使用指南'),
    'visitor_data_analytics requires frontend skill refs' => in_array(
        'frontend_skill_refs_required',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($visitorAnalyticsSurface['norms'] ?? null) ? $visitorAnalyticsSurface['norms'] : [],
        ))),
        true,
    ),
    'visitor_data_analytics requires gtm_ga4_mutex norm' => in_array(
        'gtm_ga4_mutex_dual_channel',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($visitorAnalyticsSurface['norms'] ?? null) ? $visitorAnalyticsSurface['norms'] : [],
        ))),
        true,
    ),
    'visitor_data_analytics requires event_dictionary_and_chain norm' => in_array(
        'event_dictionary_and_chain',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($visitorAnalyticsSurface['norms'] ?? null) ? $visitorAnalyticsSurface['norms'] : [],
        ))),
        true,
    ),
    'resolveActiveSurfaceIds matches widget development task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_WIDGET_DEVELOPMENT,
        $activeWidgetIds,
        true,
    ),
    'resolveActiveSurfaceIds matches theme development task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_THEME_DEVELOPMENT,
        $activeThemeIds,
        true,
    ),
    'resolveActiveSurfaceIds matches payment development task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_PAYMENT_DEVELOPMENT,
        $activePaymentIds,
        true,
    ),
    'resolveActiveSurfaceIds matches ecommerce advisor task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_ECOMMERCE_ADVISOR,
        $activeEcommerceAdvisorIds,
        true,
    ),
    'resolveActiveSurfaceIds matches performance check task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_PERFORMANCE_CHECK,
        $activePerformanceCheckIds,
        true,
    ),
    'performance_check norms include theme_seat_integrity_over_peer_requests' => in_array(
        'theme_seat_integrity_over_peer_requests',
        array_values(array_filter(array_map(
            static fn (mixed $norm): string => is_array($norm) ? (string) ($norm['id'] ?? '') : '',
            is_array($performanceCheckSurface['norms'] ?? null) ? $performanceCheckSurface['norms'] : [],
        ))),
        true,
    ),
    'resolveActiveSurfaceIds matches prompt optimization task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION,
        $activePromptOptimizationIds,
        true,
    ),
    'prompt_optimization surface exists' => ($promptOptimizationSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_PROMPT_OPTIMIZATION
        && str_contains((string) ($promptOptimizationSurface['authoritative_doc'] ?? ''), '提示词优化'),
    'resolveActiveSurfaceIds matches translation engineer task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER,
        $activeTranslationEngineerIds,
        true,
    ),
    'translation_engineer surface exists' => ($translationEngineerSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_TRANSLATION_ENGINEER
        && str_contains((string) ($translationEngineerSurface['authoritative_doc'] ?? ''), '翻译工程师'),
    'resolveActiveSurfaceIds matches visitor analytics task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_VISITOR_DATA_ANALYTICS,
        $activeVisitorAnalyticsIds,
        true,
    ),
    'engineering_team_bundle includes 部件开发工程师 seat' => in_array(
        '部件开发工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['部件开发工程师']),
    'engineering_team_bundle includes 主题开发工程师 seat' => in_array(
        '主题开发工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['主题开发工程师'])
        && !isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['主题'])
        && !in_array('主题', is_array($engineeringTeamBundle['core_roster'] ?? null) ? $engineeringTeamBundle['core_roster'] : [], true),
    'engineering_team_bundle includes 支付开发工程师 seat' => in_array(
        '支付开发工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['支付开发工程师']),
    'engineering_team_bundle includes 数据分析 seat' => in_array(
        '数据分析',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['数据分析']),
    'engineering_team_bundle includes 电商顾问 seat' => in_array(
        '电商顾问',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['电商顾问'])
        && !isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['合规']),
    'engineering_team_bundle includes 性能检查工程师 seat' => in_array(
        '性能检查工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['性能检查工程师']),
    'engineering_team_bundle includes 提示词优化工程师 seat' => in_array(
        '提示词优化工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['提示词优化工程师']),
    'engineering_team_bundle includes 翻译工程师 seat' => in_array(
        '翻译工程师',
        is_array($engineeringTeamBundle['framework_seats'] ?? null)
            ? $engineeringTeamBundle['framework_seats']
            : [],
        true,
    )
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['翻译工程师'])
        && isset($engineeringTeamBundle['seat_skill_mirrors']['seats']['i18n']),
    'hard_constraints include payment_engineer_for_payment_work' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'payment_engineer_for_payment_work'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:支付开发工程师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'payment_development')),
        false,
    ),
    'hard_constraints include theme_engineer_for_theme_work' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'theme_engineer_for_theme_work'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:主题开发工程师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'theme_development')
            && str_contains((string) ($rule['summary'] ?? ''), 'work_mode')),
        false,
    ),
    'hard_constraints include required_default_always_present_without_user_deleted' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'required_default_always_present_without_user_deleted'
            && str_contains((string) ($rule['summary'] ?? ''), 'user_deleted@{versionId}')
            && str_contains((string) ($rule['summary'] ?? ''), 'default_injections')
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST memorize')),
        false,
    ),
    'hard_constraints include theme_seat_integrity_over_peer_requests' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'theme_seat_integrity_over_peer_requests'
            && str_contains((string) ($rule['summary'] ?? ''), '主题开发工程师')
            && (str_contains((string) ($rule['summary'] ?? ''), 'OUTRANKS')
                || str_contains((string) ($rule['summary'] ?? ''), 'bottom line'))
            && str_contains((string) ($rule['summary'] ?? ''), 'escalate')
            && str_contains((string) ($rule['summary'] ?? ''), 'header')
            && (str_contains((string) ($rule['summary'] ?? ''), 'reject')
                || str_contains((string) ($rule['summary'] ?? ''), 'veto')
                || str_contains((string) ($rule['summary'] ?? ''), '驳回'))),
        false,
    ),
    'hard_constraints include theme_design_must_not_override_core_runtime_assets' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'theme_design_must_not_override_core_runtime_assets'
            && str_contains((string) ($rule['summary'] ?? ''), 'theme.css')
            && str_contains((string) ($rule['summary'] ?? ''), 'theme.js')),
        false,
    ),
    'hard_constraints include analytics_engineer_for_visitor_work' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'analytics_engineer_for_visitor_work'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:数据分析:')
            && str_contains((string) ($rule['summary'] ?? ''), 'visitor_data_analytics')
            && str_contains((string) ($rule['summary'] ?? ''), 'frontend_development')
            && str_contains((string) ($rule['summary'] ?? ''), 'taglib_ui_control')),
        false,
    ),
    'hard_constraints include ecommerce_advisor_for_commerce' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'ecommerce_advisor_for_commerce'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:电商顾问:')
            && str_contains((string) ($rule['summary'] ?? ''), 'supported countries')
            && (str_contains((string) ($rule['summary'] ?? ''), 'OPS PLANNER')
                || str_contains((string) ($rule['summary'] ?? ''), '要开发什么')
                || str_contains((string) ($rule['summary'] ?? ''), '运营策划'))),
        false,
    ),
    'hard_constraints include performance_engineer_for_design_and_review' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'performance_engineer_for_design_and_review'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:性能检查工程师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'performance_check')
            && str_contains((string) ($rule['summary'] ?? ''), 'theme_seat_integrity_over_peer_requests')),
        false,
    ),
    'hard_constraints include prompt_engineer_for_skill_prompt_work' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'prompt_engineer_for_skill_prompt_work'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:提示词优化工程师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'prompt_optimization')),
        false,
    ),
    'hard_constraints include translation_engineer_for_i18n_work' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'translation_engineer_for_i18n_work'
            && str_contains((string) ($rule['summary'] ?? ''), 'Team:翻译工程师:')
            && str_contains((string) ($rule['summary'] ?? ''), 'translation_engineer')
            && !str_contains((string) ($rule['summary'] ?? ''), 'default skip')
            && (str_contains((string) ($rule['summary'] ?? ''), 'system dictionary')
                || str_contains((string) ($rule['summary'] ?? ''), 'FORMAT BOUNDARY'))),
        false,
    ),
    'translation_engineer surface forbids skip-other-locales wording' => !str_contains((string) ($translationEngineerSurface['description'] ?? ''), '默认先不做')
        && array_reduce(
            is_array($translationEngineerSurface['norms'] ?? null) ? $translationEngineerSurface['norms'] : [],
            static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
                && ($norm['id'] ?? '') === 'i18n_default_website_all_locales_on_copy_change'),
            false,
        ),
    'ecommerce_advisor surface includes FAQ/translator wake norm' => array_reduce(
        is_array($ecommerceAdvisorSurface['norms'] ?? null) ? $ecommerceAdvisorSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'advisor_storefront_compliance_copy_surfaces'
            && str_contains((string) ($norm['summary'] ?? ''), '翻译工程师')),
        false,
    ),
    'hard_constraints include local_dev_test_accounts_self_serve' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'local_dev_test_accounts_self_serve'
            && str_contains((string) ($rule['summary'] ?? ''), 'admin')
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT ask the user')
            && str_contains((string) ($rule['summary'] ?? ''), 'e2e.customer@weline.local')),
        false,
    ),
    'surfaces include engineering_team' => ($teamSurface['id'] ?? '')
        === GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM
        && ($teamSurface['authoritative_doc'] ?? '') === 'dev/ai-command/ai/工程团队.md',
    'pinned includes engineering team command doc' => in_array(
        'dev/ai-command/ai/工程团队.md',
        $pinned,
        true,
    ),
    'resolveActiveSurfaceIds matches engineering team task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_ENGINEERING_TEAM,
        $activeTeamIds,
        true,
    ),
    'engineering team phase sits before implement' => array_reduce(
        is_array($contract['phases'] ?? null) ? $contract['phases'] : [],
        static fn (bool $ok, mixed $phase): bool => $ok || (is_array($phase)
            && ($phase['id'] ?? '') === 'engineering_team'
            && str_contains((string) ($phase['label'] ?? ''), '停工')),
        false,
    ),
    'mcp instructions mention engineering team' => str_contains(ToolService::instructions(), 'engineering_team_for_new_requirements')
        && str_contains(ToolService::instructions(), 'Team:项目经理:')
        && str_contains(ToolService::instructions(), 'one_seat_one_agent')
        && str_contains(ToolService::instructions(), 'peer_talk_via_channel')
        && str_contains(ToolService::instructions(), '监工'),
    'mandatory_before_closeout includes requirement_acceptance_always_satisfied' => in_array(
        'requirement_acceptance_always_satisfied',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mcp instructions mention host Plan Mode' => str_contains(ToolService::instructions(), 'host_plan_mode_for_planning')
        && str_contains(ToolService::instructions(), 'requirement_acceptance_always'),
    'mcp instructions mention host Codex CLI delegation' => str_contains(ToolService::instructions(), 'host_codex_delegation')
        && str_contains(ToolService::instructions(), 'nested codex')
        && str_contains(ToolService::instructions(), 'knowledge.codex.enabled'),
    'mcp instructions mention plan_content_focus_only' => str_contains(ToolService::instructions(), 'plan_content_focus_only')
        && str_contains(ToolService::instructions(), '背景+方案+细节'),
    'surfaces include requirement_clarify_use_case' => ($clarifySurface['id'] ?? '')
        === GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
    'pinned includes clarify use-case command doc' => in_array(
        'dev/ai-command/ai/需求澄清与用例规格.md',
        $pinned,
        true,
    ),
    'resolveActiveSurfaceIds matches clarify use-case task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
        $activeClarifyIds,
        true,
    ),
    'surfaces include webui_browser_closeout' => ($webuiBrowserCloseoutSurface['id'] ?? '')
        === GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT,
    'pinned includes webui browser closeout doc' => in_array(
        'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
        $pinned,
        true,
    ),
    'resolveActiveSurfaceIds matches webui closeout task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT,
        $activeWebuiCloseoutIds,
        true,
    ),
    'mandatory_before_closeout includes browser self-test' => in_array(
        'webui_browser_operator_self_test_pass_or_na',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mandatory_before_closeout includes agent self verify evidence' => in_array(
        'agent_self_verify_with_acceptance_evidence',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mandatory_before_code includes tdd unit acceptance planned' => in_array(
        'tdd_unit_acceptance_planned',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'mandatory_before_closeout includes tdd unit tests executed' => in_array(
        'tdd_unit_tests_executed_and_passed',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mandatory_before_closeout includes browser release after delivery' => in_array(
        'webui_browser_released_after_delivery_or_na',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'closeout reminder requires browser self-test for web' => ($closeoutReminder['browser_self_test_required_for_web'] ?? false) === true
        && (($closeoutReminder['browser_tooling'] ?? '') === 'host_available_real_browser')
        && ($closeoutReminder['agent_self_verify_required'] ?? false) === true
        && ($closeoutReminder['agent_self_verify_rule'] ?? '') === 'agent_self_verify_before_done'
        && ($closeoutReminder['prefer_tdd'] ?? false) === true
        && str_contains((string) ($closeoutReminder['summary_zh'] ?? ''), '真实 Browser'),
    'closeout reminder requires browser release after delivery' => ($closeoutReminder['browser_release_after_delivery_required'] ?? false) === true
        && is_array($closeoutReminder['browser_release_order'] ?? null)
        && in_array('write_delivery_urls_section', $closeoutReminder['browser_release_order'], true)
        && in_array('close_acceptance_browser_tabs', $closeoutReminder['browser_release_order'], true)
        && str_contains((string) ($closeoutReminder['summary_zh'] ?? ''), '关闭'),
    'closeout reminder requires browser cache disabled on open' => ($closeoutReminder['browser_cache_disabled_on_open_required'] ?? false) === true
        && ($closeoutReminder['browser_operator_non_preemptive_required'] ?? false) === true
        && is_array($closeoutReminder['browser_open_order'] ?? null)
        && in_array('prefer_background_non_preemptive_navigate', $closeoutReminder['browser_open_order'], true)
        && in_array('disable_http_cache_for_session', $closeoutReminder['browser_open_order'], true)
        && in_array('strip_automation_detection_flags', $closeoutReminder['browser_open_order'], true)
        && in_array('navigate_or_reload_ignore_cache', $closeoutReminder['browser_open_order'], true)
        && str_contains((string) ($closeoutReminder['summary_zh'] ?? ''), '缓存'),
    'webui surface requires browser_cache_disabled_on_open norm' => array_reduce(
        is_array($webuiBrowserCloseoutSurface['norms'] ?? null) ? $webuiBrowserCloseoutSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'browser_cache_disabled_on_open'),
        false,
    ),
    'webui surface requires browser_operator_non_preemptive norm' => array_reduce(
        is_array($webuiBrowserCloseoutSurface['norms'] ?? null) ? $webuiBrowserCloseoutSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'browser_operator_non_preemptive'),
        false,
    ),
    'webui surface requires browser_release_after_delivery norm' => array_reduce(
        is_array($webuiBrowserCloseoutSurface['norms'] ?? null) ? $webuiBrowserCloseoutSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'browser_release_after_delivery'),
        false,
    ),
    'webui surface requires e2e_playwright_formal_runner_only norm' => array_reduce(
        is_array($webuiBrowserCloseoutSurface['norms'] ?? null) ? $webuiBrowserCloseoutSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'e2e_playwright_formal_runner_only'),
        false,
    ),
    'hard_rules require browser operator self-test' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'host') && str_contains($rule, 'Browser')),
        false,
    ),
    'chapter webui browser is host-agnostic' => (($chapterDelivery['webui_defaults']['browser'] ?? '') === 'host_available_real_browser'),
    'template_i18n forbids unquoted @lang commas' => array_reduce(
        is_array($i18nSurface['norms'] ?? null) ? $i18nSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'at_lang_no_unquoted_comma'),
        false,
    ),
    'frontend norms include weline_ui_theme_first' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'weline_ui_theme_first'),
        false,
    ),
    'frontend norms include ui_skill_requires_theme_skill' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'ui_skill_requires_theme_skill'
            && str_contains((string) ($norm['summary'] ?? ''), 'weline-theme-development')
            && (($norm['authoritative_skill'] ?? '') === 'weline-theme-development')),
        false,
    ),
    'frontend norms include backend_admin_ui_requires_frontend_theme_skills' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'backend_admin_ui_requires_frontend_theme_skills'
            && str_contains((string) ($norm['summary'] ?? ''), 'w-backend-page')
            && (($norm['authoritative_skill'] ?? '') === 'weline-theme-development')),
        false,
    ),
    'frontend norms include css_or_theme_requires_ui_prototype_theme_skills' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'css_or_theme_requires_ui_prototype_theme_skills'
            && is_array($norm['required_companion_skills'] ?? null)
            && in_array('frontend-design', $norm['required_companion_skills'], true)
            && in_array('prototype', $norm['required_companion_skills'], true)
            && in_array('weline-theme-development', $norm['required_companion_skills'], true)),
        false,
    ),
    'frontend norms include browser_api_binquery_default' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'browser_api_binquery_default'
            && str_contains((string) ($norm['summary'] ?? ''), 'BinQuery')
            && str_contains((string) ($norm['summary'] ?? ''), '回退')),
        false,
    ),
    'frontend description mandates BinQuery only' => str_contains((string) ($frontend['description'] ?? ''), 'BinQuery')
        && str_contains((string) ($frontend['description'] ?? ''), '原生')
        && str_contains((string) ($frontend['description'] ?? ''), '回退'),
    'frontend authoritative_docs include Weline.Api and BinQuery' => is_array($frontend['authoritative_docs'] ?? null)
        && in_array('app/code/Weline/Frontend/doc/Weline.Api使用指南.md', $frontend['authoritative_docs'], true)
        && in_array('app/code/Weline/Framework/doc/BinQuery/README.md', $frontend['authoritative_docs'], true),
    'hard_constraints include weline_api_not_raw_fetch as BinQuery-only' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'weline_api_not_raw_fetch'
            && str_contains((string) ($rule['summary'] ?? ''), 'BinQuery')
            && str_contains((string) ($rule['summary'] ?? ''), 'fallback')
            && str_contains((string) ($rule['summary'] ?? ''), 'fetch')),
        false,
    ),
    'frontend surface requires companion skills trio' => is_array($frontend['required_companion_skills'] ?? null)
        && in_array('frontend-design', $frontend['required_companion_skills'], true)
        && in_array('prototype', $frontend['required_companion_skills'], true)
        && in_array('weline-theme-development', $frontend['required_companion_skills'], true),
    'frontend surface requires i18n companion skills' => is_array($frontend['required_companion_skills'] ?? null)
        && in_array('template_i18n', $frontend['required_companion_skills'], true)
        && in_array('module_i18n_csv', $frontend['required_companion_skills'], true),
    'frontend description mandates chinese default + zh/en csv' => is_string($frontend['description'] ?? null)
        && str_contains((string) $frontend['description'], '开发语言')
        && str_contains((string) $frontend['description'], '简体中文')
        && str_contains((string) $frontend['description'], 'zh_Hans_CN.csv')
        && str_contains((string) $frontend['description'], 'en_US.csv')
        && str_contains((string) $frontend['description'], '不是 CSS'),
    'frontend norms include frontend_dev_language_chinese_default' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'frontend_dev_language_chinese_default'
            && str_contains((string) ($norm['summary'] ?? ''), '简体中文')),
        false,
    ),
    'frontend norms include frontend_ui_requires_zh_en_csv' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'frontend_ui_requires_zh_en_csv'
            && str_contains((string) ($norm['summary'] ?? ''), 'zh_Hans_CN.csv')
            && str_contains((string) ($norm['summary'] ?? ''), 'en_US.csv')),
        false,
    ),
    'frontend authoritative_docs include 模块翻译CSV规范' => in_array(
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        is_array($frontend['authoritative_docs'] ?? null) ? $frontend['authoritative_docs'] : [],
        true,
    ),
    'frontend template_surface_rules require chinese + csv' => is_array($frontend['template_surface_rules']['required'] ?? null)
        && array_reduce(
            $frontend['template_surface_rules']['required'],
            static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule)
                && str_contains($rule, 'Simplified Chinese')
                && str_contains($rule, 'zh_Hans_CN.csv')),
            false,
        ),
    'frontend template_surface_rules forbid english source' => is_array($frontend['template_surface_rules']['forbidden'] ?? null)
        && array_reduce(
            $frontend['template_surface_rules']['forbidden'],
            static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule)
                && str_contains($rule, 'English')
                && str_contains($rule, 'Simplified Chinese')),
            false,
        ),
    'frontend triggers include css' => in_array('css', $frontend['triggers'] ?? [], true)
        || in_array('CSS', $frontend['triggers'] ?? [], true),
    'frontend surface authoritative_skill is weline-theme-development' => (($frontend['authoritative_skill'] ?? '') === 'weline-theme-development'),
    'forTask frontend surface keeps authoritative_skill' => ((GuidanceWorkflowCatalog::forTask('frontend-design 主题 UI 颜色间距')['surfaces']['frontend_development']['authoritative_skill'] ?? '') === 'weline-theme-development'),
    'frontend norms include theme_address_for_region_pickers' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'theme_address_for_region_pickers'),
        false,
    ),
    'frontend norms include weline_ui_floating_primitives' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'weline_ui_floating_primitives'),
        false,
    ),
    'weline_ui_floating_primitives covers dialog and pickers' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static function (bool $ok, mixed $rule): bool {
            if (!is_array($rule) || ($rule['id'] ?? '') !== 'weline_ui_floating_primitives') {
                return $ok;
            }
            $summary = (string)($rule['summary'] ?? '');

            return $ok
                || (str_contains($summary, 'dialog')
                    && str_contains($summary, 'picker')
                    && str_contains($summary, 'Weline.UI.dialog'));
        },
        false,
    ),
    'hard_rules require Weline UI theme' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'Weline UI 2.0') && str_contains($rule, 'theme CSS variable')),
        false,
    ),
    'session_startup_notices present' => is_array($contract['session_startup_notices'] ?? null)
        && count($contract['session_startup_notices']) >= 2
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'hard_constraints')),
            false,
        )
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'feature_delivery_urls')),
            false,
        ),
    'mandatory_before_closeout includes docs responsive and delivery urls' => is_array($contract['mandatory_before_closeout'] ?? null)
        && in_array('module_docs_reconciled_with_behavior', $contract['mandatory_before_closeout'], true)
        && in_array('responsive_breakpoints_considered_for_web_ui', $contract['mandatory_before_closeout'], true)
        && in_array('feature_delivery_urls_provided', $contract['mandatory_before_closeout'], true),
    'feature_delivery_urls contract present' => ($featureDeliveryUrls['schema'] ?? '') === 'feature-delivery-urls.v1'
        && ($featureDeliveryUrls['required_on_feature_closeout'] ?? false) === true
        && is_array($featureDeliveryUrls['rules'] ?? null)
        && count($featureDeliveryUrls['rules']) >= 3,
    'feature_delivery_urls defines clickable link format' => is_array($featureDeliveryUrls['link_format'] ?? null)
        && str_contains((string)($featureDeliveryUrls['link_format']['primary_acceptance'] ?? ''), '{url}')
        && !str_contains((string)($featureDeliveryUrls['link_format']['primary_acceptance'] ?? ''), 'command:simpleBrowser'),
    'feature_delivery_urls forbids styled plain open text' => is_array($featureDeliveryUrls['forbidden_delivery_patterns'] ?? null)
        && array_reduce(
            $featureDeliveryUrls['forbidden_delivery_patterns'],
            static fn (bool $ok, mixed $item): bool => $ok || (is_string($item) && str_contains($item, '打开')),
            false,
        ),
    'feature_delivery_urls defaults to project_hash.test.weline.com' => array_reduce(
        is_array($featureDeliveryUrls['link_format']['url_rules'] ?? null) ? $featureDeliveryUrls['link_format']['url_rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule)
            && str_contains($rule, '{project_hash}.test.weline.com')
            && str_contains($rule, '*.weline.test')),
        false,
    ),
    'feature_delivery_urls forbids primary weline.test host' => array_reduce(
        $featureDeliveryUrls['forbidden_delivery_patterns'] ?? [],
        static fn (bool $ok, mixed $item): bool => $ok || (is_string($item) && str_contains($item, '*.weline.test')),
        false,
    ),
    'feature_delivery_urls exposes default_local_host field' => ($featureDeliveryUrls['default_local_host'] ?? '') === '{project_hash}.test.weline.com'
        && is_array($featureDeliveryUrls['forbidden_primary_hosts'] ?? null)
        && in_array('*.weline.test', $featureDeliveryUrls['forbidden_primary_hosts'], true),
    'closeout_delivery_reminder mentions default host' => is_string($closeoutReminder['summary_zh'] ?? null)
        && str_contains((string)$closeoutReminder['summary_zh'], '{project_hash}.test.weline.com')
        && str_contains((string)$closeoutReminder['summary_zh'], '*.weline.test'),
    'webui surface requires default host norm' => array_reduce(
        is_array($webuiBrowserCloseoutSurface['norms'] ?? null) ? $webuiBrowserCloseoutSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'delivery_default_host_test_weline_com'),
        false,
    ),
    'closeout_delivery_reminder present' => ($closeoutReminder['schema'] ?? '') === 'closeout-delivery-reminder.v1'
        && ($closeoutReminder['required_in_every_feature_report'] ?? false) === true
        && is_string($closeoutReminder['summary_zh'] ?? null)
        && str_contains((string)$closeoutReminder['summary_zh'], '交付地址'),
    'session_startup_notices mandate prepare for engineering' => array_reduce(
        $contract['session_startup_notices'] ?? [],
        static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice)
            && str_contains($notice, 'prepare_project')
            && (str_contains($notice, '必须') || str_contains($notice, 'MUST'))),
        false,
    ),
    'bootstrap phase is mandatory engineering gate' => array_reduce(
        is_array($contract['phases'] ?? null) ? $contract['phases'] : [],
        static fn (bool $ok, mixed $phase): bool => $ok || (is_array($phase)
            && ($phase['id'] ?? '') === 'bootstrap'
            && str_contains((string) ($phase['label'] ?? ''), '工程必做')),
        false,
    ),
    'mcp_call_scope mandates prepare_project for engineering' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'mcp_call_scope'
            && str_contains((string) ($rule['summary'] ?? ''), 'MANDATORY')
            && str_contains((string) ($rule['summary'] ?? ''), 'prepare_project')
            && str_contains((string) ($rule['summary'] ?? ''), 'hard_constraints')),
        false,
    ),
    'content_ops_skills_skip_mcp forbids prepare on product/blog ops' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'content_ops_skills_skip_mcp'
            && str_contains((string) ($rule['summary'] ?? ''), '产品优化')
            && str_contains((string) ($rule['summary'] ?? ''), '新建文章')
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT call prepare_project')),
        false,
    ),
    'host_editor_rules mention coldstart generator' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'host_editor_rules_mcp_generated_only'
            && str_contains((string) ($rule['summary'] ?? ''), 'weline-mcp-coldstart.mdc')
            && str_contains((string) ($rule['summary'] ?? ''), 'HostEditorRulesGenerator')),
        false,
    ),
    'session_startup_notices require delivery section in reports' => array_reduce(
        $contract['session_startup_notices'] ?? [],
        static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'feature_delivery_urls')),
        false,
    ),
    'hard_constraints omit retired mcp_capacity_native_fallback' => !array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'mcp_capacity_native_fallback'),
        false,
    ),
    'MCP hard constraints preserve dirty workspace changes' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'preserve_dirty_workspace'
            && str_contains((string) ($rule['summary'] ?? ''), 'staged')
            && str_contains((string) ($rule['summary'] ?? ''), 'untracked')
            && str_contains((string) ($rule['summary'] ?? ''), 'Agent Shell')
            && str_contains((string) ($rule['summary'] ?? ''), 'git checkout')
            && str_contains((string) ($rule['summary'] ?? ''), 'dirty-load')
            && str_contains((string) ($rule['summary'] ?? ''), 'other-session')
            && str_contains((string) ($rule['summary'] ?? ''), 'cross-session overwrite')),
        false,
    ),
    'MCP hard constraints require runtime status query local-first' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'runtime_status_query_local_first'
            && str_contains((string) ($rule['summary'] ?? ''), 'LOCAL')
            && str_contains((string) ($rule['summary'] ?? ''), 'production')
            && str_contains((string) ($rule['summary'] ?? ''), 'default profile')
            && str_contains((string) ($rule['summary'] ?? ''), 'translation')),
        false,
    ),
    'mcp instructions require local-first status queries' => str_contains(ToolService::instructions(), 'runtime_status_query_local_first')
        || str_contains(ToolService::instructions(), 'LOCAL-FIRST'),
    'mcp instructions ban wiping dirty workspace with git' => str_contains(ToolService::instructions(), 'preserve_dirty_workspace')
        && str_contains(ToolService::instructions(), 'never git checkout')
        && str_contains(ToolService::instructions(), 'dirty-load')
        && str_contains(ToolService::instructions(), 'other-session'),
    'session_startup_notices mention dirty-load preserve' => array_reduce(
        $contract['session_startup_notices'] ?? [],
        static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice)
            && str_contains($notice, 'preserve_dirty_workspace')
            && str_contains($notice, 'dirty-load')),
        false,
    ),
    'MCP hard constraints require host editor rules mcp-generated only' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'host_editor_rules_mcp_generated_only'
            && str_contains((string) ($rule['summary'] ?? ''), 'MANDATORY')
            && str_contains((string) ($rule['summary'] ?? ''), 'MUST NOT be hand-authored')
            && str_contains((string) ($rule['summary'] ?? ''), 'generated only by MCP')
            && str_contains((string) ($rule['summary'] ?? ''), '.cursor/rules')),
        false,
    ),
    'MCP hard constraints require skills fetch from MCP' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'mcp_skills_fetch_from_mcp'
            && str_contains((string) ($rule['summary'] ?? ''), 'resolve_skill')
            && str_contains((string) ($rule['summary'] ?? ''), 'get_skill')),
        false,
    ),
    'MCP hard constraints require greeting lists skills and commands' => array_reduce(
        is_array($hardConstraintsPackage['mcp_operational'] ?? null) ? $hardConstraintsPackage['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'greeting_lists_mcp_skills_and_commands'
            && str_contains((string) ($rule['summary'] ?? ''), 'hi')),
        false,
    ),
    'session_startup_notices mention mcp_skills' => array_reduce(
        $contract['session_startup_notices'] ?? [],
        static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'mcp_skills')),
        false,
    ),
    'mcp instructions include hard-constraints preamble' => str_contains(ToolService::instructions(), 'hard-constraints.v1')
        && str_contains(ToolService::instructions(), 'prepare_project')
        && !str_contains(substr(ToolService::instructions(), 0, 400), 'w-field'),
    'pinned includes Theme开发总指南' => in_array(
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        $pinned,
        true,
    ),
    'pinned includes AI hard rules index' => in_array(
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
        $pinned,
        true,
    ),
    'pinned includes Taglib scenario mapping' => in_array(
        'app/code/Weline/Taglib/doc/场景映射表.md',
        $pinned,
        true,
    ),
    'surfaces include taglib_ui_control' => ($taglibSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL,
    'surfaces include hook_extension' => ($hookSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION,
    'surfaces include event_extension' => ($eventSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_EVENT_EXTENSION,
    'surfaces include template_i18n' => ($i18nSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_TEMPLATE_I18N,
    'surfaces include module_upgrade_gate' => ($moduleUpgradeSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE,
    'surfaces include module_i18n_csv' => ($moduleI18nCsvSurface['id'] ?? '') === GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV,
    'pinned includes module i18n csv doc' => in_array(
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        $pinned,
        true,
    ),
    'resolveActiveSurfaceIds matches i18n csv task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_MODULE_I18N_CSV,
        $activeI18nCsvIds,
        true,
    ),
    'hard_rules require i18n collect' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'i18n:collect')),
        false,
    ),
    'mandatory_before_closeout includes i18n csv collect' => in_array(
        'module_i18n_csv_collected_when_strings_changed',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'mandatory_before_closeout includes default website locales translation' => in_array(
        'default_website_locales_translated_when_user_asks_translation',
        is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [],
        true,
    ),
    'hard_constraints include user_mentions_translation_all_default_website_locales' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'user_mentions_translation_all_default_website_locales'
            && str_contains((string) ($rule['summary'] ?? ''), 'Website::ID_DEFAULT')
            && str_contains((string) ($rule['summary'] ?? ''), 'never stop at en_US')
            && str_contains((string) ($rule['summary'] ?? ''), 'system dictionary')
            && str_contains((string) ($rule['summary'] ?? ''), 'ONLY store zh_Hans_CN.csv and en_US.csv')),
        false,
    ),
    'hard_constraints include module_i18n_chinese_source_default' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'module_i18n_chinese_source_default'
            && str_contains((string) ($rule['summary'] ?? ''), 'Simplified Chinese')
            && str_contains((string) ($rule['summary'] ?? ''), 'FORBIDDEN')
            && str_contains((string) ($rule['summary'] ?? ''), 'English')
            && str_contains((string) ($rule['summary'] ?? ''), 'CORRECT')),
        false,
    ),
    'hard_constraints include active_locale_must_show_target_language' => array_reduce(
        is_array($hardConstraintsPackage['rules'] ?? null) ? $hardConstraintsPackage['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'active_locale_must_show_target_language'
            && str_contains((string) ($rule['summary'] ?? ''), 'ACTIVE')
            && str_contains((string) ($rule['summary'] ?? ''), 'DEFAULT')
            && str_contains((string) ($rule['summary'] ?? ''), 'FORBIDDEN')
            && str_contains((string) ($rule['summary'] ?? ''), 'Chinese source')),
        false,
    ),
    'mcp instructions mention chinese source default' => str_contains(
        ToolService::instructions(),
        'module_i18n_chinese_source_default',
    ),
    'mcp instructions mention active locale target language' => str_contains(
        ToolService::instructions(),
        'active_locale_must_show_target_language',
    ),
    'mcp instructions mention default-website translation' => str_contains(
        ToolService::instructions(),
        'user_mentions_translation_all_default_website_locales',
    ),
    'module_i18n_csv surface requires chinese source default' => array_reduce(
        is_array($moduleI18nCsvSurface['norms'] ?? null) ? $moduleI18nCsvSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'chinese_source_default'),
        false,
    ),
    'module_i18n_csv surface requires active locale target language' => array_reduce(
        is_array($moduleI18nCsvSurface['norms'] ?? null) ? $moduleI18nCsvSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'active_locale_must_show_target_language'),
        false,
    ),
    'module_i18n_csv surface requires default website locales' => array_reduce(
        is_array($moduleI18nCsvSurface['norms'] ?? null) ? $moduleI18nCsvSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'user_mentions_translation_all_default_website_locales'),
        false,
    ),
    'pinned includes module upgrade gate doc' => in_array(
        'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
        $pinned,
        true,
    ),
    'resolveActiveSurfaceIds matches module upgrade task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_MODULE_UPGRADE,
        $activeModuleUpgradeIds,
        true,
    ),
    'hard_rules require module version bump' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'etc/module.php')),
        false,
    ),
    'module_upgrade_gate forbids change set without bump' => in_array(
        'Changing Model/Controller/event/hook/register without bumping etc/module.php version in the same change set',
        is_array($moduleUpgradeSurface['template_surface_rules']['forbidden'] ?? null)
            ? $moduleUpgradeSurface['template_surface_rules']['forbidden']
            : [],
        true,
    ),
    'module_upgrade_gate module_version_bump_gate norm present' => array_reduce(
        is_array($moduleUpgradeSurface['norms'] ?? null) ? $moduleUpgradeSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'module_version_bump_gate'),
        false,
    ),
    'taglib surface has authoritative_docs' => is_array($taglibSurface['authoritative_docs'] ?? null)
        && count($taglibSurface['authoritative_docs']) >= 2,
    'resolveActiveSurfaceIds matches hook task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_HOOK_EXTENSION,
        $activeHookIds,
        true,
    ),
    'resolveActiveSurfaceIds matches taglib task' => in_array(
        GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL,
        $activeTaglibIds,
        true,
    ),
    'authoritative_hard_rules_index present' => ($contract['authoritative_hard_rules_index'] ?? '')
        === 'app/code/Weline/Ai/doc/AI硬规则索引.md',
    'hard_rules require Taglib scenario mapping' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'Taglib')),
        false,
    ),
    'hard_rules forbid taglib callback literal @static' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'callback') && str_contains($rule, '@static')),
        false,
    ),
    'taglib surface forbids callback literal @static' => in_array(
        'Literal @static(...) inside Taglib callback()/runtime_callback() HTML return strings',
        is_array($taglibSurface['template_surface_rules']['forbidden'] ?? null)
            ? $taglibSurface['template_surface_rules']['forbidden']
            : [],
        true,
    ),
    'hard_rules require Hook triple' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'hook.php')),
        false,
    ),
    'hard_rules require hook type partials or layouts' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'partials') && str_contains($rule, 'layouts')),
        false,
    ),
    'hook surface forbids invented type segment' => in_array(
        'Inventing type segment (theme-editor, checkout, product, account, …) — type is ONLY partials or layouts',
        is_array($hookSurface['template_surface_rules']['forbidden'] ?? null)
            ? $hookSurface['template_surface_rules']['forbidden']
            : [],
        true,
    ),
    'hard_rules forbid phtml __()' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, '__()')),
        false,
    ),
    'extension matrix ui_control points to scenario mapping' => array_reduce(
        is_array($contract['extension_point_matrix'] ?? null) ? $contract['extension_point_matrix'] : [],
        static fn (bool $ok, mixed $row): bool => $ok || (is_array($row)
            && ($row['intent'] ?? '') === 'ui_control'
            && str_contains((string) ($row['index'] ?? ''), '场景映射表')),
        false,
    ),
    'pinned does not elevate attribute-named specialty as primary skill' => !in_array(
        'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
        $pinned,
        true,
    ),
    'hard_rules point to frontend_development surface' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'frontend_development')),
        false,
    ),
    'hard_rules require doc reconcile' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'reconcile')),
        false,
    ),
    'hard_rules require plan todo evidence closeout' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'per-todo evidence')),
        false,
    ),
    'hard_rules require tablet/PC responsive' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, '768')),
        false,
    ),
    'section identity is one norm among many' => $hasSectionIdentityNorm && count($norms) >= 5,
    'theme_layout_widget_owner norm exists' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'theme_layout_widget_owner'),
        false,
    ),
    'frontend_development norms include required_default_always_present' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm)
            && ($norm['id'] ?? '') === 'required_default_always_present_without_user_deleted'
            && str_contains((string) ($norm['summary'] ?? ''), 'user_deleted@{versionId}')),
        false,
    ),
    'hard_rules require preview storefront delivery parity' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule)
            && str_contains($rule, 'preview_storefront_delivery_parity')
            && str_contains($rule, 'FORBIDDEN')
            && str_contains($rule, 'SAME business logic')
            && str_contains($rule, 'early-return')),
        false,
    ),
    'hard_rules require theme layout widget owner' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule)
            && str_contains($rule, 'check-theme-layout-widgets')
            && str_contains($rule, 'SAME module')
            && str_contains($rule, 'CROSS module')
            && str_contains($rule, 'default_injections')),
        false,
    ),
    'forbidden rules catch non-Theme layout widgets' => array_reduce(
        $forbidden,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'Non-Weline_Theme')),
        false,
    ),
    'responsive and docs norms exist' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'responsive_tablet_pc'),
        false,
    ) && array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'docs_reconcile_per_feature'),
        false,
    ) && array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'feature_delivery_urls'),
        false,
    ),
    'required rules mention section identity' => array_reduce(
        $required,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'section identity')),
        false,
    ),
    'forbidden rules still catch missing section identity' => array_reduce(
        $forbidden,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'section identity')),
        false,
    ),
    'authoritative_doc is Theme开发总指南' => ($frontend['authoritative_doc'] ?? '')
        === 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
    'frontend verification includes welineModules collect' => in_array(
        'php bin/w resource:compile welineModules',
        is_array($frontend['verification_commands'] ?? null) ? $frontend['verification_commands'] : [],
        true,
    ),
    'theme_js_module_declare_only requires collect verify' => array_reduce(
        $norms,
        static fn (bool $ok, mixed $norm): bool => $ok || (
            is_array($norm)
            && ($norm['id'] ?? '') === 'theme_js_module_declare_only'
            && ($norm['verify'] ?? '') === 'php bin/w resource:compile welineModules'
        ),
        false,
    ),
    'chapter_delivery schema present' => ($chapterDelivery['schema'] ?? '') === 'chapter-delivery.v1',
    'chapter_delivery has four acceptance segments' => ($chapterDelivery['acceptance_segments'] ?? []) === [
        'unit_test',
        'runtime',
        'webui',
        'dev_log',
    ],
    'chapter_delivery requires visual acceptance closeout' => in_array(
        'visual_acceptance_pass_for_webui_cases_or_na',
        is_array($chapterDelivery['mandatory_before_chapter_closeout'] ?? null)
            ? $chapterDelivery['mandatory_before_chapter_closeout']
            : [],
        true,
    ),
    'chapter_delivery webui_defaults require visual acceptance' => ($webuiDefaults['visual_acceptance_required'] ?? false) === true,
    'chapter_delivery visual_evidence is non-empty' => $visualEvidence !== [],
    'chapter_delivery forbids text-only visual claims' => in_array(
        'text_only_visual_claim_without_screenshot',
        is_array($webuiDefaults['forbidden_substitutes'] ?? null)
            ? $webuiDefaults['forbidden_substitutes']
            : [],
        true,
    ),
    'hard_rules require delivery urls' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && (
            str_contains($rule, '交付地址') || str_contains($rule, 'Delivery URLs')
        )),
        false,
    ),
    'required rules mention delivery urls' => array_reduce(
        $required,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'frontend/backend/API URLs')),
        false,
    ),
    'mandatory_before_code includes webui acceptance agreement' => in_array(
        'webui_acceptance_cases_agreed_for_web_surface',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'template_surface_rules remains compatibility alias' => ($templateRules['surface'] ?? '') === 'frontend_development'
        && ($templateRules['label'] ?? '') === '前端开发规范',
];

$failed = false;
foreach ($checks as $label => $passed) {
    if ($passed) {
        fwrite(STDOUT, "[PASS] {$label}\n");
        continue;
    }
    $failed = true;
    fwrite(STDERR, "[FAIL] {$label}\n");
}

exit($failed ? 1 : 0);
