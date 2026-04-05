<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 * 
 * 该 Worker 进程集成框架路由，支持完整的 HTTPS 请求处理
 * 包含健康检查接口 /_wls/health（仅本地访问）
 * 维护模式由框架自动处理
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 设置内存限制为 256M（推荐值）
@\ini_set('memory_limit', '256M');

// 解析命令行参数
$processName = '';
$isFrontend = false;
$useReusePort = false;  // 是否使用 SO_REUSEPORT（Linux 直连模式）
$deferSsl = false;      // 延迟 SSL 模式（用于 TCP 透传架构，先接受 TCP 连接，再手动启用 SSL）
                        // 注意：延迟 SSL 仅改变握手时机，不消除 TLS 问题。Windows 下若出现 TLS reset，
                        // 可改用 --no-ssl 或 wls.https=false 做 HTTP 验证；或安装 event 扩展后再测 HTTPS。
$wlsLoopDriver = 'auto';
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';

// 先提取位置参数（跳过以 -- 开头的参数）
$positionalArgs = [];
foreach ($argv as $i => $arg) {
    if ($i === 0) continue; // 跳过脚本名
    if (!\str_starts_with($arg, '--') && !\str_starts_with($arg, '-')) {
        $positionalArgs[] = $arg;
    }
}

$host = $positionalArgs[0] ?? '127.0.0.1';
$port = (int) ($positionalArgs[1] ?? 9981);
$workerId = (int) ($positionalArgs[2] ?? 1);
$instanceName = $positionalArgs[3] ?? 'default';
$sslCert = $positionalArgs[4] ?? '';
$sslKey = $positionalArgs[5] ?? '';

// 解析命名参数
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
    } elseif ($arg === '--defer-ssl' || $arg === '-defer-ssl') {
        $deferSsl = true;
    } elseif (\str_starts_with($arg, '--host=')) {
        $host = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--port=')) {
        $port = (int)\substr($arg, 7);
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif ($arg === '--maintenance') {
        $isMaintenanceWorker = true;
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--ssl-cert=')) {
        $sslCert = \substr($arg, 11);
    } elseif (\str_starts_with($arg, '--ssl-key=')) {
        $sslKey = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--wls-loop-driver=')) {
        $wlsLoopDriver = (string)\substr($arg, 18);
    }
}

// IPC 控制端口（未显式传入时从实例文件推算）
if (!isset($controlPort)) {
    $controlPort = 0;
}
// Master PID（用于孤儿检测）
if (!isset($masterPid) || $masterPid <= 0) {
    $masterPid = 0;
}
// 是否为维护 Worker
if (!isset($isMaintenanceWorker)) {
    $isMaintenanceWorker = false;
}

// 检测根目录
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;

// 将相对路径转换为绝对路径
if ($sslCert && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslCert)) {
    $sslCert = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCert);
}
if ($sslKey && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslKey)) {
    $sslKey = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslKey);
}

if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// 定义前端模式常量（供 WlsRuntime 使用）
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

// 预读 env.php 判断开发模式（在框架初始化前定义，供 WlsRequest 等使用）
$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsDevMode = ($_wlsEnvConfig['deploy'] ?? '') === 'dev';
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', $_wlsDevMode);
}
unset($_wlsEnvFile, $_wlsEnvConfig, $_wlsDevMode);

// 统一自动加载：app/code 优先于 vendor（与 app/bootstrap.php 共用 app/autoload.php）
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';
(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

$processTag = 'WorkerSSL#' . $workerId . ':' . $port . '@' . $instanceName;

ErrorBootstrap::init($processTag, [
    'worker_id' => $workerId,
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
    'is_maintenance' => $isMaintenanceWorker,
    'ssl' => true,
]);

// ========== 进程日志文件（持久化，跨重启保留） ==========
// Worker 自身负责将错误和关键日志写入 var/process/{processName}.log
// 确保即使 Windows 隐藏窗口或 Linux 重定向丢失，日志也不会丢
$processLogFile = '';
if ($processName) {
    $processLogFile = \Weline\Server\Service\WlsLogService::getProcessLogFile($processName, $instanceName, $processTag);
    $processLogDir = \dirname($processLogFile);
    if (!\is_dir($processLogDir)) {
        @\mkdir($processLogDir, 0777, true);
    }
    // 将 PHP error_log() 重定向到进程日志文件（追加模式）
    \ini_set('error_log', $processLogFile);
}

// 预先读取 env.php 中的 deploy 配置（备用方案，用于在 App::init() 之前检测 DEV 模式）
$envConfig = null;
$envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($envFile)) {
    $envConfig = @include $envFile;
}
$envConfig = \is_array($envConfig) ? $envConfig : [];
$sharedStateRuntime = \Weline\Server\Service\SharedStateRuntimeOptions::fromCliArgs($argv, $instanceName, $envConfig);
$envOverrides = $sharedStateRuntime->toEnvOverrides();
$envConfig = \array_replace_recursive($envConfig, $envOverrides);
\Weline\Framework\App\Env::getInstance()->applyRuntimeConfig($envOverrides);
$sessionRuntime = $sharedStateRuntime->getSession();
$memoryRuntime = $sharedStateRuntime->getMemory();
$envLoopDriver = (string) (($envConfig['wls']['loop']['driver'] ?? 'auto'));
$wlsLoopDriver = $wlsLoopDriver !== '' ? $wlsLoopDriver : $envLoopDriver;
$wlsLoopDriver = \Weline\Server\EventLoop\EventLoopFactory::normalizeDriver($wlsLoopDriver);

// 所有 Worker 都启用 stdout 输出（便于调试和监控）
WlsLogger::getInstance()
    ->setStdoutEnabled(true)
    ->setProcessTag($processTag);

// Origin Token 回源校验配置（可选安全增强）
$originToken = '';
$originTokenValidationEnabled = false;
$originTokenHeader = 'X-Weline-Origin-Token';
$originTokenAllowLocal = true;
if ($envConfig !== []) {
    $wlsEnv = $envConfig['wls'] ?? [];
    $originToken = (string)($wlsEnv['origin_token'] ?? '');
    $originValidationConfig = $wlsEnv['origin_token_validation'] ?? [];
    if (\is_array($originValidationConfig)) {
        $originTokenValidationEnabled = (bool)($originValidationConfig['enabled'] ?? false);
        $originTokenHeader = (string)($originValidationConfig['header'] ?? $originTokenHeader);
        $originTokenAllowLocal = (bool)($originValidationConfig['allow_local'] ?? true);
    }
}

/**
 * 从 ssl_certificate_map.json 加载 SNI 证书映射。
 * 在 Worker 启动时调用一次，并在收到 ssl_cert_reload IPC 命令时再次调用以热更新。
 *
 * @return array<string, array{local_cert: string, local_pk: string}>
 */
function _loadSniCertsFromMap(): array
{
    global $_domainPolicies;
    $mapFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'ssl_certificate_map.json';
    if (!\is_file($mapFile)) {
        return [];
    }
    try {
        \clearstatcache(true, $mapFile);
        $raw = (string)@\file_get_contents($mapFile);
        $map = \json_decode($raw, true);
        if (!\is_array($map)) {
            return [];
        }
        $certs = [];
        $policies = [];
        foreach ($map as $domain => $pair) {
            $certPath = (string)($pair['cert'] ?? '');
            $keyPath = (string)($pair['key'] ?? '');
            if ($domain !== '' && $certPath !== '' && $keyPath !== '' && \is_file($certPath) && \is_file($keyPath)) {
                $certs[(string)$domain] = [
                    'local_cert' => $certPath,
                    'local_pk' => $keyPath,
                ];
            }
            if ($domain !== '') {
                $policies[(string)$domain] = [
                    'force_https' => (int) ($pair['force_https'] ?? 1),
                    'force_root_to_www' => (int) ($pair['force_root_to_www'] ?? 0),
                ];
            }
        }
        $_domainPolicies = $policies;
        return $certs;
    } catch (\Throwable) {
        return [];
    }
}

/**
 * 获取指定域名的重定向策略。
 *
 * @return array{force_https: int, force_root_to_www: int}
 */
function _getDomainPolicy(string $domain): array
{
    global $_domainPolicies;
    return $_domainPolicies[$domain] ?? ['force_https' => 1, 'force_root_to_www' => 0];
}

/**
 * 开发域：子域目录无证书时，复用磁盘上 `*.weline.local` 通配一盘（目录名与 SslCertificateService 一致，Windows 为 _wildcard_.weline.local）。
 * 仅匹配「单标签主机名」如 p11005ce4.weline.local（标准 *.weline.local 覆盖范围）。
 *
 * @return array{local_cert: string, local_pk: string}|null
 */
function _resolveWelineLocalWildcardCertFromDisk(string $host): ?array
{
    $host = \strtolower(\trim($host));
    if ($host === '' || $host === 'weline.local' || !\str_ends_with($host, '.weline.local')) {
        return null;
    }
    if (\count(\explode('.', $host)) !== 3) {
        return null;
    }

    $segment = \Weline\Server\Service\SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.local');
    $certDir = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR;
    $certFile = $certDir . 'fullchain.pem';
    $keyFile = $certDir . 'privkey.pem';
    if (!\is_file($certFile) || !\is_file($keyFile)) {
        return null;
    }

    return ['local_cert' => $certFile, 'local_pk' => $keyFile];
}

/**
 * 为指定域名解析 SSL 证书。这是证书选择的唯一入口。
 *
 * 解析顺序：
 *  1. 进程内存缓存（$sniServerCerts，由 IPC ssl_cert_reload 维护）
 *  2. 磁盘证书目录（app/etc/ssl/{domain}/fullchain.pem + privkey.pem）
 *  3. 数据库回退：从证书管理表查询并恢复完整文件到磁盘
 *  4. 开发域 weline.local：仅有通配一盘时，子域复用 *.weline.local 目录
 *
 * 命中后自动写入内存缓存，后续同域名请求零开销。
 *
 * @param string $domain 小写域名
 * @param array<string, array{local_cert: string, local_pk: string}> &$cache 进程级缓存（引用传递）
 * @return array{local_cert: string, local_pk: string}|null
 */
function _resolveSniCert(string $domain, array &$cache): ?array
{
    // 1. 内存缓存命中
    if (isset($cache[$domain])) {
        return $cache[$domain];
    }

    // 2. 磁盘证书目录（Windows 下泛域目录名为 _wildcard_.，与 SslCertificateService 一致）
    $segment = \Weline\Server\Service\SslCertificateService::certificateStorageSegmentForFilesystem($domain);
    $certDir = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR;
    $certFile = $certDir . 'fullchain.pem';
    $keyFile = $certDir . 'privkey.pem';
    if (\is_file($certFile) && \is_file($keyFile)) {
        $entry = ['local_cert' => $certFile, 'local_pk' => $keyFile];
        $cache[$domain] = $entry;
        return $entry;
    }

    // 3. 数据库回退：磁盘无证书时从 DB 恢复
    $restored = _restoreCertFromDb($domain);
    if ($restored) {
        \clearstatcache(true, $certFile);
        \clearstatcache(true, $keyFile);
        if (\is_file($certFile) && \is_file($keyFile)) {
            $entry = ['local_cert' => $certFile, 'local_pk' => $keyFile];
            $cache[$domain] = $entry;
            WlsLogger::info_("证书已从数据库恢复到磁盘：{$domain}");
            return $entry;
        }
    }

    // 4. 仅有 *.weline.local 通配一盘、未写入各子域目录时的兜底（map 未列全子域亦可握手）
    $wildcardEntry = _resolveWelineLocalWildcardCertFromDisk($domain);
    if ($wildcardEntry !== null) {
        $cache[$domain] = $wildcardEntry;
        return $wildcardEntry;
    }

    return null;
}

/**
 * 清除指定域名的 DB 回退负缓存（negative cache）。
 *
 * 当 SSL 证书申请成功后，通过 IPC 通知 Worker 清除该域名的负缓存，
 * 下次请求到来时 _restoreCertFromDb 会重新尝试从数据库恢复证书。
 *
 * @param string[] $domains 要清除的域名列表；空数组 = 清除全部负缓存。
 */
function _clearRestoreCertNegativeCache(array $domains): void
{
    static $attempted = [];  // 与 _restoreCertFromDb 共享同一 static 变量（同文件作用域中不存在，需通过引用传递）
    // PHP static 变量在同一进程内按函数隔离，此处须通过注入方式清除。
    // 实现方案：用一个全局的引用数组代替函数内 static，见下方 $_wlsRestoreAttempted。
    global $_wlsRestoreAttempted;
    if (empty($domains)) {
        $_wlsRestoreAttempted = [];
    } else {
        foreach ($domains as $d) {
            unset($_wlsRestoreAttempted[$d]);
        }
    }
}

/**
 * 从证书管理表恢复域名证书文件到磁盘（全部 6 个文件）。
 * 仅在 _resolveSniCert 磁盘未命中时调用，避免每次请求都查库。
 */
function _restoreCertFromDb(string $domain): bool
{
    global $_domainPolicies, $_wlsRestoreAttempted;
    if (isset($_wlsRestoreAttempted[$domain])) {
        return false;
    }
    $_wlsRestoreAttempted[$domain] = true;

    try {
        /** @var \Weline\Server\Service\SslCertificateService $sslService */
        $sslService = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Server\Service\SslCertificateService::class
        );
        $restored = $sslService->restoreCertificateFromDb($domain);
        if ($restored) {
            $certModel = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Server\Model\SslCertificate::class, [], false
            );
            $certModel->clearQuery()->loadByDomain($domain);
            if ($certModel->getCertId()) {
                $_domainPolicies[$domain] = [
                    'force_https' => $certModel->getForceHttps() ? 1 : 0,
                    'force_root_to_www' => $certModel->getForceRootToWww() ? 1 : 0,
                ];
            }
        }
        return $restored;
    } catch (\Throwable $e) {
        WlsLogger::warning_("证书数据库恢复失败：{$domain} - {$e->getMessage()}");
        return false;
    }
}

/**
 * 从 TLS ClientHello 数据中解析 SNI（Server Name Indication）域名。
 * 用于 defer-ssl 模式：PHP 的 SNI_server_certs 在 stream_socket_enable_crypto 时不生效，
 * 需手动解析 ClientHello 并在握手前设置对应域名的证书。
 *
 * @param string $data peek 到的原始 TCP 数据（至少需要 43+ 字节）
 * @return string|null 解析到的 SNI 主机名，失败返回 null
 */
function _parseSniHostFromClientHello(string $data): ?string
{
    $len = \strlen($data);
    // TLS record: ContentType(1) + Version(2) + Length(2) + Handshake
    // Handshake: HandshakeType(1) + Length(3) + ClientVersion(2) + Random(32) = 43 bytes minimum
    if ($len < 43 || \ord($data[0]) !== 0x16) {
        return null; // Not a TLS handshake
    }
    // Handshake type: ClientHello = 0x01
    if (\ord($data[5]) !== 0x01) {
        return null;
    }
    $pos = 43; // skip: record header(5) + handshake header(4) + client version(2) + random(32)
    if ($pos >= $len) {
        return null;
    }
    // Session ID
    $sessionIdLen = \ord($data[$pos]);
    $pos += 1 + $sessionIdLen;
    if ($pos + 2 > $len) {
        return null;
    }
    // Cipher Suites
    $cipherSuitesLen = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
    $pos += 2 + $cipherSuitesLen;
    if ($pos + 1 > $len) {
        return null;
    }
    // Compression Methods
    $compressionLen = \ord($data[$pos]);
    $pos += 1 + $compressionLen;
    if ($pos + 2 > $len) {
        return null;
    }
    // Extensions total length
    $extensionsLen = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
    $pos += 2;
    $extensionsEnd = $pos + $extensionsLen;
    if ($extensionsEnd > $len) {
        $extensionsEnd = $len;
    }
    while ($pos + 4 <= $extensionsEnd) {
        $extType = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
        $extLen = (\ord($data[$pos + 2]) << 8) | \ord($data[$pos + 3]);
        $pos += 4;
        if ($extType === 0x0000) { // SNI extension
            // Server Name List Length(2) + Name Type(1) + Name Length(2) + Name
            if ($pos + 5 <= $extensionsEnd && $pos + $extLen <= $extensionsEnd) {
                $nameType = \ord($data[$pos + 2]);
                $nameLen = (\ord($data[$pos + 3]) << 8) | \ord($data[$pos + 4]);
                if ($nameType === 0 && $pos + 5 + $nameLen <= $extensionsEnd) {
                    return \substr($data, $pos + 5, $nameLen);
                }
            }
            return null;
        }
        $pos += $extLen;
    }
    return null;
}

// 域名策略缓存（force_https / force_root_to_www），由 _loadSniCertsFromMap() 填充
$_domainPolicies = [];

// DB 回退负缓存：记录已尝试从 DB 恢复但未找到证书的域名，避免每次请求都查库。
// 通过 _clearRestoreCertNegativeCache() 可在 ssl_cert_reload IPC 中清除指定域名的记录。
$_wlsRestoreAttempted = [];

// 读取 SNI 证书映射（var/server/ssl_certificate_map.json）
// 使用 _loadSniCertsFromMap() 函数加载，后续收到 ssl_cert_reload IPC 命令时可热更新
$sniServerCerts = _loadSniCertsFromMap();

// ========== 日志系统：直接使用 WlsLogger ==========
// 检测模式（只检测一次）
$isDev = false;
if (\defined('DEV') && DEV) {
    $isDev = true;
} elseif ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev') {
    $isDev = true;
}

// 前台模式：启用控制台输出
if ($isFrontend) {
    WlsLogger::getInstance()
        ->setStdoutEnabled(true)
        ->setProcessTag($processTag);
}
// ========== 日志系统结束 ==========

// 注册 PID 到 Processer（启用快速 PID 查找）
if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

// 初始化路由提示服务（用于 TCP 透传模式下的智能路由）
\Weline\Server\Service\RouteHintService::init($port, true, 3600);

