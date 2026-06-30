<?php
declare(strict_types=1);

/**
 * AI 建站验收 v4 — 完全复刻测试模式
 * 1. 模拟方案生成 + 确认
 * 2. fixture 模式走确定性构建；real-plan 模式才强制真 AI 生成组件
 * 3. 门禁检查
 * 用法: php dev/ai/verify_build.php
 */

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;

require __DIR__ . '/../../app/bootstrap.php';

$mode = 'fixture';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string)$arg, '--mode=')) {
        $mode = substr((string)$arg, 7);
    }
}
if (!in_array($mode, ['fixture', 'real-plan', 'content-only'], true)) {
    fwrite(STDERR, "Usage: php dev/ai/verify_build.php [--mode=fixture|real-plan|content-only]\n");
    exit(2);
}
$requireRealPlan = $mode === 'real-plan';
$contentOnly = $mode === 'content-only';
$baseUrl = rtrim((string)(getenv('WELINE_BASE_URL') ?: 'https://127.0.0.1'), '/');
$routePrefix = trim((string)(getenv('WELINE_ROUTE_PREFIX') ?: ''), '/');
$workspacePath = ($routePrefix !== '' ? '/' . $routePrefix : '') . '/pagebuilder/backend/ai-site-agent/workspace';

echo "╔════════════════════════════════════════════════╗\n";
echo "║   AI 建站验收 v4 — 复刻测试流程               ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

$jsonDecode = static fn(mixed $r): array =>
    is_string($r) ? (json_decode($r, true) ?: []) : (is_array($r) ? $r : []);

$buildContentOnlyReport = static function (array $scope): array {
    $buildPlan = is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
    $validation = is_array($scope['build_plan_v2_validation'] ?? null) ? $scope['build_plan_v2_validation'] : [];
    $contentManifest = is_array($scope['content_manifest'] ?? null) ? $scope['content_manifest'] : [];
    $sourceTruth = is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
    $planProjection = is_array($scope['plan_projection'] ?? null) ? $scope['plan_projection'] : [];
    $items = [
        [
            'key' => 'build_plan_contract_ready',
            'label' => 'build_plan_v2 canonical contract is confirmed and valid',
            'ok' => $buildPlan !== [] && (empty($validation['errors']) && (($validation['valid'] ?? true) !== false)),
            'blocking' => true,
        ],
        [
            'key' => 'content_manifest_ready',
            'label' => 'content manifest contains visitor-facing content items',
            'ok' => is_array($contentManifest['items'] ?? null) && count($contentManifest['items']) > 0,
            'blocking' => true,
        ],
        [
            'key' => 'task_graph_ready',
            'label' => 'build_plan_v2 contains executable tasks and blocks',
            'ok' => is_array($buildPlan['tasks'] ?? null) && count($buildPlan['tasks']) > 0
                && is_array($buildPlan['blocks'] ?? null) && count($buildPlan['blocks']) > 0,
            'blocking' => true,
        ],
        [
            'key' => 'source_truth_ready',
            'label' => 'source-truth contract is available before build',
            'ok' => $sourceTruth !== [],
            'blocking' => true,
        ],
        [
            'key' => 'plan_projection_ready',
            'label' => 'UI projection is present without becoming the build source',
            'ok' => $planProjection !== [],
            'blocking' => true,
        ],
    ];
    $passed = true;
    foreach ($items as $item) {
        if (!empty($item['blocking']) && empty($item['ok'])) {
            $passed = false;
            break;
        }
    }

    return [
        'passed' => $passed,
        'items' => $items,
        'page_reports' => [],
    ];
};

