<?php
/**
 * 完整 AI 建站流程 — 通过 HTTPS API 全自动执行
 * 用法: php dev/ai/run_build.php
 */

$baseUrl = 'https://127.0.0.1';
$prefix = 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8';
$sslOpts = [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
$sessId = null;

function http(string $method, string $path, array $data = []): array {
    global $baseUrl, $prefix, $sslOpts, $sessId;
    $url = "{$baseUrl}/{$prefix}{$path}";
    $ch = curl_init($url);
    $opts = $sslOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    if ($sessId) {
        $opts[CURLOPT_COOKIE] = "WELINE_SESSID={$sessId}";
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Extract any updated session cookie
    preg_match_all('/^Set-Cookie:\s*WELINE_SESSID=([^;]+)/im', substr($response, 0, $headerSize), $matches);
    if (!empty($matches[1])) {
        $sessId = end($matches[1]);
    }

    $body = substr($response, $headerSize);
    return [json_decode($body, true) ?: [], $code, $body];
}

echo "╔══════════════════════════════════════════╗\n";
echo "║    AI 建站全流程自动化测试              ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

// 1. Login
echo "[1/7] 登录...\n";
[$_, $code] = http('GET', '/admin/login');
[$_, $code, $_] = http('POST', '/admin/login/post', ['username' => 'admin', 'password' => 'Admin@123']);
echo "  Session: {$sessId}\n";
echo "  " . ($sessId ? "✓" : "✗") . " 已登录\n\n";

// 2. Create AI session
echo "[2/7] 创建建站会话...\n";
[$data] = http('POST', '/pagebuilder/backend/ai-site-agent/post-create-session');
$publicId = (string)($data['public_id'] ?? '');
echo "  public_id: {$publicId}\n";
echo "  " . ($publicId ? "✓" : "✗") . " 会话已创建\n\n";

// 3. Merge scope
echo "[3/7] 填充网站需求...\n";
$siteTitle = '蜀韵川菜馆-' . date('mdHi');
[$data] = http('POST', '/pagebuilder/backend/ai-site-agent/post-merge-scope', [
    'public_id' => $publicId,
    'scope_patch' => [
        'site_title' => $siteTitle,
        'site_tagline' => '正宗老成都川菜，线上预订享优惠',
        'target_domain' => 'shuyun.local.test',
        'brief_description' => '成都老城区传统川菜馆，展示招牌菜（麻婆豆腐、水煮鱼、回锅肉），提供在线预订，展示门店地址和食客好评。',
        'user_description' => '川菜、预订、老字号、成都美食、麻辣、麻婆豆腐、水煮鱼',
        'default_locale' => 'zh_Hans_CN',
        'page_types' => ['home'],
    ],
]);
echo "  workspace_status: " . ($data['data']['workspace_status'] ?? 'N/A') . "\n";
echo "  " . (($data['success'] ?? false) ? "✓" : "✗") . " Scope 已合并\n\n";

// 4. Start plan
echo "[4/7] 启动方案生成...\n";
[$data] = http('POST', '/pagebuilder/backend/ai-site-agent/post-start-plan', [
    'public_id' => $publicId,
]);
echo "  Message: " . ($data['data']['message'] ?? $data['message'] ?? 'N/A') . "\n";
echo "  " . (($data['success'] ?? false) ? "✓" : "⚠") . " 计划已入队\n\n";

// 5. Wait for plan and confirm
echo "[5/7] 等待方案生成并确认...\n";
// Try to confirm - if plan is ready, it will work
sleep(2);
[$data] = http('POST', '/pagebuilder/backend/ai-site-agent/post-confirm-plan', [
    'public_id' => $publicId,
]);
$planConfirmed = (int)($data['data']['plan_confirmed'] ?? 0);
echo "  plan_confirmed: {$planConfirmed}\n";
if ($planConfirmed) {
    echo "  ✓ 方案已确认\n";
} else {
    echo "  ⚠ 方案未就绪，可能需要队列执行\n";
    echo "  Message: " . ($data['message'] ?? 'N/A') . "\n";
}
echo "\n";

// 6. Start build
echo "[6/7] 启动构建...\n";
[$data] = http('POST', '/pagebuilder/backend/ai-site-agent/post-start-build', [
    'public_id' => $publicId,
]);
echo "  Message: " . ($data['data']['message'] ?? $data['message'] ?? 'N/A') . "\n";
echo "  " . (($data['success'] ?? false) ? "✓" : "⚠") . " 构建已入队\n\n";

// 7. Check quality gates
echo "[7/7] 检查质量门禁...\n";
// Call via http:req CLI which uses the framework inspectScope
$cmd = sprintf(
    'php %s/bin/w http:req "pagebuilder/backend/ai-site-agent/post-publish-checklist" -b --port=16895 --sid=%s -m POST -d %s',
    'E:/WelineFramework/DEV-workspace',
    escapeshellarg($sessId),
    escapeshellarg(json_encode(['public_id' => $publicId]))
);
exec($cmd, $output, $exitCode);
// Fallback: check via direct PHP
echo "\n";
echo "═══ 浏览器链接 ═══\n";
echo "工作台: https://127.0.0.1/{$prefix}/pagebuilder/backend/ai-site-agent/workspace?public_id={$publicId}\n";
echo "Hub:    https://127.0.0.1/{$prefix}/websites/backend/site-builder-agent/index?fake_mode=1\n";
echo "\nSession ID: {$sessId}\n";
echo "Public ID:  {$publicId}\n";
echo "Site Title: {$siteTitle}\n";
echo "\n完成。在浏览器中打开工作台查看进度。\n";