// 初始化框架运行时
$runtime = null;
$runtimeError = null;

try {
    WlsLogger::info_("Worker 启动，监听 ssl://{$host}:{$port}");
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    WlsLogger::info_("框架运行时初始化成功");

    // 共享服务提前并发 ensure：拿到最新 host/port/token，避免沿用陈旧 runtime 导致认证失败
    try {
        $sharedRuntime = (new \Weline\Server\Service\SharedStateServiceManager())->ensureRuntime(
            $instanceName,
            [],
            $envConfig,
            $isFrontend,
            false
        );
        if (\is_array($sharedRuntime['session'] ?? null)) {
            $sessionRuntime = \array_replace($sessionRuntime, $sharedRuntime['session']);
        }
        if (\is_array($sharedRuntime['memory'] ?? null)) {
            $memoryRuntime = \array_replace($memoryRuntime, $sharedRuntime['memory']);
        }
        WlsLogger::info_('[SharedState] Session/Memory 共享服务已提前并发就绪');
    } catch (\Throwable $sharedEnsureError) {
        WlsLogger::warning_('[SharedState] 提前确保共享服务失败，继续按本地 runtime 尝试: ' . $sharedEnsureError->getMessage());
    }

    // 启动后必须连上 Session 服务再开始工作，否则拒绝启动（重试 10 次，每次间隔 2 秒）
    $sessionHost = (string) ($sessionRuntime['host'] ?? '127.0.0.1');
    $sessionPort = (int) ($sessionRuntime['port'] ?? 19970);
    $sessionTokenFileName = (string) ($sessionRuntime['token_file_name'] ?? 'session_server.token');
    $memoryHost = (string) ($memoryRuntime['host'] ?? '127.0.0.1');
    $memoryPort = (int) ($memoryRuntime['port'] ?? 19971);
    $memoryTokenFileName = (string) ($memoryRuntime['token_file_name'] ?? 'memory_server.token');
    WlsLogger::info_("[Session] 开始连接 Session 服务 {$sessionHost}:{$sessionPort}，最多重试 10 次，间隔 2 秒");
    $sessionClient = new \Weline\Server\Session\Client\SessionClient($sessionHost, $sessionPort, [
        'connect_timeout' => 1.0,
        'timeout' => 2.0,
        'token_file_name' => $sessionTokenFileName,
        // 启动重试阶段允许失败但不刷屏；最终失败由下面重试总控给出明确错误。
        'log_connect_fail' => false,
    ]);
    $sessionConnected = false;
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        WlsLogger::info_("[Session] 第 {$attempt}/10 次尝试连接 Session 服务...");
        if ($sessionClient->connect()) {
            $sessionConnected = true;
            WlsLogger::info_("[Session] 第 {$attempt} 次尝试连接成功");
            break;
        }
        WlsLogger::warning_("[Session] 第 {$attempt}/10 次连接失败，2 秒后重试");
        if ($attempt < 10) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep(2);
        }
    }
    if (!$sessionConnected) {
        WlsLogger::error_("[Session] Session 服务连接失败，已重试 10 次，Worker 拒绝工作并退出");
        w_log_error("[WLS Worker SSL] Session 服务不可达 (host={$sessionHost}, port={$sessionPort})，已重试 10 次，退出");
        exit(1);
    }
    WlsLogger::info_("[Session] Session 服务连接成功，Worker 可开始接收请求");
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[32m    ✓ Session 服务连接成功\033[0m\n");
    }
    // Session/Memory 连接池预热在 Fiber 调度器初始化后执行（见下方 runWithFiberScheduler）
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    WlsLogger::error_("框架运行时初始化失败: " . $e->getMessage());
    w_log_error('[WLS Worker SSL] Bootstrap error: ' . $e->getMessage());
}

// ========== Fiber 调度器初始化（确保 SSE/长任务不阻塞主循环） ==========
$fiberScheduler = new \Weline\Server\Scheduler\FiberScheduler();
$eventLoopMeta = \Weline\Server\EventLoop\EventLoopFactory::create($wlsLoopDriver);
$eventLoop = $eventLoopMeta['loop'];
$coroutineRuntime = new \Weline\Server\Runtime\CoroutineRuntime($eventLoop, $fiberScheduler);
$asyncBizAdapters = new \Weline\Server\Runtime\Async\AsyncBizAdapters();
\Weline\Server\Observer\SchedulerWaitObserver::setScheduler($fiberScheduler);
\Weline\Framework\Runtime\SchedulerSystem::enableScheduler();
$longLivedProtocolResolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
$activeFibers = [];
\Weline\Framework\Runtime\WlsConcurrency::setOtherSuspendedFiberCountProvider(
    static function () use (&$activeFibers): int {
        return \count($activeFibers);
    }
);
// Fiber 池与长连接治理（与 worker.php 对齐，供 Master IPC 与 Dispatcher 饱和策略使用）
$fiberIdleTtlSec = 0;
$fiberMaxActive = 0;
$fiberReleaseIdleRequested = false;
$lastFiberIdleCheck = \time();
$longLivedConnections = [];
$longLivedMaxActive = 0;
$longLivedSaturationReported = false;
$longLivedSaturationCleared = false;
$lastLongLivedSaturationReport = 0;
$longLivedSaturationInterval = 10;
WlsLogger::info_("Fiber 调度器已初始化");
WlsLogger::info_(
    "EventLoop 已初始化 requested={$eventLoopMeta['requested']} resolved={$eventLoopMeta['resolved']} backend={$coroutineRuntime->getLoopBackend()}"
);

// 预热 Session/Memory 连接池：在 Fiber 内建连 + ensureMinIdle 中 yield，泵送 tick；结束后不保留额外调度状态
if ($runtimeError === null && isset($sessionHost, $sessionPort, $memoryHost, $memoryPort)) {
    try {
        $sessionOpts = ['connect_timeout' => 1.0, 'timeout' => 2.0, 'min_idle' => 1, 'max_size' => 8, 'token_file_name' => $sessionTokenFileName, 'log_connect_fail' => false];
        $memoryOpts = [
            'connect_timeout' => 1.0,
            'timeout' => 2.0,
            'min_idle' => 1,
            'max_size' => 8,
            'token_file_name' => $memoryTokenFileName,
            'service_type' => 'Memory',
            'log_connect_fail' => false,
        ];
        \Weline\Server\Shared\Connection\ConnectionPoolManager::runWithFiberScheduler($fiberScheduler, function () use ($sessionHost, $sessionPort, $sessionOpts, $memoryHost, $memoryPort, $memoryOpts): void {
            \Weline\Server\Shared\Connection\ConnectionPoolManager::getInstance($sessionHost, $sessionPort, $sessionOpts);
            \Weline\Server\Shared\Connection\ConnectionPoolManager::getInstance($memoryHost, $memoryPort, $memoryOpts);
        });
    } catch (\Throwable) {
        // 预热失败不阻塞 Worker，首请求时再建连
    }
}

// ========== WLS 内存缓存配置（智能模式） ==========
// 读取 env 配置中的 WLS 缓存配置
$wlsCacheConfig = [];
if ($envConfig !== null && isset(($envConfig['wls'] ?? [])['cache'])) {
    $wlsCacheConfig = $envConfig['wls']['cache'];
}

/**
 * 检查 shell_exec 函数是否可用
 * @return bool
 */
function isShellExecAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        $available = \function_exists('shell_exec') 
            && !\in_array('shell_exec', \array_map('trim', \explode(',', \ini_get('disable_functions') ?: '')), true);
    }
    return $available;
}

/**
 * 获取系统可用内存（字节）
 * @return int 可用内存字节数，获取失败返回 0
 */
function getSystemFreeMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: 使用 wmic 获取可用内存（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('wmic OS get FreePhysicalMemory /value 2>nul');
            if ($output && \preg_match('/FreePhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
        }
        // 回退：返回 0（使用默认值）
        return 0;
    } else {
        // Linux/Mac: 读取 /proc/meminfo 或使用 free 命令
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemAvailable:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
            // 回退：MemFree + Cached + Buffers
            if ($meminfo) {
                $free = 0;
                if (\preg_match('/MemFree:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Cached:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Buffers:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if ($free > 0) {
                    return $free * 1024;
                }
            }
        }
        // Mac: vm_stat 仅 "Pages free" 偏小，需加上可回收的 inactive/speculative（与 Linux MemAvailable 语义一致）
        // 注意：macOS 可能输出千位逗号（如 "1,234,567"），需去掉逗号再转 int，否则会误判为内存严重不足
        if (isShellExecAvailable()) {
            $output = @\shell_exec('vm_stat 2>/dev/null');
            if ($output) {
                $pageSize = 4096; // macOS 页面大小（Intel/Apple Silicon 均为 4KB）
                $parse = static function (string $text, string $key): int {
                    if (!\preg_match('/' . \preg_quote($key, '/') . ':\s*([\d,\.]+)/', $text, $m)) {
                        return 0;
                    }
                    return (int)\str_replace([',', '.'], '', $m[1]);
                };
                $free = $parse($output, 'Pages free');
                $inactive = $parse($output, 'Pages inactive');
                $speculative = $parse($output, 'Pages speculative');
                $availablePages = $free + $inactive + $speculative;
                if ($availablePages > 0) {
                    return $availablePages * $pageSize;
                }
            }
        }
    }
    return 0;
}

/**
 * 获取系统总内存（字节）
 * @return int 总内存字节数
 */
function getSystemTotalMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: 使用 wmic 获取总内存（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>nul');
            if ($output && \preg_match('/TotalPhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
        }
        // 回退：返回默认值
        return 4 * 1024 * 1024 * 1024; // 4GB
    } else {
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemTotal:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024;
            }
        }
        // Mac（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) {
                return (int)\trim($output);
            }
        }
    }
    // 默认返回 4GB
    return 4 * 1024 * 1024 * 1024;
}

/**
 * 智能计算缓存大小
 * @param string $configValue 配置值：'auto'、'50M'、'100MB'、数字（字节）
 * @param int $defaultPercent 默认百分比（相对于系统内存）
 * @param int $defaultMin 默认最小值（字节）
 * @param int $defaultMax 默认最大值（字节）
 * @return int 缓存大小（字节）
 */
function calculateCacheSize(string|int $configValue, int $defaultPercent, int $defaultMin, int $defaultMax): int
{
    // 数字直接返回
    if (\is_int($configValue)) {
        return $configValue;
    }
    
    $configValue = \strtolower(\trim($configValue));
    
    // 'auto' 或空：智能计算
    if ($configValue === 'auto' || $configValue === '') {
        $totalMem = getSystemTotalMemory();
        $calculated = (int)($totalMem * $defaultPercent / 100);
        return \max($defaultMin, \min($defaultMax, $calculated));
    }
    
    // 解析带单位的值：50M, 100MB, 1G, 1GB
    if (\preg_match('/^(\d+(?:\.\d+)?)\s*(k|kb|m|mb|g|gb)?$/i', $configValue, $matches)) {
        $value = (float)$matches[1];
        $unit = \strtolower($matches[2] ?? '');
        
        return match($unit) {
            'k', 'kb' => (int)($value * 1024),
            'm', 'mb' => (int)($value * 1024 * 1024),
            'g', 'gb' => (int)($value * 1024 * 1024 * 1024),
            default => (int)$value,
        };
    }
    
    // 解析失败，返回默认最小值
    return $defaultMin;
}

// 计算静态文件缓存大小
// 默认：系统内存的 2%，最小 32MB，最大 256MB
$staticFileCacheMaxTotalConfig = $wlsCacheConfig['static_file_max_total'] ?? 'auto';
$WLS_STATIC_CACHE_MAX_TOTAL = calculateCacheSize($staticFileCacheMaxTotalConfig, 2, 32 * 1024 * 1024, 256 * 1024 * 1024);

// 单文件最大缓存大小（H13: 提高默认值到 2MB，支持大型 JS 库如 CKEditor）
$staticFileCacheMaxSizeConfig = $wlsCacheConfig['static_file_max_size'] ?? '2M';
$WLS_STATIC_CACHE_MAX_SIZE = calculateCacheSize($staticFileCacheMaxSizeConfig, 0, 512 * 1024, 10 * 1024 * 1024);

// 缓存淘汰临界值：剩余多少字节时开始淘汰
$WLS_CACHE_EVICTION_THRESHOLD = (int)($wlsCacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024); // 默认 5MB

// 检查启动时内存是否足够
$freeMemory = getSystemFreeMemory();
$requiredMemory = $WLS_STATIC_CACHE_MAX_TOTAL + 50 * 1024 * 1024; // 缓存 + 50MB 预留

if ($freeMemory > 0 && $freeMemory < $requiredMemory) {
    $freeMB = \round($freeMemory / 1024 / 1024, 1);
    $requiredMB = \round($requiredMemory / 1024 / 1024, 1);
    $cacheMB = \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1);
    
    WlsLogger::warning_("内存不足警告：系统可用内存 {$freeMB}MB，WLS 需要 {$requiredMB}MB（缓存 {$cacheMB}MB + 50MB 预留）");
    
    // 如果严重不足（低于需求的 50%），报错退出
    if ($freeMemory < $requiredMemory * 0.5) {
        WlsLogger::error_("内存严重不足，无法启动。请增加系统内存或减少 env.php 中的 wls.cache.static_file_max_total 配置");
        exit(1);
    }
    
    // 自动缩减缓存大小
    $newCacheSize = (int)($freeMemory * 0.6); // 使用 60% 的可用内存
    $newCacheMB = \round($newCacheSize / 1024 / 1024, 1);
    WlsLogger::warning_("自动缩减静态文件缓存至 {$newCacheMB}MB");
    $WLS_STATIC_CACHE_MAX_TOTAL = $newCacheSize;
}

WlsLogger::info_("内存缓存配置：静态文件缓存上限 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB，单文件上限 " . \round($WLS_STATIC_CACHE_MAX_SIZE / 1024, 1) . "KB，淘汰阈值 " . \round($WLS_CACHE_EVICTION_THRESHOLD / 1024 / 1024, 1) . "MB");
// ========== 内存缓存配置结束 ==========

$WLS_UOPZ_EXIT_GUARD = false;
if (\extension_loaded('uopz') && \function_exists('uopz_allow_exit')) {
    try {
        \uopz_allow_exit(false);
        $WLS_UOPZ_EXIT_GUARD = true;
        WlsLogger::info_('uopz 已启用：裸 exit()/die() 不结束 SSL Worker（请使用 System::exit）');
    } catch (\Throwable) {
    }
}

// 注册补充 shutdown handler（检测 die()/exit() 非正常退出）
// 注：致命错误由 ErrorBootstrap 统一处理，此处仅处理非致命退出
\register_shutdown_function(function() use ($workerId, $port, $instanceName) {
    $error = \error_get_last();
    $fatalErrorTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_RECOVERABLE_ERROR, \E_USER_ERROR];
    
    // 致命错误由 ErrorBootstrap 处理，不在此重复
    if ($error !== null && \in_array($error['type'], $fatalErrorTypes, true)) {
        return;
    }
    
    // 无致命错误但进程即将退出：多为业务代码 die()/exit() 或信号终止
    $exitMsg = "Worker SSL 非致命退出，可能为 die()/exit() 或信号终止";
    WlsLogger::warning_($exitMsg);
    WlsLogger::flush_(true);
});

// 确定最高支持的 TLS 版本
$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
} elseif (\defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
}

// 检测 SO_REUSEPORT 支持（Linux 3.9+，允许多进程监听同一端口）
$isWindows = \PHP_OS_FAMILY === 'Windows';
$supportsReusePort = false;

// 检测 SO_REUSEPORT 支持
if (!$isWindows && \defined('SO_REUSEPORT')) {
    if (PHP_OS === 'Linux') {
        $release = \php_uname('r');
        if (\version_compare($release, '3.9', '>=')) {
            $supportsReusePort = true;
        }
    } elseif (PHP_OS === 'Darwin') {
        // macOS 也支持 SO_REUSEPORT
        $supportsReusePort = true;
    }
}

// 如果显式指定了 --reuseport 但平台不支持，报错
if ($useReusePort && !$supportsReusePort) {
    WlsLogger::error_("平台不支持 SO_REUSEPORT");
    exit(1);
}

// ========== Socket 创建 ==========

$socket = null;

// 延迟 SSL 时共用：accept 后根据首包判断 HTTP 重定向或启用 SSL（同端口 http→https）
if ($deferSsl) {
    $deferSslOptions = [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
        'single_dh_use' => true,
        'honor_cipher_order' => true,
        'SNI_enabled' => !empty($sniServerCerts),
        'SNI_server_certs' => $sniServerCerts,
    ];
}

// 特权端口权限检查（macOS/Linux）
if (\PHP_OS !== 'WINNT' && $port < 1024) {
    $euid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : -1;
    if ($euid !== 0 && $euid !== -1) {
        WlsLogger::error_("错误：尝试绑定特权端口 {$port} 但当前进程不是 root (euid: {$euid})");
        WlsLogger::error_("请使用 sudo php bin/w server:start 启动服务器");
        exit(1);
    }
}