$ensureFixtureVerifiedAssets = static function (
    AiSiteAgentSessionService $sessionService,
    AiSiteAgentSession $session,
    array $scope
): array {
    /** @var AiSiteAssetManifestService $assetManifestService */
    $assetManifestService = ObjectManager::getInstance(AiSiteAssetManifestService::class);
    $manifest = $assetManifestService->syncFromBuildPlan($scope);
    $slots = is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
    if ($slots === []) {
        return $scope;
    }

    $repoRoot = dirname(__DIR__, 2);
    $sanitizeHandle = static function (string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return trim($value, '-_.');
    };
    $handle = '';
    foreach ([
        $scope['target_domain'] ?? null,
        $scope['selected_domain'] ?? null,
        $scope['website_profile']['target_domain'] ?? null,
        $scope['website_profile']['domain'] ?? null,
        $session->getPublicId(),
    ] as $candidate) {
        if (!is_scalar($candidate)) {
            continue;
        }
        $handle = $sanitizeHandle((string)$candidate);
        if ($handle !== '') {
            break;
        }
    }
    if ($handle === '') {
        $handle = 'fixture-site';
    }
    $fixtureDir = $repoRoot . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'media'
        . DIRECTORY_SEPARATOR . 'page-build' . DIRECTORY_SEPARATOR . 'ai-generated'
        . DIRECTORY_SEPARATOR . $handle;
    if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0755, true) && !is_dir($fixtureDir)) {
        throw new RuntimeException('Cannot create fixture asset directory: ' . $fixtureDir);
    }
    $fixturePngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAcUlEQVR4nO3PQQ3AMAzAwP7/6c8EJKgk5gT8' .
        's6GZ2bZtAAAAAAAAAAAAAADg3V7n9wDeJgECBAgQIECAAAECBAgQIECAAAECBAgQIECAAAECBAgQ' .
        'IECAAAECBAgQIECAAIFbAhcAAAD//wMAp5AClwmJ+0UAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($fixturePngBytes) || $fixturePngBytes === '') {
        throw new RuntimeException('Fixture PNG payload is invalid.');
    }

    $generated = [];
    foreach ($slots as $slotId => $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $slotId = trim((string)($slot['slot_id'] ?? $slotId));
        if ($slotId === '') {
            continue;
        }
        $required = (int)($slot['required'] ?? $slot['desired_image'] ?? 0) === 1;
        $finalUrl = trim((string)($slot['final_url'] ?? $slot['url'] ?? ''));
        if (!$required || $finalUrl !== '') {
            continue;
        }
        $fileName = preg_replace('/[^a-z0-9._-]+/i', '-', $slotId) ?: sha1($slotId);
        $relativePath = 'pub/media/page-build/ai-generated/' . $handle . '/' . trim($fileName, '-_.') . '.png';
        $absolutePath = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath) && file_put_contents($absolutePath, $fixturePngBytes) === false) {
            throw new RuntimeException('Cannot write fixture asset: ' . $absolutePath);
        }
        $finalUrl = '/' . $relativePath;
        $slot['final_url'] = $finalUrl;
        $slot['url'] = $finalUrl;
        $slot['status'] = 'generated';
        $slot['source'] = 'fixture';
        $slot['fixture_verified_asset'] = 1;
        $slot['variants'] = [[
            'url' => $finalUrl,
            'path' => $relativePath,
            'mime_type' => 'image/png',
            'mode' => 'fixture',
        ]];
        $manifest['slots'][$slotId] = $slot;
        $generated[] = $slotId;
    }

    if ($generated === []) {
        return $scope;
    }

    $manifest['updated_at'] = date('Y-m-d H:i:s');
    $scope['asset_manifest'] = $manifest;
    $scope['verified_assets'] = $assetManifestService->extractVerifiedAssets($manifest);
    $scope['fixture_verified_assets'] = $generated;
    $sessionService->mergeScope($session->getId(), 1, [
        'asset_manifest' => $scope['asset_manifest'],
        'verified_assets' => $scope['verified_assets'],
        'fixture_verified_assets' => $generated,
    ]);

    echo "  fixture verified assets: " . implode(', ', $generated) . "\n";

    return $scope;
};

// Login
echo "[1] 登录...\n";
$admin = ObjectManager::getInstance(BackendUser::class)->clearData()->clearQuery()->load(1);
SessionFactory::getInstance()->createBackendSession()->login($admin);
echo "✓ admin\n\n";

// Create + merge scope
$request = ObjectManager::getInstance(Request::class);
$request->setMethod('POST');
$controller = ObjectManager::getInstance(AiSiteAgent::class);

echo "[2] 创建会话 + 填充需求...\n";
$cr = $jsonDecode($controller->postCreateSession());
$publicId = (string)($cr['public_id'] ?? '');
$siteTitle = '蜀韵川菜馆-' . date('mdHi');

$request->setPost('public_id', $publicId);
$request->setPost('scope_patch', [
    'site_title' => $siteTitle,
    'site_tagline' => '正宗老成都川菜，线上预订享优惠',
    'target_domain' => 'shuyun.local.test',
    'brief_description' => '成都老城区传统川菜馆，展示招牌菜麻婆豆腐水煮鱼回锅肉，提供在线预订，展示门店地址和食客好评。',
    'user_description' => '川菜、预订、老字号、成都美食、麻辣、麻婆豆腐、水煮鱼',
    'default_locale' => 'zh_Hans_CN',
    'page_types' => ['home'],
]);
$controller->postMergeScope();
echo "✓ public_id: {$publicId}\n\n";

