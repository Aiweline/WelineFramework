<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

$publicId = trim((string)($argv[1] ?? ''));
$pageType = trim((string)($argv[2] ?? 'home_page'));
$sectionCode = trim((string)($argv[3] ?? 'content/home-page-hero'));

if ($publicId === '' || $pageType === '' || $sectionCode === '') {
    fwrite(STDERR, "Usage: php dev/ai/generate_block_preview.php <public_id> <page_type> <section_code>\n");
    exit(2);
}

$om = ObjectManager::getInstance();
/** @var AiSiteAgentSessionService $sessionService */
$sessionService = $om->get(AiSiteAgentSessionService::class);
/** @var AiSitePageComponentGenerationService $generationService */
$generationService = $om->get(AiSitePageComponentGenerationService::class);

$session = $sessionService->loadByPublicId($publicId, 1);
$scope = $sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT);
$websiteProfile = is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST, true);

$specs = $generationService->buildPageSectionSpecs($pageType, $websiteProfile, $scope);
$section = null;
foreach ($specs['sections'] ?? [] as $candidate) {
    if ((string)($candidate['code'] ?? '') === $sectionCode) {
        $section = $candidate;
        break;
    }
}
if (!is_array($section)) {
    throw new InvalidArgumentException('Unknown page section: ' . $sectionCode);
}

$assetSlots = is_array($scope['asset_manifest']['slots'] ?? null) ? $scope['asset_manifest']['slots'] : [];
$cachedImageGenerator = static function (string $slotId) use ($assetSlots): array {
    $slot = is_array($assetSlots[$slotId] ?? null) ? $assetSlots[$slotId] : [];
    $url = trim((string)($slot['final_url'] ?? $slot['url'] ?? ''));
    if ($url === '') {
        throw new RuntimeException('No cached image final_url for slot ' . $slotId . '.');
    }

    return [
        'slot_id' => $slotId,
        'final_url' => $url,
        'url' => $url,
        'cached' => true,
    ];
};

$spec = [
    'componentCode' => (string)$section['code'],
    'name' => (string)$section['name'],
    'region' => (string)$section['region'],
    'prompt' => (string)$section['prompt'],
    'defaultConfig' => is_array($section['default_config'] ?? null) ? $section['default_config'] : [],
    'renderContext' => is_array($section['render_context'] ?? null) ? $section['render_context'] : [],
] + $section;
$spec['renderContext']['_inline_image_asset_generator'] = $cachedImageGenerator;

$prepare = new ReflectionMethod($generationService, 'prepareInlineImageAssetForComponentSpec');
$prepare->setAccessible(true);
$spec = $prepare->invoke($generationService, $spec);

$generate = new ReflectionMethod($generationService, 'generateComponent');
$generate->setAccessible(true);

$startedAt = microtime(true);
$component = $generate->invoke(
    $generationService,
    (string)$spec['componentCode'],
    (string)$spec['name'],
    (string)$spec['region'],
    (string)$spec['prompt'],
    is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [],
    is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : []
);
$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

$result = [
    'key' => (string)($section['key'] ?? ''),
    'code' => (string)$section['code'],
    'name' => (string)$section['name'],
    'region' => (string)$section['region'],
    'sort_order' => (int)($section['sort_order'] ?? 0),
    'phtml' => (string)($component['phtml'] ?? ''),
    'html' => (string)($component['html'] ?? ''),
    'default_config' => is_array($component['default_config'] ?? null) ? $component['default_config'] : [],
    'ai_data' => is_array($component['ai_data'] ?? null) ? $component['ai_data'] : [],
];

