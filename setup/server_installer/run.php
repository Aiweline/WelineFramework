<?php

declare(strict_types=1);

/**
 * 安装后统一入口：composer、env:check、env:install、PostgreSQL 建库/校验、setup:upgrade×2、server:stop/start。
 * 由 bin/install.bat 或 bin/install.sh 在安装 PHP/pgsql 后调用。
 */

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnvLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SetupPgsqlDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsurePgsqlData.php';

$argv = $GLOBALS['argv'] ?? [];

// === 检测系统是否已安装（env.php 存在且非空配置） ===
$envPhpFile = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$forceInstall = in_array('-f', $argv, true) || in_array('--force', $argv, true);

/** env.php 非空（含 db 等有效配置）才视为已安装 */
$isEnvPhpInstalled = static function (string $path): bool {
    if (!is_file($path) || filesize($path) < 10) {
        return false;
    }
    $config = @include $path;
    return is_array($config) && isset($config['db']) && $config['db'] !== [];
};

if ($isEnvPhpInstalled($envPhpFile)) {
    if (!$forceInstall) {
        // 系统已安装，提示用户
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
        echo "║                              系统已安装                                      ║\n";
        echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
        echo "║ 检测到 app/etc/env.php 已存在，说明系统已完成过安装。                        ║\n";
        echo "║                                                                              ║\n";
        echo "║ 建议操作：                                                                   ║\n";
        echo "║   1. 升级系统：php bin/w setup:upgrade                                       ║\n";
        echo "║   2. 重装系统：先删除 app/etc/env.php，再执行 php bin/w system:install       ║\n";
        echo "║                                                                              ║\n";
        echo "║ 如果确实需要重新执行安装脚本，请使用强制模式：                               ║\n";
        echo "║   php setup/server_installer/run.php -f                                      ║\n";
        echo "║   或通过安装脚本：                                                           ║\n";
        echo "║   bin/install.bat -f (Windows) / ./bin/install.sh -f (Linux/Mac)             ║\n";
        echo "║                                                                              ║\n";
        echo "║ ⚠ 警告：强制重装可能导致数据丢失，请先备份重要数据！                        ║\n";
        echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        exit(0);
    }
    
    // 强制安装模式：二次确认
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                          ⚠ 强制安装警告 ⚠                                  ║\n";
    echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
    echo "║ 检测到 app/etc/env.php 已存在，您正在使用强制安装模式。                      ║\n";
    echo "║                                                                              ║\n";
    echo "║ ⚠ 这将重新执行完整安装流程，可能导致：                                      ║\n";
    echo "║   - 数据库配置被覆盖                                                         ║\n";
    echo "║   - 现有配置丢失                                                             ║\n";
    echo "║   - 其他不可预料的数据损失                                                   ║\n";
    echo "║                                                                              ║\n";
    echo "║ 请确认您已备份重要数据！                                                     ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "是否继续？输入 'yes' 确认继续，其他任意键取消：";
    
    $handle = fopen('php://stdin', 'r');
    $input = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($input) !== 'yes') {
        echo "已取消安装。\n";
        exit(0);
    }
    
    echo "\n继续强制安装...\n\n";
}

$env = (new EnvLoader($projectRoot))->load(true);
$fromStep5b = in_array('--from', $argv, true)
    && (($i = array_search('--from', $argv, true)) !== false)
    && isset($argv[$i + 1])
    && $argv[$i + 1] === '5b';

$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
$phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
$run = function (string $cmd) use ($projectRoot, $phpBin): int {
    $full = $phpBin . ' ' . $cmd;
    echo "执行命令：$full\n";
    passthru($full, $code);
    return (int) $code;
};
// 执行裸命令（不 prepend php），用于 composer 等独立可执行命令
$runRaw = function (string $cmd): int {
    echo "执行命令：$cmd\n";
    passthru($cmd, $code);
    return (int) $code;
};