// 方案1a：SO_REUSEPORT + 延迟 SSL（同端口 HTTP→HTTPS 重定向，与方案2b 行为一致）
if ($useReusePort && $supportsReusePort && $deferSsl && \function_exists('socket_create')) {
    WlsLogger::info_("使用 SO_REUSEPORT + 延迟 SSL，监听 tcp://{$host}:{$port}（同端口 HTTP→HTTPS 重定向）");
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    // 不在此 socket 上启用 SSL，由 accept 后按首包处理
    WlsLogger::info_("SO_REUSEPORT + 延迟 SSL socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");

// 方案1b：使用 socket 扩展创建支持 SO_REUSEPORT 的 socket（直接 SSL，无同端口重定向）
} elseif ($useReusePort && $supportsReusePort && \function_exists('socket_create')) {
    WlsLogger::info_("使用 socket 扩展创建 SO_REUSEPORT socket...");
    
    // 创建原始 socket
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    
    // 设置 SO_REUSEADDR
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    
    // 设置 SO_REUSEPORT
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 绑定地址
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        
        // 如果端口被占用，直接退出进程，不再重试
        if ($errCode === 10048 || $errCode === 98) { // Windows: 10048, Linux: 98
            @\socket_close($rawSocket);
            exit(1); // 退出码 1 通知 Master 启动失败
        }
        
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 开始监听
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 将 socket 资源转换为 stream
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 启用 SSL 加密（手动处理）
    $sslContext = \stream_context_create([
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
            'single_dh_use' => true,
            'honor_cipher_order' => true,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);
    \stream_context_set_params($socket, \stream_context_get_params($sslContext));
    
    WlsLogger::info_("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");
    
} elseif ($deferSsl && $useReusePort && !$isWindows && \function_exists('socket_create')) {
    // 方案2b-socket：仅 SO_REUSEPORT 直连模式才用 socket 扩展（socket_export_stream + stream_socket_accept 在 Dispatcher 模式下不可靠）
    // Dispatcher 模式（$useReusePort=false）直接 fallthrough 到方案2b 的 stream_socket_server，保证 stream_socket_accept 正常工作
    $maxBindRetries = 1;
    $bindRetryDelay = 0;
    $rawSocket = false;
    $lastErrno = 0;
    $lastErrstr = '';

    for ($attempt = 1; $attempt <= $maxBindRetries; $attempt++) {
        $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$rawSocket) {
            $lastErrno = \socket_last_error();
            $lastErrstr = \socket_strerror($lastErrno);
            WlsLogger::error_("Socket 创建失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            WlsLogger::warning_("设置 SO_REUSEADDR 失败");
        }
        if (\defined('SO_REUSEPORT') && !@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            WlsLogger::warning_("设置 SO_REUSEPORT 失败（可忽略）");
        }
        if (@\socket_bind($rawSocket, $host, $port)) {
            break;
        }
        $lastErrno = \socket_last_error($rawSocket);
        $lastErrstr = \socket_strerror($lastErrno);
        @\socket_close($rawSocket);
        $rawSocket = false;
        if ($lastErrno !== 98) { // EADDRINUSE on Linux
            WlsLogger::error_("Socket 绑定失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
            break;
        }
        WlsLogger::warning_("端口 {$port} 占用 (errno: {$lastErrno})，{$bindRetryDelay} 秒后重试 ({$attempt}/{$maxBindRetries})");
        if ($attempt < $maxBindRetries) {
            \Weline\Framework\Runtime\SchedulerSystem::sleep($bindRetryDelay);
        }
    }

    if (!$rawSocket) {
        WlsLogger::error_("Socket 创建失败 (defer-ssl): {$lastErrstr} (errno: {$lastErrno})");
        w_log_error("[WLS Worker SSL] Failed to create socket (defer-ssl): {$lastErrstr}");
        exit(1);
    }
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    WlsLogger::info_("延迟 SSL 模式: 监听 tcp://{$host}:{$port}，accept 后手动启用 SSL");

} elseif ($deferSsl) {
    // 方案2b：Windows 或未走 2b-socket 时，保持原 stream_socket_server 逻辑不变
    // Windows 下可能出现 TLS reset（cURL 35），与延迟 SSL 无关，属 PHP stream+OpenSSL 兼容性。
    $socketOptions = [
        'backlog' => 102400,
        'so_reuseaddr' => true,
    ];

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => $deferSslOptions,
    ]);

    $socket = @\stream_socket_server(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败 (defer-ssl): {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket (defer-ssl): {$errstr}");
        exit(1);
    }
    WlsLogger::info_("延迟 SSL 模式: 监听 tcp://{$host}:{$port}，accept 后手动启用 SSL");

} else {
    // 方案2：标准 stream_socket_server 方式
    $socketOptions = [
        'backlog' => 102400,  // 增大 backlog 提高并发
        'so_reuseaddr' => true,
    ];

    // Linux 下尝试启用 SO_REUSEPORT（通过 stream context，可能不被支持）
    if ($supportsReusePort && !$useReusePort) {
        $socketOptions['so_reuseport'] = true;
        WlsLogger::info_("尝试通过 stream_context 启用 SO_REUSEPORT");
    }

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
            'single_dh_use' => true,
            'honor_cipher_order' => true,
            'SNI_enabled' => !empty($sniServerCerts),
            'SNI_server_certs' => $sniServerCerts,
        ]
    ]);

    $socket = @\stream_socket_server(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败: {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket: {$errstr}");
        exit(1);
    }
}

WlsLogger::info_("Socket 创建成功，开始监听连接");

\stream_set_blocking($socket, false);

// ========== IPC 控制通道：连接 Master 并注册 + 上报就绪 ==========
$kernel = null;
$ipcClient = null;
$ipcReceivedShutdown = false;
$ipcDraining = false; // 是否正在排水（收到 reload 后关闭监听 socket，处理剩余请求）
$drainStartTime = 0;   // 排水开始时间戳
$maxDrainTime = 10;     // 由 Master drain/reload 消息或默认覆盖
$pendingMaintDrainReqId = null; // 维护：先排空本 Worker 存量连接再 ACK
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();

// 如果启用了维护模式
if ($isMaintenanceWorker) {
    try {
        \Weline\Framework\App\Env::getInstance()->setConfig('system.maintenance', true);
        WlsLogger::info_("维护 Worker 模式已启用");
    } catch (\Throwable $e) {
        WlsLogger::warning_("设置维护模式失败: " . $e->getMessage());
    }
}

// 获取控制端口
$controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
$ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;

if ($controlPort > 0) {
    $ipcSelfTag = ($isMaintenanceWorker ? 'Maintenance' : 'Worker') . "#{$workerId}";
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        $ipcRole,
        \getmypid(),
        $port,
        $workerId,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\WorkerSslControlHandler(
        static function (array $msg) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, &$maxDrainTime, &$pendingMaintDrainReqId, $workerId, &$sniServerCerts, &$ipcClient, $isMaintenanceWorker, &$activeFibers, &$fiberIdleTtlSec, &$fiberMaxActive, &$fiberReleaseIdleRequested, $port): void {
            $type = $msg['type'] ?? '';
            // 帝王令：shutdown 至高无上，一旦收到则不再处理其他 IPC（RELOAD/DRAIN/CACHE_CLEAR）
            if ($type !== \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN && $ipcReceivedShutdown) {
                return;
            }
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_PING:
                    $pingTimestamp = (float) ($msg['timestamp'] ?? 0.0);
                    $stats = [
                        'active_fibers' => \count($activeFibers),
                        'memory_usage' => \memory_get_usage(true),
                    ];
                    if ($ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::pong($pingTimestamp, $stats));
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ACK_READY:
                    $ackWorkerId = $msg['worker_id'] ?? 0;
                    WlsLogger::info_("收到 Master ACK_READY 确认 (worker_id={$ackWorkerId})，SSL Worker 启动确认");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_RELOAD:
                    // 代码重载：先清 opcache（共享内存级），确保新 Worker 加载最新文件
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    $maxDrainTime = $dt >= 10 ? \min(7200, $dt) : 120;
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    WlsLogger::info_("收到 reload 命令，已清除 opcache 并关闭监听 socket，开始排水（最多等待 {$maxDrainTime} 秒）...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    // 缓存清理：原地执行，不重启
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    // 清理 WLS 内置的静态文件内存缓存
                    if (\function_exists('handleStaticFile')) {
                        handleStaticFile('__CLEAR_CACHE__', '');
                    }
                    WlsLogger::info_("收到 cache_clear 命令，已清理缓存（opcache + ObjectManager + 静态文件缓存）");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_PAGEBUILDER_PAGE_INVALIDATE:
                    $pbWid = (int)($msg['website_id'] ?? 0);
                    $pbHandle = (string)($msg['handle'] ?? '');
                    $pbHome = (bool)($msg['is_home_page'] ?? false);
                    if (\class_exists(\GuoLaiRen\PageBuilder\Controller\Router::class)) {
                        \GuoLaiRen\PageBuilder\Controller\Router::clearHandleCacheForPage($pbWid, $pbHandle, $pbHome);
                    }
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    WlsLogger::info_('收到 pagebuilder_page_invalidate，已清理 PageBuilder handle 缓存并重置 ObjectManager');
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SSL_CERT_RELOAD:
                    \clearstatcache(true);
                    $reloadDomains = isset($msg['domains']) && \is_array($msg['domains'])
                        ? \array_filter($msg['domains'], static fn($d) => \is_string($d) && $d !== '')
                        : [];
                    // 清除负缓存：让被清除的域名在下次访问时重新尝试 DB 恢复
                    _clearRestoreCertNegativeCache(\array_values($reloadDomains));
                    // 清除指定域名的内存正缓存（若无指定则全量替换）
                    if (!empty($reloadDomains)) {
                        foreach ($reloadDomains as $reloadDomain) {
                            unset($sniServerCerts[$reloadDomain]);
                        }
                    }
                    $oldCount = \count($sniServerCerts);
                    $newSniCerts = _loadSniCertsFromMap();
                    // 合并：新 map 为主，手动清除的域名若已在磁盘则会被 map 重新加入
                    $sniServerCerts = $newSniCerts;
                    $newCount = \count($sniServerCerts);
                    $domainsStr = $newCount > 0 ? \implode(', ', \array_keys($sniServerCerts)) : '(空)';
                    $targetStr = empty($reloadDomains) ? '全量重载' : ('域名：' . \implode(', ', $reloadDomains));
                    WlsLogger::info_("收到 ssl_cert_reload（{$targetStr}），已热更新 SNI 证书映射（{$oldCount} → {$newCount}）：{$domainsStr}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_ROUTING_POLICY:
                    $policyData = $msg['data'] ?? [];
                    if (\is_array($policyData)) {
                        \Weline\Server\Service\Runtime\RoutingPolicyRegistry::update($policyData);
                        WlsLogger::info_('收到 routing_policy 命令，已更新进程内路由策略快照');
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_SET_CONFIG:
                    $fiberIdleTtlSec = (int) ($msg['idle_ttl_sec'] ?? 0);
                    $fiberMaxActive = (int) ($msg['max_active'] ?? 0);
                    WlsLogger::info_("收到 fiber_set_config: idle_ttl_sec={$fiberIdleTtlSec}, max_active={$fiberMaxActive}");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_RELEASE_IDLE:
                    $fiberReleaseIdleRequested = true;
                    WlsLogger::info_('收到 fiber_release_idle，下一轮循环执行释放');
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_FIBER_POOL_QUERY:
                    $requestId = $msg['request_id'] ?? '';
                    if ($requestId !== '' && $ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::fiberPoolStats(
                            $requestId,
                            $workerId,
                            \count($activeFibers),
                            (int) $fiberIdleTtlSec,
                            (int) $fiberMaxActive,
                            0
                        ));
                    }
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_DRAIN:
                    // 排水模式：停止接受新连接，完成现有请求后退出
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    $dt = (int) ($msg['drain_timeout_sec'] ?? 0);
                    if ($dt >= 10) {
                        $maxDrainTime = \min(7200, $dt);
                    }
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    WlsLogger::info_("收到 drain 命令，已关闭监听 socket，开始排水（最多 {$maxDrainTime}s）...");
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SET_MAINTENANCE_MODE:
                    if ($isMaintenanceWorker) {
                        break;
                    }
                    $mEnabled = (bool) ($msg['enabled'] ?? false);
                    $mReqId = (string) ($msg['request_id'] ?? '');
                    if ($mEnabled) {
                        if (!empty($msg['immediate_ack'])) {
                            try {
                                \Weline\Framework\App\Env::getInstance()->setConfig('system.maintenance', true);
                            } catch (\Throwable $e) {
                                WlsLogger::warning_('IPC 维护信号应用失败: ' . $e->getMessage());
                            }
                            if ($mReqId !== '' && $ipcClient !== null && $ipcClient->isConnected()) {
                                $ipcClient->send(\Weline\Server\IPC\ControlMessage::encode([
                                    'type' => \Weline\Server\IPC\ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                                    'request_id' => $mReqId,
                                    'worker_id' => $workerId,
                                ]));
                            }
                            break;
                        }
                        $pendingMaintDrainReqId = $mReqId !== '' ? $mReqId : 'wm';
                        WlsLogger::info_('维护模式：等待本 Worker 存量连接处理完毕后再确认 Master（新连接已由 Dispatcher 切至维护 Worker）');
                        break;
                    }
                    $pendingMaintDrainReqId = null;
                    try {
                        \Weline\Framework\App\Env::getInstance()->setConfig('system.maintenance', false);
                        WlsLogger::info_("IPC 维护信号 enabled=false request_id={$mReqId}");
                    } catch (\Throwable $e) {
                        WlsLogger::warning_('IPC 维护信号应用失败: ' . $e->getMessage());
                    }
                    if ($mReqId !== '' && $ipcClient !== null && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::encode([
                            'type' => \Weline\Server\IPC\ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                            'request_id' => $mReqId,
                            'worker_id' => $workerId,
                        ]));
                    }
                    break;

                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    // 主动终结：优雅退出
                    $ipcReceivedShutdown = true;
                    $shouldExit = true;
                    WlsLogger::info_("收到 shutdown 命令，准备退出");
                    break;
            }
        },
        static function () use (&$ipcClient): void {
            $ipcClient?->tryReconnect();
        }
    );
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $handler,
        $ipcSelfTag,
        (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE),
        $instanceName
    );
    if ($kernel->connectAndRegister($controlPort)) {
        $ipcClient = $kernel->getClient();
        WlsLogger::info_("IPC 控制通道已连接 (控制端口: {$controlPort})");
        WlsLogger::info_("已上报就绪状态");
        if (\Weline\Server\Log\LogConfig::isDevMode() && $ipcClient !== null) {
            WlsLogger::getInstance()->setIpcLogSink(static function (string $line, string $level, string $tag) use ($ipcClient): void {
                if ($ipcClient->isConnected()) {
                    $ipcClient->sendLogLine($line, $level, $tag);
                }
            });
        }
    } else {
        WlsLogger::warning_("IPC 控制通道连接失败 (控制端口: {$controlPort})，继续独立运行");
        $kernel = null;
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$requestCount = 0;
$activeRequests = 0; // 正在处理的请求数
$requestBuffers = [];
$connectionLastActivity = []; // 连接最后活动时间（用于超时清理）
$requestLogged = []; // 记录已输出日志的连接（前端模式使用）
$writeBuffers = [];
$writableConnections = [];
$pendingPeek = [];
$pendingPeekStartTimes = [];
$pendingHandshakes = [];
$pendingClose = [];
$handshakeStartTimes = [];
$startTime = \time(); // 记录启动时间

// Keep-Alive 连接超时配置（秒）
$keepAliveTimeout = 60; // 默认 60 秒空闲超时
$connectionTimeoutCheckInterval = 5; // 每 5 秒检查一次超时连接
$lastTimeoutCheck = \time();
if (\defined('BP') && \is_file(BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php')) {
    $env = @include BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
    $env = \is_array($env) ? $env : [];
    $wls = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
    $wlsServers = \is_array($wls['servers'] ?? null) ? $wls['servers'] : [];
    $wlsInstance = \is_array($wlsServers[$instanceName] ?? null) ? $wlsServers[$instanceName] : [];
    $configuredKeepAliveTimeout = $wlsInstance['keep_alive_timeout'] ?? $wls['keep_alive_timeout'] ?? null;
    if (\is_numeric($configuredKeepAliveTimeout)) {
        $configuredKeepAliveTimeout = (int)$configuredKeepAliveTimeout;
        if ($configuredKeepAliveTimeout > 0) {
            $keepAliveTimeout = $configuredKeepAliveTimeout;
        }
    }
}

// 重载日志输出函数
$logReload = function (string $method) use ($workerId, $instanceName) {
    $time = \date('Y-m-d H:i:s');
    // 根据方法类型显示不同消息
    if ($method === 'FLAG-CACHE' || $method === 'IPC-CACHE') {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 已清理缓存（opcache + ObjectManager）[{$method}]";
    } else {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 正在重载（优雅退出，由 Master 重启）[{$method}]";
    }
    w_log_info($message);
    // 前台模式时输出到控制台
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[33m{$message}\033[0m\n");
    }
};

// 是否需要优雅退出（重载时设置为 true）
$shouldExit = false;

// Worker 优雅退出函数
$gracefulExit = function (string $reason = '') use ($socket, &$connections, &$requestBuffers, &$connectionLastActivity, $processName, &$ipcClient, $workerId, $port, $isMaintenanceWorker) {
    // 刷新日志缓冲区
    WlsLogger::flush_(true);
    
    // 记录退出原因
    if ($reason) {
        w_log_info("[WLS-SSL Worker] 退出原因: {$reason}");
    }
    
    // 关闭所有连接（仅对有效 stream 调用 fclose，避免已关闭或无效 resource 导致 TypeError）
    foreach ($connections as $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            safeCloseStream($conn);
        }
    }
    if (\is_resource($socket) && \get_resource_type($socket) === 'stream') {
        @\fclose($socket);
    }
    
    // 清理连接相关数据
    $connections = [];
    $requestBuffers = [];
    $connectionLastActivity = [];
    
    // 通知 Master 即将退出（先发送退出原因，再发送 exited）
    if ($ipcClient && $ipcClient->isConnected()) {
        $exitRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $exitReason = $reason !== '' ? $reason : 'graceful';
        @$ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason($exitReason, 0));
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::exited($exitRole, \getmypid(), $port, $workerId));
        WlsLogger::info_("已发送 exit_reason + exited 消息给 Master");
    }
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    exit(0);
};

// 信号处理（热更新支持，仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal')) {
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGUSR1, function () use (&$shouldExit, &$ipcDraining, &$drainStartTime, &$socket, $logReload) {
        // 收到重载信号，标记优雅退出（Master 会重新启动新进程加载新代码）
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = \time();
        // 关闭监听 socket（不再接受新连接）
        if ($socket && \is_resource($socket)) {
            @\fclose($socket);
            $socket = null;
        }
        $logReload('SIGUSR1');
    });
    
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

