<?php
declare(strict_types=1);

/**
 * AI 建站门禁监控脚本
 * 用法: php dev/ai/check_gate.php [public_id]
 * 不带参数则检查最新 session
 */

use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

require __DIR__ . '/../../app/bootstrap.php';

$publicId = $argv[1] ?? null;

// Login
$admin = ObjectManager::getInstance(BackendUser::class);
$admin->clearData()->clearQuery()->load(1);
SessionFactory::getInstance()->createBackendSession()->login($admin);

/** @var AiSiteAgentSessionService $sessionService */
$sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);

if ($publicId === null) {
    // Find latest session
    $model = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\AiSiteAgentSession::class);
    $latest = $model->clearData()->clearQuery()->select('public_id')->order('updated_at', 'DESC')->limit(1)->fetchArray();
    if (empty($latest)) {
        echo "No sessions found\n";
        exit(1);
    }
    $publicId = (string)($latest[0]['public_id'] ?? '');
}

echo "Session: {$publicId}\n";

$session = $sessionService->loadByPublicId($publicId, 1);
if (!$session) {
    echo "Session not found\n";
    exit(1);
}

$scope = $sessionService->loadScope($session);
echo "Stage: " . $session->getStage() . "\n";
echo "Workspace: " . ($scope['workspace_status'] ?? 'unknown') . "\n";
echo "Site: " . ($scope['site_title'] ?? 'unknown') . "\n";

// Quality Gate
/** @var AiSiteQualityGateService $gate */
$gate = ObjectManager::getInstance(AiSiteQualityGateService::class);
$report = $gate->inspectScope($scope);

echo "\n═══ 13 项门禁 ═══\n";
$pass = 0;
$fail = 0;
foreach ($report['items'] ?? [] as $item) {
    $ok = !empty($item['ok']);
    $ok ? $pass++ : $fail++;
    printf("  %s [%-26s] %s\n",
        $ok ? "✓" : "✗",
        $item['key'] ?? '?',
        $item['label'] ?? ''
    );
    $detail = (string)($item['detail'] ?? '');
    if ($detail !== '') {
        echo "    → {$detail}\n";
    }
}
echo "──\n";
echo "  {$pass}/13 pass, {$fail}/13 fail\n";
echo "  Overall: " . ($report['passed'] ? "PASS" : "FAIL") . "\n";

// Per-page detail
echo "\n═══ 逐页报告 ═══\n";
foreach ($report['page_reports'] ?? [] as $pt => $pr) {
    $rendered = !empty($pr['rendered']);
    $vd = $pr['visual_depth_signals'] ?? [];
    $rs = $pr['responsive_signals'] ?? [];
    $th = $pr['theme_hits'] ?? [];
    $bm = $pr['bad_matches'] ?? [];

    echo "[{$pt}] " . ($rendered ? "✓ rendered" : "✗ NOT rendered") . "\n";
    if (!empty($pr['render_error'])) {
        echo "  Error: " . $pr['render_error'] . "\n";
    }
    echo "  visual_depth:   " . (count($vd) >= 3 ? "✓" : "✗") . " signals=" . count($vd) . " [" . implode(',', $vd) . "]\n";
    echo "  responsive:     " . ((isset($rs['media_query']) && count($rs) >= 4) ? "✓" : "✗") . " signals=" . count($rs) . " [" . implode(',', array_keys($rs)) . "]\n";
    echo "  theme:          " . (count($th) > 0 ? "✓" : "✗") . " hits=" . count($th) . " [" . implode(',', $th) . "]\n";
    echo "  content:        " . (empty($bm) ? "✓" : "✗") . " bad=" . count($bm) . "\n";
    foreach (array_slice($bm, 0, 3) as $m) {
        echo "    bad: " . mb_substr((string)$m, 0, 80) . "\n";
    }
    echo "  stage1:         " . (!empty($pr['stage1_content_visible']) ? "✓" : "✗") . "\n";
    echo "  language:       " . (empty($pr['language_violations'] ?? []) ? "✓" : "✗") . "\n";
    echo "  visuals:        " . (!empty($pr['visuals_safe']) ? "✓" : "✗") . "\n";
    echo "  shared_blocks:  " . (!empty($pr['shared_blocks_ready']) ? "✓" : "✗") . "\n";
}

echo "\nPreview URL: " . ($scope['visual_preview_url'] ?? $scope['preview_url'] ?? 'N/A') . "\n";

exit($report['passed'] ? 0 : 1);
