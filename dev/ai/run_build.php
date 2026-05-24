<?php
declare(strict_types=1);

/**
 * End-to-end PageBuilder AI site flow smoke script.
 *
 * Required env:
 * - WELINE_ADMIN_USER
 * - WELINE_ADMIN_PASSWORD
 *
 * Optional env:
 * - WELINE_BASE_URL=https://127.0.0.1
 * - WELINE_ROUTE_PREFIX=<front route prefix>
 * - WELINE_HTTP_PORT=<bin/w http:request server port for publish checklist>
 * - WELINE_PLAN_POLL_SECONDS=180
 */

$repoRoot = dirname(__DIR__, 2);
$bin = $repoRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';
if (!is_file($bin)) {
    fwrite(STDERR, "Missing framework CLI: {$bin}\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Missing PHP curl extension; cannot call backend HTTP endpoints.\n");
    exit(2);
}

$baseUrl = rtrim((string)(getenv('WELINE_BASE_URL') ?: 'https://127.0.0.1'), '/');
$prefix = trim((string)(getenv('WELINE_ROUTE_PREFIX') ?: ''), '/');
$adminUser = trim((string)(getenv('WELINE_ADMIN_USER') ?: ''));
$adminPassword = (string)(getenv('WELINE_ADMIN_PASSWORD') ?: '');
$httpPort = trim((string)(getenv('WELINE_HTTP_PORT') ?: ''));
$maxPollSeconds = max(30, (int)(getenv('WELINE_PLAN_POLL_SECONDS') ?: 180));
$sslOpts = [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
$sessId = null;

if ($adminUser === '' || $adminPassword === '') {
    fwrite(STDERR, "Set WELINE_ADMIN_USER and WELINE_ADMIN_PASSWORD before running this script.\n");
    exit(2);
}

function build_path(string $path): string
{
    global $prefix;
    $path = '/' . ltrim($path, '/');
    return $prefix !== '' ? '/' . $prefix . $path : $path;
}

function http_json(string $method, string $path, array $data = []): array
{
    global $baseUrl, $sslOpts, $sessId;
    $url = $baseUrl . build_path($path);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Cannot initialize curl.');
    }
    $opts = $sslOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($sessId !== null && $sessId !== '') {
        $opts[CURLOPT_COOKIE] = "WELINE_SESSID={$sessId}";
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if (!is_string($response)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    preg_match_all('/^Set-Cookie:\s*WELINE_SESSID=([^;]+)/im', substr($response, 0, $headerSize), $matches);
    if (!empty($matches[1])) {
        $sessId = (string)end($matches[1]);
    }

    $body = substr($response, $headerSize);
    $decoded = json_decode($body, true);
    return [is_array($decoded) ? $decoded : [], $code, $body, $url];
}

function require_success(array $response, string $label): array
{
    [$data, $code, $body, $url] = $response;
    if ($code < 200 || $code >= 300 || (array_key_exists('success', $data) && empty($data['success']))) {
        throw new RuntimeException($label . " failed at {$url}: HTTP {$code}; " . substr((string)$body, 0, 500));
    }
    return $data;
}

echo "PageBuilder AI site flow\n";
echo "Repo: {$repoRoot}\n";
echo "Base URL: {$baseUrl}" . ($prefix !== '' ? " / prefix={$prefix}" : '') . "\n\n";

echo "[1/7] Login\n";
http_json('GET', '/admin/login');
require_success(http_json('POST', '/admin/login/post', ['username' => $adminUser, 'password' => $adminPassword]), 'login');
if ($sessId === null || $sessId === '') {
    throw new RuntimeException('Login did not return WELINE_SESSID.');
}
echo "  session acquired\n\n";

echo "[2/7] Create AI session\n";
$create = require_success(http_json('POST', '/pagebuilder/backend/ai-site-agent/post-create-session'), 'create session');
$publicId = (string)($create['public_id'] ?? $create['data']['public_id'] ?? '');
if ($publicId === '') {
    throw new RuntimeException('Create session response did not include public_id.');
}
echo "  public_id={$publicId}\n\n";

echo "[3/7] Merge scope\n";
$siteTitle = 'Contract QA Restaurant ' . date('mdHi');
require_success(http_json('POST', '/pagebuilder/backend/ai-site-agent/post-merge-scope', [
    'public_id' => $publicId,
    'scope_patch' => [
        'site_title' => $siteTitle,
        'site_tagline' => 'Reservation-ready Chengdu restaurant site',
        'target_domain' => 'contract-qa.local.test',
        'brief_description' => 'Traditional Chengdu restaurant website with signature dishes, reservations, location, reviews, and strong non-hero food and dining imagery.',
        'user_description' => 'Sichuan restaurant, reservations, signature dishes, Chengdu food, spicy cuisine, customer reviews, location.',
        'default_locale' => 'zh_Hans_CN',
        'page_types' => ['home_page', 'services_page', 'about_page', 'contact_page'],
    ],
]), 'merge scope');
echo "  scope merged\n\n";

echo "[4/7] Start plan queue\n";
require_success(http_json('POST', '/pagebuilder/backend/ai-site-agent/post-start-plan', ['public_id' => $publicId]), 'start plan');
echo "  plan queued; waiting for scheduler-owned completion\n\n";

echo "[5/7] Poll confirm-plan\n";
$deadline = time() + $maxPollSeconds;
$planConfirmed = false;
$lastMessage = '';
while (time() <= $deadline) {
    [$data, $code, $body] = http_json('POST', '/pagebuilder/backend/ai-site-agent/post-confirm-plan', ['public_id' => $publicId]);
    $lastMessage = (string)($data['message'] ?? $data['data']['message'] ?? substr((string)$body, 0, 200));
    $planConfirmed = $code >= 200 && $code < 300 && (int)($data['data']['plan_confirmed'] ?? 0) === 1;
    echo "  " . date('H:i:s') . " plan_confirmed=" . ($planConfirmed ? '1' : '0') . " {$lastMessage}\n";
    if ($planConfirmed) {
        break;
    }
    sleep(5);
}
if (!$planConfirmed) {
    throw new RuntimeException("Plan was not confirmable within {$maxPollSeconds}s. Last message: {$lastMessage}");
}
echo "\n";

echo "[6/7] Start build queue\n";
require_success(http_json('POST', '/pagebuilder/backend/ai-site-agent/post-start-build', ['public_id' => $publicId]), 'start build');
echo "  build queued; scheduler owns execution\n\n";

echo "[7/7] Publish checklist\n";
if ($httpPort === '') {
    throw new RuntimeException('Set WELINE_HTTP_PORT to run the publish checklist via bin/w http:request.');
}
$payload = json_encode(['public_id' => $publicId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cmd = [
    PHP_BINARY,
    $bin,
    'http:request',
    'pagebuilder/backend/ai-site-agent/post-publish-checklist',
    '-b',
    '--port=' . $httpPort,
    '--sid=' . $sessId,
    '-m',
    'POST',
    '-d',
    (string)$payload,
];
$escaped = array_map(static fn(string $part): string => escapeshellarg($part), $cmd);
$output = [];
$exitCode = 0;
exec(implode(' ', $escaped), $output, $exitCode);
echo implode("\n", $output) . "\n";
if ($exitCode !== 0) {
    throw new RuntimeException('Publish checklist failed with exit code ' . $exitCode);
}

$workspace = $baseUrl . build_path('/pagebuilder/backend/ai-site-agent/workspace?public_id=' . rawurlencode($publicId));
$hub = $baseUrl . build_path('/websites/backend/site-builder-agent/index');
echo "\nWorkspace: {$workspace}\n";
echo "Hub: {$hub}\n";
echo "Public ID: {$publicId}\n";
