<?php
declare(strict_types=1);

/**
 * AI 建站验收 v4 — 完全复刻测试模式
 * 1. 模拟方案生成 + 确认
 * 2. 强制真AI生成组件
 * 3. 门禁检查
 * 用法: php dev/ai/verify_build.php
 */

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
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

echo "╔════════════════════════════════════════════════╗\n";
echo "║   AI 建站验收 v4 — 复刻测试流程               ║\n";
echo "╚════════════════════════════════════════════════╝\n\n";

$jsonDecode = static fn(mixed $r): array =>
    is_string($r) ? (json_decode($r, true) ?: []) : (is_array($r) ? $r : []);

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
$controller->postStartPlan();

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
$artifacts = $eb->buildPlanArtifacts($scope, is_array($wp) ? $wp : []);

$ss->mergeScope($session->getId(), 1, array_replace(
    is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [],
    [
        'website_profile' => is_array($wp) ? $wp : [],
        'execution_blueprint_draft' => is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [],
        'plan_json' => is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
        'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
        'plan_structured' => is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
        'plan_locale' => (string)($scope['plan_locale'] ?? $scope['default_locale'] ?? ''),
        'plan_ai_generated' => 0,
        'plan_ai_fallback' => 1,
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

// Start build
echo "[5] 启动构建...\n";
$request->setPost('public_id', $publicId);
$br = $jsonDecode($controller->postStartBuild());
echo ($br['success'] ?? false ? "✓" : "⚠") . " " . ($br['data']['message'] ?? $br['message'] ?? '') . "\n\n";

// Force real AI + run build via reflection
echo "[6] 执行构建（真AI模式）...\n";
RequestContext::set(
    AiSitePageComponentGenerationService::REQUEST_KEY_FORCE_REAL_AI_IN_TEST,
    true
);

$session = $ss->loadByPublicId($publicId, 1);
$sseWriter = new class extends SseWriter {
    public array $events = [];
    public function write(string $data): void { $this->events[] = $data; }
    public function writeEvent(string $event, string $data): void { $this->events[] = "[{$event}] {$data}"; }
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
}
echo "\n";

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
$report = $gate->inspectScope($scope);

echo "\n═══ 13 项门禁 ═══\n";
$pass = $fail = 0;
$failures = [];
foreach ($report['items'] ?? [] as $item) {
    $ok = !empty($item['ok']);
    $ok ? $pass++ : ($fail++ && $failures[] = $item['key']);
    printf("  %s [%-26s] %s\n", $ok ? "✓" : "✗", $item['key'] ?? '?', $item['label'] ?? '');
    $d = (string)($item['detail'] ?? '');
    if ($d !== '') echo "    → {$d}\n";
}
echo "  ── {$pass}/13 pass, {$fail}/13 fail — " . ($report['passed'] ? "PASS" : "FAIL") . "\n";

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
echo "Workspace: https://127.0.0.1/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/pagebuilder/backend/ai-site-agent/workspace?public_id={$publicId}\n";

if ($fail > 0) {
    echo "\n⚠ 失败门禁: " . implode(', ', $failures) . "\n";
    exit(1);
}