// Master 感知通过 IPC 控制通道（TCP 连接断开 = Master 死亡/重启，无需文件轮询）

// 连续错误计数器（Workerman 模式：避免单次错误导致进程退出）
$consecutiveErrors = 0;
$maxConsecutiveErrors = 100; // 连续 100 次错误才考虑重启（给予足够的恢复机会）

// 进入事件循环后向 Master 上报（略延迟，避免早于 register/ready 被 Master 处理）
$workerLoopStartedSent = false;
$workerLoopNotifyNotBefore = 0.0;
$eventLoopWaitTimeouts = 0;
$eventLoopLagWarnings = 0;
$eventLoopLastMetricsLogAt = \time();

// 事件循环（Workerman 模式：外层 try-catch 防止意外退出）
while (true) {
    try {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 定期刷新日志缓冲区（避免日志堆积）
    WlsLogger::flush_(false);
    
    $now = \time();
    
    // ========== 孤儿检测（IPC 优先） ==========
    if ($orphanGuard->shouldExit(
        $masterPid,
        $ipcClient && $ipcClient->isConnected(),
        $ipcReceivedShutdown,
        $ipcSelfTag ?? 'Worker'
    )) {
        WlsLogger::warning_("Master PID {$masterPid} 已死亡，Worker 自行退出（孤儿保护）");
        $gracefulExit('孤儿检测：Master 已死亡');
    }
    
    // ========== IPC 控制通道处理 ==========
    // 如果有 IPC 客户端且连接断开了，尝试重连
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }
    if ($ipcClient && !$ipcClient->isConnected()) {
        $workerLoopStartedSent = false;
        $workerLoopNotifyNotBefore = 0.0;
    }
    if ($ipcClient && $ipcClient->isConnected() && !$workerLoopStartedSent && !$ipcReceivedShutdown) {
        if ($workerLoopNotifyNotBefore <= 0.0) {
            $workerLoopNotifyNotBefore = \microtime(true) + 0.25;
        }
        if (\microtime(true) >= $workerLoopNotifyNotBefore) {
            $ipcClient->sendWorkerLoopStarted($workerId, $port, (int) \getmypid());
            $workerLoopStartedSent = true;
        }
    }

    if ($pendingMaintDrainReqId !== null && !$isMaintenanceWorker
        && empty($connections) && empty($pendingHandshakes)) {
        try {
            \Weline\Framework\App\Env::getInstance()->setConfig('system.maintenance', true);
        } catch (\Throwable $e) {
            WlsLogger::warning_('维护标志应用失败: ' . $e->getMessage());
        }
        $rid = $pendingMaintDrainReqId;
        if ($ipcClient !== null && $ipcClient->isConnected()) {
            $ipcClient->send(\Weline\Server\IPC\ControlMessage::encode([
                'type' => \Weline\Server\IPC\ControlMessage::TYPE_MAINTENANCE_MODE_ACK,
                'request_id' => $rid,
                'worker_id' => $workerId,
            ]));
        }
        WlsLogger::info_('维护：存量连接已排空，已上报 Master ACK');
        $pendingMaintDrainReqId = null;
    }

    // 检查是否需要优雅退出（排水模式）
    if ($shouldExit) {
        if ($ipcDraining) {
            // ========== 排水模式：快速清理连接，加速退出 ==========
            // 1. 立即关闭所有空闲 Keep-Alive 连接（无请求数据、无写缓冲、非握手中）
            foreach ($connections as $cid => $cconn) {
                $hasReqData = isset($requestBuffers[$cid]) && $requestBuffers[$cid] !== '';
                $hasWriteData = isset($writeBuffers[$cid]) && $writeBuffers[$cid] !== '';
                $isPendingHs = isset($pendingHandshakes[$cid]);
                if (!$hasReqData && !$hasWriteData && !$isPendingHs) {
                    @\fclose($cconn);
                    unset($connections[$cid], $requestBuffers[$cid], $connectionLastActivity[$cid],
                          $requestLogged[$cid], $writeBuffers[$cid], $writableConnections[$cid], $pendingClose[$cid]);
                }
            }
            
            $drainElapsed = $drainStartTime > 0 ? (\time() - $drainStartTime) : 0;
            
            // 2. 所有连接已清空 → 排水完成（帝王令：若已收 shutdown，做完排水仍以 shutdown 名义退出）
            if (empty($connections) && empty($pendingHandshakes)) {
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                WlsLogger::info_("排水完成（{$drainElapsed}秒），Worker 退出");
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
            }
            
            // 3. 排水超时 → 强制关闭所有剩余连接
            if ($drainElapsed >= $maxDrainTime) {
                $remaining = \count($connections) + \count($pendingHandshakes);
                WlsLogger::warning_("排水超时（{$drainElapsed}秒 >= {$maxDrainTime}秒），强制关闭剩余 {$remaining} 个连接");
                foreach ($connections as $cid => $cconn) {
                    @\fclose($cconn);
                }
                foreach ($pendingHandshakes as $cid => $hsInfo) {
                    if (\is_resource($hsInfo['conn'])) {
                        @\fclose($hsInfo['conn']);
                    }
                }
                $connections = [];
                $pendingPeek = [];
                $pendingPeekStartTimes = [];
                $pendingHandshakes = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                $writeBuffers = [];
                $writableConnections = [];
                $pendingClose = [];
                $handshakeStartTimes = [];
                
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载（超时强制退出）');
            }
        } elseif (empty($connections) && empty($pendingHandshakes)) {
            // 非排水模式退出（如 shutdown 命令）
            $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
        }
    }
    
    // Keep-Alive 连接超时清理（定期检查并关闭空闲连接）
    if ($now - $lastTimeoutCheck >= $connectionTimeoutCheckInterval) {
        $lastTimeoutCheck = $now;
        foreach ($connections as $connId => $conn) {
            $lastActivity = $connectionLastActivity[$connId] ?? $now;
            $idleTime = $now - $lastActivity;
            
            // 如果连接空闲时间超过超时时间，关闭连接
            if ($idleTime >= $keepAliveTimeout) {
                // 如果缓冲区有数据，说明还在发送响应，不能关闭连接
                $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
                if ($hasBufferedData) {
                    // 缓冲区有数据，跳过关闭，等待数据发送完成
                    // 但更新超时时间，避免无限等待
                    if ($idleTime >= $keepAliveTimeout * 3) {
                        // 超过 3 倍超时时间仍未发送完成，强制关闭（防止僵尸连接）
                        WlsLogger::warning_("连接超时且缓冲区有数据，强制关闭 (connId: {$connId}, 剩余: " . \strlen($writeBuffers[$connId]) . " 字节)");
                        if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                            safeCloseStream($conn);
                        }
                        unset($connections[$connId]);
                        unset($requestBuffers[$connId]);
                        unset($connectionLastActivity[$connId]);
                        unset($requestLogged[$connId]);
                        unset($writeBuffers[$connId]);
                        unset($writableConnections[$connId]);
                        unset($pendingClose[$connId]);
                    }
                    continue; // 跳过正常超时关闭
                }

                if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                    safeCloseStream($conn);
                }
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                // 清理写缓冲区相关状态（虽然此时应该为空）
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($pendingClose[$connId]);
            }
        }
        
        // 定期记录 Worker 状态到数据库
        try {
            \Weline\Server\Service\StatusLogService::logWorkerStatus([
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'pid' => \getmypid(),
                'connections' => \count($connections),
                'active_requests' => $activeRequests,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $now - $startTime,
                'ssl' => true,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    $pendingPeekConns = [];
    foreach ($pendingPeek as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingPeekConns[$connId] = $info['conn'];
        } else {
            unset($pendingPeek[$connId]);
            unset($pendingPeekStartTimes[$connId]);
        }
    }
    
    // 同时验证所有资源是否仍然有效（防止 stream_select 错误）
    $pendingConns = [];
    foreach ($pendingHandshakes as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingConns[$connId] = $info['conn'];
        } else {
            // 资源已无效，标记为需要清理
            unset($pendingHandshakes[$connId]);
            unset($handshakeStartTimes[$connId]);
        }
    }
    
    // 验证 $connections 中的资源
    $validConnections = [];
    foreach ($connections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validConnections[$connId] = $conn;
        } else {
            // 资源已无效，清理
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
        }
    }
    
    // 验证 $writableConnections 中的资源
    $validWritableConnections = [];
    foreach ($writableConnections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validWritableConnections[$connId] = $conn;
        } else {
            unset($writableConnections[$connId]);
            unset($writeBuffers[$connId]);
        }
    }
    
    // 构建 stream_select 读数组
    $readSockets = [];
    if ($socket && \is_resource($socket)) {
        $readSockets[] = $socket; // 监听 socket（排水后已关闭则不加入）
    }
    $validConnectionsReadable = [];
    foreach ($validConnections as $connIdReadable => $connReadable) {
        if (!isset($longLivedConnections[$connIdReadable])) {
            $validConnectionsReadable[$connIdReadable] = $connReadable;
        }
    }
    $readSockets = \array_merge($readSockets, $validConnectionsReadable, $pendingConns, $pendingPeekConns);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    // SSL 握手需要双向通信，将 pendingHandshakes 也加入写数组
    $write = \array_merge($validWritableConnections, $pendingConns);
    if ($ipcSocket && $ipcClient && $ipcClient->hasPendingWrites()) {
        $write[] = $ipcSocket;
    }
    $except = [];
    
    // EventLoop + CoroutineRuntime：统一等待语义（select/event 后端可切换）
    $waitStartedAt = \microtime(true);
    $changed = $coroutineRuntime->wait($read, $write, $except, 100000);
    $waitElapsedMs = (\microtime(true) - $waitStartedAt) * 1000;
    if ($waitElapsedMs >= 500) {
        $eventLoopLagWarnings++;
        WlsLogger::warning_(
            'EventLoop wait 慢调用 backend=' . $coroutineRuntime->getLoopBackend()
            . ' elapsed_ms=' . \round($waitElapsedMs, 2)
        );
    }
    if ($changed === 0) {
        $eventLoopWaitTimeouts++;
    }

    // 先 tick，避免 sleep/usleep 挂起的 Fiber 饿死
    $fiberScheduler->tick(function (\Fiber $fiber) use (&$activeFibers): void {
        foreach ($activeFibers as $afData) {
            if (($afData['fiber'] ?? null) === $fiber && isset($afData['context'])) {
                $afData['context']->restore();
                return;
            }
        }
    });
    foreach ($activeFibers as $afConnId => $afData) {
        $af = $afData['fiber'] ?? null;
        if (!($af instanceof \Fiber)) {
            unset($activeFibers[$afConnId]);
            continue;
        }
        if ($af->isTerminated()) {
            if (isset($afData['context'])) {
                $afData['context']->restore();
            }
            $afResponse = '';
            try {
                $afResponse = (string) ($af->getReturn() ?? '');
            } catch (\Throwable) {
            }
            $fiberScheduler->unregisterFiber();
            $afDurationMs = (\microtime(true) - (float) ($afData['handleStartTime'] ?? \microtime(true))) * 1000;
            $afResponse = injectWlsProcessTimeHeader($afResponse, $afDurationMs);
            $afIsSse = (bool) ($afData['is_sse_protocol'] ?? false);
            if (isset($connections[$afConnId]) && \is_resource($afData['conn'] ?? null)) {
                sslFinalizeHttpResponseAfterHandle(
                    $afData['conn'],
                    $afConnId,
                    (string) ($afData['rawRequest'] ?? ''),
                    $afResponse,
                    (float) ($afData['handleStartTime'] ?? \microtime(true)),
                    $afIsSse,
                    $ipcDraining,
                    $connections,
                    $requestBuffers,
                    $connectionLastActivity,
                    $requestLogged,
                    $writeBuffers,
                    $writableConnections,
                    $pendingClose,
                    $longLivedConnections,
                    $ipcClient,
                    $instanceName,
                    $activeRequests
                );
            } else {
                $activeRequests = \max(0, $activeRequests - 1);
                \Weline\Framework\Http\Sse\SseContext::reset();
            }
            unset($activeFibers[$afConnId]);
            continue;
        }
        if ($af->isSuspended()) {
            $afData['context'] = \Weline\Framework\Runtime\WlsFiberContext::capture();
            $afData['suspended_at'] = \time();
            $afData['last_activity'] = \time();
            $activeFibers[$afConnId] = $afData;
        }
    }

    \Weline\Server\Runtime\WorkerFiberSnapshot::setSnapshot(\Weline\Server\Runtime\WorkerFiberHealthSnapshot::build($activeFibers));

    $nowFiberCheck = \time();
    $idleCheckIntervalSsl = 5;
    $doReleaseIdleSsl = $fiberReleaseIdleRequested
        || ($fiberIdleTtlSec > 0 && $nowFiberCheck - $lastFiberIdleCheck >= $idleCheckIntervalSsl);
    if ($doReleaseIdleSsl && $activeFibers !== []) {
        $lastFiberIdleCheck = $nowFiberCheck;
        $fiberReleaseIdleRequested = false;
        $releaseThresholdSsl = $fiberIdleTtlSec > 0 ? $fiberIdleTtlSec : 0;
        $toReleaseSsl = [];
        $fiberHeartbeatTimeoutSsl = 60;
        if (isset($envConfig['wls']['fiber']['heartbeat_timeout'])) {
            $fiberHeartbeatTimeoutSsl = (int) $envConfig['wls']['fiber']['heartbeat_timeout'];
        }
        foreach ($activeFibers as $afConnIdSsl => $afDataSsl) {
            $suspendedAtSsl = $afDataSsl['suspended_at'] ?? $nowFiberCheck;
            $lastActivitySsl = $afDataSsl['last_activity'] ?? $afDataSsl['handleStartTime'] ?? $nowFiberCheck;
            $inactiveTimeSsl = $nowFiberCheck - $lastActivitySsl;
            if ($fiberHeartbeatTimeoutSsl > 0 && $inactiveTimeSsl >= $fiberHeartbeatTimeoutSsl) {
                WlsLogger::warning_(
                    "Fiber 心跳超时: connId={$afConnIdSsl} inactive_time={$inactiveTimeSsl}s (超过 {$fiberHeartbeatTimeoutSsl}s 未续约)"
                );
                $toReleaseSsl[$afConnIdSsl] = $afDataSsl;
                continue;
            }
            $isLongLivedAfSsl = $afDataSsl['is_long_lived'] ?? false;
            if (!$isLongLivedAfSsl && $releaseThresholdSsl > 0 && ($nowFiberCheck - $suspendedAtSsl) >= $releaseThresholdSsl) {
                $toReleaseSsl[$afConnIdSsl] = $afDataSsl;
            }
        }
        foreach ($toReleaseSsl as $afConnIdSsl => $afDataSsl) {
            $fiberScheduler->cancelTimersForFiber($afDataSsl['fiber']);
            if (isset($afDataSsl['conn']) && \is_resource($afDataSsl['conn'])) {
                @\fclose($afDataSsl['conn']);
            }
            unset(
                $connections[$afConnIdSsl],
                $requestBuffers[$afConnIdSsl],
                $connectionLastActivity[$afConnIdSsl],
                $requestLogged[$afConnIdSsl],
                $writeBuffers[$afConnIdSsl],
                $writableConnections[$afConnIdSsl],
                $pendingClose[$afConnIdSsl]
            );
            unset($activeFibers[$afConnIdSsl]);
            if (isset($longLivedConnections[$afConnIdSsl])) {
                unset($longLivedConnections[$afConnIdSsl]);
            }
            $activeRequests = \max(0, $activeRequests - 1);
            $fiberScheduler->unregisterFiber();
        }
        $releasedSsl = \count($toReleaseSsl);
        if ($releasedSsl > 0) {
            WlsLogger::info_("Fiber 池释放闲置: {$releasedSsl} 个 (connIds 已关闭)");
        }
    }

    if ($changed === false) {
        // EventLoop wait 失败，可能是资源问题，记录错误但继续
        $error = \error_get_last();
        WlsLogger::warning_("EventLoop wait 失败: " . ($error['message'] ?? 'unknown'));
        continue;
    }

    if (($now - $eventLoopLastMetricsLogAt) >= 30) {
        $eventLoopLastMetricsLogAt = $now;
        WlsLogger::info_(
            'EventLoop metrics backend=' . $coroutineRuntime->getLoopBackend()
            . ' active_fibers=' . \count($activeFibers)
            . ' wait_timeouts=' . $eventLoopWaitTimeouts
            . ' lag_warnings=' . $eventLoopLagWarnings
        );
    }

    // Fiber tick 已可能让 SSE 等长连接向 writeBuffers 追加数据；若等到本轮末尾才 fwrite，
    // 中间批量 fread 其它连接时同 Worker 上的静态资源会长时间 Pending（头阻塞）。
    wlsSslFlushQueuedWrites(
        $writableConnections,
        $writeBuffers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections
    );
    
    // 处理 IPC 控制通道消息
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($ipcClient) {
            $ipcClient->handleReadable();
        }
    }
    if ($ipcSocket && \in_array($ipcSocket, $write, true) && $ipcClient) {
        $ipcClient->handleWritable();
    }
    
    wlsSslAcceptNewConnections(
        $socket,
        $read,
        $deferSsl,
        $pendingPeek,
        $pendingPeekStartTimes,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $isDev
    );

    wlsSslAdvancePeekState(
        $pendingPeek,
        $pendingPeekStartTimes,
        $pendingHandshakes,
        $handshakeStartTimes,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $read,
        $deferSslOptions,
        $cryptoMethod,
        $isDev
    );

    wlsSslAdvanceHandshakeState(
        $pendingHandshakes,
        $handshakeStartTimes,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $read,
        $write,
        $changed,
        $cryptoMethod,
        $isDev
    );

    // 处理连接
    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);

        if (isset($pendingPeek[$connId])) {
            continue;
        }

        // 注释掉 pendingHandshakes 检查
        /*
        if (isset($pendingHandshakes[$connId])) {
            continue;
        }

        // 跳过本轮刚完成握手的连接，等待下一轮再读取数据
        if (isset($justCompletedHandshakes[$connId])) {
            continue;
        }
        */

        if (!isset($connections[$connId])) {
            continue;
        }
        
        $data = @\fread($conn, 65535);
        
        // fread 返回 false 表示错误
        // fread 返回空字符串只表示暂无数据（非阻塞模式），不是连接关闭
        // 需要用 feof() 检查连接是否真正关闭
        if ($data === false) {
            // 读取错误，关闭连接
            safeCloseStream($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            unset($writeBuffers[$connId]);
            unset($writableConnections[$connId]);
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
                WlsLogger::info_(
                    '客户端断开，长连接已清理 (connId: ' . $connId . ', 剩余长连接数: ' . \count($longLivedConnections) . ')'
                );
            }
            if (isset($activeFibers[$connId])) {
                $fiberScheduler->cancelTimersForFiber($activeFibers[$connId]['fiber']);
                $fiberScheduler->unregisterFiber();
                unset($activeFibers[$connId]);
                WlsLogger::info_(
                    '客户端断开，Fiber 已清理 (connId: ' . $connId . ', 剩余活跃 Fiber: ' . \count($activeFibers) . ')'
                );
            }
            $activeRequests = \max(0, $activeRequests - 1);
            continue;
        }
        
        if ($data === '') {
            // 暂无数据，不要立即检查 feof()，因为 SSL 连接上 feof() 不可靠
            // 让 Keep-Alive 超时机制来处理真正的空闲连接
            continue;
        }
        
        // 更新连接最后活动时间
        $connectionLastActivity[$connId] = \time();
        
        $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        
        // 开发模式：在接收到请求的第一行时立即输出路径日志（前台直接输出，后台通过 IPC 汇聚到 Master）
        if ($isDev && !isset($requestLogged[$connId])) {
            $firstLineEnd = \strpos($requestBuffers[$connId], "\r\n");
            if ($firstLineEnd !== false) {
                $requestLine = \substr($requestBuffers[$connId], 0, $firstLineEnd);
                if (\preg_match('/^(\w+)\s+([^\s]+)/', $requestLine, $matches)) {
                    $method = $matches[1];
                    $_p = \parse_url($matches[2], PHP_URL_PATH);
                    $uri = (\is_string($_p) && $_p !== '') ? $_p : '/';
                    $requestCount++;
                    WlsLogger::info_("→ {$method} {$uri}");
                    $requestLogged[$connId] = true;
                }
            }
        }
        
        $isComplete = isRequestComplete($requestBuffers[$connId]);
        if (!$isComplete) {
            continue;
        }
        
        $rawRequest = $requestBuffers[$connId];
        $requestBuffers[$connId] = '';
        if (!isset($requestLogged[$connId])) {
            $requestCount++;
        }
        unset($requestLogged[$connId]); // 清理标记（如果不存在也不会报错）
        $activeRequests++;

        // 周期性垃圾回收：每处理 50 个请求触发一次 GC，避免内存持续增长
        // AbstractModel::$items 和其他静态缓存会在 StateManager::reset() 中清理，
        // 但 PHP 的循环引用垃圾需要主动 gc_collect_cycles() 才能释放
        static $lastGcRequestCount = 0;
        if ($requestCount - $lastGcRequestCount >= 50) {
            $lastGcRequestCount = $requestCount;
            $compaction = \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
            $collected = (int)($compaction['cycles'] ?? 0);
            $currentMemory = \memory_get_usage(true);
            $memoryPeak = \memory_get_peak_usage(true);
            if (($compaction['cycles'] ?? 0) > 0 || ($compaction['trimmed_bytes'] ?? 0) > 0 || $currentMemory > 150 * 1024 * 1024) {
                WlsLogger::debug_("GC 触发: 回收 {$collected} 个循环，内存: " . \round($currentMemory / 1024 / 1024, 1) . "MB，峰值: " . \round($memoryPeak / 1024 / 1024, 1) . "MB");
            }
            // 内存过高警告（超过 200MB）
            if ($currentMemory > 200 * 1024 * 1024) {
                WlsLogger::warning_("内存使用过高: " . \round($currentMemory / 1024 / 1024, 1) . "MB，请检查内存泄漏。当前请求: {$method} {$uri}");
            }
        }
        
        // 非开发模式：在请求完整后输出路径日志（开发模式已在接收首行时提前输出）
        if (!$isDev) {
            $uri = '/';
            if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
                $_p = \parse_url($matches[1], PHP_URL_PATH);
                $uri = (\is_string($_p) && $_p !== '') ? $_p : '/';
            }
            $method = 'GET';
            if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
                $method = $matches[1];
            }
            WlsLogger::info_("收到请求: {$method} {$uri} (connId: {$connId}, requestCount: {$requestCount})");
        }
        
        // force_root_to_www：HTTPS 下根域 301 到 www 子域（在框架处理前拦截）
        if (\preg_match('/\r\nHost:\s*([^\r\n]+)/i', $rawRequest, $_hostMatch)) {
            $_reqHost = \strtolower(\trim($_hostMatch[1]));
            $_hostOnly = \explode(':', $_reqHost)[0];
            $_hostParts = \explode('.', $_hostOnly);
            if (\count($_hostParts) === 2) {
                $_p = _getDomainPolicy($_hostOnly);
                if ($_p['force_root_to_www'] === 1) {
                    $_reqPath = '/';
                    if (\preg_match('/^\w+\s+(\S+)\s+/', $rawRequest, $_rm)) {
                        $_reqPath = \parse_url($_rm[1], \PHP_URL_PATH) ?: '/';
                    }
                    $_wwwHost = 'www.' . $_hostOnly;
                    $_redirectPort = (int) $port;
                    $_wwwUrl = ($_redirectPort === 443)
                        ? "https://{$_wwwHost}{$_reqPath}"
                        : "https://{$_wwwHost}:{$_redirectPort}{$_reqPath}";
                    $_body = '';
                    $_resp = "HTTP/1.1 301 Moved Permanently\r\nLocation: {$_wwwUrl}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                    @\fwrite($conn, $_resp);
                    safeCloseStream($conn);
                    unset($connections[$connId], $connectionLastActivity[$connId], $requestBuffers[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
                    $activeRequests--;
                    continue;
                }
            }
        }

        // 长连分层：is_long_lived 与 HTTP Worker 一致；protocol===sse 仅本文件用于 SseContext 写队列 + sslFinalize 分支（见 SseMatcher 注释）。
        $longLivedDetection = $longLivedProtocolResolver->detect($rawRequest);
        $isLongLived = ($longLivedDetection['is_long_lived'] ?? false) === true;
        $requestProtocol = (string) ($longLivedDetection['protocol'] ?? 'http');
        $isSseProtocolRequest = ($requestProtocol === 'sse');

        $uriForLog = '/';
        if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $m)) {
            $uriForLog = \parse_url($m[1], \PHP_URL_PATH) ?: $m[1];
        }
        WlsLogger::info_(
            'Worker 开始处理请求 connId=' . $connId . ' uri='
            . (\strlen($uriForLog) > 80 ? \substr($uriForLog, 0, 80) . '...' : $uriForLog)
        );
        $handleStartTime = \microtime(true);

        if ($isLongLived) {
            $layer = (string) ($longLivedDetection['layer'] ?? 'unknown');
            $protocol = (string) ($longLivedDetection['protocol'] ?? 'long-lived');
            WlsLogger::info_("长链分层命中: layer={$layer}, protocol={$protocol}, connId={$connId}");
            if ($longLivedMaxActive > 0 && \count($longLivedConnections) >= $longLivedMaxActive) {
                $activeRequests--;
                $body = 'Service Unavailable - Too Many Long Connections';
                $resp = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
                    . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                @\fwrite($conn, $resp);
                @\fclose($conn);
                unset(
                    $connections[$connId],
                    $requestBuffers[$connId],
                    $connectionLastActivity[$connId],
                    $requestLogged[$connId],
                    $writeBuffers[$connId],
                    $writableConnections[$connId]
                );
                continue;
            }
            $longLivedConnections[$connId] = [
                'type' => $protocol,
                'start' => \time(),
            ];
            WlsLogger::info_(
                '长连接槽位已分配 (connId: ' . $connId . ', protocol: ' . $protocol
                . ', 当前长连接数: ' . \count($longLivedConnections) . ')'
            );
        }

        if ($fiberMaxActive > 0 && \count($activeFibers) >= $fiberMaxActive) {
            $activeRequests--;
            $body = 'Service Unavailable';
            $resp = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: "
                . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            @\fwrite($conn, $resp);
            @\fclose($conn);
            unset(
                $connections[$connId],
                $requestBuffers[$connId],
                $connectionLastActivity[$connId],
                $requestLogged[$connId],
                $writeBuffers[$connId],
                $writableConnections[$connId]
            );
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            WlsLogger::warning_("Fiber 池已满 (max_active={$fiberMaxActive})，拒绝请求 (connId: {$connId})");
            continue;
        }

        $fiberConnId = $connId;
        $fiberConn = $conn;
        $fiberRawRequest = $rawRequest;
        $requestFiber = new \Fiber(function () use (
            $fiberRawRequest,
            $runtime,
            $runtimeError,
            $instanceName,
            $workerId,
            $port,
            $requestCount,
            &$activeRequests,
            &$connections,
            $startTime,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal,
            $asyncBizAdapters,
            $WLS_UOPZ_EXIT_GUARD,
            $fiberConn,
            $fiberConnId,
            $isSseProtocolRequest,
            &$requestBuffers,
            &$connectionLastActivity,
            &$requestLogged,
            &$writeBuffers,
            &$writableConnections,
            &$pendingClose
        ) {
            wlsFiberRequestContextEnter($fiberConn);
            try {
                if ($isSseProtocolRequest) {
                    \Weline\Framework\Http\Sse\SseContext::setWriteCallback(
                        static function (string $data) use (
                            $fiberConnId,
                            $fiberConn,
                            &$connections,
                            &$requestBuffers,
                            &$connectionLastActivity,
                            &$requestLogged,
                            &$writeBuffers,
                            &$writableConnections,
                            &$pendingClose
                        ): void {
                            enqueueSseWriteAndAwaitDrain(
                                $fiberConnId,
                                $fiberConn,
                                $data,
                                $connections,
                                $requestBuffers,
                                $connectionLastActivity,
                                $requestLogged,
                                $writeBuffers,
                                $writableConnections,
                                $pendingClose
                            );
                        }
                    );
                }
                return handleRequest(
                    $fiberRawRequest,
                    $runtime,
                    $runtimeError,
                    $asyncBizAdapters,
                    $instanceName,
                    $workerId,
                    $port,
                    $requestCount,
                    $activeRequests,
                    \count($connections),
                    $startTime,
                    $originToken,
                    $originTokenValidationEnabled,
                    $originTokenHeader,
                    $originTokenAllowLocal
                );
            } catch (\Weline\Framework\Runtime\RequestExitException $e) {
                throw $e;
            } catch (\Error $e) {
                if ($WLS_UOPZ_EXIT_GUARD && \str_contains($e->getMessage(), 'uopz')) {
                    WlsLogger::warning_('SSL Worker：exit()/die() 已由 uopz 拦截');
                    return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                        . "Connection: close\r\nContent-Length: 52\r\n\r\n"
                        . "Internal error: exit()/die() not allowed in WLS request\n";
                }
                throw $e;
            } finally {
                // 统一清台：无论正常/异常/提前返回，都清理请求级上下文，避免 Fiber 间串味。
                wlsFiberRequestContextLeave();
            }
        });

        $fiberScheduler->registerFiber();
        try {
            $requestFiber->start();
        } catch (\Weline\Framework\Runtime\RequestExitException) {
        } catch (\Throwable $e) {
            WlsLogger::error_('Fiber 启动异常: ' . $e->getMessage());
        }

        if ($requestFiber->isTerminated()) {
            $fiberScheduler->unregisterFiber();
            $fiberResponse = '';
            try {
                $fiberResponse = (string) ($requestFiber->getReturn() ?? '');
            } catch (\Throwable) {
            }
            $handleDurationMs = (\microtime(true) - $handleStartTime) * 1000;
            $fiberResponse = injectWlsProcessTimeHeader($fiberResponse, $handleDurationMs);
            sslFinalizeHttpResponseAfterHandle(
                $conn,
                $connId,
                $rawRequest,
                $fiberResponse,
                $handleStartTime,
                $isSseProtocolRequest,
                $ipcDraining,
                $connections,
                $requestBuffers,
                $connectionLastActivity,
                $requestLogged,
                $writeBuffers,
                $writableConnections,
                $pendingClose,
                $longLivedConnections,
                $ipcClient,
                $instanceName,
                $activeRequests
            );
        } elseif ($requestFiber->isSuspended()) {
            $activeFibers[$fiberConnId] = [
                'fiber' => $requestFiber,
                'conn' => $fiberConn,
                'rawRequest' => $rawRequest,
                'handleStartTime' => $handleStartTime,
                'context' => \Weline\Framework\Runtime\WlsFiberContext::capture(),
                'suspended_at' => \time(),
                'last_activity' => \time(),
                'is_long_lived' => $isLongLived,
                'is_sse_protocol' => $isSseProtocolRequest,
            ];
            WlsLogger::info_("请求进入 Fiber 异步模式 (connId: {$connId})");
            $nowSat = \time();
            if ($longLivedMaxActive > 0) {
                $isSaturated = \count($longLivedConnections) >= $longLivedMaxActive;
                if (
                    $isSaturated
                    && !$longLivedSaturationReported
                    && ($nowSat - $lastLongLivedSaturationReport) >= $longLivedSaturationInterval
                ) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturation(
                            $workerId,
                            $port,
                            \count($longLivedConnections),
                            $longLivedMaxActive,
                            \count($activeFibers),
                            $fiberMaxActive
                        ));
                        $lastLongLivedSaturationReport = $nowSat;
                        $longLivedSaturationReported = true;
                        $longLivedSaturationCleared = false;
                        WlsLogger::warning_(
                            '长连接饱和上报 (long_lived_count=' . \count($longLivedConnections)
                            . ", max={$longLivedMaxActive})"
                        );
                    }
                } elseif (!$isSaturated && $longLivedSaturationReported && !$longLivedSaturationCleared) {
                    if ($ipcClient && $ipcClient->isConnected()) {
                        $ipcClient->send(\Weline\Server\IPC\ControlMessage::workerSaturationCleared(
                            $workerId,
                            $port,
                            \count($longLivedConnections),
                            $longLivedMaxActive
                        ));
                        $longLivedSaturationReported = false;
                        $longLivedSaturationCleared = true;
                        WlsLogger::info_(
                            '长连接饱和解除 (long_lived_count=' . \count($longLivedConnections) . ')'
                        );
                    }
                }
            }
        } else {
            $fiberScheduler->unregisterFiber();
            $activeRequests = \max(0, $activeRequests - 1);
        }
        continue;
    }

    // 处理可写连接
    wlsSslFlushQueuedWrites(
        $writableConnections,
        $writeBuffers,
        $connections,
        $requestBuffers,
        $connectionLastActivity,
        $requestLogged,
        $pendingClose,
        $longLivedConnections
    );
    
    // 重置连续错误计数（本轮循环成功完成）
    $consecutiveErrors = 0;
    
    } catch (\Throwable $loopException) {
        // Workerman 模式：捕获所有异常，防止 Worker 意外退出
        $consecutiveErrors++;
        $errorMessage = $loopException->getMessage();
        $errorFile = $loopException->getFile();
        $errorLine = $loopException->getLine();
        
        // 记录错误日志
        w_log_error("[WLS-SSL Worker #{$workerId}] 事件循环异常 ({$consecutiveErrors}/{$maxConsecutiveErrors}): {$errorMessage} in {$errorFile}:{$errorLine}");
        WlsLogger::error_("事件循环异常: {$errorMessage}");
        
        // 刷新日志缓冲区
        WlsLogger::flush_(true);
        
        // 如果连续错误过多，优雅退出让 Master 重启
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            w_log_error("[WLS-SSL Worker #{$workerId}] 连续错误过多，优雅退出");
            $gracefulExit("连续错误过多 ({$consecutiveErrors} 次)");
        }
        
        // 短暂休眠后继续（避免错误风暴）
        \Weline\Framework\Runtime\SchedulerSystem::usleep(10000); // 10ms
        continue;
    }
}

