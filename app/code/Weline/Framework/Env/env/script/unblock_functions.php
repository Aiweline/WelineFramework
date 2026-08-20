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
 *
 * 与 setup/server_installer/ConfigurePhpIni.php::$defaultFunctions 保持同步。
 */

/** @var list<string> */
$requiredFunctions = [
    'exec', 'putenv', 'proc_open', 'proc_close', 'proc_get_status',
    'shell_exec', 'passthru', 'system', 'popen',
    'chown', 'chmod', 'chgrp',
    'symlink', 'link', 'readlink',
    'pcntl_fork', 'pcntl_signal', 'pcntl_signal_dispatch', 'pcntl_wait',
    'pcntl_waitpid', 'pcntl_async_signals', 'pcntl_alarm',
];

$action = $argv[1] ?? 'check';

$disabledFunctions = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$disabledFunctions = array_values(array_filter($disabledFunctions, static fn(string $f): bool => $f !== ''));

/**
 * @return list<string>
 */
function weline_env_collect_php_ini_paths(): array
{
    $paths = [];
    $loaded = php_ini_loaded_file();
    if (is_string($loaded) && $loaded !== '' && is_file($loaded)) {
        $paths[] = $loaded;
    }
    $scanned = php_ini_scanned_files();
    if (is_string($scanned) && $scanned !== '') {
        foreach (preg_split('/,\s*/', $scanned) ?: [] as $file) {
            $file = trim((string)$file);
            if ($file !== '' && is_file($file)) {
                $paths[] = $file;
            }
        }
    }

    // 宝塔 / 常见面板：CLI 与 FPM 可能共用或并列 php.ini
    $bin = (string)(PHP_BINARY ?? '');
    if (preg_match('#(/www/server/php/\d+)/#', $bin, $m) === 1
        || preg_match('#(/www/server/php/\d+)/#', (string)$loaded, $m) === 1) {
        $base = $m[1];
        foreach ([$base . '/etc/php.ini', $base . '/etc/php-cli.ini', $base . '/etc/php-fpm.ini'] as $candidate) {
            if (is_file($candidate)) {
                $paths[] = $candidate;
            }
        }
    }

    $unique = [];
    foreach ($paths as $path) {
        $real = realpath($path) ?: $path;
        $unique[$real] = $real;
    }
    return array_values($unique);
}

/**
 * @param list<string> $requiredFunctions
 * @return array{ok:bool,changed:list<string>,failed:list<string>,message:string}
 */
function weline_env_unblock_in_ini(string $phpIniPath, array $requiredFunctions): array
{
    if (!is_file($phpIniPath)) {
        return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'ini missing'];
    }

    $content = file_get_contents($phpIniPath);
    if ($content === false) {
        return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'read failed'];
    }

    $pattern = '/^(disable_functions\s*=\s*)(.*)$/m';
    if (!preg_match($pattern, $content, $matches)) {
        return ['ok' => true, 'changed' => [], 'failed' => [], 'message' => 'no disable_functions'];
    }

    $currentDisabled = array_map('trim', explode(',', $matches[2]));
    $currentDisabled = array_values(array_filter($currentDisabled, static fn(string $f): bool => $f !== ''));
    $removed = array_values(array_intersect($currentDisabled, $requiredFunctions));
    if ($removed === []) {
        return ['ok' => true, 'changed' => [], 'failed' => [], 'message' => 'already unblocked'];
    }

    $newDisabled = array_values(array_diff($currentDisabled, $requiredFunctions));
    $newLine = 'disable_functions = ' . implode(',', $newDisabled);
    $newContent = preg_replace($pattern, $newLine, $content);
    if (!is_string($newContent)) {
        return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'replace failed'];
    }

    if (is_writable($phpIniPath)) {
        if (file_put_contents($phpIniPath, $newContent) === false) {
            return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'write failed'];
        }
        return ['ok' => true, 'changed' => $removed, 'failed' => [], 'message' => 'updated'];
    }

    // sudo 回退（安装脚本 / root cron）
    $tempFile = tempnam(sys_get_temp_dir(), 'weline_php_ini_');
    if ($tempFile === false || file_put_contents($tempFile, $newContent) === false) {
        if (is_string($tempFile)) {
            @unlink($tempFile);
        }
        return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'temp write failed'];
    }
    $cmd = 'cp ' . escapeshellarg($tempFile) . ' ' . escapeshellarg($phpIniPath)
        . ' && chmod 0644 ' . escapeshellarg($phpIniPath);
    $output = [];
    $code = 1;
    @exec('sudo -n sh -c ' . escapeshellarg($cmd) . ' 2>&1', $output, $code);
    @unlink($tempFile);
    if ($code !== 0) {
        return ['ok' => false, 'changed' => [], 'failed' => [$phpIniPath], 'message' => 'sudo write failed'];
    }
    return ['ok' => true, 'changed' => $removed, 'failed' => [], 'message' => 'updated via sudo'];
}

