<?php

declare(strict_types=1);

/**
 * 在启用 Opcache 前配置 php.ini（须在 opcache 可能触发 ASLR Fatal 之前执行）。
 * 由 bin/install.bat、bin/install.bash 在调用 run.php 之前以 -d opcache.enable=0 启动。
 */

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EnvLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';

$phpDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
if (!is_dir($phpDir)) {
    fwrite(STDERR, "bootstrap_php_ini: PHP directory not found, skip.\n");
    exit(0);
}

try {
    $env = (new EnvLoader($projectRoot))->load(true);
    (new ConfigurePhpIni($projectRoot, $phpDir))->apply($env);
    echo "bootstrap_php_ini: php.ini / php.installer.ini configured.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'bootstrap_php_ini failed: ' . $e->getMessage() . "\n");
    exit(1);
}
