<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-intent-alive/var/');
@mkdir(VAR_DIR . 'process', 0775, true);
require BP . 'app/code/Weline/Framework/Setup/Lock/SetupUpgradeIntent.php';

use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

$path = VAR_DIR . 'process/' . SetupUpgradeIntent::FILENAME;
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$write = static function (int $pid) use ($path): void {
    file_put_contents($path, json_encode([
        'pid' => $pid,
        'started_at' => microtime(true),
        'command' => 'setup:upgrade',
    ], JSON_UNESCAPED_UNICODE) . "\n");
};

// 1. 当前进程：活着
@unlink($path);
$write((int) getmypid());
$check(SetupUpgradeIntent::isActive() === true, 'current pid should be active');
$check(is_file($path), 'current pid: intent file should stay');

// 2. pid=1：活着但 EPERM，不能误判为死
@unlink($path);
$write(1);
$check(SetupUpgradeIntent::isActive() === true, 'pid=1 (alive, EPERM) must not be treated as dead');
$check(is_file($path), 'pid=1: intent file must not be reclaimed');

// 3. 不存在的 pid：必须回收
@unlink($path);
$write(999999);
$check(SetupUpgradeIntent::isActive() === false, 'missing pid should be inactive');
$check(!is_file($path), 'missing pid: intent file should be reclaimed');

@unlink($path);
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;
