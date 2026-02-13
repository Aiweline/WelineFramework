<?php

declare(strict_types=1);

/**
 * 安装后统一入口：composer、env:check、env:install、PostgreSQL 建库/校验、setup:upgrade×2、server:stop/start。
 * 由 bin/install.bat 或 bin/install.sh 在安装 PHP/pgsql 后调用。
 */

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnvLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SetupPgsqlDatabase.php';

$env = (new EnvLoader($projectRoot))->load(true);
$argv = $GLOBALS['argv'] ?? [];
$fromStep5b = in_array('--from', $argv, true)
    && (($i = array_search('--from', $argv, true)) !== false)
    && isset($argv[$i + 1])
    && $argv[$i + 1] === '5b';

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

// 1. composer install（vendor 已存在则跳过）；composer 为独立命令，不能用 php composer 方式调用
if (!$fromStep5b) {
    if (is_dir($projectRoot . DIRECTORY_SEPARATOR . 'vendor')) {
        echo "vendor/ exists, skipping composer install.\n";
    } else {
        $composerPhar = $projectRoot . DIRECTORY_SEPARATOR . 'composer.phar';
        $code = is_file($composerPhar)
            ? $run($composerPhar . ' install -n --no-interaction')
            : $runRaw('composer install -n --no-interaction');
        if ($code !== 0) {
            fwrite(STDERR, "ERROR: composer install failed (exit $code).\n");
            exit(1);
        }
    }
}

// 2. env:check（必需项缺失时非零退出，直接终止后续步骤）
if (!$fromStep5b) {
    $code = $run('bin/w env:check');
    if ($code !== 0) {
        fwrite(STDERR, "ERROR: env:check failed (exit $code). Fix required dependencies and re-run.\n");
        exit(1);
    }
}

// 3. env:install -y
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
$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
if (is_dir($pgsqlBin)) {
    $currentPath = getenv('PATH') ?: '';
    $pathSep = (DIRECTORY_SEPARATOR === '\\') ? ';' : ':';
    if (strpos($currentPath, $pgsqlBin) === false) {
        putenv('PATH=' . $pgsqlBin . $pathSep . $currentPath);
    }
}

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
    fwrite(STDERR, "ERROR: PostgreSQL database init failed. PDO connection check did not succeed. Fix weline.env / DB user password and re-run.\n");
    exit(1);
}

// 6. setup:upgrade -f (1/2)
$code = $run('bin/w setup:upgrade -y');
if ($code !== 0) {
    fwrite(STDERR, "ERROR: setup:upgrade (1/2) failed (exit $code). Fix the errors above and re-run install.\n");
    exit(1);
}

// 7. setup:upgrade -f (2/2)
$code = $run('bin/w setup:upgrade -y');
if ($code !== 0) {
    fwrite(STDERR, "ERROR: setup:upgrade (2/2) failed (exit $code). Fix the errors above and re-run install.\n");
    exit(1);
}

// 8. server:stop
$run('bin/w server:stop');

// 9. server:start
$code = $run('bin/w server:start');
if ($code !== 0) {
    fwrite(STDERR, "WARNING: server:start failed (exit $code). You may start the server manually.\n");
}

exit(0);
