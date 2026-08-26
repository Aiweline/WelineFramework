<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;

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
$pinned = GuidanceWorkflowCatalog::pinnedDocumentPaths();

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
    'session_startup_notices present' => is_array($contract['session_startup_notices'] ?? null)
        && count($contract['session_startup_notices']) >= 2
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'doc/')),
            false,
        )
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && str_contains($notice, 'WB-VIS')),
            false,
        )
        && array_reduce(
            $contract['session_startup_notices'],
            static fn (bool $ok, mixed $notice): bool => $ok || (is_string($notice) && (str_contains($notice, '前台') || str_contains($notice, 'frontend page URL'))),
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
    'pinned includes Theme开发总指南' => in_array(
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        $pinned,
        true,
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
        static fn (bool $ok, mixed $rule): bool => $ok || (is_string($rule) && str_contains($rule, 'URL inventory')),
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
