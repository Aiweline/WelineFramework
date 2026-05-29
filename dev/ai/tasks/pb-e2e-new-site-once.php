<?php
declare(strict_types=1);

/**
 * 新建 AI 建站会话并跑完 plan + build，最后校验预览字体 token。
 * 用法：php dev/ai/tasks/pb-e2e-new-site-once.php
 */

$repoRoot = dirname(__DIR__, 3);
chdir($repoRoot);

$baseUrl = rtrim((string) (getenv('WELINE_BASE_URL') ?: 'https://p11005ce4.weline.test'), '/');
$prefix = trim((string) (getenv('WELINE_ROUTE_PREFIX') ?: 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8'), '/');
$adminUser = trim((string) (getenv('WELINE_ADMIN_USER') ?: 'admin'));
$adminPassword = (string) (getenv('WELINE_ADMIN_PASSWORD') ?: 'admin');
$planPollSeconds = max(60, (int) (getenv('WELINE_PLAN_POLL_SECONDS') ?: 600));
$buildPollSeconds = max(120, (int) (getenv('WELINE_BUILD_POLL_SECONDS') ?: 1800));

$sslOpts = [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
$sessId = null;

$path = static function (string $p) use ($prefix): string {
    $p = '/' . ltrim($p, '/');
    return $prefix !== '' ? '/' . $prefix . $p : $p;
};

$req = static function (string $method, string $rel, ?array $json = null) use ($baseUrl, $path, $sslOpts, &$sessId): array {
    $url = $baseUrl . $path($rel);
    $ch = curl_init($url);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $opts = $sslOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $json !== null
            ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
    }
    if ($sessId) {
        $opts[CURLOPT_COOKIE] = 'WELINE_SESSID=' . $sessId;
    }
    $raw = false;
    $lastErr = '';
    for ($attempt = 1; $attempt <= 6; ++$attempt) {
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if (is_string($raw)) {
            break;
        }
        $lastErr = curl_error($ch);
        usleep(500000 * $attempt);
    }
    if (!is_string($raw)) {
        curl_close($ch);
        throw new RuntimeException('curl failed after retries: ' . $lastErr);
    }
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hdr = substr($raw, 0, $hs);
    $body = substr($raw, $hs);
    if (preg_match_all('/^Set-Cookie:\s*WELINE_SESSID=([^;]+)/im', $hdr, $m)) {
        $sessId = (string) end($m[1]);
    }
    $decoded = json_decode($body, true);
    return [is_array($decoded) ? $decoded : [], $code, $body, $url];
};

$ok = static function (array $resp, string $label): array {
    [$data, $code, $body, $url] = $resp;
    if ($code < 200 || $code >= 300 || (array_key_exists('success', $data) && empty($data['success']))) {
        throw new RuntimeException($label . " failed HTTP {$code} @ {$url}: " . substr((string) $body, 0, 400));
    }
    return $data;
};

function log_line(string $msg): void
{
    echo date('H:i:s') . ' ' . $msg . PHP_EOL;
}

log_line('Base URL: ' . $baseUrl . ($prefix !== '' ? " prefix={$prefix}" : ''));

log_line('[1] Login');
$ok($req('GET', '/admin/login'), 'login page');
$ch = curl_init($baseUrl . $path('/admin/login/post'));
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => $adminUser, 'password' => $adminPassword, 'remember' => 'on']),
    CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
]);
$raw = curl_exec($ch);
curl_close($ch);
if (!is_string($raw) || !preg_match('/WELINE_SESSID=([^;]+)/', $raw, $m)) {
    throw new RuntimeException('Login did not set WELINE_SESSID');
}
$sessId = $m[1];
log_line('  sid=' . substr($sessId, 0, 8) . '...');

$resumePublicId = trim((string) (getenv('WELINE_RESUME_PUBLIC_ID') ?: ''));
if ($resumePublicId !== '') {
    $publicId = $resumePublicId;
    log_line('[2] Resume existing session public_id=' . $publicId);
} else {
    log_line('[2] Create session');
    $create = $ok($req('POST', '/pagebuilder/backend/ai-site-agent/post-create-session', []), 'create session');
    $publicId = (string) ($create['public_id'] ?? $create['data']['public_id'] ?? '');
    if ($publicId === '') {
        throw new RuntimeException('Missing public_id in create response');
    }
    log_line('  public_id=' . $publicId);

    $tag = date('mdHi');
    log_line('[3] Merge scope (single home page for faster E2E)');
    $ok($req('POST', '/pagebuilder/backend/ai-site-agent/post-merge-scope', [
        'public_id' => $publicId,
        'scope_patch' => [
            'site_title' => '设计一致性实测-' . $tag,
            'site_tagline' => '字体与语气统一验收',
            'target_domain' => 'design-qa-' . $tag . '.local.test',
            'brief_description' => '简约中文品牌官网，强调清晰排版、统一字体与可信语气。',
            'user_description' => '中文官网首页，品牌介绍、核心服务、联系入口，专业可信。',
            'default_locale' => 'zh_Hans_CN',
            'page_types' => ['home_page'],
        ],
    ]), 'merge scope');

    log_line('[4] Start plan');
    $ok($req('POST', '/pagebuilder/backend/ai-site-agent/post-start-plan', ['public_id' => $publicId]), 'start plan');
}

