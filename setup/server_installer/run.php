<?php

declare(strict_types=1);

/**
 * 安装后统一入口：composer、env:check、env:install、PostgreSQL 建库/校验、setup:upgrade×2、server:stop/start。
 * 由 bin/install.bat 或 bin/install.sh 在安装 PHP/pgsql 后调用。
 */

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnvLoader.php';

/** 优先使用项目 PHP（extend/server/php），非 root 时需提权的命令由内部加 sudo */
$resolveProjectPhpBin = static function (string $root, string $phpDir): string {
    $bin = 'php';
    if (DIRECTORY_SEPARATOR === '\\') {
        if (is_file($phpDir . DIRECTORY_SEPARATOR . 'php.exe')) {
            $bin = '"' . $phpDir . DIRECTORY_SEPARATOR . 'php.exe' . '"';
        } elseif (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $bin = (string) PHP_BINARY;
        }
    } else {
        $phpPath = $phpDir . DIRECTORY_SEPARATOR . 'php';
        if (is_file($phpPath) && is_executable($phpPath)) {
            $bin = $phpPath;
        } elseif (defined('PHP_BINARY') && PHP_BINARY !== '' && PHP_BINARY !== 'php') {
            $bin = (string) PHP_BINARY;
        }
    }
    $installerIni = $phpDir . DIRECTORY_SEPARATOR . 'php.installer.ini';
    if (is_file($installerIni)) {
        $bin .= ' -c "' . $installerIni . '"';
    }
    return $bin;
};
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SetupPgsqlDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsurePgsqlData.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsureComposer.php';

$phpDirEarly = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
if (is_dir($phpDirEarly)) {
    try {
        $envEarly = (new EnvLoader($projectRoot))->load(true);
        (new ConfigurePhpIni($projectRoot, $phpDirEarly))->apply($envEarly);
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARNING: early php.ini configure failed: ' . $e->getMessage() . "\n");
    }
}

$argv = $GLOBALS['argv'] ?? [];
$envFileArg = null;
foreach ($argv as $idx => $arg) {
    if ($arg === '--env-file' && isset($argv[$idx + 1])) {
        $envFileArg = (string)$argv[$idx + 1];
        break;
    }
    if (str_starts_with((string)$arg, '--env-file=')) {
        $envFileArg = substr((string)$arg, strlen('--env-file='));
        break;
    }
}
if ($envFileArg !== null && trim($envFileArg) !== '') {
    putenv('WELINE_ENV_FILE=' . trim($envFileArg));
    $envLoaderForCheck = new EnvLoader($projectRoot, trim($envFileArg));
    $resolvedEnvFile = $envLoaderForCheck->resolveEnvFilePath();
    if (!is_file($resolvedEnvFile)) {
        fwrite(STDERR, "ERROR: env file not found: {$resolvedEnvFile}\n");
        exit(1);
    }
}

