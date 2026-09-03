<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\HardConstraintsCatalog;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$contract = GuidanceWorkflowCatalog::contract();
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
$hardRules = is_array($contract['hard_rules'] ?? null) ? $contract['hard_rules'] : [];
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

$hasSectionIdentityNorm = false;
foreach ($norms as $norm) {
    if (is_array($norm) && ($norm['id'] ?? '') === 'section_identity') {
        $hasSectionIdentityNorm = true;
        break;
    }
}

$checks = [
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
    'hard_constraints package present' => is_array($contract['hard_constraints'] ?? null)
        && ($contract['hard_constraints']['schema'] ?? '') === HardConstraintsCatalog::SCHEMA
        && ($contract['hard_constraints']['must_obey'] ?? false) === true
        && ($contract['hard_constraints']['authoritative_doc'] ?? '') === HardConstraintsCatalog::AUTHORITATIVE_DOC
        && is_array($contract['hard_constraints']['rules'] ?? null)
        && count($contract['hard_constraints']['rules']) >= 10
        && is_string($contract['hard_constraints']['preamble'] ?? null)
        && str_contains((string) $contract['hard_constraints']['preamble'], 'hard-constraints.v1'),
    'hard_constraints include weline_ui_theme_first' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'weline_ui_theme_first'),
        false,
    ),
    'hard_constraints include frontend_unified_content_container' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'frontend_unified_content_container'
            && str_contains((string) ($rule['summary'] ?? ''), 'never invent a private page container')),
        false,
    ),
    'hard_constraints include theme_js_module_declare_only' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'theme_js_module_declare_only'),
        false,
    ),
    'hard_constraints include at_lang_no_unquoted_comma' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'at_lang_no_unquoted_comma'),
        false,
    ),
    'hard_constraints include browser_operator_self_test' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'browser_operator_self_test'),
        false,
    ),
    'hard_constraints feature_delivery_urls requires section' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'feature_delivery_urls'
            && str_contains((string) ($rule['summary'] ?? ''), '交付地址')),
        false,
    ),
    'hard_constraints include task_plan_before_edit' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'task_plan_before_edit'),
        false,
    ),
    'hard_constraints include user_requirement_full_workflow' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule) && ($rule['id'] ?? '') === 'user_requirement_full_workflow'),
        false,
    ),
    'mandatory_before_code includes requirement_analysis_in_task_plan' => in_array(
        'requirement_analysis_in_task_plan',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
        true,
    ),
    'hard_constraints include plan_todo_evidence_closeout' => array_reduce(
        is_array($contract['hard_constraints']['rules'] ?? null) ? $contract['hard_constraints']['rules'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'plan_todo_evidence_closeout'
            && str_contains((string) ($rule['summary'] ?? ''), 'evidence')),
        false,
    ),
    'mandatory_before_code includes submit_task_plan_accepted' => in_array(
        'submit_task_plan_accepted',
        is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [],
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
    'closeout reminder requires browser self-test for web' => ($closeoutReminder['browser_self_test_required_for_web'] ?? false) === true
        && (($closeoutReminder['browser_tooling'] ?? '') === 'host_available_real_browser')
        && str_contains((string) ($closeoutReminder['summary_zh'] ?? ''), '真实 Browser'),
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
    'closeout_delivery_reminder present' => ($closeoutReminder['schema'] ?? '') === 'closeout-delivery-reminder.v1'
        && ($closeoutReminder['required_in_every_feature_report'] ?? false) === true
        && is_string($closeoutReminder['summary_zh'] ?? null)
        && str_contains((string)$closeoutReminder['summary_zh'], '交付地址'),
    'session_startup_notices require delivery section in reports' => array_reduce(
        $contract['session_startup_notices'] ?? [],
        static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'feature_delivery_urls')),
        false,
    ),
    'session_startup_notices allow native fallback on MCP capacity' => array_reduce(
        is_array($contract['hard_constraints']['mcp_operational'] ?? null) ? $contract['hard_constraints']['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'mcp_capacity_native_fallback'
            && str_contains((string) ($rule['summary'] ?? ''), 'MCP_TARGET_UNAVAILABLE')),
        false,
    ),
    'MCP hard constraints preserve dirty workspace changes' => array_reduce(
        is_array($contract['hard_constraints']['mcp_operational'] ?? null) ? $contract['hard_constraints']['mcp_operational'] : [],
        static fn (bool $ok, mixed $rule): bool => $ok || (is_array($rule)
            && ($rule['id'] ?? '') === 'preserve_dirty_workspace'
            && str_contains((string) ($rule['summary'] ?? ''), 'staged')
            && str_contains((string) ($rule['summary'] ?? ''), 'untracked')),
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
    'module_upgrade_gate forbids sealed plan without bump' => in_array(
        'Submitting sealed edit-plan.v1 with Model/Controller/event/hook/register changes but omitting etc/module.php bump',
        is_array($moduleUpgradeSurface['template_surface_rules']['forbidden'] ?? null)
            ? $moduleUpgradeSurface['template_surface_rules']['forbidden']
            : [],
        true,
    ),
    'module_upgrade_gate sealed_edit norm present' => array_reduce(
        is_array($moduleUpgradeSurface['norms'] ?? null) ? $moduleUpgradeSurface['norms'] : [],
        static fn (bool $ok, mixed $norm): bool => $ok || (is_array($norm) && ($norm['id'] ?? '') === 'sealed_edit_module_version_gate'),
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
    'hard_rules require theme layout widget owner' => array_reduce(
        $hardRules,
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'check-theme-layout-widgets')),
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