log_line('[5] Poll plan confirm (max ' . $planPollSeconds . 's)');
$planConfirmed = false;
$deadline = time() + $planPollSeconds;
while (time() <= $deadline) {
    [$data, $code] = $req('POST', '/pagebuilder/backend/ai-site-agent/post-confirm-plan', ['public_id' => $publicId]);
    $confirmed = $code >= 200 && $code < 300 && (int) ($data['data']['plan_confirmed'] ?? $data['plan_confirmed'] ?? 0) === 1;
    $msg = (string) ($data['message'] ?? $data['data']['message'] ?? '');
    log_line('  plan_confirmed=' . ($confirmed ? '1' : '0') . ' ' . substr($msg, 0, 80));
    if ($confirmed) {
        $planConfirmed = true;
        break;
    }
    sleep(10);
}
if (!$planConfirmed) {
    throw new RuntimeException('Plan not confirmed in time');
}

log_line('[6] Start build');
$ok($req('POST', '/pagebuilder/backend/ai-site-agent/post-start-build', ['public_id' => $publicId]), 'start build');

log_line('[7] Poll build until visual_edit or preview ready (max ' . $buildPollSeconds . 's)');
$ready = false;
$deadline = time() + $buildPollSeconds;
$lastStage = '';
while (time() <= $deadline) {
    $snap = $ok($req('POST', '/pagebuilder/backend/ai-site-agent/post-workspace-snapshot', [
        'public_id' => $publicId,
        'snapshot_mode' => 'queue_poll',
    ]), 'workspace snapshot');
    $state = is_array($snap['data'] ?? null) ? $snap['data'] : [];
    $stage = (string) ($state['stage'] ?? $state['workspace_stage'] ?? '');
    $status = (string) ($state['status'] ?? $state['workspace_status'] ?? '');
    $progress = (string) ($state['progress_percent'] ?? $state['progress'] ?? '');
  $lastStage = $stage !== '' ? $stage : $status;
    log_line('  stage=' . $lastStage . ' progress=' . $progress);

    if ($stage === 'visual_edit' || $status === 'visual_edit' || str_contains($status, 'ready')) {
        $ready = true;
        break;
    }
    $failed = (int) ($state['failed'] ?? 0);
    $total = (int) ($state['total'] ?? 0);
    $done = (int) ($state['completed'] ?? $state['done'] ?? 0);
    if ($total > 0 && $done >= $total && $failed === 0) {
        $ready = true;
        break;
    }
    sleep(12);
}
if (!$ready) {
    throw new RuntimeException('Build did not reach visual_edit in time; last=' . $lastStage);
}

log_line('[8] Preview font probe');
[$_, $code, $html] = $req('GET', '/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=' . rawurlencode($publicId) . '&page_type=home_page&preview=1');
if ($code < 200 || $code >= 300) {
    throw new RuntimeException('Preview HTTP ' . $code);
}
preg_match_all('/var\(\s*--pb-font-(display|body)/i', $html, $v);
preg_match_all('/--pb-font-display\s*:/i', $html, $root);
preg_match_all('/font-family\s*:\s*[^;}]+/i', $html, $ff);
$varCount = count($v[0]);
$hasRoot = count($root[0]) > 0;
$hard = 0;
foreach ($ff[0] ?? [] as $decl) {
    if (stripos($decl, 'var(--pb-font') === false && stripos($decl, 'inherit') === false
        && preg_match('/\b(?:Inter|Roboto|system-ui|-apple-system)\b/i', $decl) === 1) {
        $hard++;
    }
}
log_line('  preview_bytes=' . strlen($html));
log_line('  var_pb_font=' . $varCount . ' root_display_var=' . ($hasRoot ? 'yes' : 'no') . ' hardcoded_system_font=' . $hard);

log_line('[9] CLI audit');
$bin = $repoRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';
$auditCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin)
    . ' aisite:audit --public_id=' . escapeshellarg($publicId) . ' --admin_id=1';
passthru($auditCmd, $auditExit);

$pass = $varCount > 0 && $hasRoot && $hard === 0;
log_line('');
log_line('=== RESULT ===');
log_line('public_id=' . $publicId);
log_line('workspace=' . $baseUrl . $path('/pagebuilder/backend/ai-site-agent/workspace?public_id=' . rawurlencode($publicId)));
log_line('font_g02=' . ($pass ? 'PASS' : 'FAIL'));

exit($pass && $auditExit === 0 ? 0 : 1);
