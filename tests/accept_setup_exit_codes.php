<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');

// A. 维护关闭失败必须影响退出码
$check(substr_count($src, 'closeoutFailed') >= 3, 'closeout failure must be tracked and applied to the exit code');
$check((bool) preg_match('/elseif \(\$envOk\)[\s\S]{0,400}closeoutFailed\s*=\s*true/', $src), 'WLS maintenance close failure must set closeoutFailed');
$check((bool) preg_match('/closeoutFailed[\s\S]{0,200}\$exitCode\s*=\s*1/', $src), 'closeoutFailed must force a non-zero exit code');

// B. 未安装时取消安装/仅看帮助必须返回非零
$check((bool) preg_match('/安装已取消。[\s\S]{0,160}return 1;/', $src), 'cancelled installation must return 1');
$check((bool) preg_match("/if \(\\\$input === '1'\)[\s\S]{0,240}showSystemInstallHelp\(\);[\s\S]{0,240}return 1;/", $src), 'help-only install branch must return 1');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