$aiData = is_array($result['ai_data'] ?? null) ? $result['ai_data'] : [];
$html = (string)($result['html'] ?? '');
$cssExtra = (string)($aiData['css_extra'] ?? '');
$cssResponsive = (string)($aiData['css_responsive'] ?? '');
$visibleHtml = preg_replace('/<(?:style|script)\b[^>]*>.*?<\/(?:style|script)>/isu', ' ', $html) ?? $html;
$visibleText = trim((string)preg_replace('/\s+/u', ' ', strip_tags($visibleHtml)));
$hasEnglishLeak = preg_match(
    '/Convert visitors into loyal customers|showcasing Aster and Rye|seamless preorder|artisan breads and specialty coffee/iu',
    $visibleText
) === 1;
$hasDefaultFontOnly = preg_match('/font-family\s*:\s*(?:system-ui|-apple-system|BlinkMacSystemFont|Segoe UI|Roboto|Arial|Helvetica|sans-serif|,\s*)+;/iu', $cssExtra) === 1;
$hasFontFamily = preg_match('/font-family\s*:/iu', $cssExtra) === 1;
$hasHeadingFont = false;
$hasBodyFont = false;
if (preg_match_all('/([^{}]+)\{([^}]*)\}/iu', $cssExtra, $cssRules, PREG_SET_ORDER) > 0) {
    foreach ($cssRules as $cssRule) {
        $selector = strtolower(trim((string)($cssRule[1] ?? '')));
        $body = (string)($cssRule[2] ?? '');
        if (preg_match('/font-family\s*:\s*([^;}]+)/iu', $body, $fontMatch) !== 1) {
            continue;
        }
        $families = array_filter(array_map(static function (string $family): string {
            return strtolower(trim($family, " \t\n\r\0\x0B'\""));
        }, explode(',', (string)($fontMatch[1] ?? ''))));
        $defaultFamilies = [
            'system-ui' => true,
            '-apple-system' => true,
            'blinkmacsystemfont' => true,
            'segoe ui' => true,
            'roboto' => true,
            'arial' => true,
            'helvetica' => true,
            'sans-serif' => true,
        ];
        $nonDefault = false;
        foreach ($families as $family) {
            if (!isset($defaultFamilies[$family])) {
                $nonDefault = true;
                break;
            }
        }
        if (!$nonDefault) {
            continue;
        }
        $hasHeadingFont = $hasHeadingFont || preg_match('/(?:-title\b|\.title\b|\bh[1-6]\b)/iu', $selector) === 1;
        $hasBodyFont = $hasBodyFont || preg_match('/(?:-root\b|-copy\b|-text\b|\.root\b|\.copy\b|\.text\b|body\b)/iu', $selector) === 1;
    }
}
$hasResponsive = preg_match('/@media\s*\(\s*max-width\s*:\s*768px\s*\)/iu', $cssResponsive) === 1
    && preg_match('/@media\s*\(\s*max-width\s*:\s*420px\s*\)/iu', $cssResponsive) === 1;
$imgCount = (int)preg_match_all('/<img\b/iu', $html);
$renderContext = is_array($spec['renderContext'] ?? null) ? $spec['renderContext'] : [];
$defaultConfig = is_array($spec['defaultConfig'] ?? null) ? $spec['defaultConfig'] : [];
$buildPlanTask = is_array($renderContext['_build_plan_task'] ?? null) ? $renderContext['_build_plan_task'] : [];
$runtimeContext = is_array($buildPlanTask['runtime_context'] ?? null) ? $buildPlanTask['runtime_context'] : [];
$blockContract = [];
foreach ([
    $runtimeContext['block_contract']['contract_v2'] ?? null,
    $runtimeContext['block_contract'] ?? null,
    $buildPlanTask['block_contract'] ?? null,
    $buildPlanTask['output_contract']['block_contract'] ?? null,
] as $candidate) {
    if (is_array($candidate) && $candidate !== []) {
        $blockContract = $candidate;
        break;
    }
}
$mediaStrategy = is_array($blockContract['media_strategy'] ?? null) ? $blockContract['media_strategy'] : [];
$imageIntent = is_array($blockContract['image_intent'] ?? null)
    ? $blockContract['image_intent']
    : (is_array($buildPlanTask['image_intent'] ?? null) ? $buildPlanTask['image_intent'] : []);