// === 检测系统是否已安装（env.php 存在且非空配置） ===
$envPhpFile = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$forceInstall = in_array('-f', $argv, true) || in_array('--force', $argv, true);
$autoUpgrade = in_array('-y', $argv, true) || in_array('--yes', $argv, true);

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
        if ($autoUpgrade) {
            // -y：系统已安装时直接执行 setup:upgrade，不询问、不断开（快捷路径，跳过 composer 等）
            $env = (new EnvLoader($projectRoot))->load(true);
            $phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
            $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
            $run = function (string $cmd) use ($phpBin): int {
                $full = $phpBin . ' ' . $cmd;
                echo "执行命令：$full\n";
                passthru($full, $code);
                return (int) $code;
            };
            $runWithUpgradeRetry = function (string $cmd, string $stage, string $setupLockPath, int $maxWaitSeconds = 120) use ($run): int {
                $code = $run($cmd);
                if ($code === 0 || !is_file($setupLockPath)) {
                    return $code;
                }
                $waitSeconds = max(0, $maxWaitSeconds);
                $interval = 2;
                while ($waitSeconds > 0 && is_file($setupLockPath)) {
                    echo "等待 setup:upgrade 执行中的锁释放，重试{$stage}（剩余 {$waitSeconds}s）...\n";
                    sleep($interval);
                    $waitSeconds -= $interval;
                }
                return $run($cmd);
            };
            if (is_dir($phpDir)) {
                try {
                    $iniCfg = new ConfigurePhpIni($projectRoot, $phpDir);
                    $iniCfg->apply($env);
                    $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
                    (new EnsureComposer($projectRoot))->ensure($phpBin);
                    EnsureComposer::applyEnvCommand($projectRoot, $phpBin);
                    $run = function (string $cmd) use ($phpBin): int {
                        $full = $phpBin . ' ' . $cmd;
                        echo "鎵ц鍛戒护锛?full\n";
                        passthru($full, $code);
                        return (int) $code;
                    };
                    $runWithUpgradeRetry = function (string $cmd, string $stage, string $setupLockPath, int $maxWaitSeconds = 120) use ($run): int {
                        $code = $run($cmd);
                        if ($code === 0 || !is_file($setupLockPath)) {
                            return $code;
                        }
                        $waitSeconds = max(0, $maxWaitSeconds);
                        $interval = 2;
                        while ($waitSeconds > 0 && is_file($setupLockPath)) {
                            echo "绛夊緟 setup:upgrade 鎵ц涓殑閿侀噴鏀撅紝閲嶈瘯{$stage}锛堝墿浣?{$waitSeconds}s锛?..\n";
                            sleep($interval);
                            $waitSeconds -= $interval;
                        }
                        return $run($cmd);
                    };
                } catch (Throwable $e) {
                    fwrite(STDERR, "WARNING: php.ini config: " . $e->getMessage() . "\n");
                }
            }
            $pgsqlBin = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
            if (is_dir($pgsqlBin) && strpos(getenv('PATH') ?: '', $pgsqlBin) === false) {
                putenv('PATH=' . $pgsqlBin . (DIRECTORY_SEPARATOR === '\\' ? ';' : ':') . getenv('PATH'));
            }
            (new EnsurePgsqlData($projectRoot))->ensure();
            $env = (new EnvLoader($projectRoot))->load(true);
            $setupDb = new SetupPgsqlDatabase($projectRoot, $env);
            if (!$setupDb->run()) {
                fwrite(STDERR, "ERROR: PostgreSQL 连接失败，无法执行 setup:upgrade。\n");
                exit(1);
            }
            $installModeFlagDir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'process';
            $installModeFlagFile = $installModeFlagDir . DIRECTORY_SEPARATOR . 'command_install_mode.flag';
            $setupLockPath = $installModeFlagDir . DIRECTORY_SEPARATOR . 'setup_upgrade.lock';
            is_dir($installModeFlagDir) || @mkdir($installModeFlagDir, 0755, true);
            @file_put_contents($installModeFlagFile, date('Y-m-d H:i:s') . ' - install mode enabled');
            echo "系统已安装，正在执行 setup:upgrade...\n";
            $code = $runWithUpgradeRetry('bin/w setup:upgrade -y', '(1/2)', $setupLockPath);
            if ($code !== 0) {
                @unlink($installModeFlagFile);
                exit(1);
            }
            $code = $runWithUpgradeRetry('bin/w setup:upgrade -y', '(2/2)', $setupLockPath);
            @unlink($installModeFlagFile);
            if ($code !== 0) {
                exit(1);
            }
            $run('bin/w server:stop');
            $run('bin/w server:start');
            echo "setup:upgrade 已完成。\n";
            exit(0);
        } else {
            // 系统已安装，提示用户
            echo "\n";
            echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
            echo "║                              系统已安装                                      ║\n";
            echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
            echo "║ 检测到 app/etc/env.php 已存在，说明系统已完成过安装。                        ║\n";
            echo "║                                                                              ║\n";
            echo "║ 建议操作：                                                                   ║\n";
            echo "║   1. 升级系统：php bin/w setup:upgrade                                       ║\n";
            echo "║   2. 或使用 -y 直接升级：php setup/server_installer/run.php -y               ║\n";
            echo "║   3. 重装系统：先删除 app/etc/env.php，再执行 php bin/w system:install       ║\n";
            echo "║                                                                              ║\n";
            echo "║ 如果确实需要重新执行安装脚本，请使用强制模式：                               ║\n";
            echo "║   php setup/server_installer/run.php -f                                      ║\n";
            echo "║   或通过安装脚本：bin/install.sh -f (Linux/Mac)                               ║\n";
            echo "║                                                                              ║\n";
            echo "║ ⚠ 警告：强制重装可能导致数据丢失，请先备份重要数据！                        ║\n";
            echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
            echo "\n";
            exit(0);
        }
    }
    
    // 强制安装模式：二次确认（-y 跳过；非 TTY 时提示用 -y）
    if (!$autoUpgrade) {
        $isTty = (function_exists('stream_isatty') && stream_isatty(STDIN));
        if (!$isTty) {
            echo "\n";
            echo "非交互式环境（无 TTY），无法输入确认。\n";
            echo "请使用 -y 跳过确认：php setup/server_installer/run.php -f -y\n";
            echo "或通过安装脚本：bin/install.sh -f -y\n";
            exit(1);
        }
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
        $input = trim((string) fgets($handle));
        fclose($handle);
        
        if (strtolower($input) !== 'yes') {
            echo "已取消安装。\n";
            exit(0);
        }
    } else {
        echo "强制安装模式 (-f -y)，跳过二次确认。\n";
    }
    echo "\n继续强制安装...\n\n";
}

