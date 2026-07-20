<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * event 扩展检测/安装脚本
 *
 * event 扩展是 libevent 的 PHP 绑定，提供高性能的 epoll/kqueue 事件驱动 I/O。
 * 安装后 WLS 将自动切换为 Event 驱动模式，替代默认的 stream_select。
 *
 * 用法：
 *   php install_event_extension.php check   - 检查 event 扩展是否可用
 *   php install_event_extension.php install  - 尝试安装 event 扩展
 *
 * 退出码：
 *   0 - 成功
 *   1 - 失败
 */

$action = $argv[1] ?? 'check';

const WINDOWS_EVENT_VERSION = '3.1.4';
const WINDOWS_EVENT_BASE_URL = 'https://windows.php.net/downloads/pecl/releases/event/' . WINDOWS_EVENT_VERSION;
const WINDOWS_EVENT_PACKAGE_SHA256 = [
    '8.4-nts-vs17-x64' => 'b172b1ee43c769f1a2c8cb4d8b924c4bc97c1fda52d6b1b3d6cc98c23a402c73',
    '8.4-ts-vs17-x64' => '28170c7e79393bfe98d20e77b27e8dbbbe6dee31a48b9cb4faa4b35e38cf1392',
    '8.4-nts-vs17-x86' => '435adf3a392f87460a87da1415bd90aff9196c3a9f8b5abff83c0293a813ff1b',
    '8.4-ts-vs17-x86' => 'a07f49a02fdace05c9621fe2ec34b30a4b141085e69b4fc0aa879e03989aa31c',
];

switch ($action) {
    case 'check':
        if (extension_loaded('event')) {
            echo json_encode([
                'installed' => true,
                'message'   => 'event 扩展已加载（版本: ' . phpversion('event') . '）',
            ]) . PHP_EOL;
            exit(0);
        }

        echo json_encode([
            'installed' => false,
            'message'   => 'event 扩展未安装',
            'guide'     => getInstallGuide(),
        ]) . PHP_EOL;
        exit(1);

    case 'install':
        if (extension_loaded('event')) {
            echo json_encode([
                'success' => true,
                'message' => 'event 扩展已加载，无需安装',
            ]) . PHP_EOL;
            exit(0);
        }

        $result = tryInstallEvent();
        echo json_encode($result) . PHP_EOL;
        exit($result['success'] ? 0 : 1);

    default:
        echo json_encode(['error' => '未知动作: ' . $action . '，请使用 check 或 install']) . PHP_EOL;
        exit(1);
}

/**
 * 尝试安装 event 扩展
 */
function tryInstallEvent(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return tryInstallEventWindows();
    }
    return tryInstallEventLinux();
}

/**
 * Windows: 检查 DLL 是否存在并启用
 */
function tryInstallEventWindows(): array
{
    $phpDir = dirname(PHP_BINARY);
    $extDir = resolveWindowsExtensionDirectory($phpDir);

    // 当前进程未加载 event 时，不信任目录里“碰巧存在”的 DLL。
    // 始终用固定摘要的官方精确 ABI 包原子发布；已有文件先保留备份。
    $install = installOfficialWindowsEventPackage($phpDir, $extDir);
    if (!$install['success']) {
        $install['guide'] = getInstallGuide();
        return $install;
    }

    // DLL 存在，尝试在 php.ini 中启用
    $phpIniPath = php_ini_loaded_file();
    if (!$phpIniPath || !is_writable($phpIniPath)) {
        return [
            'success' => false,
            'message' => 'php.ini 不可写: ' . ($phpIniPath ?: '未知'),
            'guide'   => getInstallGuide(),
        ];
    }

    $content = file_get_contents($phpIniPath);
    if ($content === false) {
        return ['success' => false, 'message' => '无法读取 php.ini'];
    }

    // 检查是否已有被注释的行
    $pattern = '/^;(\s*extension\s*=\s*(?:php_)?event(?:\.dll)?\s*)$/mi';
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, '$1', $content);
    } else {
        // 检查是否已启用
        $enabledPattern = '/^\s*extension\s*=\s*(?:php_)?event(?:\.dll)?\s*$/mi';
        if (preg_match($enabledPattern, $content)) {
            return ['success' => true, 'message' => 'event 扩展已在 php.ini 中启用'];
        }
        // 追加
        $content .= "\nextension=event\n";
    }

    if (file_put_contents($phpIniPath, $content) === false) {
        return ['success' => false, 'message' => '写入 php.ini 失败'];
    }

    return [
        'success' => true,
        'message' => 'event 扩展已安装并在 php.ini 中启用；新 PHP 进程将执行最终 ABI 加载验证',
        'note'    => '当前进程不会动态加载新 DLL；WLS 将在创建任何子进程前自动重入',
    ];
}

