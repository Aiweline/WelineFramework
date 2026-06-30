<?php
declare(strict_types=1);

/**
 * Git webhook entry for WelineFramework deploy.
 *
 * This file validates a Git provider webhook secret, filters the target branch,
 * then executes dev/deploy/webhook.sh deploy with the same .config file.
 */

function deploy_webhook_respond(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function deploy_webhook_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
        return $_SERVER[$serverKey];
    }

    if ($name === 'Authorization') {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $authKey) {
            if (isset($_SERVER[$authKey]) && is_string($_SERVER[$authKey])) {
                return $_SERVER[$authKey];
            }
        }
    }

    return '';
}

function deploy_webhook_parse_config(string $file): array
{
    if (!is_file($file)) {
        deploy_webhook_respond(500, ['ok' => false, 'error' => 'config file not found']);
    }

    $config = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        deploy_webhook_respond(500, ['ok' => false, 'error' => 'config file is not readable']);
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }

        $key = $m[1];
        $value = trim($m[2]);
        $len = strlen($value);
        if ($len >= 2) {
            $quote = $value[0];
            if (($quote === "'" || $quote === '"') && $value[$len - 1] === $quote) {
                $value = substr($value, 1, -1);
            }
        }
        $config[$key] = $value;
    }

    return $config;
}

function deploy_webhook_config_value(array $config, string $key, string $default = ''): string
{
    $env = getenv($key);
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return isset($config[$key]) && is_string($config[$key]) ? $config[$key] : $default;
}

function deploy_webhook_load_backend_config(string $projectRoot): array
{
    try {
        if (!defined('BP')) {
            define('BP', rtrim($projectRoot, "\\/") . DIRECTORY_SEPARATOR);
        }
        require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';
        require_once BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . 'functions.php';

        /** @var \Weline\Deploy\Service\DeployConfigService $deployConfigService */
        $deployConfigService = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Deploy\Service\DeployConfigService::class);
        return $deployConfigService->getWebhookShellConfig();
    } catch (\Throwable) {
        return [];
    }
}

function deploy_webhook_shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function deploy_webhook_write_runtime_config(array $config): string
{
    $file = tempnam(sys_get_temp_dir(), 'weline-deploy-webhook-');
    if ($file === false) {
        deploy_webhook_respond(500, ['ok' => false, 'error' => 'cannot create runtime config']);
    }

    $lines = [];
    foreach ($config as $key => $value) {
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', (string)$key)) {
            continue;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            $lines[] = $key . '=' . deploy_webhook_shell_quote((string)$value);
        }
    }

    file_put_contents($file, implode("\n", $lines) . "\n");
    @chmod($file, 0600);
    return $file;
}

function deploy_webhook_is_valid_token(string $secret, string $rawBody): bool
{
    $giteeToken = deploy_webhook_header('X-Gitee-Token');
    $giteeTimestamp = deploy_webhook_header('X-Gitee-Timestamp');
    if ($giteeToken !== '' && $giteeTimestamp !== '') {
        $computed = base64_encode(hash_hmac('sha256', $giteeTimestamp . "\n" . $secret, $secret, true));
        if (hash_equals($computed, $giteeToken)) {
            return true;
        }
    }
    if ($giteeToken !== '' && hash_equals($secret, $giteeToken)) {
        return true;
    }

    $githubSignature = deploy_webhook_header('X-Hub-Signature-256');
    if (str_starts_with($githubSignature, 'sha256=')) {
        $computed = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (hash_equals($computed, $githubSignature)) {
            return true;
        }
    }

    $gitlabToken = deploy_webhook_header('X-Gitlab-Token');
    if ($gitlabToken !== '' && hash_equals($secret, $gitlabToken)) {
        return true;
    }

    $authorization = deploy_webhook_header('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) === 1 && hash_equals($secret, $m[1])) {
        return true;
    }

    $queryToken = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    return $queryToken !== '' && hash_equals($secret, $queryToken);
}

function deploy_webhook_redact(string $line, array $secrets): string
{
    foreach ($secrets as $secret) {
        if ($secret !== '') {
            $line = str_replace($secret, '[redacted]', $line);
        }
    }

    return $line;
}

if (ob_get_level()) {
    ob_end_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['health'])) {
    deploy_webhook_respond(200, ['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_webhook_respond(405, ['ok' => false, 'error' => 'only POST is allowed']);
}

$projectRoot = dirname(__DIR__, 2);
$configFile = getenv('DEPLOY_CONFIG_FILE');
if (!is_string($configFile) || $configFile === '') {
    $configFile = isset($_SERVER['DEPLOY_CONFIG_FILE']) && is_string($_SERVER['DEPLOY_CONFIG_FILE'])
        ? $_SERVER['DEPLOY_CONFIG_FILE']
        : '';
}
$configFile = $configFile !== '' ? $configFile : __DIR__ . '/.config';
$config = deploy_webhook_parse_config($configFile);
$config = array_merge($config, deploy_webhook_load_backend_config($projectRoot));

$secret = deploy_webhook_config_value($config, 'WEBHOOK_SECRET');
if ($secret === '') {
    deploy_webhook_respond(500, ['ok' => false, 'error' => 'WEBHOOK_SECRET is empty']);
}

$rawBody = file_get_contents('php://input');
$rawBody = is_string($rawBody) ? $rawBody : '';
if (!deploy_webhook_is_valid_token($secret, $rawBody)) {
    deploy_webhook_respond(403, ['ok' => false, 'error' => 'invalid webhook token']);
}

$branch = deploy_webhook_config_value($config, 'WEBHOOK_BRANCH', deploy_webhook_config_value($config, 'GIT_BRANCH'));
if ($branch !== '') {
    $payload = json_decode($rawBody, true);
    $ref = is_array($payload) && isset($payload['ref']) ? (string) $payload['ref'] : '';
    if ($ref !== '' && $ref !== $branch && $ref !== 'refs/heads/' . $branch) {
        deploy_webhook_respond(202, [
            'ok' => true,
            'skipped' => true,
            'reason' => 'branch mismatch',
            'ref' => $ref,
        ]);
    }
}

$script = __DIR__ . '/webhook.sh';
if (!is_file($script)) {
    deploy_webhook_respond(500, ['ok' => false, 'error' => 'webhook.sh not found']);
}

if (!function_exists('exec')) {
    deploy_webhook_respond(500, ['ok' => false, 'error' => 'PHP exec() is disabled']);
}

$bash = deploy_webhook_config_value($config, 'WEBHOOK_BASH', 'bash');
$runtimeConfigFile = deploy_webhook_write_runtime_config($config);
putenv('DEPLOY_CONFIG_FILE=' . $runtimeConfigFile);

$cmd = escapeshellarg($bash) . ' ' . escapeshellarg($script) . ' deploy --from-webhook 2>&1';
$output = [];
$exitCode = 1;
exec($cmd, $output, $exitCode);
@unlink($runtimeConfigFile);

$secrets = [
    $secret,
    deploy_webhook_config_value($config, 'GIT_REMOTE_URL'),
    deploy_webhook_config_value($config, 'CLOUDFLARE_API_TOKEN'),
];
$tail = array_map(static fn(string $line): string => deploy_webhook_redact($line, $secrets), array_slice($output, -20));

deploy_webhook_respond($exitCode === 0 ? 200 : 500, [
    'ok' => $exitCode === 0,
    'exit_code' => $exitCode,
    'output_tail' => $tail,
]);