// Generate plan (simulated — same as test harness)
echo "[3] 生成方案...\n";
$request->setPost('public_id', $publicId);
// This verification harness builds plan artifacts below; do not also create a
// scheduler-owned plan queue, otherwise confirm-plan rejects the session as active.

/** @var AiSiteAgentSessionService $ss */
$ss = ObjectManager::getInstance(AiSiteAgentSessionService::class);
/** @var AiSiteScopeCompatibilityService $sc */
$sc = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
/** @var AiSiteProfileGenerationService $ps */
$ps = ObjectManager::getInstance(AiSiteProfileGenerationService::class);
/** @var AiSiteExecutionBlueprintService $eb */
$eb = ObjectManager::getInstance(AiSiteExecutionBlueprintService::class);

$session = $ss->loadByPublicId($publicId, 1);
$scope = $sc->normalizeScope($ss->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN));
$wp = $ps->generate($scope, false);
$artifacts = $requireRealPlan
    ? $eb->buildPlanArtifactsByAiStream($scope, is_array($wp) ? $wp : [])
    : $eb->buildPlanArtifacts($scope, is_array($wp) ? $wp : []);
$planAiGenerated = $requireRealPlan
    ? ((int)($artifacts['ai_generated'] ?? 0) === 1 || (string)($artifacts['generation_source'] ?? '') === 'ai_staged' ? 1 : 0)
    : 0;
$planAiFallback = $requireRealPlan ? (int)($artifacts['ai_fallback'] ?? 0) : 1;
if ($requireRealPlan && ($planAiGenerated !== 1 || $planAiFallback !== 0)) {
    fwrite(STDERR, "real-plan mode rejected fallback/fake plan evidence: plan_ai_generated={$planAiGenerated}, plan_ai_fallback={$planAiFallback}\n");
    exit(1);
}

$ss->mergeScope($session->getId(), 1, array_replace(
    is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [],
    [
        'website_profile' => is_array($wp) ? $wp : [],
        'execution_blueprint_draft' => is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [],
        'plan_json' => is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
        'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
        'plan_structured' => is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
        'plan_locale' => (string)($scope['plan_locale'] ?? $scope['default_locale'] ?? ''),
        'plan_ai_generated' => $planAiGenerated,
        'plan_ai_fallback' => $planAiFallback,
        'plan_generated_at' => date('Y-m-d H:i:s'),
        'plan_generated_page_types' => ['home'],
        'plan_confirmed' => 0,
        'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
        'active_operation' => ['operation' => 'plan', 'status' => 'done', 'message' => '方案已生成', 'updated_at' => date('Y-m-d H:i:s')],
    ]
));
$scope = $ss->loadScope($session);
echo "✓ Plan generated\n";
echo "  Palette: " . json_encode($scope['plan_json']['palette'] ?? [], JSON_UNESCAPED_UNICODE) . "\n\n";

// Confirm plan
echo "[4] 确认方案...\n";
$request->setPost('public_id', $publicId);
$cr = $jsonDecode($controller->postConfirmPlan());
$ok = (bool)($cr['success'] ?? false);
echo ($ok ? "✓" : "✗") . " " . ($cr['message'] ?? '') . "\n";
echo "  plan_confirmed: " . ($cr['data']['plan_confirmed'] ?? 'N/A') . "\n\n";
if (!$ok || (int)($cr['data']['plan_confirmed'] ?? 0) !== 1) {
    fwrite(STDERR, "Plan confirmation failed; aborting build verification.\n");
    exit(1);
}

if ($mode === 'fixture') {
    echo "[4b] 准备 fixture verified assets...\n";
    $session = $ss->loadByPublicId($publicId, 1);
    $scope = $ensureFixtureVerifiedAssets($ss, $session, $ss->loadScope($session));
    echo "✓ fixture asset manifest ready\n\n";
}

// Start build
if (!$contentOnly) {
echo "[5] 启动构建...\n";
$request->setPost('public_id', $publicId);
$br = $jsonDecode($controller->postStartBuild());
echo ($br['success'] ?? false ? "✓" : "⚠") . " " . ($br['data']['message'] ?? $br['message'] ?? '') . "\n\n";
if ($mode === 'fixture') {
    echo "[5b] 刷新 fixture verified assets after build start...\n";
    $session = $ss->loadByPublicId($publicId, 1);
    $scope = $ensureFixtureVerifiedAssets($ss, $session, $ss->loadScope($session));
    echo "✓ fixture asset manifest refreshed\n\n";
}

} else {
    echo "[5] content-only mode: skip start-build.\n\n";
}

