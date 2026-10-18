<?php

declare(strict_types=1);

/**
 * brotli 扩展检测/安装（FPC html-disk 必装）
 *
 * Nginx 边缘 try_files 只服务 index.html.br。缺 brotli_compress 时发布只能落明文叶，
 * 公开页永远打源站。本脚本对 bundled PHP 用 phpize 从 PECL 源码构建（主路径）；
 * 失败退出码 1，安装链必须 fail-closed。
 *
 * 用法：
 *   php install_brotli_extension.php check   - 检查 brotli 是否可用
 *   php install_brotli_extension.php install - 尝试安装 brotli 扩展
 *
 * 退出码：
 *   0 - 成功
 *   1 - 失败
 */

$action = $argv[1] ?? 'check';

/** Linux 离线/网络失败时的 PECL 回退版本（与 pecl.php.net/package/brotli 对齐）。 */
const BROTLI_PECL_FALLBACK_VERSION = '0.15.2';

switch ($action) {
    case 'check':
        if (extension_loaded('brotli') && function_exists('brotli_compress') && function_exists('brotli_uncompress')) {
            echo json_encode([
                'installed' => true,
                'message' => 'brotli 扩展已加载（版本: ' . (phpversion('brotli') ?: 'unknown') . '）',
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(0);
        }

        echo json_encode([
            'installed' => false,
            'message' => 'brotli 扩展未安装或未提供 brotli_compress/brotli_uncompress',
            'guide' => getBrotliInstallGuide(),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);

    case 'install':
        if (extension_loaded('brotli') && function_exists('brotli_compress') && function_exists('brotli_uncompress')) {
            echo json_encode([
                'success' => true,
                'message' => 'brotli 扩展已加载，无需安装',
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(0);
        }

        $result = tryInstallBrotli();
        echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(!empty($result['success']) ? 0 : 1);

    default:
        echo json_encode([
            'error' => '未知动作: ' . $action . '，请使用 check 或 install',
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
}

/**
 * @return array{success:bool,message:string,guide?:array<string,string>,errors?:list<string>,enabled?:bool,restart_required?:bool}
 */
function tryInstallBrotli(): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            'success' => false,
            'message' => 'Windows 请从 https://windows.php.net/downloads/pecl/releases/brotli/ 下载匹配 ABI 的 php_brotli.dll，放入 ext 并启用 extension=brotli',
            'guide' => getBrotliInstallGuide(),
        ];
    }

    $errors = [];

    ensureBrotliDevPackage();

    // 1) pecl（系统 PHP / brew PHP 常用）
    $output = [];
    $code = 0;
    @exec('printf "\n" | pecl install brotli 2>&1', $output, $code);
    if ($code === 0) {
        enableBrotliExtensionInIni();

        return [
            'success' => true,
            'message' => 'brotli 扩展已通过 pecl 安装；新 PHP 进程将加载',
            'restart_required' => true,
        ];
    }
    $errors[] = 'pecl install brotli 失败: ' . implode("\n", array_slice($output, -5));

    // 2) bundled PHP：phpize 源码构建（主路径）
    $sourceBuild = tryBuildBrotliFromSource();
    if (!empty($sourceBuild['success'])) {
        return $sourceBuild;
    }
    $errors[] = '源码构建失败: ' . ($sourceBuild['message'] ?? 'unknown');

    return [
        'success' => false,
        'message' => 'brotli 扩展安装失败（FPC html-disk 必装，不可跳过）',
        'errors' => $errors,
        'guide' => getBrotliInstallGuide(),
    ];
}

function ensureBrotliDevPackage(): void
{
    $commands = [
        'apt-get install -y libbrotli-dev pkg-config',
        'dnf install -y libbrotli-devel pkgconf-pkg-config',
        'yum install -y libbrotli-devel pkgconfig',
        'apk add brotli-dev pkgconf',
        'brew install brotli pkgconf',
    ];
    foreach ($commands as $cmd) {
        $output = [];
        $code = 0;
        // Fixed allowlist of package-manager install commands only.
        // nosemgrep: php.lang.security.exec-use.exec-use
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code === 0) {
            return;
        }
    }
}

/**
 * @return array{success:bool,message:string,enabled?:bool,restart_required?:bool,detail?:string}
 */
function tryBuildBrotliFromSource(): array
{
    $phpBinDir = dirname(PHP_BINARY);
    $phpize = $phpBinDir . DIRECTORY_SEPARATOR . 'phpize';
    $phpConfig = $phpBinDir . DIRECTORY_SEPARATOR . 'php-config';

    if (!is_file($phpize) || !is_file($phpConfig)) {
        return ['success' => false, 'message' => '当前 PHP 未提供 phpize/php-config，无法源码构建'];
    }

    $version = resolveBrotliPeclVersion();
    $workDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'weline-brotli-' . getmypid();
    $archive = $workDir . '.tgz';
    $sourceDir = $workDir . DIRECTORY_SEPARATOR . 'brotli-' . $version;

    $cleanup = static function () use ($workDir, $archive): void {
        @exec('rm -rf ' . escapeshellarg($workDir) . ' ' . escapeshellarg($archive) . ' 2>/dev/null');
    };

    $run = static function (string $cmd, string $cwd): array {
        $output = [];
        $code = 0;
        @exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $output, $code);

        return [$code, implode("\n", array_slice($output, -8))];
    };

    $cleanup();
    if (!@mkdir($workDir, 0755, true) && !is_dir($workDir)) {
        return ['success' => false, 'message' => '无法创建构建目录: ' . $workDir];
    }

    $url = 'https://pecl.php.net/get/brotli-' . $version . '.tgz';
    if (!downloadBrotliUrlToFile($url, $archive)) {
        $cleanup();

        return ['success' => false, 'message' => '下载 brotli 源码失败: ' . $url];
    }

    [$code, $detail] = $run('tar xzf ' . escapeshellarg($archive), $workDir);
    if ($code !== 0 || !is_dir($sourceDir)) {
        $cleanup();

        return ['success' => false, 'message' => '解压 brotli 源码失败: ' . $detail];
    }

    $jobs = max(1, (int) trim((string) @shell_exec('nproc 2>/dev/null') ?: (@shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: '1')));
    $steps = [
        escapeshellarg($phpize),
        './configure --with-php-config=' . escapeshellarg($phpConfig),
        'make -j' . $jobs,
        'make install',
    ];

    foreach ($steps as $step) {
        [$code, $detail] = $run($step, $sourceDir);
        if ($code !== 0) {
            $cleanup();

            return [
                'success' => false,
                'message' => '源码构建步骤失败: ' . $step,
                'detail' => $detail,
            ];
        }
    }

    $cleanup();
    $enabled = enableBrotliExtensionInIni();

    return [
        'success' => true,
        'message' => 'brotli 扩展已从源码构建并安装（版本 ' . $version . '）'
            . ($enabled ? '' : '；请确认 extension=brotli 已启用'),
        'enabled' => $enabled,
        'restart_required' => true,
    ];
}

function resolveBrotliPeclVersion(): string
{
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = @file_get_contents('https://pecl.php.net/rest/r/brotli/latest.txt', false, $context);
    if (is_string($raw) && preg_match('/^\d+\.\d+\.\d+$/', trim($raw)) === 1) {
        return trim($raw);
    }

    return BROTLI_PECL_FALLBACK_VERSION;
}

function downloadBrotliUrlToFile(string $url, string $target): bool
{
    $curl = @shell_exec('command -v curl 2>/dev/null');
    if (is_string($curl) && trim($curl) !== '') {
        $output = [];
        $code = 0;
        @exec(
            'curl -fsSL --max-time 300 -o ' . escapeshellarg($target) . ' ' . escapeshellarg($url) . ' 2>&1',
            $output,
            $code
        );
        if ($code === 0 && is_file($target) && (int) filesize($target) > 0) {
            return true;
        }
    }

    $context = stream_context_create(['http' => ['timeout' => 300]]);
    $data = @file_get_contents($url, false, $context);

    return is_string($data) && $data !== '' && @file_put_contents($target, $data) !== false;
}

function enableBrotliExtensionInIni(): bool
{
    $ext = 'brotli';
    $phpIniPath = php_ini_loaded_file();
    if ($phpIniPath && is_writable($phpIniPath)) {
        $content = file_get_contents($phpIniPath);
        if ($content !== false) {
            $pattern = '/^\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*$/mi';
            if (preg_match($pattern, $content)) {
                return true;
            }
            $commentPattern = '/^;(\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*)$/mi';
            if (preg_match($commentPattern, $content)) {
                $content = preg_replace($commentPattern, '$1', $content);
            } else {
                $content .= "\nextension=" . $ext . "\n";
            }
            if (file_put_contents($phpIniPath, $content) !== false) {
                return true;
            }
        }
    }

    return enableBrotliExtensionInScanDir($ext);
}

function enableBrotliExtensionInScanDir(string $ext): bool
{
    $scanDir = defined('PHP_CONFIG_FILE_SCAN_DIR') ? (string) PHP_CONFIG_FILE_SCAN_DIR : '';
    if ($scanDir === '' || !is_dir($scanDir) || !is_writable($scanDir)) {
        // Fall back to --with-config-file-scan-dir next to php binary (bundled PHP).
        $candidate = dirname(PHP_BINARY) . '/../conf.d';
        $candidate = realpath($candidate) ?: $candidate;
        if (is_dir($candidate) && is_writable($candidate)) {
            $scanDir = $candidate;
        } else {
            $prefixConf = dirname(dirname(PHP_BINARY)) . '/conf.d';
            if (is_dir($prefixConf) && is_writable($prefixConf)) {
                $scanDir = $prefixConf;
            } else {
                return false;
            }
        }
    }

    $target = rtrim($scanDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'weline-' . $ext . '.ini';
    $desired = '; 由 Weline Framework env/script/install_brotli_extension.php 生成' . PHP_EOL
        . 'extension=' . $ext . '.so' . PHP_EOL;

    if (is_file($target) && file_get_contents($target) === $desired) {
        return true;
    }

    return file_put_contents($target, $desired) !== false;
}

/**
 * @return array<string, string>
 */
function getBrotliInstallGuide(): array
{
    return [
        'Ubuntu/Debian' => 'sudo apt-get install -y libbrotli-dev && pecl install brotli',
        'CentOS/RHEL' => 'sudo yum install -y libbrotli-devel && pecl install brotli',
        'Alpine' => 'apk add brotli-dev && pecl install brotli',
        'macOS' => 'brew install brotli && pecl install brotli',
        'bundled PHP（无 pecl）' => '安装 libbrotli-dev 后，用该 PHP 自带的 phpize/php-config '
            . '从 PECL 源码构建（本脚本自动执行）；或: bash bin/install.sh --rebuild-php php',
        '启用' => 'php.ini 或 conf.d 中添加: extension=brotli',
        '验证' => 'php -m | grep -i brotli && php -r \'echo function_exists("brotli_compress")?"ok":"missing";\'',
    ];
}
