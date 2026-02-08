<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * opcache 检测/启用脚本
 *
 * opcache 是 Zend 扩展，需通过 zend_extension 加载，不能用 extension=。
 * PHP 8.x 通常自带 opcache，只需确认已启用。
 *
 * 用法：
 *   php check_opcache.php check   - 检查 opcache 是否启用
 *   php check_opcache.php install  - 尝试启用 opcache
 *
 * 退出码：
 *   0 - 成功
 *   1 - 失败
 */

$action = $argv[1] ?? 'check';

switch ($action) {
    case 'check':
        if (extension_loaded('Zend OPcache')) {
            echo json_encode([
                'installed' => true,
                'message'   => 'opcache 已启用（版本: ' . phpversion('Zend OPcache') . '）',
            ]) . PHP_EOL;
            exit(0);
        }

        echo json_encode([
            'installed' => false,
            'message'   => 'opcache 未启用',
            'guide'     => getGuide(),
        ]) . PHP_EOL;
        exit(1);

    case 'install':
        if (extension_loaded('Zend OPcache')) {
            echo json_encode([
                'success' => true,
                'message' => 'opcache 已启用，无需安装',
            ]) . PHP_EOL;
            exit(0);
        }

        $result = tryEnableOpcache();
        echo json_encode($result) . PHP_EOL;
        exit($result['success'] ? 0 : 1);

    default:
        echo json_encode(['error' => '未知动作: ' . $action . '，请使用 check 或 install']) . PHP_EOL;
        exit(1);
}

/**
 * 尝试启用 opcache
 */
function tryEnableOpcache(): array
{
    $phpIniPath = php_ini_loaded_file();
    if (!$phpIniPath) {
        return ['success' => false, 'message' => '无法获取 php.ini 路径', 'guide' => getGuide()];
    }
    if (!is_writable($phpIniPath)) {
        return ['success' => false, 'message' => 'php.ini 不可写: ' . $phpIniPath, 'guide' => getGuide()];
    }

    $content = file_get_contents($phpIniPath);
    if ($content === false) {
        return ['success' => false, 'message' => '无法读取 php.ini'];
    }

    // 查找 DLL/.so 是否存在
    if (PHP_OS_FAMILY === 'Windows') {
        $phpDir = dirname(PHP_BINARY);
        $extDir = $phpDir . DIRECTORY_SEPARATOR . ini_get('extension_dir');
        if (is_dir(ini_get('extension_dir'))) {
            $extDir = ini_get('extension_dir');
        }
        $libFile = $extDir . DIRECTORY_SEPARATOR . 'php_opcache.dll';
        $zendLine = 'zend_extension=php_opcache.dll';
    } else {
        $extDir = ini_get('extension_dir');
        $libFile = $extDir . DIRECTORY_SEPARATOR . 'opcache.so';
        $zendLine = 'zend_extension=opcache.so';
    }

    if (!file_exists($libFile)) {
        return [
            'success' => false,
            'message' => 'opcache 库文件不存在: ' . $libFile,
            'guide'   => getGuide(),
        ];
    }

    // 检查是否已有被注释的 zend_extension 行
    $patterns = [
        '/^;\s*zend_extension\s*=\s*(?:php_)?opcache(?:\.dll|\.so)?\s*$/mi',
    ];

    $modified = false;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $zendLine, $content);
            $modified = true;
            break;
        }
    }

    if (!$modified) {
        // 检查是否已启用
        $enabledPattern = '/^\s*zend_extension\s*=\s*(?:php_)?opcache(?:\.dll|\.so)?\s*$/mi';
        if (preg_match($enabledPattern, $content)) {
            return ['success' => true, 'message' => 'opcache 已在 php.ini 中配置（可能需要重启 PHP）'];
        }

        // 追加
        $content .= "\n" . $zendLine . "\nopcache.enable=1\nopcache.enable_cli=1\n";
        $modified = true;
    }

    if ($modified && file_put_contents($phpIniPath, $content) !== false) {
        return [
            'success' => true,
            'message' => 'opcache 已在 php.ini 中启用',
            'note'    => 'CLI 模式已生效，Web 服务需要重启',
        ];
    }

    return ['success' => false, 'message' => '写入 php.ini 失败'];
}

/**
 * 获取手动启用指引
 */
function getGuide(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            '步骤' => [
                '1. 打开 php.ini: ' . (php_ini_loaded_file() ?: '未知'),
                '2. 添加或取消注释: zend_extension=php_opcache.dll',
                '3. 确保有: opcache.enable=1',
                '4. 可选: opcache.enable_cli=1（CLI 也启用）',
                '5. 重启 PHP 服务',
            ],
        ];
    }

    return [
        'Ubuntu/Debian' => 'sudo phpenmod opcache',
        'CentOS/RHEL'   => 'yum install php-opcache',
        '手动'          => '在 php.ini 中添加: zend_extension=opcache.so 和 opcache.enable=1',
    ];
}