function weline_env_try_reload_php_fpm(): void
{
    $bin = (string)(PHP_BINARY ?? '');
    $loaded = (string)(php_ini_loaded_file() ?: '');
    $ver = '';
    if (preg_match('#/www/server/php/(\d+)/#', $bin . ' ' . $loaded, $m) === 1) {
        $ver = $m[1];
    }
    $candidates = [];
    if ($ver !== '') {
        $candidates[] = '/etc/init.d/php-fpm-' . $ver . ' reload';
        $candidates[] = 'systemctl reload php-fpm-' . $ver;
    }
    $candidates[] = 'systemctl reload php-fpm';
    foreach ($candidates as $cmd) {
        $output = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code === 0) {
            return;
        }
    }
}

switch ($action) {
    case 'check':
        $blocked = [];
        foreach ($requiredFunctions as $func) {
            if (in_array($func, $disabledFunctions, true)) {
                $blocked[] = $func;
            }
        }
        if ($blocked === []) {
            echo json_encode(['installed' => true, 'message' => '所有必需函数都可用'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(0);
        }
        echo json_encode([
            'installed' => false,
            'blocked' => $blocked,
            'message' => '以下函数被禁用: ' . implode(', ', $blocked),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);

    case 'install':
        $iniPaths = weline_env_collect_php_ini_paths();
        if ($iniPaths === []) {
            echo json_encode(['success' => false, 'message' => '无法确定 php.ini 路径'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(1);
        }

        $allChanged = [];
        $failed = [];
        foreach ($iniPaths as $iniPath) {
            $result = weline_env_unblock_in_ini($iniPath, $requiredFunctions);
            if (!$result['ok']) {
                $failed[] = $iniPath . ':' . $result['message'];
                continue;
            }
            foreach ($result['changed'] as $fn) {
                $allChanged[$fn] = true;
            }
        }

        if ($failed !== [] && $allChanged === []) {
            echo json_encode([
                'success' => false,
                'message' => 'php.ini 不可写或解禁失败',
                'paths' => $iniPaths,
                'failed' => $failed,
                'guide' => [
                    'action' => '请以管理员权限编辑 php.ini，从 disable_functions 中移除: ' . implode(', ', $requiredFunctions),
                    'verify' => '再次运行 php bin/w env:check',
                ],
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(1);
        }

        weline_env_try_reload_php_fpm();

        echo json_encode([
            'success' => true,
            'message' => $allChanged === []
                ? 'disable_functions 已无需变更'
                : ('已从 disable_functions 中移除: ' . implode(', ', array_keys($allChanged))),
            'paths' => $iniPaths,
            'removed' => array_keys($allChanged),
            'failed' => $failed,
            'note' => '若 Web/FPM 仍报禁用，请确认已 reload PHP-FPM',
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);

    default:
        echo json_encode(['error' => '未知动作: ' . $action . '，请使用 check 或 install'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
}