/**
 * Step-1: accept 连接并放入下一状态（plain/read 或 defer-ssl/peek）。
 *
 * @param resource|null $socket
 * @param array<int, resource> $read
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, array{conn: resource, peerName: string, buffer: string}> $pendingPeek
 * @param array<int, float> $pendingPeekStartTimes
 */
function wlsSslAcceptNewConnections(
    mixed $socket,
    array &$read,
    bool $deferSsl,
    array &$pendingPeek,
    array &$pendingPeekStartTimes,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    bool $isDev
): void {
    if (!$socket || !\is_resource($socket) || !\in_array($socket, $read, true)) {
        return;
    }

    $conn = @\stream_socket_accept($socket, 0);
    if ($conn) {
        $connId = \get_resource_id($conn);
        $peerNameRaw = @\stream_socket_get_name($conn, true);
        $peerName = \is_string($peerNameRaw) ? $peerNameRaw : 'unknown-peer';
        if ($isDev) {
            WlsLogger::info_("新连接: {$peerName} (connId: {$connId})");
        }

        \stream_set_blocking($conn, false);
        if ($deferSsl) {
            $pendingPeek[$connId] = [
                'conn' => $conn,
                'peerName' => $peerName,
                'buffer' => '',
            ];
            $pendingPeekStartTimes[$connId] = \microtime(true);
        } else {
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
        }
    }

    $key = \array_search($socket, $read, true);
    if ($key !== false) {
        unset($read[$key]);
    }
}

