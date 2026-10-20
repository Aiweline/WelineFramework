<?php

declare(strict_types=1);

/**
 * FFI 扩展检测/启用（Linux/macOS Direct 硬依赖）
 *
 * FFI 是 PHP 核心扩展，须在编译期 --with-ffi（依赖 libffi）。
 * 本脚本不能从零编译 FFI；仅能检测，或在 extension_dir 已有 ffi.so 时
 * 交给 PhpExtensionIniEnabler 写入当前 PHP 真正会读的 ini（含宝塔 php.ini/php-cli.ini）。
 *
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

    $classFile = \dirname(__DIR__, 3) . '/Framework/Env/Service/PhpExtensionIniEnabler.php';
    if (!\is_file($classFile)) {
        $classFile = \dirname(__DIR__, 3) . '/Framework/env/Service/PhpExtensionIniEnabler.php';
    }
    require_once $classFile;
    $result = (new \Weline\Framework\Env\Service\PhpExtensionIniEnabler())->enable(
        'ffi',
        static function (): bool {
            $probe = \escapeshellarg(PHP_BINARY)
                . ' -d opcache.enable=0 -d opcache.enable_cli=0 -r '
                . \escapeshellarg('exit(extension_loaded("ffi") && class_exists("FFI", false) ? 0 : 1);');
            $code = 1;
            \exec($probe, $out, $code);

            return $code === 0;
        },
        ['ffi.enable=true']
    );
    if ($result['success']) {
        return [
            'success' => true,
            'message' => $result['message'] . '；ffi.so=' . $so,
        ];
    }

    return [
        'success' => false,
        'message' => $result['message'] . '；ffi.so=' . $so,
        'guide' => getFfiGuide(),
    ];
}

function getFfiGuide(): string
{
    return 'Linux/macOS：确认 extension_dir 有 ffi.so；在已加载 php.ini 取消注释 extension=ffi 并设 ffi.enable=true'
        . '（php --ini）。若无 ffi.so：安装 libffi-devel 后 bash bin/install.sh --rebuild-php php（--with-ffi）。'
        . ' Windows Direct 不硬依赖 FFI。';
}
