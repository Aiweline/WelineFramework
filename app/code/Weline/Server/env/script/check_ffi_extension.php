<?php

declare(strict_types=1);

/**
 * FFI 扩展检测/启用（Linux/macOS Direct 硬依赖）
 *
 * FFI 是 PHP 核心扩展，须在编译期 --with-ffi（依赖 libffi）。
 * 本脚本不能从零编译 FFI；仅能检测，或在 extension_dir 已有 ffi.so 时写入 conf.d 启用。
 * 失败必须阻断安装/启动，禁止当推荐项软过。
 *
 * 用法：
 *   php check_ffi_extension.php check
 *   php check_ffi_extension.php install
 *
 * 退出码：0 成功；1 失败
 */

$action = $argv[1] ?? 'check';

switch ($action) {
    case 'check':
        if (ffiReady()) {
            echo json_encode([
                'installed' => true,
                'message' => 'FFI 扩展已加载',
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(0);
        }
        echo json_encode([
            'installed' => false,
            'message' => 'FFI 扩展未加载',
            'guide' => getFfiGuide(),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);

    case 'install':
        if (ffiReady()) {
            echo json_encode([
                'success' => true,
                'message' => 'FFI 扩展已加载，无需安装',
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(0);
        }
        $result = tryEnableFfi();
        echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(!empty($result['success']) ? 0 : 1);

    default:
        echo json_encode([
            'error' => '未知动作: ' . $action . '，请使用 check 或 install',
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
}

function ffiReady(): bool
{
    return \extension_loaded('ffi') && \class_exists(\FFI::class, false);
}

/**
 * @return array{success:bool,message:string,guide?:string}
 */
function tryEnableFfi(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            'success' => true,
            'message' => 'Windows Direct 使用 worker_ports，不硬依赖 FFI',
        ];
    }

    $extDir = (string)\ini_get('extension_dir');
    $so = '';
    if ($extDir !== '') {
        $candidate = \rtrim($extDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ffi.so';
        if (\is_file($candidate)) {
            $so = $candidate;
        } elseif (\is_dir($extDir)) {
            $scan = \glob(\rtrim($extDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ffi.so') ?: [];
            $so = $scan[0] ?? '';
        }
    }
    if ($so === '') {
        return [
            'success' => false,
            'message' => '当前 PHP 未编入 FFI（无 ffi.so）。须重编：bash bin/install.sh --rebuild-php php',
            'guide' => getFfiGuide(),
        ];
    }

    $confDir = resolvePhpConfDir();
    if ($confDir === null) {
        return [
            'success' => false,
            'message' => '无法定位 conf.d / php.ini 以启用 FFI',
            'guide' => getFfiGuide(),
        ];
    }

    $iniPath = $confDir . DIRECTORY_SEPARATOR . '20-ffi.ini';
    $body = "; WLS Direct shared-listener FD handoff\nextension=ffi\nffi.enable=true\n";
    if (@\file_put_contents($iniPath, $body, LOCK_EX) === false) {
        return [
            'success' => false,
            'message' => '无法写入 ' . $iniPath,
            'guide' => getFfiGuide(),
        ];
    }

    // 当前进程不会加载新 ini；用同二进制子进程探测。
    $probe = \escapeshellarg(PHP_BINARY)
        . ' -d opcache.enable=0 -d opcache.enable_cli=0 -r '
        . \escapeshellarg('exit(extension_loaded("ffi") && class_exists("FFI", false) ? 0 : 1);');
    $code = 1;
    \exec($probe, $out, $code);
    if ($code !== 0) {
        return [
            'success' => false,
            'message' => '已写 20-ffi.ini 但新进程仍未加载 FFI；请重编 PHP（--with-ffi）',
            'guide' => getFfiGuide(),
        ];
    }

    return [
        'success' => true,
        'message' => '已启用 FFI（' . $iniPath . '）',
    ];
}

function resolvePhpConfDir(): ?string
{
    $scan = (string)\php_ini_scanned_files();
    if ($scan !== '') {
        $first = \trim(\explode(',', $scan)[0] ?? '');
        if ($first !== '' && \is_file($first)) {
            return \dirname($first);
        }
    }
    $ini = \php_ini_loaded_file();
    if (\is_string($ini) && $ini !== '' && \is_file($ini)) {
        $dir = \dirname($ini) . DIRECTORY_SEPARATOR . 'conf.d';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        return \is_dir($dir) ? $dir : \dirname($ini);
    }
    $prefix = \dirname(PHP_BINARY, 2);
    $dir = $prefix . DIRECTORY_SEPARATOR . 'conf.d';
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0755, true);
    }

    return \is_dir($dir) ? $dir : null;
}

function getFfiGuide(): string
{
    return 'Linux/macOS：安装 libffi-dev（Debian）或 libffi-devel，然后 '
        . 'bash bin/install.sh --rebuild-php php（configure 含 --with-ffi）。'
        . ' Windows Direct 不硬依赖 FFI。';
}