/**
 * Step-2: defer-ssl peek 状态推进（直接尝试握手，失败进入 pendingHandshakes 重试）。
 *
 * @param array<int, array{conn: resource, peerName: string, buffer: string}> $pendingPeek
 * @param array<int, float> $pendingPeekStartTimes
 * @param array<int, array{conn: resource, peerName: string, phase?: string, started?: bool}> $pendingHandshakes
 * @param array<int, float> $handshakeStartTimes
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<string, mixed> $deferSslOptions
 */
function wlsSslAdvancePeekState(
    array &$pendingPeek,
    array &$pendingPeekStartTimes,
    array &$pendingHandshakes,
    array &$handshakeStartTimes,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array $read,
    array $deferSslOptions,
    int $cryptoMethod,
    bool $isDev
): void {
    if ($pendingPeek === []) {
        return;
    }

    $peekTimeout = 5.0;
    $completedPeeks = [];
    $failedPeeks = [];

    foreach ($pendingPeek as $connId => $peekInfo) {
        $conn = $peekInfo['conn'];
        $peerName = $peekInfo['peerName'];
        $startTime = $pendingPeekStartTimes[$connId] ?? \microtime(true);
        $elapsed = \microtime(true) - $startTime;
        if ($elapsed > $peekTimeout) {
            $failedPeeks[] = $connId;
            WlsLogger::warning_("Peek 超时: {$peerName} (connId: {$connId})");
            continue;
        }

        foreach ($deferSslOptions as $optName => $optValue) {
            \stream_context_set_option($conn, 'ssl', $optName, $optValue);
        }

        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);
        if ($isDev) {
            WlsLogger::info_("SSL 握手尝试: {$peerName} (connId: {$connId}), result: " . \var_export($cryptoResult, true));
        }

        if ($cryptoResult === true) {
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
            $completedPeeks[] = $connId;
            continue;
        }

        $pendingHandshakes[$connId] = [
            'conn' => $conn,
            'peerName' => $peerName,
            'phase' => 'pending',
        ];
        $handshakeStartTimes[$connId] = \microtime(true);
        $completedPeeks[] = $connId;

        if ($isDev && $cryptoResult === false) {
            $error = \error_get_last();
            $errorMsg = $error['message'] ?? 'unknown';
            WlsLogger::info_("SSL 握手首次返回 false，加入重试队列: {$peerName} (connId: {$connId}) - {$errorMsg}");
        }
    }

    foreach ($completedPeeks as $connId) {
        unset($pendingPeek[$connId], $pendingPeekStartTimes[$connId]);
    }

    foreach ($failedPeeks as $connId) {
        if (isset($pendingPeek[$connId]['conn'])) {
            safeCloseStream($pendingPeek[$connId]['conn']);
        }
        unset($pendingPeek[$connId], $pendingPeekStartTimes[$connId]);
    }
}

/**
 * Step-3: 握手重试状态推进（读写就绪触发 retry，成功后进入可读 connections）。
 *
 * @param array<int, array{conn: resource, peerName: string, phase?: string, started?: bool}> $pendingHandshakes
 * @param array<int, float> $handshakeStartTimes
 * @param array<int, resource> $connections
 * @param array<int, string> $requestBuffers
 * @param array<int, int> $connectionLastActivity
 * @param array<int, bool> $requestLogged
 * @param array<int, resource> $read
 * @param array<int, resource> $write
 */
function wlsSslAdvanceHandshakeState(
    array &$pendingHandshakes,
    array &$handshakeStartTimes,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array $read,
    array $write,
    int|false $changed,
    int $cryptoMethod,
    bool $isDev
): void {
    if ($pendingHandshakes === []) {
        return;
    }

    $handshakeTimeout = 5.0;
    $completedHandshakes = [];
    $failedHandshakes = [];

    if ($isDev) {
        static $lastPendingHandshakeLogAt = 0.0;
        static $lastPendingHandshakeCount = -1;
        $pendingCount = \count($pendingHandshakes);
        $now = \microtime(true);
        // 节流：数量变化立即记录；数量不变时最多每秒记录一次，避免日志风暴淹没关键事件。
        if ($pendingCount !== $lastPendingHandshakeCount || ($now - $lastPendingHandshakeLogAt) >= 1.0) {
            WlsLogger::info_('握手循环待处理连接数: ' . $pendingCount);
            $lastPendingHandshakeLogAt = $now;
            $lastPendingHandshakeCount = $pendingCount;
        }
    }

    foreach ($pendingHandshakes as $connId => $handshakeInfo) {
        $conn = $handshakeInfo['conn'];
        $peerName = $handshakeInfo['peerName'];
        $startTime = $handshakeStartTimes[$connId] ?? \microtime(true);
        $elapsed = \microtime(true) - $startTime;
        if ($elapsed > $handshakeTimeout) {
            $failedHandshakes[] = $connId;
            WlsLogger::warning_("SSL 握手超时: {$peerName} (connId: {$connId})");
            continue;
        }

        $shouldAttempt = !isset($handshakeInfo['started']);
        if (!$shouldAttempt && $changed !== false) {
            foreach ($read as $r) {
                if (\is_resource($r) && \get_resource_id($r) === $connId) {
                    $shouldAttempt = true;
                    break;
                }
            }
            if (!$shouldAttempt) {
                foreach ($write as $w) {
                    if (\is_resource($w) && \get_resource_id($w) === $connId) {
                        $shouldAttempt = true;
                        break;
                    }
                }
            }
        }

        if (!$shouldAttempt) {
            continue;
        }

        $pendingHandshakes[$connId]['started'] = true;
        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);

        if ($cryptoResult === true) {
            $completedHandshakes[] = $connId;
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            continue;
        }

        if ($cryptoResult === 0) {
            continue;
        }

        $error = \error_get_last();
        $errorMsg = $error['message'] ?? 'unknown';
        $failedHandshakes[] = $connId;
        logSslHandshakeFailure($peerName, $connId, $errorMsg);
    }

    foreach ($completedHandshakes as $connId) {
        if (!isset($pendingHandshakes[$connId])) {
            continue;
        }
        $conn = $pendingHandshakes[$connId]['conn'];
        $connections[$connId] = $conn;
        $requestBuffers[$connId] = '';
        $connectionLastActivity[$connId] = \time();
        unset($pendingHandshakes[$connId], $handshakeStartTimes[$connId]);
    }

    foreach ($failedHandshakes as $connId) {
        if (isset($pendingHandshakes[$connId]['conn'])) {
            safeCloseStream($pendingHandshakes[$connId]['conn']);
        }
        unset(
            $pendingHandshakes[$connId],
            $handshakeStartTimes[$connId],
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId]
        );
    }
}

/**
 * 将 writeBuffers 中的数据写入 SSL 流（非阻塞 fwrite，单连接每轮最多尝试若干次）。
 * 供事件循环在 Fiber tick 之后及早调用，减轻 SSE 与同 Worker 其它 HTTP 请求之间的写方向头阻塞。
 *
 * @param array<int|string, resource> $writableConnections
 * @param array<int|string, string> $writeBuffers
 */
function wlsSslFlushQueuedWrites(
    array &$writableConnections,
    array &$writeBuffers,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$pendingClose,
    array &$longLivedConnections
): void {
    $maxBytesPerConnectionPerLoop = 131072; // 128KB，分片推进上限
    $maxChunkPerWrite = 16384; // 16KB，单次写入分片
    foreach ($writableConnections as $connId => $conn) {
        if (!isset($writeBuffers[$connId]) || $writeBuffers[$connId] === '') {
            continue;
        }
        if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            unset($writeBuffers[$connId], $writableConnections[$connId]);
            continue;
        }

        $initialBufferLen = \strlen($writeBuffers[$connId]);
        $totalWrittenThisLoop = 0;
        $maxWriteAttempts = 16;
        $writeAttempts = 0;

        while (isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '' && $writeAttempts < $maxWriteAttempts) {
            $writeAttempts++;
            if (!\is_resource($conn) || !\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                unset($longLivedConnections[$connId]);
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }
            $buffer = $writeBuffers[$connId];
            $bufferLen = \strlen($buffer);
            if ($totalWrittenThisLoop >= $maxBytesPerConnectionPerLoop) {
                // 单连接本轮写预算耗尽，交回事件循环，避免写阶段头阻塞。
                break;
            }
            $remainingBudget = $maxBytesPerConnectionPerLoop - $totalWrittenThisLoop;
            $writeLen = \min($bufferLen, $maxChunkPerWrite, $remainingBudget);
            if ($writeLen <= 0) {
                break;
            }

            $written = @\fwrite($conn, \substr($buffer, 0, $writeLen));

            if ($written === false) {
                WlsLogger::warning_("缓冲区写入失败 (connId: {$connId}, 剩余: {$bufferLen} 字节)");
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId], $pendingClose[$connId]);
                unset($longLivedConnections[$connId]);
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }

            $connectionLastActivity[$connId] = \time();

            if ($written === 0) {
                break;
            }

            $totalWrittenThisLoop += $written;
            $writeBuffers[$connId] = \substr($buffer, $written);

            if ($writeBuffers[$connId] === '' || $writeBuffers[$connId] === false) {
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);

                if (isset($pendingClose[$connId])) {
                    safeCloseStream($conn);
                    unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $pendingClose[$connId]);
                    unset($longLivedConnections[$connId]);
                }
                if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($initialBufferLen)) {
                    \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
                }
                break;
            }
        }

    }
}

function isRequestComplete(string $data): bool
{
    $headerEnd = \strpos($data, "\r\n\r\n");
    if ($headerEnd === false) {
        return false;
    }
    
    if (\preg_match('/Content-Length:\s*(\d+)/i', $data, $matches)) {
        $contentLength = (int) $matches[1];
        $bodyStart = $headerEnd + 4;
        $currentBodyLength = \strlen($data) - $bodyStart;
        return $currentBodyLength >= $contentLength;
    }
    
    return true;
}

function isKeepAlive(string $rawRequest): bool
{
    // HTTP/1.1 默认启用 Keep-Alive，除非显式指定 Connection: close
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    
    // 检查 Connection 头
    if (\preg_match('/Connection:\s*(\S+)/i', $rawRequest, $matches)) {
        $connection = \strtolower(\trim($matches[1]));
        // 如果显式指定 close，则关闭连接
        if ($connection === 'close') {
            return false;
        }
        // 如果显式指定 keep-alive，则保持连接
        if ($connection === 'keep-alive') {
            return true;
        }
    }
    
    // HTTP/1.1 默认 Keep-Alive，HTTP/1.0 默认关闭
    return $isHttp11;
}

function getHeaderValue(string $rawRequest, string $headerName): ?string
{
    $pattern = '/^' . \preg_quote($headerName, '/') . ':\s*([^\r\n]+)/im';
    if (\preg_match($pattern, $rawRequest, $matches)) {
        $value = \trim($matches[1]);
        return $value === '' ? null : $value;
    }
    return null;
}

/**
 * 从 Cookie 头中解析指定 name 的值
 */
function getCookieValue(string $cookieHeader, string $name): ?string
{
    if ($cookieHeader === '') {
        return null;
    }
    $name = \preg_quote($name, '/');
    if (\preg_match('/\b' . $name . '=([^;\s]+)/', $cookieHeader, $m)) {
        $v = \trim($m[1], '"');
        return $v === '' ? null : $v;
    }
    return null;
}

/**
 * 校验“开发模式+后台登录”下发放的健康检查放行 Cookie（与 PHP 端生成逻辑一致）
 * 仅当 env 中配置了 wls.health_cookie_secret 时生效。
 */
function isHealthAllowCookieValid(string $cookieValue, array $env): bool
{
    $secret = $env['wls']['health_cookie_secret'] ?? null;
    if ($secret === null || $secret === '') {
        return false;
    }
    $slot = \floor(\time() / 3600);
    $expected = \hash_hmac('sha256', 'wls_health_' . $slot, (string) $secret);
    if (\hash_equals($expected, $cookieValue)) {
        return true;
    }
    $expectedPrev = \hash_hmac('sha256', 'wls_health_' . ($slot - 1), (string) $secret);
    return \hash_equals($expectedPrev, $cookieValue);
}

/**
 * 注入 WLS 处理耗时响应头。
 * 仅添加 header，不修改 body / Content-Length，避免 Content-Length mismatch 导致浏览器 loading 挂死。
 * 前端通过 Server-Timing API 读取：performance.getEntriesByType('navigation')[0].serverTiming
 */
function injectWlsProcessTimeHeader(string $response, float $durationMs): string
{
    $pos = \strpos($response, "\r\n\r\n");
    if ($pos === false) {
        return $response;
    }
    $ms = \round($durationMs, 2);
    $headers = "X-WLS-Process-Time: {$ms}\r\nServer-Timing: wls;dur={$ms};desc=\"WLS Process\"\r\n";
    return \substr_replace($response, $headers, $pos + 2, 0);
}

/**
 * 安全关闭 stream/socket 资源，避免重复关闭触发 warning。
 */
function safeCloseStream(mixed $conn): void
{
    if (!\is_resource($conn)) {
        return;
    }
    if (!\in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
        return;
    }

    $connId = \get_resource_id($conn);
    $backtrace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    $caller = $backtrace[1] ?? [];
    $callerLine = ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? '?');
    $peerName = @\stream_socket_get_name($conn, true);
    if (!\is_string($peerName) || $peerName === '') {
        $peerName = 'unknown-peer';
    }
    WlsLogger::debug_("关闭连接 {$connId} peer={$peerName} caller={$callerLine}");

    try {
        \fclose($conn);
    } catch (\Throwable $e) {
        // 连接关闭存在竞态（另一处分支已关闭），这里静默兜底，避免打断事件循环。
        if (!\str_contains($e->getMessage(), 'supplied resource is not a valid stream resource')) {
            throw $e;
        }
    }
}

/**
 * 判断是否属于可预期的 TLS 握手失败（客户端主动中断、证书不信任等）。
 */
function isBenignSslHandshakeFailure(string $errorMsg): bool
{
    $msg = \strtolower($errorMsg);
    $patterns = [
        'certificate unknown',
        'alert unknown ca',
        'connection reset by peer',
        'software caused connection abort',
        '你的主机中的软件中止了一个已建立的连接',
        'sslv3 alert certificate unknown',
    ];

    foreach ($patterns as $pattern) {
        if (\str_contains($msg, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * 统一记录握手失败日志：可预期失败降级为 info，保留关键告警信噪比。
 */
function logSslHandshakeFailure(string $peerName, int $connId, string $errorMsg): void
{
    $message = "SSL 握手失败: {$peerName} (connId: {$connId}) - {$errorMsg}";
    if (isBenignSslHandshakeFailure($errorMsg)) {
        WlsLogger::info_($message . ' [benign]');
        return;
    }

    WlsLogger::warning_($message);
}

/**
 * Fiber 请求开始前清理并初始化请求级上下文，避免前一请求残留污染当前 Fiber。
 */
function wlsFiberRequestContextEnter(mixed $conn): void
{
    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
    \Weline\Framework\Http\Sse\SseContext::clearWriteCallback();
}

/**
 * Fiber 请求结束后统一清台（成功/异常均执行）。
 */
function wlsFiberRequestContextLeave(): void
{
    if (\session_status() === PHP_SESSION_ACTIVE) {
        @\session_write_close();
    }
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Http\Request::class);
    try {
        $resolvedClass = \Weline\Framework\Manager\ObjectManager::parserClass(\Weline\Framework\Http\Request::class);
        if ($resolvedClass !== \Weline\Framework\Http\Request::class) {
            \Weline\Framework\Manager\ObjectManager::removeInstance($resolvedClass);
        }
    } catch (\Throwable) {
    }
}

/**
 * 将 SSE 数据接入 worker 现有的非阻塞写缓冲，并协作等待缓冲区排空。
 */
function enqueueSseWriteAndAwaitDrain(
    int $connId,
    mixed $conn,
    string $data,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose
): void {
    if ($data === '') {
        return;
    }

    $streamOk = isset($connections[$connId])
        && \is_resource($conn)
        && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);

    if (!$streamOk) {
        if (\is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true)) {
            safeCloseStream($conn);
        }
        unset(
            $connections[$connId],
            $requestBuffers[$connId],
            $connectionLastActivity[$connId],
            $requestLogged[$connId],
            $writeBuffers[$connId],
            $writableConnections[$connId],
            $pendingClose[$connId]
        );
        return;
    }

    $writeBuffers[$connId] = ($writeBuffers[$connId] ?? '') . $data;
    $writableConnections[$connId] = $conn;
    $connectionLastActivity[$connId] = \time();

    $maxWaitSpins = 2000;
    $spins = 0;
    while (isset($connections[$connId]) && isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '') {
        $spins++;
        $streamStillOpen = \is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);
        if (!$streamStillOpen) {
            unset(
                $connections[$connId],
                $requestBuffers[$connId],
                $connectionLastActivity[$connId],
                $requestLogged[$connId],
                $writeBuffers[$connId],
                $writableConnections[$connId],
                $pendingClose[$connId]
            );
            return;
        }

        $connectionLastActivity[$connId] = \time();
        if ($spins >= $maxWaitSpins) {
            // 等待排空采用分片预算，避免单个长连接 Fiber 在此处长期驻留。
            break;
        }
        \Weline\Framework\Runtime\SchedulerSystem::yield();
    }
}

/**
 * SSL Worker：请求处理完成后写回响应（与同步路径一致，供 Fiber 同步完成与 tick 恢复后调用）。
 * $response 须已含 injectWlsProcessTimeHeader。
 *
 * @param mixed $ipcClient Control client 或 null
 */
