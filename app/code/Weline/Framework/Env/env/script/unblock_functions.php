<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * 函数解禁脚本
 * 
 * 用法：
 *   php unblock_functions.php check   - 检查函数是否可用
 *   php unblock_functions.php install - 尝试解禁函数
 * 
 * 退出码：
 *   0 - 成功（check 时表示函数已可用，install 时表示解禁成功）
 *   1 - 失败
 */

// 需要解禁的函数列表
$requiredFunctions = ['exec', 'putenv', 'proc_open'];

// 获取动作参数
$action = $argv[1] ?? 'check';

// 获取当前被禁用的函数
$disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));

switch ($action) {
    case 'check':
        // 检查是否所有函数都可用
        $blocked = [];
        foreach ($requiredFunctions as $func) {
            if (in_array($func, $disabledFunctions, true)) {
                $blocked[] = $func;
            }
        }

        if (empty($blocked)) {
            echo json_encode(['installed' => true, 'message' => '所有必需函数都可用']) . PHP_EOL;
            exit(0);
        } else {
            echo json_encode(['installed' => false, 'blocked' => $blocked, 'message' => '以下函数被禁用: ' . implode(', ', $blocked)]) . PHP_EOL;
            exit(1);
        }
        break;

    case 'install':
        // 尝试解禁函数
        $phpIniPath = php_ini_loaded_file();
        
        if (!$phpIniPath) {
            echo json_encode(['success' => false, 'message' => '无法确定 php.ini 路径']) . PHP_EOL;
            exit(1);
        }

        if (!is_writable($phpIniPath)) {
            echo json_encode([
                'success' => false,
                'message' => 'php.ini 不可写',
                'path' => $phpIniPath,
                'guide' => [
                    'location' => $phpIniPath,
                    'action' => '请以管理员权限编辑该文件，找到 disable_functions，从中移除: ' . implode(', ', $requiredFunctions),
                    'verify' => '再次运行 php bin/w env:check',
                ],
            ]) . PHP_EOL;
            exit(1);
        }

        // 读取 php.ini
        $content = file_get_contents($phpIniPath);
        if ($content === false) {
            echo json_encode(['success' => false, 'message' => '无法读取 php.ini']) . PHP_EOL;
            exit(1);
        }

        // 查找 disable_functions 并修改
        $pattern = '/^(disable_functions\s*=\s*)(.*)$/m';
        if (!preg_match($pattern, $content, $matches)) {
            echo json_encode(['success' => true, 'message' => '未找到 disable_functions 配置，函数应该可用']) . PHP_EOL;
            exit(0);
        }

        $currentDisabled = array_map('trim', explode(',', $matches[2]));
        $newDisabled = array_diff($currentDisabled, $requiredFunctions);
        $newLine = 'disable_functions = ' . implode(',', array_filter($newDisabled));

        $newContent = preg_replace($pattern, $newLine, $content);
        if ($newContent === null) {
            echo json_encode(['success' => false, 'message' => '正则替换失败']) . PHP_EOL;
            exit(1);
        }

        // 写回 php.ini
        if (file_put_contents($phpIniPath, $newContent) === false) {
            echo json_encode(['success' => false, 'message' => '无法写入 php.ini']) . PHP_EOL;
            exit(1);
        }

        echo json_encode([
            'success' => true,
            'message' => '已从 disable_functions 中移除: ' . implode(', ', $requiredFunctions),
            'path' => $phpIniPath,
            'note' => '请重启 PHP/Web 服务以使更改生效',
        ]) . PHP_EOL;
        exit(0);
        break;

    default:
        echo json_encode(['error' => '未知动作: ' . $action . '，请使用 check 或 install']) . PHP_EOL;
        exit(1);
}
