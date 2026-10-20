<?php

declare(strict_types=1);

/**
 * 安装后统一入口：composer、env:check、env:install、PostgreSQL 建库/校验、setup:upgrade×2、server:stop/start。
 * 由 bin/install.bat 或 bin/install.sh 在安装 PHP/pgsql 后调用。
 */

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnvLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DeployUserCommandRunner.php';

/**
 * Root 直接调用本脚本时：整段以部署用户重执行（与 bin/install.bash 一致），
 * 避免 composer/setup:upgrade/cron:install 把 vendor、var、crontab 写成 root。
 * Windows 允许管理员跑安装器；框架命令经 DeployUserCommandRunner 降到部署用户。
 */
if (
    DeployUserCommandRunner::shouldDropRootPrivileges($projectRoot)
    && \getenv('WELINE_INSTALLER_AS_DEPLOY_USER') !== '1'
) {
    $deployUser = DeployUserCommandRunner::resolveDeployUser($projectRoot);
    if (\function_exists('posix_getpwnam') && @\posix_getpwnam($deployUser) === false) {
        \fwrite(
            \STDERR,
            "ERROR: deploy user '{$deployUser}' does not exist. "
            . "Create it first (e.g. bin/install.sh as root), or set WELINE_USER / env.php user.\n"
        );
        exit(1);
    }
    $selfPhp = (\defined('PHP_BINARY') && \PHP_BINARY !== '') ? (string) \PHP_BINARY : 'php';
    $argList = \array_slice($argv, 1);
    $quotedArgs = \array_map('escapeshellarg', $argList);
    $path = (string) (\getenv('PATH') ?: '');
    $inner = 'cd ' . \escapeshellarg($projectRoot)
        . ' && export PATH=' . \escapeshellarg($path)
        . ' && export WELINE_INSTALLER_AS_DEPLOY_USER=1'
        . ' && export WELINE_USER=' . \escapeshellarg($deployUser)
        . ' && ' . \escapeshellarg($selfPhp) . ' ' . \escapeshellarg(__FILE__)
        . ($quotedArgs !== [] ? ' ' . \implode(' ', $quotedArgs) : '');
    $wrapped = DeployUserCommandRunner::privilegeDropPrefix($deployUser) . \escapeshellarg($inner);
    echo "run.php invoked as root; re-executing as deploy user {$deployUser}...\n";
    \passthru($wrapped, $reexecCode);
    exit((int) $reexecCode);
}

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
        foreach (['bin' . DIRECTORY_SEPARATOR . 'php', 'php'] as $rel) {
            $phpPath = $phpDir . DIRECTORY_SEPARATOR . $rel;
            if (is_file($phpPath) && is_executable($phpPath)) {
                $bin = $phpPath;
                break;
            }
        }
        if ($bin === 'php' && defined('PHP_BINARY') && PHP_BINARY !== '' && PHP_BINARY !== 'php') {
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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SetupMysqlDatabase.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsurePgsql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsurePgsqlData.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnsureComposer.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PgsqlProjectOwnership.php';

/** Resolve first-install DB driver: mysql|pgsql (default pgsql). Set via weline.env INSTALL_DB_DRIVER / DB_TYPE. */
$resolveInstallDbDriver = static function (string $root): string {
    $env = (new EnvLoader($root))->load(true);
    $raw = \strtolower(\trim((string)($env['INSTALL_DB_DRIVER'] ?? $env['DB_TYPE'] ?? '')));
    if (\in_array($raw, ['mysql', 'mariadb'], true)) {
        return 'mysql';
    }
    if ($raw === 'pgsql' || $raw === 'postgresql' || $raw === 'postgres') {
        return 'pgsql';
    }
    return 'pgsql';
};

/** 配置 php.ini 并确保 extend/server/composer.phar 存在（已安装环境重跑 install 时也会补全） */
$ensureLocalComposer = static function (string $root) use ($resolveProjectPhpBin): bool {
    $phpDir = $root . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
    if (!is_dir($phpDir)) {
        fwrite(STDERR, "ERROR: 未找到项目 PHP 目录：{$phpDir}\n");
        return false;
    }
    try {
        $env = (new EnvLoader($root))->load(true);
        (new ConfigurePhpIni($root, $phpDir))->apply($env);
        $phpBin = $resolveProjectPhpBin($root, $phpDir);
        $ok = (new EnsureComposer($root))->ensure($phpBin);
        EnsureComposer::applyEnvCommand($root, $phpBin);
        return $ok;
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: 确保 composer.phar 失败：' . $e->getMessage() . "\n");
        return false;
    }
};

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

$pgsqlOwnership = new PgsqlProjectOwnership($projectRoot);
$envPhpHadDbAtStart = $isEnvPhpInstalled($envPhpFile);
$ensureProjectPgsqlBinary = static function (?string $major = null) use ($projectRoot): bool {
    // Always go through EnsurePgsql validation. A lone pg_ctl (or Debian
    // /usr/bin wrapper tree) must not short-circuit a broken project bindir.
    $env = (new EnvLoader($projectRoot))->load(true);
    if ($major !== null && $major !== '') {
        $env['INSTALL_PGSQL_VERSION'] = $major;
    }
    return (new EnsurePgsql($projectRoot))->ensure($env);
};

$ensureConfiguredDatabaseReady = static function () use ($projectRoot, $pgsqlOwnership, $ensureProjectPgsqlBinary): bool {
    $target = $pgsqlOwnership->targetFromEnvPhp();
    if ($target === null) {
        fwrite(STDERR, "ERROR: app/etc/env.php exists but db.master is missing.\n");
        return false;
    }

    $pgsqlOwnership->printTargetSummary($target, 'env.php db.master');
    $classification = $pgsqlOwnership->classifyEnvTarget($target);
    echo ($classification['message'] ?? '') . "\n";
    if (($classification['error'] ?? false) === true) {
        return false;
    }

    if (($classification['manageable'] ?? false) === true) {
        $ensureData = new EnsurePgsqlData($projectRoot, false);
        if (!$ensureData->hasProjectPgCtl()) {
            $major = $ensureData->getDataMajorVersion();
            if (!$ensureProjectPgsqlBinary($major)) {
                fwrite(STDERR, "ERROR: PostgreSQL binary is missing and could not be installed/linked for project data.\n");
                return false;
            }
        }
        $preferredPort = (int)($target['port'] ?? 5432);
        if (!$ensureData->ensure($preferredPort)) {
            return false;
        }
        $resolvedPort = $ensureData->getConfiguredPort();
        if ($resolvedPort !== null && (int)($target['port'] ?? 0) !== $resolvedPort) {
            if (!$pgsqlOwnership->updateEnvPhpDbPort($resolvedPort)) {
                return false;
            }
            $target['port'] = (string)$resolvedPort;
        }
        if (!$pgsqlOwnership->validateOwnershipMarkers($target)) {
            return false;
        }
    }

    return $pgsqlOwnership->verifyTargetConnection($target);
};

$initializeFirstInstallDatabase = static function () use ($projectRoot, $pgsqlOwnership, $ensureProjectPgsqlBinary): bool {
    if (!$ensureProjectPgsqlBinary(null)) {
        fwrite(STDERR, "ERROR: PostgreSQL binary install/link failed.\n");
        return false;
    }
    if (!(new EnsurePgsqlData($projectRoot, true))->ensure()) {
        return false;
    }
    $env = (new EnvLoader($projectRoot))->load(true);
    $setupDb = new SetupPgsqlDatabase($projectRoot, $env);
    if (!$setupDb->run()) {
        return false;
    }
    $target = $pgsqlOwnership->targetFromEnvPhp();
    $pgsqlOwnership->printTargetSummary($target, 'created db.master');
    if ($target !== null && !$pgsqlOwnership->ensureOwnershipMarkers($target)) {
        return false;
    }
    return true;
};

if ($envPhpHadDbAtStart) {
    if ($forceInstall) {
        echo "app/etc/env.php already has db.master; -f will not overwrite database config. Delete env.php for a fresh install.\n";
    }
    if ($autoUpgrade) {
        $env = (new EnvLoader($projectRoot))->load(true);
        $phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
        $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
        if (is_dir($phpDir)) {
            try {
                (new ConfigurePhpIni($projectRoot, $phpDir))->apply($env);
                $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
                (new EnsureComposer($projectRoot))->ensure($phpBin);
                EnsureComposer::applyEnvCommand($projectRoot, $phpBin);
            } catch (Throwable $e) {
                fwrite(STDERR, "WARNING: php.ini/composer config failed: " . $e->getMessage() . "\n");
            }
        }

        $run = static function (string $cmd) use ($projectRoot, $phpBin): int {
            return DeployUserCommandRunner::runPhp($projectRoot, $phpBin, $cmd);
        };
        $runWithUpgradeRetry = function (string $cmd, string $stage, string $setupLockPath, int $maxWaitSeconds = 120) use ($run): int {
            $code = $run($cmd);
            if ($code === 0 || !is_file($setupLockPath)) {
                return $code;
            }
            $waitSeconds = max(0, $maxWaitSeconds);
            $interval = 2;
            while ($waitSeconds > 0 && is_file($setupLockPath)) {
                echo "Waiting for setup:upgrade lock before retrying {$stage} ({$waitSeconds}s left)...\n";
                sleep($interval);
                $waitSeconds -= $interval;
            }
            return $run($cmd);
        };

        $pgsqlBin = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
        if (is_dir($pgsqlBin) && strpos(getenv('PATH') ?: '', $pgsqlBin) === false) {
            putenv('PATH=' . $pgsqlBin . (DIRECTORY_SEPARATOR === '\\' ? ';' : ':') . getenv('PATH'));
        }

        $dbReady = $pgsqlOwnership->withInstallLock($ensureConfiguredDatabaseReady);
        if ($dbReady !== true) {
            fwrite(STDERR, "ERROR: Database configured in env.php is not ready; setup:upgrade was not started.\n");
            exit(1);
        }

        $installModeFlagDir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'process';
        $installModeFlagFile = $installModeFlagDir . DIRECTORY_SEPARATOR . 'command_install_mode.flag';
        $setupLockPath = $installModeFlagDir . DIRECTORY_SEPARATOR . 'setup_upgrade.lock';
        is_dir($installModeFlagDir) || @mkdir($installModeFlagDir, 0755, true);
        @file_put_contents($installModeFlagFile, date('Y-m-d H:i:s') . ' - install mode enabled');
        echo "env.php is authoritative. Running setup:upgrade with the configured database target...\n";
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
        echo "setup:upgrade completed.\n";
        exit(0);
    }

    $target = $pgsqlOwnership->targetFromEnvPhp();
    $pgsqlOwnership->printTargetSummary($target, 'env.php db.master');
    $classification = $pgsqlOwnership->classifyEnvTarget($target);
    echo ($classification['message'] ?? '') . "\n";
    echo "env.php db.master is authoritative. Re-run with -y to run setup:upgrade, or delete env.php for a fresh project-local install.\n";
    exit(0);

    if (!$forceInstall) {
        if ($autoUpgrade) {
            // -y：系统已安装时直接执行 setup:upgrade，不询问、不断开（快捷路径，跳过 composer 等）
            $env = (new EnvLoader($projectRoot))->load(true);
            $phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
            $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
            $run = static function (string $cmd) use ($projectRoot, $phpBin): int {
                return DeployUserCommandRunner::runPhp($projectRoot, $phpBin, $cmd);
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
                    $run = static function (string $cmd) use ($projectRoot, $phpBin): int {
                        return DeployUserCommandRunner::runPhp($projectRoot, $phpBin, $cmd);
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
            // 注意：本分支当前位于上方 exit(0) 之后，为历史死代码；主链见 $requireDirectRuntimeExtensions。
            // FPC html-disk：已装站快捷路径也必须有 brotli（与主链一致，失败即停）
            if (!\extension_loaded('brotli') || !\function_exists('brotli_compress') || !\function_exists('brotli_uncompress')) {
                echo "brotli 扩展未加载，正在必装（FPC html-disk）...\n";
                $brotliCode = $run('bin/w env:install brotli -y');
                $brotliProbe = $run('-r ' . \escapeshellarg(
                    'exit(extension_loaded("brotli") && function_exists("brotli_compress")'
                    . ' && function_exists("brotli_uncompress") ? 0 : 1);'
                ));
                if ($brotliProbe !== 0) {
                    \fwrite(\STDERR, "ERROR: brotli 扩展必装失败（env=$brotliCode probe=$brotliProbe）。FPC html-disk 需要 brotli_compress。\n");
                    \fwrite(\STDERR, "  请安装 libbrotli-dev 后执行: bash bin/install.sh --rebuild-php php\n");
                    exit(1);
                }
                echo "brotli 扩展已验证可用。\n";
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
            // 系统已安装：报告 composer 状态；缺失时补全（避免仅提示退出后 setup:upgrade 找不到 composer）
            echo "========== Composer ==========\n";
            $composerPhar = EnsureComposer::pharPath($projectRoot);
            $composerOk = is_file($composerPhar) && filesize($composerPhar) >= 500_000;
            if ($composerOk) {
                echo "composer.phar already present at {$composerPhar}\n\n";
            } else {
                echo "检测到 composer.phar 缺失或无效，正在补全...\n";
                if (!$ensureLocalComposer($projectRoot)) {
                    fwrite(STDERR, "ERROR: 无法下载 composer.phar 到 extend/server/，请检查网络后重试。\n");
                    exit(1);
                }
                echo "composer.phar 已补全。\n\n";
            }

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
$run = static function (string $cmd) use ($projectRoot, $phpBin): int {
    return DeployUserCommandRunner::runPhp($projectRoot, $phpBin, $cmd);
};
/**
 * Linux/macOS WLS auto→Direct 硬依赖：先装 event（+ 校验 FFI），再允许启动。
 * Windows Direct=worker_ports 不硬依赖 event/FFI。
 */
$requireDirectRuntimeExtensions = function () use ($run, $projectRoot, $phpBin): void {
    if (\PHP_OS_FAMILY === 'Windows') {
        echo "Windows Direct 使用 worker_ports，跳过 Unix Direct 扩展硬校验。\n";
        return;
    }
    $hasEvent = static function (): bool {
        return \extension_loaded('event') && \class_exists(\EventBase::class, false);
    };
    if (!$hasEvent()) {
        echo "event 扩展未加载，正在必装（WLS Linux/macOS Direct）...\n";
        $code = $run('bin/w env:install event -y');
        if ($code !== 0) {
            $script = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code'
                . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Server'
                . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'script'
                . DIRECTORY_SEPARATOR . 'install_event_extension.php';
            if (\is_file($script) && $phpBin !== '') {
                echo "env:install event 未成功，改用 pecl/phpize 脚本...\n";
                $code = DeployUserCommandRunner::runPhp(
                    $projectRoot,
                    $phpBin,
                    \escapeshellarg($script) . ' install'
                );
            }
        }
        $probe = DeployUserCommandRunner::runPhp(
            $projectRoot,
            $phpBin,
            '-r ' . \escapeshellarg(
                'exit(extension_loaded("event") && class_exists("EventBase", false) ? 0 : 1);'
            )
        );
        if ($probe !== 0) {
            \fwrite(\STDERR, "ERROR: event 扩展必装失败（exit env=$code probe=$probe）。\n");
            \fwrite(\STDERR, "  Linux/macOS Direct 需要 ext-event；请安装 libevent-dev 后执行:\n");
            \fwrite(\STDERR, "  bash bin/install.sh --rebuild-php php\n");
            \fwrite(\STDERR, "  或: php bin/w env:install event -y\n");
            exit(1);
        }
        echo "event 扩展已验证可用。\n";
    } else {
        echo "event 扩展已加载，跳过必装。\n";
    }

    $ffiProbe = DeployUserCommandRunner::runPhp(
        $projectRoot,
        $phpBin,
        '-r ' . \escapeshellarg(
            'exit(extension_loaded("ffi") && class_exists("FFI", false) ? 0 : 1);'
        )
    );
    if ($ffiProbe !== 0) {
        $ffiScript = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code'
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Server'
            . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'script'
            . DIRECTORY_SEPARATOR . 'check_ffi_extension.php';
        if (\is_file($ffiScript) && $phpBin !== '') {
            echo "FFI 未加载，尝试启用（WLS Direct 共享监听交接）...\n";
            DeployUserCommandRunner::runPhp(
                $projectRoot,
                $phpBin,
                \escapeshellarg($ffiScript) . ' install'
            );
            $ffiProbe = DeployUserCommandRunner::runPhp(
                $projectRoot,
                $phpBin,
                '-r ' . \escapeshellarg(
                    'exit(extension_loaded("ffi") && class_exists("FFI", false) ? 0 : 1);'
                )
            );
        }
        if ($ffiProbe !== 0) {
            \fwrite(\STDERR, "ERROR: FFI 扩展不可用。Linux/macOS Direct 共享监听交接需要 --with-ffi。\n");
            \fwrite(\STDERR, "  请安装 libffi-dev 后执行: bash bin/install.sh --rebuild-php php\n");
            exit(1);
        }
    }
    echo "FFI 扩展已验证可用。\n";
};
/** FPC html-disk 硬依赖：缺 brotli 则安装失败退出。 */
$requireBrotliExtension = function () use ($run, $projectRoot, $phpBin): void {
    $hasBrotli = static function (): bool {
        return \extension_loaded('brotli')
            && \function_exists('brotli_compress')
            && \function_exists('brotli_uncompress');
    };
    if ($hasBrotli()) {
        echo "brotli 扩展已加载，跳过必装。\n";
        return;
    }
    echo "brotli 扩展未加载，正在必装（FPC html-disk / Nginx index.html.br）...\n";
    $code = $run('bin/w env:install brotli -y');
    if ($code !== 0) {
        // 契约：Framework/Env/env/script。旧树 Framework/env/env/script 仅作回退。
        $frameworkRoot = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code'
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Framework';
        $scriptCandidates = [
            $frameworkRoot . DIRECTORY_SEPARATOR . 'Env' . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'script' . DIRECTORY_SEPARATOR . 'install_brotli_extension.php',
            $frameworkRoot . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'script' . DIRECTORY_SEPARATOR . 'install_brotli_extension.php',
        ];
        $script = '';
        foreach ($scriptCandidates as $candidate) {
            if (is_file($candidate)) {
                $script = $candidate;
                break;
            }
        }
        if ($script !== '' && $phpBin !== '') {
            echo "env:install brotli 未成功，改用 phpize 源码脚本...\n";
            $code = DeployUserCommandRunner::runPhp(
                $projectRoot,
                $phpBin,
                escapeshellarg($script) . ' install'
            );
        }
    }
    // 当前进程不会动态加载新扩展；用同二进制新进程探测。
    $probe = DeployUserCommandRunner::runPhp(
        $projectRoot,
        $phpBin,
        '-r ' . escapeshellarg(
            'exit(extension_loaded("brotli") && function_exists("brotli_compress")'
            . ' && function_exists("brotli_uncompress") ? 0 : 1);'
        )
    );
    if ($probe !== 0) {
        fwrite(STDERR, "ERROR: brotli 扩展必装失败（exit env=$code probe=$probe）。\n");
        fwrite(STDERR, "  FPC html-disk 需要 brotli_compress；请安装 libbrotli-dev 后执行:\n");
        fwrite(STDERR, "  bash bin/install.sh --rebuild-php php\n");
        fwrite(STDERR, "  或: php bin/w env:install brotli -y\n");
        exit(1);
    }
    echo "brotli 扩展已验证可用。\n";
};
// 执行裸命令（不 prepend php），用于 composer 等独立可执行命令
$runRaw = static function (string $cmd) use ($projectRoot): int {
    return DeployUserCommandRunner::runShell($projectRoot, $cmd);
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
        $run = static function (string $cmd) use ($projectRoot, $phpBin): int {
            return DeployUserCommandRunner::runPhp($projectRoot, $phpBin, $cmd);
        };
    } catch (Throwable $e) {
        fwrite(STDERR, "WARNING: php.ini configuration failed: " . $e->getMessage() . "\n");
    }
}

// 0c. 按 composer.json 下载 composer.phar 到 extend/server/（不纳入 Git）
if (!$fromStep5b) {
    echo "========== Composer ==========\n";
    if (!$ensureLocalComposer($projectRoot)) {
        fwrite(STDERR, "ERROR: 无法确保 composer.phar，安装中止。\n");
        exit(1);
    }
    $phpBin = $resolveProjectPhpBin($projectRoot, $phpDir);
}

// 1. composer install（无论 vendor 是否存在都执行，确保依赖完整）
if (!$fromStep5b) {
    $composerArgs = ' install -n --no-interaction';
    if (!extension_loaded('exif') || !extension_loaded('fileinfo')) {
        $composerArgs .= ' --ignore-platform-req=ext-exif --ignore-platform-req=ext-fileinfo';
        echo "exif/fileinfo 扩展未安装，composer 将忽略平台要求以继续安装。若使用 WLS 自带 PHP，请执行: bash bin/install.sh --rebuild-php php\n";
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
    $requireDirectRuntimeExtensions();
    $requireBrotliExtension();
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

$installDbDriver = $resolveInstallDbDriver($projectRoot);
echo "Step 5: install DB driver = {$installDbDriver}\n";

// 5. 将 pgsql/bin 加入 PATH（便于 psql / pdo_pgsql 加载 libpq.dll）——仅 pgsql 路径需要
$pgsqlBin = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql' . DIRECTORY_SEPARATOR . 'bin';
if ($installDbDriver === 'pgsql' && is_dir($pgsqlBin)) {
    $currentPath = getenv('PATH') ?: '';
    $pathSep = (DIRECTORY_SEPARATOR === '\\') ? ';' : ':';
    if (strpos($currentPath, $pgsqlBin) === false) {
        putenv('PATH=' . $pgsqlBin . $pathSep . $currentPath);
    }
}

$step5bOk = false;
if ($installDbDriver === 'mysql') {
    // MySQL/MariaDB first-install: skip project-local PostgreSQL entirely.
    if (!$envPhpHadDbAtStart) {
        echo "Step 5b: First install MySQL/MariaDB database init...\n";
        if (!extension_loaded('pdo_mysql')) {
            echo "Step 5a2: pdo_mysql 未加载，正在执行 env:install pdo_mysql -y...\n";
            $installCode = $run('bin/w env:install pdo_mysql -y');
            if ($installCode === 0) {
                $runPhp = is_file($phpDir . DIRECTORY_SEPARATOR . 'php.exe')
                    ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php.exe"'
                    : (is_file($phpDir . DIRECTORY_SEPARATOR . 'php') ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php"' : 'php');
                $runCmd = $runPhp . ' "' . $projectRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'server_installer' . DIRECTORY_SEPARATOR . 'run.php" --from 5b';
                $code = DeployUserCommandRunner::runShell($projectRoot, $runCmd);
                exit($code);
            }
            fwrite(STDERR, "WARNING: env:install pdo_mysql 未成功；将仍尝试 PDO 连接。\n");
        }
        $env = (new EnvLoader($projectRoot))->load(true);
        $step5bOk = (new SetupMysqlDatabase($projectRoot, $env))->run();
    } else {
        echo "Step 5b: Verifying MySQL from env.php (no database/user creation)...\n";
        $env = (new EnvLoader($projectRoot))->load(true);
        $target = $pgsqlOwnership->targetFromEnvPhp();
        // For mysql targets, classifyEnvTarget skips local pgsql; just try reconnect via SetupMysqlDatabase settings.
        $master = null;
        if (is_file($envPhpFile)) {
            $cfg = @include $envPhpFile;
            $master = is_array($cfg) ? ($cfg['db']['master'] ?? null) : null;
        }
        if (is_array($master) && ($master['type'] ?? '') === 'mysql') {
            try {
                $h = (string)($master['hostname'] ?? '127.0.0.1');
                $p = (string)($master['hostport'] ?? '3306');
                $d = (string)($master['database'] ?? '');
                $u = (string)($master['username'] ?? '');
                $pw = (string)($master['password'] ?? '');
                new PDO("mysql:host={$h};port={$p};dbname={$d};charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $step5bOk = true;
                echo "  MySQL connection OK.\n";
            } catch (Throwable $e) {
                fwrite(STDERR, 'ERROR: MySQL in env.php not ready: ' . $e->getMessage() . "\n");
                $step5bOk = false;
            }
        } else {
            $step5bOk = false;
            fwrite(STDERR, "ERROR: env.php db.master is not mysql while INSTALL_DB_DRIVER=mysql.\n");
        }
    }
    if (!$step5bOk) {
        fwrite(STDERR, "ERROR: MySQL database init failed. Set DB_* / DB_ROOT_PASSWORD in weline.env or ensure mariadb is running (sudo mysql).\n");
        exit(1);
    }
} else {
    // 5a. 若 extend/server/pgsql 存在，确保 data 已初始化并启动（与 install.sh Linux 数据目录一致）
    $envTargetBeforeDbSetup = $pgsqlOwnership->targetFromEnvPhp();
    if (!$envPhpHadDbAtStart) {
        $pgsqlPrepared = $pgsqlOwnership->withInstallLock(static function () use ($projectRoot, $ensureProjectPgsqlBinary): bool {
            if (!$ensureProjectPgsqlBinary(null)) {
                return false;
            }
            return (new EnsurePgsqlData($projectRoot, true))->ensure();
        });
        if ($pgsqlPrepared !== true) {
            fwrite(STDERR, "ERROR: PostgreSQL project data preparation failed.\n");
            exit(1);
        }
    } else {
        $pgsqlOwnership->printTargetSummary($envTargetBeforeDbSetup, 'env.php db.master');
        echo "env.php db.master exists before database setup; local PostgreSQL init is skipped unless this project owns the data.\n";
    }
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
            $code = DeployUserCommandRunner::runShell($projectRoot, $runCmd);
            exit($code);
        }
        fwrite(STDERR, "WARNING: env:install pdo_pgsql 未成功。Linux/Mac 请确保有 sudo 权限；Windows 需在 php.ini 启用 extension=pdo_pgsql 并将 extend/server/pgsql/bin 加入 PATH。\n");
    }

    // 5b. 每次运行都执行：根据 env DB_* 或项目级默认值同步 env.php 的 db，并视情况建库/校验连接
    // env.php is authoritative. Only first install may create database/user.
    $envTargetAtDbSetup = $pgsqlOwnership->targetFromEnvPhp();
    if ($envPhpHadDbAtStart && $envTargetAtDbSetup !== null) {
        echo "Step 5b: Verifying database from env.php (no database/user creation)...\n";
        $step5bOk = $pgsqlOwnership->withInstallLock($ensureConfiguredDatabaseReady);
    } else {
        echo "Step 5b: First install PostgreSQL database init (project-local DB)...\n";
        $step5bOk = $pgsqlOwnership->withInstallLock($initializeFirstInstallDatabase);
    }

    if (!$step5bOk && DIRECTORY_SEPARATOR === '\\' && is_dir($pgsqlBin)) {
        $libpq = $pgsqlBin . DIRECTORY_SEPARATOR . 'libpq.dll';
        if (!$fromStep5b && is_file($libpq) && !extension_loaded('pdo_pgsql')) {
            echo "Step 5b: pdo_pgsql needs libpq.dll. Re-running from Step 5b with PATH set...\n";
            $runPhp = is_file($phpDir . DIRECTORY_SEPARATOR . 'php.exe') ? '"' . $phpDir . DIRECTORY_SEPARATOR . 'php.exe"' : 'php';
            $runCmd = $runPhp . ' "' . $projectRoot . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'server_installer' . DIRECTORY_SEPARATOR . 'run.php" --from 5b';
            $code = DeployUserCommandRunner::runShell($projectRoot, $runCmd);
            exit($code);
        }
    }

    if (!$step5bOk) {
        fwrite(STDERR, "ERROR: PostgreSQL database init failed. 未配置 DB_* 时会自动生成项目级数据库；若连接失败请按上方提示配置 PGSQL_INIT_* 后重试。\n");
        exit(1);
    }
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
$runSetupUpgrade = function (string $cmd, string $stage) use ($run): int {
    $code = $run($cmd);
    if ($code === 0) {
        return 0;
    }

    echo "setup:upgrade {$stage} failed (exit {$code}); rechecking environment in a fresh PHP process before one retry...\n";
    $envCode = $run('bin/w env:check');
    if ($envCode !== 0) {
        echo "Environment still fails in a fresh PHP process (exit {$envCode}); setup:upgrade will not be retried.\n";
        return $code;
    }

    echo "Environment check now passes; retrying setup:upgrade {$stage} once...\n";
    return $run($cmd);
};
$code = $runSetupUpgrade('bin/w setup:upgrade -y', '(1/2)');
if ($code !== 0) {
    @unlink($installModeFlagFile); // 清除安装模式标志
    fwrite(STDERR, "ERROR: setup:upgrade (1/2) failed (exit $code). Fix the errors above and re-run install.\n");
    exit(1);
}

// 8. setup:upgrade -y (2/2)
$code = $runSetupUpgrade('bin/w setup:upgrade -y', '(2/2)');
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
    // Env::check_user 禁止 root 跑 bin/w；特权端口请改用 >=1024 或系统 capabilities，勿 sudo 成 root 启动。
    echo "提示：macOS 上绑定端口 {$serverPort} 通常需要特权；将以部署用户执行 server:start（禁止 root）。"
        . " 若失败请将 SERVER_PORT 设为 >=1024，或为 PHP 配置 port binding 能力。\n";
}
$code = $run($serverStartCmd);
if ($code !== 0) {
    echo "\n";
    echo "提示：server:start 未能自动启动，您可以手动启动服务器：\n";
    echo "  php bin/w server:start\n";
    if ($needSudo) {
        echo "  （勿使用 sudo 以 root 启动；请改用端口 >=1024 或以部署用户具备的能力绑定低端口）\n";
    }
    echo "\n";
}

// 12. 清除安装模式标志（放在 server:stop/start 之后，确保执行时仍能解析 server:* 命令）
@unlink($installModeFlagFile);
echo "安装模式已关闭。\n";

exit(0);