// Build components through the harness. Only real-plan mode may force live AI.
if ($contentOnly) {
    echo "[6] content-only mode: skip component build and inspect content gates only.\n\n";
} else {
echo "[6] 执行构建（mode=" . ($requireRealPlan ? 'real-plan' : 'fixture') . "）...\n";
RequestContext::set(
    AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST,
    $requireRealPlan
);

$session = $ss->loadByPublicId($publicId, 1);
$sseWriter = new class extends SseWriter {
    public array $events = [];
    private int $eventLimit = 80;

    public function start(): self
    {
        return $this;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->capture($event, $data);
        return $this;
    }

    public function sendData(mixed $data): self
    {
        $this->capture('data', $data);
        return $this;
    }

    public function sendComment(string $comment = ''): self
    {
        $this->capture('comment', $comment);
        return $this;
    }

    public function sendHeartbeat(): self
    {
        return $this;
    }

    public function maybeHeartbeat(): self
    {
        return $this;
    }

    public function sendEventAndYield(string $event, mixed $data = null, ?int $id = null): self
    {
        return $this->sendEvent($event, $data, $id);
    }

    public function sendDataAndYield(mixed $data): self
    {
        return $this->sendData($data);
    }

    public function sendError(string $message, int $code = 500): self
    {
        return $this->sendEvent('error', ['message' => $message, 'code' => $code]);
    }

    public function complete(mixed $data = null): void
    {
        $this->capture('done', $data ?? ['message' => 'Stream completed']);
    }

    public function close(): void
    {
    }

    public function isAlive(): bool
    {
        return true;
    }

    private function capture(string $event, mixed $data): void
    {
        $this->events[] = '[' . $event . '] ' . $this->summarize($data);
        if (count($this->events) > $this->eventLimit) {
            array_shift($this->events);
        }
    }

    private function summarize(mixed $data): string
    {
        if (is_array($data)) {
            $summary = [];
            foreach (['message', 'operation', 'page_type', 'progress_percent', 'progress_kind', 'active_operation_status'] as $key) {
                if (array_key_exists($key, $data)) {
                    $summary[$key] = $data[$key];
                }
            }
            foreach (['task_summary', 'build_task_summary', 'task_progress'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $summary[$key] = array_intersect_key($data[$key], array_flip(['total', 'done', 'pending', 'running', 'failed', 'cancelled']));
                }
            }
            if ($summary === []) {
                $summary = ['keys' => array_slice(array_keys($data), 0, 20)];
            }
            return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }
        if (is_scalar($data) || $data === null) {
            return mb_substr((string)$data, 0, 300);
        }
        return mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: get_debug_type($data), 0, 300);
    }
};

$reflection = new ReflectionMethod($controller, 'runBuildOperation');
$reflection->setAccessible(true);

try {
    $buildResult = $reflection->invoke($controller, $sseWriter, $session, 1);
    echo "✓ Build completed\n";
    if (is_array($buildResult)) {
        echo "  virtual_theme_id: " . ($buildResult['virtual_theme_id'] ?? 'N/A') . "\n";
    }
} catch (\Throwable $e) {
    echo "✗ Build error: " . $e->getMessage() . "\n\n";
    // Show last few SSE events
    $events = $sseWriter->events;
    $n = count($events);
    echo "  Last SSE events ({$n} total):\n";
    foreach (array_slice($events, max(0, $n - 8)) as $ev) {
        echo "    " . mb_substr((string)$ev, 0, 200) . "\n";
    }
    echo "\nProceeding with gate check using current scope...\n";
} finally {
    RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST);
}
echo "\n";
}

// Quality gates
echo "[7] 门禁检查...\n";
$scope = $ss->loadScope($session);

// Check if blocks were generated
$vps = $scope['virtual_pages_by_type'] ?? [];
foreach ($vps as $pt => $vp) {
    echo "  {$pt}: " . count($vp['blocks'] ?? []) . " blocks\n";
    foreach ($vp['blocks'] ?? [] as $bk => $block) {
        $hl = isset($block['html_content']) ? strlen($block['html_content']) . 'chars' : 'no html';
        $cl = isset($block['css_extra']) ? strlen($block['css_extra']) . 'chars' : 'no css';
        echo "    [{$bk}] html={$hl} css={$cl}\n";
    }
}