// 0. 确保 generated/code 存在（Composer classmap 会扫描，缺失会报错）
$generatedCodeDir = $projectRoot . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'code';
if (!is_dir($generatedCodeDir)) {
    mkdir($generatedCodeDir, 0755, true);
    $gitkeep = $generatedCodeDir . DIRECTORY_SEPARATOR . '.gitkeep';
    if (!is_file($gitkeep)) {
        file_put_contents($gitkeep, "# 保留此目录以便 Composer classmap 可扫描；目录内生成文件由 .gitignore 忽略\n");
    }
    echo "Created generated/code/ for Composer autoload.\n";
}

// 0b. 在 composer 前先配置 php.ini（extension_dir、openssl、日志路径、扩展、disable_functions 等），避免 composer 报 “openssl extension is required”
$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
if (!$fromStep5b && is_dir($phpDir)) {
    echo "Step 0b: Configuring php.ini (log paths, extensions, functions)...\n";
    try {
        $iniCfg = new ConfigurePhpIni($projectRoot, $phpDir);
        $iniCfg->apply($env);
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: php.ini configuration failed: " . $e->getMessage() . "\n");
    }
}

// 1. composer install（无论 vendor 是否存在都执行，确保依赖完整）；composer 为独立命令，不能用 php composer 方式调用
if (!$fromStep5b) {
    $composerPhar = $projectRoot . DIRECTORY_SEPARATOR . 'composer.phar';
    $composerArgs = ' install -n --no-interaction';
    if (!extension_loaded('exif') || !extension_loaded('fileinfo')) {
        $composerArgs .= ' --ignore-platform-req=ext-exif --ignore-platform-req=ext-fileinfo';
        echo "exif/fileinfo 扩展未安装，composer 将忽略平台要求以继续安装。建议在宝塔面板中安装：软件商店 -> PHP -> 安装扩展 -> exif、fileinfo\n";
    }
    $code = is_file($composerPhar)
        ? $run($composerPhar . $composerArgs)
        : $runRaw('composer' . $composerArgs);
    if ($code !== 0) {
        fwrite(STDERR, "ERROR: composer install failed (exit $code).\n");
        exit(1);
    }
}

// 2. env:check；未通过则先 env:install 再重检，仍不通过再退出
if (!$fromStep5b) {
    $code = $run('bin/w env:check');
    if ($code !== 0) {
        echo "环境检测未通过，正在运行 env:install 尝试自动安装依赖...\n";
        $run('bin/w env:install -y');
        $code = $run('bin/w env:check');
        if ($code !== 0) {
            fwrite(STDERR, "ERROR: env:check failed (exit $code). Fix required dependencies and re-run.\n");
            exit(1);
        }
    }
}

// 3. env:install -y（安装/补齐推荐项等）
if (!$fromStep5b) {
    $code = $run('bin/w env:install -y');
    if ($code !== 0) {
        fwrite(STDERR, "ERROR: env:install failed (exit $code).\n");
        exit(1);
    }
}

// 4. （无独立步骤）

// 5. 将 pgsql/bin 加入 PATH（便于 psql / pdo_pgsql 加载 libpq.dll）
$pgsqlBin = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
if (is_dir($pgsqlBin)) {
    $currentPath = getenv('PATH') ?: '';
    $pathSep = (DIRECTORY_SEPARATOR === '\\') ? ';' : ':';
    if (strpos($currentPath, $pgsqlBin) === false) {
        putenv('PATH=' . $pgsqlBin . $pathSep . $currentPath);
    }
}

// 5a. 若 extend/server/pgsql 存在，确保 data 已初始化并启动（与 install.sh Linux 数据目录一致）
(new EnsurePgsqlData($projectRoot))->ensure();

// 5b. 每次运行都执行：根据 weline.env 同步 env.php 的 db，并视情况建库/校验连接
echo "Step 5b: PostgreSQL database init (from weline.env DB_*)...\n";
$setupDb = new SetupPgsqlDatabase($projectRoot, $env);
$step5bOk = $setupDb->run();

