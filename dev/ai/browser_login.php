<?php
/**
 * 通过 HTTP 登录后台，获取 session cookie，然后构建 AI 建站工作台 URL。
 * 用法: php dev/ai/browser_login.php
 */

$baseUrl = 'https://127.0.0.1';
$prefix = 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8';
$loginUrl = "$baseUrl/$prefix/admin/login";
$loginPostUrl = "$baseUrl/$prefix/admin/login/post";

$sslOpts = [
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
];

// Step 1: Get initial session
$ch = curl_init($loginUrl);
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
curl_close($ch);

// Extract session cookie
preg_match_all('/^Set-Cookie:\s*([^;]+)/im', $response, $cookies);
$cookieJar = [];
foreach ($cookies[1] as $c) {
    $cookieJar[] = $c;
}
$cookieHeader = implode('; ', $cookieJar);
echo "Step 1: Got initial cookies\n";
echo "  Cookie: {$cookieHeader}\n\n";

// Step 2: POST login
$ch = curl_init($loginPostUrl);
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'admin', 'password' => 'Admin@123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_COOKIE => $cookieHeader,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Extract updated cookies
preg_match_all('/^Set-Cookie:\s*([^;]+)/im', $response, $cookies);
foreach ($cookies[1] as $c) {
    $parts = explode('=', $c, 2);
    $name = $parts[0];
    if ($name === 'w_flash' || $name === 'deleted') {
        continue;
    }
    // Add or update
    $found = false;
    foreach ($cookieJar as $i => $existing) {
        if (str_starts_with($existing, $name . '=')) {
            $cookieJar[$i] = $c;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $cookieJar[] = $c;
    }
}
$cookieHeader = implode('; ', $cookieJar);

// Check if login redirected to dashboard
$location = '';
preg_match('/^Location:\s*(.+)/im', $response, $locMatch);
$location = $locMatch[1] ?? '';

echo "Step 2: Login POST\n";
echo "  Response code: {$responseCode}\n";
echo "  Location: {$location}\n";
echo "  Cookie: {$cookieHeader}\n";

$loggedIn = (stripos($location, 'dashboard') !== false || stripos($location, 'admin') === false);
echo "  " . ($loggedIn ? "✓ Login SUCCESS" : "✗ Login FAILED") . "\n\n";

// Extract WELINE_SESSID
$sessId = '';
foreach ($cookieJar as $c) {
    if (str_starts_with($c, 'WELINE_SESSID=')) {
        $sessId = substr($c, strlen('WELINE_SESSID='));
        break;
    }
}

if ($sessId === '') {
    echo "No WELINE_SESSID found in cookies\n";
    exit(1);
}

echo "WELINE_SESSID: {$sessId}\n\n";

// Step 3: Access AI workbench hub
$hubUrl = "$baseUrl/$prefix/websites/backend/site-builder-agent/index?fake_mode=1";
$ch = curl_init($hubUrl);
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIE => "WELINE_SESSID={$sessId}",
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$body = substr($response, curl_getinfo($ch, CURLINFO_HEADER_SIZE));

echo "Step 3: Access workbench hub\n";
echo "  Response code: {$responseCode}\n";
echo "  Body length: " . strlen($body) . "\n";
echo "  Contains 'AI': " . (stripos($body, 'AI') !== false || stripos($body, 'ai') !== false ? 'yes' : 'no') . "\n";
echo "  Contains '建站': " . (stripos($body, '建站') !== false ? 'yes' : 'no') . "\n";
echo "  Contains '工作台': " . (stripos($body, '工作台') !== false ? 'yes' : 'no') . "\n";

// Step 4: Create session via API
$createUrl = "$baseUrl/$prefix/pagebuilder/backend/ai-site-agent/post-create-session";
$ch = curl_init($createUrl);
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
    CURLOPT_COOKIE => "WELINE_SESSID={$sessId}",
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nStep 4: Create AI session\n";
echo "  Response code: {$responseCode}\n";
$data = json_decode($response, true);
if ($data) {
    echo "  success: " . ($data['success'] ?? 'N/A') . "\n";
    echo "  public_id: " . ($data['public_id'] ?? 'N/A') . "\n";
    echo "  workspace_url: " . ($data['workspace_url'] ?? 'N/A') . "\n";
    echo "\n✓ Browser workspace URL:\n";
    echo "  http://127.0.0.1:16895/" . ($data['workspace_url'] ?? 'N/A') . "\n";
} else {
    echo "  Raw response: " . substr($response, 0, 200) . "\n";
}
