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
 * 尝试启用 opcache。安装期与这里共用 PhpInstallIniOptimizer，成功只看新进程探针。
 */
function tryEnableOpcache(): array
{
    $optimizer = dirname(__DIR__, 3) . '/Framework/Env/Service/PhpInstallIniOptimizer.php';
    $enabler = dirname(__DIR__, 3) . '/Framework/Env/Service/PhpExtensionIniEnabler.php';
    if (!is_file($optimizer)) {
        $optimizer = dirname(__DIR__, 3) . '/Framework/env/Service/PhpInstallIniOptimizer.php';
        $enabler = dirname(__DIR__, 3) . '/Framework/env/Service/PhpExtensionIniEnabler.php';
    }
    if (!is_file($optimizer) || !is_file($enabler)) {
        return ['success' => false, 'message' => '缺少 PhpInstallIniOptimizer', 'guide' => getGuide()];
    }
    require_once $enabler;
    require_once $optimizer;
    $item = (new \Weline\Framework\Env\Service\PhpInstallIniOptimizer())->enableOpcache();
    if ($item['skipped']) {
        return [
            'success' => false,
            'message' => $item['message'],
            'guide' => getGuide(),
        ];
    }

    return [
        'success' => $item['success'],
        'message' => $item['message'],
        'guide' => $item['success'] ? null : getGuide(),
    ];
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