if (!$step5bOk && DIRECTORY_SEPARATOR === '\\' && is_dir($pgsqlBin)) {
    $libpq = $pgsqlBin . DIRECTORY_SEPARATOR . 'libpq.dll';
    if (!$fromStep5b && is_file($libpq) && !extension_loaded('pdo_pgsql')) {
        echo "Step 5b: pdo_pgsql needs libpq.dll. Re-running from Step 5b with PATH set...\n";
        $runPhp = is_file($phpDir . DIRECTORY_SEPARATOR . 'php.exe') ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php.exe"' : 'php';
        $runCmd = $runPhp . ' "' . $projectRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'server_installer' . DIRECTORY_SEPARATOR . 'run.php" --from 5b';
        passthru($runCmd, $code);
        exit($code);
    }
}

if (!$step5bOk) {
    fwrite(STDERR, "ERROR: PostgreSQL database init failed. 未配置时默认使用 postgres/postgres 与数据库 weline；若连接失败请按上方提示配置后重试。\n");
    exit(1);
}

// 6. 设置安装模式标志
// 安装模式下 command:upgrade 会扫描所有模块的命令（不管是否激活），确保 setup:upgrade、server:start 等核心命令可用
$installModeFlagDir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'process';
$installModeFlagFile = $installModeFlagDir . DIRECTORY_SEPARATOR . 'command_install_mode.flag';
if (!is_dir($installModeFlagDir)) {
    @mkdir($installModeFlagDir, 0755, true);
}
@file_put_contents($installModeFlagFile, date('Y-m-d H:i:s') . ' - install mode enabled');
echo "已启用安装模式：命令收集将扫描所有模块（包括未激活的模块）。\n";

// 7. setup:upgrade -y (1/2)
// 注意：command:upgrade 已集成到 setup:upgrade 中，会在 collectFrameworkRegistries 时自动执行
// 安装模式下会扫描所有模块的命令
$code = $run('bin/w setup:upgrade -y');
if ($code !== 0) {
    @unlink($installModeFlagFile); // 清除安装模式标志
    fwrite(STDERR, "ERROR: setup:upgrade (1/2) failed (exit $code). Fix the errors above and re-run install.\n");
    exit(1);
}

// 8. setup:upgrade -y (2/2)
$code = $run('bin/w setup:upgrade -y');
if ($code !== 0) {
    @unlink($installModeFlagFile); // 清除安装模式标志
    fwrite(STDERR, "ERROR: setup:upgrade (2/2) failed (exit $code). Fix the errors above and re-run install.\n");
    exit(1);
}

// 9. server:stop / server:start（在清除安装模式前执行，此时命令收集仍会扫描所有模块，server:* 可用）
// 10. server:stop（可选步骤，失败不影响安装）
$stopCode = $run('bin/w server:stop');
if ($stopCode !== 0) {
    echo "提示：server:stop 未执行（可能服务器未运行或首次安装），跳过。\n";
}

// 11. server:start（可选步骤，失败时给出手动启动提示）
// macOS 下使用特权端口（如 443）需要 sudo 权限
$isMac = PHP_OS === 'Darwin';
$serverPort = (int) ($env['SERVER_PORT'] ?? $env['server']['port'] ?? 9981);
$needSudo = $isMac && $serverPort < 1024;
$serverStartCmd = 'bin/w server:start';
if ($needSudo) {
    echo "提示：macOS 上绑定端口 {$serverPort} 需要管理员权限，将使用 sudo 启动...\n";
    $code = 0;
    $sudoCmd = 'sudo ' . $phpBin . ' ' . $serverStartCmd;
    echo "执行命令：$sudoCmd\n";
    passthru($sudoCmd, $code);
} else {
    $code = $run($serverStartCmd);
}
if ($code !== 0) {
    echo "\n";
    echo "提示：server:start 未能自动启动，您可以手动启动服务器：\n";
    if ($needSudo) {
        echo "  sudo php bin/w server:start\n";
    } else {
        echo "  php bin/w server:start\n";
    }
    echo "\n";
}

// 12. 清除安装模式标志（放在 server:stop/start 之后，确保执行时仍能解析 server:* 命令）
@unlink($installModeFlagFile);
echo "安装模式已关闭。\n";

exit(0);