function resolveWindowsExtensionDirectory(string $phpDir): string
{
    $configured = trim((string)ini_get('extension_dir'), " \t\n\r\0\x0B\"'");
    if ($configured !== '' && is_dir($configured)) {
        return rtrim($configured, '/\\');
    }

    return $phpDir . DIRECTORY_SEPARATOR . ltrim($configured !== '' ? $configured : 'ext', '/\\');
}

/**
 * Download exactly one official PECL package whose filename and digest encode
 * the current PHP ABI. No latest-version lookup or cross-minor fallback is
 * allowed on the startup path.
 */
function installOfficialWindowsEventPackage(string $phpDir, string $extDir): array
{
    if (PHP_DEBUG) {
        return ['success' => false, 'message' => 'Debug PHP 不加载 release event DLL'];
    }

    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $threadSafety = PHP_ZTS ? 'ts' : 'nts';
    $architecture = PHP_INT_SIZE >= 8 ? 'x64' : 'x86';
    $compiler = $phpVersion === '8.4' ? 'vs17' : '';
    $abi = implode('-', [$phpVersion, $threadSafety, $compiler, $architecture]);
    $expectedHash = WINDOWS_EVENT_PACKAGE_SHA256[$abi] ?? '';
    if ($compiler === '' || $expectedHash === '') {
        return [
            'success' => false,
            'message' => '当前 PHP ABI 没有固定校验值，拒绝猜测 event DLL: ' . $abi,
        ];
    }
    if (!is_dir($extDir) || !is_writable($extDir) || !is_writable($phpDir)) {
        return [
            'success' => false,
            'message' => 'PHP ext 或运行目录不可写，无法原子安装 event DLL: ' . $extDir,
        ];
    }
    if (!class_exists(ZipArchive::class)) {
        return [
            'success' => false,
            'message' => 'Windows event 自动安装需要当前 PHP 启用 ZipArchive，以便校验后只提取固定 DLL',
        ];
    }

    $packageName = 'php_event-' . WINDOWS_EVENT_VERSION . '-' . $abi . '.zip';
    $url = WINDOWS_EVENT_BASE_URL . '/' . $packageName;
    $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
        . 'wls-event-' . bin2hex(random_bytes(8));
    if (!@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
        return ['success' => false, 'message' => '无法创建 event 安装临时目录'];
    }

    $archive = $tempDir . DIRECTORY_SEPARATOR . $packageName;
    try {
        $download = downloadVerifiedWindowsEventPackage($url, $archive, $expectedHash);
        if (!$download['success']) {
            return $download;
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return ['success' => false, 'message' => '无法打开已校验的 event ZIP'];
        }
        try {
            $eventDll = $zip->getFromName('php_event.dll');
            $pthreadDll = $zip->getFromName('pthreadVC2.dll');
        } finally {
            $zip->close();
        }
        if (!is_string($eventDll) || $eventDll === '' || !is_string($pthreadDll) || $pthreadDll === '') {
            return ['success' => false, 'message' => '官方 event ZIP 缺少 php_event.dll 或 pthreadVC2.dll'];
        }

        $installed = installWindowsRuntimeFilesAtomically([
            $extDir . DIRECTORY_SEPARATOR . 'php_event.dll' => $eventDll,
            $phpDir . DIRECTORY_SEPARATOR . 'pthreadVC2.dll' => $pthreadDll,
        ]);
        if (!$installed['success']) {
            return $installed;
        }

        return [
            'success' => true,
            'message' => '已安装官方 PECL event ' . WINDOWS_EVENT_VERSION . '（' . $abi . '，SHA-256 已验证）',
        ];
    } finally {
        removeWindowsEventTempTree($tempDir);
    }
}