$morphologyId = trim((string)($blockContract['morphology_id'] ?? $buildPlanTask['morphology_id'] ?? ''));
$flowRole = strtolower(trim((string)($blockContract['page_flow_role'] ?? $buildPlanTask['page_flow_role'] ?? '')));
$needsRealImage = !empty($mediaStrategy['needs_real_image']) || !empty($imageIntent['needs_image']);
$requiredImageSlotId = trim((string)($mediaStrategy['asset_slot_id'] ?? $defaultConfig['runtime.section_image_slot_id'] ?? ''));
$requiredImageUsed = $requiredImageSlotId !== ''
    && (
        str_contains($html, $requiredImageSlotId)
        || str_contains(json_encode($result['default_config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', $requiredImageSlotId)
    );
$nonHeroImage = $imgCount > 0 && !in_array($flowRole, ['opening', 'hero'], true);
$signalHaystack = strtolower($html . "\n" . $cssExtra . "\n" . $cssResponsive);
$visualSignals = [];
foreach ([
    'gradient' => 'gradient',
    'shadow' => 'box-shadow',
    'radius' => 'border-radius',
    'grid' => 'grid',
    'media_frame' => '<img',
    'figure' => '<figure',
    'badge' => 'badge',
    'metric' => 'metric',
    'quote' => 'quote',
    'timeline' => 'timeline',
    'card' => 'card',
    'surface' => 'surface',
] as $signal => $needle) {
    if (str_contains($signalHaystack, $needle)) {
        $visualSignals[] = $signal;
    }
}
$hasSkeletonOnly = preg_match('/<h[1-6]\b/i', $html) === 1
    && preg_match('/<p\b/i', $html) === 1
    && preg_match('/<(?:a|button)\b/i', $html) === 1
    && preg_match('/<(?:img|picture|figure|ul|ol|li|blockquote|form|input|textarea|select)\b/i', $html) !== 1
    && preg_match('/(?:metric|stat|timeline|quote|card|grid|media|proof|gallery|map|faq)/i', $html) !== 1;
$skeletonScore = $hasSkeletonOnly ? 1 : 0;
$acceptanceChecks = is_array($blockContract['acceptance_checks'] ?? null) ? $blockContract['acceptance_checks'] : [];
$acceptanceFailures = [];
if ($blockContract === []) {
    $acceptanceFailures[] = 'block_contract_missing';
}
if ($morphologyId === '') {
    $acceptanceFailures[] = 'morphology_id_missing';
}
if ($needsRealImage && ($imgCount === 0 || !$requiredImageUsed)) {
    $acceptanceFailures[] = 'required_image_not_used';
}
if (
    in_array($flowRole, ['proof', 'details', 'support'], true)
    && empty($mediaStrategy['allow_css_only'])
    && $imgCount === 0
) {
    $acceptanceFailures[] = 'non_hero_media_missing';
}
if ($skeletonScore > 0) {
    $acceptanceFailures[] = 'generic_title_paragraph_cta_skeleton';
}

$componentId = 'pb-block-qa-' . substr(sha1($publicId . '|' . $pageType . '|' . $sectionCode . '|' . microtime(true)), 0, 10);
$renderedHtml = str_replace('#componentId', '#' . $componentId, $html);
$renderedCssExtra = str_replace('#componentId', '#' . $componentId, $cssExtra);
$renderedCssResponsive = str_replace('#componentId', '#' . $componentId, $cssResponsive);

$safeTitle = htmlspecialchars((string)($result['name'] ?? $sectionCode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$preview = "<!doctype html>\n"
    . "<html lang=\"ru\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
    . "<title>Block QA - {$safeTitle}</title>"
    . "<style>body{margin:0;background:#f5efe6;color:#23170f;} .qa-shell{padding:32px;} .qa-meta{font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#6d5845;margin-bottom:18px;} {$renderedCssExtra}\n{$renderedCssResponsive}</style>"
    . "</head><body><main class=\"qa-shell\"><div class=\"qa-meta\">"
    . htmlspecialchars($pageType . ' / ' . $sectionCode . ' / ' . $elapsedMs . 'ms', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . "</div><div id=\"{$componentId}\">{$renderedHtml}</div></main></body></html>";

$dir = dirname(__DIR__, 2) . '/pub/media/pagebuilder-block-qa';
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create preview directory: ' . $dir);
}
$fileName = preg_replace('/[^a-z0-9_-]+/i', '-', $pageType . '-' . $sectionCode) . '-' . date('Ymd-His') . '.html';
$filePath = $dir . '/' . $fileName;
file_put_contents($filePath, $preview);

$relativeUrl = '/pub/media/pagebuilder-block-qa/' . $fileName;
$report = [
    'ok' => !$hasEnglishLeak && !$hasDefaultFontOnly && $hasFontFamily && $hasHeadingFont && $hasBodyFont && $hasResponsive && $html !== '' && $acceptanceFailures === [],
    'public_id' => $publicId,
    'page_type' => $pageType,
    'section_code' => $sectionCode,
    'elapsed_ms' => $elapsedMs,
    'preview_path' => $filePath,
    'preview_url' => $relativeUrl,
    'html_length' => strlen($html),
    'css_extra_length' => strlen($cssExtra),
    'css_responsive_length' => strlen($cssResponsive),
    'has_font_family' => $hasFontFamily,
    'has_heading_font' => $hasHeadingFont,
    'has_body_font' => $hasBodyFont,
    'has_default_font_only' => $hasDefaultFontOnly,
    'has_responsive' => $hasResponsive,
    'image_count' => $imgCount,
    'block_contract_found' => $blockContract !== [],
    'morphology_id' => $morphologyId,
    'required_image_slot_id' => $requiredImageSlotId,
    'required_image_used' => $requiredImageUsed,
    'non_hero_image' => $nonHeroImage,
    'skeleton_score' => $skeletonScore,
    'visual_depth_signal_count' => \count($visualSignals),
    'visual_depth_signals' => $visualSignals,
    'acceptance_checks' => $acceptanceChecks,
    'acceptance_checks_passed' => $acceptanceFailures === [],
    'acceptance_failures' => $acceptanceFailures,
    'has_english_brief_leak' => $hasEnglishLeak,
    'text_excerpt' => mb_substr($visibleText, 0, 500),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