function sslFinalizeHttpResponseAfterHandle(
    mixed $conn,
    int $connId,
    string $rawRequest,
    string $response,
    float $handleStartTime,
    bool $isSseProtocolRequest,
    bool $ipcDraining,
    array &$connections,
    array &$requestBuffers,
    array &$connectionLastActivity,
    array &$requestLogged,
    array &$writeBuffers,
    array &$writableConnections,
    array &$pendingClose,
    array &$longLivedConnections,
    mixed $ipcClient,
    string $instanceName,
    int &$activeRequests,
): void {
    $responseStatus = 200;
    if (\preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $response, $statusMatches)) {
        $responseStatus = (int) $statusMatches[1];
    }

    if ($responseStatus === 400) {
        $requestLine = '';
        if (\preg_match('/^([^\r\n]+)/', $rawRequest, $lineMatches)) {
            $requestLine = (string) ($lineMatches[1] ?? '');
        }
        $responsePreview = \substr($response, 0, 500);
        WlsLogger::warning_("HTTP 400 响应 (connId: {$connId}, 请求: {$requestLine}, 响应预览: {$responsePreview})");
    }
    if ($responseStatus >= 500) {
        $requestLine = '';
        if (\preg_match('/^([^\r\n]+)/', $rawRequest, $lineMatches)) {
            $requestLine = (string) ($lineMatches[1] ?? '');
        }
    }
    $responseBytes = 0;
    $requestHost = getHeaderValue($rawRequest, 'Host') ?? '';
    if (\str_contains($requestHost, ':')) {
        $requestHost = (string) \explode(':', $requestHost, 2)[0];
    }

    $activeRequests = \max(0, $activeRequests - 1);

    $responseLenPre = \strlen($response);
    WlsLogger::info_("Worker 即将写回响应 connId={$connId} len={$responseLenPre}");

    $actualSseStarted = $isSseProtocolRequest
        && (
            \Weline\Framework\Http\Sse\SseContext::isSseEnabled()
            || \Weline\Framework\Http\Sse\SseContext::isHeadersSent()
        );
    if ($isSseProtocolRequest && !$actualSseStarted && $response !== '') {
        $statusLine = \trim((string) (\strtok($response, "\r\n") ?: ''));
        WlsLogger::warning_(
            'SSE 路径未实际启动流式响应，普通响应将按 HTTP 回写 (connId: '
            . $connId . ', status: ' . $statusLine . ', len: ' . \strlen($response) . ')'
        );
    }
    $isSseMode = $actualSseStarted;
    $keepAlive = isKeepAlive($rawRequest);
    $bufferedBytesBeforeWrite = isset($writeBuffers[$connId]) ? \strlen($writeBuffers[$connId]) : 0;
    $forceCloseAfterResponse = \Weline\Server\Service\WorkerResponseMemoryGuard::shouldForceConnectionClose(
        $keepAlive,
        $isSseMode,
        $responseLenPre,
        $bufferedBytesBeforeWrite
    );
    if ($forceCloseAfterResponse && !$isSseMode) {
        $response = \Weline\Server\Service\WorkerResponseMemoryGuard::forceConnectionCloseHeader($response);
    }

    if (!$isSseMode) {
        $responseLen = \strlen($response);
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            $writeBuffers[$connId] .= $response;
            $writableConnections[$connId] = $conn;
            WlsLogger::info_("Worker 响应追加到缓冲区 connId={$connId} len={$responseLen}");
            goto ssl_finalize_skip_write;
        }

        $totalWritten = 0;
        $streamOk = \is_resource($conn) && \in_array(\get_resource_type($conn), ['stream', 'Socket'], true);
        if (!$streamOk) {
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
            \Weline\Framework\Http\Sse\SseContext::reset();

            return;
        }

        $immediateRetries = 0;
        $maxImmediateRetries = 10;

        while ($totalWritten < $responseLen && $immediateRetries < $maxImmediateRetries) {
            $remaining = \substr($response, $totalWritten);
            $written = @\fwrite($conn, $remaining);

            if ($written === false) {
                safeCloseStream($conn);
                unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
                \Weline\Framework\Http\Sse\SseContext::reset();

                return;
            }

            if ($written === 0) {
                break;
            }

            $totalWritten += $written;
            $immediateRetries++;
        }

        if ($totalWritten >= $responseLen) {
            WlsLogger::info_("Worker 已写完响应 connId={$connId} written={$totalWritten}");
            $responseBytes = $totalWritten;
            goto ssl_finalize_skip_write;
        }

        $responseBytes = $totalWritten;
        $writeBuffers[$connId] = \substr($response, $totalWritten);
        $writableConnections[$connId] = $conn;
        WlsLogger::info_(
            'Worker 响应入队 connId=' . $connId . ' written=' . $totalWritten . ' total=' . $responseLen
            . ' remaining=' . ($responseLen - $totalWritten)
        );

        ssl_finalize_skip_write:
    } else {
        WlsLogger::info_("SSE 流式响应完成 (connId: {$connId})");
    }

    \Weline\Framework\Http\Sse\SseContext::reset();
    $connectionLastActivity[$connId] = \time();

    if ($ipcClient && $ipcClient->isConnected()) {
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::telemetry(
            $instanceName,
            $requestHost,
            $responseStatus,
            (int) \round((\microtime(true) - $handleStartTime) * 1000, 2),
            $responseBytes
        ));
    }

    $shouldClose = $isSseMode || !$keepAlive || $ipcDraining || $forceCloseAfterResponse;
    if ($shouldClose) {
        $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';

        if ($hasBufferedData) {
            $pendingClose[$connId] = true;
        } else {
            safeCloseStream($conn);
            unset($connections[$connId], $requestBuffers[$connId], $connectionLastActivity[$connId], $requestLogged[$connId], $writeBuffers[$connId], $writableConnections[$connId]);
            if (isset($longLivedConnections[$connId])) {
                unset($longLivedConnections[$connId]);
            }
            if (\Weline\Server\Service\WorkerResponseMemoryGuard::shouldCompactAfterDrain($responseLenPre)) {
                \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
            }
        }
    }
}