function downloadVerifiedWindowsEventPackage(string $url, string $target, string $expectedHash): array
{
    $context = stream_context_create([
        'http' => [
            'follow_location' => 1,
            'max_redirects' => 3,
            'timeout' => 60,
            'user_agent' => 'WelineFramework-WLS/' . PHP_VERSION,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $source = @fopen($url, 'rb', false, $context);
    $destination = @fopen($target, 'xb');
    if (!is_resource($source) || !is_resource($destination)) {
        if (is_resource($source)) {
            fclose($source);
        }
        if (is_resource($destination)) {
            fclose($destination);
        }
        return ['success' => false, 'message' => '无法通过 HTTPS 下载官方 event 包: ' . $url];
    }

    try {
        $bytes = stream_copy_to_stream($source, $destination);
    } finally {
        fclose($source);
        fclose($destination);
    }
    if (!is_int($bytes) || $bytes <= 0) {
        return ['success' => false, 'message' => '官方 event 包下载为空'];
    }

    $actualHash = hash_file('sha256', $target);
    if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
        return ['success' => false, 'message' => '官方 event 包 SHA-256 校验失败，拒绝安装'];
    }

    return ['success' => true, 'message' => 'event 包下载与摘要校验通过'];
}

/** @param array<string,string> $files */
function installWindowsRuntimeFilesAtomically(array $files): array
{
    $token = bin2hex(random_bytes(6));
    $temporary = [];
    $backups = [];
    $installed = [];

    foreach ($files as $target => $contents) {
        $temp = $target . '.wls-install-' . $token;
        if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)) {
            foreach ($temporary as $path) {
                // Targets come only from the two installer-owned PHP runtime paths above.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($path);
            }
            // Installer-owned random-suffix path, never request input.
            // nosemgrep: php.lang.security.unlink-use.unlink-use
            @unlink($temp);
            return ['success' => false, 'message' => '无法写入 event DLL 临时文件: ' . $target];
        }
        $temporary[$target] = $temp;
    }

    foreach ($temporary as $target => $temp) {
        if (is_file($target)) {
            $backup = $target . '.wls-backup-' . gmdate('YmdHis') . '-' . $token;
            if (!@rename($target, $backup)) {
                rollbackWindowsRuntimeFiles($temporary, $backups, $installed);
                return ['success' => false, 'message' => '无法备份已有 DLL: ' . $target];
            }
            $backups[$target] = $backup;
        }
        if (!@rename($temp, $target)) {
            rollbackWindowsRuntimeFiles($temporary, $backups, $installed);
            return ['success' => false, 'message' => '无法原子发布 event DLL: ' . $target];
        }
        $installed[] = $target;
        unset($temporary[$target]);
    }

    return ['success' => true, 'message' => 'event 运行时文件已原子发布'];
}

/**
 * @param array<string,string> $temporary
 * @param array<string,string> $backups
 * @param list<string> $installed
 */
function rollbackWindowsRuntimeFiles(array $temporary, array $backups, array $installed): void
{
    foreach ($temporary as $path) {
        // Installer-owned random-suffix path, never request input.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($path);
    }
    foreach (array_reverse($installed) as $target) {
        // Only the allowlisted php_event.dll/pthreadVC2.dll targets are passed here.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($target);
    }
    foreach ($backups as $target => $backup) {
        if (!is_file($target) && is_file($backup)) {
            @rename($backup, $target);
        }
    }
}

function removeWindowsEventTempTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        // Path is rooted in this process-created wls-event-<random> directory.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($path);
        return;
    }
    foreach ((array)scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeWindowsEventTempTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

/**
 * Linux/macOS: 通过 pecl 或包管理器安装
 */
function tryInstallEventLinux(): array
{
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $installed = [];
    $errors = [];

    // 1. 确保 libevent-dev 已安装（event 扩展的编译依赖）
    $devPackages = [
        'apt-get install -y libevent-dev',
        'yum install -y libevent-devel',
        'dnf install -y libevent-devel',
        'apk add libevent-dev',
    ];

    $devInstalled = false;
    foreach ($devPackages as $cmd) {
        $output = [];
        $code = 0;
        // $cmd comes exclusively from the fixed package-manager allowlist above.
        // nosemgrep: php.lang.security.exec-use.exec-use
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code === 0) {
            $devInstalled = true;
            break;
        }
    }

    // 2. Docker 环境
    if (file_exists('/.dockerenv')) {
        $output = [];
        $code = 0;
        @exec('docker-php-ext-install event 2>&1', $output, $code);
        if ($code === 0) {
            return ['success' => true, 'message' => 'event 扩展已通过 docker-php-ext-install 安装'];
        }
    }

    // 3. 尝试 pecl install
    $output = [];
    $code = 0;
    // pecl install event 可能会提示是否启用 OpenSSL，用 yes 管道
    @exec('printf "\n\n\n\n" | pecl install event 2>&1', $output, $code);
    if ($code === 0) {
        // 尝试在 php.ini 中启用
        enableExtensionInIni('event');
        return ['success' => true, 'message' => 'event 扩展已通过 pecl 安装'];
    }
    $errors[] = 'pecl install event 失败: ' . implode("\n", array_slice($output, -5));

    // 4. 尝试通过包管理器
    $packages = [
        'apt-get install -y php' . $phpVersion . '-event',
        'apt-get install -y php-event',
        'yum install -y php-pecl-event',
        'dnf install -y php-pecl-event',
    ];

    foreach ($packages as $cmd) {
        $output = [];
        $code = 0;
        // $cmd comes exclusively from the fixed package-manager allowlist above.
        // nosemgrep: php.lang.security.exec-use.exec-use
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code === 0) {
            return ['success' => true, 'message' => 'event 扩展已通过包管理器安装'];
        }
    }

    return [
        'success' => false,
        'message' => 'event 扩展安装失败',
        'errors'  => $errors,
        'guide'   => getInstallGuide(),
    ];
}

/**
 * 在 php.ini 中启用扩展
 */
function enableExtensionInIni(string $ext): bool
{
    $phpIniPath = php_ini_loaded_file();
    if (!$phpIniPath || !is_writable($phpIniPath)) {
        return false;
    }

    $content = file_get_contents($phpIniPath);
    if ($content === false) {
        return false;
    }

    // 检查是否已启用
    $pattern = '/^\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*$/mi';
    if (preg_match($pattern, $content)) {
        return true;
    }

    // 检查被注释的行
    $commentPattern = '/^;(\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*)$/mi';
    if (preg_match($commentPattern, $content)) {
        $content = preg_replace($commentPattern, '$1', $content);
    } else {
        $content .= "\nextension=" . $ext . "\n";
    }

    return file_put_contents($phpIniPath, $content) !== false;
}

/**
 * 获取安装指引
 */
function getInstallGuide(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            '自动方式' => 'WLS 仅下载官方 PECL event ' . WINDOWS_EVENT_VERSION . '，并严格匹配 PHP minor、TS/NTS、VS ABI、架构及固定 SHA-256',
            '手动来源' => WINDOWS_EVENT_BASE_URL . '/',
            '步骤'  => [
                '1. 下载 php_event.dll（注意选择 ts/nts 和 x64/x86）',
                '2. 放入 PHP 的 ext 目录: ' . dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . ini_get('extension_dir'),
                '3. 将同包 pthreadVC2.dll 放入 PHP 根目录',
                '4. 在 php.ini 中添加: extension=event',
                '5. 使用同一 PHP_BINARY 新进程验证 EventBase/Event',
            ],
        ];
    }

    return [
        'Ubuntu/Debian' => 'sudo apt-get install -y libevent-dev && pecl install event',
        'CentOS/RHEL'   => 'sudo yum install -y libevent-devel && pecl install event',
        'Alpine'        => 'apk add libevent-dev && pecl install event',
        'macOS'         => 'brew install libevent && pecl install event',
        'Docker'        => 'docker-php-ext-install event',
        '启用'          => '在 php.ini 中添加: extension=event',
    ];
}