$env = (new EnvLoader($projectRoot))->load(true);
$fromStep5b = in_array('--from', $argv, true)
    && (($i = array_search('--from', $argv, true)) !== false)
    && isset($argv[$i + 1])
    && $argv[$i + 1] === '5b';

$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
$phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
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

// 0a. Unix/Linux 下确保 bin/w、bin/m 可执行（便于 crontab 等直接执行 bin/w cron:task:run）
if (DIRECTORY_SEPARATOR !== '\\') {
    $binW = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';
    $binM = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'm';
    if (is_file($binW)) {
        @chmod($binW, 0755);
    }
    if (is_file($binM)) {
        @chmod($binM, 0755);
    }
}

// 0b. 在 composer 前先配置 php.ini（extension_dir、openssl、日志路径、扩展、disable_functions 等），避免 composer 报 “openssl extension is required”
$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
if (!$fromStep5b && is_dir($phpDir)) {
    echo "Step 0b: Configuring php.ini (log paths, extensions, functions)...\n";
    try {
        $iniCfg = new ConfigurePhpIni($projectRoot, $phpDir);
        $iniCfg->apply($env);
        $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
        $run = function (string $cmd) use ($projectRoot, $phpBin): int {
            $full = $phpBin . ' ' . $cmd;
            echo "执行命令：$full\n";
            passthru($full, $code);
            return (int) $code;
        };
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: php.ini configuration failed: " . $e->getMessage() . "\n");
    }
}

// 0c. 按 composer.json 下载 composer.phar 到 extend/server/（不纳入 Git）
if (!$fromStep5b) {
    (new EnsureComposer($projectRoot))->ensure($phpBin);
    EnsureComposer::applyEnvCommand($projectRoot, $phpBin);
}