function handleRequest(
    string $rawRequest,
    ?\Weline\Framework\Runtime\WlsRuntime $runtime,
    ?string $runtimeError,
    \Weline\Server\Runtime\Async\AsyncBizAdapters $asyncBizAdapters,
    string $instanceName,
    int $workerId,
    int $port,
    int $requestCount,
    int $activeRequests,
    int $connectionCount,
    int $startTime,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal
): string {
    // 解析请求 URI（parse_url 失败时返回 false，?? 无法兜底，须显式归一为 string）
    $uri = '/';
    if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
        $path = \parse_url($matches[1], PHP_URL_PATH);
        $uri = (\is_string($path) && $path !== '') ? $path : '/';
    }
    $method = 'GET';
    if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
        $method = $matches[1];
    }
    
    // 获取客户端 IP
    $clientIp = '127.0.0.1';
    $cfConnectingIp = getHeaderValue($rawRequest, 'CF-Connecting-IP');
    if ($cfConnectingIp !== null) {
        $clientIp = $cfConnectingIp;
    } elseif (\preg_match('/X-Real-IP:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    } elseif (\preg_match('/X-Forwarded-For:\s*([^\r\n,]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    }
    
    // 判断是否本地请求
    $localIps = ['127.0.0.1', '::1', 'localhost'];
    $isLocal = \in_array($clientIp, $localIps, true) || \strpos($clientIp, '192.168.') === 0 || \strpos($clientIp, '10.') === 0;
    
    // ========== 健康检查接口（仅本地访问，不受维护模式影响） ==========
    if ($uri === '/_wls/health') {
        // 检查请求是否要求 Keep-Alive（HTTP/1.1 默认 keep-alive）
        $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
        $hasClose = \stripos($rawRequest, 'Connection: close') !== false;
        $keepAlive = $isHttp11 && !$hasClose;
        // 可选：允许外网访问健康检查（仅测试/内网环境建议开启，生产建议关闭）
        $healthAllowRemote = false;
        $env = [];
        if (\defined('BP') && \is_file(BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php')) {
            $env = @include BP . 'app' . \DIRECTORY_SEPARATOR . 'etc' . \DIRECTORY_SEPARATOR . 'env.php';
            $env = \is_array($env) ? $env : [];
            $w = $env['wls'] ?? [];
            $wlsServers = \is_array($w['servers'] ?? null) ? $w['servers'] : [];
            $healthAllowRemote = (bool)(($wlsServers[$instanceName]['health_allow_remote'] ?? null)
                ?? $w['health_allow_remote'] ?? false);
        }
        // 非本地且未全局放行时：若带有“开发模式+后台登录”下发放的签名 Cookie 或同源请求则放行
        $healthAllowedByCookie = false;
        $healthAllowedBySameOrigin = false;
        if (!$isLocal && !$healthAllowRemote) {
            $cookieHeader = getHeaderValue($rawRequest, 'Cookie') ?? '';
            $allowCookie = getCookieValue($cookieHeader, 'wls_health_allow');
            if ($allowCookie !== null && isHealthAllowCookieValid($allowCookie, $env)) {
                $healthAllowedByCookie = true;
            }
            // 同源请求放行：开发工具面板等从后台页面发起的 fetch 可访问；直接导航时 Referer 同站点也可放行
            $hostHeader = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
            $originHeader = \trim((string)(getHeaderValue($rawRequest, 'Origin') ?? ''));
            if ($hostHeader !== '') {
                if ($originHeader !== '' && \preg_match('#^https?://([^/]+)#i', $originHeader, $om)) {
                    if (\strcasecmp($om[1], $hostHeader) === 0) {
                        $healthAllowedBySameOrigin = true;
                    }
                } else {
                    $refererHeader = \trim((string)(getHeaderValue($rawRequest, 'Referer') ?? ''));
                    if ($refererHeader !== '' && \preg_match('#^https?://([^/]+)#i', $refererHeader, $rm)) {
                        if (\strcasecmp($rm[1], $hostHeader) === 0) {
                            $healthAllowedBySameOrigin = true;
                        }
                    }
                }
            }
        }
        if (!$isLocal && !$healthAllowRemote && !$healthAllowedByCookie && !$healthAllowedBySameOrigin) {
            // 非本地请求且未配置允许且无有效放行 Cookie：返回 403（极简响应）
            return $keepAlive
                ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: keep-alive\r\n\r\nForbidden"
                : "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: close\r\n\r\nForbidden";
        }
        
        // 高性能健康检查：使用极简响应，避免 json_encode/memory_get_usage 开销
        // 完整信息可通过 /_wls/health?detail=1 获取
        $wantsDetail = \strpos($rawRequest, 'detail=1') !== false || \strpos($rawRequest, 'detail=true') !== false;
        
        if ($wantsDetail) {
            // 详细模式：返回完整信息
            $health = [
                'status' => 'healthy',
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'connections' => $connectionCount,
                'active_requests' => $activeRequests - 1,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => \time() - $startTime,
                'php_version' => PHP_VERSION,
                'ssl' => true,
                'timestamp' => \time(),
            ];
            $body = \json_encode($health, JSON_UNESCAPED_UNICODE);
            $len = \strlen($body);
            return $keepAlive
                ? "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
                : "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
        }
        
        // 极简模式（默认）：直接返回静态字符串，最大性能
        return $keepAlive
            ? "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nOK"
            : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
    }
    // ========== 健康检查接口结束 ==========

    // ========== Origin Token 回源校验（可选）==========
    if ($originTokenValidationEnabled && $originToken !== '') {
        $isLocalClient = $isLocal;
        if (!$originTokenAllowLocal || !$isLocalClient) {
            $receivedToken = getHeaderValue($rawRequest, $originTokenHeader) ?? '';
            if (!\hash_equals($originToken, $receivedToken)) {
                $forbiddenBody = '{"error":true,"message":"Origin token validation failed"}';
                return "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($forbiddenBody) . "\r\nConnection: close\r\n\r\n{$forbiddenBody}";
            }
        }
    }
    // ========== Origin Token 回源校验结束 ==========

    // ========== ACME HTTP-01 校验（WLS 虚拟：从 generated/acme-http01 按域名返回 keyAuth，验证完由证书流程删除） ==========
    if ($method === 'GET' && \preg_match('#^/\.well-known/acme-challenge/([^/]+)/?$#', $uri, $acmeMatches)) {
        $requestToken = $acmeMatches[1];
        $hostHeader = \trim((string)(getHeaderValue($rawRequest, 'Host') ?? ''));
        if (\strpos($hostHeader, ':') !== false) {
            $hostHeader = \trim((string)\explode(':', $hostHeader, 2)[0]);
        }
        $safeDomain = \preg_replace('/[^a-z0-9_]/', '', \str_replace('.', '_', \strtolower($hostHeader)));
        $safeDomain = $safeDomain !== '' ? $safeDomain : 'default';
        if (\defined('BP')) {
            $acmeFile = \rtrim(BP, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'generated' . \DIRECTORY_SEPARATOR . 'acme-http01' . \DIRECTORY_SEPARATOR . $safeDomain . '.json';
            if (\is_file($acmeFile)) {
                $json = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($acmeFile);
                if ($json !== false) {
                    $data = \json_decode($json, true);
                    if (\is_array($data) && isset($data['keyAuth']) && \is_string($data['keyAuth'])
                        && (string)($data['token'] ?? '') === (string)$requestToken) {
                        $body = $data['keyAuth'];
                        $len = \strlen($body);
                        $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
                        $hasClose = \stripos($rawRequest, 'Connection: close') !== false;
                        $keepAlive = $isHttp11 && !$hasClose;
                        return $keepAlive
                            ? "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
                            : "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=UTF-8\r\nCache-Control: no-store\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
                    }
                }
            }
        }
        $notFoundBody = 'ACME challenge not found';
        return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Length: " . \strlen($notFoundBody) . "\r\nConnection: close\r\n\r\n{$notFoundBody}";
    }
    // ========== ACME HTTP-01 校验结束 ==========
    
    // ========== 静态文件处理（WLS 模式特有） ==========
    $staticResponse = handleStaticFile($uri, $rawRequest);
    if ($staticResponse !== null) {
        $cacheInfo = $WLS_LAST_STATIC_CACHE ?? null;
        $cacheStatus = $cacheInfo['status'] ?? 'miss';
        $cacheUri = $cacheInfo['uri'] ?? $uri;
        WlsLogger::info_(__('静态文件缓存: %{1} %{2}', [\strtoupper($cacheStatus), $cacheUri]));
        return $staticResponse;
    }
    // ========== 静态文件处理结束 ==========
    
    // 如果运行时初始化失败，返回错误
    if ($runtime === null) {
        WlsLogger::error_("运行时未初始化，返回错误: {$runtimeError}");
        $errorBody = \json_encode([
            'error' => true,
            'message' => 'Runtime initialization failed',
            'detail' => $runtimeError,
        ], JSON_UNESCAPED_UNICODE);
        
        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($errorBody) . "\r\nConnection: close\r\n\r\n" . $errorBody;
    }
    
    WlsLogger::info_("准备进入框架处理: {$method} {$uri}");
    try {
        // 创建 WLS 请求对象（框架会自动处理维护模式）
        $request = \Weline\Framework\Http\WlsRequest::fromRaw($rawRequest, [
            'WLS_INSTANCE' => $instanceName,
            'WLS_WORKER_ID' => $workerId,
            'WLS_PORT' => $port,
            'WLS_REQUEST_COUNT' => $requestCount,
            'HTTPS' => 'on',
            'REQUEST_SCHEME' => 'https',
        ]);
        
        $result = $asyncBizAdapters->dispatch(
            static fn() => $runtime->handle($request)
        );
        
        // 释放 PHP Session 文件锁
        // 在 WLS 常驻进程模式下，session_start() 会锁定 session 文件
        // 必须在请求处理完成后立即释放锁，否则同一 session 的并发请求会被阻塞
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        // WLS 模式下控制器通过 return 返回 body；对 body trim 并可从 JSON 的 code 解析出状态码
        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            // 合并 Runtime 保存的 Cookie（在 StateManager reset 前提取的副本）
            // 若 302 已在 WlsRuntime 中带上了 Set-Cookie，则不再合并，避免重复头导致浏览器异常
            $headerEnd = \strpos($result, "\r\n\r\n");
            $alreadyHasSetCookie = $headerEnd !== false && \stripos(\substr($result, 0, $headerEnd), 'Set-Cookie:') !== false;
            $pendingCookies = $runtime->consumePendingCookies();
            if (!empty($pendingCookies) && !$alreadyHasSetCookie && $headerEnd !== false) {
                $cookieHeaders = '';
                foreach ($pendingCookies as $cookie) {
                    $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
                    if (isset($cookie['expire']) && $cookie['expire'] !== 0) { $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']); }
                    if (!empty($cookie['path']))     { $parts[] = 'Path=' . $cookie['path']; }
                    if (!empty($cookie['domain']))   { $parts[] = 'Domain=' . $cookie['domain']; }
                    if (!empty($cookie['secure']))   { $parts[] = 'Secure'; }
                    if (!empty($cookie['httpOnly'])) { $parts[] = 'HttpOnly'; }
                    if (!empty($cookie['sameSite'])) { $parts[] = 'SameSite=' . $cookie['sameSite']; }
                    $cookieHeaders .= 'Set-Cookie: ' . \implode('; ', $parts) . "\r\n";
                }
                $result = \substr($result, 0, $headerEnd) . "\r\n" . $cookieHeaders . \substr($result, $headerEnd);
            }
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
            $result = \Weline\Server\Service\RouteHintService::addHintToResponse($result, $sni);
            // HEAD 请求只返回头，不返回 body
            if (\strtoupper($method) === 'HEAD') {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $result = \substr($result, 0, $headerEnd + 4);
                }
            }
            return $result;
        }
        $result = \is_string($result) ? \trim($result) : (string) $result;
        $pendingResponseStatus = $runtime->consumePendingResponseStatus();
        $statusCode = (new \Weline\Server\Service\ResponseStatusResolver())->resolve(
            $result,
            $pendingResponseStatus['status_code'] ?? null,
            (bool) ($pendingResponseStatus['explicit'] ?? false)
        );
        $response = \Weline\Framework\Http\WlsResponse::fromContent($result, $statusCode);
        
        // WLS 模式核心：将 Runtime 保存的 Cookie/Header 合并进 HTTP 响应
        // 框架内部（Session、Cookie 类等）通过 HeaderCollector 设置响应头和 Cookie，
        // 但 WLS 模式下 PHP 内置的 header()/setcookie() 无效。
        // WlsRuntime 在 StateManager 重置前将 HeaderCollector 副本保存到 pendingCookies/pendingHeaders。
        $pendingCookies2 = $runtime->consumePendingCookies();
        foreach ($pendingCookies2 as $cookie) {
            $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
            if (isset($cookie['expire']) && $cookie['expire'] !== 0) { $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']); }
            if (!empty($cookie['path']))     { $parts[] = 'Path=' . $cookie['path']; }
            if (!empty($cookie['domain']))   { $parts[] = 'Domain=' . $cookie['domain']; }
            if (!empty($cookie['secure']))   { $parts[] = 'Secure'; }
            if (!empty($cookie['httpOnly'])) { $parts[] = 'HttpOnly'; }
            if (!empty($cookie['sameSite'])) { $parts[] = 'SameSite=' . $cookie['sameSite']; }
            $response->addCookieHeader(\implode('; ', $parts));
        }
        $pendingHeaders2 = $runtime->consumePendingHeaders();
        foreach ($pendingHeaders2 as $name => $value) {
            if (\is_string($value)) { $response->setHeader($name, $value); }
        }
        
        // 添加路由提示头（用于 TCP 透传模式下的智能路由）
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
        \Weline\Server\Service\RouteHintService::addHintToWlsResponse($response, $sni);
        
        $acceptEncoding = $request->getHeader('Accept-Encoding');
        if ($acceptEncoding && \is_string($acceptEncoding)) {
            $response->compress($acceptEncoding);
        }
        
        $httpString = $response->toHttpString($request->isKeepAlive());
        
        // HTTP 规范：HEAD 请求应该返回与 GET 请求相同的响应头，但不返回响应体
        // Content-Length 头部应该保留，告知客户端如果是 GET 请求会返回多大的内容
        if (\strtoupper($method) === 'HEAD') {
            $headerEnd = \strpos($httpString, "\r\n\r\n");
            if ($headerEnd !== false) {
                // 只保留响应头部分（包括末尾的 \r\n\r\n）
                $httpString = \substr($httpString, 0, $headerEnd + 4);
            }
        }
        
        return $httpString;
        
    } catch (\Throwable $e) {
        // 302 等响应终止为正常控制流，不记错误
        if (!$e instanceof \Weline\Framework\Http\ResponseTerminateException) {
            WlsLogger::error_("请求处理错误: " . $e->getMessage() . " (文件: " . $e->getFile() . ":" . $e->getLine() . ")");
            w_log_error('[WLS Worker SSL] Request error: ' . $e->getMessage());
        }

        $statusCode = 500;
        $errorMessage = $e->getMessage() ?: 'Internal Server Error';
        
        if ($e instanceof \Weline\Framework\App\Exception) {
            $code = $e->getCode();
            if ($code >= 400 && $code < 600) {
                $statusCode = $code;
            }
        }
        
        $isDev = \defined('DEV') && DEV;
        if ($isDev || (\defined('DEBUG') && DEBUG)) {
            $errorBody = \json_encode([
                'error' => true,
                'message' => $errorMessage,
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => \explode("\n", $e->getTraceAsString()),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } else {
            // 生产模式：非 App\Exception 不暴露内部错误细节
            $safeMessage = ($e instanceof \Weline\Framework\App\Exception) ? $errorMessage : 'Internal Server Error';
            $errorBody = \json_encode([
                'error' => true,
                'message' => $safeMessage,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if ($errorBody === false) {
            $errorBody = '{"error":true,"message":"JSON encode failed"}';
        }
        
        $response = new \Weline\Framework\Http\WlsResponse($errorBody, $statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        
        // 异常情况下也要释放 Session 锁
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        return $response->toHttpString(false);
    }
}

/**
 * 处理静态文件请求（WLS 模式特有）
 * 
 * 在 WLS 模式下，PHP 的 header() 和 readfile() 不起作用，
 * 需要在 Worker 层面直接读取文件并返回 HTTP 响应字符串。
 * 
 * 内存缓存策略：
 * - 小于配置阈值的文件缓存到内存，避免重复读取磁盘
 * - 缓存有效期 7 天（基于文件修改时间验证）
 * - 大于配置阈值的文件直接从磁盘读取（避免内存占用过大）
 * 
 * H13: 修复 Content-Length mismatch
 * - 根据客户端请求设置正确的 Connection 头
 * - 支持 Range 请求用于大文件断点续传
 * 
 * @param string $uri 请求 URI
 * @param string $rawRequest 原始请求（用于获取 If-Modified-Since 等头部）
 * @return string|null 如果是静态文件则返回 HTTP 响应字符串，否则返回 null
 */
function handleStaticFile(string $uri, string $rawRequest): ?string
{
    global $WLS_STATIC_CACHE_MAX_TOTAL, $WLS_STATIC_CACHE_MAX_SIZE, $WLS_CACHE_EVICTION_THRESHOLD, $WLS_LAST_STATIC_CACHE;
    $WLS_LAST_STATIC_CACHE = null;
    
    // ========== 静态文件内存缓存（冷热淘汰策略） ==========
    // 缓存格式：[filepath => ['content' => string, 'mtime' => int, 'size' => int, 'cached_at' => int, 'hits' => int, 'last_access' => int]]
    static $staticFileCache = [];
    static $staticFileCacheTotalSize = 0;
    static $staticFileCacheMaxAge = 86400 * 7;  // 缓存有效期：7 天
    
    // 使用全局配置（如果已设置）
    $maxTotal = $WLS_STATIC_CACHE_MAX_TOTAL ?? 100 * 1024 * 1024;
    $maxSize = $WLS_STATIC_CACHE_MAX_SIZE ?? 1024 * 1024;
    $evictionThreshold = $WLS_CACHE_EVICTION_THRESHOLD ?? 5 * 1024 * 1024;
    
    // 特殊命令：清理内存缓存
    if ($uri === '__CLEAR_CACHE__') {
        $count = \count($staticFileCache);
        $size = $staticFileCacheTotalSize;
        $staticFileCache = [];
        $staticFileCacheTotalSize = 0;
        return "cleared:{$count}:{$size}";
    }
    
    // 特殊命令：获取缓存状态
    if ($uri === '__CACHE_STATUS__') {
        return \json_encode([
            'count' => \count($staticFileCache),
            'size' => $staticFileCacheTotalSize,
            'max_total' => $maxTotal,
            'max_size' => $maxSize,
            'eviction_threshold' => $evictionThreshold,
        ]);
    }
    
    /**
     * 冷热淘汰：当缓存接近上限时，淘汰最冷的缓存项
     * 评分公式：score = hits * 10 + recency_bonus
     * recency_bonus = max(0, 100 - (now - last_access) / 60) // 最近访问加分
     */
    $evictColdCache = static function (int $neededSpace) use (&$staticFileCache, &$staticFileCacheTotalSize, $maxTotal, $evictionThreshold): void {
        // 计算需要释放多少空间
        $targetSize = $maxTotal - $evictionThreshold - $neededSpace;
        if ($staticFileCacheTotalSize <= $targetSize) {
            return; // 空间足够，无需淘汰
        }
        
        $now = \time();
        $candidates = [];
        
        // 计算每个缓存项的冷热分数
        foreach ($staticFileCache as $path => $item) {
            $hits = $item['hits'] ?? 0;
            $lastAccess = $item['last_access'] ?? $item['cached_at'];
            $age = $now - $lastAccess;
            
            // 分数越低越冷（优先淘汰）
            $recencyBonus = \max(0, 100 - (int)($age / 60)); // 每分钟减 1 分
            $score = $hits * 10 + $recencyBonus;
            
            $candidates[] = [
                'path' => $path,
                'score' => $score,
                'size' => $item['size'],
            ];
        }
        
        // 按分数升序排序（最冷的在前）
        \usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        
        // 淘汰最冷的缓存项直到空间足够
        foreach ($candidates as $candidate) {
            if ($staticFileCacheTotalSize <= $targetSize) {
                break;
            }
            
            $path = $candidate['path'];
            if (isset($staticFileCache[$path])) {
                $staticFileCacheTotalSize -= $staticFileCache[$path]['size'];
                unset($staticFileCache[$path]);
            }
        }
    };
    
    // 静态文件扩展名列表
    static $staticExtensions = [
        'css', 'js', 'map',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'eot', 'ttf', 'otf',
        'mp4', 'mp3', 'webm', 'ogg', 'm3u8',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'json', 'xml',
        'zip', 'rar', '7z', 'gz', 'tar',
    ];
    
    // MIME 类型映射
    static $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'eot' => 'application/vnd.ms-fontobject',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
    ];
    
    // 解析文件扩展名（去除查询字符串）
    $_up = \parse_url($uri, PHP_URL_PATH);
    $uriPath = (\is_string($_up) && $_up !== '') ? $_up : $uri;
    $extension = \strtolower(\pathinfo($uriPath, PATHINFO_EXTENSION));
    
    // 不是静态文件，交给框架处理
    if (empty($extension) || !\in_array($extension, $staticExtensions, true)) {
        return null;
    }
    
    // 安全检查：防止目录遍历，并兼容带 backend key / 货币 / 语言前缀的静态资源 URL
    $normalizedUri = \str_replace(['../', '..\\'], '', $uriPath);
    $normalizedUri = \trim(\str_replace('\\', '/', $normalizedUri), '/\\');
    if ($normalizedUri === '') {
        return null;
    }

    $candidateUris = [];
    $addCandidateUri = static function (string $candidate) use (&$candidateUris): void {
        $candidate = \trim(\str_replace('\\', '/', $candidate), '/');
        if ($candidate === '') {
            return;
        }
        $candidateUris[] = $candidate;
        if (\str_starts_with($candidate, 'pub/')) {
            $stripped = \substr($candidate, 4);
            if ($stripped !== '') {
                $candidateUris[] = $stripped;
            }
        }
    };
    $isCurrencySegment = static fn(string $segment): bool => \preg_match('/^[A-Z]{3}$/', $segment) === 1;
    $isLocaleSegment = static fn(string $segment): bool => \preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $segment) === 1;

    $addCandidateUri($normalizedUri);
    $segments = \array_values(\array_filter(\explode('/', $normalizedUri), static fn(string $segment): bool => $segment !== ''));
    $segmentCount = \count($segments);

    if ($segmentCount >= 2 && $segments[0] === 'pub') {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }
    if ($segmentCount >= 3 && $isCurrencySegment($segments[1]) && $isLocaleSegment($segments[2])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 3)));
    }
    if ($segmentCount >= 2 && $isCurrencySegment($segments[0]) && $isLocaleSegment($segments[1])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 2)));
    }
    if ($segmentCount >= 1 && $isLocaleSegment($segments[0])) {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }
    if ($segmentCount >= 2
        && !\str_contains($segments[0], '.')
        && \in_array($segments[1], ['pub', 'statics', 'theme_previews', 'media', '.well-known', 'errors'], true)
    ) {
        $addCandidateUri(\implode('/', \array_slice($segments, 1)));
    }

    $candidateUris = \array_values(\array_unique($candidateUris));

    foreach ($candidateUris as $candidateUri) {
        if (\Weline\Server\Service\StaticRequestBypassDecider::shouldDeferToFramework($candidateUri)) {
            return null;
        }
    }

    // 查找文件位置（按优先级）
    $filename = null;
    foreach ($candidateUris as $candidateUri) {
        $searchPaths = [];
        $searchPaths[] = BP . 'pub' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . 'app' . DS . 'code' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . 'vendor' . DS . \str_replace('/', DS, $candidateUri);
        $searchPaths[] = BP . \str_replace('/', DS, $candidateUri);

        foreach ($searchPaths as $path) {
            $path = \str_replace([DS . DS, '//'], DS, $path);
            if (\is_file($path) && \is_readable($path)) {
                $filename = $path;
                break 2;
            }
        }
    }
    
    // 文件不存在，交给框架处理（可能是动态生成的资源）
    if ($filename === null) {
        return null;
    }
    
    // 默认标记为 MISS（非内存缓存命中）
    $WLS_LAST_STATIC_CACHE = [
        'status' => 'miss',
        'uri' => $uriPath,
        'path' => $filename,
    ];

    $validatedCached = null;
    $cacheHeaderStatus = 'MISS';
    $now = \time();
    if (isset($staticFileCache[$filename])) {
        $cached = $staticFileCache[$filename];
        if (($cached['mtime'] ?? null) === \filemtime($filename)
            && ($now - (int)($cached['cached_at'] ?? 0)) < $staticFileCacheMaxAge
        ) {
            $validatedCached = $cached;
            $cacheHeaderStatus = 'HIT';
            $WLS_LAST_STATIC_CACHE['status'] = 'hit';
            $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
            $staticFileCache[$filename]['last_access'] = $now;
        } else {
            $staticFileCacheTotalSize -= $cached['size'];
            unset($staticFileCache[$filename]);
        }
    }
    
    // 获取文件修改时间
    $mtime = \filemtime($filename);
    $lastModified = \gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $etag = '"' . \md5($filename . $mtime) . '"';
    
    // 检查缓存验证（304 Not Modified）- 精简响应头
    if (\preg_match('/If-Modified-Since:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifModifiedSince = \trim($matches[1]);
        if ($ifModifiedSince === $lastModified) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nX-WLS-Static-Cache: {$cacheHeaderStatus}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    if (\preg_match('/If-None-Match:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifNoneMatch = \trim($matches[1]);
        if ($ifNoneMatch === $etag) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nX-WLS-Static-Cache: {$cacheHeaderStatus}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    // 获取文件大小
    $fileSize = \filesize($filename);
    
    // 获取 MIME 类型
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // 缓存控制（静态资源可以长期缓存）
    $maxAge = 86400 * 7; // 7 天
    
    // ========== 内存缓存策略（冷热淘汰） ==========
    $content = $validatedCached['content'] ?? null;
    $fromCache = $validatedCached !== null;
    $now = $now ?? \time();
    
    // 只有小于配置阈值的文件才缓存到内存
    if ($fileSize <= $maxSize) {
        // 检查缓存是否存在且有效
        if (!$fromCache && isset($staticFileCache[$filename])) {
            $cached = $staticFileCache[$filename];
            // 验证：文件修改时间一致 且 缓存未过期
            if ($cached['mtime'] === $mtime && ($now - $cached['cached_at']) < $staticFileCacheMaxAge) {
                $content = $cached['content'];
                    $fromCache = true;
                    $WLS_LAST_STATIC_CACHE['status'] = 'hit';
                // 更新访问统计（冷热计数）
                $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
                $staticFileCache[$filename]['last_access'] = $now;
            } else {
                // 缓存失效，移除旧缓存
                $staticFileCacheTotalSize -= $cached['size'];
                unset($staticFileCache[$filename]);
            }
        }
        
        // 缓存未命中，从磁盘读取并缓存
        if ($content === null) {
            $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
            if ($content === false) {
                return null; // 读取失败，交给框架处理
            }
            
            // 检查是否需要淘汰：剩余空间不足时启动冷热淘汰
            $remainingSpace = $maxTotal - $staticFileCacheTotalSize;
            if ($remainingSpace - $fileSize < $evictionThreshold) {
                // 剩余空间低于阈值，启动冷热淘汰
                $evictColdCache($fileSize);
            }
            
            // 再次检查空间是否足够（淘汰后）
            if ($staticFileCacheTotalSize + $fileSize <= $maxTotal) {
                // 添加到缓存
                $staticFileCache[$filename] = [
                    'content' => $content,
                    'mtime' => $mtime,
                    'size' => $fileSize,
                    'cached_at' => $now,
                    'hits' => 1,
                    'last_access' => $now,
                ];
                $staticFileCacheTotalSize += $fileSize;
            }
            // 如果空间仍不足，不缓存该文件（但仍返回内容）
        }
    } else {
        // 大于配置阈值的文件不缓存，直接读取
        $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
        if ($content === false) {
            return null; // 读取失败，交给框架处理
        }
    }
    
    // 计算内容长度
    $contentLength = \strlen($content);
    
    if ($contentLength !== $fileSize && !$fromCache) {
        // 文件可能在读取过程中被修改，重新读取
        $content = \Weline\Server\Runtime\Async\AsyncBizAdapters::fileGetContentsWithYield($filename);
        if ($content === false) {
            return null;
        }
        $contentLength = \strlen($content);
    }
    
    // HTTP/1.1 默认 keep-alive，除非客户端显式请求 close
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    $hasCloseHeader = \stripos($rawRequest, 'Connection: close') !== false;
    $keepAlive = $isHttp11 && !$hasCloseHeader;
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';
    
    // 构建精简的 HTTP 响应（静态文件不需要 cookie、server 等冗余头部）
    $response = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: {$mimeType}\r\n";
    $response .= "Content-Length: {$contentLength}\r\n";
    $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
    $response .= "ETag: {$etag}\r\n";
    $response .= "Connection: {$connectionHeader}\r\n";
    // WLS 内存缓存状态标识（HIT=内存缓存命中, MISS=磁盘读取）
    $response .= "X-WLS-Static-Cache: " . ($fromCache ? 'HIT' : 'MISS') . "\r\n";
    $response .= "X-WLS-File-Size: {$fileSize}\r\n";
    $response .= "X-WLS-Content-Length: {$contentLength}\r\n";
    $response .= "\r\n";
    $response .= $content;
    
    $expectedResponseLen = \strlen($response);
    $headerEndPos = \strpos($response, "\r\n\r\n");
    $actualBodyLen = $expectedResponseLen - $headerEndPos - 4;
    if ($actualBodyLen !== $contentLength) {
        // 响应构建错误，返回错误响应
        return "HTTP/1.1 500 Internal Server Error\r\n" .
               "Content-Type: text/plain\r\n" .
               "Content-Length: 32\r\n" .
               "Connection: close\r\n" .
               "\r\n" .
               "Response construction error: {$actualBodyLen} != {$contentLength}";
    }
    
    return $response;
}
