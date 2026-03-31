<?php
declare(strict_types=1);

$rootDir = \dirname(__DIR__, 3);
$envPath = $rootDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';

if (!\is_file($envPath)) {
    \fwrite(STDERR, "Missing env config: {$envPath}\n");
    exit(1);
}

/** @var array<string, mixed> $env */
$env = require $envPath;

function normalizeHost(string $host): string
{
    $host = \trim($host);
    if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '::0') {
        return '127.0.0.1';
    }

    return $host;
}

function buildOrigin(string $scheme, string $host, int $port): string
{
    $host = normalizeHost($host);
    $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

    return $isDefaultPort ? "{$scheme}://{$host}" : "{$scheme}://{$host}:{$port}";
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>|null
 */
function getDefaultDbProfile(array $env): ?array
{
    $db = (array)($env['db'] ?? []);
    $default = (string)($db['default'] ?? '');
    if ($default === '') {
        return null;
    }

    $profile = (array)($db[$default] ?? []);
    return $profile === [] ? null : $profile;
}

function createDbConnection(?array $profile): ?\PDO
{
    if (!$profile) {
        return null;
    }

    $type = \strtolower((string)($profile['type'] ?? ''));
    $username = (string)($profile['username'] ?? $profile['user'] ?? '');
    $password = (string)($profile['password'] ?? $profile['pass'] ?? '');

    if ($type === 'pgsql') {
        $host = normalizeHost((string)($profile['hostname'] ?? $profile['host'] ?? '127.0.0.1'));
        $port = (int)($profile['hostport'] ?? $profile['port'] ?? 5432);
        $database = (string)($profile['database'] ?? $profile['dbname'] ?? '');
        if ($database === '') {
            return null;
        }

        return new \PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    if ($type === 'mysql') {
        $host = normalizeHost((string)($profile['hostname'] ?? $profile['host'] ?? '127.0.0.1'));
        $port = (int)($profile['hostport'] ?? $profile['port'] ?? 3306);
        $database = (string)($profile['database'] ?? $profile['dbname'] ?? '');
        if ($database === '') {
            return null;
        }

        $charset = (string)($profile['charset'] ?? 'utf8mb4');

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            $username,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    if ($type === 'sqlite') {
        $path = (string)($profile['path'] ?? $profile['database'] ?? '');
        if ($path === '') {
            return null;
        }

        return new \PDO(
            "sqlite:{$path}",
            null,
            null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }

    return null;
}

function quoteSqlIdentifier(string $identifier, string $driver): string
{
    $escaped = \str_replace(
        $driver === 'mysql' ? '`' : '"',
        $driver === 'mysql' ? '``' : '""',
        $identifier
    );

    return $driver === 'mysql' ? "`{$escaped}`" : "\"{$escaped}\"";
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function readActiveThemes(array $env): array
{
    $profile = getDefaultDbProfile($env);
    $type = \strtolower((string)($profile['type'] ?? ''));
    $prefix = (string)($profile['prefix'] ?? '');
    $result = [
        'source' => 'unavailable',
        'driver' => $type,
        'table_prefix' => $prefix,
        'active' => [
            'global' => null,
            'frontend' => null,
            'backend' => null,
        ],
    ];

    if (!$profile || $type === '') {
        return $result;
    }

    try {
        $pdo = createDbConnection($profile);
        if (!$pdo) {
            return $result;
        }

        $tableName = quoteSqlIdentifier($prefix . 'weline_theme', $type);
        $supportsAreaFlags = true;

        try {
            $sql = "SELECT id, name, path, is_active, is_active_frontend, is_active_backend FROM {$tableName} WHERE is_active = 1 OR is_active_frontend = 1 OR is_active_backend = 1 ORDER BY id ASC";
            $rows = $pdo->query($sql)->fetchAll();
        } catch (\Throwable) {
            $supportsAreaFlags = false;
            $fallbackSql = "SELECT id, name, path, is_active FROM {$tableName} WHERE is_active = 1 ORDER BY id ASC";
            $rows = $pdo->query($fallbackSql)->fetchAll();
        }

        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $theme = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'path' => (string)($row['path'] ?? ''),
                'is_active' => (int)($row['is_active'] ?? 0),
                'is_active_frontend' => $supportsAreaFlags ? (int)($row['is_active_frontend'] ?? 0) : (int)($row['is_active'] ?? 0),
                'is_active_backend' => $supportsAreaFlags ? (int)($row['is_active_backend'] ?? 0) : (int)($row['is_active'] ?? 0),
            ];

            if ($theme['is_active'] === 1) {
                $result['active']['global'] = $theme;
            }
            if ($theme['is_active_frontend'] === 1) {
                $result['active']['frontend'] = $theme;
            }
            if ($theme['is_active_backend'] === 1) {
                $result['active']['backend'] = $theme;
            }
        }

        if ($result['active']['frontend'] === null) {
            $result['active']['frontend'] = $result['active']['global'];
        }
        if ($result['active']['backend'] === null) {
            $result['active']['backend'] = $result['active']['global'];
        }

        $result['source'] = 'database';
    } catch (\Throwable $throwable) {
        $result['source'] = 'database_error';
        $result['error'] = $throwable->getMessage();
    }

    return $result;
}

/**
 * @return array<string, mixed>|null
 */
function readJsonFile(string $path): ?array
{
    if (!\is_file($path)) {
        return null;
    }

    for ($attempt = 0; $attempt < 6; $attempt++) {
        \clearstatcache(true, $path);
        $content = @\file_get_contents($path);
        if (\is_string($content) && \trim($content) !== '') {
            $decoded = \json_decode($content, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        if ($attempt < 5) {
            \usleep(50000);
        }
    }

    return null;
}

function isReachableEndpoint(string $host, int $port, float $timeoutSeconds = 2.0): bool
{
    if ($port <= 0) {
        return false;
    }

    $errno = 0;
    $error = '';
    $socket = @\fsockopen(normalizeHost($host), $port, $errno, $error, $timeoutSeconds);
    if (!\is_resource($socket)) {
        return false;
    }

    \fclose($socket);
    return true;
}

/**
 * Windows / busy-loop 场景下单次探测可能误判；短重试降低 E2E worker 与 PHP 子进程之间的抖动。
 */
function isReachableEndpointWithRetry(string $host, int $port, int $attempts = 5, float $timeoutSeconds = 2.0): bool
{
    for ($i = 0; $i < $attempts; $i++) {
        if (isReachableEndpoint($host, $port, $timeoutSeconds)) {
            return true;
        }
        if ($i < $attempts - 1) {
            \usleep(200000);
        }
    }

    return false;
}

function resolveInstancePort(array $instance): int
{
    foreach (['main_port', 'dispatcher_port', 'port', 'worker_port'] as $key) {
        $port = (int)($instance[$key] ?? 0);
        if ($port > 0) {
            return $port;
        }
    }

    $dispatcher = (array)(((array)($instance['services'] ?? []))['dispatcher'] ?? []);
    foreach ((array)($dispatcher['instances'] ?? []) as $serviceInstance) {
        $port = (int)(((array)$serviceInstance)['port'] ?? 0);
        if ($port > 0) {
            return $port;
        }
    }

    return 0;
}

function isUsableInstance(?array $instance): bool
{
    if (!$instance) {
        return false;
    }

    return resolveInstancePort($instance) > 0;
}

/**
 * @return array<string, mixed>|null
 */
function findPreferredConfig(string $rootDir, string $instanceName): ?array
{
    $configPath = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $instanceName . '.json';
    $config = readJsonFile($configPath);

    return \is_array($config) ? $config : null;
}

/**
 * @return array<string, mixed>|null
 */
function findLatestConfig(string $rootDir): ?array
{
    $configDir = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'config';
    if (!\is_dir($configDir)) {
        return null;
    }

    $candidates = [];
    foreach ((array)\glob($configDir . DIRECTORY_SEPARATOR . '*.json') as $configPath) {
        $config = readJsonFile($configPath);
        if (!\is_array($config)) {
            continue;
        }

        $config['__path'] = $configPath;
        $candidates[] = $config;
    }

    if ($candidates === []) {
        return null;
    }

    \usort(
        $candidates,
        static function (array $left, array $right): int {
            $leftTs = (int)\strtotime((string)($left['saved_at'] ?? ''));
            $rightTs = (int)\strtotime((string)($right['saved_at'] ?? ''));
            return $rightTs <=> $leftTs;
        }
    );

    return $candidates[0];
}

/**
 * @return array{scheme:string,host:string,port:int,ssl_enabled:bool}|null
 */
function resolveConfigTarget(array $config): ?array
{
    $port = (int)($config['port'] ?? 0);
    if ($port <= 0) {
        return null;
    }

    $host = normalizeHost((string)($config['host'] ?? '127.0.0.1'));
    $scheme = \strtolower((string)($config['scheme'] ?? ''));
    $sslEnabled = false;

    if ($scheme === 'https') {
        $sslEnabled = true;
    } elseif ($scheme === 'http') {
        $sslEnabled = false;
    } else {
        $sslEnabled = !empty($config['ssl_cert']) && !empty($config['ssl_key']);
    }

    return [
        'scheme' => $sslEnabled ? 'https' : 'http',
        'host' => $host,
        'port' => $port,
        'ssl_enabled' => $sslEnabled,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function findPreferredInstance(string $rootDir, string $instanceName): ?array
{
    $instancePath = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
    $instance = readJsonFile($instancePath);
    return isUsableInstance($instance) ? $instance : null;
}

/**
 * @return array<string, mixed>|null
 */
function findLatestUsableInstance(string $rootDir): ?array
{
    $instanceDir = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
    if (!\is_dir($instanceDir)) {
        return null;
    }

    $candidates = [];
    foreach ((array)\glob($instanceDir . DIRECTORY_SEPARATOR . '*.json') as $instancePath) {
        $instance = readJsonFile($instancePath);
        if (!isUsableInstance($instance)) {
            continue;
        }

        $instance['__path'] = $instancePath;
        $candidates[] = $instance;
    }

    if ($candidates === []) {
        return null;
    }

    \usort(
        $candidates,
        static function (array $left, array $right): int {
            $leftTs = (int)($left['started_timestamp'] ?? 0);
            $rightTs = (int)($right['started_timestamp'] ?? 0);
            return $rightTs <=> $leftTs;
        }
    );

    return $candidates[0];
}

$instanceName = (string)(\getenv('PLAYWRIGHT_INSTANCE_NAME') ?: 'default');
$instance = findPreferredInstance($rootDir, $instanceName) ?? findLatestUsableInstance($rootDir);
$config = findPreferredConfig($rootDir, $instanceName) ?? findLatestConfig($rootDir);

$overrideOrigin = \trim((string)(\getenv('PLAYWRIGHT_TARGET_ORIGIN') ?: ''));
if ($overrideOrigin === '') {
    $e2eTargetFile = $rootDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'e2e' . DIRECTORY_SEPARATOR . '.weline-e2e-target-origin';
    if (\is_file($e2eTargetFile)) {
        $fromFile = \trim((string)@\file_get_contents($e2eTargetFile));
        if ($fromFile !== '') {
            $overrideOrigin = $fromFile;
        }
    }
}
$targetSource = 'fallback';
$targetScheme = 'http';
$targetHost = '127.0.0.1';
$targetPort = 9981;
$cliServer = (array)($env['cli_server'] ?? []);
$cliScheme = (string)($cliServer['scheme'] ?? 'http');
$cliHost = normalizeHost((string)($cliServer['host'] ?? '127.0.0.1'));
$cliPort = (int)($cliServer['port'] ?? 9981);
$targetReachable = false;

if ($overrideOrigin !== '') {
    $parsed = \parse_url($overrideOrigin);
    if (\is_array($parsed) && isset($parsed['host'])) {
        $targetScheme = (string)($parsed['scheme'] ?? 'http');
        $targetHost = normalizeHost((string)$parsed['host']);
        $targetPort = (int)($parsed['port'] ?? ($targetScheme === 'https' ? 443 : 80));
        $targetSource = 'override';
        $targetReachable = isReachableEndpointWithRetry($targetHost, $targetPort);
    }
} else {
    $instanceScheme = $instance && !empty($instance['ssl_enabled']) ? 'https' : 'http';
    $instanceHost = normalizeHost((string)($instance['host'] ?? '127.0.0.1'));
    $instancePort = $instance ? resolveInstancePort($instance) : 0;
    if ($instancePort <= 0) {
        $instancePort = $instanceScheme === 'https' ? 443 : 80;
    }
    $instanceReachable = $instance !== null && isReachableEndpointWithRetry($instanceHost, $instancePort);

    $configTarget = \is_array($config) ? resolveConfigTarget($config) : null;
    $configReachable = $configTarget !== null && isReachableEndpointWithRetry($configTarget['host'], $configTarget['port']);
    $cliReachable = isReachableEndpointWithRetry($cliHost, $cliPort);

    // 当 var/server/instances 下存在可用实例元数据时，始终使用该实例推导的 origin。
    // 避免因端口探测瞬时失败而回落到 cli_server，导致 Playwright 主进程与 worker 之间 target_origin 不一致（https:443 ↔ http:9981）。
    if ($instance !== null) {
        $targetScheme = $instanceScheme;
        $targetHost = $instanceHost;
        $targetPort = $instancePort;
        $targetSource = $instanceReachable ? 'wls_instance' : 'wls_instance_unreachable';
        $targetReachable = $instanceReachable;
    } elseif ($configTarget !== null && $configReachable) {
        $targetScheme = $configTarget['scheme'];
        $targetHost = $configTarget['host'];
        $targetPort = $configTarget['port'];
        $targetSource = 'wls_config';
        $targetReachable = true;
    } elseif ($cliReachable) {
        $targetScheme = $cliScheme;
        $targetHost = $cliHost;
        $targetPort = $cliPort;
        $targetSource = 'cli_server';
        $targetReachable = true;
    } elseif ($configTarget !== null) {
        $targetScheme = $configTarget['scheme'];
        $targetHost = $configTarget['host'];
        $targetPort = $configTarget['port'];
        $targetSource = 'wls_config_unreachable';
        $targetReachable = false;
    } else {
        $targetScheme = $cliScheme;
        $targetHost = $cliHost;
        $targetPort = $cliPort;
        $targetSource = 'cli_server';
        $targetReachable = false;
    }
}

// Primary discovery points at WLS/HTTPS that is not listening (common local dev): use reachable PHP cli_server from app/etc/env.php if up.
if (!$targetReachable && isReachableEndpoint($cliHost, $cliPort)) {
    $targetScheme = $cliScheme;
    $targetHost = $cliHost;
    $targetPort = $cliPort;
    $targetSource = 'cli_server_fallback';
    $targetReachable = true;
}

$areaRoutes = (array)(($env['router'] ?? [])['area_routes'] ?? []);
$backendPrefix = (string)(($areaRoutes['backend'] ?? [])['prefix'] ?? 'admin');
$frontendApiPrefix = (string)(($areaRoutes['rest_frontend'] ?? [])['prefix'] ?? 'api');
$backendApiPrefix = (string)(($areaRoutes['rest_backend'] ?? [])['prefix'] ?? 'admin-api');

$proxyHost = normalizeHost((string)(\getenv('PLAYWRIGHT_PROXY_HOST') ?: '127.0.0.1'));
$proxyPort = (int)(\getenv('PLAYWRIGHT_PROXY_PORT') ?: 3999);
$defaultCertDir = $rootDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'localhost';
$proxyCertPath = (string)(\getenv('PLAYWRIGHT_PROXY_CERT') ?: $defaultCertDir . DIRECTORY_SEPARATOR . 'fullchain.pem');
$proxyKeyPath = (string)(\getenv('PLAYWRIGHT_PROXY_KEY') ?: $defaultCertDir . DIRECTORY_SEPARATOR . 'privkey.pem');
$proxyScheme = (string)(\getenv('PLAYWRIGHT_PROXY_SCHEME') ?: '');
if ($proxyScheme === '') {
    $proxyScheme = (\is_file($proxyCertPath) && \is_file($proxyKeyPath)) ? 'https' : 'http';
}

$themes = readActiveThemes($env);

/**
 * 从 app/etc/modules.php 收集各模块前台/后台路由键（与模块 register 生成结果一致）。
 *
 * @return array<string, array{router: string, backend_router: string}>
 */
function collectModuleRouters(string $rootDir): array
{
    $modulesFile = $rootDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'modules.php';
    if (!\is_file($modulesFile)) {
        return [];
    }
    /** @var mixed $list */
    $list = require $modulesFile;
    if (!\is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $name => $meta) {
        if (!\is_string($name) || $name === '' || !\is_array($meta)) {
            continue;
        }
        $out[$name] = [
            'router' => (string)($meta['router'] ?? ''),
            'backend_router' => (string)($meta['backend_router'] ?? ''),
        ];
    }

    return $out;
}

$moduleRouters = collectModuleRouters($rootDir);

$data = [
    'generated_at' => \date('c'),
    'root_dir' => $rootDir,
    'runtime' => [
        'source' => $targetSource,
        'instance_name' => $instance['name'] ?? $instanceName,
        'target_origin' => buildOrigin($targetScheme, $targetHost, $targetPort),
        'target_scheme' => $targetScheme,
        'target_host' => $targetHost,
        'target_port' => $targetPort,
        'ssl_enabled' => $targetScheme === 'https',
        'reachable' => $targetReachable,
        'cli_origin' => buildOrigin(
            $cliScheme,
            $cliHost,
            $cliPort
        ),
    ],
    'routes' => [
        'backend' => $backendPrefix,
        'rest_frontend' => $frontendApiPrefix,
        'rest_backend' => $backendApiPrefix,
    ],
    'paths' => [
        'backend_prefix_path' => '/' . $backendPrefix,
        'backend_login_path' => '/' . $backendPrefix . '/admin/login',
        'frontend_api_prefix_path' => '/' . $frontendApiPrefix,
        'backend_api_prefix_path' => '/' . $backendApiPrefix,
    ],
    'proxy' => [
        'origin' => buildOrigin($proxyScheme, $proxyHost, $proxyPort),
        'scheme' => $proxyScheme,
        'host' => $proxyHost,
        'port' => $proxyPort,
        'cert_path' => $proxyCertPath,
        'key_path' => $proxyKeyPath,
    ],
    'themes' => $themes,
    'modules' => [
        'routers' => $moduleRouters,
    ],
];

echo \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