// 1. composer install（无论 vendor 是否存在都执行，确保依赖完整）
if (!$fromStep5b) {
    $composerArgs = ' install -n --no-interaction';
    if (!extension_loaded('exif') || !extension_loaded('fileinfo')) {
        $composerArgs .= ' --ignore-platform-req=ext-exif --ignore-platform-req=ext-fileinfo';
        echo "exif/fileinfo 扩展未安装，composer 将忽略平台要求以继续安装。建议在宝塔面板中安装：软件商店 -> PHP -> 安装扩展 -> exif、fileinfo\n";
    }
    $composerPharQuoted = EnsureComposer::quotedPharPath($projectRoot);
    $code = $composerPharQuoted !== null
        ? $run($composerPharQuoted . $composerArgs)
        : $runRaw('composer' . $composerArgs);
    if ($code !== 0) {
        fwrite(STDERR, "ERROR: composer install failed (exit $code).\n");
        exit(1);
    }
}

// 2. env:check；未通过则先 env:install 再重检，仍不通过仅警告不退出（继续后续步骤）
if (!$fromStep5b) {
    $code = $run('bin/w env:check');
    if ($code !== 0) {
        echo "环境检测未通过，正在运行 env:install 尝试自动安装依赖...\n";
        $run('bin/w env:install -y');
        $code = $run('bin/w env:check');
        if ($code !== 0) {
            fwrite(STDERR, "WARNING: env:check 仍未通过 (exit $code)。将继续后续步骤，完成后请运行 php bin/w env:check 验证并手动修复缺失依赖。\n");
        }
    }
}

// 3. env:install -y（安装/补齐推荐项等）；失败不阻断，仅警告，允许后续步骤继续
if (!$fromStep5b) {
    $code = $run('bin/w env:install -y');
    if ($code !== 0) {
        fwrite(STDERR, "WARNING: env:install 有项未成功安装 (exit $code)。将继续后续步骤，请稍后运行 php bin/w env:check 验证并手动修复。\n");
    }
}

// 3b. Linux/macOS 下安装 crontab 依赖（cron:install 需要）；失败不阻断
if (!$fromStep5b && DIRECTORY_SEPARATOR !== '\\') {
    echo "Step 3b: 安装 crontab 依赖（cron:install 需要）...\n";
    $code = $run('bin/w env:install crontab -y');
    if ($code !== 0) {
        fwrite(STDERR, "WARNING: crontab 安装失败 (exit $code)。cron:install 可能不可用，可稍后运行 php bin/w env:install crontab -y。\n");
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
$env = (new EnvLoader($projectRoot))->load(true);

// 5a2. 数据库驱动自动安装：pdo_pgsql 未加载时执行 env:install pdo_pgsql -y，成功时重新执行本脚本（--from 5b）以便新进程加载扩展
if (!extension_loaded('pdo_pgsql')) {
    echo "Step 5a2: pdo_pgsql 未加载，正在执行 env:install pdo_pgsql -y 尝试自动安装...\n";
    $installCode = $run('bin/w env:install pdo_pgsql -y');
    if ($installCode === 0) {
        echo "数据库驱动安装已成功，正在重新执行以加载扩展...\n";
        $runPhp = is_file($phpDir . DIRECTORY_SEPARATOR . 'php.exe')
            ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php.exe"'
            : (is_file($phpDir . DIRECTORY_SEPARATOR . 'php') ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php"' : 'php');
        $runCmd = $runPhp . ' "' . $projectRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'server_installer' . DIRECTORY_SEPARATOR . 'run.php" --from 5b';
        passthru($runCmd, $code);
        exit($code);
    }
    fwrite(STDERR, "WARNING: env:install pdo_pgsql 未成功。Linux/Mac 请确保有 sudo 权限；Windows 需在 php.ini 启用 extension=pdo_pgsql 并将 extend/server/pgsql/bin 加入 PATH。\n");
}

// 5b. 每次运行都执行：根据 env DB_* 或项目级默认值同步 env.php 的 db，并视情况建库/校验连接
echo "Step 5b: PostgreSQL database init (from env DB_* or generated project DB)...\n";
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
    fwrite(STDERR, "ERROR: PostgreSQL database init failed. 未配置 DB_* 时会自动生成项目级数据库；若连接失败请按上方提示配置 PGSQL_INIT_* 后重试。\n");
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
