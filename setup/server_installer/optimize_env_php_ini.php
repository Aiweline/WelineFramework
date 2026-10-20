<?php

declare(strict_types=1);

/**
 * 用当前 PHP 二进制，把环境依赖写进它真正会读的 ini（含兄弟 php.ini / php-cli.ini）。
 * 由 bin/install.bash 在 PHP 安装 / 扩展编入之后调用。
 */

$enabler = dirname(__DIR__, 2) . '/app/code/Weline/Framework/Env/Service/PhpExtensionIniEnabler.php';
$optimizer = dirname(__DIR__, 2) . '/app/code/Weline/Framework/Env/Service/PhpInstallIniOptimizer.php';
if (!is_file($enabler) || !is_file($optimizer)) {
    fwrite(STDERR, "optimize_env_php_ini: missing Framework Env ini classes\n");
    exit(1);
}

require_once $enabler;
require_once $optimizer;

$report = (new Weline\Framework\Env\Service\PhpInstallIniOptimizer())->apply();
echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