/** @var AiSiteQualityGateService $gate */
$gate = ObjectManager::getInstance(AiSiteQualityGateService::class);
$report = $contentOnly ? $buildContentOnlyReport($scope) : $gate->inspectScope($scope);

echo "\n═══ 质量门禁 ═══\n";
$pass = $fail = 0;
$failures = [];
$total = 0;
foreach ($report['items'] ?? [] as $item) {
    $total++;
    $ok = !empty($item['ok']);
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $item['key'] ?? '?';
    }
    printf("  %s [%-26s] %s\n", $ok ? "✓" : "✗", $item['key'] ?? '?', $item['label'] ?? '');
    $d = (string)($item['detail'] ?? '');
    if ($d !== '') echo "    → {$d}\n";
}
echo "  ── {$pass}/{$total} pass, {$fail}/{$total} fail — " . ($report['passed'] ? "PASS" : "FAIL") . "\n";

$designContractReport = [];
foreach ($report['items'] ?? [] as $item) {
    if (
        in_array((string)($item['key'] ?? ''), [
            'morphology_diversity',
            'non_hero_asset_distribution',
            'block_contract_coverage',
            'required_image_contract',
            'generic_skeleton_guard',
        ], true)
        && is_array($item['value'] ?? null)
        && is_array($item['value']['page_reports'] ?? null)
    ) {
        $designContractReport = $item['value'];
        break;
    }
}
if ($designContractReport !== []) {
    echo "\n═══ 设计契约报告 ═══\n";
    foreach ($designContractReport['page_reports'] ?? [] as $pt => $pr) {
        if (!is_array($pr)) {
            continue;
        }
        echo "[{$pt}] blocks=" . (int)($pr['block_count'] ?? 0)
            . " contracts=" . (int)($pr['blocks_with_contract'] ?? 0)
            . " morphology=" . (int)($pr['unique_morphology_count'] ?? 0) . "/" . (int)($pr['expected_unique_morphology_count'] ?? 0)
            . " real_images=" . (int)($pr['required_image_blocks'] ?? 0) . "/" . (int)($pr['target_real_image_slots'] ?? 0)
            . " non_hero=" . (int)($pr['non_hero_required_image_blocks'] ?? 0) . "/" . (int)($pr['min_non_hero_real_image_slots'] ?? 0)
            . "\n";
        foreach (array_slice((array)($pr['required_image_errors'] ?? []), 0, 4) as $error) {
            echo "  image_error: " . mb_substr((string)$error, 0, 160) . "\n";
        }
        foreach (array_slice((array)($pr['generic_skeleton_blocks'] ?? []), 0, 4) as $blockRef) {
            echo "  skeleton_block: " . mb_substr((string)$blockRef, 0, 160) . "\n";
        }
    }
}

// Per-page
echo "\n═══ 逐页报告 ═══\n";
foreach ($report['page_reports'] ?? [] as $pt => $pr) {
    $vd = $pr['visual_depth_signals'] ?? [];
    $rs = $pr['responsive_signals'] ?? [];
    $th = $pr['theme_hits'] ?? [];
    $bm = $pr['bad_matches'] ?? [];
    echo "[{$pt}] rendered=" . ($pr['rendered']?'yes':'no') . "\n";
    echo "  visual_depth:  " . (count($vd)>=3?'✓':'✗') . " (" . count($vd) . ") [" . implode(',',$vd) . "]\n";
    echo "  responsive:    " . ((isset($rs['media_query'])&&count($rs)>=4)?'✓':'✗') . " (" . count($rs) . ") [" . implode(',',array_keys($rs)) . "]\n";
    echo "  theme:         " . (count($th)>0?'✓':'✗') . " (" . count($th) . ") [" . implode(',',$th) . "]\n";
    echo "  content_clean: " . (empty($bm)?'✓':'✗') . " (" . count($bm) . " bad)\n";
    foreach (array_slice($bm,0,3) as $m) echo "    bad: " . mb_substr((string)$m,0,100) . "\n";
}

echo "\n═══ 浏览器链接 ═══\n";
$workspaceUrl = $baseUrl . $workspacePath . '?public_id=' . rawurlencode($publicId);
echo "Workspace: {$workspaceUrl}\n";

if ($fail > 0) {
    echo "\n⚠ 失败门禁: " . implode(', ', $failures) . "\n";
    exit(1);
}
