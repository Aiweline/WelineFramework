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
    $extDir = $phpDir . DIRECTORY_SEPARATOR . ini_get('extension_dir');
    if (is_dir(ini_get('extension_dir'))) {
        $extDir = ini_get('extension_dir');
    }

    // event 扩展的 DLL 名称
    $dllName = 'php_event.dll';
    $dllPath = $extDir . DIRECTORY_SEPARATOR . $dllName;

    if (!file_exists($dllPath)) {
        return [
            'success' => false,
            'message' => 'event DLL 不存在: ' . $dllPath,
            'guide'   => getInstallGuide(),
        ];
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
        'message' => 'event 扩展已在 php.ini 中启用',
        'note'    => 'CLI 模式已生效，Web 服务需要重启',
    ];
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
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    if (PHP_OS_FAMILY === 'Windows') {
        return [
            '方式1' => '从 https://pecl.php.net/package/event 下载对应 PHP ' . $phpVersion . ' 的 DLL',
            '方式2' => '从 https://windows.php.net/downloads/pecl/releases/event/ 下载',
            '步骤'  => [
                '1. 下载 php_event.dll（注意选择 ts/nts 和 x64/x86）',
                '2. 放入 PHP 的 ext 目录: ' . dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . ini_get('extension_dir'),
                '3. 在 php.ini 中添加: extension=event',
                '4. 重启 PHP 服务',
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
